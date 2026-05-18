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

    public function actionCreate()
    {
        if (Yii::$app->request->method !== 'POST') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        // $item = new Replenishment();
        // $item->load(Yii::$app->request->post(), '');
        // return ['data' => $item];

        $request = Yii::$app->request;
        $data = $request->post();

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

            if (isset($data['items']) && is_array($data['items'])) {
                foreach ($data['items'] as $itemData) {
                    // Try to find the item in inventory by name
                    $inventory = Inventory::findOne(['product_name' => $itemData['item_name']]);
                    
                    if (!$inventory) {
                        $transaction->rollBack();
                        return ['success' => false, 'error' => "Item '{$itemData['item_name']}' not found in inventory"];
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
                    $inventory->current_qty += $replenishmentItem->qty_added;
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
                'message' => 'Replenishment created successfully',
                'id' => $replenishment->id
            ];
        } catch (\Exception $e) {
            $transaction->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
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
                'sku' => 'inventory.sku',
                'total' => new \yii\db\Expression('replenishment_items.qty_added * replenishment_items.cost_per_unit')
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

    protected function findModel($id)
    {
        if (($model = Replenishment::findOne(['id' => $id])) !== null) {
            return $model;
        }

        throw new NotFoundHttpException('The requested page does not exist.');
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
        ]);

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

    public function actionGeneratetrnxno()
    {   
        $prefix = 'REPL-';
        $date = date('ymd');
        $count = Replenishment::find()->orderBy(['id' => SORT_DESC])->limit(1)->one();
        $lastId = Yii::$app->db->createCommand("SELECT MAX(id) FROM replenishment")->queryScalar();
        $lastId = str_pad($lastId + 1, 4, '0', STR_PAD_LEFT);

        return [
            'success' => true,
            'trnxno' => $prefix . $date . $lastId,
        ];
    }
}