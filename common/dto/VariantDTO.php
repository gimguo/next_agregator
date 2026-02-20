<?php

namespace common\dto;

/**
 * Вариант товара (размер, цвет и т.д.)
 */
final class VariantDTO
{
    public function __construct(
        public readonly ?string $sku = null,
        public readonly float $price = 0,
        public readonly ?float $comparePrice = null,
        public readonly bool $inStock = true,
        public readonly ?int $stockQuantity = null,
        public readonly string $stockStatus = 'available',
        /** @var array<string, string> Вариантообразующие: ['Размер' => '80x200', 'Цвет' => 'Белый'] */
        public readonly array $options = [],
        /** @var string[] */
        public readonly array $imageUrls = [],
    ) {}

    public function getOption(string $key): ?string
    {
        return $this->options[$key] ?? null;
    }

    public function hasDiscount(): bool
    {
        return $this->comparePrice !== null && $this->comparePrice > $this->price;
    }

    public function getDiscountPercent(): ?int
    {
        if (!$this->hasDiscount()) {
            return null;
        }
        return (int)round(100 - ($this->price / $this->comparePrice * 100));
    }
}
