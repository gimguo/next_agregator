<?php

use yii\db\Migration;

/**
 * Sprint 17: Сид базового системного шаблона каталога.
 *
 * Создаёт базовый шаблон с простой структурой:
 *   - Матрасы (slug: mattresses)
 *   - Кровати (slug: beds)
 */
class m260223_020000_seed_default_catalog_template extends Migration
{
    public function safeUp()
    {
        $structure = [
            'categories' => [
                [
                    'id' => 1,
                    'name' => 'Матрасы',
                    'slug' => 'mattresses',
                    'parent_id' => null,
                    'sort_order' => 1,
                ],
                [
                    'id' => 2,
                    'name' => 'Кровати',
                    'slug' => 'beds',
                    'parent_id' => null,
                    'sort_order' => 2,
                ],
            ],
        ];

        $this->insert('{{%catalog_templates}}', [
            'name' => 'Базовый',
            'description' => 'Максимально подробный каталог со всеми категориями как у поставщиков',
            'structure_json' => json_encode($structure, JSON_UNESCAPED_UNICODE),
            'merge_rules' => null,
            'is_system' => true,
        ]);
    }

    public function safeDown()
    {
        $this->delete('{{%catalog_templates}}', ['name' => 'Базовый', 'is_system' => true]);
    }
}
