<?php

namespace console\controllers;

use common\jobs\CategorizeCardsJob;
use common\jobs\EnrichCardJob;
use common\jobs\ResolveBrandsJob;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;
use Yii;

/**
 * AI-обработка карточек товаров.
 *
 * Примеры:
 *   yii ai/brands              — резолв брендов (очередь)
 *   yii ai/categorize           — категоризация (очередь)
 *   yii ai/enrich               — обогащение (очередь)
 *   yii ai/status               — статус AI-сервиса
 */
class AiController extends Controller
{
    /** @var int Лимит карточек */
    public int $limit = 100;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['limit']);
    }

    /**
     * Статус AI-сервиса.
     */
    public function actionStatus(): int
    {
        /** @var \common\services\AIService $ai */
        $ai = Yii::$app->get('aiService');
        $info = $ai->getModelInfo();

        $this->stdout("\n=== AI Service Status ===\n\n", Console::BOLD);
        $this->stdout("Доступен:  " . ($ai->isAvailable() ? 'Да' : 'НЕТ') . "\n");
        $this->stdout("Модель:    " . ($info['model'] ?? '—') . "\n");
        $this->stdout("API URL:   " . ($ai->baseUrl ?? '—') . "\n");
        $this->stdout("API Key:   " . (empty($ai->apiKey) ? 'НЕ ЗАДАН' : mb_substr($ai->apiKey, 0, 15) . '...') . "\n");

        // Статистика AI-логов
        try {
            $stats = Yii::$app->db->createCommand("
                SELECT 
                    COUNT(*) as total,
                    SUM(prompt_tokens) as total_in,
                    SUM(completion_tokens) as total_out,
                    SUM(total_tokens) as total_tokens,
                    MAX(created_at) as last_used
                FROM {{%ai_logs}}
            ")->queryOne();

            $this->stdout("\nСтатистика запросов:\n", Console::BOLD);
            $this->stdout("  Всего:       {$stats['total']}\n");
            $this->stdout("  Токены IN:   " . number_format($stats['total_in'] ?? 0) . "\n");
            $this->stdout("  Токены OUT:  " . number_format($stats['total_out'] ?? 0) . "\n");
            $this->stdout("  Всего токен: " . number_format($stats['total_tokens'] ?? 0) . "\n");
            $this->stdout("  Последний:   " . ($stats['last_used'] ?? 'никогда') . "\n");
        } catch (\Throwable $e) {
            $this->stdout("\nТаблица ai_logs недоступна\n", Console::FG_YELLOW);
        }

        $this->stdout("\n");
        return ExitCode::OK;
    }

    /**
     * Поставить резолв брендов в очередь.
     */
    public function actionBrands(): int
    {
        $ids = Yii::$app->db->createCommand("
            SELECT id FROM {{%product_cards}}
            WHERE brand_id IS NULL AND (brand IS NOT NULL OR manufacturer IS NOT NULL)
            ORDER BY created_at DESC
            LIMIT :limit
        ", [':limit' => $this->limit])->queryColumn();

        if (empty($ids)) {
            $this->stdout("Все бренды уже разрешены\n", Console::FG_GREEN);
            return ExitCode::OK;
        }

        $chunks = array_chunk($ids, 20);
        foreach ($chunks as $chunk) {
            Yii::$app->queue->push(new ResolveBrandsJob(['cardIds' => $chunk]));
        }

        $this->stdout("Поставлено " . count($chunks) . " заданий для " . count($ids) . " карточек\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Поставить AI-категоризацию в очередь.
     */
    public function actionCategorize(): int
    {
        $ids = Yii::$app->db->createCommand("
            SELECT id FROM {{%product_cards}}
            WHERE category_id IS NULL
            ORDER BY created_at DESC
            LIMIT :limit
        ", [':limit' => $this->limit])->queryColumn();

        if (empty($ids)) {
            $this->stdout("Все карточки уже категоризированы\n", Console::FG_GREEN);
            return ExitCode::OK;
        }

        $chunks = array_chunk($ids, 10);
        foreach ($chunks as $chunk) {
            Yii::$app->queue->push(new CategorizeCardsJob(['cardIds' => $chunk]));
        }

        $this->stdout("Поставлено " . count($chunks) . " заданий для " . count($ids) . " карточек\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Поставить AI-обогащение в очередь.
     */
    public function actionEnrich(): int
    {
        $ids = Yii::$app->db->createCommand("
            SELECT id FROM {{%product_cards}}
            WHERE quality_score < 50
            ORDER BY quality_score ASC, created_at DESC
            LIMIT :limit
        ", [':limit' => $this->limit])->queryColumn();

        if (empty($ids)) {
            $this->stdout("Нет карточек для обогащения (quality_score >= 50)\n", Console::FG_GREEN);
            return ExitCode::OK;
        }

        foreach ($ids as $cardId) {
            Yii::$app->queue->push(new EnrichCardJob(['cardId' => (int)$cardId]));
        }

        $this->stdout("Поставлено " . count($ids) . " заданий на обогащение\n", Console::FG_GREEN);
        return ExitCode::OK;
    }
}
