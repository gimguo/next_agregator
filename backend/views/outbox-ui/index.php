<?php

/** @var yii\web\View $this */
/** @var yii\data\ActiveDataProvider $dataProvider */
/** @var string|null $status */
/** @var string|null $entityType */
/** @var string|null $lane */
/** @var array $stats */
/** @var array $laneStats */

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

$laneCounts = [];
foreach ($laneStats as $row) {
    $laneCounts[$row['lane']] = (int)$row['cnt'];
}
?>
<div class="outbox-ui-index">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0" style="font-weight:700">
            <i class="fas fa-paper-plane" style="color:var(--accent)"></i> Outbox — Очередь выгрузки
        </h3>
        <span id="live-indicator" class="d-flex align-items-center" style="font-size:.8rem;color:var(--text-secondary)">
            <span id="live-dot" style="width:8px;height:8px;border-radius:50%;background:var(--success);display:inline-block;margin-right:6px;animation:pulse 2s infinite"></span>
            <span id="live-time"><?= date('H:i:s') ?></span>
        </span>
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
        <div class="col-xl-2 col-md-4 col-6">
            <a href="<?= Url::to(['index', 'status' => 'pending']) ?>" class="text-decoration-none">
                <div class="stat-card warning">
                    <div class="stat-value"><?= number_format($statusCounts['pending'] ?? 0) ?></div>
                    <div class="stat-label">Pending</div>
                </div>
            </a>
        </div>
        <div class="col-xl-2 col-md-4 col-6">
            <a href="<?= Url::to(['index', 'status' => 'processing']) ?>" class="text-decoration-none">
                <div class="stat-card info">
                    <div class="stat-value"><?= number_format($statusCounts['processing'] ?? 0) ?></div>
                    <div class="stat-label">Processing</div>
                </div>
            </a>
        </div>
        <div class="col-xl-2 col-md-4 col-6">
            <a href="<?= Url::to(['index', 'status' => 'success']) ?>" class="text-decoration-none">
                <div class="stat-card success">
                    <div class="stat-value"><?= number_format($statusCounts['success'] ?? 0) ?></div>
                    <div class="stat-label">Success</div>
                </div>
            </a>
        </div>
        <div class="col-xl-2 col-md-4 col-6">
            <a href="<?= Url::to(['index', 'status' => 'error']) ?>" class="text-decoration-none">
                <div class="stat-card danger">
                    <div class="stat-value"><?= number_format(($statusCounts['error'] ?? 0) + ($statusCounts['failed'] ?? 0)) ?></div>
                    <div class="stat-label">Error / DLQ</div>
                </div>
            </a>
        </div>
        <div class="col-xl-2 col-md-4 col-6">
            <div class="stat-card purple">
                <div class="stat-value" style="font-size:1rem">
                    <?php foreach (['content_updated', 'price_updated', 'stock_updated'] as $l): ?>
                        <span class="lane-badge lane-<?= explode('_', $l)[0] ?>"><?= $laneCounts[$l] ?? 0 ?></span>
                    <?php endforeach; ?>
                </div>
                <div class="stat-label">Pending по лейнам</div>
            </div>
        </div>
    </div>

    <!-- ═══ Filters ═══ -->
    <div class="card mb-3">
        <div class="card-body py-2">
            <form method="get" class="d-flex align-items-center gap-3 flex-wrap">
                <select name="status" class="form-select form-select-sm" style="width:140px">
                    <option value="">Все статусы</option>
                    <?php foreach (['pending','processing','success','error','failed'] as $s): ?>
                        <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= $s ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="lane" class="form-select form-select-sm" style="width:160px">
                    <option value="">Все лейны</option>
                    <?php foreach (['content_updated','price_updated','stock_updated'] as $l): ?>
                        <option value="<?= $l ?>" <?= $lane === $l ? 'selected' : '' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="entity_type" class="form-select form-select-sm" style="width:120px">
                    <option value="">Все типы</option>
                    <option value="model" <?= $entityType === 'model' ? 'selected' : '' ?>>model</option>
                    <option value="variant" <?= $entityType === 'variant' ? 'selected' : '' ?>>variant</option>
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
                        'headerOptions' => ['style' => 'width:60px'],
                    ],
                    [
                        'attribute' => 'lane',
                        'label' => 'Лейн',
                        'format' => 'raw',
                        'headerOptions' => ['style' => 'width:120px'],
                        'value' => function ($model) {
                            $laneMap = [
                                'content_updated' => 'lane-content',
                                'price_updated' => 'lane-price',
                                'stock_updated' => 'lane-stock',
                            ];
                            $cls = $laneMap[$model->lane] ?? '';
                            return '<span class="lane-badge ' . $cls . '">' . Html::encode($model->lane) . '</span>';
                        },
                    ],
                    [
                        'attribute' => 'source_event',
                        'label' => 'Событие',
                        'headerOptions' => ['style' => 'width:120px'],
                        'format' => 'raw',
                        'value' => function ($model) {
                            return '<code style="font-size:.78rem">' . Html::encode($model->source_event) . '</code>';
                        },
                    ],
                    [
                        'attribute' => 'entity_type',
                        'label' => 'Тип',
                        'headerOptions' => ['style' => 'width:70px'],
                    ],
                    [
                        'attribute' => 'entity_id',
                        'label' => 'ID',
                        'headerOptions' => ['style' => 'width:60px'],
                    ],
                    [
                        'attribute' => 'model_id',
                        'label' => 'Модель',
                        'format' => 'raw',
                        'headerOptions' => ['style' => 'width:70px'],
                        'value' => function ($model) {
                            return Html::a($model->model_id, ['/catalog/view', 'id' => $model->model_id],
                                ['style' => 'color:var(--accent);font-weight:500']);
                        },
                    ],
                    [
                        'attribute' => 'status',
                        'format' => 'raw',
                        'headerOptions' => ['style' => 'width:100px'],
                        'value' => function ($model) {
                            $map = [
                                'pending' => 'badge-pending',
                                'processing' => 'badge-processing',
                                'success' => 'badge-success',
                                'error' => 'badge-error',
                                'failed' => 'badge-failed',
                            ];
                            $badge = $map[$model->status] ?? 'badge-draft';
                            $retry = $model->retry_count > 0
                                ? ' <sup style="color:var(--text-secondary);font-size:.65rem">×' . $model->retry_count . '</sup>'
                                : '';
                            return '<span class="badge-status ' . $badge . '">' . Html::encode($model->status) . '</span>' . $retry;
                        },
                    ],
                    [
                        'attribute' => 'created_at',
                        'label' => 'Создано',
                        'headerOptions' => ['style' => 'width:120px'],
                        'value' => function ($model) {
                            return $model->created_at ? Yii::$app->formatter->asRelativeTime($model->created_at) : '—';
                        },
                    ],
                    [
                        'attribute' => 'processed_at',
                        'label' => 'Обработано',
                        'headerOptions' => ['style' => 'width:120px'],
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
                                return '<span style="color:var(--text-muted)">—</span>';
                            }
                            $short = mb_strimwidth($model->error_log, 0, 100, '...');
                            return '<span style="color:var(--danger);font-size:.78rem" title="' . Html::encode($model->error_log) . '">'
                                . Html::encode($short) . '</span>';
                        },
                    ],
                ],
            ]) ?>
        </div>
    </div>

</div>

<?php
$liveUrl = Url::to(['/outbox-ui/live-stats']);
$js = <<<JS
(function() {
    var timer = null;
    function fmt(n) { return n.toString().replace(/\\B(?=(\\d{3})+(?!\\d))/g, " "); }
    function update() {
        fetch('{$liveUrl}', {headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(function(r){return r.json()})
        .then(function(d) {
            document.getElementById('live-dot').style.background = 'var(--success)';
            document.getElementById('live-time').textContent = d.timestamp;
            var cards = document.querySelectorAll('.stat-card .stat-value');
            if (cards[0]) cards[0].textContent = fmt(d.total);
            if (cards[1]) cards[1].textContent = fmt(d.pending);
            if (cards[2]) cards[2].textContent = fmt(d.processing);
            if (cards[3]) cards[3].textContent = fmt(d.success);
            if (cards[4]) cards[4].textContent = fmt((d.error||0) + (d.failed||0));
        })
        .catch(function(){ document.getElementById('live-dot').style.background='var(--danger)'; });
    }
    document.addEventListener('visibilitychange', function() {
        if (document.hidden) { clearInterval(timer); timer=null; }
        else { update(); timer=setInterval(update, 8000); }
    });
    timer = setInterval(update, 8000);
})();
JS;
$this->registerJs($js);
?>
