<?php

/** @var yii\web\View $this */
/** @var array $stats */
/** @var array $aiLogs */

use yii\helpers\Html;

$this->title = 'Очередь';
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="queue-dashboard-index">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0" style="font-weight:700">Мониторинг очереди</h3>
        <div style="color:var(--text-secondary);font-size:.85rem">
            Канал: <code>agregator-queue</code> &middot; Redis
        </div>
    </div>

    <!-- ═══ Queue Stats ═══ -->
    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="stat-card warning">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="stat-value"><?= number_format($stats['waiting']) ?></div>
                        <div class="stat-label">В ожидании</div>
                    </div>
                    <div class="stat-icon">&#9203;</div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="stat-card accent">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="stat-value"><?= number_format($stats['reserved']) ?></div>
                        <div class="stat-label">Выполняется</div>
                    </div>
                    <div class="stat-icon">&#9881;</div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="stat-card info">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="stat-value"><?= number_format($stats['delayed']) ?></div>
                        <div class="stat-label">Отложено</div>
                    </div>
                    <div class="stat-icon">&#128336;</div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="stat-card success">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="stat-value"><?= number_format($stats['done']) ?></div>
                        <div class="stat-label">Всего обработано</div>
                    </div>
                    <div class="stat-icon">&#9989;</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <!-- ═══ Console Commands ═══ -->
        <div class="col-xl-5">
            <div class="card h-100">
                <div class="card-header">Полезные команды</div>
                <div class="card-body">
                    <table class="table table-sm mb-0" style="font-size:.85rem">
                        <tr>
                            <td style="color:var(--text-secondary);border:none;width:40%">Импорт Ormatek</td>
                            <td style="border:none"><code>yii import/run ormatek &lt;file&gt;</code></td>
                        </tr>
                        <tr>
                            <td style="color:var(--text-secondary);border:none">Импорт в очередь</td>
                            <td style="border:none"><code>yii import/queue ormatek &lt;file&gt;</code></td>
                        </tr>
                        <tr>
                            <td style="color:var(--text-secondary);border:none">Лимит товаров</td>
                            <td style="border:none"><code>yii import/run ormatek &lt;file&gt; --max=100</code></td>
                        </tr>
                        <tr>
                            <td style="color:var(--text-secondary);border:none">Скачать картинки</td>
                            <td style="border:none"><code>yii import/images</code></td>
                        </tr>
                        <tr>
                            <td style="color:var(--text-secondary);border:none">Статистика</td>
                            <td style="border:none"><code>yii import/stats</code></td>
                        </tr>
                        <tr>
                            <td style="color:var(--text-secondary);border:none">Слушатель очереди</td>
                            <td style="border:none"><code>yii queue/listen --verbose=1</code></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <!-- ═══ AI Logs ═══ -->
        <div class="col-xl-7">
            <div class="card h-100">
                <div class="card-header">AI-логи (DeepSeek)</div>
                <div class="card-body p-0">
                    <?php if (empty($aiLogs)): ?>
                        <div class="p-4 text-center" style="color:var(--text-secondary)">
                            AI-запросов пока не было. Логи появятся после первой обработки.
                        </div>
                    <?php else: ?>
                        <table class="table table-striped mb-0" style="font-size:.85rem">
                            <thead>
                            <tr>
                                <th>Операция</th>
                                <th>Модель</th>
                                <th>Prompt</th>
                                <th>Completion</th>
                                <th>Время</th>
                                <th>Дата</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($aiLogs as $log): ?>
                                <tr>
                                    <td><code><?= Html::encode($log['operation']) ?></code></td>
                                    <td style="color:var(--text-secondary)"><?= Html::encode($log['model']) ?></td>
                                    <td><?= number_format($log['prompt_tokens'] ?? 0) ?></td>
                                    <td><?= number_format($log['completion_tokens'] ?? 0) ?></td>
                                    <td><?= $log['duration_ms'] ? round($log['duration_ms']) . 'ms' : '—' ?></td>
                                    <td style="color:var(--text-secondary)">
                                        <?= Yii::$app->formatter->asRelativeTime($log['created_at']) ?>
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
</div>
