<?php

/** @var yii\web\View $this */
/** @var \common\models\Supplier $model */
/** @var string $title */

use yii\helpers\Html;
use yii\bootstrap5\ActiveForm;

$this->title = $title;
$this->params['breadcrumbs'][] = ['label' => 'Поставщики', 'url' => ['/supplier/index']];
if (!$model->isNewRecord) {
    $this->params['breadcrumbs'][] = ['label' => $model->name, 'url' => ['/supplier/view', 'id' => $model->id]];
}
$this->params['breadcrumbs'][] = $model->isNewRecord ? 'Создание' : 'Редактирование';
?>
<div class="supplier-form">

    <h3 class="mb-4" style="font-weight:700"><?= Html::encode($title) ?></h3>

    <div class="card" style="max-width:700px">
        <div class="card-body">
            <?php $form = ActiveForm::begin([
                'options' => ['class' => 'dark-form'],
            ]); ?>

            <div class="row g-3">
                <div class="col-md-6">
                    <?= $form->field($model, 'name')->textInput(['maxlength' => 255, 'placeholder' => 'Орматек']) ?>
                </div>
                <div class="col-md-6">
                    <?= $form->field($model, 'code')->textInput([
                        'maxlength' => 50,
                        'placeholder' => 'ormatek',
                        'readonly' => !$model->isNewRecord,
                    ])->hint('Латиница, без пробелов. Не изменяется после создания.') ?>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-md-6">
                    <?= $form->field($model, 'format')->dropDownList([
                        'xml' => 'XML',
                        'csv' => 'CSV',
                        'xlsx' => 'Excel (XLSX)',
                        'json' => 'JSON',
                        'yml' => 'YML (Яндекс.Маркет)',
                    ], ['prompt' => '— Выберите —']) ?>
                </div>
                <div class="col-md-6">
                    <?= $form->field($model, 'website')->textInput([
                        'maxlength' => 500,
                        'placeholder' => 'https://ormatek.com',
                    ]) ?>
                </div>
            </div>

            <?= $form->field($model, 'parser_class')->textInput([
                'maxlength' => 255,
                'placeholder' => 'common\components\parsers\OrmatekXmlParser',
            ])->hint('Полный класс парсера (необязательно — определяется автоматически)') ?>

            <?= $form->field($model, 'is_active')->checkbox() ?>

            <div class="mt-3">
                <?= Html::submitButton($model->isNewRecord ? 'Создать' : 'Сохранить', ['class' => 'btn btn-accent']) ?>
                <?= Html::a('Отмена', $model->isNewRecord ? ['/supplier/index'] : ['/supplier/view', 'id' => $model->id], ['class' => 'btn btn-dark-outline ms-2']) ?>
            </div>

            <?php ActiveForm::end(); ?>
        </div>
    </div>
</div>
