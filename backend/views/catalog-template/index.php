<?php

/**
 * @var yii\web\View $this
 * @var yii\data\ActiveDataProvider $dataProvider
 */

use yii\grid\GridView;
use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'Шаблоны каталога';
$this->params['breadcrumbs'][] = $this->title;
?>

<div class="catalog-template-index">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0" style="font-weight:700">
            <i class="fas fa-file-code me-2" style="color:var(--accent)"></i><?= Html::encode($this->title) ?>
        </h3>
        <a href="<?= Url::to(['create']) ?>" class="btn btn-accent">
            <i class="fas fa-plus me-1"></i> Создать шаблон
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
                        'attribute' => 'description',
                        'format' => 'text',
                        'value' => function ($model) {
                            return $model->description ? mb_substr($model->description, 0, 100) . '...' : '—';
                        },
                    ],
                    [
                        'label' => 'Тип',
                        'format' => 'raw',
                        'value' => function ($model) {
                            if ($model->is_system) {
                                return '<span class="badge bg-warning" style="font-size:.75rem">Системный</span>';
                            }
                            return '<span class="badge bg-secondary" style="font-size:.75rem">Пользовательский</span>';
                        },
                        'headerOptions' => ['style' => 'width:120px'],
                    ],
                    [
                        'label' => 'Превью',
                        'format' => 'raw',
                        'value' => function ($model) {
                            $count = $model->getPreviews()->count();
                            if ($count > 0) {
                                return '<span style="color:var(--text-secondary);font-size:.85rem">'
                                    . number_format($count) . ' превью'
                                    . '</span>';
                            }
                            return '<span style="color:var(--text-muted)">—</span>';
                        },
                        'headerOptions' => ['style' => 'width:100px'],
                    ],
                    [
                        'attribute' => 'created_at',
                        'format' => 'datetime',
                        'headerOptions' => ['style' => 'width:160px'],
                    ],
                    [
                        'class' => 'yii\grid\ActionColumn',
                        'headerOptions' => ['style' => 'width:120px'],
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
                                $options = [
                                    'class' => 'btn btn-sm btn-link',
                                    'title' => 'Удалить',
                                    'style' => 'color:var(--danger)',
                                    'data-confirm' => 'Удалить шаблон каталога?',
                                    'data-method' => 'post',
                                ];
                                if ($model->is_system) {
                                    $options['style'] = 'color:var(--text-muted);cursor:not-allowed;opacity:.5';
                                    $options['data-confirm'] = null;
                                    $options['onclick'] = 'return false;';
                                    $options['title'] = 'Системный шаблон нельзя удалить';
                                }
                                return Html::a('<i class="fas fa-trash"></i>', $url, $options);
                            },
                        ],
                    ],
                ],
            ]) ?>
        </div>
    </div>

</div>
