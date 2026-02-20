<?php

/** @var yii\web\View $this */
/** @var yii\data\ActiveDataProvider $dataProvider */
/** @var string|null $status */
/** @var string|null $sessionId */
/** @var array $stats */
/** @var string[] $sessions */

use yii\grid\GridView;
use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'Сырые данные (Staging)';
$this->params['breadcrumbs'][] = 'Staging';

$statusCounts = [];
foreach ($stats as $row) {
    $statusCounts[$row['status']] = (int)$row['cnt'];
}
$totalRecords = array_sum($statusCounts);
?>
<div class="staging-ui-index">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0" style="font-weight:700">Сырые данные (Staging Raw Offers)</h3>
        <span class="text-secondary" style="font-size:.82rem">UNLOGGED TABLE — данные временные</span>
    </div>

    <!-- ═══ Stats ═══ -->
    <div class="row g-3 mb-4">
        <div class="col-xl-2 col-md-4 col-6">
            <a href="<?= Url::to(['index']) ?>" class="text-decoration-none">
                <div class="stat-card accent">
                    <div class="stat-value"><?= number_format($totalRecords) ?></div>
                    <div class="stat-label">Всего</div>
                </div>
            </a>
        </div>
        <?php
        $statusColors = [
            'pending' => 'warning',
            'normalized' => 'info',
            'persisted' => 'success',
            'error' => 'danger',
        ];
        foreach ($statusColors as $s => $color): ?>
            <div class="col-xl-2 col-md-4 col-6">
                <a href="<?= Url::to(['index', 'status' => $s]) ?>" class="text-decoration-none">
                    <div class="stat-card <?= $color ?>">
                        <div class="stat-value"><?= number_format($statusCounts[$s] ?? 0) ?></div>
                        <div class="stat-label"><?= $s ?></div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- ═══ Filters ═══ -->
    <div class="card mb-3">
        <div class="card-body py-2">
            <form method="get" class="d-flex align-items-center gap-3">
                <select name="status" class="form-select form-select-sm" style="width:150px">
                    <option value="">Все статусы</option>
                    <?php foreach (array_keys($statusColors) as $s): ?>
                        <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= $s ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="session_id" class="form-select form-select-sm" style="width:300px">
                    <option value="">Все сессии</option>
                    <?php foreach ($sessions as $sid): ?>
                        <option value="<?= Html::encode($sid) ?>" <?= $sessionId === $sid ? 'selected' : '' ?>>
                            <?= Html::encode($sid) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-sm btn-accent">Фильтр</button>
                <a href="<?= Url::to(['index']) ?>" class="btn btn-sm btn-dark-outline">Сбросить</a>
            </form>
        </div>
    </div>

    <!-- ═══ Grid ═══ -->
    <div class="card">
        <div class="card-body p-0">
            <?= GridView::widget([
                'dataProvider' => $dataProvider,
                'tableOptions' => ['class' => 'table table-striped mb-0'],
                'layout' => "{items}\n<div class='card-footer d-flex justify-content-between align-items-center'>{summary}{pager}</div>",
                'columns' => [
                    [
                        'attribute' => 'id',
                        'headerOptions' => ['style' => 'width:70px'],
                    ],
                    [
                        'attribute' => 'import_session_id',
                        'label' => 'Сессия',
                        'format' => 'raw',
                        'headerOptions' => ['style' => 'width:220px'],
                        'value' => function ($model) {
                            return '<code style="font-size:.78rem">'
                                . Html::encode(mb_strimwidth($model->import_session_id, 0, 35, '...'))
                                . '</code>';
                        },
                    ],
                    [
                        'attribute' => 'supplier_id',
                        'label' => 'Поставщик',
                        'format' => 'raw',
                        'headerOptions' => ['style' => 'width:120px'],
                        'value' => function ($model) {
                            $supplier = $model->supplier;
                            if ($supplier) {
                                return '<code>' . Html::encode($supplier->code) . '</code>';
                            }
                            return 'ID: ' . $model->supplier_id;
                        },
                    ],
                    [
                        'attribute' => 'supplier_sku',
                        'label' => 'SKU',
                        'headerOptions' => ['style' => 'width:140px'],
                        'value' => function ($model) {
                            return $model->supplier_sku ? mb_strimwidth($model->supplier_sku, 0, 25, '...') : '—';
                        },
                    ],
                    [
                        'attribute' => 'status',
                        'format' => 'raw',
                        'headerOptions' => ['style' => 'width:110px'],
                        'value' => function ($model) {
                            $map = [
                                'pending' => 'badge-pending',
                                'normalized' => 'badge-partial',
                                'persisted' => 'badge-active',
                                'error' => 'badge-failed',
                            ];
                            $cls = $map[$model->status] ?? 'badge-draft';
                            return '<span class="badge-status ' . $cls . '">' . Html::encode($model->status) . '</span>';
                        },
                    ],
                    [
                        'attribute' => 'raw_data',
                        'label' => 'Raw JSON',
                        'format' => 'raw',
                        'value' => function ($model) {
                            $data = $model->raw_data;
                            if (is_string($data)) {
                                $decoded = json_decode($data, true);
                            } else {
                                $decoded = $data;
                            }
                            if (empty($decoded)) {
                                return '<span style="color:var(--text-secondary)">—</span>';
                            }
                            $json = json_encode($decoded, JSON_UNESCAPED_UNICODE);
                            $short = mb_strimwidth($json, 0, 100, '...');
                            return '<pre style="background:var(--bg-dark);color:var(--text-secondary);padding:4px 8px;border-radius:4px;margin:0;font-size:.75rem;max-width:350px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis" title="'
                                . Html::encode($json) . '">'
                                . Html::encode($short)
                                . '</pre>';
                        },
                    ],
                    [
                        'attribute' => 'error_message',
                        'label' => 'Ошибка',
                        'format' => 'raw',
                        'headerOptions' => ['style' => 'width:180px'],
                        'value' => function ($model) {
                            if (empty($model->error_message)) {
                                return '<span style="color:var(--text-secondary)">—</span>';
                            }
                            $short = mb_strimwidth($model->error_message, 0, 80, '...');
                            return '<span style="color:var(--danger);font-size:.82rem" title="' . Html::encode($model->error_message) . '">'
                                . Html::encode($short) . '</span>';
                        },
                    ],
                    [
                        'attribute' => 'created_at',
                        'label' => 'Создано',
                        'headerOptions' => ['style' => 'width:130px'],
                        'value' => function ($model) {
                            return $model->created_at ? Yii::$app->formatter->asRelativeTime($model->created_at) : '—';
                        },
                    ],
                ],
            ]) ?>
        </div>
    </div>

</div>
