<?php

namespace app\controllers;

use Yii;
use app\models\Returns;
use app\models\ReturnsItems;
use app\models\Inventory;
use app\models\InventoryBatches;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\filters\VerbFilter;
use yii\db\Expression;
use app\models\Employee; 

class ReturnsController extends Controller
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
        // Ensure JSON response format
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        if (Yii::$app->request->method !== 'GET') {
            Yii::$app->response->statusCode = 405;
            return ['success' => false, 'error' => 'Method not allowed'];
        }

        $request = Yii::$app->request;

        // Base query with computed total return amount from line items matching your updated columns
        $query = Returns::find()->select([
            'returns.*',
            // ADJUSTED: Changed columns to match returns_items schema (qty_returned * unit_price)
            'calculated_amount' => (new \yii\db\Query())
                ->select('SUM(returns_items.qty_returned * returns_items.unit_price)')
                ->from('returns_items')
                ->where('returns_items.return_id = returns.id')
                ->andWhere(['returns_items.record_status' => 'active'])
        ]);

        // Enforce active logs only (soft-delete safe switch)
        $query->where(['returns.record_status' => 'active']);

        // 🔍 Search (customer_name, invoice_no, return_no, status, remarks)
        $search = $request->get('search');
        if (!empty($search)) {
            $query->andFilterWhere([
                'or',
                ['like', 'returns.customer_name', $search],
                ['like', 'returns.invoice_no', $search],
                ['like', 'returns.return_no', $search],
                ['like', 'returns.status', $search],
                ['like', 'returns.remarks', $search],
            ]);

            // Filter on computed aggregate field safely using having clause syntax mapping
            if (is_numeric($search)) {
                $query->andHaving(['like', 'calculated_amount', $search]);
            }
        }

        // 📄 Pagination
        $page = (int)$request->get('page', 1);
        $pageSize = (int)$request->get('pageSize', 10);
        $offset = ($page - 1) * $pageSize;

        // 📊 Sorting (default id DESC)
        $sortField = $request->get('sort', 'id');
        $sortOrder = strtolower($request->get('order', 'desc')) === 'asc' ? SORT_ASC : SORT_DESC;

        $allowedSortFields = [
            'id', 'customer_name', 'invoice_no', 'return_no', 'status', 'date_created', 'amount'
        ];
        
        if (in_array($sortField, $allowedSortFields)) {
            if ($sortField === 'amount') {
                $query->orderBy(['calculated_amount' => $sortOrder]);
            } else {
                $query->orderBy(["returns.$sortField" => $sortOrder]);
            }
        } else {
            $query->orderBy(['returns.id' => SORT_DESC]);
        }

        // Calculate counts using clean scalar clones
        $totalCount = (int)(clone $query)->count();
        
        // Eager load nested line items using ActiveRelation ('items' method inside your Returns model)
        $items = $query->offset($offset)
            ->limit($pageSize)
            // ->with(['itemsx' => function($q) {
            //     $q->andWhere(['returns_items.record_status' => 'active']); // Only load active line items
            // }]) 
            ->asArray()
            ->all();

        // Standardize output payload to map seamlessly to your React components
        return [
            'success' => true,
            'page' => $page,
            'pageSize' => $pageSize,
            'totalCount' => $totalCount,
            'totalPages' => (int)ceil($totalCount / $pageSize),
            'sortField' => $sortField,
            'sortOrder' => $sortOrder === SORT_ASC ? 'asc' : 'desc',
            'count' => count($items),
            'data' => array_map(function($row) {
                // UI Fallback fallback mapping logic: ensures 'amount' key exists seamlessly 
                $row['amount'] = $row['calculated_amount'] !== null ? (float)$row['calculated_amount'] : (float)$row['amount'];
                return $row;
            }, $items),
        ];
    }

    public function actionGetinvoiceitems($id)
    {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        if (Yii::$app->request->method !== 'GET') {
            Yii::$app->response->statusCode = 405;
            return ['success' => false, 'message' => 'Method not allowed.'];
        }

        $invoiceNo = $id;

        if (empty($invoiceNo)) {
            Yii::$app->response->statusCode = 400;
            return ['success' => false, 'message' => 'Invoice number parameter is required.'];
        }

        // Query sales_items matching the active invoice mapped to your explicit inventory schema
        $items = (new \yii\db\Query())
            ->select([
                'sales_item_id' => 'si.id',
                'sales_id'      => 's.id',
                'customer_name' => 's.customer_name', 
                'inventory_id'  => 'si.inventory_id',
                'batch_id'      => 'si.batch_id',
                'qty_sold'      => 'si.qty_sold',
                'unit_price'    => 'si.price_per_unit',
                'product_name'  => 'i.product_name',
                'sku'           => 'i.sku' // Exact match to your schema's `product_name`
            ])
            ->from('sales_items si')
            ->innerJoin('sales s', 'si.sales_id = s.id')
            ->innerJoin('inventory i', 'si.inventory_id = i.id') 
            ->where([
                's.invoice_no' => $invoiceNo,
                's.record_status' => 'active',
                'si.record_status' => 'active',
                'i.record_status' => 'Active' // Matches your schema's PascalCase ENUM ('Active', 'Inactive')
            ])
            ->all();

        if (empty($items)) {
            Yii::$app->response->statusCode = 404;
            return [
                'success' => false,
                'error' => "No active items found for Invoice: {$invoiceNo}"
            ];
        }

        // Return properties structured neatly inside data wrapper for returnsService.js
        return [
            'success' => true,
            'data' => [
                'invoice_id' => (int)$items[0]['sales_id'],
                'customer_name' => $items[0]['customer_name'] ?? 'Walk-in Customer',
                'items' => array_map(function($item) {
                    return [
                        'sales_item_id' => (int)$item['sales_item_id'],
                        'inventory_id'  => (int)$item['inventory_id'],
                        'sku'           => $item['sku'],
                        'batch_id'      => $item['batch_id'] !== null ? (int)$item['batch_id'] : null,
                        'qty_sold'      => (int)$item['qty_sold'],
                        'unit_price'    => (float)$item['unit_price'],
                        'product_name'  => $item['product_name'] ?? ('Product #' . $item['inventory_id'])
                    ];
                }, $items)
            ]
        ];
    }

    public function actionCreate()
    {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        if (Yii::$app->request->method !== 'POST') {
            Yii::$app->response->statusCode = 405;
            return ['success' => false, 'message' => 'Method not allowed.'];
        }

        // Handle both standard multipart forms and raw JSON payloads natively
        $requestData = Yii::$app->request->post();
        if (empty($requestData)) {
            $requestData = json_decode(Yii::$app->request->getRawBody(), true);
        }

        // Validate if items exist and is a valid array
        if (empty($requestData['items']) || !is_array($requestData['items'])) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => ['items' => ['Add at least one item row to commit return manifest logs.']]
            ];
        }

        // Optimization: Batch-load all inventories up front to avoid N+1 queries loop
        $inventoryIds = array_filter(array_column($requestData['items'], 'inventory_id'));
        $inventories = Inventory::find()->where(['id' => $inventoryIds])->indexBy('id')->all();

        // Server-side calculation of the total amount to prevent client tampering
        $calculatedAmount = 0.0;
        foreach ($requestData['items'] as $itemData) {
            $qty = (int)($itemData['qty_returned'] ?? 0);
            $price = (float)($itemData['unit_price'] ?? 0);
            if ($qty > 0 && $price > 0) {
                $calculatedAmount += ($qty * $price);
            }
        }

        // Single instantiation handles both validation and execution safely
        // (Assuming CustomerReturns is your ActiveRecord model name)
        $returnMaster = new Returns();
        $returnMaster->load($requestData, '');
        
        // Explicitly set metadata matching your incoming React payload fields
        $returnMaster->return_no = $requestData['return_no'] ?? null;
        $returnMaster->invoice_id = !empty($requestData['invoice_id']) ? (int)$requestData['invoice_id'] : null;
        $returnMaster->invoice_no = $requestData['invoice_no'] ?? null;
        $returnMaster->customer_name = $requestData['customer_name'] ?? 'Walk-in Customer';
        $returnMaster->date_received = $requestData['date_received'] ?? date('Y-m-d');
        $returnMaster->amount = $calculatedAmount; // Safe server-calculated credit amount
        $returnMaster->remarks = $requestData['remarks'] ?? null;
        $returnMaster->date_created = date('Y-m-d H:i:s');
        $returnMaster->added_by = Yii::$app->user->id ?? ($requestData['added_by'] ?? null); 
        $returnMaster->status = $requestData['status'] ?? 'draft';

        if (!$returnMaster->validate()) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false, 
                'message' => 'Validation failed.', 
                'errors' => $returnMaster->errors
            ];
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            if (!$returnMaster->save(false)) { 
                $transaction->rollBack();
                return [
                    'success' => false, 
                    'message' => 'Failed to save customer return transaction header record.', 
                    'errors' => $returnMaster->getErrors()
                ];
            }

            $processedPairs = [];
            $isInstantApprove = ($returnMaster->status === 'approved' || $returnMaster->status === 'completed');

            foreach ($requestData['items'] as $index => $itemData) {
                $invId = $itemData['inventory_id'] ?? null;
                $inventory = $inventories[$invId] ?? null;
                
                if (!$inventory) {
                    $transaction->rollBack();
                    Yii::$app->response->statusCode = 422;
                    return [
                        'success' => false, 
                        'message' => 'Validation failed.', 
                        'errors' => ["items.{$index}.inventory_id" => ["Item ID {$invId} not found in database product registries."]]
                    ];
                }

                $qtyReturned = (int)($itemData['qty_returned'] ?? 0);
                $unitPrice = (float)($itemData['unit_price'] ?? 0);
                $batchId = !empty($itemData['batch_id']) ? (int)$itemData['batch_id'] : null;

                if ($qtyReturned < 1) {
                    $transaction->rollBack();
                    Yii::$app->response->statusCode = 422;
                    return [
                        'success' => false,
                        'message' => 'Validation failed.',
                        'errors' => ["items.{$index}.qty_returned" => ['Returned quantity must be at least 1 units.']]
                    ];
                }

                if ($unitPrice <= 0) {
                    $transaction->rollBack();
                    Yii::$app->response->statusCode = 422;
                    return [
                        'success' => false,
                        'message' => 'Validation failed.',
                        'errors' => ["items.{$index}.unit_price" => ['Unit price structure parameter must be greater than ₱0.00.']]
                    ];
                }

                // Block matching layout row duplication bugs based on product + original trace sales item ID
                $payloadKey = $inventory->id . '-' . ($itemData['sales_item_id'] ?? '0');
                if (isset($processedPairs[$payloadKey])) {
                    $transaction->rollBack();
                    Yii::$app->response->statusCode = 422;
                    return [
                        'success' => false,
                        'message' => 'Validation failed.',
                        'errors' => [
                            "items.{$index}.inventory_id" => ["Duplicate row found: An entry for {$inventory->product_name} linked to this exact invoice reference line has already been added."]
                        ]
                    ];
                }
                $processedPairs[$payloadKey] = true;

                // Save lines targeting child returns schema table
                // (Assuming ReturnsItems is your ActiveRecord model name)
                $returnItem = new ReturnsItems();
                $returnItem->return_id = $returnMaster->id;
                $returnItem->inventory_id = $inventory->id;
                $returnItem->sales_item_id = !empty($itemData['sales_item_id']) ? (int)$itemData['sales_item_id'] : null;
                $returnItem->batch_id = $batchId;
                $returnItem->qty_returned = $qtyReturned;
                $returnItem->unit_price = $unitPrice;
                $returnItem->total = $qtyReturned * $unitPrice;
                $returnItem->reason = $itemData['reason'] ?? '';
                $returnItem->status = $isInstantApprove ? 'approved' : 'draft';

                if (!$returnItem->save()) {
                    $transaction->rollBack();
                    return [
                        'success' => false, 
                        'message' => 'Failed to save return item sub-line row.', 
                        'errors' => $returnItem->getErrors()
                    ];
                }

                // --- INSTANT APPROVAL BACK-TRIP TRIGGER ---
                // If saved instantly as completed or approved, increment stock assets right now
                if ($isInstantApprove) {
                    // 1. Update Global Core Count
                    $inventory->current_qty_x = new \yii\db\Expression('current_qty_x + :qty', [':qty' => $qtyReturned]);
                    if (!$inventory->save(false)) {
                        $transaction->rollBack();
                        return ['success' => false, 'message' => "Failed to update global stock matrix for item '{$inventory->product_name}'"];
                    }

                    // 2. Adjust Batch Layer Profile structures
                    $existingBatch = null;
                    if ($batchId !== null) {
                        $existingBatch = InventoryBatches::findOne(['id' => $batchId, 'inventory_id' => $inventory->id]);
                    }

                    if ($existingBatch) {
                        $existingBatch->initial_qty = new \yii\db\Expression('initial_qty + :qty', [':qty' => $qtyReturned]);
                        $existingBatch->current_qty = new \yii\db\Expression('current_qty + :qty', [':qty' => $qtyReturned]);
                        $existingBatch->save(false);
                    } else {
                        // Fallback matching logic if parent batch was deleted or unassigned
                        $costMatchBatch = InventoryBatches::findOne([
                            'inventory_id'  => $inventory->id,
                            'cost_per_unit' => $unitPrice
                        ]);

                        if ($costMatchBatch) {
                            $costMatchBatch->initial_qty = new \yii\db\Expression('initial_qty + :qty', [':qty' => $qtyReturned]);
                            $costMatchBatch->current_qty = new \yii\db\Expression('current_qty + :qty', [':qty' => $qtyReturned]);
                            $costMatchBatch->save(false);
                        } else {
                            // Generate custom isolated return tier allocation batch
                            $newBatch = new InventoryBatches();
                            $newBatch->inventory_id = $inventory->id;
                            $newBatch->cost_per_unit = $unitPrice;
                            $newBatch->initial_qty = $qtyReturned;
                            $newBatch->current_qty = $qtyReturned;
                            if (!$newBatch->save()) {
                                $transaction->rollBack();
                                return ['success' => false, 'errors' => $newBatch->getErrors()];
                            }
                        }
                    }
                }
            }

            // Write tracking metric payload block directly into history Audit Log
            Yii::$app->db->createCommand()->insert('audit_log', [
                'entity' => 'customer_returns',
                'entity_id' => $returnMaster->id,
                'action' => 'create',
                'new_data' => json_encode([
                    'customer_returns' => $returnMaster->attributes,
                    'items' => $requestData['items']
                ]),
                'updated_by' => $returnMaster->added_by,
                'updated_at' => date('Y-m-d H:i:s'),
            ])->execute();

            $transaction->commit();
            
            return [
                'success' => true,
                'message' => 'Customer return manifest recorded successfully.',
                'id' => $returnMaster->id
            ];

        } catch (\Exception $e) {
            $transaction->rollBack();
            Yii::$app->response->statusCode = 500;
            return [
                'success' => false, 
                'message' => 'An unexpected internal processing exception occurred.', 
                'errors' => [$e->getMessage()]
            ];
        }
    }

    public function actionApprove() 
    {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        if (Yii::$app->request->method !== 'PUT' && Yii::$app->request->method !== 'POST') {
            Yii::$app->response->statusCode = 405;
            return ['success' => false, 'error' => 'Method not allowed'];
        }

        $request = Yii::$app->request;
        $data = $request->post();
        if (empty($data)) {
            $data = json_decode($request->getRawBody(), true);
        }
        
        if (empty($data['return_no'])) {
            Yii::$app->response->statusCode = 400;
            return ['success' => false, 'error' => 'Return reference number parameter is required.'];
        }

        // 1. Fetch Master Return Record
        // (Assuming CustomerReturns is your ActiveRecord model name)
        $returnMaster = Returns::findOne(['return_no' => $data['return_no']]);
        if (!$returnMaster) {
            Yii::$app->response->statusCode = 404;
            return ['success' => false, 'error' => 'Customer return manifest record not found'];
        }

        // Guardrail: Prevent double stock accumulation if already finalized
        if ($returnMaster->status === 'approved' || $returnMaster->status === 'completed') {
            Yii::$app->response->statusCode = 400;
            return ['success' => false, 'error' => 'This return manifest has already been approved and finalized.'];
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            // Update parent record status parameters
            $returnMaster->status = $data['status'] ?? 'approved';
            $returnMaster->updated_by = $data['updated_by'] ?? null;
            $returnMaster->date_updated = date('Y-m-d H:i:s');

            if (!$returnMaster->save()) {
                $transaction->rollBack();
                return ['success' => false, 'errors' => $returnMaster->getErrors()];
            }

            // Fetch all item lines attached to this specific return event
            // (Assuming ReturnsItems is your ActiveRecord model name)
            $itemDatas = ReturnsItems::findAll(['return_id' => $returnMaster->id]);

            if (empty($itemDatas)) {
                $transaction->rollBack();
                return ['success' => false, 'error' => 'Cannot approve a return manifest with zero items.'];
            }

            foreach ($itemDatas as $itemData) {
                // Verify product exists in Master Inventory table
                $inventory = Inventory::findOne(['id' => $itemData['inventory_id']]);
                if (!$inventory) {
                    $transaction->rollBack();
                    return ['success' => false, 'error' => "Inventory Item ID {$itemData['inventory_id']} not found in product master."];
                }

                // Approve individual line item status flags
                $itemData->status = 'approved';        
                if (!$itemData->save()) {
                    $transaction->rollBack();
                    return ['success' => false, 'errors' => $itemData->getErrors()];
                }

                $qtyReturned = (int)$itemData['qty_returned'];

                // 2. CRITICAL MASTER UPDATE: Increment master inventory stock
                // This triggers your schema's generated stored columns (status_x, valuation etc.)
                $inventory->current_qty_x = new \yii\db\Expression('current_qty_x + :qty', [':qty' => $qtyReturned]);
                if (!$inventory->save(false)) { // save(false) safely skips validation constraints for raw DB expressions
                    $transaction->rollBack();
                    return ['success' => false, 'error' => "Failed to update master inventory metrics for '{$inventory->product_name}'"];
                }

                // 3. BATCH ROUTING: Returns use itemData['batch_id'] directly to look up layers
                $existingBatch = null;
                if (!empty($itemData['batch_id'])) {
                    $existingBatch = InventoryBatches::findOne([
                        'id' => $itemData['batch_id'],
                        'inventory_id' => $itemData['inventory_id']
                    ]);
                }

                if ($existingBatch) {
                    // Batch layer matched -> Safely update quantities using database expressions
                    $existingBatch->initial_qty = new \yii\db\Expression('initial_qty + :qty', [':qty' => $qtyReturned]);
                    $existingBatch->current_qty = new \yii\db\Expression('current_qty + :qty', [':qty' => $qtyReturned]);
                    
                    if (!$existingBatch->save(false)) {
                        $transaction->rollBack();
                        return ['success' => false, 'error' => "Failed to update existing batch layer for '{$inventory->product_name}'"];
                    }
                } else {
                    // FALLBACK: If batch tracking was bypassed or not found, look up by cost matching 
                    // to prevent throwing unexpected system errors, or generate a fresh return tier layer.
                    $costMatchBatch = InventoryBatches::findOne([
                        'inventory_id'  => $itemData['inventory_id'],
                        'cost_per_unit' => $itemData['unit_price'] // Falls back onto item selling unit price if cost layer isn't available
                    ]);

                    if ($costMatchBatch) {
                        $costMatchBatch->initial_qty = new \yii\db\Expression('initial_qty + :qty', [':qty' => $qtyReturned]);
                        $costMatchBatch->current_qty = new \yii\db\Expression('current_qty + :qty', [':qty' => $qtyReturned]);
                        $costMatchBatch->save(false);
                    } else {
                        // Create a brand new unique return batch row layer
                        $newBatch = new InventoryBatches();
                        $newBatch->inventory_id = $itemData['inventory_id'];
                        // Standard procedure: items returned use their original unit price for accounting base cost structures
                        $newBatch->cost_per_unit = $itemData['unit_price']; 
                        $newBatch->initial_qty = $qtyReturned;
                        $newBatch->current_qty = $qtyReturned;
                        
                        if (!$newBatch->save()) {
                            $transaction->rollBack();
                            return ['success' => false, 'errors' => $newBatch->getErrors()];
                        }
                    }
                }
            }

            // 4. Write Activity Metrics to System Audit Log
            Yii::$app->db->createCommand()->insert('audit_log', [
                'entity' => 'customer_returns',
                'entity_id' => $returnMaster->id,
                'action' => 'approve',
                'new_data' => json_encode([
                    'customer_returns' => $returnMaster->attributes,
                    'items' => array_map(function($item) { return $item->attributes; }, $itemDatas) 
                ]),
                'updated_by' => $returnMaster->updated_by,
                'updated_at' => date('Y-m-d H:i:s'),
            ])->execute();

            $transaction->commit();
            return [
                'success' => true,
                'message' => 'Customer return manifest approved and stock metrics restocked successfully.',
                'id' => $returnMaster->id
            ];

        } catch (\Exception $e) {
            $transaction->rollBack();
            Yii::$app->response->statusCode = 500;
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function actionGeneratetrnxno()
    {   
        $prefix = 'RTN';
        $date = date('ymd');
        $count = Returns::find()->orderBy(['id' => SORT_DESC])->limit(1)->one();
        $lastId = Yii::$app->db->createCommand("SELECT MAX(id) FROM returns")->queryScalar();
        $lastId = str_pad($lastId + 1, 4, '0', STR_PAD_LEFT);

        return [
            'success' => true,
            'trnxno' => $prefix . $date . $lastId,
        ];
    }

    public function actionView($id)
    {
        if (Yii::$app->request->method !== 'GET') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $returns = Returns::find()
            ->where(['id' => $id])
            ->asArray()
            ->one();

        if (!$returns) {
            Yii::$app->response->statusCode = 404;
            return ['success' => false, 'error' => 'Returns transaction not found'];
        }

        // 🌟 Subquery to calculate total live stock across all batches for the product
        $batchQtySubquery = (new \yii\db\Query())
            ->select('SUM(current_qty)')
            ->from('inventory_batches')
            ->where('inventory_id = returns_items.inventory_id');

        $items = ReturnsItems::find()
            ->select([
                'returns_items.*',
                'product_name'  => 'inventory.product_name',
                'sku'           => 'inventory.sku',
                'reorder_level' => 'inventory.reorder_level',
                // 🔥 This replaces the hardcoded 0 with the live total count from your batches
                'current_qty'   => $batchQtySubquery, 
            ])
            ->leftJoin('inventory', 'inventory.id = returns_items.inventory_id')
            ->where(['return_id' => $id])
            ->asArray()
            ->all();

        // Typecast current_qty elements to integers since SQL SUM() returns strings/floats
        foreach ($items as &$item) {
            $item['current_qty'] = isset($item['current_qty']) ? (int)$item['current_qty'] : 0;
        }
        unset($item); // Break reference pointer link safety wrapper

        $returns['items'] = $items;

        return [
            'success' => true,
            'data' => $returns
        ];
    }
}