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

class ReportsController extends Controller
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
     * GET /report/daily-itemized
     * Optional Query Params: start_date (YYYY-MM-DD), end_date (YYYY-MM-DD)
     */
    public function actionDailyItemized()
    {
        $request = Yii::$app->request;
        
        // Default to the last 30 days if no date filters are supplied
        $startDate = $request->get('start_date', date('Y-m-d', strtotime('-30 days')));
        $endDate = $request->get('end_date', date('Y-m-d'));

        $sql = "
            SELECT 
                d.report_date AS `date`,
                i.sku AS `sku`,
                i.product_name AS `product_name`,
                
                COALESCE(s.qty_sold, 0) AS `qty_sold`,
                COALESCE(r.qty_returned, 0) AS `qty_returned`,
                COALESCE(rep.qty_replenished, 0) AS `qty_replenished`,
                
                CAST(COALESCE(s.gross_sales, 0) AS DECIMAL(10,2)) AS `gross_sales`,
                CAST(COALESCE(r.total_returns, 0) AS DECIMAL(10,2)) AS `returns_refunds`,
                CAST((COALESCE(s.gross_sales, 0) - COALESCE(r.total_returns, 0)) AS DECIMAL(10,2)) AS `net_sales`,
                
                CAST(COALESCE(s.total_cogs, 0) AS DECIMAL(10,2)) AS `cogs`,
                CAST(COALESCE(rep.replenishment_spend, 0) AS DECIMAL(10,2)) AS `replenishment_spend`,
                
                CAST(((COALESCE(s.gross_sales, 0) - COALESCE(r.total_returns, 0)) - COALESCE(s.total_cogs, 0)) AS DECIMAL(10,2)) AS `net_profit`,
                
                IF((COALESCE(s.gross_sales, 0) - COALESCE(r.total_returns, 0)) > 0,
                    ROUND((((COALESCE(s.gross_sales, 0) - COALESCE(r.total_returns, 0)) - COALESCE(s.total_cogs, 0)) / (COALESCE(s.gross_sales, 0) - COALESCE(r.total_returns, 0))) * 100, 2),
                    0
                ) AS `net_profit_margin_percent`
            FROM (
                SELECT DATE(sales.date_sold) AS report_date, si.inventory_id 
                FROM sales 
                JOIN sales_items si ON sales.id = si.sales_id
                WHERE sales.status = 'approved' AND sales.record_status = 'active' AND si.record_status = 'active'
                
                UNION
                
                SELECT DATE(replenishment.date_received) AS report_date, ri.inventory_id 
                FROM replenishment 
                JOIN replenishment_items ri ON replenishment.id = ri.transaction_id
                WHERE replenishment.status = 'approved' AND replenishment.record_status = 'active' AND ri.record_status = 'active'
                
                UNION
                
                SELECT DATE(`returns`.date_received) AS report_date, ret_i.inventory_id 
                FROM `returns` 
                JOIN returns_items ret_i ON `returns`.id = ret_i.return_id
                WHERE `returns`.status = 'approved' AND `returns`.record_status = 'active' AND ret_i.record_status = 'active'
            ) d
            JOIN inventory i ON d.inventory_id = i.id
            LEFT JOIN (
                SELECT 
                    DATE(main.date_sold) AS report_date,
                    items.inventory_id,
                    SUM(items.qty_sold) AS qty_sold,
                    SUM(items.qty_sold * items.price_per_unit) AS gross_sales,
                    SUM(items.qty_sold * items.cost_per_unit) AS total_cogs
                FROM sales main
                JOIN sales_items items ON main.id = items.sales_id
                WHERE main.status = 'approved' AND main.record_status = 'active' AND items.record_status = 'active'
                GROUP BY DATE(main.date_sold), items.inventory_id
            ) s ON d.report_date = s.report_date AND d.inventory_id = s.inventory_id
            LEFT JOIN (
                SELECT 
                    DATE(main.date_received) AS report_date,
                    items.inventory_id,
                    SUM(items.qty_returned) AS qty_returned,
                    SUM(items.qty_returned * items.unit_price) AS total_returns
                FROM `returns` main
                JOIN returns_items items ON main.id = items.return_id
                WHERE main.status = 'approved' AND main.record_status = 'active' AND items.record_status = 'active'
                GROUP BY DATE(main.date_received), items.inventory_id
            ) r ON d.report_date = r.report_date AND d.inventory_id = r.inventory_id
            LEFT JOIN (
                SELECT 
                    DATE(main.date_received) AS report_date,
                    items.inventory_id,
                    SUM(items.qty_added) AS qty_replenished,
                    SUM(items.qty_added * items.cost_per_unit) AS replenishment_spend
                FROM replenishment main
                JOIN replenishment_items items ON main.id = items.transaction_id
                WHERE main.status = 'approved' AND main.record_status = 'active' AND items.record_status = 'active'
                GROUP BY DATE(main.date_received), items.inventory_id
            ) rep ON d.report_date = rep.report_date AND d.inventory_id = rep.inventory_id
            WHERE d.report_date BETWEEN :start_date AND :end_date
            ORDER BY d.report_date DESC, i.product_name ASC;
        ";

        // Execute the query safely using parameter binding
        $data = Yii::$app->db->createCommand($sql)
            ->bindValue(':start_date', $startDate)
            ->bindValue(':end_date', $endDate)
            ->queryAll();

        return [
            'success' => true,
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'count' => count($data),
            'data' => $data
        ];
    }

    public function actionList() 
    {
        $sql = "
            SELECT 
                d.report_date AS `date`,
                
                -- Revenue Metrics
                COALESCE(s.gross_sales, 0) AS `gross_sales`,
                COALESCE(r.total_returns, 0) AS `returns_refunds`,
                (COALESCE(s.gross_sales, 0) - COALESCE(r.total_returns, 0)) AS `net_sales`,
                
                -- Expense Metric 1: Cost of Goods Sold
                COALESCE(s.total_cogs, 0) AS `cogs`,
                
                -- Expense Metric 2: Cash Capital Outflow
                COALESCE(rep.replenishment_spend, 0) AS `Supplier Replenishment Spend`,
                
                -- Net Profit (Net Sales - COGS)
                ((COALESCE(s.gross_sales, 0) - COALESCE(r.total_returns, 0)) - COALESCE(s.total_cogs, 0)) AS `net_profit`,
                
                -- Net Profit Margin %
                IF((COALESCE(s.gross_sales, 0) - COALESCE(r.total_returns, 0)) > 0,
                    ROUND((((COALESCE(s.gross_sales, 0) - COALESCE(r.total_returns, 0)) - COALESCE(s.total_cogs, 0)) / (COALESCE(s.gross_sales, 0) - COALESCE(r.total_returns, 0))) * 100, 2),
                    0
                ) AS `Net Profit Margin (%)`

            FROM (
                -- Step 1: Create a master timeline matching all subquery filters exactly
                SELECT DATE(date_sold) AS report_date FROM sales WHERE status = 'approved' AND record_status = 'active' AND is_paid = 'yes'
                UNION
                SELECT DATE(date_received) AS report_date FROM replenishment WHERE status = 'approved' AND record_status = 'active'
                UNION
                SELECT DATE(date_received) AS report_date FROM `returns` WHERE status = 'approved' AND record_status = 'active'
            ) d

            -- Step 2: Aggregate Sales and calculate COGS safely from sales_items
            LEFT JOIN (
                SELECT 
                    DATE(sales.date_sold) AS report_date,
                    SUM(sales.amount) AS gross_sales,
                    SUM(items.cogs) AS total_cogs
                FROM sales
                LEFT JOIN (
                    SELECT sales_id, SUM(qty_sold * cost_per_unit) AS cogs
                    FROM sales_items
                    WHERE record_status = 'active'
                    GROUP BY sales_id
                ) items ON sales.id = items.sales_id
                WHERE sales.status = 'approved' AND sales.record_status = 'active' AND sales.is_paid = 'yes'
                GROUP BY DATE(sales.date_sold)
            ) s ON d.report_date = s.report_date

            -- Step 3: Aggregate Customer Returns
            LEFT JOIN (
                SELECT 
                    DATE(date_received) AS report_date,
                    SUM(amount) AS total_returns
                FROM `returns`
                WHERE status = 'approved' AND record_status = 'active'
                GROUP BY DATE(date_received)
            ) r ON d.report_date = r.report_date

            -- Step 4: Aggregate Supplier Replenishments
            LEFT JOIN (
                SELECT 
                    DATE(date_received) AS report_date,
                    SUM(amount) AS replenishment_spend
                FROM replenishment
                WHERE status = 'approved' AND record_status = 'active'
                GROUP BY DATE(date_received)
            ) rep ON d.report_date = rep.report_date

            ORDER BY d.report_date DESC;
        ";

        $data = Yii::$app->db->createCommand($sql)
            // ->bindValue(':start_date', $startDate)
            // ->bindValue(':end_date', $endDate)
            ->queryAll();

        return [
            'success' => true,
            // 'filters' => [
                // 'start_date' => $startDate,
                // 'end_date' => $endDate,
            // ],
            'count' => count($data),
            'data' => $data
        ];
    }
}