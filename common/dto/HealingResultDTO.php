<?php

namespace common\dto;

/**
 * Результат попытки AI-лечения карточки товара.
 *
 * Содержит:
 *   - success       — удалось ли ИИ улучшить карточку
 *   - healed_fields — какие поля были исцелены: ['description', 'attr:frame_material', ...]
 *   - skipped_fields — поля, которые пропущены (не лечатся ИИ): ['image', 'barcode']
 *   - failed_fields — поля, которые ИИ не смог вылечить
 *   - description   — сгенерированное описание (если есть)
 *   - attributes    — сгенерированные атрибуты (если есть)
 *   - new_score     — новый скоринг после лечения (null если не пересчитывался)
 *   - new_is_ready  — готовность после лечения (null если не пересчитывался)
 *   - errors        — текстовые ошибки (API failure, parse error, etc.)
 */
class HealingResultDTO
{
    public bool $success = false;
    public array $healedFields = [];
    public array $skippedFields = [];
    public array $failedFields = [];
    public ?string $description = null;
    public array $attributes = [];
    public ?int $newScore = null;
    public ?bool $newIsReady = null;
    public array $errors = [];

    /**
     * Сколько полей было исцелено.
     */
    public function healedCount(): int
    {
        return count($this->healedFields);
    }

    /**
     * Карточка полностью вылечена (все обязательные поля есть)?
     */
    public function isFullyHealed(): bool
    {
        return $this->newIsReady === true;
    }

    /**
     * Для логирования.
     */
    public function toArray(): array
    {
        return [
            'success'        => $this->success,
            'healed_fields'  => $this->healedFields,
            'skipped_fields' => $this->skippedFields,
            'failed_fields'  => $this->failedFields,
            'new_score'      => $this->newScore,
            'new_is_ready'   => $this->newIsReady,
            'errors'         => $this->errors,
        ];
    }
}
