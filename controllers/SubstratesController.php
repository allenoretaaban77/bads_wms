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

class SubstratesController extends Controller
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

    public function actionGetmonthlysubstratesledger() 
    {
        if (Yii::$app->request->method !== 'GET') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $sqlInventory = "SELECT * FROM inventory AS i WHERE i.substrates = 1";
        $dataInventory = Yii::$app->db->createCommand($sqlInventory)->queryAll();
        $additionalHeader = [];
        $substratesIds = [];

        $tableHeader = [
            ["title"=>"#","name"=>"id","align"=>"right","class"=>"w-10"],
            ["title"=>"Month","name"=>"date","align"=>"left","class"=>"w-40"],
            ["title"=>"Initial Gravel","name"=>"puhunan","align"=>"right","class"=>"w-40"],
            ["title"=>"Final Gravel","name"=>"puhunan","align"=>"right","class"=>"w-40"],
            ["title"=>"Initial CHB 4","name"=>"puhunan","align"=>"right","class"=>"w-40"],
            ["title"=>"Final CHB 4","name"=>"puhunan","align"=>"right","class"=>"w-40"],
            ["title"=>"Initial CHB 5","name"=>"puhunan","align"=>"right","class"=>"w-40"],
            ["title"=>"Final CHB 5","name"=>"puhunan","align"=>"right","class"=>"w-40"],
            ["title"=>"Action","name"=>"action","default"=>1,"class"=>"w-20"],
        ];
        array_splice($tableHeader, 5, 0, $additionalHeader);

        $sql = "
            SELECT 
                *, 
                DATE_FORMAT(date, '%M, %Y') AS date,
                DATE_FORMAT(date, '%m, %Y') AS date_value
            FROM substrates_monthly_ledger;
        ";
        $data = Yii::$app->db->createCommand($sql)->queryAll();

        return [
            'data' => $data,
            'substrates_items' => $dataInventory,
            'count' => count($data),
            'mids' => $dataInventory,
            'success' => true,
            'headers' => json_encode($tableHeader),
        ];      
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
        $initialGravel   = Yii::$app->request->getBodyParam('initial_gravel');
        $initialCHB4     = Yii::$app->request->getBodyParam('initial_chb_4');
        $initialCHB5     = Yii::$app->request->getBodyParam('initial_chb_5');

        return $this->processReportMonthly($inputDate, $initialGravel, $initialCHB4, $initialCHB5);
    }

    public function processReportMonthly($inputDate, $initialGravel, $initialCHB4, $initialCHB5) 
    {
        $userId = !Yii::$app->user->isGuest ? Yii::$app->user->id : 0;

        $d = $this->formatMonthlyReportDate($inputDate);

        // Normalize to the 1st of the month
        $startDate = $d->format('Y-m-01');
        
        // Calculate the start of the next month
        $endDateTime = (clone $d)->modify('first day of next month');
        $endDate = $endDateTime->format('Y-m-01');

        // Fallback: Query ending balances from the day BEFORE start_date
        if ($initialGravel === null || $initialCHB4 === null || $initialCHB5 === null) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'Initial values parameter is required'];
        } else {
            $runningGravel          = (float)$initialGravel;
            $runningCHB4            = (float)$initialCHB4;
            $runningCHB5            = (float)$initialCHB5;
        }

        $transaction = Yii::$app->db->beginTransaction();

        try {
            // 1. 
            $deleteSql = Yii::$app->db->createCommand("
                DELETE FROM substrates_daily_snapshots
                WHERE report_date >= :start_date AND report_date < :end_date
            ")->bindValue(':start_date', $startDate)
              ->bindValue(':end_date', $endDate)
              ->execute();

            // 2. 
            $substratesDailySnapshotsInserts = Yii::$app->db->createCommand("
                INSERT INTO substrates_daily_snapshots
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
                  AND s.status = 'approved' AND s.is_paid = 'yes' AND i.substrates = 1
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
                    FROM substrates_daily_snapshots mds
                    
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
                    FROM substrates_daily_snapshots mds
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
                  AND i.substrates = 1
                ORDER BY d.report_date ASC, d.inventory_id ASC;
            ")->bindValue(':start_date', $startDate)
              ->bindValue(':end_date', $endDate)
              ->queryAll();

            $salesByDate = [];
            $substratesByInventory = [];
            foreach ($dailyAggregates as $row) {
                $salesByDate[$row['report_date']] = $row;

                $invId   = (int)$row['inventory_id'];
                $repDate = $row['report_date'];

                if (!isset($substratesByInventory[$invId])) {
                    $substratesByInventory[$invId] = [];
                }

                $substratesByInventory[$invId][$repDate] = [
                    'puhunan' => (float)$row['puhunan'],
                    'tubo' => (float)$row['tubo'],
                    'expenses' => (float)$row['expenses'],
                ];

                // if (!in_array($invId, $substratesInventoryIds)) {
                //     $substratesInventoryIds[] = $invId;
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

            $substratesInventoryIds = [1898,309,310];
            foreach ($substratesInventoryIds as $invId) {
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
                INSERT INTO substrates_daily_ledger (
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

                $substratesPuhunan   = 0.00;
                $substratesTubo      = 0.00;
                foreach ($substratesInventoryIds as $invId) {
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
                    if (isset($substratesByInventory[$invId][$dateStr])) {
                        $monPuhunan     = $substratesByInventory[$invId][$dateStr]['puhunan'];
                        $monTubo        = $substratesByInventory[$invId][$dateStr]['tubo'];
                        $monExpenses    = $substratesByInventory[$invId][$dateStr]['expenses'];
                    }

                    $bindParams[":{$iCol}"]     = 0;
                    $bindParams[":{$pCol}"]     = $monPuhunan;
                    $bindParams[":{$tCol}"]     = $monTubo;
                    $bindParams[":{$eCol}"]     = $monExpenses;
                    $bindParams[":{$rCol}"]     = 0;
                    $bindParams[":{$gCol}"]     = 0;
                    $bindParams[":{$toCol}"]    = 0;

                    $substratesPuhunan           += $monPuhunan;
                    $substratesTubo              += $monTubo;

                    if ($invId == 1898) {
                        $bindParams[":{$iCol}"] = $runningGravel;
                        $bindParams[":{$rCol}"] = $runningGravel + $monPuhunan;
                        $runningGravel          += $monPuhunan - $monExpenses;
                    } 

                    if ($invId == 309) {
                        $bindParams[":{$iCol}"] = $runningCHB4;
                        $bindParams[":{$rCol}"] = $runningCHB4 + $monPuhunan;
                        $runningCHB4           += $monPuhunan - $monExpenses;
                    } 

                    if ($invId == 310) {
                        $bindParams[":{$iCol}"] = $runningCHB5;
                        $bindParams[":{$rCol}"] = $runningCHB5 + $monPuhunan;
                        $runningCHB5           += $monPuhunan - $monExpenses;
                    } 
                }

                $cmdUpsertDay->bindValues($bindParams)->execute();

                $currentTimestamp = strtotime('+1 day', $currentTimestamp);
            }

            // 6.
            Yii::$app->db->createCommand("
                INSERT INTO substrates_monthly_ledger (
                    date,
                    i_1898,
                    p_1898,
                    t_1898,
                    e_1898,
                    r_1898,
                    g_1898,
                    to_1898,
                    i_309,
                    p_309,
                    t_309,
                    e_309,
                    r_309,
                    g_309,
                    to_309,
                    i_310,
                    p_310,
                    t_310,
                    e_310,
                    r_310,
                    g_310,
                    to_310,
                    created_by,
                    updated_by
                ) VALUES (
                    :date,
                    :initial_gravel,
                    :puhunan_gravel,
                    :tubo_gravel,
                    :expenses_gravel,
                    :running_gravel,
                    :grand_gravel,
                    :total_gravel,
                    :initial_chb_4,
                    :puhunan_chb_4,
                    :tubo_chb_4,
                    :expenses_chb_4,
                    :running_chb_4,
                    :grand_chb_4,
                    :total_chb_4,
                    :initial_chb_5,
                    :puhunan_chb_5,
                    :tubo_chb_5,
                    :expenses_chb_5,
                    :running_chb_5,
                    :grand_chb_5,
                    :total_chb_5,
                    :created_by,
                    :updated_by
                )
                ON DUPLICATE KEY UPDATE
                    i_1898                    = VALUES(i_1898),
                    p_1898                    = VALUES(p_1898),
                    t_1898                    = VALUES(t_1898),
                    e_1898                    = VALUES(e_1898),
                    r_1898                    = VALUES(r_1898),
                    g_1898                    = VALUES(g_1898),
                    to_1898                   = VALUES(to_1898),
                    i_309                  = VALUES(i_309),
                    p_309                  = VALUES(p_309),
                    t_309                  = VALUES(t_309),
                    e_309                  = VALUES(e_309),
                    r_309                  = VALUES(r_309),
                    g_309                  = VALUES(g_309),
                    to_309                 = VALUES(to_309),
                    i_310                  = VALUES(i_310),
                    p_310                  = VALUES(p_310),
                    t_310                  = VALUES(t_310),
                    e_310                  = VALUES(e_310),
                    r_310                  = VALUES(r_310),
                    g_310                  = VALUES(g_310),
                    to_310                 = VALUES(to_310),
                    updated_by              = VALUES(updated_by);
            ")->bindValues([
                ':date'                     => $startDate,

                ':initial_gravel'           => $initialGravel,
                ':puhunan_gravel'           => 0,
                ':tubo_gravel'              => 0,
                ':expenses_gravel'          => 0,
                ':running_gravel'           => $runningGravel,
                ':grand_gravel'             => 0,
                ':total_gravel'             => 0,

                ':initial_chb_4'           => $initialCHB4,
                ':puhunan_chb_4'           => 0,
                ':tubo_chb_4'              => 0,
                ':expenses_chb_4'          => 0,
                ':running_chb_4'           => $runningCHB4,
                ':grand_chb_4'             => 0,
                ':total_chb_4'             => 0,
                
                ':initial_chb_5'           => $initialCHB5,
                ':puhunan_chb_5'           => 0,
                ':tubo_chb_5'              => 0,
                ':expenses_chb_5'          => 0,
                ':running_chb_5'           => $runningCHB5,
                ':grand_chb_5'             => 0,
                ':total_chb_5'             => 0,

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

    public function actionGetdailysubstratesledger() 
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

        $sqlInventory = "SELECT * FROM inventory AS i WHERE i.substrates = 1";
        $dataInventory = Yii::$app->db->createCommand($sqlInventory)->queryAll();
        $additionalHeader = [];
        $substratesIds = [];
        foreach ($dataInventory as $item) {
            $additionalHeader[] = ["title"=>"P - ".ucwords(strtolower($item["product_name"])),"name"=>"","align"=>"right","class"=>"w-28"];
            $additionalHeader[] = ["title"=>"T - ".ucwords(strtolower($item["product_name"])),"name"=>"","align"=>"right","class"=>"w-28"];
            // $additionalHeader[] = ["title"=>"Ex - ".ucwords(strtolower($item["product_name"])),"name"=>"","align"=>"right","class"=>"w-28"];
            $substratesIds[] = $item["id"];
        }

        $tableHeader = [
            ["title"=>"#","name"=>"id","align"=>"right","class"=>"w-10"],
            ["title"=>"Date","name"=>"date","align"=>"left","class"=>"w-40"],
            ["title"=>"Tubo Gravel","name"=>"tubo_gravel","align"=>"right","class"=>"w-28"],
            ["title"=>"Puhunan Gravel","name"=>"puhunan_gravel","align"=>"right","class"=>"w-28"],
            ["title"=>"Expenses Gravel","name"=>"expenses_gravel","align"=>"right","class"=>"w-28"],
            ["title"=>"Running Balance Gravel","name"=>"running_balance_gravel","align"=>"right","class"=>"w-28"],
            ["title"=>"Tubo CHB4","name"=>"tubo_chb4","align"=>"right","class"=>"w-28"],
            ["title"=>"Puhunan CHB4","name"=>"puhunan_chb4","align"=>"right","class"=>"w-28"],
            ["title"=>"Expenses CHB4","name"=>"expenses_chb4","align"=>"right","class"=>"w-28"],
            ["title"=>"Running Balance CHB4","name"=>"running_balance_chb4","align"=>"right","class"=>"w-28"],
            ["title"=>"Tubo CHB5","name"=>"tubo_chb5","align"=>"right","class"=>"w-28"],
            ["title"=>"Puhunan CHB5","name"=>"puhunan_chb5","align"=>"right","class"=>"w-28"],
            ["title"=>"Expenses CHB5","name"=>"expenses_chb5","align"=>"right","class"=>"w-28"],
            ["title"=>"Running Balance CHB5","name"=>"running_balance_chb5","align"=>"right","class"=>"w-28"],
            // ["title"=>"Action","name"=>"action","default"=>1,"class"=>"w-20"],
        ];
        // array_splice($tableHeader, 5, 0, $additionalHeader);

        $sql = "
            SELECT * FROM substrates_daily_ledger 
            WHERE date >= '$startDate' AND date < '$endDate' 
            ORDER BY date DESC;
        ";
        $data = Yii::$app->db->createCommand($sql)->queryAll();

        // $sqlTotals = "
        //     SELECT 
        //         COALESCE(SUM(dbl.puhunan), 0) AS puhunan,
        //         COALESCE(SUM(dbl.tubo), 0) AS tubo,
        //         COALESCE(SUM(dbl.total_sales), 0) AS total_sales,

        //         COALESCE(SUM(dbl.p_1898), 0) AS puhunan_gravel,
        //         COALESCE(SUM(dbl.t_1898), 0) AS tubo_gravel,

        //         SUM(+ COALESCE(dbl.p_309, 0) + COALESCE(dbl.p_310, 0) + COALESCE(dbl.p_1423, 0)) AS puhunan_rsb,
        //         SUM(+ COALESCE(dbl.t_309, 0) + COALESCE(dbl.t_310, 0) + COALESCE(dbl.t_1423, 0)) AS tubo_rsb,


        //         COALESCE(SUM(dbl.puhunan), 0) AS puhunan,
        //         DATE_FORMAT(report_date, '%M, %Y') AS group_date
        //     FROM daily_business_ledger_v2 as dbl
        //     WHERE report_date >= '$startDate' AND report_date < '$endDate'
        //     GROUP BY group_date;
        // ";
        // $dataTotals = Yii::$app->db->createCommand($sqlTotals)->queryOne();

        $sqlMonth = "
            SELECT *
            FROM substrates_monthly_ledger as mbl
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

            'initialGravel' => $dataMonth['i_1898'],
            'initialCHB4' => $dataMonth['i_309'],
            'initialCHB5' => $dataMonth['i_310'],

            'finalGravel' => $dataMonth['r_1898'],
            'finalCHB4' => $dataMonth['r_309'],
            'finalCHB5' => $dataMonth['r_310'],


            // 'totalPuhunan' => $dataTotals['puhunan'],
            // 'totalTubo' => $dataTotals['tubo'],
            // 'totalSales' => $dataTotals['total_sales'],

            // 'totalPuhunanGravel' => $dataTotals['puhunan_gravel'],
            // 'totalTuboGravel' => $dataTotals['tubo_gravel'],

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