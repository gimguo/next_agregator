<?php

namespace common\services;

use yii\base\Component;
use yii\db\Connection;
use Yii;

/**
 * Сервис управления брендами и их алиасами.
 *
 * Задача: любое «грязное» написание бренда от поставщика
 * мгновенно привести к эталонному виду через таблицу замен.
 *
 * Поток:
 * 1. Поставщик присылает "ОРМАТЭК" / "Ormatek" / "ОрмаТек"
 * 2. BrandService::resolve("ОРМАТЭК") → brand_id=1, canonical="Орматек"
 * 3. Если алиас не найден:
 *    a) Fuzzy-поиск (Levenshtein)
 *    b) AI анализ + предложение маппинга
 *    c) Создание нового бренда или алиаса
 *
 * Кэш: алиасы загружаются при старте, lookup — O(1).
 */
class BrandService extends Component
{
    /** @var AIService */
    public ?AIService $aiService = null;

    /** @var array<string, array{brand_id: int, canonical_name: string}> Кэш алиасов */
    private array $aliasCache = [];

    private bool $cacheLoaded = false;
    private Connection $db;

    public function init(): void
    {
        parent::init();
        $this->db = Yii::$app->db;

        if ($this->aiService === null) {
            $this->aiService = Yii::$app->has('aiService')
                ? Yii::$app->get('aiService')
                : new AIService();
        }
    }

    /**
     * Разрешить грязное название бренда → эталонный бренд.
     *
     * @param string $rawBrand Грязное: "ОРМАТЭК", "Ormatek"
     * @return array{brand_id: int, canonical_name: string}|null
     */
    public function resolve(string $rawBrand): ?array
    {
        $rawBrand = trim($rawBrand);
        if (empty($rawBrand)) return null;

        $lower = mb_strtolower($rawBrand, 'UTF-8');

        // 1. Поиск в кэше алиасов
        $this->ensureCacheLoaded();
        if (isset($this->aliasCache[$lower])) {
            return $this->aliasCache[$lower];
        }

        // 2. Точный поиск по canonical_name
        $brand = $this->db->createCommand(
            "SELECT id, canonical_name FROM {{%brands}} WHERE LOWER(canonical_name) = :lower LIMIT 1",
            [':lower' => $lower]
        )->queryOne();

        if ($brand) {
            $result = ['brand_id' => (int)$brand['id'], 'canonical_name' => $brand['canonical_name']];
            $this->aliasCache[$lower] = $result;
            return $result;
        }

        // 3. Fuzzy-поиск (Levenshtein)
        $fuzzyMatch = $this->fuzzyResolve($lower);
        if ($fuzzyMatch !== null) {
            $this->createAlias($fuzzyMatch['brand_id'], $rawBrand, 'fuzzy', null, 0.9);
            return $fuzzyMatch;
        }

        // 4. AI: определить бренд
        if ($this->aiService->isAvailable()) {
            $aiResult = $this->resolveWithAI($rawBrand);
            if ($aiResult !== null) {
                return $aiResult;
            }
        }

        Yii::warning("Бренд не найден: {$rawBrand}", 'brand');
        return null;
    }

    /**
     * Разрешить бренд или создать новый.
     */
    public function resolveOrCreate(string $rawBrand, ?string $supplierCode = null): array
    {
        $resolved = $this->resolve($rawBrand);
        if ($resolved !== null) {
            return $resolved;
        }

        // Создать новый бренд
        $brandId = $this->createBrand($rawBrand);
        $this->createAlias($brandId, $rawBrand, 'import', $supplierCode, 1.0);

        $result = [
            'brand_id' => $brandId,
            'canonical_name' => $rawBrand,
        ];

        Yii::info("Создан новый бренд: {$rawBrand} (id={$brandId})", 'brand');
        return $result;
    }

    /**
     * Массовый анализ брендов из прайса (первый импорт).
     *
     * @param string[] $rawBrands Массив грязных названий
     * @param string $supplierCode Код поставщика
     * @return array Результат маппинга
     */
    public function bulkAnalyze(array $rawBrands, string $supplierCode): array
    {
        $rawBrands = array_unique(array_filter(array_map('trim', $rawBrands)));
        if (empty($rawBrands)) return [];

        Yii::info("Массовый анализ брендов: count=" . count($rawBrands) . " supplier={$supplierCode}", 'brand');

        // Разделяем: известные vs неизвестные
        $resolved = [];
        $unknown = [];

        foreach ($rawBrands as $raw) {
            $match = $this->resolve($raw);
            if ($match !== null) {
                $resolved[$raw] = $match;
            } else {
                $unknown[] = $raw;
            }
        }

        Yii::info("Бренды: resolved=" . count($resolved) . " unknown=" . count($unknown), 'brand');

        // AI анализирует неизвестные бренды
        if (!empty($unknown) && $this->aiService->isAvailable()) {
            $aiMappings = $this->aiAnalyzeBrands($unknown, $supplierCode);
            $resolved = array_merge($resolved, $aiMappings);
        }

        return [
            'total' => count($rawBrands),
            'resolved' => count($resolved),
            'unknown' => count($unknown) - count(array_intersect_key($resolved, array_flip($unknown))),
            'mappings' => $resolved,
        ];
    }

    /**
     * AI анализирует неизвестные бренды.
     */
    protected function aiAnalyzeBrands(array $unknownBrands, string $supplierCode): array
    {
        $this->ensureCacheLoaded();
        $existingBrands = array_unique(array_column($this->aliasCache, 'canonical_name'));

        $response = $this->aiService->analyzeBrands($unknownBrands, $existingBrands);
        $result = [];

        foreach ($response['mappings'] ?? [] as $mapping) {
            $raw = $mapping['raw'] ?? '';
            $action = $mapping['action'] ?? 'skip';
            $canonical = $mapping['canonical_name'] ?? null;
            $confidence = $mapping['confidence'] ?? 0.5;

            if ($action === 'skip' || $canonical === null) continue;

            if ($action === 'alias') {
                $existing = $this->resolve($canonical);
                if ($existing) {
                    $this->createAlias($existing['brand_id'], $raw, 'ai', $supplierCode, $confidence);
                    $result[$raw] = $existing;
                }
            } elseif ($action === 'create') {
                $brandId = $this->createBrand($canonical);
                $this->createAlias($brandId, $raw, 'ai', $supplierCode, $confidence);
                $this->createAlias($brandId, $canonical, 'ai', $supplierCode, $confidence);
                $result[$raw] = ['brand_id' => $brandId, 'canonical_name' => $canonical];
            }
        }

        return $result;
    }

    /**
     * Нечёткий поиск бренда по Levenshtein.
     */
    protected function fuzzyResolve(string $lower): ?array
    {
        $this->ensureCacheLoaded();
        $bestMatch = null;
        $bestDistance = PHP_INT_MAX;
        $threshold = max(2, (int)(mb_strlen($lower) * 0.2));

        foreach ($this->aliasCache as $alias => $brand) {
            $distance = levenshtein(
                mb_substr($lower, 0, 50),
                mb_substr($alias, 0, 50)
            );
            if ($distance < $bestDistance && $distance <= $threshold) {
                $bestDistance = $distance;
                $bestMatch = $brand;
            }
        }

        return $bestMatch;
    }

    /**
     * AI: разрешить один бренд.
     */
    protected function resolveWithAI(string $rawBrand): ?array
    {
        $normalized = $this->aiService->normalizeProductName($rawBrand);
        if (!empty($normalized['brand'])) {
            $existing = $this->resolve($normalized['brand']);
            if ($existing) {
                $this->createAlias($existing['brand_id'], $rawBrand, 'ai', null, 0.85);
                return $existing;
            }
        }
        return null;
    }

    // ═══════════════════════════════════════════
    // DB Operations
    // ═══════════════════════════════════════════

    protected function createBrand(string $canonicalName): int
    {
        $slug = $this->slugify($canonicalName);

        $id = $this->db->createCommand("
            INSERT INTO {{%brands}} (canonical_name, slug, is_active)
            VALUES (:name, :slug, true)
            ON CONFLICT (slug) DO UPDATE SET canonical_name = EXCLUDED.canonical_name
            RETURNING id
        ", [':name' => $canonicalName, ':slug' => $slug])->queryScalar();

        return (int)$id;
    }

    public function createAlias(int $brandId, string $alias, string $source, ?string $supplierCode, float $confidence): void
    {
        $lower = mb_strtolower(trim($alias), 'UTF-8');

        try {
            $this->db->createCommand("
                INSERT INTO {{%brand_aliases}} (brand_id, alias, alias_lower, source, supplier_code, confidence)
                VALUES (:brand_id, :alias, :lower, :source, :supplier, :conf)
                ON CONFLICT (alias_lower) DO NOTHING
            ", [
                ':brand_id' => $brandId,
                ':alias' => $alias,
                ':lower' => $lower,
                ':source' => $source,
                ':supplier' => $supplierCode,
                ':conf' => $confidence,
            ])->execute();
        } catch (\Throwable $e) {
            Yii::warning("Не удалось создать алиас бренда: {$alias} — {$e->getMessage()}", 'brand');
        }

        // Получаем canonical_name
        $canonical = $this->db->createCommand(
            "SELECT canonical_name FROM {{%brands}} WHERE id = :id",
            [':id' => $brandId]
        )->queryScalar();

        $this->aliasCache[$lower] = [
            'brand_id' => $brandId,
            'canonical_name' => $canonical ?: $alias,
        ];
    }

    protected function ensureCacheLoaded(): void
    {
        if ($this->cacheLoaded) return;

        try {
            $rows = $this->db->createCommand("
                SELECT ba.alias_lower, b.id AS brand_id, b.canonical_name
                FROM {{%brand_aliases}} ba
                JOIN {{%brands}} b ON b.id = ba.brand_id
                WHERE b.is_active = true
            ")->queryAll();

            foreach ($rows as $row) {
                $this->aliasCache[$row['alias_lower']] = [
                    'brand_id' => (int)$row['brand_id'],
                    'canonical_name' => $row['canonical_name'],
                ];
            }
        } catch (\Throwable $e) {
            Yii::warning("Не удалось загрузить кэш брендов: {$e->getMessage()}", 'brand');
        }

        $this->cacheLoaded = true;
    }

    protected function slugify(string $text): string
    {
        $translitMap = [
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd',
            'е' => 'e', 'ё' => 'yo', 'ж' => 'zh', 'з' => 'z', 'и' => 'i',
            'й' => 'j', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
            'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
            'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'ch',
            'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '',
            'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        ];

        $text = mb_strtolower($text, 'UTF-8');
        $text = strtr($text, $translitMap);
        $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
        $text = preg_replace('/[\s-]+/', '-', $text);
        return trim($text, '-') ?: 'brand-' . uniqid();
    }
}
