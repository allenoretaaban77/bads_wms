<?php

namespace app\controllers;

use Yii;
use app\models\Sales;
use app\models\SalesItems;
use app\models\Inventory;
use app\models\InventoryBatches;
use app\models\ReplenishmentItems;
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

        // 🎯 Filters
        $filters = [
            'status' => $request->get('status'),
            'payment_status' => $request->get('payment_status'),
            'is_paid' => $request->get('is_paid'),
        ];
        foreach ($filters as $field => $value) {
            if (!empty($value)) {
                $query->andWhere([$field => $value]);
            }
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
                // 'current_qty' => 'inventory.current_qty',
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

    public function actionViewupdate($id)
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
                'product_name' => 'i.product_name',
                'reorder_level' => 'i.reorder_level',
                'sku' => 'i.sku',
                'i.tracking_method',
                'sales_items.inventory_id',
                'sales_items.price_per_unit AS price',
                'sales_items.cost_per_unit AS cost',
                'SUM(COALESCE(sales_items.qty_sold, 0)) AS qty_sold', 
                'SUM(COALESCE(sales_items.total, 0)) AS total', 
                'SUM(COALESCE(b.current_qty, 0)) AS current_qty', 
            ])
            ->leftJoin('inventory i', 'i.id = sales_items.inventory_id')
            ->leftJoin('inventory_batches b', 'b.id = sales_items.batch_id')
            ->where(['sales_items.sales_id' => $id])
            ->groupBy(['sales_items.inventory_id'])
            ->orderBy(['sales_items.id' => SORT_ASC])
            ->asArray()
            ->all();

        // add allocated_batches
        foreach ($items as &$item) {
            if ($item['tracking_method'] == 'batch_monitored') {
                $allocated_batches = SalesItems::find()
                    ->select([
                        // 'sales_items.*',
                        'sales_id' => 'sales_items.sales_id',
                        'quantity_out' => 'sales_items.qty_sold',
                        'sales_items.price_per_unit',
                        'sales_items.total',
                        'sales_items.inventory_id',
                        'product_name' => 'i.product_name',
                        'reorder_level' => 'i.reorder_level',
                        'cost_per_unit' => 'b.cost_per_unit',
                        'batch_id' => 'b.id',
                        // 'sku' => 'i.sku',
                        // 'i.tracking_method',
                    ])
                    ->leftJoin('inventory i', 'i.id = sales_items.inventory_id')
                    ->leftJoin('inventory_batches b', 'b.id = sales_items.batch_id')
                    ->where(['sales_items.sales_id' => $id])
                    ->andWhere(['sales_items.inventory_id' => $item['inventory_id']])
                    ->asArray()
                    ->all();
                $item['allocated_batches'] = $allocated_batches;

                unset($item); // because we use &$item
            }
        }


        $sales['items'] = $items;

        return [
            'success' => true,
            'data' => $sales
        ];
    }

    public function actionViewsales($id)
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
                'product_name' => 'i.product_name',
                // 'current_qty' => 'inventory.current_qty',
                'reorder_level' => 'i.reorder_level',
                'cost_per_unit' => 'i.cost_per_unit',
                'sku' => 'i.sku',
                'SUM(COALESCE(sales_items.qty_sold, 0)) AS qty_sold',
                'SUM(COALESCE(sales_items.total, 0)) AS total',  
            ])
            ->leftJoin('inventory i', 'i.id = sales_items.inventory_id')
            ->groupBy([
                'i.product_name'
            ])
            ->where(['sales_id' => $id])
            ->orderBy(['sales_items.id' => SORT_ASC])
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

        $requestData = Yii::$app->request->post();

        // Validate parent sales document rules
        $data = new Sales();
        $data->load($requestData, '');

        if (!$data->validate()) {
            Yii::$app->response->statusCode = 422;
            return ['error' => 'Validation failed', 'errors' => $data->errors];
        }

        if (!isset($requestData['items']) || !is_array($requestData['items']) || count($requestData['items']) === 0) {
            Yii::$app->response->statusCode = 422;
            return ['error' => 'Validation failed', 'errors' => ['items' => ['Add at least one item.']]];
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $sales = new Sales();
            $sales->customer_name = $requestData['customer_name'] ?? null;
            $sales->invoice_no = $requestData['invoice_no'] ?? null;
            $sales->date_sold = $requestData['date_sold'] ?? date('Y-m-d');
            $sales->payment_status = $requestData['payment_status'] ?? 'draft';
            $sales->payment_method = $requestData['payment_method'] ?? null;
            $sales->amount = (float)($requestData['amount'] ?? 0);
            $sales->remarks = $requestData['remarks'] ?? null;
            $sales->date_created = date('Y-m-d H:i:s');
            $sales->added_by = $requestData['added_by'] ?? null;
            $sales->status = $requestData['status'] ?? 'draft';
            $sales->is_paid = $requestData['is_paid'] ?? ($requestData['payment_status'] == 'cash' ? 'yes' : 'no');

            if (!$sales->save()) {
                $transaction->rollBack();
                Yii::$app->response->statusCode = 422;
                return ['error' => 'Validation failed', 'errors' => $sales->getErrors()];
            }

            $itemsData = $requestData['items'];

            foreach ($itemsData as $itemData) {
                // 1. Verify Item Master Exists
                $inventory = Inventory::findOne(['id' => $itemData['inventory_id']]);
                if (!$inventory) {
                    $transaction->rollBack();
                    Yii::$app->response->statusCode = 422;
                    return ['error' => 'Validation failed', 'errors' => ['items' => ["Item not found in inventory."]]];
                }

                $totalQtySold = (float)($itemData['quantity'] ?? 0);
                $pricePerUnit = (float)($itemData['price'] ?? 0);

                // Row-level base validations
                if ($totalQtySold <= 0) {
                    $transaction->rollBack();
                    Yii::$app->response->statusCode = 422;
                    return ['error' => 'Validation failed', 'errors' => ['items' => ['Invalid quantity: '.$inventory->product_name]]];
                }
                if ($pricePerUnit <= 0) {
                    $transaction->rollBack();
                    Yii::$app->response->statusCode = 422;
                    return ['error' => 'Validation failed', 'errors' => ['items' => ['Invalid price.: '.$inventory->product_name]]];
                }

                // 2. Fork Flow based on Tracking Method
                // Assumed property names: 'batch_monitored' and 'standard'
                if ($inventory->tracking_method === 'batch_monitored') {
                    
                    $allocatedBatches = $itemData['allocated_batches'] ?? [];
                    if (empty($allocatedBatches)) {
                        $transaction->rollBack();
                        Yii::$app->response->statusCode = 422;
                        return ['error' => 'Validation failed', 'errors' => ['items' => ["Allocated batches are required for batch-monitored item: '{$inventory->product_name}'."]]];
                    }

                    // Verify sum of allocations matches frontend totals
                    $sumAllocated = array_sum(array_column($allocatedBatches, 'quantity_out'));
                    if ($sumAllocated !== $totalQtySold) {
                        $transaction->rollBack();
                        Yii::$app->response->statusCode = 422;
                        return ['error' => 'Validation failed', 'errors' => ['items' => ["Allocated batch quantities ({$sumAllocated}) do not equal total line quantity ({$totalQtySold}) for '{$inventory->product_name}'."]]];
                    }

                    // Process manual batch allocations
                    foreach ($allocatedBatches as $allocated) {
                        $batchId = $allocated['batch_id'];
                        $qtyOut = (int)$allocated['quantity_out'];
                        $costPerUnit = 0.00;

                        // Draft state fallback
                        $batch = InventoryBatches::findOne(['id' => $batchId, 'inventory_id' => $inventory->id]);
                        if ($batch) {
                            $costPerUnit = (float)$batch->cost_per_unit;
                        }

                        // Save line-item linked directly to this custom batch assignment
                        $this->saveSalesItemRow($sales->id, $inventory->id, $batchId, $qtyOut, $pricePerUnit, $costPerUnit);
                    }

                } elseif ($inventory->tracking_method === 'standard') {
                    // FIFO logic pipeline
                    // Query batches ordered oldest to newest
                    $batchesQuery = InventoryBatches::find()
                        ->where(['inventory_id' => $inventory->id])
                        ->andWhere(['>', 'current_qty', 0])
                        ->orderBy(['id' => SORT_ASC]);

                    $availableBatches = $batchesQuery->all();

                    // Validate global stock volume across available rows
                    $totalAvailableStock = array_sum(array_column($availableBatches, 'current_qty'));
                    
                    if ($totalAvailableStock < $totalQtySold) {
                        $transaction->rollBack();
                        Yii::$app->response->statusCode = 422;
                        return [
                            'error' => 'Validation failed', 
                            'errors' => ['items' => ["Insufficient total stock for {$inventory->product_name} [{$inventory->sku}]. Required: {$totalQtySold}, Total Available: {$totalAvailableStock}."]]
                        ];
                    }

                    $remainderToDeduct = $totalQtySold;

                    foreach ($availableBatches as $batch) {
                        if ($remainderToDeduct <= 0) {
                            break;
                        }

                        $currentBatchQty = (int)$batch->current_qty;
                        // Deduct either what remains or up to the max capacity of this specific bucket layer
                        $deductionAmount = min($currentBatchQty, $remainderToDeduct);
                        $costPerUnit = (float)$batch->cost_per_unit;

                        // 🌟 Saves perfectly for BOTH draft and approved! 
                        // For draft, it assigns the expected batch_id without touching inventory counters.
                        $this->saveSalesItemRow($sales->id, $inventory->id, $batch->id, $deductionAmount, $pricePerUnit, $costPerUnit);
                        
                        $remainderToDeduct -= $deductionAmount;
                    }
                }
            }

            // Write actions to global transaction audit trail
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
                'message' => 'Sales invoice created successfully.',
                'id' => $sales->id
            ];

        } catch (\Exception $e) {
            $transaction->rollBack();
            Yii::$app->response->statusCode = 500;
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Helper utility function to save individual sales lines cleanly
     */
    private function saveSalesItemRow($salesId, $inventoryId, $batchId, $qty, $price, $cost)
    {
        $salesItem = new SalesItems();
        $salesItem->sales_id = $salesId;
        $salesItem->inventory_id = $inventoryId;
        $salesItem->batch_id = $batchId;
        $salesItem->qty_sold = $qty;
        $salesItem->price_per_unit = $price;
        $salesItem->cost_per_unit = $cost;

        if (!$salesItem->save()) {
            throw new \yii\db\Exception("Failed saving line row properties: " . json_encode($salesItem->getErrors()));
        }
    }

    public function actionUpdate()
    {
        if (Yii::$app->request->method !== 'PUT' && Yii::$app->request->method !== 'POST') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $request = Yii::$app->request;
        $requestData = $request->post();
        $invoice_no = $request->getBodyParam('invoice_no') ?? ($requestData['invoice_no'] ?? null);

        if (empty($invoice_no)) {
            Yii::$app->response->statusCode = 422;
            return ['error' => 'Validation failed', 'errors' => ['invoice_no' => ['Invoice number is required.']]];
        }

        $sales = Sales::findOne(['invoice_no' => $invoice_no]);
        if (!$sales) {
            Yii::$app->response->statusCode = 404;
            return ['error' => "Invoice '{$invoice_no}' not found."];
        }

        // Keep track of previous state before processing updates
        $oldData = $sales->attributes;

        $sales->load($requestData, '');
        if (!$sales->validate()) {
            Yii::$app->response->statusCode = 422;
            return ['error' => 'Validation failed', 'errors' => $sales->errors];
        }

        if (!isset($requestData['items']) || !is_array($requestData['items']) || count($requestData['items']) === 0) {
            Yii::$app->response->statusCode = 422;
            return ['error' => 'Validation failed', 'errors' => ['items' => ['Add at least one item.']]];
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            // Set update tracking timestamps
            $sales->date_updated = date('Y-m-d H:i:s');
            if (!$sales->save()) {
                $transaction->rollBack();
                Yii::$app->response->statusCode = 422;
                return ['error' => 'Validation failed', 'errors' => $sales->getErrors()];
            }

            $newItems = $requestData['items'];
            $oldItems = SalesItems::findAll(['sales_id' => $sales->id]);
            $oldItemsToDelete = [];

            // Document is a draft, capture properties for audit logs before wiping lines
            foreach ($oldItems as $oldItem) {
                $oldItemsToDelete[] = $oldItem->attributes;
            }

            // Wipe previous line entries cleanly before writing new ones
            SalesItems::deleteAll(['sales_id' => $sales->id]);

            // 📥 STEP 2: Process new lines coming from payload configuration (Draft State ONLY)
            foreach ($newItems as $itemData) {
                $inventory = Inventory::findOne(['id' => $itemData['inventory_id']]);
                if (!$inventory) {
                    $transaction->rollBack();
                    Yii::$app->response->statusCode = 422;
                    return ['error' => 'Validation failed', 'errors' => ['items' => ["Item not found in inventory."]]];
                }

                $totalQtySold = (float)($itemData['quantity'] ?? 0);
                $pricePerUnit = (float)($itemData['price'] ?? 0);

                // Row-level base validations
                if ($totalQtySold <= 0) {
                    $transaction->rollBack();
                    Yii::$app->response->statusCode = 422;
                    return ['error' => 'Validation failed', 'errors' => ['items' => ['Invalid quantity: '.$inventory->product_name]]];
                }
                if ($pricePerUnit <= 0) {
                    $transaction->rollBack();
                    Yii::$app->response->statusCode = 422;
                    return ['error' => 'Validation failed', 'errors' => ['items' => ['Invalid price: '.$inventory->product_name]]];
                }

                // 2. Fork Flow based on Tracking Method (Draft-only behavior)
                if ($inventory->tracking_method === 'batch_monitored') {
                    
                    $allocatedBatches = $itemData['allocated_batches'] ?? [];
                    if (empty($allocatedBatches)) {
                        $transaction->rollBack();
                        Yii::$app->response->statusCode = 422;
                        return ['error' => 'Validation failed', 'errors' => ['items' => ["Allocated batches are required for batch-monitored item: '{$inventory->product_name}'."]]];
                    }

                    // Verify sum of allocations matches frontend totals
                    $sumAllocated = array_sum(array_column($allocatedBatches, 'quantity_out'));
                    if ($sumAllocated !== $totalQtySold) {
                        $transaction->rollBack();
                        Yii::$app->response->statusCode = 422;
                        return ['error' => 'Validation failed', 'errors' => ['items' => ["Allocated batch quantities ({$sumAllocated}) do not equal total line quantity ({$totalQtySold}) for '{$inventory->product_name}'."]]];
                    }

                    // Process manual batch allocations for draft
                    foreach ($allocatedBatches as $allocated) {
                        $batchId = $allocated['batch_id'];
                        $qtyOut = (int)$allocated['quantity_out'];
                        $costPerUnit = 0.00;

                        $batch = InventoryBatches::findOne(['id' => $batchId, 'inventory_id' => $inventory->id]);
                        if ($batch) {
                            $costPerUnit = (float)$batch->cost_per_unit;
                        }

                        // Save line-item linked directly to this custom batch assignment without touching counters
                        $this->saveSalesItemRow($sales->id, $inventory->id, $batchId, $qtyOut, $pricePerUnit, $costPerUnit);
                    }

                } elseif ($inventory->tracking_method === 'standard') {
                    // FIFO logic pipeline for draft (Assigns expected batches without changing counters)
                    $batchesQuery = InventoryBatches::find()
                        ->where(['inventory_id' => $inventory->id])
                        ->andWhere(['>', 'current_qty', 0])
                        ->orderBy(['id' => SORT_ASC]);

                    $availableBatches = $batchesQuery->all();

                    // Validate global stock volume across available rows
                    $totalAvailableStock = array_sum(array_column($availableBatches, 'current_qty'));
                    if ($totalAvailableStock < $totalQtySold) {
                        $transaction->rollBack();
                        Yii::$app->response->statusCode = 422;
                        return [
                            'error' => 'Validation failed', 
                            'errors' => ['items' => ["Insufficient total stock for {$inventory->product_name} [{$inventory->sku}]. Required: {$totalQtySold}, Total Available: {$totalAvailableStock}."]]
                        ];
                    }

                    $remainderToDeduct = $totalQtySold;

                    foreach ($availableBatches as $batch) {
                        if ($remainderToDeduct <= 0) {
                            break;
                        }

                        $currentBatchQty = (int)$batch->current_qty;
                        $deductionAmount = min($currentBatchQty, $remainderToDeduct);
                        $costPerUnit = (float)$batch->cost_per_unit;

                        // Assign expected batch parameters safely
                        $this->saveSalesItemRow($sales->id, $inventory->id, $batch->id, $deductionAmount, $pricePerUnit, $costPerUnit);
                        
                        $remainderToDeduct -= $deductionAmount;
                    }
                }
            }

            // Write actions to global transaction audit trail
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
                'updated_by' => $sales->added_by,
                'updated_at' => date('Y-m-d H:i:s'),
            ])->execute();

            $transaction->commit();
            return [
                'success' => true,
                'message' => 'Sales invoice draft updated successfully.',
                'id' => $sales->id
            ];

        } catch (\Exception $e) {
            $transaction->rollBack();
            Yii::$app->response->statusCode = 500;
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
        $requestData = $request->post();
        $invoice_no = $request->getBodyParam('invoice_no') ?? ($requestData['invoice_no'] ?? null);

        if (empty($invoice_no)) {
            Yii::$app->response->statusCode = 422;
            return ['error' => 'Validation failed', 'errors' => ['invoice_no' => ['Invoice number is required.']]];
        }

        // Fetch the target sales record
        $sales = Sales::findOne(['invoice_no' => $invoice_no]);
        if (!$sales) {
            Yii::$app->response->statusCode = 404;
            return ['error' => "Invoice '{$invoice_no}' not found."];
        }

        // Concurrency Guard: Ensure it hasn't been approved already
        if ($sales->status === 'approved') {
            Yii::$app->response->statusCode = 422;
            return ['error' => 'Validation failed', 'errors' => ['status' => ["Invoice '{$invoice_no}' is already approved."]]];
        }

        $oldData = $sales->attributes;
        $salesItems = SalesItems::findAll(['sales_id' => $sales->id]);

        if (empty($salesItems)) {
            Yii::$app->response->statusCode = 422;
            return ['error' => 'Validation failed', 'errors' => ['items' => ['Cannot approve an empty invoice. Add at least one item.']]];
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            // Update primary document tracking properties
            $sales->status = 'approved';
            $sales->payment_status = $requestData['payment_status'] ?? $sales->payment_status;
            $sales->is_paid = $requestData['is_paid'] ?? ($sales->payment_status == 'cash' ? 'yes' : $sales->is_paid);
            $sales->date_updated = date('Y-m-d H:i:s');

            if (!$sales->save()) {
                $transaction->rollBack();
                Yii::$app->response->statusCode = 422;
                return ['error' => 'Validation failed', 'errors' => $sales->getErrors()];
            }

            // Group existing saved draft lines by item to validate collective stock layers safely
            $itemsGrouped = [];
            foreach ($salesItems as $item) {
                $itemsGrouped[$item->inventory_id][] = $item;
            }

            // Process approvals and deduct stock layers matching your tracking method rules
            foreach ($itemsGrouped as $inventoryId => $lines) {
                $inventory = Inventory::findOne($inventoryId);
                if (!$inventory) {
                    $transaction->rollBack();
                    Yii::$app->response->statusCode = 422;
                    return ['error' => 'Validation failed', 'errors' => ['items' => ["Inventory item ID {$inventoryId} no longer exists."]]];
                }

                if ($inventory->tracking_method === 'batch_monitored') {
                    // For batch-monitored, process item rows directly using row-level FOR UPDATE allocations
                    foreach ($lines as $line) {
                        $batchQuery = InventoryBatches::find()->where(['id' => $line->batch_id, 'inventory_id' => $inventory->id]);
                        $batchCmd = $batchQuery->createCommand();
                        $batch = InventoryBatches::findBySql($batchCmd->getSql() . ' FOR UPDATE', $batchCmd->params)->one();

                        if (!$batch || $batch->current_qty < $line->qty_sold) {
                            $transaction->rollBack();
                            Yii::$app->response->statusCode = 422;
                            return [
                                'error' => 'Validation failed',
                                'errors' => ['quantity_' . $inventory->id => ["Insufficient stock in assigned batch layer for '{$inventory->product_name}'. available: " . ($batch ? $batch->current_qty : 0)]]
                            ];
                        }

                        // Atomic stock deduction
                        $batch->current_qty = new \yii\db\Expression('current_qty - :qty', [':qty' => $line->qty_sold]);
                        $batch->save(false);

                        // Update product master summary metric
                        $inventory->total_sold = new \yii\db\Expression('COALESCE(total_sold, 0) + :qty', [':qty' => $line->qty_sold]);
                        if ($line->price_per_unit > 0) {
                            // $inventory->price_per_unit = $line->price_per_unit; // do not update as per client
                        }
                        $inventory->save(false);
                    }

                } elseif ($inventory->tracking_method === 'standard') {
                    // Total up what this draft originally required for a standard FIFO verification pass
                    $totalQtyRequired = array_sum(array_column($lines, 'qty_sold'));

                    // Re-query current live valid FIFO pools using SELECT ... FOR UPDATE to handle live concurrency
                    $batchesQuery = InventoryBatches::find()
                        ->where(['inventory_id' => $inventory->id])
                        ->andWhere(['>', 'current_qty', 0])
                        ->orderBy(['id' => SORT_ASC]);
                    $batchCmd = $batchesQuery->createCommand();
                    $availableBatches = InventoryBatches::findBySql($batchCmd->getSql() . ' FOR UPDATE', $batchCmd->params)->all();

                    $totalAvailableStock = array_sum(array_column($availableBatches, 'current_qty'));
                    if ($totalAvailableStock < $totalQtyRequired) {
                        $transaction->rollBack();
                        Yii::$app->response->statusCode = 422;
                        return [
                            'error' => 'Validation failed',
                            'errors' => ['items' => ["Stock balances shifted! Insufficient stock for standard item '{$inventory->product_name}'. Required: {$totalQtyRequired}, Available: {$totalAvailableStock}."]]
                        ];
                    }

                    // Re-run real-time assignment to reflect actual current state safely
                    // Step A: Clear the draft rows for this specific item inside this transaction
                    SalesItems::deleteAll(['sales_id' => $sales->id, 'inventory_id' => $inventory->id]);

                    $remainderToDeduct = $totalQtyRequired;

                    foreach ($availableBatches as $batch) {
                        if ($remainderToDeduct <= 0) {
                            break;
                        }

                        $currentBatchQty = (int)$batch->current_qty;
                        $deductionAmount = min($currentBatchQty, $remainderToDeduct);
                        $costPerUnit = (float)$batch->cost_per_unit;
                        $pricePerUnit = (float)$lines[0]->price_per_unit; // Inherit original unit pricing

                        // Deduct from current batch row
                        $batch->current_qty = new \yii\db\Expression('current_qty - :qty', [':qty' => $deductionAmount]);
                        $batch->save(false);

                        // Update master summaries tracker metrics
                        $inventory->total_sold = new \yii\db\Expression('COALESCE(total_sold, 0) + :qty', [':qty' => $deductionAmount]);
                        if ($pricePerUnit > 0) {
                            // $inventory->price_per_unit = $pricePerUnit; // do not update as per client
                        }
                        $inventory->save(false);

                        // Re-write final synchronized item line row parameters
                        $this->saveSalesItemRow($sales->id, $inventory->id, $batch->id, $deductionAmount, $pricePerUnit, $costPerUnit);

                        $remainderToDeduct -= $deductionAmount;
                    }
                }
            }

            // Write validation action event parameters cleanly into audit database logs
            Yii::$app->db->createCommand()->insert('audit_log', [
                'entity' => 'sales',
                'entity_id' => $sales->id,
                'action' => 'approve',
                'old_data' => json_encode(['status' => $oldData['status']]),
                'new_data' => json_encode(['status' => $sales->status]),
                'updated_by' => $requestData['approved_by'] ?? ($sales->added_by),
                'updated_at' => date('Y-m-d H:i:s'),
            ])->execute();

            $transaction->commit();
            return [
                'success' => true,
                'message' => 'Sales invoice approved successfully and stock deductions executed.',
                'id' => $sales->id
            ];

        } catch (\Exception $e) {
            $transaction->rollBack();
            Yii::$app->response->statusCode = 500;
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

    public function actionStockbatches($id) 
    {
        if (Yii::$app->request->method !== 'GET') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $query_main = Inventory::find()
            ->alias('i')
            ->select([
                'i.id AS inventory_id',
                'i.product_name',
                'i.sku',
                'i.price_per_unit', // Selling Price
                'i.reorder_level',
                'i.type',
                'SUM(COALESCE(b.current_qty, 0)) AS current_qty',        // Combined total stock across all active batches
                // 'MAX(COALESCE(b.cost_per_unit, 0.00)) AS cost_per_unit', // Reference baseline cost (Displays highest batch cost)
            ])
            ->leftJoin('inventory_batches b', 'i.id = b.inventory_id')
            ->groupBy([
                'i.id', 'i.product_name', 'i.sku', 'i.price_per_unit', 
                'i.reorder_level', 'i.type'
            ])
            ->where(['inventory_id' => $id]);
        // $totalCountMain = $query_main->count();
        // $items_main = $query_main->asArray()->all();
        $info = $query_main->asArray()->one();

        $query = Inventory::find()
            ->alias('i')
            ->select([
                'i.id AS inventory_id',
                'i.product_name',
                'i.sku',
                'i.price_per_unit', // Selling Price
                'i.reorder_level',
                'i.type',
                'b.date_received',
                'b.cost_per_unit',
                'b.current_qty',
                'b.id',
                new Expression('0 AS target_quantity')
            ])
            ->leftJoin('inventory_batches b', 'i.id = b.inventory_id')
            ->where(['inventory_id' => $id]);
        $query->orderBy(['b.date_received' => SORT_DESC]);
        $totalCount = $query->count();
        $items = $query->asArray()->all();

        if (!$items) {
            Yii::$app->response->statusCode = 404;
            return ['success' => false, 'error' => 'Replenishment data not found'];
        } 

        $data['sku'] = $info['sku'];
        $data['product_name'] = $info['product_name'];
        $data['total_target_quantity'] = 0;
        $data['current_qty'] = $info['current_qty'];
        $data['reorder_level'] = $info['reorder_level'];
        $data['items'] = $items;

        return [
            'success' => true,
            'count' => $totalCount,
            'data' => $data,
        ];
    }

    public function actionSetpaidunpaid()
    {
        if (Yii::$app->request->method !== 'PUT' && Yii::$app->request->method !== 'PATCH') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $invoiceNo = Yii::$app->request->getBodyParam('invoice_no');
        $isPaid    = Yii::$app->request->getBodyParam('is_paid'); // expected 'yes' or 'no'
        $updatedBy = Yii::$app->request->getBodyParam('updated_by');

        if (!in_array($isPaid, ['yes', 'no'])) {
            Yii::$app->response->statusCode = 422;
            return ['error' => 'Invalid value for is_paid. Use "yes" or "no".'];
        }

        $sale = Sales::findOne(['invoice_no' => $invoiceNo]);
        if (!$sale) {
            Yii::$app->response->statusCode = 404;
            return ['error' => 'Sale not found'];
        }

        $oldData = $sale->attributes;

        $sale->is_paid    = $isPaid;
        $sale->updated_by = $updatedBy;

        if (!$sale->save()) {
            Yii::$app->response->statusCode = 500;
            return ['error' => 'Failed to update sale'];
        }

        // ✅ Audit log entry
        Yii::$app->db->createCommand()->insert('audit_log', [
            'entity'      => 'sales',
            'entity_id'   => $sale->id,
            'action'      => 'set_paid_unpaid',
            'old_data'    => json_encode($oldData),
            'new_data'    => json_encode($sale->attributes),
            'updated_by'  => $updatedBy,
            'updated_at'  => date('Y-m-d H:i:s'),
        ])->execute();

        return ['success' => true, 'data' => $sale];
    }
}