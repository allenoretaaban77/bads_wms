<?php

namespace app\models;

use Yii;

/**
 * This is the model class for table "returns".
 *
 * @property int $id
 * @property string $return_no
 * @property string|null $customer_name
 * @property int $invoice_id
 * @property string|null $invoice_no
 * @property float $amount
 * @property string $remarks
 * @property string|null $status
 * @property string $date_received
 * @property string $date_created
 * @property string $date_updated
 * @property int|null $added_by
 * @property int|null $updated_by
 * @property string|null $record_status
 *
 * @property ReturnsItems[] $returnsItems
 */
class Returns extends \yii\db\ActiveRecord
{

    /**
     * ENUM field values
     */
    const STATUS_DRAFT = 'draft';
    const STATUS_APPROVED = 'approved';
    const STATUS_CANCELLED = 'cancelled';
    const RECORD_STATUS_ACTIVE = 'active';
    const RECORD_STATUS_INACTIVE = 'inactive';

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'returns';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['customer_name', 'invoice_no', 'added_by', 'updated_by'], 'default', 'value' => null],
            [['amount'], 'default', 'value' => 0],
            [['status'], 'default', 'value' => 'draft'],
            [['record_status'], 'default', 'value' => 'active'],
            [['return_no', 'invoice_id'], 'required'],
            [['invoice_id', 'added_by', 'updated_by'], 'integer'],
            [['amount'], 'number'],
            [['remarks', 'status', 'record_status'], 'string'],
            [['date_received', 'date_created', 'date_updated'], 'safe'],
            [['return_no', 'invoice_no'], 'string', 'max' => 100],
            [['customer_name'], 'string', 'max' => 255],
            ['status', 'in', 'range' => array_keys(self::optsStatus())],
            ['record_status', 'in', 'range' => array_keys(self::optsRecordStatus())],
            [['return_no'], 'unique'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'return_no' => 'Return No',
            'customer_name' => 'Customer Name',
            'invoice_id' => 'Invoice ID',
            'invoice_no' => 'Invoice No',
            'amount' => 'Amount',
            'remarks' => 'Remarks',
            'status' => 'Status',
            'date_received' => 'Date Received',
            'date_created' => 'Date Created',
            'date_updated' => 'Date Updated',
            'added_by' => 'Added By',
            'updated_by' => 'Updated By',
            'record_status' => 'Record Status',
        ];
    }

    /**
     * Gets query for [[ReturnsItems]].
     *
     * @return \yii\db\ActiveQuery
     */
    public function getReturnsItems()
    {
        return $this->hasMany(ReturnsItems::class, ['return_id' => 'id']);
    }


    /**
     * column status ENUM value labels
     * @return string[]
     */
    public static function optsStatus()
    {
        return [
            self::STATUS_DRAFT => 'draft',
            self::STATUS_APPROVED => 'approved',
            self::STATUS_CANCELLED => 'cancelled',
        ];
    }

    /**
     * column record_status ENUM value labels
     * @return string[]
     */
    public static function optsRecordStatus()
    {
        return [
            self::RECORD_STATUS_ACTIVE => 'active',
            self::RECORD_STATUS_INACTIVE => 'inactive',
        ];
    }

    /**
     * @return string
     */
    public function displayStatus()
    {
        return self::optsStatus()[$this->status];
    }

    /**
     * @return bool
     */
    public function isStatusDraft()
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function setStatusToDraft()
    {
        $this->status = self::STATUS_DRAFT;
    }

    /**
     * @return bool
     */
    public function isStatusApproved()
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function setStatusToApproved()
    {
        $this->status = self::STATUS_APPROVED;
    }

    /**
     * @return bool
     */
    public function isStatusCancelled()
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function setStatusToCancelled()
    {
        $this->status = self::STATUS_CANCELLED;
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
