<?php

namespace common\services\marketplace;

/**
 * Интерфейс клиента маркетплейса.
 *
 * Абстракция для отправки проекций товаров на внешнюю витрину.
 * Текущая реализация — мок (логирование). В будущем — HTTP-клиент к RosMatras API.
 *
 * Контракт:
 *   - pushProduct() отправляет полную проекцию одной модели
 *   - pushBatch() оптимизированная пакетная отправка
 *   - deleteProduct() удаляет товар с витрины
 *
 * Идемпотентность: витрина ДОЛЖНА уметь принять ту же проекцию повторно
 * без создания дублей.
 */
interface MarketplaceApiClientInterface
{
    /**
     * Отправить проекцию одного товара (модели) на витрину.
     *
     * @param int   $modelId    ID product_model
     * @param array $projection Полная проекция из RosMatrasSyndicationService
     *
     * @return bool Успешно или нет
     * @throws \Exception В случае ошибки сети/API
     */
    public function pushProduct(int $modelId, array $projection): bool;

    /**
     * Пакетная отправка проекций.
     *
     * @param array<int, array> $projections model_id => projection
     *
     * @return array<int, bool> model_id => success
     */
    public function pushBatch(array $projections): array;

    /**
     * Удалить товар с витрины.
     *
     * @param int $modelId ID product_model
     * @return bool
     */
    public function deleteProduct(int $modelId): bool;

    /**
     * Проверить доступность API витрины.
     *
     * @return bool
     */
    public function healthCheck(): bool;
}
