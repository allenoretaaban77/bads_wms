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

    public function actionCreate()
    {
        if (Yii::$app->request->method !== 'POST') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $request = Yii::$app->request;
        $data = $request->post();

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $sales = new Sales();
            $sales->customer_name = $data['customer_name'] ?? null;
            $sales->invoice_no = $data['invoice_no'] ?? null;
            $sales->date_sold = $data['date_sold'] ?? date('Y-m-d');
            $sales->payment_method = $data['payment_method'] ?? null;
            $sales->remarks = $data['remarks'] ?? null;
            $sales->date_created = date('Y-m-d H:i:s');
            $sales->added_by = $data['added_by'] ?? null;

            if (!$sales->save()) {
                $transaction->rollBack();
                return ['success' => false, 'errors' => $sales->getErrors()];
            }
            Yii::debug($sales->getErrors(), __METHOD__);

            if (isset($data['items']) && is_array($data['items'])) {
                foreach ($data['items'] as $itemData) {
                    $inventory = Inventory::findOne(['product_name' => $itemData['item_name']]);
                    
                    if (!$inventory) {
                        $transaction->rollBack();
                        return ['success' => false, 'error' => "Item '{$itemData['item_name']}' not found in inventory"];
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
                    
                    if (!$inventory->save(false)) { // Save without validation to be faster
                        $transaction->rollBack();
                        return ['success' => false, 'error' => "Failed to update inventory for '{$itemData['item_name']}'"];
                    }
                }
            }  

            $transaction->commit();
            return [
                'success' => true,
                'message' => 'Sales created successfully',
                'id' => $sales->id
            ];
        } catch (\Exception $e) {
            $transaction->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}