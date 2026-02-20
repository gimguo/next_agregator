<?php

namespace backend\models;

use common\models\ProductModel;
use yii\base\Model;
use yii\data\ActiveDataProvider;

/**
 * Поисковая модель для ProductModel (MDM Каталог).
 */
class ProductModelSearch extends Model
{
    public $id;
    public $name;
    public $product_family;
    public $brand_id;
    public $status;

    public function rules(): array
    {
        return [
            [['id', 'brand_id'], 'integer'],
            [['name', 'product_family', 'status'], 'safe'],
        ];
    }

    public function search(array $params): ActiveDataProvider
    {
        $query = ProductModel::find()
            ->with(['brand', 'category']);

        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => 50],
            'sort' => [
                'defaultOrder' => ['id' => SORT_DESC],
                'attributes' => [
                    'id',
                    'name',
                    'product_family',
                    'best_price',
                    'variant_count',
                    'status',
                    'created_at',
                ],
            ],
        ]);

        $this->load($params);

        if (!$this->validate()) {
            return $dataProvider;
        }

        $query->andFilterWhere(['id' => $this->id]);
        $query->andFilterWhere(['brand_id' => $this->brand_id]);
        $query->andFilterWhere(['product_family' => $this->product_family]);
        $query->andFilterWhere(['status' => $this->status]);
        $query->andFilterWhere(['ilike', 'name', $this->name]);

        return $dataProvider;
    }
}
