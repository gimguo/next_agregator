<?php

namespace common\dto;

/**
 * Результат сопоставления товара в Matching Engine.
 *
 * Если variantId === null — товар новый (ни один матчер не дал результат).
 * Если variantId !== null — найден существующий reference_variant.
 */
class MatchResult
{
    public function __construct(
        /** @var int|null ID найденного reference_variant (null = товар новый) */
        public readonly ?int $variantId,

        /** @var int|null ID найденной product_model */
        public readonly ?int $modelId,

        /** @var string Имя матчера, давшего результат: 'gtin', 'mpn', 'composite', 'new' */
        public readonly string $matcherName,

        /** @var float Уверенность в матче: 0.0 = нет, 1.0 = точное совпадение */
        public readonly float $confidence,

        /** @var array Детали матчинга (для логирования/отладки) */
        public readonly array $details = [],
    ) {}

    /**
     * Матч найден?
     */
    public function isMatched(): bool
    {
        return $this->variantId !== null;
    }

    /**
     * Создать результат "товар не найден" (новый товар).
     */
    public static function notFound(array $details = []): self
    {
        return new self(
            variantId:   null,
            modelId:     null,
            matcherName: 'new',
            confidence:  0.0,
            details:     $details,
        );
    }

    /**
     * Создать результат "товар найден".
     */
    public static function found(
        ?int $variantId,
        int $modelId,
        string $matcherName,
        float $confidence = 1.0,
        array $details = [],
    ): self {
        return new self(
            variantId:   $variantId,
            modelId:     $modelId,
            matcherName: $matcherName,
            confidence:  $confidence,
            details:     $details,
        );
    }

    public function toArray(): array
    {
        return [
            'variant_id'   => $this->variantId,
            'model_id'     => $this->modelId,
            'matcher_name' => $this->matcherName,
            'confidence'   => $this->confidence,
            'details'      => $this->details,
        ];
    }
}
