<?php

/** @var yii\web\View $this */
/** @var backend\models\ProductModelSearch $searchModel */
/** @var yii\data\ActiveDataProvider $dataProvider */

use yii\grid\GridView;
use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'MDM Каталог — Модели товаров';
$this->params['breadcrumbs'][] = 'MDM Каталог';
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
                        'headerOptions' => ['style' => 'width:70px'],
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
                        ],
                        'value' => function ($model) {
                            $labels = [
                                'mattress' => 'Матрас',
                                'pillow' => 'Подушка',
                                'bed' => 'Кровать',
                                'topper' => 'Топпер',
                                'blanket' => 'Одеяло',
                                'base' => 'Основание',
                            ];
                            return $labels[$model->product_family] ?? $model->product_family;
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
                        'headerOptions' => ['style' => 'width:140px'],
                    ],
                    [
                        'attribute' => 'name',
                        'format' => 'raw',
                        'value' => function ($model) {
                            return Html::a(
                                Html::encode(mb_strimwidth($model->name, 0, 70, '...')),
                                ['catalog/view', 'id' => $model->id],
                                ['style' => 'color:var(--accent);text-decoration:none;font-weight:500']
                            );
                        },
                    ],
                    [
                        'attribute' => 'variant_count',
                        'label' => 'Вариантов',
                        'headerOptions' => ['style' => 'width:100px;text-align:center'],
                        'contentOptions' => ['style' => 'text-align:center'],
                    ],
                    [
                        'label' => 'Фото',
                        'format' => 'raw',
                        'headerOptions' => ['style' => 'width:70px;text-align:center'],
                        'contentOptions' => ['style' => 'text-align:center'],
                        'value' => function ($model) {
                            $count = (int)\Yii::$app->db->createCommand(
                                "SELECT count(*) FROM {{%media_assets}} WHERE entity_type='model' AND entity_id=:id",
                                [':id' => $model->id]
                            )->queryScalar();
                            if ($count > 0) {
                                $processed = (int)\Yii::$app->db->createCommand(
                                    "SELECT count(*) FROM {{%media_assets}} WHERE entity_type='model' AND entity_id=:id AND status='processed'",
                                    [':id' => $model->id]
                                )->queryScalar();
                                $cls = ($processed === $count) ? 'badge-active' : 'badge-partial';
                                return '<span class="badge-status ' . $cls . '">' . $processed . '/' . $count . '</span>';
                            }
                            return '<span style="color:var(--text-secondary)">0</span>';
                        },
                    ],
                    [
                        'attribute' => 'best_price',
                        'label' => 'Лучшая цена',
                        'format' => 'raw',
                        'headerOptions' => ['style' => 'width:120px;text-align:right'],
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
                        'headerOptions' => ['style' => 'width:90px'],
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
