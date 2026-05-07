<?php

use yii\db\Migration;

/**
 * Handles adding access_token to table `{{%employees}}`.
 */
class m260502_100_add_access_token_to_employees_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->addColumn('{{%employees}}', 'access_token', $this->string(255)->unique()->after('username'));
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropColumn('{{%employees}}', 'access_token');
    }
}
