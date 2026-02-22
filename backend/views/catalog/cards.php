<?php

/**
 * Карточки товаров — визуальное представление MDM-моделей.
 *
 * @var yii\web\View $this
 * @var backend\models\ProductModelSearch $searchModel
 * @var yii\data\ActiveDataProvider $dataProvider
 * @var common\models\ModelChannelReadiness[] $readinessMap  [model_id => readiness]
 * @var common\models\MediaAsset[] $imageMap                [entity_id => image]
 */

use yii\helpers\Html;
use yii\helpers\Url;
use yii\widgets\LinkPager;

$this->title = 'Карточки товаров';
$this->params['breadcrumbs'][] = $this->title;

$models = $dataProvider->getModels();
$pagination = $dataProvider->getPagination();
?>

<div class="catalog-cards-page">

    <!-- ═══ Header ═══ -->
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div>
            <h3 style="font-weight:700;margin:0">
                <i class="fas fa-id-card me-2" style="color:var(--accent)"></i><?= Html::encode($this->title) ?>
            </h3>
            <div style="color:var(--text-secondary);font-size:.85rem;margin-top:4px">
                Всего: <strong><?= number_format($dataProvider->getTotalCount()) ?></strong> моделей
            </div>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= Url::to(['/catalog/index']) ?>" class="btn btn-sm btn-dark-outline">
                <i class="fas fa-table me-1"></i> Таблица
            </a>
        </div>
    </div>

    <!-- ═══ Search bar ═══ -->
    <div class="card mb-4" style="border:none;background:var(--bg-elevated)">
        <div class="card-body py-3">
            <form method="get" action="<?= Url::to(['/catalog/cards']) ?>" class="d-flex gap-3 flex-wrap align-items-end">
                <div style="flex:1;min-width:200px">
                    <input type="text"
                           name="ProductModelSearch[name]"
                           class="form-control form-control-sm"
                           placeholder="Поиск по названию..."
                           value="<?= Html::encode($searchModel->name ?? '') ?>">
                </div>
                <div style="min-width:140px">
                    <select name="ProductModelSearch[status]" class="form-select form-select-sm">
                        <option value="">Все статусы</option>
                        <option value="active" <?= ($searchModel->status ?? '') === 'active' ? 'selected' : '' ?>>Активные</option>
                        <option value="draft" <?= ($searchModel->status ?? '') === 'draft' ? 'selected' : '' ?>>Черновик</option>
                    </select>
                </div>
                <div style="min-width:140px">
                    <select name="ProductModelSearch[product_family]" class="form-select form-select-sm">
                        <option value="">Все семейства</option>
                        <?php
                        $families = \common\models\ProductModel::find()
                            ->select('product_family')
                            ->distinct()
                            ->where(['IS NOT', 'product_family', null])
                            ->column();
                        foreach ($families as $f):
                        ?>
                            <option value="<?= Html::encode($f) ?>" <?= ($searchModel->product_family ?? '') === $f ? 'selected' : '' ?>>
                                <?= Html::encode($f) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-sm btn-accent">
                    <i class="fas fa-search me-1"></i> Найти
                </button>
                <a href="<?= Url::to(['/catalog/cards']) ?>" class="btn btn-sm btn-dark-outline">
                    <i class="fas fa-times"></i>
                </a>
            </form>
        </div>
    </div>

    <!-- ═══ Cards Grid ═══ -->
    <?php if (empty($models)): ?>
        <div class="card">
            <div class="card-body text-center py-5" style="color:var(--text-muted)">
                <i class="fas fa-box-open fa-3x mb-3" style="opacity:.2"></i>
                <div>Нет товаров, соответствующих фильтрам</div>
            </div>
        </div>
    <?php else: ?>
        <div class="product-cards-grid">
            <?php foreach ($models as $model): ?>
                <?php
                /** @var \common\models\ProductModel $model */
                $readiness = $readinessMap[$model->id] ?? null;
                $image     = $imageMap[$model->id] ?? null;
                $score     = $readiness ? (int)$readiness->score : null;
                $scoreClass = $score === null ? '' : ($score >= 90 ? 'high' : ($score >= 60 ? 'medium' : 'low'));
                ?>
                <a href="<?= Url::to(['/catalog/view', 'id' => $model->id]) ?>" class="text-decoration-none">
                    <div class="product-card-item">
                        <!-- Thumb -->
                        <div class="product-card-thumb">
                            <?php if ($image && $image->getThumbUrl()): ?>
                                <img src="<?= Html::encode($image->getThumbUrl()) ?>" alt="<?= Html::encode($model->name) ?>" loading="lazy">
                            <?php else: ?>
                                <span class="no-image"><i class="fas fa-image"></i></span>
                            <?php endif; ?>
                        </div>

                        <!-- Body -->
                        <div class="product-card-body">
                            <div class="product-card-title"><?= Html::encode($model->name) ?></div>
                            <div class="product-card-meta">
                                <?php if ($model->brand): ?>
                                    <span><?= Html::encode($model->brand->canonical_name) ?></span> ·
                                <?php endif; ?>
                                <span><?= Html::encode($model->product_family ?: '—') ?></span>
                                ·
                                <span class="badge-status badge-<?= $model->status === 'active' ? 'active' : 'draft' ?>" style="font-size:.6rem;padding:1px 6px">
                                    <?= $model->status ?>
                                </span>
                            </div>

                            <div class="product-card-stats">
                                <?php if ($model->variant_count): ?>
                                    <span title="Варианты"><i class="fas fa-cubes" style="color:var(--info);font-size:.65rem"></i> <?= $model->variant_count ?></span>
                                <?php endif; ?>
                                <?php if ($model->supplier_count): ?>
                                    <span title="Поставщики"><i class="fas fa-truck" style="color:var(--text-muted);font-size:.65rem"></i> <?= $model->supplier_count ?></span>
                                <?php endif; ?>
                                <?php if ($model->is_in_stock): ?>
                                    <span style="color:var(--success)" title="В наличии"><i class="fas fa-check-circle" style="font-size:.65rem"></i></span>
                                <?php else: ?>
                                    <span style="color:var(--danger)" title="Нет в наличии"><i class="fas fa-times-circle" style="font-size:.65rem"></i></span>
                                <?php endif; ?>
                            </div>

                            <!-- Footer -->
                            <div class="product-card-footer">
                                <div class="product-card-price">
                                    <?php if ($model->best_price): ?>
                                        <?= number_format($model->best_price, 0, '.', ' ') ?> ₽
                                    <?php else: ?>
                                        <span style="color:var(--text-muted);font-size:.8rem">нет цены</span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($score !== null): ?>
                                    <div class="product-card-score <?= $scoreClass ?>">
                                        <?php if ($score >= 90): ?>
                                            <i class="fas fa-check-circle" style="font-size:.6rem"></i>
                                        <?php elseif ($score >= 60): ?>
                                            <i class="fas fa-exclamation-circle" style="font-size:.6rem"></i>
                                        <?php else: ?>
                                            <i class="fas fa-times-circle" style="font-size:.6rem"></i>
                                        <?php endif; ?>
                                        <?= $score ?>%
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- ═══ Pagination ═══ -->
        <div class="d-flex justify-content-center mt-4">
            <?= LinkPager::widget([
                'pagination' => $pagination,
                'options' => ['class' => 'pagination'],
                'linkContainerOptions' => ['class' => 'page-item'],
                'linkOptions' => ['class' => 'page-link'],
                'disabledListItemSubTagOptions' => ['class' => 'page-link'],
            ]) ?>
        </div>
    <?php endif; ?>

</div>
