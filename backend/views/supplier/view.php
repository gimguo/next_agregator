<?php

/** @var yii\web\View $this */
/** @var \common\models\Supplier $supplier */
/** @var yii\data\ActiveDataProvider $offersProvider */
/** @var array $stats */

use yii\helpers\Html;
use yii\helpers\Url;
use yii\widgets\LinkPager;

$this->title = $supplier->name;
$this->params['breadcrumbs'][] = ['label' => 'Поставщики', 'url' => ['/supplier/index']];
$this->params['breadcrumbs'][] = $supplier->name;

$offers = $offersProvider->getModels();
?>
<div class="supplier-view">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-0" style="font-weight:700"><?= Html::encode($supplier->name) ?></h3>
            <div style="color:var(--text-secondary)">
                <code><?= Html::encode($supplier->code) ?></code>
                &middot; <?= Html::encode($supplier->format) ?>
                <?php if ($supplier->website): ?>
                    &middot; <a href="<?= Html::encode($supplier->website) ?>" target="_blank" style="color:var(--accent)"><?= Html::encode(parse_url($supplier->website, PHP_URL_HOST)) ?></a>
                <?php endif; ?>
            </div>
        </div>
        <span class="badge-status badge-<?= $supplier->is_active ? 'active' : 'inactive' ?>" style="font-size:.9rem;padding:6px 16px">
            <?= $supplier->is_active ? 'Активен' : 'Отключён' ?>
        </span>
    </div>

    <!-- ═══ Stats ═══ -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="stat-card accent">
                <div class="stat-value"><?= number_format($stats['total_offers']) ?></div>
                <div class="stat-label">Всего офферов</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card success">
                <div class="stat-value"><?= number_format($stats['active_offers']) ?></div>
                <div class="stat-label">Активных</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card info">
                <div class="stat-value"><?= number_format($stats['in_stock']) ?></div>
                <div class="stat-label">В наличии</div>
            </div>
        </div>
    </div>

    <!-- ═══ Offers Table ═══ -->
    <div class="card">
        <div class="card-header">Офферы</div>
        <div class="card-body p-0">
            <?php if (empty($offers)): ?>
                <div class="p-4 text-center" style="color:var(--text-secondary)">Нет офферов</div>
            <?php else: ?>
                <table class="table table-striped mb-0">
                    <thead>
                    <tr>
                        <th>Карточка</th>
                        <th>SKU</th>
                        <th>Цена</th>
                        <th>Наличие</th>
                        <th>Варианты</th>
                        <th>Уверенность</th>
                        <th>Обновлён</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($offers as $offer): ?>
                        <tr>
                            <td>
                                <?php if ($offer->card): ?>
                                    <a href="<?= Url::to(['/product-card/view', 'id' => $offer->card_id]) ?>"
                                       style="color:var(--accent);text-decoration:none">
                                        <?= Html::encode(mb_strimwidth($offer->card->canonical_name, 0, 50, '...')) ?>
                                    </a>
                                <?php else: ?>
                                    #<?= $offer->card_id ?>
                                <?php endif; ?>
                            </td>
                            <td><code><?= Html::encode($offer->supplier_sku) ?></code></td>
                            <td>
                                <?= $offer->price_min ? number_format($offer->price_min, 0, '.', ' ') . ' &#8381;' : '—' ?>
                            </td>
                            <td>
                                <span class="badge-status badge-<?= $offer->in_stock ? 'active' : 'inactive' ?>">
                                    <?= $offer->in_stock ? 'Да' : 'Нет' ?>
                                </span>
                            </td>
                            <td class="text-center"><?= $offer->variant_count ?></td>
                            <td>
                                <?php $conf = round($offer->match_confidence * 100); ?>
                                <small><?= $conf ?>%</small>
                            </td>
                            <td style="color:var(--text-secondary);font-size:.85rem">
                                <?= Yii::$app->formatter->asRelativeTime($offer->updated_at) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php if ($offersProvider->getPagination()->pageCount > 1): ?>
            <div class="card-body py-2 d-flex justify-content-center">
                <?= LinkPager::widget(['pagination' => $offersProvider->getPagination()]) ?>
            </div>
        <?php endif; ?>
    </div>
</div>
