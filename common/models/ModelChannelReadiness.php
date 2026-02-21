<?php

namespace common\models;

use yii\db\ActiveRecord;

/**
 * Кэш готовности модели для канала.
 *
 * Материализованный результат ReadinessScoringService::evaluate().
 * Обновляется:
 *   - При каждом emitContentUpdate (проактивная проверка)
 *   - Массово через quality/scan
 *
 * @property int    $id
 * @property int    $model_id
 * @property int    $channel_id
 * @property bool   $is_ready        Готова ли модель для публикации
 * @property int    $score           0-100 (%)
 * @property array  $missing_fields  JSONB: ['image', 'description', 'attr:height']
 * @property string $checked_at
 *
 * @property ProductModel $model
 * @property SalesChannel $channel
 */
class ModelChannelReadiness extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%model_channel_readiness}}';
    }

    public function rules(): array
    {
        return [
            [['model_id', 'channel_id'], 'required'],
            [['model_id', 'channel_id', 'score'], 'integer'],
            ['is_ready', 'boolean'],
            ['score', 'integer', 'min' => 0, 'max' => 100],
            ['missing_fields', 'safe'],
        ];
    }

    public function getModel()
    {
        return $this->hasOne(ProductModel::class, ['id' => 'model_id']);
    }

    public function getChannel()
    {
        return $this->hasOne(SalesChannel::class, ['id' => 'channel_id']);
    }

    /**
     * Получить missing_fields как массив.
     */
    public function getMissingList(): array
    {
        $val = $this->missing_fields;
        if (is_string($val)) {
            return json_decode($val, true) ?: [];
        }
        return is_array($val) ? $val : [];
    }

    /**
     * UPSERT: обновить или создать запись кэша.
     */
    public static function upsert(int $modelId, int $channelId, bool $isReady, int $score, array $missingFields): void
    {
        $db = \Yii::$app->db;

        $db->createCommand("
            INSERT INTO {{%model_channel_readiness}} (model_id, channel_id, is_ready, score, missing_fields, checked_at)
            VALUES (:mid, :cid, :ready, :score, :missing::jsonb, NOW())
            ON CONFLICT (model_id, channel_id) DO UPDATE SET
                is_ready = EXCLUDED.is_ready,
                score = EXCLUDED.score,
                missing_fields = EXCLUDED.missing_fields,
                checked_at = NOW()
        ", [
            ':mid'     => $modelId,
            ':cid'     => $channelId,
            ':ready'   => $isReady ? 'true' : 'false',
            ':score'   => $score,
            ':missing' => json_encode($missingFields, JSON_UNESCAPED_UNICODE),
        ])->execute();
    }
}
