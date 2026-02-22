<?php

namespace backend\controllers;

use common\models\Brand;
use common\models\BrandAlias;
use yii\data\ActiveDataProvider;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use Yii;

/**
 * Sprint 22: Управление эталонным справочником брендов.
 */
class BrandController extends Controller
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
                    'delete' => ['post'],
                    'delete-alias' => ['post'],
                ],
            ],
        ];
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * INDEX — Список брендов
     * ═══════════════════════════════════════════════════════════════════ */

    public function actionIndex(): string
    {
        $dataProvider = new ActiveDataProvider([
            'query' => Brand::find()->orderBy(['name' => SORT_ASC]),
            'pagination' => ['pageSize' => 50],
        ]);

        return $this->render('index', [
            'dataProvider' => $dataProvider,
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * VIEW — Просмотр бренда
     * ═══════════════════════════════════════════════════════════════════ */

    public function actionView(int $id): string
    {
        $model = $this->findModel($id);
        return $this->render('view', ['model' => $model]);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * CREATE — Создание бренда
     * ═══════════════════════════════════════════════════════════════════ */

    public function actionCreate(): string|Response
    {
        $model = new Brand();

        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            Yii::$app->session->setFlash('success', 'Бренд создан.');
            return $this->redirect(['view', 'id' => $model->id]);
        }

        return $this->render('create', ['model' => $model]);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * UPDATE — Редактирование бренда
     * ═══════════════════════════════════════════════════════════════════ */

    public function actionUpdate(int $id): string|Response
    {
        $model = $this->findModel($id);

        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            Yii::$app->session->setFlash('success', 'Бренд обновлён.');
            return $this->redirect(['view', 'id' => $model->id]);
        }

        return $this->render('update', ['model' => $model]);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * DELETE — Удаление бренда
     * ═══════════════════════════════════════════════════════════════════ */

    public function actionDelete(int $id): Response
    {
        $model = $this->findModel($id);

        // Проверка: нельзя удалить бренд, если есть товары
        $productCount = $model->getProductModels()->count();
        if ($productCount > 0) {
            Yii::$app->session->setFlash('error', 
                "Нельзя удалить бренд: используется в {$productCount} товарах.");
            return $this->redirect(['view', 'id' => $id]);
        }

        $model->delete();
        Yii::$app->session->setFlash('success', 'Бренд удалён.');
        return $this->redirect(['index']);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * ADD ALIAS — Добавление алиаса
     * ═══════════════════════════════════════════════════════════════════ */

    public function actionAddAlias(): Response
    {
        $brandId = (int)Yii::$app->request->post('brand_id');
        $alias = trim(Yii::$app->request->post('alias', ''));

        if (empty($alias)) {
            return $this->asJson(['success' => false, 'message' => 'Алиас не может быть пустым']);
        }

        $brand = Brand::findOne($brandId);
        if (!$brand) {
            return $this->asJson(['success' => false, 'message' => 'Бренд не найден']);
        }

        // Проверяем, не существует ли уже такой алиас
        $existing = BrandAlias::find()
            ->where(['ILIKE', 'alias', $alias])
            ->one();

        if ($existing) {
            return $this->asJson([
                'success' => false,
                'message' => "Алиас '{$alias}' уже существует для бренда '{$existing->brand->name}'",
            ]);
        }

        $brandAlias = new BrandAlias();
        $brandAlias->brand_id = $brandId;
        $brandAlias->alias = $alias;

        if ($brandAlias->save()) {
            return $this->asJson(['success' => true, 'message' => 'Алиас добавлен']);
        }

        return $this->asJson([
            'success' => false,
            'message' => 'Ошибка: ' . implode(', ', $brandAlias->getFirstErrors()),
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * DELETE ALIAS — Удаление алиаса
     * ═══════════════════════════════════════════════════════════════════ */

    public function actionDeleteAlias(int $id): Response
    {
        $alias = BrandAlias::findOne($id);
        if (!$alias) {
            return $this->asJson(['success' => false, 'message' => 'Алиас не найден']);
        }

        $alias->delete();
        return $this->asJson(['success' => true, 'message' => 'Алиас удалён']);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * MOVE ALIAS — Перемещение алиаса к другому бренду
     * ═══════════════════════════════════════════════════════════════════ */

    public function actionMoveAlias(): Response
    {
        $aliasId = (int)Yii::$app->request->post('alias_id');
        $newBrandId = (int)Yii::$app->request->post('new_brand_id');

        $alias = BrandAlias::findOne($aliasId);
        if (!$alias) {
            return $this->asJson(['success' => false, 'message' => 'Алиас не найден']);
        }

        $newBrand = Brand::findOne($newBrandId);
        if (!$newBrand) {
            return $this->asJson(['success' => false, 'message' => 'Бренд не найден']);
        }

        $alias->brand_id = $newBrandId;
        if ($alias->save()) {
            return $this->asJson(['success' => true, 'message' => 'Алиас перемещён']);
        }

        return $this->asJson([
            'success' => false,
            'message' => 'Ошибка: ' . implode(', ', $alias->getFirstErrors()),
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * HELPERS
     * ═══════════════════════════════════════════════════════════════════ */

    protected function findModel(int $id): Brand
    {
        $model = Brand::findOne($id);
        if (!$model) {
            throw new NotFoundHttpException('Бренд не найден.');
        }
        return $model;
    }
}
