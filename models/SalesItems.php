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
 * @property float|null $total_item_price
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
            [['total_item_price'], 'default', 'value' => null],
            [['sales_id', 'inventory_id', 'qty_sold', 'price_per_unit'], 'required'],
            [['sales_id', 'inventory_id', 'qty_sold'], 'integer'],
            [['price_per_unit', 'total_item_price'], 'number'],
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
            'total_item_price' => 'Total Item Price',
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
