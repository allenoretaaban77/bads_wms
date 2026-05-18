<?php

namespace app\models;

use Yii;

/**
 * This is the model class for table "replenishment".
 *
 * @property int $id
 * @property string|null $supplier
 * @property string|null $reference_no
 * @property string $date_received
 * @property string $remarks
 * @property string $date_created
 * @property string $date_updated
 * @property int $added_by
 * @property int $updated_by
 * @property string|null $hash
 * @property string|null $record_status
 */
class Replenishment extends \yii\db\ActiveRecord
{

    /**
     * ENUM field values
     */
    const RECORD_STATUS_ACTIVE = 'Active';
    const RECORD_STATUS_INACTIVE = 'Inactive';

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'replenishment';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['supplier', 'reference_no', 'added_by', 'updated_by', 'hash'], 'default', 'value' => null],
            [['record_status'], 'default', 'value' => 'Active'],
            [['date_received', 'date_created', 'date_updated'], 'safe'],
            [['record_status', 'remarks'], 'string'],
            [['supplier', 'hash'], 'string', 'max' => 255],
            [['reference_no'], 'string', 'max' => 100],
            [['added_by', 'updated_by'], 'integer'],
            ['record_status', 'in', 'range' => array_keys(self::optsRecordStatus())],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'supplier' => 'Supplier',
            'reference_no' => 'Reference No',
            'date_received' => 'Date Received',
            'remarks' => 'Remarks',
            'date_created' => 'Date Created',
            'date_updated' => 'Date Updated',
            'added_by' => 'Added By',
            'updated_by' => 'Updated By',
            'hash' => 'Hash',
            'record_status' => 'Record Status',
        ];
    }


    /**
     * column record_status ENUM value labels
     * @return string[]
     */
    public static function optsRecordStatus()
    {
        return [
            self::RECORD_STATUS_ACTIVE => 'Active',
            self::RECORD_STATUS_INACTIVE => 'Inactive',
        ];
    }

    /**
     * @return string
     */
    public function displayRecordStatus()
    {
        return self::optsRecordStatus()[$this->record_status];
    }

    /**
     * @return bool
     */
    public function isRecordStatusActive()
    {
        return $this->record_status === self::RECORD_STATUS_ACTIVE;
    }

    public function setRecordStatusToActive()
    {
        $this->record_status = self::RECORD_STATUS_ACTIVE;
    }

    /**
     * @return bool
     */
    public function isRecordStatusInactive()
    {
        return $this->record_status === self::RECORD_STATUS_INACTIVE;
    }

    public function setRecordStatusToInactive()
    {
        $this->record_status = self::RECORD_STATUS_INACTIVE;
    }
}
