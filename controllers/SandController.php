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
            ["title"=>"Initial Puhunan","name"=>"initial","align"=>"right","class"=>"w-40"],
            ["title"=>"Running Balance","name"=>"running","align"=>"right","class"=>"w-40"],
            // ["title"=>"Final Gravel","name"=>"final","align"=>"right","class"=>"w-40"],
            // ["title"=>"Initial White Sand","name"=>"initial","align"=>"right","class"=>"w-40"],
            // ["title"=>"Final White Sand","name"=>"final","align"=>"right","class"=>"w-40"],
            // ["title"=>"Initial Sand Bistay","name"=>"initial","align"=>"right","class"=>"w-40"],
            // ["title"=>"Final Sand Bistay","name"=>"final","align"=>"right","class"=>"w-40"],
            // ["title"=>"Initial Sand Hindi Bistay","name"=>"initial","align"=>"right","class"=>"w-40"],
            // ["title"=>"Final Sand Hindi Bistay","name"=>"final","align"=>"right","class"=>"w-40"],
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
        $initialGsData            = [];
        $initialGsData[0]         = Yii::$app->request->getBodyParam('initial');
        $initialGsData[1898]      = 0.00; // Yii::$app->request->getBodyParam('initial_gravel');
        $initialGsData[1897]      = 0.00; // Yii::$app->request->getBodyParam('initial_sand');
        $initialGsData[1906]      = 0.00; // Yii::$app->request->getBodyParam('initial_sand_b');
        $initialGsData[1907]      = 0.00; // Yii::$app->request->getBodyParam('initial_sand_h');

        return $this->processReportMonthly($inputDate, $initialGsData);
    }

    public function processReportMonthly($inputDate, $initialGsData)
    {
        $userId = !Yii::$app->user->isGuest ? Yii::$app->user->id : 0;

        $d = $this->formatMonthlyReportDate($inputDate);

        // Normalize to the 1st of the month
        $startDate = $d->format('Y-m-01');
        
        // Calculate the start of the next month
        $endDateTime = (clone $d)->modify('first day of next month');
        $endDate = $endDateTime->format('Y-m-01');

        // Fallback: Query ending balances from the day BEFORE start_date
        $runningGsData = [];
        if ($initialGsData === null) {
            Yii::$app->response->statusCode = 400;
            return ['success' => false, 'error' => 'Initial values parameter is required'];
        } else {
            $runningGsData        = $initialGsData;
        }
        $runningTotal = $runningGsData[0];

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
                'date', 'created_by', 'initial', 'running'
            ];

            $insertValues = [
                ':date', ':created_by', ':initial', ':running'
            ];

            $updateClauses = [
                'updated_by = :updated_by',
                'initial = :initial',
                'running = :running',
            ];

            $sandInventoryIds = [1898,1897,1906,1907];
            $sumBindParams    = [];
            foreach ($sandInventoryIds as $invId) {
                $iCol = "i_{$invId}";
                $pCol = "p_{$invId}";
                $tCol = "t_{$invId}";
                $eCol = "e_{$invId}";
                $exCol = "ex_{$invId}";
                $rCol = "r_{$invId}";
                $gCol = "g_{$invId}";
                $toCol = "to_{$invId}";

                $insertCols[]   = "`{$iCol}`";
                $insertCols[]   = "`{$pCol}`";
                $insertCols[]   = "`{$tCol}`";
                $insertCols[]   = "`{$eCol}`";
                $insertCols[]   = "`{$exCol}`";
                $insertCols[]   = "`{$rCol}`";
                $insertCols[]   = "`{$gCol}`";
                $insertCols[]   = "`{$toCol}`";

                $insertValues[] = ":{$iCol}";
                $insertValues[] = ":{$pCol}";
                $insertValues[] = ":{$tCol}";
                $insertValues[] = ":{$eCol}";
                $insertValues[] = ":{$exCol}";
                $insertValues[] = ":{$rCol}";
                $insertValues[] = ":{$gCol}";
                $insertValues[] = ":{$toCol}";

                $updateClauses[] = "`{$iCol}` = VALUES(`{$iCol}`)";
                $updateClauses[] = "`{$pCol}` = VALUES(`{$pCol}`)";
                $updateClauses[] = "`{$tCol}` = VALUES(`{$tCol}`)";
                $updateClauses[] = "`{$eCol}` = VALUES(`{$eCol}`)";
                $updateClauses[] = "`{$exCol}` = VALUES(`{$exCol}`)";
                $updateClauses[] = "`{$rCol}` = VALUES(`{$rCol}`)";
                $updateClauses[] = "`{$gCol}` = VALUES(`{$gCol}`)";
                $updateClauses[] = "`{$toCol}` = VALUES(`{$toCol}`)";

                $sumBindParams["e_{$invId}"]    = 0.0;
                $sumBindParams["ex_{$invId}"]   = 0.0;
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

            $origData = Yii::$app->db->createCommand("
                SELECT * FROM sand_daily_ledger d
                WHERE date >= :start_date 
                AND date < :end_date 
                ORDER BY date ASC;
            ")->bindValue(':start_date', $startDate)->bindValue(':end_date', $endDate)->queryAll();
            $origData = array_column($origData, null, 'date');

            $totalReplenishments    = 0.0;
            $totalExpenses          = 0.0;
            while ($currentTimestamp < $endTimestamp) {
                $dateStr = date('Y-m-d', $currentTimestamp);
                $bindParams = [':date' => $dateStr];
                $bindParams[':created_by'] = $userId;
                $bindParams[':updated_by'] = $userId;
                $bindParams[':initial']    = $runningGsData[0];

                $allExpenses = 0.00;
                if (isset($origData[$dateStr])) {
                    $origItem = $origData[$dateStr];
                    $allExpenses = $origItem['expenses'];
                }

                foreach ($sandInventoryIds as $invId) {
                    $iCol   = "i_{$invId}";
                    $pCol   = "p_{$invId}";
                    $tCol   = "t_{$invId}";
                    $eCol   = "e_{$invId}";
                    $exCol   = "ex_{$invId}";
                    $rCol   = "r_{$invId}";
                    $gCol   = "g_{$invId}";
                    $toCol  = "to_{$invId}";

                    $exPenses = 0.00;
                    if (isset($origData[$dateStr])) {
                        $origItem = $origData[$dateStr];
                        $exPenses = $origItem[$exCol];
                    }

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
                    $totalReplenishments        += $monExpenses;
                    $bindParams[":{$exCol}"]    = $exPenses;
                    $bindParams[":{$rCol}"]     = 0;
                    $bindParams[":{$gCol}"]     = 0;
                    $bindParams[":{$toCol}"]    = 0;

                    $bindParams[":{$iCol}"]     = $runningGsData[$invId];
                    $bindParams[":{$rCol}"]     = $runningGsData[$invId] + $monPuhunan - $monExpenses - $exPenses;
                    $runningGsData[$invId]      += $monPuhunan - $monExpenses - $exPenses;

                    $runningGsData[0]           += $monPuhunan - $monExpenses - $exPenses;

                    $sumBindParams["e_{$invId}"]    += $monExpenses;
                    $sumBindParams["ex_{$invId}"]   += $exPenses;
                }
                $runningGsData[0]               = $runningGsData[0] - $allExpenses;

                $bindParams[':running']         = $runningGsData[0];

                $res = $cmdUpsertDay->bindValues($bindParams)->execute();

                $currentTimestamp = strtotime('+1 day', $currentTimestamp);
            }

            // 6.
            $columns = ['date'];
            $values = [':date'];
            $updates = [];
            $params = [
                ':date' => $startDate,
                ':created_by' => $userId,
                ':updated_by' => $userId,
                ':initial' => $initialGsData[0],
                ':running' => $runningGsData[0],
                ':replenishments' => $totalReplenishments,
            ];

            // Loop through each inventory ID to build columns, placeholders, and updates dynamically
            foreach ($sandInventoryIds as $id) {
                // Table columns
                $columns[] = 'i_' . $id;
                $columns[] = 'p_' . $id;
                $columns[] = 't_' . $id;
                $columns[] = 'e_' . $id;
                $columns[] = 'ex_' . $id;
                $columns[] = 'r_' . $id;
                $columns[] = 'g_' . $id;
                $columns[] = 'to_' . $id;

                // Value placeholders
                $values[] = ':initial_' . $id;
                $values[] = ':puhunan_' . $id;
                $values[] = ':tubo_' . $id;
                $values[] = ':replenishment_' . $id;
                $values[] = ':expenses_' . $id;
                $values[] = ':running_' . $id;
                $values[] = ':grand_' . $id;
                $values[] = ':total_' . $id;

                // Bind default values (e.g., 0)
                $params[':initial_' . $id] = $initialGsData[$id];
                $params[':puhunan_' . $id] = 0;
                $params[':tubo_' . $id] = 0;
                $params[':replenishment_' . $id] = $sumBindParams["e_{$id}"];
                $params[':expenses_' . $id] = $sumBindParams["ex_{$id}"];
                $params[':running_' . $id] = $runningGsData[$id];
                $params[':grand_' . $id] = 0;
                $params[':total_' . $id] = 0;

                // ON DUPLICATE KEY UPDATE mappings
                $updates[] = 'i_' . $id . ' = VALUES(i_' . $id . ')';
                $updates[] = 'p_' . $id . ' = VALUES(p_' . $id . ')';
                $updates[] = 't_' . $id . ' = VALUES(t_' . $id . ')';
                $updates[] = 'e_' . $id . ' = VALUES(e_' . $id . ')';
                $updates[] = 'ex_' . $id . ' = VALUES(ex_' . $id . ')';
                $updates[] = 'r_' . $id . ' = VALUES(r_' . $id . ')';
                $updates[] = 'g_' . $id . ' = VALUES(g_' . $id . ')';
                $updates[] = 'to_' . $id . ' = VALUES(to_' . $id . ')';
            }

            // Append audit columns
            $columns[] = 'created_by';
            $columns[] = 'updated_by';
            $columns[] = 'initial';
            $columns[] = 'running';
            $columns[] = 'replenishments';
            $values[] = ':created_by';
            $values[] = ':updated_by';
            $values[] = ':initial';
            $values[] = ':running';
            $values[] = ':replenishments';
            $updates[] = 'updated_by = VALUES(updated_by)';
            $updates[] = 'initial = VALUES(initial)';
            $updates[] = 'running = VALUES(running)';
            $updates[] = 'replenishments = VALUES(replenishments)';

            // Assemble the final SQL string
            $sql = "INSERT INTO sand_monthly_ledger (" . implode(', ', $columns) . ") 
                    VALUES (" . implode(', ', $values) . ") 
                    ON DUPLICATE KEY UPDATE " . implode(', ', $updates);

            // Execute the command
            Yii::$app->db->createCommand($sql)
                ->bindValues($params)
                ->execute();

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
                // 'sumBindParams' => $sumBindParams,
                // 'orig_item' => $origItem
            ];

        } catch (\Exception $e) {
            $transaction->rollBack();
            Yii::$app->response->statusCode = 500;
            return [
                'success' => false,
                'error'   => 'Failed to update sand ledger reports: ' . $e->getMessage()
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
            ["title"=>"Tubo Gravel","name"=>"tubo_gravel","align"=>"right","class"=>"w-28"],
            ["title"=>"Puhunan Gravel","name"=>"puhunan_gravel","align"=>"right","class"=>"w-28"],
            ["title"=>"Replenishment Gravel","name"=>"expenses_gravel","align"=>"right","class"=>"w-28"],
            // ["title"=>"Expenses Gravel","name"=>"expenses_gravel","align"=>"right","class"=>"w-28"],
            // ["title"=>"Running Balance Gravel","name"=>"running_balance_gravel","align"=>"right","class"=>"w-28"],
            ["title"=>"Tubo White Sand","name"=>"tubo_sand","align"=>"right","class"=>"w-28"],
            ["title"=>"Puhunan White Sand","name"=>"puhunan_sand","align"=>"right","class"=>"w-28"],
            ["title"=>"Replenishment White Sand","name"=>"expenses_sand","align"=>"right","class"=>"w-28"],
            // ["title"=>"Expenses White Sand","name"=>"expenses_sand","align"=>"right","class"=>"w-28"],
            // ["title"=>"Running Balance White Sand","name"=>"running_balance_sand","align"=>"right","class"=>"w-28"],
            ["title"=>"Tubo Sand Bistay","name"=>"tubo_sand_b","align"=>"right","class"=>"w-28"],
            ["title"=>"Puhunan Sand Bistay","name"=>"puhunan_sand_b","align"=>"right","class"=>"w-28"],
            ["title"=>"Replenishment Sand Bistay","name"=>"expenses_sand_b","align"=>"right","class"=>"w-28"],
            // ["title"=>"Expenses Sand Bistay","name"=>"expenses_sand_b","align"=>"right","class"=>"w-28"],
            // ["title"=>"Running Balance Sand Bistay","name"=>"running_balance_sand_b","align"=>"right","class"=>"w-28"],
            ["title"=>"Tubo Sand Hindi Bistay","name"=>"tubo_sand_h","align"=>"right","class"=>"w-28"],
            ["title"=>"Puhunan Sand Hindi Bistay","name"=>"puhunan_sand_h","align"=>"right","class"=>"w-28"],
            ["title"=>"Replenishment Sand Hindi Bistay","name"=>"expenses_sand_h","align"=>"right","class"=>"w-28"],
            // ["title"=>"Expenses Sand Hindi Bistay","name"=>"expenses_sand_h","align"=>"right","class"=>"w-28"],
            // ["title"=>"Running Balance Sand Hindi Bistay","name"=>"running_balance_sand_h","align"=>"right","class"=>"w-28"],
            ["title"=>"Expenses","name"=>"expenses","align"=>"right","class"=>"w-28"],
            ["title"=>"Running Balance","name"=>"running_balance","align"=>"right","class"=>"w-28"],
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

            'initial'           => $dataMonth['initial'],
            'running'           => $dataMonth['running'],
            'replenishments'   => $dataMonth['replenishments'],

            'initialGravel' => $dataMonth['i_1898'],
            'initialSand'   => $dataMonth['i_1897'],
            'initialSandB'  => $dataMonth['i_1906'],
            'initialSandH'  => $dataMonth['i_1907'],

            'finalGravel' => $dataMonth['r_1898'],
            'finalSand'   => $dataMonth['r_1897'],
            'finalSandB'  => $dataMonth['r_1906'],
            'finalSandH'  => $dataMonth['r_1907'],

            'replenishmentGravel' => $dataMonth['e_1898'],
            'replenishmentSand'   => $dataMonth['e_1897'],
            'replenishmentSandB'  => $dataMonth['e_1906'],
            'replenishmentSandH'  => $dataMonth['e_1907'],

            'total' => [
                'initialTotal'  => (float)$dataMonth['i_1898'] + (float)$dataMonth['i_1897'] + (float)$dataMonth['i_1906'] + (float)$dataMonth['i_1907'],
                'finalTotal'  => (float)$dataMonth['r_1898'] + (float)$dataMonth['r_1897'] + (float)$dataMonth['r_1906'] + (float)$dataMonth['r_1907']
            ]
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
            // $save_type = ($save_type == "bahay" || $save_type == "hardware") ? $save_type : "ex_" . $save_type ;
            $sql = "UPDATE sand_daily_ledger SET ".$save_type." = :amount, ".$save_type."_details = :details WHERE id = :id";

            $rowsAffected = Yii::$app->db->createCommand($sql)
                ->bindValues([
                    ':amount' => $amount,
                    ':details' => $details,
                    ':id' => $id,
                ])
                ->execute();

            $date = Yii::$app->request->getBodyParam('date');
            $initialGsData            = [];
            $initialGsData[0]         = Yii::$app->request->getBodyParam('initial');
            $initialGsData[1898]      = Yii::$app->request->getBodyParam('initialGravel');
            $initialGsData[1897]      = Yii::$app->request->getBodyParam('initialSand');
            $initialGsData[1906]      = Yii::$app->request->getBodyParam('initialSandB');
            $initialGsData[1907]      = Yii::$app->request->getBodyParam('initialSandH');

            
            $processUpdate = $this->processReportMonthly($date, $initialGsData);

            if ($processUpdate['success']) {
                return [
                    'success' => true,
                    'message' => 'Sand ledger updated successfully',
                    'rows_affected' => $rowsAffected
                ];
            } else {
                return [
                    'success' => false,
                    'error' => $processUpdate['error'],
                    'rows_affected' => $rowsAffected
                ];
            }

        } catch (\Exception $e) {
            // Handle database errors gracefully without crashing the API
            Yii::$app->response->statusCode = 500;
            return [
                'success' => false,
                'error' => 'Database error: ' . $e->getMessage()
            ];
        }
    }

    public function actionGetsandreplenishmentview() 
    {
        if (Yii::$app->request->method !== 'GET') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $date = Yii::$app->request->get('date');
        $id = Yii::$app->request->get('item_id');

        $data_inventory = Yii::$app->db->createCommand("SELECT * FROM inventory WHERE id = '$id';")->queryOne();
        $data_summary = Yii::$app->db->createCommand("SELECT SUM(ri.total) as total FROM replenishment_items ri LEFT JOIN replenishment r ON r.id = ri.transaction_id WHERE r.date_received = '$date'  AND ri.inventory_id = '$id';")->queryOne();

        $sql = "
            SELECT
                ri.id,
                r.reference_no,
                ri.cost_per_unit,
                ri.qty_added,
                ri.total,
                r.supplier,
                r.remarks,
                i.product_name
            FROM replenishment_items ri 
            LEFT JOIN replenishment r
            ON r.id = ri.transaction_id
            LEFT JOIN inventory i
            ON i.id = ri.inventory_id
                WHERE r.date_received = '$date'  AND i.id = '$id'
            ORDER BY r.reference_no ASC, ri.id ASC;
        ";
        $data = Yii::$app->db->createCommand($sql)->queryAll();

        return [
            'data' => $data,
            'success' => true,
            'summary' => [
                'product_name' => $data_inventory['product_name'],
                'count' => count($data),
                'date' => $date,
                'total' => $data_summary['total']
            ]
        ];       
    }
}