<?php

namespace common\services;

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

        $this->httpClient = new HttpClient([
            'base_uri' => $this->baseUrl,
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
        return !empty($this->apiKey) && $this->apiKey !== 'your-openrouter-api-key';
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
     * Определяет категорию товара.
     *
     * @param string $productName Название
     * @param string $description Описание
     * @param array $availableCategories [id => 'Матрасы > Пружинные', ...]
     * @return array{category_id: int, confidence: float}
     */
    public function categorize(string $productName, string $description, array $availableCategories): array
    {
        $categoriesList = '';
        foreach ($availableCategories as $id => $path) {
            $categoriesList .= "\n  [{$id}] {$path}";
        }

        $prompt = <<<PROMPT
Определи категорию товара из списка.

Товар: {$productName}
Описание: {$description}

Доступные категории:
{$categoriesList}

Ответь СТРОГО в JSON:
{
  "category_id": число,
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
     * Извлекает структурированные атрибуты из описания.
     */
    public function extractAttributes(string $productName, string $description): array
    {
        $prompt = <<<PROMPT
Извлеки атрибуты товара для сна из названия и описания.

Название: {$productName}
Описание: {$description}

Извлеки в JSON:
{
  "height_cm": число или null,
  "width_cm": число или null,
  "length_cm": число или null,
  "stiffness": "Мягкий" / "Средний" / "Жёсткий" / "Умеренно мягкий" / "Умеренно жёсткий" или null,
  "spring_type": "Независимый" / "Зависимый" / "Без пружин" или null,
  "max_load_kg": число или null,
  "materials": ["материал1", "материал2"],
  "is_orthopedic": true/false/null,
  "is_two_sided": true/false/null,
  "warranty_years": число или null
}
PROMPT;

        $result = $this->chat($prompt, 0.1);
        return $this->parseJsonResponse($result);
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
        }

        $brandsList = implode(', ', array_slice($uniqueBrands, 0, 30));
        $categoriesList = implode(', ', array_slice($uniqueCategories, 0, 30));
        $existingBrandsList = implode(', ', array_slice($existingBrands, 0, 50));
        $existingCatList = '';
        foreach (array_slice($existingCategories, 0, 30) as $id => $name) {
            $existingCatList .= "\n  [{$id}] {$name}";
        }

        $prompt = <<<PROMPT
Ты — эксперт по товарам для сна (матрасы, подушки, одеяла, кровати, наматрасники, основания).
Проанализируй выборку из прайс-листа поставщика и создай РЕЦЕПТ для автоматической нормализации ВСЕХ товаров.

═══ ВЫБОРКА ТОВАРОВ ({$i} шт.) ═══
{$sampleLines}

═══ ВСЕ БРЕНДЫ В ПРАЙСЕ ═══
{$brandsList}

═══ ВСЕ КАТЕГОРИИ В ПРАЙСЕ ═══
{$categoriesList}

═══ ИЗВЕСТНЫЕ БРЕНДЫ В СИСТЕМЕ ═══
{$existingBrandsList}

═══ СУЩЕСТВУЮЩИЕ КАТЕГОРИИ В СИСТЕМЕ ═══
{$existingCatList}

═══ ЗАДАЧА ═══
Создай JSON-рецепт для автоматической обработки ВСЕГО прайса:

1. **brand_mapping** — Маппинг брендов из прайса в канонические названия.
   Для каждого бренда определи: это существующий (alias), новый (create) или мусор (skip).

2. **category_mapping** — Маппинг категорий из прайса в существующие категории системы.
   Если точного совпадения нет — предложи наиболее близкую или новую.

3. **name_rules** — Правила нормализации названий:
   - Нужно ли убирать бренд из начала?
   - Формат: "Бренд Модель" или "Модель (Бренд)"?
   - Шаблон canonical_name

4. **product_type_rules** — Правила определения типа товара из названия/категории.

5. **quality_indicators** — Какие признаки указывают на качественную карточку.

Ответь СТРОГО в JSON:
{
  "brand_mapping": {
    "ОРМАТЭК": {"canonical": "Орматек", "action": "alias"},
    "New Brand": {"canonical": "New Brand", "action": "create"}
  },
  "category_mapping": {
    "Матрасы пружинные": {"target_id": 1, "target_name": "Матрасы"},
    "Подушки ортопедические": {"target_id": null, "target_name": "Подушки", "action": "create"}
  },
  "name_template": "{brand} {model}",
  "name_rules": {
    "remove_brand_prefix": true,
    "capitalize": true,
    "trim_whitespace": true
  },
  "product_type_rules": [
    {"pattern": "матрас", "type": "mattress"},
    {"pattern": "подушка", "type": "pillow"},
    {"pattern": "одеяло", "type": "blanket"},
    {"pattern": "кровать", "type": "bed"},
    {"pattern": "наматрасник", "type": "protector"},
    {"pattern": "основание", "type": "base"}
  ],
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
     * Генерация описания товара.
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

            $response = $this->httpClient->post('/chat/completions', [
                'json' => [
                    'model' => $this->model,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'Ты — AI-ассистент агрегатора товаров для сна. Отвечай ТОЛЬКО валидным JSON без markdown-обёртки.',
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
