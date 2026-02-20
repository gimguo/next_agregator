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
?>
<?php $this->beginPage() ?>
<!DOCTYPE html>
<html lang="<?= Yii::$app->language ?>" class="h-100">
<head>
    <meta charset="<?= Yii::$app->charset ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <?php $this->registerCsrfMetaTags() ?>
    <title><?= Html::encode($this->title) ?> — Агрегатор</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <?php $this->head() ?>
</head>
<body class="d-flex flex-column h-100">
<?php $this->beginBody() ?>

<header>
    <?php
    NavBar::begin([
        'brandLabel' => '<span style="color:#4f8cff">&#9670;</span> Агрегатор',
        'brandUrl' => ['/dashboard/index'],
        'options' => [
            'class' => 'navbar navbar-expand-md navbar-dark bg-dark fixed-top',
        ],
    ]);

    $menuItems = [];
    if (!Yii::$app->user->isGuest) {
        $menuItems = [
            [
                'label' => 'Дашборд',
                'url' => ['/dashboard/index'],
                'active' => $controller === 'dashboard',
            ],
            [
                'label' => 'MDM Каталог',
                'url' => ['/catalog/index'],
                'active' => $controller === 'catalog',
            ],
            [
                'label' => 'Карточки',
                'url' => ['/product-card/index'],
                'active' => $controller === 'product-card',
            ],
            [
                'label' => 'Поставщики',
                'url' => ['/supplier/index'],
                'active' => $controller === 'supplier',
            ],
            [
                'label' => 'Медиа (S3)',
                'url' => ['/media-ui/index'],
                'active' => $controller === 'media-ui',
            ],
            [
                'label' => 'Outbox',
                'url' => ['/outbox-ui/index'],
                'active' => $controller === 'outbox-ui',
            ],
            [
                'label' => 'Staging',
                'url' => ['/staging-ui/index'],
                'active' => $controller === 'staging-ui',
            ],
            [
                'label' => 'Очередь',
                'url' => ['/queue-dashboard/index'],
                'active' => $controller === 'queue-dashboard',
            ],
        ];
    }

    echo Nav::widget([
        'options' => ['class' => 'navbar-nav me-auto mb-2 mb-md-0'],
        'items' => $menuItems,
    ]);

    if (!Yii::$app->user->isGuest) {
        echo Html::beginForm(['/site/logout'], 'post', ['class' => 'd-flex align-items-center'])
            . '<span class="text-secondary me-2" style="font-size:.85rem">'
            . Html::encode(Yii::$app->user->identity->username)
            . '</span>'
            . Html::submitButton(
                'Выход',
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
        <span>&copy; Агрегатор <?= date('Y') ?></span>
        <span>v2.0 &middot; <?= Yii::$app->db->driverName ?></span>
    </div>
</footer>

<?php $this->endBody() ?>
</body>
</html>
<?php $this->endPage();
