<?php

/**
 * @var yii\web\View $this
 * @var common\models\CatalogTemplate $model
 */

use yii\helpers\Html;
use yii\helpers\Url;
use yii\widgets\DetailView;

$this->title = $model->name;
$this->params['breadcrumbs'][] = ['label' => 'Шаблоны каталога', 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>

<div class="catalog-template-view">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0" style="font-weight:700">
            <i class="fas fa-file-code me-2" style="color:var(--accent)"></i><?= Html::encode($this->title) ?>
            <?php if ($model->is_system): ?>
                <span class="badge bg-warning ms-2" style="font-size:.7rem">Системный</span>
            <?php endif; ?>
        </h3>
        <div>
            <?= Html::a(
                '<i class="fas fa-edit me-1"></i> Редактировать',
                ['update', 'id' => $model->id],
                ['class' => 'btn btn-accent']
            ) ?>
            <?= Html::a(
                '<i class="fas fa-arrow-left me-1"></i> Назад',
                ['index'],
                ['class' => 'btn btn-dark-outline ms-2']
            ) ?>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">
            <i class="fas fa-info-circle me-2"></i>Информация о шаблоне
        </div>
        <div class="card-body">
            <?= DetailView::widget([
                'model' => $model,
                'options' => ['class' => 'table detail-view mb-0'],
                'attributes' => [
                    'id',
                    [
                        'label' => 'Название',
                        'value' => $model->name,
                    ],
                    [
                        'label' => 'Описание',
                        'value' => $model->description ?: '—',
                    ],
                    [
                        'label' => 'Тип',
                        'format' => 'raw',
                        'value' => $model->is_system
                            ? '<span class="badge bg-warning">Системный</span>'
                            : '<span class="badge bg-secondary">Пользовательский</span>',
                    ],
                    [
                        'label' => 'Превью каталогов',
                        'format' => 'raw',
                        'value' => function () use ($model) {
                            $count = $model->getPreviews()->count();
                            if ($count > 0) {
                                return Html::a(
                                    number_format($count) . ' превью',
                                    ['/catalog-builder/index', 'CatalogPreview[template_id]' => $model->id],
                                    ['style' => 'color:var(--accent)']
                                );
                            }
                            return '—';
                        },
                    ],
                    [
                        'label' => 'Создан',
                        'value' => Yii::$app->formatter->asDatetime($model->created_at),
                    ],
                    [
                        'label' => 'Обновлён',
                        'value' => Yii::$app->formatter->asDatetime($model->updated_at),
                    ],
                ],
            ]) ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <i class="fas fa-sitemap me-2"></i>Структура категорий (JSON)
        </div>
        <div class="card-body">
            <pre style="background:var(--bg-elevated);padding:12px;border-radius:6px;border:1px solid var(--border);font-size:.85rem;max-height:500px;overflow:auto"><?= Html::encode(json_encode($model->getStructure(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
        </div>
    </div>

    <div class="mt-4">
        <?php if (!$model->is_system): ?>
            <?= Html::a(
                '<i class="fas fa-trash me-1"></i> Удалить',
                ['delete', 'id' => $model->id],
                [
                    'class' => 'btn btn-danger',
                    'data-confirm' => 'Удалить шаблон каталога?',
                    'data-method' => 'post',
                ]
            ) ?>
        <?php else: ?>
            <div class="alert alert-warning mb-0">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>Системный шаблон</strong> нельзя удалить или переименовать. Можно редактировать только структуру категорий.
            </div>
        <?php endif; ?>
    </div>

</div>
