<?php

namespace common\services;

use yii\base\Component;
use yii\db\Connection;
use Yii;

/**
 * Сервис сопоставления товаров от поставщиков с эталонным каталогом.
 *
 * Многоуровневая стратегия (от быстрой к дорогой):
 * 1. Точный SKU/артикул
 * 2. Производитель + Модель
 * 3. Fuzzy (триграммы PostgreSQL)
 * 4. AI-сопоставление (DeepSeek)
 *
 * Пороги:
 * - confidence >= 0.95 → auto-match
 * - 0.60 <= confidence < 0.95 → review queue
 * - confidence < 0.60 → создаём новую карточку
 */
class ProductMatcher extends Component
{
    public float $autoMatchThreshold = 0.95;
    public float $reviewThreshold = 0.60;

    /** @var AIService */
    public ?AIService $aiService = null;

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
     * Найти существующую карточку для товара.
     *
     * @param array $product [name, manufacturer, model, category, sku, attributes]
     * @param int $supplierId
     * @return array{card_id: ?int, confidence: float, method: string, needs_review: bool}
     */
    public function match(array $product, int $supplierId): array
    {
        // 1. Точный SKU
        $result = $this->matchBySku($product['sku'] ?? '', $supplierId);
        if ($result) return $result;

        // 2. Производитель + Модель
        $result = $this->matchByManufacturerModel(
            $product['manufacturer'] ?? '',
            $product['model'] ?? $product['name']
        );
        if ($result) return $result;

        // 3. Fuzzy (триграммы)
        $result = $this->matchByFuzzy(
            $product['name'],
            $product['manufacturer'] ?? ''
        );
        if ($result) return $result;

        // 4. AI
        if ($this->aiService->isAvailable()) {
            $result = $this->matchByAI($product);
            if ($result) return $result;
        }

        // Не найдено
        return [
            'card_id' => null,
            'confidence' => 0,
            'method' => 'none',
            'needs_review' => false,
        ];
    }

    /**
     * 1. Точный SKU: ищем existing offer с таким же supplier_sku.
     */
    protected function matchBySku(string $sku, int $supplierId): ?array
    {
        if (empty($sku)) return null;

        $cardId = $this->db->createCommand("
            SELECT card_id FROM {{%supplier_offers}} 
            WHERE supplier_id = :sid AND supplier_sku = :sku AND is_active = true
            LIMIT 1
        ", [':sid' => $supplierId, ':sku' => $sku])->queryScalar();

        if ($cardId) {
            return [
                'card_id' => (int)$cardId,
                'confidence' => 1.0,
                'method' => 'exact_sku',
                'needs_review' => false,
            ];
        }

        return null;
    }

    /**
     * 2. Производитель + Модель.
     */
    protected function matchByManufacturerModel(string $manufacturer, string $model): ?array
    {
        if (empty($manufacturer) || empty($model)) return null;

        $row = $this->db->createCommand("
            SELECT id FROM {{%product_cards}} 
            WHERE LOWER(manufacturer) = LOWER(:m) AND LOWER(model) = LOWER(:model)
            AND status = 'active'
            LIMIT 1
        ", [':m' => $manufacturer, ':model' => $model])->queryScalar();

        if ($row) {
            return [
                'card_id' => (int)$row,
                'confidence' => 0.98,
                'method' => 'manufacturer_model',
                'needs_review' => false,
            ];
        }

        return null;
    }

    /**
     * 3. Fuzzy (триграммы) — PostgreSQL pg_trgm.
     */
    protected function matchByFuzzy(string $name, string $manufacturer): ?array
    {
        if (empty($name)) return null;

        $searchTerm = trim("{$manufacturer} {$name}");

        $row = $this->db->createCommand("
            SELECT id, canonical_name, manufacturer,
                   similarity(canonical_name, :name) AS sim
            FROM {{%product_cards}}
            WHERE status = 'active'
              AND similarity(canonical_name, :name) > 0.3
            ORDER BY sim DESC
            LIMIT 1
        ", [':name' => $searchTerm])->queryOne();

        if ($row) {
            $confidence = (float)$row['sim'];
            $needsReview = $confidence < $this->autoMatchThreshold;

            if ($confidence >= $this->reviewThreshold) {
                return [
                    'card_id' => (int)$row['id'],
                    'confidence' => round($confidence, 3),
                    'method' => 'fuzzy_trigram',
                    'needs_review' => $needsReview,
                ];
            }
        }

        return null;
    }

    /**
     * 4. AI-сопоставление.
     */
    protected function matchByAI(array $product): ?array
    {
        // Ищем кандидатов по категории/производителю
        $candidates = $this->findCandidates($product);
        if (empty($candidates)) return null;

        $result = $this->aiService->findBestMatch($product, $candidates);

        if (!empty($result['match_id']) && $result['confidence'] >= $this->reviewThreshold) {
            $needsReview = $result['confidence'] < $this->autoMatchThreshold;

            // Логируем в match_reviews если нужна ревизия
            if ($needsReview) {
                $this->createReview(null, $result['match_id'], $result['confidence'], $result['reason'] ?? '');
            }

            return [
                'card_id' => (int)$result['match_id'],
                'confidence' => round($result['confidence'], 3),
                'method' => 'ai_match',
                'needs_review' => $needsReview,
            ];
        }

        return null;
    }

    /**
     * Найти кандидатов для AI-матчинга.
     */
    protected function findCandidates(array $product): array
    {
        $manufacturer = $product['manufacturer'] ?? '';
        $name = $product['name'] ?? '';

        $query = "
            SELECT id, canonical_name AS name, manufacturer, model
            FROM {{%product_cards}}
            WHERE status = 'active'
        ";
        $params = [];

        if (!empty($manufacturer)) {
            $query .= " AND (LOWER(manufacturer) = LOWER(:m) OR similarity(manufacturer, :m) > 0.4)";
            $params[':m'] = $manufacturer;
        }

        $query .= " ORDER BY similarity(canonical_name, :name) DESC LIMIT 20";
        $params[':name'] = $name;

        return $this->db->createCommand($query, $params)->queryAll();
    }

    /**
     * Создать запись для ручной ревизии.
     */
    protected function createReview(?int $offerId, ?int $suggestedCardId, float $confidence, string $reason): void
    {
        if ($offerId === null) return;

        try {
            $this->db->createCommand()->insert('{{%match_reviews}}', [
                'offer_id' => $offerId,
                'suggested_card_id' => $suggestedCardId,
                'ai_confidence' => $confidence,
                'ai_reason' => $reason,
                'status' => 'pending',
            ])->execute();
        } catch (\Throwable $e) {
            Yii::warning("Не удалось создать review: {$e->getMessage()}", 'matcher');
        }
    }
}
