<?php

namespace backend\controllers;

use common\models\CatalogTemplate;
use yii\data\ActiveDataProvider;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use Yii;

/**
 * Управление шаблонами каталога.
 *
 * Защита системных шаблонов:
 *   - Системные шаблоны нельзя удалять (actionDelete)
 *   - Системные шаблоны нельзя переименовывать (actionUpdate)
 *   - Структуру системных шаблонов можно редактировать
 */
class CatalogTemplateController extends Controller
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
                ],
            ],
        ];
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * INDEX — Список шаблонов
     * ═══════════════════════════════════════════════════════════════════ */

    public function actionIndex(): string
    {
        $dataProvider = new ActiveDataProvider([
            'query' => CatalogTemplate::find()->orderBy(['is_system' => SORT_DESC, 'name' => SORT_ASC]),
            'pagination' => ['pageSize' => 20],
        ]);

        return $this->render('index', [
            'dataProvider' => $dataProvider,
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * VIEW — Просмотр шаблона
     * ═══════════════════════════════════════════════════════════════════ */

    public function actionView(int $id): string
    {
        $model = $this->findModel($id);
        return $this->render('view', ['model' => $model]);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * CREATE — Создание шаблона
     * ═══════════════════════════════════════════════════════════════════ */

    public function actionCreate(): string|Response
    {
        $model = new CatalogTemplate();

        if ($model->load(Yii::$app->request->post())) {
            // Парсим JSON поля из строк
            $this->parseJsonFields($model);
            if ($model->save()) {
                Yii::$app->session->setFlash('success', 'Шаблон каталога создан.');
                return $this->redirect(['view', 'id' => $model->id]);
            }
        }

        // Преобразуем JSON в строку для textarea
        $this->prepareJsonForForm($model);
        return $this->render('create', ['model' => $model]);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * UPDATE — Редактирование шаблона
     * ═══════════════════════════════════════════════════════════════════ */

    public function actionUpdate(int $id): string|Response
    {
        $model = $this->findModel($id);
        $isSystem = (bool)$model->is_system;
        $oldName = $model->name; // Сохраняем старое имя для проверки

        if ($model->load(Yii::$app->request->post())) {
            // Восстанавливаем имя для системных шаблонов (disabled поле не отправляется)
            if ($isSystem) {
                $model->name = $oldName;
            }

            // Защита: системные шаблоны нельзя переименовывать (дополнительная проверка)
            if ($isSystem && $model->name !== $oldName) {
                Yii::$app->session->setFlash('error', 'Нельзя переименовывать системный шаблон.');
                $model->name = $oldName;
            }

            // Защита: нельзя изменить флаг is_system через форму
            $model->is_system = $isSystem;

            // Парсим JSON поля из строк
            $this->parseJsonFields($model);

            if ($model->save()) {
                Yii::$app->session->setFlash('success', 'Шаблон каталога обновлён.');
                return $this->redirect(['view', 'id' => $model->id]);
            }
        }

        // Преобразуем JSON в строку для textarea
        $this->prepareJsonForForm($model);
        return $this->render('update', [
            'model' => $model,
            'isSystem' => $isSystem,
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * DELETE — Удаление шаблона
     * ═══════════════════════════════════════════════════════════════════ */

    public function actionDelete(int $id): Response
    {
        $model = $this->findModel($id);

        // Защита: системные шаблоны нельзя удалять
        if ($model->is_system) {
            throw new ForbiddenHttpException('Нельзя удалить системный шаблон.');
        }

        // Проверка: нельзя удалить шаблон, если есть превью
        $previewCount = $model->getPreviews()->count();
        if ($previewCount > 0) {
            Yii::$app->session->setFlash('error', 
                "Нельзя удалить шаблон: используется в {$previewCount} превью каталога.");
            return $this->redirect(['view', 'id' => $id]);
        }

        $model->delete();
        Yii::$app->session->setFlash('success', 'Шаблон каталога удалён.');
        return $this->redirect(['index']);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * HELPERS
     * ═══════════════════════════════════════════════════════════════════ */

    protected function findModel(int $id): CatalogTemplate
    {
        $model = CatalogTemplate::findOne($id);
        if (!$model) {
            throw new NotFoundHttpException('Шаблон каталога не найден.');
        }
        return $model;
    }

    /**
     * Парсит JSON поля из строк в массивы перед сохранением.
     */
    protected function parseJsonFields(CatalogTemplate $model): void
    {
        // structure_json
        if (is_string($model->structure_json)) {
            $decoded = json_decode($model->structure_json, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $model->structure_json = $decoded;
            } else {
                $model->addError('structure_json', 'Неверный формат JSON: ' . json_last_error_msg());
            }
        }

        // merge_rules
        if (is_string($model->merge_rules) && !empty($model->merge_rules)) {
            $decoded = json_decode($model->merge_rules, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $model->merge_rules = $decoded;
            } else {
                $model->addError('merge_rules', 'Неверный формат JSON: ' . json_last_error_msg());
            }
        } elseif (is_string($model->merge_rules) && empty($model->merge_rules)) {
            $model->merge_rules = null;
        }
    }

    /**
     * Преобразует JSON поля в строки для отображения в textarea.
     */
    protected function prepareJsonForForm(CatalogTemplate $model): void
    {
        if (is_array($model->structure_json)) {
            $model->structure_json = json_encode($model->structure_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }
        if (is_array($model->merge_rules)) {
            $model->merge_rules = json_encode($model->merge_rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }
    }
}
