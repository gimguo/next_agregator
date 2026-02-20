<?php

/** @var yii\web\View $this */
/** @var yii\data\ActiveDataProvider $dataProvider */
/** @var string|null $status */
/** @var string|null $entityType */
/** @var array $stats */

use yii\grid\GridView;
use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'Очередь выгрузки (Outbox)';
$this->params['breadcrumbs'][] = 'Outbox';

$statusCounts = [];
foreach ($stats as $row) {
    $statusCounts[$row['status']] = (int)$row['cnt'];
}
$totalRecords = array_sum($statusCounts);
?>
<div class="outbox-ui-index">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0" style="font-weight:700">Очередь выгрузки (Marketplace Outbox)</h3>
    </div>

    <!-- ═══ Stats ═══ -->
    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6">
            <a href="<?= Url::to(['index']) ?>" class="text-decoration-none">
                <div class="stat-card accent">
                    <div class="stat-value"><?= number_format($totalRecords) ?></div>
                    <div class="stat-label">Всего записей</div>
                </div>
            </a>
        </div>
        <div class="col-xl-3 col-md-6">
            <a href="<?= Url::to(['index', 'status' => 'pending']) ?>" class="text-decoration-none">
                <div class="stat-card warning">
                    <div class="stat-value"><?= number_format($statusCounts['pending'] ?? 0) ?></div>
                    <div class="stat-label">Pending</div>
                </div>
            </a>
        </div>
        <div class="col-xl-3 col-md-6">
            <a href="<?= Url::to(['index', 'status' => 'success']) ?>" class="text-decoration-none">
                <div class="stat-card success">
                    <div class="stat-value"><?= number_format($statusCounts['success'] ?? 0) ?></div>
                    <div class="stat-label">Success</div>
                </div>
            </a>
        </div>
        <div class="col-xl-3 col-md-6">
            <a href="<?= Url::to(['index', 'status' => 'error']) ?>" class="text-decoration-none">
                <div class="stat-card danger">
                    <div class="stat-value"><?= number_format($statusCounts['error'] ?? 0) ?></div>
                    <div class="stat-label">Error</div>
                </div>
            </a>
        </div>
    </div>

    <!-- ═══ Filters ═══ -->
    <div class="card mb-3">
        <div class="card-body py-2">
            <form method="get" class="d-flex align-items-center gap-3">
                <select name="status" class="form-select form-select-sm" style="width:150px">
                    <option value="">Все статусы</option>
                    <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>pending</option>
                    <option value="processing" <?= $status === 'processing' ? 'selected' : '' ?>>processing</option>
                    <option value="success" <?= $status === 'success' ? 'selected' : '' ?>>success</option>
                    <option value="error" <?= $status === 'error' ? 'selected' : '' ?>>error</option>
                </select>
                <select name="entity_type" class="form-select form-select-sm" style="width:150px">
                    <option value="">Все типы</option>
                    <option value="model" <?= $entityType === 'model' ? 'selected' : '' ?>>model</option>
                    <option value="variant" <?= $entityType === 'variant' ? 'selected' : '' ?>>variant</option>
                    <option value="offer" <?= $entityType === 'offer' ? 'selected' : '' ?>>offer</option>
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
                        'attribute' => 'entity_type',
                        'label' => 'Тип',
                        'headerOptions' => ['style' => 'width:80px'],
                    ],
                    [
                        'attribute' => 'entity_id',
                        'label' => 'Entity ID',
                        'headerOptions' => ['style' => 'width:80px'],
                    ],
                    [
                        'attribute' => 'model_id',
                        'label' => 'Model',
                        'format' => 'raw',
                        'headerOptions' => ['style' => 'width:80px'],
                        'value' => function ($model) {
                            return Html::a($model->model_id, ['/catalog/view', 'id' => $model->model_id],
                                ['style' => 'color:var(--accent)']);
                        },
                    ],
                    [
                        'attribute' => 'event_type',
                        'label' => 'Событие',
                        'headerOptions' => ['style' => 'width:130px'],
                        'format' => 'raw',
                        'value' => function ($model) {
                            $colors = [
                                'created' => 'var(--success)',
                                'updated' => 'var(--info)',
                                'deleted' => 'var(--danger)',
                                'price_changed' => 'var(--warning)',
                                'stock_changed' => 'var(--accent)',
                                'attributes_updated' => 'var(--info)',
                            ];
                            $color = $colors[$model->event_type] ?? 'var(--text-secondary)';
                            return '<code style="color:' . $color . '">' . Html::encode($model->event_type) . '</code>';
                        },
                    ],
                    [
                        'attribute' => 'status',
                        'format' => 'raw',
                        'headerOptions' => ['style' => 'width:110px'],
                        'value' => function ($model) {
                            $map = [
                                'pending' => ['badge-pending', '#f39c12'],
                                'processing' => ['badge-partial', '#3498db'],
                                'success' => ['badge-active', '#2ecc71'],
                                'error' => ['badge-failed', '#e74c3c'],
                            ];
                            $badge = $map[$model->status] ?? ['badge-draft', '#888'];
                            $retry = $model->retry_count > 0 ? ' <sup style="color:var(--text-secondary)">(retry: ' . $model->retry_count . ')</sup>' : '';
                            return '<span class="badge-status ' . $badge[0] . '">' . Html::encode($model->status) . '</span>' . $retry;
                        },
                    ],
                    [
                        'attribute' => 'created_at',
                        'label' => 'Создано',
                        'headerOptions' => ['style' => 'width:140px'],
                        'value' => function ($model) {
                            return $model->created_at ? Yii::$app->formatter->asRelativeTime($model->created_at) : '—';
                        },
                    ],
                    [
                        'attribute' => 'processed_at',
                        'label' => 'Обработано',
                        'headerOptions' => ['style' => 'width:140px'],
                        'value' => function ($model) {
                            return $model->processed_at ? Yii::$app->formatter->asRelativeTime($model->processed_at) : '—';
                        },
                    ],
                    [
                        'attribute' => 'error_log',
                        'label' => 'Ошибка',
                        'format' => 'raw',
                        'value' => function ($model) {
                            if (empty($model->error_log)) {
                                return '<span style="color:var(--text-secondary)">—</span>';
                            }
                            $short = mb_strimwidth($model->error_log, 0, 120, '...');
                            return '<span style="color:var(--danger);font-size:.82rem" title="' . Html::encode($model->error_log) . '">'
                                . Html::encode($short) . '</span>';
                        },
                    ],
                ],
            ]) ?>
        </div>
    </div>

</div>
