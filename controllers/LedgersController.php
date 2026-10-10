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

class LedgersController extends Controller
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

    public function actionUpdatereportmonthly()
    {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        if (Yii::$app->request->method !== 'POST') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $inputDate = Yii::$app->request->getBodyParam('date');

        // Get initial running parameters if passed via request body
        $initialMoneyHand = Yii::$app->request->getBodyParam('running_money_on_hand');
        $initialTubo      = Yii::$app->request->getBodyParam('running_tubo');
        $initialPuhunan   = Yii::$app->request->getBodyParam('running_puhunan');

        return $this->processReportMonthly($inputDate, $initialMoneyHand, $initialTubo, $initialPuhunan);
    }

    public function processReportMonthly($inputDate, $initialMoneyHand, $initialTubo, $initialPuhunan) {
        $d = $this->formatMonthlyReportDate($inputDate);

        // Normalize to the 1st of the month
        $startDate = $d->format('Y-m-01');
        
        // Calculate the start of the next month
        $endDateTime = (clone $d)->modify('first day of next month');
        $endDate = $endDateTime->format('Y-m-01');

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
                  AND i.sand = 0 AND i.chb = 0 and i.gravel = 0
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
                ORDER BY report_date ASC, inventory_id ASC
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
                    initial_money_on_hand,
                    initial_puhunan,
                    initial_tubo,
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
                    :initial_money_on_hand,
                    :initial_puhunan,
                    :initial_tubo,
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
                    initial_money_on_hand   = VALUES(initial_money_on_hand),
                    initial_puhunan         = VALUES(initial_puhunan),
                    initial_tubo            = VALUES(initial_tubo),
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

                ':initial_money_on_hand'    => $initialMoneyHand,
                ':initial_puhunan'          => $initialPuhunan,
                ':initial_tubo'             => $initialTubo,

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
            ["title"=>"Initial Money on Hand","name"=>"puhunan","align"=>"right","class"=>"w-40"],
            // ["title"=>"Initial Puhunan","name"=>"puhunan","align"=>"right","class"=>"w-28"],
            // ["title"=>"Initial Tubo","name"=>"tubo","align"=>"right","class"=>"w-28"],
            ["title"=>"Initial Puhunan","name"=>"total_sales","align"=>"right","class"=>"w-28"],
            ["title"=>"Initial Tubo","name"=>"total_sales","align"=>"right","class"=>"w-28"],
            // ["title"=>"Total Sales","name"=>"total_amount","align"=>"right","class"=>"w-28"],
            ["title"=>"Final Money On Hand","name"=>"money_on_hand","align"=>"right","class"=>"w-40"],
            ["title"=>"Final Puhunan","name"=>"total_puhunan","align"=>"right","class"=>"w-28"],
            ["title"=>"Final Tubo","name"=>"total_tubo","align"=>"right","class"=>"w-28"],
            ["title"=>"Action","name"=>"action","default"=>1,"class"=>"w-20"],
        ];
        array_splice($tableHeader, 5, 0, $additionalHeader);

        // $prefixed_string = "";
        // $prefixed_array = array_map(function($item) { return 'ex_' . $item; }, $monitoredIds);
        // $prefixed_string = implode(", ", $prefixed_array);
        // $prefixed_array = array_map(function($item) { return 'ex_' . $item . '_details'; }, $monitoredIds);
        // $prefixed_string = ", " . $prefixed_string . ", " . implode(", ", $prefixed_array);

        $sql = "
            SELECT 
                *, 
                DATE_FORMAT(date, '%M, %Y') AS date,
                DATE_FORMAT(date, '%m, %Y') AS date_value
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

    public function actionGetmonthlyviewbusinessledger() 
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
            ["title"=>"Puhunan","name"=>"puhunan","align"=>"right","class"=>"w-28"],
            ["title"=>"Tubo","name"=>"tubo","align"=>"right","class"=>"w-28"],
            ["title"=>"Total Sales","name"=>"total_sales","align"=>"right","class"=>"w-28"],
            ["title"=>"Hardware","name"=>"hardware","align"=>"right","class"=>"w-28"],
            ["title"=>"Bahay","name"=>"bahay","align"=>"right","class"=>"w-28"],
            ["title"=>"Total Amount","name"=>"total_amount","align"=>"right","class"=>"w-28"],
            ["title"=>"Money On Hand","name"=>"money_on_hand","align"=>"right","class"=>"w-28"],
            ["title"=>"Total Puhunan","name"=>"total_puhunan","align"=>"right","class"=>"w-28"],
            ["title"=>"Total Tubo","name"=>"total_tubo","align"=>"right","class"=>"w-28"],
            // ["title"=>"Action","name"=>"action","default"=>1,"class"=>"w-20"],
        ];
        array_splice($tableHeader, 5, 0, $additionalHeader);

        $sql = "
            SELECT * FROM daily_business_ledger_v2 
            WHERE report_date >= '$startDate' AND report_date < '$endDate' 
            ORDER BY report_date DESC;
        ";
        $data = Yii::$app->db->createCommand($sql)->queryAll();

        $sqlTotals = "
            SELECT 
                COALESCE(SUM(dbl.puhunan), 0) AS puhunan,
                COALESCE(SUM(dbl.tubo), 0) AS tubo,
                COALESCE(SUM(dbl.total_sales), 0) AS total_sales,

                COALESCE(SUM(dbl.p_21), 0) AS puhunan_cement,
                COALESCE(SUM(dbl.t_21), 0) AS tubo_cement,

                SUM(+ COALESCE(dbl.p_1421, 0) + COALESCE(dbl.p_1422, 0) + COALESCE(dbl.p_1423, 0)) AS puhunan_rsb,
                SUM(+ COALESCE(dbl.t_1421, 0) + COALESCE(dbl.t_1422, 0) + COALESCE(dbl.t_1423, 0)) AS tubo_rsb,


                COALESCE(SUM(dbl.puhunan), 0) AS puhunan,
                DATE_FORMAT(report_date, '%M, %Y') AS group_date
            FROM daily_business_ledger_v2 as dbl
            WHERE report_date >= '$startDate' AND report_date < '$endDate'
            GROUP BY group_date;
        ";
        $dataTotals = Yii::$app->db->createCommand($sqlTotals)->queryOne();

        $sqlMonth = "
            SELECT *
            FROM monthly_business_ledger_v2 as mbl
            WHERE date >= '$startDate' AND date < '$endDate';
        ";
        $dataMonth = Yii::$app->db->createCommand($sqlMonth)->queryOne();

        if (empty($dataTotals)) {
            return [
                'success' => false,
                'message' => 'No total data available',
                'data' => $data,
                'count' => 0,
                'mids' => $dataInventory,
                'headers' => json_encode($tableHeader),
            ];
        }

        return [
            'data' => $data,
            'mids' => $dataInventory,
            'count' => count($data),
            'success' => true,
            'headers' => json_encode($tableHeader),

            'totalPuhunan' => $dataTotals['puhunan'],
            'totalTubo' => $dataTotals['tubo'],
            'totalSales' => $dataTotals['total_sales'],

            'totalPuhunanCement' => $dataTotals['puhunan_cement'],
            'totalTuboCement' => $dataTotals['tubo_cement'],

            'totalPuhunanRSB' => $dataTotals['puhunan_rsb'],
            'totalTuboRSB' => $dataTotals['tubo_rsb'],

            'initialMoneyOnHand' => $dataMonth['initial_money_on_hand'],
            'initialPuhunan' => $dataMonth['initial_puhunan'],
            'initialTubo' => $dataMonth['initial_tubo'],

            'finalMoneyOnHand' => $dataMonth['running_money_on_hand'],
            'finalPuhunan' => $dataMonth['running_puhunan'],
            'finalTubo' => $dataMonth['running_tubo'],
            'reportId' => $dataMonth['id'],

            // 'dataTotals' => $dataTotals,
            // 'sql' => $sql,
        ];       
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
            $sql = "UPDATE daily_business_ledger_v2 SET ".$save_type." = :amount, ".$save_type."_details = :details WHERE id = :id";

            $rowsAffected = Yii::$app->db->createCommand($sql)
                ->bindValues([
                    ':amount' => $amount,
                    ':details' => $details,
                    ':id' => $id,
                ])
                ->execute();

            $date = Yii::$app->request->getBodyParam('date');
            $initialMoneyOnHand = Yii::$app->request->getBodyParam('initialMoneyOnHand');
            $initialPuhunan = Yii::$app->request->getBodyParam('initialPuhunan');
            $initialTubo = Yii::$app->request->getBodyParam('initialTubo');
            $this->processReportMonthly($date, $initialMoneyOnHand, $initialPuhunan, $initialTubo);

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
}