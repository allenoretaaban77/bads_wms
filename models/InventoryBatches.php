<?php

namespace app\models;

use Yii;

/**
 * This is the model class for table "inventory_batches".
 *
 * @property int $id
 * @property int $inventory_id
 * @property float $cost_per_unit
 * @property int $initial_qty
 * @property int $current_qty
 * @property string $date_received
 *
 * @property Inventory $inventory
 * @property SalesItems[] $salesItems
 */
class InventoryBatches extends \yii\db\ActiveRecord
{


    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'inventory_batches';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['inventory_id', 'cost_per_unit', 'initial_qty', 'current_qty'], 'required'],
            [['inventory_id', 'initial_qty', 'current_qty'], 'integer'],
            [['cost_per_unit'], 'number'],
            [['date_received'], 'safe'],
            [['inventory_id'], 'exist', 'skipOnError' => true, 'targetClass' => Inventory::class, 'targetAttribute' => ['inventory_id' => 'id']],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'inventory_id' => 'Inventory ID',
            'cost_per_unit' => 'Cost Per Unit',
            'initial_qty' => 'Initial Qty',
            'current_qty' => 'Current Qty',
            'date_received' => 'Date Received',
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
     * Gets query for [[SalesItems]].
     *
     * @return \yii\db\ActiveQuery
     */
    public function getSalesItems()
    {
        return $this->hasMany(SalesItems::class, ['batch_id' => 'id']);
    }

}
