<?php

/** @var yii\web\View $this */
/** @var common\models\PricingRule $model */
/** @var string $title */

use common\models\PricingRule;
use yii\helpers\Html;
use yii\widgets\ActiveForm;

$this->title = $title;
$this->params['breadcrumbs'][] = ['label' => 'Наценки', 'url' => ['index']];
$this->params['breadcrumbs'][] = $model->isNewRecord ? 'Новое правило' : $model->name;
?>
<div class="pricing-rule-form">

    <h3 class="mb-4" style="font-weight:700">
        <i class="fas fa-tags" style="color:var(--accent)"></i>
        <?= Html::encode($title) ?>
    </h3>

    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-body dark-form">
                    <?php $form = ActiveForm::begin([
                        'options' => ['class' => 'dark-form'],
                    ]); ?>

                    <?= $form->field($model, 'name')
                        ->textInput(['maxlength' => 255, 'placeholder' => 'Наценка для Орматек 20%'])
                        ->hint('Понятное название для идентификации правила') ?>

                    <div class="row">
                        <div class="col-md-4">
                            <?= $form->field($model, 'target_type')
                                ->dropDownList(PricingRule::targetTypes(), ['prompt' => '— Выберите —']) ?>
                        </div>
                        <div class="col-md-4">
                            <?= $form->field($model, 'target_id')
                                ->textInput(['type' => 'number', 'placeholder' => 'ID поставщика/бренда'])
                                ->hint('Для global/family оставьте пустым') ?>
                        </div>
                        <div class="col-md-4">
                            <?= $form->field($model, 'target_value')
                                ->textInput(['placeholder' => 'mattress, pillow...'])
                                ->hint('Для семейства — строковое значение') ?>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <?= $form->field($model, 'markup_type')
                                ->dropDownList(PricingRule::markupTypes()) ?>
                        </div>
                        <div class="col-md-4">
                            <?= $form->field($model, 'markup_value')
                                ->textInput(['type' => 'number', 'step' => '0.01', 'placeholder' => '20.00'])
                                ->hint('20 = +20% или +20₽') ?>
                        </div>
                        <div class="col-md-4">
                            <?= $form->field($model, 'priority')
                                ->textInput(['type' => 'number', 'placeholder' => '0'])
                                ->hint('Чем выше, тем приоритетнее') ?>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <?= $form->field($model, 'rounding')
                                ->dropDownList(PricingRule::roundingStrategies()) ?>
                        </div>
                        <div class="col-md-4">
                            <?= $form->field($model, 'min_price')
                                ->textInput(['type' => 'number', 'step' => '0.01', 'placeholder' => 'Без ограничения'])
                                ->hint('Мин. базовая цена для применения') ?>
                        </div>
                        <div class="col-md-4">
                            <?= $form->field($model, 'max_price')
                                ->textInput(['type' => 'number', 'step' => '0.01', 'placeholder' => 'Без ограничения'])
                                ->hint('Макс. базовая цена для применения') ?>
                        </div>
                    </div>

                    <?= $form->field($model, 'is_active')->checkbox() ?>

                    <div class="d-flex gap-2 mt-4">
                        <?= Html::submitButton(
                            $model->isNewRecord ? '<i class="fas fa-plus me-1"></i> Создать' : '<i class="fas fa-save me-1"></i> Сохранить',
                            ['class' => 'btn btn-accent']
                        ) ?>
                        <?= Html::a('Отмена', ['index'], ['class' => 'btn btn-dark-outline']) ?>
                    </div>

                    <?php ActiveForm::end(); ?>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-info-circle me-1"></i> Справка
                </div>
                <div class="card-body" style="font-size:.85rem;color:var(--text-secondary)">
                    <p><strong>Приоритет</strong> — при нескольких подходящих правилах применяется самое приоритетное.</p>
                    <p><strong>Типы целей:</strong></p>
                    <ul style="padding-left:18px">
                        <li><strong>Глобальное</strong> — для всех товаров</li>
                        <li><strong>Поставщик</strong> — по ID поставщика</li>
                        <li><strong>Бренд</strong> — по ID бренда</li>
                        <li><strong>Семейство</strong> — mattress, pillow и т.д.</li>
                        <li><strong>Категория</strong> — по ID категории</li>
                    </ul>
                    <p><strong>Пример:</strong> Правило «Бренд Орматек +20%» (приоритет 100) перекроет «Глобальное +15%» (приоритет 10).</p>
                </div>
            </div>
        </div>
    </div>

</div>
