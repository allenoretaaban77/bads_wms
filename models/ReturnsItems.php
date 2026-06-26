<?php

namespace app\models;

use Yii;

/**
 * This is the model class for table "returns_items".
 *
 * @property int $id
 * @property int $return_id
 * @property int $inventory_id
 * @property int|null $batch_id
 * @property int $sales_item_id
 * @property int $qty_returned
 * @property float $unit_price
 * @property float|null $total
 * @property string|null $reason
 * @property string|null $status
 * @property string|null $record_status
 *
 * @property Inventory $inventory
 * @property Returns $return
 */
class ReturnsItems extends \yii\db\ActiveRecord
{

    /**
     * ENUM field values
     */
    const RECORD_STATUS_ACTIVE = 'active';
    const RECORD_STATUS_INACTIVE = 'inactive';

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'returns_items';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['batch_id', 'total', 'reason'], 'default', 'value' => null],
            [['status'], 'default', 'value' => 'draft'],
            [['record_status'], 'default', 'value' => 'active'],
            [['return_id', 'inventory_id', 'sales_item_id', 'qty_returned', 'unit_price'], 'required'],
            [['return_id', 'inventory_id', 'batch_id', 'sales_item_id'], 'integer'],
            [['unit_price', 'total', 'qty_returned'], 'number'],
            [['record_status'], 'string'],
            [['reason'], 'string', 'max' => 255],
            [['status'], 'string', 'max' => 50],
            ['record_status', 'in', 'range' => array_keys(self::optsRecordStatus())],
            [['inventory_id'], 'exist', 'skipOnError' => true, 'targetClass' => Inventory::class, 'targetAttribute' => ['inventory_id' => 'id']],
            [['return_id'], 'exist', 'skipOnError' => true, 'targetClass' => Returns::class, 'targetAttribute' => ['return_id' => 'id']],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'return_id' => 'Return ID',
            'inventory_id' => 'Inventory ID',
            'batch_id' => 'Batch ID',
            'sales_item_id' => 'Sales Item ID',
            'qty_returned' => 'Qty Returned',
            'unit_price' => 'Unit Price',
            'total' => 'Total',
            'reason' => 'Reason',
            'status' => 'Status',
            'record_status' => 'Record Status',
        ];
    }

    /**
     * Gets query for [[Inventory]].
     *
     * @return \yii\db\ActiveQuery
     */
    public function getInventory()
    {
        return $this->hasOne(Inventory::class, ['id' => 'inventory_id']);
    }

    /**
     * Gets query for [[Return]].
     *
     * @return \yii\db\ActiveQuery
     */
    public function getReturn()
    {
        return $this->hasOne(Returns::class, ['id' => 'return_id']);
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
