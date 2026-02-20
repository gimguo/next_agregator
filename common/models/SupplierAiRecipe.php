<?php

namespace common\models;

use yii\db\ActiveRecord;
use Yii;

/**
 * Кэшированный AI-рецепт нормализации для поставщика.
 *
 * Один активный рецепт на поставщика. Содержит маппинги
 * брендов, категорий → ProductFamily и правила извлечения атрибутов.
 *
 * @property int    $id
 * @property int    $supplier_id
 * @property string $supplier_code
 * @property array  $family_mappings   Маппинг категорий → ProductFamily
 * @property array  $brand_mappings    Маппинг брендов (исправление опечаток)
 * @property array  $extraction_rules  Правила извлечения атрибутов
 * @property array  $full_recipe       Полный AI-ответ (для восстановления)
 * @property int    $recipe_version
 * @property int    $sample_size
 * @property string $ai_model
 * @property float  $ai_duration_sec
 * @property int    $ai_tokens_used
 * @property string $data_quality
 * @property string $notes
 * @property bool   $is_active
 * @property string $created_at
 * @property string $updated_at
 *
 * @property Supplier $supplier
 */
class SupplierAiRecipe extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%supplier_ai_recipes}}';
    }

    public function rules(): array
    {
        return [
            [['supplier_id', 'supplier_code'], 'required'],
            [['supplier_id', 'recipe_version', 'sample_size', 'ai_tokens_used'], 'integer'],
            [['ai_duration_sec'], 'number'],
            [['supplier_code'], 'string', 'max' => 50],
            [['ai_model'], 'string', 'max' => 50],
            [['data_quality'], 'string', 'max' => 20],
            [['notes'], 'string'],
            [['is_active'], 'boolean'],
            [['family_mappings', 'brand_mappings', 'extraction_rules', 'full_recipe'], 'safe'],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'id'               => 'ID',
            'supplier_id'      => 'Поставщик',
            'supplier_code'    => 'Код поставщика',
            'family_mappings'  => 'Маппинг семейств',
            'brand_mappings'   => 'Маппинг брендов',
            'extraction_rules' => 'Правила извлечения',
            'full_recipe'      => 'Полный рецепт',
            'recipe_version'   => 'Версия рецепта',
            'sample_size'      => 'Размер выборки',
            'ai_model'         => 'Модель AI',
            'ai_duration_sec'  => 'Время генерации',
            'ai_tokens_used'   => 'Потрачено токенов',
            'data_quality'     => 'Качество данных',
            'notes'            => 'Заметки',
            'is_active'        => 'Активен',
            'created_at'       => 'Создан',
            'updated_at'       => 'Обновлён',
        ];
    }

    // ═══════════════════════════════════════════
    // RELATIONS
    // ═══════════════════════════════════════════

    public function getSupplier()
    {
        return $this->hasOne(Supplier::class, ['id' => 'supplier_id']);
    }

    // ═══════════════════════════════════════════
    // STATIC FINDERS
    // ═══════════════════════════════════════════

    /**
     * Найти активный рецепт для поставщика.
     */
    public static function findActiveForSupplier(int $supplierId): ?self
    {
        return static::find()
            ->where(['supplier_id' => $supplierId, 'is_active' => true])
            ->one();
    }

    /**
     * Найти активный рецепт по коду поставщика.
     */
    public static function findActiveByCode(string $supplierCode): ?self
    {
        return static::find()
            ->where(['supplier_code' => $supplierCode, 'is_active' => true])
            ->one();
    }

    // ═══════════════════════════════════════════
    // GETTERS (автоматический JSON decode)
    // ═══════════════════════════════════════════

    /**
     * Получить family_mappings как массив.
     */
    public function getFamilyMappingsArray(): array
    {
        if (is_array($this->family_mappings)) return $this->family_mappings;
        return json_decode($this->family_mappings ?: '{}', true) ?: [];
    }

    /**
     * Получить brand_mappings как массив.
     */
    public function getBrandMappingsArray(): array
    {
        if (is_array($this->brand_mappings)) return $this->brand_mappings;
        return json_decode($this->brand_mappings ?: '{}', true) ?: [];
    }

    /**
     * Получить extraction_rules как массив.
     */
    public function getExtractionRulesArray(): array
    {
        if (is_array($this->extraction_rules)) return $this->extraction_rules;
        return json_decode($this->extraction_rules ?: '{}', true) ?: [];
    }

    /**
     * Получить полный рецепт как массив.
     */
    public function getFullRecipeArray(): array
    {
        if (is_array($this->full_recipe)) return $this->full_recipe;
        return json_decode($this->full_recipe ?: '{}', true) ?: [];
    }

    // ═══════════════════════════════════════════
    // RECIPE CONVERSION
    // ═══════════════════════════════════════════

    /**
     * Собрать рецепт в формате, совместимом с NormalizeStagedJob.
     *
     * NormalizeStagedJob ожидает:
     *   - brand_mapping: {raw_brand → canonical}
     *   - category_mapping: {raw_category → target_name}
     *   - name_rules, name_template, product_type_rules
     */
    public function toNormalizeRecipe(): array
    {
        $rules = $this->getExtractionRulesArray();
        $full = $this->getFullRecipeArray();

        return [
            'brand_mapping'      => $this->getBrandMappingsArray(),
            'category_mapping'   => $this->getFamilyMappingsArray(),
            'name_rules'         => $rules['name_rules'] ?? ($full['name_rules'] ?? []),
            'name_template'      => $rules['name_template'] ?? ($full['name_template'] ?? '{brand} {model}'),
            'product_type_rules' => $rules['product_type_rules'] ?? ($full['product_type_rules'] ?? []),
            'insights'           => [
                'data_quality' => $this->data_quality,
                'notes'        => $this->notes ? [$this->notes] : [],
            ],
        ];
    }

    /**
     * Создать или обновить рецепт из AI-ответа.
     */
    public static function saveFromAIResponse(
        int $supplierId,
        string $supplierCode,
        array $recipe,
        array $meta = []
    ): self {
        $model = static::findActiveForSupplier($supplierId);

        if ($model) {
            // Инкремент версии
            $model->recipe_version++;
        } else {
            $model = new static();
            $model->supplier_id = $supplierId;
            $model->supplier_code = $supplierCode;
            $model->recipe_version = 1;
        }

        // Разбиваем AI-ответ на компоненты
        $model->brand_mappings = json_encode(
            $recipe['brand_mapping'] ?? [],
            JSON_UNESCAPED_UNICODE
        );
        $model->family_mappings = json_encode(
            $recipe['category_mapping'] ?? [],
            JSON_UNESCAPED_UNICODE
        );
        $model->extraction_rules = json_encode([
            'name_rules'         => $recipe['name_rules'] ?? [],
            'name_template'      => $recipe['name_template'] ?? '{brand} {model}',
            'product_type_rules' => $recipe['product_type_rules'] ?? [],
        ], JSON_UNESCAPED_UNICODE);
        $model->full_recipe = json_encode($recipe, JSON_UNESCAPED_UNICODE);

        // Мета
        $model->sample_size     = $meta['sample_size'] ?? null;
        $model->ai_model        = $meta['ai_model'] ?? 'deepseek-chat';
        $model->ai_duration_sec = $meta['ai_duration_sec'] ?? null;
        $model->ai_tokens_used  = $meta['ai_tokens_used'] ?? null;
        $model->data_quality    = $recipe['insights']['data_quality'] ?? null;
        $model->notes           = is_array($recipe['insights']['notes'] ?? null)
            ? implode("\n", $recipe['insights']['notes'])
            : ($recipe['insights']['notes'] ?? null);
        $model->is_active       = true;
        $model->updated_at      = new \yii\db\Expression('NOW()');

        if (!$model->save(false)) {
            Yii::error("SupplierAiRecipe::saveFromAIResponse failed: " . json_encode($model->errors), 'import');
        }

        return $model;
    }
}
