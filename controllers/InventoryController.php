<?php

namespace app\controllers;

use Yii;
use yii\rest\Controller;
use yii\filters\auth\HttpBearerAuth;
use app\models\Inventory;
use app\models\Employee; 

/**
 * Inventory API Controller
 * Handles CRUD operations for inventory items
 */
class InventoryController extends Controller
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

    /**
     * List all inventory items - GET /api/inventory/list
     */
    public function actionList()
    {
        if (Yii::$app->request->method !== 'GET') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $request = Yii::$app->request;
        $query = Inventory::find()
            ->select([
                'inventory.*',
                // 'date_received' => 'b.date_received',
                'SUM(COALESCE(b.current_qty, 0)) AS current_qty', 
                'total_inventory_cost' => 'SUM(COALESCE(b.current_qty, 0) * COALESCE(b.cost_per_unit, 0))',
                'SUM(COALESCE(b.current_qty, 0) * COALESCE(inventory.price_per_unit, 0)) AS total_inventory_value',
                'CASE 
                    WHEN SUM(COALESCE(b.current_qty, 0)) = 0 THEN "No Stock" 
                    WHEN SUM(COALESCE(b.current_qty, 0)) < MIN(inventory.reorder_level) THEN "Low Stock" 
                    ELSE "In Stock" 
                END AS status'
            ])
            ->leftJoin('inventory_batches b', 'inventory.id = b.inventory_id')
            ->groupBy(['inventory.id']);

        $search = $request->get('search');
        if (!empty($search)) {
            $query->andFilterWhere(['like', 'product_name', $search])
                // ->orFilterWhere(['like', 'cost_per_unit', $search])
                ->orFilterWhere(['like', 'price_per_unit', $search])
                // ->orFilterWhere(['like', 'total_inventory_cost', $search])
                // ->orFilterWhere(['like', 'total_inventory_value', $search])
                ->orFilterWhere(['like', 'total_sold', $search])
                ->orFilterWhere(['like', 'sku', $search])
                ->orFilterWhere(['like', 'type', $search]);
                // ->orFilterWhere(['like', 'status', $search])
        }

        // 🎯 Filters
        $filters = [
            'type' => $request->get('type') === 'all' ? '' : $request->get('type'),
            'rack' => $request->get('rack'),
            'shelf' => $request->get('shelf'),
            'box' => $request->get('box'),
            // 'status' => $request->get('status'),
            'record_status' => $request->get('record_status'),
        ];
        foreach ($filters as $field => $value) {
            if (!empty($value)) {
                $query->andWhere([$field => $value]);
            }
        }

        $filters = [
            'status' => $request->get('status'),
        ];
        foreach ($filters as $field => $value) {
            if (!empty($value)) {
                $query->andHaving([$field => $value]);
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
            'id', 'product_name', 'sku', 'cost_per_unit', 'price_per_unit',
            'current_qty', 'total_inventory_cost', 'total_inventory_value',
            'date_created', 'date_updated', 'tae'
        ];
        if (in_array($sortField, $allowedSortFields)) {
            $query->orderBy([$sortField => $sortOrder]);
        } else {
            $query->orderBy(['id' => SORT_DESC]);
        }

        // Execute query
        $totalCount = $query->count();
        $items = $query->offset($offset)->limit($pageSize)->asArray()->all();

        $query_summary = Inventory::find()
            ->select([
                // 'SUM(COALESCE(b.current_qty, 0)) AS total_items', 
                'COUNT(*) AS total_product_count', 
                'SUM(COALESCE(b.current_qty, 0) * COALESCE(b.cost_per_unit, 0)) AS total_inventory_cost',
                'SUM(COALESCE(b.current_qty, 0) * COALESCE(inventory.price_per_unit, 0)) AS total_inventory_value',
                'SUM(CASE WHEN COALESCE(b.current_qty, 0) = 0 THEN 1 ELSE 0 END) AS no_stock',
                'SUM(CASE WHEN (COALESCE(b.current_qty, 0) < inventory.reorder_level AND COALESCE(b.current_qty, 0) > 0) THEN 1 ELSE 0 END) AS low_stock',
                'SUM(CASE WHEN (COALESCE(b.current_qty, 0) >= inventory.reorder_level AND COALESCE(b.current_qty, 0) > 0) THEN 1 ELSE 0 END) AS in_stock',
            ])
            ->leftJoin('inventory_batches b', 'inventory.id = b.inventory_id');
        $summary = $query_summary->asArray()->one();

        return [
            'success' => true,
            'totalProductCount' => $summary['total_product_count'],
            'totalInventoryCost' => $summary['total_inventory_cost'],
            'totalInventoryValue' => $summary['total_inventory_value'],
            'totalNoStock' => (float) $summary['no_stock'],
            'totalLowStock' => (float) $summary['low_stock'],
            'totalInStock' => (float) $summary['in_stock'],
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

    public function actionTablelistsearch()
    {
        if (Yii::$app->request->method !== 'GET') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $request = Yii::$app->request;
        $query = Inventory::find()
            ->select([
                'inventory.*',
                // 'date_received' => 'b.date_received',
                'SUM(COALESCE(b.current_qty, 0)) AS current_qty', 
                'SUM(COALESCE(b.current_qty, 0) * COALESCE(b.cost_per_unit, 0)) AS total_inventory_cost',
                'SUM(COALESCE(b.current_qty, 0) * COALESCE(inventory.price_per_unit, 0)) AS total_inventory_value',
                'CASE 
                    WHEN SUM(COALESCE(b.current_qty, 0)) = 0 THEN "No Stock" 
                    WHEN SUM(COALESCE(b.current_qty, 0)) < MIN(inventory.reorder_level) THEN "Low Stock" 
                    ELSE "In Stock" 
                END AS status'
            ])
            ->leftJoin('inventory_batches b', 'inventory.id = b.inventory_id')
            ->groupBy(['inventory.id']);

        $search = $request->get('search');
        if (!empty($search)) {
            $query->andFilterWhere(['like', 'product_name', $search])
                ->orFilterWhere(['like', 'cost_per_unit', $search])
                ->orFilterWhere(['like', 'price_per_unit', $search])
                ->orFilterWhere(['like', 'total_inventory_cost', $search])
                ->orFilterWhere(['like', 'total_inventory_value', $search])
                ->orFilterWhere(['like', 'total_sold', $search])
                ->orFilterWhere(['like', 'sku', $search])
                ->orFilterWhere(['like', 'type', $search])
                ->orFilterWhere(['like', 'status', $search]);
        }

        // 🎯 Filters
        $filters = [
            'type' => $request->get('type'),
            'rack' => $request->get('rack'),
            'shelf' => $request->get('shelf'),
            'box' => $request->get('box'),
            // 'status' => $request->get('status'),
            'record_status' => $request->get('record_status'),
        ];
        foreach ($filters as $field => $value) {
            if (!empty($value)) {
                $query->andWhere([$field => $value]);
            }
        }

        $filters = [
            'status' => $request->get('status'),
        ];
        foreach ($filters as $field => $value) {
            if (!empty($value)) {
                $query->andHaving([$field => $value]);
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
            'id', 'product_name', 'sku', 'cost_per_unit', 'price_per_unit',
            'current_qty', 'total_inventory_cost', 'total_inventory_value',
            'date_created', 'date_updated', 'tae'
        ];
        if (in_array($sortField, $allowedSortFields)) {
            $query->orderBy([$sortField => $sortOrder]);
        } else {
            $query->orderBy(['id' => SORT_DESC]);
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

    public function actionListsearch()
    {
        if (Yii::$app->request->method !== 'GET') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $request = Yii::$app->request;
        $query = Inventory::find();

        $search = $request->get('search');
        if (!empty($search)) {
            $query->andFilterWhere(['like', 'product_name', $search])
                ->orFilterWhere(['like', 'cost_per_unit', $search])
                ->orFilterWhere(['like', 'price_per_unit', $search])
                ->orFilterWhere(['like', 'total_inventory_cost', $search])
                ->orFilterWhere(['like', 'total_inventory_value', $search])
                ->orFilterWhere(['like', 'total_sold', $search])
                ->orFilterWhere(['like', 'sku', $search])
                // ->orFilterWhere(['like', 'type', $search])
                ->orFilterWhere(['like', 'status', $search]);
        }

        // 🎯 Filters
        $filters = [
            'type' => $request->get('type'),
            'rack' => $request->get('rack'),
            'shelf' => $request->get('shelf'),
            'box' => $request->get('box'),
            'status' => $request->get('status'),
            'record_status' => $request->get('record_status'),
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
            'id', 'product_name', 'sku', 'cost_per_unit', 'price_per_unit',
            'current_qty', 'total_inventory_cost', 'total_inventory_value',
            'date_created', 'date_updated', 'reoder_level'
        ];
        if (in_array($sortField, $allowedSortFields)) {
            $query->orderBy([$sortField => $sortOrder]);
        } else {
            $query->orderBy(['id' => SORT_DESC]);
        }

        // Execute query
        $totalCount = $query->count();
        $items = $query->offset($offset)->limit($pageSize)->all();

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

    public function actionReplenishmentlistsearchOld()
    {
        if (Yii::$app->request->method !== 'GET') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $request = Yii::$app->request;
        $query = Inventory::find();

        $search = $request->get('search');
        if (!empty($search)) {
            $query->andFilterWhere(['like', 'product_name', $search])
                ->orFilterWhere(['like', 'cost_per_unit', $search])
                ->orFilterWhere(['like', 'price_per_unit', $search])
                ->orFilterWhere(['like', 'total_sold', $search])
                ->orFilterWhere(['like', 'sku', $search])
                // ->orFilterWhere(['like', 'type', $search])
                ->orFilterWhere(['like', 'status', $search]);
        }

        // 🎯 Filters
        $filters = [
            'type' => $request->get('type'),
            'rack' => $request->get('rack'),
            'shelf' => $request->get('shelf'),
            'box' => $request->get('box'),
            'status' => $request->get('status'),
            'record_status' => $request->get('record_status'),
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
            'id', 'product_name', 'sku', 'cost_per_unit', 'price_per_unit',
            'current_qty', 'total_inventory_cost', 'total_inventory_value',
            'date_created', 'date_updated', 'reoder_level'
        ];
        if (in_array($sortField, $allowedSortFields)) {
            $query->orderBy([$sortField => $sortOrder]);
        } else {
            $query->orderBy(['id' => SORT_DESC]);
        }

        // Execute query
        $totalCount = $query->count();
        $items = $query->offset($offset)->limit($pageSize)->all();

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

    public function actionReplenishmentlistsearch()
    {
        if (Yii::$app->request->method !== 'GET') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $request = Yii::$app->request;
        
        // 1. Point the query to base inventory table with a strict alias
        $query = Inventory::find()
            ->alias('i')
            ->select([
                'i.*', // All static product master information
                'SUM(COALESCE(b.current_qty, 0)) AS current_qty',
                'SUM(COALESCE(b.current_qty * b.cost_per_unit, 0)) AS total_inventory_cost',
                'SUM(COALESCE(b.current_qty * i.price_per_unit, 0)) AS total_inventory_value',
                // Dynamically recalculate status string based on live aggregate quantities
                // '(CASE 
                //     WHEN SUM(COALESCE(b.current_qty, 0)) = 0 THEN "No Stock"
                //     WHEN SUM(COALESCE(b.current_qty, 0)) < i.reorder_level THEN "Low Stock"
                //     ELSE "In Stock"
                //  END) AS status'
            ])
            ->leftJoin('inventory_batches b', 'i.id = b.inventory_id')
            ->groupBy('i.id');

        // 🔍 Global Text Search
        $search = $request->get('search');
        if (!empty($search)) {
            // Static table definitions go into WHERE; dynamic expressions go into HAVING
            $query->andFilterWhere(['like', 'i.product_name', $search])
                  ->orFilterWhere(['like', 'i.sku', $search])
                  ->orFilterWhere(['like', 'i.price_per_unit', $search])
                  ->orFilterWhere(['like', 'i.total_sold', $search]);
                  // ->orHaving(['like', 'status', $search])
                  // ->orHaving(['like', 'current_qty', $search])
                  // ->orHaving(['like', 'total_inventory_cost', $search])
                  // ->orHaving(['like', 'total_inventory_value', $search]);
        }

        // 🎯 Structured Sidebar/Dropdown Filters
        $filters = [
            'i.type' => $request->get('type'),
            'i.rack' => $request->get('rack'),
            'i.shelf' => $request->get('shelf'),
            'i.box' => $request->get('box'),
            // 'i.record_status' => $request->get('record_status'),
        ];
        foreach ($filters as $field => $value) {
            if (!empty($value)) {
                $query->andWhere([$field => $value]);
            }
        }

        // Aggregate values require a HAVING wrapper condition
        $statusFilter = $request->get('status');
        if (!empty($statusFilter)) {
            $query->andHaving(['status' => $statusFilter]);
        }

        // 📄 Pagination (Wrapped cleanly in a subquery count to support our GROUP BY rule)
        $page = (int)$request->get('page', 1);
        $pageSize = (int)$request->get('pageSize', 10);
        $offset = ($page - 1) * $pageSize;

        $totalCount = (int)(new \yii\db\Query())
            ->from(['sub' => $query])
            ->count('*', Yii::$app->db);

        // 📊 Sorting Configuration (Mapping parameters safely to explicit aliases)
        $sortField = $request->get('sort', 'id');
        $sortOrder = strtolower($request->get('order', 'asc')) === 'desc' ? SORT_DESC : SORT_ASC;

        $allowedSortFields = [
            'id'                    => 'i.id', 
            'product_name'          => 'i.product_name', 
            'sku'                   => 'i.sku', 
            'price_per_unit'        => 'i.price_per_unit',
            'date_created'          => 'i.date_created', 
            'date_updated'          => 'i.date_updated', 
            'reorder_level'         => 'i.reorder_level',
            'current_qty'           => 'current_qty', 
            'total_inventory_cost'  => 'total_inventory_cost',
            'total_inventory_value' => 'total_inventory_value',
            // 'status'                => 'status'
        ];

        if (array_key_exists($sortField, $allowedSortFields)) {
            $query->orderBy([$allowedSortFields[$sortField] => $sortOrder]);
        } else {
            $query->orderBy(['i.id' => SORT_DESC]);
        }

        // 🚀 .asArray() captures the computed aliases cleanly into JSON object attributes
        $items = $query->offset($offset)->limit($pageSize)->asArray()->all();

        // Clean up raw numeric values for JSON formatting
        foreach ($items as &$item) {
            $item['current_qty'] = (int)$item['current_qty'];
        }
        unset($item);

        return [
            'success'    => true,
            'page'       => $page,
            'pageSize'   => $pageSize,
            'totalCount' => $totalCount,
            'totalPages' => ceil($totalCount / $pageSize),
            'sortField'  => $sortField,
            'sortOrder'  => $sortOrder === SORT_ASC ? 'asc' : 'desc',
            'count'      => count($items),
            'data'       => $items,
        ];
    }

    public function actionBatcheslistsearchOld()
    {
        if (Yii::$app->request->method !== 'GET') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $request = Yii::$app->request;

        // 1. Core query: One row per batch. Do NOT use groupBy('i.id') here.
        $query = Inventory::find()
            ->alias('i')
            ->select([
                'i.id AS inventory_id',
                'i.product_name',
                'i.sku',
                'i.price_per_unit',
                'i.reorder_level',
                'i.type',
                'i.rack',
                'i.shelf',
                'i.box',
                'b.id AS batch_id', // 🌟 CRITICAL: Exposed to know which batch is selected
                'COALESCE(b.cost_per_unit, 0.00) AS cost_per_unit', // 🌟 The cost of this specific batch
                'COALESCE(b.current_qty, 0) AS current_qty',       // 🌟 The stock left in this specific batch
                'b.date_received',
                // Status computed per batch line or for the item if no batch exists
                '(CASE 
                    WHEN b.id IS NULL OR b.current_qty = 0 THEN "No Stock"
                    ELSE "In Stock"
                 END) AS batch_status'
            ])
            ->leftJoin('inventory_batches b', 'i.id = b.inventory_id');
        // 🔍 Global Text Search (Filters standard columns)
        $search = $request->get('search');
        if (!empty($search)) {
            $query->andFilterWhere([
                'or',
                ['like', 'i.product_name', $search],
                ['like', 'i.sku', $search],
                ['like', 'b.cost_per_unit', $search],
                ['like', 'i.price_per_unit', $search]
            ]);
        }

        // 🎯 Structured Filters (Dropdown selectors)
        $filters = [
            'i.type' => $request->get('type'),
            'i.rack' => $request->get('rack'),
            'i.shelf' => $request->get('shelf'),
            'i.box' => $request->get('box'),
            'i.record_status' => $request->get('record_status', 'Active'),
        ];
        foreach ($filters as $field => $value) {
            if (!empty($value)) {
                $query->andWhere([$field => $value]);
            }
        }

        // Custom status filter handling
        $statusFilter = $request->get('status');
        if (!empty($statusFilter)) {
            if ($statusFilter === 'No Stock') {
                $query->andWhere(['or', ['b.id' => null], ['b.current_qty' => 0]]);
            } elseif ($statusFilter === 'In Stock') {
                $query->andWhere(['>', 'b.current_qty', 0]);
            }
        }

        // 📄 Pagination
        $page = (int)$request->get('page', 1);
        $pageSize = (int)$request->get('pageSize', 10);
        $offset = ($page - 1) * $pageSize;

        // Simple count handles this perfectly now since GROUP BY was removed
        $totalCount = (int)$query->count();

        // 📊 Sorting (Prioritize listing actual batch entries first)
        $sortField = $request->get('sort', 'product_name');
        $sortOrder = strtolower($request->get('order', 'asc')) === 'desc' ? SORT_DESC : SORT_ASC;

        $allowedSortFields = [
            'product_name' => 'i.product_name',
            'sku' => 'i.sku',
            'cost_per_unit' => 'b.cost_per_unit',
            'current_qty' => 'b.current_qty',
            'date_received' => 'b.date_received'
        ];

        if (array_key_exists($sortField, $allowedSortFields)) {
            $query->orderBy([$allowedSortFields[$sortField] => $sortOrder]);
        } else {
            // Default sorting: Group identical items together, oldest received batches first
            $query->orderBy(['i.product_name' => SORT_ASC, 'b.date_received' => SORT_ASC]);
        }

        // 🚀 Execute and pull raw dictionary array
        $items = $query->offset($offset)->limit($pageSize)->asArray()->all();

        return [
            'success' => true,
            'page' => $page,
            'pageSize' => $pageSize,
            'totalCount' => $totalCount,
            'totalPages' => ceil($totalCount / $pageSize),
            'count' => count($items),
            'data' => $items,
        ];
    }

    public function actionBatcheslistsearchOld2()
    {
        if (Yii::$app->request->method !== 'GET') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $request = Yii::$app->request;

        // 1. Core query: Grouped by product to return exactly ONE row per unique item
        $query = Inventory::find()
            ->alias('i')
            ->select([
                'i.id AS inventory_id',
                'i.product_name',
                'i.sku',
                'i.price_per_unit', // Selling Price
                'i.reorder_level',
                'i.type',
                'i.rack',
                'i.shelf',
                'i.box',
                'SUM(COALESCE(b.current_qty, 0)) AS current_qty',        // 📊 Combined total stock across all active batches
                'MAX(COALESCE(b.cost_per_unit, 0.00)) AS cost_per_unit', // 🌟 Reference baseline cost (Displays highest batch cost)
                // Combined global product status
                '(CASE 
                    WHEN SUM(COALESCE(b.current_qty, 0)) > 0 THEN "In Stock"
                    ELSE "No Stock"
                 END) AS batch_status'
            ])
            ->leftJoin('inventory_batches b', 'i.id = b.inventory_id')
            ->groupBy([
                'i.id', 'i.product_name', 'i.sku', 'i.price_per_unit', 
                'i.reorder_level', 'i.type', 'i.rack', 'i.shelf', 'i.box'
            ]);

        // 🔍 Global Text Search (Filters standard columns)
        $search = $request->get('search');
        if (!empty($search)) {
            $query->andFilterWhere([
                'or',
                ['like', 'i.product_name', $search],
                ['like', 'i.sku', $search],
                ['like', 'b.cost_per_unit', $search] // Matches if any underlying batch hits this cost threshold
            ]);
        }

        // 🎯 Structured Filters (Dropdown selectors)
        $filters = [
            'i.type' => $request->get('type'),
            'i.rack' => $request->get('rack'),
            'i.shelf' => $request->get('shelf'),
            'i.box' => $request->get('box'),
            'i.record_status' => $request->get('record_status', 'Active'),
        ];
        foreach ($filters as $field => $value) {
            if (!empty($value)) {
                $query->andWhere([$field => $value]);
            }
        }

        // 🎯 Status Filter handling (Switched to HAVING since it targets aggregated values)
        $statusFilter = $request->get('status');
        if (!empty($statusFilter)) {
            if ($statusFilter === 'No Stock') {
                $query->having(['or', ['SUM(COALESCE(b.current_qty, 0))' => 0], ['SUM(COALESCE(b.current_qty, 0))' => null]]);
            } elseif ($statusFilter === 'In Stock') {
                $query->having(['>', 'SUM(COALESCE(b.current_qty, 0))', 0]);
            }
        }

        // 📄 Pagination Setup
        $page = (int)$request->get('page', 1);
        $pageSize = (int)$request->get('pageSize', 10);
        $offset = ($page - 1) * $pageSize;

        // 🧮 Subquery Count: This wraps your grouped query block and counts total rows flawlessly,
        // avoiding the common Yii2 SQL errors caused by running ->count() directly on a GROUP BY.
        $totalCount = (int)(new \yii\db\Query())
            ->from(['sub' => $query])
            ->count('*', Yii::$app->db);

        // 📊 Sorting Configuration
        $sortField = $request->get('sort', 'product_name');
        $sortOrder = strtolower($request->get('order', 'asc')) === 'desc' ? SORT_DESC : SORT_ASC;

        $allowedSortFields = [
            'product_name' => 'i.product_name',
            'sku' => 'i.sku',
            'cost_per_unit' => 'MAX(b.cost_per_unit)',
            'current_qty' => 'SUM(COALESCE(b.current_qty, 0))'
        ];

        if (array_key_exists($sortField, $allowedSortFields)) {
            $query->orderBy([$allowedSortFields[$sortField] => $sortOrder]);
        } else {
            $query->orderBy(['i.product_name' => SORT_ASC]);
        }

        // 🚀 Execute and pull raw dictionary payload
        $items = $query->offset($offset)->limit($pageSize)->asArray()->all();

        return [
            'success' => true,
            'page' => $page,
            'pageSize' => $pageSize,
            'totalCount' => $totalCount,
            'totalPages' => ceil($totalCount / $pageSize),
            'count' => count($items),
            'data' => $items,
        ];
    }

    public function actionBatcheslistsearch()
    {
        if (Yii::$app->request->method !== 'GET') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $request = Yii::$app->request;

        // 1. Core query: Grouped by product to return exactly ONE row per unique item
        $query = Inventory::find()
            ->alias('i')
            ->select([
                'i.id AS inventory_id',
                'i.product_name',
                'i.sku',
                'i.price_per_unit', // Selling Price
                'i.reorder_level',
                'i.type',
                'i.rack',
                'i.shelf',
                'i.box',
                'i.tracking_method',
                'SUM(COALESCE(b.current_qty, 0)) AS current_qty',        // Combined total stock across all active batches
                // 'MAX(COALESCE(b.cost_per_unit, 0.00)) AS cost_per_unit', // Reference baseline cost (Displays highest batch cost)
                '(CASE 
                    WHEN i.tracking_method = "standard" THEN i.cost_per_unit
                    ELSE MAX(COALESCE(b.cost_per_unit, 0.00))
                END) AS cost_per_unit',
                // Combined global product status
                '(CASE 
                    WHEN SUM(COALESCE(b.current_qty, 0)) > 0 THEN "In Stock"
                    ELSE "No Stock"
                 END) AS batch_status'
            ])
            ->leftJoin('inventory_batches b', 'i.id = b.inventory_id')
            ->groupBy([
                'i.id', 'i.product_name', 'i.sku', 'i.price_per_unit', 
                'i.reorder_level', 'i.type', 'i.rack', 'i.shelf', 'i.box'
            ]);

        // 🔍 Global Text Search (Filters standard columns)
        $search = $request->get('search');
        if (!empty($search)) {
            $query->andFilterWhere([
                'or',
                ['like', 'i.product_name', $search],
                ['like', 'i.sku', $search],
                ['like', 'b.cost_per_unit', $search], // Matches if any underlying batch hits this cost threshold,
                ['like', 'i.price_per_unit', $search]
            ]);
        }

        // 🎯 Structured Filters (Dropdown selectors)
        $filters = [
            'i.type' => $request->get('type'),
            'i.rack' => $request->get('rack'),
            'i.shelf' => $request->get('shelf'),
            'i.box' => $request->get('box'),
            'i.record_status' => $request->get('record_status', 'Active'),
        ];
        foreach ($filters as $field => $value) {
            if (!empty($value)) {
                $query->andWhere([$field => $value]);
            }
        }

        // 🎯 Status Filter handling (Switched to HAVING since it targets aggregated values)
        $statusFilter = $request->get('status');
        if (!empty($statusFilter)) {
            if ($statusFilter === 'No Stock') {
                $query->having(['or', ['SUM(COALESCE(b.current_qty, 0))' => 0], ['SUM(COALESCE(b.current_qty, 0))' => null]]);
            } elseif ($statusFilter === 'In Stock') {
                $query->having(['>', 'SUM(COALESCE(b.current_qty, 0))', 0]);
            }
        }

        // 📄 Pagination Setup
        $page = (int)$request->get('page', 1);
        $pageSize = (int)$request->get('pageSize', 10);
        $offset = ($page - 1) * $pageSize;

        // 🧮 Subquery Count
        $totalCount = (int)(new \yii\db\Query())
            ->from(['sub' => $query])
            ->count('*', Yii::$app->db);

        // 📊 Sorting Configuration
        $sortField = $request->get('sort', 'product_name');
        $sortOrder = strtolower($request->get('order', 'asc')) === 'desc' ? SORT_DESC : SORT_ASC;

        $allowedSortFields = [
            'product_name' => 'i.product_name',
            'sku' => 'i.sku',
            'cost_per_unit' => 'MAX(b.cost_per_unit)',
            'current_qty' => 'SUM(COALESCE(b.current_qty, 0))'
        ];

        if (array_key_exists($sortField, $allowedSortFields)) {
            $query->orderBy([$allowedSortFields[$sortField] => $sortOrder]);
        } else {
            $query->orderBy(['i.product_name' => SORT_ASC]);
        }

        // 🚀 Execute and pull raw dictionary payload for the single page
        $items = $query->offset($offset)->limit($pageSize)->asArray()->all();

        // 📦 NEW: Batch Injection Logic
        if (!empty($items)) {
            // Extract only the inventory IDs present on this specific page
            $inventoryIds = array_column($items, 'inventory_id');

            // Fetch all active batches for these items ordered by FIFO (oldest first)
            $rawBatches = (new \yii\db\Query())
                ->select([
                    'id AS batch_id',
                    'inventory_id',
                    'cost_per_unit',
                    'initial_qty',
                    'current_qty',
                    'date_received'
                ])
                ->from('inventory_batches')
                ->where(['inventory_id' => $inventoryIds])
                ->andWhere(['>', 'current_qty', 0]) // Only fetch batches that actually have stock to sell
                ->orderBy(['date_received' => SORT_ASC]) // FIFO sorting structure
                ->all();

            // Group the batches by their parent inventory_id
            $groupedBatches = [];
            foreach ($rawBatches as $batch) {
                $groupedBatches[$batch['inventory_id']][] = [
                    'batch_id' => (int)$batch['batch_id'],
                    'cost_per_unit' => (float)$batch['cost_per_unit'],
                    'initial_qty' => (int)$batch['initial_qty'],
                    'current_qty' => (int)$batch['current_qty'],
                    'date_received' => $batch['date_received']
                ];
            }

            // Map the grouped batches back into our primary items list
            foreach ($items as &$item) {
                $id = $item['inventory_id'];
                $item['batches'] = isset($groupedBatches[$id]) ? $groupedBatches[$id] : [];
            }
            unset($item); // Break reference safety pointer
        }

        return [
            'success' => true,
            'page' => $page,
            'pageSize' => $pageSize,
            'totalCount' => $totalCount,
            'totalPages' => ceil($totalCount / $pageSize),
            'count' => count($items),
            'data' => $items,
        ];
    }

    /**
     * View single item - GET /api/inventory/view?id=123
     */
    public function actionView($id)
    {
        $item = Inventory::findOne($id);
        if (!$item) {
            Yii::$app->response->statusCode = 404;
            return ['error' => 'Item not found'];
        }
        return ['success' => true, 'data' => $item];
    }

    /**
     * Create new item - POST /api/inventory/create
     */
    public function actionCreate()
    {
        if (Yii::$app->request->method !== 'POST') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $item = new Inventory();
        $item->load(Yii::$app->request->post(), '');

        if (!$item->validate()) {
            Yii::$app->response->statusCode = 422;
            return ['error' => 'Validation failed', 'errors' => $item->errors];
        }

        if (empty($item->sku)) {
            // Generate SKU
            $initials = '';// Get the initials from the product name
            $words = explode(' ', $item->product_name);
            foreach ($words as $word) {
                if (!empty($word)) {
                    $initials .= strtoupper(substr($word, 0, 1)); // Get the first letter of each word
                }
            }
            $month = date('m'); // Get the current month
            $year = date('y'); // Get the current year
            $lastId = Inventory::find()
                ->select('id')
                ->orderBy(['id' => SORT_DESC])
                ->limit(1)
                ->scalar();

            if ($lastId) {
                $lastId = substr($lastId, -5); // Extract the last 5 digits of the ID
            } else {
                $lastId = '00000'; // Set a default value for the first record
            }
            $sku = strtoupper($initials) . '-' . $month . $year . '-' . str_pad($lastId + 1, 5, '0', STR_PAD_LEFT);
            $item->sku = $sku;
        }

        if (!$item->save()) {
            Yii::$app->response->statusCode = 500;
            return ['error' => 'Failed to save item'];
        }

        // ✅ Insert into audit log after successful update
        Yii::$app->db->createCommand()->insert('audit_log', [
            'entity' => 'inventory',
            'entity_id' => $item->id,
            'action' => 'create',
            'new_data' => json_encode($item->attributes),
            'updated_by' => $item->added_by,
            'updated_at' => date('Y-m-d H:i:s'),
        ])->execute();

        return ['success' => true, 'data' => $item];
    }

    /**
     * Update item - PUT /api/inventory/update
     */
    public function actionUpdate()
    {
        if (Yii::$app->request->method !== 'PUT' && Yii::$app->request->method !== 'PATCH') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $id = Yii::$app->request->getBodyParam('id');
        $item = Inventory::findOne($id);
        if (!$item) {
            Yii::$app->response->statusCode = 404;
            return ['error' => 'Item not found'];
        }

        $oldData = $item->attributes; // Capture old values before update

        $item->load(Yii::$app->request->post(), '');

        if (!$item->validate()) {
            Yii::$app->response->statusCode = 422;
            return ['error' => 'Validation failed', 'errors' => $item->errors];
        }

        if (empty($item->sku)) {
            // Generate SKU
            $initials = '';// Get the initials from the product name
            $words = explode(' ', $item->product_name);
            foreach ($words as $word) {
                if (!empty($word)) {
                    $initials .= strtoupper(substr($word, 0, 1)); // Get the first letter of each word
                }
            }
            $month = date('m'); // Get the current month
            $year = date('y'); // Get the current year
            $lastId = Inventory::find()
                ->select('id')
                ->orderBy(['id' => SORT_DESC])
                ->limit(1)
                ->scalar();

            if ($lastId) {
                $lastId = substr($lastId, -5); // Extract the last 5 digits of the ID
            } else {
                $lastId = '00000'; // Set a default value for the first record
            }
            $sku = strtoupper($initials) . '-' . $month . $year . '-' . str_pad($lastId + 1, 5, '0', STR_PAD_LEFT);
            $item->sku = $sku;
        }

        if (!$item->save()) {
            Yii::$app->response->statusCode = 500;
            return ['error' => 'Failed to save item'];
        }

        // ✅ Insert into audit log after successful update
        Yii::$app->db->createCommand()->insert('audit_log', [
            'entity' => 'inventory',
            'entity_id' => $item->id,
            'action' => 'update',
            'old_data' => json_encode($oldData),
            'new_data' => json_encode($item->attributes),
            'updated_by' => $item->updated_by,
            'updated_at' => date('Y-m-d H:i:s'),
        ])->execute();

        return ['success' => true, 'data' => $item];
    }

    /**
     * Delete item - DELETE /api/inventory/delete
     */
    public function actionDelete()
    {
        if (Yii::$app->request->method !== 'DELETE') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $id = Yii::$app->request->getBodyParam('id');
        $item = Inventory::findOne($id);
        $employee_id = Yii::$app->request->getBodyParam('employee_id');

        if (!$item) {
            Yii::$app->response->statusCode = 404;
            return ['error' => 'Item not found'];
        }

        $oldData = $item->attributes; // Capture data before deletion

        if (!$item->delete()) {
            Yii::$app->response->statusCode = 500;
            return ['error' => 'Failed to delete item'];
        }    

        // ✅ Insert into audit log after successful delete
        Yii::$app->db->createCommand()->insert('audit_log', [
            'entity' => 'inventory',
            'entity_id' => $id,
            'action' => 'delete',
            'old_data' => json_encode($oldData),
            'new_data' => null,
            'updated_by' => $employee_id,
            'updated_at' => date('Y-m-d H:i:s'),
        ])->execute();

        return ['success' => true, 'message' => 'Item deleted successfully'];
    }

    public function actionChecksku()
    {
        if (Yii::$app->request->method !== 'POST') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $sku = Yii::$app->request->post('sku');
        $existingItem = Inventory::find()->where(['sku' => $sku])->one();

        if ($existingItem) {
            return ['exists' => true];
        } else {
            return ['exists' => false];
        }
    }

    public function actionGetsummary()
    {
        if (Yii::$app->request->method !== 'GET') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }
    }
}
