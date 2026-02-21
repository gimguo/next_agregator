<?php

namespace common\exceptions;

/**
 * Исключение: AI API вернул 429 (Rate Limit) или 502/503 (сервер перегружен).
 *
 * НЕ считается окончательной ошибкой — задачу можно повторить позже.
 * HealModelJob ловит это исключение и возвращает задачу в очередь с задержкой.
 */
class AiRateLimitException extends \RuntimeException
{
    /** @var int Рекомендованная задержка перед повтором (секунды) */
    public int $retryAfterSec;

    /** @var int HTTP-код ответа (429, 502, 503) */
    public int $httpCode;

    public function __construct(int $httpCode = 429, int $retryAfterSec = 15, string $message = '', ?\Throwable $previous = null)
    {
        $this->httpCode = $httpCode;
        $this->retryAfterSec = $retryAfterSec;

        if (empty($message)) {
            $message = match ($httpCode) {
                429 => "AI Rate Limit (429): повтор через {$retryAfterSec}s",
                502 => "AI Backend Error (502): сервер перегружен, повтор через {$retryAfterSec}s",
                503 => "AI Service Unavailable (503): повтор через {$retryAfterSec}s",
                default => "AI HTTP {$httpCode}: повтор через {$retryAfterSec}s",
            };
        }

        parent::__construct($message, $httpCode, $previous);
    }
}
