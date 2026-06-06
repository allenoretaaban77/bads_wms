<?php

namespace app\controllers;

use Yii;
use app\models\Sales;
use app\models\SalesItems;
use app\models\Inventory;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\filters\VerbFilter;
use yii\db\Expression;
use app\models\Employee; 

class SalesController extends Controller
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

        // Base query with computed total amount
        $query = Sales::find()->select([
            'sales.*',
            'amount' => (new \yii\db\Query())
                ->select('SUM(qty_sold * price_per_unit)')
                ->from('sales_items')
                ->where('sales_id = sales.id')
        ]);
        // ->where(['sales.record_status' => 'active']);

        // 🔍 Search (customer_name, invoice_no, date_sold, payment_method)
        $search = $request->get('search');
        if (!empty($search)) {
            $query->andFilterWhere([
                'or',
                ['like', 'customer_name', $search],
                ['like', 'invoice_no', $search],
                ['like', 'date_sold', $search],
                ['like', 'payment_method', $search],
                ['like', 'amount', $search],
                ['like', 'remarks', $search],
            ]);
        }

        // 📄 Pagination
        $page = (int)$request->get('page', 1);
        $pageSize = (int)$request->get('pageSize', 10);
        $offset = ($page - 1) * $pageSize;

        // 📊 Sorting (default id ASC)
        $sortField = $request->get('sort', 'id');
        $sortOrder = strtolower($request->get('order', 'asc')) === 'desc' ? SORT_DESC : SORT_ASC;

        $allowedSortFields = [
            'id', 'customer_name', 'invoice_no', 'date_sold', 'payment_method', 'amount',
        ];
        if (in_array($sortField, $allowedSortFields)) {
            $query->orderBy([$sortField => $sortOrder]);
        } else {
            $query->orderBy(['id' => SORT_ASC]);
        }

        // Execute query
        $totalCount = $query->count();
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

    public function actionView($id)
    {
        $sales = Sales::find()
            ->where(['id' => $id])
            ->asArray()
            ->one();

        if (!$sales) {
            Yii::$app->response->statusCode = 404;
            return ['success' => false, 'error' => 'Sales transaction not found'];
        }

        $items = SalesItems::find()
            ->select([
                'sales_items.*',
                'product_name' => 'inventory.product_name',
                'current_qty' => 'inventory.current_qty',
                'reorder_level' => 'inventory.reorder_level',
                'cost_per_unit' => 'inventory.cost_per_unit',
                'sku' => 'inventory.sku',
            ])
            ->leftJoin('inventory', 'inventory.id = sales_items.inventory_id')
            ->where(['sales_id' => $id])
            ->asArray()
            ->all();

        $sales['items'] = $items;

        return [
            'success' => true,
            'data' => $sales
        ];
    }

    public function actionCreate()
    {
        if (Yii::$app->request->method !== 'POST') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $data = new Sales();
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
            $sales = new Sales();
            $sales->customer_name = $data['customer_name'] ?? null;
            $sales->invoice_no = $data['invoice_no'] ?? null;
            $sales->date_sold = $data['date_sold'] ?? date('Y-m-d');
            $sales->payment_status = $data['payment_status'] ?? null;
            $sales->amount = (float)($data['amount'] ?? 0);
            $sales->remarks = $data['remarks'] ?? null;
            $sales->date_created = date('Y-m-d H:i:s');
            $sales->added_by = $data['added_by'] ?? null;
            $sales->status = $data['status'] ?? null;

            if (!$sales->save()) {
                $transaction->rollBack();
                Yii::$app->response->statusCode = 422;
                return ['error' => 'Validation failed', 'errors' => $sales->getErrors()];
            }
            Yii::debug($sales->getErrors(), __METHOD__);

            $itemsData = $data['items'];
            foreach ($itemsData as $itemData) {
                // $inventory = Inventory::findOne(['id' => $itemData['inventory_id']]);
                $inventory = Inventory::find()
                    ->where(['id' => $itemData['inventory_id']])
                    ->forUpdate()
                    ->one();
                    
                if (!$inventory) {
                    $transaction->rollBack();
                    return ['success' => false, 'error' => "Item not found in inventory"];
                }

                if ($itemData['quantity'] == "" || $itemData['quantity'] < 1) {
                    $transaction->rollBack();
                    Yii::$app->response->statusCode = 422;
                    return ['error' => 'Validation failed', 'errors' => ['quantity_'.$inventory->id => ['Invalid quantity.']]];
                }

                if ($itemData['price'] == "" || $itemData['price'] < 1) {
                    $transaction->rollBack();
                    Yii::$app->response->statusCode = 422;
                    return ['error' => 'Validation failed', 'errors' => ['price_'.$inventory->id => ['Invalid price.']]];
                }

                if($data['status'] != 'draft') {
                    if ($inventory->current_qty < $itemData['quantity']) {
                        $transaction->rollBack();
                        Yii::$app->response->statusCode = 422;
                        return ['error' => 'Validation failed', 'errors' => ['quantity_'.$inventory->id => ['Insufficient stock.']]];
                    }
                }

                $salesItem = new SalesItems();
                $salesItem->sales_id = $sales->id;
                $salesItem->inventory_id = $inventory->id;
                $salesItem->qty_sold = (int)($itemData['quantity'] ?? 0);
                $salesItem->price_per_unit = (float)($itemData['price'] ?? 0);

                if (!$salesItem->save()) {
                    $transaction->rollBack();
                    return ['success' => false, 'errors' => $salesItem->getErrors()];
                }

                // Update Inventory current_qty and price_per_unit
                if($data['status'] != 'draft') {
                    $inventory->current_qty = new \yii\db\Expression('current_qty - :qty', [':qty' => $salesItem->qty_sold]);
                
                    if ($salesItem->price_per_unit > 0) {
                        $inventory->price_per_unit = $salesItem->price_per_unit;
                    }
                }

                if (!$inventory->save(false)) { // Save without validation to be faster
                    $transaction->rollBack();
                    return ['success' => false, 'error' => "Failed to update inventory for '{$itemData['item_name']}'"];
                }
            }

            // ✅ Insert into audit log after successful update
            Yii::$app->db->createCommand()->insert('audit_log', [
                'entity' => 'sales',
                'entity_id' => $sales->id,
                'action' => 'create',
                'new_data' => json_encode([
                    'sales' => $sales->attributes,
                    'items' => $itemsData
                ]),
                'updated_by' => $sales->added_by,
                'updated_at' => date('Y-m-d H:i:s'),
            ])->execute();

            $transaction->commit();
            return [
                'success' => true,
                'message' => 'Sales invoice created successfully',
                'id' => $sales->id
            ];
        } catch (\Exception $e) {
            $transaction->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function actionUpdate()
    {
        if (Yii::$app->request->method !== 'PUT' && Yii::$app->request->method !== 'POST') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $invoice_no = Yii::$app->request->getBodyParam('invoice_no');
        $sales = Sales::findOne(['invoice_no' => $invoice_no]);
        $oldData = $sales->attributes;

        $request = Yii::$app->request;
        $data = $request->post();
        $sales->load($data, '');

        if (!$sales->validate()) {
            Yii::$app->response->statusCode = 422;
            return ['error' => 'Validation failed', 'errors' => $sales->errors];
        }

        if (!isset($data['items']) || !is_array($data['items']) || count($data['items']) === 0) {
            Yii::$app->response->statusCode = 422;
            return ['error' => 'Validation failed', 'errors' => ['items' => ['Add at least one item.']]];
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            // Save parent record
            if (!$sales->save()) {
                $transaction->rollBack();
                return ['success' => false, 'errors' => $sales->getErrors()];
            }

            $newItems = $data['items'];
            $oldItems = SalesItems::findAll(['sales_id' => $sales->id]);

            // Validate for negative deductions
            // if($data['status'] != 'draft') {
            //     foreach ($newItems as $newItem) {
            //         $inventory = Inventory::findOne(['id' => $newItem['inventory_id']]);
            //         foreach ($oldItems as $oldItem) {
            //             if ($newItem['inventory_id'] == $oldItem['inventory_id']) {

            //                 var_dump([ 'new'=>$newItem['quantity'], 'old'=> $oldItem['qty_sold']]);
            //                 if ($newItem['quantity'] > $oldItem['qty_sold']) {
            //                     $induction = floatval($newItem['quantity']) - floatval($oldItem['qty_sold']);
            //                     if ($inventory->current_qty < $induction) {
            //                         $transaction->rollBack();
            //                         Yii::$app->response->statusCode = 422;
            //                         return ['error' => 'Validation failed', 'errors' => ['quantity_'.$inventory->id => ['Cannot add additional count. Insufficient stock.']]];
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
                        $inventory->current_qty = new \yii\db\Expression('current_qty - :qty', [':qty' => $oldItem->qty_sold]);
                        $inventory->save(false);
                    }
                }
            }
            SalesItems::deleteAll(['sales_id' => $sales->id]);

            foreach ($newItems as $itemData) {
                // $inventory = Inventory::findOne(['id' => $itemData['inventory_id']]);
                $inventory = Inventory::find()
                    ->where(['id' => $itemData['inventory_id']])
                    ->forUpdate()
                    ->one();
                
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

                $salesItem = new SalesItems();
                $salesItem->sales_id = $sales->id;
                $salesItem->inventory_id = $inventory->id;
                $salesItem->qty_sold = (int)$itemData['quantity'];
                $salesItem->price_per_unit = (float)$itemData['price'];

                if (!$salesItem->save()) {
                    $transaction->rollBack();
                    return ['success' => false, 'errors' => $salesItem->getErrors()];
                }

                if($data['status'] != 'draft') {
                    // Update inventory current_qty
                    $inventory->current_qty = new \yii\db\Expression('current_qty - :qty', [':qty' => $salesItem->qty_sold]);

                    // Update inventory price_per_unit
                    if ($salesItem->price_per_unit > 0) {
                        $inventory->price_per_unit = $salesItem->price_per_unit;
                    }
                }

                if (!$inventory->save(false)) {
                    $transaction->rollBack();
                    return ['success' => false, 'error' => "Failed to update inventory for '{$itemData['item_name']}'"];
                }
            }

            // ✅ Insert into audit log after successful update
            Yii::$app->db->createCommand()->insert('audit_log', [
                'entity' => 'sales',
                'entity_id' => $sales->id,
                'action' => 'update',
                'old_data' => json_encode([
                    'sales' => $oldData,
                    'items' => $oldItemsToDelete 
                ]),
                'new_data' => json_encode([
                    'sales' => $sales->attributes,
                    'items' => $newItems 
                ]),
                'updated_by' => $sales->updated_by,
                'updated_at' => date('Y-m-d H:i:s'),
            ])->execute();

            $transaction->commit();
            return [
                'success' => true,
                'message' => 'Sales transaction updated successfully.',
                'id' => $sales->id
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
        $sales = Sales::findOne(['invoice_no' => $data['invoice_no']]);
        // $invoice_no = Yii::$app->request->getBodyParam('invoice_no');

        $transaction = Yii::$app->db->beginTransaction();
        try {
            // Save parent record
            $sales->status = $data['status'];
            $sales->updated_by = $data['updated_by'];

            if (!$sales->save()) {
                $transaction->rollBack();
                return ['success' => false, 'errors' => $sales->getErrors()];
            }

            $itemDatas = SalesItems::findAll(['sales_id' => $sales->id]);

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

                // $salesItem = new SalesItems();
                // $salesItem->transaction_id = $sales->id;
                // $salesItem->inventory_id = $inventory->id;
                // $salesItem->qty_added = (int)$itemData['quantity'];
                // $salesItem->price_per_unit = (float)$itemData['cost'];

                // if (!$salesItem->save()) {
                //     $transaction->rollBack();
                //     return ['success' => false, 'errors' => $salesItem->getErrors()];
                // }

                if ($inventory->current_qty < $itemData['qty_sold']) {
                    $transaction->rollBack();
                    Yii::$app->response->statusCode = 422;
                    return ['error' => 'Insufficient stock for item '.$inventory->product_name.' ('.$inventory->sku.').'];
                }

                // Update inventory current_qty
                $inventory->current_qty = new \yii\db\Expression('current_qty - :qty', [':qty' => $itemData['qty_sold']]);

                // Update inventory price_per_unit
                if ($itemData['price_per_unit'] > 0) {
                    $inventory->price_per_unit = $itemData['price_per_unit'];
                }

                if (!$inventory->save(false)) {
                    $transaction->rollBack();
                    return ['success' => false, 'error' => "Failed to update inventory for '{$itemData['item_name']}'"];
                }
            }

            // ✅ Insert into audit log after successful update
            Yii::$app->db->createCommand()->insert('audit_log', [
                'entity' => 'sales',
                'entity_id' => $sales->id,
                'action' => 'approve',
                'new_data' => json_encode([
                    'sales' => $sales->attributes,
                    'items' => $itemDatas 
                ]),
                'updated_by' => $sales->updated_by,
                'updated_at' => date('Y-m-d H:i:s'),
            ])->execute();

            $transaction->commit();
            return [
                'success' => true,
                'message' => 'Sales transaction approved successfully.',
                'id' => $sales->id
            ];
        } catch (\Exception $e) {
            $transaction->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function actionVoid()
    {
        if (Yii::$app->request->method !== 'POST' && Yii::$app->request->method !== 'DELETE') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $id = Yii::$app->request->getBodyParam('id');

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $sales = Sales::findOne($id);
            if (!$sales) {
                $transaction->rollBack();
                Yii::$app->response->statusCode = 404;
                return ['success' => false, 'error' => 'Sales record not found'];
            }

            // Capture old data before deletion
            $oldData = $sales->attributes;
            $itemsData = [];

            // Rollback inventory stock levels and collect item data
            $salesItems = SalesItems::findAll(['sales_id' => $id]);
            foreach ($salesItems as $salesItem) {
                $itemsData[] = $salesItem->attributes;

                $inventory = Inventory::findOne($salesItem->inventory_id);
                if ($inventory) {
                    if ($sales['payment_status'] != 'draft') 
                        $inventory->current_qty += $salesItem->qty_sold;

                    if (!$inventory->save(false)) {
                        $transaction->rollBack();
                        return ['success' => false, 'error' => "Failed to update inventory stock."];
                    }
                }

                $salesItem->record_status = 'inactive';
                if (!$salesItem->save()) {
                    $transaction->rollBack();
                    return ['success' => false, 'error' => "Failed to void sales item."];
                }
            }

            $sales->record_status = 'inactive';
            if (!$sales->save()) {
                $transaction->rollBack();
                return ['success' => false, 'error' => 'Failed to void sales record'];
            }

            // ✅ Insert into audit log after successful delete
            Yii::$app->db->createCommand()->insert('audit_log', [
                'entity' => 'sales',
                'entity_id' => $id,
                'action' => 'void',
                'old_data' => json_encode([
                    'sales' => $oldData,
                    'items' => $itemsData
                ]),
                'new_data' => null,
                'updated_by' => Yii::$app->user->identity->username ?? 'system',
                'updated_at' => date('Y-m-d H:i:s'),
            ])->execute();

            $transaction->commit();
            return [
                'success' => true,
                'message' => 'Sales and items void successfully, changes logged'
            ];

        } catch (\Exception $e) {
            $transaction->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
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
            $sales = Sales::findOne($id);
            if (!$sales) {
                $transaction->rollBack();
                Yii::$app->response->statusCode = 404;
                return ['success' => false, 'error' => 'Sales record not found'];
            }

            // Capture old data before deletion
            $oldData = $sales->attributes;
            $itemsData = [];

            if ($sales->status != "draft") {
                // Rollback inventory stock levels and collect item data
                $salesItems = SalesItems::findAll(['sales_id' => $id]);
                foreach ($salesItems as $salesItem) {
                    $itemsData[] = $salesItem->attributes;

                    $inventory = Inventory::findOne($salesItem->inventory_id);
                    if ($inventory) {
                        if ($sales['payment_status'] != 'draft') 
                            $inventory->current_qty += $salesItem->qty_sold;

                        if (!$inventory->save(false)) {
                            $transaction->rollBack();
                            return ['success' => false, 'error' => "Failed to update inventory stock."];
                        }
                    }

                    // Hard delete sales item
                    if (!$salesItem->delete()) {
                        $transaction->rollBack();
                        return ['success' => false, 'error' => "Failed to delete sales item."];
                    }
                }
            } else {
                SalesItems::deleteAll(['sales_id' => $id]);
            }

            // Hard delete parent sales record
            if (!$sales->delete()) {
                $transaction->rollBack();
                return ['success' => false, 'error' => 'Failed to delete sales record'];
            }

            // ✅ Insert into audit log after successful delete
            Yii::$app->db->createCommand()->insert('audit_log', [
                'entity' => 'sales',
                'entity_id' => $id,
                'action' => 'delete',
                'old_data' => json_encode([
                    'sales' => $oldData,
                    'items' => $itemsData
                ]),
                'new_data' => null,
                'updated_by' => Yii::$app->user->identity->username ?? 'system',
                'updated_at' => date('Y-m-d H:i:s'),
            ])->execute();

            $transaction->commit();
            return [
                'success' => true,
                'message' => 'Sales and items deleted successfully, changes logged'
            ];

        } catch (\Exception $e) {
            $transaction->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function actionGeneratetrnxno()
    {   
        $prefix = 'SLS';
        $date = date('ymd');
        $count = Sales::find()->orderBy(['id' => SORT_DESC])->limit(1)->one();
        $lastId = Yii::$app->db->createCommand("SELECT MAX(id) FROM sales")->queryScalar();
        $lastId = str_pad($lastId + 1, 4, '0', STR_PAD_LEFT);

        return [
            'success' => true,
            'trnxno' => $prefix . $date . $lastId,
        ];
    }
}