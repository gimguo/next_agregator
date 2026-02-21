<?php

namespace common\services;

use common\dto\ReadinessReportDTO;
use common\models\ChannelRequirement;
use common\models\ModelChannelReadiness;
use common\models\SalesChannel;
use yii\base\Component;
use Yii;

/**
 * Сервис скоринга готовности карточки товара для канала продаж.
 *
 * Алгоритм evaluate():
 *   1. Определить ProductFamily модели
 *   2. Найти ChannelRequirement для этого канала + семейства (или wildcard '*')
 *   3. Проверить каждое требование:
 *      - Обязательные атрибуты (required_attributes) → required:attr:XXX
 *      - Рекомендуемые атрибуты (recommended_attributes) → recommended:attr:XXX
 *      - Фото (require_image, min_images) → required:image
 *      - Штрихкод (require_barcode) → required:barcode
 *      - Описание (require_description, min_description_length) → required:description
 *      - Бренд (require_brand) → required:brand
 *      - Цена (require_price) → required:price
 *   4. Рассчитать score (0-100):
 *      - Каждое обязательное поле = равная доля от 80%
 *      - Каждое рекомендуемое поле = равная доля от 20%
 *      - is_ready = нет ни одного required:* пропуска
 *
 * Использование:
 *   $service = Yii::$app->get('readinessService');
 *   $report = $service->evaluate($modelId, $channel);
 *   if (!$report->isReady) {
 *       echo "Не хватает: " . implode(', ', $report->getMissingLabels());
 *   }
 */
class ReadinessScoringService extends Component
{
    /** @var array Кэш требований: channelId => [family => ChannelRequirement] */
    private array $requirementsCache = [];

    // ═══════════════════════════════════════════
    // PUBLIC API
    // ═══════════════════════════════════════════

    /**
     * Оценить готовность модели для канала.
     *
     * @param int          $modelId   ID product_model
     * @param SalesChannel $channel   Канал продаж
     * @param bool         $persist   Сохранить результат в кэш model_channel_readiness
     * @return ReadinessReportDTO
     */
    public function evaluate(int $modelId, SalesChannel $channel, bool $persist = false): ReadinessReportDTO
    {
        $db = Yii::$app->db;

        // Получаем данные модели
        $model = $db->createCommand("
            SELECT
                pm.id,
                pm.name,
                pm.product_family,
                pm.brand_id,
                pm.description,
                pm.short_description,
                pm.canonical_attributes,
                pm.canonical_images,
                pm.best_price
            FROM {{%product_models}} pm
            WHERE pm.id = :id
        ", [':id' => $modelId])->queryOne();

        if (!$model) {
            return new ReadinessReportDTO(false, 0, ['required:model_not_found'], ['error' => 'Model not found']);
        }

        // Парсим атрибуты
        $attrs = [];
        if (!empty($model['canonical_attributes'])) {
            $attrs = is_string($model['canonical_attributes'])
                ? (json_decode($model['canonical_attributes'], true) ?: [])
                : (is_array($model['canonical_attributes']) ? $model['canonical_attributes'] : []);
        }

        // Парсим изображения
        $images = [];
        if (!empty($model['canonical_images'])) {
            $images = is_string($model['canonical_images'])
                ? (json_decode($model['canonical_images'], true) ?: [])
                : (is_array($model['canonical_images']) ? $model['canonical_images'] : []);
        }

        // Проверяем media_assets (более надёжно, чем canonical_images)
        $mediaCount = (int)$db->createCommand("
            SELECT COUNT(*) FROM {{%media_assets}}
            WHERE entity_type = 'model' AND entity_id = :id AND status != 'failed'
        ", [':id' => $modelId])->queryScalar();

        $imageCount = max(count($images), $mediaCount);

        // Проверяем наличие GTIN в вариантах
        $hasBarcode = (bool)$db->createCommand("
            SELECT 1 FROM {{%reference_variants}} rv
            WHERE rv.model_id = :id AND rv.gtin IS NOT NULL AND rv.gtin != ''
            LIMIT 1
        ", [':id' => $modelId])->queryScalar();

        // Получаем требования
        $family = $model['product_family'] ?? 'unknown';
        $requirement = $this->getRequirement($channel->id, $family);

        if (!$requirement) {
            // Нет требований → считаем готовым (нет ограничений)
            return new ReadinessReportDTO(true, 100, []);
        }

        // ═══ СКОРИНГ ═══
        $missing = [];
        $details = [];
        $requiredChecks = 0;
        $requiredPassed = 0;
        $recommendedChecks = 0;
        $recommendedPassed = 0;

        // --- Фото ---
        if ($requirement->require_image) {
            $requiredChecks++;
            if ($imageCount >= $requirement->min_images) {
                $requiredPassed++;
                $details['image'] = ['status' => 'ok', 'count' => $imageCount, 'required' => $requirement->min_images];
            } else {
                $missing[] = 'required:image';
                $details['image'] = ['status' => 'missing', 'count' => $imageCount, 'required' => $requirement->min_images];
            }
        }

        // --- Описание ---
        if ($requirement->require_description) {
            $requiredChecks++;
            $descLen = mb_strlen($model['description'] ?? '');
            $shortDescLen = mb_strlen($model['short_description'] ?? '');
            $totalDescLen = max($descLen, $shortDescLen);

            if ($totalDescLen >= $requirement->min_description_length) {
                $requiredPassed++;
                $details['description'] = ['status' => 'ok', 'length' => $totalDescLen, 'required' => $requirement->min_description_length];
            } else {
                $missing[] = 'required:description';
                $details['description'] = ['status' => 'missing', 'length' => $totalDescLen, 'required' => $requirement->min_description_length];
            }
        }

        // --- Штрихкод ---
        if ($requirement->require_barcode) {
            $requiredChecks++;
            if ($hasBarcode) {
                $requiredPassed++;
                $details['barcode'] = ['status' => 'ok'];
            } else {
                $missing[] = 'required:barcode';
                $details['barcode'] = ['status' => 'missing'];
            }
        }

        // --- Бренд ---
        if ($requirement->require_brand) {
            $requiredChecks++;
            if (!empty($model['brand_id'])) {
                $requiredPassed++;
                $details['brand'] = ['status' => 'ok', 'brand_id' => (int)$model['brand_id']];
            } else {
                $missing[] = 'required:brand';
                $details['brand'] = ['status' => 'missing'];
            }
        }

        // --- Цена ---
        if ($requirement->require_price) {
            $requiredChecks++;
            if (!empty($model['best_price']) && (float)$model['best_price'] > 0) {
                $requiredPassed++;
                $details['price'] = ['status' => 'ok', 'price' => (float)$model['best_price']];
            } else {
                $missing[] = 'required:price';
                $details['price'] = ['status' => 'missing'];
            }
        }

        // --- Обязательные атрибуты ---
        $reqAttrs = $requirement->getRequiredAttrsList();
        foreach ($reqAttrs as $attrKey) {
            $requiredChecks++;
            if ($this->attrPresent($attrs, $attrKey)) {
                $requiredPassed++;
            } else {
                $missing[] = 'required:attr:' . $attrKey;
            }
        }

        // --- Рекомендуемые атрибуты ---
        $recAttrs = $requirement->getRecommendedAttrsList();
        foreach ($recAttrs as $attrKey) {
            $recommendedChecks++;
            if ($this->attrPresent($attrs, $attrKey)) {
                $recommendedPassed++;
            } else {
                $missing[] = 'recommended:attr:' . $attrKey;
            }
        }

        // ═══ РАСЧЁТ SCORE ═══
        // 80% — обязательные, 20% — рекомендуемые
        $requiredWeight = 80;
        $recommendedWeight = 20;

        $requiredScore = $requiredChecks > 0
            ? (int)round(($requiredPassed / $requiredChecks) * $requiredWeight)
            : $requiredWeight; // нет проверок = 100% обязательных

        $recommendedScore = $recommendedChecks > 0
            ? (int)round(($recommendedPassed / $recommendedChecks) * $recommendedWeight)
            : $recommendedWeight;

        $totalScore = min(100, $requiredScore + $recommendedScore);

        // is_ready = все обязательные пройдены
        $isReady = ($requiredPassed === $requiredChecks);

        $report = new ReadinessReportDTO($isReady, $totalScore, $missing, $details);

        // Сохраняем в кэш
        if ($persist) {
            ModelChannelReadiness::upsert(
                $modelId,
                $channel->id,
                $isReady,
                $totalScore,
                $missing
            );
        }

        return $report;
    }

    /**
     * Массовая оценка: все модели для одного канала.
     * Возвращает сводку.
     *
     * @return array{total: int, ready: int, not_ready: int, avg_score: float, top_missing: array}
     */
    public function evaluateAll(SalesChannel $channel, callable $progressCallback = null): array
    {
        $db = Yii::$app->db;

        $totalModels = (int)$db->createCommand(
            "SELECT COUNT(*) FROM {{%product_models}} WHERE status = 'active'"
        )->queryScalar();

        $ready = 0;
        $notReady = 0;
        $totalScore = 0;
        $missingStats = [];
        $processed = 0;

        // Батчами по 200
        $batchSize = 200;
        $offset = 0;

        while ($offset < $totalModels) {
            $modelIds = $db->createCommand("
                SELECT id FROM {{%product_models}}
                WHERE status = 'active'
                ORDER BY id
                LIMIT :limit OFFSET :offset
            ", [':limit' => $batchSize, ':offset' => $offset])->queryColumn();

            if (empty($modelIds)) {
                break;
            }

            foreach ($modelIds as $modelId) {
                $report = $this->evaluate((int)$modelId, $channel, true);

                if ($report->isReady) {
                    $ready++;
                } else {
                    $notReady++;
                }

                $totalScore += $report->score;
                $processed++;

                // Статистика пропусков
                foreach ($report->missing as $field) {
                    if (!isset($missingStats[$field])) {
                        $missingStats[$field] = 0;
                    }
                    $missingStats[$field]++;
                }

                if ($progressCallback) {
                    $progressCallback($processed, $totalModels);
                }
            }

            $offset += $batchSize;
        }

        // Топ пропусков
        arsort($missingStats);

        return [
            'total'       => $processed,
            'ready'       => $ready,
            'not_ready'   => $notReady,
            'avg_score'   => $processed > 0 ? round($totalScore / $processed, 1) : 0,
            'top_missing' => $missingStats,
        ];
    }

    /**
     * Быстрая проверка: готова ли модель для канала (из кэша или live).
     */
    public function isReady(int $modelId, int $channelId): bool
    {
        // Сначала проверяем кэш
        $cached = Yii::$app->db->createCommand("
            SELECT is_ready FROM {{%model_channel_readiness}}
            WHERE model_id = :mid AND channel_id = :cid
        ", [':mid' => $modelId, ':cid' => $channelId])->queryScalar();

        if ($cached !== false) {
            return (bool)$cached;
        }

        // Кэша нет → live оценка
        $channel = SalesChannel::findOne($channelId);
        if (!$channel) {
            return true; // нет канала → нет блокировки
        }

        $report = $this->evaluate($modelId, $channel, true);
        return $report->isReady;
    }

    /**
     * Сбросить кэш требований.
     */
    public function resetCache(): void
    {
        $this->requirementsCache = [];
    }

    // ═══════════════════════════════════════════
    // PRIVATE
    // ═══════════════════════════════════════════

    /**
     * Получить ChannelRequirement для канала + семейства (с кэшированием).
     */
    private function getRequirement(int $channelId, string $family): ?ChannelRequirement
    {
        if (!isset($this->requirementsCache[$channelId])) {
            $this->requirementsCache[$channelId] = ChannelRequirement::findAllForChannel($channelId);
        }

        $map = $this->requirementsCache[$channelId];

        // Сначала конкретное семейство
        if (isset($map[$family])) {
            return $map[$family];
        }

        // Fallback на wildcard
        if (isset($map[ChannelRequirement::FAMILY_WILDCARD])) {
            return $map[ChannelRequirement::FAMILY_WILDCARD];
        }

        return null;
    }

    /**
     * Проверить наличие атрибута (не null, не пустая строка, не пустой массив).
     */
    private function attrPresent(array $attrs, string $key): bool
    {
        if (!isset($attrs[$key])) {
            return false;
        }

        $val = $attrs[$key];

        if ($val === null || $val === '' || $val === []) {
            return false;
        }

        return true;
    }
}
