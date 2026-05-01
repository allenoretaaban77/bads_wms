<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * Employee model
 *
 * @property integer $id
 * @property integer $employee_id
 * @property string $employee_number
 * @property string $firstname
 * @property string $lastname
 * @property string $surname
 * @property string $username
 * @property string $hash
 * @property string $status
 * @property integer $status_id
 * @property string $position_name
 * @property integer $position_id
 * @property string $date_created
 * @property string $date_updated
 */
class Employee extends ActiveRecord
{
    public $password;

    const STATUS_INACTIVE = 0;
    const STATUS_ACTIVE = 1;

    public static function tableName()
    {
        return '{{%employees}}';
    }

    public function rules()
    {
        return [
            [['employee_number', 'firstname', 'lastname', 'username', 'password'], 'required'],
            [['employee_id', 'status_id', 'position_id'], 'integer'],
            [['date_created', 'date_updated'], 'safe'],
            [['employee_number', 'firstname', 'lastname', 'surname', 'username', 'position_name'], 'string', 'max' => 255],
            [['status'], 'string', 'max' => 50],
            [['hash'], 'string'],
            [['employee_number'], 'unique'],
            [['username'], 'unique'],
            [['password'], 'string', 'min' => 6],
        ];
    }

    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'employee_id' => 'Employee ID',
            'employee_number' => 'Employee Number',
            'firstname' => 'First Name',
            'lastname' => 'Last Name',
            'surname' => 'Surname',
            'username' => 'Username',
            'password' => 'Password',
            'hash' => 'Hash',
            'status' => 'Status',
            'status_id' => 'Status ID',
            'position_name' => 'Position Name',
            'position_id' => 'Position ID',
            'date_created' => 'Date Created',
            'date_updated' => 'Date Updated',
        ];
    }

    public function beforeSave($insert)
    {
        if (!parent::beforeSave($insert)) {
            return false;
        }

        if (!empty($this->password)) {
            $this->hash = Yii::$app->security->generatePasswordHash($this->password);
        }

        $timestamp = date('Y-m-d H:i:s');
        if ($insert && empty($this->date_created)) {
            $this->date_created = $timestamp;
        }
        $this->date_updated = $timestamp;

        return true;
    }

    public static function findByUsername($username)
    {
        return static::findOne(['username' => $username]);
    }

    public function validatePassword($password)
    {
        return Yii::$app->security->validatePassword($password, $this->hash);
    }

    public function fields()
    {
        $fields = parent::fields();
        unset($fields['hash'], $fields['password']);
        return $fields;
    }
}
