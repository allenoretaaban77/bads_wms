<?php

namespace app\controllers;

use Yii;
use app\models\Replenishment;
use app\models\ReplenishmentItems;
use app\models\Inventory;
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
            'amount' => (new \yii\db\Query())
                ->select('SUM(qty_added * cost_per_unit)')
                ->from('replenishment_items')
                ->where('transaction_id = replenishment.id')
        ])
        ->where(['replenishment.record_status' => 'active']);

        // 🔍 Search (product_name or SKU)
        $search = $request->get('search');
        if (!empty($search)) {
            $query->andFilterWhere([
                'or',
                ['like', 'supplier', $search],
                ['like', 'reference_no', $search],
                ['like', 'date_received', $search],
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

    public function actionView($id)
    {
        $replenishment = Replenishment::find()
            ->where(['id' => $id])
            ->asArray()
            ->one();

        if (!$replenishment) {
            Yii::$app->response->statusCode = 404;
            return ['success' => false, 'error' => 'Replenishment transaction not found'];
        }

        $items = ReplenishmentItems::find()
            ->select([
                'replenishment_items.*',
                'product_name' => 'inventory.product_name',
                'current_qty' => 'inventory.current_qty',
                'reorder_level' => 'inventory.reorder_level',
                'sku' => 'inventory.sku',
                //'total' => new \yii\db\Expression('replenishment_items.qty_added * replenishment_items.cost_per_unit')
            ])
            ->leftJoin('inventory', 'inventory.id = replenishment_items.inventory_id')
            ->where(['transaction_id' => $id])
            ->asArray()
            ->all();

        $replenishment['items'] = $items;

        return [
            'success' => true,
            'data' => $replenishment
        ];
    }

    public function actionCreate()
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

        $request = Yii::$app->request;
        $items = $request->post();
        // var_dump($items);

        if (!isset($items['items']) || !is_array($items['items']) || count($items['items']) === 0) {
            Yii::$app->response->statusCode = 422;
            return ['error' => 'Validation failed', 'errors' => ['items' => ['Add at least one item.']]];
        } else {
            $transaction = Yii::$app->db->beginTransaction();
            try {
                $replenishment = new Replenishment();
                $replenishment->supplier = $data['supplier'] ?? null;
                $replenishment->reference_no = $data['reference_no'] ?? null;
                $replenishment->date_received = $data['date_received'] ?? date('Y-m-d');
                $replenishment->remarks = $data['remarks'] ?? null;
                $replenishment->date_created = date('Y-m-d H:i:s');
                $replenishment->added_by = $data['added_by'] ?? null;

                if (!$replenishment->save()) {
                    $transaction->rollBack();
                    return ['success' => false, 'errors' => $replenishment->getErrors()];
                }
                Yii::debug($replenishment->getErrors(), __METHOD__);

                if (isset($items['items']) && is_array($items['items'])) {
                    foreach ($items['items'] as $itemData) {
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

                        if (!$replenishmentItem->save()) {
                            $transaction->rollBack();
                            return ['success' => false, 'errors' => $replenishmentItem->getErrors()];
                        }

                        // Update Inventory current_qty and cost_per_unit
                        $inventory->current_qty = new \yii\db\Expression('current_qty + :qty', [':qty' => $replenishmentItem->qty_added]);
                        if ($replenishmentItem->cost_per_unit > 0) {
                            $inventory->cost_per_unit = $replenishmentItem->cost_per_unit;
                        }

                        if (!$inventory->save(false)) { // Save without validation to be faster
                            $transaction->rollBack();
                            return ['success' => false, 'error' => "Failed to update inventory for '{$itemData['item_name']}'"];
                        }
                    }
                }

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
    }

    public function actionUpdate()
    {
        if (Yii::$app->request->method !== 'PUT' && Yii::$app->request->method !== 'POST') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $id = Yii::$app->request->getBodyParam('id');
        $reference_no = Yii::$app->request->getBodyParam('reference_no');
        $request = Yii::$app->request;
        $data = $request->post();
        
        // if (!$replenishment) {
        //     Yii::$app->response->statusCode = 404;
        //     return ['error' => 'Validation failed.', 'errors' => ['reference_no' => ["Replenishment transaction number not found."]]];
        // }

        $replenishment = Replenishment::findOne(['id' => $id]);
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

            // Delete old items before re‑inserting
            $oldItems = ReplenishmentItems::findAll(['transaction_id' => $replenishment->id]);
            foreach ($oldItems as $oldItem) {
                $inventory = Inventory::findOne($oldItem->inventory_id);
                if ($inventory) {
                    $inventory->current_qty = new \yii\db\Expression('current_qty - :qty', [':qty' => $oldItem->qty_added]);
                    $inventory->save(false);
                }
            }
            ReplenishmentItems::deleteAll(['transaction_id' => $replenishment->id]);

            foreach ($data['items'] as $itemData) {
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

                // Update Inventory current_qty and cost_per_unit
                $inventory->current_qty = new \yii\db\Expression('current_qty + :qty', [':qty' => $replenishmentItem->qty_added]);
                if ($replenishmentItem->cost_per_unit > 0) {
                    $inventory->cost_per_unit = $replenishmentItem->cost_per_unit;
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
                'new_data' => json_encode($replenishment->attributes),
                'updated_by' => $replenishment->added_by,
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

            // Rollback inventory stock levels and collect item data
            $replenishmentItems = ReplenishmentItems::findAll(['transaction_id' => $id]);
            foreach ($replenishmentItems as $replenishmentItem) {
                $itemsData[] = $replenishmentItem->attributes;

                $inventory = Inventory::findOne($replenishmentItem->inventory_id);
                if ($inventory) {
                    $inventory->current_qty -= $replenishmentItem->qty_added;
                    if ($inventory->current_qty < 0) {
                        $inventory->current_qty = 0;
                    }
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