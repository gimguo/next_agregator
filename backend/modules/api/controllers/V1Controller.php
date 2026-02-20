<?php

namespace backend\modules\api\controllers;

use common\models\Brand;
use common\models\Category;
use common\models\ProductCard;
use common\models\Supplier;
use common\models\SupplierOffer;
use common\models\CardImage;
use yii\data\ActiveDataProvider;
use Yii;

/**
 * REST API v1 для синхронизации с ros-matras.
 *
 * Эндпоинты:
 *   GET /api/v1/cards          — карточки с пагинацией
 *   GET /api/v1/cards?id=42    — одна карточка (полная)
 *   GET /api/v1/updated        — обновлённые карточки с даты
 *   GET /api/v1/brands         — бренды
 *   GET /api/v1/categories     — категории
 *   GET /api/v1/suppliers      — поставщики
 *   GET /api/v1/stats          — статистика
 */
class V1Controller extends BaseApiController
{
    /**
     * GET /api/v1/cards
     * Параметры: page, per-page, status, brand, category_id, search, id, include
     * include=offers — включить офферы в ответ (для синхронизации)
     * include=offers,images — включить офферы и картинки
     */
    public function actionCards(): array
    {
        $request = Yii::$app->request;
        $id = $request->get('id');

        // Одна карточка с полными данными
        if ($id) {
            return $this->actionCardDetail((int)$id);
        }

        $query = ProductCard::find()
            ->orderBy(['updated_at' => SORT_DESC]);

        // Фильтры
        $status = $request->get('status');
        if ($status) $query->andWhere(['status' => $status]);

        $brand = $request->get('brand');
        if ($brand) $query->andWhere(['brand' => $brand]);

        $categoryId = $request->get('category_id');
        if ($categoryId) $query->andWhere(['category_id' => (int)$categoryId]);

        $inStock = $request->get('in_stock');
        if ($inStock !== null) $query->andWhere(['is_in_stock' => (bool)$inStock]);

        $search = $request->get('search');
        if ($search) $query->andWhere(['ilike', 'canonical_name', $search]);

        // Eager loading для include
        $includes = array_filter(explode(',', $request->get('include', '')));
        $includeOffers = in_array('offers', $includes);
        $includeImages = in_array('images', $includes);

        if ($includeOffers) {
            $query->with(['offers.supplier']);
        }
        if ($includeImages) {
            $query->with(['images']);
        }

        $provider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => [
                'pageSize' => min((int)($request->get('per-page', 50)), 200),
            ],
        ]);

        $cards = [];
        foreach ($provider->getModels() as $card) {
            $cardData = $this->serializeCard($card);

            if ($includeOffers) {
                $cardData['offers'] = [];
                foreach ($card->offers as $offer) {
                    $offerData = [
                        'supplier_code' => $offer->supplier->code ?? null,
                        'supplier_name' => $offer->supplier->name ?? null,
                        'supplier_sku' => $offer->supplier_sku,
                        'price_min' => $offer->price_min,
                        'price_max' => $offer->price_max,
                        'compare_price' => $offer->price_max,
                        'in_stock' => $offer->in_stock,
                        'variant_count' => $offer->variant_count,
                        'is_active' => $offer->is_active,
                        'updated_at' => $offer->updated_at,
                    ];
                    // Включаем variants_json если есть
                    if ($offer->variants_json) {
                        $offerData['variants_json'] = is_string($offer->variants_json)
                            ? json_decode($offer->variants_json, true)
                            : $offer->variants_json;
                    }
                    $cardData['offers'][] = $offerData;
                }
            }

            if ($includeImages) {
                $cardData['images'] = [];
                $baseUrl = rtrim(Yii::$app->params['appUrl'] ?? '', '/');
                foreach ($card->images as $img) {
                    if ($img->status !== 'completed') continue;
                    $cardData['images'][] = [
                        'url' => $baseUrl . $img->getDisplayUrl('large'),
                        'thumb' => $baseUrl . $img->getDisplayUrl('thumb'),
                        'source_url' => $img->source_url,
                        'is_main' => $img->is_main,
                        'width' => $img->width,
                        'height' => $img->height,
                    ];
                }
            }

            $cards[] = $cardData;
        }

        $pagination = $provider->getPagination();
        return $this->success($cards, [
            'total' => $provider->getTotalCount(),
            'page' => $pagination->getPage() + 1,
            'pageSize' => $pagination->getPageSize(),
            'pageCount' => $pagination->getPageCount(),
        ]);
    }

    /**
     * Детальная карточка по ID.
     */
    protected function actionCardDetail(int $id): array
    {
        $card = ProductCard::findOne($id);
        if (!$card) {
            return $this->error('Карточка не найдена', 404);
        }

        $data = $this->serializeCard($card, true);

        // Офферы
        $data['offers'] = [];
        foreach ($card->offers as $offer) {
            $data['offers'][] = [
                'supplier_code' => $offer->supplier->code ?? null,
                'supplier_name' => $offer->supplier->name ?? null,
                'supplier_sku' => $offer->supplier_sku,
                'price_min' => $offer->price_min,
                'price_max' => $offer->price_max,
                'in_stock' => $offer->in_stock,
                'variant_count' => $offer->variant_count,
                'is_active' => $offer->is_active,
                'updated_at' => $offer->updated_at,
            ];
        }

        // Картинки
        $data['images'] = [];
        $baseUrl = rtrim(Yii::$app->params['appUrl'] ?? '', '/');
        foreach ($card->images as $img) {
            if ($img->status !== 'completed') continue;
            $data['images'][] = [
                'url' => $baseUrl . $img->getDisplayUrl('large'),
                'thumb' => $baseUrl . $img->getDisplayUrl('thumb'),
                'source_url' => $img->source_url,
                'is_main' => $img->is_main,
                'width' => $img->width,
                'height' => $img->height,
            ];
        }

        return $this->success($data);
    }

    /**
     * GET /api/v1/updated?since=2026-02-20T00:00:00
     * Карточки обновлённые после указанной даты.
     * Всегда включает офферы для синхронизации.
     */
    public function actionUpdated(): array
    {
        $since = Yii::$app->request->get('since');
        if (!$since) {
            return $this->error('Параметр since обязателен (формат: 2026-02-20T00:00:00)');
        }

        $query = ProductCard::find()
            ->where(['>=', 'updated_at', $since])
            ->with(['offers.supplier', 'images'])
            ->orderBy(['updated_at' => SORT_DESC]);

        $perPage = min((int)(Yii::$app->request->get('per-page', 100)), 500);
        $provider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => $perPage],
        ]);

        $cards = [];
        foreach ($provider->getModels() as $card) {
            $cardData = $this->serializeCard($card);
            $cardData['offers'] = [];
            foreach ($card->offers as $offer) {
                $offerData = [
                    'supplier_code' => $offer->supplier->code ?? null,
                    'supplier_sku' => $offer->supplier_sku,
                    'price_min' => $offer->price_min,
                    'price_max' => $offer->price_max,
                    'compare_price' => $offer->price_max,
                    'in_stock' => $offer->in_stock,
                    'variant_count' => $offer->variant_count,
                    'is_active' => $offer->is_active,
                ];
                if ($offer->variants_json) {
                    $offerData['variants_json'] = is_string($offer->variants_json)
                        ? json_decode($offer->variants_json, true)
                        : $offer->variants_json;
                }
                $cardData['offers'][] = $offerData;
            }
            $cardData['images'] = [];
            $baseUrl = rtrim(Yii::$app->params['appUrl'] ?? '', '/');
            foreach ($card->images as $img) {
                if ($img->status !== 'completed') continue;
                $cardData['images'][] = [
                    'url' => $baseUrl . $img->getDisplayUrl('large'),
                    'thumb' => $baseUrl . $img->getDisplayUrl('thumb'),
                    'source_url' => $img->source_url,
                    'is_main' => $img->is_main,
                ];
            }
            $cards[] = $cardData;
        }

        return $this->success($cards, [
            'since' => $since,
            'total' => $provider->getTotalCount(),
        ]);
    }

    /**
     * GET /api/v1/brands
     */
    public function actionBrands(): array
    {
        $brands = Brand::find()->orderBy('canonical_name')->asArray()->all();

        $data = [];
        foreach ($brands as $b) {
            $data[] = [
                'id' => (int)$b['id'],
                'name' => $b['canonical_name'],
                'slug' => $b['slug'],
                'country' => $b['country'] ?? null,
                'product_count' => (int)($b['product_count'] ?? 0),
            ];
        }

        return $this->success($data);
    }

    /**
     * GET /api/v1/categories
     */
    public function actionCategories(): array
    {
        $categories = Category::find()->orderBy('sort_order, name')->asArray()->all();

        $data = [];
        foreach ($categories as $c) {
            $data[] = [
                'id' => (int)$c['id'],
                'name' => $c['name'],
                'slug' => $c['slug'],
                'parent_id' => $c['parent_id'] ? (int)$c['parent_id'] : null,
            ];
        }

        return $this->success($data);
    }

    /**
     * GET /api/v1/suppliers
     */
    public function actionSuppliers(): array
    {
        $suppliers = Supplier::find()
            ->where(['is_active' => true])
            ->orderBy('name')
            ->all();

        $data = [];
        foreach ($suppliers as $s) {
            $data[] = [
                'id' => $s->id,
                'code' => $s->code,
                'name' => $s->name,
                'format' => $s->format,
                'last_import_at' => $s->last_import_at,
                'offers_count' => $s->getOffersCount(),
            ];
        }

        return $this->success($data);
    }

    /**
     * GET /api/v1/stats
     */
    public function actionStats(): array
    {
        return $this->success([
            'cards_total' => (int)ProductCard::find()->count(),
            'cards_active' => (int)ProductCard::find()->where(['status' => 'active'])->count(),
            'cards_published' => (int)ProductCard::find()->where(['is_published' => true])->count(),
            'offers_total' => (int)SupplierOffer::find()->count(),
            'offers_active' => (int)SupplierOffer::find()->where(['is_active' => true])->count(),
            'suppliers_active' => (int)Supplier::find()->where(['is_active' => true])->count(),
            'images_total' => (int)CardImage::find()->count(),
            'images_completed' => (int)CardImage::find()->where(['status' => 'completed'])->count(),
            'brands_total' => (int)Brand::find()->count(),
            'categories_total' => (int)Category::find()->count(),
        ]);
    }

    /**
     * Сериализация карточки в массив.
     */
    protected function serializeCard(ProductCard $card, bool $full = false): array
    {
        $data = [
            'id' => $card->id,
            'canonical_name' => $card->canonical_name,
            'slug' => $card->slug,
            'brand' => $card->brand,
            'manufacturer' => $card->manufacturer,
            'model' => $card->model,
            'product_type' => $card->product_type,
            'category_id' => $card->category_id,
            'brand_id' => $card->brand_id,
            'best_price' => $card->best_price,
            'price_range_min' => $card->price_range_min,
            'price_range_max' => $card->price_range_max,
            'supplier_count' => $card->supplier_count,
            'total_variants' => $card->total_variants,
            'is_in_stock' => $card->is_in_stock,
            'status' => $card->status,
            'quality_score' => $card->quality_score,
            'image_count' => $card->image_count,
            'updated_at' => $card->updated_at,
        ];

        if ($full) {
            $data['description'] = $card->description;
            $data['has_active_offers'] = $card->has_active_offers;
            $data['is_published'] = $card->is_published;
            $data['created_at'] = $card->created_at;
        }

        return $data;
    }
}
