<?php

namespace common\services\channel;

use common\models\SalesChannel;

/**
 * Интерфейс API-клиента канала (Strategy) — отправляет проекции на витрину.
 *
 * Каждый канал имеет свой API:
 *   - RosMatras: REST API (POST /api/v1/import/product)
 *   - Ozon:      Product API v3 (POST /v3/product/import)
 *   - WB:        Content API v2 (POST /content/v2/cards/upload)
 *
 * Клиент знает как авторизоваться (Bearer token, Api-Key, и т.д.)
 * и обрабатывает специфичные ошибки каждого канала.
 *
 * Токены/ключи берутся из SalesChannel->api_config (не из глобальных params).
 */
interface ApiClientInterface
{
    /**
     * Отправить проекцию одного товара на канал.
     *
     * @param int          $modelId    ID product_model
     * @param array        $projection Проекция в формате канала (из SyndicatorInterface)
     * @param SalesChannel $channel    Канал продаж (api_config с токенами)
     *
     * @return bool Успешно или нет
     * @throws \common\services\marketplace\MarketplaceUnavailableException API недоступен
     */
    public function push(int $modelId, array $projection, SalesChannel $channel): bool;

    /**
     * Пакетная отправка проекций на канал.
     *
     * @param array<int, array> $projections model_id => projection
     * @param SalesChannel      $channel     Канал продаж
     *
     * @return array<int, bool> model_id => success
     * @throws \common\services\marketplace\MarketplaceUnavailableException API недоступен
     */
    public function pushBatch(array $projections, SalesChannel $channel): array;

    /**
     * Проверить доступность API канала.
     *
     * @param SalesChannel $channel Канал продаж
     * @return bool
     */
    public function healthCheck(SalesChannel $channel): bool;
}
