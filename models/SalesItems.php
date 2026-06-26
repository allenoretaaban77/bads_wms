<?php

namespace app\models;

use Yii;

/**
 * This is the model class for table "sales_items".
 *
 * @property int $id
 * @property int $sales_id
 * @property int $inventory_id
 * @property int $qty_sold
 * @property float $price_per_unit
 * @property float $cost_per_unit
 * @property float|null $total
 *
 * @property Inventory $inventory
 * @property Sales $sales
 */
class SalesItems extends \yii\db\ActiveRecord
{


    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'sales_items';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['sales_id', 'inventory_id', 'qty_sold', 'price_per_unit', 'cost_per_unit'], 'required'],
            [['total'], 'default', 'value' => null],
            [['sales_id', 'inventory_id'], 'integer'],
            [['price_per_unit', 'total', 'cost_per_unit', 'qty_sold'], 'number'],
            [['inventory_id'], 'exist', 'skipOnError' => true, 'targetClass' => Inventory::class, 'targetAttribute' => ['inventory_id' => 'id']],
            [['sales_id'], 'exist', 'skipOnError' => true, 'targetClass' => Sales::class, 'targetAttribute' => ['sales_id' => 'id']],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'sales_id' => 'Sales ID',
            'inventory_id' => 'Inventory ID',
            'qty_sold' => 'Qty Sold',
            'price_per_unit' => 'Price Per Unit',
            'cost_per_unit' => 'Cost Per Unit',
            'total' => 'Total',
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
     * Gets query for [[Sales]].
     *
     * @return \yii\db\ActiveQuery
     */
    public function getSales()
    {
        return $this->hasOne(Sales::class, ['id' => 'sales_id']);
    }

}
