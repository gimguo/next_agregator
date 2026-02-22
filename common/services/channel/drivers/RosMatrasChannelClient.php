<?php

namespace common\services\channel\drivers;

use common\models\SalesChannel;
use common\services\channel\ApiClientInterface;
use common\services\channel\ChannelValidationException;
use common\services\marketplace\MarketplaceUnavailableException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;
use yii\base\Component;
use Yii;

/**
 * RosMatras Channel API Client — реализация ApiClientInterface для канала RosMatras.
 *
 * Токены и URL берутся из SalesChannel->api_config:
 *   {
 *     "apiUrl": "http://rosmatras-nginx/api/v1",
 *     "apiToken": "secret-token"
 *   }
 *
 * === Fast-Lane (Sprint 10) ===
 *   pushPrices() → POST /import/product (полный push, т.к. RosMatras не имеет отдельного эндпоинта)
 *   pushStocks() → POST /import/product (полный push)
 *
 *   Для RosMatras Fast-Lane сводится к отправке полной проекции (как content_updated),
 *   т.к. витрина не имеет отдельных API для цен/остатков.
 *   Но payload ЗНАЧИТЕЛЬНО легче: только model_id + variant_ids + prices/stocks.
 *
 * === DLQ (Sprint 10) ===
 *   Если API возвращает 4xx — выбрасываем ChannelValidationException.
 *   Воркер перехватит и запишет в channel_sync_errors (DLQ).
 */
class RosMatrasChannelClient extends Component implements ApiClientInterface
{
    /** @var int Таймаут подключения (секунды) */
    public int $connectTimeout = 10;

    /** @var int Таймаут запроса (секунды) */
    public int $requestTimeout = 30;

    /** @var int Максимум попыток на один запрос */
    public int $maxRetries = 2;

    /** @var array<int, Client> Пул Guzzle-клиентов (per channel_id) */
    private array $clients = [];

    // ═══════════════════════════════════════════
    // ApiClientInterface — Content (Full Projection)
    // ═══════════════════════════════════════════

    /**
     * {@inheritdoc}
     */
    public function push(int $modelId, array $projection, SalesChannel $channel): bool
    {
        $payload = [
            'model_id'   => $modelId,
            'projection' => $projection,
        ];

        $response = $this->sendWithRetry('POST', 'import/product', $payload, $channel);
        $statusCode = $response['status'];
        $body = $response['body'];

        if ($statusCode >= 200 && $statusCode < 300) {
            Yii::info(
                "RosMatrasChannel[{$channel->name}]: pushed model_id={$modelId} → HTTP {$statusCode}",
                'marketplace.export'
            );
            return true;
        }

        $errorMsg = $body['message'] ?? ($body['error'] ?? json_encode($body));

        // 4xx → ChannelValidationException (DLQ)
        if ($statusCode >= 400 && $statusCode < 500) {
            throw new ChannelValidationException(
                "HTTP {$statusCode}: {$errorMsg}",
                $statusCode,
                $channel->name,
                $payload
            );
        }

        // Остальные ошибки — RuntimeException (retry)
        Yii::error(
            "RosMatrasChannel[{$channel->name}]: push model_id={$modelId} → HTTP {$statusCode}: {$errorMsg}",
            'marketplace.export'
        );
        throw new \RuntimeException(
            "API error for model_id={$modelId} on channel '{$channel->name}': HTTP {$statusCode} — {$errorMsg}"
        );
    }

    /**
     * {@inheritdoc}
     */
    public function pushBatch(array $projections, SalesChannel $channel): array
    {
        $results = [];
        if (empty($projections)) {
            return $results;
        }

        $items = [];
        foreach ($projections as $modelId => $projection) {
            $items[] = [
                'model_id'   => $modelId,
                'projection' => $projection,
            ];
        }

        try {
            $response = $this->sendWithRetry('POST', 'import/batch', ['products' => $items], $channel);
            $statusCode = $response['status'];
            $body = $response['body'];

            if ($statusCode >= 200 && $statusCode < 300) {
                $batchResults = $body['results'] ?? [];
                foreach ($projections as $modelId => $projection) {
                    $itemResult = $batchResults[$modelId] ?? ($body['success'] ?? true);
                    $results[$modelId] = (bool)$itemResult;
                }
                Yii::info(
                    "RosMatrasChannel[{$channel->name}]: batch pushed " . count($projections) . " models → HTTP {$statusCode}",
                    'marketplace.export'
                );
            } else {
                foreach ($projections as $modelId => $projection) {
                    $results[$modelId] = false;
                }
                Yii::error(
                    "RosMatrasChannel[{$channel->name}]: batch failed HTTP {$statusCode}",
                    'marketplace.export'
                );
            }
        } catch (MarketplaceUnavailableException $e) {
            throw $e;
        } catch (ChannelValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            foreach ($projections as $modelId => $projection) {
                $results[$modelId] = false;
            }
            Yii::error(
                "RosMatrasChannel[{$channel->name}]: batch error: {$e->getMessage()}",
                'marketplace.export'
            );
        }

        return $results;
    }

    // ═══════════════════════════════════════════
    // ApiClientInterface — Fast-Lane (Price / Stock)
    // ═══════════════════════════════════════════

    /**
     * {@inheritdoc}
     *
     * RosMatras не имеет отдельного эндпоинта для цен.
     * Отправляем через /import/prices (если доступен) или через /import/product.
     */
    public function pushPrices(array $priceItems, SalesChannel $channel): bool
    {
        if (empty($priceItems)) {
            return true;
        }

        $payload = [
            'type'  => 'price_update',
            'items' => $priceItems,
        ];

        try {
            // Пробуем специализированный эндпоинт
            $response = $this->sendWithRetry('POST', 'import/prices', $payload, $channel);
        } catch (MarketplaceUnavailableException $e) {
            throw $e;
        }

        $statusCode = $response['status'];
        $body = $response['body'];

        if ($statusCode >= 200 && $statusCode < 300) {
            Yii::info(
                "RosMatrasChannel[{$channel->name}]: pushed " . count($priceItems) . " price updates → HTTP {$statusCode}",
                'marketplace.export'
            );
            return true;
        }

        $errorMsg = $body['message'] ?? ($body['error'] ?? json_encode($body));

        // 4xx → DLQ
        if ($statusCode >= 400 && $statusCode < 500) {
            throw new ChannelValidationException(
                "HTTP {$statusCode}: {$errorMsg}",
                $statusCode,
                $channel->name,
                $payload
            );
        }

        Yii::error(
            "RosMatrasChannel[{$channel->name}]: pushPrices failed → HTTP {$statusCode}: {$errorMsg}",
            'marketplace.export'
        );
        return false;
    }

    /**
     * {@inheritdoc}
     *
     * RosMatras не имеет отдельного эндпоинта для остатков.
     * Отправляем через /import/stocks (если доступен) или через /import/product.
     */
    public function pushStocks(array $stockItems, SalesChannel $channel): bool
    {
        if (empty($stockItems)) {
            return true;
        }

        $payload = [
            'type'  => 'stock_update',
            'items' => $stockItems,
        ];

        try {
            $response = $this->sendWithRetry('POST', 'import/stocks', $payload, $channel);
        } catch (MarketplaceUnavailableException $e) {
            throw $e;
        }

        $statusCode = $response['status'];
        $body = $response['body'];

        if ($statusCode >= 200 && $statusCode < 300) {
            Yii::info(
                "RosMatrasChannel[{$channel->name}]: pushed " . count($stockItems) . " stock updates → HTTP {$statusCode}",
                'marketplace.export'
            );
            return true;
        }

        $errorMsg = $body['message'] ?? ($body['error'] ?? json_encode($body));

        // 4xx → DLQ
        if ($statusCode >= 400 && $statusCode < 500) {
            throw new ChannelValidationException(
                "HTTP {$statusCode}: {$errorMsg}",
                $statusCode,
                $channel->name,
                $payload
            );
        }

        Yii::error(
            "RosMatrasChannel[{$channel->name}]: pushStocks failed → HTTP {$statusCode}: {$errorMsg}",
            'marketplace.export'
        );
        return false;
    }

    // ═══════════════════════════════════════════
    // ApiClientInterface — Category Tree
    // ═══════════════════════════════════════════

    /**
     * {@inheritdoc}
     * Отправка структуры каталога на витрину.
     */
    public function pushCategoryTree(array $payload, SalesChannel $channel): bool
    {
        try {
            $response = $this->sendWithRetry('POST', 'import/catalog', $payload, $channel);
        } catch (MarketplaceUnavailableException $e) {
            throw $e;
        }

        $statusCode = $response['status'];
        $body = $response['body'];

        if ($statusCode >= 200 && $statusCode < 300) {
            $previewId = $payload['preview_id'] ?? '?';
            Yii::info(
                "RosMatrasChannel[{$channel->name}]: pushed catalog tree preview_id={$previewId} → HTTP {$statusCode}",
                'catalog.builder'
            );
            return true;
        }

        $errorMsg = $body['message'] ?? ($body['error'] ?? json_encode($body));

        // 4xx → DLQ
        if ($statusCode >= 400 && $statusCode < 500) {
            throw new ChannelValidationException(
                "HTTP {$statusCode}: {$errorMsg}",
                $statusCode,
                $channel->name,
                $payload
            );
        }

        Yii::error(
            "RosMatrasChannel[{$channel->name}]: pushCategoryTree failed → HTTP {$statusCode}: {$errorMsg}",
            'catalog.builder'
        );
        return false;
    }

    // ═══════════════════════════════════════════
    // Health Check
    // ═══════════════════════════════════════════

    /**
     * {@inheritdoc}
     */
    public function healthCheck(SalesChannel $channel): bool
    {
        try {
            $client = $this->getClientForChannel($channel);
            $response = $client->get('health');
            return $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
        } catch (\Throwable $e) {
            Yii::warning(
                "RosMatrasChannel[{$channel->name}]: health check failed: {$e->getMessage()}",
                'marketplace.export'
            );
            return false;
        }
    }

    // ═══════════════════════════════════════════
    // HTTP Transport
    // ═══════════════════════════════════════════

    /**
     * HTTP-запрос с retry.
     *
     * @return array ['status' => int, 'body' => array]
     * @throws MarketplaceUnavailableException
     */
    protected function sendWithRetry(string $method, string $uri, ?array $payload, SalesChannel $channel): array
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            try {
                $result = $this->send($method, $uri, $payload, $channel);
                $statusCode = $result['status'];

                // 4xx — сразу возвращаем (не retry: клиентская ошибка)
                if ($statusCode >= 400 && $statusCode < 500) {
                    return $result;
                }

                if (in_array($statusCode, [500, 502, 503, 504, 429])) {
                    if ($attempt < $this->maxRetries) {
                        $delay = MarketplaceUnavailableException::calculateBackoff($attempt, 2, 30);
                        Yii::warning(
                            "RosMatrasChannel[{$channel->name}]: HTTP {$statusCode} on {$method} {$uri}, retry {$attempt}/{$this->maxRetries} in {$delay}s",
                            'marketplace.export'
                        );
                        sleep($delay);
                        continue;
                    }

                    throw new MarketplaceUnavailableException(
                        "API returned HTTP {$statusCode} on {$method} {$uri} after {$this->maxRetries} attempts (channel: {$channel->name})",
                        $statusCode
                    );
                }

                return $result;

            } catch (MarketplaceUnavailableException $e) {
                throw $e;
            } catch (ConnectException $e) {
                $lastException = $e;
                if ($attempt < $this->maxRetries) {
                    $delay = MarketplaceUnavailableException::calculateBackoff($attempt, 2, 30);
                    Yii::warning(
                        "RosMatrasChannel[{$channel->name}]: connect error on {$method} {$uri}: {$e->getMessage()}, retry {$attempt}/{$this->maxRetries} in {$delay}s",
                        'marketplace.export'
                    );
                    sleep($delay);
                    continue;
                }

                throw new MarketplaceUnavailableException(
                    "Connection failed for {$method} {$uri} (channel: {$channel->name}): {$e->getMessage()}",
                    null,
                    null,
                    $e
                );
            }
        }

        throw new MarketplaceUnavailableException(
            "All {$this->maxRetries} attempts failed for {$method} {$uri} (channel: {$channel->name})",
            null,
            null,
            $lastException
        );
    }

    /**
     * Один HTTP-запрос.
     */
    protected function send(string $method, string $uri, ?array $payload, SalesChannel $channel): array
    {
        $client = $this->getClientForChannel($channel);

        $options = [];
        if ($payload !== null) {
            $options[RequestOptions::JSON] = $payload;
        }

        try {
            $response = $client->request($method, $uri, $options);
        } catch (ConnectException $e) {
            throw $e;
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                $response = $e->getResponse();
            } else {
                throw new MarketplaceUnavailableException(
                    "Request failed for {$method} {$uri}: {$e->getMessage()}",
                    null,
                    null,
                    $e
                );
            }
        }

        $statusCode = $response->getStatusCode();
        $bodyStr = (string)$response->getBody();
        $body = json_decode($bodyStr, true) ?: [];

        return [
            'status' => $statusCode,
            'body'   => $body,
        ];
    }

    /**
     * Получить / создать Guzzle-клиент для канала.
     * Токены берутся из api_config канала.
     */
    private function getClientForChannel(SalesChannel $channel): Client
    {
        $channelId = $channel->id;

        if (!isset($this->clients[$channelId])) {
            $apiUrl = $channel->getConfigValue('apiUrl', '');
            $apiToken = $channel->getConfigValue('apiToken', '');

            $headers = [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
                'User-Agent'   => 'Agregator/3.0 RosMatrasChannel',
            ];

            if (!empty($apiToken)) {
                $headers['Authorization'] = 'Bearer ' . $apiToken;
            }

            $this->clients[$channelId] = new Client([
                'base_uri'        => rtrim($apiUrl, '/') . '/',
                'timeout'         => $this->requestTimeout,
                'connect_timeout' => $this->connectTimeout,
                'headers'         => $headers,
                'http_errors'     => false,
                'verify'          => false,
                'proxy'           => '',
            ]);
        }

        return $this->clients[$channelId];
    }
}
