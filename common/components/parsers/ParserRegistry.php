<?php

namespace common\components\parsers;

use yii\base\Component;
use Yii;

/**
 * Реестр парсеров поставщиков.
 * Регистрируется как Yii-компонент.
 *
 * Конфиг:
 *   'parserRegistry' => [
 *       'class' => 'common\components\parsers\ParserRegistry',
 *       'parsers' => [
 *           'ormatek' => ['class' => 'common\components\parsers\OrmatekXmlParser'],
 *       ],
 *   ],
 */
class ParserRegistry extends Component
{
    /** @var array Конфигурации парсеров [code => config] */
    public array $parsers = [];

    /** @var ParserInterface[] Инстанцированные парсеры */
    private array $instances = [];

    public function init(): void
    {
        parent::init();

        // Авторегистрация по умолчанию
        if (empty($this->parsers)) {
            $this->parsers = [
                'ormatek' => ['class' => OrmatekXmlParser::class],
            ];
        }
    }

    /**
     * Получить парсер по коду поставщика.
     */
    public function get(string $supplierCode): ?ParserInterface
    {
        if (isset($this->instances[$supplierCode])) {
            return $this->instances[$supplierCode];
        }

        if (!isset($this->parsers[$supplierCode])) {
            return null;
        }

        $config = $this->parsers[$supplierCode];
        $parser = Yii::createObject($config);

        if (!$parser instanceof ParserInterface) {
            throw new \RuntimeException("Парсер '{$supplierCode}' не реализует ParserInterface");
        }

        $this->instances[$supplierCode] = $parser;
        return $parser;
    }

    /**
     * Определить парсер по файлу (автоопределение).
     */
    public function detect(string $filePath): ?ParserInterface
    {
        foreach (array_keys($this->parsers) as $code) {
            $parser = $this->get($code);
            if ($parser && $parser->accepts($filePath)) {
                return $parser;
            }
        }
        return null;
    }

    /**
     * Все зарегистрированные коды.
     * @return string[]
     */
    public function getCodes(): array
    {
        return array_keys($this->parsers);
    }
}
