<?php

/**
 * Sprint 15 — Manual Override: Форма редактирования карточки товара.
 *
 * Позволяет менеджеру вручную изменить описание и атрибуты.
 * Изменения сохраняются в model_data_sources (source_type=manual_override, priority=100),
 * после чего пересчитывается Golden Record, Readiness Score и, если 100%,
 * товар автоматически отправляется в Outbox.
 *
 * @var yii\web\View $this
 * @var common\models\ProductModel $model
 * @var array $currentAttrs  Текущие canonical_attributes
 * @var array $manualData    Данные из manual_override source (если есть)
 */

use yii\helpers\Html;

$this->title = 'Редактирование: ' . $model->name;
$this->params['breadcrumbs'][] = ['label' => 'MDM Каталог', 'url' => ['index']];
$this->params['breadcrumbs'][] = ['label' => "#{$model->id}", 'url' => ['view', 'id' => $model->id]];
$this->params['breadcrumbs'][] = 'Редактирование';

// Ожидаемые ключи атрибутов по семейству (для удобства заполнения)
$familyAttrKeys = [
    'mattress'  => ['height', 'spring_block', 'rigidity', 'max_weight', 'frame_material', 'cover_material', 'warranty_years'],
    'pillow'    => ['height', 'filler', 'cover_material', 'shape', 'orthopedic'],
    'bed'       => ['frame_material', 'max_weight', 'has_storage', 'mechanism', 'headboard_type'],
    'topper'    => ['height', 'filler', 'rigidity', 'cover_material'],
    'blanket'   => ['filler', 'season', 'cover_material', 'density'],
    'base'      => ['frame_material', 'lamella_count', 'adjustment', 'legs_included'],
];

$suggestedKeys = $familyAttrKeys[$model->product_family] ?? [];

// Объединяем: существующие ключи + предложенные (пустые)
$allAttrs = $currentAttrs;
foreach ($suggestedKeys as $key) {
    if (!isset($allAttrs[$key])) {
        $allAttrs[$key] = '';
    }
}
ksort($allAttrs);

$manualDesc = $manualData['description'] ?? null;
$manualShortDesc = $manualData['short_description'] ?? null;
$manualAttrs = $manualData['attributes'] ?? [];
?>

<div class="catalog-update">
    <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
        <div>
            <h3 class="mb-1" style="font-weight:700">
                <i class="fas fa-user-pen me-2" style="color:var(--accent)"></i>
                Manual Override
            </h3>
            <p style="color:var(--text-secondary);margin:0;font-size:.88rem">
                Ручная правка будет сохранена с приоритетом <strong>100</strong> (перекрывает AI=50 и поставщика=30).
            </p>
        </div>
        <a href="<?= \yii\helpers\Url::to(['view', 'id' => $model->id]) ?>" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-arrow-left me-1"></i> К карточке
        </a>
    </div>

    <!-- Инфо-блок: что это за модель -->
    <div class="card mb-4" style="border-left:3px solid var(--accent)">
        <div class="card-body py-3">
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <div>
                    <strong><?= Html::encode($model->name) ?></strong>
                    <span style="color:var(--text-muted);font-size:.82rem;margin-left:6px">ID: <?= $model->id ?></span>
                </div>
                <?php if ($model->brand): ?>
                    <span class="badge bg-secondary"><?= Html::encode($model->brand->canonical_name) ?></span>
                <?php endif; ?>
                <?php if ($model->product_family): ?>
                    <span class="badge bg-secondary"><?= Html::encode($model->product_family) ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <form method="post" action="<?= \yii\helpers\Url::to(['update', 'id' => $model->id]) ?>">
        <input type="hidden" name="<?= Yii::$app->request->csrfParam ?>" value="<?= Yii::$app->request->getCsrfToken() ?>">

        <!-- ═══ Описание ═══ -->
        <div class="card mb-4">
            <div class="card-header">
                <i class="fas fa-align-left me-2"></i>Описание
                <?php if ($manualDesc): ?>
                    <span class="badge-status badge-active ms-2" style="font-size:.6rem">manual override</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label for="desc-field" class="form-label">Описание товара (SEO)</label>
                    <textarea id="desc-field" name="description" class="form-control"
                              rows="8" placeholder="Описание для витрины (1000–1500 символов)"
                              style="font-size:.9rem;line-height:1.6"
                    ><?= Html::encode($model->description ?? '') ?></textarea>
                    <div class="form-text">
                        Текущая длина: <span id="desc-count"><?= mb_strlen($model->description ?? '') ?></span> симв.
                        <?php if ($manualDesc): ?>
                            <span style="color:var(--accent)">· Manual override активен</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="mb-0">
                    <label for="shortdesc-field" class="form-label">Краткое описание</label>
                    <textarea id="shortdesc-field" name="short_description" class="form-control"
                              rows="3" placeholder="Короткое описание (до 300 символов)"
                              style="font-size:.9rem"
                    ><?= Html::encode($model->short_description ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <!-- ═══ Атрибуты ═══ -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <i class="fas fa-sliders me-2"></i>Атрибуты
                    <span class="badge bg-secondary ms-2" id="attrs-count"><?= count(array_filter($currentAttrs, fn($v) => $v !== '')) ?></span>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="add-attr-btn">
                    <i class="fas fa-plus me-1"></i> Добавить
                </button>
            </div>
            <div class="card-body p-0">
                <table class="table mb-0" id="attrs-table" style="font-size:.88rem">
                    <thead>
                    <tr>
                        <th style="width:200px">Ключ</th>
                        <th>Значение</th>
                        <th style="width:60px;text-align:center">Src</th>
                        <th style="width:40px"></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php $idx = 0; foreach ($allAttrs as $key => $val): ?>
                        <?php
                        $isManual = isset($manualAttrs[$key]);
                        $isEmpty = ($val === '' || $val === null);
                        $isSuggested = in_array($key, $suggestedKeys) && $isEmpty;
                        ?>
                        <tr class="attr-row <?= $isEmpty ? 'attr-empty' : '' ?> <?= $isManual ? 'attr-manual' : '' ?>">
                            <td>
                                <input type="text" name="attr_key[]" value="<?= Html::encode($key) ?>"
                                       class="form-control form-control-sm attr-key-input"
                                       style="font-family:monospace;font-size:.82rem"
                                       <?= !$isEmpty ? 'readonly' : '' ?>>
                            </td>
                            <td>
                                <input type="text" name="attr_value[]" value="<?= Html::encode((string)$val) ?>"
                                       class="form-control form-control-sm attr-val-input"
                                       placeholder="<?= $isSuggested ? 'Рекомендуется заполнить' : '' ?>"
                                       style="font-size:.85rem">
                            </td>
                            <td style="text-align:center">
                                <?php if ($isManual): ?>
                                    <i class="fas fa-user-pen" style="color:var(--accent);font-size:.7rem" title="Manual Override"></i>
                                <?php elseif (!$isEmpty): ?>
                                    <i class="fas fa-database" style="color:var(--text-muted);font-size:.7rem" title="Из Golden Record"></i>
                                <?php else: ?>
                                    <i class="fas fa-circle-question" style="color:var(--text-muted);font-size:.6rem;opacity:.3" title="Пусто"></i>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button" class="btn btn-sm btn-link text-danger remove-attr-btn p-0"
                                        title="Удалить" style="font-size:.75rem">
                                    <i class="fas fa-times"></i>
                                </button>
                            </td>
                        </tr>
                    <?php $idx++; endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ═══ Кнопки ═══ -->
        <div class="d-flex justify-content-between align-items-center">
            <div style="font-size:.82rem;color:var(--text-muted)">
                <i class="fas fa-shield-halved me-1"></i>
                Изменения сохраняются в model_data_sources (priority=100).
                Golden Record + Readiness пересчитываются автоматически.
            </div>
            <div class="d-flex gap-2">
                <a href="<?= \yii\helpers\Url::to(['view', 'id' => $model->id]) ?>" class="btn btn-outline-secondary">
                    Отмена
                </a>
                <button type="submit" class="btn btn-accent">
                    <i class="fas fa-save me-1"></i> Сохранить (Manual Override)
                </button>
            </div>
        </div>
    </form>
</div>

<style>
.attr-empty .attr-val-input {
    border-color: rgba(251, 191, 36, .3) !important;
    background: rgba(251, 191, 36, .03) !important;
}
.attr-manual .attr-val-input {
    border-color: rgba(96, 165, 250, .3) !important;
    background: rgba(96, 165, 250, .04) !important;
}
.attr-row:hover {
    background: rgba(255,255,255,.02);
}
.btn-accent {
    background: var(--accent) !important;
    color: #fff !important;
    border: none !important;
}
.btn-accent:hover {
    filter: brightness(1.15);
}
</style>

<?php
$js = <<<JS
// Счётчик символов описания
document.getElementById('desc-field')?.addEventListener('input', function() {
    document.getElementById('desc-count').textContent = this.value.length;
});

// Удаление строки атрибута
document.addEventListener('click', function(e) {
    if (e.target.closest('.remove-attr-btn')) {
        e.target.closest('.attr-row').remove();
    }
});

// Добавление нового атрибута
document.getElementById('add-attr-btn')?.addEventListener('click', function() {
    var tbody = document.querySelector('#attrs-table tbody');
    var tr = document.createElement('tr');
    tr.className = 'attr-row attr-empty';
    tr.innerHTML = '<td><input type="text" name="attr_key[]" class="form-control form-control-sm attr-key-input" style="font-family:monospace;font-size:.82rem" placeholder="ключ"></td>'
        + '<td><input type="text" name="attr_value[]" class="form-control form-control-sm attr-val-input" style="font-size:.85rem" placeholder="значение"></td>'
        + '<td style="text-align:center"><i class="fas fa-plus" style="color:var(--success);font-size:.6rem"></i></td>'
        + '<td><button type="button" class="btn btn-sm btn-link text-danger remove-attr-btn p-0" style="font-size:.75rem"><i class="fas fa-times"></i></button></td>';
    tbody.appendChild(tr);
    tr.querySelector('.attr-key-input').focus();
});
JS;
$this->registerJs($js, \yii\web\View::POS_END);
?>
