<?php

/**
 * @var yii\web\View $this
 * @var common\models\CatalogPreview $preview
 * @var string $categoryId
 * @var string $categoryName
 * @var yii\data\ActiveDataProvider $dataProvider
 * @var array $readinessMap [model_id => ModelChannelReadiness]
 */

use yii\grid\GridView;
use yii\helpers\Html;
use yii\helpers\Url;

$this->title = "Товары категории: {$categoryName}";
$this->params['breadcrumbs'][] = ['label' => 'Конструктор каталога', 'url' => ['index']];
$this->params['breadcrumbs'][] = ['label' => $preview->name ?: 'Превью #' . $preview->id, 'url' => ['view', 'id' => $preview->id]];
$this->params['breadcrumbs'][] = $this->title;
?>

<div class="catalog-builder-category-products">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0" style="font-weight:700">
            <i class="fas fa-boxes me-2" style="color:var(--accent)"></i><?= Html::encode($this->title) ?>
        </h3>
        <a href="<?= Url::to(['view', 'id' => $preview->id]) ?>" class="btn btn-dark-outline">
            <i class="fas fa-arrow-left me-1"></i> Назад к предпросмотру
        </a>
    </div>

    <!-- ═══ Массовые действия ═══ -->
    <div class="card mb-4">
        <div class="card-body">
            <form id="bulk-actions-form" method="post" action="<?= Url::to(['/catalog/bulk']) ?>" data-csrf-param="<?= Yii::$app->request->csrfParam ?>" data-csrf-token="<?= Yii::$app->request->csrfToken ?>">
                <div class="row align-items-end">
                    <div class="col-md-4">
                        <label class="form-label" style="font-size:.85rem;font-weight:600;color:var(--text-secondary)">
                            Массовые действия:
                        </label>
                        <select name="action" id="bulk-action-select" class="form-select form-select-sm">
                            <option value="">— Выберите действие —</option>
                            <option value="heal">🪄 Отправить на массовое AI-лечение</option>
                            <option value="recalculate-readiness">♻️ Принудительно пересчитать Readiness</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="button" id="bulk-apply-btn" class="btn btn-accent btn-sm w-100" disabled>
                            <i class="fas fa-check me-1"></i> Применить
                        </button>
                    </div>
                    <div class="col-md-6">
                        <span id="bulk-selected-count" class="text-muted" style="font-size:.85rem">
                            Выбрано: <strong>0</strong> товаров
                        </span>
                    </div>
                </div>
                <input type="hidden" name="model_ids" id="bulk-model-ids" value="">
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <?= GridView::widget([
                'dataProvider' => $dataProvider,
                'tableOptions' => ['class' => 'table table-striped mb-0'],
                'layout' => "{items}\n<div class='card-footer d-flex justify-content-between align-items-center'>{summary}{pager}</div>",
                'columns' => [
                    [
                        'class' => 'yii\grid\CheckboxColumn',
                        'name' => 'selection',
                        'checkboxOptions' => function ($model) {
                            return ['value' => $model->id];
                        },
                    ],
                    [
                        'attribute' => 'id',
                        'headerOptions' => ['style' => 'width:60px'],
                    ],
                    [
                        'label' => 'Фото',
                        'format' => 'raw',
                        'headerOptions' => ['style' => 'width:60px'],
                        'value' => function ($model) {
                            $images = $model->canonical_images;
                            if (is_string($images)) {
                                $images = json_decode($images, true) ?: [];
                            }
                            $firstImage = is_array($images) && !empty($images) ? reset($images) : null;
                            
                            if ($firstImage) {
                                return Html::img($firstImage, [
                                    'style' => 'width:42px;height:42px;object-fit:cover;border-radius:6px;border:1px solid var(--border)',
                                    'alt' => 'Photo',
                                ]);
                            }
                            return '<div style="width:42px;height:42px;border-radius:6px;background:var(--bg-body);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;color:var(--text-secondary);font-size:.9rem"><i class="fas fa-image"></i></div>';
                        },
                    ],
                    [
                        'attribute' => 'product_family',
                        'format' => 'raw',
                        'value' => function ($model) {
                            $labels = \common\enums\ProductFamily::labels();
                            $family = $model->product_family;
                            $label = $labels[$family] ?? ($family === 'unknown' ? 'Неизвестно' : $family);
                            return '<span class="badge-status badge-partial" style="font-size:.7rem">'
                                . Html::encode($label)
                                . '</span>';
                        },
                        'headerOptions' => ['style' => 'width:120px'],
                    ],
                    [
                        'label' => 'Бренд',
                        'format' => 'raw',
                        'value' => function ($model) {
                            $brand = $model->brand;
                            return $brand ? Html::encode($brand->name) : '—';
                        },
                        'headerOptions' => ['style' => 'width:150px'],
                    ],
                    [
                        'attribute' => 'name',
                        'format' => 'raw',
                        'value' => function ($model) {
                            return Html::a(
                                Html::encode($model->name),
                                ['/catalog/view', 'id' => $model->id],
                                ['style' => 'color:var(--accent);text-decoration:none']
                            );
                        },
                    ],
                    [
                        'label' => 'Готовность',
                        'format' => 'raw',
                        'headerOptions' => ['style' => 'width:90px;text-align:center'],
                        'contentOptions' => ['style' => 'text-align:center'],
                        'value' => function ($model) use ($readinessMap) {
                            $readiness = $readinessMap[$model->id] ?? null;
                            if ($readiness) {
                                $score = (int)$readiness->score;
                                $cls = 'bg-danger';
                                if ($score >= 100) $cls = 'bg-success';
                                elseif ($score >= 50) $cls = 'bg-warning';
                                return '<span class="badge ' . $cls . '" style="font-size:.7rem">' . $score . '%</span>';
                            }
                            return '<span class="badge bg-secondary" style="font-size:.7rem">—</span>';
                        },
                    ],
                ],
            ]) ?>
        </div>
    </div>

</div>

<?php
// JavaScript для массовых действий
$this->registerJs(<<<JS
(function() {
    var form = document.getElementById('bulk-actions-form');
    var select = document.getElementById('bulk-action-select');
    var applyBtn = document.getElementById('bulk-apply-btn');
    var modelIdsInput = document.getElementById('bulk-model-ids');
    var selectedCount = document.getElementById('bulk-selected-count');
    
    // Обновление счётчика выбранных товаров
    function updateSelection() {
        var checkboxes = form.querySelectorAll('input[type="checkbox"][name="selection[]"]:checked');
        var count = checkboxes.length;
        var ids = Array.from(checkboxes).map(function(cb) { return cb.value; });
        
        modelIdsInput.value = ids.join(',');
        selectedCount.innerHTML = 'Выбрано: <strong>' + count + '</strong> товаров';
        
        // Активируем кнопку только если выбраны товары и действие
        applyBtn.disabled = (count === 0 || !select.value);
    }
    
    // Слушаем изменения чекбоксов
    form.addEventListener('change', function(e) {
        if (e.target.type === 'checkbox') {
            updateSelection();
        }
    });
    
    // Слушаем изменения выбора действия
    select.addEventListener('change', function() {
        updateSelection();
    });
    
    // Обработка сабмита
    applyBtn.addEventListener('click', function(e) {
        e.preventDefault();
        
        var action = select.value;
        var ids = modelIdsInput.value;
        
        if (!action || !ids) {
            alert('Выберите действие и товары');
            return;
        }
        
        if (!confirm('Применить действие "' + select.options[select.selectedIndex].text + '" к выбранным товарам?')) {
            return;
        }
        
        // Отправляем POST-запрос
        var xhr = new XMLHttpRequest();
        xhr.open('POST', form.action, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        
        xhr.onload = function() {
            if (xhr.status === 200) {
                var response = JSON.parse(xhr.responseText);
                if (response.success) {
                    alert(response.message || 'Действие выполнено успешно');
                    location.reload();
                } else {
                    alert(response.message || 'Ошибка выполнения действия');
                }
            } else {
                alert('Ошибка сервера: ' + xhr.status);
            }
        };
        
        xhr.onerror = function() {
            alert('Ошибка сети');
        };
        
        var csrfParam = form.getAttribute('data-csrf-param') || '';
        var csrfToken = form.getAttribute('data-csrf-token') || '';
        var params = 'action=' + encodeURIComponent(action) 
            + '&model_ids=' + encodeURIComponent(ids);
        if (csrfParam && csrfToken) {
            params += '&' + encodeURIComponent(csrfParam) + '=' + encodeURIComponent(csrfToken);
        }
        xhr.send(params);
    });
    
    // Инициализация
    updateSelection();
})();
JS
, \yii\web\View::POS_READY);
?>
