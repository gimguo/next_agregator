<?php

namespace common\models;

use yii\db\ActiveRecord;

/**
 * Требования канала к карточке товара.
 *
 * Для каждой пары (channel_id + family) определяет минимальный набор данных,
 * необходимый для успешной публикации на маркетплейсе.
 *
 * family = '*' — общие требования, которые действуют для всех семейств,
 * если для конкретного семейства нет отдельной записи.
 *
 * @property int    $id
 * @property int    $channel_id
 * @property string $family                  ProductFamily или '*' для wildcard
 * @property array  $required_attributes     JSONB — обязательные атрибуты ['height', 'spring_block']
 * @property array  $recommended_attributes  JSONB — рекомендуемые атрибуты (влияют на score, но не блокируют)
 * @property bool   $require_image
 * @property int    $min_images
 * @property bool   $require_barcode
 * @property bool   $require_description
 * @property int    $min_description_length
 * @property bool   $require_brand
 * @property bool   $require_price
 * @property bool   $is_active
 * @property string $created_at
 * @property string $updated_at
 *
 * @property SalesChannel $channel
 */
class ChannelRequirement extends ActiveRecord
{
    /** Wildcard — подходит для любого семейства */
    const FAMILY_WILDCARD = '*';

    public static function tableName(): string
    {
        return '{{%channel_requirements}}';
    }

    public function rules(): array
    {
        return [
            [['channel_id', 'family'], 'required'],
            ['family', 'string', 'max' => 30],
            [['channel_id', 'min_images', 'min_description_length'], 'integer'],
            [['require_image', 'require_barcode', 'require_description', 'require_brand', 'require_price', 'is_active'], 'boolean'],
            [['required_attributes', 'recommended_attributes'], 'safe'],
            ['min_images', 'default', 'value' => 1],
            ['min_description_length', 'default', 'value' => 50],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'id'                     => 'ID',
            'channel_id'             => 'Канал',
            'family'                 => 'Семейство',
            'required_attributes'    => 'Обязательные атрибуты',
            'recommended_attributes' => 'Рекомендуемые атрибуты',
            'require_image'          => 'Нужно фото',
            'min_images'             => 'Мин. фото',
            'require_barcode'        => 'Нужен штрихкод',
            'require_description'    => 'Нужно описание',
            'min_description_length' => 'Мин. длина описания',
            'require_brand'          => 'Нужен бренд',
            'require_price'          => 'Нужна цена',
            'is_active'              => 'Активно',
        ];
    }

    public function getChannel()
    {
        return $this->hasOne(SalesChannel::class, ['id' => 'channel_id']);
    }

    /**
     * Получить обязательные атрибуты как массив.
     */
    public function getRequiredAttrsList(): array
    {
        $val = $this->required_attributes;
        if (is_string($val)) {
            return json_decode($val, true) ?: [];
        }
        return is_array($val) ? $val : [];
    }

    /**
     * Получить рекомендуемые атрибуты как массив.
     */
    public function getRecommendedAttrsList(): array
    {
        $val = $this->recommended_attributes;
        if (is_string($val)) {
            return json_decode($val, true) ?: [];
        }
        return is_array($val) ? $val : [];
    }

    /**
     * Найти требование для канала + семейства.
     * Сначала ищет конкретное семейство, потом wildcard '*'.
     */
    public static function findForChannelAndFamily(int $channelId, string $family): ?self
    {
        // Сначала конкретное
        $req = static::find()
            ->where(['channel_id' => $channelId, 'family' => $family, 'is_active' => true])
            ->one();

        if ($req) {
            return $req;
        }

        // Fallback на wildcard
        return static::find()
            ->where(['channel_id' => $channelId, 'family' => self::FAMILY_WILDCARD, 'is_active' => true])
            ->one();
    }

    /**
     * Все требования для канала (для массового скоринга).
     *
     * @return array family => ChannelRequirement
     */
    public static function findAllForChannel(int $channelId): array
    {
        $requirements = static::find()
            ->where(['channel_id' => $channelId, 'is_active' => true])
            ->all();

        $map = [];
        foreach ($requirements as $req) {
            $map[$req->family] = $req;
        }

        return $map;
    }
}
