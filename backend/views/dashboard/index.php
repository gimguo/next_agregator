<?php

/** @var yii\web\View $this */
/** @var array $stats */
/** @var \common\models\Supplier[] $suppliers */
/** @var \common\models\ProductCard[] $recentCards */

use yii\helpers\Html;

$this->title = 'Дашборд';
?>
<div class="dashboard-index">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0" style="font-weight:700">Дашборд</h3>
        <div>
            <a href="/adminer" target="_blank" class="btn btn-sm btn-dark-outline me-2">Adminer</a>
        </div>
    </div>

    <!-- ═══ Stat Cards ═══ -->
    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="stat-card accent">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="stat-value"><?= number_format($stats['cards']) ?></div>
                        <div class="stat-label">Карточек товаров</div>
                    </div>
                    <div class="stat-icon">&#128230;</div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="stat-card success">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="stat-value"><?= number_format($stats['cards_active']) ?></div>
                        <div class="stat-label">Активных карточек</div>
                    </div>
                    <div class="stat-icon">&#9989;</div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="stat-card warning">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="stat-value"><?= number_format($stats['offers']) ?></div>
                        <div class="stat-label">Офферов поставщиков</div>
                    </div>
                    <div class="stat-icon">&#128176;</div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="stat-card info">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="stat-value"><?= number_format($stats['suppliers']) ?></div>
                        <div class="stat-label">Активных поставщиков</div>
                    </div>
                    <div class="stat-icon">&#127970;</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <!-- ═══ Images Status ═══ -->
        <div class="col-xl-4">
            <div class="card h-100">
                <div class="card-header">Картинки</div>
                <div class="card-body">
                    <?php
                    $imgTotal = max($stats['images_total'], 1);
                    $completedPct = round($stats['images_completed'] / $imgTotal * 100);
                    $pendingPct = round($stats['images_pending'] / $imgTotal * 100);
                    $failedPct = round($stats['images_failed'] / $imgTotal * 100);
                    ?>
                    <div class="row text-center mb-3">
                        <div class="col-4 queue-stat">
                            <div class="value" style="color:var(--success)"><?= number_format($stats['images_completed']) ?></div>
                            <div class="label">Загружено</div>
                        </div>
                        <div class="col-4 queue-stat">
                            <div class="value" style="color:var(--warning)"><?= number_format($stats['images_pending']) ?></div>
                            <div class="label">Ожидает</div>
                        </div>
                        <div class="col-4 queue-stat">
                            <div class="value" style="color:var(--danger)"><?= number_format($stats['images_failed']) ?></div>
                            <div class="label">Ошибки</div>
                        </div>
                    </div>
                    <div class="progress" style="height:10px">
                        <div class="progress-bar bg-success" style="width:<?= $completedPct ?>%"></div>
                        <div class="progress-bar bg-warning" style="width:<?= $pendingPct ?>%"></div>
                        <div class="progress-bar bg-danger" style="width:<?= $failedPct ?>%"></div>
                    </div>
                    <div class="text-center mt-2" style="color:var(--text-secondary);font-size:.8rem">
                        Всего: <?= number_format($stats['images_total']) ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══ Suppliers ═══ -->
        <div class="col-xl-8">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Поставщики</span>
                    <a href="<?= \yii\helpers\Url::to(['/supplier/index']) ?>" class="btn btn-sm btn-dark-outline">Все</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($suppliers)): ?>
                        <div class="p-4 text-center" style="color:var(--text-secondary)">
                            Нет активных поставщиков. Запустите импорт через консоль:<br>
                            <code>docker compose exec php php yii import/run ormatek /path/to/price.xml</code>
                        </div>
                    <?php else: ?>
                        <table class="table table-striped mb-0">
                            <thead>
                            <tr>
                                <th>Код</th>
                                <th>Название</th>
                                <th>Формат</th>
                                <th>Офферов</th>
                                <th>Последний импорт</th>
                                <th>Статус</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($suppliers as $supplier): ?>
                                <tr>
                                    <td><code><?= Html::encode($supplier->code) ?></code></td>
                                    <td><?= Html::encode($supplier->name) ?></td>
                                    <td><?= Html::encode($supplier->format) ?></td>
                                    <td><?= number_format($supplier->getOffersCount()) ?></td>
                                    <td>
                                        <?php if ($supplier->last_import_at): ?>
                                            <?= Yii::$app->formatter->asRelativeTime($supplier->last_import_at) ?>
                                        <?php else: ?>
                                            <span style="color:var(--text-secondary)">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge-status badge-<?= $supplier->is_active ? 'active' : 'inactive' ?>">
                                            <?= $supplier->is_active ? 'Активен' : 'Отключён' ?>
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
    </div>

    <!-- ═══ Recent Cards ═══ -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Последние карточки</span>
            <a href="<?= \yii\helpers\Url::to(['/product-card/index']) ?>" class="btn btn-sm btn-dark-outline">Все карточки</a>
        </div>
        <div class="card-body p-0">
            <?php if (empty($recentCards)): ?>
                <div class="p-4 text-center" style="color:var(--text-secondary)">
                    Карточек пока нет. Запустите импорт прайс-листа для начала работы.
                </div>
            <?php else: ?>
                <table class="table table-striped mb-0">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Название</th>
                        <th>Бренд</th>
                        <th>Тип</th>
                        <th>Цена</th>
                        <th>Поставщики</th>
                        <th>Варианты</th>
                        <th>Фото</th>
                        <th>Статус</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recentCards as $card): ?>
                        <tr>
                            <td><?= $card->id ?></td>
                            <td>
                                <a href="<?= \yii\helpers\Url::to(['/product-card/view', 'id' => $card->id]) ?>" style="color:var(--accent);text-decoration:none">
                                    <?= Html::encode(mb_strimwidth($card->canonical_name, 0, 60, '...')) ?>
                                </a>
                            </td>
                            <td><?= Html::encode($card->brand ?: '—') ?></td>
                            <td><?= Html::encode($card->product_type ?: '—') ?></td>
                            <td>
                                <?php if ($card->best_price): ?>
                                    <strong><?= number_format($card->best_price, 0, '.', ' ') ?> &#8381;</strong>
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
                                <span class="badge-status badge-<?= $card->status ?>">
                                    <?= $card->status === 'active' ? 'Активна' : ($card->status === 'draft' ? 'Черновик' : $card->status) ?>
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
