<?php

namespace common\services\channel\drivers;

use common\models\SalesChannel;
use common\services\channel\SyndicatorInterface;
use common\services\RosMatrasSyndicationService;
use yii\base\Component;
use Yii;

/**
 * RosMatras Syndicator — адаптер существующего RosMatrasSyndicationService
 * под интерфейс SyndicatorInterface для многоканальной архитектуры.
 *
 * Делегирует работу проверенному RosMatrasSyndicationService::buildProductProjection(),
 * который формирует плоский JSON с selector_axes для фронтенда RosMatras.
 *
 * SalesChannel.api_config для RosMatras пока не влияет на формат проекции,
 * но в будущем может содержать маппинги категорий, фильтры семейств и т.д.
 */
class RosMatrasSyndicator extends Component implements SyndicatorInterface
{
    /** @var RosMatrasSyndicationService|null */
    private ?RosMatrasSyndicationService $service = null;

    /**
     * {@inheritdoc}
     */
    public function buildProjection(int $modelId, SalesChannel $channel): ?array
    {
        return $this->getService()->buildProductProjection($modelId);
    }

    /**
     * Получить базовый сервис (lazy load из DI).
     */
    private function getService(): RosMatrasSyndicationService
    {
        if ($this->service === null) {
            $this->service = Yii::$app->get('syndicationService');
        }
        return $this->service;
    }
}
