<?php

namespace backend\controllers;

use common\models\Supplier;
use common\models\SupplierFetchConfig;
use common\jobs\FetchPriceJob;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use Yii;

/**
 * Управление конфигурацией автоматического получения прайсов.
 */
class SupplierFetchConfigController extends Controller
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
                    'test-fetch' => ['post'],
                ],
            ],
        ];
    }

    /**
     * Создание/редактирование конфигурации для поставщика.
     */
    public function actionUpdate(int $supplierId)
    {
        $supplier = Supplier::findOne($supplierId);
        if (!$supplier) {
            throw new NotFoundHttpException('Поставщик не найден.');
        }

        $model = SupplierFetchConfig::findOne(['supplier_id' => $supplierId]);
        if (!$model) {
            $model = new SupplierFetchConfig([
                'supplier_id' => $supplierId,
                'fetch_method' => 'manual',
                'is_enabled' => true,
            ]);
        }

        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            Yii::$app->session->setFlash('success', 'Конфигурация получения прайса сохранена');
            return $this->redirect(['/supplier/view', 'id' => $supplierId]);
        }

        return $this->render('update', [
            'model' => $model,
            'supplier' => $supplier,
        ]);
    }

    /**
     * Тестовый запуск получения прайса.
     */
    public function actionTestFetch(int $supplierId)
    {
        $supplier = Supplier::findOne($supplierId);
        if (!$supplier) {
            throw new NotFoundHttpException('Поставщик не найден.');
        }

        $config = SupplierFetchConfig::findOne(['supplier_id' => $supplierId]);
        if (!$config) {
            Yii::$app->session->setFlash('error', 'Конфигурация не задана');
            return $this->redirect(['/supplier/view', 'id' => $supplierId]);
        }

        if ($config->fetch_method === 'manual') {
            Yii::$app->session->setFlash('warning', 'Метод получения — ручной. Загрузите файл вручную.');
            return $this->redirect(['/supplier/view', 'id' => $supplierId]);
        }

        $jobId = Yii::$app->queue->push(new FetchPriceJob([
            'supplierCode' => $supplier->code,
            'fetchConfigId' => $config->id,
        ]));

        Yii::$app->session->setFlash('success', "Задание на получение прайса поставлено в очередь (Job #{$jobId})");
        return $this->redirect(['/supplier/view', 'id' => $supplierId]);
    }
}
