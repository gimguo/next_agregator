<?php

namespace common\services\marketplace;

/**
 * Исключение: маркетплейс временно недоступен.
 *
 * Выбрасывается при HTTP-кодах 429, 500, 502, 503, 504
 * или при сетевых ошибках (таймаут, DNS, connection refused).
 *
 * ВАЖНО: Worker НЕ должен помечать запись как error при этом исключении.
 * Вместо этого запись остаётся в pending/processing для повторной попытки.
 *
 * Содержит:
 *   - HTTP status code (если есть)
 *   - Retry-After header (если сервер прислал)
 *   - Количество попыток, чтобы вычислить экспоненциальную задержку
 */
class MarketplaceUnavailableException extends \RuntimeException
{
    /** @var int|null HTTP status code */
    private ?int $httpCode;

    /** @var int|null Retry-After в секундах (из заголовка ответа) */
    private ?int $retryAfter;

    /**
     * @param string   $message
     * @param int|null $httpCode    HTTP-код ответа (429, 500, 502 и т.д.)
     * @param int|null $retryAfter  Retry-After секунды (из заголовка)
     * @param \Throwable|null $previous
     */
    public function __construct(
        string $message = 'Marketplace API is temporarily unavailable',
        ?int $httpCode = null,
        ?int $retryAfter = null,
        ?\Throwable $previous = null,
    ) {
        $this->httpCode = $httpCode;
        $this->retryAfter = $retryAfter;

        $code = $httpCode ?? 503;
        parent::__construct($message, $code, $previous);
    }

    public function getHttpCode(): ?int
    {
        return $this->httpCode;
    }

    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }

    /**
     * Рассчитать задержку до retry (экспоненциальный backoff).
     *
     * @param int $attempt Номер попытки (1, 2, 3...)
     * @param int $baseDelay Базовая задержка в секундах
     * @param int $maxDelay Максимальная задержка в секундах
     * @return int Задержка в секундах
     */
    public static function calculateBackoff(int $attempt, int $baseDelay = 5, int $maxDelay = 300): int
    {
        // Если есть Retry-After — используем его
        // Иначе: 5s, 10s, 20s, 40s, 80s, 160s, 300s (cap)
        $delay = $baseDelay * (2 ** ($attempt - 1));
        // Добавляем jitter (±20%)
        $jitter = $delay * 0.2;
        $delay = (int)($delay + mt_rand((int)(-$jitter), (int)$jitter));

        return min($delay, $maxDelay);
    }
}
