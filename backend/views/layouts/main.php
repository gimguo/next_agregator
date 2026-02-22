<?php

/** @var \yii\web\View $this */
/** @var string $content */

use backend\assets\AppAsset;
use common\widgets\Alert;
use yii\bootstrap5\Breadcrumbs;
use yii\bootstrap5\Html;
use yii\helpers\Url;

AppAsset::register($this);

$controller = Yii::$app->controller->id;
$action = Yii::$app->controller->action->id ?? '';
$isGuest = Yii::$app->user->isGuest;
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

<?php if (!$isGuest): ?>

<!-- ═══ TOP BAR ═══ -->
<header class="pim-topbar">
    <div class="pim-topbar-inner">
        <a href="<?= Url::to(['/dashboard/index']) ?>" class="pim-topbar-brand">
            <i class="fas fa-cubes"></i>
            <span>PIM Агрегатор</span>
        </a>

        <div class="pim-topbar-right">
            <a href="https://ros-matras.ru" target="_blank" class="pim-topbar-link" title="Открыть витрину">
                <i class="fas fa-external-link-alt"></i>
                <span class="d-none d-sm-inline">Витрина</span>
            </a>
            <div class="pim-topbar-user">
                <i class="fas fa-user-circle"></i>
                <span><?= Html::encode(Yii::$app->user->identity->username) ?></span>
            </div>
            <?= Html::beginForm(['/site/logout'], 'post', ['class' => 'd-inline']) ?>
                <?= Html::submitButton('<i class="fas fa-sign-out-alt"></i>', [
                    'class' => 'pim-topbar-logout',
                    'title' => 'Выход',
                ]) ?>
            <?= Html::endForm() ?>

            <!-- Mobile sidebar toggle -->
            <button class="pim-sidebar-toggle d-xl-none" id="sidebar-toggle" type="button" title="Меню">
                <i class="fas fa-bars"></i>
            </button>
        </div>
    </div>
</header>

<!-- ═══ LAYOUT: Content + Right Sidebar ═══ -->
<div class="pim-layout">

    <!-- MAIN CONTENT -->
    <main class="pim-main" role="main">
        <div class="pim-content">
            <?= Breadcrumbs::widget([
                'links' => isset($this->params['breadcrumbs']) ? $this->params['breadcrumbs'] : [],
            ]) ?>
            <?= Alert::widget() ?>
            <?= $content ?>
        </div>
    </main>

    <!-- ═══ RIGHT SIDEBAR ═══ -->
    <aside class="pim-sidebar" id="pim-sidebar">
        <div class="pim-sidebar-scroll">

            <!-- ── Навигация ── -->
            <nav class="pim-nav">

                <div class="pim-nav-section">
                    <div class="pim-nav-section-label">Основное</div>
                    <a href="<?= Url::to(['/dashboard/index']) ?>"
                       class="pim-nav-item <?= $controller === 'dashboard' ? 'active' : '' ?>">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Дашборд</span>
                    </a>
                </div>

                <div class="pim-nav-section">
                    <div class="pim-nav-section-label">КАТАЛОГ</div>
                    <a href="<?= Url::to(['/catalog/index']) ?>"
                       class="pim-nav-item <?= $controller === 'catalog' && $controller !== 'catalog-builder' ? 'active' : '' ?>">
                        <i class="fas fa-database"></i>
                        <span>MDM Модели</span>
                        <?php
                        try {
                            $modelCount = \common\models\ProductModel::find()->count();
                            echo '<span class="pim-nav-badge">' . number_format($modelCount) . '</span>';
                        } catch (\Exception $e) {}
                        ?>
                    </a>
                    <a href="<?= Url::to(['/catalog-builder/index']) ?>"
                       class="pim-nav-item <?= $controller === 'catalog-builder' ? 'active' : '' ?>">
                        <i class="fas fa-sitemap"></i>
                        <span>Конструктор каталога</span>
                    </a>
                    <a href="<?= Url::to(['/catalog-template/index']) ?>"
                       class="pim-nav-item <?= $controller === 'catalog-template' ? 'active' : '' ?>">
                        <i class="fas fa-file-code"></i>
                        <span>Шаблоны каталога</span>
                    </a>
                    <a href="<?= Url::to(['/pricing-rule/index']) ?>"
                       class="pim-nav-item <?= $controller === 'pricing-rule' ? 'active' : '' ?>">
                        <i class="fas fa-tags"></i>
                        <span>Наценки</span>
                    </a>
                </div>

                <div class="pim-nav-section">
                    <div class="pim-nav-section-label">ДАННЫЕ</div>
                    <a href="<?= Url::to(['/supplier/index']) ?>"
                       class="pim-nav-item <?= $controller === 'supplier' ? 'active' : '' ?>">
                        <i class="fas fa-building"></i>
                        <span>Поставщики</span>
                    </a>
                    <a href="<?= Url::to(['/supplier-fetch-config/index']) ?>"
                       class="pim-nav-item <?= $controller === 'supplier-fetch-config' ? 'active' : '' ?>">
                        <i class="fas fa-cloud-arrow-down"></i>
                        <span>Сборщик прайсов</span>
                    </a>
                    <a href="<?= Url::to(['/staging-ui/index']) ?>"
                       class="pim-nav-item <?= $controller === 'staging-ui' ? 'active' : '' ?>">
                        <i class="fas fa-layer-group"></i>
                        <span>Сырые данные</span>
                    </a>
                    <a href="<?= Url::to(['/media-ui/index']) ?>"
                       class="pim-nav-item <?= $controller === 'media-ui' ? 'active' : '' ?>">
                        <i class="fas fa-images"></i>
                        <span>Медиа</span>
                    </a>
                </div>

                <div class="pim-nav-section">
                    <div class="pim-nav-section-label">ЭКСПОРТ & КАЧЕСТВО</div>
                    <a href="<?= Url::to(['/outbox-ui/index']) ?>"
                       class="pim-nav-item <?= $controller === 'outbox-ui' ? 'active' : '' ?>">
                        <i class="fas fa-paper-plane"></i>
                        <span>Outbox</span>
                    </a>
                    <a href="<?= Url::to(['/quality/index']) ?>"
                       class="pim-nav-item <?= $controller === 'quality' ? 'active' : '' ?>">
                        <i class="fas fa-clipboard-check"></i>
                        <span>Качество данных</span>
                    </a>
                </div>

                <div class="pim-nav-section">
                    <div class="pim-nav-section-label">СИСТЕМА</div>
                    <a href="<?= Url::to(['/queue-dashboard/index']) ?>"
                       class="pim-nav-item <?= $controller === 'queue-dashboard' ? 'active' : '' ?>">
                        <i class="fas fa-list-check"></i>
                        <span>Очередь (Redis)</span>
                    </a>
                    <a href="/adminer" target="_blank" class="pim-nav-item">
                        <i class="fas fa-database"></i>
                        <span>Adminer</span>
                        <i class="fas fa-external-link-alt pim-nav-external"></i>
                    </a>
                </div>

            </nav>

            <!-- ── Sidebar Footer ── -->
            <div class="pim-sidebar-footer">
                <div class="pim-sidebar-footer-line">
                    <i class="fas fa-code-branch"></i> v3.1
                </div>
                <div class="pim-sidebar-footer-line">
                    <i class="fas fa-database"></i> <?= Yii::$app->db->driverName ?>
                </div>
            </div>
        </div>
    </aside>

    <!-- Sidebar overlay (mobile) -->
    <div class="pim-sidebar-overlay d-xl-none" id="sidebar-overlay"></div>
</div>

<?php else: ?>
    <!-- Guest mode (login page) -->
    <main role="main" class="flex-shrink-0">
        <div class="container-fluid">
            <?= Alert::widget() ?>
            <?= $content ?>
        </div>
    </main>
<?php endif; ?>

<script>
// Sidebar toggle for mobile
(function() {
    var toggle = document.getElementById('sidebar-toggle');
    var sidebar = document.getElementById('pim-sidebar');
    var overlay = document.getElementById('sidebar-overlay');
    if (!toggle || !sidebar) return;

    function openSidebar() {
        sidebar.classList.add('open');
        if (overlay) overlay.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    function closeSidebar() {
        sidebar.classList.remove('open');
        if (overlay) overlay.classList.remove('active');
        document.body.style.overflow = '';
    }

    toggle.addEventListener('click', function() {
        sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
    });
    if (overlay) {
        overlay.addEventListener('click', closeSidebar);
    }
})();
</script>

<?php $this->endBody() ?>
</body>
</html>
<?php $this->endPage();
