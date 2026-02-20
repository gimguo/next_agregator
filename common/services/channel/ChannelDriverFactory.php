<?php

namespace common\services\channel;

use common\models\SalesChannel;
use yii\base\Component;
use yii\base\InvalidConfigException;
use Yii;

/**
 * Фабрика драйверов каналов продаж (Factory + Strategy Pattern).
 *
 * По строке driver ('rosmatras', 'ozon', 'wb', 'yandex')
 * возвращает пару: Синдикатор + ApiClient.
 *
 * Регистрация драйверов:
 *   'channelFactory' => [
 *       'class' => ChannelDriverFactory::class,
 *       'drivers' => [
 *           'rosmatras' => [
 *               'syndicator' => RosMatrasSyndicator::class,
 *               'client'     => RosMatrasChannelClient::class,
 *           ],
 *           'ozon' => [
 *               'syndicator' => OzonSyndicator::class,
 *               'client'     => OzonApiClient::class,
 *           ],
 *       ],
 *   ],
 *
 * Использование:
 *   $factory = Yii::$app->get('channelFactory');
 *   $syndicator = $factory->getSyndicator($channel);
 *   $client     = $factory->getApiClient($channel);
 */
class ChannelDriverFactory extends Component
{
    /**
     * Реестр драйверов: driver_name => ['syndicator' => class, 'client' => class]
     * @var array<string, array{syndicator: string, client: string}>
     */
    public array $drivers = [];

    /** @var array Кэш инстансов (Singleton per driver) */
    private array $syndicatorInstances = [];

    /** @var array Кэш инстансов (Singleton per driver) */
    private array $clientInstances = [];

    /**
     * Получить синдикатор для канала.
     *
     * @param SalesChannel $channel
     * @return SyndicatorInterface
     * @throws InvalidConfigException Если драйвер не зарегистрирован
     */
    public function getSyndicator(SalesChannel $channel): SyndicatorInterface
    {
        $driver = $channel->driver;

        if (!isset($this->syndicatorInstances[$driver])) {
            $config = $this->getDriverConfig($driver);
            $syndicator = Yii::createObject($config['syndicator']);

            if (!$syndicator instanceof SyndicatorInterface) {
                throw new InvalidConfigException(
                    "Syndicator for driver '{$driver}' must implement SyndicatorInterface"
                );
            }

            $this->syndicatorInstances[$driver] = $syndicator;
        }

        return $this->syndicatorInstances[$driver];
    }

    /**
     * Получить API-клиент для канала.
     *
     * @param SalesChannel $channel
     * @return ApiClientInterface
     * @throws InvalidConfigException Если драйвер не зарегистрирован
     */
    public function getApiClient(SalesChannel $channel): ApiClientInterface
    {
        $driver = $channel->driver;

        if (!isset($this->clientInstances[$driver])) {
            $config = $this->getDriverConfig($driver);
            $client = Yii::createObject($config['client']);

            if (!$client instanceof ApiClientInterface) {
                throw new InvalidConfigException(
                    "ApiClient for driver '{$driver}' must implement ApiClientInterface"
                );
            }

            $this->clientInstances[$driver] = $client;
        }

        return $this->clientInstances[$driver];
    }

    /**
     * Проверить, зарегистрирован ли драйвер.
     */
    public function hasDriver(string $driver): bool
    {
        return isset($this->drivers[$driver]);
    }

    /**
     * Получить список зарегистрированных драйверов.
     *
     * @return string[]
     */
    public function getRegisteredDrivers(): array
    {
        return array_keys($this->drivers);
    }

    /**
     * Получить конфигурацию драйвера.
     *
     * @throws InvalidConfigException
     */
    private function getDriverConfig(string $driver): array
    {
        if (!isset($this->drivers[$driver])) {
            $registered = implode(', ', $this->getRegisteredDrivers()) ?: 'none';
            throw new InvalidConfigException(
                "Channel driver '{$driver}' is not registered in ChannelDriverFactory. " .
                "Registered drivers: {$registered}"
            );
        }

        $config = $this->drivers[$driver];

        if (!isset($config['syndicator']) || !isset($config['client'])) {
            throw new InvalidConfigException(
                "Channel driver '{$driver}' must define 'syndicator' and 'client' classes"
            );
        }

        return $config;
    }
}
