<?php

namespace app\models;

use Yii;

/**
 * This is the model class for table "employees".
 *
 * @property int $id
 * @property int|null $employee_id
 * @property string $employee_number
 * @property string $firstname
 * @property string $middlename
 * @property string|null $lastname
 * @property string $username
 * @property string|null $access_token
 * @property string|null $hash
 * @property string|null $status
 * @property int|null $status_id
 * @property string|null $position_name
 * @property int|null $position_id
 * @property string|null $date_created
 * @property string|null $date_updated
 */
class Employees extends \yii\db\ActiveRecord
{


    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'employees';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['employee_id', 'lastname', 'access_token', 'hash', 'status', 'position_name', 'position_id', 'date_created', 'date_updated'], 'default', 'value' => null],
            [['status_id'], 'default', 'value' => 1],
            [['employee_id', 'status_id', 'position_id'], 'integer'],
            [['employee_number', 'firstname', 'middlename', 'username'], 'required'],
            [['hash'], 'string'],
            [['date_created', 'date_updated'], 'safe'],
            [['employee_number', 'firstname', 'middlename', 'lastname', 'username', 'access_token', 'position_name'], 'string', 'max' => 255],
            [['status'], 'string', 'max' => 50],
            [['employee_number'], 'unique'],
            [['username'], 'unique'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'employee_id' => 'Employee ID',
            'employee_number' => 'Employee Number',
            'firstname' => 'Firstname',
            'middlename' => 'Middlename',
            'lastname' => 'Lastname',
            'username' => 'Username',
            'access_token' => 'Access Token',
            'hash' => 'Hash',
            'status' => 'Status',
            'status_id' => 'Status ID',
            'position_name' => 'Position Name',
            'position_id' => 'Position ID',
            'date_created' => 'Date Created',
            'date_updated' => 'Date Updated',
        ];
    }

}
