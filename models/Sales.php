<?php

namespace app\models;

use Yii;

/**
 * This is the model class for table "sales".
 *
 * @property int $id
 * @property string|null $customer_name
 * @property string|null $invoice_no
 * @property string $date_sold
 * @property string|null $payment_method
 * @property string $remarks
 * @property string $date_created
 * @property string $date_updated
 * @property int|null $added_by
 * @property int|null $updated_by
 * @property string|null $hash
 * @property string|null $record_status
 *
 * @property SalesItems[] $salesItems
 */
class Sales extends \yii\db\ActiveRecord
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
        return 'sales';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['customer_name', 'invoice_no', 'payment_method', 'added_by', 'updated_by', 'hash'], 'default', 'value' => null],
            [['record_status'], 'default', 'value' => 'Active'],
            [['date_sold', 'date_created', 'date_updated'], 'safe'],
            [['remarks'], 'required'],
            [['remarks', 'record_status'], 'string'],
            [['added_by', 'updated_by'], 'integer'],
            [['customer_name', 'hash'], 'string', 'max' => 255],
            [['invoice_no'], 'string', 'max' => 100],
            [['payment_method'], 'string', 'max' => 50],
            ['record_status', 'in', 'range' => array_keys(self::optsRecordStatus())],
            [['invoice_no'], 'unique'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'customer_name' => 'Customer Name',
            'invoice_no' => 'Invoice No',
            'date_sold' => 'Date Sold',
            'payment_method' => 'Payment Method',
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
     * Gets query for [[SalesItems]].
     *
     * @return \yii\db\ActiveQuery
     */
    public function getSalesItems()
    {
        return $this->hasMany(SalesItems::class, ['sales_id' => 'id']);
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
