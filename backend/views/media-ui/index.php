<?php

/** @var yii\web\View $this */
/** @var yii\data\ActiveDataProvider $dataProvider */
/** @var string|null $status */
/** @var string|null $entityType */
/** @var array $stats */

use common\components\S3UrlGenerator;
use yii\grid\GridView;
use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'Медиа-файлы (S3)';
$this->params['breadcrumbs'][] = 'Медиа (S3)';

// Преобразуем статистику в удобный формат
$statusCounts = [];
foreach ($stats as $row) {
    $statusCounts[$row['status']] = (int)$row['cnt'];
}
$totalAssets = array_sum($statusCounts);
?>
<div class="media-ui-index">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0" style="font-weight:700">&#128247; Медиа-файлы (S3/MinIO)</h3>
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
                    <div class="stat-value"><?= number_format($totalAssets) ?></div>
                    <div class="stat-label">Всего</div>
                </div>
            </a>
        </div>
        <?php
        $statusColors = [
            'pending' => 'warning',
            'downloading' => 'info',
            'processed' => 'success',
            'deduplicated' => 'info',
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
                        'value' => function ($model) {
                            return $model->entity_type;
                        },
                    ],
                    [
                        'attribute' => 'entity_id',
                        'label' => 'Entity ID',
                        'headerOptions' => ['style' => 'width:80px'],
                        'format' => 'raw',
                        'value' => function ($model) {
                            if ($model->entity_type === 'model') {
                                return Html::a($model->entity_id, ['/catalog/view', 'id' => $model->entity_id],
                                    ['style' => 'color:var(--accent)']);
                            }
                            return $model->entity_id;
                        },
                    ],
                    [
                        'attribute' => 'file_hash',
                        'label' => 'Hash',
                        'headerOptions' => ['style' => 'width:120px'],
                        'value' => function ($model) {
                            return $model->file_hash ? substr($model->file_hash, 0, 12) . '...' : '—';
                        },
                    ],
                    [
                        'attribute' => 'status',
                        'format' => 'raw',
                        'headerOptions' => ['style' => 'width:110px'],
                        'value' => function ($model) {
                            $map = [
                                'processed' => 'badge-active',
                                'deduplicated' => 'badge-active',
                                'pending' => 'badge-pending',
                                'downloading' => 'badge-partial',
                                'error' => 'badge-failed',
                            ];
                            $cls = $map[$model->status] ?? 'badge-draft';
                            return '<span class="badge-status ' . $cls . '">' . Html::encode($model->status) . '</span>';
                        },
                    ],
                    [
                        'label' => 'Превью',
                        'format' => 'raw',
                        'headerOptions' => ['style' => 'width:80px;text-align:center'],
                        'contentOptions' => ['style' => 'text-align:center'],
                        'value' => function ($model) {
                            if ($model->status === 'processed' || $model->status === 'deduplicated') {
                                $thumbUrl = $model->getThumbUrl();
                                $fullUrl = $model->getPublicUrl();
                                if ($thumbUrl) {
                                    return Html::a(
                                        Html::img($thumbUrl, [
                                            'style' => 'width:50px;height:50px;object-fit:cover;border-radius:4px',
                                            'loading' => 'lazy',
                                        ]),
                                        $fullUrl,
                                        ['target' => '_blank']
                                    );
                                }
                            }
                            return '<span style="color:var(--text-secondary)">—</span>';
                        },
                    ],
                    [
                        'attribute' => 'mime_type',
                        'label' => 'MIME',
                        'headerOptions' => ['style' => 'width:100px'],
                        'value' => function ($model) {
                            return $model->mime_type ?? '—';
                        },
                    ],
                    [
                        'attribute' => 'size_bytes',
                        'label' => 'Размер',
                        'headerOptions' => ['style' => 'width:90px;text-align:right'],
                        'contentOptions' => ['style' => 'text-align:right'],
                        'value' => function ($model) {
                            return $model->size_bytes ? Yii::$app->formatter->asShortSize($model->size_bytes) : '—';
                        },
                    ],
                    [
                        'attribute' => 'attempts',
                        'label' => 'Поп.',
                        'headerOptions' => ['style' => 'width:50px;text-align:center'],
                        'contentOptions' => ['style' => 'text-align:center'],
                    ],
                    [
                        'attribute' => 'created_at',
                        'label' => 'Создано',
                        'headerOptions' => ['style' => 'width:140px'],
                        'value' => function ($model) {
                            return $model->created_at ? Yii::$app->formatter->asRelativeTime($model->created_at) : '—';
                        },
                    ],
                ],
            ]) ?>
        </div>
    </div>

</div>

<?php
$liveUrl = Url::to(['/media-ui/live-stats']);
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
            var vals = [d.total, d.pending, d.downloading, d.processed, d.deduplicated, d.error];
            cards.forEach(function(c,i){ if(i < vals.length) c.textContent = fmt(vals[i]); });
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
$this->registerCss('@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}');
?>
