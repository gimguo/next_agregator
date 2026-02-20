<?php

/** @var yii\web\View $this */
/** @var yii\data\ActiveDataProvider $dataProvider */
/** @var string|null $search */
/** @var string|null $status */
/** @var string|null $brand */
/** @var string[] $brands */

use yii\helpers\Html;
use yii\helpers\Url;
use yii\widgets\LinkPager;

$this->title = 'Карточки товаров';
$this->params['breadcrumbs'][] = $this->title;

$models = $dataProvider->getModels();
$pagination = $dataProvider->getPagination();
?>
<div class="product-card-index">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0" style="font-weight:700">Карточки товаров</h3>
        <span style="color:var(--text-secondary)"><?= number_format($dataProvider->getTotalCount()) ?> шт.</span>
    </div>

    <!-- ═══ Filters ═══ -->
    <div class="card mb-3">
        <div class="card-body py-2">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-md-4">
                    <input type="text" name="search" class="form-control form-control-sm"
                           placeholder="Поиск по названию, бренду..."
                           value="<?= Html::encode($search) ?>">
                </div>
                <div class="col-md-2">
                    <select name="status" class="form-select form-select-sm">
                        <option value="">Все статусы</option>
                        <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Активна</option>
                        <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Черновик</option>
                        <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Неактивна</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="brand" class="form-select form-select-sm">
                        <option value="">Все бренды</option>
                        <?php foreach ($brands as $b): ?>
                            <option value="<?= Html::encode($b) ?>" <?= $brand === $b ? 'selected' : '' ?>>
                                <?= Html::encode($b) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-sm btn-accent me-1">Найти</button>
                    <a href="<?= Url::to(['/product-card/index']) ?>" class="btn btn-sm btn-dark-outline">Сброс</a>
                </div>
            </form>
        </div>
    </div>

    <!-- ═══ Table ═══ -->
    <div class="card">
        <div class="card-body p-0">
            <?php if (empty($models)): ?>
                <div class="p-4 text-center" style="color:var(--text-secondary)">
                    Карточек не найдено.
                </div>
            <?php else: ?>
                <table class="table table-striped mb-0">
                    <thead>
                    <tr>
                        <th style="width:50px">ID</th>
                        <th>Название</th>
                        <th>Бренд</th>
                        <th>Тип</th>
                        <th>Цена</th>
                        <th class="text-center">Пост.</th>
                        <th class="text-center">Вар.</th>
                        <th class="text-center">Фото</th>
                        <th>Качество</th>
                        <th>Статус</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($models as $card): ?>
                        <tr>
                            <td><?= $card->id ?></td>
                            <td>
                                <a href="<?= Url::to(['/product-card/view', 'id' => $card->id]) ?>"
                                   style="color:var(--accent);text-decoration:none">
                                    <?= Html::encode(mb_strimwidth($card->canonical_name, 0, 65, '...')) ?>
                                </a>
                            </td>
                            <td><?= Html::encode($card->brand ?: '—') ?></td>
                            <td style="color:var(--text-secondary)"><?= Html::encode($card->product_type ?: '—') ?></td>
                            <td>
                                <?php if ($card->best_price): ?>
                                    <strong><?= number_format($card->best_price, 0, '.', ' ') ?></strong> &#8381;
                                    <?php if ($card->price_range_min && $card->price_range_max && $card->price_range_min != $card->price_range_max): ?>
                                        <br><small style="color:var(--text-secondary)"><?= number_format($card->price_range_min, 0, '.', ' ') ?> — <?= number_format($card->price_range_max, 0, '.', ' ') ?></small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color:var(--text-secondary)">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center"><?= $card->supplier_count ?></td>
                            <td class="text-center"><?= $card->total_variants ?></td>
                            <td class="text-center">
                                <?php if ($card->image_count > 0): ?>
                                    <span class="badge-status badge-<?= $card->images_status ?>"><?= $card->image_count ?></span>
                                <?php else: ?>
                                    <span style="color:var(--text-secondary)">0</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $qColor = $card->quality_score >= 80 ? 'success' : ($card->quality_score >= 50 ? 'warning' : 'danger');
                                ?>
                                <div class="progress" style="width:50px;height:6px" title="<?= $card->quality_score ?>%">
                                    <div class="progress-bar bg-<?= $qColor ?>" style="width:<?= $card->quality_score ?>%"></div>
                                </div>
                            </td>
                            <td>
                                <span class="badge-status badge-<?= $card->status ?>">
                                    <?= $card->status === 'active' ? 'Актив' : ($card->status === 'draft' ? 'Черн.' : $card->status) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php if ($pagination->pageCount > 1): ?>
            <div class="card-body py-2 d-flex justify-content-center">
                <?= LinkPager::widget(['pagination' => $pagination]) ?>
            </div>
        <?php endif; ?>
    </div>
</div>
