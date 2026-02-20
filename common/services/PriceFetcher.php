<?php

namespace common\services;

use GuzzleHttp\Client as HttpClient;
use yii\base\Component;
use yii\db\Connection;
use Yii;

/**
 * Универсальный сервис получения прайс-листов от поставщиков.
 *
 * Поддерживаемые источники (из опыта agregator_old):
 * - file: локальный файл (загружен вручную)
 * - url: скачивание по HTTP/HTTPS ссылке
 * - ftp: скачивание с FTP-сервера
 * - email: получение из почтового ящика (IMAP)
 * - api: запрос через REST/SOAP API поставщика
 *
 * Конфигурация хранится в suppliers.config (JSONB):
 * {
 *   "fetch_method": "ftp",
 *   "host": "ftp.supplier.com",
 *   "user": "login",
 *   "password": "pass",
 *   "remote_path": "/prices/full.xml",
 *   "schedule": "0 6 * * *",
 *   "email_from": "supplier@mail.ru",
 *   "email_subject_pattern": "Прайс-лист",
 *   "url": "https://supplier.com/price.xml"
 * }
 */
class PriceFetcher extends Component
{
    /** @var string Директория для хранения скачанных прайсов */
    public string $storagePath = '';

    private Connection $db;
    private ?HttpClient $httpClient = null;

    public function init(): void
    {
        parent::init();
        $this->db = Yii::$app->db;

        if (empty($this->storagePath)) {
            $this->storagePath = Yii::getAlias('@storage/prices');
        }

        if (!is_dir($this->storagePath)) {
            @mkdir($this->storagePath, 0775, true);
        }

        $this->httpClient = new HttpClient([
            'timeout' => 300, // 5 мин на скачивание больших файлов
            'verify' => false,
        ]);
    }

    /**
     * Скачать прайс-лист поставщика.
     *
     * @param string $supplierCode Код поставщика
     * @return array{success: bool, file_path: ?string, method: string, error: ?string}
     */
    public function fetch(string $supplierCode): array
    {
        $supplier = $this->db->createCommand(
            "SELECT id, code, name, config FROM {{%suppliers}} WHERE code = :code AND is_active = true",
            [':code' => $supplierCode]
        )->queryOne();

        if (!$supplier) {
            return ['success' => false, 'file_path' => null, 'method' => 'none', 'error' => "Поставщик '{$supplierCode}' не найден"];
        }

        $config = json_decode($supplier['config'] ?: '{}', true);
        $method = $config['fetch_method'] ?? 'file';

        Yii::info("PriceFetcher: поставщик={$supplierCode} method={$method}", 'import');

        try {
            $result = match ($method) {
                'url' => $this->fetchFromUrl($supplierCode, $config),
                'ftp' => $this->fetchFromFtp($supplierCode, $config),
                'email' => $this->fetchFromEmail($supplierCode, $config),
                'api' => $this->fetchFromApi($supplierCode, $config),
                'file' => $this->findLocalFile($supplierCode, $config),
                default => ['success' => false, 'file_path' => null, 'error' => "Неизвестный метод: {$method}"],
            };

            $result['method'] = $method;

            if ($result['success']) {
                // Обновить last_import_at
                $this->db->createCommand(
                    "UPDATE {{%suppliers}} SET last_import_at = NOW() WHERE code = :code",
                    [':code' => $supplierCode]
                )->execute();

                Yii::info("PriceFetcher: скачан прайс supplier={$supplierCode} file={$result['file_path']}", 'import');
            }

            return $result;

        } catch (\Throwable $e) {
            Yii::error("PriceFetcher: ошибка supplier={$supplierCode} — {$e->getMessage()}", 'import');
            return [
                'success' => false,
                'file_path' => null,
                'method' => $method,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Скачать по HTTP/HTTPS URL.
     */
    protected function fetchFromUrl(string $supplierCode, array $config): array
    {
        $url = $config['url'] ?? '';
        if (empty($url)) {
            return ['success' => false, 'file_path' => null, 'error' => 'URL не указан в конфигурации'];
        }

        $supplierDir = $this->ensureSupplierDir($supplierCode);
        $extension = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'xml';
        $filename = $supplierCode . '_' . date('Y-m-d_His') . '.' . $extension;
        $localPath = $supplierDir . '/' . $filename;

        $response = $this->httpClient->get($url, [
            'sink' => $localPath,
            'headers' => $config['headers'] ?? [],
        ]);

        if ($response->getStatusCode() !== 200) {
            @unlink($localPath);
            return ['success' => false, 'file_path' => null, 'error' => 'HTTP ' . $response->getStatusCode()];
        }

        $size = filesize($localPath);
        if ($size < 100) {
            @unlink($localPath);
            return ['success' => false, 'file_path' => null, 'error' => 'Файл слишком мал (' . $size . ' bytes)'];
        }

        return ['success' => true, 'file_path' => $localPath, 'error' => null];
    }

    /**
     * Скачать по FTP.
     */
    protected function fetchFromFtp(string $supplierCode, array $config): array
    {
        $host = $config['host'] ?? '';
        $user = $config['user'] ?? 'anonymous';
        $password = $config['password'] ?? '';
        $remotePath = $config['remote_path'] ?? '';
        $port = (int)($config['port'] ?? 21);
        $passive = (bool)($config['passive'] ?? true);

        if (empty($host) || empty($remotePath)) {
            return ['success' => false, 'file_path' => null, 'error' => 'FTP host или remote_path не указаны'];
        }

        $connId = @ftp_connect($host, $port, 30);
        if (!$connId) {
            return ['success' => false, 'file_path' => null, 'error' => "Не удалось подключиться к FTP: {$host}:{$port}"];
        }

        if (!@ftp_login($connId, $user, $password)) {
            ftp_close($connId);
            return ['success' => false, 'file_path' => null, 'error' => "Ошибка аутентификации FTP: {$user}@{$host}"];
        }

        if ($passive) {
            ftp_pasv($connId, true);
        }

        $supplierDir = $this->ensureSupplierDir($supplierCode);
        $extension = pathinfo($remotePath, PATHINFO_EXTENSION) ?: 'xml';
        $filename = $supplierCode . '_' . date('Y-m-d_His') . '.' . $extension;
        $localPath = $supplierDir . '/' . $filename;

        $success = @ftp_get($connId, $localPath, $remotePath, FTP_BINARY);
        ftp_close($connId);

        if (!$success || !file_exists($localPath)) {
            return ['success' => false, 'file_path' => null, 'error' => "Не удалось скачать файл: {$remotePath}"];
        }

        return ['success' => true, 'file_path' => $localPath, 'error' => null];
    }

    /**
     * Получить из почтового ящика (IMAP).
     * Требует расширение php-imap.
     */
    protected function fetchFromEmail(string $supplierCode, array $config): array
    {
        $imapHost = $config['imap_host'] ?? '';
        $imapUser = $config['imap_user'] ?? '';
        $imapPassword = $config['imap_password'] ?? '';
        $fromFilter = $config['email_from'] ?? '';
        $subjectPattern = $config['email_subject_pattern'] ?? '';

        if (empty($imapHost) || empty($imapUser)) {
            return ['success' => false, 'file_path' => null, 'error' => 'IMAP настройки не указаны'];
        }

        if (!function_exists('imap_open')) {
            return ['success' => false, 'file_path' => null, 'error' => 'PHP extension imap не установлен'];
        }

        $mailbox = @imap_open($imapHost, $imapUser, $imapPassword);
        if (!$mailbox) {
            return ['success' => false, 'file_path' => null, 'error' => 'Не удалось подключиться к IMAP: ' . imap_last_error()];
        }

        // Ищем непрочитанные письма
        $criteria = 'UNSEEN';
        if ($fromFilter) {
            $criteria .= ' FROM "' . $fromFilter . '"';
        }

        $emails = imap_search($mailbox, $criteria);
        if (!$emails) {
            imap_close($mailbox);
            return ['success' => false, 'file_path' => null, 'error' => 'Нет новых писем от поставщика'];
        }

        // Берём самое свежее
        rsort($emails);
        $supplierDir = $this->ensureSupplierDir($supplierCode);

        foreach ($emails as $emailId) {
            $overview = imap_fetch_overview($mailbox, (string)$emailId, 0);
            $subject = $overview[0]->subject ?? '';

            // Проверяем паттерн темы
            if ($subjectPattern && !str_contains(mb_strtolower($subject), mb_strtolower($subjectPattern))) {
                continue;
            }

            // Ищем вложения
            $structure = imap_fetchstructure($mailbox, $emailId);
            $attachments = $this->extractAttachments($mailbox, $emailId, $structure, $supplierDir, $supplierCode);

            if (!empty($attachments)) {
                // Пометить как прочитанное
                imap_setflag_full($mailbox, (string)$emailId, '\\Seen');
                imap_close($mailbox);

                return ['success' => true, 'file_path' => $attachments[0], 'error' => null];
            }
        }

        imap_close($mailbox);
        return ['success' => false, 'file_path' => null, 'error' => 'Вложения не найдены в письмах'];
    }

    /**
     * Получить через API поставщика.
     */
    protected function fetchFromApi(string $supplierCode, array $config): array
    {
        $apiUrl = $config['api_url'] ?? '';
        $apiMethod = strtoupper($config['api_method'] ?? 'GET');
        $apiHeaders = $config['api_headers'] ?? [];
        $apiBody = $config['api_body'] ?? null;

        if (empty($apiUrl)) {
            return ['success' => false, 'file_path' => null, 'error' => 'API URL не указан'];
        }

        $supplierDir = $this->ensureSupplierDir($supplierCode);
        $filename = $supplierCode . '_' . date('Y-m-d_His') . '.json';
        $localPath = $supplierDir . '/' . $filename;

        $requestOptions = [
            'sink' => $localPath,
            'headers' => $apiHeaders,
        ];

        if ($apiBody && $apiMethod !== 'GET') {
            $requestOptions['json'] = $apiBody;
        }

        $response = $this->httpClient->request($apiMethod, $apiUrl, $requestOptions);

        if ($response->getStatusCode() !== 200) {
            @unlink($localPath);
            return ['success' => false, 'file_path' => null, 'error' => 'API HTTP ' . $response->getStatusCode()];
        }

        return ['success' => true, 'file_path' => $localPath, 'error' => null];
    }

    /**
     * Найти локальный файл (ручная загрузка).
     */
    protected function findLocalFile(string $supplierCode, array $config): array
    {
        $supplierDir = $this->ensureSupplierDir($supplierCode);
        $pattern = $config['file_pattern'] ?? '*.*';

        $files = glob($supplierDir . '/' . $pattern);
        if (empty($files)) {
            return ['success' => false, 'file_path' => null, 'error' => "Файлы не найдены в {$supplierDir}"];
        }

        // Самый свежий файл
        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));

        return ['success' => true, 'file_path' => $files[0], 'error' => null];
    }

    // ═══════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════

    protected function ensureSupplierDir(string $supplierCode): string
    {
        $dir = $this->storagePath . '/' . $supplierCode;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /**
     * Извлечь вложения из email.
     */
    protected function extractAttachments($mailbox, int $emailId, object $structure, string $saveDir, string $prefix): array
    {
        $attachments = [];

        if (isset($structure->parts)) {
            foreach ($structure->parts as $partIndex => $part) {
                if (!empty($part->disposition) && strtolower($part->disposition) === 'attachment') {
                    $filename = $this->decodeAttachmentName($part);
                    if (empty($filename)) continue;

                    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                    if (!in_array($extension, ['xml', 'csv', 'xlsx', 'xls', 'json', 'zip', 'txt'])) {
                        continue;
                    }

                    $body = imap_fetchbody($mailbox, $emailId, (string)($partIndex + 1));
                    if ($part->encoding === 3) { // BASE64
                        $body = base64_decode($body);
                    } elseif ($part->encoding === 4) { // QUOTED-PRINTABLE
                        $body = quoted_printable_decode($body);
                    }

                    $savePath = $saveDir . '/' . $prefix . '_' . date('Y-m-d_His') . '.' . $extension;
                    file_put_contents($savePath, $body);
                    $attachments[] = $savePath;
                }
            }
        }

        return $attachments;
    }

    protected function decodeAttachmentName(object $part): string
    {
        $filename = '';
        if (!empty($part->dparameters)) {
            foreach ($part->dparameters as $param) {
                if (strtolower($param->attribute) === 'filename') {
                    $filename = $param->value;
                    break;
                }
            }
        }
        if (empty($filename) && !empty($part->parameters)) {
            foreach ($part->parameters as $param) {
                if (strtolower($param->attribute) === 'name') {
                    $filename = $param->value;
                    break;
                }
            }
        }
        return imap_utf8($filename);
    }
}
