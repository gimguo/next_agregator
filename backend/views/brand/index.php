<?php

/**
 * @var yii\web\View $this
 * @var yii\data\ActiveDataProvider $dataProvider
 */

use yii\grid\GridView;
use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'Бренды';
$this->params['breadcrumbs'][] = $this->title;
?>

<div class="brand-index">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0" style="font-weight:700">
            <i class="fas fa-tags me-2" style="color:var(--accent)"></i><?= Html::encode($this->title) ?>
        </h3>
        <a href="<?= Url::to(['create']) ?>" class="btn btn-accent">
            <i class="fas fa-plus me-1"></i> Создать бренд
        </a>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <?= GridView::widget([
                'dataProvider' => $dataProvider,
                'tableOptions' => ['class' => 'table table-striped mb-0'],
                'layout' => "{items}\n<div class='card-footer d-flex justify-content-between align-items-center'>{summary}{pager}</div>",
                'columns' => [
                    [
                        'attribute' => 'id',
                        'headerOptions' => ['style' => 'width:60px'],
                    ],
                    [
                        'attribute' => 'name',
                        'format' => 'raw',
                        'value' => function ($model) {
                            return Html::a(
                                Html::encode($model->name),
                                ['view', 'id' => $model->id],
                                ['style' => 'color:var(--accent);text-decoration:none;font-weight:500']
                            );
                        },
                    ],
                    [
                        'attribute' => 'slug',
                        'format' => 'raw',
                        'value' => function ($model) {
                            return $model->slug 
                                ? '<code style="font-size:.75rem;color:var(--text-muted)">' . Html::encode($model->slug) . '</code>'
                                : '—';
                        },
                    ],
                    [
                        'label' => 'Алиасы',
                        'format' => 'raw',
                        'value' => function ($model) {
                            $count = $model->getAliases()->count();
                            if ($count > 0) {
                                return '<span style="color:var(--text-secondary);font-size:.85rem">'
                                    . number_format($count) . ' алиасов'
                                    . '</span>';
                            }
                            return '<span style="color:var(--text-muted)">—</span>';
                        },
                        'headerOptions' => ['style' => 'width:100px'],
                    ],
                    [
                        'label' => 'Товаров',
                        'format' => 'raw',
                        'value' => function ($model) {
                            $count = $model->getProductModels()->count();
                            if ($count > 0) {
                                return Html::a(
                                    number_format($count),
                                    ['/catalog/index', 'ProductModelSearch[brand_id]' => $model->id],
                                    ['style' => 'color:var(--accent)']
                                );
                            }
                            return '<span style="color:var(--text-muted)">—</span>';
                        },
                        'headerOptions' => ['style' => 'width:100px'],
                    ],
                    [
                        'attribute' => 'is_active',
                        'format' => 'raw',
                        'value' => function ($model) {
                            return $model->is_active
                                ? '<span class="badge bg-success" style="font-size:.7rem">Активен</span>'
                                : '<span class="badge bg-secondary" style="font-size:.7rem">Неактивен</span>';
                        },
                        'headerOptions' => ['style' => 'width:100px'],
                    ],
                    [
                        'class' => 'yii\grid\ActionColumn',
                        'headerOptions' => ['style' => 'width:100px'],
                        'template' => '{view} {update} {delete}',
                        'buttons' => [
                            'view' => function ($url, $model) {
                                return Html::a(
                                    '<i class="fas fa-eye"></i>',
                                    $url,
                                    [
                                        'class' => 'btn btn-sm btn-link',
                                        'title' => 'Просмотр',
                                        'style' => 'color:var(--accent)',
                                    ]
                                );
                            },
                            'update' => function ($url, $model) {
                                return Html::a(
                                    '<i class="fas fa-edit"></i>',
                                    $url,
                                    [
                                        'class' => 'btn btn-sm btn-link',
                                        'title' => 'Редактировать',
                                        'style' => 'color:var(--accent)',
                                    ]
                                );
                            },
                            'delete' => function ($url, $model) {
                                return Html::a(
                                    '<i class="fas fa-trash"></i>',
                                    $url,
                                    [
                                        'class' => 'btn btn-sm btn-link',
                                        'title' => 'Удалить',
                                        'style' => 'color:var(--danger)',
                                        'data-confirm' => 'Удалить бренд?',
                                        'data-method' => 'post',
                                    ]
                                );
                            },
                        ],
                    ],
                ],
            ]) ?>
        </div>
    </div>

</div>
