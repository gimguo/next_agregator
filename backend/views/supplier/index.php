<?php

/** @var yii\web\View $this */
/** @var yii\data\ActiveDataProvider $dataProvider */

use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'Поставщики';
$this->params['breadcrumbs'][] = $this->title;

$models = $dataProvider->getModels();
?>
<div class="supplier-index">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0" style="font-weight:700">Поставщики</h3>
        <span style="color:var(--text-secondary)"><?= count($models) ?> шт.</span>
    </div>

    <div class="row g-3">
        <?php if (empty($models)): ?>
            <div class="col-12">
                <div class="card">
                    <div class="card-body text-center py-5" style="color:var(--text-secondary)">
                        <div style="font-size:3rem;margin-bottom:12px">&#127970;</div>
                        <h5>Нет поставщиков</h5>
                        <p>Запустите первый импорт прайс-листа:</p>
                        <code>docker compose exec php php yii import/run ormatek /app/storage/prices/ormatek/All.xml</code>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($models as $supplier): ?>
                <div class="col-xl-4 col-md-6">
                    <div class="card h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div>
                                    <h5 class="mb-0" style="font-weight:600">
                                        <a href="<?= Url::to(['/supplier/view', 'id' => $supplier->id]) ?>"
                                           style="color:var(--accent);text-decoration:none">
                                            <?= Html::encode($supplier->name) ?>
                                        </a>
                                    </h5>
                                    <code style="font-size:.8rem"><?= Html::encode($supplier->code) ?></code>
                                </div>
                                <span class="badge-status badge-<?= $supplier->is_active ? 'active' : 'inactive' ?>">
                                    <?= $supplier->is_active ? 'Активен' : 'Отключён' ?>
                                </span>
                            </div>

                            <table class="table table-sm mb-0" style="font-size:.85rem">
                                <tr>
                                    <td style="color:var(--text-secondary);border:none;padding:3px 0">Формат</td>
                                    <td style="border:none;padding:3px 0"><?= Html::encode($supplier->format ?: '—') ?></td>
                                </tr>
                                <tr>
                                    <td style="color:var(--text-secondary);border:none;padding:3px 0">Офферов</td>
                                    <td style="border:none;padding:3px 0"><?= number_format($supplier->getOffersCount()) ?></td>
                                </tr>
                                <tr>
                                    <td style="color:var(--text-secondary);border:none;padding:3px 0">Последний импорт</td>
                                    <td style="border:none;padding:3px 0">
                                        <?php if ($supplier->last_import_at): ?>
                                            <?= Yii::$app->formatter->asRelativeTime($supplier->last_import_at) ?>
                                        <?php else: ?>
                                            <span style="color:var(--text-secondary)">никогда</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php if ($supplier->website): ?>
                                    <tr>
                                        <td style="color:var(--text-secondary);border:none;padding:3px 0">Сайт</td>
                                        <td style="border:none;padding:3px 0">
                                            <a href="<?= Html::encode($supplier->website) ?>" target="_blank"
                                               style="color:var(--accent)"><?= Html::encode(parse_url($supplier->website, PHP_URL_HOST)) ?></a>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
