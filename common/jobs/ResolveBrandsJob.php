<?php

namespace common\jobs;

use yii\base\BaseObject;
use yii\queue\JobInterface;
use yii\queue\Queue;
use Yii;

/**
 * Задание: разрешить бренды для пакета карточек через BrandService.
 *
 * После импорта прайса поля brand/manufacturer могут содержать «грязные» значения.
 * Этот джоб нормализует бренды через кэш алиасов + AI (DeepSeek).
 *
 * Использование:
 *   Yii::$app->queue->push(new ResolveBrandsJob([
 *       'cardIds' => [1, 2, 3, ...],
 *   ]));
 */
class ResolveBrandsJob extends BaseObject implements JobInterface
{
    /** @var int[] ID карточек для обработки */
    public array $cardIds = [];

    public function execute($queue): void
    {
        if (empty($this->cardIds)) return;

        $db = Yii::$app->db;
        /** @var \common\services\BrandService $brandService */
        $brandService = Yii::$app->get('brandService');

        $resolved = 0;
        $failed = 0;

        foreach ($this->cardIds as $cardId) {
            try {
                $card = $db->createCommand(
                    "SELECT id, brand, manufacturer, brand_id FROM {{%product_cards}} WHERE id = :id",
                    [':id' => $cardId]
                )->queryOne();

                if (!$card) continue;

                // Пытаемся разрешить бренд
                $rawBrand = $card['brand'] ?: $card['manufacturer'] ?: '';
                if (empty($rawBrand)) continue;

                $result = $brandService->resolve($rawBrand);

                if ($result) {
                    $db->createCommand()->update('{{%product_cards}}', [
                        'brand_id' => $result['brand_id'],
                        'brand' => $result['canonical_name'],
                    ], ['id' => $cardId])->execute();
                    $resolved++;
                }
            } catch (\Throwable $e) {
                $failed++;
                Yii::warning("ResolveBrandsJob: card={$cardId} error={$e->getMessage()}", 'queue');
            }
        }

        Yii::info("ResolveBrandsJob: resolved={$resolved} failed={$failed} total=" . count($this->cardIds), 'queue');
    }
}
