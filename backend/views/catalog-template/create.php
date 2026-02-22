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
            <?= $this->render('_form', ['model' => $model, 'isSystem' => false]) ?>
            
            <div class="mt-3">
                <?= Html::a(
                    'Отмена',
                    ['index'],
                    ['class' => 'btn btn-dark-outline']
                ) ?>
            </div>
        </div>
    </div>

</div>
