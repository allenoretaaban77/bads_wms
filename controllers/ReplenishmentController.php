<?php

namespace app\controllers;

use Yii;
use app\models\Replenishment;
use app\models\ReplenishmentItems;
use app\models\Inventory;
use app\models\InventoryBatches;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\filters\VerbFilter;
use yii\db\Expression;
use app\models\Employee; 

class ReplenishmentController extends Controller
{
    public $enableCsrfValidation = false;

    /**
     * {@inheritdoc}
     */
    public function behaviors()
    {
        $behaviors = parent::behaviors();
        
        $behaviors['contentNegotiator'] = [
            'class' => \yii\filters\ContentNegotiator::class,
            'formats' => [
                'application/json' => \yii\web\Response::FORMAT_JSON,
            ],
        ];

        return $behaviors;
    }  

    public function beforeAction($action)
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        if ($action->id === 'register') {
            return true; // skip token check for register
        }

        $authHeader = Yii::$app->request->getHeaders()->get('Authorization');
        if (!$authHeader || !preg_match('/^Bearer\s+(.*?)$/i', $authHeader, $matches)) {
            Yii::$app->response->statusCode = 401;
            Yii::$app->response->data = ['error' => 'Authorization header missing or invalid'];
            return false; // stop execution
        }

        $accessToken = $matches[1];
        $employee = Employee::findByAccessToken($accessToken);

        if (!$employee) {
            Yii::$app->response->statusCode = 401;
            Yii::$app->response->data = ['error' => 'Invalid access token'];
            return false; // stop execution
        }

        return true; // allow action to run
    }

    public function actionList()
    {
        if (Yii::$app->request->method !== 'GET') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $request = Yii::$app->request;
        $query = Replenishment::find()->select([
            'replenishment.*',
            // 'amount' => (new \yii\db\Query())
            //     ->select('SUM(qty_added * cost_per_unit)')
            //     ->from('replenishment_items')
            //     ->where('transaction_id = replenishment.id')
        ]);
        // ->where(['replenishment.record_status' => 'active']);

        $search = $request->get('search');
        if (!empty($search)) {
            // Normal columns go in WHERE
            $query->andFilterWhere([
                'or',
                ['like', 'supplier', $search],
                ['like', 'reference_no', $search],
                ['like', 'date_received', $search],
                ['like', 'amount', $search],
                ['like', 'remarks', $search],
            ]);
        }

        // 🎯 Filters
        // $filters = [
        //     'type' => $request->get('supplier'),
        // ];
        // foreach ($filters as $field => $value) {
        //     if (!empty($value)) {
        //         $query->andWhere([$field => $value]);
        //     }
        // }

        // 📄 Pagination
        $page = (int)$request->get('page', 1);
        $pageSize = (int)$request->get('pageSize', 10);
        $offset = ($page - 1) * $pageSize;

        // 📊 Sorting (default id ASC)
        $sortField = $request->get('sort', 'id');
        $sortOrder = strtolower($request->get('order', 'asc')) === 'desc' ? SORT_DESC : SORT_ASC;

        $allowedSortFields = [
            'id', 'supplier', 'reference_no', 'date_received', 'amount',
        ];
        if (in_array($sortField, $allowedSortFields)) {
            $query->orderBy([$sortField => $sortOrder]);
        } else {
            $query->orderBy(['id' => SORT_DESC]);
        }

        // Execute query
        $totalCount = $query->count();
        // $items = $query->offset($offset)->limit($pageSize)->all();
        $items = $query->offset($offset)->limit($pageSize)->asArray()->all();

        return [
            'success' => true,
            'page' => $page,
            'pageSize' => $pageSize,
            'totalCount' => $totalCount,
            'totalPages' => ceil($totalCount / $pageSize),
            'sortField' => $sortField,
            'sortOrder' => $sortOrder === SORT_ASC ? 'asc' : 'desc',
            'count' => count($items),
            'data' => $items,
        ];
    }

    public function actionViewavailablestocks($id) 
    {
        if (Yii::$app->request->method !== 'GET') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $items = ReplenishmentItems::find()
            ->select([
                'replenishment_items.*',
                'product_name' => 'inventory.product_name',
                'current_qty' => 'inventory.current_qty',
                'reorder_level' => 'inventory.reorder_level',
                'sku' => 'inventory.sku',
            ])
            ->leftJoin('inventory', 'inventory.id = replenishment_items.inventory_id')
            ->where(['inventory_id' => $id])
            ->andWhere(['replenishment_items.status' => 'approved'])
            ->asArray()
            ->all();

        $items['items'] = $items;

        return [
            'success' => true,
            'data' => $items
        ];

        // $replenishmentitems = ReplenishmentItems::find()
        //     ->where(['id' => $id])
        //     ->asArray()
        //     ->one();
        // ->where(['replenishment.status' => 'approved']);

        // // $request = Yii::$app->request;
        // $query = ReplenishmentItems::find()->select([
        //     'replenishment.*',
        //     // 'amount' => (new \yii\db\Query())
        //     //     ->select('SUM(qty_added * cost_per_unit)')
        //     //     ->from('replenishment_items')
        //     //     ->where('transaction_id = replenishment.id')
        // ]);
        // ->where(['replenishment.status' => 'approved']);
    }

    public function actionStockintrnxs($id, $cost) 
    {
        if (Yii::$app->request->method !== 'GET') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $query = ReplenishmentItems::find()
            ->select([
                // 'replenishment_items.*',
                'id' => 'replenishment_items.id',
                'inventory_id' => 'replenishment_items.inventory_id',
                'sku' => 'inventory.sku',
                'product_name' => 'inventory.product_name',
                // 'current_qty' => 'inventory.current_qty',
                'quantity' => 'replenishment_items.qty_added',
                'cost' => 'replenishment_items.cost_per_unit',
                'total' => 'replenishment_items.total',
                'date_received' => 'replenishment.date_received',
                'reference_no' => 'replenishment.reference_no',
                'reorder_level' => 'inventory.reorder_level',
            ])
            ->leftJoin('replenishment', 'replenishment.id = replenishment_items.transaction_id')
            ->leftJoin('inventory', 'inventory.id = replenishment_items.inventory_id')
            ->where(['inventory_id' => $id])
            // ->andWhere(['replenishment_items.cost_per_unit' => $cost])
            ->andWhere(['replenishment_items.status' => 'approved']);
        $query->orderBy(['replenishment.date_received' => SORT_DESC]);
        // $query->limit(1);
        $totalCount = $query->count();
        $items = $query->asArray()->all();

        if (!$items) {
            Yii::$app->response->statusCode = 404;
            return ['success' => false, 'error' => 'Replenishment data not found'];
        } 

        $info = $items[0];
        $data['sku'] = $info['sku'];
        $data['product_name'] = $info['product_name'];
        $data['items'] = $items;

        return [
            'success' => true,
            'count' => $totalCount,
            'data' => $data,
        ];
    }

    public function actionView($id)
    {
        if (Yii::$app->request->method !== 'GET') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $replenishment = Replenishment::find()
            ->where(['id' => $id])
            ->asArray()
            ->one();

        if (!$replenishment) {
            Yii::$app->response->statusCode = 404;
            return ['success' => false, 'error' => 'Replenishment transaction not found'];
        }

        // 🌟 Subquery to calculate total live stock across all batches for the product
        $batchQtySubquery = (new \yii\db\Query())
            ->select('SUM(current_qty)')
            ->from('inventory_batches')
            ->where('inventory_id = replenishment_items.inventory_id');

        $items = ReplenishmentItems::find()
            ->select([
                'replenishment_items.*',
                'product_name'  => 'inventory.product_name',
                'sku'           => 'inventory.sku',
                'reorder_level' => 'inventory.reorder_level',
                // 🔥 This replaces the hardcoded 0 with the live total count from your batches
                'current_qty'   => $batchQtySubquery, 
            ])
            ->leftJoin('inventory', 'inventory.id = replenishment_items.inventory_id')
            ->where(['transaction_id' => $id])
            ->asArray()
            ->all();

        // Typecast current_qty elements to integers since SQL SUM() returns strings/floats
        foreach ($items as &$item) {
            $item['current_qty'] = isset($item['current_qty']) ? (int)$item['current_qty'] : 0;
        }
        unset($item); // Break reference pointer link safety wrapper

        $replenishment['items'] = $items;

        return [
            'success' => true,
            'data' => $replenishment
        ];
    }

    public function actionCreateOld()
    {
        if (Yii::$app->request->method !== 'POST') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $data = new Replenishment();
        $data->load(Yii::$app->request->post(), '');

        if (!$data->validate()) {
            Yii::$app->response->statusCode = 422;
            return ['error' => 'Validation failed', 'errors' => $data->errors];
        }

        $data = Yii::$app->request->post();
        if (!isset($data['items']) || !is_array($data['items']) || count($data['items']) === 0) {
            Yii::$app->response->statusCode = 422;
            return ['error' => 'Validation failed', 'errors' => ['items' => ['Add at least one item.']]];
        }

        // $request = Yii::$app->request;
        // $data = $request->post();
        $transaction = Yii::$app->db->beginTransaction();
        try {
            $replenishment = new Replenishment();
            $replenishment->supplier = $data['supplier'] ?? null;
            $replenishment->reference_no = $data['reference_no'] ?? null;
            $replenishment->date_received = $data['date_received'] ?? date('Y-m-d');
            $replenishment->amount = (float)($data['amount'] ?? 0);
            $replenishment->remarks = $data['remarks'] ?? null;
            $replenishment->date_created = date('Y-m-d H:i:s');
            $replenishment->added_by = $data['added_by'] ?? null;
            $replenishment->status = $data['status'] ?? null;

            if (!$replenishment->save()) {
                $transaction->rollBack();
                return ['success' => false, 'errors' => $replenishment->getErrors()];
            }
            Yii::debug($replenishment->getErrors(), __METHOD__);

            $itemsData = $data['items'];
            foreach ($itemsData as $itemData) {
                // Try to find the item in inventory by name
                $inventory = Inventory::findOne(['id' => $itemData['inventory_id']]);
                
                if (!$inventory) {
                    $transaction->rollBack();
                    return ['success' => false, 'error' => "Item not found in inventory"];
                }

                if ($itemData['quantity'] == "" || $itemData['quantity'] < 1) {
                    $transaction->rollBack();
                    Yii::$app->response->statusCode = 422;
                    return ['error' => 'Validation failed', 'errors' => ['quantity_'.$inventory->id => ['Invalid quantity.']]];
                }

                if ($itemData['cost'] == "" || $itemData['cost'] < 1) {
                    $transaction->rollBack();
                    Yii::$app->response->statusCode = 422;
                    return ['error' => 'Validation failed', 'errors' => ['cost_'.$inventory->id => ['Invalid cost.']]];
                }

                $replenishmentItem = new ReplenishmentItems();
                $replenishmentItem->transaction_id = $replenishment->id;
                $replenishmentItem->inventory_id = $inventory->id;
                $replenishmentItem->qty_added = (int)($itemData['quantity'] ?? 0);
                $replenishmentItem->cost_per_unit = (float)($itemData['cost'] ?? 0);
                $replenishmentItem->status = 'draft';

                if (!$replenishmentItem->save()) {
                    $transaction->rollBack();
                    return ['success' => false, 'errors' => $replenishmentItem->getErrors()];
                }

                if($data['status'] != 'draft') {
                    // Update inventory current_qty
                    $inventory->current_qty = new \yii\db\Expression('current_qty + :qty', [':qty' => $replenishmentItem->qty_added]);

                    // Update inventory cost_per_unit
                    if ($replenishmentItem->cost_per_unit > 0) {
                        $inventory->cost_per_unit = $replenishmentItem->cost_per_unit;
                    }
                }

                if (!$inventory->save(false)) { // Save without validation to be faster
                    $transaction->rollBack();
                    return ['success' => false, 'error' => "Failed to update inventory for '{$itemData['item_name']}'"];
                }
            }

            // ✅ Insert into audit log after successful update
            Yii::$app->db->createCommand()->insert('audit_log', [
                'entity' => 'replenishment',
                'entity_id' => $replenishment->id,
                'action' => 'create',
                'new_data' => json_encode([
                    'replenishment' => $replenishment->attributes,
                    'items' => $itemsData 
                ]),
                'updated_by' => $replenishment->added_by,
                'updated_at' => date('Y-m-d H:i:s'),
            ])->execute();

            $transaction->commit();
            return [
                'success' => true,
                'message' => 'Replenishment transaction created successfully.',
                'id' => $replenishment->id
            ];
        } catch (\Exception $e) {
            $transaction->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function actionUpdateOld()
    {
        if (Yii::$app->request->method !== 'PUT' && Yii::$app->request->method !== 'POST') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $reference_no = Yii::$app->request->getBodyParam('reference_no');
        $replenishment = Replenishment::findOne(['reference_no' => $reference_no]);
        $oldData = $replenishment->attributes;

        $request = Yii::$app->request;
        $data = $request->post();
        $replenishment->load($data, '');

        if (!$replenishment->validate()) {
            Yii::$app->response->statusCode = 422;
            return ['error' => 'Validation failed', 'errors' => $replenishment->errors];
        }

        if (!isset($data['items']) || !is_array($data['items']) || count($data['items']) === 0) {
            Yii::$app->response->statusCode = 422;
            return ['error' => 'Validation failed', 'errors' => ['items' => ['Add at least one item.']]];
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            // Save parent record
            if (!$replenishment->save()) {
                $transaction->rollBack();
                return ['success' => false, 'errors' => $replenishment->getErrors()];
            }

            $newItems = $data['items'];
            $oldItems = ReplenishmentItems::findAll(['transaction_id' => $replenishment->id]);

            // Validate for negative deductions
            // if($data['status'] != 'draft') {
            //     foreach ($newItems as $newItem) {
            //         $inventory = Inventory::findOne(['id' => $newItem['inventory_id']]);
            //         foreach ($oldItems as $oldItem) {
            //             if ($newItem['inventory_id'] == $oldItem['inventory_id']) {
            //                 if ($newItem['quantity'] < $oldItem['qty_added']) {
            //                     $deduction = floatval($oldItem['qty_added'])  - floatval($newItem['quantity']);
            //                     if ($inventory->current_qty < $deduction) {
            //                         $transaction->rollBack();
            //                         Yii::$app->response->statusCode = 422;
            //                         return ['error' => 'Validation failed', 'errors' => ['quantity_'.$inventory->id => ['Cannot deduct. Items were already sold. Please void the sales or adjust inventory.']]];
            //                     }
            //                 }
            //             }

            //         }
            //     }
            // }

            // Delete old items before re‑inserting
            $oldItemsToDelete = [];
            if($data['status'] != 'draft') {
                foreach ($oldItems as $oldItem) {
                    $oldItemsToDelete[] = $oldItem->attributes;

                    $inventory = Inventory::findOne($oldItem->inventory_id);
                    if ($inventory) {
                        $inventory->current_qty = new \yii\db\Expression('current_qty - :qty', [':qty' => $oldItem->qty_added]);
                        $inventory->save(false);
                    }
                }
            }
            ReplenishmentItems::deleteAll(['transaction_id' => $replenishment->id]);

            foreach ($newItems as $itemData) {
                $inventory = Inventory::findOne(['id' => $itemData['inventory_id']]);
                if (!$inventory) {
                    $transaction->rollBack();
                    return ['success' => false, 'error' => "Item not found in inventory"];
                }

                if (empty($itemData['quantity']) || $itemData['quantity'] < 1) {
                    $transaction->rollBack();
                    Yii::$app->response->statusCode = 422;
                    return ['error' => 'Validation failed', 'errors' => ['quantity_'.$inventory->id => ['Invalid quantity.']]];
                }

                if (empty($itemData['cost']) || $itemData['cost'] < 1) {
                    $transaction->rollBack();
                    Yii::$app->response->statusCode = 422;
                    return ['error' => 'Validation failed', 'errors' => ['cost_'.$inventory->id => ['Invalid cost.']]];
                }

                $replenishmentItem = new ReplenishmentItems();
                $replenishmentItem->transaction_id = $replenishment->id;
                $replenishmentItem->inventory_id = $inventory->id;
                $replenishmentItem->qty_added = (int)$itemData['quantity'];
                $replenishmentItem->cost_per_unit = (float)$itemData['cost'];

                if (!$replenishmentItem->save()) {
                    $transaction->rollBack();
                    return ['success' => false, 'errors' => $replenishmentItem->getErrors()];
                }

                if($data['status'] != 'draft') {
                    // Update inventory current_qty
                    $inventory->current_qty = new \yii\db\Expression('current_qty + :qty', [':qty' => $replenishmentItem->qty_added]);

                    // Update inventory cost_per_unit
                    if ($replenishmentItem->cost_per_unit > 0) {
                        $inventory->cost_per_unit = $replenishmentItem->cost_per_unit;
                    }
                }

                if (!$inventory->save(false)) {
                    $transaction->rollBack();
                    return ['success' => false, 'error' => "Failed to update inventory for '{$itemData['item_name']}'"];
                }
            }

            // ✅ Insert into audit log after successful update
            Yii::$app->db->createCommand()->insert('audit_log', [
                'entity' => 'replenishment',
                'entity_id' => $replenishment->id,
                'action' => 'update',
                'old_data' => json_encode([
                    'replenishment' => $oldData,
                    'items' => $oldItemsToDelete 
                ]),
                'new_data' => json_encode([
                    'replenishment' => $replenishment->attributes,
                    'items' => $newItems 
                ]),
                'updated_by' => $replenishment->updated_by,
                'updated_at' => date('Y-m-d H:i:s'),
            ])->execute();

            $transaction->commit();
            return [
                'success' => true,
                'message' => 'Replenishment transaction updated successfully.',
                'id' => $replenishment->id
            ];
        } catch (\Exception $e) {
            $transaction->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function actionApproveOld() 
    {
        if (Yii::$app->request->method !== 'PUT' && Yii::$app->request->method !== 'POST') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $request = Yii::$app->request;
        $data = $request->post();

        $replenishment = Replenishment::findOne(['reference_no' => $data['reference_no']]);
        if (!$replenishment) {
            Yii::$app->response->statusCode = 404;
            return ['success' => false, 'error' => 'Replenishment record not found'];
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            // Save parent record
            $replenishment->status = $data['status'];
            $replenishment->updated_by = $data['updated_by'];

            if (!$replenishment->save()) {
                $transaction->rollBack();
                return ['success' => false, 'errors' => $replenishment->getErrors()];
            }

            $itemDatas = ReplenishmentItems::findAll(['transaction_id' => $replenishment->id]);

            // Validate for negative deductions
            // foreach ($itemDatas as $itemData) {
            //     $inventory = Inventory::findOne(['id' => $itemData['inventory_id']]);
            //     if ((float)$inventory->current_qty < (float)$itemData['qty_added']) {
            //         $transaction->rollBack();
            //         Yii::$app->response->statusCode = 422;
            //         return ['error' => 'Cannot deduct for '.$inventory->product_name.' ('.$inventory->sku.'). Items were already sold. Please void the sales or adjust inventory.'];
            //     }
            // }
            // **** will not process this since the values are already edited via draft

            foreach ($itemDatas as $itemData) {
                $inventory = Inventory::findOne(['id' => $itemData['inventory_id']]);
                if (!$inventory) {
                    $transaction->rollBack();
                    return ['success' => false, 'error' => "Item not found in inventory"];
                }

                // approved also the items
                $replenishmentItem = ReplenishmentItems::findOne(['id' => $itemData['id']]);
                $replenishmentItem->status = 'approved';                
                if (!$replenishmentItem->save()) {
                    $transaction->rollBack();
                    return ['success' => false, 'errors' => $replenishmentItem->getErrors()];
                }

                // Update inventory current_qty
                $inventory->current_qty = new \yii\db\Expression('current_qty + :qty', [':qty' => $itemData['qty_added']]);

                // Update inventory cost_per_unit
                if ($itemData['cost_per_unit'] > 0) {
                    $inventory->cost_per_unit = $itemData['cost_per_unit'];
                }

                if (!$inventory->save(false)) {
                    $transaction->rollBack();
                    return ['success' => false, 'error' => "Failed to update inventory for '{$itemData['item_name']}'"];
                }
            }

            // ✅ Insert into audit log after successful update
            Yii::$app->db->createCommand()->insert('audit_log', [
                'entity' => 'replenishment',
                'entity_id' => $replenishment->id,
                'action' => 'approve',
                'new_data' => json_encode([
                    'replenishment' => $replenishment->attributes,
                    'items' => $itemDatas 
                ]),
                'updated_by' => $replenishment->updated_by,
                'updated_at' => date('Y-m-d H:i:s'),
            ])->execute();

            $transaction->commit();
            return [
                'success' => true,
                'message' => 'Replenishment transaction approved successfully.',
                'id' => $replenishment->id
            ];
        } catch (\Exception $e) {
            $transaction->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function actionApprove() 
    {
        if (Yii::$app->request->method !== 'PUT' && Yii::$app->request->method !== 'POST') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $request = Yii::$app->request;
        $data = $request->post();
        
        $replenishment = Replenishment::findOne(['reference_no' => $data['reference_no']]);
        if (!$replenishment) {
            Yii::$app->response->statusCode = 404;
            return ['success' => false, 'error' => 'Replenishment record not found'];
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            // 1. Save parent record status
            $replenishment->status = $data['status']; // e.g., 'approved'
            $replenishment->updated_by = $data['updated_by'];

            if (!$replenishment->save()) {
                $transaction->rollBack();
                return ['success' => false, 'errors' => $replenishment->getErrors()];
            }

            // Fetch all item lines attached to this delivery
            $itemDatas = ReplenishmentItems::findAll(['transaction_id' => $replenishment->id]);

            foreach ($itemDatas as $itemData) {
                // Verify product exists in the Master table
                $inventory = Inventory::findOne(['id' => $itemData['inventory_id']]);
                if (!$inventory) {
                    $transaction->rollBack();
                    return ['success' => false, 'error' => "Item ID {$itemData['inventory_id']} not found in product master."];
                }

                // 2. Approve the individual line item status
                $itemData->status = 'approved';        
                if (!$itemData->save()) {
                    $transaction->rollBack();
                    return ['success' => false, 'errors' => $itemData->getErrors()];
                }

                // 3. BATCH ROUTING: Look for an existing batch with the exact same cost
                // (Uses Approach 1: Merging matching costs to keep layouts clean)
                $existingBatch = InventoryBatches::findOne([
                    'inventory_id'  => $itemData['inventory_id'],
                    'cost_per_unit' => $itemData['cost_per_unit']
                ]);

                if ($existingBatch) {
                    // Batch found with same cost -> Update quantities using expressions to avoid race conditions
                    $existingBatch->initial_qty = new \yii\db\Expression('initial_qty + :qty', [':qty' => $itemData['qty_added']]);
                    $existingBatch->current_qty = new \yii\db\Expression('current_qty + :qty', [':qty' => $itemData['qty_added']]);
                    
                    if (!$existingBatch->save(false)) {
                        $transaction->rollBack();
                        return ['success' => false, 'error' => "Failed to update existing batch layer for '{$inventory->product_name}'"];
                    }
                } else {
                    // No matching cost layer -> Create a brand new batch row
                    $newBatch = new InventoryBatches();
                    $newBatch->inventory_id = $itemData['inventory_id'];
                    $newBatch->cost_per_unit = $itemData['cost_per_unit'];
                    $newBatch->initial_qty = $itemData['qty_added'];
                    $newBatch->current_qty = $itemData['qty_added'];
                    
                    if (!$newBatch->save()) {
                        $transaction->rollBack();
                        return ['success' => false, 'errors' => $newBatch->getErrors()];
                    }
                }
            }

            // 4. Insert into audit log
            Yii::$app->db->createCommand()->insert('audit_log', [
                'entity' => 'replenishment',
                'entity_id' => $replenishment->id,
                'action' => 'approve',
                'new_data' => json_encode([
                    'replenishment' => $replenishment->attributes,
                    'items' => array_map(function($item) { return $item->attributes; }, $itemDatas) 
                ]),
                'updated_by' => $replenishment->updated_by,
                'updated_at' => date('Y-m-d H:i:s'),
            ])->execute();

            $transaction->commit();
            return [
                'success' => true,
                'message' => 'Replenishment transaction approved and inventory batches updated successfully.',
                'id' => $replenishment->id
            ];

        } catch (\Exception $e) {
            $transaction->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function actionCreate()
    {
        // Ensure JSON response format
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        if (Yii::$app->request->method !== 'POST') {
            Yii::$app->response->statusCode = 405;
            return [
                'success' => false, 
                'message' => 'Method not allowed.', 
                'errors' => []
            ];
        }

        $requestData = Yii::$app->request->post();

        // Validate if items exist and is a valid array
        if (!isset($requestData['items']) || !is_array($requestData['items']) || empty($requestData['items'])) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => ['items' => ['Add at least one item.']]
            ];
        }

        // Optimization: Batch-load all inventories up front to avoid N+1 queries loop
        $inventoryIds = array_filter(array_column($requestData['items'], 'inventory_id'));
        $inventories = Inventory::find()->where(['id' => $inventoryIds])->indexBy('id')->all();

        // Server-side calculation of the total amount to prevent client tampering
        $calculatedAmount = 0.0;
        foreach ($requestData['items'] as $itemData) {
            $qty = (int)($itemData['quantity'] ?? 0);
            $cost = (float)($itemData['cost'] ?? 0);
            if ($qty > 0 && $cost > 0) {
                $calculatedAmount += ($qty * $cost);
            }
        }

        // Single instantiation handles both validation and execution safely
        $replenishment = new Replenishment();
        $replenishment->load($requestData, '');
        
        // Explicitly set metadata
        $replenishment->supplier = $requestData['supplier'] ?? null;
        $replenishment->reference_no = $requestData['reference_no'] ?? null;
        $replenishment->date_received = $requestData['date_received'] ?? date('Y-m-d');
        $replenishment->amount = $calculatedAmount; // Safe server-calculated total
        $replenishment->remarks = $requestData['remarks'] ?? null;
        $replenishment->date_created = date('Y-m-d H:i:s');
        $replenishment->added_by = Yii::$app->user->id ?? null; // Secure server-side user tracking
        $replenishment->status = $requestData['status'] ?? 'draft';

        if (!$replenishment->validate()) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false, 
                'message' => 'Validation failed.', 
                'errors' => $replenishment->errors
            ];
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            if (!$replenishment->save(false)) { // Validation already ran above
                $transaction->rollBack();
                return [
                    'success' => false, 
                    'message' => 'Failed to save replenishment transaction.', 
                    'errors' => $replenishment->getErrors()
                ];
            }

            $processedPairs = [];

            foreach ($requestData['items'] as $itemData) {
                $invId = $itemData['inventory_id'] ?? null;
                $inventory = $inventories[$invId] ?? null;
                
                if (!$inventory) {
                    $transaction->rollBack();
                    Yii::$app->response->statusCode = 422;
                    return [
                        'success' => false, 
                        'message' => "Item ID {$invId} not found in inventory.", 
                        'errors' => []
                    ];
                }

                $qtyAdded = (int)($itemData['quantity'] ?? 0);
                $costPerUnit = (float)($itemData['cost'] ?? 0);

                if ($qtyAdded < 1) {
                    $transaction->rollBack();
                    Yii::$app->response->statusCode = 422;
                    return [
                        'success' => false,
                        'message' => 'Validation failed.',
                        'errors' => ["quantity_{$inventory->id}" => ['Invalid quantity.']]
                    ];
                }

                if ($costPerUnit <= 0) {
                    $transaction->rollBack();
                    Yii::$app->response->statusCode = 422;
                    return [
                        'success' => false,
                        'message' => 'Validation failed.',
                        'errors' => ["cost_{$inventory->id}" => ['Invalid cost.']]
                    ];
                }

                // Block exact matching row duplication bugs
                $payloadKey = $inventory->id . '-' . $costPerUnit;
                if (isset($processedPairs[$payloadKey])) {
                    $transaction->rollBack();
                    Yii::$app->response->statusCode = 422;
                    return [
                        'success' => false,
                        'message' => 'Validation failed.',
                        'errors' => [
                            'items' => ["Duplicate Row Blocked: An identical entry for {$inventory->product_name} [{$inventory->sku}] exists inside this form submission."]
                        ]
                    ];
                }
                $processedPairs[$payloadKey] = true;

                $replenishmentItem = new ReplenishmentItems();
                $replenishmentItem->transaction_id = $replenishment->id;
                $replenishmentItem->inventory_id = $inventory->id;
                $replenishmentItem->qty_added = $qtyAdded;
                $replenishmentItem->cost_per_unit = $costPerUnit;
                $replenishmentItem->status = ($replenishment->status === 'approved') ? 'approved' : 'draft';

                if (!$replenishmentItem->save()) {
                    $transaction->rollBack();
                    return [
                        'success' => false, 
                        'message' => 'Failed to save item line data.', 
                        'errors' => $replenishmentItem->getErrors()
                    ];
                }
            }

            // Audit Logging
            Yii::$app->db->createCommand()->insert('audit_log', [
                'entity' => 'replenishment',
                'entity_id' => $replenishment->id,
                'action' => 'create',
                'new_data' => json_encode([
                    'replenishment' => $replenishment->attributes,
                    'items' => $requestData['items']
                ]),
                'updated_by' => $replenishment->added_by,
                'updated_at' => date('Y-m-d H:i:s'),
            ])->execute();

            $transaction->commit();
            
            return [
                'success' => true,
                'message' => 'Replenishment transaction created successfully.',
                'id' => $replenishment->id
            ];

        } catch (\Exception $e) {
            $transaction->rollBack();
            Yii::$app->response->statusCode = 500;
            return [
                'success' => false, 
                'message' => 'An unexpected internal error occurred.', 
                'errors' => [$e->getMessage()]
            ];
        }
    }

    public function actionUpdate()
    {
        // Ensure JSON response format
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        if (Yii::$app->request->method !== 'PUT' && Yii::$app->request->method !== 'POST') {
            Yii::$app->response->statusCode = 405;
            return [
                'success' => false,
                'message' => 'Method not allowed.',
                'errors' => []
            ];
        }

        $requestData = Yii::$app->request->post();
        
        // Retrieve reference number cleanly from body params or POST array
        $referenceNo = Yii::$app->request->getBodyParam('reference_no') ?? ($requestData['reference_no'] ?? null);
        
        // Find existing parent record
        $replenishment = Replenishment::findOne(['reference_no' => $referenceNo]);
        if (!$replenishment) {
            Yii::$app->response->statusCode = 404;
            return [
                'success' => false,
                'message' => "Replenishment transaction with reference '{$referenceNo}' not found.",
                'errors' => []
            ];
        }

        // Store baseline details for inventory reversal and audit logging
        $oldStatus = $replenishment->status;
        $oldData = $replenishment->attributes;
        $oldItems = ReplenishmentItems::findAll(['transaction_id' => $replenishment->id]);

        // Validate if new items array exists and is valid
        if (!isset($requestData['items']) || !is_array($requestData['items']) || empty($requestData['items'])) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => ['items' => ['Add at least one item.']]
            ];
        }

        // Optimization: Batch-load all required inventories to eliminate N+1 queries loop
        $newInventoryIds = array_filter(array_column($requestData['items'], 'inventory_id'));
        $oldInventoryIds = array_column($oldItems, 'inventory_id');
        $allInventoryIds = array_unique(array_merge($newInventoryIds, $oldInventoryIds));
        $inventories = Inventory::find()->where(['id' => $allInventoryIds])->indexBy('id')->all();

        // Server-side recalculation of the total amount to guarantee system integrity
        $calculatedAmount = 0.0;
        foreach ($requestData['items'] as $itemData) {
            $qty = (int)($itemData['quantity'] ?? 0);
            $cost = (float)($itemData['cost'] ?? 0);
            if ($qty > 0 && $cost > 0) {
                $calculatedAmount += ($qty * $cost);
            }
        }

        // Safe model population and attribute updating
        $replenishment->load($requestData, '');
        $replenishment->supplier = $requestData['supplier'] ?? $replenishment->supplier;
        $replenishment->date_received = $requestData['date_received'] ?? $replenishment->date_received;
        $replenishment->amount = $calculatedAmount; // Apply safe calculated value
        $replenishment->remarks = $requestData['remarks'] ?? $replenishment->remarks;
        $replenishment->status = $requestData['status'] ?? 'draft';
        $replenishment->updated_by = Yii::$app->user->id ?? null; // Secure server-side user tracking

        if (!$replenishment->validate()) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $replenishment->errors
            ];
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            // 1. REVERT STOCK IF PREVIOUSLY APPROVED
            // If the transaction was approved, we must roll back its original stock layers before modifying
            $oldItemsDeletedLogs = [];
            foreach ($oldItems as $oldItem) {
                $oldItemsDeletedLogs[] = $oldItem->attributes;
            }

            // 2. PURGE OLD LINE ITEMS
            ReplenishmentItems::deleteAll(['transaction_id' => $replenishment->id]);

            // Save modified parent model parameters
            if (!$replenishment->save(false)) {
                $transaction->rollBack();
                return ['success' => false, 'message' => 'Failed to save parent replenishment record.', 'errors' => $replenishment->getErrors()];
            }

            // 3. PROCESS AND WRITE NEW ITEMS
            $processedPairs = [];
            foreach ($requestData['items'] as $itemData) {
                $invId = $itemData['inventory_id'] ?? null;
                $inventory = $inventories[$invId] ?? null;

                if (!$inventory) {
                    $transaction->rollBack();
                    Yii::$app->response->statusCode = 422;
                    return ['success' => false, 'message' => "Item ID {$invId} not found in master inventory records.", 'errors' => []];
                }

                $qtyAdded = (int)($itemData['quantity'] ?? 0);
                $costPerUnit = (float)($itemData['cost'] ?? 0);

                if ($qtyAdded < 1) {
                    $transaction->rollBack();
                    Yii::$app->response->statusCode = 422;
                    return ['success' => false, 'message' => 'Validation failed.', 'errors' => ["quantity_{$inventory->id}" => ['Invalid quantity.']]];
                }

                if ($costPerUnit <= 0) {
                    $transaction->rollBack();
                    Yii::$app->response->statusCode = 422;
                    return ['success' => false, 'message' => 'Validation failed.', 'errors' => ["cost_{$inventory->id}" => ['Invalid cost.']]];
                }

                // Block exact matching duplicate item row submission bugs inside the same form
                $payloadKey = $inventory->id . '-' . $costPerUnit;
                if (isset($processedPairs[$payloadKey])) {
                    $transaction->rollBack();
                    Yii::$app->response->statusCode = 422;
                    return [
                        'success' => false,
                        'message' => 'Validation failed.',
                        'errors' => ['items' => ["Duplicate Row Blocked: An identical entry for {$inventory->product_name} [{$inventory->sku}] exists inside this submission."]]
                    ];
                }
                $processedPairs[$payloadKey] = true;

                // Instantiate and write new sub-line records
                $replenishmentItem = new ReplenishmentItems();
                $replenishmentItem->transaction_id = $replenishment->id;
                $replenishmentItem->inventory_id = $inventory->id;
                $replenishmentItem->qty_added = $qtyAdded;
                $replenishmentItem->cost_per_unit = $costPerUnit;
                $replenishmentItem->status = ($replenishment->status === 'approved') ? 'approved' : 'draft';

                if (!$replenishmentItem->save()) {
                    $transaction->rollBack();
                    return ['success' => false, 'message' => 'Failed to save updated item line data.', 'errors' => $replenishmentItem->getErrors()];
                }

            }

            // 5. COMPREHENSIVE AUDIT LOGGING
            Yii::$app->db->createCommand()->insert('audit_log', [
                'entity' => 'replenishment',
                'entity_id' => $replenishment->id,
                'action' => 'update',
                'old_data' => json_encode([
                    'replenishment' => $oldData,
                    'items' => $oldItemsDeletedLogs
                ]),
                'new_data' => json_encode([
                    'replenishment' => $replenishment->attributes,
                    'items' => $requestData['items']
                ]),
                'updated_by' => $replenishment->updated_by,
                'updated_at' => date('Y-m-d H:i:s'),
            ])->execute();

            $transaction->commit();

            return [
                'success' => true,
                'message' => 'Replenishment transaction updated successfully.',
                'id' => $replenishment->id
            ];

        } catch (\Exception $e) {
            $transaction->rollBack();
            Yii::$app->response->statusCode = 500;
            return [
                'success' => false,
                'message' => 'An unexpected internal error occurred.',
                'errors' => [$e->getMessage()]
            ];
        }
    }    

    public function actionDelete()
    {
        if (Yii::$app->request->method !== 'POST' && Yii::$app->request->method !== 'DELETE') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $id = Yii::$app->request->getBodyParam('id');

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $replenishment = Replenishment::findOne($id);
            if (!$replenishment) {
                $transaction->rollBack();
                Yii::$app->response->statusCode = 404;
                return ['success' => false, 'error' => 'Replenishment record not found'];
            }

            // Capture old data before deletion
            $oldData = $replenishment->attributes;
            $itemsData = [];

            if ($replenishment->status != "draft") {
                // Rollback inventory stock levels and collect item data
                $replenishmentItems = ReplenishmentItems::findAll(['transaction_id' => $id]);
                foreach ($replenishmentItems as $replenishmentItem) {
                    $itemsData[] = $replenishmentItem->attributes;

                    $inventory = Inventory::findOne($replenishmentItem->inventory_id);
                    if ($inventory) {
                        if ($inventory->current_qty < $replenishmentItem->qty_added) {
                            $transaction->rollBack();
                            Yii::$app->response->statusCode = 422;
                            return ['success' => false, 'error' => 'Cannot be deleted. Items were already sold. Please void the sales first or adjust inventory.'];
                        }

                        $inventory->current_qty -= $replenishmentItem->qty_added;
                        if (!$inventory->save(false)) {
                            $transaction->rollBack();
                            return ['success' => false, 'error' => "Failed to update inventory stock."];
                        }
                    }

                    // Hard delete replenishment item
                    if (!$replenishmentItem->delete()) {
                        $transaction->rollBack();
                        return ['success' => false, 'error' => "Failed to delete replenishment item."];
                    }
                }
            } else {
                ReplenishmentItems::deleteAll(['transaction_id' => $id]);
            }

            // Hard delete parent replenishment record
            if (!$replenishment->delete()) {
                $transaction->rollBack();
                return ['success' => false, 'error' => 'Failed to delete replenishment record'];
            }

            // ✅ Insert into audit log after successful delete
            Yii::$app->db->createCommand()->insert('audit_log', [
                'entity' => 'replenishment',
                'entity_id' => $id,
                'action' => 'delete',
                'old_data' => json_encode([
                    'replenishment' => $oldData,
                    'items' => $itemsData
                ]),
                'new_data' => null,
                'updated_by' => Yii::$app->user->identity->username ?? 'system',
                'updated_at' => date('Y-m-d H:i:s'),
            ])->execute();

            $transaction->commit();
            return [
                'success' => true,
                'message' => 'Replenishment and items deleted successfully, changes logged'
            ];

        } catch (\Exception $e) {
            $transaction->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function actionGeneratetrnxno()
    {   
        $prefix = 'RPL';
        $date = date('ymd');
        $count = Replenishment::find()->orderBy(['id' => SORT_DESC])->limit(1)->one();
        $lastId = Yii::$app->db->createCommand("SELECT MAX(id) FROM replenishment")->queryScalar();
        $lastId = str_pad($lastId + 1, 4, '0', STR_PAD_LEFT);

        return [
            'success' => true,
            'trnxno' => $prefix . $date . $lastId,
        ];
    }

    protected function findModel($id)
    {
        if (($model = Replenishment::findOne(['id' => $id])) !== null) {
            return $model;
        }

        throw new NotFoundHttpException('The requested page does not exist.');
    }

    public function actionSaveItems($replenishmentId)
    {
        $replenishmentItems = Yii::$app->request->post('replenishment_items');

        if (!$replenishmentItems) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'No items provided'];
        }

        foreach ($replenishmentItems as $itemData) {
            $item = new ReplenishmentItem();
            $item->replenishment_id = $replenishmentId;
            $item->inventory_id = $itemData['inventory_id'];
            $item->quantity = $itemData['quantity'];
            $item->save();
        }

        return ['success' => true];
    }
}