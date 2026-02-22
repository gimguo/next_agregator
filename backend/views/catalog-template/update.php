<?php

/**
 * @var yii\web\View $this
 * @var common\models\CatalogTemplate $model
 * @var bool $isSystem
 */

use yii\helpers\Html;
use yii\widgets\ActiveForm;

$this->title = 'Редактировать шаблон: ' . $model->name;
$this->params['breadcrumbs'][] = ['label' => 'Шаблоны каталога', 'url' => ['index']];
$this->params['breadcrumbs'][] = ['label' => $model->name, 'url' => ['view', 'id' => $model->id]];
$this->params['breadcrumbs'][] = 'Редактировать';
?>

<div class="catalog-template-update">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0" style="font-weight:700">
            <i class="fas fa-edit me-2" style="color:var(--accent)"></i><?= Html::encode($this->title) ?>
            <?php if ($isSystem): ?>
                <span class="badge bg-warning ms-2" style="font-size:.7rem">Системный</span>
            <?php endif; ?>
        </h3>
        <a href="<?= Url::to(['view', 'id' => $model->id]) ?>" class="btn btn-dark-outline">
            <i class="fas fa-arrow-left me-1"></i> Назад
        </a>
    </div>

    <?php if ($isSystem): ?>
        <div class="alert alert-warning mb-4">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>Системный шаблон:</strong> нельзя переименовывать. Можно редактировать только описание и структуру категорий.
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <?php $form = ActiveForm::begin(); ?>

            <?= $form->field($model, 'name')
                ->textInput([
                    'maxlength' => true,
                    'disabled' => $isSystem, // Системные шаблоны нельзя переименовывать
                ])
                ->label('Название шаблона')
                ->hint($isSystem ? 'Системные шаблоны нельзя переименовывать' : 'Уникальное название для идентификации шаблона') ?>

            <?= $form->field($model, 'description')
                ->textarea(['rows' => 3])
                ->label('Описание')
                ->hint('Краткое описание назначения шаблона (необязательно)') ?>

            <?= $form->field($model, 'structure_json')
                ->textarea(['rows' => 15, 'style' => 'font-family:monospace;font-size:.85rem'])
                ->label('Структура категорий (JSON)')
                ->hint('JSON структура категорий каталога. Пример: {"categories": [{"id": 1, "name": "Матрасы", "slug": "matrasy", "parent_id": null}]}') ?>

            <?= $form->field($model, 'merge_rules')
                ->textarea(['rows' => 5, 'style' => 'font-family:monospace;font-size:.85rem'])
                ->label('Правила объединения (JSON)')
                ->hint('JSON правила объединения категорий (необязательно)') ?>

            <div class="form-group mt-4">
                <?= Html::submitButton(
                    '<i class="fas fa-save me-1"></i> Сохранить',
                    ['class' => 'btn btn-accent btn-lg']
                ) ?>
                <?= Html::a(
                    'Отмена',
                    ['view', 'id' => $model->id],
                    ['class' => 'btn btn-dark-outline btn-lg ms-2']
                ) ?>
            </div>

            <?php ActiveForm::end(); ?>
        </div>
    </div>

</div>
