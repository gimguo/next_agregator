<?php

/**
 * @var yii\web\View $this
 * @var common\models\Brand $model
 */

use yii\helpers\Html;
use yii\helpers\Url;
use yii\widgets\ActiveForm;

$this->title = 'Создать бренд';
$this->params['breadcrumbs'][] = ['label' => 'Бренды', 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>

<div class="brand-create">

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
                ->label('Название бренда')
                ->hint('Эталонное название бренда (например, "Орматек")') ?>

            <?= $form->field($model, 'slug')
                ->textInput(['maxlength' => true])
                ->label('Slug')
                ->hint('URL-friendly идентификатор (генерируется автоматически, если не указан)') ?>

            <?= $form->field($model, 'is_active')
                ->checkbox()
                ->label('Активен')
                ->hint('Неактивные бренды не будут использоваться при резолвинге') ?>

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
