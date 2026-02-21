<?php

namespace common\services;

use common\dto\HealingResultDTO;
use common\dto\ReadinessReportDTO;
use common\models\ModelChannelReadiness;
use common\models\SalesChannel;
use yii\base\Component;
use Yii;

/**
 * Sprint 13 — AI Auto-Healing Service.
 *
 * Самовосстанавливающийся каталог: ИИ (DeepSeek) анализирует пропущенные поля
 * карточки товара и пытается сгенерировать/определить недостающие данные.
 *
 * === Что лечит ===
 *   - description       → AIService::generateSeoDescription()
 *   - short_description → AIService::generateSeoDescription()
 *   - attributes        → AIService::inferMissingAttributes()
 *
 * === Что НЕ лечит (skip) ===
 *   - image   → ИИ не рисует фото
 *   - barcode → GTIN генерируется физически, не ИИ
 *   - price   → Цены определяются поставщиками
 *   - brand   → Требует ручной привязки
 *
 * === Closed-Loop ===
 *   После лечения:
 *   1. Обновляет ProductModel (description, canonical_attributes)
 *   2. Пересчитывает ReadinessScoringService::evaluate()
 *   3. Если is_ready === true → emitContentUpdate() → товар едет в Outbox
 *   4. Записывает last_heal_attempt_at, чтобы не мучить ИИ повторно
 *
 * Использование:
 *   $healer = Yii::$app->get('autoHealer');
 *   $result = $healer->healModel($modelId, $missingFields, $channel);
 */
class AutoHealingService extends Component
{
    /** @var int Минимальная длина сгенерированного описания для принятия */
    public int $minDescriptionLength = 100;

    /** @var int Кулдаун между попытками лечения одной модели (секунды) */
    public int $healCooldownSeconds = 86400; // 24 часа

    /** @var string[] Поля, которые ИИ НЕ может вылечить */
    private const UNHEALABLE_FIELDS = [
        'required:image',
        'required:barcode',
        'required:price',
        'required:brand',
    ];

    // ═══════════════════════════════════════════
    // PUBLIC API
    // ═══════════════════════════════════════════

    /**
     * Попытаться вылечить карточку товара.
     *
     * @param int          $modelId       ID product_model
     * @param array        $missingFields Массив пропусков из ReadinessReportDTO (e.g., ['required:description', 'required:attr:height'])
     * @param SalesChannel $channel       Канал (для пересчёта readiness)
     * @return HealingResultDTO
     */
    public function healModel(int $modelId, array $missingFields, SalesChannel $channel): HealingResultDTO
    {
        $result = new HealingResultDTO();
        $db = Yii::$app->db;

        // Разделяем поля на лечимые и нелечимые
        $healable = [];
        foreach ($missingFields as $field) {
            if ($this->isUnhealable($field)) {
                $result->skippedFields[] = $field;
            } else {
                $healable[] = $field;
            }
        }

        if (empty($healable)) {
            $result->errors[] = 'Все пропущенные поля нельзя вылечить ИИ (фото, штрихкод, цена, бренд)';
            $this->markHealAttempt($modelId, $channel->id);
            return $result;
        }

        // Загружаем данные модели
        $model = $db->createCommand("
            SELECT
                pm.id, pm.name, pm.product_family, pm.brand_id,
                pm.description, pm.short_description,
                pm.canonical_attributes, pm.canonical_images,
                b.canonical_name AS brand_name
            FROM {{%product_models}} pm
            LEFT JOIN {{%brands}} b ON b.id = pm.brand_id
            WHERE pm.id = :id
        ", [':id' => $modelId])->queryOne();

        if (!$model) {
            $result->errors[] = "Модель #{$modelId} не найдена";
            return $result;
        }

        $attrs = $this->parseJson($model['canonical_attributes']);
        $brandName = $model['brand_name'] ?? '';
        $family = $model['product_family'] ?? 'unknown';
        $updated = false;

        /** @var AIService $ai */
        $ai = Yii::$app->get('aiService');

        if (!$ai->isAvailable()) {
            $result->errors[] = 'AI сервис недоступен (нет API ключа)';
            $this->markHealAttempt($modelId, $channel->id);
            return $result;
        }

        // ═══ ЛЕЧЕНИЕ ОПИСАНИЯ ═══
        $needsDescription = in_array('required:description', $healable);
        if ($needsDescription) {
            try {
                $descResult = $ai->generateSeoDescription(
                    $model['name'],
                    $brandName,
                    $family,
                    $attrs,
                    $model['description'] ?? ''
                );

                if ($descResult && mb_strlen($descResult['description']) >= $this->minDescriptionLength) {
                    // Обновляем модель
                    $updateData = ['description' => $descResult['description']];
                    if (!empty($descResult['short_description'])) {
                        $updateData['short_description'] = $descResult['short_description'];
                    }

                    $db->createCommand()->update(
                        '{{%product_models}}',
                        $updateData,
                        ['id' => $modelId]
                    )->execute();

                    $result->description = $descResult['description'];
                    $result->healedFields[] = 'description';
                    if (!empty($descResult['short_description'])) {
                        $result->healedFields[] = 'short_description';
                    }
                    $updated = true;

                    Yii::info(
                        "AutoHealing: описание сгенерировано для model_id={$modelId} ({$model['name']}), " .
                        mb_strlen($descResult['description']) . " симв.",
                        'ai.healing'
                    );
                } else {
                    $result->failedFields[] = 'description';
                    $result->errors[] = 'Описание слишком короткое или пустое';
                }
            } catch (\Throwable $e) {
                $result->failedFields[] = 'description';
                $result->errors[] = 'Ошибка генерации описания: ' . $e->getMessage();
                Yii::warning("AutoHealing: ошибка описания model_id={$modelId}: {$e->getMessage()}", 'ai.healing');
            }
        }

        // ═══ ЛЕЧЕНИЕ АТРИБУТОВ ═══
        $missingAttrs = [];
        foreach ($healable as $field) {
            if (preg_match('/^required:attr:(.+)$/', $field, $m)) {
                $missingAttrs[] = $m[1];
            } elseif (preg_match('/^recommended:attr:(.+)$/', $field, $m)) {
                $missingAttrs[] = $m[1];
            }
        }

        if (!empty($missingAttrs)) {
            try {
                $inferredAttrs = $ai->inferMissingAttributes(
                    $model['name'],
                    $brandName,
                    $family,
                    $missingAttrs,
                    $attrs
                );

                if (!empty($inferredAttrs)) {
                    // Мержим с существующими атрибутами
                    $mergedAttrs = array_merge($attrs, $inferredAttrs);

                    $db->createCommand()->update(
                        '{{%product_models}}',
                        ['canonical_attributes' => json_encode($mergedAttrs, JSON_UNESCAPED_UNICODE)],
                        ['id' => $modelId]
                    )->execute();

                    foreach ($inferredAttrs as $key => $value) {
                        $result->healedFields[] = 'attr:' . $key;
                        $result->attributes[$key] = $value;
                    }
                    $updated = true;

                    Yii::info(
                        "AutoHealing: атрибуты определены для model_id={$modelId}: " .
                        implode(', ', array_keys($inferredAttrs)),
                        'ai.healing'
                    );
                }

                // Атрибуты, которые ИИ не смог определить
                foreach ($missingAttrs as $attrKey) {
                    if (!isset($inferredAttrs[$attrKey])) {
                        $result->failedFields[] = 'attr:' . $attrKey;
                    }
                }
            } catch (\Throwable $e) {
                foreach ($missingAttrs as $attrKey) {
                    $result->failedFields[] = 'attr:' . $attrKey;
                }
                $result->errors[] = 'Ошибка определения атрибутов: ' . $e->getMessage();
                Yii::warning("AutoHealing: ошибка атрибутов model_id={$modelId}: {$e->getMessage()}", 'ai.healing');
            }
        }

        // ═══ CLOSED-LOOP: пересчёт readiness + авто-outbox ═══
        if ($updated) {
            $result->success = true;
            $this->closedLoop($modelId, $channel, $result);
        }

        // Отмечаем попытку лечения
        $this->markHealAttempt($modelId, $channel->id);

        return $result;
    }

    /**
     * Можно ли лечить эту модель (не в кулдауне)?
     */
    public function canHeal(int $modelId, int $channelId): bool
    {
        $lastAttempt = Yii::$app->db->createCommand("
            SELECT last_heal_attempt_at FROM {{%model_channel_readiness}}
            WHERE model_id = :mid AND channel_id = :cid
        ", [':mid' => $modelId, ':cid' => $channelId])->queryScalar();

        if (!$lastAttempt) {
            return true; // Никогда не лечили
        }

        $lastTime = strtotime($lastAttempt);
        return (time() - $lastTime) >= $this->healCooldownSeconds;
    }

    /**
     * Определить, есть ли в missing_fields хоть что-то лечимое ИИ.
     */
    public function hasHealableFields(array $missingFields): bool
    {
        foreach ($missingFields as $field) {
            if (!$this->isUnhealable($field)) {
                return true;
            }
        }
        return false;
    }

    // ═══════════════════════════════════════════
    // PRIVATE
    // ═══════════════════════════════════════════

    /**
     * Closed-Loop: пересчитываем readiness и при 100% — пушим в Outbox.
     */
    private function closedLoop(int $modelId, SalesChannel $channel, HealingResultDTO $result): void
    {
        try {
            /** @var ReadinessScoringService $readiness */
            $readiness = Yii::$app->get('readinessService');
            $readiness->resetCache();

            $report = $readiness->evaluate($modelId, $channel, true);
            $result->newScore = $report->score;
            $result->newIsReady = $report->isReady;

            if ($report->isReady) {
                // Модель готова! Пушим content_updated в Outbox
                try {
                    /** @var OutboxService $outbox */
                    $outbox = Yii::$app->get('outbox');
                    // Временно отключаем readiness gate — мы уже проверили
                    $originalGate = $outbox->readinessGate;
                    $outbox->readinessGate = false;
                    $outbox->emitContentUpdate($modelId, null, ['source' => 'auto_healing']);
                    $outbox->readinessGate = $originalGate;

                    Yii::info(
                        "AutoHealing: CLOSED-LOOP — model_id={$modelId} now READY (score={$report->score}%), pushed to Outbox",
                        'ai.healing'
                    );
                } catch (\Throwable $e) {
                    $result->errors[] = 'Ошибка Outbox: ' . $e->getMessage();
                    Yii::warning("AutoHealing: outbox push failed for model_id={$modelId}: {$e->getMessage()}", 'ai.healing');
                }
            }
        } catch (\Throwable $e) {
            $result->errors[] = 'Ошибка пересчёта readiness: ' . $e->getMessage();
            Yii::warning("AutoHealing: readiness recheck failed for model_id={$modelId}: {$e->getMessage()}", 'ai.healing');
        }
    }

    /**
     * Записать дату попытки лечения.
     */
    private function markHealAttempt(int $modelId, int $channelId): void
    {
        try {
            Yii::$app->db->createCommand("
                UPDATE {{%model_channel_readiness}}
                SET last_heal_attempt_at = NOW()
                WHERE model_id = :mid AND channel_id = :cid
            ", [':mid' => $modelId, ':cid' => $channelId])->execute();
        } catch (\Throwable $e) {
            // Не фатально
            Yii::warning("AutoHealing: mark heal attempt failed: {$e->getMessage()}", 'ai.healing');
        }
    }

    /**
     * Поле нельзя вылечить ИИ?
     */
    private function isUnhealable(string $field): bool
    {
        foreach (self::UNHEALABLE_FIELDS as $pattern) {
            if ($field === $pattern) {
                return true;
            }
        }
        return false;
    }

    /**
     * Безопасный парсинг JSON.
     */
    private function parseJson($value): array
    {
        if (empty($value)) {
            return [];
        }
        if (is_string($value)) {
            return json_decode($value, true) ?: [];
        }
        return is_array($value) ? $value : [];
    }
}
