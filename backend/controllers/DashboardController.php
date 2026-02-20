<?php

namespace backend\controllers;

use common\models\Brand;
use common\models\MediaAsset;
use common\models\ProductModel;
use common\models\ReferenceVariant;
use common\models\Supplier;
use common\models\SupplierOffer;
use yii\filters\AccessControl;
use yii\web\Controller;
use yii\web\Response;
use Yii;

/**
 * Дашборд агрегатора — главная страница админки с live-обновлением.
 */
class DashboardController extends Controller
{
    public function behaviors(): array
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    ['allow' => true, 'roles' => ['@']],
                ],
            ],
        ];
    }

    /**
     * Главная страница дашборда.
     */
    public function actionIndex(): string
    {
        $stats = $this->collectStats();

        return $this->render('index', [
            'stats' => $stats,
        ]);
    }

    /**
     * AJAX-эндпоинт для live-обновления статистики.
     * GET /dashboard/live-stats
     */
    public function actionLiveStats(): Response
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        return $this->asJson($this->collectStats());
    }

    /**
     * Собрать всю статистику для дашборда.
     */
    protected function collectStats(): array
    {
        $db = Yii::$app->db;

        // ═══ MDM Core ═══
        $models = (int)$db->createCommand("SELECT count(*) FROM {{%product_models}}")->queryScalar();
        $modelsActive = (int)$db->createCommand("SELECT count(*) FROM {{%product_models}} WHERE status='active'")->queryScalar();
        $variants = (int)$db->createCommand("SELECT count(*) FROM {{%reference_variants}}")->queryScalar();
        $offers = (int)$db->createCommand("SELECT count(*) FROM {{%supplier_offers}}")->queryScalar();
        $suppliers = Supplier::find()->where(['is_active' => true])->all();

        // ═══ Media Assets ═══
        $mediaStats = $db->createCommand("
            SELECT status, count(*) as cnt, COALESCE(SUM(size_bytes), 0) as total_size
            FROM {{%media_assets}}
            GROUP BY status
        ")->queryAll();

        $media = ['pending' => 0, 'downloading' => 0, 'processed' => 0, 'deduplicated' => 0, 'error' => 0, 'total' => 0, 'total_size' => 0];
        foreach ($mediaStats as $row) {
            $media[$row['status']] = (int)$row['cnt'];
            $media['total'] += (int)$row['cnt'];
            $media['total_size'] += (int)$row['total_size'];
        }
        $media['ready'] = $media['processed'] + $media['deduplicated'];

        // ═══ Outbox ═══
        $outboxStats = $db->createCommand("
            SELECT status, count(*) as cnt
            FROM {{%marketplace_outbox}}
            GROUP BY status
        ")->queryAll();

        $outbox = ['pending' => 0, 'processing' => 0, 'success' => 0, 'error' => 0, 'total' => 0];
        foreach ($outboxStats as $row) {
            $outbox[$row['status']] = (int)$row['cnt'];
            $outbox['total'] += (int)$row['cnt'];
        }

        // Уникальные модели в outbox pending
        $outbox['pending_models'] = (int)$db->createCommand("
            SELECT count(DISTINCT model_id) FROM {{%marketplace_outbox}} WHERE status='pending'
        ")->queryScalar();

        // ═══ Staging ═══
        $stagingCount = 0;
        try {
            $stagingCount = (int)$db->createCommand("SELECT count(*) FROM {{%staging_raw_offers}}")->queryScalar();
        } catch (\Exception $e) {}

        // ═══ Последняя сессия импорта ═══
        $lastSession = $db->createCommand("
            SELECT * FROM {{%import_sessions}} ORDER BY created_at DESC LIMIT 1
        ")->queryOne();

        // ═══ Brands / Categories ═══
        $brandsCount = (int)$db->createCommand("SELECT count(*) FROM {{%brands}}")->queryScalar();
        $categoriesCount = (int)$db->createCommand("SELECT count(*) FROM {{%categories}}")->queryScalar();

        // ═══ Recent Models (с картинками) ═══
        $recentModels = $db->createCommand("
            SELECT pm.id, pm.name, pm.product_family, pm.best_price, pm.variant_count, pm.status,
                   pm.created_at, b.canonical_name as brand_name,
                   (SELECT s3_thumb_key FROM {{%media_assets}} ma
                    WHERE ma.entity_type='model' AND ma.entity_id=pm.id
                    AND ma.status IN ('processed','deduplicated') AND ma.s3_key IS NOT NULL
                    ORDER BY ma.is_primary DESC, ma.sort_order ASC LIMIT 1) as thumb_key,
                   (SELECT s3_bucket FROM {{%media_assets}} ma
                    WHERE ma.entity_type='model' AND ma.entity_id=pm.id
                    AND ma.status IN ('processed','deduplicated') AND ma.s3_key IS NOT NULL
                    ORDER BY ma.is_primary DESC, ma.sort_order ASC LIMIT 1) as thumb_bucket
            FROM {{%product_models}} pm
            LEFT JOIN {{%brands}} b ON b.id = pm.brand_id
            ORDER BY pm.created_at DESC
            LIMIT 12
        ")->queryAll();

        return [
            'mdm' => [
                'models' => $models,
                'models_active' => $modelsActive,
                'variants' => $variants,
                'offers' => $offers,
            ],
            'media' => $media,
            'outbox' => $outbox,
            'staging' => $stagingCount,
            'suppliers' => array_map(fn($s) => [
                'code' => $s->code,
                'name' => $s->name,
                'format' => $s->format,
                'is_active' => $s->is_active,
                'last_import_at' => $s->last_import_at,
                'offers_count' => $s->getOffersCount(),
            ], $suppliers),
            'refs' => [
                'brands' => $brandsCount,
                'categories' => $categoriesCount,
            ],
            'lastSession' => $lastSession,
            'recentModels' => $recentModels,
            'timestamp' => date('H:i:s'),
        ];
    }
}
