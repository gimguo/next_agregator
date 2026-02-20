<?php

namespace common\jobs;

use yii\base\BaseObject;
use yii\queue\JobInterface;
use yii\queue\Queue;
use Yii;

/**
 * Задание: AI-категоризация карточек товаров.
 *
 * Определяет category_id и product_type для карточек без категории.
 * Использует AIService::categorize() для контекстного анализа.
 *
 * Использование:
 *   Yii::$app->queue->push(new CategorizeCardsJob([
 *       'cardIds' => [1, 2, 3],
 *   ]));
 */
class CategorizeCardsJob extends BaseObject implements JobInterface
{
    /** @var int[] ID карточек для категоризации */
    public array $cardIds = [];

    public function execute($queue): void
    {
        if (empty($this->cardIds)) return;

        $db = Yii::$app->db;
        /** @var \common\services\AIService $ai */
        $ai = Yii::$app->get('aiService');

        if (!$ai->isAvailable()) {
            Yii::warning('CategorizeCardsJob: AI недоступен, пропуск', 'queue');
            return;
        }

        // Загрузить дерево категорий
        $categories = $db->createCommand("
            SELECT id, name, parent_id, slug FROM {{%categories}} ORDER BY sort_order, name
        ")->queryAll();

        $categoryNames = array_column($categories, 'name', 'id');

        $categorized = 0;
        $skipped = 0;

        foreach ($this->cardIds as $cardId) {
            try {
                $card = $db->createCommand(
                    "SELECT id, canonical_name, brand, manufacturer, model, product_type, description 
                     FROM {{%product_cards}} WHERE id = :id",
                    [':id' => $cardId]
                )->queryOne();

                if (!$card) continue;

                // AI-категоризация
                $result = $ai->categorize(
                    $card['canonical_name'],
                    $card['brand'] ?? '',
                    $card['description'] ?? '',
                    $categoryNames
                );

                if ($result && isset($result['category_id'])) {
                    $updateData = ['category_id' => $result['category_id']];

                    if (!empty($result['product_type'])) {
                        $updateData['product_type'] = $result['product_type'];
                    }

                    $db->createCommand()->update('{{%product_cards}}', $updateData, ['id' => $cardId])->execute();
                    $categorized++;
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $skipped++;
                Yii::warning("CategorizeCardsJob: card={$cardId} error={$e->getMessage()}", 'queue');
            }

            usleep(200_000); // 200ms пауза между AI-запросами
        }

        Yii::info("CategorizeCardsJob: categorized={$categorized} skipped={$skipped}", 'queue');
    }
}
