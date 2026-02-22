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
                    'slug' => 'matrasy',
                    'parent_id' => null,
                    'sort_order' => 1,
                    'rules' => [
                        'family' => ['mattress'],
                    ],
                    'children' => [
                        [
                            'id' => 2,
                            'name' => 'Пружинные',
                            'slug' => 'pruzhinnye',
                            'parent_id' => 1,
                            'sort_order' => 1,
                            'rules' => [
                                'family' => ['mattress'],
                                'attributes' => [
                                    'spring_block' => ['tfk', 's1000', 'multipocket'],
                                ],
                            ],
                            'children' => [],
                        ],
                        [
                            'id' => 3,
                            'name' => 'Беспружинные',
                            'slug' => 'bespruzhinnye',
                            'parent_id' => 1,
                            'sort_order' => 2,
                            'rules' => [
                                'family' => ['mattress'],
                                'attributes' => [
                                    'spring_block' => ['monolith', 'latex', 'memory'],
                                ],
                            ],
                            'children' => [],
                        ],
                    ],
                ],
                [
                    'id' => 4,
                    'name' => 'Кровати',
                    'slug' => 'krovati',
                    'parent_id' => null,
                    'sort_order' => 2,
                    'rules' => [
                        'family' => ['bed'],
                    ],
                    'children' => [],
                ],
                [
                    'id' => 5,
                    'name' => 'Подушки',
                    'slug' => 'podushki',
                    'parent_id' => null,
                    'sort_order' => 3,
                    'rules' => [
                        'family' => ['pillow'],
                    ],
                    'children' => [],
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
