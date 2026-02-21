<?php

namespace common\models;

use yii\db\ActiveRecord;
use yii\behaviors\TimestampBehavior;
use yii\db\Expression;

/**
 * Конфигурация автоматического получения прайсов.
 *
 * @property int $id
 * @property int $supplier_id
 * @property string $fetch_method  manual|url|ftp|email|api
 * @property string|null $url
 * @property string|null $ftp_host
 * @property int|null $ftp_port
 * @property string|null $ftp_user
 * @property string|null $ftp_password
 * @property string|null $ftp_path
 * @property bool $ftp_passive
 * @property string|null $email_host
 * @property int|null $email_port
 * @property string|null $email_user
 * @property string|null $email_password
 * @property string|null $email_folder
 * @property string|null $email_subject_filter
 * @property string|null $email_from_filter
 * @property string|null $api_url
 * @property string|null $api_key
 * @property array|null $api_headers
 * @property string|null $api_method
 * @property string|null $schedule_cron
 * @property int|null $schedule_interval_hours
 * @property bool $is_enabled
 * @property string|null $file_format
 * @property string|null $file_encoding
 * @property string|null $archive_type
 * @property string|null $file_pattern
 * @property string|null $last_fetch_at
 * @property string|null $last_fetch_status
 * @property string|null $last_fetch_error
 * @property int $fetch_count
 * @property array|null $credentials           JSONB: {login, password, token, api_key, headers, host, port, ...}
 * @property string|null $next_run_at           Предвычисленное время следующего запуска
 * @property int|null $last_duration_sec        Длительность последнего скачивания (сек)
 * @property string|null $notes                 Заметки менеджера
 * @property string $created_at
 * @property string $updated_at
 *
 * @property Supplier $supplier
 */
class SupplierFetchConfig extends ActiveRecord
{
    /** @var string[] Автоматизируемые методы (не manual) */
    public const AUTO_METHODS = ['url', 'ftp', 'api'];

    public static function tableName(): string
    {
        return '{{%supplier_fetch_configs}}';
    }

    public function behaviors(): array
    {
        return [
            [
                'class' => TimestampBehavior::class,
                'value' => new Expression('NOW()'),
            ],
        ];
    }

    public function rules(): array
    {
        return [
            [['supplier_id', 'fetch_method'], 'required'],
            [['fetch_method'], 'in', 'range' => ['manual', 'url', 'ftp', 'email', 'api']],
            [['url', 'api_url'], 'string', 'max' => 1000],
            [['ftp_host', 'ftp_user', 'ftp_password', 'email_host', 'email_user', 'email_password', 'email_subject_filter', 'email_from_filter'], 'string', 'max' => 255],
            [['ftp_path', 'api_key'], 'string', 'max' => 500],
            [['email_folder'], 'string', 'max' => 100],
            [['file_format', 'file_encoding'], 'string', 'max' => 20],
            [['archive_type', 'api_method'], 'string', 'max' => 10],
            [['schedule_cron'], 'string', 'max' => 100],
            [['file_pattern'], 'string', 'max' => 255],
            [['is_enabled', 'ftp_passive'], 'boolean'],
            [['ftp_port', 'email_port', 'schedule_interval_hours', 'fetch_count', 'last_duration_sec'], 'integer'],
            [['notes', 'last_fetch_error'], 'string'],
            [['credentials'], 'safe'], // JSONB
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'id' => 'ID',
            'supplier_id' => 'Поставщик',
            'fetch_method' => 'Метод получения',
            'url' => 'URL прайса',
            'ftp_host' => 'FTP хост',
            'ftp_user' => 'FTP пользователь',
            'ftp_path' => 'FTP путь',
            'email_host' => 'IMAP хост',
            'email_user' => 'Email',
            'email_subject_filter' => 'Фильтр по теме',
            'email_from_filter' => 'Фильтр по отправителю',
            'api_url' => 'URL API',
            'schedule_cron' => 'Cron-расписание',
            'schedule_interval_hours' => 'Интервал (часов)',
            'is_enabled' => 'Включён',
            'file_format' => 'Формат файла',
            'file_encoding' => 'Кодировка',
            'archive_type' => 'Тип архива',
            'file_pattern' => 'Паттерн файла',
            'last_fetch_at' => 'Последняя загрузка',
            'last_fetch_status' => 'Статус загрузки',
            'last_fetch_error' => 'Ошибка',
            'last_duration_sec' => 'Длительность (сек)',
            'fetch_count' => 'Кол-во загрузок',
            'credentials' => 'Авторизация (JSONB)',
            'next_run_at' => 'Следующий запуск',
            'notes' => 'Заметки',
        ];
    }

    // ═══════════════════════════════════════════
    // Relations
    // ═══════════════════════════════════════════

    public function getSupplier()
    {
        return $this->hasOne(Supplier::class, ['id' => 'supplier_id']);
    }

    // ═══════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════

    /**
     * Метод получения в человеческом виде.
     */
    public function getMethodLabel(): string
    {
        return match ($this->fetch_method) {
            'manual' => '📁 Ручная загрузка',
            'url'    => '🌐 По ссылке (HTTP)',
            'ftp'    => '📡 FTP',
            'email'  => '📧 Email (IMAP)',
            'api'    => '🔌 API',
            default  => $this->fetch_method,
        };
    }

    /**
     * Статус в человеческом виде.
     */
    public function getStatusLabel(): string
    {
        return match ($this->last_fetch_status) {
            'success'    => '✅ Успешно',
            'failed'     => '❌ Ошибка',
            'running'    => '⏳ Выполняется',
            'queued'     => '📋 В очереди',
            null         => '—',
            default      => $this->last_fetch_status,
        };
    }

    /**
     * Является ли конфиг автоматизируемым (не manual).
     */
    public function isAutomatable(): bool
    {
        return in_array($this->fetch_method, self::AUTO_METHODS, true);
    }

    /**
     * Есть ли расписание (cron или интервал).
     */
    public function hasSchedule(): bool
    {
        return !empty($this->schedule_cron) || ($this->schedule_interval_hours > 0);
    }

    /**
     * Получить URL источника (унифицировано).
     */
    public function getSourceUrl(): string
    {
        return match ($this->fetch_method) {
            'url'  => $this->url ?? '',
            'ftp'  => "ftp://{$this->ftp_host}:{$this->ftp_port}{$this->ftp_path}",
            'api'  => $this->api_url ?? '',
            default => '',
        };
    }

    /**
     * Записать результат скачивания.
     */
    public function recordFetchResult(bool $success, ?string $error = null, ?int $durationSec = null): void
    {
        $this->last_fetch_at = new Expression('NOW()');
        $this->last_fetch_status = $success ? 'success' : 'failed';
        $this->last_fetch_error = $error;
        $this->last_duration_sec = $durationSec;

        if ($success) {
            $this->fetch_count = ($this->fetch_count ?? 0) + 1;
        }

        $this->save(false);
    }

    /**
     * Рассчитать и записать next_run_at на основе cron-расписания.
     */
    public function calculateNextRun(): void
    {
        if (!empty($this->schedule_cron)) {
            try {
                $cron = new \Cron\CronExpression($this->schedule_cron);
                $next = $cron->getNextRunDate();
                $this->next_run_at = $next->format('Y-m-d H:i:s');
            } catch (\Throwable $e) {
                // Невалидное cron-выражение
                $this->next_run_at = null;
            }
        } elseif ($this->schedule_interval_hours > 0) {
            $lastFetch = $this->last_fetch_at ? strtotime($this->last_fetch_at) : time();
            $nextTs = $lastFetch + ($this->schedule_interval_hours * 3600);
            $this->next_run_at = date('Y-m-d H:i:s', max($nextTs, time()));
        } else {
            $this->next_run_at = null;
        }
    }
}
