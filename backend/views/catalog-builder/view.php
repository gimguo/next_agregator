<?php

/**
 * @var yii\web\View $this
 * @var common\models\CatalogPreview $preview
 * @var array $categories Структура категорий из preview_data
 * @var array $productsByCategory [category_id => [model_id, ...]]
 * @var common\models\Supplier[] $suppliers
 */

use yii\helpers\Html;
use yii\helpers\Url;
use yii\widgets\DetailView;

$this->title = $preview->name ?: 'Превью каталога #' . $preview->id;
$this->params['breadcrumbs'][] = ['label' => 'Конструктор каталога', 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;

// Строим дерево категорий (parent_id -> children)
$categoryTree = [];
$rootCategories = [];
foreach ($categories as $cat) {
    $catId = $cat['id'] ?? null;
    $parentId = $cat['parent_id'] ?? null;
    $productCount = isset($productsByCategory[$catId]) ? count($productsByCategory[$catId]) : 0;
    
    $categoryTree[$catId] = [
        'data' => $cat,
        'product_count' => $productCount,
        'children' => [],
    ];
    
    if ($parentId === null) {
        $rootCategories[] = $catId;
    } else {
        if (!isset($categoryTree[$parentId])) {
            $categoryTree[$parentId] = ['data' => [], 'product_count' => 0, 'children' => []];
        }
        $categoryTree[$parentId]['children'][] = $catId;
    }
}

// Функция для форматирования правил категории
$formatRules = function ($rules) {
    if (empty($rules) || !is_array($rules)) {
        return null;
    }
    
    $parts = [];
    
    // Family
    if (isset($rules['family']) && is_array($rules['family'])) {
        $familyLabels = [];
        foreach ($rules['family'] as $family) {
            $familyLabels[] = Html::encode($family);
        }
        if (!empty($familyLabels)) {
            $parts[] = 'Семейство: ' . implode(', ', $familyLabels);
        }
    }
    
    // Attributes
    if (isset($rules['attributes']) && is_array($rules['attributes'])) {
        foreach ($rules['attributes'] as $attrKey => $allowedValues) {
            if (is_array($allowedValues) && !empty($allowedValues)) {
                $valueStr = implode(', ', array_map(function($v) { return Html::encode($v); }, $allowedValues));
                $parts[] = Html::encode($attrKey) . ': ' . $valueStr;
            }
        }
    }
    
    return !empty($parts) ? implode('; ', $parts) : null;
};

// Функция для рекурсивного вывода категорий
$renderCategory = function ($catId) use (&$renderCategory, $categoryTree, $productsByCategory, $formatRules) {
    $node = $categoryTree[$catId] ?? null;
    if (!$node) return '';
    
    $cat = $node['data'];
    $name = $cat['name'] ?? 'Без названия';
    $slug = $cat['slug'] ?? '';
    $productCount = $node['product_count'];
    $children = $node['children'];
    $rules = $cat['rules'] ?? null;
    $rulesText = $formatRules($rules);
    
    $html = '<li class="category-item mb-2">';
    $html .= '<div class="d-flex align-items-center justify-content-between p-2" style="background:var(--bg-elevated);border-radius:6px;border:1px solid var(--border)">';
    $html .= '<div class="flex-grow-1">';
    $html .= '<div class="d-flex align-items-center mb-1">';
    $html .= '<i class="fas fa-folder me-2" style="color:var(--accent);font-size:.85rem"></i>';
    $html .= '<strong style="font-size:.9rem">' . Html::encode($name) . '</strong>';
    if ($slug) {
        $html .= ' <code style="font-size:.7rem;margin-left:8px;color:var(--text-muted)">' . Html::encode($slug) . '</code>';
    }
    $html .= '</div>';
    if ($rulesText) {
        $html .= '<div style="font-size:.7rem;color:var(--text-muted);margin-left:24px;font-style:italic">';
        $html .= '<i class="fas fa-filter me-1" style="font-size:.65rem"></i>' . $rulesText;
        $html .= '</div>';
    }
    $html .= '</div>';
    $html .= '<span class="badge bg-info ms-2" style="font-size:.75rem;font-weight:600">' . number_format($productCount) . '</span>';
    $html .= '</div>';
    
    if (!empty($children)) {
        $html .= '<ul class="category-children mt-2 ms-4" style="list-style:none;padding:0">';
        foreach ($children as $childId) {
            $html .= $renderCategory($childId);
        }
        $html .= '</ul>';
    }
    
    $html .= '</li>';
    return $html;
};
?>

<div class="catalog-builder-view">

    <!-- ═══ Сводка ═══ -->
    <div class="row g-4 mb-4">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-info-circle me-2"></i>Информация о каталоге
                </div>
                <div class="card-body">
                    <?= DetailView::widget([
                        'model' => $preview,
                        'options' => ['class' => 'table detail-view mb-0'],
                        'attributes' => [
                            [
                                'label' => 'Название',
                                'value' => $preview->name ?: 'Без названия',
                            ],
                            [
                                'label' => 'Шаблон',
                                'format' => 'raw',
                                'value' => $preview->template
                                    ? '<span class="badge bg-secondary">' . Html::encode($preview->template->name) . '</span>'
                                    : '—',
                            ],
                            [
                                'label' => 'Поставщики',
                                'format' => 'raw',
                                'value' => function () use ($suppliers) {
                                    if (empty($suppliers)) return '—';
                                    $names = array_map(fn($s) => Html::encode($s->name), $suppliers);
                                    return implode(', ', $names);
                                },
                            ],
                            [
                                'label' => 'Товаров',
                                'value' => number_format($preview->product_count),
                            ],
                            [
                                'label' => 'Категорий',
                                'value' => number_format($preview->category_count),
                            ],
                            [
                                'label' => 'Создано',
                                'value' => Yii::$app->formatter->asDatetime($preview->created_at),
                            ],
                        ],
                    ]) ?>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card" style="border-left:3px solid var(--success)">
                <div class="card-body text-center">
                    <div style="font-size:2.5rem;font-weight:700;color:var(--success);line-height:1">
                        <?= number_format($preview->product_count) ?>
                    </div>
                    <div style="color:var(--text-secondary);font-size:.85rem;margin-top:4px">
                        товаров в каталоге
                    </div>
                    <div style="font-size:1.5rem;font-weight:600;color:var(--accent);margin-top:12px">
                        <?= number_format($preview->category_count) ?>
                    </div>
                    <div style="color:var(--text-secondary);font-size:.85rem">
                        категорий
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ Дерево категорий ═══ -->
    <div class="card mb-4">
        <div class="card-header">
            <i class="fas fa-sitemap me-2"></i>Структура каталога
        </div>
        <div class="card-body">
            <?php if (empty($rootCategories)): ?>
                <div class="text-center py-4" style="color:var(--text-muted)">
                    <i class="fas fa-folder-open fa-2x mb-2" style="opacity:.3"></i><br>
                    Категории не найдены
                </div>
            <?php else: ?>
                <ul class="category-tree" style="list-style:none;padding:0;margin:0">
                    <?php foreach ($rootCategories as $rootId): ?>
                        <?= $renderCategory($rootId) ?>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <!-- ═══ Кнопка экспорта ═══ -->
    <div class="card" style="border-left:3px solid var(--success)">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-1" style="font-weight:600">
                        <i class="fas fa-rocket me-2" style="color:var(--success)"></i>Экспорт на витрину
                    </h5>
                    <p class="mb-0" style="color:var(--text-secondary);font-size:.85rem">
                        Каталог будет отправлен в очередь Outbox. Сначала создадутся категории, затем товары.
                    </p>
                </div>
                <?= Html::beginForm(['export', 'id' => $preview->id], 'post', [
                    'style' => 'margin:0',
                ]) ?>
                    <?= Html::submitButton(
                        '<i class="fas fa-rocket me-2"></i> Экспортировать на витрину (RosMatras)',
                        [
                            'class' => 'btn btn-success btn-lg',
                            'data-confirm' => 'Отправить каталог в очередь на выгрузку?',
                        ]
                    ) ?>
                <?= Html::endForm() ?>
            </div>
        </div>
    </div>

    <!-- ═══ История экспортов ═══ -->
    <?php
    $exports = \common\models\CatalogExport::find()
        ->where(['preview_id' => $preview->id])
        ->orderBy(['created_at' => SORT_DESC])
        ->limit(5)
        ->all();
    ?>
    <?php if (!empty($exports)): ?>
    <div class="card mt-4">
        <div class="card-header">
            <i class="fas fa-history me-2"></i>История экспортов
        </div>
        <div class="card-body p-0">
            <table class="table table-striped mb-0" style="font-size:.85rem">
                <thead>
                    <tr>
                        <th style="width:100px">Статус</th>
                        <th>Статистика</th>
                        <th style="width:160px">Дата</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($exports as $export): ?>
                        <tr>
                            <td>
                                <?php
                                $statusMap = [
                                    'pending' => ['class' => 'badge-pending', 'label' => 'Ожидание'],
                                    'processing' => ['class' => 'badge-partial', 'label' => 'Обработка'],
                                    'completed' => ['class' => 'badge-active', 'label' => 'Завершён'],
                                    'failed' => ['class' => 'badge-error', 'label' => 'Ошибка'],
                                ];
                                $status = $statusMap[$export->status] ?? ['class' => 'badge-draft', 'label' => $export->status];
                                ?>
                                <span class="badge-status <?= $status['class'] ?>" style="font-size:.7rem">
                                    <?= $status['label'] ?>
                                </span>
                            </td>
                            <td>
                                <?php
                                $stats = $export->getStatsArray();
                                if (!empty($stats)):
                                ?>
                                    <span style="color:var(--text-secondary);font-size:.8rem">
                                        Товаров: <?= number_format($stats['products'] ?? 0) ?>,
                                        Категорий: <?= number_format($stats['categories'] ?? 0) ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color:var(--text-muted)">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span style="color:var(--text-secondary);font-size:.8rem">
                                    <?= Yii::$app->formatter->asDatetime($export->created_at) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <div class="mt-4">
        <?= Html::a(
            '<i class="fas fa-arrow-left me-1"></i> Назад к списку',
            ['index'],
            ['class' => 'btn btn-dark-outline']
        ) ?>
        <?= Html::a(
            '<i class="fas fa-trash me-1"></i> Удалить',
            ['delete', 'id' => $preview->id],
            [
                'class' => 'btn btn-danger ms-2',
                'data-confirm' => 'Удалить превью каталога?',
                'data-method' => 'post',
            ]
        ) ?>
    </div>

</div>
