<?php

namespace common\enums;

/**
 * Семейства товаров для сна.
 *
 * Определяет тип товара и связанную JSON-схему атрибутов.
 * Используется для:
 * - Строгой типизации AI-ответов (extractAttributes)
 * - Маппинга атрибутов → EAV в RosMatras
 * - Определения вариантообразующих атрибутов (width, length, color)
 * - Фильтрации в каталоге
 *
 * Пример:
 *   $family = ProductFamily::detect('Матрас Орматек Оптима 160x200');
 *   $schema = ProductFamilySchema::getSchema($family);
 */
enum ProductFamily: string
{
    case MATTRESS   = 'mattress';    // Матрасы
    case PILLOW     = 'pillow';      // Подушки
    case BLANKET    = 'blanket';     // Одеяла
    case BED        = 'bed';         // Кровати
    case BASE       = 'base';        // Основания
    case PROTECTOR  = 'protector';   // Наматрасники
    case TOPPER     = 'topper';      // Топперы
    case LINEN      = 'linen';       // Постельное бельё
    case ACCESSORY  = 'accessory';   // Аксессуары
    case UNKNOWN    = 'unknown';     // Не определено

    /**
     * Человекочитаемое название на русском.
     */
    public function label(): string
    {
        return match ($this) {
            self::MATTRESS  => 'Матрас',
            self::PILLOW    => 'Подушка',
            self::BLANKET   => 'Одеяло',
            self::BED       => 'Кровать',
            self::BASE      => 'Основание',
            self::PROTECTOR => 'Наматрасник',
            self::TOPPER    => 'Топпер',
            self::LINEN     => 'Постельное бельё',
            self::ACCESSORY => 'Аксессуар',
            self::UNKNOWN   => 'Не определено',
        };
    }

    /**
     * Множественное число на русском.
     */
    public function labelPlural(): string
    {
        return match ($this) {
            self::MATTRESS  => 'Матрасы',
            self::PILLOW    => 'Подушки',
            self::BLANKET   => 'Одеяла',
            self::BED       => 'Кровати',
            self::BASE      => 'Основания',
            self::PROTECTOR => 'Наматрасники',
            self::TOPPER    => 'Топперы',
            self::LINEN     => 'Постельное бельё',
            self::ACCESSORY => 'Аксессуары',
            self::UNKNOWN   => 'Прочее',
        };
    }

    /**
     * Автоопределение семейства по названию/категории товара.
     *
     * @param string $text Название товара или путь категории
     * @return self
     */
    public static function detect(string $text): self
    {
        $lower = mb_strtolower($text);

        // Порядок важен: сначала более специфичные, потом общие
        // [ProductFamily, [keywords...]]
        $patterns = [
            [self::TOPPER,    ['топпер', 'тонкий матрас', 'topper', 'матрас-топпер']],
            [self::PROTECTOR, ['наматрасник', 'чехол на матрас', 'protector', 'mattress pad', 'защита матраса']],
            [self::MATTRESS,  ['матрас', 'матрац', 'mattress']],
            [self::PILLOW,    ['подушк', 'pillow']],
            [self::BLANKET,   ['одеял', 'плед', 'покрывал', 'blanket']],
            [self::BED,       ['кроват', 'bed', 'кушетка', 'диван-кровать']],
            [self::BASE,      ['основани', 'решётк', 'решетк', 'ортопедическ основ', 'base', 'ламел']],
            [self::LINEN,     ['постельн', 'бельё', 'белье', 'простын', 'наволочк', 'пододеяльник', 'комплект белья']],
            [self::ACCESSORY, ['аксессуар', 'валик', 'ролик', 'accessory']],
        ];

        foreach ($patterns as [$family, $keywords]) {
            foreach ($keywords as $keyword) {
                if (mb_strpos($lower, $keyword) !== false) {
                    return $family;
                }
            }
        }

        return self::UNKNOWN;
    }

    /**
     * Все семейства кроме UNKNOWN.
     *
     * @return self[]
     */
    public static function concrete(): array
    {
        return array_filter(self::cases(), fn(self $f) => $f !== self::UNKNOWN);
    }

    /**
     * Карта: category slug → ProductFamily.
     * Привязка к начальным категориям из миграции.
     */
    public static function fromCategorySlug(string $slug): self
    {
        return match ($slug) {
            'matrasy'       => self::MATTRESS,
            'podushki'      => self::PILLOW,
            'odeyala'       => self::BLANKET,
            'krovati'       => self::BED,
            'osnovaniya'    => self::BASE,
            'namatrasniki'  => self::PROTECTOR,
            'toppery'       => self::TOPPER,
            'aksessuary'    => self::ACCESSORY,
            default         => self::UNKNOWN,
        };
    }
}
