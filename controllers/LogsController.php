<?php

namespace app\controllers;

use Yii;
// use app\models\Sales;
// use app\models\SalesItems;
// use app\models\Inventory;
// use app\models\InventoryBatches;
// use app\models\ReplenishmentItems;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\filters\VerbFilter;
use yii\db\Expression;
use app\models\Employee; 

class LogsController extends Controller
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

    // public function beforeAction($action)
    // {
    //     if (!parent::beforeAction($action)) {
    //         return false;
    //     }

    //     if ($action->id === 'register') {
    //         return true; // skip token check for register
    //     }

    //     $authHeader = Yii::$app->request->getHeaders()->get('Authorization');
    //     if (!$authHeader || !preg_match('/^Bearer\s+(.*?)$/i', $authHeader, $matches)) {
    //         Yii::$app->response->statusCode = 401;
    //         Yii::$app->response->data = ['error' => 'Authorization header missing or invalid'];
    //         return false; // stop execution
    //     }

    //     $accessToken = $matches[1];
    //     $employee = Employee::findByAccessToken($accessToken);

    //     if (!$employee) {
    //         Yii::$app->response->statusCode = 401;
    //         Yii::$app->response->data = ['error' => 'Invalid access token'];
    //         return false; // stop execution
    //     }

    //     return true; // allow action to run
    // } 

    public function actionRecon($id) {
        // Force the response format to JSON immediately
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        // 1. Fetch and safely decode the audit log
        $sql_audit_log = "SELECT * FROM audit_log WHERE id = :log_id";
        $audit_data = Yii::$app->db->createCommand($sql_audit_log)->bindValue(':log_id', $id)->queryOne();

        if (!$audit_data || empty($audit_data['new_data'])) {
            Yii::$app->response->statusCode = 404;
            return [
                'success' => false,
                'message' => "Audit log record ID {$id} not found."
            ];
        }

        $log_payload = json_decode($audit_data["new_data"]);
        
        // Validate JSON structure
        if (json_last_error() !== JSON_ERROR_NONE) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'message' => 'Malformed JSON payload detected in audit log.'
            ];
        }

        $log_payload = json_decode($log_payload);
        $items = $log_payload->items ?? [];
        $sales = $log_payload->sales ?? null;

        // $data_array = json_decode($data["new_data"], true);
        // $data = json_decode($data_array);
        // $items = $data->items;
        // $sales = $data->sales;

        // Fail clearly if data payload doesn't contain the essentials
        if (!$sales || empty($items)) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'message' => 'Audit log payload is missing required sales or items objects.'
            ];
        }

        // 2. Collect unique inventory IDs
        $inventory_ids = [];
        foreach ($items as $val) {
            if (!empty($val->inventory_id)) {
                $inventory_ids[] = $val->inventory_id;
            }
        }
        $inventory_ids = array_unique($inventory_ids);

        // 3. Fetch batch mapping via Query Builder (prevents IN bind errors)
        $sales_items_map = [];
        if (!empty($inventory_ids)) {
            $rows = (new \yii\db\Query())
                ->select(['inventory_id', 'batch_id'])
                ->from('sales_items')
                ->where(['inventory_id' => $inventory_ids])
                ->all();
                
            $sales_items_map = \yii\helpers\ArrayHelper::map($rows, 'inventory_id', 'batch_id');
        }

        // 4. Build the bulk insert matrix
        $inserts = [];
        $columns = ['sales_id', 'batch_id', 'inventory_id', 'cost_per_unit', 'qty_sold', 'price_per_unit', 'total'];

        foreach ($items as $val) {
            $batch_id = $sales_items_map[$val->inventory_id] ?? null;

            $inserts[] = [
                $sales->id,
                $batch_id,
                $val->inventory_id,
                $val->cost ?? 0,
                $val->quantity ?? 0,
                $val->price ?? 0,
                $val->total ?? 0,
            ];
        }

        // 5. Execute DB updates atomically inside a transaction
        $transaction = Yii::$app->db->beginTransaction();
        try {
            // Clear out old data
            Yii::$app->db->createCommand()
                ->delete('sales_items', ['sales_id' => $sales->id])
                ->execute();

            // Perform bulk insert
            if (!empty($inserts)) {
                Yii::$app->db->createCommand()->batchInsert('sales_items', $columns, $inserts)->execute();
            }

            $transaction->commit();
            
            // Return clear, API-friendly success structure
            return [
                'success' => true,
                'message' => 'Reconciliation completed successfully.',
                'reconciled_items_count' => count($inserts)
            ];

        } catch (\Exception $e) {
            $transaction->rollBack();
            
            // Return 500 status on database failure without leaking sensitive server exceptions
            Yii::$app->response->statusCode = 500;
            return [
                'success' => false,
                'message' => 'An internal database error occurred while running reconciliation.',
                'error' => YII_DEBUG ? $e->getMessage() : 'Internal Server Error'
            ];
        }
    }

    public function actionReconOld($id) {
        $sql_audit_log = "SELECT * FROM audit_log WHERE id = :log_id";
        $data = Yii::$app->db->createCommand($sql_audit_log)->bindValue(':log_id', $id)->queryOne();

        $data_array = json_decode($data["new_data"], true);

        $data = json_decode($data_array);
        $items = $data->items;
        $sales = $data->sales;
        $i = 1;

        $inserts = [];
        foreach($items as $key => $val) {
            $sql_sales_items = "SELECT * FROM sales_items WHERE inventory_id = :inventory_id";
            $sales_items = Yii::$app->db->createCommand($sql_sales_items)->bindValue(':inventory_id', $val->inventory_id)->queryOne();

            //echo  $i++.". (".$sales->id." - ".$sales_items["batch_id"]." - ".$sales_items["id"].") ".$val->inventory_id." - ".$val->item_name." - ".$val->quantity."|".$val->quantity."|".$val->price."\r\n";

            $inserts[] = [
                // "id" => $sales_items["id"],
                "sales_id" => $sales->id,
                "batch_id" => $sales_items["batch_id"],
                "inventory_id" => $val->inventory_id,
                "cost_per_unit" => $val->cost,
                "qty_sold" => $val->quantity,
                "price_per_unit" => $val->price,
                "total" => $val->total,
            ];
        }

        $sql_delete = "DELETE FROM sales_items WHERE sales_id = :sales_id";
        Yii::$app->db->createCommand($sql_delete)->bindValue(':sales_id', $sales->id)->execute();

        foreach($inserts as $key => $val) {
            echo json_encode($val);
            Yii::$app->db->createCommand()->insert('sales_items', $val)->execute();
        }

        // $sql_insert

        // $sql = "
        //     SELECT * FROM audit_log WHERE id = :log_id;
        // ";

        // $data = Yii::$app->db->createCommand($sql)->bindValue(':log_id', $log_id)->queryOne();

        // // 1. Double check that we actually found a row in the database
        // if ($data && isset($data['new_data'])) {
            
        //     // 2. Attempt to decode it into an array
        //     $arrayData = json_decode($data['new_data'], true);

        //     // 3. SAFETY CHECK: Only loop if json_decode actually succeeded and gave us an array
        //     if (is_array($arrayData)) {
        //         foreach($arrayData as $key => $value) {
        //             if (is_array($value)) {
        //                 echo $key . ": " . json_encode($value) . "<br><br>";
        //             } else {
        //                 echo $key . ": " . $value . "<br><br>";
        //             }
        //         }
        //     } else {
        //         // If it's not an array, json_decode failed. Let's see what was actually in there:
        //         echo "JSON decoding failed. Raw data in database is: <pre>";
        //         var_dump($data['new_data']);
        //         echo "</pre>";
                
        //         // This will print the specific JSON error (e.g., Syntax error, Malformed UTF-8)
        //         echo "JSON Error: " . json_last_error_msg();
        //     }
        // } else {
        //     echo "No log found for ID: " . $log_id;
        // }

        return [];
    }
}