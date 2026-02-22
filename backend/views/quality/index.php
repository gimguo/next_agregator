<?php

/** @var yii\web\View $this */
/** @var array $channelStats */
/** @var array $topProblems */
/** @var yii\data\ActiveDataProvider $dataProvider */
/** @var int $selectedChannelId */
/** @var common\models\SalesChannel[] $channels */

use common\dto\ReadinessReportDTO;
use yii\grid\GridView;
use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'Качество данных';
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="quality-index">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-1" style="font-weight:700">
                <i class="fas fa-clipboard-check" style="color:var(--accent)"></i> Качество данных и готовность каналов
            </h3>
            <div style="font-size:.85rem;color:var(--text-secondary)">
                Оценка полноты карточек товаров для каждого канала продаж
            </div>
        </div>
        <div style="font-size:.82rem;color:var(--text-muted)">
            <code>php yii quality/scan</code> — пересчёт &nbsp;|&nbsp;
            <code>php yii quality/heal</code> — AI-лечение
        </div>
    </div>

    <!-- ═══ Channel Stats Cards ═══ -->
    <?php if (!empty($channelStats)): ?>
    <div class="row g-3 mb-4">
        <?php foreach ($channelStats as $cs): ?>
            <?php
            $ch = $cs['channel'];
            $pct = $cs['total'] > 0 ? round($cs['ready'] / $cs['total'] * 100, 1) : 0;
            $pctColor = $pct >= 90 ? 'success' : ($pct >= 70 ? 'warning' : 'danger');
            ?>
            <div class="col-xl-6">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span>
                            <i class="fas fa-store me-1"></i>
                            <strong><?= Html::encode($ch->name) ?></strong>
                            <span style="color:var(--text-muted);font-size:.8rem;margin-left:6px">(<?= $ch->driver ?>)</span>
                        </span>
                        <a href="<?= Url::to(['index', 'channel_id' => $ch->id]) ?>" class="btn btn-sm btn-dark-outline">
                            <i class="fas fa-list me-1"></i> Проблемные
                        </a>
                    </div>
                    <div class="card-body">
                        <div class="row g-2 text-center mb-3">
                            <div class="col-3">
                                <div class="stat-mini">
                                    <div class="stat-value" style="color:var(--text-primary)"><?= number_format($cs['total']) ?></div>
                                    <div class="stat-label">Всего</div>
                                </div>
                            </div>
                            <div class="col-3">
                                <div class="stat-mini">
                                    <div class="stat-value" style="color:var(--success)"><?= number_format($cs['ready']) ?></div>
                                    <div class="stat-label">Готовы</div>
                                </div>
                            </div>
                            <div class="col-3">
                                <div class="stat-mini">
                                    <div class="stat-value" style="color:var(--danger)"><?= number_format($cs['notReady']) ?></div>
                                    <div class="stat-label">Не готовы</div>
                                </div>
                            </div>
                            <div class="col-3">
                                <div class="stat-mini">
                                    <div class="stat-value" style="color:var(--purple)"><?= number_format($cs['healedOk']) ?></div>
                                    <div class="stat-label">Исцелено AI</div>
                                </div>
                            </div>
                        </div>
                        <div class="progress" style="height:10px">
                            <div class="progress-bar bg-<?= $pctColor ?>" style="width:<?= $pct ?>%"></div>
                        </div>
                        <div class="d-flex justify-content-between mt-2" style="font-size:.78rem;color:var(--text-secondary)">
                            <span><strong style="color:var(--<?= $pctColor ?>)"><?= $pct ?>%</strong> готовы</span>
                            <span>Средний скор: <strong><?= $cs['avgScore'] ?>%</strong></span>
                            <?php if ($cs['healed'] > 0): ?>
                                <span>AI попыток: <?= $cs['healed'] ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ═══ Top Problems ═══ -->
    <?php if (!empty($topProblems)): ?>
    <div class="card mb-4">
        <div class="card-header">
            <i class="fas fa-exclamation-triangle me-2" style="color:var(--warning)"></i> Топ проблем (пропущенные поля)
        </div>
        <div class="card-body p-0">
            <table class="table table-striped mb-0">
                <thead>
                <tr>
                    <th style="width:50px">#</th>
                    <th>Поле</th>
                    <th>Описание</th>
                    <th style="width:120px;text-align:right">Моделей</th>
                    <th style="width:180px">Доля</th>
                </tr>
                </thead>
                <tbody>
                <?php
                $maxCount = max(array_values($topProblems) ?: [1]);
                $i = 1;
                foreach ($topProblems as $field => $count):
                    $barPct = round($count / $maxCount * 100);
                    $label = ReadinessReportDTO::labelFor($field);
                    $isHealable = !in_array($field, ['required:image', 'required:barcode', 'required:price']);
                    ?>
                    <tr>
                        <td style="color:var(--text-muted)"><?= $i++ ?></td>
                        <td>
                            <code style="font-size:.8rem"><?= Html::encode($field) ?></code>
                            <?php if ($isHealable): ?>
                                <span style="font-size:.65rem;color:var(--purple);margin-left:4px" title="AI может исправить">
                                    <i class="fas fa-wand-magic-sparkles"></i>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:.85rem"><?= Html::encode($label) ?></td>
                        <td style="text-align:right"><strong><?= number_format($count) ?></strong></td>
                        <td>
                            <div class="progress" style="height:6px">
                                <div class="progress-bar bg-warning" style="width:<?= $barPct ?>%"></div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- ═══ Problem Models List ═══ -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>
                <i class="fas fa-list me-2"></i> Не-ready модели
                <?php
                $selectedName = '';
                foreach ($channels as $ch) {
                    if ($ch->id == $selectedChannelId) {
                        $selectedName = $ch->name;
                    }
                }
                ?>
                <span style="color:var(--text-secondary);font-size:.85rem">(<?= Html::encode($selectedName) ?>)</span>
            </span>
            <div class="d-flex gap-2">
                <?php foreach ($channels as $ch): ?>
                    <a href="<?= Url::to(['index', 'channel_id' => $ch->id]) ?>"
                       class="btn btn-sm <?= $ch->id == $selectedChannelId ? 'btn-accent' : 'btn-dark-outline' ?>">
                        <?= Html::encode($ch->name) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="card-body p-0">
            <?= GridView::widget([
                'dataProvider' => $dataProvider,
                'tableOptions' => ['class' => 'table table-striped mb-0'],
                'layout' => "{items}\n<div class='card-footer d-flex justify-content-between align-items-center'>{summary}{pager}</div>",
                'columns' => [
                    [
                        'attribute' => 'model_id',
                        'label' => 'Модель',
                        'format' => 'raw',
                        'headerOptions' => ['style' => 'width:70px'],
                        'value' => function ($m) {
                            return Html::a($m->model_id, ['/catalog/view', 'id' => $m->model_id],
                                ['style' => 'color:var(--accent);font-weight:500']);
                        },
                    ],
                    [
                        'attribute' => 'score',
                        'label' => 'Скор',
                        'format' => 'raw',
                        'headerOptions' => ['style' => 'width:130px'],
                        'value' => function ($m) {
                            $color = $m->score >= 80 ? 'success' : ($m->score >= 50 ? 'warning' : 'danger');
                            return '<div class="d-flex align-items-center gap-2">'
                                . '<div class="progress" style="width:60px;height:6px"><div class="progress-bar bg-' . $color . '" style="width:' . $m->score . '%"></div></div>'
                                . '<strong style="color:var(--' . $color . ');font-size:.82rem">' . $m->score . '%</strong>'
                                . '</div>';
                        },
                    ],
                    [
                        'attribute' => 'missing_fields',
                        'label' => 'Пропущенные поля',
                        'format' => 'raw',
                        'value' => function ($m) {
                            $fields = is_array($m->missing_fields)
                                ? $m->missing_fields
                                : json_decode($m->missing_fields ?? '[]', true);

                            if (empty($fields)) return '<span style="color:var(--text-muted)">—</span>';

                            $tags = [];
                            foreach (array_slice($fields, 0, 5) as $f) {
                                $label = ReadinessReportDTO::labelFor($f);
                                $tags[] = '<span class="badge-status badge-pending" style="font-size:.65rem;margin:1px" title="' . Html::encode($f) . '">'
                                    . Html::encode(mb_strimwidth($label, 0, 30, '...'))
                                    . '</span>';
                            }
                            if (count($fields) > 5) {
                                $tags[] = '<span style="color:var(--text-muted);font-size:.72rem">+' . (count($fields) - 5) . '</span>';
                            }
                            return implode(' ', $tags);
                        },
                    ],
                    [
                        'attribute' => 'last_heal_attempt_at',
                        'label' => 'AI лечение',
                        'format' => 'raw',
                        'headerOptions' => ['style' => 'width:130px'],
                        'value' => function ($m) {
                            if ($m->last_heal_attempt_at) {
                                return '<span style="color:var(--purple);font-size:.82rem">'
                                    . Yii::$app->formatter->asRelativeTime($m->last_heal_attempt_at)
                                    . '</span>';
                            }
                            return '<span style="color:var(--text-muted);font-size:.82rem">не было</span>';
                        },
                    ],
                    [
                        'attribute' => 'checked_at',
                        'label' => 'Обновлено',
                        'headerOptions' => ['style' => 'width:120px'],
                        'value' => function ($m) {
                            return $m->checked_at ? Yii::$app->formatter->asRelativeTime($m->checked_at) : '—';
                        },
                    ],
                ],
            ]) ?>
        </div>
    </div>

</div>
