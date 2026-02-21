<?php

namespace common\dto;

/**
 * Результат оценки готовности модели для канала.
 *
 * Содержит:
 *   - is_ready   — можно ли отправлять в канал (100% обязательных полей заполнено)
 *   - score      — процент заполненности (0-100)
 *   - missing    — список отсутствующих полей (обязательных и рекомендуемых)
 *   - details    — детализация по категориям проверок
 *
 * Разделение missing:
 *   - 'required:image'           — обязательное фото отсутствует
 *   - 'required:description'     — обязательное описание
 *   - 'required:attr:height'     — обязательный атрибут
 *   - 'recommended:attr:materials' — рекомендуемый атрибут (не блокирует, но снижает score)
 */
class ReadinessReportDTO
{
    public function __construct(
        public readonly bool  $isReady,
        public readonly int   $score,
        public readonly array $missing,
        public readonly array $details = [],
    ) {}

    /**
     * Только обязательные пропущенные поля (блокируют публикацию).
     */
    public function getRequiredMissing(): array
    {
        return array_filter($this->missing, fn(string $field) => str_starts_with($field, 'required:'));
    }

    /**
     * Только рекомендуемые пропущенные поля (снижают score).
     */
    public function getRecommendedMissing(): array
    {
        return array_filter($this->missing, fn(string $field) => str_starts_with($field, 'recommended:'));
    }

    /**
     * Человекочитаемые метки для пропущенных полей.
     */
    public function getMissingLabels(): array
    {
        $labels = [];
        foreach ($this->missing as $field) {
            $labels[] = self::labelFor($field);
        }
        return $labels;
    }

    /**
     * Для сериализации в JSONB (model_channel_readiness).
     */
    public function toArray(): array
    {
        return [
            'is_ready' => $this->isReady,
            'score'    => $this->score,
            'missing'  => $this->missing,
        ];
    }

    /**
     * Человекочитаемая метка для кода поля.
     */
    public static function labelFor(string $field): string
    {
        $map = [
            'required:image'       => 'Нет фото (обязательно)',
            'required:description' => 'Нет описания (обязательно)',
            'required:barcode'     => 'Нет штрихкода (обязательно)',
            'required:brand'       => 'Нет бренда (обязательно)',
            'required:price'       => 'Нет цены (обязательно)',
        ];

        if (isset($map[$field])) {
            return $map[$field];
        }

        // required:attr:height → "Нет атрибута: height (обязательно)"
        if (preg_match('/^required:attr:(.+)$/', $field, $m)) {
            return "Нет атрибута: {$m[1]} (обязательно)";
        }

        // recommended:attr:materials → "Нет атрибута: materials (рекомендуется)"
        if (preg_match('/^recommended:attr:(.+)$/', $field, $m)) {
            return "Нет атрибута: {$m[1]} (рекомендуется)";
        }

        if (preg_match('/^recommended:(.+)$/', $field, $m)) {
            return "{$m[1]} (рекомендуется)";
        }

        return $field;
    }
}
