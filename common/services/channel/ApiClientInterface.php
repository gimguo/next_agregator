<?php

namespace common\services\channel;

use common\models\SalesChannel;

/**
 * Интерфейс API-клиента канала (Strategy) — отправляет проекции на витрину.
 *
 * Каждый канал имеет свой API:
 *   - RosMatras: REST API (POST /api/v1/import/product)
 *   - Ozon:      Product API v3 (POST /v3/product/import, /v1/product/import/prices, /v2/products/stocks)
 *   - WB:        Content API v2 (POST /content/v2/cards/upload)
 *
 * === Fast-Lane (Sprint 10) ===
 *   pushPrices() и pushStocks() — специализированные эндпоинты для лёгких обновлений.
 *   Маркетплейсы обрабатывают их гораздо быстрее полного обновления карточки.
 *
 * === DLQ (Sprint 10) ===
 *   Если API возвращает 4xx (ошибка валидации), клиент должен выбросить
 *   ChannelValidationException → воркер запишет в DLQ, а не retry.
 *
 * Токены/ключи берутся из SalesChannel->api_config (не из глобальных params).
 */
interface ApiClientInterface
{
    /**
     * Отправить полную проекцию одного товара на канал.
     * Lane: content_updated
     *
     * @param int          $modelId    ID product_model
     * @param array        $projection Проекция (из SyndicatorInterface::buildProjection)
     * @param SalesChannel $channel    Канал продаж
     *
     * @return bool Успешно или нет
     * @throws \common\services\marketplace\MarketplaceUnavailableException API недоступен (5xx, таймаут)
     * @throws \common\services\channel\ChannelValidationException         Ошибка валидации (4xx) → DLQ
     */
    public function push(int $modelId, array $projection, SalesChannel $channel): bool;

    /**
     * Пакетная отправка полных проекций на канал.
     * Lane: content_updated
     *
     * @param array<int, array> $projections model_id => projection
     * @param SalesChannel      $channel     Канал продаж
     *
     * @return array<int, bool> model_id => success
     * @throws \common\services\marketplace\MarketplaceUnavailableException
     */
    public function pushBatch(array $projections, SalesChannel $channel): array;

    /**
     * Отправить обновление цен (лёгкий пакет).
     * Lane: price_updated
     *
     * @param array        $priceItems Массив из SyndicatorInterface::buildPriceProjection()['items']
     * @param SalesChannel $channel    Канал продаж
     *
     * @return bool
     * @throws \common\services\marketplace\MarketplaceUnavailableException
     * @throws \common\services\channel\ChannelValidationException
     */
    public function pushPrices(array $priceItems, SalesChannel $channel): bool;

    /**
     * Отправить обновление остатков (лёгкий пакет).
     * Lane: stock_updated
     *
     * @param array        $stockItems Массив из SyndicatorInterface::buildStockProjection()['items']
     * @param SalesChannel $channel    Канал продаж
     *
     * @return bool
     * @throws \common\services\marketplace\MarketplaceUnavailableException
     * @throws \common\services\channel\ChannelValidationException
     */
    public function pushStocks(array $stockItems, SalesChannel $channel): bool;

    /**
     * Отправить структуру каталога (дерево категорий).
     * Lane: content_updated (entity_type = 'category_tree')
     *
     * @param array        $payload Проекция из SyndicatorInterface::buildCategoryTreeProjection()
     * @param SalesChannel $channel Канал продаж
     *
     * @return bool
     * @throws \common\services\marketplace\MarketplaceUnavailableException
     * @throws \common\services\channel\ChannelValidationException
     */
    public function pushCategoryTree(array $payload, SalesChannel $channel): bool;

    /**
     * Проверить доступность API канала.
     *
     * @param SalesChannel $channel Канал продаж
     * @return bool
     */
    public function healthCheck(SalesChannel $channel): bool;
}
