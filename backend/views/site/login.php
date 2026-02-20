<?php

/** @var yii\web\View $this */
/** @var yii\bootstrap5\ActiveForm $form */
/** @var \common\models\LoginForm $model */

use yii\bootstrap5\ActiveForm;
use yii\bootstrap5\Html;

$this->title = 'Вход';
?>
<div class="login-page">
    <div class="login-box">
        <h1><span style="color:#4f8cff">&#9670;</span> Агрегатор</h1>
        <p class="login-sub">Панель управления каталогом</p>

        <?php $form = ActiveForm::begin(['id' => 'login-form']); ?>

        <?= $form->field($model, 'username')
            ->textInput(['autofocus' => true, 'placeholder' => 'Логин'])
            ->label('Имя пользователя') ?>

        <?= $form->field($model, 'password')
            ->passwordInput(['placeholder' => 'Пароль'])
            ->label('Пароль') ?>

        <?= $form->field($model, 'rememberMe')->checkbox([
            'template' => '<div class="form-check">{input} {label}</div>{error}',
        ])->label('Запомнить меня') ?>

        <div class="form-group mt-3">
            <?= Html::submitButton('Войти', ['class' => 'btn btn-primary', 'name' => 'login-button']) ?>
        </div>

        <?php ActiveForm::end(); ?>
    </div>
</div>
