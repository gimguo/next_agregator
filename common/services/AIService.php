<?php

namespace common\services;

use common\enums\ProductFamily;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\RequestException;
use yii\base\Component;
use Yii;

/**
 * AI-сервис для интеллектуальной обработки каталога.
 *
 * Использует OpenRouter API (DeepSeek) для:
 * - Сопоставления товаров от разных поставщиков
 * - Автоматической категоризации
 * - Извлечения атрибутов из описаний
 * - Генерации эталонных описаний карточек
 * - Анализа качества карточек
 * - Нормализации названий брендов
 * - Подсказок по настройке парсинга
 */
class AIService extends Component
{
    /** @var string API-ключ OpenRouter */
    public string $apiKey = '';

    /** @var string Модель LLM */
    public string $model = 'deepseek/deepseek-chat-v3-0324';

    /** @var string Базовый URL API */
    public string $baseUrl = 'https://openrouter.ai/api/v1';

    /** @var string URL приложения (для заголовков) */
    public string $appUrl = 'http://localhost';

    private ?HttpClient $httpClient = null;

    /** @var array Кэш ответов для снижения расходов */
    private array $cache = [];

    public function init(): void
    {
        parent::init();
        $params = Yii::$app->params;

        if (empty($this->apiKey)) {
            $this->apiKey = $params['openrouter']['apiKey'] ?? '';
        }
        if (empty($this->model) || $this->model === 'deepseek/deepseek-chat-v3-0324') {
            $this->model = $params['openrouter']['model'] ?? 'deepseek/deepseek-chat-v3-0324';
        }
        if (empty($this->baseUrl) || $this->baseUrl === 'https://openrouter.ai/api/v1') {
            $this->baseUrl = $params['openrouter']['baseUrl'] ?? 'https://openrouter.ai/api/v1';
        }

        // Trailing slash обязателен для корректного resolve относительных путей в Guzzle
        $baseUri = rtrim($this->baseUrl, '/') . '/';
        $this->httpClient = new HttpClient([
            'base_uri' => $baseUri,
            'timeout' => 60,
            'headers' => [
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
                'HTTP-Referer' => $this->appUrl,
                'X-Title' => 'NextAgregator',
            ],
        ]);
    }

    /**
     * Проверить доступность AI.
     */
    public function isAvailable(): bool
    {
        if (empty($this->apiKey)) {
            return false;
        }

        // Плейсхолдеры
        $placeholders = ['your-openrouter-api-key', 'sk-or-v1-your-key-here'];
        return !in_array($this->apiKey, $placeholders, true);
    }

    /**
     * Информация о модели.
     */
    public function getModelInfo(): array
    {
        return [
            'model' => $this->model,
            'base_url' => $this->baseUrl,
            'available' => $this->isAvailable(),
        ];
    }

    // ═══════════════════════════════════════════
    // СОПОСТАВЛЕНИЕ ТОВАРОВ (Product Matching)
    // ═══════════════════════════════════════════

    /**
     * Определяет, являются ли два товара одним и тем же.
     *
     * @param array $productA ['name', 'manufacturer', 'model', 'attributes', 'category']
     * @param array $productB аналогично
     * @return array{is_match: bool, confidence: float, reason: string}
     */
    public function matchProducts(array $productA, array $productB): array
    {
        $cacheKey = 'match:' . md5(json_encode([$productA, $productB]));
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $attrsA = json_encode($productA['attributes'] ?? [], JSON_UNESCAPED_UNICODE);
        $attrsB = json_encode($productB['attributes'] ?? [], JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
Ты — эксперт по товарам для сна (матрасы, кровати, подушки, наматрасники).
Определи, являются ли два товара одним и тем же продуктом от одного производителя.

Товар A:
- Название: {$productA['name']}
- Производитель: {$productA['manufacturer']}
- Модель: {$productA['model']}
- Категория: {$productA['category']}
- Атрибуты: {$attrsA}

Товар B:
- Название: {$productB['name']}
- Производитель: {$productB['manufacturer']}
- Модель: {$productB['model']}
- Категория: {$productB['category']}
- Атрибуты: {$attrsB}

Ответь СТРОГО в формате JSON (без markdown):
{
  "is_match": true/false,
  "confidence": 0.0-1.0,
  "reason": "краткое объяснение"
}
PROMPT;

        $result = $this->chat($prompt, 0.1);
        $parsed = $this->parseJsonResponse($result);

        $this->cache[$cacheKey] = $parsed;
        return $parsed;
    }

    /**
     * Batch-сопоставление: найти лучшее совпадение среди кандидатов.
     *
     * @param array $product Новый товар
     * @param array $candidates Массив кандидатов [{id, name, manufacturer, model}, ...]
     * @return array{match_id: ?int, confidence: float, reason: string}
     */
    public function findBestMatch(array $product, array $candidates): array
    {
        if (empty($candidates)) {
            return ['match_id' => null, 'confidence' => 0, 'reason' => 'Нет кандидатов'];
        }

        $candidatesList = '';
        foreach ($candidates as $i => $c) {
            $candidatesList .= sprintf(
                "\n[%d] id=%d | %s | %s | %s",
                $i + 1,
                $c['id'],
                $c['name'],
                $c['manufacturer'] ?? '—',
                $c['model'] ?? '—'
            );
        }

        $prompt = <<<PROMPT
Ты — эксперт по товарам для сна. Найди лучшее совпадение для товара среди кандидатов.

Новый товар:
- Название: {$product['name']}
- Производитель: {$product['manufacturer']}
- Модель: {$product['model']}
- Категория: {$product['category']}

Кандидаты из каталога:
{$candidatesList}

Ответь СТРОГО в JSON (без markdown):
{
  "match_index": номер_кандидата_или_0_если_нет_совпадений,
  "confidence": 0.0-1.0,
  "reason": "объяснение"
}
PROMPT;

        $result = $this->chat($prompt, 0.1);
        $parsed = $this->parseJsonResponse($result);

        $matchIndex = ($parsed['match_index'] ?? 0) - 1;
        $matchId = $matchIndex >= 0 && isset($candidates[$matchIndex])
            ? $candidates[$matchIndex]['id']
            : null;

        return [
            'match_id' => $matchId,
            'confidence' => $parsed['confidence'] ?? 0,
            'reason' => $parsed['reason'] ?? 'Неизвестно',
        ];
    }

    // ═══════════════════════════════════════════
    // АВТОМАТИЧЕСКАЯ КАТЕГОРИЗАЦИЯ
    // ═══════════════════════════════════════════

    /**
     * Определяет категорию и семейство товара.
     *
     * @param string $productName Название
     * @param string $brand Бренд
     * @param string $description Описание
     * @param array $availableCategories [id => 'Матрасы', ...]
     * @return array{category_id: int, product_family: string, product_type: string, confidence: float}
     */
    public function categorize(string $productName, string $brand, string $description, array $availableCategories): array
    {
        $categoriesList = '';
        foreach ($availableCategories as $id => $path) {
            $categoriesList .= "\n  [{$id}] {$path}";
        }

        $familyValues = implode(', ', array_map(
            fn(ProductFamily $f) => "'{$f->value}'",
            ProductFamily::concrete()
        ));

        $prompt = <<<PROMPT
Определи категорию и тип товара из списка.

Товар: {$productName}
Бренд: {$brand}
Описание: {$description}

Доступные категории:
{$categoriesList}

Допустимые product_family: {$familyValues}

Ответь СТРОГО в JSON:
{
  "category_id": число,
  "product_family": "значение из списка product_family",
  "product_type": "человекочитаемый тип (например: Матрас пружинный)",
  "confidence": 0.0-1.0
}
PROMPT;

        $result = $this->chat($prompt, 0.1);
        return $this->parseJsonResponse($result);
    }

    // ═══════════════════════════════════════════
    // ИЗВЛЕЧЕНИЕ АТРИБУТОВ ИЗ ОПИСАНИЯ
    // ═══════════════════════════════════════════

    /**
     * Извлекает атрибуты со строгой типизацией по ProductFamily.
     *
     * Если семейство не передано — определяется автоматически из названия.
     * AI получает жёсткую JSON-схему и обязан вернуть ответ строго по ней.
     *
     * @param string $productName Название товара
     * @param string $description Описание товара
     * @param ProductFamily|null $family Семейство товара (auto-detect если null)
     * @return array{family: string, attributes: array, validation: array}
     */
    public function extractAttributesStrict(
        string $productName,
        string $description,
        ?ProductFamily $family = null
    ): array {
        // Автоопределение семейства
        if ($family === null) {
            $family = ProductFamily::detect($productName);
        }

        $schemaBlock = ProductFamilySchema::buildPromptBlock($family);
        $jsonTemplate = json_encode(
            ProductFamilySchema::buildJsonTemplate($family),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );

        $prompt = <<<PROMPT
Ты — эксперт по товарам для сна. Извлеки характеристики товара из названия и описания.

═══ ТОВАР ═══
Название: {$productName}
Описание: {$description}

═══ СХЕМА АТРИБУТОВ ═══
{$schemaBlock}

═══ ПРАВИЛА ═══
1. Используй ТОЛЬКО ключи из схемы выше. НЕ добавляй свои поля.
2. Размеры (width, length) ДОЛЖНЫ быть отдельными числами: width=160, length=200. НЕ строкой "160x200".
3. Для enum-полей используй ТОЛЬКО допустимые значения из схемы.
4. Если значение неизвестно — ставь null.
5. Не выдумывай данные. Извлекай только то, что явно указано в названии или описании.

═══ ФОРМАТ ОТВЕТА ═══
Верни СТРОГО JSON:
{
  "product_family": "{$family->value}",
  "attributes": {$jsonTemplate}
}
PROMPT;

        $result = $this->chat($prompt, 0.1, 1500);
        $parsed = $this->parseJsonResponse($result);

        // Берём атрибуты из ответа AI
        $aiAttributes = $parsed['attributes'] ?? $parsed;

        // Валидация и очистка по схеме
        $validation = ProductFamilySchema::validate($family, $aiAttributes);

        return [
            'family'     => $family->value,
            'attributes' => $validation['cleaned'],
            'validation' => [
                'valid'  => $validation['valid'],
                'errors' => $validation['errors'],
            ],
        ];
    }

    /**
     * Извлекает атрибуты (обратная совместимость — делегирует в extractAttributesStrict).
     */
    public function extractAttributes(string $productName, string $description): array
    {
        $result = $this->extractAttributesStrict($productName, $description);
        return $result['attributes'] ?? [];
    }

    // ═══════════════════════════════════════════
    // ГЕНЕРАЦИЯ ЭТАЛОННОГО ОПИСАНИЯ
    // ═══════════════════════════════════════════

    /**
     * Генерирует красивое единое описание для карточки.
     */
    public function generateCanonicalDescription(array $supplierDescriptions, array $attributes): array
    {
        $descriptions = '';
        foreach ($supplierDescriptions as $supplier => $desc) {
            $descriptions .= "\n--- {$supplier} ---\n{$desc}\n";
        }
        $attrs = json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $prompt = <<<PROMPT
Ты — копирайтер интернет-магазина товаров для сна.
На основе описаний от разных поставщиков создай единое эталонное описание.

Описания от поставщиков:
{$descriptions}

Атрибуты товара:
{$attrs}

Создай:
1. Краткое описание (1-2 предложения для листинга)
2. Полное описание (3-5 абзацев, продающий текст)
3. SEO title (до 70 символов)
4. SEO description (до 160 символов)

Ответь в JSON:
{
  "short_description": "...",
  "full_description": "...",
  "meta_title": "...",
  "meta_description": "..."
}
PROMPT;

        $result = $this->chat($prompt, 0.7);
        return $this->parseJsonResponse($result);
    }

    // ═══════════════════════════════════════════
    // АНАЛИЗ КАЧЕСТВА КАРТОЧКИ
    // ═══════════════════════════════════════════

    /**
     * Оценивает полноту и качество карточки товара.
     */
    public function analyzeCardQuality(array $productCard): array
    {
        $card = json_encode($productCard, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $prompt = <<<PROMPT
Оцени качество карточки товара для интернет-магазина:

{$card}

Проверь:
- Полнота названия
- Наличие описания
- Заполнённость атрибутов
- Наличие изображений
- SEO-оптимизация

Ответь в JSON:
{
  "score": 0-100,
  "grade": "A" / "B" / "C" / "D" / "F",
  "issues": ["проблема1", "проблема2"],
  "suggestions": ["совет1", "совет2"]
}
PROMPT;

        $result = $this->chat($prompt, 0.3);
        return $this->parseJsonResponse($result);
    }

    // ═══════════════════════════════════════════
    // АНАЛИЗ ПРАЙС-ЛИСТА
    // ═══════════════════════════════════════════

    /**
     * Анализирует фрагмент прайс-листа и предлагает настройки парсинга.
     */
    public function analyzePriceList(string $sampleData, string $format = 'xml'): array
    {
        $prompt = <<<PROMPT
Ты — эксперт по парсингу прайс-листов поставщиков товаров.
Проанализируй фрагмент прайс-листа и предложи оптимальные настройки парсинга.

Формат: {$format}
Фрагмент данных:
```
{$sampleData}
```

Проанализируй:
1. Структуру данных
2. Группировку товаров
3. Вариантообразующие атрибуты
4. Аномалии
5. Рекомендации по маппингу полей

Ответь в JSON:
{
  "structure": {
    "root_element": "...",
    "product_element": "...",
    "key_fields": ["field1"],
    "grouping_field": "...",
    "variant_fields": ["field1"]
  },
  "field_mapping": {"source_field": "target_field"},
  "anomalies": ["аномалия1"],
  "recommendations": ["рекомендация1"],
  "estimated_products": число,
  "data_quality_score": 0-100
}
PROMPT;

        $result = $this->chat($prompt, 0.3);
        return $this->parseJsonResponse($result);
    }

    // ═══════════════════════════════════════════
    // РЕЦЕПТ ИМПОРТА (batch-анализ прайса)
    // ═══════════════════════════════════════════

    /**
     * Генерирует «рецепт» для автоматической нормализации всего прайса.
     *
     * AI анализирует выборку из 30-50 товаров и возвращает:
     * - маппинг брендов (грязное → каноническое)
     * - правила категоризации (паттерн → категория)
     * - правила нормализации названий
     * - тип группировки вариантов
     * - product_family для каждой категории (для привязки к JSON-схемам атрибутов)
     *
     * @param array $sampleProducts Выборка товаров (массивы, не DTO)
     * @param array $existingBrands Известные бренды в системе
     * @param array $existingCategories Существующие категории
     * @param array $uniqueBrands Все уникальные бренды в прайсе
     * @param array $uniqueCategories Все уникальные категории в прайсе
     * @return array Рецепт нормализации
     */
    public function generateImportRecipe(
        array $sampleProducts,
        array $existingBrands = [],
        array $existingCategories = [],
        array $uniqueBrands = [],
        array $uniqueCategories = [],
    ): array {
        // Формируем компактный сэмпл для промпта
        $sampleLines = '';
        $sampleCount = 0;
        foreach (array_slice($sampleProducts, 0, 40) as $i => $p) {
            $variants = count($p['variants'] ?? []);
            $priceRange = '';
            if (!empty($p['variants'])) {
                $prices = array_filter(array_column($p['variants'], 'price'), fn($pr) => $pr > 0);
                if ($prices) $priceRange = min($prices) . '–' . max($prices) . '₽';
            }
            $sampleLines .= sprintf(
                "\n[%d] Бренд: %s | Модель: %s | Категория: %s | Вариантов: %d | Цена: %s",
                $i + 1,
                $p['manufacturer'] ?? $p['brand'] ?? '—',
                $p['model'] ?? $p['name'] ?? '—',
                $p['category_path'] ?? '—',
                $variants,
                $priceRange ?: '—',
            );
            $sampleCount = $i + 1;
        }

        $brandsList = implode(', ', array_slice($uniqueBrands, 0, 30));
        $categoriesList = implode(', ', array_slice($uniqueCategories, 0, 30));
        $existingBrandsList = implode(', ', array_slice($existingBrands, 0, 50));
        $existingCatList = '';
        foreach (array_slice($existingCategories, 0, 30) as $id => $name) {
            $existingCatList .= "\n  [{$id}] {$name}";
        }

        // Список допустимых product_family для AI
        $familyValues = implode(', ', array_map(
            fn(ProductFamily $f) => "'{$f->value}' ({$f->label()})",
            ProductFamily::concrete()
        ));

        $prompt = <<<PROMPT
Ты — эксперт по товарам для сна (матрасы, подушки, одеяла, кровати, наматрасники, основания).
Проанализируй выборку из прайс-листа поставщика и создай РЕЦЕПТ для автоматической нормализации ВСЕХ товаров.

═══ ВЫБОРКА ТОВАРОВ ({$sampleCount} шт.) ═══
{$sampleLines}

═══ ВСЕ БРЕНДЫ В ПРАЙСЕ ═══
{$brandsList}

═══ ВСЕ КАТЕГОРИИ В ПРАЙСЕ ═══
{$categoriesList}

═══ ИЗВЕСТНЫЕ БРЕНДЫ В СИСТЕМЕ ═══
{$existingBrandsList}

═══ СУЩЕСТВУЮЩИЕ КАТЕГОРИИ В СИСТЕМЕ ═══
{$existingCatList}

═══ ДОПУСТИМЫЕ СЕМЕЙСТВА ТОВАРОВ ═══
{$familyValues}

═══ ЗАДАЧА ═══
Создай JSON-рецепт для автоматической обработки ВСЕГО прайса:

1. **brand_mapping** — Маппинг брендов из прайса в канонические названия.
   Для каждого бренда определи: это существующий (alias), новый (create) или мусор (skip).

2. **category_mapping** — Маппинг категорий из прайса в существующие категории системы.
   Для каждой категории ОБЯЗАТЕЛЬНО укажи `product_family` из списка допустимых семейств.
   Если точного совпадения нет — предложи наиболее близкую или новую.

3. **name_rules** — Правила нормализации названий.

4. **product_type_rules** — Правила определения product_family из названия/категории.
   Используй ТОЛЬКО значения из списка допустимых семейств.

5. **attribute_extraction_rules** — Правила извлечения размеров из названия.
   Обрати внимание: размеры ВСЕГДА должны быть раздельно: width (число), length (число).
   НЕ допускается формат "160x200" — только отдельные поля.

Ответь СТРОГО в JSON:
{
  "brand_mapping": {
    "ОРМАТЭК": {"canonical": "Орматек", "action": "alias"},
    "New Brand": {"canonical": "New Brand", "action": "create"}
  },
  "category_mapping": {
    "Матрасы пружинные": {"target_id": 1, "target_name": "Матрасы", "product_family": "mattress"},
    "Подушки ортопедические": {"target_id": null, "target_name": "Подушки", "product_family": "pillow", "action": "create"}
  },
  "name_template": "{brand} {model}",
  "name_rules": {
    "remove_brand_prefix": true,
    "capitalize": true,
    "trim_whitespace": true
  },
  "product_type_rules": [
    {"pattern": "матрас", "family": "mattress"},
    {"pattern": "подушка", "family": "pillow"},
    {"pattern": "одеяло", "family": "blanket"},
    {"pattern": "кровать", "family": "bed"},
    {"pattern": "наматрасник", "family": "protector"},
    {"pattern": "основание", "family": "base"},
    {"pattern": "топпер", "family": "topper"}
  ],
  "attribute_extraction_rules": {
    "size_pattern": "описание regex/правила для извлечения ширины и длины",
    "size_in_name": true,
    "size_separator": "х|x|*|X",
    "notes": "заметки по специфике прайса"
  },
  "variant_grouping": "model_name",
  "insights": {
    "total_brands": 0,
    "total_categories": 0,
    "data_quality": 0,
    "notes": ["заметка1"]
  }
}
PROMPT;

        $result = $this->chat($prompt, 0.2, 4000);
        return $this->parseJsonResponse($result);
    }

    /**
     * Генерация описания товара (legacy — простой текст).
     */
    public function generateDescription(string $productName, string $brand, string $existingDescription = ''): string
    {
        $prompt = <<<PROMPT
Создай SEO-оптимизированное описание для товара для сна.

Товар: {$productName}
Бренд: {$brand}
Существующее описание: {$existingDescription}

Создай продающее описание на 2-3 абзаца (до 500 символов).
Не выдумывай технические характеристики, если их нет в исходных данных.
Ответь ТОЛЬКО текстом описания, без JSON и markdown.
PROMPT;

        return trim($this->chat($prompt, 0.7, 1000));
    }

    // ═══════════════════════════════════════════
    // AUTO-HEALING (Sprint 13)
    // ═══════════════════════════════════════════

    /**
     * Генерация SEO-описания для модели (Auto-Healing).
     *
     * Использует все имеющиеся данные модели: название, бренд, семейство, атрибуты.
     * Возвращает description + short_description в JSON.
     *
     * @param string $modelName     Название модели ("Орматек Оптима")
     * @param string $brand         Бренд ("Орматек")
     * @param string $family        Семейство ("mattress")
     * @param array  $attributes    Канонические атрибуты модели
     * @param string $existingDesc  Существующее описание (если есть)
     * @return array{description: string, short_description: string}|null
     */
    public function generateSeoDescription(
        string $modelName,
        string $brand,
        string $family,
        array  $attributes = [],
        string $existingDesc = ''
    ): ?array {
        $attrsJson = !empty($attributes)
            ? json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            : 'Нет данных';

        $familyLabel = $this->getFamilyLabel($family);

        $prompt = <<<PROMPT
Напиши продающее SEO-описание для товара категории «{$familyLabel}».

═══ ТОВАР ═══
Название: {$modelName}
Бренд: {$brand}
Тип товара: {$familyLabel}

═══ ХАРАКТЕРИСТИКИ ═══
{$attrsJson}

═══ СУЩЕСТВУЮЩЕЕ ОПИСАНИЕ ═══
{$existingDesc}

═══ ПРАВИЛА ═══
1. Полное описание: 1000-1500 символов, разбитое на 3-4 абзаца.
2. Краткое описание: 1-2 предложения (до 200 символов), для карточки в каталоге.
3. Текст должен быть на русском, продающий, без «воды».
4. Упоминай ТОЛЬКО те характеристики, что указаны выше. НЕ выдумывай.
5. Если характеристик мало — фокусируйся на бренде и общих преимуществах типа товара.
6. Используй абзацы (\n\n) для разделения блоков текста.

Ответь СТРОГО в JSON:
{
  "description": "полное описание",
  "short_description": "краткое описание для каталога"
}
PROMPT;

        $result = $this->chat($prompt, 0.7, 2000);
        $parsed = $this->parseJsonResponse($result);

        if (empty($parsed['description'])) {
            return null;
        }

        return [
            'description'       => trim($parsed['description']),
            'short_description' => trim($parsed['short_description'] ?? ''),
        ];
    }

    /**
     * Определение недостающих атрибутов на основе названия и контекста (Auto-Healing).
     *
     * AI анализирует название, бренд, семейство и пытается определить
     * значения недостающих атрибутов.
     *
     * @param string $modelName        Название модели
     * @param string $brand            Бренд
     * @param string $family           Семейство товара
     * @param array  $targetAttributes Список атрибутов для определения ['frame_material', 'color']
     * @param array  $existingAttrs    Уже известные атрибуты модели
     * @return array Массив определённых атрибутов (ключ => значение), пустые = не определено
     */
    public function inferMissingAttributes(
        string $modelName,
        string $brand,
        string $family,
        array  $targetAttributes,
        array  $existingAttrs = []
    ): array {
        if (empty($targetAttributes)) {
            return [];
        }

        $existingJson = !empty($existingAttrs)
            ? json_encode($existingAttrs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            : 'Нет данных';

        $attrsList = implode(', ', $targetAttributes);
        $familyLabel = $this->getFamilyLabel($family);

        // Получаем JSON-схему для семейства (если есть)
        $schemaBlock = '';
        try {
            $familyEnum = ProductFamily::tryFrom($family);
            if ($familyEnum) {
                $schemaBlock = ProductFamilySchema::buildPromptBlock($familyEnum);
            }
        } catch (\Throwable $e) {
            // Нет схемы — не страшно
        }

        $schemaSection = $schemaBlock
            ? "\n═══ СХЕМА АТРИБУТОВ ═══\n{$schemaBlock}\n"
            : '';

        $prompt = <<<PROMPT
Для товара категории «{$familyLabel}» определи значения следующих атрибутов: {$attrsList}.

═══ ТОВАР ═══
Название: {$modelName}
Бренд: {$brand}
Тип товара: {$familyLabel}

═══ ИЗВЕСТНЫЕ АТРИБУТЫ ═══
{$existingJson}
{$schemaSection}
═══ ПРАВИЛА ═══
1. Определяй ТОЛЬКО те атрибуты, что указаны в задании: {$attrsList}
2. Если значение МОЖНО уверенно определить из названия/контекста — укажи его.
3. Если значение НЕЛЬЗЯ определить — ставь null.
4. Размеры (width, length, height) — только числа в сантиметрах.
5. Для enum-полей используй ТОЛЬКО допустимые значения из схемы.
6. НЕ выдумывай. Лучше null, чем неверное значение.

Ответь СТРОГО в JSON:
{
  "attributes": {
    "ключ_атрибута": "значение или null"
  },
  "confidence": 0.0-1.0
}
PROMPT;

        $result = $this->chat($prompt, 0.2, 1000);
        $parsed = $this->parseJsonResponse($result);

        $attributes = $parsed['attributes'] ?? $parsed;

        // Фильтруем — оставляем только запрошенные и не-null
        $filtered = [];
        foreach ($targetAttributes as $key) {
            if (isset($attributes[$key]) && $attributes[$key] !== null && $attributes[$key] !== '' && $attributes[$key] !== 'null') {
                $filtered[$key] = $attributes[$key];
            }
        }

        return $filtered;
    }

    /**
     * Человекочитаемое название семейства товаров.
     */
    private function getFamilyLabel(string $family): string
    {
        $map = [
            'mattress'  => 'Матрас',
            'pillow'    => 'Подушка',
            'blanket'   => 'Одеяло',
            'bed'       => 'Кровать',
            'base'      => 'Основание для кровати',
            'topper'    => 'Топпер',
            'protector' => 'Наматрасник',
            'bedlinen'  => 'Постельное бельё',
            'furniture' => 'Мебель',
            'accessory' => 'Аксессуар',
        ];
        return $map[$family] ?? $family;
    }

    /**
     * Анализ качества карточки (упрощённый).
     */
    public function analyzeQuality(array $cardData): array
    {
        $card = json_encode($cardData, JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
Оцени качество карточки товара (0-100):
{$card}

Критерии: полнота названия, описание, атрибуты, картинки.
Ответь JSON: {"score": число, "issues": ["проблема"]}
PROMPT;

        $result = $this->chat($prompt, 0.2, 500);
        return $this->parseJsonResponse($result);
    }

    // ═══════════════════════════════════════════
    // НОРМАЛИЗАЦИЯ НАЗВАНИЯ
    // ═══════════════════════════════════════════

    /**
     * Нормализует название товара (очистка, капитализация).
     */
    public function normalizeProductName(string $rawName, ?string $manufacturer = null): array
    {
        $prompt = <<<PROMPT
Нормализуй название товара для сна. Убери мусор, исправь порядок слов, капитализацию.

Сырое название: {$rawName}
Производитель: {$manufacturer}

Ответь в JSON:
{
  "normalized_name": "чистое название",
  "brand": "бренд если есть",
  "model": "модель если есть",
  "product_type": "тип товара (матрас, подушка, кровать...)"
}
PROMPT;

        $result = $this->chat($prompt, 0.1);
        return $this->parseJsonResponse($result);
    }

    // ═══════════════════════════════════════════
    // НОРМАЛИЗАЦИЯ БРЕНДА
    // ═══════════════════════════════════════════

    /**
     * Массовый анализ неизвестных брендов.
     *
     * @param string[] $unknownBrands Массив грязных названий
     * @param string[] $knownBrands Массив известных канонических имён
     * @return array Маппинг [{raw, canonical_name, action, confidence}, ...]
     */
    public function analyzeBrands(array $unknownBrands, array $knownBrands): array
    {
        $brandsList = implode("\n", array_map(fn($b) => "  - {$b}", $unknownBrands));
        $existingList = implode(", ", array_slice($knownBrands, 0, 50));

        $prompt = <<<PROMPT
Ты — эксперт по товарам для сна (матрасы, подушки, кровати).
Проанализируй бренды из прайса поставщика и определи маппинг.

Неизвестные бренды из прайса:
{$brandsList}

Уже известные бренды в системе:
{$existingList}

Для каждого бренда определи:
1. Это существующий бренд с другим написанием? → action: "alias", укажи canonical_name
2. Это новый бренд? → action: "create", предложи canonical_name
3. Это мусор / не бренд? → action: "skip"

Ответь СТРОГО в JSON:
{
  "mappings": [
    {"raw": "ОРМАТЭК", "canonical_name": "Орматек", "action": "alias", "confidence": 0.99},
    {"raw": "NewBrand", "canonical_name": "NewBrand", "action": "create", "confidence": 0.95},
    {"raw": "---", "canonical_name": null, "action": "skip", "confidence": 0.99}
  ]
}
PROMPT;

        $result = $this->chat($prompt, 0.1, 3000);
        return $this->parseJsonResponse($result);
    }

    // ═══════════════════════════════════════════
    // INTERNAL
    // ═══════════════════════════════════════════

    /**
     * Отправить запрос к LLM через OpenRouter.
     */
    public function chat(string $prompt, float $temperature = 0.3, int $maxTokens = 2000): string
    {
        if (!$this->isAvailable()) {
            Yii::warning('AI: OpenRouter API key не настроен', 'ai');
            return '{}';
        }

        try {
            $startTime = microtime(true);

            $response = $this->httpClient->post('chat/completions', [
                'json' => [
                    'model' => $this->model,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => implode("\n", [
                                'Ты — AI-ассистент агрегатора товаров для сна (матрасы, подушки, одеяла, кровати, основания, наматрасники).',
                                'КРИТИЧЕСКИЕ ПРАВИЛА:',
                                '1. Отвечай ТОЛЬКО валидным JSON. Без markdown, без ```json```, без пояснений.',
                                '2. Размеры (width, length, height) — ВСЕГДА отдельные числа в сантиметрах. НИКОГДА строкой "160x200".',
                                '3. Для enum-полей используй ТОЛЬКО допустимые значения из промпта.',
                                '4. Если значение неизвестно — ставь null, НЕ выдумывай.',
                                '5. Все строки — на русском, если не указано иное.',
                            ]),
                        ],
                        [
                            'role' => 'user',
                            'content' => $prompt,
                        ],
                    ],
                    'temperature' => $temperature,
                    'max_tokens' => $maxTokens,
                    'response_format' => ['type' => 'json_object'],
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            $content = $body['choices'][0]['message']['content'] ?? '{}';
            $durationMs = (int)((microtime(true) - $startTime) * 1000);

            // Логируем использование
            $usage = $body['usage'] ?? [];
            Yii::info("AI запрос выполнен: model={$this->model} tokens=" . ($usage['total_tokens'] ?? 0) . " time={$durationMs}ms", 'ai');

            // Пишем в ai_logs если таблица есть
            $this->logAIUsage('chat', $usage, $durationMs, mb_substr($prompt, 0, 200), mb_substr($content, 0, 500));

            return $content;

        } catch (RequestException $e) {
            Yii::error('AI: ошибка запроса — ' . $e->getMessage(), 'ai');
            return '{}';
        } catch (\Throwable $e) {
            Yii::error('AI: неизвестная ошибка — ' . $e->getMessage(), 'ai');
            return '{}';
        }
    }

    /**
     * Парсинг JSON из ответа AI (с защитой от мусора).
     */
    public function parseJsonResponse(string $response): array
    {
        // Убрать markdown-обёртку
        $response = preg_replace('/^```(?:json)?\s*/m', '', $response);
        $response = preg_replace('/```\s*$/m', '', $response);
        $response = trim($response);

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Yii::warning('AI: невалидный JSON — ' . json_last_error_msg() . ' response: ' . mb_substr($response, 0, 300), 'ai');
            return [];
        }

        return $decoded;
    }

    /**
     * Запись в таблицу ai_logs.
     */
    protected function logAIUsage(string $operation, array $usage, int $durationMs, string $inputSummary, string $outputSummary): void
    {
        try {
            Yii::$app->db->createCommand()->insert('{{%ai_logs}}', [
                'operation' => $operation,
                'model' => $this->model,
                'prompt_tokens' => $usage['prompt_tokens'] ?? 0,
                'completion_tokens' => $usage['completion_tokens'] ?? 0,
                'total_tokens' => $usage['total_tokens'] ?? 0,
                'estimated_cost_usd' => $this->estimateCost($usage),
                'duration_ms' => $durationMs,
                'input_summary' => $inputSummary,
                'output_summary' => $outputSummary,
                'success' => true,
            ])->execute();
        } catch (\Throwable $e) {
            // Не фатально, если ai_logs ещё не создана
        }
    }

    /**
     * Оценка стоимости запроса (DeepSeek ~ $0.27/1M input, $1.10/1M output).
     */
    protected function estimateCost(array $usage): float
    {
        $promptTokens = $usage['prompt_tokens'] ?? 0;
        $completionTokens = $usage['completion_tokens'] ?? 0;
        return ($promptTokens * 0.00000027) + ($completionTokens * 0.0000011);
    }
}
