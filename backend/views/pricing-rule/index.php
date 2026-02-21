<?php

/** @var yii\web\View $this */
/** @var yii\data\ActiveDataProvider $dataProvider */
/** @var int $totalRules */
/** @var int $activeRules */

use common\models\PricingRule;
use yii\grid\GridView;
use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'Правила ценообразования';
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="pricing-rule-index">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-1" style="font-weight:700">
                <i class="fas fa-tags" style="color:var(--accent)"></i> Правила ценообразования
            </h3>
            <div style="font-size:.85rem;color:var(--text-secondary)">
                <?= $totalRules ?> правил, из них <strong style="color:var(--success)"><?= $activeRules ?></strong> активных
            </div>
        </div>
        <a href="<?= Url::to(['create']) ?>" class="btn btn-accent">
            <i class="fas fa-plus me-1"></i> Добавить правило
        </a>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <?= GridView::widget([
                'dataProvider' => $dataProvider,
                'tableOptions' => ['class' => 'table table-striped mb-0'],
                'layout' => "{items}\n<div class='card-footer d-flex justify-content-between align-items-center'>{summary}{pager}</div>",
                'columns' => [
                    [
                        'attribute' => 'id',
                        'headerOptions' => ['style' => 'width:50px'],
                    ],
                    [
                        'attribute' => 'name',
                        'label' => 'Название',
                        'format' => 'raw',
                        'value' => function (PricingRule $m) {
                            $icon = $m->is_active
                                ? '<i class="fas fa-circle fa-xs me-1" style="color:var(--success)"></i>'
                                : '<i class="fas fa-circle fa-xs me-1" style="color:var(--text-muted)"></i>';
                            return $icon . Html::a(Html::encode($m->name), ['update', 'id' => $m->id],
                                    ['style' => 'color:var(--accent);font-weight:500']);
                        },
                    ],
                    [
                        'attribute' => 'target_type',
                        'label' => 'Цель',
                        'format' => 'raw',
                        'headerOptions' => ['style' => 'width:160px'],
                        'value' => function (PricingRule $m) {
                            $typeLabel = PricingRule::targetTypes()[$m->target_type] ?? $m->target_type;
                            $detail = '';
                            if ($m->target_type !== PricingRule::TARGET_GLOBAL) {
                                $val = $m->target_value ?: ($m->target_id ? "ID:{$m->target_id}" : '');
                                if ($val) {
                                    $detail = ' <span style="color:var(--text-secondary);font-size:.78rem">' . Html::encode($val) . '</span>';
                                }
                            }
                            return '<span class="badge-status badge-partial" style="font-size:.7rem">' . $typeLabel . '</span>' . $detail;
                        },
                    ],
                    [
                        'attribute' => 'markup_value',
                        'label' => 'Наценка',
                        'format' => 'raw',
                        'headerOptions' => ['style' => 'width:130px;text-align:right'],
                        'contentOptions' => ['style' => 'text-align:right'],
                        'value' => function (PricingRule $m) {
                            $val = $m->markup_type === PricingRule::MARKUP_PERCENTAGE
                                ? '+' . rtrim(rtrim(number_format($m->markup_value, 2), '0'), '.') . '%'
                                : '+' . number_format($m->markup_value, 0, '.', ' ') . ' ₽';
                            $color = 'var(--success)';
                            return '<strong style="color:' . $color . '">' . $val . '</strong>';
                        },
                    ],
                    [
                        'attribute' => 'rounding',
                        'label' => 'Округление',
                        'headerOptions' => ['style' => 'width:120px'],
                        'value' => function (PricingRule $m) {
                            return PricingRule::roundingStrategies()[$m->rounding] ?? $m->rounding;
                        },
                    ],
                    [
                        'attribute' => 'priority',
                        'label' => 'Приоритет',
                        'format' => 'raw',
                        'headerOptions' => ['style' => 'width:80px;text-align:center'],
                        'contentOptions' => ['style' => 'text-align:center'],
                        'value' => function (PricingRule $m) {
                            $color = $m->priority >= 100 ? 'var(--danger)' : ($m->priority >= 50 ? 'var(--warning)' : 'var(--text-secondary)');
                            return '<strong style="color:' . $color . '">' . $m->priority . '</strong>';
                        },
                    ],
                    [
                        'attribute' => 'is_active',
                        'label' => 'Статус',
                        'format' => 'raw',
                        'headerOptions' => ['style' => 'width:90px;text-align:center'],
                        'contentOptions' => ['style' => 'text-align:center'],
                        'value' => function (PricingRule $m) {
                            return $m->is_active
                                ? '<span class="badge-status badge-active">Актив</span>'
                                : '<span class="badge-status badge-inactive">Откл.</span>';
                        },
                    ],
                    [
                        'class' => 'yii\grid\ActionColumn',
                        'template' => '{toggle} {update} {delete}',
                        'headerOptions' => ['style' => 'width:120px;text-align:center'],
                        'contentOptions' => ['style' => 'text-align:center;white-space:nowrap'],
                        'buttons' => [
                            'toggle' => function ($url, PricingRule $m) {
                                $icon = $m->is_active ? 'fa-pause' : 'fa-play';
                                $title = $m->is_active ? 'Деактивировать' : 'Активировать';
                                $color = $m->is_active ? 'var(--warning)' : 'var(--success)';
                                return Html::a(
                                    '<i class="fas ' . $icon . '"></i>',
                                    ['toggle', 'id' => $m->id],
                                    ['title' => $title, 'style' => "color:{$color};margin-right:8px"]
                                );
                            },
                            'update' => function ($url, PricingRule $m) {
                                return Html::a(
                                    '<i class="fas fa-pen"></i>',
                                    ['update', 'id' => $m->id],
                                    ['title' => 'Редактировать', 'style' => 'color:var(--accent);margin-right:8px']
                                );
                            },
                            'delete' => function ($url, PricingRule $m) {
                                return Html::a(
                                    '<i class="fas fa-trash"></i>',
                                    ['delete', 'id' => $m->id],
                                    [
                                        'title' => 'Удалить',
                                        'style' => 'color:var(--danger)',
                                        'data-method' => 'post',
                                        'data-confirm' => "Удалить правило «{$m->name}»?",
                                    ]
                                );
                            },
                        ],
                    ],
                ],
            ]) ?>
        </div>
    </div>

    <div class="mt-3" style="font-size:.82rem;color:var(--text-muted)">
        <i class="fas fa-info-circle me-1"></i>
        Для массового пересчёта цен после изменения правил используйте: <code>php yii pricing/recalculate --all</code>
    </div>
</div>
