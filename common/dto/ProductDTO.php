<?php

namespace common\dto;

/**
 * Нормализованное представление товара от поставщика.
 * Единый формат для всех парсеров.
 */
final class ProductDTO
{
    /**
     * @param string $supplierSku SKU у поставщика
     * @param string $name Название товара
     * @param string $categoryPath Путь категории: 'Матрасы > Пружинные'
     * @param string|null $manufacturer Производитель
     * @param string|null $brand Бренд
     * @param string|null $model Модель
     * @param string|null $description Полное описание
     * @param string|null $shortDescription Краткое описание
     * @param float|null $price Цена (если нет вариантов)
     * @param float|null $comparePrice Старая цена
     * @param bool $inStock В наличии
     * @param int|null $stockQuantity Количество на складе
     * @param string $stockStatus 'available', 'on_order', 'out_of_stock'
     * @param array<string, mixed> $attributes Атрибуты
     * @param string[] $imageUrls URLs изображений
     * @param VariantDTO[] $variants Варианты товара
     * @param array $rawData Сырые данные (для дебага)
     */
    public function __construct(
        public readonly string $supplierSku,
        public readonly string $name,
        public readonly string $categoryPath = '',
        public readonly ?string $manufacturer = null,
        public readonly ?string $brand = null,
        public readonly ?string $model = null,
        public readonly ?string $description = null,
        public readonly ?string $shortDescription = null,
        public readonly ?float $price = null,
        public readonly ?float $comparePrice = null,
        public readonly bool $inStock = true,
        public readonly ?int $stockQuantity = null,
        public readonly string $stockStatus = 'available',
        public readonly array $attributes = [],
        public readonly array $imageUrls = [],
        public readonly array $variants = [],
        public readonly array $rawData = [],
    ) {}

    public function hasVariants(): bool
    {
        return !empty($this->variants);
    }

    public function getMinPrice(): ?float
    {
        if (!$this->hasVariants()) {
            return $this->price;
        }
        $prices = array_filter(
            array_map(fn(VariantDTO $v) => $v->price, $this->variants),
            fn($p) => $p > 0
        );
        return !empty($prices) ? min($prices) : $this->price;
    }

    public function getMaxPrice(): ?float
    {
        if (!$this->hasVariants()) {
            return $this->price;
        }
        $prices = array_filter(
            array_map(fn(VariantDTO $v) => $v->price, $this->variants),
            fn($p) => $p > 0
        );
        return !empty($prices) ? max($prices) : $this->price;
    }

    public function getChecksum(): string
    {
        return hash('sha256', json_encode([
            'sku' => $this->supplierSku,
            'name' => $this->name,
            'price' => $this->price,
            'attributes' => $this->attributes,
            'variants' => array_map(fn(VariantDTO $v) => [
                'sku' => $v->sku,
                'price' => $v->price,
                'options' => $v->options,
                'inStock' => $v->inStock,
            ], $this->variants),
        ]));
    }
}
