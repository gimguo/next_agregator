<?php

/** @var \yii\web\View $this */
/** @var string $content */

use backend\assets\AppAsset;
use common\widgets\Alert;
use yii\bootstrap5\Breadcrumbs;
use yii\bootstrap5\Html;
use yii\bootstrap5\Nav;
use yii\bootstrap5\NavBar;

AppAsset::register($this);

$controller = Yii::$app->controller->id;
$action = Yii::$app->controller->action->id ?? '';
?>
<?php $this->beginPage() ?>
<!DOCTYPE html>
<html lang="<?= Yii::$app->language ?>" class="h-100" data-bs-theme="dark">
<head>
    <meta charset="<?= Yii::$app->charset ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <?php $this->registerCsrfMetaTags() ?>
    <title><?= Html::encode($this->title) ?> — PIM Агрегатор</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous">
    <?php $this->head() ?>
</head>
<body class="d-flex flex-column h-100">
<?php $this->beginBody() ?>

<header>
    <?php
    NavBar::begin([
        'brandLabel' => '<i class="fas fa-cubes" style="color:#4f8cff;margin-right:6px"></i> PIM Агрегатор',
        'brandUrl' => ['/dashboard/index'],
        'options' => [
            'class' => 'navbar navbar-expand-lg navbar-dark bg-dark fixed-top',
        ],
    ]);

    $menuItems = [];
    if (!Yii::$app->user->isGuest) {
        $menuItems = [
            [
                'label' => '<i class="fas fa-tachometer-alt"></i> Дашборд',
                'encode' => false,
                'url' => ['/dashboard/index'],
                'active' => $controller === 'dashboard',
            ],

            // ═══ Каталог (MDM) ═══
            [
                'label' => '<i class="fas fa-database"></i> Каталог',
                'encode' => false,
                'active' => in_array($controller, ['catalog', 'pricing-rule']),
                'items' => [
                    [
                        'label' => '<i class="fas fa-boxes-stacked fa-fw"></i> MDM Модели',
                        'encode' => false,
                        'url' => ['/catalog/index'],
                        'active' => $controller === 'catalog',
                    ],
                    '<li><hr class="dropdown-divider"></li>',
                    [
                        'label' => '<i class="fas fa-tags fa-fw"></i> Наценки (Pricing)',
                        'encode' => false,
                        'url' => ['/pricing-rule/index'],
                        'active' => $controller === 'pricing-rule',
                    ],
                ],
            ],

            // ═══ Данные ═══
            [
                'label' => '<i class="fas fa-truck"></i> Данные',
                'encode' => false,
                'active' => in_array($controller, ['supplier', 'supplier-fetch-config', 'staging-ui', 'media-ui']),
                'items' => [
                    [
                        'label' => '<i class="fas fa-building fa-fw"></i> Поставщики',
                        'encode' => false,
                        'url' => ['/supplier/index'],
                        'active' => $controller === 'supplier',
                    ],
                    [
                        'label' => '<i class="fas fa-layer-group fa-fw"></i> Staging (сырые)',
                        'encode' => false,
                        'url' => ['/staging-ui/index'],
                        'active' => $controller === 'staging-ui',
                    ],
                    [
                        'label' => '<i class="fas fa-images fa-fw"></i> Медиа (S3)',
                        'encode' => false,
                        'url' => ['/media-ui/index'],
                        'active' => $controller === 'media-ui',
                    ],
                ],
            ],

            // ═══ Экспорт и Качество ═══
            [
                'label' => '<i class="fas fa-rocket"></i> Экспорт',
                'encode' => false,
                'active' => in_array($controller, ['outbox-ui', 'quality']),
                'items' => [
                    [
                        'label' => '<i class="fas fa-paper-plane fa-fw"></i> Outbox',
                        'encode' => false,
                        'url' => ['/outbox-ui/index'],
                        'active' => $controller === 'outbox-ui',
                    ],
                    [
                        'label' => '<i class="fas fa-clipboard-check fa-fw"></i> Качество данных',
                        'encode' => false,
                        'url' => ['/quality/index'],
                        'active' => $controller === 'quality',
                    ],
                ],
            ],

            // ═══ Система ═══
            [
                'label' => '<i class="fas fa-server"></i> Система',
                'encode' => false,
                'active' => $controller === 'queue-dashboard',
                'items' => [
                    [
                        'label' => '<i class="fas fa-list-check fa-fw"></i> Очередь (Redis)',
                        'encode' => false,
                        'url' => ['/queue-dashboard/index'],
                        'active' => $controller === 'queue-dashboard',
                    ],
                    '<li><hr class="dropdown-divider"></li>',
                    [
                        'label' => '<i class="fas fa-database fa-fw"></i> Adminer',
                        'encode' => false,
                        'url' => '/adminer',
                        'linkOptions' => ['target' => '_blank'],
                    ],
                ],
            ],
        ];
    }

    echo Nav::widget([
        'options' => ['class' => 'navbar-nav me-auto mb-2 mb-lg-0'],
        'items' => $menuItems,
        'encodeLabels' => false,
        'dropdownClass' => 'yii\bootstrap5\Dropdown',
    ]);

    if (!Yii::$app->user->isGuest) {
        echo Html::beginForm(['/site/logout'], 'post', ['class' => 'd-flex align-items-center'])
            . '<span class="text-secondary me-2" style="font-size:.82rem">'
            . '<i class="fas fa-user-circle me-1"></i>'
            . Html::encode(Yii::$app->user->identity->username)
            . '</span>'
            . Html::submitButton(
                '<i class="fas fa-sign-out-alt"></i> Выход',
                ['class' => 'btn btn-sm btn-dark-outline']
            )
            . Html::endForm();
    }

    NavBar::end();
    ?>
</header>

<main role="main" class="flex-shrink-0">
    <div class="container-fluid">
        <?= Breadcrumbs::widget([
            'links' => isset($this->params['breadcrumbs']) ? $this->params['breadcrumbs'] : [],
        ]) ?>
        <?= Alert::widget() ?>
        <?= $content ?>
    </div>
</main>

<footer class="footer mt-auto">
    <div class="container-fluid d-flex justify-content-between">
        <span>&copy; PIM Агрегатор <?= date('Y') ?></span>
        <span class="d-flex align-items-center gap-3">
            <span>v3.0</span>
            <span><i class="fas fa-database fa-sm"></i> <?= Yii::$app->db->driverName ?></span>
        </span>
    </div>
</footer>

<?php $this->endBody() ?>
</body>
</html>
<?php $this->endPage();
