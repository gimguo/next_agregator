<?php

namespace common\services;

use common\enums\ProductFamily;
use yii\base\Component;

/**
 * Строгие JSON-схемы атрибутов для каждого ProductFamily.
 *
 * Определяет:
 * - Какие атрибуты ожидаются для каждого типа товара
 * - Типы и допустимые значения (enum)
 * - Какие атрибуты вариантообразующие (создают ProductVariant в RosMatras)
 * - Единицы измерения
 *
 * Используется в:
 * - AIService::extractAttributesStrict() — передаётся в промпт AI
 * - NormalizeStagedJob — валидация нормализованных данных
 * - SyncController (RosMatras) — маппинг в EAV
 *
 * Пример:
 *   $schema = ProductFamilySchema::getSchema(ProductFamily::MATTRESS);
 *   $prompt = ProductFamilySchema::buildPromptBlock(ProductFamily::MATTRESS);
 */
class ProductFamilySchema extends Component
{
    /**
     * Получить полную схему атрибутов для семейства.
     *
     * @return array{
     *   family: string,
     *   label: string,
     *   attributes: array<string, array{type: string, required: bool, unit?: string, enum?: string[], variant: bool, label: string}>,
     *   variant_attributes: string[],
     *   required_attributes: string[],
     * }
     */
    public static function getSchema(ProductFamily $family): array
    {
        $attributes = self::getAttributes($family);

        $variantAttrs = [];
        $requiredAttrs = [];

        foreach ($attributes as $code => $def) {
            if ($def['variant'] ?? false) {
                $variantAttrs[] = $code;
            }
            if ($def['required'] ?? false) {
                $requiredAttrs[] = $code;
            }
        }

        return [
            'family'              => $family->value,
            'label'               => $family->label(),
            'attributes'          => $attributes,
            'variant_attributes'  => $variantAttrs,
            'required_attributes' => $requiredAttrs,
        ];
    }

    /**
     * Все схемы сразу (для кэширования).
     */
    public static function getAllSchemas(): array
    {
        $result = [];
        foreach (ProductFamily::concrete() as $family) {
            $result[$family->value] = self::getSchema($family);
        }
        return $result;
    }

    /**
     * Построить блок для AI-промпта: какие поля извлечь и в каком формате.
     *
     * Возвращает текст, который можно вставить в system/user prompt.
     */
    public static function buildPromptBlock(ProductFamily $family): string
    {
        $schema = self::getSchema($family);
        $lines = [];
        $lines[] = "Тип товара: {$schema['label']} ({$schema['family']})";
        $lines[] = "";
        $lines[] = "Извлеки СТРОГО следующие атрибуты в JSON:";
        $lines[] = "{";

        foreach ($schema['attributes'] as $code => $def) {
            $type = $def['type'];
            $comment = $def['label'];

            if (isset($def['unit'])) {
                $comment .= " ({$def['unit']})";
            }

            $typeHint = match ($type) {
                'integer' => 'число или null',
                'float'   => 'число с плавающей точкой или null',
                'boolean' => 'true/false или null',
                'string'  => 'строка или null',
                'enum'    => '"' . implode('" | "', $def['enum'] ?? []) . '" или null',
                'array'   => '["значение1", "значение2"]',
                default   => $type,
            };

            $req = ($def['required'] ?? false) ? ' [обязательное]' : '';
            $var = ($def['variant'] ?? false) ? ' [вариантообразующее]' : '';

            $lines[] = "  \"{$code}\": {$typeHint}, // {$comment}{$req}{$var}";
        }

        $lines[] = "}";

        if (!empty($schema['variant_attributes'])) {
            $lines[] = "";
            $lines[] = "ВАЖНО: Атрибуты [" . implode(', ', $schema['variant_attributes']) . "] являются вариантообразующими.";
            $lines[] = "Для каждого варианта (размера) товара они ДОЛЖНЫ быть заполнены раздельно (width и length — отдельные числа, НЕ строка '160x200').";
        }

        return implode("\n", $lines);
    }

    /**
     * Построить компактную JSON-схему для mode=json_object в API.
     *
     * Возвращает массив, который AI должен заполнить.
     */
    public static function buildJsonTemplate(ProductFamily $family): array
    {
        $template = [];
        $attributes = self::getAttributes($family);

        foreach ($attributes as $code => $def) {
            $template[$code] = match ($def['type']) {
                'integer' => 0,
                'float'   => 0.0,
                'boolean' => false,
                'string'  => '',
                'enum'    => $def['enum'][0] ?? '',
                'array'   => [],
                default   => null,
            };
        }

        return $template;
    }

    /**
     * Валидация атрибутов по схеме.
     *
     * @return array{valid: bool, errors: string[], cleaned: array}
     */
    public static function validate(ProductFamily $family, array $attributes): array
    {
        $schema = self::getSchema($family);
        $errors = [];
        $cleaned = [];

        foreach ($schema['attributes'] as $code => $def) {
            $value = $attributes[$code] ?? null;

            // Проверка обязательных
            if (($def['required'] ?? false) && $value === null) {
                $errors[] = "Атрибут '{$code}' ({$def['label']}) обязателен";
                continue;
            }

            if ($value === null) {
                $cleaned[$code] = null;
                continue;
            }

            // Валидация типа
            switch ($def['type']) {
                case 'integer':
                    if (!is_numeric($value)) {
                        $errors[] = "'{$code}' должен быть числом, получено: " . gettype($value);
                    } else {
                        $cleaned[$code] = (int)$value;
                    }
                    break;

                case 'float':
                    if (!is_numeric($value)) {
                        $errors[] = "'{$code}' должен быть числом, получено: " . gettype($value);
                    } else {
                        $cleaned[$code] = (float)$value;
                    }
                    break;

                case 'boolean':
                    $cleaned[$code] = (bool)$value;
                    break;

                case 'enum':
                    $allowed = $def['enum'] ?? [];
                    if (!in_array($value, $allowed, true)) {
                        // Попробуем fuzzy match
                        $matched = self::fuzzyMatchEnum($value, $allowed);
                        if ($matched !== null) {
                            $cleaned[$code] = $matched;
                        } else {
                            $errors[] = "'{$code}' имеет недопустимое значение '{$value}'. Допустимые: " . implode(', ', $allowed);
                        }
                    } else {
                        $cleaned[$code] = $value;
                    }
                    break;

                case 'array':
                    $cleaned[$code] = is_array($value) ? $value : [$value];
                    break;

                case 'string':
                default:
                    $cleaned[$code] = (string)$value;
                    break;
            }
        }

        return [
            'valid'   => empty($errors),
            'errors'  => $errors,
            'cleaned' => $cleaned,
        ];
    }

    /**
     * Fuzzy-матчинг значения enum (регистр, транслит).
     */
    protected static function fuzzyMatchEnum(string $value, array $allowed): ?string
    {
        $lower = mb_strtolower(trim($value));

        foreach ($allowed as $option) {
            if (mb_strtolower($option) === $lower) {
                return $option;
            }
        }

        return null;
    }

    // ═══════════════════════════════════════════════════════
    // ОПРЕДЕЛЕНИЯ АТРИБУТОВ ПО СЕМЕЙСТВАМ
    // ═══════════════════════════════════════════════════════

    /**
     * @return array<string, array{type: string, required: bool, label: string, unit?: string, enum?: string[], variant?: bool}>
     */
    protected static function getAttributes(ProductFamily $family): array
    {
        return match ($family) {
            ProductFamily::MATTRESS  => self::mattressAttributes(),
            ProductFamily::PILLOW    => self::pillowAttributes(),
            ProductFamily::BLANKET   => self::blanketAttributes(),
            ProductFamily::BED       => self::bedAttributes(),
            ProductFamily::BASE      => self::baseAttributes(),
            ProductFamily::PROTECTOR => self::protectorAttributes(),
            ProductFamily::TOPPER    => self::topperAttributes(),
            ProductFamily::LINEN     => self::linenAttributes(),
            ProductFamily::ACCESSORY => self::accessoryAttributes(),
            ProductFamily::UNKNOWN   => self::unknownAttributes(),
        };
    }

    /**
     * МАТРАС — основная категория.
     */
    protected static function mattressAttributes(): array
    {
        return [
            'width' => [
                'type' => 'integer', 'required' => false, 'variant' => true,
                'label' => 'Ширина', 'unit' => 'см',
            ],
            'length' => [
                'type' => 'integer', 'required' => false, 'variant' => true,
                'label' => 'Длина', 'unit' => 'см',
            ],
            'height' => [
                'type' => 'integer', 'required' => false, 'variant' => false,
                'label' => 'Высота', 'unit' => 'см',
            ],
            'stiffness_side_1' => [
                'type' => 'enum', 'required' => false, 'variant' => false,
                'label' => 'Жёсткость (сторона 1)',
                'enum' => ['soft', 'medium-soft', 'medium', 'medium-hard', 'hard'],
            ],
            'stiffness_side_2' => [
                'type' => 'enum', 'required' => false, 'variant' => false,
                'label' => 'Жёсткость (сторона 2)',
                'enum' => ['soft', 'medium-soft', 'medium', 'medium-hard', 'hard'],
            ],
            'spring_block' => [
                'type' => 'enum', 'required' => false, 'variant' => false,
                'label' => 'Пружинный блок',
                'enum' => ['none', 'bonnel', 'tfk', 's1000', 's2000', 'micropocket', 'other'],
            ],
            'max_load' => [
                'type' => 'integer', 'required' => false, 'variant' => false,
                'label' => 'Макс. нагрузка на спальное место', 'unit' => 'кг',
            ],
            'is_orthopedic' => [
                'type' => 'boolean', 'required' => false, 'variant' => false,
                'label' => 'Ортопедический',
            ],
            'is_two_sided' => [
                'type' => 'boolean', 'required' => false, 'variant' => false,
                'label' => 'Двусторонний',
            ],
            'materials' => [
                'type' => 'array', 'required' => false, 'variant' => false,
                'label' => 'Материалы наполнителя',
            ],
            'cover_material' => [
                'type' => 'string', 'required' => false, 'variant' => false,
                'label' => 'Материал чехла',
            ],
            'cover_removable' => [
                'type' => 'boolean', 'required' => false, 'variant' => false,
                'label' => 'Съёмный чехол',
            ],
            'warranty_years' => [
                'type' => 'integer', 'required' => false, 'variant' => false,
                'label' => 'Гарантия', 'unit' => 'лет',
            ],
        ];
    }

    /**
     * ПОДУШКА
     */
    protected static function pillowAttributes(): array
    {
        return [
            'width' => [
                'type' => 'integer', 'required' => false, 'variant' => true,
                'label' => 'Ширина', 'unit' => 'см',
            ],
            'length' => [
                'type' => 'integer', 'required' => false, 'variant' => true,
                'label' => 'Длина', 'unit' => 'см',
            ],
            'height' => [
                'type' => 'integer', 'required' => false, 'variant' => false,
                'label' => 'Высота', 'unit' => 'см',
            ],
            'fill_type' => [
                'type' => 'enum', 'required' => false, 'variant' => false,
                'label' => 'Тип наполнителя',
                'enum' => ['memory_foam', 'latex', 'fiber', 'down', 'buckwheat', 'gel', 'other'],
            ],
            'is_orthopedic' => [
                'type' => 'boolean', 'required' => false, 'variant' => false,
                'label' => 'Ортопедическая',
            ],
            'shape' => [
                'type' => 'enum', 'required' => false, 'variant' => false,
                'label' => 'Форма',
                'enum' => ['classic', 'ergonomic', 'wave', 'roll', 'other'],
            ],
            'cover_material' => [
                'type' => 'string', 'required' => false, 'variant' => false,
                'label' => 'Материал чехла',
            ],
            'cover_removable' => [
                'type' => 'boolean', 'required' => false, 'variant' => false,
                'label' => 'Съёмный чехол',
            ],
        ];
    }

    /**
     * ОДЕЯЛО
     */
    protected static function blanketAttributes(): array
    {
        return [
            'width' => [
                'type' => 'integer', 'required' => false, 'variant' => true,
                'label' => 'Ширина', 'unit' => 'см',
            ],
            'length' => [
                'type' => 'integer', 'required' => false, 'variant' => true,
                'label' => 'Длина', 'unit' => 'см',
            ],
            'fill_type' => [
                'type' => 'enum', 'required' => false, 'variant' => false,
                'label' => 'Тип наполнителя',
                'enum' => ['down', 'synthetic', 'bamboo', 'camel', 'sheep', 'silk', 'other'],
            ],
            'warmth_level' => [
                'type' => 'enum', 'required' => false, 'variant' => false,
                'label' => 'Степень тепла',
                'enum' => ['light', 'medium', 'warm', 'extra_warm'],
            ],
            'season' => [
                'type' => 'enum', 'required' => false, 'variant' => false,
                'label' => 'Сезонность',
                'enum' => ['summer', 'winter', 'all_season', 'demi_season'],
            ],
            'cover_material' => [
                'type' => 'string', 'required' => false, 'variant' => false,
                'label' => 'Материал чехла',
            ],
            'fill_weight' => [
                'type' => 'integer', 'required' => false, 'variant' => false,
                'label' => 'Вес наполнителя', 'unit' => 'г/м²',
            ],
        ];
    }

    /**
     * КРОВАТЬ
     */
    protected static function bedAttributes(): array
    {
        return [
            'width' => [
                'type' => 'integer', 'required' => false, 'variant' => true,
                'label' => 'Ширина спального места', 'unit' => 'см',
            ],
            'length' => [
                'type' => 'integer', 'required' => false, 'variant' => true,
                'label' => 'Длина спального места', 'unit' => 'см',
            ],
            'overall_height' => [
                'type' => 'integer', 'required' => false, 'variant' => false,
                'label' => 'Общая высота', 'unit' => 'см',
            ],
            'frame_material' => [
                'type' => 'enum', 'required' => false, 'variant' => false,
                'label' => 'Материал каркаса',
                'enum' => ['wood', 'metal', 'mdf', 'chipboard', 'upholstered', 'other'],
            ],
            'upholstery_material' => [
                'type' => 'string', 'required' => false, 'variant' => false,
                'label' => 'Материал обивки',
            ],
            'color' => [
                'type' => 'string', 'required' => false, 'variant' => true,
                'label' => 'Цвет',
            ],
            'has_storage' => [
                'type' => 'boolean', 'required' => false, 'variant' => false,
                'label' => 'Бельевой ящик',
            ],
            'has_lift_mechanism' => [
                'type' => 'boolean', 'required' => false, 'variant' => false,
                'label' => 'Подъёмный механизм',
            ],
            'headboard_type' => [
                'type' => 'enum', 'required' => false, 'variant' => false,
                'label' => 'Тип изголовья',
                'enum' => ['soft', 'hard', 'none', 'integrated'],
            ],
            'includes_base' => [
                'type' => 'boolean', 'required' => false, 'variant' => false,
                'label' => 'Основание в комплекте',
            ],
            'includes_mattress' => [
                'type' => 'boolean', 'required' => false, 'variant' => false,
                'label' => 'Матрас в комплекте',
            ],
        ];
    }

    /**
     * ОСНОВАНИЕ
     */
    protected static function baseAttributes(): array
    {
        return [
            'width' => [
                'type' => 'integer', 'required' => false, 'variant' => true,
                'label' => 'Ширина', 'unit' => 'см',
            ],
            'length' => [
                'type' => 'integer', 'required' => false, 'variant' => true,
                'label' => 'Длина', 'unit' => 'см',
            ],
            'height' => [
                'type' => 'integer', 'required' => false, 'variant' => false,
                'label' => 'Высота', 'unit' => 'см',
            ],
            'base_type' => [
                'type' => 'enum', 'required' => false, 'variant' => false,
                'label' => 'Тип основания',
                'enum' => ['lamella', 'solid', 'mesh', 'adjustable', 'folding', 'other'],
            ],
            'slat_count' => [
                'type' => 'integer', 'required' => false, 'variant' => false,
                'label' => 'Количество ламелей',
            ],
            'has_legs' => [
                'type' => 'boolean', 'required' => false, 'variant' => false,
                'label' => 'Ножки в комплекте',
            ],
            'is_adjustable' => [
                'type' => 'boolean', 'required' => false, 'variant' => false,
                'label' => 'Трансформируемое',
            ],
            'max_load' => [
                'type' => 'integer', 'required' => false, 'variant' => false,
                'label' => 'Макс. нагрузка', 'unit' => 'кг',
            ],
            'frame_material' => [
                'type' => 'enum', 'required' => false, 'variant' => false,
                'label' => 'Материал каркаса',
                'enum' => ['wood', 'metal', 'birch', 'other'],
            ],
        ];
    }

    /**
     * НАМАТРАСНИК
     */
    protected static function protectorAttributes(): array
    {
        return [
            'width' => [
                'type' => 'integer', 'required' => false, 'variant' => true,
                'label' => 'Ширина', 'unit' => 'см',
            ],
            'length' => [
                'type' => 'integer', 'required' => false, 'variant' => true,
                'label' => 'Длина', 'unit' => 'см',
            ],
            'height' => [
                'type' => 'integer', 'required' => false, 'variant' => false,
                'label' => 'Высота борта', 'unit' => 'см',
            ],
            'is_waterproof' => [
                'type' => 'boolean', 'required' => false, 'variant' => false,
                'label' => 'Водонепроницаемый',
            ],
            'material' => [
                'type' => 'string', 'required' => false, 'variant' => false,
                'label' => 'Материал',
            ],
            'attachment_type' => [
                'type' => 'enum', 'required' => false, 'variant' => false,
                'label' => 'Тип крепления',
                'enum' => ['elastic', 'skirt', 'zipper', 'straps', 'other'],
            ],
        ];
    }

    /**
     * ТОППЕР
     */
    protected static function topperAttributes(): array
    {
        return [
            'width' => [
                'type' => 'integer', 'required' => false, 'variant' => true,
                'label' => 'Ширина', 'unit' => 'см',
            ],
            'length' => [
                'type' => 'integer', 'required' => false, 'variant' => true,
                'label' => 'Длина', 'unit' => 'см',
            ],
            'height' => [
                'type' => 'integer', 'required' => false, 'variant' => false,
                'label' => 'Высота', 'unit' => 'см',
            ],
            'stiffness' => [
                'type' => 'enum', 'required' => false, 'variant' => false,
                'label' => 'Жёсткость',
                'enum' => ['soft', 'medium-soft', 'medium', 'medium-hard', 'hard'],
            ],
            'materials' => [
                'type' => 'array', 'required' => false, 'variant' => false,
                'label' => 'Материалы наполнителя',
            ],
            'max_load' => [
                'type' => 'integer', 'required' => false, 'variant' => false,
                'label' => 'Макс. нагрузка', 'unit' => 'кг',
            ],
            'cover_removable' => [
                'type' => 'boolean', 'required' => false, 'variant' => false,
                'label' => 'Съёмный чехол',
            ],
            'attachment_type' => [
                'type' => 'enum', 'required' => false, 'variant' => false,
                'label' => 'Тип крепления',
                'enum' => ['elastic', 'straps', 'none', 'other'],
            ],
        ];
    }

    /**
     * ПОСТЕЛЬНОЕ БЕЛЬЁ
     */
    protected static function linenAttributes(): array
    {
        return [
            'size_standard' => [
                'type' => 'enum', 'required' => false, 'variant' => true,
                'label' => 'Стандарт размера',
                'enum' => ['single', 'one_and_half', 'double', 'euro', 'family', 'baby', 'other'],
            ],
            'material' => [
                'type' => 'string', 'required' => false, 'variant' => false,
                'label' => 'Материал ткани',
            ],
            'thread_count' => [
                'type' => 'integer', 'required' => false, 'variant' => false,
                'label' => 'Плотность ткани', 'unit' => 'нит/см²',
            ],
            'color' => [
                'type' => 'string', 'required' => false, 'variant' => true,
                'label' => 'Цвет/Дизайн',
            ],
            'pieces_count' => [
                'type' => 'integer', 'required' => false, 'variant' => false,
                'label' => 'Количество предметов',
            ],
            'closure_type' => [
                'type' => 'enum', 'required' => false, 'variant' => false,
                'label' => 'Тип застёжки',
                'enum' => ['buttons', 'zipper', 'pocket', 'other'],
            ],
        ];
    }

    /**
     * АКСЕССУАРЫ (минимальная схема)
     */
    protected static function accessoryAttributes(): array
    {
        return [
            'width' => [
                'type' => 'integer', 'required' => false, 'variant' => true,
                'label' => 'Ширина', 'unit' => 'см',
            ],
            'length' => [
                'type' => 'integer', 'required' => false, 'variant' => true,
                'label' => 'Длина', 'unit' => 'см',
            ],
            'material' => [
                'type' => 'string', 'required' => false, 'variant' => false,
                'label' => 'Материал',
            ],
            'color' => [
                'type' => 'string', 'required' => false, 'variant' => true,
                'label' => 'Цвет',
            ],
        ];
    }

    /**
     * UNKNOWN — базовый набор
     */
    protected static function unknownAttributes(): array
    {
        return [
            'width' => [
                'type' => 'integer', 'required' => false, 'variant' => true,
                'label' => 'Ширина', 'unit' => 'см',
            ],
            'length' => [
                'type' => 'integer', 'required' => false, 'variant' => true,
                'label' => 'Длина', 'unit' => 'см',
            ],
            'height' => [
                'type' => 'integer', 'required' => false, 'variant' => false,
                'label' => 'Высота', 'unit' => 'см',
            ],
            'material' => [
                'type' => 'string', 'required' => false, 'variant' => false,
                'label' => 'Материал',
            ],
            'color' => [
                'type' => 'string', 'required' => false, 'variant' => true,
                'label' => 'Цвет',
            ],
        ];
    }
}
