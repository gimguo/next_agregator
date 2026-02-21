<?php

namespace backend\controllers;

use backend\models\ProductModelSearch;
use common\models\MediaAsset;
use common\models\ModelChannelReadiness;
use common\models\ModelDataSource;
use common\models\ProductModel;
use common\models\ReferenceVariant;
use common\models\SalesChannel;
use common\models\SupplierOffer;
use common\services\AutoHealingService;
use common\services\GoldenRecordService;
use common\services\OutboxService;
use common\services\ReadinessScoringService;
use common\services\RosMatrasSyndicationService;
use common\services\marketplace\MarketplaceApiClientInterface;
use common\services\marketplace\MarketplaceUnavailableException;
use yii\db\JsonExpression;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use Yii;

/**
 * Sprint 15 — PIM Cockpit: MDM Каталог с Manual Override и AI Healing.
 *
 * Пульт управления карточками товаров:
 *   - actionView: детальная карточка с Readiness, Pricing, AI Heal
 *   - actionUpdate: ручное редактирование с Manual Override (priority=100)
 *   - actionHeal: принудительное AI-лечение из админки
 *   - actionSync: ручная синхронизация на витрину
 */
class CatalogController extends Controller
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
                    'sync' => ['post'],
                    'heal' => ['post'],
                ],
            ],
        ];
    }

    /**
     * Список всех моделей товаров.
     */
    public function actionIndex(): string
    {
        $searchModel = new ProductModelSearch();
        $dataProvider = $searchModel->search(Yii::$app->request->queryParams);

        return $this->render('index', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
        ]);
    }

    /**
     * PIM Cockpit: детальная карточка модели.
     *
     * Отображает:
     *   - Readiness Score + missing fields
     *   - Основные данные (описание, атрибуты)
     *   - Изображения
     *   - Варианты с ценообразованием (base_price / retail_price)
     *   - Источники данных (model_data_sources)
     *   - Кнопки: Редактировать, AI Heal, Sync
     */
    public function actionView(int $id): string
    {
        $model = $this->findModel($id);

        // Readiness
        $channel = SalesChannel::find()->where(['is_active' => true])->one();
        $readiness = null;
        $readinessReport = null;
        if ($channel) {
            $readiness = ModelChannelReadiness::findOne([
                'model_id' => $id,
                'channel_id' => $channel->id,
            ]);
            // Live evaluate if no cache
            if (!$readiness) {
                try {
                    /** @var ReadinessScoringService $scorer */
                    $scorer = Yii::$app->get('readinessService');
                    $readinessReport = $scorer->evaluate($id, $channel, true);
                    $readiness = ModelChannelReadiness::findOne([
                        'model_id' => $id,
                        'channel_id' => $channel->id,
                    ]);
                } catch (\Throwable $e) {
                    Yii::warning("CatalogView: readiness eval failed for model #{$id}: {$e->getMessage()}", 'catalog');
                }
            }
        }

        // Изображения модели
        $images = MediaAsset::find()
            ->where(['entity_type' => 'model', 'entity_id' => $id])
            ->orderBy(['is_primary' => SORT_DESC, 'sort_order' => SORT_ASC])
            ->all();

        // Варианты с офферами (включая retail_price)
        $variants = ReferenceVariant::find()
            ->where(['model_id' => $id])
            ->orderBy(['sort_order' => SORT_ASC, 'variant_label' => SORT_ASC])
            ->all();

        $variantIds = array_map(fn($v) => $v->id, $variants);
        $offers = [];
        if (!empty($variantIds)) {
            $allOffers = SupplierOffer::find()
                ->where(['variant_id' => $variantIds])
                ->with('supplier')
                ->orderBy(['variant_id' => SORT_ASC, 'price_min' => SORT_ASC])
                ->all();
            foreach ($allOffers as $offer) {
                $offers[$offer->variant_id][] = $offer;
            }
        }

        // Офферы без варианта
        $orphanOffers = SupplierOffer::find()
            ->where(['model_id' => $id, 'variant_id' => null])
            ->with('supplier')
            ->all();

        // Источники данных (model_data_sources)
        $dataSources = ModelDataSource::find()
            ->where(['model_id' => $id])
            ->orderBy(['priority' => SORT_DESC, 'updated_at' => SORT_DESC])
            ->all();

        return $this->render('view', [
            'model' => $model,
            'images' => $images,
            'variants' => $variants,
            'offers' => $offers,
            'orphanOffers' => $orphanOffers,
            'readiness' => $readiness,
            'channel' => $channel,
            'dataSources' => $dataSources,
        ]);
    }

    /**
     * Manual Override: редактирование карточки менеджером.
     *
     * При сохранении:
     *   1. Записывает изменения в model_data_sources (source_type=manual_override, priority=100)
     *   2. Применяет merged данные из всех источников к ProductModel
     *   3. Пересчитывает GoldenRecord агрегаты
     *   4. Пересчитывает ReadinessScore
     *   5. Если 100% — emitContentUpdate() → Outbox
     */
    public function actionUpdate(int $id): string|\yii\web\Response
    {
        $model = $this->findModel($id);

        // Текущие атрибуты
        $currentAttrs = [];
        if (!empty($model->canonical_attributes)) {
            $currentAttrs = is_string($model->canonical_attributes)
                ? (json_decode($model->canonical_attributes, true) ?: [])
                : (is_array($model->canonical_attributes) ? $model->canonical_attributes : []);
        }

        // Загружаем manual_override если есть
        $manualSource = ModelDataSource::findOne([
            'model_id' => $id,
            'source_type' => ModelDataSource::SOURCE_MANUAL,
            'source_id' => 'admin',
        ]);
        $manualData = $manualSource ? $manualSource->getDataArray() : [];

        if (Yii::$app->request->isPost) {
            $post = Yii::$app->request->post();

            // Собираем данные из формы
            $overrideData = [];
            $changes = [];

            // Описание
            $newDescription = trim($post['description'] ?? '');
            if ($newDescription !== '' && $newDescription !== ($model->description ?? '')) {
                $overrideData['description'] = $newDescription;
                $changes[] = 'описание';
            }

            // Краткое описание
            $newShortDesc = trim($post['short_description'] ?? '');
            if ($newShortDesc !== '' && $newShortDesc !== ($model->short_description ?? '')) {
                $overrideData['short_description'] = $newShortDesc;
                $changes[] = 'краткое описание';
            }

            // Атрибуты (из формы приходят как key=value пары)
            $newAttrs = [];
            $attrKeys = $post['attr_key'] ?? [];
            $attrVals = $post['attr_value'] ?? [];
            foreach ($attrKeys as $idx => $key) {
                $key = trim($key);
                $val = trim($attrVals[$idx] ?? '');
                if ($key !== '' && $val !== '') {
                    $newAttrs[$key] = $val;
                }
            }

            if (!empty($newAttrs)) {
                // Вычисляем diff: только реально изменённые/новые атрибуты
                $attrDiff = [];
                foreach ($newAttrs as $k => $v) {
                    if (!isset($currentAttrs[$k]) || (string)$currentAttrs[$k] !== (string)$v) {
                        $attrDiff[$k] = $v;
                    }
                }
                if (!empty($attrDiff)) {
                    $overrideData['attributes'] = $newAttrs; // Сохраняем полный набор из формы
                    $changes[] = count($attrDiff) . ' атрибут(ов)';
                }
            }

            if (empty($overrideData) && empty($changes)) {
                Yii::$app->session->setFlash('info', 'Нет изменений для сохранения.');
                return $this->redirect(['view', 'id' => $id]);
            }

            $db = Yii::$app->db;
            $transaction = $db->beginTransaction();

            try {
                // ═══ 1. Записываем в model_data_sources (Manual Override, priority=100) ═══
                $userId = Yii::$app->user->id ?? null;
                ModelDataSource::upsert(
                    $id,
                    ModelDataSource::SOURCE_MANUAL,
                    'admin',
                    $overrideData,
                    ModelDataSource::PRIORITY_MANUAL,
                    null,
                    $userId
                );

                // ═══ 2. Применяем данные к ProductModel ═══
                $updateFields = [];

                if (isset($overrideData['description'])) {
                    $updateFields['description'] = $overrideData['description'];
                }
                if (isset($overrideData['short_description'])) {
                    $updateFields['short_description'] = $overrideData['short_description'];
                }
                if (isset($overrideData['attributes'])) {
                    // Мержим: manual attrs перекрывают существующие
                    $mergedAttrs = array_merge($currentAttrs, $overrideData['attributes']);
                    $updateFields['canonical_attributes'] = new JsonExpression($mergedAttrs);
                }

                if (!empty($updateFields)) {
                    $updateFields['updated_at'] = new \yii\db\Expression('NOW()');
                    $db->createCommand()->update('{{%product_models}}', $updateFields, ['id' => $id])->execute();
                }

                // ═══ 3. Пересчитываем Golden Record агрегаты ═══
                /** @var GoldenRecordService $gr */
                $gr = Yii::$app->get('goldenRecord');
                $gr->recalculateModel($id);

                // ═══ 4. Пересчитываем Readiness Score ═══
                $channel = SalesChannel::find()->where(['is_active' => true])->one();
                $readinessResult = null;
                if ($channel) {
                    /** @var ReadinessScoringService $scorer */
                    $scorer = Yii::$app->get('readinessService');
                    $scorer->resetCache();
                    $readinessResult = $scorer->evaluate($id, $channel, true);

                    // ═══ 5. Если 100% — пушим в Outbox ═══
                    if ($readinessResult->isReady) {
                        try {
                            /** @var OutboxService $outbox */
                            $outbox = Yii::$app->get('outbox');
                            $originalGate = $outbox->readinessGate;
                            $outbox->readinessGate = false; // Мы уже проверили
                            $outbox->emitContentUpdate($id, null, ['source' => 'manual_override']);
                            $outbox->readinessGate = $originalGate;
                        } catch (\Throwable $e) {
                            Yii::warning("ManualOverride: outbox push failed for model #{$id}: {$e->getMessage()}", 'catalog');
                        }
                    }
                }

                $transaction->commit();

                $changesList = implode(', ', $changes);
                $readinessMsg = $readinessResult
                    ? " Readiness: {$readinessResult->score}%"
                        . ($readinessResult->isReady ? ' ✓ → Outbox' : '')
                    : '';

                Yii::$app->session->setFlash('success',
                    "✓ Сохранено (Manual Override, priority=100): {$changesList}.{$readinessMsg}"
                );

            } catch (\Throwable $e) {
                $transaction->rollBack();
                Yii::error("ManualOverride error model #{$id}: {$e->getMessage()}", 'catalog');
                Yii::$app->session->setFlash('error', "Ошибка сохранения: {$e->getMessage()}");
            }

            return $this->redirect(['view', 'id' => $id]);
        }

        // GET: показываем форму
        return $this->render('update', [
            'model' => $model,
            'currentAttrs' => $currentAttrs,
            'manualData' => $manualData,
        ]);
    }

    /**
     * Принудительное AI-лечение из админки (синхронное).
     */
    public function actionHeal(int $id): \yii\web\Response
    {
        $model = $this->findModel($id);
        $channel = SalesChannel::find()->where(['is_active' => true])->one();

        if (!$channel) {
            Yii::$app->session->setFlash('error', 'Нет активного канала продаж.');
            return $this->redirect(['view', 'id' => $id]);
        }

        // Получаем missing fields
        $readiness = ModelChannelReadiness::findOne([
            'model_id' => $id,
            'channel_id' => $channel->id,
        ]);

        if (!$readiness || $readiness->is_ready) {
            Yii::$app->session->setFlash('info', 'Модель уже готова или нет данных readiness. Запустите quality/scan.');
            return $this->redirect(['view', 'id' => $id]);
        }

        $missingFields = $readiness->getMissingList();
        if (empty($missingFields)) {
            Yii::$app->session->setFlash('info', 'Нет пропущенных полей для лечения.');
            return $this->redirect(['view', 'id' => $id]);
        }

        try {
            /** @var AutoHealingService $healer */
            $healer = Yii::$app->get('autoHealer');
            $result = $healer->healModel($id, $missingFields, $channel);

            if ($result->success) {
                $healedList = implode(', ', $result->healedFields);
                $scoreMsg = "Score: {$result->newScore}%";
                $outboxMsg = $result->newIsReady ? ' → Outbox ✓' : '';

                Yii::$app->session->setFlash('success',
                    "🧬 AI вылечил: {$healedList}. {$scoreMsg}{$outboxMsg}"
                );
            } else {
                $errors = implode('; ', $result->errors);
                Yii::$app->session->setFlash('warning', "AI не смог вылечить: {$errors}");
            }
        } catch (\Throwable $e) {
            Yii::error("AI Heal from admin, model #{$id}: {$e->getMessage()}", 'catalog');
            Yii::$app->session->setFlash('error', "Ошибка AI: {$e->getMessage()}");
        }

        return $this->redirect(['view', 'id' => $id]);
    }

    /**
     * Принудительная синхронизация модели на витрину.
     */
    public function actionSync(int $id): \yii\web\Response
    {
        $model = $this->findModel($id);

        try {
            /** @var RosMatrasSyndicationService $syndicator */
            $syndicator = Yii::$app->get('syndicationService');
            /** @var MarketplaceApiClientInterface $client */
            $client = Yii::$app->get('marketplaceClient');

            $projection = $syndicator->buildProductProjection($id);
            if (!$projection) {
                Yii::$app->session->setFlash('warning', "Не удалось построить проекцию для модели #{$id}.");
                return $this->redirect(['view', 'id' => $id]);
            }

            $result = $client->pushProduct($id, $projection);
            if ($result) {
                $varCount = $projection['variant_count'] ?? 0;
                $imgCount = count($projection['images'] ?? []);
                $price = $projection['best_price']
                    ? number_format($projection['best_price'], 0, '.', ' ') . ' ₽'
                    : 'N/A';

                Yii::$app->session->setFlash('success',
                    "✓ Товар «{$model->name}» отправлен на витрину! ({$varCount} вар., {$imgCount} фото, цена: {$price})"
                );
            } else {
                Yii::$app->session->setFlash('error', "API вернул false при отправке «{$model->name}».");
            }
        } catch (MarketplaceUnavailableException $e) {
            Yii::$app->session->setFlash('error', "API витрины недоступен: {$e->getMessage()}");
        } catch (\Throwable $e) {
            Yii::$app->session->setFlash('error', "Ошибка синхронизации: {$e->getMessage()}");
        }

        return $this->redirect(['view', 'id' => $id]);
    }

    protected function findModel(int $id): ProductModel
    {
        $model = ProductModel::findOne($id);
        if (!$model) {
            throw new NotFoundHttpException('Модель не найдена.');
        }
        return $model;
    }
}
