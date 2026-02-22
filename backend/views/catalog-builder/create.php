<?php

/**
 * @var yii\web\View $this
 * @var common\models\CatalogPreview $model
 * @var common\models\CatalogTemplate[] $templates
 * @var common\models\Supplier[] $suppliers
 */

use yii\helpers\Html;
use yii\widgets\ActiveForm;

$this->title = 'Создать каталог';
$this->params['breadcrumbs'][] = ['label' => 'Конструктор каталога', 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>

<div class="catalog-builder-create">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0" style="font-weight:700">
            <i class="fas fa-plus-circle me-2" style="color:var(--accent)"></i><?= Html::encode($this->title) ?>
        </h3>
        <a href="<?= \yii\helpers\Url::to(['index']) ?>" class="btn btn-dark-outline">
            <i class="fas fa-arrow-left me-1"></i> Назад к списку
        </a>
    </div>

    <div class="card">
        <div class="card-header">
            <i class="fas fa-info-circle me-2"></i>Выберите шаблон и поставщиков для сборки каталога
        </div>
        <div class="card-body">
            <?php $form = ActiveForm::begin([
                'options' => ['class' => 'catalog-builder-form'],
            ]); ?>

            <div class="row">
                <div class="col-md-6">
                    <?= $form->field($model, 'name')
                        ->textInput(['maxlength' => true, 'placeholder' => 'Название каталога (необязательно)'])
                        ->label('Название превью')
                        ->hint('Если не указано, будет сгенерировано автоматически') ?>
                </div>
                <div class="col-md-6">
                    <?= $form->field($model, 'template_id')
                        ->dropDownList(
                            \yii\helpers\ArrayHelper::map($templates, 'id', 'name'),
                            ['prompt' => '— Выберите шаблон —', 'required' => true]
                        )
                        ->label('Шаблон каталога')
                        ->hint('Структура категорий для каталога') ?>
                </div>
            </div>

            <div class="row">
                <div class="col-12">
                    <?= $form->field($model, 'supplier_ids')
                        ->checkboxList(
                            \yii\helpers\ArrayHelper::map($suppliers, 'id', 'name'),
                            [
                                'item' => function ($index, $label, $name, $checked, $value) {
                                    $id = 'supplier_' . $value;
                                    return '<div class="form-check mb-2">'
                                        . Html::checkbox($name, $checked, ['id' => $id, 'value' => $value, 'class' => 'form-check-input'])
                                        . Html::label($label, $id, ['class' => 'form-check-label'])
                                        . '</div>';
                                },
                                'class' => 'supplier-checkboxes',
                            ]
                        )
                        ->label('Поставщики')
                        ->hint('Выберите одного или нескольких поставщиков. Товары будут собраны только от выбранных поставщиков.') ?>
                </div>
            </div>

            <div class="form-group mt-4">
                <?= Html::submitButton(
                    '<i class="fas fa-magic me-1"></i> Собрать каталог',
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

    <?php if (!empty($templates)): ?>
    <div class="card mt-4">
        <div class="card-header">
            <i class="fas fa-info-circle me-2"></i>Доступные шаблоны
        </div>
        <div class="card-body">
            <?php foreach ($templates as $template): ?>
                <div class="mb-3 pb-3" style="border-bottom:1px solid var(--border)">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h6 class="mb-1" style="font-weight:600">
                                <?= Html::encode($template->name) ?>
                                <?php if ($template->is_system): ?>
                                    <span class="badge bg-secondary ms-2" style="font-size:.65rem">Системный</span>
                                <?php endif; ?>
                            </h6>
                            <?php if ($template->description): ?>
                                <p class="mb-0" style="color:var(--text-secondary);font-size:.85rem">
                                    <?= Html::encode($template->description) ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</div>
