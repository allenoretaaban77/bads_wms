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

    public function actionUpdatereport() 
    {
        // 1. Force JSON response format (Standard practice for API endpoints in Yii2)
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        if (Yii::$app->request->method !== 'POST') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $date = Yii::$app->request->getBodyParam('date');
        if (!$date) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'Date parameter is required'];
        }

        // Start a transaction to ensure both inserts happen together safely
        $transaction = Yii::$app->db->beginTransaction();

        try {

            $sqlDelete = "DELETE FROM daily_financial_snapshots WHERE report_date = :target_date";
            Yii::$app->db->createCommand($sqlDelete)->bindValue(':target_date', $date)->execute();

            // $sqlDeleteLedger = "DELETE FROM daily_business_ledger WHERE report_date = :target_date";
            // Yii::$app->db->createCommand($sqlDeleteLedger)->bindValue(':target_date', $date)->execute();

            $maxId = Yii::$app->db->createCommand("SELECT MAX(id) FROM daily_financial_snapshots")->queryScalar();
            $nextId = $maxId ? ($maxId + 1) : 1;
            Yii::$app->db->createCommand("ALTER TABLE daily_financial_snapshots AUTO_INCREMENT = :next_id")
                ->bindValue(':next_id', $nextId)
                ->execute();

            // --- QUERY 1: Insert itemized inventory breakdown ---
            // Note: Using UNION ALL to combine sales and returns properly if uncommented
            $sqlInventoryBreakdown = "

                INSERT INTO daily_financial_snapshots (report_date, inventory_id, source_type, source_item_id, puhunan, tubo, total_sales)
                SELECT 
                    :target_date AS report_date,
                    si.inventory_id,
                    'sale' AS source_type,
                    si.id AS source_item_id,
                    (si.qty_sold * si.cost_per_unit) AS puhunan,
                    (si.total - (si.qty_sold * si.cost_per_unit)) AS tubo,
                    si.total AS total_sales
                FROM sales s
                JOIN sales_items si ON s.id = si.sales_id
                WHERE DATE(s.date_sold) = :target_date 
                  AND s.status = 'approved' AND s.is_paid = 'yes'
                ORDER BY si.id ASC

                -- UNION ALL

                -- SELECT 
                --     :target_date AS report_date,
                --     ri.inventory_id,
                --     'return' AS source_type,
                --     ri.id AS source_item_id,
                --     (ri.qty_returned * si_orig.cost_per_unit) * -1 AS puhunan,
                --     ((ri.total) * -1) - ((ri.qty_returned * si_orig.cost_per_unit) * -1) AS tubo,
                --     (ri.total) * -1 AS total_sales
                -- FROM `returns` r
                -- JOIN returns_items ri ON r.id = ri.return_id
                -- JOIN sales_items si_orig ON ri.sales_item_id = si_orig.id
                -- WHERE DATE(r.date_received) = :target_date 
                --   AND r.status = 'approved' AND r.record_status = 'active'
                --   AND ri.record_status = 'active'

                ON DUPLICATE KEY UPDATE 
                    puhunan = VALUES(puhunan), 
                    tubo = VALUES(tubo), 
                    total_sales = VALUES(total_sales);

                -- INSERT INTO daily_financial_snapshots (report_date, inventory_id, puhunan, tubo, total_sales)
                -- SELECT 
                --     :target_date AS report_date,
                --     sub.inventory_id,
                --     SUM(sub.item_puhunan) AS puhunan,
                --     SUM(sub.item_total_sales - sub.item_puhunan) AS tubo,
                --     SUM(sub.item_total_sales) AS total_sales
                -- FROM (
                --     SELECT 
                --         si.inventory_id,
                --         SUM(si.qty_sold * si.cost_per_unit) AS item_puhunan,
                --         SUM(si.total) AS item_total_sales
                --     FROM sales s
                --     JOIN sales_items si ON s.id = si.sales_id
                --     WHERE DATE(s.date_sold) = :target_date 
                --       AND s.status = 'approved' AND s.is_paid = 'yes'
                --     GROUP BY si.inventory_id
                --     -- ORDER BY si.id ASC

                --     /* UNION ALL
                --     SELECT 
                --         ri.inventory_id,
                --         SUM(ri.qty_returned * si_orig.cost_per_unit) * -1 AS item_puhunan,
                --         SUM(ri.total) * -1 AS item_total_sales
                --     FROM `returns` r
                --     JOIN returns_items ri ON r.id = ri.return_id
                --     JOIN sales_items si_orig ON ri.sales_item_id = si_orig.id
                --     WHERE DATE(r.date_received) = :target_date 
                --       AND r.status = 'approved' AND r.record_status = 'active'
                --       AND ri.record_status = 'active'
                --     GROUP BY ri.inventory_id
                --     */
                -- ) sub
                -- GROUP BY sub.inventory_id
                -- ON DUPLICATE KEY UPDATE 
                --     puhunan = VALUES(puhunan), tubo = VALUES(tubo), total_sales = VALUES(total_sales);
            ";

            $rowsAffected = Yii::$app->db->createCommand($sqlInventoryBreakdown)
                ->bindValue(':target_date', $date)
                ->execute(); // Use execute() for INSERT/UPDATE statements

            // --- QUERY 2: Sync the GLOBAL total store row (inventory_id = 0) ---
            $sqlGlobalTotal = "
                INSERT INTO daily_financial_snapshots (report_date, inventory_id, puhunan, tubo, total_sales)
                SELECT 
                    report_date,
                    0 AS inventory_id,
                    SUM(puhunan) AS puhunan,
                    SUM(tubo) AS tubo,
                    SUM(total_sales) AS total_sales
                FROM daily_financial_snapshots
                WHERE report_date = :target_date AND inventory_id > 0
                GROUP BY report_date
                ON DUPLICATE KEY UPDATE 
                    puhunan = VALUES(puhunan), tubo = VALUES(tubo), total_sales = VALUES(total_sales);
            ";
            Yii::$app->db->createCommand($sqlGlobalTotal)->bindValue(':target_date', $date)->execute();

            // --- QUERY 3: For Ledger ---
            $checker = "SELECT * FROM daily_business_ledger WHERE report_date = :target_date";
            $checkerData = Yii::$app->db->createCommand($checker)->bindValue(':target_date', $date)->queryAll();
            if (empty($checkerData)) { 
                $sqlGlobalTotalLedger = "
                    INSERT INTO daily_business_ledger (report_date, inventory_id, puhunan, tubo, total_sales)
                    SELECT 
                        report_date,
                        0 AS inventory_id,
                        SUM(puhunan) AS puhunan,
                        SUM(tubo) AS tubo,
                        SUM(total_sales) AS total_sales
                    FROM daily_financial_snapshots
                    WHERE report_date = :target_date AND inventory_id > 0
                    GROUP BY report_date
                    ON DUPLICATE KEY UPDATE 
                        puhunan = VALUES(puhunan), tubo = VALUES(tubo), total_sales = VALUES(total_sales);
                ";
            } else {
                $sqlGlobalTotalLedger = "
                    UPDATE daily_business_ledger AS dbl
                    INNER JOIN (
                        SELECT 
                            report_date,
                            SUM(puhunan) AS total_puhunan,
                            SUM(tubo) AS total_tubo,
                            SUM(total_sales) AS total_sales
                        FROM daily_financial_snapshots
                        WHERE report_date = :target_date AND inventory_id > 0
                        GROUP BY report_date
                    ) AS snapshots ON dbl.report_date = snapshots.report_date
                    SET 
                        dbl.puhunan = snapshots.total_puhunan,
                        dbl.tubo = snapshots.total_tubo,
                        dbl.total_sales = snapshots.total_sales;
                ";
            }
            Yii::$app->db->createCommand($sqlGlobalTotalLedger)->bindValue(':target_date', $date)->execute();

            // Commit changes if everything went well
            $transaction->commit();

            return [
                'success' => true,
                'rows_affected' => $rowsAffected,
            ];

        } catch (\Exception $e) {
            // Rollback database if anything fails
            $transaction->rollBack();
            Yii::$app->response->statusCode = 500;
            return [
                'success' => false,
                'error' => 'Failed to update snapshot: ' . $e->getMessage()
            ];
        }
    }

    public function actionList() 
    {
        if (Yii::$app->request->method !== 'GET') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $tableHeader = [
            ["title"=>"#","name"=>"id","align"=>"right","class"=>"w-10"],
            ["title"=>"Date","name"=>"date","align"=>"left"],
            ["title"=>"Puhunan","name"=>"puhunan","align"=>"right"],
            ["title"=>"Tubo","name"=>"tubo","align"=>"right"],
            ["title"=>"Total Sales","name"=>"total_sales","align"=>"right"],
            ["title"=>"Action","name"=>"action","default"=>1],
        ];

        $sql = "
            SELECT 
                s.date_sold AS date, 
                COALESCE(dfi.puhunan, 0) AS puhunan,
                COALESCE(dfi.tubo, 0) AS tubo,
                COALESCE(dfi.total_sales, 0) AS total_sales
            FROM sales AS s
            LEFT JOIN daily_financial_snapshots AS dfi
                ON s.date_sold = dfi.report_date 
                AND dfi.inventory_id = 0 
            GROUP BY 
                s.date_sold, 
                dfi.puhunan, 
                dfi.tubo,
                dfi.total_sales 
            ORDER BY date DESC;
        ";

        $data = Yii::$app->db->createCommand($sql)
            ->queryAll();

        return [
            'success' => true,
            'count' => count($data),
            'data' => $data,
            'headers' => json_encode($tableHeader)
        ];
    }

    public function actionListitems() 
    {
        if (Yii::$app->request->method !== 'POST') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $date = Yii::$app->request->getBodyParam('date');
        if (!$date) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'Date parameter is required'];
        }

        $sql = "
            SELECT 
                i.sku AS sku,
                i.product_name AS product_name,
                dfi.report_date AS date, 
                s.invoice_no,
                si.qty_sold,
                si.cost_per_unit,
                si.price_per_unit,
                COALESCE(dfi.puhunan, 0) AS puhunan,
                COALESCE(dfi.tubo, 0) AS tubo,
                COALESCE(dfi.total_sales, 0) AS total_sales
            FROM daily_financial_snapshots AS dfi
            LEFT JOIN inventory AS i
                ON i.id = dfi.inventory_id
            LEFT JOIN sales_items as si
                ON si.id = dfi.source_item_id
            LEFT JOIN sales as s
                ON si.sales_id = s.id
            WHERE dfi.inventory_id != 0 AND dfi.report_date = '$date'
            -- GROUP BY 
            --     s.date_sold, 
            --     dfi.puhunan, 
            --     dfi.tubo,
            --     dfi.total_sales 
            ORDER BY dfi.source_item_id ASC;
        ";

        $data = Yii::$app->db->createCommand($sql)
            ->queryAll();

        $totalPuhunan = 0;
        $totalTubo = 0;
        $totalSales = 0;
        foreach ($data as $item) {
            $totalPuhunan += (float)$item['puhunan'];
            $totalTubo += (float)$item['tubo'];
            $totalSales += (float)$item['total_sales'];
        }

        return [
            'success' => true,
            'report_date' => $date,
            'count' => count($data),
            'total_puhunan' => $totalPuhunan,
            'total_tubo' => $totalTubo,
            'total_sales' => $totalSales,
            'items' => $data,
        ];
    }

    public function actionGetdailystockins() 
    {
        if (Yii::$app->request->method !== 'GET') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $tableHeader = [
            ["title"=>"#","name"=>"id","align"=>"right","class"=>"w-10"],
            ["title"=>"Report Date","name"=>"date","align"=>"left"],
            ["title"=>"Total Purchase Cost","name"=>"total_purchase_cost","align"=>"right","class"=>"w-40"],
            ["title"=>"Record Count","name"=>"record_count","align"=>"right","class"=>"w-28"],
            ["title"=>"Total Quantity","name"=>"total_quantity","align"=>"right","class"=>"w-28"],
            ["title"=>"Action","name"=>"action","default"=>1],
        ];

        $sql = "
            SELECT 
                r.date_received AS date,
                SUM(ri.total) AS total_purchase_cost,
                COUNT(*) AS record_count,
                SUM(ri.qty_added) AS total_quantity
            FROM replenishment AS r
            LEFT JOIN replenishment_items AS ri
            ON r.id = ri.transaction_id
            GROUP BY r.date_received
            ORDER BY r.date_received DESC;
        ";

        $data = Yii::$app->db->createCommand($sql)->queryAll();

        return [
            'success' => true,
            'count' => count($data),
            'data' => $data,
            'headers' => json_encode($tableHeader)
        ];
    }

    public function actionGetdailystockinitems() 
    {
        if (Yii::$app->request->method !== 'POST') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $date = Yii::$app->request->getBodyParam('date');
        if (!$date) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'Date parameter is required'];
        }

        $sql = "
            SELECT
                i.sku,
                i.product_name,
                ri.qty_added AS quantity,
                ri.cost_per_unit,
                ri.total AS total_purchase_cost,
                r.date_received AS date_received,
                r.supplier AS supplier,
                r.reference_no AS reference_no
            FROM inventory AS i
            LEFT JOIN replenishment_items AS ri
            ON i.id = ri.inventory_id
            LEFT JOIN replenishment AS r
            ON r.id = ri.transaction_id
            WHERE r.date_received = '$date';
        ";

        $data = Yii::$app->db->createCommand($sql)->queryAll();

        $total_purchase_cost = 0;
        $total_quantity = 0;
        foreach ($data as $item) {
            $total_purchase_cost += (float)$item['total_purchase_cost'];
            $total_quantity += (float)$item['quantity'];
        }

        return [
            'success' => true,
            'report_date' => $date,
            'count' => count($data),
            'total_purchase_cost' => $total_purchase_cost,
            'total_quantity' => $total_quantity,
            'items' => $data,
        ];
    }

    public function actionGetmonthlyreports()
    {
        if (Yii::$app->request->method !== 'GET') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $tableHeader = [
            ["title"=>"#","name"=>"id","align"=>"right","class"=>"w-10"],
            ["title"=>"Date","name"=>"date","align"=>"left"],
            ["title"=>"Puhunan","name"=>"puhunan","align"=>"right","class"=>"w-40"],
            ["title"=>"Tubo","name"=>"tubo","align"=>"right","class"=>"w-40"],
            ["title"=>"Total Sales","name"=>"total_sales","align"=>"right","class"=>"w-40"],
            ["title"=>"Action","name"=>"action","default"=>1],
        ];

        $sql = "
            SELECT 
                DATE_FORMAT(dfi.report_date, '%Y-%m') AS month,
                SUM(dfi.puhunan) AS total_puhunan,
                SUM(dfi.tubo) AS total_tubo,
                SUM(dfi.total_sales) AS total_sales
            FROM daily_financial_snapshots AS dfi
            WHERE dfi.inventory_id = 0
            GROUP BY DATE_FORMAT(dfi.report_date, '%Y-%m')
            ORDER BY month DESC;
        ";

        $data = Yii::$app->db->createCommand($sql)->queryAll();

        return [
            'success' => true,
            'count' => count($data),
            'data' => $data,
            'headers' => json_encode($tableHeader)
        ];        
    }

    public function actionGetdailybusinessledger() 
    {
        if (Yii::$app->request->method !== 'GET') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $sqlInventory = "SELECT * FROM inventory AS i WHERE i.monitored = 1";
        $dataInventory = Yii::$app->db->createCommand($sqlInventory)->queryAll();
        $additionalHeader = [];
        $monitoredIds = [];
        foreach ($dataInventory as $item) {
            $additionalHeader[] = ["title"=>"P - ".ucwords(strtolower($item["product_name"])),"name"=>"","align"=>"right","class"=>"w-28"];
            $additionalHeader[] = ["title"=>"T - ".ucwords(strtolower($item["product_name"])),"name"=>"","align"=>"right","class"=>"w-28"];
            $additionalHeader[] = ["title"=>"TS - ".ucwords(strtolower($item["product_name"])),"name"=>"","align"=>"right","class"=>"w-28"];
            $monitoredIds[] = $item["id"];
        }

        $tableHeader = [
            ["title"=>"#","name"=>"id","align"=>"right","class"=>"w-10"],
            ["title"=>"Date","name"=>"date","align"=>"left","class"=>"w-40"],
            ["title"=>"Puhunan","name"=>"puhunan","align"=>"right","class"=>"w-28"],
            ["title"=>"Tubo","name"=>"tubo","align"=>"right","class"=>"w-28"],
            ["title"=>"Total Sales","name"=>"total_sales","align"=>"right","class"=>"w-28"],
            ["title"=>"Hardware","name"=>"hardware","align"=>"right","class"=>"w-28"],
            ["title"=>"Bahay","name"=>"bahay","align"=>"right","class"=>"w-28"],
            ["title"=>"Total Amount","name"=>"total_amount","align"=>"right","class"=>"w-28"],
            ["title"=>"Money On Hand","name"=>"money_on_hand","align"=>"right","class"=>"w-28"],
            ["title"=>"Total Puhunan","name"=>"total_puhunan","align"=>"right","class"=>"w-28"],
            ["title"=>"Total Tubo","name"=>"total_tubo","align"=>"right","class"=>"w-28"],
            ["title"=>"Action","name"=>"action","default"=>1,"class"=>"w-20"],
        ];
        array_splice($tableHeader, 5, 0, $additionalHeader);

        $sql = "
            SELECT 
                s.date_sold AS date, 
                COALESCE(dbl.puhunan, 0) AS puhunan,
                COALESCE(dbl.tubo, 0) AS tubo,
                COALESCE(dbl.total_sales, 0) AS total_sales,
                dbl.hardware AS hardware,
                dbl.bahay AS bahay,
                dbl.starting_puhunan AS starting_puhunan,
                dbl.starting_tubo AS starting_tubo,
                dbl.starting_money_on_hand AS starting_money_on_hand,
                dbl.money_on_hand AS money_on_hand,
                dbl.total_puhunan AS total_puhunan,
                dbl.total_tubo AS total_tubo
            FROM sales AS s
            LEFT JOIN daily_business_ledger AS dbl
                ON s.date_sold = dbl.report_date 
                AND dbl.inventory_id = 0 
            GROUP BY 
                s.date_sold, 
                dbl.puhunan, 
                dbl.tubo,
                dbl.total_sales 
            ORDER BY date DESC;
        ";
        $data = Yii::$app->db->createCommand($sql)->queryAll();

        $monitoredItem = [];
        $sqlMonitoredItem = "SELECT * FROM daily_financial_snapshots WHERE inventory_id IN (".implode(",", $monitoredIds).")";
        $query = Yii::$app->db->createCommand($sqlMonitoredItem)->queryAll();
        $previous_array = [];
        foreach($data as $key => $parent) {
            $previous = $this->getPreviousLedger($parent["date"]);
            $previous_array[] = $previous;
            if (!$previous) { 
                $previous["money_on_hand"] = 0;
                $previous["total_puhunan"] = 0;
                $previous["total_tubo"] = 0;
            }

            $data[$key]["money_on_hand"] = $previous["money_on_hand"];
            $data[$key]["money_on_hand_str"] = $previous["money_on_hand"];

            $data[$key]["total_puhunan"] = $previous["total_puhunan"];
            $data[$key]["total_puhunan_str"] = $previous["total_puhunan"];

            $data[$key]["total_tubo"] = $previous["total_tubo"];
            $data[$key]["total_tubo_str"] = $previous["total_tubo"];


            foreach($query as $child) {

                // $data[$key]["tubo"] = 0;
                // $data[$key]["money_on_hand"] = 0;
                // $data[$key]["total_amount"] = 0;
                // $child["tubo"] = 0;

                if ($parent["date"] == $child["report_date"]." 00:00:00") {

                    $data[$key]["p_".$child["inventory_id"]] = $child["puhunan"];
                    $data[$key]["t_".$child["inventory_id"]] = $child["tubo"];
                    $data[$key]["ts_".$child["inventory_id"]] = $child["total_sales"];

                    $data[$key]["puhunan"] = (float) $data[$key]["puhunan"] - (float) $child["puhunan"];
                    $data[$key]["tubo"] = (float) $data[$key]["tubo"] - (float) $child["tubo"];
                    $data[$key]["total_sales"] = (float) $data[$key]["total_sales"] - (float) $child["total_sales"];

                    $data[$key]["puhunan"] = $data[$key]["puhunan"] < 0 ? 0 : $data[$key]["puhunan"];
                    $data[$key]["tubo"] = $data[$key]["tubo"] < 0 ? 0 : $data[$key]["tubo"];
                    $data[$key]["total_sales"] = $data[$key]["total_sales"] < 0 ? 0 : $data[$key]["total_sales"];

                    $data[$key]["money_on_hand"] = $data[$key]["money_on_hand"] + $child["tubo"];
                    $data[$key]["money_on_hand_str"] = $data[$key]["money_on_hand_str"] . " + " . $child["tubo"];

                    $data[$key]["total_tubo"] = $data[$key]["total_tubo"] + $child["tubo"];
                    $data[$key]["total_tubo_str"] = $data[$key]["total_tubo_str"] . " + " . $child["tubo"];
                }

                // $data[$key]["money_on_hand"] = $data[$key]["tubo"] + $child["tubo"] + $data[$key]["total_amount"];
            }

            $data[$key]["total_amount"] = $data[$key]["hardware"] + $data[$key]["bahay"];

            $data[$key]["money_on_hand"] = $data[$key]["money_on_hand"] + $data[$key]["total_sales"] - $data[$key]["total_amount"];
            $data[$key]["money_on_hand_str"] = $data[$key]["money_on_hand_str"] . " + " . $data[$key]["total_sales"] . " - " . $data[$key]["total_amount"];

            $data[$key]["total_puhunan"] = $previous["total_puhunan"] + $data[$key]["puhunan"] - $data[$key]["hardware"];
            // $data[$key]["total_puhunan"] = $data[$key]["starting_puhunan"] + $data[$key]["puhunan"] - $data[$key]["hardware"];
            $data[$key]["total_puhunan_str"] = $data[$key]["total_puhunan_str"] . " + ". $data[$key]["puhunan"] . " - " . $data[$key]["hardware"];

            $data[$key]["total_tubo"] = $data[$key]["total_tubo"] + $data[$key]["tubo"] - $data[$key]["bahay"];
            $data[$key]["total_tubo_str"] = $data[$key]["total_tubo_str"] . " + " . $data[$key]["tubo"] . " - " . $data[$key]["bahay"];

            if ($data[$key]["total_sales"] == 0) {
                $data[$key]["money_on_hand"] = 0;
                $data[$key]["total_puhunan"] = 0;
                $data[$key]["total_tubo"] = 0;
                $data[$key]["money_on_hand_str"] = "";
                $data[$key]["total_puhunan_str"] = "";
                $data[$key]["total_tubo_str"] = "";
            }

            $sqlUpdateLedger = "
                UPDATE daily_business_ledger AS dbl
                SET 
                    dbl.total_puhunan = ".$data[$key]["total_puhunan"].",
                    dbl.total_tubo = ".$data[$key]["total_tubo"].",
                    dbl.money_on_hand = ".$data[$key]["money_on_hand"]."
                WHERE report_date = :target_date AND inventory_id = 0;
            ";
            Yii::$app->db->createCommand($sqlUpdateLedger)->bindValue(':target_date', $parent["date"])->execute();
        }

        return [
            'data' => $data,
            'count' => count($data),
            'previous_array' => $previous_array,
            'mids' => $monitoredIds,
            'sqlMonitoredItem' => $sqlMonitoredItem,
            // 'monitored_items' => $query,
            'success' => true,
            'headers' => json_encode($tableHeader)
        ];       
    }

    public function getPreviousLedger($date) 
    {
        // Clean up the date string (removing time stamp if it exists) to match DATE data type
        $cleanDate = date('Y-m-d', strtotime($date));

        $sql = "
            SELECT * FROM `daily_business_ledger`
            WHERE `report_date` < :selected_date 
            ORDER BY `report_date` DESC
            LIMIT 1;
        ";

        return Yii::$app->db->createCommand($sql)->bindValue(':selected_date', $cleanDate)->queryOne(); 
    }
}