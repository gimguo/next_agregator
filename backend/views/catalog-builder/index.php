<?php

/**
 * @var yii\web\View $this
 * @var yii\data\ActiveDataProvider $dataProvider
 */

use yii\grid\GridView;
use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'Конструктор каталога';
$this->params['breadcrumbs'][] = $this->title;
?>

<div class="catalog-builder-index">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0" style="font-weight:700">
            <i class="fas fa-sitemap me-2" style="color:var(--accent)"></i><?= Html::encode($this->title) ?>
        </h3>
        <a href="<?= Url::to(['create']) ?>" class="btn btn-accent">
            <i class="fas fa-plus me-1"></i> Создать каталог
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
                                Html::encode($model->name ?: 'Без названия'),
                                ['view', 'id' => $model->id],
                                ['style' => 'color:var(--accent);text-decoration:none;font-weight:500']
                            );
                        },
                    ],
                    [
                        'label' => 'Шаблон',
                        'format' => 'raw',
                        'value' => function ($model) {
                            $template = $model->template;
                            if (!$template) return '—';
                            return '<span class="badge bg-secondary" style="font-size:.75rem">'
                                . Html::encode($template->name)
                                . '</span>';
                        },
                        'headerOptions' => ['style' => 'width:150px'],
                    ],
                    [
                        'label' => 'Статистика',
                        'format' => 'raw',
                        'value' => function ($model) {
                            return '<span style="color:var(--text-secondary);font-size:.85rem">'
                                . '<i class="fas fa-box me-1"></i>' . number_format($model->product_count) . ' товаров'
                                . ' · '
                                . '<i class="fas fa-folder me-1"></i>' . number_format($model->category_count) . ' категорий'
                                . '</span>';
                        },
                        'headerOptions' => ['style' => 'width:200px'],
                    ],
                    [
                        'attribute' => 'created_at',
                        'format' => 'datetime',
                        'headerOptions' => ['style' => 'width:160px'],
                    ],
                    [
                        'class' => 'yii\grid\ActionColumn',
                        'headerOptions' => ['style' => 'width:100px'],
                        'template' => '{view} {delete}',
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
                            'delete' => function ($url, $model) {
                                return Html::a(
                                    '<i class="fas fa-trash"></i>',
                                    $url,
                                    [
                                        'class' => 'btn btn-sm btn-link',
                                        'title' => 'Удалить',
                                        'style' => 'color:var(--danger)',
                                        'data-confirm' => 'Удалить превью каталога?',
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
