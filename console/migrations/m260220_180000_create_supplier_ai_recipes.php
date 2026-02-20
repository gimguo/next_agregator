<?php

use yii\db\Migration;

/**
 * Кэширование AI-рецептов нормализации.
 *
 * Проблема: генерация рецепта через DeepSeek стоит $0.003-0.01 за запрос,
 * и занимает 5-15 секунд. При ежедневном импорте 10 поставщиков — это
 * $0.03-0.10/день и 50-150 секунд задержки без пользы.
 *
 * Решение: кэшировать рецепт для каждого поставщика. Генерировать новый только:
 *   - При первом импорте (нет рецепта)
 *   - При ручном force_regenerate
 *   - Когда структура прайса существенно изменилась
 *
 * Таблица supplier_ai_recipes:
 *   - Один активный рецепт на поставщика (UNIQUE ON supplier_id)
 *   - JSONB для маппингов (семейства, бренды, правила извлечения)
 *   - Версионирование через recipe_version + updated_at
 */
class m260220_180000_create_supplier_ai_recipes extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%supplier_ai_recipes}}', [
            'id'               => $this->primaryKey(),
            'supplier_id'      => $this->integer()->notNull(),
            'supplier_code'    => $this->string(50)->notNull(),

            // Маппинг категорий поставщика → наши ProductFamily
            // Пример: {"Матрасы" → "mattress", "Подушки ортопедические" → "pillow"}
            'family_mappings'  => 'JSONB NOT NULL DEFAULT \'{}\'',

            // Маппинг брендов (исправление опечаток, алиасы)
            // Пример: {"ОРМАТЭК" → "Орматек", "ormatek" → "Орматек"}
            'brand_mappings'   => 'JSONB NOT NULL DEFAULT \'{}\'',

            // Правила извлечения атрибутов из названий/описаний
            // Пример: {"name_template": "{brand} {model}", "size_regex": "\\d+x\\d+"}
            'extraction_rules' => 'JSONB NOT NULL DEFAULT \'{}\'',

            // Полный рецепт от AI (raw response, для восстановления)
            'full_recipe'      => 'JSONB NOT NULL DEFAULT \'{}\'',

            // Мета-информация
            'recipe_version'   => $this->integer()->notNull()->defaultValue(1),
            'sample_size'      => $this->integer()->comment('Кол-во товаров в выборке для генерации'),
            'ai_model'         => $this->string(50)->comment('Модель AI: deepseek-chat, etc.'),
            'ai_duration_sec'  => $this->decimal(10, 2)->comment('Время генерации в секундах'),
            'ai_tokens_used'   => $this->integer()->comment('Потрачено токенов'),
            'data_quality'     => $this->string(20)->comment('Оценка качества данных от AI'),
            'notes'            => $this->text()->comment('Заметки AI о прайсе'),

            'is_active'        => $this->boolean()->notNull()->defaultValue(true),
            'created_at'       => $this->timestamp()->defaultExpression('NOW()'),
            'updated_at'       => $this->timestamp()->defaultExpression('NOW()'),
        ]);

        // Один активный рецепт на поставщика
        $this->createIndex(
            'idx-supplier_ai_recipes-supplier_id',
            '{{%supplier_ai_recipes}}',
            'supplier_id',
            true // UNIQUE
        );

        $this->createIndex(
            'idx-supplier_ai_recipes-supplier_code',
            '{{%supplier_ai_recipes}}',
            'supplier_code'
        );

        $this->createIndex(
            'idx-supplier_ai_recipes-active',
            '{{%supplier_ai_recipes}}',
            ['supplier_id', 'is_active']
        );

        $this->addForeignKey(
            'fk-supplier_ai_recipes-supplier_id',
            '{{%supplier_ai_recipes}}', 'supplier_id',
            '{{%suppliers}}', 'id',
            'CASCADE', 'CASCADE'
        );
    }

    public function safeDown()
    {
        $this->dropTable('{{%supplier_ai_recipes}}');
    }
}
