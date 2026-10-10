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

class SandController extends Controller
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

    public function actionGetmonthlysandledger() 
    {
        if (Yii::$app->request->method !== 'GET') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $sqlInventory = "SELECT * FROM inventory AS i WHERE i.sand = 1";
        $dataInventory = Yii::$app->db->createCommand($sqlInventory)->queryAll();
        $additionalHeader = [];
        $sandIds = [];

        $tableHeader = [
            ["title"=>"#","name"=>"id","align"=>"right","class"=>"w-10"],
            ["title"=>"Month","name"=>"date","align"=>"left","class"=>"w-40"],
            ["title"=>"Initial White Sand","name"=>"initial","align"=>"right","class"=>"w-40"],
            ["title"=>"Final White Sand","name"=>"final","align"=>"right","class"=>"w-40"],
            ["title"=>"Action","name"=>"action","default"=>1,"class"=>"w-20"],
        ];
        array_splice($tableHeader, 5, 0, $additionalHeader);

        $sql = "
            SELECT 
                *, 
                DATE_FORMAT(date, '%M, %Y') AS date,
                DATE_FORMAT(date, '%m, %Y') AS date_value
            FROM sand_monthly_ledger;
        ";
        $data = Yii::$app->db->createCommand($sql)->queryAll();

        return [
            'data' => $data,
            'sand_items' => $dataInventory,
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
        $initialSand   = Yii::$app->request->getBodyParam('initial_sand');

        return $this->processReportMonthly($inputDate, $initialSand);
    }

    public function processReportMonthly($inputDate, $initialSand) 
    {
        $userId = !Yii::$app->user->isGuest ? Yii::$app->user->id : 0;

        $d = $this->formatMonthlyReportDate($inputDate);

        // Normalize to the 1st of the month
        $startDate = $d->format('Y-m-01');
        
        // Calculate the start of the next month
        $endDateTime = (clone $d)->modify('first day of next month');
        $endDate = $endDateTime->format('Y-m-01');

        // Fallback: Query ending balances from the day BEFORE start_date
        if ($initialSand === null) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'Initial values parameter is required'];
        } else {
            $runningSand          = (float)$initialSand;
        }

        $transaction = Yii::$app->db->beginTransaction();

        try {
            // 1. 
            $deleteSql = Yii::$app->db->createCommand("
                DELETE FROM sand_daily_snapshots
                WHERE report_date >= :start_date AND report_date < :end_date
            ")->bindValue(':start_date', $startDate)
              ->bindValue(':end_date', $endDate)
              ->execute();

            // 2. 
            $sandDailySnapshotsInserts = Yii::$app->db->createCommand("
                INSERT INTO sand_daily_snapshots
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
                  AND s.status = 'approved' AND s.is_paid = 'yes' AND i.sand = 1
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
                    FROM sand_daily_snapshots mds
                    
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
                    FROM sand_daily_snapshots mds
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
                  AND i.sand = 1
                ORDER BY d.report_date ASC, d.inventory_id ASC;
            ")->bindValue(':start_date', $startDate)
              ->bindValue(':end_date', $endDate)
              ->queryAll();

            $salesByDate = [];
            $sandByInventory = [];
            foreach ($dailyAggregates as $row) {
                $salesByDate[$row['report_date']] = $row;

                $invId   = (int)$row['inventory_id'];
                $repDate = $row['report_date'];

                if (!isset($sandByInventory[$invId])) {
                    $sandByInventory[$invId] = [];
                }

                $sandByInventory[$invId][$repDate] = [
                    'puhunan' => (float)$row['puhunan'],
                    'tubo' => (float)$row['tubo'],
                    'expenses' => (float)$row['expenses'],
                ];

                // if (!in_array($invId, $sandInventoryIds)) {
                //     $sandInventoryIds[] = $invId;
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

            $sandInventoryIds = [1897];
            foreach ($sandInventoryIds as $invId) {
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
                INSERT INTO sand_daily_ledger (
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

                $sandPuhunan   = 0.00;
                $sandTubo      = 0.00;
                foreach ($sandInventoryIds as $invId) {
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
                    if (isset($sandByInventory[$invId][$dateStr])) {
                        $monPuhunan     = $sandByInventory[$invId][$dateStr]['puhunan'];
                        $monTubo        = $sandByInventory[$invId][$dateStr]['tubo'];
                        $monExpenses    = $sandByInventory[$invId][$dateStr]['expenses'];
                    }

                    $bindParams[":{$iCol}"]     = 0;
                    $bindParams[":{$pCol}"]     = $monPuhunan;
                    $bindParams[":{$tCol}"]     = $monTubo;
                    $bindParams[":{$eCol}"]     = $monExpenses;
                    $bindParams[":{$rCol}"]     = 0;
                    $bindParams[":{$gCol}"]     = 0;
                    $bindParams[":{$toCol}"]    = 0;

                    $sandPuhunan           += $monPuhunan;
                    $sandTubo              += $monTubo;

                    if ($invId == 1897) {
                        $bindParams[":{$iCol}"] = $runningSand;
                        $bindParams[":{$rCol}"] = $runningSand + $monPuhunan - $monExpenses;
                        $runningSand            += $monPuhunan - $monExpenses;
                    } 
                }

                $cmdUpsertDay->bindValues($bindParams)->execute();

                $currentTimestamp = strtotime('+1 day', $currentTimestamp);
            }

            // 6.
            Yii::$app->db->createCommand("
                INSERT INTO sand_monthly_ledger (
                    date,
                    i_1897,
                    p_1897,
                    t_1897,
                    e_1897,
                    r_1897,
                    g_1897,
                    to_1897,
                    created_by,
                    updated_by
                ) VALUES (
                    :date,
                    :initial_sand,
                    :puhunan_sand,
                    :tubo_sand,
                    :expenses_sand,
                    :running_sand,
                    :grand_sand,
                    :total_sand,
                    :created_by,
                    :updated_by
                )
                ON DUPLICATE KEY UPDATE
                    i_1897                  = VALUES(i_1897),
                    p_1897                  = VALUES(p_1897),
                    t_1897                  = VALUES(t_1897),
                    e_1897                  = VALUES(e_1897),
                    r_1897                  = VALUES(r_1897),
                    g_1897                  = VALUES(g_1897),
                    to_1897                 = VALUES(to_1897),
                    updated_by             = VALUES(updated_by);
            ")->bindValues([
                ':date'                     => $startDate,

                ':initial_sand'           => $initialSand,
                ':puhunan_sand'           => 0,
                ':tubo_sand'              => 0,
                ':expenses_sand'          => 0,
                ':running_sand'           => $runningSand,
                ':grand_sand'             => 0,
                ':total_sand'             => 0,

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

    public function actionGetdailysandledger() 
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

        $sqlInventory = "SELECT * FROM inventory AS i WHERE i.sand = 1";
        $dataInventory = Yii::$app->db->createCommand($sqlInventory)->queryAll();
        $additionalHeader = [];
        $sandIds = [];
        foreach ($dataInventory as $item) {
            $additionalHeader[] = ["title"=>"P - ".ucwords(strtolower($item["product_name"])),"name"=>"","align"=>"right","class"=>"w-28"];
            $additionalHeader[] = ["title"=>"T - ".ucwords(strtolower($item["product_name"])),"name"=>"","align"=>"right","class"=>"w-28"];
            // $additionalHeader[] = ["title"=>"Ex - ".ucwords(strtolower($item["product_name"])),"name"=>"","align"=>"right","class"=>"w-28"];
            $sandIds[] = $item["id"];
        }

        $tableHeader = [
            ["title"=>"#","name"=>"id","align"=>"right","class"=>"w-10"],
            ["title"=>"Date","name"=>"date","align"=>"left","class"=>"w-40"],
            ["title"=>"Tubo Sand","name"=>"tubo_sand","align"=>"right","class"=>"w-28"],
            ["title"=>"Puhunan Sand","name"=>"puhunan_sand","align"=>"right","class"=>"w-28"],
            ["title"=>"Expenses Sand","name"=>"expenses_sand","align"=>"right","class"=>"w-28"],
            ["title"=>"Running Balance Sand","name"=>"running_balance_sand","align"=>"right","class"=>"w-28"],
            // ["title"=>"Action","name"=>"action","default"=>1,"class"=>"w-20"],
        ];
        // array_splice($tableHeader, 5, 0, $additionalHeader);

        $sql = "
            SELECT * FROM sand_daily_ledger 
            WHERE date >= '$startDate' AND date < '$endDate' 
            ORDER BY date DESC;
        ";
        $data = Yii::$app->db->createCommand($sql)->queryAll();

        $sqlMonth = "
            SELECT *
            FROM sand_monthly_ledger as mbl
            WHERE date >= '$startDate' AND date < '$endDate';
        ";
        $dataMonth = Yii::$app->db->createCommand($sqlMonth)->queryOne();

        return [
            'data' => $data,
            'count' => count($data),
            'success' => true,
            'headers' => json_encode($tableHeader),

            'initialSand' => $dataMonth['i_1897'],
        ];       
    }

    public function actionDeletereportmonthly()
    {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        // Allow POST or DELETE requests
        if (!in_array(Yii::$app->request->method, ['POST', 'DELETE'])) {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed. Use POST or DELETE.'];
        }

        $id = Yii::$app->request->getBodyParam('id');
        $item = Suppliers::findOne($id);
        
        $employee_id = Yii::$app->request->getBodyParam('employee_id');
        $inputDate = Yii::$app->request->getBodyParam('date');
        if (!$inputDate) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'Date parameter is required'];
        }

        // Clean up input spacing
        $cleanDate = trim($inputDate);

        // Parse date formats: "MM, YYYY", "M, YYYY", or fallback to standard formats
        $d = \DateTime::createFromFormat('m, Y',$cleanDate);
        if (!$d) {
            $d = \DateTime::createFromFormat('n, Y',$cleanDate);
        }
        
        if (!$d) {
            $time = strtotime($cleanDate);
            if ($time !== false) {$d = new \DateTime();
                $d->setTimestamp($time);
            }
        }

        if (!$d) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'Invalid date format provided. Expected formats like "08, 2026" or "YYYY-MM"'];
        }

        // Normalize start date to the 1st day of the target month
        $startDate =$d->format('Y-m-01');
        
        // Calculate the start of the next month for range-based deletion
        $endDateTime = (clone$d)->modify('first day of next month');
        $endDate =$endDateTime->format('Y-m-01');

        $transaction = Yii::$app->db->beginTransaction();

        try {
            // 1. Delete target month snapshots
            $deletedSnapshots = Yii::$app->db->createCommand("                 
                DELETE FROM sand_daily_snapshots                 
                WHERE report_date >= :start_date AND report_date < :end_date             
            ")->bindValue(':start_date', $startDate)
            ->bindValue(':end_date', $endDate)
            ->execute();

            // 2. Delete daily business ledger records for the target month
            $deletedDailyLedger = Yii::$app->db->createCommand("                 
                DELETE FROM sand_daily_ledger                 
                WHERE date >= :start_date AND date < :end_date             
            ")->bindValue(':start_date', $startDate)
            ->bindValue(':end_date', $endDate)
            ->execute();

            // 3. Delete monthly business ledger summary record
            $deletedMonthlyLedger = Yii::$app->db->createCommand("
                DELETE FROM sand_monthly_ledger
                WHERE `date` = :start_date
            ")->bindValue(':start_date', $startDate)
            ->execute();

            $transaction->commit();

            // ✅ Insert into audit log after successful delete
            Yii::$app->db->createCommand()->insert('audit_log', [
                'entity' => 'sand monthly report',
                'entity_id' => $id,
                'action' => 'delete',
                'old_data' => json_encode($deletedMonthlyLedger),
                'new_data' => null,
                'updated_by' => $employee_id,
                'updated_at' => date('Y-m-d H:i:s'),
            ])->execute();

            return [
                'success' => true,
                'message' => 'CHB monthly ledger reports and daily records successfully deleted.',
                'period'  => [
                    'start_date' => $startDate,
                    'end_date'   => date('Y-m-d', strtotime('-1 day', strtotime($endDate))),
                ],
                'deleted_counts' => [
                    'snapshots'      => $deletedSnapshots,
                    'daily_ledger'   => $deletedDailyLedger,
                    'monthly_ledger' => $deletedMonthlyLedger,
                ]
            ];

        } catch (\Exception $e) {$transaction->rollBack();
            Yii::$app->response->statusCode = 500;
            return [
                'success' => false,
                'error'   => 'Failed to delete sand monthly ledger reports: ' . $e->getMessage()
            ];
        }
    }

}