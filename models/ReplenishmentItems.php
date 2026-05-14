<?php

namespace app\models;

use Yii;

/**
 * This is the model class for table "replenishment_items".
 *
 * @property int $id
 * @property int $transaction_id
 * @property int $inventory_id
 * @property int $qty_added
 * @property float $cost_per_unit
 *
 * @property Inventory $inventory
 */
class ReplenishmentItems extends \yii\db\ActiveRecord
{


    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'replenishment_items';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['transaction_id', 'inventory_id', 'qty_added', 'cost_per_unit'], 'required'],
            [['transaction_id', 'inventory_id', 'qty_added'], 'integer'],
            [['cost_per_unit'], 'number'],
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
            'transaction_id' => 'Transaction ID',
            'inventory_id' => 'Inventory ID',
            'qty_added' => 'Qty Added',
            'cost_per_unit' => 'Cost Per Unit',
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

}
