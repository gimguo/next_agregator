<?php

namespace console\controllers;

use common\jobs\FetchPriceJob;
use common\models\SupplierFetchConfig;
use Cron\CronExpression;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;
use Yii;

/**
 * Планировщик задач (Sprint 14).
 *
 * Два режима работы:
 *
 * 1. **Одиночный тик** (рекомендуется для системного cron):
 *    * * * * * docker compose exec -T php php yii scheduler/run
 *    Выполняет один проход, проверяет расписания и выходит.
 *
 * 2. **Демон** (бесконечный цикл):
 *    php yii scheduler/daemon --interval=60
 *    Работает в фоне внутри Docker-контейнера.
 *
 * Задачи:
 * - Автоматический сбор прайсов по cron-расписанию
 * - Retry failed images (раз в час)
 * - Деактивация устаревших офферов (раз в день)
 * - Мониторинг очереди
 */
class SchedulerController extends Controller
{
    /** @var int Интервал между тиками для режима демона (секунды) */
    public int $interval = 60;

    /** @var bool Подробный вывод */
    public bool $verbose = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['interval', 'verbose']);
    }

    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), [
            'v' => 'verbose',
        ]);
    }

    // ═══════════════════════════════════════════
    // ОСНОВНЫЕ КОМАНДЫ
    // ═══════════════════════════════════════════

    /**
     * Один тик планировщика (для системного cron).
     *
     * Вызывается каждую минуту:
     *   * * * * * docker compose exec -T php php yii scheduler/run
     */
    public function actionRun(): int
    {
        $startTime = microtime(true);

        if ($this->verbose) {
            $this->stdout("Scheduler: тик " . date('Y-m-d H:i:s') . "\n", Console::FG_GREEN);
        }

        try {
            $fetchCount = $this->checkScheduledFetches();
            $this->periodicTasks();

            $duration = round(microtime(true) - $startTime, 2);

            if ($fetchCount > 0 || $this->verbose) {
                $this->stdout("Scheduler: тик завершён за {$duration}s, запущено фетчей: {$fetchCount}\n", Console::FG_GREEN);
            }
        } catch (\Throwable $e) {
            Yii::error("Scheduler error: {$e->getMessage()}", 'scheduler');
            $this->stderr("Scheduler error: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    /**
     * Демон-режим (бесконечный цикл).
     *
     * php yii scheduler/daemon --interval=60
     */
    public function actionDaemon(): int
    {
        $this->stdout("\n", Console::FG_CYAN);
        $this->stdout("  ╔══════════════════════════════════════════════════════════════╗\n", Console::FG_CYAN);
        $this->stdout("  ║  ⏰ SCHEDULER DAEMON — Планировщик задач                    ║\n", Console::FG_CYAN);
        $this->stdout("  ╚══════════════════════════════════════════════════════════════╝\n\n", Console::FG_CYAN);
        $this->stdout("  Интервал: {$this->interval}s\n");
        $this->stdout("  Часовой пояс: " . (Yii::$app->timeZone ?? 'UTC') . "\n\n");

        while (true) {
            try {
                $fetchCount = $this->checkScheduledFetches();
                $this->periodicTasks();

                if ($fetchCount > 0) {
                    $this->stdout("  [" . date('H:i:s') . "] Запущено фетчей: {$fetchCount}\n", Console::FG_GREEN);
                }
            } catch (\Throwable $e) {
                Yii::error("Scheduler error: {$e->getMessage()}", 'scheduler');
                $this->stderr("  [" . date('H:i:s') . "] Ошибка: {$e->getMessage()}\n", Console::FG_RED);
            }

            sleep($this->interval);
        }
    }

    /**
     * Показать расписание всех поставщиков.
     *
     * php yii scheduler/status
     */
    public function actionStatus(): int
    {
        $this->stdout("\n  ╔══════════════════════════════════════════════════════════════╗\n", Console::FG_CYAN);
        $this->stdout("  ║  ⏰ РАСПИСАНИЕ АВТОСБОРА ПРАЙСОВ                             ║\n", Console::FG_CYAN);
        $this->stdout("  ╚══════════════════════════════════════════════════════════════╝\n\n", Console::FG_CYAN);

        $configs = SupplierFetchConfig::find()
            ->with('supplier')
            ->orderBy(['is_enabled' => SORT_DESC, 'supplier_id' => SORT_ASC])
            ->all();

        if (empty($configs)) {
            $this->stdout("  Конфигурации не найдены.\n\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $this->stdout(sprintf(
            "  %-4s %-18s %-8s %-15s %-20s %-12s %-20s %s\n",
            'ID', 'Поставщик', 'Метод', 'Расписание', 'Последний запуск', 'Статус', 'Следующий запуск', 'Вкл'
        ), Console::BOLD);
        $this->stdout("  " . str_repeat('─', 120) . "\n");

        foreach ($configs as $config) {
            $supplierName = mb_substr($config->supplier->name ?? '?', 0, 18);
            $enabled = $config->is_enabled ? '✅' : '❌';
            $status = $config->getStatusLabel();

            // Следующий запуск
            $nextRun = $config->next_run_at ?? '—';
            if ($config->next_run_at) {
                $nextTs = strtotime($config->next_run_at);
                if ($nextTs < time()) {
                    $nextRun .= ' ⚠️';
                }
            }

            // Рассчитаем следующий запуск через cron-expression если не записан
            if ($nextRun === '—' && $config->schedule_cron) {
                try {
                    $cron = new CronExpression($config->schedule_cron);
                    $nextRun = $cron->getNextRunDate()->format('Y-m-d H:i');
                } catch (\Throwable $e) {
                    $nextRun = '⚠️ invalid cron';
                }
            }

            $this->stdout(sprintf(
                "  %-4s %-18s %-8s %-15s %-20s %-12s %-20s %s\n",
                $config->id,
                $supplierName,
                $config->fetch_method,
                $config->schedule_cron ?: ($config->schedule_interval_hours ? "каждые {$config->schedule_interval_hours}ч" : '—'),
                $config->last_fetch_at ? date('Y-m-d H:i', strtotime($config->last_fetch_at)) : '—',
                $status,
                $nextRun,
                $enabled
            ));
        }

        $this->stdout("\n");
        return ExitCode::OK;
    }

    /**
     * Ручной запуск скачивания прайса.
     *
     * php yii scheduler/fetch-now --supplier=ormatek
     */
    public function actionFetchNow(): int
    {
        $supplierCode = $this->prompt('Код поставщика:');
        if (empty($supplierCode)) {
            $this->stderr("  Укажите код поставщика.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $supplierId = Yii::$app->db->createCommand(
            "SELECT id FROM {{%suppliers}} WHERE code = :code AND is_active = true",
            [':code' => $supplierCode]
        )->queryScalar();

        if (!$supplierId) {
            $this->stderr("  Поставщик '{$supplierCode}' не найден.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $config = SupplierFetchConfig::findOne(['supplier_id' => $supplierId]);
        if (!$config) {
            $this->stderr("  Конфигурация для '{$supplierCode}' не задана.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if ($config->fetch_method === 'manual') {
            $this->stderr("  Метод = manual. Загрузите файл вручную.\n", Console::FG_YELLOW);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("  Запускаем скачивание для {$supplierCode} (метод: {$config->fetch_method})...\n", Console::FG_CYAN);

        $jobId = Yii::$app->queue->push(new FetchPriceJob([
            'fetchConfigId' => $config->id,
            'supplierCode'  => $supplierCode,
        ]));

        $this->stdout("  ✅ FetchPriceJob #{$jobId} поставлен в очередь.\n\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    // ═══════════════════════════════════════════
    // ВНУТРЕННЯЯ ЛОГИКА
    // ═══════════════════════════════════════════

    /**
     * Проверить cron-расписание и поставить задачи в очередь.
     *
     * @return int Количество запущенных задач
     */
    protected function checkScheduledFetches(): int
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone(Yii::$app->timeZone ?? 'UTC'));
        $fetchCount = 0;

        // ═══ Способ 1: Предвычисленный next_run_at ═══
        $configs = SupplierFetchConfig::find()
            ->with('supplier')
            ->where(['is_enabled' => true])
            ->andWhere(['!=', 'fetch_method', 'manual'])
            ->andWhere([
                'or',
                // next_run_at уже наступил
                ['<=', 'next_run_at', $now->format('Y-m-d H:i:s')],
                // next_run_at не рассчитан — проверим вручную
                ['next_run_at' => null],
            ])
            ->all();

        foreach ($configs as $config) {
            if (!$config->supplier || !$config->supplier->is_active) {
                continue;
            }

            $shouldRun = false;

            if ($config->next_run_at !== null) {
                // next_run_at уже наступил — запускаем
                $shouldRun = strtotime($config->next_run_at) <= time();
            } elseif (!empty($config->schedule_cron)) {
                // Матчинг через dragonmantank/cron-expression
                $shouldRun = $this->matchCronExpression($config->schedule_cron, $now, $config->last_fetch_at);
            } elseif ($config->schedule_interval_hours > 0) {
                // Интервальное расписание
                $lastFetch = $config->last_fetch_at ? strtotime($config->last_fetch_at) : 0;
                $intervalSec = $config->schedule_interval_hours * 3600;
                $shouldRun = (time() - $lastFetch) >= $intervalSec;
            }

            if ($shouldRun) {
                $this->enqueueFetch($config);
                $fetchCount++;
            }
        }

        return $fetchCount;
    }

    /**
     * Матчинг cron-выражения через dragonmantank/cron-expression.
     *
     * Проверяет, попадает ли текущее время в расписание,
     * и не было ли уже запуска в эту минуту.
     */
    protected function matchCronExpression(string $cronExpr, \DateTimeImmutable $now, ?string $lastFetchAt): bool
    {
        try {
            $cron = new CronExpression($cronExpr);

            // cron выражение должно совпадать с текущей минутой
            if (!$cron->isDue($now)) {
                return false;
            }

            // Защита от двойного запуска в ту же минуту
            if ($lastFetchAt) {
                $lastFetchMinute = date('Y-m-d H:i', strtotime($lastFetchAt));
                $currentMinute = $now->format('Y-m-d H:i');
                if ($lastFetchMinute === $currentMinute) {
                    return false;
                }
            }

            return true;
        } catch (\Throwable $e) {
            Yii::warning("Scheduler: невалидное cron-выражение: '{$cronExpr}' — {$e->getMessage()}", 'scheduler');
            return false;
        }
    }

    /**
     * Поставить задачу забора прайса в очередь.
     */
    protected function enqueueFetch(SupplierFetchConfig $config): void
    {
        $supplier = $config->supplier;

        Yii::info("Scheduler: автосбор для {$supplier->code} (метод: {$config->fetch_method})", 'scheduler');

        if ($this->verbose) {
            $this->stdout("  → Автосбор: {$supplier->code} ({$config->getMethodLabel()})\n", Console::FG_CYAN);
        }

        // Ставим в очередь
        Yii::$app->queue->push(new FetchPriceJob([
            'fetchConfigId' => $config->id,
            'supplierCode'  => $supplier->code,
        ]));

        // Обновляем last_fetch_at и пересчитываем next_run_at
        $config->last_fetch_at = new \yii\db\Expression('NOW()');
        $config->last_fetch_status = 'queued';
        $config->calculateNextRun();
        $config->save(false);
    }

    /**
     * Периодические задачи (не связанные с прайсами).
     */
    protected function periodicTasks(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone(Yii::$app->timeZone ?? 'UTC'));
        $minute = (int)$now->format('i');
        $hour = (int)$now->format('G');

        // Раз в 5 минут: мониторинг очереди
        if ($minute % 5 === 0) {
            $this->logQueueStats();
        }

        // Раз в час (:30): retry failed images
        if ($minute === 30) {
            $this->retryFailedImages();
        }

        // Раз в день (03:00): деактивация устаревших офферов
        if ($hour === 3 && $minute === 0) {
            $this->deactivateStaleOffers();
        }
    }

    /**
     * Retry failed image downloads.
     */
    protected function retryFailedImages(): void
    {
        try {
            $count = Yii::$app->db->createCommand("
                UPDATE {{%card_images}} 
                SET status = 'pending', error_message = NULL
                WHERE status = 'failed' AND attempts < 3
            ")->execute();

            if ($count > 0) {
                if ($this->verbose) {
                    $this->stdout("  Retry: {$count} failed images → pending\n", Console::FG_YELLOW);
                }
                Yii::info("Scheduler: reset {$count} failed images to pending", 'scheduler');
            }
        } catch (\Throwable $e) {
            // Таблица может не существовать
        }
    }

    /**
     * Деактивация офферов, не обновлявшихся >7 дней.
     */
    protected function deactivateStaleOffers(): void
    {
        try {
            $count = Yii::$app->db->createCommand("
                UPDATE {{%supplier_offers}} 
                SET is_active = false 
                WHERE is_active = true 
                  AND updated_at < NOW() - INTERVAL '7 days'
            ")->execute();

            if ($count > 0) {
                if ($this->verbose) {
                    $this->stdout("  Stale: деактивировано {$count} устаревших офферов\n", Console::FG_YELLOW);
                }
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
        } catch (\Throwable $e) {
            // Таблица может не существовать
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

            if (($waiting > 0 || $reserved > 0 || $delayed > 0) && $this->verbose) {
                $this->stdout("  Queue: w={$waiting} r={$reserved} d={$delayed}\n");
            }
        } catch (\Throwable $e) {
            // Redis может быть недоступен
        }
    }
}
