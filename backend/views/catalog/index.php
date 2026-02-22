<?php

/** @var yii\web\View $this */
/** @var backend\models\ProductModelSearch $searchModel */
/** @var yii\data\ActiveDataProvider $dataProvider */

use common\components\S3UrlGenerator;
use common\models\MediaAsset;
use yii\grid\GridView;
use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'MDM Каталог — Модели товаров';
$this->params['breadcrumbs'][] = 'MDM Каталог';

// Prefetch thumbnails, readiness for all models on this page to avoid N+1 queries
$modelIds = array_map(fn($m) => $m->id, $dataProvider->getModels());
$thumbMap = [];
$photoCountMap = [];
$readinessMap = [];
if (!empty($modelIds)) {
    $inSql = implode(',', $modelIds);
    // Primary/first image per model
    $thumbs = Yii::$app->db->createCommand("
        SELECT DISTINCT ON (entity_id)
            entity_id, s3_bucket, COALESCE(s3_thumb_key, s3_key) as thumb_key
        FROM {{%media_assets}}
        WHERE entity_type='model' AND entity_id IN ({$inSql})
          AND status IN ('processed','deduplicated') AND s3_key IS NOT NULL
        ORDER BY entity_id, is_primary DESC, sort_order ASC
    ")->queryAll();
    foreach ($thumbs as $t) {
        $thumbMap[(int)$t['entity_id']] = S3UrlGenerator::getPublicUrl($t['s3_bucket'], $t['thumb_key']);
    }
    // Photo counts per model
    $counts = Yii::$app->db->createCommand("
        SELECT entity_id,
            count(*) as total,
            count(*) FILTER (WHERE status IN ('processed','deduplicated')) as ready
        FROM {{%media_assets}}
        WHERE entity_type='model' AND entity_id IN ({$inSql})
        GROUP BY entity_id
    ")->queryAll();
    foreach ($counts as $c) {
        $photoCountMap[(int)$c['entity_id']] = ['total' => (int)$c['total'], 'ready' => (int)$c['ready']];
    }
    // Readiness scores (для активного канала по умолчанию)
    $channel = \common\models\SalesChannel::find()->where(['is_active' => true])->one();
    if ($channel) {
        $readinessRows = Yii::$app->db->createCommand("
            SELECT model_id, score, is_ready
            FROM {{%model_channel_readiness}}
            WHERE model_id IN ({$inSql}) AND channel_id = :channel_id
        ", [':channel_id' => $channel->id])->queryAll();
        foreach ($readinessRows as $r) {
            $readinessMap[(int)$r['model_id']] = [
                'score' => (int)$r['score'],
                'is_ready' => (bool)$r['is_ready'],
            ];
        }
    }
}
?>
<div class="catalog-index">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0" style="font-weight:700">MDM Каталог</h3>
        <div>
            <span class="text-secondary me-3" style="font-size:.85rem">
                Всего моделей: <strong style="color:var(--text-primary)"><?= $dataProvider->getTotalCount() ?></strong>
            </span>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <?= GridView::widget([
                'dataProvider' => $dataProvider,
                'filterModel' => $searchModel,
                'tableOptions' => ['class' => 'table table-striped mb-0'],
                'layout' => "{items}\n<div class='card-footer d-flex justify-content-between align-items-center'>{summary}{pager}</div>",
                'columns' => [
                    [
                        'attribute' => 'id',
                        'headerOptions' => ['style' => 'width:60px'],
                    ],
                    [
                        'label' => '',
                        'format' => 'raw',
                        'headerOptions' => ['style' => 'width:54px'],
                        'contentOptions' => ['style' => 'padding:4px 6px'],
                        'value' => function ($model) use ($thumbMap) {
                            $url = $thumbMap[$model->id] ?? null;
                            if ($url) {
                                return '<a href="' . Url::to(['catalog/view', 'id' => $model->id]) . '">'
                                    . '<img src="' . Html::encode($url) . '" loading="lazy" '
                                    . 'style="width:42px;height:42px;object-fit:cover;border-radius:6px;border:1px solid var(--border)">'
                                    . '</a>';
                            }
                            return '<a href="' . Url::to(['catalog/view', 'id' => $model->id]) . '">'
                                . '<div style="width:42px;height:42px;border-radius:6px;background:var(--bg-body);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;color:var(--text-muted)">'
                                . '<i class="fas fa-image" style="font-size:1rem;opacity:0.3"></i>'
                                . '</div>'
                                . '</a>';
                        },
                    ],
                    [
                        'attribute' => 'product_family',
                        'label' => 'Family',
                        'filter' => [
                            'mattress' => 'Матрас',
                            'pillow' => 'Подушка',
                            'bed' => 'Кровать',
                            'topper' => 'Топпер',
                            'blanket' => 'Одеяло',
                            'base' => 'Основание',
                            'unknown' => 'Unknown',
                        ],
                        'format' => 'raw',
                        'value' => function ($model) {
                            $labels = [
                                'mattress' => 'Матрас',
                                'pillow' => 'Подушка',
                                'bed' => 'Кровать',
                                'topper' => 'Топпер',
                                'blanket' => 'Одеяло',
                                'base' => 'Основание',
                                'unknown' => 'Unknown',
                            ];
                            $family = $model->product_family ?? 'unknown';
                            $label = $labels[$family] ?? $family;
                            $badgeClass = ($family === 'unknown') ? 'badge-draft' : 'badge-partial';
                            return '<span class="badge-status ' . $badgeClass . '" style="font-size:.7rem">'
                                . Html::encode($label)
                                . '</span>';
                        },
                        'headerOptions' => ['style' => 'width:100px'],
                    ],
                    [
                        'attribute' => 'brand_id',
                        'label' => 'Бренд',
                        'filter' => \yii\helpers\ArrayHelper::map(
                            \common\models\Brand::find()->where(['is_active' => true])->orderBy('canonical_name')->all(),
                            'id',
                            'canonical_name'
                        ),
                        'value' => function ($model) {
                            return $model->brand ? $model->brand->canonical_name : '—';
                        },
                        'headerOptions' => ['style' => 'width:130px'],
                    ],
                    [
                        'attribute' => 'name',
                        'format' => 'raw',
                        'value' => function ($model) {
                            return Html::a(
                                Html::encode(mb_strimwidth($model->name, 0, 60, '...')),
                                ['catalog/view', 'id' => $model->id],
                                ['style' => 'color:var(--accent);text-decoration:none;font-weight:500']
                            );
                        },
                    ],
                    [
                        'attribute' => 'variant_count',
                        'label' => 'Вар.',
                        'headerOptions' => ['style' => 'width:60px;text-align:center'],
                        'contentOptions' => ['style' => 'text-align:center'],
                    ],
                    [
                        'label' => 'Фото',
                        'format' => 'raw',
                        'headerOptions' => ['style' => 'width:80px;text-align:center'],
                        'contentOptions' => ['style' => 'text-align:center'],
                        'value' => function ($model) use ($photoCountMap) {
                            $pc = $photoCountMap[$model->id] ?? null;
                            if ($pc) {
                                $cls = ($pc['ready'] === $pc['total']) ? 'badge-active' : 'badge-partial';
                                return '<span class="badge-status ' . $cls . '">' . $pc['ready'] . '/' . $pc['total'] . '</span>';
                            }
                            return '<span style="color:var(--text-secondary)">0</span>';
                        },
                    ],
                    [
                        'label' => 'Готовность',
                        'format' => 'raw',
                        'headerOptions' => ['style' => 'width:100px;text-align:center'],
                        'contentOptions' => ['style' => 'text-align:center'],
                        'value' => function ($model) use ($readinessMap) {
                            $r = $readinessMap[$model->id] ?? null;
                            if (!$r) {
                                return '<span class="badge bg-secondary" style="font-size:.7rem">—</span>';
                            }
                            $score = $r['score'];
                            $isReady = $r['is_ready'];
                            if ($isReady || $score >= 100) {
                                $cls = 'bg-success';
                            } elseif ($score >= 50) {
                                $cls = 'bg-warning';
                            } else {
                                $cls = 'bg-danger';
                            }
                            return '<span class="badge ' . $cls . '" style="font-size:.7rem;font-weight:600">' . $score . '%</span>';
                        },
                    ],
                    [
                        'attribute' => 'best_price',
                        'label' => 'Цена',
                        'format' => 'raw',
                        'headerOptions' => ['style' => 'width:110px;text-align:right'],
                        'contentOptions' => ['style' => 'text-align:right'],
                        'value' => function ($model) {
                            if ($model->best_price) {
                                return '<strong>' . number_format($model->best_price, 0, '.', ' ') . ' &#8381;</strong>';
                            }
                            return '<span style="color:var(--text-secondary)">—</span>';
                        },
                    ],
                    [
                        'attribute' => 'status',
                        'filter' => ['active' => 'Active', 'draft' => 'Draft', 'archived' => 'Archived'],
                        'format' => 'raw',
                        'headerOptions' => ['style' => 'width:80px'],
                        'value' => function ($model) {
                            $map = [
                                'active' => 'badge-active',
                                'draft' => 'badge-draft',
                                'archived' => 'badge-inactive',
                            ];
                            $cls = $map[$model->status] ?? 'badge-draft';
                            return '<span class="badge-status ' . $cls . '">' . Html::encode($model->status) . '</span>';
                        },
                    ],
                ],
            ]) ?>
        </div>
    </div>

</div>
