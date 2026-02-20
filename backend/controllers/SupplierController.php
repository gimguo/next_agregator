<?php

namespace backend\controllers;

use common\models\Supplier;
use common\models\SupplierFetchConfig;
use common\models\SupplierOffer;
use common\jobs\ImportPriceJob;
use common\jobs\DownloadImagesJob;
use common\jobs\FetchPriceJob;
use common\jobs\ResolveBrandsJob;
use common\jobs\CategorizeCardsJob;
use yii\data\ActiveDataProvider;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use Yii;

/**
 * Управление поставщиками.
 */
class SupplierController extends Controller
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
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'import' => ['post'],
                    'queue-images' => ['post'],
                    'queue-ai' => ['post'],
                ],
            ],
        ];
    }

    public function actionIndex(): string
    {
        $dataProvider = new ActiveDataProvider([
            'query' => Supplier::find()->orderBy(['is_active' => SORT_DESC, 'name' => SORT_ASC]),
            'pagination' => ['pageSize' => 50],
        ]);

        return $this->render('index', [
            'dataProvider' => $dataProvider,
        ]);
    }

    public function actionView(int $id): string
    {
        $supplier = $this->findModel($id);

        $offersProvider = new ActiveDataProvider([
            'query' => SupplierOffer::find()
                ->where(['supplier_id' => $id])
                ->with('card')
                ->orderBy(['updated_at' => SORT_DESC]),
            'pagination' => ['pageSize' => 30],
        ]);

        $stats = [
            'total_offers' => SupplierOffer::find()->where(['supplier_id' => $id])->count(),
            'active_offers' => SupplierOffer::find()->where(['supplier_id' => $id, 'is_active' => true])->count(),
            'in_stock' => SupplierOffer::find()->where(['supplier_id' => $id, 'in_stock' => true])->count(),
        ];

        $fetchConfig = SupplierFetchConfig::findOne(['supplier_id' => $id]);

        // Файлы прайсов (локальные + source)
        $priceFiles = $this->scanPriceFiles($supplier->code);

        return $this->render('view', [
            'supplier' => $supplier,
            'offersProvider' => $offersProvider,
            'stats' => $stats,
            'fetchConfig' => $fetchConfig,
            'priceFiles' => $priceFiles,
        ]);
    }

    /**
     * Создание нового поставщика.
     */
    public function actionCreate()
    {
        $model = new Supplier();
        $model->is_active = true;

        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            Yii::$app->session->setFlash('success', "Поставщик '{$model->name}' создан");
            return $this->redirect(['/supplier/view', 'id' => $model->id]);
        }

        return $this->render('form', [
            'model' => $model,
            'title' => 'Новый поставщик',
        ]);
    }

    /**
     * Редактирование поставщика.
     */
    public function actionUpdate(int $id)
    {
        $model = $this->findModel($id);

        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            Yii::$app->session->setFlash('success', "Поставщик '{$model->name}' обновлён");
            return $this->redirect(['/supplier/view', 'id' => $model->id]);
        }

        return $this->render('form', [
            'model' => $model,
            'title' => "Редактирование: {$model->name}",
        ]);
    }

    /**
     * Запустить импорт из админки (через очередь).
     */
    public function actionImport(int $id)
    {
        $supplier = $this->findModel($id);

        $filePath = Yii::$app->request->post('file_path');
        $maxProducts = (int)Yii::$app->request->post('max_products', 0);
        $downloadImages = (bool)Yii::$app->request->post('download_images', 1);
        $importMode = Yii::$app->request->post('import_mode', 'pipeline');
        $useAI = (bool)Yii::$app->request->post('use_ai', 1);

        if (empty($filePath)) {
            Yii::$app->session->setFlash('error', 'Не указан файл прайса');
            return $this->redirect(['/supplier/view', 'id' => $id]);
        }

        $options = [];
        if ($maxProducts > 0) {
            $options['max_products'] = $maxProducts;
        }

        $jobId = Yii::$app->queue->push(new ImportPriceJob([
            'supplierCode' => $supplier->code,
            'filePath' => $filePath,
            'options' => $options,
            'downloadImages' => $downloadImages,
            'mode' => $importMode,
            'analyzeWithAI' => $useAI,
        ]));

        $modeLabel = $importMode === 'pipeline' ? 'Pipeline (Redis → AI → DB)' : 'Legacy (прямой)';
        Yii::$app->session->setFlash('success',
            "Импорт поставлен в очередь (Job #{$jobId}, {$modeLabel}). " .
            "Файл: " . basename($filePath) .
            ($maxProducts > 0 ? " (лимит: {$maxProducts})" : '') .
            ($useAI ? ' + AI-анализ' : '')
        );

        return $this->redirect(['/supplier/view', 'id' => $id]);
    }

    /**
     * Поставить скачивание картинок в очередь.
     */
    public function actionQueueImages(int $id)
    {
        $supplier = $this->findModel($id);

        $cardIds = Yii::$app->db->createCommand("
            SELECT DISTINCT ci.card_id FROM {{%card_images}} ci
            JOIN {{%supplier_offers}} so ON so.card_id = ci.card_id
            WHERE ci.status = 'pending' AND so.supplier_id = :sid
            ORDER BY ci.card_id
            LIMIT 500
        ", [':sid' => $id])->queryColumn();

        if (empty($cardIds)) {
            Yii::$app->session->setFlash('warning', 'Нет pending-картинок для этого поставщика');
            return $this->redirect(['/supplier/view', 'id' => $id]);
        }

        $chunks = array_chunk($cardIds, 10);
        foreach ($chunks as $chunk) {
            Yii::$app->queue->push(new DownloadImagesJob([
                'cardIds' => $chunk,
                'supplierCode' => $supplier->code,
            ]));
        }

        Yii::$app->session->setFlash('success', "Поставлено " . count($chunks) . " заданий на скачивание картинок (" . count($cardIds) . " карточек)");
        return $this->redirect(['/supplier/view', 'id' => $id]);
    }

    /**
     * Поставить AI-обработку в очередь.
     */
    public function actionQueueAi(int $id)
    {
        $supplier = $this->findModel($id);
        $jobs = 0;

        // Бренды без brand_id
        $unbrandedIds = Yii::$app->db->createCommand("
            SELECT pc.id FROM {{%product_cards}} pc
            JOIN {{%supplier_offers}} so ON so.card_id = pc.id
            WHERE so.supplier_id = :sid AND pc.brand_id IS NULL 
              AND (pc.brand IS NOT NULL OR pc.manufacturer IS NOT NULL)
            LIMIT 200
        ", [':sid' => $id])->queryColumn();

        if (!empty($unbrandedIds)) {
            foreach (array_chunk($unbrandedIds, 20) as $chunk) {
                Yii::$app->queue->push(new ResolveBrandsJob(['cardIds' => $chunk]));
                $jobs++;
            }
        }

        // Без категории
        $uncategorizedIds = Yii::$app->db->createCommand("
            SELECT pc.id FROM {{%product_cards}} pc
            JOIN {{%supplier_offers}} so ON so.card_id = pc.id
            WHERE so.supplier_id = :sid AND pc.category_id IS NULL
            LIMIT 200
        ", [':sid' => $id])->queryColumn();

        if (!empty($uncategorizedIds)) {
            foreach (array_chunk($uncategorizedIds, 10) as $chunk) {
                Yii::$app->queue->push(new CategorizeCardsJob(['cardIds' => $chunk]));
                $jobs++;
            }
        }

        if ($jobs > 0) {
            Yii::$app->session->setFlash('success', "Поставлено {$jobs} AI-заданий (бренды: " . count($unbrandedIds) . ", категории: " . count($uncategorizedIds) . ")");
        } else {
            Yii::$app->session->setFlash('info', 'Все карточки уже обработаны');
        }

        return $this->redirect(['/supplier/view', 'id' => $id]);
    }

    /**
     * Поиск файлов прайсов для поставщика.
     */
    protected function scanPriceFiles(string $code): array
    {
        $files = [];

        // Локальная папка prices
        $localDir = Yii::getAlias("@storage/prices/{$code}");
        if (is_dir($localDir)) {
            foreach (glob("{$localDir}/*") as $f) {
                if (is_file($f)) {
                    $files[] = [
                        'path' => $f,
                        'name' => basename($f),
                        'size' => filesize($f),
                        'modified' => filemtime($f),
                        'source' => 'local',
                    ];
                }
            }
        }

        // Source (read-only mount)
        $sourceDir = '/app/storage/prices-source/' . $code;
        if (is_dir($sourceDir)) {
            foreach (glob("{$sourceDir}/*") as $f) {
                if (is_file($f)) {
                    $files[] = [
                        'path' => $f,
                        'name' => basename($f),
                        'size' => filesize($f),
                        'modified' => filemtime($f),
                        'source' => 'source',
                    ];
                }
            }
        }

        // Сортировка по дате (новые первыми)
        usort($files, fn($a, $b) => $b['modified'] <=> $a['modified']);

        return $files;
    }

    protected function findModel(int $id): Supplier
    {
        $model = Supplier::findOne($id);
        if (!$model) {
            throw new NotFoundHttpException('Поставщик не найден.');
        }
        return $model;
    }
}
