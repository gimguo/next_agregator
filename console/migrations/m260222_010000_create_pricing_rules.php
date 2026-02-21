<?php

use yii\db\Migration;

/**
 * Sprint 11 — Pricing Engine: Таблица правил наценки + retail_price в supplier_offers.
 *
 * pricing_rules:
 *   Определяет правила ценообразования по уровням:
 *     - global     → применяется ко ВСЕМ товарам (наценка по умолчанию)
 *     - supplier   → наценка для конкретного поставщика (target_id = supplier_id)
 *     - brand      → наценка для бренда (target_id = brand_id)
 *     - family     → наценка для семейства (target_id = NULL, target_value = 'mattress')
 *     - category   → наценка для категории (target_id = category_id)
 *
 *   Приоритет (priority): чем выше, тем важнее.
 *   Несколько правил могут матчить один товар → берётся с максимальным priority.
 *
 * supplier_offers.retail_price:
 *   Рассчитанная розничная цена = base_price + наценка.
 *   GoldenRecord использует COALESCE(retail_price, price_min) для агрегации.
 */
class m260222_010000_create_pricing_rules extends Migration
{
    public function safeUp()
    {
        // ═══ 1. ТАБЛИЦА pricing_rules ═══
        $this->createTable('{{%pricing_rules}}', [
            'id'           => $this->primaryKey(),
            'name'         => $this->string(255)->notNull()->comment('Название правила (напр. "Наценка Орматек +20%")'),

            // Тип цели: к чему применяется правило
            'target_type'  => $this->string(20)->notNull()->comment('global, supplier, brand, family, category'),
            'target_id'    => $this->integer()->comment('ID цели (supplier_id, brand_id, category_id). NULL для global/family'),
            'target_value' => $this->string(100)->comment('Текстовое значение цели (напр. "mattress" для family)'),

            // Тип наценки
            'markup_type'  => $this->string(20)->notNull()->comment('percentage — процент, fixed — фиксированная сумма'),
            'markup_value' => $this->decimal(10, 2)->notNull()->comment('Значение наценки (20.00 = +20% или +20₽)'),

            // Приоритет: чем выше, тем важнее (перекрывает менее приоритетные)
            'priority'     => $this->integer()->notNull()->defaultValue(0)->comment('Приоритет правила (brand:100 > supplier:50 > global:0)'),

            // Дополнительные условия
            'min_price'    => $this->decimal(10, 2)->comment('Применять только если base_price >= min_price'),
            'max_price'    => $this->decimal(10, 2)->comment('Применять только если base_price <= max_price'),

            // Округление
            'rounding'     => $this->string(20)->notNull()->defaultValue('round_up_100')
                              ->comment('Стратегия округления: none, round_up_100, round_up_10, round_down_100'),

            'is_active'    => $this->boolean()->notNull()->defaultValue(true),
            'created_at'   => $this->timestamp()->defaultExpression('NOW()'),
            'updated_at'   => $this->timestamp()->defaultExpression('NOW()'),
        ]);

        // Индексы
        $this->createIndex('idx-pricing_rules-target', '{{%pricing_rules}}', ['target_type', 'target_id', 'is_active']);
        $this->createIndex('idx-pricing_rules-priority', '{{%pricing_rules}}', ['priority']);
        $this->createIndex('idx-pricing_rules-active', '{{%pricing_rules}}', ['is_active']);

        // ═══ 2. retail_price В supplier_offers ═══
        $this->addColumn('{{%supplier_offers}}', 'retail_price',
            $this->decimal(12, 2)->after('compare_price')->comment('Розничная цена = price_min + наценка')
        );

        // Индекс для поиска офферов без retail_price (для массового репрайсинга)
        $this->createIndex('idx-offers-retail_price', '{{%supplier_offers}}', 'retail_price');

        // ═══ 3. SEED: Дефолтное глобальное правило +0% (без наценки) ═══
        $this->insert('{{%pricing_rules}}', [
            'name'         => 'Без наценки (по умолчанию)',
            'target_type'  => 'global',
            'target_id'    => null,
            'target_value' => null,
            'markup_type'  => 'percentage',
            'markup_value' => 0.00,
            'priority'     => 0,
            'rounding'     => 'none',
            'is_active'    => true,
        ]);
    }

    public function safeDown()
    {
        $this->dropIndex('idx-offers-retail_price', '{{%supplier_offers}}');
        $this->dropColumn('{{%supplier_offers}}', 'retail_price');
        $this->dropTable('{{%pricing_rules}}');
    }
}
