<?php

namespace app\controllers;

use Yii;
use app\models\Suppliers;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\filters\VerbFilter;
use yii\db\Expression;
use app\models\Employee; 

class MonitoredController extends Controller
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

    public function formatMonthlyReportDate($inputDate) 
    {
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

        return $d;
    }

    public function actionUpdatereportmonthly()
    {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        if (Yii::$app->request->method !== 'POST') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $inputDate = Yii::$app->request->getBodyParam('date');

        // Get initial running parameters if passed via request body
        $initalCement     = Yii::$app->request->getBodyParam('initial_cement');
        $initialRSB10     = Yii::$app->request->getBodyParam('initial_rsb_10');
        $initialRSB12     = Yii::$app->request->getBodyParam('initial_rsb_12');
        $initialRSB16     = Yii::$app->request->getBodyParam('initial_rsb_16');

        return $this->processReportMonthly($inputDate, $initalCement, $initialRSB10, $initialRSB12, $initialRSB16);
    }

    public function processReportMonthly($inputDate, $initalCement, $initialRSB10, $initialRSB12, $initialRSB16) 
    {
        $userId = !Yii::$app->user->isGuest ? Yii::$app->user->id : 0;

        $d = $this->formatMonthlyReportDate($inputDate);

        // Normalize to the 1st of the month
        $startDate = $d->format('Y-m-01');
        
        // Calculate the start of the next month
        $endDateTime = (clone $d)->modify('first day of next month');
        $endDate = $endDateTime->format('Y-m-01');

        // Fallback: Query ending balances from the day BEFORE start_date
        if ($initalCement === null || $initialRSB10 === null || $initialRSB12 === null || $initialRSB16 === null) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'Initial values parameter is required'];
        } else {
            $runningCement          = (float)$initalCement;
            $runningRSB10           = (float)$initialRSB10;
            $runningRSB12           = (float)$initialRSB12;
            $runningRSB16           = (float)$initialRSB16;
        }

        $transaction = Yii::$app->db->beginTransaction();

        try {
            // 1. 
            $deleteSql = Yii::$app->db->createCommand("
                DELETE FROM monitored_daily_snapshots
                WHERE report_date >= :start_date AND report_date < :end_date
            ")->bindValue(':start_date', $startDate)
              ->bindValue(':end_date', $endDate)
              ->execute();

            // 2. 
            $monitoredDailySnapshotsInserts = Yii::$app->db->createCommand("
                INSERT INTO monitored_daily_snapshots
                    (report_date, inventory_id, source_type, category_type, source_item_id, puhunan, tubo, total_sales)
                SELECT 
                    DATE(s.date_sold) AS report_date,
                    si.inventory_id,
                    'sale' AS source_type,
                    i.type AS category_type,
                    si.id AS source_item_id,
                    (si.qty_sold * si.cost_per_unit) AS puhunan,
                    (si.total - (si.qty_sold * si.cost_per_unit)) AS tubo,
                    si.total AS total_sales
                FROM sales s
                JOIN sales_items si ON s.id = si.sales_id
                LEFT JOIN inventory i ON si.inventory_id = i.id
                WHERE s.date_sold >= :start_date AND s.date_sold < :end_date
                  AND s.status = 'approved' AND s.is_paid = 'yes' AND i.monitored = 1
                ORDER BY s.date_sold ASC, si.id ASC
                ON DUPLICATE KEY UPDATE 
                    puhunan = VALUES(puhunan), 
                    tubo = VALUES(tubo), 
                    total_sales = VALUES(total_sales);
            ")->bindValue(':start_date', $startDate)
              ->bindValue(':end_date', $endDate)
              ->execute();

            // 3. 
            $dailyAggregates = Yii::$app->db->createCommand("
                SELECT 
                    d.report_date,
                    d.inventory_id,
                    COALESCE(xs.puhunan, 0) AS puhunan,
                    COALESCE(xs.tubo, 0) AS tubo,
                    COALESCE(xr.expenses, 0) AS expenses
                FROM 
                (
                    SELECT DATE(mds.report_date) AS report_date, mds.inventory_id
                    FROM monitored_daily_snapshots mds
                    
                    UNION
                    
                    SELECT DATE(r.date_received) AS report_date, ri.inventory_id 
                    FROM replenishment r
                    JOIN replenishment_items ri ON r.id = ri.transaction_id
                    WHERE r.status = 'approved'
                ) d

                LEFT JOIN inventory i ON d.inventory_id = i.id

                -- Subquery for Daily Snapshot Aggregates
                LEFT JOIN (
                    SELECT 
                        DATE(mds.report_date) AS report_date,
                        mds.inventory_id,
                        SUM(mds.puhunan) AS puhunan,
                        SUM(mds.tubo) AS tubo
                    FROM monitored_daily_snapshots mds
                    GROUP BY DATE(mds.report_date), mds.inventory_id
                ) xs ON d.report_date = xs.report_date AND d.inventory_id = xs.inventory_id

                -- Subquery for Replenishment Expenses
                LEFT JOIN (
                    SELECT 
                        DATE(r.date_received) AS report_date,
                        ri.inventory_id,
                        SUM(ri.qty_added) AS qty_replenished,
                        SUM(ri.qty_added * ri.cost_per_unit) AS expenses
                    FROM replenishment r
                    JOIN replenishment_items ri ON r.id = ri.transaction_id
                    WHERE r.status = 'approved'
                    GROUP BY DATE(r.date_received), ri.inventory_id
                ) xr ON d.report_date = xr.report_date AND d.inventory_id = xr.inventory_id

                WHERE d.report_date >= :start_date 
                  AND d.report_date < :end_date 
                  AND i.monitored = 1
                ORDER BY d.report_date ASC, d.inventory_id ASC;
            ")->bindValue(':start_date', $startDate)
              ->bindValue(':end_date', $endDate)
              ->queryAll();

            $salesByDate = [];
            $monitoredByInventory = [];
            foreach ($dailyAggregates as $row) {
                $salesByDate[$row['report_date']] = $row;

                $invId   = (int)$row['inventory_id'];
                $repDate = $row['report_date'];

                if (!isset($monitoredByInventory[$invId])) {
                    $monitoredByInventory[$invId] = [];
                }

                $monitoredByInventory[$invId][$repDate] = [
                    'puhunan' => (float)$row['puhunan'],
                    'tubo' => (float)$row['tubo'],
                    'expenses' => (float)$row['expenses'],
                ];

                // if (!in_array($invId, $monitoredInventoryIds)) {
                //     $monitoredInventoryIds[] = $invId;
                // }
            }

            // 4. 
            $insertCols = [
                'date', 'created_by'
            ];

            $insertValues = [
                ':date', ':created_by'
            ];

            $updateClauses = [
                'updated_by = :updated_by',
            ];

            $monitoredInventoryIds = [21,1421,1422,1423];
            foreach ($monitoredInventoryIds as $invId) {
                $iCol = "i_{$invId}";
                $pCol = "p_{$invId}";
                $tCol = "t_{$invId}";
                $eCol = "e_{$invId}";
                $rCol = "r_{$invId}";
                $gCol = "g_{$invId}";
                $toCol = "to_{$invId}";

                $insertCols[]   = "`{$iCol}`";
                $insertCols[]   = "`{$pCol}`";
                $insertCols[]   = "`{$tCol}`";
                $insertCols[]   = "`{$eCol}`";
                $insertCols[]   = "`{$rCol}`";
                $insertCols[]   = "`{$gCol}`";
                $insertCols[]   = "`{$toCol}`";

                $insertValues[] = ":{$iCol}";
                $insertValues[] = ":{$pCol}";
                $insertValues[] = ":{$tCol}";
                $insertValues[] = ":{$eCol}";
                $insertValues[] = ":{$rCol}";
                $insertValues[] = ":{$gCol}";
                $insertValues[] = ":{$toCol}";

                $updateClauses[] = "`{$iCol}` = VALUES(`{$iCol}`)";
                $updateClauses[] = "`{$pCol}` = VALUES(`{$pCol}`)";
                $updateClauses[] = "`{$tCol}` = VALUES(`{$tCol}`)";
                $updateClauses[] = "`{$eCol}` = VALUES(`{$eCol}`)";
                $updateClauses[] = "`{$rCol}` = VALUES(`{$rCol}`)";
                $updateClauses[] = "`{$gCol}` = VALUES(`{$gCol}`)";
                $updateClauses[] = "`{$toCol}` = VALUES(`{$toCol}`)";
            }

            $sqlUpsertDay = "
                INSERT INTO monitored_daily_ledger (
                    " . implode(', ', $insertCols) . "
                ) VALUES (
                    " . implode(', ', $insertValues) . "
                )
                ON DUPLICATE KEY UPDATE
                    " . implode(",\n", $updateClauses) . ";
            ";

            $cmdUpsertDay = Yii::$app->db->createCommand($sqlUpsertDay);

            // 5. 
            $currentTimestamp = strtotime($startDate);
            $endTimestamp     = strtotime($endDate);

            while ($currentTimestamp < $endTimestamp) {
                $dateStr = date('Y-m-d', $currentTimestamp);
                $bindParams = [':date' => $dateStr];
                $bindParams[':created_by'] = $userId;
                $bindParams[':updated_by'] = $userId;

                $monitoredPuhunan   = 0.00;
                $monitoredTubo      = 0.00;
                foreach ($monitoredInventoryIds as $invId) {
                    $iCol   = "i_{$invId}";
                    $pCol   = "p_{$invId}";
                    $tCol   = "t_{$invId}";
                    $eCol   = "e_{$invId}";
                    $rCol   = "r_{$invId}";
                    $gCol   = "g_{$invId}";
                    $toCol  = "to_{$invId}";


                    $monPuhunan     = 0.00;
                    $monTubo        = 0.00;
                    $monExpenses    = 0.00;
                    if (isset($monitoredByInventory[$invId][$dateStr])) {
                        $monPuhunan     = $monitoredByInventory[$invId][$dateStr]['puhunan'];
                        $monTubo        = $monitoredByInventory[$invId][$dateStr]['tubo'];
                        $monExpenses    = $monitoredByInventory[$invId][$dateStr]['expenses'];
                    }

                    $bindParams[":{$iCol}"]     = 0;
                    $bindParams[":{$pCol}"]     = $monPuhunan;
                    $bindParams[":{$tCol}"]     = $monTubo;
                    $bindParams[":{$eCol}"]     = $monExpenses;
                    $bindParams[":{$rCol}"]     = 0;
                    $bindParams[":{$gCol}"]     = 0;
                    $bindParams[":{$toCol}"]    = 0;

                    $monitoredPuhunan           += $monPuhunan;
                    $monitoredTubo              += $monTubo;

                    if ($invId == 21) {
                        $bindParams[":{$iCol}"] = $runningCement;
                        $bindParams[":{$rCol}"] = $runningCement + $monPuhunan;
                        $runningCement          += $monPuhunan - $monExpenses;
                    } 

                    if ($invId == 1421) {
                        $bindParams[":{$iCol}"] = $runningRSB10;
                        $bindParams[":{$rCol}"] = $runningRSB10 + $monPuhunan;
                        $runningRSB10           += $monPuhunan - $monExpenses;
                    } 

                    if ($invId == 1422) {
                        $bindParams[":{$iCol}"] = $runningRSB12;
                        $bindParams[":{$rCol}"] = $runningRSB12 + $monPuhunan;
                        $runningRSB12           += $monPuhunan - $monExpenses;
                    } 

                    if ($invId == 1423) {
                        $bindParams[":{$iCol}"] = $runningRSB16;
                        $bindParams[":{$rCol}"] = $runningRSB16 + $monPuhunan;
                        $runningRSB16           += $monPuhunan - $monExpenses;
                    } 
                }

                $cmdUpsertDay->bindValues($bindParams)->execute();

                $currentTimestamp = strtotime('+1 day', $currentTimestamp);
            }

            // 6.
            Yii::$app->db->createCommand("
                INSERT INTO monitored_monthly_ledger (
                    date,
                    i_21,
                    p_21,
                    t_21,
                    e_21,
                    r_21,
                    g_21,
                    to_21,
                    i_1421,
                    p_1421,
                    t_1421,
                    e_1421,
                    r_1421,
                    g_1421,
                    to_1421,
                    i_1422,
                    p_1422,
                    t_1422,
                    e_1422,
                    r_1422,
                    g_1422,
                    to_1422,
                    i_1423,
                    p_1423,
                    t_1423,
                    e_1423,
                    r_1423,
                    g_1423,
                    to_1423,
                    created_by,
                    updated_by
                ) VALUES (
                    :date,
                    :initial_cement,
                    :puhunan_cement,
                    :tubo_cement,
                    :expenses_cement,
                    :running_cement,
                    :grand_cement,
                    :total_cement,
                    :initial_rsb_10,
                    :puhunan_rsb_10,
                    :tubo_rsb_10,
                    :expenses_rsb_10,
                    :running_rsb_10,
                    :grand_rsb_10,
                    :total_rsb_10,
                    :initial_rsb_12,
                    :puhunan_rsb_12,
                    :tubo_rsb_12,
                    :expenses_rsb_12,
                    :running_rsb_12,
                    :grand_rsb_12,
                    :total_rsb_12,
                    :initial_rsb_16,
                    :puhunan_rsb_16,
                    :tubo_rsb_16,
                    :expenses_rsb_16,
                    :running_rsb_16,
                    :grand_rsb_16,
                    :total_rsb_16,
                    :created_by,
                    :updated_by
                )
                ON DUPLICATE KEY UPDATE
                    i_21                    = VALUES(i_21),
                    p_21                    = VALUES(p_21),
                    t_21                    = VALUES(t_21),
                    e_21                    = VALUES(e_21),
                    r_21                    = VALUES(r_21),
                    g_21                    = VALUES(g_21),
                    to_21                   = VALUES(to_21),
                    i_1421                  = VALUES(i_1421),
                    p_1421                  = VALUES(p_1421),
                    t_1421                  = VALUES(t_1421),
                    e_1421                  = VALUES(e_1421),
                    r_1421                  = VALUES(r_1421),
                    g_1421                  = VALUES(g_1421),
                    to_1421                 = VALUES(to_1421),
                    i_1422                  = VALUES(i_1422),
                    p_1422                  = VALUES(p_1422),
                    t_1422                  = VALUES(t_1422),
                    e_1422                  = VALUES(e_1422),
                    r_1422                  = VALUES(r_1422),
                    g_1422                  = VALUES(g_1422),
                    to_1422                 = VALUES(to_1422),
                    i_1423                  = VALUES(i_1423),
                    p_1423                  = VALUES(p_1423),
                    t_1423                  = VALUES(t_1423),
                    e_1423                  = VALUES(e_1423),
                    r_1423                  = VALUES(r_1423),
                    g_1423                  = VALUES(g_1423),
                    to_1423                 = VALUES(to_1423),
                    updated_by              = VALUES(updated_by);
            ")->bindValues([
                ':date'                     => $startDate,

                ':initial_cement'           => $initalCement,
                ':puhunan_cement'           => 0,
                ':tubo_cement'              => 0,
                ':expenses_cement'          => 0,
                ':running_cement'           => $runningCement,
                ':grand_cement'             => 0,
                ':total_cement'             => 0,

                ':initial_rsb_10'           => $initialRSB10,
                ':puhunan_rsb_10'           => 0,
                ':tubo_rsb_10'              => 0,
                ':expenses_rsb_10'          => 0,
                ':running_rsb_10'           => $runningRSB10,
                ':grand_rsb_10'             => 0,
                ':total_rsb_10'             => 0,
                
                ':initial_rsb_12'           => $initialRSB12,
                ':puhunan_rsb_12'           => 0,
                ':tubo_rsb_12'              => 0,
                ':expenses_rsb_12'          => 0,
                ':running_rsb_12'           => $runningRSB12,
                ':grand_rsb_12'             => 0,
                ':total_rsb_12'             => 0,
                
                ':initial_rsb_16'           => $initialRSB16,
                ':puhunan_rsb_16'           => 0,
                ':tubo_rsb_16'              => 0,
                ':expenses_rsb_16'          => 0,
                ':running_rsb_16'           => $runningRSB16,
                ':grand_rsb_16'             => 0,
                ':total_rsb_16'             => 0,

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
                    // 'running_puhunan'       => $startingMonthPuhunan,
                    // 'running_tubo'          => $startingMonthTubo,
                    // 'running_money_on_hand' => $startingMonthMoneyHand,
                    // 'total_puhunan'         => $runningPuhunan,
                    // 'total_tubo'            => $runningTubo,
                    // 'total_money_on_hand'   => $runningMoneyHand,
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

    public function actionGetmonthlymonitoredledger() 
    {
        if (Yii::$app->request->method !== 'GET') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $sqlInventory = "SELECT * FROM inventory AS i WHERE i.monitored = 1";
        $dataInventory = Yii::$app->db->createCommand($sqlInventory)->queryAll();
        $additionalHeader = [];
        $monitoredIds = [];

        $tableHeader = [
            ["title"=>"#","name"=>"id","align"=>"right","class"=>"w-10"],
            ["title"=>"Month","name"=>"date","align"=>"left","class"=>"w-40"],
            ["title"=>"Initial Cement","name"=>"puhunan","align"=>"right","class"=>"w-40"],
            ["title"=>"Final Cement","name"=>"puhunan","align"=>"right","class"=>"w-40"],
            ["title"=>"Initial RSB 10","name"=>"puhunan","align"=>"right","class"=>"w-40"],
            ["title"=>"Final RSB 10","name"=>"puhunan","align"=>"right","class"=>"w-40"],
            ["title"=>"Initial RSB 12","name"=>"puhunan","align"=>"right","class"=>"w-40"],
            ["title"=>"Final RSB 12","name"=>"puhunan","align"=>"right","class"=>"w-40"],
            ["title"=>"Initial RSB 16","name"=>"puhunan","align"=>"right","class"=>"w-40"],
            ["title"=>"Final RSB 16","name"=>"puhunan","align"=>"right","class"=>"w-40"],
            ["title"=>"Action","name"=>"action","default"=>1,"class"=>"w-20"],
        ];
        array_splice($tableHeader, 5, 0, $additionalHeader);

        $sql = "
            SELECT 
                *, 
                DATE_FORMAT(date, '%M, %Y') AS date,
                DATE_FORMAT(date, '%m, %Y') AS date_value
            FROM monitored_monthly_ledger;
        ";
        $data = Yii::$app->db->createCommand($sql)->queryAll();

        return [
            'data' => $data,
            'monitored_items' => $dataInventory,
            'count' => count($data),
            'mids' => $dataInventory,
            'success' => true,
            'headers' => json_encode($tableHeader),
        ];      
    }

    public function actionGetdailymonitoredledger() 
    {
        if (Yii::$app->request->method !== 'GET') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $inputDate = Yii::$app->request->get('date');
        $d = $this->formatMonthlyReportDate($inputDate);

        $startDate = $d->format('Y-m-01');
        $endDateTime = (clone $d)->modify('first day of next month');
        $endDate = $endDateTime->format('Y-m-01');

        $sqlInventory = "SELECT * FROM inventory AS i WHERE i.monitored = 1";
        $dataInventory = Yii::$app->db->createCommand($sqlInventory)->queryAll();
        $additionalHeader = [];
        $monitoredIds = [];
        foreach ($dataInventory as $item) {
            $additionalHeader[] = ["title"=>"P - ".ucwords(strtolower($item["product_name"])),"name"=>"","align"=>"right","class"=>"w-28"];
            $additionalHeader[] = ["title"=>"T - ".ucwords(strtolower($item["product_name"])),"name"=>"","align"=>"right","class"=>"w-28"];
            // $additionalHeader[] = ["title"=>"Ex - ".ucwords(strtolower($item["product_name"])),"name"=>"","align"=>"right","class"=>"w-28"];
            $monitoredIds[] = $item["id"];
        }

        $tableHeader = [
            ["title"=>"#","name"=>"id","align"=>"right","class"=>"w-10"],
            ["title"=>"Date","name"=>"date","align"=>"left","class"=>"w-40"],
            ["title"=>"Tubo Cement","name"=>"tubo_cement","align"=>"right","class"=>"w-28"],
            ["title"=>"Puhunan Cement","name"=>"puhunan_cement","align"=>"right","class"=>"w-28"],
            ["title"=>"Expenses Cement","name"=>"expenses_cement","align"=>"right","class"=>"w-28"],
            ["title"=>"Running Balance Cement","name"=>"running_balance_cement","align"=>"right","class"=>"w-28"],
            ["title"=>"Tubo RSB10","name"=>"tubo_rsb10","align"=>"right","class"=>"w-28"],
            ["title"=>"Puhunan RSB10","name"=>"puhunan_rsb10","align"=>"right","class"=>"w-28"],
            ["title"=>"Expenses RSB10","name"=>"expenses_rsb10","align"=>"right","class"=>"w-28"],
            ["title"=>"Running Balance RSB10","name"=>"running_balance_rsb10","align"=>"right","class"=>"w-28"],
            ["title"=>"Tubo RSB12","name"=>"tubo_rsb12","align"=>"right","class"=>"w-28"],
            ["title"=>"Puhunan RSB12","name"=>"puhunan_rsb12","align"=>"right","class"=>"w-28"],
            ["title"=>"Expenses RSB12","name"=>"expenses_rsb12","align"=>"right","class"=>"w-28"],
            ["title"=>"Running Balance RSB12","name"=>"running_balance_rsb12","align"=>"right","class"=>"w-28"],
            ["title"=>"Tubo RSB16","name"=>"tubo_rsb16","align"=>"right","class"=>"w-28"],
            ["title"=>"Puhunan RSB16","name"=>"puhunan_rsb16","align"=>"right","class"=>"w-28"],
            ["title"=>"Expenses RSB16","name"=>"expenses_rsb16","align"=>"right","class"=>"w-28"],
            ["title"=>"Running Balance RSB16","name"=>"running_balance_rsb16","align"=>"right","class"=>"w-28"],
            // ["title"=>"Action","name"=>"action","default"=>1,"class"=>"w-20"],
        ];
        // array_splice($tableHeader, 5, 0, $additionalHeader);

        $sql = "
            SELECT * FROM monitored_daily_ledger 
            WHERE date >= '$startDate' AND date < '$endDate' 
            ORDER BY date DESC;
        ";
        $data = Yii::$app->db->createCommand($sql)->queryAll();

        // $sqlTotals = "
        //     SELECT 
        //         COALESCE(SUM(dbl.puhunan), 0) AS puhunan,
        //         COALESCE(SUM(dbl.tubo), 0) AS tubo,
        //         COALESCE(SUM(dbl.total_sales), 0) AS total_sales,

        //         COALESCE(SUM(dbl.p_21), 0) AS puhunan_cement,
        //         COALESCE(SUM(dbl.t_21), 0) AS tubo_cement,

        //         SUM(+ COALESCE(dbl.p_1421, 0) + COALESCE(dbl.p_1422, 0) + COALESCE(dbl.p_1423, 0)) AS puhunan_rsb,
        //         SUM(+ COALESCE(dbl.t_1421, 0) + COALESCE(dbl.t_1422, 0) + COALESCE(dbl.t_1423, 0)) AS tubo_rsb,


        //         COALESCE(SUM(dbl.puhunan), 0) AS puhunan,
        //         DATE_FORMAT(report_date, '%M, %Y') AS group_date
        //     FROM daily_business_ledger_v2 as dbl
        //     WHERE report_date >= '$startDate' AND report_date < '$endDate'
        //     GROUP BY group_date;
        // ";
        // $dataTotals = Yii::$app->db->createCommand($sqlTotals)->queryOne();

        $sqlMonth = "
            SELECT *
            FROM monitored_monthly_ledger as mbl
            WHERE date >= '$startDate' AND date < '$endDate';
        ";
        $dataMonth = Yii::$app->db->createCommand($sqlMonth)->queryOne();

        // if (empty($dataTotals)) {
        //     return [
        //         'success' => false,
        //         'message' => 'No total data available',
        //         'data' => $data,
        //         'count' => 0,
        //         'mids' => $dataInventory,
        //         'headers' => json_encode($tableHeader),
        //     ];
        // }

        return [
            'data' => $data,
            // 'mids' => $dataInventory,
            'count' => count($data),
            'success' => true,
            'headers' => json_encode($tableHeader),

            'initialCement' => $dataMonth['i_21'],
            'initialRSB10' => $dataMonth['i_1421'],
            'initialRSB12' => $dataMonth['i_1422'],
            'initialRSB16' => $dataMonth['i_1423'],

            'finalCement' => $dataMonth['r_21'],
            'finalRSB10' => $dataMonth['r_1421'],
            'finalRSB12' => $dataMonth['r_1422'],
            'finalRSB16' => $dataMonth['r_1423'],


            // 'totalPuhunan' => $dataTotals['puhunan'],
            // 'totalTubo' => $dataTotals['tubo'],
            // 'totalSales' => $dataTotals['total_sales'],

            // 'totalPuhunanCement' => $dataTotals['puhunan_cement'],
            // 'totalTuboCement' => $dataTotals['tubo_cement'],

            // 'totalPuhunanRSB' => $dataTotals['puhunan_rsb'],
            // 'totalTuboRSB' => $dataTotals['tubo_rsb'],

            // 'initialMoneyOnHand' => $dataMonth['initial_money_on_hand'],
            // 'initialPuhunan' => $dataMonth['initial_puhunan'],
            // 'initialTubo' => $dataMonth['initial_tubo'],

            // 'finalMoneyOnHand' => $dataMonth['running_money_on_hand'],
            // 'finalPuhunan' => $dataMonth['running_puhunan'],
            // 'finalTubo' => $dataMonth['running_tubo'],
            // 'reportId' => $dataMonth['id'],

            // 'dataTotals' => $dataTotals,
            // 'sql' => $sql,
        ];       
    }
}