<?php

/** @var yii\web\View $this */
/** @var \common\models\ProductCard $card */
/** @var \common\models\SupplierOffer[] $offers */

use yii\helpers\Html;

$this->title = $card->canonical_name;
$this->params['breadcrumbs'][] = ['label' => 'Карточки', 'url' => ['/product-card/index']];
$this->params['breadcrumbs'][] = "#{$card->id}";
?>
<div class="product-card-view">

    <div class="d-flex justify-content-between align-items-start mb-4">
        <div>
            <h4 style="font-weight:700"><?= Html::encode($card->canonical_name) ?></h4>
            <div style="color:var(--text-secondary)">
                <?= Html::encode($card->brand ?: '') ?>
                <?= $card->model ? ' &middot; ' . Html::encode($card->model) : '' ?>
                <?= $card->product_type ? ' &middot; ' . Html::encode($card->product_type) : '' ?>
            </div>
        </div>
        <span class="badge-status badge-<?= $card->status ?>" style="font-size:.9rem;padding:6px 16px">
            <?= $card->status === 'active' ? 'Активна' : ($card->status === 'draft' ? 'Черновик' : $card->status) ?>
        </span>
    </div>

    <div class="row g-3 mb-4">
        <!-- ═══ Info ═══ -->
        <div class="col-xl-8">
            <div class="card">
                <div class="card-header">Основная информация</div>
                <div class="card-body">
                    <table class="table mb-0">
                        <tr><td style="width:180px;color:var(--text-secondary)">ID</td><td><?= $card->id ?></td></tr>
                        <tr><td style="color:var(--text-secondary)">Slug</td><td><code><?= Html::encode($card->slug) ?></code></td></tr>
                        <tr><td style="color:var(--text-secondary)">Производитель</td><td><?= Html::encode($card->manufacturer ?: '—') ?></td></tr>
                        <tr><td style="color:var(--text-secondary)">Бренд</td><td><?= Html::encode($card->brand ?: '—') ?></td></tr>
                        <tr><td style="color:var(--text-secondary)">Модель</td><td><?= Html::encode($card->model ?: '—') ?></td></tr>
                        <tr><td style="color:var(--text-secondary)">Тип товара</td><td><?= Html::encode($card->product_type ?: '—') ?></td></tr>
                        <tr>
                            <td style="color:var(--text-secondary)">Лучшая цена</td>
                            <td>
                                <?php if ($card->best_price): ?>
                                    <strong style="font-size:1.2rem"><?= number_format($card->best_price, 0, '.', ' ') ?> &#8381;</strong>
                                    <?php if ($card->price_range_min && $card->price_range_max): ?>
                                        <span style="color:var(--text-secondary);margin-left:8px">
                                            (<?= number_format($card->price_range_min, 0, '.', ' ') ?> — <?= number_format($card->price_range_max, 0, '.', ' ') ?>)
                                        </span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr><td style="color:var(--text-secondary)">Поставщиков</td><td><?= $card->supplier_count ?></td></tr>
                        <tr><td style="color:var(--text-secondary)">Вариантов</td><td><?= $card->total_variants ?></td></tr>
                        <tr><td style="color:var(--text-secondary)">Качество</td>
                            <td>
                                <?php $qColor = $card->quality_score >= 80 ? 'success' : ($card->quality_score >= 50 ? 'warning' : 'danger'); ?>
                                <div class="d-flex align-items-center">
                                    <div class="progress me-2" style="width:80px;height:8px">
                                        <div class="progress-bar bg-<?= $qColor ?>" style="width:<?= $card->quality_score ?>%"></div>
                                    </div>
                                    <span><?= $card->quality_score ?>%</span>
                                </div>
                            </td>
                        </tr>
                        <tr><td style="color:var(--text-secondary)">Создана</td><td><?= Yii::$app->formatter->asDatetime($card->created_at) ?></td></tr>
                        <tr><td style="color:var(--text-secondary)">Обновлена</td><td><?= Yii::$app->formatter->asDatetime($card->updated_at) ?></td></tr>
                    </table>
                </div>
            </div>

            <?php if ($card->description): ?>
                <div class="card mt-3">
                    <div class="card-header">Описание</div>
                    <div class="card-body">
                        <?= nl2br(Html::encode($card->description)) ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- ═══ Images ═══ -->
        <div class="col-xl-4">
            <div class="card">
                <div class="card-header">
                    Изображения
                    <span class="badge-status badge-<?= $card->images_status ?>" style="float:right">
                        <?= $card->image_count ?> шт.
                    </span>
                </div>
                <div class="card-body">
                    <?php
                    $images = $card->images;
                    if (empty($images)):
                        ?>
                        <div class="text-center py-3" style="color:var(--text-secondary)">Нет изображений</div>
                    <?php else: ?>
                        <div class="row g-2">
                            <?php foreach (array_slice($images, 0, 8) as $img): ?>
                                <div class="col-6">
                                    <?php $url = $img->getDisplayUrl('thumb'); ?>
                                    <?php if ($url): ?>
                                        <img src="<?= Html::encode($url) ?>" class="img-fluid rounded"
                                             style="border:1px solid var(--border)">
                                    <?php else: ?>
                                        <div style="background:var(--bg-input);border-radius:6px;padding:20px;text-align:center;color:var(--text-secondary);font-size:.75rem">
                                            <?= $img->status ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if (count($images) > 8): ?>
                            <div class="text-center mt-2" style="color:var(--text-secondary);font-size:.85rem">
                                +<?= count($images) - 8 ?> ещё
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ Offers ═══ -->
    <div class="card">
        <div class="card-header">Офферы поставщиков (<?= count($offers) ?>)</div>
        <div class="card-body p-0">
            <?php if (empty($offers)): ?>
                <div class="p-4 text-center" style="color:var(--text-secondary)">Нет офферов</div>
            <?php else: ?>
                <table class="table table-striped mb-0">
                    <thead>
                    <tr>
                        <th>Поставщик</th>
                        <th>SKU</th>
                        <th>Цена от</th>
                        <th>Цена до</th>
                        <th>Наличие</th>
                        <th>Варианты</th>
                        <th>Уверенность</th>
                        <th>Метод</th>
                        <th>Активен</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($offers as $offer): ?>
                        <tr>
                            <td>
                                <code><?= Html::encode($offer->supplier->code ?? '?') ?></code>
                                <?= Html::encode($offer->supplier->name ?? '') ?>
                            </td>
                            <td><code><?= Html::encode($offer->supplier_sku) ?></code></td>
                            <td><?= $offer->price_min ? number_format($offer->price_min, 0, '.', ' ') . ' &#8381;' : '—' ?></td>
                            <td><?= $offer->price_max ? number_format($offer->price_max, 0, '.', ' ') . ' &#8381;' : '—' ?></td>
                            <td>
                                <span class="badge-status badge-<?= $offer->in_stock ? 'active' : 'inactive' ?>">
                                    <?= $offer->in_stock ? 'Да' : 'Нет' ?>
                                </span>
                            </td>
                            <td class="text-center"><?= $offer->variant_count ?></td>
                            <td>
                                <?php
                                $conf = $offer->match_confidence * 100;
                                $confColor = $conf >= 95 ? 'success' : ($conf >= 60 ? 'warning' : 'danger');
                                ?>
                                <div class="d-flex align-items-center">
                                    <div class="progress me-2" style="width:50px;height:6px">
                                        <div class="progress-bar bg-<?= $confColor ?>" style="width:<?= $conf ?>%"></div>
                                    </div>
                                    <small><?= round($conf) ?>%</small>
                                </div>
                            </td>
                            <td><code style="font-size:.75rem"><?= Html::encode($offer->match_method) ?></code></td>
                            <td>
                                <span class="badge-status badge-<?= $offer->is_active ? 'active' : 'inactive' ?>">
                                    <?= $offer->is_active ? 'Да' : 'Нет' ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>
