<?php

use yii\db\Migration;

class m260220_133125_add_supplier_fetch_configs extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {

    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        echo "m260220_133125_add_supplier_fetch_configs cannot be reverted.\n";

        return false;
    }

    /*
    // Use up()/down() to run migration code without a transaction.
    public function up()
    {

    }

    public function down()
    {
        echo "m260220_133125_add_supplier_fetch_configs cannot be reverted.\n";

        return false;
    }
    */
}
