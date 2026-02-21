<?php

/**
 * Sprint 15 — Manual Override: форма редактирования с schema-driven атрибутами.
 *
 * Атрибуты рендерятся на основе ProductFamilySchema:
 *   - integer  → input[type=number] с unit-label
 *   - float    → input[type=number, step=0.1]
 *   - boolean  → toggle checkbox
 *   - enum     → <select> с допустимыми значениями
 *   - string   → input[type=text]
 *   - array    → input[type=text] (comma-separated)
 *
 * Возле каждого поля — source badge (AI, Supplier, Manual).
 *
 * @var yii\web\View $this
 * @var common\models\ProductModel $model
 * @var array  $currentAttrs   canonical_attributes
 * @var array  $manualData     manual_override source data
 * @var array  $familySchema   ProductFamilySchema::getSchema()
 * @var array  $attrSourceMap  attr_key → ['type', 'label', 'priority']
 */

use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'Редактирование: ' . $model->name;
$this->params['breadcrumbs'][] = ['label' => 'MDM Каталог', 'url' => ['index']];
$this->params['breadcrumbs'][] = ['label' => "#{$model->id}", 'url' => ['view', 'id' => $model->id]];
$this->params['breadcrumbs'][] = 'Редактирование';

$schemaAttrs  = $familySchema['attributes'] ?? [];
$familyLabel  = $familySchema['label'] ?? $model->product_family;
$manualAttrs  = $manualData['attributes'] ?? [];

// Source badge renderer
$renderSource = function(?array $src): string {
    if (!$src) return '';
    $map = [
        'manual_override' => ['icon' => 'fa-user-pen',  'color' => 'danger',  'short' => 'Manual'],
        'ai_enrichment'   => ['icon' => 'fa-robot',     'color' => 'purple',  'short' => 'AI'],
        'ai_attributes'   => ['icon' => 'fa-robot',     'color' => 'purple',  'short' => 'AI'],
        'supplier'        => ['icon' => 'fa-truck',     'color' => 'info',    'short' => 'Supplier'],
    ];
    $c = $map[$src['type']] ?? ['icon' => 'fa-database', 'color' => 'text-secondary', 'short' => $src['type']];
    return '<span class="src-badge" title="' . htmlspecialchars($src['label'] . ' (P:' . $src['priority'] . ')') . '">'
        . '<i class="fas ' . $c['icon'] . '" style="color:var(--' . $c['color'] . ')"></i>'
        . '</span>';
};
?>

<style>
.attr-row { transition: background .15s; }
.attr-row:hover { background: rgba(255,255,255,.02); }
.attr-row.attr-empty input,
.attr-row.attr-empty select { border-color: rgba(251, 191, 36, .3) !important; }
.attr-row.attr-manual .form-control,
.attr-row.attr-manual .form-select { border-color: rgba(248, 113, 113, .3) !important; }
.src-badge { margin-left: 6px; font-size: .7rem; cursor: help; }
.attr-label-row { display: flex; align-items: center; gap: 6px; }
.attr-meta { font-size: .65rem; color: var(--text-muted); }
.unit-addon { font-size: .78rem; min-width: 36px; }
.section-title { font-weight: 600; font-size: .92rem; margin-bottom: 12px; }
.variant-tag { font-size: .55rem; color: var(--info); border: 1px solid rgba(56,189,248,.2); padding: 0 4px; border-radius: 3px; }
.required-tag { font-size: .55rem; color: var(--danger); border: 1px solid rgba(248,113,113,.2); padding: 0 4px; border-radius: 3px; }
</style>

<div class="catalog-update">

    <!-- ═══ Header ═══ -->
    <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
        <div>
            <h4 class="mb-1" style="font-weight:700">
                <i class="fas fa-user-pen me-2" style="color:var(--accent)"></i>Manual Override
            </h4>
            <p style="color:var(--text-secondary);margin:0;font-size:.85rem">
                Ручная правка → <code>model_data_sources</code> (priority <strong style="color:var(--danger)">100</strong>)
                → Golden Record → Readiness → Outbox
            </p>
        </div>
        <a href="<?= Url::to(['view', 'id' => $model->id]) ?>" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-arrow-left me-1"></i> К карточке
        </a>
    </div>

    <!-- Model info -->
    <div class="card mb-4" style="border-left:3px solid var(--accent)">
        <div class="card-body py-3">
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <strong><?= Html::encode($model->name) ?></strong>
                <span style="color:var(--text-muted);font-size:.82rem">ID: <?= $model->id ?></span>
                <?= $model->brand ? '<span class="badge bg-secondary">' . Html::encode($model->brand->canonical_name) . '</span>' : '' ?>
                <span class="badge bg-secondary"><?= Html::encode($familyLabel) ?></span>
            </div>
        </div>
    </div>

    <form method="post" action="<?= Url::to(['update', 'id' => $model->id]) ?>" id="override-form">
        <input type="hidden" name="<?= Yii::$app->request->csrfParam ?>" value="<?= Yii::$app->request->getCsrfToken() ?>">

        <!-- ═══ Description ═══ -->
        <div class="card mb-4">
            <div class="card-header">
                <i class="fas fa-align-left me-2"></i>Описание
                <?php if (!empty($manualData['description'])): ?>
                    <span class="badge-status badge-error" style="font-size:.55rem;padding:1px 6px;margin-left:6px">manual override</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label for="desc-field" class="form-label">Описание товара (SEO, 1000–1500 символов)</label>
                    <textarea id="desc-field" name="description" class="form-control"
                              rows="8" style="font-size:.9rem;line-height:1.6"
                              placeholder="Продающее SEO-описание для витрины"
                    ><?= Html::encode($model->description ?? '') ?></textarea>
                    <div class="form-text">
                        Длина: <span id="desc-count"><?= mb_strlen($model->description ?? '') ?></span> символов.
                        Рекомендуется 1000–1500.
                    </div>
                </div>
                <div>
                    <label for="shortdesc-field" class="form-label">Краткое описание (до 300 символов)</label>
                    <textarea id="shortdesc-field" name="short_description" class="form-control"
                              rows="3" style="font-size:.9rem"
                              placeholder="Короткое описание для карточки"
                    ><?= Html::encode($model->short_description ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <!-- ═══ Schema-driven Attributes ═══ -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <i class="fas fa-sliders me-2"></i>Атрибуты
                    <span class="badge bg-secondary ms-2"><?= Html::encode($familyLabel) ?></span>
                    <span class="badge bg-secondary ms-1" id="filled-count"><?= count(array_filter($currentAttrs, fn($v) => $v !== null && $v !== '' && $v !== [])) ?> / <?= count($schemaAttrs) ?></span>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="add-custom-attr">
                    <i class="fas fa-plus me-1"></i> Добавить поле
                </button>
            </div>
            <div class="card-body p-0">
                <table class="table mb-0" id="attrs-table" style="font-size:.88rem">
                    <thead>
                    <tr>
                        <th style="width:210px">Атрибут</th>
                        <th>Значение</th>
                        <th style="width:50px;text-align:center">Src</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($schemaAttrs as $code => $def):
                        $val       = $currentAttrs[$code] ?? null;
                        $isEmpty   = ($val === null || $val === '' || $val === []);
                        $isManual  = isset($manualAttrs[$code]);
                        $source    = $attrSourceMap[$code] ?? null;
                        $isVariant = $def['variant'] ?? false;
                        $isReq     = $def['required'] ?? false;
                        $type      = $def['type'];
                        $unit      = $def['unit'] ?? null;
                    ?>
                    <tr class="attr-row <?= $isEmpty ? 'attr-empty' : '' ?> <?= $isManual ? 'attr-manual' : '' ?>">
                        <td>
                            <div class="attr-label-row">
                                <strong><?= Html::encode($def['label']) ?></strong>
                                <?php if ($isVariant): ?><span class="variant-tag">вар.</span><?php endif; ?>
                                <?php if ($isReq): ?><span class="required-tag">обяз.</span><?php endif; ?>
                            </div>
                            <code class="attr-meta"><?= $code ?></code>
                            <span class="attr-meta"> · <?= $type ?></span>
                        </td>
                        <td>
                            <input type="hidden" name="attr_key[]" value="<?= Html::encode($code) ?>">

                            <?php if ($type === 'boolean'): ?>
                                <!-- Boolean → toggle -->
                                <div class="form-check form-switch">
                                    <input type="hidden" name="attr_value[]" value="<?= $val ? 'true' : 'false' ?>" class="bool-hidden">
                                    <input type="checkbox" class="form-check-input bool-toggle"
                                           <?= ($val && $val !== 'false') ? 'checked' : '' ?>
                                           style="cursor:pointer">
                                    <label class="form-check-label" style="font-size:.82rem;color:var(--text-secondary)">
                                        <?= ($val && $val !== 'false') ? 'Да' : 'Нет' ?>
                                    </label>
                                </div>

                            <?php elseif ($type === 'enum' && !empty($def['enum'])): ?>
                                <!-- Enum → select -->
                                <select name="attr_value[]" class="form-select form-select-sm">
                                    <option value="">— не выбрано —</option>
                                    <?php foreach ($def['enum'] as $opt): ?>
                                        <option value="<?= Html::encode($opt) ?>" <?= (string)$val === (string)$opt ? 'selected' : '' ?>>
                                            <?= Html::encode($opt) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                            <?php elseif ($type === 'integer'): ?>
                                <!-- Integer → number input -->
                                <div class="input-group input-group-sm">
                                    <input type="number" name="attr_value[]" class="form-control"
                                           value="<?= Html::encode($val ?? '') ?>"
                                           step="1" placeholder="0">
                                    <?php if ($unit): ?>
                                        <span class="input-group-text unit-addon"><?= Html::encode($unit) ?></span>
                                    <?php endif; ?>
                                </div>

                            <?php elseif ($type === 'float'): ?>
                                <!-- Float → number input -->
                                <div class="input-group input-group-sm">
                                    <input type="number" name="attr_value[]" class="form-control"
                                           value="<?= Html::encode($val ?? '') ?>"
                                           step="0.1" placeholder="0.0">
                                    <?php if ($unit): ?>
                                        <span class="input-group-text unit-addon"><?= Html::encode($unit) ?></span>
                                    <?php endif; ?>
                                </div>

                            <?php elseif ($type === 'array'): ?>
                                <!-- Array → text (comma separated) -->
                                <input type="text" name="attr_value[]" class="form-control form-control-sm"
                                       value="<?= Html::encode(is_array($val) ? implode(', ', $val) : ($val ?? '')) ?>"
                                       placeholder="значение1, значение2, ...">
                                <div class="form-text" style="font-size:.68rem">Через запятую</div>

                            <?php else: ?>
                                <!-- String (default) -->
                                <input type="text" name="attr_value[]" class="form-control form-control-sm"
                                       value="<?= Html::encode($val ?? '') ?>"
                                       placeholder="<?= Html::encode($def['label']) ?>">
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center">
                            <?= $source ? $renderSource($source) : '' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>

                    <?php
                    // Extra attributes not in schema
                    $extraAttrs = array_diff_key($currentAttrs, $schemaAttrs);
                    foreach ($extraAttrs as $code => $val):
                        if ($val === null || $val === '') continue;
                        $source = $attrSourceMap[$code] ?? null;
                    ?>
                    <tr class="attr-row attr-custom">
                        <td>
                            <input type="text" name="attr_key[]" value="<?= Html::encode($code) ?>"
                                   class="form-control form-control-sm" style="font-family:monospace;font-size:.78rem"
                                   readonly>
                            <span class="attr-meta" style="color:var(--warning)">вне схемы</span>
                        </td>
                        <td>
                            <input type="text" name="attr_value[]" value="<?= Html::encode(is_array($val) ? json_encode($val, JSON_UNESCAPED_UNICODE) : (string)$val) ?>"
                                   class="form-control form-control-sm">
                        </td>
                        <td style="text-align:center">
                            <?= $source ? $renderSource($source) : '' ?>
                            <button type="button" class="btn btn-sm btn-link text-danger remove-attr p-0" title="Удалить" style="font-size:.7rem;display:block;margin:2px auto 0">
                                <i class="fas fa-times"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ═══ Submit ═══ -->
        <div class="d-flex justify-content-between align-items-center">
            <div style="font-size:.8rem;color:var(--text-muted)">
                <i class="fas fa-shield-halved me-1"></i>
                Изменения: <code>model_data_sources</code> (P:100) → Golden Record → Readiness → Outbox
            </div>
            <div class="d-flex gap-2">
                <a href="<?= Url::to(['view', 'id' => $model->id]) ?>" class="btn btn-outline-secondary">Отмена</a>
                <button type="submit" class="btn btn-accent">
                    <i class="fas fa-save me-1"></i> Сохранить (Manual Override)
                </button>
            </div>
        </div>
    </form>
</div>

<?php
$js = <<<'JS'
// Character counter
document.getElementById('desc-field')?.addEventListener('input', function() {
    document.getElementById('desc-count').textContent = this.value.length;
});

// Boolean toggles
document.querySelectorAll('.bool-toggle').forEach(function(cb) {
    cb.addEventListener('change', function() {
        var hidden = this.closest('.form-check').querySelector('.bool-hidden');
        var label = this.closest('.form-check').querySelector('.form-check-label');
        hidden.value = this.checked ? 'true' : 'false';
        label.textContent = this.checked ? 'Да' : 'Нет';
    });
});

// Remove custom attr
document.addEventListener('click', function(e) {
    if (e.target.closest('.remove-attr')) {
        e.target.closest('.attr-row').remove();
    }
});

// Add custom attribute
document.getElementById('add-custom-attr')?.addEventListener('click', function() {
    var tbody = document.querySelector('#attrs-table tbody');
    var tr = document.createElement('tr');
    tr.className = 'attr-row attr-custom attr-empty';
    tr.innerHTML = '<td>'
        + '<input type="text" name="attr_key[]" class="form-control form-control-sm" style="font-family:monospace;font-size:.78rem" placeholder="ключ_атрибута">'
        + '<span class="attr-meta" style="color:var(--success)">новое поле</span>'
        + '</td>'
        + '<td><input type="text" name="attr_value[]" class="form-control form-control-sm" placeholder="значение"></td>'
        + '<td style="text-align:center"><button type="button" class="btn btn-sm btn-link text-danger remove-attr p-0" style="font-size:.7rem"><i class="fas fa-times"></i></button></td>';
    tbody.appendChild(tr);
    tr.querySelector('input').focus();
});
JS;
$this->registerJs($js, \yii\web\View::POS_END);
?>
