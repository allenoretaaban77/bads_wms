<?php

namespace app\models;

use Yii;

/**
 * This is the model class for table "inventory".
 *
 * @property int $id
 * @property string $product_name
 * @property string $sku
 * @property float $cost_per_unit
 * @property float $price_per_unit
 * @property int $initial_qty
 * @property int $reorder_level
 * @property int $current_qty
 * @property string $type
 * @property string $rack
 * @property string $shelf
 * @property string $box
 * @property string $status
 * @property string $remarks
 * @property float|null $total_inventory_cost
 * @property float|null $total_inventory_value
 * @property int|null $total_sold
 * @property string $date_created
 * @property string $date_updated
 * @property int|null $added_by
 * @property int|null $updated_by
 * @property string|null $hash
 * @property string|null $record_status
 */
class Inventory extends \yii\db\ActiveRecord
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
        return 'inventory';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['total_inventory_cost', 'total_inventory_value', 'added_by', 'updated_by', 'hash'], 'default', 'value' => null],
            [['total_sold'], 'default', 'value' => 0],
            [['record_status'], 'default', 'value' => 'Active'],
            //[['product_name', 'sku', 'cost_per_unit', 'price_per_unit', 'initial_qty', 'reorder_level', 'current_qty', 'type', 'rack', 'shelf', 'box', 'status', 'remarks'], 'required'],
            [['product_name', 'sku', 'type', 'cost_per_unit', 'price_per_unit', 'initial_qty', 'reorder_level', 'current_qty'], 'required'],
            [['cost_per_unit', 'price_per_unit', 'total_inventory_cost', 'total_inventory_value'], 'number'],
            [['initial_qty', 'reorder_level', 'current_qty', 'total_sold', 'added_by', 'updated_by'], 'integer'],
            [['date_created', 'date_updated'], 'safe'],
            [['record_status', 'remarks'], 'string'],
            [['product_name', 'hash'], 'string', 'max' => 255],
            [['sku'], 'string', 'max' => 100],
            [['type', 'rack', 'shelf', 'box', 'status'], 'string', 'max' => 50],
            ['record_status', 'in', 'range' => array_keys(self::optsRecordStatus())],
            [['sku'], 'unique', 'targetClass' => '\app\models\Inventory', 'message' => 'SKU already exists'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'product_name' => 'Product Name',
            'sku' => 'Sku',
            'cost_per_unit' => 'Cost Per Unit',
            'price_per_unit' => 'Price Per Unit',
            'initial_qty' => 'Initial Qty',
            'reorder_level' => 'Reorder Level',
            'current_qty' => 'Current Qty',
            'type' => 'Type',
            'rack' => 'Rack',
            'shelf' => 'Shelf',
            'box' => 'Box',
            'status' => 'Status',
            'remarks' => 'Remarks',
            'total_inventory_cost' => 'Total Inventory Cost',
            'total_inventory_value' => 'Total Inventory Value',
            'total_sold' => 'Total Sold',
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
