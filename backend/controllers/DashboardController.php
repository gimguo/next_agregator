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
        $stats['readiness'] = $this->collectReadinessStats();
        $stats['pricing'] = $this->collectPricingStats();
        $stats['healing'] = $this->collectHealingStats();
        $stats['scheduler'] = $this->collectSchedulerStats();

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
        $stats = $this->collectStats();
        $stats['readiness'] = $this->collectReadinessStats();
        return $this->asJson($stats);
    }

    /**
     * Собрать основную статистику.
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

        $outbox = ['pending' => 0, 'processing' => 0, 'success' => 0, 'error' => 0, 'failed' => 0, 'total' => 0];
        foreach ($outboxStats as $row) {
            $key = $row['status'];
            if (isset($outbox[$key])) {
                $outbox[$key] = (int)$row['cnt'];
            }
            $outbox['total'] += (int)$row['cnt'];
        }

        // Lane breakdown
        $laneStats = $db->createCommand("
            SELECT lane, count(*) as cnt
            FROM {{%marketplace_outbox}}
            WHERE status = 'pending'
            GROUP BY lane
        ")->queryAll();
        $outbox['lanes'] = [];
        foreach ($laneStats as $row) {
            $outbox['lanes'][$row['lane']] = (int)$row['cnt'];
        }

        $outbox['pending_models'] = (int)$db->createCommand("
            SELECT count(DISTINCT entity_id) FROM {{%marketplace_outbox}} WHERE status='pending' AND entity_type='model'
        ")->queryScalar();

        // ═══ Staging ═══
        $stagingCount = 0;
        try {
            $stagingCount = (int)$db->createCommand("SELECT count(*) FROM {{%staging_raw_offers}}")->queryScalar();
        } catch (\Exception $e) {}

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
            LIMIT 10
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
            'recentModels' => $recentModels,
            'timestamp' => date('H:i:s'),
        ];
    }

    /**
     * Readiness scoring stats.
     */
    protected function collectReadinessStats(): array
    {
        $db = Yii::$app->db;
        try {
            $stats = $db->createCommand("
                SELECT
                    sc.name as channel_name,
                    sc.driver,
                    COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE mcr.is_ready = true) AS ready,
                    COUNT(*) FILTER (WHERE mcr.is_ready = false) AS not_ready,
                    ROUND(AVG(mcr.score)::numeric, 1) AS avg_score
                FROM {{%model_channel_readiness}} mcr
                JOIN {{%sales_channels}} sc ON sc.id = mcr.channel_id
                GROUP BY sc.id, sc.name, sc.driver
                ORDER BY sc.name
            ")->queryAll();

            return $stats ?: [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Pricing engine stats.
     */
    protected function collectPricingStats(): array
    {
        $db = Yii::$app->db;
        try {
            $rulesCount = (int)$db->createCommand("SELECT count(*) FROM {{%pricing_rules}} WHERE is_active = true")->queryScalar();
            $withRetailPrice = (int)$db->createCommand("SELECT count(*) FROM {{%supplier_offers}} WHERE retail_price IS NOT NULL AND retail_price > 0")->queryScalar();
            $totalOffers = (int)$db->createCommand("SELECT count(*) FROM {{%supplier_offers}}")->queryScalar();
            return [
                'active_rules' => $rulesCount,
                'with_retail_price' => $withRetailPrice,
                'total_offers' => $totalOffers,
            ];
        } catch (\Exception $e) {
            return ['active_rules' => 0, 'with_retail_price' => 0, 'total_offers' => 0];
        }
    }

    /**
     * AI healing stats.
     */
    protected function collectHealingStats(): array
    {
        $db = Yii::$app->db;
        try {
            $healed = (int)$db->createCommand("
                SELECT count(*) FROM {{%model_channel_readiness}}
                WHERE last_heal_attempt_at IS NOT NULL
            ")->queryScalar();

            $healedAndReady = (int)$db->createCommand("
                SELECT count(*) FROM {{%model_channel_readiness}}
                WHERE last_heal_attempt_at IS NOT NULL AND is_ready = true
            ")->queryScalar();

            // Queue info
            $queueWaiting = 0;
            try {
                $redis = Yii::$app->redis;
                $queueWaiting = (int)$redis->executeCommand('LLEN', ['agregator-queue.waiting']);
            } catch (\Exception $e) {}

            return [
                'total_healed' => $healed,
                'healed_and_ready' => $healedAndReady,
                'queue_waiting' => $queueWaiting,
            ];
        } catch (\Exception $e) {
            return ['total_healed' => 0, 'healed_and_ready' => 0, 'queue_waiting' => 0];
        }
    }

    /**
     * Scheduler stats.
     */
    protected function collectSchedulerStats(): array
    {
        $db = Yii::$app->db;
        try {
            $configs = $db->createCommand("
                SELECT sfc.id, s.name as supplier_name, sfc.source_type, sfc.cron_schedule,
                       sfc.last_fetch_at, sfc.last_status, sfc.is_active
                FROM {{%supplier_fetch_configs}} sfc
                JOIN {{%suppliers}} s ON s.id = sfc.supplier_id
                WHERE sfc.is_active = true
                ORDER BY sfc.last_fetch_at DESC NULLS LAST
            ")->queryAll();
            return $configs ?: [];
        } catch (\Exception $e) {
            return [];
        }
    }
}
