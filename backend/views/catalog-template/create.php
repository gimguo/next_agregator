<?php

/**
 * @var yii\web\View $this
 * @var common\models\CatalogTemplate $model
 */

use yii\helpers\Html;
use yii\helpers\Url;
use yii\widgets\ActiveForm;

$this->title = 'Создать шаблон каталога';
$this->params['breadcrumbs'][] = ['label' => 'Шаблоны каталога', 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>

<div class="catalog-template-create">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0" style="font-weight:700">
            <i class="fas fa-plus-circle me-2" style="color:var(--accent)"></i><?= Html::encode($this->title) ?>
        </h3>
        <a href="<?= Url::to(['index']) ?>" class="btn btn-dark-outline">
            <i class="fas fa-arrow-left me-1"></i> Назад к списку
        </a>
    </div>

    <div class="card">
        <div class="card-body">
            <?php $form = ActiveForm::begin(); ?>

            <?= $form->field($model, 'name')
                ->textInput(['maxlength' => true])
                ->label('Название шаблона')
                ->hint('Уникальное название для идентификации шаблона') ?>

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
                    '<i class="fas fa-save me-1"></i> Создать',
                    ['class' => 'btn btn-accent btn-lg']
                ) ?>
                <?= Html::a(
                    'Отмена',
                    ['index'],
                    ['class' => 'btn btn-dark-outline btn-lg ms-2']
                ) ?>
            </div>

            <?php ActiveForm::end(); ?>
        </div>
    </div>

</div>
