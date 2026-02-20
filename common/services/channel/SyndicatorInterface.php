<?php

namespace common\services\channel;

use common\models\SalesChannel;

/**
 * Интерфейс синдикатора (Strategy) — строит проекцию для конкретного канала.
 *
 * Каждый канал (RosMatras, Ozon, WB, Yandex) имеет свой формат проекции:
 *   - RosMatras: плоский JSON с selector_axes
 *   - Ozon:      Ozon Product API format (description_category_id, attributes, ...)
 *   - WB:        Wildberries card format (nmID, subjectID, addin, ...)
 *
 * Синдикатор трансформирует единый MDM-каталог в формат конкретного канала.
 */
interface SyndicatorInterface
{
    /**
     * Построить проекцию товара для конкретного канала.
     *
     * @param int          $modelId ID product_model в MDM
     * @param SalesChannel $channel Канал продаж (содержит api_config с маппингами и т.д.)
     *
     * @return array|null Проекция в формате канала или null если модель не найдена / не подходит
     */
    public function buildProjection(int $modelId, SalesChannel $channel): ?array;
}
