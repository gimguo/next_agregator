<?php

namespace common\services\matching;

use common\dto\MatchResult;
use common\dto\ProductDTO;

/**
 * Интерфейс матчера товаров (Chain of Responsibility).
 *
 * Каждый матчер пытается найти существующий reference_variant
 * по своему критерию (GTIN, MPN, атрибуты).
 *
 * Если матчер не может дать результат — возвращает null,
 * и MatchingService передаёт DTO следующему матчеру в цепочке.
 */
interface ProductMatcherInterface
{
    /**
     * Попытаться найти существующий reference_variant для товара.
     *
     * @param ProductDTO $dto  Нормализованный товар
     * @param array      $context  Доп. контекст: supplier_id, brand_id, session_id и т.д.
     *
     * @return MatchResult|null  Результат матча или null (передать следующему)
     */
    public function match(ProductDTO $dto, array $context = []): ?MatchResult;

    /**
     * Имя матчера (для логирования).
     */
    public function getName(): string;

    /**
     * Приоритет матчера (чем меньше — тем раньше в цепочке).
     */
    public function getPriority(): int;
}
