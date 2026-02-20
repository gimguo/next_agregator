<?php

namespace console\controllers;

use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;
use Yii;

/**
 * Инструменты разработчика — обнуление и диагностика.
 *
 * Команды:
 *   php yii dev/truncate          -- Полная очистка транзакционных таблиц (сохраняет справочники)
 *   php yii dev/truncate --yes    -- Без подтверждения
 *   php yii dev/flush-redis       -- Сброс Redis (кэш + очередь)
 *   php yii dev/clear-s3          -- Очистка бакета S3/MinIO
 *   php yii dev/full-reset        -- Полный сброс: truncate + flush-redis + clear-s3
 */
class DevController extends Controller
{
    /** @var bool Пропустить подтверждение */
    public bool $yes = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['yes']);
    }

    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), ['y' => 'yes']);
    }

    /**
     * Полная очистка транзакционных таблиц (справочники сохраняются).
     *
     * Очищаемые таблицы:
     *   marketplace_outbox, media_assets, supplier_offers,
     *   reference_variants, product_models, staging_raw_offers, import_sessions
     *
     * НЕ трогаем: brands, categories, suppliers, supplier_fetch_configs, supplier_ai_recipes, user
     */
    public function actionTruncate(): int
    {
        $this->stdout("\n", Console::BOLD);
        $this->stdout("╔══════════════════════════════════════════════════╗\n", Console::FG_RED);
        $this->stdout("║   TRUNCATE — Полная очистка транзакционных данных ║\n", Console::FG_RED);
        $this->stdout("╚══════════════════════════════════════════════════╝\n\n", Console::FG_RED);

        // Показываем текущее состояние
        $db = Yii::$app->db;
        $tables = [
            'marketplace_outbox' => 'Outbox (экспорт)',
            'media_assets' => 'Медиа-ассеты (S3)',
            'matching_log' => 'Matching Log',
            'supplier_offers' => 'Офферы поставщиков',
            'reference_variants' => 'Эталонные варианты',
            'product_models' => 'Модели товаров',
            'staging_raw_offers' => 'Staging (сырые данные)',
            'import_sessions' => 'Сессии импорта',
        ];

        // Считаем карточки (legacy)
        try {
            $cardCount = (int)$db->createCommand("SELECT count(*) FROM {{%product_cards}}")->queryScalar();
            $tables = array_merge(['card_images' => 'Картинки карточек', 'product_cards' => 'Карточки (legacy)'], $tables);
        } catch (\Exception $e) {
            $cardCount = 0;
        }

        $this->stdout("  Текущее состояние:\n", Console::FG_YELLOW);
        $totalRows = 0;
        foreach ($tables as $table => $label) {
            try {
                $count = (int)$db->createCommand("SELECT count(*) FROM {{%{$table}}}")->queryScalar();
                $totalRows += $count;
                $color = $count > 0 ? Console::FG_RED : Console::FG_GREEN;
                $this->stdout("    {$label}: ", Console::FG_GREY);
                $this->stdout(number_format($count) . "\n", $color);
            } catch (\Exception $e) {
                $this->stdout("    {$label}: ", Console::FG_GREY);
                $this->stdout("таблица не найдена\n", Console::FG_YELLOW);
                unset($tables[$table]);
            }
        }

        $this->stdout("\n  Справочники (НЕ трогаем):\n", Console::FG_GREEN);
        $refs = ['brands' => 'Бренды', 'categories' => 'Категории', 'suppliers' => 'Поставщики'];
        foreach ($refs as $table => $label) {
            try {
                $count = (int)$db->createCommand("SELECT count(*) FROM {{%{$table}}}")->queryScalar();
                $this->stdout("    {$label}: ", Console::FG_GREY);
                $this->stdout(number_format($count) . " ✓\n", Console::FG_GREEN);
            } catch (\Exception $e) {
                // skip
            }
        }
        $this->stdout("\n");

        if ($totalRows === 0) {
            $this->stdout("  База уже пуста! Нечего очищать.\n\n", Console::FG_GREEN);
            return ExitCode::OK;
        }

        // Подтверждение
        if (!$this->yes) {
            $confirm = $this->confirm("Удалить {$totalRows} записей из " . count($tables) . " таблиц?");
            if (!$confirm) {
                $this->stdout("  Отменено.\n\n", Console::FG_YELLOW);
                return ExitCode::OK;
            }
        }

        $this->stdout("\n  Очистка...\n", Console::FG_RED);

        // Порядок важен (FK constraints): дочерние → родительские
        $truncateOrder = [
            'marketplace_outbox',
            'media_assets',
            'matching_log',
            'card_images',
            'supplier_offers',
            'reference_variants',
            'product_models',
            'product_cards',
            'staging_raw_offers',
            'import_sessions',
        ];

        foreach ($truncateOrder as $table) {
            if (!isset($tables[$table])) continue;
            try {
                if ($table === 'staging_raw_offers') {
                    // UNLOGGED table — TRUNCATE без CASCADE
                    $db->createCommand("TRUNCATE TABLE {{%{$table}}}")->execute();
                } else {
                    $db->createCommand("TRUNCATE TABLE {{%{$table}}} RESTART IDENTITY CASCADE")->execute();
                }
                $this->stdout("    ✓ {$tables[$table]} — очищена\n", Console::FG_GREEN);
            } catch (\Exception $e) {
                $this->stdout("    ✗ {$tables[$table]} — " . $e->getMessage() . "\n", Console::FG_RED);
            }
        }

        $this->stdout("\n  ✅ Транзакционные данные очищены. Справочники сохранены.\n\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Сброс Redis (кэш + очередь).
     */
    public function actionFlushRedis(): int
    {
        $this->stdout("\n  Сброс Redis...\n", Console::FG_YELLOW);

        try {
            /** @var \yii\redis\Connection $redis */
            $redis = Yii::$app->redis;
            $redis->executeCommand('FLUSHALL');
            $this->stdout("  ✅ Redis — FLUSHALL выполнен.\n\n", Console::FG_GREEN);
        } catch (\Exception $e) {
            $this->stdout("  ✗ Redis — " . $e->getMessage() . "\n\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    /**
     * Очистка S3/MinIO бакета (папка products).
     */
    public function actionClearS3(): int
    {
        $this->stdout("\n  Очистка S3 бакета...\n", Console::FG_YELLOW);

        try {
            /** @var \common\services\MediaProcessingService $media */
            $media = Yii::$app->get('mediaService');
            $fs = $media->getFilesystem();

            // Получаем все файлы в products/
            $listing = $fs->listContents('products', true);
            $deleted = 0;

            foreach ($listing as $item) {
                if ($item['type'] === 'file') {
                    $fs->delete($item['path']);
                    $deleted++;
                    if ($deleted % 100 === 0) {
                        $this->stdout("    Удалено: {$deleted}...\r");
                    }
                }
            }

            $this->stdout("  ✅ S3 — удалено {$deleted} файлов из products/.\n\n", Console::FG_GREEN);
        } catch (\Exception $e) {
            $this->stdout("  ✗ S3 — " . $e->getMessage() . "\n\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    /**
     * Полный сброс: truncate + flush-redis + clear-s3.
     */
    public function actionFullReset(): int
    {
        $this->stdout("\n", Console::BOLD);
        $this->stdout("╔══════════════════════════════════════════════════╗\n", Console::FG_RED);
        $this->stdout("║   FULL RESET — Полное обнуление агрегатора       ║\n", Console::FG_RED);
        $this->stdout("╚══════════════════════════════════════════════════╝\n", Console::FG_RED);

        if (!$this->yes) {
            $confirm = $this->confirm("\n  Полностью обнулить агрегатор (БД + Redis + S3)?");
            if (!$confirm) {
                $this->stdout("  Отменено.\n\n", Console::FG_YELLOW);
                return ExitCode::OK;
            }
        }

        $this->yes = true; // Не спрашивать повторно в под-командах

        $result = $this->actionTruncate();
        if ($result !== ExitCode::OK) return $result;

        $result = $this->actionFlushRedis();
        if ($result !== ExitCode::OK) return $result;

        $result = $this->actionClearS3();
        if ($result !== ExitCode::OK) return $result;

        $this->stdout("╔══════════════════════════════════════════════════╗\n", Console::FG_GREEN);
        $this->stdout("║   ✅ АГРЕГАТОР ПОЛНОСТЬЮ ОБНУЛЁН                 ║\n", Console::FG_GREEN);
        $this->stdout("╚══════════════════════════════════════════════════╝\n\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
