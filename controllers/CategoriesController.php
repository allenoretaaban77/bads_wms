<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\filters\VerbFilter;
use yii\db\Expression;
use app\models\Employee; 
use app\models\Categories; 

class CategoriesController extends Controller
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
        $query = Categories::find()->select([
            'categories.*',
        ]);
        // ->where(['sales.record_status' => 'active']);

        // 🔍 Search (customer_name, invoice_no, date_sold, payment_method)
        $search = $request->get('search');
        if (!empty($search)) {
            $query->andFilterWhere([
                'or',
                ['like', 'name', $search],
                ['like', 'tag', $search],
            ]);
        }

        // 🎯 Filters
        $filters = [
            // 'status' => $request->get('status'),
            // 'payment_status' => $request->get('payment_status'),
            // 'is_paid' => $request->get('is_paid'),
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
            'id', 'name', 'tag'
        ];
        if (in_array($sortField, $allowedSortFields)) {
            $query->orderBy([$sortField => $sortOrder]);
        } else {
            $query->orderBy(['id' => SORT_ASC]);
        }

        // Execute query
        $totalCount = $query->count();
        $items = $query->offset($offset)->limit($pageSize)->asArray()->all();
        $items = [[
            'id'=>1, 
            'name'=>'All',
            'tag' =>'all',
            'remarks' =>'System Generated'
        ], ...$items];

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
        $item = Categories::findOne($id);
        if (!$item) {
            Yii::$app->response->statusCode = 404;
            return ['error' => 'Item not found'];
        }
        return ['success' => true, 'data' => $item];
    }

    public function actionCreate()
    {
        if (Yii::$app->request->method !== 'POST') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $category = new Categories();
        $category->load(Yii::$app->request->post(), '');

        // Automatically assign audit trail fields if passed via current user session (optional clean-up)
        // $category->created_by = Yii::$app->user->id; 

        if (!$category->validate()) {
            Yii::$app->response->statusCode = 422;
            return ['error' => 'Validation failed', 'errors' => $category->errors];
        }

        $category->date_created = date('Y-m-d H:i:s');
        if (!$category->save()) {
            Yii::$app->response->statusCode = 500;
            return ['error' => 'Failed to save category'];
        }

        // ✅ Insert into audit log after successful creation
        Yii::$app->db->createCommand()->insert('audit_log', [
            'entity' => 'categories',
            'entity_id' => $category->id,
            'action' => 'create',
            'new_data' => json_encode($category->attributes),
            'updated_by' => $category->created_by, // Tracks creator based on schema
            'updated_at' => date('Y-m-d H:i:s'),
        ])->execute();

        return ['success' => true, 'data' => $category];
    }

    public function actionUpdate()
    {
        if (Yii::$app->request->method !== 'PUT' && Yii::$app->request->method !== 'PATCH') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $id = Yii::$app->request->getBodyParam('id');
        $category = Categories::findOne($id);
        if (!$category) {
            Yii::$app->response->statusCode = 404;
            return ['error' => 'Category not found'];
        }

        $oldData = $category->attributes; // Capture old values before update

        $category->load(Yii::$app->request->post(), '');

        if (!$category->validate()) {
            Yii::$app->response->statusCode = 422;
            return ['error' => 'Validation failed', 'errors' => $category->errors];
        }

        $category->date_updated = date('Y-m-d H:i:s');
        if (!$category->save()) {
            Yii::$app->response->statusCode = 500;
            return ['error' => 'Failed to save item'];
        }

        // ✅ Insert into audit log after successful update
        Yii::$app->db->createCommand()->insert('audit_log', [
            'entity' => 'categories',
            'entity_id' => $category->id,
            'action' => 'update',
            'old_data' => json_encode($oldData),
            'new_data' => json_encode($category->attributes),
            'updated_by' => $category->updated_by,
            'updated_at' => date('Y-m-d H:i:s'),
        ])->execute();

        return ['success' => true, 'data' => $category];
    }
    
    public function actionDelete()
    {
        if (Yii::$app->request->method !== 'DELETE') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $id = Yii::$app->request->getBodyParam('id');
        $item = Categories::findOne($id);
        $employee_id = Yii::$app->request->getBodyParam('employee_id');

        if (!$item) {
            Yii::$app->response->statusCode = 404;
            return ['error' => 'Item not found'];
        }

        $oldData = $item->attributes; // Capture data before deletion

        if (!$item->delete()) {
            Yii::$app->response->statusCode = 500;
            return ['error' => 'Failed to delete category record'];
        }    

        // ✅ Insert into audit log after successful delete
        Yii::$app->db->createCommand()->insert('audit_log', [
            'entity' => 'categories',
            'entity_id' => $id,
            'action' => 'delete',
            'old_data' => json_encode($oldData),
            'new_data' => null,
            'updated_by' => $employee_id,
            'updated_at' => date('Y-m-d H:i:s'),
        ])->execute();

        return ['success' => true, 'message' => 'Category record deleted successfully'];
    }
}