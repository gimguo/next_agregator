<?php

namespace console\controllers;

use common\jobs\FetchPriceJob;
use common\models\SupplierFetchConfig;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;
use Yii;

/**
 * Планировщик задач.
 *
 * Запускается в Docker-контейнере и выполняет периодические задачи:
 * - Автоматический сбор прайсов по расписанию
 * - Проверка обновлений от поставщиков
 * - Мониторинг очереди
 * - Авто-ретрай failed картинок
 *
 * Использование:
 *   php yii scheduler/run
 */
class SchedulerController extends Controller
{
    /** @var int Интервал между тиками (секунды) */
    public int $interval = 60;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['interval']);
    }

    /**
     * Основной цикл планировщика.
     */
    public function actionRun(): int
    {
        $this->stdout("Scheduler: запущен (interval={$this->interval}s)\n", Console::FG_GREEN);

        while (true) {
            try {
                $this->tick();
            } catch (\Throwable $e) {
                Yii::error("Scheduler error: {$e->getMessage()}", 'scheduler');
                $this->stderr("Scheduler error: {$e->getMessage()}\n", Console::FG_RED);
            }

            sleep($this->interval);
        }

        return ExitCode::OK;
    }

    /**
     * Один тик планировщика.
     */
    protected function tick(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone(Yii::$app->timeZone ?? 'UTC'));
        $minute = (int)$now->format('i');
        $hour = (int)$now->format('G');
        $dayOfWeek = (int)$now->format('w'); // 0=Sunday
        $dayOfMonth = (int)$now->format('j');

        // Каждую минуту: проверяем cron-расписание поставщиков
        $this->checkScheduledFetches($minute, $hour, $dayOfWeek, $dayOfMonth);

        // Раз в 5 минут: мониторинг очереди
        if ($minute % 5 === 0) {
            $this->logQueueStats();
        }

        // Раз в час: retry failed images (max 100)
        if ($minute === 30) {
            $this->retryFailedImages();
        }

        // Раз в день (в 3:00): деактивация устаревших офферов
        if ($hour === 3 && $minute === 0) {
            $this->deactivateStaleOffers();
        }
    }

    /**
     * Проверить cron-расписание автоматического сбора прайсов.
     */
    protected function checkScheduledFetches(int $minute, int $hour, int $dow, int $dom): void
    {
        $configs = SupplierFetchConfig::find()
            ->with('supplier')
            ->where(['is_enabled' => true])
            ->andWhere(['!=', 'fetch_method', 'manual'])
            ->all();

        foreach ($configs as $config) {
            if (!$config->supplier || !$config->supplier->is_active) {
                continue;
            }

            $shouldRun = false;

            // Cron-расписание
            if ($config->schedule_cron) {
                $shouldRun = $this->matchCron($config->schedule_cron, $minute, $hour, $dom, $dow);
            }
            // Интервальное расписание
            elseif ($config->schedule_interval_hours > 0) {
                $lastFetch = $config->last_fetch_at ? strtotime($config->last_fetch_at) : 0;
                $intervalSec = $config->schedule_interval_hours * 3600;
                $shouldRun = (time() - $lastFetch) >= $intervalSec;
            }

            if ($shouldRun) {
                $this->enqueueFetch($config);
            }
        }
    }

    /**
     * Простой матчинг cron-выражения (min hour dom * dow).
     */
    protected function matchCron(string $cron, int $minute, int $hour, int $dom, int $dow): bool
    {
        $parts = preg_split('/\s+/', trim($cron));
        if (count($parts) < 5) return false;

        [$cronMin, $cronHour, $cronDom, $cronMon, $cronDow] = $parts;

        return $this->matchCronField($cronMin, $minute)
            && $this->matchCronField($cronHour, $hour)
            && $this->matchCronField($cronDom, $dom)
            // month — не проверяем для простоты
            && $this->matchCronField($cronDow, $dow);
    }

    protected function matchCronField(string $field, int $value): bool
    {
        if ($field === '*') return true;

        // Поддержка */N
        if (str_starts_with($field, '*/')) {
            $step = (int)substr($field, 2);
            return $step > 0 && ($value % $step === 0);
        }

        // Поддержка списка: 1,5,10
        $values = array_map('intval', explode(',', $field));
        return in_array($value, $values, true);
    }

    /**
     * Поставить задачу забора прайса в очередь.
     */
    protected function enqueueFetch(SupplierFetchConfig $config): void
    {
        $supplier = $config->supplier;

        Yii::info("Scheduler: автосбор для {$supplier->code} (метод: {$config->fetch_method})", 'scheduler');
        $this->stdout("  → Автосбор: {$supplier->code} ({$config->fetch_method})\n", Console::FG_CYAN);

        Yii::$app->queue->push(new FetchPriceJob([
            'supplierCode' => $supplier->code,
            'fetchConfigId' => $config->id,
        ]));
    }

    /**
     * Retry failed image downloads.
     */
    protected function retryFailedImages(): void
    {
        $count = Yii::$app->db->createCommand("
            UPDATE {{%card_images}} 
            SET status = 'pending', error_message = NULL
            WHERE status = 'failed' AND attempts < 3
        ")->execute();

        if ($count > 0) {
            $this->stdout("  Retry: {$count} failed images reset to pending\n", Console::FG_YELLOW);
            Yii::info("Scheduler: reset {$count} failed images to pending", 'scheduler');
        }
    }

    /**
     * Деактивация офферов, не обновлявшихся >7 дней.
     */
    protected function deactivateStaleOffers(): void
    {
        $count = Yii::$app->db->createCommand("
            UPDATE {{%supplier_offers}} 
            SET is_active = false 
            WHERE is_active = true 
              AND updated_at < NOW() - INTERVAL '7 days'
        ")->execute();

        if ($count > 0) {
            $this->stdout("  Stale: деактивировано {$count} устаревших офферов\n", Console::FG_YELLOW);
            Yii::info("Scheduler: deactivated {$count} stale offers", 'scheduler');

            // Обновить has_active_offers у карточек
            Yii::$app->db->createCommand("
                UPDATE {{%product_cards}} pc SET has_active_offers = EXISTS(
                    SELECT 1 FROM {{%supplier_offers}} so 
                    WHERE so.card_id = pc.id AND so.is_active = true
                )
                WHERE pc.has_active_offers = true
            ")->execute();
        }
    }

    /**
     * Логировать статистику очереди.
     */
    protected function logQueueStats(): void
    {
        try {
            $redis = Yii::$app->redis;
            $prefix = Yii::$app->queue->channel ?? 'queue';
            $waiting = $redis->executeCommand('LLEN', ["{$prefix}.waiting"]);
            $reserved = $redis->executeCommand('ZCARD', ["{$prefix}.reserved"]);
            $delayed = $redis->executeCommand('ZCARD', ["{$prefix}.delayed"]);

            if ($waiting > 0 || $reserved > 0 || $delayed > 0) {
                $this->stdout("  Queue: w={$waiting} r={$reserved} d={$delayed}\n");
            }
        } catch (\Throwable $e) {
            // Redis может быть недоступен
        }
    }
}
