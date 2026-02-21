<?php

namespace common\services\fetcher\drivers;

use common\models\SupplierFetchConfig;
use common\services\fetcher\FetchResult;
use common\services\fetcher\PriceFetcherInterface;
use GuzzleHttp\Client as HttpClient;
use Yii;

/**
 * Скачивание прайс-листа по HTTP/HTTPS URL.
 *
 * Особенности:
 * - Потоковое скачивание (stream → sink) — не загружает весь файл в RAM
 * - Поддержка произвольных заголовков (Authorization, Cookie и т.д.)
 * - Таймаут 10 минут для больших файлов (500MB+)
 * - Проверка минимального размера (защита от пустых ответов)
 */
class UrlFetcher implements PriceFetcherInterface
{
    private HttpClient $httpClient;

    public function __construct()
    {
        $this->httpClient = new HttpClient([
            'timeout' => 600, // 10 мин для больших файлов
            'connect_timeout' => 30,
            'verify' => false,
            'allow_redirects' => ['max' => 5],
        ]);
    }

    public function supports(string $method): bool
    {
        return $method === 'url';
    }

    public function fetch(SupplierFetchConfig $config): FetchResult
    {
        $url = $config->url;
        if (empty($url)) {
            return FetchResult::fail('URL не указан в конфигурации', 'url');
        }

        $supplierCode = $config->supplier->code ?? 'unknown';
        $storagePath = $this->ensureStoragePath($supplierCode);

        // Определяем расширение из URL
        $extension = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'xml';
        // Учитываем archive_type
        if ($config->archive_type) {
            $extension = $config->archive_type;
        }
        $filename = $supplierCode . '_' . date('Y-m-d_His') . '.' . $extension;
        $localPath = $storagePath . '/' . $filename;

        $startTime = microtime(true);

        // Заголовки из credentials
        $headers = $this->buildHeaders($config);

        try {
            Yii::info("UrlFetcher: скачиваем {$url} → {$localPath}", 'fetcher');

            $response = $this->httpClient->get($url, [
                'sink' => $localPath, // Потоковая запись на диск
                'headers' => $headers,
                'progress' => function ($downloadTotal, $downloadedBytes) use ($supplierCode) {
                    // Логируем прогресс для больших файлов
                    if ($downloadTotal > 0 && $downloadedBytes > 0) {
                        $pct = round($downloadedBytes / $downloadTotal * 100);
                        if ($pct % 25 === 0) {
                            $mb = round($downloadedBytes / 1024 / 1024, 1);
                            Yii::debug("UrlFetcher [{$supplierCode}]: {$pct}% ({$mb}MB)", 'fetcher');
                        }
                    }
                },
            ]);

            $duration = round(microtime(true) - $startTime, 2);
            $statusCode = $response->getStatusCode();

            if ($statusCode !== 200) {
                @unlink($localPath);
                return FetchResult::fail("HTTP {$statusCode}", 'url');
            }

            // Проверка минимального размера
            $fileSize = filesize($localPath);
            if ($fileSize < 100) {
                @unlink($localPath);
                return FetchResult::fail("Файл слишком мал ({$fileSize} bytes) — вероятно пустой ответ", 'url');
            }

            Yii::info("UrlFetcher: скачан {$localPath} ({$fileSize} bytes, {$duration}s)", 'fetcher');
            return FetchResult::ok($localPath, 'url', $duration);

        } catch (\Throwable $e) {
            @unlink($localPath);
            Yii::error("UrlFetcher: ошибка {$url} — {$e->getMessage()}", 'fetcher');
            return FetchResult::fail($e->getMessage(), 'url');
        }
    }

    /**
     * Собираем HTTP-заголовки из credentials.
     */
    private function buildHeaders(SupplierFetchConfig $config): array
    {
        $creds = is_array($config->credentials) ? $config->credentials : json_decode($config->credentials ?? '{}', true);
        $headers = [];

        // Кастомные заголовки
        if (!empty($creds['headers']) && is_array($creds['headers'])) {
            $headers = $creds['headers'];
        }

        // API-ключ как Bearer токен
        if (!empty($creds['api_key'])) {
            $headers['Authorization'] = 'Bearer ' . $creds['api_key'];
        }

        // Basic Auth
        if (!empty($creds['login']) && !empty($creds['password'])) {
            $headers['Authorization'] = 'Basic ' . base64_encode($creds['login'] . ':' . $creds['password']);
        }

        return $headers;
    }

    private function ensureStoragePath(string $supplierCode): string
    {
        $dir = Yii::getAlias('@storage/prices-source/' . $supplierCode);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir;
    }
}
