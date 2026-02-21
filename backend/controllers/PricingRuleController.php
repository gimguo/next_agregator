<?php

namespace backend\controllers;

use common\models\PricingRule;
use yii\data\ActiveDataProvider;
use yii\filters\AccessControl;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use Yii;

/**
 * Управление правилами ценообразования.
 */
class PricingRuleController extends Controller
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
     * Список всех правил.
     */
    public function actionIndex(): string
    {
        $dataProvider = new ActiveDataProvider([
            'query' => PricingRule::find()->orderBy(['priority' => SORT_DESC, 'id' => SORT_ASC]),
            'pagination' => ['pageSize' => 50],
        ]);

        // Общая статистика
        $db = Yii::$app->db;
        $totalRules = (int)$db->createCommand("SELECT count(*) FROM {{%pricing_rules}}")->queryScalar();
        $activeRules = (int)$db->createCommand("SELECT count(*) FROM {{%pricing_rules}} WHERE is_active = true")->queryScalar();

        return $this->render('index', [
            'dataProvider' => $dataProvider,
            'totalRules' => $totalRules,
            'activeRules' => $activeRules,
        ]);
    }

    /**
     * Создание правила.
     */
    public function actionCreate(): string|\yii\web\Response
    {
        $model = new PricingRule();

        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            Yii::$app->session->setFlash('success', "Правило «{$model->name}» создано.");
            return $this->redirect(['index']);
        }

        return $this->render('form', [
            'model' => $model,
            'title' => 'Новое правило наценки',
        ]);
    }

    /**
     * Редактирование правила.
     */
    public function actionUpdate(int $id): string|\yii\web\Response
    {
        $model = $this->findModel($id);

        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            Yii::$app->session->setFlash('success', "Правило «{$model->name}» обновлено.");
            return $this->redirect(['index']);
        }

        return $this->render('form', [
            'model' => $model,
            'title' => "Редактирование: {$model->name}",
        ]);
    }

    /**
     * Переключить активность (AJAX-friendly).
     */
    public function actionToggle(int $id): \yii\web\Response
    {
        $model = $this->findModel($id);
        $model->is_active = !$model->is_active;
        $model->save(false, ['is_active']);

        $state = $model->is_active ? 'активировано' : 'деактивировано';
        Yii::$app->session->setFlash('success', "Правило «{$model->name}» {$state}.");
        return $this->redirect(['index']);
    }

    /**
     * Удаление правила.
     */
    public function actionDelete(int $id): \yii\web\Response
    {
        $model = $this->findModel($id);
        $name = $model->name;
        $model->delete();

        Yii::$app->session->setFlash('success', "Правило «{$name}» удалено.");
        return $this->redirect(['index']);
    }

    protected function findModel(int $id): PricingRule
    {
        $model = PricingRule::findOne($id);
        if (!$model) {
            throw new NotFoundHttpException("Правило #{$id} не найдено.");
        }
        return $model;
    }
}
