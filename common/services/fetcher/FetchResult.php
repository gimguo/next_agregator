<?php

namespace common\services\fetcher;

/**
 * Результат скачивания прайс-листа.
 *
 * Иммутабельный value object с информацией о скачанном файле.
 */
class FetchResult
{
    public function __construct(
        public readonly bool    $success,
        public readonly ?string $filePath = null,
        public readonly ?string $error = null,
        public readonly ?int    $fileSize = null,
        public readonly ?float  $durationSec = null,
        public readonly string  $method = 'unknown'
    ) {}

    /**
     * Фабрика для успешного результата.
     */
    public static function ok(string $filePath, string $method = 'url', ?float $duration = null): self
    {
        $size = file_exists($filePath) ? filesize($filePath) : null;
        return new self(
            success: true,
            filePath: $filePath,
            fileSize: $size,
            durationSec: $duration,
            method: $method
        );
    }

    /**
     * Фабрика для ошибки.
     */
    public static function fail(string $error, string $method = 'url'): self
    {
        return new self(success: false, error: $error, method: $method);
    }

    /**
     * Человекочитаемый размер файла.
     */
    public function getHumanSize(): string
    {
        if (!$this->fileSize) return '—';
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = $this->fileSize;
        $i = 0;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }
        return round($size, 1) . ' ' . $units[$i];
    }
}
