<?php

namespace common\services\fetcher\drivers;

use common\models\SupplierFetchConfig;
use common\services\fetcher\FetchResult;
use common\services\fetcher\PriceFetcherInterface;
use Yii;

/**
 * Скачивание прайс-листа по FTP/FTPS.
 *
 * Особенности:
 * - Потоковое скачивание (FTP_BINARY) — не загружает файл в RAM
 * - Passive mode по умолчанию (работает за NAT)
 * - Поддержка file_pattern для поиска файла по маске
 * - Таймаут подключения 30 секунд
 * - SSL/FTPS при указании порта 990 или через credentials
 */
class FtpFetcher implements PriceFetcherInterface
{
    public function supports(string $method): bool
    {
        return $method === 'ftp';
    }

    public function fetch(SupplierFetchConfig $config): FetchResult
    {
        $creds = $this->resolveCredentials($config);

        $host = $creds['host'] ?? $config->ftp_host ?? '';
        $port = (int)($creds['port'] ?? $config->ftp_port ?? 21);
        $user = $creds['login'] ?? $config->ftp_user ?? 'anonymous';
        $password = $creds['password'] ?? $config->ftp_password ?? '';
        $remotePath = $creds['remote_path'] ?? $config->ftp_path ?? '';
        $passive = (bool)($creds['passive'] ?? $config->ftp_passive ?? true);
        $useSsl = (bool)($creds['ssl'] ?? ($port === 990));

        if (empty($host)) {
            return FetchResult::fail('FTP host не указан', 'ftp');
        }

        if (empty($remotePath) && empty($config->file_pattern)) {
            return FetchResult::fail('FTP remote_path и file_pattern не указаны', 'ftp');
        }

        $supplierCode = $config->supplier->code ?? 'unknown';
        $storagePath = $this->ensureStoragePath($supplierCode);

        $startTime = microtime(true);

        try {
            Yii::info("FtpFetcher: подключаемся к {$user}@{$host}:{$port}", 'fetcher');

            // Подключение
            if ($useSsl && function_exists('ftp_ssl_connect')) {
                $connId = @ftp_ssl_connect($host, $port, 30);
            } else {
                $connId = @ftp_connect($host, $port, 30);
            }

            if (!$connId) {
                return FetchResult::fail("Не удалось подключиться к FTP: {$host}:{$port}", 'ftp');
            }

            // Аутентификация
            if (!@ftp_login($connId, $user, $password)) {
                ftp_close($connId);
                return FetchResult::fail("Ошибка аутентификации FTP: {$user}@{$host}", 'ftp');
            }

            // Passive mode
            if ($passive) {
                ftp_pasv($connId, true);
            }

            // Если указан file_pattern, ищем файл
            if (empty($remotePath) && !empty($config->file_pattern)) {
                $remotePath = $this->findFileByPattern($connId, $config->file_pattern);
                if (!$remotePath) {
                    ftp_close($connId);
                    return FetchResult::fail("Файл по маске '{$config->file_pattern}' не найден на FTP", 'ftp');
                }
                Yii::info("FtpFetcher: найден файл по маске: {$remotePath}", 'fetcher');
            }

            // Определяем имя локального файла
            $extension = pathinfo($remotePath, PATHINFO_EXTENSION) ?: 'xml';
            if ($config->archive_type) {
                $extension = $config->archive_type;
            }
            $filename = $supplierCode . '_' . date('Y-m-d_His') . '.' . $extension;
            $localPath = $storagePath . '/' . $filename;

            // Скачиваем потоково
            Yii::info("FtpFetcher: скачиваем {$remotePath} → {$localPath}", 'fetcher');
            $success = @ftp_get($connId, $localPath, $remotePath, FTP_BINARY);
            ftp_close($connId);

            if (!$success || !file_exists($localPath)) {
                @unlink($localPath);
                return FetchResult::fail("Не удалось скачать файл: {$remotePath}", 'ftp');
            }

            $duration = round(microtime(true) - $startTime, 2);
            $fileSize = filesize($localPath);

            if ($fileSize < 100) {
                @unlink($localPath);
                return FetchResult::fail("Файл слишком мал ({$fileSize} bytes)", 'ftp');
            }

            Yii::info("FtpFetcher: скачан {$localPath} ({$fileSize} bytes, {$duration}s)", 'fetcher');
            return FetchResult::ok($localPath, 'ftp', $duration);

        } catch (\Throwable $e) {
            Yii::error("FtpFetcher: ошибка — {$e->getMessage()}", 'fetcher');
            return FetchResult::fail($e->getMessage(), 'ftp');
        }
    }

    /**
     * Найти файл на FTP по маске (glob-like pattern).
     */
    private function findFileByPattern($connId, string $pattern): ?string
    {
        // Определяем директорию
        $dir = dirname($pattern);
        if ($dir === '.') $dir = '/';
        $filePattern = basename($pattern);

        $listing = @ftp_nlist($connId, $dir);
        if (!$listing) return null;

        // Конвертируем glob-паттерн в regex
        $regex = '/^' . str_replace(['.', '*', '?'], ['\.', '.*', '.'], $filePattern) . '$/i';

        $matches = [];
        foreach ($listing as $file) {
            $basename = basename($file);
            if (preg_match($regex, $basename)) {
                $matches[] = ($dir !== '/' ? rtrim($dir, '/') . '/' : '/') . $basename;
            }
        }

        if (empty($matches)) return null;

        // Берём самый свежий (последний по алфавиту, обычно содержит дату)
        sort($matches);
        return end($matches);
    }

    /**
     * Извлечь credentials из JSONB или из legacy-полей.
     */
    private function resolveCredentials(SupplierFetchConfig $config): array
    {
        if ($config->credentials) {
            return is_array($config->credentials) ? $config->credentials : json_decode($config->credentials, true) ?: [];
        }
        return [];
    }

    private function ensureStoragePath(string $supplierCode): string
    {
        $dir = Yii::getAlias('@storage/prices-source/' . $supplierCode);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir;
    }
}
