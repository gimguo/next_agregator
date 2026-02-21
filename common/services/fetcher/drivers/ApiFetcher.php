<?php

namespace common\services\fetcher\drivers;

use common\models\SupplierFetchConfig;
use common\services\fetcher\FetchResult;
use common\services\fetcher\PriceFetcherInterface;
use GuzzleHttp\Client as HttpClient;
use Yii;

/**
 * Получение прайс-листа через REST/SOAP API поставщика.
 *
 * Особенности:
 * - Потоковое сохранение (sink) для больших JSON/XML ответов
 * - Поддержка GET/POST методов
 * - Произвольные заголовки и тело запроса из credentials
 * - Bearer / API-key авторизация
 */
class ApiFetcher implements PriceFetcherInterface
{
    private HttpClient $httpClient;

    public function __construct()
    {
        $this->httpClient = new HttpClient([
            'timeout' => 600,
            'connect_timeout' => 30,
            'verify' => false,
        ]);
    }

    public function supports(string $method): bool
    {
        return $method === 'api';
    }

    public function fetch(SupplierFetchConfig $config): FetchResult
    {
        $creds = $this->resolveCredentials($config);

        $apiUrl = $creds['api_url'] ?? $config->api_url ?? '';
        $apiMethod = strtoupper($creds['method'] ?? $config->api_method ?? 'GET');

        if (empty($apiUrl)) {
            return FetchResult::fail('API URL не указан', 'api');
        }

        $supplierCode = $config->supplier->code ?? 'unknown';
        $storagePath = $this->ensureStoragePath($supplierCode);

        $filename = $supplierCode . '_' . date('Y-m-d_His') . '.json';
        $localPath = $storagePath . '/' . $filename;

        $startTime = microtime(true);

        try {
            Yii::info("ApiFetcher: запрос {$apiMethod} {$apiUrl}", 'fetcher');

            $requestOptions = [
                'sink' => $localPath, // Потоковая запись на диск
                'headers' => $this->buildHeaders($creds),
            ];

            // Тело запроса для POST
            if ($apiMethod !== 'GET' && !empty($creds['body'])) {
                $requestOptions['json'] = $creds['body'];
            }

            // Query-параметры
            if (!empty($creds['query'])) {
                $requestOptions['query'] = $creds['query'];
            }

            $response = $this->httpClient->request($apiMethod, $apiUrl, $requestOptions);
            $duration = round(microtime(true) - $startTime, 2);
            $statusCode = $response->getStatusCode();

            if ($statusCode !== 200) {
                @unlink($localPath);
                return FetchResult::fail("API HTTP {$statusCode}", 'api');
            }

            $fileSize = filesize($localPath);
            if ($fileSize < 10) {
                @unlink($localPath);
                return FetchResult::fail("API вернул пустой ответ ({$fileSize} bytes)", 'api');
            }

            Yii::info("ApiFetcher: получен {$localPath} ({$fileSize} bytes, {$duration}s)", 'fetcher');
            return FetchResult::ok($localPath, 'api', $duration);

        } catch (\Throwable $e) {
            @unlink($localPath);
            Yii::error("ApiFetcher: ошибка {$apiUrl} — {$e->getMessage()}", 'fetcher');
            return FetchResult::fail($e->getMessage(), 'api');
        }
    }

    /**
     * Собираем HTTP-заголовки из credentials.
     */
    private function buildHeaders(array $creds): array
    {
        $headers = [];

        if (!empty($creds['headers']) && is_array($creds['headers'])) {
            $headers = $creds['headers'];
        }

        if (!empty($creds['api_key'])) {
            $headers['Authorization'] = 'Bearer ' . $creds['api_key'];
        }

        if (!empty($creds['token'])) {
            $headers['Authorization'] = 'Bearer ' . $creds['token'];
        }

        return $headers;
    }

    private function resolveCredentials(SupplierFetchConfig $config): array
    {
        if ($config->credentials) {
            return is_array($config->credentials) ? $config->credentials : json_decode($config->credentials, true) ?: [];
        }
        return [];
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
