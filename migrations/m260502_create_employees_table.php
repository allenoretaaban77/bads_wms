<?php

use yii\db\Migration;

/**
 * Handles the creation of table `{{%employees}}`.
 */
class m260502_create_employees_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%employees}}', [
            'id' => $this->primaryKey(),
            'employee_id' => $this->integer(),
            'employee_number' => $this->string(255)->notNull()->unique(),
            'firstname' => $this->string(255)->notNull(),
            'lastname' => $this->string(255)->notNull(),
            'surname' => $this->string(255),
            'username' => $this->string(255)->notNull()->unique(),
            'hash' => $this->text(),
            'status' => $this->string(50),
            'status_id' => $this->integer()->defaultValue(1),
            'position_name' => $this->string(255),
            'position_id' => $this->integer(),
            'date_created' => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP'),
            'date_updated' => $this->timestamp()->defaultExpression('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'),
        ]);

        // Create indexes for better query performance
        $this->createIndex(
            '{{%idx_employees_username}}',
            '{{%employees}}',
            'username'
        );

        $this->createIndex(
            '{{%idx_employees_employee_number}}',
            '{{%employees}}',
            'employee_number'
        );

        $this->createIndex(
            '{{%idx_employees_status_id}}',
            '{{%employees}}',
            'status_id'
        );

        $this->createIndex(
            '{{%idx_employees_position_id}}',
            '{{%employees}}',
            'position_id'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropTable('{{%employees}}');
    }
}
