<?php

namespace common\models;

use yii\db\ActiveRecord;

/**
 * PricingRule — правило ценообразования (наценка).
 *
 * @property int    $id
 * @property string $name
 * @property string $target_type   global | supplier | brand | family | category
 * @property int    $target_id     ID поставщика / бренда / категории (NULL для global/family)
 * @property string $target_value  Текстовое значение для family (напр. "mattress")
 * @property string $markup_type   percentage | fixed
 * @property float  $markup_value  Значение наценки (20.00 = +20% или +20₽)
 * @property int    $priority      Приоритет (чем выше, тем важнее)
 * @property float  $min_price     Минимальная цена для применения (NULL = без ограничения)
 * @property float  $max_price     Максимальная цена для применения (NULL = без ограничения)
 * @property string $rounding      Стратегия округления: none, round_up_100, round_up_10, round_down_100
 * @property bool   $is_active
 * @property string $created_at
 * @property string $updated_at
 */
class PricingRule extends ActiveRecord
{
    // ═══ target_type ═══
    const TARGET_GLOBAL   = 'global';
    const TARGET_SUPPLIER = 'supplier';
    const TARGET_BRAND    = 'brand';
    const TARGET_FAMILY   = 'family';
    const TARGET_CATEGORY = 'category';

    // ═══ markup_type ═══
    const MARKUP_PERCENTAGE = 'percentage';
    const MARKUP_FIXED      = 'fixed';

    // ═══ rounding ═══
    const ROUNDING_NONE           = 'none';
    const ROUNDING_UP_100         = 'round_up_100';
    const ROUNDING_UP_10          = 'round_up_10';
    const ROUNDING_DOWN_100       = 'round_down_100';

    public static function tableName(): string
    {
        return '{{%pricing_rules}}';
    }

    public function rules(): array
    {
        return [
            [['name', 'target_type', 'markup_type', 'markup_value'], 'required'],
            ['name', 'string', 'max' => 255],
            ['target_type', 'in', 'range' => [
                self::TARGET_GLOBAL,
                self::TARGET_SUPPLIER,
                self::TARGET_BRAND,
                self::TARGET_FAMILY,
                self::TARGET_CATEGORY,
            ]],
            ['markup_type', 'in', 'range' => [
                self::MARKUP_PERCENTAGE,
                self::MARKUP_FIXED,
            ]],
            ['rounding', 'in', 'range' => [
                self::ROUNDING_NONE,
                self::ROUNDING_UP_100,
                self::ROUNDING_UP_10,
                self::ROUNDING_DOWN_100,
            ]],
            [['markup_value', 'min_price', 'max_price'], 'number'],
            [['target_id', 'priority'], 'integer'],
            ['target_value', 'string', 'max' => 100],
            ['is_active', 'boolean'],
            ['priority', 'default', 'value' => 0],
            ['rounding', 'default', 'value' => self::ROUNDING_NONE],
            ['is_active', 'default', 'value' => true],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'id'           => 'ID',
            'name'         => 'Название',
            'target_type'  => 'Тип цели',
            'target_id'    => 'ID цели',
            'target_value' => 'Значение цели',
            'markup_type'  => 'Тип наценки',
            'markup_value' => 'Наценка',
            'priority'     => 'Приоритет',
            'min_price'    => 'Мин. цена',
            'max_price'    => 'Макс. цена',
            'rounding'     => 'Округление',
            'is_active'    => 'Активно',
            'created_at'   => 'Создано',
            'updated_at'   => 'Обновлено',
        ];
    }

    /**
     * Списки для UI.
     */
    public static function targetTypes(): array
    {
        return [
            self::TARGET_GLOBAL   => 'Глобальное',
            self::TARGET_SUPPLIER => 'Поставщик',
            self::TARGET_BRAND    => 'Бренд',
            self::TARGET_FAMILY   => 'Семейство',
            self::TARGET_CATEGORY => 'Категория',
        ];
    }

    public static function markupTypes(): array
    {
        return [
            self::MARKUP_PERCENTAGE => 'Процент',
            self::MARKUP_FIXED      => 'Фиксированная',
        ];
    }

    public static function roundingStrategies(): array
    {
        return [
            self::ROUNDING_NONE     => 'Без округления',
            self::ROUNDING_UP_100   => 'Вверх до 100₽',
            self::ROUNDING_UP_10    => 'Вверх до 10₽',
            self::ROUNDING_DOWN_100 => 'Вниз до 100₽',
        ];
    }

    /**
     * Проверяет, попадает ли цена в диапазон min_price / max_price.
     */
    public function matchesPrice(float $basePrice): bool
    {
        if ($this->min_price !== null && $basePrice < (float)$this->min_price) {
            return false;
        }
        if ($this->max_price !== null && $basePrice > (float)$this->max_price) {
            return false;
        }
        return true;
    }
}
