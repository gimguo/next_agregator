<?php

/**
 * @var yii\web\View $this
 * @var common\models\Brand $model
 */

use yii\helpers\Html;
use yii\helpers\Url;
use yii\widgets\DetailView;

$this->title = $model->name;
$this->params['breadcrumbs'][] = ['label' => 'Бренды', 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>

<div class="brand-view">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0" style="font-weight:700">
            <i class="fas fa-tag me-2" style="color:var(--accent)"></i><?= Html::encode($this->title) ?>
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

    <div class="row g-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-info-circle me-2"></i>Информация о бренде
                </div>
                <div class="card-body">
                    <?= DetailView::widget([
                        'model' => $model,
                        'options' => ['class' => 'table detail-view mb-0'],
                        'attributes' => [
                            'id',
                            'name',
                            'slug',
                            [
                                'attribute' => 'is_active',
                                'format' => 'raw',
                                'value' => $model->is_active
                                    ? '<span class="badge bg-success">Активен</span>'
                                    : '<span class="badge bg-secondary">Неактивен</span>',
                            ],
                            [
                                'label' => 'Товаров',
                                'value' => function () use ($model) {
                                    $count = $model->getProductModels()->count();
                                    return $count > 0 
                                        ? Html::a(
                                            number_format($count),
                                            ['/catalog/index', 'ProductModelSearch[brand_id]' => $model->id],
                                            ['style' => 'color:var(--accent)']
                                        )
                                        : '—';
                                },
                                'format' => 'raw',
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
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-list me-2"></i>Алиасы (синонимы и опечатки)</span>
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
                        <ul class="list-group list-group-flush" id="aliases-list">
                            <?php foreach ($aliases as $alias): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center" data-alias-id="<?= $alias->id ?>">
                                    <span><code style="font-size:.85rem"><?= Html::encode($alias->alias) ?></code></span>
                                    <button type="button" class="btn btn-sm btn-link text-danger" onclick="deleteAlias(<?= $alias->id ?>)">
                                        <i class="fas fa-trash"></i>
                                    </button>
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
                        <div class="form-text">Этот алиас будет автоматически резолвиться в бренд "<?= Html::encode($model->name) ?>"</div>
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
JS
, \yii\web\View::POS_READY);
?>
