<?php

namespace common\jobs;

use common\services\AIService;
use common\services\ImportStagingService;
use yii\base\BaseObject;
use yii\queue\JobInterface;
use yii\queue\Queue;
use Yii;

/**
 * Фаза 2: AI-анализ выборки из staging → рецепт нормализации.
 *
 * Берёт 30-50 товаров из Redis staging, отправляет в DeepSeek,
 * получает «рецепт» — правила маппинга брендов, категорий,
 * нормализации названий. Сохраняет рецепт обратно в Redis.
 *
 * После завершения ставит в очередь NormalizeStagedJob (фаза 3).
 *
 * Стоимость: ~1 запрос к AI ($0.003-0.01).
 */
class AnalyzePriceJob extends BaseObject implements JobInterface
{
    public string $taskId;
    public string $supplierCode;

    /** @var int Размер выборки для AI */
    public int $sampleSize = 40;

    public function execute($queue): void
    {
        Yii::info("AnalyzePriceJob: старт taskId={$this->taskId}", 'import');

        /** @var ImportStagingService $staging */
        $staging = Yii::$app->get('importStaging');

        /** @var AIService $ai */
        $ai = Yii::$app->get('aiService');

        $staging->setStatus($this->taskId, 'analyzing');

        try {
            // Проверяем, доступен ли AI
            if (!$ai->isAvailable()) {
                Yii::warning("AnalyzePriceJob: AI недоступен, пропускаем анализ", 'import');
                $staging->updateMeta($this->taskId, [
                    'analyzed_at' => date('Y-m-d H:i:s'),
                    'ai_skipped' => true,
                    'ai_skip_reason' => 'AI service unavailable',
                ]);

                // Переходим к фазе 3 без рецепта
                $this->enqueueNextPhase($staging);
                return;
            }

            // Получаем сэмпл товаров из Redis
            $sample = $staging->getSample($this->taskId, $this->sampleSize);
            if (empty($sample)) {
                Yii::warning("AnalyzePriceJob: staging пуст", 'import');
                $staging->setStatus($this->taskId, 'failed');
                return;
            }

            // Собираем контекст: уникальные бренды и категории
            $uniqueBrands = $staging->getBrands($this->taskId);
            $uniqueCategories = $staging->getCategories($this->taskId);

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
                sampleProducts: $sample,
                existingBrands: $existingBrands,
                existingCategories: $catMap,
                uniqueBrands: $uniqueBrands,
                uniqueCategories: $uniqueCategories,
            );
            $aiDuration = round(microtime(true) - $startTime, 1);

            if (empty($recipe)) {
                Yii::warning("AnalyzePriceJob: AI вернул пустой рецепт", 'import');
                $staging->updateMeta($this->taskId, [
                    'analyzed_at' => date('Y-m-d H:i:s'),
                    'ai_skipped' => true,
                    'ai_skip_reason' => 'Empty recipe from AI',
                    'ai_duration_sec' => $aiDuration,
                ]);
            } else {
                // Сохраняем рецепт в Redis
                $staging->setRecipe($this->taskId, $recipe);

                $brandMappings = count($recipe['brand_mapping'] ?? []);
                $categoryMappings = count($recipe['category_mapping'] ?? []);
                $insights = $recipe['insights'] ?? [];

                $staging->updateMeta($this->taskId, [
                    'status' => 'analyzed',
                    'analyzed_at' => date('Y-m-d H:i:s'),
                    'ai_duration_sec' => $aiDuration,
                    'recipe_brand_mappings' => $brandMappings,
                    'recipe_category_mappings' => $categoryMappings,
                    'recipe_data_quality' => $insights['data_quality'] ?? null,
                    'recipe_notes' => $insights['notes'] ?? [],
                ]);

                Yii::info(
                    "AnalyzePriceJob: рецепт готов — brands={$brandMappings} categories={$categoryMappings} " .
                    "quality=" . ($insights['data_quality'] ?? '?') . " time={$aiDuration}s",
                    'import'
                );
            }

            // Фаза 3: нормализация
            $this->enqueueNextPhase($staging);

        } catch (\Throwable $e) {
            Yii::error("AnalyzePriceJob: ошибка — {$e->getMessage()}", 'import');
            $staging->updateMeta($this->taskId, [
                'ai_error' => $e->getMessage(),
                'analyzed_at' => date('Y-m-d H:i:s'),
            ]);
            // Продолжаем без AI — не блокируем пайплайн
            $this->enqueueNextPhase($staging);
        }
    }

    private function enqueueNextPhase(ImportStagingService $staging): void
    {
        Yii::$app->queue->push(new NormalizeStagedJob([
            'taskId' => $this->taskId,
            'supplierCode' => $this->supplierCode,
        ]));
        Yii::info("AnalyzePriceJob: поставлен NormalizeStagedJob в очередь", 'import');
    }
}
