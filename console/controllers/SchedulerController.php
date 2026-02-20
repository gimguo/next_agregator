<?php

namespace console\controllers;

use yii\console\Controller;
use yii\console\ExitCode;
use Yii;

/**
 * Планировщик задач.
 *
 * Запускается в Docker-контейнере и выполняет периодические задачи:
 * - Автоматический сбор прайсов по расписанию
 * - Проверка обновлений от поставщиков
 * - Очистка устаревших данных
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
        $this->stdout("Scheduler: запущен (interval={$this->interval}s)\n");

        while (true) {
            try {
                $this->tick();
            } catch (\Throwable $e) {
                Yii::error("Scheduler error: {$e->getMessage()}", 'scheduler');
                $this->stderr("Scheduler error: {$e->getMessage()}\n");
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
        $now = new \DateTimeImmutable('now', new \DateTimeZone(Yii::$app->timeZone));
        $minute = (int)$now->format('i');
        $hour = (int)$now->format('G');

        // Каждый час: проверяем, нужно ли забрать прайсы
        if ($minute === 0) {
            $this->checkScheduledFetches($hour);
        }

        // Раз в 5 минут: мониторинг очереди
        if ($minute % 5 === 0) {
            $this->logQueueStats();
        }
    }

    /**
     * Проверить расписание автоматического сбора прайсов.
     */
    protected function checkScheduledFetches(int $hour): void
    {
        $db = Yii::$app->db;

        // Получаем поставщиков с настроенным расписанием
        $suppliers = $db->createCommand("
            SELECT id, code, name, config
            FROM {{%suppliers}}
            WHERE is_active = true
              AND config IS NOT NULL
              AND config::text != '{}'
        ")->queryAll();

        foreach ($suppliers as $supplier) {
            $config = json_decode($supplier['config'] ?? '{}', true);
            $schedule = $config['schedule'] ?? null;

            if (!$schedule) continue;

            // Простая проверка cron-формата: "0 6 * * *" → час = 6
            $parts = explode(' ', $schedule);
            $cronHour = $parts[1] ?? '*';

            if ($cronHour === '*' || (int)$cronHour === $hour) {
                Yii::info("Scheduler: запуск автосбора для {$supplier['code']}", 'scheduler');
                $this->stdout("  → Автосбор: {$supplier['code']}\n");

                // TODO: Yii::$app->queue->push(new FetchPriceJob(...))
            }
        }
    }

    /**
     * Логировать статистику очереди.
     */
    protected function logQueueStats(): void
    {
        try {
            $redis = Yii::$app->redis;
            $waiting = $redis->executeCommand('LLEN', ['agregator-queue.waiting']);
            $reserved = $redis->executeCommand('ZCARD', ['agregator-queue.reserved']);

            if ($waiting > 0 || $reserved > 0) {
                $this->stdout("  Queue: waiting={$waiting} reserved={$reserved}\n");
            }
        } catch (\Throwable $e) {
            // Redis может быть недоступен
        }
    }
}
