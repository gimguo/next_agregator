<?php

/** @var yii\web\View $this */
/** @var \common\models\Supplier $supplier */
/** @var yii\data\ActiveDataProvider $offersProvider */
/** @var array $stats */
/** @var \common\models\SupplierFetchConfig|null $fetchConfig */
/** @var array $priceFiles */

use yii\helpers\Html;
use yii\helpers\Url;
use yii\widgets\LinkPager;

$this->title = $supplier->name;
$this->params['breadcrumbs'][] = ['label' => 'Поставщики', 'url' => ['/supplier/index']];
$this->params['breadcrumbs'][] = $supplier->name;

$offers = $offersProvider->getModels();

// Pending images count
$pendingImages = Yii::$app->db->createCommand("
    SELECT COUNT(*) FROM {{%card_images}} ci
    JOIN {{%supplier_offers}} so ON so.card_id = ci.card_id
    WHERE ci.status = 'pending' AND so.supplier_id = :sid
", [':sid' => $supplier->id])->queryScalar();
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
        <div>
            <span class="badge-status badge-<?= $supplier->is_active ? 'active' : 'inactive' ?>" style="font-size:.9rem;padding:6px 16px">
                <?= $supplier->is_active ? 'Активен' : 'Отключён' ?>
            </span>
            <?= Html::a('&#9998; Редактировать', ['/supplier/update', 'id' => $supplier->id], ['class' => 'btn btn-sm btn-dark-outline ms-2']) ?>
        </div>
    </div>

    <!-- ═══ Stats ═══ -->
    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="stat-card accent">
                <div class="stat-value"><?= number_format($stats['total_offers']) ?></div>
                <div class="stat-label">Всего офферов</div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="stat-card success">
                <div class="stat-value"><?= number_format($stats['active_offers']) ?></div>
                <div class="stat-label">Активных</div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="stat-card info">
                <div class="stat-value"><?= number_format($stats['in_stock']) ?></div>
                <div class="stat-label">В наличии</div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="stat-card <?= $pendingImages > 0 ? 'warning' : 'success' ?>">
                <div class="stat-value"><?= number_format($pendingImages) ?></div>
                <div class="stat-label">Картинок в ожидании</div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <!-- ═══ Import Panel ═══ -->
        <div class="col-xl-7">
            <div class="card h-100">
                <div class="card-header">
                    &#128230; Импорт прайс-листа
                </div>
                <div class="card-body">
                    <?php if (empty($priceFiles)): ?>
                        <div class="text-center py-3" style="color:var(--text-secondary)">
                            <p>Нет доступных файлов прайса.</p>
                            <p style="font-size:.85rem">Положите файл в <code>/app/storage/prices/<?= Html::encode($supplier->code) ?>/</code></p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($priceFiles as $file): ?>
                            <form method="post" action="<?= Url::to(['/supplier/import', 'id' => $supplier->id]) ?>" class="mb-3 p-3" style="background:var(--bg-input);border-radius:8px;border:1px solid var(--border)">
                                <?= Html::hiddenInput(Yii::$app->request->csrfParam, Yii::$app->request->csrfToken) ?>
                                <?= Html::hiddenInput('file_path', $file['path']) ?>

                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <strong><?= Html::encode($file['name']) ?></strong>
                                        <div style="color:var(--text-secondary);font-size:.8rem">
                                            <?= Yii::$app->formatter->asShortSize($file['size']) ?>
                                            &middot; <?= date('d.m.Y H:i', $file['modified']) ?>
                                            &middot; <span class="badge-status badge-<?= $file['source'] === 'source' ? 'partial' : 'active' ?>"><?= $file['source'] ?></span>
                                        </div>
                                    </div>
                                </div>

                                <div class="row g-2 align-items-end">
                                    <div class="col-auto">
                                        <label style="font-size:.75rem;color:var(--text-secondary)">Лимит товаров</label>
                                        <select name="max_products" class="form-select form-select-sm" style="width:140px">
                                            <option value="0">Все</option>
                                            <option value="50">50</option>
                                            <option value="100">100</option>
                                            <option value="500">500</option>
                                            <option value="1000">1 000</option>
                                            <option value="5000">5 000</option>
                                        </select>
                                    </div>
                                    <div class="col-auto">
                                        <label class="form-check" style="font-size:.85rem">
                                            <input type="checkbox" name="download_images" value="1" checked class="form-check-input">
                                            Картинки
                                        </label>
                                    </div>
                                    <div class="col-auto">
                                        <button type="submit" class="btn btn-sm btn-accent" onclick="return confirm('Запустить импорт?')">
                                            &#9654; Импорт в очередь
                                        </button>
                                    </div>
                                </div>
                            </form>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ═══ Actions Panel ═══ -->
        <div class="col-xl-5">
            <div class="card h-100">
                <div class="card-header">&#9881; Действия</div>
                <div class="card-body">
                    <!-- Download Images -->
                    <form method="post" action="<?= Url::to(['/supplier/queue-images', 'id' => $supplier->id]) ?>" class="mb-3">
                        <?= Html::hiddenInput(Yii::$app->request->csrfParam, Yii::$app->request->csrfToken) ?>
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <strong style="font-size:.9rem">Скачать картинки</strong>
                                <div style="color:var(--text-secondary);font-size:.8rem"><?= number_format($pendingImages) ?> в ожидании</div>
                            </div>
                            <button type="submit" class="btn btn-sm btn-dark-outline" <?= $pendingImages == 0 ? 'disabled' : '' ?>>
                                &#128247; Запустить
                            </button>
                        </div>
                    </form>

                    <hr style="border-color:var(--border)">

                    <!-- AI Processing -->
                    <form method="post" action="<?= Url::to(['/supplier/queue-ai', 'id' => $supplier->id]) ?>" class="mb-3">
                        <?= Html::hiddenInput(Yii::$app->request->csrfParam, Yii::$app->request->csrfToken) ?>
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <strong style="font-size:.9rem">AI-обработка</strong>
                                <div style="color:var(--text-secondary);font-size:.8rem">Бренды, категоризация</div>
                            </div>
                            <button type="submit" class="btn btn-sm btn-dark-outline">
                                &#129302; Запустить
                            </button>
                        </div>
                    </form>

                    <hr style="border-color:var(--border)">

                    <!-- Fetch Config -->
                    <?php if ($fetchConfig): ?>
                        <div class="mb-2">
                            <strong style="font-size:.9rem">Конфигурация получения</strong>
                        </div>
                        <table class="table table-sm mb-0" style="font-size:.82rem">
                            <tr>
                                <td style="color:var(--text-secondary);border:none;width:45%">Метод</td>
                                <td style="border:none">
                                    <span class="badge-status badge-<?= $fetchConfig->fetch_method === 'manual' ? 'draft' : 'active' ?>">
                                        <?= Html::encode($fetchConfig->getMethodLabel()) ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td style="color:var(--text-secondary);border:none">Формат</td>
                                <td style="border:none"><code><?= Html::encode($fetchConfig->file_format ?: '—') ?></code></td>
                            </tr>
                            <tr>
                                <td style="color:var(--text-secondary);border:none">Кодировка</td>
                                <td style="border:none"><?= Html::encode($fetchConfig->file_encoding ?: '—') ?></td>
                            </tr>
                            <?php if ($fetchConfig->schedule_cron): ?>
                                <tr>
                                    <td style="color:var(--text-secondary);border:none">Расписание</td>
                                    <td style="border:none"><code><?= Html::encode($fetchConfig->schedule_cron) ?></code></td>
                                </tr>
                            <?php endif; ?>
                            <tr>
                                <td style="color:var(--text-secondary);border:none">Загрузок</td>
                                <td style="border:none"><?= $fetchConfig->fetch_count ?></td>
                            </tr>
                            <tr>
                                <td style="color:var(--text-secondary);border:none">Статус</td>
                                <td style="border:none">
                                    <span class="badge-status badge-<?= $fetchConfig->is_enabled ? 'active' : 'inactive' ?>">
                                        <?= $fetchConfig->is_enabled ? 'Вкл' : 'Выкл' ?>
                                    </span>
                                </td>
                            </tr>
                        </table>
                        <div class="text-end mt-2">
                            <?= Html::a('&#9998; Настроить', ['/supplier-fetch-config/update', 'supplierId' => $supplier->id], ['class' => 'btn btn-sm btn-dark-outline']) ?>
                        </div>
                    <?php else: ?>
                        <div style="color:var(--text-secondary);font-size:.85rem" class="mb-2">
                            Конфигурация получения не задана
                        </div>
                        <?= Html::a('+ Создать конфигурацию', ['/supplier-fetch-config/update', 'supplierId' => $supplier->id], ['class' => 'btn btn-sm btn-accent']) ?>
                    <?php endif; ?>

                    <hr style="border-color:var(--border)">

                    <!-- Info -->
                    <div style="font-size:.8rem;color:var(--text-secondary)">
                        Последний импорт:
                        <?php if ($supplier->last_import_at): ?>
                            <?= Yii::$app->formatter->asDatetime($supplier->last_import_at) ?>
                            (<?= Yii::$app->formatter->asRelativeTime($supplier->last_import_at) ?>)
                        <?php else: ?>
                            никогда
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ Offers Table ═══ -->
    <div class="card">
        <div class="card-header">Офферы (<?= number_format($offersProvider->getTotalCount()) ?>)</div>
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
