<?php

namespace common\jobs;

use common\models\SupplierAiRecipe;
use common\services\AIService;
use common\services\ImportStagingService;
use yii\base\BaseObject;
use yii\queue\JobInterface;
use yii\queue\Queue;
use Yii;

/**
 * Фаза 2: AI-анализ выборки из staging → рецепт нормализации.
 *
 * Кэширование рецептов:
 *   1. Проверяем supplier_ai_recipes — есть ли активный рецепт для поставщика.
 *   2. Если ЕСТЬ и !forceRegenerate → используем кэш (0 запросов к AI, 0 секунд, $0).
 *   3. Если НЕТ или forceRegenerate → генерируем через DeepSeek:
 *      - Берём 40-50 случайных товаров из staging_raw_offers
 *      - Отправляем в AI, получаем рецепт
 *      - Сохраняем в supplier_ai_recipes для будущих импортов
 *
 * Экономия: при ежедневном импорте 10 поставщиков — $0.03-0.10/день → $0.
 *
 * После завершения ставит в очередь NormalizeStagedJob (фаза 3).
 */
class AnalyzePriceJob extends BaseObject implements JobInterface
{
    public string $sessionId = '';
    public string $supplierCode = '';
    public int $supplierId = 0;

    /** @var int Размер выборки для AI */
    public int $sampleSize = 40;

    /** @var bool Принудительная генерация рецепта (игнорировать кэш) */
    public bool $forceRegenerate = false;

    // Обратная совместимость
    public string $taskId = '';

    public function init(): void
    {
        parent::init();
        if (!empty($this->taskId) && empty($this->sessionId)) {
            $this->sessionId = $this->taskId;
        }
    }

    public function execute($queue): void
    {
        Yii::info("AnalyzePriceJob: старт sessionId={$this->sessionId} forceRegenerate=" . ($this->forceRegenerate ? 'yes' : 'no'), 'import');

        /** @var ImportStagingService $staging */
        $staging = Yii::$app->get('importStaging');
        $staging->setStatus($this->sessionId, 'analyzing');

        try {
            // ═══ ШАГ 1: ПРОВЕРЯЕМ КЭШ РЕЦЕПТОВ ═══
            if (!$this->forceRegenerate) {
                $cachedRecipe = SupplierAiRecipe::findActiveForSupplier($this->supplierId);

                if ($cachedRecipe) {
                    $recipe = $cachedRecipe->toNormalizeRecipe();

                    Yii::info(
                        "AnalyzePriceJob: используем кэшированный рецепт v{$cachedRecipe->recipe_version} " .
                        "для supplier_id={$this->supplierId} (сэкономлено: ~{$cachedRecipe->ai_duration_sec}s, ~{$cachedRecipe->ai_tokens_used} токенов)",
                        'import'
                    );

                    $staging->updateStats($this->sessionId, [
                        'recipe'              => $recipe,
                        'ai_cached'           => true,
                        'ai_recipe_version'   => $cachedRecipe->recipe_version,
                        'ai_duration_sec'     => 0,
                        'ai_tokens_saved'     => $cachedRecipe->ai_tokens_used ?? 0,
                    ]);
                    $staging->setStatus($this->sessionId, 'analyzed');

                    $this->enqueueNextPhase($recipe);
                    return;
                }

                Yii::info("AnalyzePriceJob: кэш рецепта не найден для supplier_id={$this->supplierId}, генерируем новый", 'import');
            } else {
                Yii::info("AnalyzePriceJob: forceRegenerate=true, генерируем новый рецепт", 'import');
            }

            // ═══ ШАГ 2: ПРОВЕРЯЕМ ДОСТУПНОСТЬ AI ═══
            /** @var AIService $ai */
            $ai = Yii::$app->get('aiService');

            if (!$ai->isAvailable()) {
                Yii::warning("AnalyzePriceJob: AI недоступен, пропускаем анализ", 'import');
                $staging->updateStats($this->sessionId, [
                    'ai_skipped'     => true,
                    'ai_skip_reason' => 'AI service unavailable',
                ]);
                $staging->setStatus($this->sessionId, 'analyzed');
                $this->enqueueNextPhase();
                return;
            }

            // ═══ ШАГ 3: ГЕНЕРАЦИЯ НОВОГО РЕЦЕПТА ═══

            // Получаем случайный сэмпл из staging_raw_offers
            $sample = $staging->getSample($this->sessionId, $this->sampleSize);
            if (empty($sample)) {
                Yii::warning("AnalyzePriceJob: staging пуст", 'import');
                $staging->setStatus($this->sessionId, 'failed');
                $staging->updateStats($this->sessionId, ['error_message' => 'Staging is empty']);
                return;
            }

            // Бренды и категории через SQL-агрегацию
            $uniqueBrands = $staging->getBrands($this->sessionId);
            $uniqueCategories = $staging->getCategories($this->sessionId);

            // Существующие бренды и категории из БД
            $existingBrands = Yii::$app->db->createCommand(
                "SELECT canonical_name FROM {{%brands}} WHERE is_active = true ORDER BY canonical_name"
            )->queryColumn();

            $existingCategories = Yii::$app->db->createCommand(
                "SELECT id, name FROM {{%categories}} WHERE is_active = true ORDER BY sort_order, name"
            )->queryAll();
            $catMap = [];
            foreach ($existingCategories as $cat) {
                $catMap[(int)$cat['id']] = $cat['name'];
            }

            // Вызываем AI
            $startTime = microtime(true);
            $recipe = $ai->generateImportRecipe(
                sampleProducts:     $sample,
                existingBrands:     $existingBrands,
                existingCategories: $catMap,
                uniqueBrands:       $uniqueBrands,
                uniqueCategories:   $uniqueCategories,
            );
            $aiDuration = round(microtime(true) - $startTime, 2);

            if (empty($recipe)) {
                Yii::warning("AnalyzePriceJob: AI вернул пустой рецепт", 'import');
                $staging->updateStats($this->sessionId, [
                    'ai_skipped'      => true,
                    'ai_skip_reason'  => 'Empty recipe from AI',
                    'ai_duration_sec' => $aiDuration,
                ]);
                $staging->setStatus($this->sessionId, 'analyzed');
                $this->enqueueNextPhase();
                return;
            }

            // ═══ ШАГ 4: СОХРАНЯЕМ РЕЦЕПТ В КЭШ (supplier_ai_recipes) ═══
            $savedRecipe = SupplierAiRecipe::saveFromAIResponse(
                $this->supplierId,
                $this->supplierCode,
                $recipe,
                [
                    'sample_size'     => count($sample),
                    'ai_model'        => 'deepseek-chat',
                    'ai_duration_sec' => $aiDuration,
                    'ai_tokens_used'  => null, // TODO: получить из AI response
                ]
            );

            $brandMappings = count($recipe['brand_mapping'] ?? []);
            $categoryMappings = count($recipe['category_mapping'] ?? []);
            $insights = $recipe['insights'] ?? [];

            Yii::info(
                "AnalyzePriceJob: рецепт сгенерирован и закэширован (v{$savedRecipe->recipe_version}) — " .
                "brands={$brandMappings} categories={$categoryMappings} " .
                "quality=" . ($insights['data_quality'] ?? '?') . " time={$aiDuration}s",
                'import'
            );

            // Сохраняем рецепт и в stats сессии
            $staging->updateStats($this->sessionId, [
                'recipe'                   => $recipe,
                'ai_cached'                => false,
                'ai_recipe_version'        => $savedRecipe->recipe_version,
                'ai_duration_sec'          => $aiDuration,
                'recipe_brand_mappings'    => $brandMappings,
                'recipe_category_mappings' => $categoryMappings,
                'recipe_data_quality'      => $insights['data_quality'] ?? null,
                'recipe_notes'             => $insights['notes'] ?? [],
            ]);
            $staging->setStatus($this->sessionId, 'analyzed');

            // Фаза 3: нормализация
            $this->enqueueNextPhase($recipe);

        } catch (\Throwable $e) {
            Yii::error("AnalyzePriceJob: ошибка — {$e->getMessage()}", 'import');
            $staging->updateStats($this->sessionId, [
                'ai_error' => $e->getMessage(),
            ]);
            // Продолжаем без AI — не блокируем пайплайн
            $staging->setStatus($this->sessionId, 'analyzed');
            $this->enqueueNextPhase();
        }
    }

    private function enqueueNextPhase(array $recipe = []): void
    {
        Yii::$app->queue->push(new NormalizeStagedJob([
            'sessionId'    => $this->sessionId,
            'supplierCode' => $this->supplierCode,
            'supplierId'   => $this->supplierId,
            'recipe'       => $recipe,
        ]));
        Yii::info("AnalyzePriceJob: поставлен NormalizeStagedJob в очередь", 'import');
    }
}
