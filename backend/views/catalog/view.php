<?php

/** @var yii\web\View $this */
/** @var common\models\ProductModel $model */
/** @var common\models\MediaAsset[] $images */
/** @var common\models\ReferenceVariant[] $variants */
/** @var array $offers  [variant_id => SupplierOffer[]] */
/** @var common\models\SupplierOffer[] $orphanOffers */
/** @var common\models\ModelChannelReadiness|null $readiness */
/** @var common\models\SalesChannel|null $channel */
/** @var common\models\ModelDataSource[] $dataSources */

use common\dto\ReadinessReportDTO;
use common\models\ModelDataSource;
use yii\helpers\Html;

$this->title = $model->name;
$this->params['breadcrumbs'][] = ['label' => 'MDM Каталог', 'url' => ['index']];
$this->params['breadcrumbs'][] = "#{$model->id}";

// Парсим атрибуты
$attrs = [];
if (!empty($model->canonical_attributes)) {
    $attrs = is_string($model->canonical_attributes)
        ? (json_decode($model->canonical_attributes, true) ?: [])
        : (is_array($model->canonical_attributes) ? $model->canonical_attributes : []);
}

$familyLabels = [
    'mattress' => 'Матрас', 'pillow' => 'Подушка', 'bed' => 'Кровать',
    'topper' => 'Топпер', 'blanket' => 'Одеяло', 'base' => 'Основание',
];
?>
<div class="catalog-view">

    <!-- ═══ Header ═══ -->
    <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
        <div>
            <h3 class="mb-1" style="font-weight:700"><?= Html::encode($model->name) ?></h3>
            <div style="color:var(--text-secondary);font-size:.85rem">
                <?= $model->brand ? Html::encode($model->brand->canonical_name) : '' ?>
                <?= $model->product_family ? ' · ' . ($familyLabels[$model->product_family] ?? $model->product_family) : '' ?>
                · <code style="font-size:.78rem"><?= Html::encode($model->slug) ?></code>
                · ID: <?= $model->id ?>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <a href="<?= \yii\helpers\Url::to(['update', 'id' => $model->id]) ?>" class="btn btn-sm btn-accent">
                <i class="fas fa-pen me-1"></i> Редактировать
            </a>

            <?php if ($readiness && !$readiness->is_ready): ?>
                <?= Html::beginForm(['catalog/heal', 'id' => $model->id], 'post', ['style' => 'display:inline']) ?>
                    <?= Html::submitButton(
                        '<i class="fas fa-wand-magic-sparkles me-1"></i> AI Heal',
                        [
                            'class' => 'btn btn-sm',
                            'style' => 'background:rgba(167,139,250,.15);color:var(--purple);border:1px solid rgba(167,139,250,.3)',
                            'title' => 'Принудительное AI-лечение (синхронно)',
                            'data-confirm' => 'Запустить AI-лечение для этой модели?',
                        ]
                    ) ?>
                <?= Html::endForm() ?>
            <?php endif; ?>

            <?= Html::beginForm(['catalog/sync', 'id' => $model->id], 'post', ['style' => 'display:inline']) ?>
                <?= Html::submitButton(
                    '<i class="fas fa-paper-plane me-1"></i> На витрину',
                    [
                        'class' => 'btn btn-sm btn-success',
                        'data-confirm' => 'Отправить товар на витрину?',
                    ]
                ) ?>
            <?= Html::endForm() ?>

            <span class="badge-status badge-<?= $model->status === 'active' ? 'active' : 'draft' ?>" style="font-size:.82rem;padding:5px 14px">
                <?= Html::encode($model->status) ?>
            </span>
        </div>
    </div>

    <!-- ═══ Readiness Score ═══ -->
    <?php if ($readiness): ?>
        <?php
        $score = (int)$readiness->score;
        $isReady = (bool)$readiness->is_ready;
        $pctColor = $isReady ? 'success' : ($score >= 70 ? 'warning' : 'danger');
        $missingFields = $readiness->getMissingList();
        ?>
        <div class="card mb-4" style="border-left:3px solid var(--<?= $pctColor ?>)">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div>
                        <strong style="font-size:1.1rem">
                            <?php if ($isReady): ?>
                                <i class="fas fa-check-circle" style="color:var(--success)"></i> Готова к экспорту
                            <?php else: ?>
                                <i class="fas fa-exclamation-triangle" style="color:var(--<?= $pctColor ?>)"></i> Не готова
                            <?php endif; ?>
                        </strong>
                        <span style="color:var(--text-secondary);font-size:.85rem;margin-left:8px">
                            Канал: <?= $channel ? Html::encode($channel->name) : '—' ?>
                        </span>
                    </div>
                    <div>
                        <strong style="font-size:1.5rem;color:var(--<?= $pctColor ?>)"><?= $score ?>%</strong>
                    </div>
                </div>
                <div class="progress mb-2" style="height:8px">
                    <div class="progress-bar bg-<?= $pctColor ?>" style="width:<?= $score ?>%"></div>
                </div>
                <?php if (!empty($missingFields)): ?>
                    <div style="margin-top:8px">
                        <span style="font-size:.78rem;color:var(--text-secondary)">Не хватает:</span>
                        <?php foreach ($missingFields as $field): ?>
                            <?php
                            $label = ReadinessReportDTO::labelFor($field);
                            $isHealable = !in_array($field, ['required:image', 'required:barcode', 'required:price', 'required:brand']);
                            ?>
                            <span class="badge-status badge-<?= str_starts_with($field, 'recommended:') ? 'partial' : 'pending' ?>"
                                  style="font-size:.68rem;margin:2px"
                                  title="<?= Html::encode($field) ?>">
                                <?= Html::encode($label) ?>
                                <?php if ($isHealable): ?>
                                    <i class="fas fa-wand-magic-sparkles" style="font-size:.55rem;margin-left:2px;color:var(--purple)"></i>
                                <?php endif; ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if ($readiness->last_heal_attempt_at): ?>
                    <div style="font-size:.75rem;color:var(--text-muted);margin-top:4px">
                        <i class="fas fa-robot me-1"></i>
                        Последняя попытка AI: <?= Yii::$app->formatter->asRelativeTime($readiness->last_heal_attempt_at) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- ═══ Left Column: Data ═══ -->
        <div class="col-xl-8">

            <!-- ═══ Core Data ═══ -->
            <div class="card mb-4">
                <div class="card-header"><i class="fas fa-info-circle me-2"></i>Основные данные</div>
                <div class="card-body p-0">
                    <table class="table mb-0">
                        <tr>
                            <td style="width:180px;color:var(--text-secondary);font-weight:500">Семейство</td>
                            <td><?= $familyLabels[$model->product_family] ?? $model->product_family ?></td>
                        </tr>
                        <tr>
                            <td style="color:var(--text-secondary);font-weight:500">Бренд</td>
                            <td><?= $model->brand ? Html::encode($model->brand->canonical_name) : '<span style="color:var(--danger)">— не указан</span>' ?></td>
                        </tr>
                        <tr>
                            <td style="color:var(--text-secondary);font-weight:500">Категория</td>
                            <td><?= $model->category ? Html::encode($model->category->name) : '—' ?></td>
                        </tr>
                        <tr>
                            <td style="color:var(--text-secondary);font-weight:500">Производитель</td>
                            <td><?= Html::encode($model->manufacturer ?: '—') ?></td>
                        </tr>
                        <tr>
                            <td style="color:var(--text-secondary);font-weight:500">Цена</td>
                            <td>
                                <?php if ($model->best_price): ?>
                                    <strong style="font-size:1.15rem;color:var(--success)"><?= number_format($model->best_price, 0, '.', ' ') ?> ₽</strong>
                                    <?php if ($model->price_range_min && $model->price_range_max): ?>
                                        <span style="color:var(--text-secondary);margin-left:8px;font-size:.85rem">
                                            (от <?= number_format($model->price_range_min, 0, '.', ' ') ?> до <?= number_format($model->price_range_max, 0, '.', ' ') ?>)
                                        </span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color:var(--danger)">— нет цены</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td style="color:var(--text-secondary);font-weight:500">Наличие</td>
                            <td>
                                <?= $model->is_in_stock
                                    ? '<span class="badge-status badge-active">В наличии</span>'
                                    : '<span class="badge-status badge-inactive">Нет</span>' ?>
                                <span style="color:var(--text-secondary);font-size:.82rem;margin-left:8px">
                                    <?= $model->variant_count ?> вар. · <?= $model->offer_count ?> офф. · <?= $model->supplier_count ?> пост.
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <td style="color:var(--text-secondary);font-weight:500">Обновлено</td>
                            <td style="font-size:.85rem"><?= Yii::$app->formatter->asDatetime($model->updated_at) ?></td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- ═══ Description ═══ -->
            <div class="card mb-4">
                <div class="card-header">
                    <i class="fas fa-align-left me-2"></i>Описание
                    <?php if (empty($model->description)): ?>
                        <span class="badge-status badge-pending ms-2" style="font-size:.65rem">нет описания</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if ($model->description): ?>
                        <div style="max-height:300px;overflow:auto;line-height:1.6;font-size:.92rem">
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

            <!-- ═══ Attributes ═══ -->
            <div class="card mb-4">
                <div class="card-header">
                    <i class="fas fa-sliders me-2"></i>Атрибуты
                    <span class="badge bg-secondary ms-2"><?= count($attrs) ?></span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($attrs)): ?>
                        <div class="p-4 text-center" style="color:var(--text-muted)">Нет атрибутов</div>
                    <?php else: ?>
                        <table class="table table-striped mb-0" style="font-size:.85rem">
                            <thead>
                            <tr>
                                <th style="width:200px">Ключ</th>
                                <th>Значение</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($attrs as $key => $val): ?>
                                <tr>
                                    <td><code><?= Html::encode($key) ?></code></td>
                                    <td><?= is_array($val) ? '<pre style="margin:0;font-size:.8rem">' . Html::encode(json_encode($val, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) . '</pre>' : Html::encode((string)$val) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ═══ Variants & Pricing ═══ -->
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
                                <th style="text-align:right">Base Price (поставщик)</th>
                                <th style="text-align:right">Retail Price (наценка)</th>
                                <th style="text-align:right">Best Price</th>
                                <th style="text-align:center">Наличие</th>
                                <th style="text-align:center">Офферы</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($variants as $variant): ?>
                                <?php
                                $varOffers = $offers[$variant->id] ?? [];
                                $basePrice = null;
                                $retailPrice = null;
                                foreach ($varOffers as $o) {
                                    if ($o->is_active && $o->price_min > 0) {
                                        if ($basePrice === null || $o->price_min < $basePrice) {
                                            $basePrice = $o->price_min;
                                        }
                                        if (!empty($o->retail_price) && ($retailPrice === null || $o->retail_price < $retailPrice)) {
                                            $retailPrice = $o->retail_price;
                                        }
                                    }
                                }
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= Html::encode($variant->variant_label ?: 'Вариант #' . $variant->id) ?></strong>
                                        <span style="color:var(--text-muted);font-size:.75rem;margin-left:4px">ID:<?= $variant->id ?></span>
                                    </td>
                                    <td>
                                        <?= $variant->gtin
                                            ? '<code style="font-size:.75rem">' . Html::encode($variant->gtin) . '</code>'
                                            : '<span style="color:var(--text-muted)">—</span>' ?>
                                    </td>
                                    <td style="text-align:right">
                                        <?= $basePrice ? number_format($basePrice, 0, '.', ' ') . ' ₽' : '<span style="color:var(--text-muted)">—</span>' ?>
                                    </td>
                                    <td style="text-align:right">
                                        <?php if ($retailPrice): ?>
                                            <strong style="color:var(--success)"><?= number_format($retailPrice, 0, '.', ' ') ?> ₽</strong>
                                            <?php if ($basePrice && $retailPrice > $basePrice): ?>
                                                <span style="font-size:.7rem;color:var(--text-muted);margin-left:2px">
                                                    (+<?= number_format(($retailPrice / $basePrice - 1) * 100, 1) ?>%)
                                                </span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="color:var(--text-muted)">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:right">
                                        <?= $variant->best_price
                                            ? '<strong>' . number_format($variant->best_price, 0, '.', ' ') . ' ₽</strong>'
                                            : '<span style="color:var(--text-muted)">—</span>' ?>
                                    </td>
                                    <td style="text-align:center">
                                        <?= $variant->is_in_stock
                                            ? '<span style="color:var(--success)">✓</span>'
                                            : '<span style="color:var(--danger)">✗</span>' ?>
                                    </td>
                                    <td style="text-align:center">
                                        <span class="badge bg-secondary"><?= count($varOffers) ?></span>
                                    </td>
                                </tr>

                                <!-- Офферы этого варианта (collapsible details) -->
                                <?php if (!empty($varOffers)): ?>
                                    <tr>
                                        <td colspan="7" style="padding:0;border-top:none">
                                            <div style="background:var(--bg-body);padding:8px 20px;font-size:.8rem">
                                                <?php foreach ($varOffers as $o): ?>
                                                    <div class="d-flex justify-content-between align-items-center py-1" style="border-bottom:1px solid var(--border)">
                                                        <div>
                                                            <code style="font-size:.72rem"><?= Html::encode($o->supplier->code ?? '?') ?></code>
                                                            <span style="color:var(--text-secondary);margin-left:4px"><?= Html::encode($o->supplier->name ?? '') ?></span>
                                                            <code style="color:var(--text-muted);font-size:.7rem;margin-left:6px"><?= Html::encode($o->supplier_sku) ?></code>
                                                        </div>
                                                        <div class="d-flex align-items-center gap-3">
                                                            <span>base: <?= $o->price_min ? number_format($o->price_min, 0, '.', ' ') . '₽' : '—' ?></span>
                                                            <span style="color:var(--success)">retail: <?= !empty($o->retail_price) ? number_format($o->retail_price, 0, '.', ' ') . '₽' : '—' ?></span>
                                                            <span class="badge-status badge-<?= $o->is_active ? 'active' : 'inactive' ?>" style="font-size:.6rem">
                                                                <?= $o->is_active ? 'актив' : 'откл' ?>
                                                            </span>
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

            <!-- ═══ Orphan Offers ═══ -->
            <?php if (!empty($orphanOffers)): ?>
                <div class="card mb-4">
                    <div class="card-header" style="color:var(--warning)">
                        <i class="fas fa-unlink me-2"></i>Офферы без варианта
                        <span class="badge bg-warning text-dark ms-2"><?= count($orphanOffers) ?></span>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-striped mb-0" style="font-size:.85rem">
                            <thead>
                            <tr>
                                <th>Поставщик</th>
                                <th>SKU</th>
                                <th style="text-align:right">Цена от</th>
                                <th style="text-align:center">В наличии</th>
                                <th>Метод</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($orphanOffers as $o): ?>
                                <tr>
                                    <td>
                                        <code style="font-size:.75rem"><?= Html::encode($o->supplier->code ?? '?') ?></code>
                                        <span class="ms-1" style="color:var(--text-secondary)"><?= Html::encode($o->supplier->name ?? '') ?></span>
                                    </td>
                                    <td><code><?= Html::encode($o->supplier_sku) ?></code></td>
                                    <td style="text-align:right"><?= $o->price_min ? number_format($o->price_min, 0, '.', ' ') . ' ₽' : '—' ?></td>
                                    <td style="text-align:center"><?= $o->in_stock ? '<span style="color:var(--success)">✓</span>' : '<span style="color:var(--danger)">✗</span>' ?></td>
                                    <td><code style="font-size:.72rem"><?= Html::encode($o->match_method ?? '—') ?></code></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- ═══ Right Column: Images + Data Sources ═══ -->
        <div class="col-xl-4">

            <!-- ═══ Images ═══ -->
            <div class="card mb-4">
                <div class="card-header">
                    <i class="fas fa-images me-2"></i>Фото
                    <span class="badge bg-secondary ms-2"><?= count($images) ?></span>
                </div>
                <div class="card-body">
                    <?php if (empty($images)): ?>
                        <div class="text-center py-4" style="color:var(--text-muted)">
                            <i class="fas fa-image fa-2x mb-2" style="opacity:.3"></i><br>
                            Нет изображений
                        </div>
                    <?php else: ?>
                        <div class="row g-2">
                            <?php foreach (array_slice($images, 0, 8) as $img): ?>
                                <div class="col-6">
                                    <?php
                                    $thumbUrl = $img->getThumbUrl();
                                    $fullUrl = $img->getPublicUrl();
                                    ?>
                                    <?php if ($img->status === 'processed' && $thumbUrl): ?>
                                        <a href="<?= Html::encode($fullUrl) ?>" target="_blank">
                                            <img src="<?= Html::encode($thumbUrl) ?>" alt=""
                                                 style="width:100%;height:120px;object-fit:cover;border-radius:6px;border:1px solid var(--border)" loading="lazy">
                                        </a>
                                    <?php else: ?>
                                        <div style="width:100%;height:120px;display:flex;align-items:center;justify-content:center;background:var(--bg-body);border-radius:6px;border:1px solid var(--border);color:var(--text-muted);font-size:.72rem">
                                            <?= Html::encode($img->status) ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($img->is_primary): ?>
                                        <div style="text-align:center;font-size:.6rem;color:var(--accent);margin-top:2px">PRIMARY</div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if (count($images) > 8): ?>
                            <div class="text-center mt-2" style="color:var(--text-muted);font-size:.82rem">
                                +<?= count($images) - 8 ?> ещё
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ═══ Data Sources ═══ -->
            <div class="card mb-4">
                <div class="card-header">
                    <i class="fas fa-layer-group me-2"></i>Источники данных
                    <span class="badge bg-secondary ms-2"><?= count($dataSources) ?></span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($dataSources)): ?>
                        <div class="p-3 text-center" style="color:var(--text-muted);font-size:.85rem">
                            Нет записей в model_data_sources
                        </div>
                    <?php else: ?>
                        <?php foreach ($dataSources as $ds): ?>
                            <?php
                            $typeLabel = ModelDataSource::sourceTypes()[$ds->source_type] ?? $ds->source_type;
                            $priorityColor = $ds->priority >= 100 ? 'danger' : ($ds->priority >= 50 ? 'purple' : 'text-secondary');
                            $typeIcon = match($ds->source_type) {
                                ModelDataSource::SOURCE_MANUAL => 'fas fa-user-pen',
                                ModelDataSource::SOURCE_AI_ENRICH, ModelDataSource::SOURCE_AI_ATTRS => 'fas fa-robot',
                                ModelDataSource::SOURCE_SUPPLIER => 'fas fa-truck',
                                default => 'fas fa-database',
                            };
                            ?>
                            <div style="padding:10px 16px;border-bottom:1px solid var(--border)">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <i class="<?= $typeIcon ?> me-1" style="color:var(--<?= $priorityColor ?>);font-size:.8rem"></i>
                                        <strong style="font-size:.82rem"><?= Html::encode($typeLabel) ?></strong>
                                        <?php if ($ds->source_id): ?>
                                            <code style="font-size:.7rem;margin-left:4px"><?= Html::encode($ds->source_id) ?></code>
                                        <?php endif; ?>
                                    </div>
                                    <span style="font-size:.7rem;color:var(--<?= $priorityColor ?>)">
                                        P:<?= $ds->priority ?>
                                    </span>
                                </div>
                                <div style="font-size:.72rem;color:var(--text-muted);margin-top:2px">
                                    <?= Yii::$app->formatter->asRelativeTime($ds->updated_at) ?>
                                    <?php
                                    $dataArr = $ds->getDataArray();
                                    $keys = array_keys($dataArr);
                                    if (!empty($keys)):
                                    ?>
                                        · Ключи: <?= Html::encode(implode(', ', array_slice($keys, 0, 5))) ?>
                                        <?= count($keys) > 5 ? '…' : '' ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ═══ Meta ═══ -->
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
