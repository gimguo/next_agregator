<?php

namespace common\jobs;

use yii\base\BaseObject;
use yii\queue\JobInterface;
use yii\queue\Queue;
use Yii;

/**
 * Задание: AI-обогащение карточки товара.
 *
 * Выполняет комплексную обработку карточки:
 * - Извлечение атрибутов из описания
 * - Генерация эталонного описания
 * - Оценка качества карточки
 * - Нормализация названия
 *
 * Использование:
 *   Yii::$app->queue->push(new EnrichCardJob(['cardId' => 42]));
 */
class EnrichCardJob extends BaseObject implements JobInterface
{
    /** @var int ID карточки */
    public int $cardId = 0;

    public function execute($queue): void
    {
        if ($this->cardId === 0) return;

        $db = Yii::$app->db;
        /** @var \common\services\AIService $ai */
        $ai = Yii::$app->get('aiService');

        if (!$ai->isAvailable()) {
            Yii::warning("EnrichCardJob: AI недоступен, пропуск card={$this->cardId}", 'queue');
            return;
        }

        $card = $db->createCommand(
            "SELECT * FROM {{%product_cards}} WHERE id = :id",
            [':id' => $this->cardId]
        )->queryOne();

        if (!$card) {
            Yii::warning("EnrichCardJob: карточка {$this->cardId} не найдена", 'queue');
            return;
        }

        Yii::info("EnrichCardJob: старт card={$this->cardId} '{$card['canonical_name']}'", 'queue');

        $updateData = [];
        $aiModel = $ai->getModelInfo()['model'] ?? 'deepseek';

        // 1. Извлечение атрибутов
        try {
            $attributes = $ai->extractAttributes($card['canonical_name'], $card['description'] ?? '');
            if ($attributes) {
                $this->saveDataSource($db, 'ai_attributes', $aiModel, $attributes, 50);
            }
        } catch (\Throwable $e) {
            Yii::warning("EnrichCardJob: extractAttributes error: {$e->getMessage()}", 'queue');
        }

        usleep(300_000); // 300ms пауза

        // 2. Генерация описания (если нет или короткое)
        if (empty($card['description']) || mb_strlen($card['description']) < 100) {
            try {
                $description = $ai->generateDescription(
                    $card['canonical_name'],
                    $card['brand'] ?? '',
                    $card['description'] ?? ''
                );
                if ($description && mb_strlen($description) > 50) {
                    $updateData['description'] = $description;
                    $this->saveDataSource($db, 'ai_enrichment', $aiModel, [
                        'type' => 'description',
                        'description' => $description,
                    ], 50);
                }
            } catch (\Throwable $e) {
                Yii::warning("EnrichCardJob: generateDescription error: {$e->getMessage()}", 'queue');
            }

            usleep(300_000);
        }

        // 3. Оценка качества
        try {
            $quality = $ai->analyzeQuality([
                'name' => $card['canonical_name'],
                'brand' => $card['brand'],
                'description' => $card['description'] ?? $updateData['description'] ?? '',
                'image_count' => (int)$card['image_count'],
                'variant_count' => (int)$card['total_variants'],
            ]);
            if ($quality && isset($quality['score'])) {
                $score = min(100, max(0, (int)$quality['score']));
                $updateData['quality_score'] = $score;
                $this->saveDataSource($db, 'ai_enrichment', $aiModel . ':quality', [
                    'type' => 'quality',
                    'score' => $score,
                    'details' => $quality,
                ], 50, isset($quality['confidence']) ? (float)$quality['confidence'] : null);
            }
        } catch (\Throwable $e) {
            Yii::warning("EnrichCardJob: analyzeQuality error: {$e->getMessage()}", 'queue');
        }

        // Применяем обновления к карточке
        if (!empty($updateData)) {
            $db->createCommand()->update('{{%product_cards}}', $updateData, ['id' => $this->cardId])->execute();
        }

        Yii::info("EnrichCardJob: завершён card={$this->cardId} updates=" . count($updateData), 'queue');
    }

    /**
     * Сохранить/обновить запись в полиморфной card_data_sources.
     *
     * Уникальность по (card_id, source_type, source_id).
     * При повторном вызове — обновляет data и updated_at.
     */
    protected function saveDataSource(
        $db,
        string $sourceType,
        string $sourceId,
        array $data,
        int $priority = 50,
        ?float $confidence = null
    ): void {
        $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $db->createCommand()->upsert('{{%card_data_sources}}', [
            'card_id'     => $this->cardId,
            'source_type' => $sourceType,
            'source_id'   => $sourceId,
            'data'        => $jsonData,
            'priority'    => $priority,
            'confidence'  => $confidence,
            'created_at'  => new \yii\db\Expression('NOW()'),
            'updated_at'  => new \yii\db\Expression('NOW()'),
        ], [
            'data'       => $jsonData,
            'confidence' => $confidence,
            'updated_at' => new \yii\db\Expression('NOW()'),
        ])->execute();
    }
}
