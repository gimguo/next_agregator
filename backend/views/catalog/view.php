<?php

/**
 * Sprint 15 — PIM Cockpit: Детальная карточка модели.
 *
 * Зоны:
 *   [Header]     — Readiness Score bar + missing fields + action buttons
 *   [Core Data]  — DetailView (бренд, семейство, цена, наличие, мета)
 *   [Description]— Блок описания с source badge
 *   [Attributes] — Schema-driven таблица с типами, source badges, enum labels
 *   [Pricing]    — GridView вариантов: base_price, retail_price, offers drill-down
 *   [Images]     — Миниатюры из MediaAsset
 *   [Sources]    — model_data_sources (timeline)
 *
 * @var yii\web\View $this
 * @var common\models\ProductModel $model
 * @var common\models\MediaAsset[] $images
 * @var common\models\ReferenceVariant[] $variants
 * @var array $offers          [variant_id => SupplierOffer[]]
 * @var common\models\SupplierOffer[] $orphanOffers
 * @var common\models\ModelChannelReadiness|null $readiness
 * @var common\models\SalesChannel|null $channel
 * @var common\models\ModelDataSource[] $dataSources
 * @var array $familySchema    ProductFamilySchema::getSchema() result
 * @var array $attrSourceMap   attr_key → ['type', 'label', 'priority']
 * @var array|null $descSource ['type', 'label', 'priority']
 */

use common\dto\ReadinessReportDTO;
use common\models\ModelDataSource;
use yii\helpers\Html;
use yii\helpers\Url;
use yii\widgets\DetailView;
use yii\widgets\Pjax;

// ═══ Helper: render source badge ═══
if (!function_exists('_sourceBadge')) {
    function _sourceBadge(array $source): string {
        $map = [
            'manual_override' => ['icon' => 'fa-user-pen',  'color' => 'danger',  'short' => 'Manual'],
            'ai_enrichment'   => ['icon' => 'fa-robot',     'color' => 'purple',  'short' => 'AI'],
            'ai_attributes'   => ['icon' => 'fa-robot',     'color' => 'purple',  'short' => 'AI attr'],
            'supplier'        => ['icon' => 'fa-truck',     'color' => 'info',    'short' => 'Supplier'],
        ];
        $cfg = $map[$source['type']] ?? ['icon' => 'fa-database', 'color' => 'text-secondary', 'short' => $source['type']];
        return '<span class="badge-status" style="font-size:.58rem;padding:2px 6px;background:rgba(255,255,255,.04)">'
            . '<i class="fas ' . $cfg['icon'] . '" style="color:var(--' . $cfg['color'] . ');font-size:.55rem;margin-right:2px"></i>'
            . htmlspecialchars($cfg['short'])
            . '<span style="color:var(--text-muted);margin-left:2px">P:' . $source['priority'] . '</span>'
            . '</span>';
    }
}

$this->title = $model->name;
$this->params['breadcrumbs'][] = ['label' => 'MDM Каталог', 'url' => ['index']];
$this->params['breadcrumbs'][] = "#{$model->id}";

// Parse attrs
$attrs = [];
if (!empty($model->canonical_attributes)) {
    $attrs = is_string($model->canonical_attributes)
        ? (json_decode($model->canonical_attributes, true) ?: [])
        : (is_array($model->canonical_attributes) ? $model->canonical_attributes : []);
}

$schemaAttrs = $familySchema['attributes'] ?? [];
$familyLabel = $familySchema['label'] ?? $model->product_family;
?>

<div class="catalog-view">

<?php
// ═══════════════════════════════════════════════════════════════════════
// HEADER ZONE — Readiness Bar + Actions
// ═══════════════════════════════════════════════════════════════════════
?>
<div class="card mb-4" id="readiness-card" style="border-left:3px solid <?php
    if (!$readiness) echo 'var(--border)';
    elseif ($readiness->is_ready) echo 'var(--success)';
    elseif ($readiness->score >= 70) echo 'var(--warning)';
    else echo 'var(--danger)';
?>">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <!-- Left: model info -->
            <div style="flex:1;min-width:300px">
                <h4 class="mb-1" style="font-weight:700"><?= Html::encode($model->name) ?></h4>
                <div style="color:var(--text-secondary);font-size:.85rem" class="mb-3">
                    <?= $model->brand ? Html::encode($model->brand->canonical_name) : '' ?>
                    · <span class="badge bg-secondary" style="font-size:.7rem"><?= Html::encode($familyLabel) ?></span>
                    · <code style="font-size:.75rem"><?= Html::encode($model->slug) ?></code>
                    · ID: <?= $model->id ?>
                    <span class="badge-status badge-<?= $model->status === 'active' ? 'active' : 'draft' ?> ms-2"><?= $model->status ?></span>
                </div>

                <?php if ($readiness): ?>
                    <?php
                    $score    = (int)$readiness->score;
                    $isReady  = (bool)$readiness->is_ready;
                    $barColor = $isReady ? 'success' : ($score >= 70 ? 'warning' : 'danger');
                    $missing  = $readiness->getMissingList();
                    ?>
                    <div class="d-flex align-items-center gap-3 mb-2">
                        <div style="flex:1">
                            <div class="d-flex justify-content-between mb-1">
                                <span style="font-size:.82rem">
                                    <?= $isReady
                                        ? '<i class="fas fa-check-circle" style="color:var(--success)"></i> Готова к экспорту'
                                        : '<i class="fas fa-exclamation-triangle" style="color:var(--' . $barColor . ')"></i> Не готова' ?>
                                    <span style="color:var(--text-muted);font-size:.78rem;margin-left:6px">
                                        <?= $channel ? Html::encode($channel->name) : '' ?>
                                    </span>
                                </span>
                                <strong id="readiness-score" style="color:var(--<?= $barColor ?>)"><?= $score ?>%</strong>
                            </div>
                            <div class="progress" style="height:8px">
                                <div id="readiness-bar" class="progress-bar bg-<?= $barColor ?>" style="width:<?= $score ?>%"></div>
                            </div>
                        </div>
                    </div>

                    <?php if ($missing): ?>
                        <div id="missing-fields-zone" style="margin-top:6px">
                            <span style="font-size:.75rem;color:var(--text-secondary)">Не хватает:</span>
                            <?php foreach ($missing as $field): ?>
                                <?php
                                $lbl = ReadinessReportDTO::labelFor($field);
                                $isHealable = !in_array($field, ['required:image', 'required:barcode', 'required:price', 'required:brand']);
                                $badgeCls = str_starts_with($field, 'recommended:') ? 'badge-partial' : 'badge-pending';
                                ?>
                                <span class="badge-status <?= $badgeCls ?>" style="font-size:.65rem;margin:2px" title="<?= Html::encode($field) ?>">
                                    <?= Html::encode($lbl) ?>
                                    <?php if ($isHealable): ?>
                                        <i class="fas fa-wand-magic-sparkles" style="font-size:.5rem;margin-left:2px;color:var(--purple)"></i>
                                    <?php endif; ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($readiness->last_heal_attempt_at): ?>
                        <div style="font-size:.72rem;color:var(--text-muted);margin-top:4px">
                            <i class="fas fa-robot me-1"></i>
                            AI: <?= Yii::$app->formatter->asRelativeTime($readiness->last_heal_attempt_at) ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div style="color:var(--text-muted);font-size:.85rem">
                        <i class="fas fa-circle-question me-1"></i> Readiness не рассчитан.
                        Выполните <code>quality/scan</code>.
                    </div>
                <?php endif; ?>
            </div>

            <!-- Right: actions -->
            <div class="d-flex flex-column gap-2 align-items-end">
                <a href="<?= Url::to(['update', 'id' => $model->id]) ?>" class="btn btn-sm btn-accent w-100">
                    <i class="fas fa-pen me-1"></i> Редактировать
                </a>

                <?php if ($readiness && !$readiness->is_ready): ?>
                    <button type="button" id="btn-ai-heal" class="btn btn-sm w-100"
                            style="background:rgba(167,139,250,.15);color:var(--purple);border:1px solid rgba(167,139,250,.3)"
                            data-url="<?= Url::to(['heal-ajax', 'id' => $model->id]) ?>">
                        <i class="fas fa-wand-magic-sparkles me-1"></i>
                        <span class="heal-text">🪄 Force AI Heal</span>
                        <span class="heal-spinner d-none"><i class="fas fa-spinner fa-spin"></i> Лечу...</span>
                    </button>
                <?php endif; ?>

                <?= Html::beginForm(['sync', 'id' => $model->id], 'post', ['class' => 'w-100']) ?>
                    <?= Html::submitButton(
                        '<i class="fas fa-paper-plane me-1"></i> На витрину',
                        ['class' => 'btn btn-sm btn-success w-100', 'data-confirm' => 'Отправить?']
                    ) ?>
                <?= Html::endForm() ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
<!-- ═══════════════════════════════════════════════════════════════════════
     LEFT COLUMN
     ═══════════════════════════════════════════════════════════════════ -->
<div class="col-xl-8">

    <?php
    // ═══ CORE DATA (DetailView) ═══
    ?>
    <div class="card mb-4">
        <div class="card-header"><i class="fas fa-info-circle me-2"></i>Основные данные</div>
        <div class="card-body p-0">
            <?= DetailView::widget([
                'model' => $model,
                'options' => ['class' => 'table detail-view mb-0'],
                'attributes' => [
                    [
                        'label' => 'Семейство',
                        'value' => $familyLabel,
                    ],
                    [
                        'label' => 'Бренд',
                        'format' => 'raw',
                        'value' => $model->brand
                            ? Html::encode($model->brand->canonical_name)
                            : '<span style="color:var(--danger)">— не указан</span>',
                    ],
                    [
                        'label' => 'Категория',
                        'value' => $model->category ? $model->category->name : '—',
                    ],
                    [
                        'label' => 'Производитель',
                        'value' => $model->manufacturer ?: '—',
                    ],
                    [
                        'label' => 'Цена',
                        'format' => 'raw',
                        'value' => function () use ($model) {
                            if (!$model->best_price) return '<span style="color:var(--danger)">— нет цены</span>';
                            $s = '<strong style="font-size:1.1rem;color:var(--success)">'
                                . number_format($model->best_price, 0, '.', ' ') . ' ₽</strong>';
                            if ($model->price_range_min && $model->price_range_max) {
                                $s .= ' <span style="color:var(--text-secondary);font-size:.82rem">(от '
                                    . number_format($model->price_range_min, 0, '.', ' ')
                                    . ' до ' . number_format($model->price_range_max, 0, '.', ' ') . ')</span>';
                            }
                            return $s;
                        },
                    ],
                    [
                        'label' => 'Наличие',
                        'format' => 'raw',
                        'value' => ($model->is_in_stock
                                ? '<span class="badge-status badge-active">В наличии</span>'
                                : '<span class="badge-status badge-inactive">Нет</span>')
                            . " <span style='color:var(--text-secondary);font-size:.82rem;margin-left:8px'>"
                            . "{$model->variant_count} вар. · {$model->offer_count} офф. · {$model->supplier_count} пост.</span>",
                    ],
                    'updated_at:datetime:Обновлено',
                ],
            ]) ?>
        </div>
    </div>

    <?php
    // ═══ DESCRIPTION ═══
    ?>
    <div class="card mb-4">
        <div class="card-header">
            <i class="fas fa-align-left me-2"></i>Описание
            <?php if ($descSource): ?>
                <?= _sourceBadge($descSource) ?>
            <?php endif; ?>
            <?php if (empty($model->description)): ?>
                <span class="badge-status badge-pending ms-2" style="font-size:.63rem">нет описания</span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if ($model->description): ?>
                <div style="max-height:300px;overflow:auto;line-height:1.65;font-size:.92rem">
                    <?= nl2br(Html::encode($model->description)) ?>
                </div>
                <div style="font-size:.72rem;color:var(--text-muted);margin-top:8px">
                    <?= mb_strlen($model->description) ?> символов
                </div>
            <?php else: ?>
                <div style="color:var(--text-muted);padding:20px 0;text-align:center">
                    Описание отсутствует. Используйте <strong>AI Heal</strong> или <strong>Редактировать</strong>.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php
    // ═══ ATTRIBUTES (Schema-driven) ═══
    ?>
    <div class="card mb-4">
        <div class="card-header">
            <i class="fas fa-sliders me-2"></i>Атрибуты
            <span class="badge bg-secondary ms-2" style="font-size:.68rem"><?= $familyLabel ?></span>
            <span class="badge bg-secondary ms-1" style="font-size:.68rem"><?= count(array_filter($attrs, fn($v) => $v !== null && $v !== '')) ?> / <?= count($schemaAttrs) ?></span>
        </div>
        <div class="card-body p-0">
            <table class="table table-striped mb-0" style="font-size:.85rem">
                <thead>
                <tr>
                    <th style="width:180px">Атрибут</th>
                    <th>Значение</th>
                    <th style="width:100px;text-align:center">Тип</th>
                    <th style="width:120px;text-align:center">Источник</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($schemaAttrs as $code => $def): ?>
                    <?php
                    $val      = $attrs[$code] ?? null;
                    $isEmpty  = ($val === null || $val === '' || $val === []);
                    $source   = $attrSourceMap[$code] ?? null;
                    $isVariant = $def['variant'] ?? false;
                    $isReq     = $def['required'] ?? false;
                    ?>
                    <tr<?= $isEmpty ? ' style="opacity:.5"' : '' ?>>
                        <td>
                            <strong><?= Html::encode($def['label']) ?></strong>
                            <code style="font-size:.68rem;color:var(--text-muted);display:block"><?= $code ?></code>
                            <?php if ($isVariant): ?>
                                <span style="font-size:.58rem;color:var(--info)">вариантообразующий</span>
                            <?php endif; ?>
                            <?php if ($isReq): ?>
                                <span style="font-size:.58rem;color:var(--danger)">обязательный</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isEmpty): ?>
                                <span style="color:var(--text-muted)">—</span>
                            <?php elseif (is_array($val)): ?>
                                <?php foreach ($val as $item): ?>
                                    <span class="badge bg-secondary" style="font-size:.72rem;margin:1px"><?= Html::encode($item) ?></span>
                                <?php endforeach; ?>
                            <?php elseif (is_bool($val) || $val === 'true' || $val === 'false'): ?>
                                <?php $bv = is_bool($val) ? $val : ($val === 'true'); ?>
                                <?= $bv ? '<i class="fas fa-check" style="color:var(--success)"></i> Да' : '<i class="fas fa-times" style="color:var(--danger)"></i> Нет' ?>
                            <?php elseif (isset($def['enum'])): ?>
                                <span class="badge-status badge-partial" style="font-size:.72rem"><?= Html::encode($val) ?></span>
                                <?php if (isset($def['unit'])): ?>
                                    <span style="color:var(--text-muted);font-size:.72rem"><?= $def['unit'] ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <?= Html::encode((string)$val) ?>
                                <?php if (isset($def['unit'])): ?>
                                    <span style="color:var(--text-muted);font-size:.78rem"><?= $def['unit'] ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center">
                            <span style="font-size:.68rem;color:var(--text-muted)"><?= $def['type'] ?></span>
                        </td>
                        <td style="text-align:center">
                            <?php if ($source): ?>
                                <?= _sourceBadge($source) ?>
                            <?php else: ?>
                                <span style="font-size:.62rem;color:var(--text-muted)">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php
                // Extra attributes not in schema
                $extraAttrs = array_diff_key($attrs, $schemaAttrs);
                foreach ($extraAttrs as $code => $val):
                    if ($val === null || $val === '') continue;
                    $source = $attrSourceMap[$code] ?? null;
                ?>
                    <tr style="opacity:.7">
                        <td>
                            <code style="font-size:.78rem"><?= Html::encode($code) ?></code>
                            <span style="font-size:.58rem;color:var(--warning);display:block">вне схемы</span>
                        </td>
                        <td>
                            <?php if (is_array($val)): ?>
                                <pre style="margin:0;font-size:.75rem"><?= Html::encode(json_encode($val, JSON_UNESCAPED_UNICODE)) ?></pre>
                            <?php else: ?>
                                <?= Html::encode((string)$val) ?>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center"><span style="font-size:.68rem;color:var(--text-muted)">custom</span></td>
                        <td style="text-align:center">
                            <?= $source ? _sourceBadge($source) : '<span style="font-size:.62rem;color:var(--text-muted)">—</span>' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php
    // ═══ PRICING & VARIANTS (GridView-style) ═══
    ?>
    <div class="card mb-4">
        <div class="card-header">
            <i class="fas fa-cubes me-2"></i>Варианты и ценообразование
            <span class="badge bg-secondary ms-2"><?= count($variants) ?></span>
        </div>
        <div class="card-body p-0">
        <?php if (empty($variants)): ?>
            <div class="p-4 text-center" style="color:var(--text-muted)">Нет вариантов</div>
        <?php else: ?>
            <table class="table table-striped mb-0" style="font-size:.85rem">
                <thead>
                <tr>
                    <th>Вариант</th>
                    <th>GTIN</th>
                    <th style="text-align:right">Base</th>
                    <th style="text-align:right">Retail</th>
                    <th style="text-align:right">Best</th>
                    <th style="text-align:center">Наличие</th>
                    <th style="text-align:center">Офф.</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($variants as $v): ?>
                    <?php
                    $vOffers = $offers[$v->id] ?? [];
                    $base = null; $retail = null;
                    foreach ($vOffers as $o) {
                        if ($o->is_active && $o->price_min > 0) {
                            if ($base === null || $o->price_min < $base) $base = $o->price_min;
                            if (!empty($o->retail_price) && ($retail === null || $o->retail_price < $retail)) $retail = $o->retail_price;
                        }
                    }
                    ?>
                    <tr>
                        <td>
                            <strong><?= Html::encode($v->variant_label ?: '#' . $v->id) ?></strong>
                            <span style="color:var(--text-muted);font-size:.72rem;margin-left:4px">ID:<?= $v->id ?></span>
                        </td>
                        <td><?= $v->gtin ? '<code style="font-size:.72rem">' . Html::encode($v->gtin) . '</code>' : '<span style="color:var(--text-muted)">—</span>' ?></td>
                        <td style="text-align:right"><?= $base ? number_format($base, 0, '.', ' ') . ' ₽' : '—' ?></td>
                        <td style="text-align:right">
                            <?php if ($retail): ?>
                                <strong style="color:var(--success)"><?= number_format($retail, 0, '.', ' ') ?> ₽</strong>
                                <?php if ($base && $retail > $base): ?>
                                    <span style="font-size:.65rem;color:var(--text-muted)">(+<?= number_format(($retail / $base - 1) * 100, 1) ?>%)</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="color:var(--text-muted)">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right"><?= $v->best_price ? '<strong>' . number_format($v->best_price, 0, '.', ' ') . ' ₽</strong>' : '—' ?></td>
                        <td style="text-align:center"><?= $v->is_in_stock ? '<span style="color:var(--success)">✓</span>' : '<span style="color:var(--danger)">✗</span>' ?></td>
                        <td style="text-align:center"><span class="badge bg-secondary"><?= count($vOffers) ?></span></td>
                    </tr>

                    <?php if ($vOffers): ?>
                    <tr>
                        <td colspan="7" style="padding:0;border-top:none">
                            <div style="background:var(--bg-body);padding:6px 20px;font-size:.78rem">
                                <?php foreach ($vOffers as $o): ?>
                                    <div class="d-flex justify-content-between align-items-center py-1" style="border-bottom:1px solid var(--border)">
                                        <div>
                                            <code style="font-size:.7rem"><?= Html::encode($o->supplier->code ?? '?') ?></code>
                                            <span style="color:var(--text-secondary);margin-left:4px"><?= Html::encode($o->supplier->name ?? '') ?></span>
                                            <code style="color:var(--text-muted);font-size:.68rem;margin-left:6px"><?= Html::encode($o->supplier_sku) ?></code>
                                        </div>
                                        <div class="d-flex align-items-center gap-3">
                                            <span>base: <?= $o->price_min ? number_format($o->price_min, 0, '.', ' ') . '₽' : '—' ?></span>
                                            <span style="color:var(--success)">retail: <?= $o->retail_price ? number_format($o->retail_price, 0, '.', ' ') . '₽' : '—' ?></span>
                                            <span class="badge-status badge-<?= $o->is_active ? 'active' : 'inactive' ?>" style="font-size:.58rem"><?= $o->is_active ? 'актив' : 'откл' ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        </div>
    </div>

    <?php if ($orphanOffers): ?>
    <div class="card mb-4">
        <div class="card-header" style="color:var(--warning)">
            <i class="fas fa-unlink me-2"></i>Офферы без варианта
            <span class="badge bg-warning text-dark ms-2"><?= count($orphanOffers) ?></span>
        </div>
        <div class="card-body p-0">
            <table class="table table-striped mb-0" style="font-size:.85rem">
                <thead><tr><th>Поставщик</th><th>SKU</th><th style="text-align:right">Цена</th><th style="text-align:center">Наличие</th></tr></thead>
                <tbody>
                <?php foreach ($orphanOffers as $o): ?>
                    <tr>
                        <td><code style="font-size:.72rem"><?= Html::encode($o->supplier->code ?? '?') ?></code> <?= Html::encode($o->supplier->name ?? '') ?></td>
                        <td><code><?= Html::encode($o->supplier_sku) ?></code></td>
                        <td style="text-align:right"><?= $o->price_min ? number_format($o->price_min, 0, '.', ' ') . ' ₽' : '—' ?></td>
                        <td style="text-align:center"><?= $o->in_stock ? '<span style="color:var(--success)">✓</span>' : '<span style="color:var(--danger)">✗</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- ═══════════════════════════════════════════════════════════════════════
     RIGHT COLUMN
     ═══════════════════════════════════════════════════════════════════ -->
<div class="col-xl-4">

    <?php // ═══ IMAGES ═══ ?>
    <div class="card mb-4">
        <div class="card-header">
            <i class="fas fa-images me-2"></i>Фото
            <span class="badge bg-secondary ms-2"><?= count($images) ?></span>
        </div>
        <div class="card-body">
            <?php if (!$images): ?>
                <div class="text-center py-4" style="color:var(--text-muted)">
                    <i class="fas fa-image fa-2x mb-2" style="opacity:.3"></i><br>Нет изображений
                </div>
            <?php else: ?>
                <div class="row g-2">
                    <?php foreach (array_slice($images, 0, 8) as $img): ?>
                        <div class="col-6">
                            <?php $thumb = $img->getThumbUrl(); $full = $img->getPublicUrl(); ?>
                            <?php if ($img->status === 'processed' && $thumb): ?>
                                <a href="<?= Html::encode($full) ?>" target="_blank">
                                    <img src="<?= Html::encode($thumb) ?>" alt=""
                                         style="width:100%;height:110px;object-fit:cover;border-radius:6px;border:1px solid var(--border)" loading="lazy">
                                </a>
                            <?php else: ?>
                                <div style="width:100%;height:110px;display:flex;align-items:center;justify-content:center;background:var(--bg-body);border-radius:6px;border:1px solid var(--border);color:var(--text-muted);font-size:.7rem">
                                    <?= Html::encode($img->status) ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($img->is_primary): ?>
                                <div style="text-align:center;font-size:.58rem;color:var(--accent)">PRIMARY</div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (count($images) > 8): ?>
                    <div class="text-center mt-2" style="color:var(--text-muted);font-size:.8rem">+<?= count($images) - 8 ?> ещё</div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php // ═══ DATA SOURCES TIMELINE ═══ ?>
    <div class="card mb-4">
        <div class="card-header">
            <i class="fas fa-layer-group me-2"></i>Источники данных
            <span class="badge bg-secondary ms-2"><?= count($dataSources) ?></span>
        </div>
        <div class="card-body p-0">
            <?php if (!$dataSources): ?>
                <div class="p-3 text-center" style="color:var(--text-muted);font-size:.85rem">Пусто</div>
            <?php else: ?>
                <?php foreach ($dataSources as $ds): ?>
                    <?php
                    $ico = match($ds->source_type) {
                        ModelDataSource::SOURCE_MANUAL   => 'fas fa-user-pen',
                        ModelDataSource::SOURCE_AI_ENRICH,
                        ModelDataSource::SOURCE_AI_ATTRS  => 'fas fa-robot',
                        ModelDataSource::SOURCE_SUPPLIER  => 'fas fa-truck',
                        default => 'fas fa-database',
                    };
                    $pColor = $ds->priority >= 100 ? 'danger' : ($ds->priority >= 50 ? 'purple' : 'text-secondary');
                    ?>
                    <div style="padding:10px 16px;border-bottom:1px solid var(--border)">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <i class="<?= $ico ?> me-1" style="color:var(--<?= $pColor ?>);font-size:.78rem"></i>
                                <strong style="font-size:.82rem"><?= Html::encode(ModelDataSource::sourceTypes()[$ds->source_type] ?? $ds->source_type) ?></strong>
                                <?php if ($ds->source_id): ?>
                                    <code style="font-size:.68rem;margin-left:4px"><?= Html::encode($ds->source_id) ?></code>
                                <?php endif; ?>
                            </div>
                            <span style="font-size:.68rem;color:var(--<?= $pColor ?>)">P:<?= $ds->priority ?></span>
                        </div>
                        <div style="font-size:.7rem;color:var(--text-muted);margin-top:2px">
                            <?= Yii::$app->formatter->asRelativeTime($ds->updated_at) ?>
                            <?php $keys = array_keys($ds->getDataArray()); ?>
                            <?php if ($keys): ?>
                                · <?= Html::encode(implode(', ', array_slice($keys, 0, 5))) ?><?= count($keys) > 5 ? '…' : '' ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php // ═══ SEO META ═══ ?>
    <div class="card">
        <div class="card-header"><i class="fas fa-tag me-2"></i>SEO Meta</div>
        <div class="card-body" style="font-size:.85rem">
            <div class="mb-2">
                <span style="color:var(--text-secondary)">title:</span>
                <?= $model->meta_title ? Html::encode($model->meta_title) : '<span style="color:var(--text-muted)">—</span>' ?>
            </div>
            <div>
                <span style="color:var(--text-secondary)">description:</span>
                <?= $model->meta_description ? Html::encode(mb_strimwidth($model->meta_description, 0, 120, '...')) : '<span style="color:var(--text-muted)">—</span>' ?>
            </div>
        </div>
    </div>

</div>
</div>
</div>

<?php
// ═══════════════════════════════════════════════════════════════════════
// AI HEAL AJAX
// ═══════════════════════════════════════════════════════════════════════
$csrfParam = Yii::$app->request->csrfParam;
$csrfToken = Yii::$app->request->getCsrfToken();

$js = <<<JS
document.getElementById('btn-ai-heal')?.addEventListener('click', function() {
    var btn = this;
    var url = btn.dataset.url;
    btn.disabled = true;
    btn.querySelector('.heal-text').classList.add('d-none');
    btn.querySelector('.heal-spinner').classList.remove('d-none');

    fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: '{$csrfParam}=' + encodeURIComponent('{$csrfToken}'),
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.querySelector('.heal-text').classList.remove('d-none');
        btn.querySelector('.heal-spinner').classList.add('d-none');

        // Update readiness bar
        if (data.score !== undefined) {
            var bar = document.getElementById('readiness-bar');
            var scoreEl = document.getElementById('readiness-score');
            if (bar) bar.style.width = data.score + '%';
            if (scoreEl) scoreEl.textContent = data.score + '%';

            // Update bar color
            var cls = data.score >= 100 ? 'bg-success' : (data.score >= 70 ? 'bg-warning' : 'bg-danger');
            if (bar) { bar.className = 'progress-bar ' + cls; }
        }

        // Show result as alert
        var alertType = data.success ? 'success' : 'warning';
        var alertHtml = '<div class="alert alert-' + alertType + ' alert-dismissible fade show" role="alert">'
            + '<i class="fas fa-' + (data.success ? 'check-circle' : 'exclamation-triangle') + ' me-2"></i>'
            + data.message
            + (data.healed && data.healed.length ? '<br><small>Вылечено: ' + data.healed.join(', ') + '</small>' : '')
            + (data.newIsReady ? '<br><strong>→ Outbox ✓</strong>' : '')
            + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>'
            + '</div>';

        var card = document.getElementById('readiness-card');
        card.insertAdjacentHTML('afterend', alertHtml);

        // Update missing fields
        if (data.missing && data.missing.length === 0) {
            var zone = document.getElementById('missing-fields-zone');
            if (zone) zone.innerHTML = '<span class="badge-status badge-active" style="font-size:.72rem"><i class="fas fa-check"></i> Все поля заполнены</span>';
            btn.style.display = 'none';
        }

        // If healed, reload after 2s to refresh all data
        if (data.success && data.healed && data.healed.length > 0) {
            setTimeout(function() { location.reload(); }, 2500);
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.querySelector('.heal-text').classList.remove('d-none');
        btn.querySelector('.heal-spinner').classList.add('d-none');
        alert('Ошибка сети: ' + err.message);
    });
});
JS;
$this->registerJs($js, \yii\web\View::POS_END);
?>

