<?php

use yii\db\Migration;

/**
 * Sprint 13: AI Auto-Healing.
 *
 * Добавляет колонку last_heal_attempt_at в model_channel_readiness
 * для предотвращения повторных попыток лечения одной и той же модели.
 */
class m260222_030000_add_heal_attempt_to_readiness extends Migration
{
    public function safeUp()
    {
        // Колонка для отслеживания последней попытки AI-лечения
        $this->addColumn('{{%model_channel_readiness}}', 'last_heal_attempt_at', $this->timestamp()->null());

        // Индекс для быстрой выборки «не лечённых» моделей
        $this->createIndex(
            'idx-readiness-heal-attempt',
            '{{%model_channel_readiness}}',
            ['channel_id', 'is_ready', 'last_heal_attempt_at']
        );

        echo "    > added last_heal_attempt_at to model_channel_readiness\n";
    }

    public function safeDown()
    {
        $this->dropIndex('idx-readiness-heal-attempt', '{{%model_channel_readiness}}');
        $this->dropColumn('{{%model_channel_readiness}}', 'last_heal_attempt_at');
    }
}
