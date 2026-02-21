<?php

namespace common\services\fetcher;

use common\models\SupplierFetchConfig;

/**
 * Стратегия получения прайс-листа от поставщика.
 *
 * Каждая реализация (URL, FTP, API) скачивает файл потоково
 * и возвращает локальный путь к скачанному файлу.
 */
interface PriceFetcherInterface
{
    /**
     * Скачать прайс-лист по конфигурации поставщика.
     *
     * @param SupplierFetchConfig $config Конфигурация источника
     * @return FetchResult Результат скачивания
     */
    public function fetch(SupplierFetchConfig $config): FetchResult;

    /**
     * Поддерживает ли данный фетчер указанный метод.
     *
     * @param string $method Метод (url, ftp, api, email)
     * @return bool
     */
    public function supports(string $method): bool;
}
