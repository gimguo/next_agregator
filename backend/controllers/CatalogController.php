<?php

namespace backend\controllers;

use backend\models\ProductModelSearch;
use common\enums\ProductFamily;
use common\models\MediaAsset;
use common\models\ModelChannelReadiness;
use common\models\ModelDataSource;
use common\models\ProductModel;
use common\models\ReferenceVariant;
use common\models\SalesChannel;
use common\models\SupplierOffer;
use common\jobs\HealModelJob;
use common\services\AutoHealingService;
use common\services\GoldenRecordService;
use common\services\OutboxService;
use common\services\ProductFamilySchema;
use common\services\ReadinessScoringService;
use common\services\RosMatrasSyndicationService;
use common\services\marketplace\MarketplaceApiClientInterface;
use common\services\marketplace\MarketplaceUnavailableException;
use yii\db\JsonExpression;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use Yii;

/**
 * Sprint 15 — PIM Cockpit: MDM Каталог с Manual Override и AI Healing.
 *
 * Пульт управления карточками товаров:
 *   - actionView:     детальная карточка с Readiness, Pricing, AI Heal
 *   - actionUpdate:   ручное редактирование с Manual Override (priority=100)
 *   - actionHeal:     принудительное AI-лечение (POST redirect)
 *   - actionHealAjax: принудительное AI-лечение (Ajax/Pjax, JSON)
 *   - actionSync:     ручная синхронизация на витрину
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
                    'sync'      => ['post'],
                    'heal'      => ['post'],
                    'heal-ajax' => ['post'],
                    'bulk'      => ['post'],
                ],
            ],
        ];
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * INDEX
     * ═══════════════════════════════════════════════════════════════════ */

    public function actionIndex(): string
    {
        $searchModel = new ProductModelSearch();
        $dataProvider = $searchModel->search(Yii::$app->request->queryParams);

        return $this->render('index', [
            'searchModel'  => $searchModel,
            'dataProvider' => $dataProvider,
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * VIEW — PIM Cockpit
     * ═══════════════════════════════════════════════════════════════════ */

    public function actionView(int $id): string
    {
        $model   = $this->findModel($id);
        $channel = SalesChannel::find()->where(['is_active' => true])->one();

        // ── Readiness ──────────────────────────────────────────
        $readiness = null;
        if ($channel) {
            $readiness = ModelChannelReadiness::findOne([
                'model_id'   => $id,
                'channel_id' => $channel->id,
            ]);
            if (!$readiness) {
                try {
                    /** @var ReadinessScoringService $scorer */
                    $scorer = Yii::$app->get('readinessService');
                    $scorer->evaluate($id, $channel, true);
                    $readiness = ModelChannelReadiness::findOne([
                        'model_id'   => $id,
                        'channel_id' => $channel->id,
                    ]);
                } catch (\Throwable $e) {
                    Yii::warning("CatalogView: readiness eval failed #{$id}: {$e->getMessage()}", 'catalog');
                }
            }
        }

        // ── Images ─────────────────────────────────────────────
        $images = MediaAsset::find()
            ->where(['entity_type' => 'model', 'entity_id' => $id])
            ->orderBy(['is_primary' => SORT_DESC, 'sort_order' => SORT_ASC])
            ->all();

        // ── Variants + Offers ──────────────────────────────────
        $variants   = ReferenceVariant::find()
            ->where(['model_id' => $id])
            ->orderBy(['sort_order' => SORT_ASC, 'variant_label' => SORT_ASC])
            ->all();

        $variantIds = array_map(fn($v) => $v->id, $variants);
        $offers     = [];
        if ($variantIds) {
            $all = SupplierOffer::find()
                ->where(['variant_id' => $variantIds])
                ->with('supplier')
                ->orderBy(['variant_id' => SORT_ASC, 'price_min' => SORT_ASC])
                ->all();
            foreach ($all as $o) {
                $offers[$o->variant_id][] = $o;
            }
        }
        $orphanOffers = SupplierOffer::find()
            ->where(['model_id' => $id, 'variant_id' => null])
            ->with('supplier')
            ->all();

        // ── Data Sources ───────────────────────────────────────
        $dataSources = ModelDataSource::find()
            ->where(['model_id' => $id])
            ->orderBy(['priority' => SORT_DESC, 'updated_at' => SORT_DESC])
            ->all();

        // ── Attribute schema ───────────────────────────────────
        $family = $model->product_family
            ? (ProductFamily::tryFrom($model->product_family) ?? ProductFamily::UNKNOWN)
            : ProductFamily::UNKNOWN;
        $familySchema = ProductFamilySchema::getSchema($family);

        // ── Per-attribute source map ───────────────────────────
        // Determine which source provided each attribute (highest priority wins)
        $attrSourceMap  = self::buildAttrSourceMap($dataSources);
        $descSource     = self::resolveFieldSource($dataSources, 'description');

        return $this->render('view', [
            'model'         => $model,
            'images'        => $images,
            'variants'      => $variants,
            'offers'        => $offers,
            'orphanOffers'  => $orphanOffers,
            'readiness'     => $readiness,
            'channel'       => $channel,
            'dataSources'   => $dataSources,
            'familySchema'  => $familySchema,
            'attrSourceMap' => $attrSourceMap,
            'descSource'    => $descSource,
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * UPDATE — Manual Override
     * ═══════════════════════════════════════════════════════════════════ */

    public function actionUpdate(int $id): string|Response
    {
        $model = $this->findModel($id);

        // Текущие атрибуты
        $currentAttrs = self::parseJsonAttrs($model->canonical_attributes);

        // manual_override данные
        $manualSource = ModelDataSource::findOne([
            'model_id'    => $id,
            'source_type' => ModelDataSource::SOURCE_MANUAL,
            'source_id'   => 'admin',
        ]);
        $manualData = $manualSource ? $manualSource->getDataArray() : [];

        // All sources for attribute-level badge
        $allSources = ModelDataSource::find()
            ->where(['model_id' => $id])
            ->orderBy(['priority' => SORT_DESC])
            ->all();
        $attrSourceMap = self::buildAttrSourceMap($allSources);

        // Schema
        $family = $model->product_family
            ? (ProductFamily::tryFrom($model->product_family) ?? ProductFamily::UNKNOWN)
            : ProductFamily::UNKNOWN;
        $familySchema = ProductFamilySchema::getSchema($family);

        // ── POST: save ─────────────────────────────────────────
        if (Yii::$app->request->isPost) {
            $post = Yii::$app->request->post();

            $overrideData = [];
            $changes      = [];

            // Description
            $newDesc = trim($post['description'] ?? '');
            if ($newDesc !== '' && $newDesc !== ($model->description ?? '')) {
                $overrideData['description'] = $newDesc;
                $changes[] = 'описание';
            }

            // Short description
            $newShort = trim($post['short_description'] ?? '');
            if ($newShort !== '' && $newShort !== ($model->short_description ?? '')) {
                $overrideData['short_description'] = $newShort;
                $changes[] = 'краткое описание';
            }

            // Attributes (schema-based inputs)
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

            if ($newAttrs) {
                $attrDiff = [];
                foreach ($newAttrs as $k => $v) {
                    if (!isset($currentAttrs[$k]) || (string)$currentAttrs[$k] !== (string)$v) {
                        $attrDiff[$k] = $v;
                    }
                }
                if ($attrDiff) {
                    $overrideData['attributes'] = $newAttrs;
                    $changes[] = count($attrDiff) . ' атрибут(ов)';
                }
            }

            if (!$overrideData && !$changes) {
                Yii::$app->session->setFlash('info', 'Нет изменений для сохранения.');
                return $this->redirect(['view', 'id' => $id]);
            }

            $db = Yii::$app->db;
            $tx = $db->beginTransaction();
            try {
                // 1. model_data_sources
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

                // 2. Apply to ProductModel
                $upd = [];
                if (isset($overrideData['description'])) {
                    $upd['description'] = $overrideData['description'];
                }
                if (isset($overrideData['short_description'])) {
                    $upd['short_description'] = $overrideData['short_description'];
                }
                if (isset($overrideData['attributes'])) {
                    $upd['canonical_attributes'] = new JsonExpression(
                        array_merge($currentAttrs, $overrideData['attributes'])
                    );
                }
                if ($upd) {
                    $upd['updated_at'] = new \yii\db\Expression('NOW()');
                    $db->createCommand()->update('{{%product_models}}', $upd, ['id' => $id])->execute();
                }

                // 3. Golden Record
                /** @var GoldenRecordService $gr */
                $gr = Yii::$app->get('goldenRecord');
                $gr->recalculateModel($id);

                // 4. Readiness
                $channel = SalesChannel::find()->where(['is_active' => true])->one();
                $rr      = null;
                if ($channel) {
                    /** @var ReadinessScoringService $scorer */
                    $scorer = Yii::$app->get('readinessService');
                    $scorer->resetCache();
                    $rr = $scorer->evaluate($id, $channel, true);

                    // 5. Outbox if ready
                    if ($rr->isReady) {
                        try {
                            /** @var OutboxService $outbox */
                            $outbox = Yii::$app->get('outbox');
                            $gate = $outbox->readinessGate;
                            $outbox->readinessGate = false;
                            $outbox->emitContentUpdate($id, null, ['source' => 'manual_override']);
                            $outbox->readinessGate = $gate;
                        } catch (\Throwable $e) {
                            Yii::warning("ManualOverride outbox #{$id}: {$e->getMessage()}", 'catalog');
                        }
                    }
                }

                $tx->commit();

                $msg = '✓ Manual Override (P:100): ' . implode(', ', $changes) . '.';
                if ($rr) {
                    $msg .= " Readiness: {$rr->score}%";
                    $msg .= $rr->isReady ? ' → Outbox ✓' : '';
                }
                Yii::$app->session->setFlash('success', $msg);
            } catch (\Throwable $e) {
                $tx->rollBack();
                Yii::error("ManualOverride #{$id}: {$e->getMessage()}", 'catalog');
                Yii::$app->session->setFlash('error', "Ошибка: {$e->getMessage()}");
            }

            return $this->redirect(['view', 'id' => $id]);
        }

        // GET
        return $this->render('update', [
            'model'         => $model,
            'currentAttrs'  => $currentAttrs,
            'manualData'    => $manualData,
            'familySchema'  => $familySchema,
            'attrSourceMap' => $attrSourceMap,
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * HEAL (POST redirect) — AI лечение
     * ═══════════════════════════════════════════════════════════════════ */

    public function actionHeal(int $id): Response
    {
        $model   = $this->findModel($id);
        $channel = SalesChannel::find()->where(['is_active' => true])->one();

        if (!$channel) {
            Yii::$app->session->setFlash('error', 'Нет активного канала продаж.');
            return $this->redirect(['view', 'id' => $id]);
        }

        $readiness = ModelChannelReadiness::findOne([
            'model_id'   => $id,
            'channel_id' => $channel->id,
        ]);
        if (!$readiness || $readiness->is_ready) {
            Yii::$app->session->setFlash('info', 'Модель уже готова или нет данных readiness.');
            return $this->redirect(['view', 'id' => $id]);
        }

        $missing = $readiness->getMissingList();
        if (!$missing) {
            Yii::$app->session->setFlash('info', 'Нет пропущенных полей.');
            return $this->redirect(['view', 'id' => $id]);
        }

        try {
            /** @var AutoHealingService $healer */
            $healer = Yii::$app->get('autoHealer');
            $result = $healer->healModel($id, $missing, $channel);

            if ($result->success) {
                $list  = implode(', ', $result->healedFields);
                $extra = $result->newIsReady ? " → Outbox ✓" : '';
                Yii::$app->session->setFlash('success',
                    "🧬 AI вылечил: {$list}. Score: {$result->newScore}%{$extra}");
            } else {
                Yii::$app->session->setFlash('warning',
                    'AI не смог вылечить: ' . implode('; ', $result->errors));
            }
        } catch (\Throwable $e) {
            Yii::error("AI Heal #{$id}: {$e->getMessage()}", 'catalog');
            Yii::$app->session->setFlash('error', "Ошибка AI: {$e->getMessage()}");
        }

        return $this->redirect(['view', 'id' => $id]);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * HEAL-AJAX — AI лечение через Ajax/Pjax (JSON response)
     * ═══════════════════════════════════════════════════════════════════ */

    public function actionHealAjax(int $id): array
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $model   = $this->findModel($id);
        $channel = SalesChannel::find()->where(['is_active' => true])->one();

        if (!$channel) {
            return ['success' => false, 'message' => 'Нет активного канала продаж.'];
        }

        // Evaluate readiness fresh
        /** @var ReadinessScoringService $scorer */
        $scorer = Yii::$app->get('readinessService');
        $scorer->resetCache();
        $report = $scorer->evaluate($id, $channel, true);

        if ($report->isReady) {
            return [
                'success' => true,
                'message' => 'Модель уже готова (100%).',
                'score'   => $report->score,
                'missing' => [],
                'healed'  => [],
            ];
        }

        $missing = $report->missing;
        if (!$missing) {
            return ['success' => true, 'message' => 'Нет пропущенных полей.', 'score' => $report->score, 'missing' => [], 'healed' => []];
        }

        try {
            /** @var AutoHealingService $healer */
            $healer = Yii::$app->get('autoHealer');

            if (!$healer->hasHealableFields($missing)) {
                return [
                    'success' => false,
                    'message' => 'Нет полей, которые AI может вылечить (только изображения/штрихкоды?).',
                    'score'   => $report->score,
                    'missing' => $missing,
                    'healed'  => [],
                ];
            }

            $result = $healer->healModel($id, $missing, $channel);

            // Re-read readiness after heal
            $scorer->resetCache();
            $newReport = $scorer->evaluate($id, $channel, true);

            return [
                'success'      => $result->success,
                'message'      => $result->success
                    ? 'AI вылечил: ' . implode(', ', $result->healedFields)
                    : 'AI не смог: ' . implode('; ', $result->errors),
                'score'        => $newReport->score,
                'missing'      => $newReport->missing,
                'healed'       => $result->healedFields,
                'newIsReady'   => $newReport->isReady,
                'errors'       => $result->errors,
            ];
        } catch (\Throwable $e) {
            Yii::error("AI Heal AJAX #{$id}: {$e->getMessage()}", 'catalog');
            return [
                'success' => false,
                'message' => "Ошибка: {$e->getMessage()}",
                'score'   => $report->score,
                'missing' => $missing,
                'healed'  => [],
            ];
        }
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * SYNC — ручная отправка на витрину
     * ═══════════════════════════════════════════════════════════════════ */

    public function actionSync(int $id): Response
    {
        $model = $this->findModel($id);

        try {
            /** @var RosMatrasSyndicationService $syndicator */
            $syndicator = Yii::$app->get('syndicationService');
            /** @var MarketplaceApiClientInterface $client */
            $client = Yii::$app->get('marketplaceClient');

            $projection = $syndicator->buildProductProjection($id);
            if (!$projection) {
                Yii::$app->session->setFlash('warning', "Не удалось построить проекцию для #{$id}.");
                return $this->redirect(['view', 'id' => $id]);
            }

            $ok = $client->pushProduct($id, $projection);
            if ($ok) {
                $v = $projection['variant_count'] ?? 0;
                $i = count($projection['images'] ?? []);
                $p = $projection['best_price'] ? number_format($projection['best_price'], 0, '.', ' ') . ' ₽' : 'N/A';
                Yii::$app->session->setFlash('success', "✓ «{$model->name}» → витрина ({$v} вар., {$i} фото, {$p})");
            } else {
                Yii::$app->session->setFlash('error', "API вернул false при отправке «{$model->name}».");
            }
        } catch (MarketplaceUnavailableException $e) {
            Yii::$app->session->setFlash('error', "API недоступен: {$e->getMessage()}");
        } catch (\Throwable $e) {
            Yii::$app->session->setFlash('error', "Ошибка синхронизации: {$e->getMessage()}");
        }

        return $this->redirect(['view', 'id' => $id]);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * HELPERS
     * ═══════════════════════════════════════════════════════════════════ */

    protected function findModel(int $id): ProductModel
    {
        $m = ProductModel::findOne($id);
        if (!$m) {
            throw new NotFoundHttpException('Модель не найдена.');
        }
        return $m;
    }

    /**
     * Parse JSONB canonical_attributes into array.
     */
    protected static function parseJsonAttrs($raw): array
    {
        if (empty($raw)) return [];
        if (is_string($raw)) return json_decode($raw, true) ?: [];
        return is_array($raw) ? $raw : [];
    }

    /**
     * Build per-attribute source map: attr_key → ['type' => 'manual_override', 'label' => 'Ручная', 'priority' => 100]
     *
     * Goes through data sources from highest priority to lowest.
     * The first source that declares an attribute "owns" it.
     */
    protected static function buildAttrSourceMap(array $dataSources): array
    {
        $map = [];

        // dataSources are already ordered by priority DESC
        foreach ($dataSources as $ds) {
            /** @var ModelDataSource $ds */
            $data = $ds->getDataArray();
            $attrs = $data['attributes'] ?? [];

            foreach ($attrs as $key => $val) {
                if ($val !== null && $val !== '' && !isset($map[$key])) {
                    $map[$key] = [
                        'type'     => $ds->source_type,
                        'label'    => ModelDataSource::sourceTypes()[$ds->source_type] ?? $ds->source_type,
                        'priority' => $ds->priority,
                    ];
                }
            }
        }

        return $map;
    }

    /**
     * Find which source provided a top-level field (description, short_description).
     */
    protected static function resolveFieldSource(array $dataSources, string $field): ?array
    {
        foreach ($dataSources as $ds) {
            /** @var ModelDataSource $ds */
            $data = $ds->getDataArray();
            if (isset($data[$field]) && $data[$field] !== '' && $data[$field] !== null) {
                return [
                    'type'     => $ds->source_type,
                    'label'    => ModelDataSource::sourceTypes()[$ds->source_type] ?? $ds->source_type,
                    'priority' => $ds->priority,
                ];
            }
        }
        return null;
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * BULK — Массовые действия (Sprint 21)
     * ═══════════════════════════════════════════════════════════════════ */

    public function actionBulk(): Response
    {
        $request = Yii::$app->request;
        $action = $request->post('action');
        $modelIdsStr = $request->post('model_ids', '');

        if (empty($action) || empty($modelIdsStr)) {
            return $this->asJson([
                'success' => false,
                'message' => 'Не указано действие или товары',
            ]);
        }

        $modelIds = array_filter(array_map('intval', explode(',', $modelIdsStr)));
        if (empty($modelIds)) {
            return $this->asJson([
                'success' => false,
                'message' => 'Не указаны ID товаров',
            ]);
        }

        $channel = SalesChannel::find()->where(['is_active' => true])->one();
        if (!$channel) {
            return $this->asJson([
                'success' => false,
                'message' => 'Нет активного канала продаж',
            ]);
        }

        $processed = 0;
        $errors = [];

        try {
            switch ($action) {
                case 'heal':
                    // Массовое AI-лечение: отправляем в очередь
                    foreach ($modelIds as $modelId) {
                        try {
                            Yii::$app->queue->push(new HealModelJob([
                                'modelId' => $modelId,
                                'channelId' => $channel->id,
                            ]));
                            $processed++;
                        } catch (\Throwable $e) {
                            $errors[] = "Модель #{$modelId}: {$e->getMessage()}";
                            Yii::error("Bulk heal error for model #{$modelId}: {$e->getMessage()}", 'catalog.bulk');
                        }
                    }

                    return $this->asJson([
                        'success' => true,
                        'message' => "{$processed} товаров отправлено на лечение ИИ" . (!empty($errors) ? '. Ошибок: ' . count($errors) : ''),
                    ]);

                case 'recalculate-readiness':
                    // Принудительно пересчитать Readiness
                    /** @var ReadinessScoringService $scorer */
                    $scorer = Yii::$app->get('readinessService');

                    foreach ($modelIds as $modelId) {
                        try {
                            $model = ProductModel::findOne($modelId);
                            if (!$model) {
                                $errors[] = "Модель #{$modelId} не найдена";
                                continue;
                            }

                            $scorer->evaluate($modelId, $channel, true);
                            $processed++;
                        } catch (\Throwable $e) {
                            $errors[] = "Модель #{$modelId}: {$e->getMessage()}";
                            Yii::error("Bulk recalculate-readiness error for model #{$modelId}: {$e->getMessage()}", 'catalog.bulk');
                        }
                    }

                    return $this->asJson([
                        'success' => true,
                        'message' => "Readiness пересчитан для {$processed} товаров" . (!empty($errors) ? '. Ошибок: ' . count($errors) : ''),
                    ]);

                default:
                    return $this->asJson([
                        'success' => false,
                        'message' => "Неизвестное действие: {$action}",
                    ]);
            }
        } catch (\Throwable $e) {
            Yii::error("Bulk action error: {$e->getMessage()}", 'catalog.bulk');
            return $this->asJson([
                'success' => false,
                'message' => "Ошибка выполнения: {$e->getMessage()}",
            ]);
        }
    }
}
