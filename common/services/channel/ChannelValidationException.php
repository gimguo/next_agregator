<?php

namespace common\services\channel;

/**
 * Исключение: ошибка валидации данных на стороне канала (4xx).
 *
 * Маркетплейс (или витрина) отклонил данные как невалидные.
 * Retry бесполезен — данные нужно исправить на стороне агрегатора.
 *
 * При перехвате этого исключения воркер должен:
 *   1. Поменять статус outbox-записи на 'failed' (не 'error' для retry)
 *   2. Сохранить ошибку в channel_sync_errors (DLQ)
 *
 * Содержит:
 *   - HTTP status code (400, 422, ...)
 *   - Текст ошибки от маркетплейса
 *   - Дамп отправленного payload (для отладки)
 */
class ChannelValidationException extends \RuntimeException
{
    private int $httpCode;
    private ?array $payloadDump;
    private string $channelName;

    public function __construct(
        string $message,
        int    $httpCode = 422,
        string $channelName = '',
        ?array $payloadDump = null,
        ?\Throwable $previous = null
    ) {
        $this->httpCode = $httpCode;
        $this->payloadDump = $payloadDump;
        $this->channelName = $channelName;

        parent::__construct($message, $httpCode, $previous);
    }

    public function getHttpCode(): int
    {
        return $this->httpCode;
    }

    public function getPayloadDump(): ?array
    {
        return $this->payloadDump;
    }

    public function getChannelName(): string
    {
        return $this->channelName;
    }
}
