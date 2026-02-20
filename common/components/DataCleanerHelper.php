<?php

namespace common\components;

/**
 * Помощник очистки данных (из agregator_old + улучшения).
 * Используется при импорте прайсов для нормализации значений.
 */
class DataCleanerHelper
{
    /**
     * Очистка названия бренда.
     * Оставляет буквы, цифры, пробелы, скобки, дефисы, точки.
     */
    public static function cleanBrand(string $str): string
    {
        $clean = trim(preg_replace("/  +/", " ", preg_replace('/[^\p{L}0-9 ()\'\/\-`.+&]/iu', '', $str)));
        return $clean;
    }

    /**
     * Очистка артикула / SKU.
     * Только буквы и цифры, макс 50 символов.
     */
    public static function cleanSku(string $str): string
    {
        $clean = preg_replace('/[^\p{L}0-9\-_]/iu', '', $str);
        return empty($clean) || strlen($clean) > 50 ? '' : $clean;
    }

    /**
     * Очистка и форматирование цены.
     */
    public static function cleanPrice(string $str): float
    {
        return self::formatPrice(preg_replace('/[^\d.,]/', '', $str));
    }

    /**
     * Очистка количества.
     */
    public static function cleanQuantity(string $str): int
    {
        $qty = (int)round((float)preg_replace('/[^\d.,]/', '', $str));
        return min($qty, 30000);
    }

    /**
     * Очистка описания.
     */
    public static function cleanDescription(string $str): string
    {
        $clean = preg_replace('/[^\p{L}0-9 ()\'.,_\-\n\r]/iu', '', $str);
        return $clean ?: '';
    }

    /**
     * Нормализация названия модели.
     * Убирает лишние пробелы, нормализует кавычки.
     */
    public static function cleanModelName(string $str): string
    {
        $str = trim($str);
        $str = str_replace(['«', '»', '"', '"', '„'], '"', $str);
        $str = preg_replace('/\s+/', ' ', $str);
        return $str;
    }

    /**
     * Универсальный парсер цены из строки.
     * Корректно обрабатывает разные форматы: 1 200.50, 1.200,50, 1200,50 и т.д.
     */
    public static function formatPrice(string $num): float
    {
        $num = str_replace(",", ".", $num);
        $num = (substr(trim($num), -1) === ".") ? substr(trim($num), 0, -1) : $num;

        // Если 4-й символ с конца — точка, это тысячный разделитель
        if (strlen($num) >= 4 && substr($num, -4, 1) === ".") {
            $num = str_replace(".", "", $num);
        }

        $dotPos = strrpos($num, '.');
        $commaPos = strrpos($num, ',');
        $sep = (($dotPos > $commaPos) && $dotPos) ? $dotPos :
            ((($commaPos > $dotPos) && $commaPos) ? $commaPos : false);

        if (!$sep) {
            return (float)preg_replace("/[^0-9]/", "", $num);
        }

        return (float)(
            preg_replace("/[^0-9]/", "", substr($num, 0, $sep)) . '.' .
            preg_replace("/[^0-9]/", "", substr($num, $sep + 1))
        );
    }
}
