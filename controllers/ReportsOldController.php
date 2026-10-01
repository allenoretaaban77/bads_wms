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
                WHERE sales.status = 'approved' AND is_paid = 'yes'
                
                UNION
                
                SELECT DATE(replenishment.date_received) AS report_date, ri.inventory_id 
                FROM replenishment 
                JOIN replenishment_items ri ON replenishment.id = ri.transaction_id
                WHERE replenishment.status = 'approved' AND is_paid = 'yes'
                
                UNION
                
                SELECT DATE(`returns`.date_received) AS report_date, ret_i.inventory_id 
                FROM `returns` 
                JOIN returns_items ret_i ON `returns`.id = ret_i.return_id
                WHERE `returns`.status = 'approved' AND is_paid = 'yes'
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
                WHERE main.status = 'approved' AND is_paid = 'yes'
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
                WHERE main.status = 'approved' AND is_paid = 'yes'
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
                WHERE main.status = 'approved' AND is_paid = 'yes'
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
        if (Yii::$app->request->method !== 'GET') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $request = Yii::$app->request;

        $tableHeader = [
            ["title"=>"#","name"=>"id","align"=>"right","class"=>"w-10"],
            ["title"=>"Date","name"=>"date","align"=>"left"],
            ["title"=>"Total Sales","name"=>"total_sales","align"=>"right"],
            ["title"=>"Puhunan","name"=>"puhunan","align"=>"right"],
            ["title"=>"Tubo","name"=>"tubo","align"=>"right"],
            ["title"=>"Action","name"=>"action","default"=>1],
        ];

        // $sql = "
        //     SELECT 
        //         s.date_sold AS date, 
        //         COALESCE(dfi.puhunan, 0) AS puhunan,
        //         COALESCE(dfi.tubo, 0) AS tubo,
        //         COALESCE(dfi.total_sales, 0) AS total_sales
        //     FROM sales AS s
        //     LEFT JOIN daily_financial_snapshots AS dfi
        //         ON s.date_sold = dfi.report_date 
        //         AND dfi.inventory_id = 0 
        //     GROUP BY 
        //         s.date_sold, 
        //         dfi.puhunan, 
        //         dfi.tubo,
        //         dfi.total_sales 
        //     ORDER BY date DESC;
        // ";
        // $data = Yii::$app->db->createCommand($sql)->queryAll();

        $inventory_id = $request->get('inventoryId');

        $pageType = $request->get('pageType');
        $query_where = "";

        if (!empty($pageType)) {
            if ($pageType == "unmonitored") {
                $query_where = "
                    WHERE s.status = 'approved' 
                        AND s.is_paid = 'yes' 
                        AND i.monitored = 0
                ";
            } else {
                $query_where = "
                    WHERE s.status = 'approved' 
                        AND s.is_paid = 'yes' 
                        AND i.monitored = 1
                        AND i.type = :type
                        AND i.id = :inventory_id
                ";
            }
        } else {
            $query_where = "
                WHERE s.status = 'approved' 
                    AND s.is_paid = 'yes' 
            ";
        }

        $sql = "
            WITH unique_dates AS (
                SELECT DISTINCT DATE(date_sold) AS sales_date
                FROM sales
            ),
            daily_sales AS (
                SELECT 
                    DATE(s.date_sold) AS sales_date,
                    SUM(si.qty_sold * si.cost_per_unit) AS total_puhunan,
                    SUM(si.total - (si.qty_sold * si.cost_per_unit)) AS total_tubo,
                    SUM(si.total) AS total_sales
                FROM sales s
                    INNER JOIN sales_items si ON s.id = si.sales_id
                    INNER JOIN inventory AS i ON i.id = si.inventory_id
                $query_where
                GROUP BY DATE(s.date_sold)
            )
            SELECT 
               ud.sales_date AS date,
               COALESCE(cs.total_puhunan, 0) AS puhunan,
               COALESCE(cs.total_tubo, 0) AS tubo,
               COALESCE(cs.total_sales, 0) AS total_sales
            FROM unique_dates ud
            LEFT JOIN daily_sales cs ON ud.sales_date = cs.sales_date
            ORDER BY date DESC;
        ";

        $data = [];

        if (!empty($pageType)) {
            if ($pageType == "unmonitored") {
                $data = Yii::$app->db->createCommand($sql)->queryAll(); 
            } else {
                $data = Yii::$app->db->createCommand($sql)->bindValue(':type', $pageType)->bindValue(':inventory_id', $inventory_id)->queryAll(); 
            }
        } else {
            $data = Yii::$app->db->createCommand($sql)->queryAll(); 
        }

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

        $page_type = Yii::$app->request->getBodyParam('page_type');
        $page_type_string = "";
        if (trim($page_type) != "") {
            if ($page_type == "unmonitored") {
                $page_type_string = "AND i.monitored = 0";
            } else {
                $page_type_string = "AND i.monitored = 1 AND i.type = :type ";
            }
        }

        $sql = "
            SELECT
                i.product_name,
                si.price_per_unit,
                SUM(si.qty_sold) AS qty_sold,
                SUM(si.total) AS total_sales,
                SUM(si.puhunan) AS puhunan,
                SUM(si.tubo) AS tubo,
                SUM(si.total) AS total,
                s.invoice_no
            FROM sales_items as si
            LEFT JOIN inventory AS i
                ON i.id = si.inventory_id 
            CROSS JOIN sales as s
                ON s.id = si.sales_id
            WHERE s.date_sold = '$date' ".$page_type_string." AND s.status = 'approved' AND s.is_paid = 'yes'
            GROUP BY si.sales_id, si.saved_id
            ORDER BY si.saved_id ASC;
        ";

        // $sql = "
        //     SELECT 
        //         *,
        //         si.qty_sold AS qty_sold,
        //         si.total AS total_sales,
        //         si.puhunan AS puhunan,
        //         si.tubo AS tubo,
        //         -- (si.qty_sold * si.cost_per_unit) AS puhunan,
        //         -- si.total - (si.qty_sold * si.cost_per_unit) AS tubo,
        //         si.total AS total
        //     FROM sales_items as si
        //     LEFT JOIN inventory AS i
        //         ON i.id = si.inventory_id 
        //     CROSS JOIN sales as s
        //         ON s.id = si.sales_id
        //     WHERE s.date_sold = '$date' ".$page_type_string." AND s.status = 'approved' AND s.is_paid = 'yes'
        //     ORDER BY si.id ASC;
        // ";

        $sql_query = Yii::$app->db->createCommand($sql);
        if (trim($page_type) != "") {
            if ($page_type != "unmonitored") {
                $sql_query->bindValue(':type', $page_type);
            }
        }
        $data = $sql_query->queryAll();

        $totalPuhunan = 0;
        $totalTubo = 0;
        $totalSales = 0;
        $totalQuantity = 0;
        foreach ($data as $item) {
            $totalPuhunan += (float)$item['puhunan'];
            $totalTubo += (float)$item['tubo'];
            $totalSales += (float)$item['total'];
            $totalQuantity += (float)$item['qty_sold'];
        }

        return [
            'success' => true,
            'report_date' => $date,
            'count' => count($data),
            'total_puhunan' => $totalPuhunan,
            'total_tubo' => $totalTubo,
            'total_sales' => $totalSales,
            'total_quantity' => $totalQuantity,
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
            $additionalHeader[] = ["title"=>"Ex - ".ucwords(strtolower($item["product_name"])),"name"=>"","align"=>"right","class"=>"w-28"];
            // $additionalHeader[] = ["title"=>"TS - ".ucwords(strtolower($item["product_name"])),"name"=>"","align"=>"right","class"=>"w-28"];
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

        $prefixed_string = "";
        $prefixed_array = array_map(function($item) { return 'ex_' . $item; }, $monitoredIds);
        $prefixed_string = implode(", ", $prefixed_array);
        $prefixed_array = array_map(function($item) { return 'ex_' . $item . '_details'; }, $monitoredIds);
        $prefixed_string = ", " . $prefixed_string . ", " . implode(", ", $prefixed_array);

        $sql = "
            SELECT 
                s.date_sold AS date, 
                COALESCE(dbl.puhunan, 0) AS puhunan,
                COALESCE(dbl.tubo, 0) AS tubo,
                COALESCE(dbl.total_sales, 0) AS total_sales,
                dbl.hardware AS hardware,
                dbl.hardware_details AS hardware_details,
                dbl.bahay AS bahay,
                dbl.bahay_details AS bahay_details,
                dbl.starting_puhunan AS starting_puhunan,
                dbl.starting_tubo AS starting_tubo,
                dbl.starting_money_on_hand AS starting_money_on_hand,
                dbl.money_on_hand AS money_on_hand,
                dbl.total_puhunan AS total_puhunan,
                dbl.total_tubo AS total_tubo,
                dbl.id" . $prefixed_string . "
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

        $monitoredItems = [];
        $sqlMonitoredItem = "
            SELECT * FROM daily_financial_snapshots AS dfs
            LEFT JOIN inventory AS i 
            ON i.id = dfs.inventory_id
            WHERE i.id IN (".implode(",", $monitoredIds).")
        ";
        $query = Yii::$app->db->createCommand($sqlMonitoredItem)->queryAll();
        $previous_array = [];
        $query_mi = [];
        foreach($data as $key => $parent) {
            $previous = $this->getPreviousLedger($parent["date"]);
            if (!$previous) { 
                $previous = [
                    "money_on_hand" => 0,
                    "total_puhunan" => 0,
                    "total_tubo"    => 0,
                ];
            }
            $previous_array[] = $previous;

            $data[$key]["money_on_hand"] = $previous["money_on_hand"];
            $data[$key]["money_on_hand_str"] = $previous["money_on_hand"];

            $data[$key]["total_puhunan"] = $previous["total_puhunan"];
            $data[$key]["total_puhunan_str"] = $previous["total_puhunan"];

            $data[$key]["total_tubo"] = $previous["total_tubo"];
            $data[$key]["total_tubo_str"] = $previous["total_tubo"];

            $query_mi[] = [];
            foreach($query as $child) {

                // $data[$key]["tubo"] = 0;
                // $data[$key]["money_on_hand"] = 0;
                // $data[$key]["total_amount"] = 0;
                // $child["tubo"] = 0;

                // $query_mi[] = "SELECT * FROM monitored_items WHERE ledger_id = :ledger_id".$data[$key]["id"]." | ".$child["inventory_id"];
                // Yii::$app->db->createCommand("")->bindValue(':ledger_id', $data[$key]["id"])->queryOne();
                // $data[$key]["ex_".$child["inventory_id"]] = 0 ;

                if ($parent["date"] == $child["report_date"]." 00:00:00") {
                    $data[$key]["p_".$child["inventory_id"]] = ($data[$key]["p_".$child["inventory_id"]] ?? 0) + $child["puhunan"];
                    $data[$key]["t_".$child["inventory_id"]] = ($data[$key]["t_".$child["inventory_id"]] ?? 0) + $child["tubo"];
                    $data[$key]["ts_".$child["inventory_id"]] = ($data[$key]["ts_".$child["inventory_id"]] ?? 0) + $child["total_sales"];
                    $data[$key]["pn_".$child["inventory_id"]] = $child["product_name"];
                    $data[$key]["i_".$child["inventory_id"]] = $child["product_name"];

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
                // $data[$key]["money_on_hand"] = 0;
                // $data[$key]["total_puhunan"] = 0;
                // $data[$key]["total_tubo"] = 0;
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

        $totalPuhunan = (object) Yii::$app->db->createCommand("SELECT SUM(puhunan) AS amount FROM daily_financial_snapshots WHERE inventory_id != 0")->queryOne();
        $totalTubo = (object) Yii::$app->db->createCommand("SELECT SUM(tubo) AS amount FROM daily_financial_snapshots WHERE inventory_id != 0")->queryOne();
        $totalSales = (object) Yii::$app->db->createCommand("SELECT SUM(total_sales) AS amount FROM daily_financial_snapshots WHERE inventory_id != 0")->queryOne();

        $totalPuhunanCement = (object) Yii::$app->db->createCommand("SELECT SUM(puhunan) AS amount FROM daily_financial_snapshots WHERE inventory_id = 21")->queryOne();
        $totalTuboCement = (object) Yii::$app->db->createCommand("SELECT SUM(tubo) AS amount FROM daily_financial_snapshots WHERE inventory_id = 21")->queryOne();

        $totalPuhunanRSB = (object) Yii::$app->db->createCommand("SELECT SUM(puhunan) AS amount FROM daily_financial_snapshots WHERE inventory_id IN (1421,1422,1423)")->queryOne();
        $totalTuboRSB = (object) Yii::$app->db->createCommand("SELECT SUM(tubo) AS amount FROM daily_financial_snapshots WHERE inventory_id IN (1421,1422,1423)")->queryOne();

        // $totalPuhunanAll = (object) Yii::$app->db->createCommand("SELECT SUM(puhunan) AS amount FROM daily_business_ledger")->queryOne();
        // $totalTuboAll = (object) Yii::$app->db->createCommand("SELECT SUM(tubo) AS amount FROM daily_business_ledger")->queryOne();

        $totalPuhunanAll = $totalPuhunan->amount;
        $totalTuboAll = $totalTubo->amount;

        $totalPuhunan->amount = $totalPuhunan->amount - ($totalPuhunanCement->amount + $totalPuhunanRSB->amount);
        $totalTubo->amount = $totalTubo->amount - ($totalTuboCement->amount + $totalTuboRSB->amount);

        return [
            'data' => $data,
            'query_mi' => $query_mi,
            'monitored_items' => $dataInventory,
            'count' => count($data),
            'previous_array' => $previous_array,
            'mids' => $dataInventory,
            'sqlMonitoredItem' => $sqlMonitoredItem,
            'success' => true,
            'headers' => json_encode($tableHeader),

            'totalPuhunan' => $totalPuhunan->amount,
            'totalTubo' => $totalTubo->amount,
            'totalSales' => $totalSales->amount,
            'totalPuhunanCement' => $totalPuhunanCement->amount,
            'totalTuboCement' => $totalTuboCement->amount,
            'totalPuhunanRSB' => $totalPuhunanRSB->amount,
            'totalTuboRSB' => $totalTuboRSB->amount,
            'totalPuhunanAll' => $totalPuhunanAll,
            'totalTuboAll' => $totalTuboAll,
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

    public function actionUpdateledgervalue() 
    {
        // 1. Force JSON response format
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        if (Yii::$app->request->method !== 'PUT') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        // 2. Extract parameters from the POST body
        $id = Yii::$app->request->getBodyParam('id');
        $amount = Yii::$app->request->getBodyParam('amount');
        $details = Yii::$app->request->getBodyParam('details');
        $save_type = Yii::$app->request->getBodyParam('save_type');

        // 3. Validation: Make sure the required fields exist
        if (!$id) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'ID parameter is required'];
        }
        if ($amount === null || $details === null) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'Both '.$save_type.' and '.$save_type.'_details are required'];
        }

        try {
            // 4. Execute the update query using parameters
            $save_type = ($save_type == "bahay" || $save_type == "hardware") ? $save_type : "ex_" . $save_type ;
            $sql = "UPDATE daily_business_ledger SET ".$save_type." = :amount, ".$save_type."_details = :details WHERE id = :id";

            $rowsAffected = Yii::$app->db->createCommand($sql)
                ->bindValues([
                    ':amount' => $amount,
                    ':details' => $details,
                    ':id' => $id,
                ])
                ->execute();

            // 5. Return success status
            return [
                'success' => true,
                'message' => 'Ledger updated successfully',
                'rows_affected' => $rowsAffected
            ];

        } catch (\Exception $e) {
            // Handle database errors gracefully without crashing the API
            Yii::$app->response->statusCode = 500;
            return [
                'success' => false,
                'error' => 'Database error: ' . $e->getMessage()
            ];
        }
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

    public function actionUpdatereportmonthlyold()
    {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        if (Yii::$app->request->method !== 'POST') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $runningMoneyHand = Yii::$app->request->getBodyParam('running_money_on_hand');
        $runningTubo = Yii::$app->request->getBodyParam('running_tubo');
        $runningPuhunan = Yii::$app->request->getBodyParam('running_puhunan');

        $inputDate = Yii::$app->request->getBodyParam('date');
        if (!$inputDate) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'Date parameter is required'];
        }

        $time = strtotime($inputDate);
        if ($time === false) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'Invalid date format provided'];
        }

        $startDate = date('Y-m-01', $time);                        // e.g., '2026-08-01'
        $endDate   = date('Y-m-01', strtotime('+1 month', $time)); // e.g., '2026-09-01'

        $transaction = Yii::$app->db->beginTransaction();

        try {
            // 1. Delete target month snapshots
            $sqlDeleteSnapshots = "
                DELETE FROM daily_financial_snapshots_v2
                WHERE report_date >= :start_date AND report_date < :end_date
            ";
            Yii::$app->db->createCommand($sqlDeleteSnapshots)
                ->bindValue(':start_date', $startDate)
                ->bindValue(':end_date', $endDate)
                ->execute();

            // 2. Insert itemized breakdown for target month
            $sqlInsertSnapshots = "
                INSERT INTO daily_financial_snapshots_v2
                    (report_date, inventory_id, source_type, source_item_id, puhunan, tubo, total_sales, monitored)
                SELECT 
                    DATE(s.date_sold) AS report_date,
                    si.inventory_id,
                    'sale' AS source_type,
                    si.id AS source_item_id,
                    (si.qty_sold * si.cost_per_unit) AS puhunan,
                    (si.total - (si.qty_sold * si.cost_per_unit)) AS tubo,
                    si.total AS total_sales,
                    i.monitored
                FROM sales s
                JOIN sales_items si ON s.id = si.sales_id
                LEFT JOIN inventory i ON si.inventory_id = i.id
                WHERE s.date_sold >= :start_date AND s.date_sold < :end_date
                  AND s.status = 'approved' AND s.is_paid = 'yes'
                ORDER BY s.date_sold ASC, si.id ASC
                ON DUPLICATE KEY UPDATE 
                    puhunan = VALUES(puhunan), 
                    tubo = VALUES(tubo), 
                    total_sales = VALUES(total_sales);
            ";
            Yii::$app->db->createCommand($sqlInsertSnapshots)
                ->bindValue(':start_date', $startDate)
                ->bindValue(':end_date', $endDate)
                ->execute();

            // 3. Query daily aggregated sales from snapshots for target month
            $dailyAggregates = Yii::$app->db->createCommand("
                SELECT 
                    report_date,
                    SUM(puhunan) AS day_puhunan,
                    SUM(tubo) AS day_tubo,
                    SUM(total_sales) AS day_sales
                FROM daily_financial_snapshots_v2
                WHERE report_date >= :start_date AND report_date < :end_date AND monitored = 0
                GROUP BY report_date
                ORDER BY report_date ASC
            ")->bindValue(':start_date', $startDate)
              ->bindValue(':end_date', $endDate)
              ->queryAll();

            // Map sales aggregates by report_date for fast lookup
            $salesByDate = [];
            foreach ($dailyAggregates as $row) {
                $salesByDate[$row['report_date']] = $row;
            }

            // 4. Loop day-by-day through the entire month
            $currentTimestamp = strtotime($startDate);
            $endTimestamp     = strtotime($endDate);

            // Prepare UPSERT query (Insert if new date, Update financials if existing date)
            $sqlUpsertDay = "
                INSERT INTO daily_business_ledger_v2 (
                    report_date, inventory_id, source_type, source_item_id,
                    puhunan, tubo, total_sales,
                    starting_puhunan, starting_tubo, starting_money_on_hand,
                    total_puhunan, total_tubo, money_on_hand
                ) VALUES (
                    :report_date, 0, 'daily_summary', 0,
                    :puhunan, :tubo, :total_sales,
                    :starting_puhunan, :starting_tubo, :starting_money_on_hand,
                    :total_puhunan, :total_tubo, :money_on_hand
                )
                ON DUPLICATE KEY UPDATE
                    puhunan                = VALUES(puhunan),
                    tubo                   = VALUES(tubo),
                    total_sales            = VALUES(total_sales),
                    starting_puhunan       = VALUES(starting_puhunan),
                    starting_tubo          = VALUES(starting_tubo),
                    starting_money_on_hand = VALUES(starting_money_on_hand),
                    total_puhunan          = VALUES(total_puhunan),
                    total_tubo             = VALUES(total_tubo),
                    money_on_hand          = VALUES(money_on_hand);
            ";

            $cmdUpsertDay = Yii::$app->db->createCommand($sqlUpsertDay);

            // Remove: $sqlDeleteLedger query execution completely! Do NOT delete ledger rows.

            while ($currentTimestamp < $endTimestamp) {
                $dateStr = date('Y-m-d', $currentTimestamp);

                // Fetch day's sales from snapshots array
                $daySales   = isset($salesByDate[$dateStr]) ? (float)$salesByDate[$dateStr]['day_sales'] : 0.00;
                $dayPuhunan = isset($salesByDate[$dateStr]) ? (float)$salesByDate[$dateStr]['day_puhunan'] : 0.00;
                $dayTubo    = isset($salesByDate[$dateStr]) ? (float)$salesByDate[$dateStr]['day_tubo'] : 0.00;

                // Set starting balances from previous day's ending totals
                $startingPuhunan   = $runningPuhunan;
                $startingTubo      = $runningTubo;
                $startingMoneyHand = $runningMoneyHand;

                // Accumulate ending totals
                $runningPuhunan   += $dayPuhunan;
                $runningTubo      += $dayTubo;
                $runningMoneyHand += $daySales;

                // Only update/insert days with active sales
                if ($daySales != 0) {
                    $cmdUpsertDay->bindValues([
                        ':report_date'            => $dateStr,
                        ':puhunan'                => $dayPuhunan,
                        ':tubo'                   => $dayTubo,
                        ':total_sales'            => $daySales,
                        ':starting_puhunan'       => $startingPuhunan,
                        ':starting_tubo'          => $startingTubo,
                        ':starting_money_on_hand' => $startingMoneyHand,
                        ':total_puhunan'          => $runningPuhunan,
                        ':total_tubo'             => $runningTubo,
                        ':money_on_hand'          => $runningMoneyHand,
                    ])->execute();
                }

                // Advance to next day
                $currentTimestamp = strtotime('+1 day', $currentTimestamp);
            }

            $transaction->commit();

            return [
                'success' => true,
                'message' => 'Monthly ledger regenerated with rolling totals.',
                'period'  => [
                    'start_date' => $startDate,
                    'end_date'   => date('Y-m-d', strtotime('-1 day', $endTimestamp)),
                ]
            ];

        } catch (\Exception $e) {
            $transaction->rollBack();
            Yii::$app->response->statusCode = 500;
            return [
                'success' => false,
                'error'   => 'Failed to update ledger reports: ' . $e->getMessage()
            ];
        }
    }

    public function actionUpdatereportmonthlyOld2()
    {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        if (Yii::$app->request->method !== 'POST') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $inputDate = Yii::$app->request->getBodyParam('date');
        if (!$inputDate) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'Date parameter is required'];
        }

        $time = strtotime($inputDate);
        if ($time === false) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'Invalid date format provided'];
        }

        $startDate = date('Y-m-01', $time);
        $endDate   = date('Y-m-01', strtotime('+1 month', $time));

        // Get initial running parameters or fetch automatically from previous day's record
        $initialMoneyHand = Yii::$app->request->getBodyParam('running_money_on_hand');
        $initialTubo      = Yii::$app->request->getBodyParam('running_tubo');
        $initialPuhunan   = Yii::$app->request->getBodyParam('running_puhunan');

        // Fallback: If not passed in request body, get ending balances from day prior to start_date
        if ($initialMoneyHand === null || $initialTubo === null || $initialPuhunan === null) {
            $prevDayLedger = Yii::$app->db->createCommand("
                SELECT total_puhunan, total_tubo, money_on_hand 
                FROM daily_business_ledger_v2 
                WHERE report_date < :start_date 
                ORDER BY report_date DESC 
                LIMIT 1
            ")->bindValue(':start_date', $startDate)->queryOne();

            $runningPuhunan   = $prevDayLedger ? (float)$prevDayLedger['total_puhunan'] : 0.00;
            $runningTubo      = $prevDayLedger ? (float)$prevDayLedger['total_tubo'] : 0.00;
            $runningMoneyHand = $prevDayLedger ? (float)$prevDayLedger['money_on_hand'] : 0.00;
        } else {
            $runningPuhunan   = (float)$initialPuhunan;
            $runningTubo      = (float)$initialTubo;
            $runningMoneyHand = (float)$initialMoneyHand;
        }

        $transaction = Yii::$app->db->beginTransaction();

        try {
            // 1. Clear monthly snapshots
            Yii::$app->db->createCommand("
                DELETE FROM daily_financial_snapshots_v2
                WHERE report_date >= :start_date AND report_date < :end_date
            ")->bindValue(':start_date', $startDate)
              ->bindValue(':end_date', $endDate)
              ->execute();

            // 2. Refresh itemized sales snapshots
            Yii::$app->db->createCommand("
                INSERT INTO daily_financial_snapshots_v2
                    (report_date, inventory_id, source_type, source_item_id, puhunan, tubo, total_sales, monitored)
                SELECT 
                    DATE(s.date_sold) AS report_date,
                    si.inventory_id,
                    'sale' AS source_type,
                    si.id AS source_item_id,
                    (si.qty_sold * si.cost_per_unit) AS puhunan,
                    (si.total - (si.qty_sold * si.cost_per_unit)) AS tubo,
                    si.total AS total_sales,
                    i.monitored
                FROM sales s
                JOIN sales_items si ON s.id = si.sales_id
                LEFT JOIN inventory i ON si.inventory_id = i.id
                WHERE s.date_sold >= :start_date AND s.date_sold < :end_date
                  AND s.status = 'approved' AND s.is_paid = 'yes'
                ORDER BY s.date_sold ASC, si.id ASC
                ON DUPLICATE KEY UPDATE 
                    puhunan = VALUES(puhunan), 
                    tubo = VALUES(tubo), 
                    total_sales = VALUES(total_sales);
            ")->bindValue(':start_date', $startDate)
              ->bindValue(':end_date', $endDate)
              ->execute();

            // 3. Query unmonitored sales grouped per day
            $dailyAggregates = Yii::$app->db->createCommand("
                SELECT 
                    report_date,
                    SUM(puhunan) AS day_puhunan,
                    SUM(tubo) AS day_tubo,
                    SUM(total_sales) AS day_sales
                FROM daily_financial_snapshots_v2
                WHERE report_date >= :start_date AND report_date < :end_date AND monitored = 0
                GROUP BY report_date
                ORDER BY report_date ASC
            ")->bindValue(':start_date', $startDate)
              ->bindValue(':end_date', $endDate)
              ->queryAll();

            $salesByDate = [];
            foreach ($dailyAggregates as $row) {
                $salesByDate[$row['report_date']] = $row;
            }

            // 4. Chronological recalculation loop
            $currentTimestamp = strtotime($startDate);
            $endTimestamp     = strtotime($endDate);

            $sqlUpsertDay = "
                INSERT INTO daily_business_ledger_v2 (
                    report_date, inventory_id, source_type, source_item_id,
                    puhunan, tubo, total_sales,
                    starting_puhunan, starting_tubo, starting_money_on_hand,
                    total_puhunan, total_tubo, money_on_hand
                ) VALUES (
                    :report_date, 0, 'daily_summary', 0,
                    :puhunan, :tubo, :total_sales,
                    :starting_puhunan, :starting_tubo, :starting_money_on_hand,
                    :total_puhunan, :total_tubo, :money_on_hand
                )
                ON DUPLICATE KEY UPDATE
                    puhunan                = VALUES(puhunan),
                    tubo                   = VALUES(tubo),
                    total_sales            = VALUES(total_sales),
                    starting_puhunan       = VALUES(starting_puhunan),
                    starting_tubo          = VALUES(starting_tubo),
                    starting_money_on_hand = VALUES(starting_money_on_hand),
                    total_puhunan          = VALUES(total_puhunan),
                    total_tubo             = VALUES(total_tubo),
                    money_on_hand          = VALUES(money_on_hand);
            ";

            $cmdUpsertDay = Yii::$app->db->createCommand($sqlUpsertDay);

            while ($currentTimestamp < $endTimestamp) {
                $dateStr = date('Y-m-d', $currentTimestamp);

                $daySales   = isset($salesByDate[$dateStr]) ? (float)$salesByDate[$dateStr]['day_sales'] : 0.00;
                $dayPuhunan = isset($salesByDate[$dateStr]) ? (float)$salesByDate[$dateStr]['day_puhunan'] : 0.00;
                $dayTubo    = isset($salesByDate[$dateStr]) ? (float)$salesByDate[$dateStr]['day_tubo'] : 0.00;

                // Set starting values for current day from previous iteration ending totals
                $startingPuhunan   = $runningPuhunan;
                $startingTubo      = $runningTubo;
                $startingMoneyHand = $runningMoneyHand;

                // Compute current day ending totals
                $runningPuhunan   += $dayPuhunan;
                $runningTubo      += $dayTubo;
                $runningMoneyHand += $daySales;

                // Always update/insert active sales days to maintain ledger continuity
                if ($daySales > 0) {
                    $cmdUpsertDay->bindValues([
                        ':report_date'            => $dateStr,
                        ':puhunan'                => $dayPuhunan,
                        ':tubo'                   => $dayTubo,
                        ':total_sales'            => $daySales,
                        ':starting_puhunan'       => $startingPuhunan,
                        ':starting_tubo'          => $startingTubo,
                        ':starting_money_on_hand' => $startingMoneyHand,
                        ':total_puhunan'          => $runningPuhunan,
                        ':total_tubo'             => $runningTubo,
                        ':money_on_hand'          => $runningMoneyHand,
                    ])->execute();
                }

                $currentTimestamp = strtotime('+1 day', $currentTimestamp);
            }

            $transaction->commit();

            return [
                'success' => true,
                'message' => 'Monthly ledger regenerated successfully.',
                'period'  => [
                    'start_date' => $startDate,
                    'end_date'   => date('Y-m-d', strtotime('-1 day', $endTimestamp)),
                ]
            ];

        } catch (\Exception $e) {
            $transaction->rollBack();
            Yii::$app->response->statusCode = 500;
            return [
                'success' => false,
                'error'   => 'Failed to update ledger reports: ' . $e->getMessage()
            ];
        }
    }

    public function actionUpdatereportmonthlyOld3()
    {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        if (Yii::$app->request->method !== 'POST') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $inputDate = Yii::$app->request->getBodyParam('date');
        if (!$inputDate) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'Date parameter is required'];
        }

        $time = strtotime($inputDate);
        if ($time === false) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'Invalid date format provided'];
        }

        $startDate = date('Y-m-01', $time);
        $endDate   = date('Y-m-01', strtotime('+1 month', $time));

        // Get initial running parameters if passed via request body
        $initialMoneyHand = Yii::$app->request->getBodyParam('running_money_on_hand');
        $initialTubo      = Yii::$app->request->getBodyParam('running_tubo');
        $initialPuhunan   = Yii::$app->request->getBodyParam('running_puhunan');

        // Fallback: Query ending balances from the day BEFORE start_date (e.g., July 31)
        if ($initialMoneyHand === null || $initialTubo === null || $initialPuhunan === null) {
            $prevDayLedger = Yii::$app->db->createCommand("
                SELECT total_puhunan, total_tubo, money_on_hand 
                FROM daily_business_ledger_v2 
                WHERE report_date < :start_date 
                ORDER BY report_date DESC 
                LIMIT 1
            ")->bindValue(':start_date', $startDate)->queryOne();

            $runningPuhunan   = $prevDayLedger ? (float)$prevDayLedger['total_puhunan'] : 0.00;
            $runningTubo      = $prevDayLedger ? (float)$prevDayLedger['total_tubo'] : 0.00;
            $runningMoneyHand = $prevDayLedger ? (float)$prevDayLedger['money_on_hand'] : 0.00;
        } else {
            $runningPuhunan   = (float)$initialPuhunan;
            $runningTubo      = (float)$initialTubo;
            $runningMoneyHand = (float)$initialMoneyHand;
        }

        $transaction = Yii::$app->db->beginTransaction();

        try {
            // 1. Delete target month snapshots
            Yii::$app->db->createCommand("
                DELETE FROM daily_financial_snapshots_v2
                WHERE report_date >= :start_date AND report_date < :end_date
            ")->bindValue(':start_date', $startDate)
              ->bindValue(':end_date', $endDate)
              ->execute();

            // 2. Insert refreshed itemized sales snapshots
            Yii::$app->db->createCommand("
                INSERT INTO daily_financial_snapshots_v2
                    (report_date, inventory_id, source_type, source_item_id, puhunan, tubo, total_sales, monitored)
                SELECT 
                    DATE(s.date_sold) AS report_date,
                    si.inventory_id,
                    'sale' AS source_type,
                    si.id AS source_item_id,
                    (si.qty_sold * si.cost_per_unit) AS puhunan,
                    (si.total - (si.qty_sold * si.cost_per_unit)) AS tubo,
                    si.total AS total_sales,
                    i.monitored
                FROM sales s
                JOIN sales_items si ON s.id = si.sales_id
                LEFT JOIN inventory i ON si.inventory_id = i.id
                WHERE s.date_sold >= :start_date AND s.date_sold < :end_date
                  AND s.status = 'approved' AND s.is_paid = 'yes'
                ORDER BY s.date_sold ASC, si.id ASC
                ON DUPLICATE KEY UPDATE 
                    puhunan = VALUES(puhunan), 
                    tubo = VALUES(tubo), 
                    total_sales = VALUES(total_sales);
            ")->bindValue(':start_date', $startDate)
              ->bindValue(':end_date', $endDate)
              ->execute();

            // 3. Query unmonitored sales aggregates grouped by day
            $dailyAggregates = Yii::$app->db->createCommand("
                SELECT 
                    report_date,
                    SUM(puhunan) AS day_puhunan,
                    SUM(tubo) AS day_tubo,
                    SUM(total_sales) AS day_sales
                FROM daily_financial_snapshots_v2
                WHERE report_date >= :start_date AND report_date < :end_date AND monitored = 0
                GROUP BY report_date
                ORDER BY report_date ASC
            ")->bindValue(':start_date', $startDate)
              ->bindValue(':end_date', $endDate)
              ->queryAll();

            $salesByDate = [];
            foreach ($dailyAggregates as $row) {
                $salesByDate[$row['report_date']] = $row;
            }

            // 4. Query monitored sales aggregated by inventory_id and report_date
            $monitoredRows = Yii::$app->db->createCommand("
                SELECT 
                    inventory_id,
                    report_date,
                    SUM(puhunan) AS day_puhunan,
                    SUM(tubo) AS day_tubo,
                    SUM(total_sales) AS day_sales
                FROM daily_financial_snapshots_v2
                WHERE report_date >= :start_date AND report_date < :end_date AND monitored = 1
                GROUP BY inventory_id, report_date
                ORDER BY inventory_id ASC, report_date ASC
            ")->bindValue(':start_date', $startDate)
              ->bindValue(':end_date', $endDate)
              ->queryAll();

            // Group data into nested array: $monitoredByInventory[inventory_id][report_date]
            $monitoredByInventory = [];

            foreach ($monitoredRows as $row) {
                $invId   = (int)$row['inventory_id'];
                $repDate = $row['report_date'];

                if (!isset($monitoredByInventory[$invId])) {
                    $monitoredByInventory[$invId] = [];
                }

                $monitoredByInventory[$invId][$repDate] = [
                    'day_puhunan' => (float)$row['day_puhunan'],
                    'day_tubo'    => (float)$row['day_tubo'],
                    'day_sales'   => (float)$row['day_sales'],
                ];
            }

            // 5. Fetch existing manual expenses per day (hardware, bahay, ex_*) to preserve them
            $existingExpenses = Yii::$app->db->createCommand("
                SELECT 
                    report_date,
                    -- (hardware + bahay + ex_21 + ex_1423 + ex_1422 + ex_1421 + ex_1894 + ex_1895 + ex_1899 + ex_1900 + ex_1905) AS total_expenses,
                    hardware,
                    bahay
                FROM daily_business_ledger_v2
                WHERE report_date >= :start_date AND report_date < :end_date
            ")->bindValue(':start_date', $startDate)
              ->bindValue(':end_date', $endDate)
              ->queryAll();

            $hardwareDate = [];
            $bahayDate = [];
            foreach ($existingExpenses as $exp) {
                $hardwareDate[$exp['report_date']] = (float)$exp['hardware'];
                $bahayDate[$exp['report_date']] = (float)$exp['bahay'];
            }


            // 6. Prepare UPSERT Command
            $sqlUpsertDay = "
                INSERT INTO daily_business_ledger_v2 (
                    report_date, inventory_id, source_type, source_item_id,
                    puhunan, tubo, total_sales,
                    starting_puhunan, starting_tubo, starting_money_on_hand,
                    total_puhunan, total_tubo, money_on_hand
                ) VALUES (
                    :report_date, 0, 'daily_summary', 0,
                    :puhunan, :tubo, :total_sales,
                    :starting_puhunan, :starting_tubo, :starting_money_on_hand,
                    :total_puhunan, :total_tubo, :money_on_hand
                )
                ON DUPLICATE KEY UPDATE
                    puhunan                = VALUES(puhunan),
                    tubo                   = VALUES(tubo),
                    total_sales            = VALUES(total_sales),
                    starting_puhunan       = VALUES(starting_puhunan),
                    starting_tubo          = VALUES(starting_tubo),
                    starting_money_on_hand = VALUES(starting_money_on_hand),
                    total_puhunan          = VALUES(total_puhunan),
                    total_tubo             = VALUES(total_tubo),
                    money_on_hand          = VALUES(money_on_hand);
            ";

            $cmdUpsertDay = Yii::$app->db->createCommand($sqlUpsertDay);

            // 6. Chronological Calculation Loop
            $currentTimestamp = strtotime($startDate);
            $endTimestamp     = strtotime($endDate);

            while ($currentTimestamp < $endTimestamp) {
                $dateStr = date('Y-m-d', $currentTimestamp);

                // Fetch day sales
                $daySales   = isset($salesByDate[$dateStr]) ? (float)$salesByDate[$dateStr]['day_sales'] : 0.00;
                $dayPuhunan = isset($salesByDate[$dateStr]) ? (float)$salesByDate[$dateStr]['day_puhunan'] : 0.00;
                $dayTubo    = isset($salesByDate[$dateStr]) ? (float)$salesByDate[$dateStr]['day_tubo'] : 0.00;

                // Fetch existing manual expenses for current date
                $hardwerDeduct = isset($hardwareDate[$dateStr]) ? $hardwareDate[$dateStr] : 0.00;
                $bahayDeduct = isset($bahayDate[$dateStr]) ? $bahayDate[$dateStr] : 0.00;

                // Carry over previous day ending values to current day starting values
                $startingPuhunan   = $runningPuhunan;
                $startingTubo      = $runningTubo;
                $startingMoneyHand = $runningMoneyHand;

                // Calculate current day net additions
                $runningPuhunan   += ($dayPuhunan - $hardwerDeduct);
                $runningTubo      += ($dayTubo - $bahayDeduct);
                $runningMoneyHand += ($daySales - $hardwerDeduct - $bahayDeduct);

                // Execute update/insert if there are sales OR manual expenses present
                if ($daySales > 0) {
                    $cmdUpsertDay->bindValues([
                        ':report_date'            => $dateStr,
                        ':puhunan'                => $dayPuhunan,
                        ':tubo'                   => $dayTubo,
                        ':total_sales'            => $daySales,
                        ':starting_puhunan'       => $startingPuhunan,
                        ':starting_tubo'          => $startingTubo,
                        ':starting_money_on_hand' => $startingMoneyHand,
                        ':total_puhunan'          => $runningPuhunan,
                        ':total_tubo'             => $runningTubo,
                        ':money_on_hand'          => $runningMoneyHand,
                    ])->execute();
                }

                $currentTimestamp = strtotime('+1 day', $currentTimestamp);
            }

            $transaction->commit();

            return [
                'data' => $monitoredByInventory,
                'success' => true,
                'message' => 'Monthly ledger regenerated with manual expense deductions.',
                'period'  => [
                    'start_date' => $startDate,
                    'end_date'   => date('Y-m-d', strtotime('-1 day', $endTimestamp)),
                ]
            ];

        } catch (\Exception $e) {
            $transaction->rollBack();
            Yii::$app->response->statusCode = 500;
            return [
                'success' => false,
                'error'   => 'Failed to update ledger reports: ' . $e->getMessage()
            ];
        }
    }

    public function actionUpdatereportmonthlyOld4()
    {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        if (Yii::$app->request->method !== 'POST') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $inputDate = Yii::$app->request->getBodyParam('date');
        if (!$inputDate) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'Date parameter is required'];
        }

        $time = strtotime($inputDate);
        if ($time === false) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'Invalid date format provided'];
        }

        $startDate = date('Y-m-01', $time);
        $endDate   = date('Y-m-01', strtotime('+1 month', $time));

        // Get initial running parameters if passed via request body
        $initialMoneyHand = Yii::$app->request->getBodyParam('running_money_on_hand');
        $initialTubo      = Yii::$app->request->getBodyParam('running_tubo');
        $initialPuhunan   = Yii::$app->request->getBodyParam('running_puhunan');

        // Fallback: Query ending balances from the day BEFORE start_date (e.g., July 31)
        if ($initialMoneyHand === null || $initialTubo === null || $initialPuhunan === null) {
            $prevDayLedger = Yii::$app->db->createCommand("
                SELECT total_puhunan, total_tubo, money_on_hand 
                FROM daily_business_ledger_v2 
                WHERE report_date < :start_date 
                ORDER BY report_date DESC 
                LIMIT 1
            ")->bindValue(':start_date', $startDate)->queryOne();

            $runningPuhunan   = $prevDayLedger ? (float)$prevDayLedger['total_puhunan'] : 0.00;
            $runningTubo      = $prevDayLedger ? (float)$prevDayLedger['total_tubo'] : 0.00;
            $runningMoneyHand = $prevDayLedger ? (float)$prevDayLedger['money_on_hand'] : 0.00;
        } else {
            $runningPuhunan   = (float)$initialPuhunan;
            $runningTubo      = (float)$initialTubo;
            $runningMoneyHand = (float)$initialMoneyHand;
        }

        $transaction = Yii::$app->db->beginTransaction();

        try {
            // 1. Delete target month snapshots
            Yii::$app->db->createCommand("
                DELETE FROM daily_financial_snapshots_v2
                WHERE report_date >= :start_date AND report_date < :end_date
            ")->bindValue(':start_date', $startDate)
              ->bindValue(':end_date', $endDate)
              ->execute();

            // 2. Insert refreshed itemized sales snapshots
            Yii::$app->db->createCommand("
                INSERT INTO daily_financial_snapshots_v2
                    (report_date, inventory_id, source_type, source_item_id, puhunan, tubo, total_sales, monitored)
                SELECT 
                    DATE(s.date_sold) AS report_date,
                    si.inventory_id,
                    'sale' AS source_type,
                    si.id AS source_item_id,
                    (si.qty_sold * si.cost_per_unit) AS puhunan,
                    (si.total - (si.qty_sold * si.cost_per_unit)) AS tubo,
                    si.total AS total_sales,
                    i.monitored
                FROM sales s
                JOIN sales_items si ON s.id = si.sales_id
                LEFT JOIN inventory i ON si.inventory_id = i.id
                WHERE s.date_sold >= :start_date AND s.date_sold < :end_date
                  AND s.status = 'approved' AND s.is_paid = 'yes'
                ORDER BY s.date_sold ASC, si.id ASC
                ON DUPLICATE KEY UPDATE 
                    puhunan = VALUES(puhunan), 
                    tubo = VALUES(tubo), 
                    total_sales = VALUES(total_sales);
            ")->bindValue(':start_date', $startDate)
              ->bindValue(':end_date', $endDate)
              ->execute();

            // 3. Query unmonitored sales aggregates grouped by day
            $dailyAggregates = Yii::$app->db->createCommand("
                SELECT 
                    report_date,
                    SUM(puhunan) AS day_puhunan,
                    SUM(tubo) AS day_tubo,
                    SUM(total_sales) AS day_sales
                FROM daily_financial_snapshots_v2
                WHERE report_date >= :start_date AND report_date < :end_date AND monitored = 0
                GROUP BY report_date
                ORDER BY report_date ASC
            ")->bindValue(':start_date', $startDate)
              ->bindValue(':end_date', $endDate)
              ->queryAll();

            $salesByDate = [];
            foreach ($dailyAggregates as $row) {
                $salesByDate[$row['report_date']] = $row;
            }

            // 4. Query monitored sales aggregated by inventory_id and report_date
            $monitoredRows = Yii::$app->db->createCommand("
                SELECT 
                    inventory_id,
                    report_date,
                    SUM(puhunan) AS day_puhunan,
                    SUM(tubo) AS day_tubo,
                    SUM(total_sales) AS day_sales
                FROM daily_financial_snapshots_v2
                WHERE report_date >= :start_date AND report_date < :end_date AND monitored = 1
                GROUP BY inventory_id, report_date
                ORDER BY inventory_id ASC, report_date ASC
            ")->bindValue(':start_date', $startDate)
              ->bindValue(':end_date', $endDate)
              ->queryAll();

            // Group data into nested array: $monitoredByInventory[inventory_id][report_date]
            $monitoredByInventory = [];
            $monitoredInventoryIds = [];

            foreach ($monitoredRows as $row) {
                $invId   = (int)$row['inventory_id'];
                $repDate = $row['report_date'];

                if (!isset($monitoredByInventory[$invId])) {
                    $monitoredByInventory[$invId] = [];
                }

                $monitoredByInventory[$invId][$repDate] = [
                    'day_puhunan' => (float)$row['day_puhunan'],
                    'day_tubo'    => (float)$row['day_tubo'],
                    'day_sales'   => (float)$row['day_sales'],
                ];

                if (!in_array($invId, $monitoredInventoryIds)) {
                    $monitoredInventoryIds[] = $invId;
                }
            }

            // 5. Fetch existing manual expenses per day (hardware, bahay, etc.)
            $existingExpenses = Yii::$app->db->createCommand("
                SELECT 
                    report_date,
                    hardware,
                    bahay
                FROM daily_business_ledger_v2
                WHERE report_date >= :start_date AND report_date < :end_date
            ")->bindValue(':start_date', $startDate)
              ->bindValue(':end_date', $endDate)
              ->queryAll();

            $hardwareDate = [];
            $bahayDate    = [];
            foreach ($existingExpenses as $exp) {
                $hardwareDate[$exp['report_date']] = (float)$exp['hardware'];
                $bahayDate[$exp['report_date']]    = (float)$exp['bahay'];
            }

            // 6. Dynamically Build the UPSERT Query with Monitored p_* and t_* Columns
            $insertCols = [
                'report_date', 'inventory_id', 'source_type', 'source_item_id',
                'puhunan', 'tubo', 'total_sales',
                'starting_puhunan', 'starting_tubo', 'starting_money_on_hand',
                'total_puhunan', 'total_tubo', 'money_on_hand'
            ];

            $insertValues = [
                ':report_date', '0', "'daily_summary'", '0',
                ':puhunan', ':tubo', ':total_sales',
                ':starting_puhunan', ':starting_tubo', ':starting_money_on_hand',
                ':total_puhunan', ':total_tubo', ':money_on_hand'
            ];

            $updateClauses = [
                'puhunan                = VALUES(puhunan)',
                'tubo                   = VALUES(tubo)',
                'total_sales            = VALUES(total_sales)',
                'starting_puhunan       = VALUES(starting_puhunan)',
                'starting_tubo          = VALUES(starting_tubo)',
                'starting_money_on_hand = VALUES(starting_money_on_hand)',
                'total_puhunan          = VALUES(total_puhunan)',
                'total_tubo             = VALUES(total_tubo)',
                'money_on_hand          = VALUES(money_on_hand)'
            ];

            // Append dynamic monitored fields (e.g., p_21, t_21, p_1421, t_1421)
            foreach ($monitoredInventoryIds as $invId) {
                $pCol = "p_{$invId}";
                $tCol = "t_{$invId}";

                $insertCols[]   = "`{$pCol}`";
                $insertCols[]   = "`{$tCol}`";

                $insertValues[] = ":{$pCol}";
                $insertValues[] = ":{$tCol}";

                $updateClauses[] = "`{$pCol}` = VALUES(`{$pCol}`)";
                $updateClauses[] = "`{$tCol}` = VALUES(`{$tCol}`)";
            }

            $sqlUpsertDay = "
                INSERT INTO daily_business_ledger_v2 (
                    " . implode(', ', $insertCols) . "
                ) VALUES (
                    " . implode(', ', $insertValues) . "
                )
                ON DUPLICATE KEY UPDATE
                    " . implode(",\n", $updateClauses) . ";
            ";

            $cmdUpsertDay = Yii::$app->db->createCommand($sqlUpsertDay);

            // 7. Chronological Calculation Loop
            $currentTimestamp = strtotime($startDate);
            $endTimestamp     = strtotime($endDate);

            while ($currentTimestamp < $endTimestamp) {
                $dateStr = date('Y-m-d', $currentTimestamp);

                // Fetch unmonitored day sales
                $daySales   = isset($salesByDate[$dateStr]) ? (float)$salesByDate[$dateStr]['day_sales'] : 0.00;
                $dayPuhunan = isset($salesByDate[$dateStr]) ? (float)$salesByDate[$dateStr]['day_puhunan'] : 0.00;
                $dayTubo    = isset($salesByDate[$dateStr]) ? (float)$salesByDate[$dateStr]['day_tubo'] : 0.00;

                // Fetch existing manual expenses
                $hardwerDeduct = isset($hardwareDate[$dateStr]) ? $hardwareDate[$dateStr] : 0.00;
                $bahayDeduct   = isset($bahayDate[$dateStr])    ? $bahayDate[$dateStr]    : 0.00;

                // Carry over previous day ending values to current day starting values
                $startingPuhunan   = $runningPuhunan;
                $startingTubo      = $runningTubo;
                $startingMoneyHand = $runningMoneyHand;

                // Calculate current day net additions (hardware & bahay deducted from tubo & money_on_hand)
                $runningPuhunan   += $dayPuhunan;
                $runningTubo      += ($dayTubo - $hardwerDeduct - $bahayDeduct);
                $runningMoneyHand += ($daySales - $hardwerDeduct - $bahayDeduct);

                // Bind basic daily balances
                $bindParams = [
                    ':report_date'            => $dateStr,
                    ':puhunan'                => $dayPuhunan,
                    ':tubo'                   => $dayTubo,
                    ':total_sales'            => $daySales,
                    ':starting_puhunan'       => $startingPuhunan,
                    ':starting_tubo'          => $startingTubo,
                    ':starting_money_on_hand' => $startingMoneyHand,
                    ':total_puhunan'          => $runningPuhunan,
                    ':total_tubo'             => $runningTubo,
                    ':money_on_hand'          => $runningMoneyHand,
                ];

                // Check if there are monitored sales on this day
                $hasMonitoredSales = false;

                // Bind monitored item values (p_* and t_*) for this date
                foreach ($monitoredInventoryIds as $invId) {
                    $pCol = "p_{$invId}";
                    $tCol = "t_{$invId}";

                    $monPuhunan = 0.00;
                    $monTubo    = 0.00;

                    if (isset($monitoredByInventory[$invId][$dateStr])) {
                        $monPuhunan = $monitoredByInventory[$invId][$dateStr]['day_puhunan'];
                        $monTubo    = $monitoredByInventory[$invId][$dateStr]['day_tubo'];
                        $hasMonitoredSales = true;
                    }

                    $bindParams[":{$pCol}"] = $monPuhunan;
                    $bindParams[":{$tCol}"] = $monTubo;
                }

                // Execute insert/update if there are unmonitored sales, monitored sales, or manual expenses
                if ($daySales > 0 || $hasMonitoredSales || $hardwerDeduct > 0 || $bahayDeduct > 0) {
                    $cmdUpsertDay->bindValues($bindParams)->execute();
                }

                $currentTimestamp = strtotime('+1 day', $currentTimestamp);
            }

            $transaction->commit();

            return [
                'success' => true,
                'message' => 'Monthly ledger regenerated successfully with monitored p_* and t_* mappings.',
                'period'  => [
                    'start_date' => $startDate,
                    'end_date'   => date('Y-m-d', strtotime('-1 day', $endTimestamp)),
                ]
            ];

        } catch (\Exception $e) {
            $transaction->rollBack();
            Yii::$app->response->statusCode = 500;
            return [
                'success' => false,
                'error'   => 'Failed to update ledger reports: ' . $e->getMessage()
            ];
        }
    }

    public function actionUpdatereportmonthly()
    {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        if (Yii::$app->request->method !== 'POST') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $inputDate = Yii::$app->request->getBodyParam('date');
        if (!$inputDate) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'Date parameter is required'];
        }

        // Clean up input spacing (e.g., "08, 2026" -> "08, 2026")
        $cleanDate = trim($inputDate);

        // Attempt parsing "MM, YYYY" (e.g., "08, 2026") or "M, YYYY" (e.g., "8, 2026")
        $d = \DateTime::createFromFormat('m, Y', $cleanDate);
        if (!$d) {
            $d = \DateTime::createFromFormat('n, Y', $cleanDate);
        }
        
        // Fallback: standard strtotime for ISO formats like "2026-08" or "2026-08-01"
        if (!$d) {
            $time = strtotime($cleanDate);
            if ($time !== false) {
                $d = new \DateTime();
                $d->setTimestamp($time);
            }
        }

        if (!$d) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'Invalid date format provided. Expected formats like "08, 2026" or "YYYY-MM"'];
        }

        // Normalize to the 1st of the month
        $startDate = $d->format('Y-m-01');
        
        // Calculate the start of the next month
        $endDateTime = (clone $d)->modify('first day of next month');
        $endDate = $endDateTime->format('Y-m-01');

        // Get initial running parameters if passed via request body
        $initialMoneyHand = Yii::$app->request->getBodyParam('running_money_on_hand');
        $initialTubo      = Yii::$app->request->getBodyParam('running_tubo');
        $initialPuhunan   = Yii::$app->request->getBodyParam('running_puhunan');

        // Fallback: Query ending balances from the day BEFORE start_date
        if ($initialMoneyHand === null || $initialTubo === null || $initialPuhunan === null) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'Initial values parameter is required'];
        } else {
            $runningMoneyHand       = (float)$initialMoneyHand;
            $runningTotalPuhunan    = (float)$initialPuhunan;
            $runningTotalTubo       = (float)$initialTubo;
        }

        // Capture starting month balance to save in monthly_business_ledger_v2
        $startingMonthPuhunan   = $runningTotalPuhunan;
        $startingMonthTubo      = $runningTotalTubo;
        $startingMonthMoneyHand = $runningMoneyHand;

        $transaction = Yii::$app->db->beginTransaction();

        try {
            // 1. Delete target month snapshots
            Yii::$app->db->createCommand("
                DELETE FROM daily_financial_snapshots_v2
                WHERE report_date >= :start_date AND report_date < :end_date
            ")->bindValue(':start_date', $startDate)
              ->bindValue(':end_date', $endDate)
              ->execute();

            // 2. Insert refreshed itemized sales snapshots
            Yii::$app->db->createCommand("
                INSERT INTO daily_financial_snapshots_v2
                    (report_date, inventory_id, source_type, category_type, source_item_id, puhunan, tubo, total_sales, monitored)
                SELECT 
                    DATE(s.date_sold) AS report_date,
                    si.inventory_id,
                    'sale' AS source_type,
                    i.type AS category_type,
                    si.id AS source_item_id,
                    (si.qty_sold * si.cost_per_unit) AS puhunan,
                    (si.total - (si.qty_sold * si.cost_per_unit)) AS tubo,
                    si.total AS total_sales,
                    i.monitored
                FROM sales s
                JOIN sales_items si ON s.id = si.sales_id
                LEFT JOIN inventory i ON si.inventory_id = i.id
                WHERE s.date_sold >= :start_date AND s.date_sold < :end_date
                  AND s.status = 'approved' AND s.is_paid = 'yes'
                ORDER BY s.date_sold ASC, si.id ASC
                ON DUPLICATE KEY UPDATE 
                    puhunan = VALUES(puhunan), 
                    tubo = VALUES(tubo), 
                    total_sales = VALUES(total_sales);
            ")->bindValue(':start_date', $startDate)
              ->bindValue(':end_date', $endDate)
              ->execute();

            // 3. Query unmonitored sales aggregates grouped by day
            $dailyAggregates = Yii::$app->db->createCommand("
                SELECT 
                    report_date,
                    SUM(puhunan) AS day_puhunan,
                    SUM(tubo) AS day_tubo,
                    SUM(total_sales) AS day_sales
                FROM daily_financial_snapshots_v2
                WHERE report_date >= :start_date AND report_date < :end_date AND monitored = 0
                GROUP BY report_date
                ORDER BY report_date ASC
            ")->bindValue(':start_date', $startDate)
              ->bindValue(':end_date', $endDate)
              ->queryAll();

            $salesByDate = [];
            foreach ($dailyAggregates as $row) {
                $salesByDate[$row['report_date']] = $row;
            }

            // 4. Query monitored sales aggregated by inventory_id and report_date
            $monitoredRows = Yii::$app->db->createCommand("
                SELECT 
                    inventory_id,
                    report_date,
                    SUM(puhunan) AS day_puhunan,
                    SUM(tubo) AS day_tubo,
                    SUM(total_sales) AS day_sales
                FROM daily_financial_snapshots_v2
                WHERE report_date >= :start_date AND report_date < :end_date AND monitored = 1
                GROUP BY inventory_id, report_date
                ORDER BY inventory_id ASC, report_date ASC
            ")->bindValue(':start_date', $startDate)
              ->bindValue(':end_date', $endDate)
              ->queryAll();

            $monitoredByInventory = [];
            $monitoredInventoryIds = [];

            foreach ($monitoredRows as $row) {
                $invId   = (int)$row['inventory_id'];
                $repDate = $row['report_date'];

                if (!isset($monitoredByInventory[$invId])) {
                    $monitoredByInventory[$invId] = [];
                }

                $monitoredByInventory[$invId][$repDate] = [
                    'day_puhunan' => (float)$row['day_puhunan'],
                    'day_tubo'    => (float)$row['day_tubo'],
                    'day_sales'   => (float)$row['day_sales'],
                ];

                if (!in_array($invId, $monitoredInventoryIds)) {
                    $monitoredInventoryIds[] = $invId;
                }
            }

            // 5. Fetch existing manual expenses per day
            $existingExpenses = Yii::$app->db->createCommand("
                SELECT 
                    report_date,
                    hardware,
                    bahay
                FROM daily_business_ledger_v2
                WHERE report_date >= :start_date AND report_date < :end_date
            ")->bindValue(':start_date', $startDate)
              ->bindValue(':end_date', $endDate)
              ->queryAll();

            $hardwareDate = [];
            $bahayDate    = [];
            foreach ($existingExpenses as $exp) {
                $hardwareDate[$exp['report_date']] = (float)$exp['hardware'];
                $bahayDate[$exp['report_date']]    = (float)$exp['bahay'];
            }

            // 6. Dynamically Build UPSERT Query for Daily Ledger
            $insertCols = [
                'report_date', 'inventory_id', 'source_type', 'source_item_id',
                'puhunan', 'tubo', 'total_sales',
                'starting_puhunan', 'starting_tubo', 'starting_money_on_hand',
                'total_puhunan', 'total_tubo', 'money_on_hand'
            ];

            $insertValues = [
                ':report_date', '0', "'daily_summary'", '0',
                ':puhunan', ':tubo', ':total_sales',
                ':starting_puhunan', ':starting_tubo', ':starting_money_on_hand',
                ':total_puhunan', ':total_tubo', ':money_on_hand'
            ];

            $updateClauses = [
                'puhunan                = VALUES(puhunan)',
                'tubo                   = VALUES(tubo)',
                'total_sales            = VALUES(total_sales)',
                'starting_puhunan       = VALUES(starting_puhunan)',
                'starting_tubo          = VALUES(starting_tubo)',
                'starting_money_on_hand = VALUES(starting_money_on_hand)',
                'total_puhunan          = VALUES(total_puhunan)',
                'total_tubo             = VALUES(total_tubo)',
                'money_on_hand          = VALUES(money_on_hand)'
            ];

            foreach ($monitoredInventoryIds as $invId) {
                $pCol = "p_{$invId}";
                $tCol = "t_{$invId}";

                $insertCols[]   = "`{$pCol}`";
                $insertCols[]   = "`{$tCol}`";

                $insertValues[] = ":{$pCol}";
                $insertValues[] = ":{$tCol}";

                $updateClauses[] = "`{$pCol}` = VALUES(`{$pCol}`)";
                $updateClauses[] = "`{$tCol}` = VALUES(`{$tCol}`)";
            }

            $sqlUpsertDay = "
                INSERT INTO daily_business_ledger_v2 (
                    " . implode(', ', $insertCols) . "
                ) VALUES (
                    " . implode(', ', $insertValues) . "
                )
                ON DUPLICATE KEY UPDATE
                    " . implode(",\n", $updateClauses) . ";
            ";

            $cmdUpsertDay = Yii::$app->db->createCommand($sqlUpsertDay);

            // 7. Chronological Daily Loop
            $currentTimestamp = strtotime($startDate);
            $endTimestamp     = strtotime($endDate);

            $runningMonitoredPuhunan    = 0.00;
            $runningMonitoredTubo       = 0.00;
            $runningPuhunan             = 0.00;
            $runningTubo                = 0.00;
            $runningTotalSales          = 0.00;
            $grandPuhunan               = 0.00;
            $grandTubo                  = 0.00;
            $grandTotalSales            = 0.00;

            while ($currentTimestamp < $endTimestamp) {
                $dateStr = date('Y-m-d', $currentTimestamp);

                $dayPuhunan = isset($salesByDate[$dateStr]) ? (float)$salesByDate[$dateStr]['day_puhunan'] : 0.00;
                $dayTubo    = isset($salesByDate[$dateStr]) ? (float)$salesByDate[$dateStr]['day_tubo'] : 0.00;
                $daySales   = $dayPuhunan + $dayTubo;

                $hardwerDeduct = isset($hardwareDate[$dateStr]) ? $hardwareDate[$dateStr] : 0.00;
                $bahayDeduct   = isset($bahayDate[$dateStr])    ? $bahayDate[$dateStr]    : 0.00;

                $bindParams = [':report_date' => $dateStr];

                $hasMonitoredSales = false;

                $monitoredPuhunan   = 0.00;
                $monitoredTubo      = 0.00;
                foreach ($monitoredInventoryIds as $invId) {
                    $pCol = "p_{$invId}";
                    $tCol = "t_{$invId}";

                    $monPuhunan = 0.00;
                    $monTubo    = 0.00;

                    if (isset($monitoredByInventory[$invId][$dateStr])) {
                        $monPuhunan = $monitoredByInventory[$invId][$dateStr]['day_puhunan'];
                        $monTubo    = $monitoredByInventory[$invId][$dateStr]['day_tubo'];
                        $hasMonitoredSales = true;
                    }

                    $bindParams[":{$pCol}"]     = $monPuhunan;
                    $bindParams[":{$tCol}"]     = $monTubo;

                    $monitoredPuhunan           += $monPuhunan;
                    $monitoredTubo              += $monTubo;
                }

                $dayMoneyOnHand                         = ($runningMoneyHand + $daySales + $monitoredTubo - ($hardwerDeduct + $bahayDeduct));
                $dayTotalPuhunan                        = ($runningTotalPuhunan + $dayPuhunan - $hardwerDeduct);
                $dayTotalTubo                           = ($runningTotalTubo + $dayTubo + $monitoredTubo - $bahayDeduct);

                $bindParams[':puhunan']                 = $dayPuhunan;  
                $bindParams[':tubo']                    = $dayTubo;  
                $bindParams[':total_sales']             = $daySales;  

                $bindParams[':money_on_hand']           = $dayMoneyOnHand;   
                $bindParams[':total_puhunan']           = $dayTotalPuhunan;  
                $bindParams[':total_tubo']              = $dayTotalTubo;  

                $bindParams[':starting_money_on_hand']  = $runningMoneyHand; 
                $bindParams[':starting_puhunan']        = $runningTotalPuhunan;  
                $bindParams[':starting_tubo']           = $runningTotalTubo;    

                $runningPuhunan                         += $dayPuhunan;
                $runningTubo                            += $dayTubo;
                $runningTotalSales                      += $daySales;
                $runningMoneyHand                       = $dayMoneyOnHand;
                $runningTotalPuhunan                    = $dayTotalPuhunan;
                $runningTotalTubo                       = $dayTotalTubo;
                $grandPuhunan                           += ($dayPuhunan + $monitoredPuhunan);
                $grandTubo                              += ($dayTubo + $monitoredTubo);
                $grandTotalSales                        += ($dayPuhunan + $monitoredPuhunan + $dayTubo + $monitoredTubo);

                if ($daySales > 0 || $hasMonitoredSales || $hardwerDeduct > 0 || $bahayDeduct > 0) {
                    $cmdUpsertDay->bindValues($bindParams)->execute();
                }

                $currentTimestamp = strtotime('+1 day', $currentTimestamp);
            }

            $runningFinalPuhunan = $runningPuhunan - $runningMonitoredPuhunan;
            $runningFinalTubo = $runningTubo - $runningMonitoredTubo;

            // 8. Save/Update Summary in monthly_business_ledger_v2
            $userId = !Yii::$app->user->isGuest ? Yii::$app->user->id : null;

            Yii::$app->db->createCommand("
                INSERT INTO monthly_business_ledger_v2 (
                    date,
                    puhunan,
                    tubo,
                    total_sales,
                    running_puhunan,
                    running_tubo,
                    running_money_on_hand,
                    grand_puhunan,
                    grand_tubo,
                    grand_total_sales,
                    total_puhunan,
                    total_tubo,
                    total_money_on_hand,
                    created_by,
                    updated_by
                ) VALUES (
                    :date,
                    :puhunan,
                    :tubo,
                    :total_sales,
                    :running_puhunan,
                    :running_tubo,
                    :running_money_on_hand,
                    :grand_puhunan,
                    :grand_tubo,
                    :grand_total_sales,
                    :total_puhunan,
                    :total_tubo,
                    :total_money_on_hand,
                    :created_by,
                    :updated_by
                )
                ON DUPLICATE KEY UPDATE
                    puhunan                 = VALUES(puhunan),
                    tubo                    = VALUES(tubo),
                    total_sales             = VALUES(total_sales),
                    running_puhunan         = VALUES(running_puhunan),
                    running_tubo            = VALUES(running_tubo),
                    running_money_on_hand   = VALUES(running_money_on_hand),
                    grand_puhunan           = VALUES(grand_puhunan),
                    grand_tubo              = VALUES(grand_tubo),
                    grand_total_sales       = VALUES(grand_total_sales),
                    total_puhunan           = VALUES(total_puhunan),
                    total_tubo              = VALUES(total_tubo),
                    total_money_on_hand     = VALUES(total_money_on_hand),
                    updated_by              = VALUES(updated_by);
            ")->bindValues([
                ':date'                     => $startDate,
                ':puhunan'                  => $runningPuhunan,
                ':tubo'                     => $runningTubo,
                ':total_sales'              => $runningTotalSales,

                ':running_puhunan'          => $runningTotalPuhunan,
                ':running_tubo'             => $runningTotalTubo,
                ':running_money_on_hand'    => $runningMoneyHand,

                ':grand_puhunan'            => $grandPuhunan,
                ':grand_tubo'               => $grandTubo,
                ':grand_total_sales'        => $grandTotalSales,

                ':total_puhunan'            => $runningFinalPuhunan,
                ':total_tubo'               => $runningFinalTubo,
                ':total_money_on_hand'      => 0.00,

                ':created_by'               => $userId,
                ':updated_by'               => $userId,
            ])->execute();

            $transaction->commit();

            return [
                'success' => true,
                'message' => 'Monthly ledger and monthly summary updated successfully.',
                'period'  => [
                    'start_date' => $startDate,
                    'end_date'   => date('Y-m-d', strtotime('-1 day', $endTimestamp)),
                ],
                'monthly_summary' => [
                    'date'                  => $startDate,
                    'running_puhunan'       => $startingMonthPuhunan,
                    'running_tubo'          => $startingMonthTubo,
                    'running_money_on_hand' => $startingMonthMoneyHand,
                    'total_puhunan'         => $runningPuhunan,
                    'total_tubo'            => $runningTubo,
                    'total_money_on_hand'   => $runningMoneyHand,
                ],
                'bind_params' => $bindParams
            ];

        } catch (\Exception $e) {
            $transaction->rollBack();
            Yii::$app->response->statusCode = 500;
            return [
                'success' => false,
                'error'   => 'Failed to update ledger reports: ' . $e->getMessage()
            ];
        }
    }
    
    public function actionGetmonthlybusinessledger() 
    {
        if (Yii::$app->request->method !== 'GET') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $sqlInventory = "SELECT * FROM inventory AS i WHERE i.monitored = 1";
        $dataInventory = Yii::$app->db->createCommand($sqlInventory)->queryAll();
        $additionalHeader = [];
        $monitoredIds = [];
        // foreach ($dataInventory as $item) {
        //     $additionalHeader[] = ["title"=>"P - ".ucwords(strtolower($item["product_name"])),"name"=>"","align"=>"right","class"=>"w-28"];
        //     $additionalHeader[] = ["title"=>"T - ".ucwords(strtolower($item["product_name"])),"name"=>"","align"=>"right","class"=>"w-28"];
        //     $additionalHeader[] = ["title"=>"Ex - ".ucwords(strtolower($item["product_name"])),"name"=>"","align"=>"right","class"=>"w-28"];
        //     // $additionalHeader[] = ["title"=>"TS - ".ucwords(strtolower($item["product_name"])),"name"=>"","align"=>"right","class"=>"w-28"];
        //     $monitoredIds[] = $item["id"];
        // }

        $tableHeader = [
            ["title"=>"#","name"=>"id","align"=>"right","class"=>"w-10"],
            ["title"=>"Month","name"=>"date","align"=>"left","class"=>"w-40"],
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

        $prefixed_string = "";
        $prefixed_array = array_map(function($item) { return 'ex_' . $item; }, $monitoredIds);
        $prefixed_string = implode(", ", $prefixed_array);
        $prefixed_array = array_map(function($item) { return 'ex_' . $item . '_details'; }, $monitoredIds);
        $prefixed_string = ", " . $prefixed_string . ", " . implode(", ", $prefixed_array);

        $sql = "
            SELECT 
                *, 
                DATE_FORMAT(date, '%M, %Y') AS date
            FROM monthly_business_ledger_v2;
        ";
        $data = Yii::$app->db->createCommand($sql)->queryAll();

        return [
            'data' => $data,
            'monitored_items' => $dataInventory,
            'count' => count($data),
            'mids' => $dataInventory,
            'success' => true,
            'headers' => json_encode($tableHeader),

            // 'totalPuhunan' => $totalPuhunan->amount,
            // 'totalTubo' => $totalTubo->amount,
            // 'totalSales' => $totalSales->amount,
            // 'totalPuhunanCement' => $totalPuhunanCement->amount,
            // 'totalTuboCement' => $totalTuboCement->amount,
            // 'totalPuhunanRSB' => $totalPuhunanRSB->amount,
            // 'totalTuboRSB' => $totalTuboRSB->amount,
            // 'totalPuhunanAll' => $totalPuhunanAll,
            // 'totalTuboAll' => $totalTuboAll,
        ];       
    }
}