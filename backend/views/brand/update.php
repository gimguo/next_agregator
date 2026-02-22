<?php

/**
 * @var yii\web\View $this
 * @var common\models\Brand $model
 */

use yii\helpers\Html;
use yii\helpers\Url;
use yii\widgets\ActiveForm;

$this->title = 'Редактировать бренд: ' . $model->name;
$this->params['breadcrumbs'][] = ['label' => 'Бренды', 'url' => ['index']];
$this->params['breadcrumbs'][] = ['label' => $model->name, 'url' => ['view', 'id' => $model->id]];
$this->params['breadcrumbs'][] = 'Редактировать';
?>

<div class="brand-update">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0" style="font-weight:700">
            <i class="fas fa-edit me-2" style="color:var(--accent)"></i><?= Html::encode($this->title) ?>
        </h3>
        <a href="<?= Url::to(['view', 'id' => $model->id]) ?>" class="btn btn-dark-outline">
            <i class="fas fa-arrow-left me-1"></i> Назад
        </a>
    </div>

    <div class="row g-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-info-circle me-2"></i>Основная информация
                </div>
                <div class="card-body">
                    <?php $form = ActiveForm::begin(); ?>

                    <?= $form->field($model, 'name')
                        ->textInput(['maxlength' => true])
                        ->label('Название бренда')
                        ->hint('Эталонное название бренда') ?>

                    <?= $form->field($model, 'slug')
                        ->textInput(['maxlength' => true])
                        ->label('Slug')
                        ->hint('URL-friendly идентификатор') ?>

                    <?= $form->field($model, 'is_active')
                        ->checkbox()
                        ->label('Активен') ?>

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
        <div class="col-md-6">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-list me-2"></i>Алиасы</span>
                    <button type="button" class="btn btn-sm btn-accent" onclick="showAddAliasModal()">
                        <i class="fas fa-plus me-1"></i> Добавить
                    </button>
                </div>
                <div class="card-body">
                    <?php
                    $aliases = $model->getAliases()->orderBy(['alias' => SORT_ASC])->all();
                    if (empty($aliases)):
                    ?>
                        <div class="text-center py-3" style="color:var(--text-muted)">
                            <i class="fas fa-inbox fa-2x mb-2" style="opacity:.3"></i><br>
                            Нет алиасов
                        </div>
                    <?php else: ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($aliases as $alias): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span><code style="font-size:.85rem"><?= Html::encode($alias->alias) ?></code></span>
                                    <div>
                                        <select class="form-select form-select-sm d-inline-block" style="width:auto;margin-right:8px" 
                                                onchange="moveAlias(<?= $alias->id ?>, this.value)">
                                            <option value="">Переместить к...</option>
                                            <?php
                                            $allBrands = \common\models\Brand::find()
                                                ->where(['!=', 'id', $model->id])
                                                ->orderBy(['name' => SORT_ASC])
                                                ->all();
                                            foreach ($allBrands as $brand):
                                            ?>
                                                <option value="<?= $brand->id ?>"><?= Html::encode($brand->name) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" class="btn btn-sm btn-link text-danger" onclick="deleteAlias(<?= $alias->id ?>)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- Модальное окно для добавления алиаса -->
<div class="modal fade" id="addAliasModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Добавить алиас</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="add-alias-form">
                    <input type="hidden" name="brand_id" value="<?= $model->id ?>">
                    <div class="mb-3">
                        <label class="form-label">Алиас (синоним/опечатка)</label>
                        <input type="text" name="alias" class="form-control" required placeholder="Например: Орматэк, Ormatek">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-dark-outline" data-bs-dismiss="modal">Отмена</button>
                <button type="button" class="btn btn-accent" onclick="submitAddAlias()">Добавить</button>
            </div>
        </div>
    </div>
</div>

<?php
$this->registerJs(<<<JS
function showAddAliasModal() {
    var modal = new bootstrap.Modal(document.getElementById('addAliasModal'));
    modal.show();
}

function submitAddAlias() {
    var form = document.getElementById('add-alias-form');
    var formData = new FormData(form);
    
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '<?= Url::to(['add-alias']) ?>', true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    
    xhr.onload = function() {
        var response = JSON.parse(xhr.responseText);
        if (response.success) {
            location.reload();
        } else {
            alert(response.message || 'Ошибка добавления алиаса');
        }
    };
    
    xhr.send(formData);
}

function deleteAlias(aliasId) {
    if (!confirm('Удалить алиас?')) return;
    
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '<?= Url::to(['delete-alias']) ?>?id=' + aliasId, true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    
    xhr.onload = function() {
        var response = JSON.parse(xhr.responseText);
        if (response.success) {
            location.reload();
        } else {
            alert(response.message || 'Ошибка удаления алиаса');
        }
    };
    
    xhr.send();
}

function moveAlias(aliasId, newBrandId) {
    if (!newBrandId) return;
    if (!confirm('Переместить алиас к другому бренду?')) {
        location.reload(); // Сбрасываем select
        return;
    }
    
    var formData = new FormData();
    formData.append('alias_id', aliasId);
    formData.append('new_brand_id', newBrandId);
    
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '<?= Url::to(['move-alias']) ?>', true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    
    xhr.onload = function() {
        var response = JSON.parse(xhr.responseText);
        if (response.success) {
            location.reload();
        } else {
            alert(response.message || 'Ошибка перемещения алиаса');
            location.reload();
        }
    };
    
    xhr.send(formData);
}
JS
, \yii\web\View::POS_READY);
?>
