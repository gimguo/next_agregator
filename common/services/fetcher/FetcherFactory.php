<?php

namespace common\services\fetcher;

use common\models\SupplierFetchConfig;
use common\services\fetcher\drivers\ApiFetcher;
use common\services\fetcher\drivers\FtpFetcher;
use common\services\fetcher\drivers\UrlFetcher;
use yii\base\Component;
use yii\base\InvalidArgumentException;

/**
 * Фабрика стратегий получения прайс-листов.
 *
 * По fetch_method из SupplierFetchConfig выбирает нужный драйвер:
 *   url → UrlFetcher (HTTP/HTTPS потоковое скачивание)
 *   ftp → FtpFetcher (FTP/FTPS бинарное скачивание)
 *   api → ApiFetcher (REST API с авторизацией)
 *
 * Использование:
 *   $factory = Yii::$app->get('fetcherFactory');
 *   $fetcher = $factory->create($config);
 *   $result = $fetcher->fetch($config);
 */
class FetcherFactory extends Component
{
    /** @var PriceFetcherInterface[] Кэш инстансов */
    private array $instances = [];

    /**
     * Создать фетчер для указанной конфигурации.
     *
     * @param SupplierFetchConfig $config
     * @return PriceFetcherInterface
     * @throws InvalidArgumentException
     */
    public function create(SupplierFetchConfig $config): PriceFetcherInterface
    {
        return $this->getByMethod($config->fetch_method);
    }

    /**
     * Получить фетчер по имени метода.
     *
     * @param string $method url|ftp|api
     * @return PriceFetcherInterface
     * @throws InvalidArgumentException
     */
    public function getByMethod(string $method): PriceFetcherInterface
    {
        if (isset($this->instances[$method])) {
            return $this->instances[$method];
        }

        $fetcher = match ($method) {
            'url'  => new UrlFetcher(),
            'ftp'  => new FtpFetcher(),
            'api'  => new ApiFetcher(),
            default => throw new InvalidArgumentException(
                "Неизвестный метод получения прайса: '{$method}'. Доступные: url, ftp, api"
            ),
        };

        $this->instances[$method] = $fetcher;
        return $fetcher;
    }

    /**
     * Скачать прайс — удобный shortcut.
     *
     * @param SupplierFetchConfig $config
     * @return FetchResult
     */
    public function fetch(SupplierFetchConfig $config): FetchResult
    {
        if ($config->fetch_method === 'manual') {
            return FetchResult::fail('Ручной метод — скачивание невозможно', 'manual');
        }

        $fetcher = $this->create($config);
        return $fetcher->fetch($config);
    }

    /**
     * Список поддерживаемых методов.
     */
    public function supportedMethods(): array
    {
        return ['url', 'ftp', 'api'];
    }
}
