<?php

/** @var yii\web\View $this */
/** @var array $stats */

use common\components\S3UrlGenerator;
use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'Дашборд';
?>
<div class="dashboard-index" id="dashboard">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0" style="font-weight:700">
            <i class="fas fa-cubes" style="color:var(--accent);margin-right:6px"></i> Центр управления
        </h3>
        <div class="d-flex align-items-center gap-3">
            <span id="live-indicator" class="d-flex align-items-center" style="font-size:.8rem;color:var(--text-secondary)">
                <span id="live-dot" style="width:8px;height:8px;border-radius:50%;background:var(--success);display:inline-block;margin-right:6px;animation:pulse 2s infinite"></span>
                <span id="live-time"><?= date('H:i:s') ?></span>
            </span>
        </div>
    </div>

    <!-- ═══ MDM Core Stats ═══ -->
    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6">
            <a href="<?= Url::to(['/catalog/index']) ?>" class="text-decoration-none">
                <div class="stat-card accent">
                    <div class="d-flex justify-content-between">
                        <div>
                            <div class="stat-value" id="stat-models"><?= number_format($stats['mdm']['models']) ?></div>
                            <div class="stat-label">Моделей товаров</div>
                            <div style="font-size:.75rem;color:var(--text-secondary);margin-top:4px">
                                <?= number_format($stats['mdm']['models_active']) ?> активных
                            </div>
                        </div>
                        <div class="stat-icon"><i class="fas fa-boxes-stacked"></i></div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="stat-card success">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="stat-value" id="stat-variants"><?= number_format($stats['mdm']['variants']) ?></div>
                        <div class="stat-label">Вариантов (SKU)</div>
                    </div>
                    <div class="stat-icon"><i class="fas fa-chart-bar"></i></div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="stat-card warning">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="stat-value" id="stat-offers"><?= number_format($stats['mdm']['offers']) ?></div>
                        <div class="stat-label">Офферов поставщиков</div>
                    </div>
                    <div class="stat-icon"><i class="fas fa-ruble-sign"></i></div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <a href="<?= Url::to(['/supplier/index']) ?>" class="text-decoration-none">
                <div class="stat-card info">
                    <div class="d-flex justify-content-between">
                        <div>
                            <div class="stat-value"><?= count($stats['suppliers']) ?></div>
                            <div class="stat-label">Поставщиков</div>
                            <div style="font-size:.75rem;color:var(--text-secondary);margin-top:4px">
                                <?= number_format($stats['refs']['brands']) ?> брендов &middot; <?= number_format($stats['refs']['categories']) ?> категорий
                            </div>
                        </div>
                        <div class="stat-icon"><i class="fas fa-building"></i></div>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <!-- ═══ Media Pipeline ═══ -->
        <div class="col-xl-6">
            <a href="<?= Url::to(['/media-ui/index']) ?>" class="text-decoration-none">
                <div class="card h-100" style="cursor:pointer">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-images me-2"></i> Медиа-пайплайн (S3)</span>
                        <span class="badge bg-secondary" id="media-total"><?= number_format($stats['media']['total']) ?></span>
                    </div>
                    <div class="card-body">
                        <div class="row text-center mb-3">
                            <div class="col queue-stat">
                                <div class="value" id="media-ready" style="color:var(--success)"><?= number_format($stats['media']['ready']) ?></div>
                                <div class="label">Готово</div>
                            </div>
                            <div class="col queue-stat">
                                <div class="value" id="media-pending" style="color:var(--warning)"><?= number_format($stats['media']['pending']) ?></div>
                                <div class="label">Ожидает</div>
                            </div>
                            <div class="col queue-stat">
                                <div class="value" id="media-downloading" style="color:var(--info)"><?= number_format($stats['media']['downloading']) ?></div>
                                <div class="label">Качается</div>
                            </div>
                            <div class="col queue-stat">
                                <div class="value" id="media-error" style="color:var(--danger)"><?= number_format($stats['media']['error']) ?></div>
                                <div class="label">Ошибки</div>
                            </div>
                        </div>
                        <?php
                        $mTotal = max($stats['media']['total'], 1);
                        $mReadyPct = round($stats['media']['ready'] / $mTotal * 100, 1);
                        $mDlPct = round($stats['media']['downloading'] / $mTotal * 100, 1);
                        $mErrPct = round($stats['media']['error'] / $mTotal * 100, 1);
                        ?>
                        <div class="progress" style="height:12px;border-radius:6px" id="media-progress">
                            <div class="progress-bar bg-success" id="media-bar-ready" style="width:<?= $mReadyPct ?>%" title="Готово: <?= $mReadyPct ?>%"></div>
                            <div class="progress-bar bg-info" id="media-bar-dl" style="width:<?= $mDlPct ?>%"></div>
                            <div class="progress-bar bg-danger" id="media-bar-err" style="width:<?= $mErrPct ?>%"></div>
                        </div>
                        <div class="d-flex justify-content-between mt-2" style="font-size:.78rem;color:var(--text-secondary)">
                            <span id="media-pct"><?= $mReadyPct ?>% обработано</span>
                            <span id="media-size"><?= Yii::$app->formatter->asShortSize($stats['media']['total_size']) ?></span>
                        </div>
                    </div>
                </div>
            </a>
        </div>

        <!-- ═══ Outbox / Export ═══ -->
        <div class="col-xl-6">
            <a href="<?= Url::to(['/outbox-ui/index']) ?>" class="text-decoration-none">
                <div class="card h-100" style="cursor:pointer">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-paper-plane me-2"></i> Экспорт (Outbox)</span>
                        <span class="badge bg-secondary" id="outbox-total"><?= number_format($stats['outbox']['total']) ?></span>
                    </div>
                    <div class="card-body">
                        <div class="row text-center mb-3">
                            <div class="col queue-stat">
                                <div class="value" id="outbox-pending" style="color:var(--warning)"><?= number_format($stats['outbox']['pending']) ?></div>
                                <div class="label">Pending</div>
                            </div>
                            <div class="col queue-stat">
                                <div class="value" id="outbox-processing" style="color:var(--info)"><?= number_format($stats['outbox']['processing']) ?></div>
                                <div class="label">Processing</div>
                            </div>
                            <div class="col queue-stat">
                                <div class="value" id="outbox-success" style="color:var(--success)"><?= number_format($stats['outbox']['success']) ?></div>
                                <div class="label">Success</div>
                            </div>
                            <div class="col queue-stat">
                                <div class="value" id="outbox-error" style="color:var(--danger)"><?= number_format($stats['outbox']['error'] + $stats['outbox']['failed']) ?></div>
                                <div class="label">Error/DLQ</div>
                            </div>
                        </div>
                        <?php
                        $oTotal = max($stats['outbox']['total'], 1);
                        $oSuccPct = round($stats['outbox']['success'] / $oTotal * 100, 1);
                        $oPendPct = round($stats['outbox']['pending'] / $oTotal * 100, 1);
                        $oProcPct = round($stats['outbox']['processing'] / $oTotal * 100, 1);
                        $oErrPct = round(($stats['outbox']['error'] + $stats['outbox']['failed']) / $oTotal * 100, 1);
                        ?>
                        <div class="progress" style="height:12px;border-radius:6px" id="outbox-progress">
                            <div class="progress-bar bg-success" id="outbox-bar-success" style="width:<?= $oSuccPct ?>%"></div>
                            <div class="progress-bar bg-info" id="outbox-bar-proc" style="width:<?= $oProcPct ?>%"></div>
                            <div class="progress-bar bg-warning" id="outbox-bar-pend" style="width:<?= $oPendPct ?>%"></div>
                            <div class="progress-bar bg-danger" id="outbox-bar-err" style="width:<?= $oErrPct ?>%"></div>
                        </div>
                        <div class="d-flex justify-content-between mt-2" style="font-size:.78rem;color:var(--text-secondary)">
                            <span id="outbox-models-info">
                                <?= $stats['outbox']['pending_models'] ?> моделей к отправке
                                <?php
                                $lanes = $stats['outbox']['lanes'] ?? [];
                                if (!empty($lanes)):
                                    $lParts = [];
                                    foreach ($lanes as $l => $c) {
                                        $lParts[] = "{$l}:{$c}";
                                    }
                                ?>
                                    <span class="ms-1" style="opacity:.7">(<?= implode(', ', $lParts) ?>)</span>
                                <?php endif; ?>
                            </span>
                            <span id="outbox-pct"><?= $oSuccPct ?>% доставлено</span>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <!-- ═══ Quality & AI Healing Row ═══ -->
    <div class="row g-3 mb-4">
        <!-- Readiness -->
        <div class="col-xl-4">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-clipboard-check me-2"></i> Готовность каналов</span>
                    <a href="<?= Url::to(['/quality/index']) ?>" class="btn btn-sm btn-dark-outline">Подробнее</a>
                </div>
                <div class="card-body">
                    <?php if (empty($stats['readiness'])): ?>
                        <div class="text-center py-3" style="color:var(--text-secondary)">
                            <i class="fas fa-info-circle"></i> Запустите <code>quality/scan</code>
                        </div>
                    <?php else: ?>
                        <?php foreach ($stats['readiness'] as $ch): ?>
                            <?php
                            $chTotal = max((int)$ch['total'], 1);
                            $chReady = (int)$ch['ready'];
                            $chPct = round($chReady / $chTotal * 100, 1);
                            $chColor = $chPct >= 90 ? 'success' : ($chPct >= 70 ? 'warning' : 'danger');
                            ?>
                            <div class="mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <strong style="font-size:.88rem"><?= Html::encode($ch['channel_name']) ?></strong>
                                    <span style="font-size:.82rem">
                                        <span style="color:var(--<?= $chColor ?>)"><?= $chReady ?></span>
                                        / <?= $ch['total'] ?> готовы
                                    </span>
                                </div>
                                <div class="progress" style="height:8px">
                                    <div class="progress-bar bg-<?= $chColor ?>" style="width:<?= $chPct ?>%"></div>
                                </div>
                                <div class="d-flex justify-content-between mt-1" style="font-size:.72rem;color:var(--text-secondary)">
                                    <span><?= $chPct ?>%</span>
                                    <span>Ср. скор: <?= $ch['avg_score'] ?>%</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- AI Healing -->
        <div class="col-xl-4">
            <div class="card h-100">
                <div class="card-header">
                    <i class="fas fa-wand-magic-sparkles me-2"></i> AI Auto-Healing
                </div>
                <div class="card-body">
                    <div class="row g-2 text-center mb-3">
                        <div class="col-4">
                            <div class="stat-mini">
                                <div class="stat-value" style="color:var(--accent)"><?= number_format($stats['healing']['total_healed']) ?></div>
                                <div class="stat-label">Попыток</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="stat-mini">
                                <div class="stat-value" style="color:var(--success)"><?= number_format($stats['healing']['healed_and_ready']) ?></div>
                                <div class="stat-label">Исцелено</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="stat-mini">
                                <div class="stat-value" style="color:var(--warning)"><?= number_format($stats['healing']['queue_waiting']) ?></div>
                                <div class="stat-label">В очереди</div>
                            </div>
                        </div>
                    </div>
                    <?php if ($stats['healing']['total_healed'] > 0): ?>
                        <?php
                        $healPct = round($stats['healing']['healed_and_ready'] / max($stats['healing']['total_healed'], 1) * 100, 1);
                        ?>
                        <div style="font-size:.82rem;color:var(--text-secondary);text-align:center">
                            Успешность: <strong style="color:var(--success)"><?= $healPct ?>%</strong>
                        </div>
                    <?php else: ?>
                        <div class="text-center" style="font-size:.82rem;color:var(--text-secondary)">
                            <code>quality/heal --channel=rosmatras</code>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Pricing -->
        <div class="col-xl-4">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-tags me-2"></i> Pricing Engine</span>
                    <a href="<?= Url::to(['/pricing-rule/index']) ?>" class="btn btn-sm btn-dark-outline">Правила</a>
                </div>
                <div class="card-body">
                    <div class="row g-2 text-center mb-3">
                        <div class="col-4">
                            <div class="stat-mini">
                                <div class="stat-value" style="color:var(--accent)"><?= $stats['pricing']['active_rules'] ?></div>
                                <div class="stat-label">Правил</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="stat-mini">
                                <div class="stat-value" style="color:var(--success)"><?= number_format($stats['pricing']['with_retail_price']) ?></div>
                                <div class="stat-label">С наценкой</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="stat-mini">
                                <div class="stat-value" style="color:var(--text-secondary)"><?= number_format($stats['pricing']['total_offers']) ?></div>
                                <div class="stat-label">Всего</div>
                            </div>
                        </div>
                    </div>
                    <?php
                    $pricingPct = $stats['pricing']['total_offers'] > 0
                        ? round($stats['pricing']['with_retail_price'] / $stats['pricing']['total_offers'] * 100, 1)
                        : 0;
                    ?>
                    <div class="progress" style="height:6px">
                        <div class="progress-bar bg-success" style="width:<?= $pricingPct ?>%"></div>
                    </div>
                    <div class="text-center mt-1" style="font-size:.72rem;color:var(--text-secondary)">
                        <?= $pricingPct ?>% офферов с розничной ценой
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ Suppliers ═══ -->
    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-building me-2"></i> Поставщики</span>
                    <a href="<?= Url::to(['/supplier/index']) ?>" class="btn btn-sm btn-dark-outline">Управление</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($stats['suppliers'])): ?>
                        <div class="p-4 text-center" style="color:var(--text-secondary)">
                            Нет активных поставщиков.
                        </div>
                    <?php else: ?>
                        <table class="table table-striped mb-0">
                            <thead>
                            <tr>
                                <th>Код</th>
                                <th>Название</th>
                                <th>Формат</th>
                                <th style="text-align:center">Офферов</th>
                                <th>Последний импорт</th>
                                <th>Статус</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($stats['suppliers'] as $s): ?>
                                <tr>
                                    <td><code><?= Html::encode($s['code']) ?></code></td>
                                    <td><?= Html::encode($s['name']) ?></td>
                                    <td><?= Html::encode($s['format']) ?></td>
                                    <td style="text-align:center"><strong><?= number_format($s['offers_count']) ?></strong></td>
                                    <td>
                                        <?php if ($s['last_import_at']): ?>
                                            <?= Yii::$app->formatter->asRelativeTime($s['last_import_at']) ?>
                                        <?php else: ?>
                                            <span style="color:var(--text-secondary)">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge-status badge-<?= $s['is_active'] ? 'active' : 'inactive' ?>">
                                            <?= $s['is_active'] ? 'Активен' : 'Откл.' ?>
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

    <!-- ═══ Scheduler ═══ -->
    <?php if (!empty($stats['scheduler'])): ?>
    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-clock me-2"></i> Планировщик (Auto-Fetch)</span>
                    <code style="font-size:.78rem">* * * * * php yii scheduler/run</code>
                </div>
                <div class="card-body p-0">
                    <table class="table table-striped mb-0">
                        <thead>
                        <tr>
                            <th>Поставщик</th>
                            <th>Тип</th>
                            <th>Расписание</th>
                            <th>Последний забор</th>
                            <th>Статус</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($stats['scheduler'] as $cfg): ?>
                            <tr>
                                <td><strong><?= Html::encode($cfg['supplier_name']) ?></strong></td>
                                <td><code><?= Html::encode($cfg['source_type']) ?></code></td>
                                <td><code><?= Html::encode($cfg['cron_schedule'] ?? '—') ?></code></td>
                                <td>
                                    <?php if ($cfg['last_fetch_at']): ?>
                                        <?= Yii::$app->formatter->asRelativeTime($cfg['last_fetch_at']) ?>
                                    <?php else: ?>
                                        <span style="color:var(--text-secondary)">никогда</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $fetchStatus = $cfg['last_status'] ?? 'unknown';
                                    $fetchBadge = match ($fetchStatus) {
                                        'success' => 'badge-active',
                                        'error' => 'badge-failed',
                                        default => 'badge-pending',
                                    };
                                    ?>
                                    <span class="badge-status <?= $fetchBadge ?>"><?= Html::encode($fetchStatus) ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ═══ Recent Models (with images) ═══ -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="fas fa-clock-rotate-left me-2"></i> Последние модели</span>
            <a href="<?= Url::to(['/catalog/index']) ?>" class="btn btn-sm btn-dark-outline">Все модели</a>
        </div>
        <div class="card-body p-0" id="recent-models-body">
            <?php if (empty($stats['recentModels'])): ?>
                <div class="p-4 text-center" style="color:var(--text-secondary)">
                    Модели товаров пока не созданы. Запустите импорт прайс-листа.
                </div>
            <?php else: ?>
                <table class="table table-striped mb-0">
                    <thead>
                    <tr>
                        <th style="width:60px"></th>
                        <th>ID</th>
                        <th>Название</th>
                        <th>Бренд</th>
                        <th>Family</th>
                        <th style="text-align:right">Лучшая цена</th>
                        <th style="text-align:center">Вар-тов</th>
                        <th>Статус</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($stats['recentModels'] as $m): ?>
                        <?php
                        $thumbUrl = null;
                        if (!empty($m['thumb_key']) && !empty($m['thumb_bucket'])) {
                            $thumbUrl = S3UrlGenerator::getPublicUrl($m['thumb_bucket'], $m['thumb_key']);
                        }
                        $familyLabels = ['mattress'=>'Матрас','pillow'=>'Подушка','bed'=>'Кровать','topper'=>'Топпер','blanket'=>'Одеяло','base'=>'Основание'];
                        ?>
                        <tr>
                            <td style="padding:4px 8px">
                                <?php if ($thumbUrl): ?>
                                    <img src="<?= Html::encode($thumbUrl) ?>"
                                         alt="" loading="lazy"
                                         style="width:44px;height:44px;object-fit:cover;border-radius:6px;border:1px solid var(--border)">
                                <?php else: ?>
                                    <div style="width:44px;height:44px;border-radius:6px;background:var(--bg-body);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;color:var(--text-secondary);font-size:.7rem">
                                        <i class="fas fa-image"></i>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><?= $m['id'] ?></td>
                            <td>
                                <a href="<?= Url::to(['/catalog/view', 'id' => $m['id']]) ?>"
                                   style="color:var(--accent);text-decoration:none;font-weight:500">
                                    <?= Html::encode(mb_strimwidth($m['name'], 0, 60, '...')) ?>
                                </a>
                            </td>
                            <td><?= Html::encode($m['brand_name'] ?: '—') ?></td>
                            <td>
                                <span class="badge-status badge-partial" style="font-size:.7rem">
                                    <?= $familyLabels[$m['product_family']] ?? $m['product_family'] ?>
                                </span>
                            </td>
                            <td style="text-align:right">
                                <?php if ($m['best_price']): ?>
                                    <strong><?= number_format($m['best_price'], 0, '.', ' ') ?> &#8381;</strong>
                                <?php else: ?>
                                    <span style="color:var(--text-secondary)">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center"><?= $m['variant_count'] ?></td>
                            <td>
                                <span class="badge-status badge-<?= $m['status'] === 'active' ? 'active' : 'draft' ?>" style="font-size:.7rem">
                                    <?= $m['status'] ?>
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

<?php
// ═══ JavaScript: AJAX Live Update ═══
$liveUrl = Url::to(['/dashboard/live-stats']);
$js = <<<JS

(function() {
    var refreshInterval = 10000;
    var timer = null;

    function formatNumber(n) {
        return n.toString().replace(/\\B(?=(\\d{3})+(?!\\d))/g, " ");
    }

    function formatSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        if (bytes < 1073741824) return (bytes / 1048576).toFixed(1) + ' MB';
        return (bytes / 1073741824).toFixed(2) + ' GB';
    }

    function updateStats() {
        fetch('{$liveUrl}', {
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            var dot = document.getElementById('live-dot');
            if (dot) dot.style.background = 'var(--success)';
            var timeEl = document.getElementById('live-time');
            if (timeEl) timeEl.textContent = d.timestamp;

            setText('stat-models', formatNumber(d.mdm.models));
            setText('stat-variants', formatNumber(d.mdm.variants));
            setText('stat-offers', formatNumber(d.mdm.offers));

            var m = d.media;
            setText('media-total', formatNumber(m.total));
            setText('media-ready', formatNumber(m.ready));
            setText('media-pending', formatNumber(m.pending));
            setText('media-downloading', formatNumber(m.downloading));
            setText('media-error', formatNumber(m.error));

            var mTotal = Math.max(m.total, 1);
            setWidth('media-bar-ready', (m.ready / mTotal * 100).toFixed(1) + '%');
            setWidth('media-bar-dl', (m.downloading / mTotal * 100).toFixed(1) + '%');
            setWidth('media-bar-err', (m.error / mTotal * 100).toFixed(1) + '%');
            setText('media-pct', (m.ready / mTotal * 100).toFixed(1) + '% обработано');
            setText('media-size', formatSize(m.total_size));

            var o = d.outbox;
            setText('outbox-total', formatNumber(o.total));
            setText('outbox-pending', formatNumber(o.pending));
            setText('outbox-processing', formatNumber(o.processing));
            setText('outbox-success', formatNumber(o.success));
            setText('outbox-error', formatNumber((o.error || 0) + (o.failed || 0)));

            var oTotal = Math.max(o.total, 1);
            setWidth('outbox-bar-success', (o.success / oTotal * 100).toFixed(1) + '%');
            setWidth('outbox-bar-proc', (o.processing / oTotal * 100).toFixed(1) + '%');
            setWidth('outbox-bar-pend', (o.pending / oTotal * 100).toFixed(1) + '%');
            setWidth('outbox-bar-err', (((o.error||0)+(o.failed||0)) / oTotal * 100).toFixed(1) + '%');
            setText('outbox-pct', (o.success / oTotal * 100).toFixed(1) + '% доставлено');
        })
        .catch(function(err) {
            var dot = document.getElementById('live-dot');
            if (dot) dot.style.background = 'var(--danger)';
        });
    }

    function setText(id, text) {
        var el = document.getElementById(id);
        if (el) el.textContent = text;
    }
    function setWidth(id, w) {
        var el = document.getElementById(id);
        if (el) el.style.width = w;
    }

    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            clearInterval(timer);
            timer = null;
        } else {
            updateStats();
            timer = setInterval(updateStats, refreshInterval);
        }
    });

    timer = setInterval(updateStats, refreshInterval);
})();

JS;
$this->registerJs($js);
?>
