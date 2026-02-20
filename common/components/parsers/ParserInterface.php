<?php

namespace common\components\parsers;

use common\dto\ProductDTO;

/**
 * Интерфейс парсера прайс-листов поставщиков.
 */
interface ParserInterface
{
    /** Код поставщика: 'ormatek', 'askona' */
    public function getSupplierCode(): string;

    /** Человекопонятное название */
    public function getSupplierName(): string;

    /** Может ли этот парсер обработать файл? */
    public function accepts(string $filePath): bool;

    /**
     * Парсинг файла → генератор ProductDTO.
     * @return \Generator<int, ProductDTO>
     */
    public function parse(string $filePath, array $options = []): \Generator;

    /** Примерная оценка количества товаров */
    public function estimateCount(string $filePath): ?int;

    /** Статистика после парсинга */
    public function getStats(): array;
}
