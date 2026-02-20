<?php

namespace common\services\marketplace;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;
use yii\base\Component;
use Yii;

/**
 * Боевой HTTP-клиент для RosMatras API.
 *
 * Реализует MarketplaceApiClientInterface через GuzzleHttp.
 *
 * Эндпоинты:
 *   POST /api/v1/import/product   — одна проекция
 *   POST /api/v1/import/batch     — пакетная отправка
 *   DELETE /api/v1/import/product/{modelId}  — удаление
 *   GET  /api/v1/health            — health check
 *
 * Авторизация: Bearer token в заголовке Authorization.
 *
 * Обработка ошибок:
 *   - 429 (Too Many Requests) → MarketplaceUnavailableException
 *   - 500, 502, 503, 504       → MarketplaceUnavailableException
 *   - Таймаут, connection refused → MarketplaceUnavailableException
 *   - 400, 404, 422              → RuntimeException (ошибка данных, не ретраить)
 *
 * Конфигурация (в common/config/main.php):
 *   'marketplaceClient' => [
 *       'class' => RosMatrasApiClient::class,
 *       'apiUrl'   => 'http://rosmatras.local/api/v1',
 *       'apiToken' => 'secret-token',
 *   ],
 *
 * Или через params:
 *   'rosmatras' => [
 *       'apiUrl'   => 'http://localhost:8080/api/v1',
 *       'apiToken' => 'Bearer token',
 *   ],
 */
class RosMatrasApiClient extends Component implements MarketplaceApiClientInterface
{
    /** @var string Базовый URL API (без trailing slash) */
    public string $apiUrl = '';

    /** @var string Bearer-токен для авторизации */
    public string $apiToken = '';

    /** @var int Таймаут подключения (секунды) */
    public int $connectTimeout = 10;

    /** @var int Таймаут запроса (секунды) */
    public int $requestTimeout = 30;

    /** @var int Максимум попыток на один запрос (для transient errors) */
    public int $maxRetries = 2;

    /** @var Client|null Переиспользуемый Guzzle-клиент */
    private ?Client $client = null;

    /** @var array Статистика текущей сессии */
    private array $stats = [
        'pushed'  => 0,
        'errors'  => 0,
        'deleted' => 0,
        'retries' => 0,
    ];

    /**
     * Инициализация: подтянуть URL и токен из params, если не заданы напрямую.
     */
    public function init(): void
    {
        parent::init();

        if (empty($this->apiUrl)) {
            $this->apiUrl = Yii::$app->params['rosmatras']['apiUrl']
                ?? (getenv('ROSMATRAS_API_URL') ?: '');
        }

        if (empty($this->apiToken)) {
            $this->apiToken = Yii::$app->params['rosmatras']['apiToken']
                ?? (getenv('ROSMATRAS_API_TOKEN') ?: '');
        }
    }

    /**
     * Получить или создать Guzzle-клиент.
     */
    protected function getClient(): Client
    {
        if ($this->client === null) {
            $headers = [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
                'User-Agent'   => 'Agregator/3.0 RosMatrasClient',
            ];

            if (!empty($this->apiToken)) {
                $headers['Authorization'] = 'Bearer ' . $this->apiToken;
            }

            $this->client = new Client([
                'base_uri'        => rtrim($this->apiUrl, '/') . '/',
                'timeout'         => $this->requestTimeout,
                'connect_timeout' => $this->connectTimeout,
                'headers'         => $headers,
                'http_errors'     => false,  // Не бросать исключения на 4xx/5xx
                'verify'          => false,  // Отключить SSL-верификацию для dev
                'proxy'           => '',     // Отключить прокси (Docker internal)
            ]);
        }

        return $this->client;
    }

    // ═══════════════════════════════════════════
    // MarketplaceApiClientInterface
    // ═══════════════════════════════════════════

    /**
     * {@inheritdoc}
     *
     * POST /api/v1/import/product
     * Body: { "model_id": 123, "projection": { ... } }
     */
    public function pushProduct(int $modelId, array $projection): bool
    {
        $payload = [
            'model_id'   => $modelId,
            'projection' => $projection,
        ];

        $response = $this->sendWithRetry('POST', 'import/product', $payload);

        $statusCode = $response['status'];
        $body = $response['body'];

        if ($statusCode >= 200 && $statusCode < 300) {
            $this->stats['pushed']++;
            Yii::info(
                "RosMatrasApiClient: pushed model_id={$modelId} → HTTP {$statusCode}",
                'marketplace.export'
            );
            return true;
        }

        // Клиентская ошибка (400, 404, 422) — не ретраить, ошибка в данных
        $this->stats['errors']++;
        $errorMsg = $body['message'] ?? ($body['error'] ?? json_encode($body));
        Yii::error(
            "RosMatrasApiClient: pushProduct model_id={$modelId} → HTTP {$statusCode}: {$errorMsg}",
            'marketplace.export'
        );
        throw new \RuntimeException(
            "API error for model_id={$modelId}: HTTP {$statusCode} — {$errorMsg}"
        );
    }

    /**
     * {@inheritdoc}
     *
     * POST /api/v1/import/batch
     * Body: { "products": [ { "model_id": 1, "projection": {...} }, ... ] }
     */
    public function pushBatch(array $projections): array
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
            $response = $this->sendWithRetry('POST', 'import/batch', [
                'products' => $items,
            ]);

            $statusCode = $response['status'];
            $body = $response['body'];

            if ($statusCode >= 200 && $statusCode < 300) {
                // Парсим результаты из ответа
                $batchResults = $body['results'] ?? [];
                foreach ($projections as $modelId => $projection) {
                    $itemResult = $batchResults[$modelId] ?? ($body['success'] ?? true);
                    $results[$modelId] = (bool)$itemResult;
                    if ($results[$modelId]) {
                        $this->stats['pushed']++;
                    } else {
                        $this->stats['errors']++;
                    }
                }

                Yii::info(
                    "RosMatrasApiClient: batch pushed " . count($projections) . " models → HTTP {$statusCode}",
                    'marketplace.export'
                );
            } else {
                // Ошибка всего батча
                foreach ($projections as $modelId => $projection) {
                    $results[$modelId] = false;
                    $this->stats['errors']++;
                }

                Yii::error(
                    "RosMatrasApiClient: batch failed HTTP {$statusCode}",
                    'marketplace.export'
                );
            }
        } catch (MarketplaceUnavailableException $e) {
            // Пробрасываем — worker обработает
            throw $e;
        } catch (\Throwable $e) {
            // Индивидуальные ошибки — помечаем все как failed
            foreach ($projections as $modelId => $projection) {
                $results[$modelId] = false;
                $this->stats['errors']++;
            }

            Yii::error(
                "RosMatrasApiClient: batch error: {$e->getMessage()}",
                'marketplace.export'
            );
        }

        return $results;
    }

    /**
     * {@inheritdoc}
     *
     * DELETE /api/v1/import/product/{modelId}
     */
    public function deleteProduct(int $modelId): bool
    {
        try {
            $response = $this->sendWithRetry('DELETE', "import/product/{$modelId}");

            $statusCode = $response['status'];

            if ($statusCode >= 200 && $statusCode < 300) {
                $this->stats['deleted']++;
                Yii::info(
                    "RosMatrasApiClient: deleted model_id={$modelId} → HTTP {$statusCode}",
                    'marketplace.export'
                );
                return true;
            }

            if ($statusCode === 404) {
                // Уже нет на витрине — считаем успехом
                $this->stats['deleted']++;
                return true;
            }

            return false;
        } catch (MarketplaceUnavailableException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->stats['errors']++;
            Yii::error(
                "RosMatrasApiClient: delete model_id={$modelId} error: {$e->getMessage()}",
                'marketplace.export'
            );
            return false;
        }
    }

    /**
     * {@inheritdoc}
     *
     * GET /api/v1/health
     */
    public function healthCheck(): bool
    {
        try {
            $client = $this->getClient();
            $response = $client->get('health');
            $statusCode = $response->getStatusCode();
            return $statusCode >= 200 && $statusCode < 300;
        } catch (\Throwable $e) {
            Yii::warning(
                "RosMatrasApiClient: health check failed: {$e->getMessage()}",
                'marketplace.export'
            );
            return false;
        }
    }

    // ═══════════════════════════════════════════
    // STATS
    // ═══════════════════════════════════════════

    public function getStats(): array
    {
        return $this->stats;
    }

    public function resetStats(): void
    {
        $this->stats = ['pushed' => 0, 'errors' => 0, 'deleted' => 0, 'retries' => 0];
    }

    // ═══════════════════════════════════════════
    // PRIVATE: HTTP с retry и обработкой ошибок
    // ═══════════════════════════════════════════

    /**
     * Отправить HTTP-запрос с автоматическим retry для transient errors.
     *
     * @return array ['status' => int, 'body' => array, 'headers' => array]
     * @throws MarketplaceUnavailableException Если API недоступен после всех попыток
     */
    protected function sendWithRetry(string $method, string $uri, ?array $payload = null): array
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            try {
                $result = $this->send($method, $uri, $payload);

                $statusCode = $result['status'];

                // Transient server errors → retry
                if (in_array($statusCode, [429, 500, 502, 503, 504])) {
                    $retryAfter = $result['headers']['Retry-After'][0] ?? null;
                    $retrySeconds = $retryAfter ? (int)$retryAfter : null;

                    if ($attempt < $this->maxRetries) {
                        $delay = $retrySeconds
                            ?? MarketplaceUnavailableException::calculateBackoff($attempt, 2, 30);
                        $this->stats['retries']++;

                        Yii::warning(
                            "RosMatrasApiClient: HTTP {$statusCode} on {$method} {$uri}, " .
                            "retry {$attempt}/{$this->maxRetries} in {$delay}s",
                            'marketplace.export'
                        );

                        sleep($delay);
                        continue;
                    }

                    // Все попытки исчерпаны
                    throw new MarketplaceUnavailableException(
                        "API returned HTTP {$statusCode} on {$method} {$uri} after {$this->maxRetries} attempts",
                        $statusCode,
                        $retrySeconds
                    );
                }

                // Успех или клиентская ошибка — возвращаем
                return $result;

            } catch (MarketplaceUnavailableException $e) {
                throw $e;
            } catch (ConnectException $e) {
                // Сетевая ошибка: таймаут, DNS, connection refused
                $lastException = $e;

                if ($attempt < $this->maxRetries) {
                    $delay = MarketplaceUnavailableException::calculateBackoff($attempt, 2, 30);
                    $this->stats['retries']++;

                    Yii::warning(
                        "RosMatrasApiClient: connect error on {$method} {$uri}: {$e->getMessage()}, " .
                        "retry {$attempt}/{$this->maxRetries} in {$delay}s",
                        'marketplace.export'
                    );

                    sleep($delay);
                    continue;
                }

                throw new MarketplaceUnavailableException(
                    "Connection failed for {$method} {$uri}: {$e->getMessage()}",
                    null,
                    null,
                    $e
                );
            }
        }

        // Не должны сюда попасть, но на всякий случай
        throw new MarketplaceUnavailableException(
            "All {$this->maxRetries} attempts failed for {$method} {$uri}",
            null,
            null,
            $lastException
        );
    }

    /**
     * Один HTTP-запрос (без retry).
     *
     * @return array ['status' => int, 'body' => array, 'headers' => array]
     */
    protected function send(string $method, string $uri, ?array $payload = null): array
    {
        $client = $this->getClient();

        $options = [];
        if ($payload !== null) {
            $options[RequestOptions::JSON] = $payload;
        }

        try {
            $response = $client->request($method, $uri, $options);
        } catch (ConnectException $e) {
            // Пробрасываем — обрабатывается в sendWithRetry
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
        $headers = $response->getHeaders();

        return [
            'status'  => $statusCode,
            'body'    => $body,
            'headers' => $headers,
        ];
    }
}
