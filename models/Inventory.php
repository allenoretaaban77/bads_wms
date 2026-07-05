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
 * @property int $reorder_level
 * @property float|null $current_qty_x
 * @property string $type
 * @property string $rack
 * @property string $shelf
 * @property string $box
 * @property string $status_x
 * @property string $remarks
 * @property float|null $total_inventory_cost_x
 * @property float|null $total_inventory_value_x
 * @property float|null $total_sold
 * @property string $date_created
 * @property string $date_updated
 * @property int|null $added_by
 * @property int|null $updated_by
 * @property string|null $hash
 * @property string|null $record_status
 * @property string|null $tracking_method
 * @property int|null $monitored
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
            [['total_inventory_cost_x', 'total_inventory_value_x', 'added_by', 'updated_by', 'hash'], 'default', 'value' => null],
            [['total_sold'], 'default', 'value' => 0],
            [['record_status'], 'default', 'value' => 'active'],
            //[['product_name', 'sku', 'cost_per_unit', 'price_per_unit', 'reorder_level', 'current_qty_x', 'type', 'rack', 'shelf', 'box', 'status_x', 'remarks'], 'required'],
            [['product_name', 'type', 'cost_per_unit', 'price_per_unit', 'reorder_level', 'tracking_method', 'monitored'], 'required'],
            [['added_by', 'updated_by', 'monitored'], 'integer'],
            [['date_created', 'date_updated'], 'safe'],
            [['record_status', 'remarks', 'tracking_method'], 'string'],
            [['product_name', 'hash'], 'string', 'max' => 255],
            [['sku'], 'string', 'max' => 100],
            [['type', 'rack', 'shelf', 'box', 'status_x'], 'string', 'max' => 50],
            // ['record_status', 'in', 'range' => array_keys(self::optsRecordStatus())],
            [['sku'], 'unique', 'targetClass' => '\app\models\Inventory', 'message' => 'SKU already exists'],
            [['cost_per_unit', 'price_per_unit', 'total_sold', 'current_qty_x'], 'number', 'min' => 0],
            [['reorder_level'], 'integer', 'min' => 0],
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
            'sku' => 'SKU',
            'cost_per_unit' => 'Cost Per Unit',
            'price_per_unit' => 'Price Per Unit',
            'reorder_level' => 'Reorder Level',
            'current_qty_x' => 'Current Quantity',
            'type' => 'Type',
            'rack' => 'Rack',
            'shelf' => 'Shelf',
            'box' => 'Box',
            'status_x' => 'Status',
            'remarks' => 'Remarks',
            'total_inventory_cost_x' => 'Total Inventory Cost',
            'total_inventory_value_x' => 'Total Inventory Value',
            'total_sold' => 'Total Sold',
            'date_created' => 'Date Created',
            'date_updated' => 'Date Updated',
            'added_by' => 'Added By',
            'updated_by' => 'Updated By',
            'hash' => 'Hash',
            'record_status' => 'Record Status',
            'tracking_method' => 'Tracking Method',
            'monitored' => 'Monitored',
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

    // public function fields()
    // {
    //     $fields = parent::fields();

    //     $fields['product_name_searched'] = function ($model) {
    //         return $model->product_name == '';
    //     };

    //     return $fields;
    // }
}
