<?php

/** @var yii\web\View $this */
/** @var common\models\ProductModel $model */
/** @var common\models\MediaAsset[] $images */
/** @var common\models\ReferenceVariant[] $variants */
/** @var array $offers  [variant_id => SupplierOffer[]] */
/** @var common\models\SupplierOffer[] $orphanOffers */

use yii\helpers\Html;
use yii\widgets\DetailView;

$this->title = $model->name;
$this->params['breadcrumbs'][] = ['label' => 'MDM Каталог', 'url' => ['index']];
$this->params['breadcrumbs'][] = "#{$model->id}";
?>
<div class="catalog-view">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0" style="font-weight:700"><?= Html::encode($model->name) ?></h3>
        <div>
            <span class="badge-status badge-<?= $model->status === 'active' ? 'active' : 'draft' ?> me-2">
                <?= Html::encode($model->status) ?>
            </span>
            <span class="text-secondary" style="font-size:.85rem">ID: <?= $model->id ?></span>
        </div>
    </div>

    <!-- ═══ Detail View ═══ -->
    <div class="card mb-4">
        <div class="card-header">Основные данные</div>
        <div class="card-body p-0">
            <?= DetailView::widget([
                'model' => $model,
                'options' => ['class' => 'table table-striped detail-view mb-0'],
                'attributes' => [
                    'id',
                    [
                        'attribute' => 'product_family',
                        'value' => function ($model) {
                            $labels = [
                                'mattress' => 'Матрас',
                                'pillow' => 'Подушка',
                                'bed' => 'Кровать',
                                'topper' => 'Топпер',
                                'blanket' => 'Одеяло',
                                'base' => 'Основание',
                            ];
                            return $labels[$model->product_family] ?? $model->product_family;
                        },
                    ],
                    [
                        'label' => 'Бренд',
                        'value' => $model->brand ? $model->brand->canonical_name : '—',
                    ],
                    [
                        'label' => 'Категория',
                        'value' => $model->category ? $model->category->name : '—',
                    ],
                    'manufacturer',
                    'model_name',
                    'slug',
                    [
                        'attribute' => 'best_price',
                        'format' => 'raw',
                        'value' => $model->best_price
                            ? '<strong>' . number_format($model->best_price, 0, '.', ' ') . ' &#8381;</strong>'
                            . ($model->price_range_min && $model->price_range_max
                                ? ' <span style="color:var(--text-secondary)">(от ' . number_format($model->price_range_min, 0, '.', ' ') . ' до ' . number_format($model->price_range_max, 0, '.', ' ') . ')</span>'
                                : '')
                            : '—',
                    ],
                    'variant_count',
                    'offer_count',
                    'supplier_count',
                    [
                        'attribute' => 'is_in_stock',
                        'format' => 'raw',
                        'value' => $model->is_in_stock
                            ? '<span class="badge-status badge-active">В наличии</span>'
                            : '<span class="badge-status badge-inactive">Нет в наличии</span>',
                    ],
                    'quality_score',
                    [
                        'attribute' => 'canonical_attributes',
                        'format' => 'raw',
                        'value' => function ($model) {
                            $attrs = $model->canonical_attributes;
                            if (is_string($attrs)) {
                                $attrs = json_decode($attrs, true);
                            }
                            if (empty($attrs)) return '—';
                            return '<pre style="background:var(--bg-dark);color:var(--text-primary);padding:10px;border-radius:6px;margin:0;max-height:300px;overflow:auto;font-size:.82rem">'
                                . Html::encode(json_encode($attrs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))
                                . '</pre>';
                        },
                    ],
                    [
                        'attribute' => 'description',
                        'format' => 'raw',
                        'value' => $model->description
                            ? '<div style="max-height:200px;overflow:auto">' . Html::encode($model->description) . '</div>'
                            : '—',
                    ],
                    'meta_title',
                    'created_at:datetime',
                    'updated_at:datetime',
                ],
            ]) ?>
        </div>
    </div>

    <!-- ═══ Images ═══ -->
    <div class="card mb-4">
        <div class="card-header">
            Изображения
            <span class="badge bg-secondary ms-2"><?= count($images) ?></span>
        </div>
        <div class="card-body">
            <?php if (empty($images)): ?>
                <div class="text-center" style="color:var(--text-secondary);padding:30px 0">
                    Нет изображений для этой модели
                </div>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($images as $image): ?>
                        <div class="col-xl-2 col-lg-3 col-md-4 col-6">
                            <div style="background:var(--bg-dark);border:1px solid var(--border);border-radius:8px;overflow:hidden;position:relative">
                                <?php
                                $thumbUrl = $image->getThumbUrl();
                                $fullUrl = $image->getPublicUrl();
                                ?>
                                <?php if ($image->status === 'processed' && $thumbUrl): ?>
                                    <a href="<?= Html::encode($fullUrl) ?>" target="_blank">
                                        <img src="<?= Html::encode($thumbUrl) ?>"
                                             alt="Image #<?= $image->id ?>"
                                             style="width:100%;height:150px;object-fit:cover;display:block"
                                             loading="lazy">
                                    </a>
                                <?php else: ?>
                                    <div style="width:100%;height:150px;display:flex;align-items:center;justify-content:center;color:var(--text-secondary)">
                                        <?php if ($image->status === 'error'): ?>
                                            <span style="color:var(--danger)">Error</span>
                                        <?php elseif ($image->status === 'pending'): ?>
                                            <span>Pending...</span>
                                        <?php else: ?>
                                            <span><?= Html::encode($image->status) ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <div style="padding:6px 8px;font-size:.72rem;color:var(--text-secondary)">
                                    <?php if ($image->is_primary): ?>
                                        <span class="badge bg-primary" style="font-size:.65rem">PRIMARY</span>
                                    <?php endif; ?>
                                    <span class="badge-status badge-<?= $image->status === 'processed' ? 'active' : ($image->status === 'error' ? 'failed' : 'pending') ?>"
                                          style="font-size:.65rem">
                                        <?= $image->status ?>
                                    </span>
                                    <?php if ($image->size_bytes): ?>
                                        <br><?= Yii::$app->formatter->asShortSize($image->size_bytes) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ═══ Variants ═══ -->
    <div class="card mb-4">
        <div class="card-header">
            Варианты (Reference Variants)
            <span class="badge bg-secondary ms-2"><?= count($variants) ?></span>
        </div>
        <div class="card-body p-0">
            <?php if (empty($variants)): ?>
                <div class="text-center p-4" style="color:var(--text-secondary)">
                    Нет вариантов для этой модели
                </div>
            <?php else: ?>
                <?php foreach ($variants as $variant): ?>
                    <div style="border-bottom:1px solid var(--border);padding:16px 20px">
                        <!-- Variant Header -->
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div>
                                <strong style="font-size:1.05rem"><?= Html::encode($variant->variant_label ?: 'Вариант #' . $variant->id) ?></strong>
                                <span class="text-secondary ms-2" style="font-size:.82rem">ID: <?= $variant->id ?></span>
                                <?php if ($variant->gtin): ?>
                                    <span class="ms-2" style="font-size:.82rem;color:var(--info)">GTIN: <?= Html::encode($variant->gtin) ?></span>
                                <?php endif; ?>
                                <?php if ($variant->mpn): ?>
                                    <span class="ms-2" style="font-size:.82rem;color:var(--info)">MPN: <?= Html::encode($variant->mpn) ?></span>
                                <?php endif; ?>
                            </div>
                            <div style="text-align:right">
                                <?php if ($variant->best_price): ?>
                                    <strong style="color:var(--success)"><?= number_format($variant->best_price, 0, '.', ' ') ?> &#8381;</strong>
                                <?php endif; ?>
                                <span class="badge-status badge-<?= $variant->is_in_stock ? 'active' : 'inactive' ?> ms-2">
                                    <?= $variant->is_in_stock ? 'В наличии' : 'Нет' ?>
                                </span>
                            </div>
                        </div>

                        <!-- Variant Attributes -->
                        <?php
                        $attrs = $variant->getAttrs();
                        if (!empty($attrs)):
                        ?>
                            <div class="mb-2">
                                <pre style="background:var(--bg-dark);color:var(--text-primary);padding:8px 12px;border-radius:6px;margin:0;font-size:.8rem;display:inline-block"><?= Html::encode(json_encode($attrs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
                            </div>
                        <?php endif; ?>

                        <!-- Offers for this variant -->
                        <?php
                        $variantOffers = $offers[$variant->id] ?? [];
                        if (!empty($variantOffers)):
                        ?>
                            <table class="table table-striped mb-0" style="font-size:.85rem">
                                <thead>
                                <tr>
                                    <th>Поставщик</th>
                                    <th>SKU</th>
                                    <th style="text-align:right">Цена от</th>
                                    <th style="text-align:right">Цена до</th>
                                    <th style="text-align:center">В наличии</th>
                                    <th style="text-align:center">Уверенность</th>
                                    <th>Активен</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($variantOffers as $offer): ?>
                                    <tr>
                                        <td>
                                            <?php if ($offer->supplier): ?>
                                                <code><?= Html::encode($offer->supplier->code) ?></code>
                                                <span class="ms-1" style="color:var(--text-secondary)"><?= Html::encode($offer->supplier->name) ?></span>
                                            <?php else: ?>
                                                <span style="color:var(--text-secondary)">supplier_id=<?= $offer->supplier_id ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><code><?= Html::encode($offer->supplier_sku) ?></code></td>
                                        <td style="text-align:right">
                                            <?= $offer->price_min ? number_format($offer->price_min, 0, '.', ' ') . ' &#8381;' : '—' ?>
                                        </td>
                                        <td style="text-align:right">
                                            <?= $offer->price_max ? number_format($offer->price_max, 0, '.', ' ') . ' &#8381;' : '—' ?>
                                        </td>
                                        <td style="text-align:center">
                                            <?= $offer->in_stock
                                                ? '<span style="color:var(--success)">&#10003;</span>'
                                                : '<span style="color:var(--danger)">&#10007;</span>' ?>
                                        </td>
                                        <td style="text-align:center">
                                            <?php if ($offer->match_confidence): ?>
                                                <span style="color:<?= $offer->match_confidence >= 0.8 ? 'var(--success)' : ($offer->match_confidence >= 0.5 ? 'var(--warning)' : 'var(--danger)') ?>">
                                                    <?= number_format($offer->match_confidence * 100, 0) ?>%
                                                </span>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge-status badge-<?= $offer->is_active ? 'active' : 'inactive' ?>" style="font-size:.7rem">
                                                <?= $offer->is_active ? 'Да' : 'Нет' ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div style="color:var(--text-secondary);font-size:.85rem;padding:4px 0">
                                Нет офферов для этого варианта
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- ═══ Orphan Offers (привязаны к модели, но не к варианту) ═══ -->
    <?php if (!empty($orphanOffers)): ?>
        <div class="card mb-4">
            <div class="card-header" style="color:var(--warning)">
                Офферы без варианта
                <span class="badge bg-warning text-dark ms-2"><?= count($orphanOffers) ?></span>
            </div>
            <div class="card-body p-0">
                <table class="table table-striped mb-0" style="font-size:.85rem">
                    <thead>
                    <tr>
                        <th>Поставщик</th>
                        <th>SKU</th>
                        <th style="text-align:right">Цена от</th>
                        <th style="text-align:right">Цена до</th>
                        <th style="text-align:center">В наличии</th>
                        <th>Метод</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($orphanOffers as $offer): ?>
                        <tr>
                            <td>
                                <?php if ($offer->supplier): ?>
                                    <code><?= Html::encode($offer->supplier->code) ?></code>
                                    <span class="ms-1" style="color:var(--text-secondary)"><?= Html::encode($offer->supplier->name) ?></span>
                                <?php else: ?>
                                    <span style="color:var(--text-secondary)">ID: <?= $offer->supplier_id ?></span>
                                <?php endif; ?>
                            </td>
                            <td><code><?= Html::encode($offer->supplier_sku) ?></code></td>
                            <td style="text-align:right">
                                <?= $offer->price_min ? number_format($offer->price_min, 0, '.', ' ') . ' &#8381;' : '—' ?>
                            </td>
                            <td style="text-align:right">
                                <?= $offer->price_max ? number_format($offer->price_max, 0, '.', ' ') . ' &#8381;' : '—' ?>
                            </td>
                            <td style="text-align:center">
                                <?= $offer->in_stock
                                    ? '<span style="color:var(--success)">&#10003;</span>'
                                    : '<span style="color:var(--danger)">&#10007;</span>' ?>
                            </td>
                            <td><code style="font-size:.75rem"><?= Html::encode($offer->match_method ?? '—') ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

</div>
