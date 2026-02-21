<?php

namespace common\jobs;

use common\exceptions\AiRateLimitException;
use common\models\ModelChannelReadiness;
use common\models\SalesChannel;
use common\services\AutoHealingService;
use yii\base\BaseObject;
use yii\queue\JobInterface;
use yii\queue\Queue;
use yii\queue\RetryableJobInterface;
use Yii;

/**
 * Джоба: AI-лечение одной модели товара.
 *
 * Запускается из quality/heal (fan-out) или напрямую.
 * Реализует RetryableJobInterface для управления ретраями.
 *
 * === Логика ===
 * 1. Загружает модель и канал
 * 2. Получает missing_fields из model_channel_readiness
 * 3. Вызывает AutoHealingService->healModel()
 * 4. При 429/502/503 (AiRateLimitException) — sleep + retry
 * 5. При других ошибках — до 3 попыток
 *
 * Использование:
 *   Yii::$app->queue->push(new HealModelJob([
 *       'modelId' => 123,
 *       'channelId' => 1,
 *   ]));
 *
 *   // С задержкой (для rate limit):
 *   Yii::$app->queue->delay(30)->push(new HealModelJob([...]));
 */
class HealModelJob extends BaseObject implements RetryableJobInterface
{
    /** @var int ID product_model */
    public int $modelId = 0;

    /** @var int ID sales_channel */
    public int $channelId = 0;

    /** @var array Пропущенные поля (опционально, если не указаны — берутся из readiness cache) */
    public array $missingFields = [];

    /** @var int Текущая попытка (инкрементируется при ретрае) */
    public int $attempt = 0;

    /**
     * Тайм-аут выполнения джобы (секунды).
     * ИИ может думать долго (30-60с на один запрос), + описание + атрибуты = до 120с.
     */
    public function getTtr(): int
    {
        return 120;
    }

    /**
     * Можно ли повторить джобу при ошибке.
     *
     * @param int $attempt Номер попытки (начиная с 1)
     * @param \Throwable $error Ошибка, вызвавшая фейл
     * @return bool
     */
    public function canRetry(int $attempt, \Throwable $error): bool
    {
        // AiRateLimitException (429/502/503) — всегда повторяем (до 5 раз)
        if ($error instanceof AiRateLimitException) {
            return $attempt <= 5;
        }

        // Другие ошибки — до 3 попыток
        return $attempt <= 3;
    }

    /**
     * @param Queue $queue
     */
    public function execute($queue): void
    {
        if ($this->modelId <= 0 || $this->channelId <= 0) {
            Yii::warning("HealModelJob: невалидные параметры modelId={$this->modelId} channelId={$this->channelId}", 'ai.healing');
            return;
        }

        $this->attempt++;

        // Загружаем канал
        $channel = SalesChannel::findOne($this->channelId);
        if (!$channel) {
            Yii::warning("HealModelJob: канал #{$this->channelId} не найден", 'ai.healing');
            return;
        }

        // Если missing_fields не переданы — берём из кэша readiness
        $missingFields = $this->missingFields;
        if (empty($missingFields)) {
            $readiness = ModelChannelReadiness::findOne([
                'model_id' => $this->modelId,
                'channel_id' => $this->channelId,
            ]);

            if (!$readiness || $readiness->is_ready) {
                Yii::info("HealModelJob: модель #{$this->modelId} уже готова или нет данных readiness", 'ai.healing');
                return;
            }

            $missingFields = is_array($readiness->missing_fields)
                ? $readiness->missing_fields
                : json_decode($readiness->missing_fields ?? '[]', true);
        }

        if (empty($missingFields)) {
            Yii::info("HealModelJob: модель #{$this->modelId} нет пропущенных полей", 'ai.healing');
            return;
        }

        /** @var AutoHealingService $healer */
        $healer = Yii::$app->get('autoHealer');

        try {
            $result = $healer->healModel($this->modelId, $missingFields, $channel);

            if ($result->success) {
                $status = $result->isFullyHealed() ? 'READY → Outbox' : "healed {$result->healedCount()} fields";
                Yii::info(
                    "HealModelJob: модель #{$this->modelId} — {$status} (score: {$result->newScore}%)",
                    'ai.healing'
                );
            } else {
                $errMsg = implode('; ', $result->errors);
                Yii::warning("HealModelJob: модель #{$this->modelId} — не удалось: {$errMsg}", 'ai.healing');
            }

        } catch (AiRateLimitException $e) {
            // Rate Limit — спим и пробрасываем для ретрая через canRetry()
            $sleepSec = $e->retryAfterSec;
            Yii::warning(
                "HealModelJob: модель #{$this->modelId} — Rate Limit (HTTP {$e->httpCode}), " .
                "sleep {$sleepSec}s, attempt={$this->attempt}",
                'ai.healing'
            );

            // Спим, чтобы дать API передышку
            sleep($sleepSec);

            // Пробрасываем — yii2-queue вызовет canRetry() и поставит джобу на ретрай
            throw $e;
        }
        // Другие исключения пробрасываются автоматически → canRetry() решит
    }
}
