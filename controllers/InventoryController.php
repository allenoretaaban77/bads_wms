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
        $query = Inventory::find();

        // 🔍 Search (product_name or SKU)
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
            'total_sold', 'date_created', 'date_updated'
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
        $sku = strtoupper($initials) . '-' . $month . $year . '-' . str_pad($lastId, 5, '0', STR_PAD_LEFT);
        $item->sku = $sku;

        if (!$item->validate()) {
            Yii::$app->response->statusCode = 422;
            return ['error' => 'Validation failed', 'errors' => $item->errors];
        }

        $item->status = $item->current_qty == 0 ? 'No Stock' : ($item->current_qty < $item->reorder_level ? 'Low Stock' : 'In Stock');
        $item->total_inventory_cost = $item->cost_per_unit * $item->current_qty;
        $item->total_inventory_value = $item->price_per_unit * $item->current_qty;

        if (!$item->save()) {
            Yii::$app->response->statusCode = 500;
            return ['error' => 'Failed to save item'];
        }

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

        $item->load(Yii::$app->request->post(), '');

        // Generate SKU
        $initials = ''; // Get the initials from the product name
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
        $sku = strtoupper($initials) . '-' . $month . $year . '-' . str_pad($lastId, 5, '0', STR_PAD_LEFT);
        $item->sku = $sku;

        if (!$item->validate()) {
            Yii::$app->response->statusCode = 422;
            return ['error' => 'Validation failed', 'errors' => $item->errors];
        }

        $item->status = $item->current_qty == 0 ? 'No Stock' : ($item->current_qty < $item->reorder_level ? 'Low Stock' : 'In Stock');
        $item->total_inventory_cost = $item->cost_per_unit * $item->current_qty;
        $item->total_inventory_value = $item->price_per_unit * $item->current_qty;

        if (!$item->save()) {
            Yii::$app->response->statusCode = 500;
            return ['error' => 'Failed to save item'];
        }

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

        if (!$item) {
            Yii::$app->response->statusCode = 404;
            return ['error' => 'Item not found'];
        }

        if (!$item->delete()) {
            Yii::$app->response->statusCode = 500;
            return ['error' => 'Failed to delete item'];
        }

        return ['success' => true, 'message' => 'Item deleted successfully'];
    }
}
