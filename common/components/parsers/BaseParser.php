<?php

namespace common\components\parsers;

use yii\base\BaseObject;

/**
 * Базовый класс парсера с общей логикой.
 * Наследуем yii\base\BaseObject для конфигурации через Yii::createObject().
 */
abstract class BaseParser extends BaseObject implements ParserInterface
{
    protected array $config = [];
    protected array $stats = [
        'total_parsed' => 0,
        'skipped' => 0,
        'errors' => 0,
    ];

    public function setConfig(array $config): static
    {
        $this->config = $config;
        return $this;
    }

    public function getStats(): array
    {
        return $this->stats;
    }

    /** Нормализация строки: trim, убрать двойные пробелы */
    protected function cleanString(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return trim(preg_replace('/\s+/', ' ', $value));
    }

    /** '20 см' → 20.0 */
    protected function extractNumber(?string $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (preg_match('/[\d]+([.,]\d+)?/', $value, $matches)) {
            return (float)str_replace(',', '.', $matches[0]);
        }
        return null;
    }

    /** '80x200' → ['width' => 80, 'length' => 200] */
    protected function parseSize(?string $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (preg_match('/^(\d+)\s*[xXхХ×]\s*(\d+)$/u', trim($value), $matches)) {
            return [
                'width' => (int)$matches[1],
                'length' => (int)$matches[2],
            ];
        }
        return null;
    }
}
