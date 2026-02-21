<?php

namespace common\models;

use yii\db\ActiveRecord;

/**
 * Sprint 15: Полиморфный источник данных для MDM-модели.
 *
 * Хранит вклад каждого источника (поставщик, AI, менеджер) в карточку товара.
 * Используется для реализации Manual Override с приоритетами:
 *   - supplier = 30 → данные из прайса
 *   - ai_enrichment / ai_attributes = 50 → AI-сгенерированные данные
 *   - manual_override = 100 → ручная правка менеджером (перекрывает всё)
 *
 * @property int    $id
 * @property int    $model_id       FK → product_models
 * @property string $source_type    supplier|ai_enrichment|ai_attributes|manual_override
 * @property string $source_id      Код поставщика, AI-модель, user_id
 * @property array  $data           JSONB — произвольные данные от источника
 * @property int    $priority       Приоритет (чем выше, тем главнее)
 * @property float  $confidence     0.00–1.00
 * @property int    $updated_by     ID пользователя (для manual_override)
 * @property string $created_at
 * @property string $updated_at
 *
 * @property ProductModel $model
 */
class ModelDataSource extends ActiveRecord
{
    // ═══ source_type enum ═══
    const SOURCE_SUPPLIER     = 'supplier';
    const SOURCE_AI_ENRICH    = 'ai_enrichment';
    const SOURCE_AI_ATTRS     = 'ai_attributes';
    const SOURCE_MANUAL       = 'manual_override';

    // ═══ default priorities ═══
    const PRIORITY_SUPPLIER   = 30;
    const PRIORITY_AI         = 50;
    const PRIORITY_MANUAL     = 100;

    public static function tableName(): string
    {
        return '{{%model_data_sources}}';
    }

    public function rules(): array
    {
        return [
            [['model_id', 'source_type'], 'required'],
            ['source_type', 'in', 'range' => [
                self::SOURCE_SUPPLIER,
                self::SOURCE_AI_ENRICH,
                self::SOURCE_AI_ATTRS,
                self::SOURCE_MANUAL,
            ]],
            ['source_id', 'string', 'max' => 100],
            [['model_id', 'priority', 'updated_by'], 'integer'],
            [['confidence'], 'number', 'min' => 0, 'max' => 1],
            ['priority', 'default', 'value' => self::PRIORITY_AI],
            ['data', 'safe'],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'id'          => 'ID',
            'model_id'    => 'Модель',
            'source_type' => 'Тип источника',
            'source_id'   => 'Источник',
            'data'        => 'Данные',
            'priority'    => 'Приоритет',
            'confidence'  => 'Уверенность',
            'updated_by'  => 'Кем обновлено',
            'created_at'  => 'Создано',
            'updated_at'  => 'Обновлено',
        ];
    }

    public function getModel()
    {
        return $this->hasOne(ProductModel::class, ['id' => 'model_id']);
    }

    /**
     * Получить data как массив.
     */
    public function getDataArray(): array
    {
        $val = $this->data;
        if (is_string($val)) {
            return json_decode($val, true) ?: [];
        }
        return is_array($val) ? $val : [];
    }

    /**
     * UPSERT: записать/обновить источник данных.
     *
     * @param int    $modelId
     * @param string $sourceType
     * @param string $sourceId
     * @param array  $data
     * @param int    $priority
     * @param float|null $confidence
     * @param int|null   $updatedBy
     */
    public static function upsert(
        int $modelId,
        string $sourceType,
        string $sourceId,
        array $data,
        int $priority = self::PRIORITY_AI,
        ?float $confidence = null,
        ?int $updatedBy = null
    ): void {
        $db = \Yii::$app->db;

        $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE);

        $db->createCommand("
            INSERT INTO {{%model_data_sources}} (model_id, source_type, source_id, data, priority, confidence, updated_by, created_at, updated_at)
            VALUES (:mid, :stype, :sid, :data::jsonb, :priority, :conf, :uby, NOW(), NOW())
            ON CONFLICT (model_id, source_type, source_id) DO UPDATE SET
                data = EXCLUDED.data,
                priority = EXCLUDED.priority,
                confidence = EXCLUDED.confidence,
                updated_by = EXCLUDED.updated_by,
                updated_at = NOW()
        ", [
            ':mid'      => $modelId,
            ':stype'    => $sourceType,
            ':sid'      => $sourceId,
            ':data'     => $jsonData,
            ':priority' => $priority,
            ':conf'     => $confidence,
            ':uby'      => $updatedBy,
        ])->execute();
    }

    /**
     * Собрать финальные данные модели из всех источников с учётом приоритетов.
     *
     * Данные от высокоприоритетного источника перекрывают данные от низкоприоритетного.
     * Возвращает merged массив: ['description' => ..., 'attributes' => [...], ...]
     */
    public static function mergeAllSources(int $modelId): array
    {
        $sources = self::find()
            ->where(['model_id' => $modelId])
            ->orderBy(['priority' => SORT_ASC]) // Низший приоритет → первый (перезаписывается высшим)
            ->all();

        $merged = [];
        foreach ($sources as $src) {
            $srcData = $src->getDataArray();
            foreach ($srcData as $key => $value) {
                if ($value !== null && $value !== '' && $value !== []) {
                    $merged[$key] = $value;
                }
            }
        }

        return $merged;
    }

    /**
     * Списки для UI.
     */
    public static function sourceTypes(): array
    {
        return [
            self::SOURCE_SUPPLIER  => 'Поставщик',
            self::SOURCE_AI_ENRICH => 'AI (описание)',
            self::SOURCE_AI_ATTRS  => 'AI (атрибуты)',
            self::SOURCE_MANUAL    => 'Ручная правка',
        ];
    }
}
