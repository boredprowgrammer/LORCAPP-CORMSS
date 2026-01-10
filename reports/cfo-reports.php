<?php
require_once __DIR__ . '/../config/config.php';

Security::requireLogin();
requirePermission('can_view_reports');

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

// Get transaction filter from URL (default to 'all')
$transactionFilter = Security::sanitizeInput($_GET['filter'] ?? 'all');

// Calculate date ranges for filters
$today = new DateTime();
$dateFilters = [
    'week' => (clone $today)->modify('-7 days')->format('Y-m-d'),
    'month' => (clone $today)->modify('-1 month')->format('Y-m-d'),
    'year' => (clone $today)->modify('-1 year')->format('Y-m-d')
];

// Build WHERE clause based on user role (for statistics, include all statuses)
$whereConditions = [];
$params = [];

if ($currentUser['role'] === 'district') {
    $whereConditions[] = 't.district_code = ?';
    $params[] = $currentUser['district_code'];
} elseif ($currentUser['role'] === 'local' || $currentUser['role'] === 'local_cfo') {
    $whereConditions[] = 't.local_code = ?';
    $params[] = $currentUser['local_code'];
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Get statistics by organization
$stats = [];
try {
    $stmt = $db->prepare("
        SELECT 
            t.cfo_classification,
            COUNT(*) as count,
            t.cfo_status,
            SUM(CASE WHEN t.cfo_status = 'active' THEN 1 ELSE 0 END) as active_count
        FROM tarheta_control t
        $whereClause
        GROUP BY t.cfo_classification, t.cfo_status
        ORDER BY t.cfo_classification
    ");
    $stmt->execute($params);
    $results = $stmt->fetchAll();
    
    foreach ($results as $row) {
        $classification = $row['cfo_classification'] ?: 'Unclassified';
        if (!isset($stats[$classification])) {
            $stats[$classification] = ['total' => 0, 'active' => 0, 'transferred' => 0];
        }
        if ($row['cfo_status'] === 'active') {
            $stats[$classification]['active'] += $row['count'];
        } else {
            $stats[$classification]['transferred'] += $row['count'];
        }
        $stats[$classification]['total'] += $row['count'];
    }
} catch (Exception $e) {
    error_log("Error loading CFO stats: " . $e->getMessage());
}

// Get officer counts by CFO classification
$officerStats = ['Buklod' => 0, 'Kadiwa' => 0, 'Binhi' => 0];
try {
    // Build WHERE clause for officers
    $officerWhereConditions = ['o.is_active = 1'];
    $officerParams = [];
    
    if ($currentUser['role'] === 'district') {
        $officerWhereConditions[] = 'o.district_code = ?';
        $officerParams[] = $currentUser['district_code'];
    } elseif ($currentUser['role'] === 'local' || $currentUser['role'] === 'local_cfo') {
        $officerWhereConditions[] = 'o.local_code = ?';
        $officerParams[] = $currentUser['local_code'];
    }
    
    $officerWhereClause = 'WHERE ' . implode(' AND ', $officerWhereConditions);
    
    $stmt = $db->prepare("
        SELECT 
            tc.cfo_classification,
            COUNT(DISTINCT o.officer_id) as officer_count
        FROM officers o
        INNER JOIN tarheta_control tc ON o.registry_number_encrypted = tc.registry_number_encrypted 
            AND o.district_code = tc.district_code
        $officerWhereClause
            AND tc.cfo_classification IS NOT NULL
            AND tc.cfo_status = 'active'
        GROUP BY tc.cfo_classification
    ");
    $stmt->execute($officerParams);
    $results = $stmt->fetchAll();
    
    foreach ($results as $row) {
        if (isset($officerStats[$row['cfo_classification']])) {
            $officerStats[$row['cfo_classification']] = $row['officer_count'];
        }
    }
} catch (Exception $e) {
    error_log("Error loading officer CFO stats: " . $e->getMessage());
}

// Get weekly and monthly changes (dagdag/bawas) by CFO classification
// First, get the last reset timestamps for this local
$resetTimestamps = ['week' => [], 'month' => []];
if ($currentUser['role'] === 'local' || $currentUser['role'] === 'local_cfo') {
    try {
        $stmt = $db->prepare("
            SELECT classification, period, MAX(reset_at) as last_reset
            FROM cfo_report_resets
            WHERE local_code = ?
            GROUP BY classification, period
        ");
        $stmt->execute([$currentUser['local_code']]);
        $resets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($resets as $reset) {
            $resetTimestamps[$reset['period']][$reset['classification']] = $reset['last_reset'];
        }
    } catch (Exception $e) {
        error_log("Error loading reset timestamps: " . $e->getMessage());
    }
}

$weeklyChanges = ['Buklod' => 0, 'Kadiwa' => 0, 'Binhi' => 0];
$monthlyChanges = ['Buklod' => 0, 'Kadiwa' => 0, 'Binhi' => 0];
try {
    $weekAgo = (clone $today)->modify('-7 days')->format('Y-m-d');
    $monthAgo = (clone $today)->modify('-1 month')->format('Y-m-d');
    
    // Weekly changes (newly added - newly transferred out)
    $changeWhere = $whereConditions;
    $changeParams = $params;
    
    // Process each classification separately to apply individual reset timestamps
    foreach (['Buklod', 'Kadiwa', 'Binhi'] as $classification) {
        // Determine baseline date for weekly calculation
        $weekBaseline = $weekAgo;
        if (isset($resetTimestamps['week'][$classification])) {
            $weekBaseline = $resetTimestamps['week'][$classification];
        } elseif (isset($resetTimestamps['week']['all'])) {
            $weekBaseline = $resetTimestamps['week']['all'];
        }
        
        // Count newly added this week for this classification
        $stmt = $db->prepare("
            SELECT COUNT(*) as added_count
            FROM tarheta_control t
            " . ($changeWhere ? 'WHERE ' . implode(' AND ', $changeWhere) . ' AND ' : 'WHERE ') . "
                t.cfo_status = 'active'
                AND t.created_at >= ?
                AND t.cfo_classification = ?
        ");
        $stmt->execute(array_merge($changeParams, [$weekBaseline, $classification]));
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $weeklyChanges[$classification] += $result['added_count'];
        
        // Count transferred out this week for this classification
        $stmt = $db->prepare("
            SELECT COUNT(*) as removed_count
            FROM tarheta_control t
            " . ($changeWhere ? 'WHERE ' . implode(' AND ', $changeWhere) . ' AND ' : 'WHERE ') . "
                t.cfo_status = 'transferred-out'
                AND COALESCE(t.transfer_out_date, t.updated_at) >= ?
                AND t.cfo_classification = ?
        ");
        $stmt->execute(array_merge($changeParams, [$weekBaseline, $classification]));
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $weeklyChanges[$classification] -= $result['removed_count'];
        
        // Determine baseline date for monthly calculation
        $monthBaseline = $monthAgo;
        if (isset($resetTimestamps['month'][$classification])) {
            $monthBaseline = $resetTimestamps['month'][$classification];
        } elseif (isset($resetTimestamps['month']['all'])) {
            $monthBaseline = $resetTimestamps['month']['all'];
        }
        
        // Count newly added this month for this classification
        $stmt = $db->prepare("
            SELECT COUNT(*) as added_count
            FROM tarheta_control t
            " . ($changeWhere ? 'WHERE ' . implode(' AND ', $changeWhere) . ' AND ' : 'WHERE ') . "
                t.cfo_status = 'active'
                AND t.created_at >= ?
                AND t.cfo_classification = ?
        ");
        $stmt->execute(array_merge($changeParams, [$monthBaseline, $classification]));
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $monthlyChanges[$classification] += $result['added_count'];
        
        // Count transferred out this month for this classification
        $stmt = $db->prepare("
            SELECT COUNT(*) as removed_count
            FROM tarheta_control t
            " . ($changeWhere ? 'WHERE ' . implode(' AND ', $changeWhere) . ' AND ' : 'WHERE ') . "
                t.cfo_status = 'transferred-out'
                AND COALESCE(t.transfer_out_date, t.updated_at) >= ?
                AND t.cfo_classification = ?
        ");
        $stmt->execute(array_merge($changeParams, [$monthBaseline, $classification]));
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $monthlyChanges[$classification] -= $result['removed_count'];
    }
} catch (Exception $e) {
    error_log("Error loading CFO changes: " . $e->getMessage());
}

// Build WHERE clause for turning 18 query (needs birthday and active status)
$whereConditionsTurning18 = ['t.birthday_encrypted IS NOT NULL', "t.cfo_status = 'active'"];
$paramsTurning18 = [];

if ($currentUser['role'] === 'district') {
    $whereConditionsTurning18[] = 't.district_code = ?';
    $paramsTurning18[] = $currentUser['district_code'];
} elseif ($currentUser['role'] === 'local' || $currentUser['role'] === 'local_cfo') {
    $whereConditionsTurning18[] = 't.local_code = ?';
    $paramsTurning18[] = $currentUser['local_code'];
}

$whereClauseTurning18 = 'WHERE ' . implode(' AND ', $whereConditionsTurning18);

// Get members turning 18 soon (Binhi → Kadiwa candidates)
$turningEighteen = [];
try {
    $stmt = $db->prepare("
        SELECT 
            t.id,
            t.last_name_encrypted,
            t.first_name_encrypted,
            t.middle_name_encrypted,
            t.birthday_encrypted,
            t.district_code,
            t.cfo_classification,
            d.district_name,
            lc.local_name
        FROM tarheta_control t
        LEFT JOIN districts d ON t.district_code = d.district_code
        LEFT JOIN local_congregations lc ON t.local_code = lc.local_code
        $whereClauseTurning18
          AND t.cfo_classification = 'Binhi'
        ORDER BY t.birthday_encrypted ASC
    ");
    $stmt->execute($paramsTurning18);
    $records = $stmt->fetchAll();
    
    $today = new DateTime();
    $nextWeek = (new DateTime())->modify('+1 week');
    $nextMonth = (new DateTime())->modify('+1 month');
    $nextThreeMonths = (new DateTime())->modify('+3 months');
    
    foreach ($records as $record) {
        $birthday = Encryption::decrypt($record['birthday_encrypted'], $record['district_code']);
        if (!$birthday) continue;
        
        try {
            $birthDate = new DateTime($birthday);
            $age = $today->diff($birthDate)->y;
            
            // Get next birthday
            $nextBirthday = clone $birthDate;
            $nextBirthday->setDate($today->format('Y'), $birthDate->format('m'), $birthDate->format('d'));
            if ($nextBirthday < $today) {
                $nextBirthday->modify('+1 year');
            }
            
            $turningEighteenAge = 18 - $age - 1;
            $targetDate = (clone $birthDate)->modify('+18 years');
            
            // Only include those who will turn 18 within next 3 months
            if ($age >= 17 && $targetDate <= $nextThreeMonths) {
                $lastName = Encryption::decrypt($record['last_name_encrypted'], $record['district_code']);
                $firstName = Encryption::decrypt($record['first_name_encrypted'], $record['district_code']);
                $middleName = $record['middle_name_encrypted'] ? Encryption::decrypt($record['middle_name_encrypted'], $record['district_code']) : '';
                $fullName = trim("$firstName $middleName $lastName");
                
                $daysUntil18 = $today->diff($targetDate)->days;
                
                $turningEighteen[] = [
                    'id' => $record['id'],
                    'name' => $fullName,
                    'age' => $age,
                    'birthday' => $birthDate->format('M d, Y'),
                    'turning_18_date' => $targetDate->format('M d, Y'),
                    'days_until_18' => $daysUntil18,
                    'district' => $record['district_name'],
                    'local' => $record['local_name']
                ];
            }
        } catch (Exception $e) {
            error_log("Error calculating age: " . $e->getMessage());
        }
    }
    
    // Sort by days until 18
    usort($turningEighteen, function($a, $b) {
        return $a['days_until_18'] - $b['days_until_18'];
    });
    
} catch (Exception $e) {
    error_log("Error loading turning 18: " . $e->getMessage());
}

// Recent Transfer Outs
$recentTransferOuts = [];
try {
    $transferOutWhere = array_filter($whereConditions, fn($c) => !str_contains($c, 'cfo_status'));
    $transferOutWhere[] = "t.cfo_status = 'transferred-out'";
    $transferOutWhere[] = "(t.transfer_history_cleared_at IS NULL OR t.transfer_history_cleared_at < t.cfo_updated_at)";
    
    // Add date filter - using transfer_out_date or updated_at
    $transferOutParams = $params;
    if ($transactionFilter === 'week') {
        $transferOutWhere[] = "COALESCE(t.transfer_out_date, t.updated_at) >= ?";
        $transferOutParams[] = $dateFilters['week'];
    } elseif ($transactionFilter === 'month') {
        $transferOutWhere[] = "COALESCE(t.transfer_out_date, t.updated_at) >= ?";
        $transferOutParams[] = $dateFilters['month'];
    } elseif ($transactionFilter === 'year') {
        $transferOutWhere[] = "COALESCE(t.transfer_out_date, t.updated_at) >= ?";
        $transferOutParams[] = $dateFilters['year'];
    }
    
    $transferOutClause = 'WHERE ' . implode(' AND ', $transferOutWhere);
    
    $stmt = $db->prepare("
        SELECT 
            t.id,
            t.first_name_encrypted,
            t.last_name_encrypted,
            t.district_code,
            t.cfo_classification,
            t.transfer_out_date,
            t.updated_at,
            lc.local_name
        FROM tarheta_control t
        LEFT JOIN local_congregations lc ON t.local_code = lc.local_code
        $transferOutClause
        ORDER BY COALESCE(t.transfer_out_date, t.updated_at) DESC
        LIMIT 50
    ");
    $stmt->execute($transferOutParams);
    $recentTransferOuts = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Error loading transfer outs: " . $e->getMessage());
}

// Recent Classification Changes
$classificationChanges = [];
try {
    $stmt = $db->prepare("
        SELECT 
            t.id,
            t.first_name_encrypted,
            t.last_name_encrypted,
            t.district_code,
            t.cfo_classification,
            t.cfo_classification_auto,
            t.updated_at,
            lc.local_name
        FROM tarheta_control t
        LEFT JOIN local_congregations lc ON t.local_code = lc.local_code
        $whereClause
        AND t.cfo_classification != t.cfo_classification_auto
        AND t.cfo_classification IS NOT NULL
        AND (
            (t.cfo_classification_auto = 'Binhi' AND t.cfo_classification = 'Kadiwa')
            OR (t.cfo_classification_auto = 'Kadiwa' AND t.cfo_classification = 'Buklod')
        )
        AND (t.classification_history_cleared_at IS NULL OR t.classification_history_cleared_at < t.cfo_updated_at)
        ORDER BY t.updated_at DESC
        LIMIT 50
    ");
    $stmt->execute($params);
    $classificationChanges = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Error loading classification changes: " . $e->getMessage());
}

// Newly Baptized Members
$newlyBaptized = [];
try {
    $baptizedWhere = array_filter($whereConditions, fn($c) => !str_contains($c, 'cfo_status'));
    $baptizedWhere[] = "t.registration_type = 'newly-baptized'";
    $baptizedWhere[] = "t.cfo_status = 'active'";
    
    // Add date filter
    $baptizedParams = $params;
    if ($transactionFilter === 'week') {
        $baptizedWhere[] = "COALESCE(t.registration_date, t.created_at) >= ?";
        $baptizedParams[] = $dateFilters['week'];
    } elseif ($transactionFilter === 'month') {
        $baptizedWhere[] = "COALESCE(t.registration_date, t.created_at) >= ?";
        $baptizedParams[] = $dateFilters['month'];
    } elseif ($transactionFilter === 'year') {
        $baptizedWhere[] = "COALESCE(t.registration_date, t.created_at) >= ?";
        $baptizedParams[] = $dateFilters['year'];
    }
    
    $baptizedClause = 'WHERE ' . implode(' AND ', $baptizedWhere);
    
    $stmt = $db->prepare("
        SELECT 
            t.id,
            t.first_name_encrypted,
            t.last_name_encrypted,
            t.middle_name_encrypted,
            t.district_code,
            t.cfo_classification,
            t.registration_date,
            t.created_at,
            lc.local_name,
            d.district_name
        FROM tarheta_control t
        LEFT JOIN local_congregations lc ON t.local_code = lc.local_code
        LEFT JOIN districts d ON t.district_code = d.district_code
        $baptizedClause
        ORDER BY COALESCE(t.registration_date, t.created_at) DESC
        LIMIT 50
    ");
    $stmt->execute($baptizedParams);
    $records = $stmt->fetchAll();
    
    foreach ($records as $record) {
        $lastName = Encryption::decrypt($record['last_name_encrypted'], $record['district_code']);
        $firstName = Encryption::decrypt($record['first_name_encrypted'], $record['district_code']);
        $middleName = $record['middle_name_encrypted'] ? Encryption::decrypt($record['middle_name_encrypted'], $record['district_code']) : '';
        $fullName = trim("$firstName $middleName $lastName");
        
        $newlyBaptized[] = [
            'id' => $record['id'],
            'name' => $fullName,
            'classification' => $record['cfo_classification'],
            'registration_date' => $record['registration_date'] ? date('M d, Y', strtotime($record['registration_date'])) : date('M d, Y', strtotime($record['created_at'])),
            'local' => $record['local_name'],
            'district' => $record['district_name']
        ];
    }
} catch (Exception $e) {
    error_log("Error loading newly baptized: " . $e->getMessage());
}

// Transfer-In Members
$transferIns = [];
try {
    $transferInWhere = array_filter($whereConditions, fn($c) => !str_contains($c, 'cfo_status'));
    $transferInWhere[] = "t.registration_type = 'transfer-in'";
    $transferInWhere[] = "t.cfo_status = 'active'";
    
    // Add date filter
    $transferInParams = $params;
    if ($transactionFilter === 'week') {
        $transferInWhere[] = "COALESCE(t.registration_date, t.created_at) >= ?";
        $transferInParams[] = $dateFilters['week'];
    } elseif ($transactionFilter === 'month') {
        $transferInWhere[] = "COALESCE(t.registration_date, t.created_at) >= ?";
        $transferInParams[] = $dateFilters['month'];
    } elseif ($transactionFilter === 'year') {
        $transferInWhere[] = "COALESCE(t.registration_date, t.created_at) >= ?";
        $transferInParams[] = $dateFilters['year'];
    }
    
    $transferInClause = 'WHERE ' . implode(' AND ', $transferInWhere);
    
    $stmt = $db->prepare("
        SELECT 
            t.id,
            t.first_name_encrypted,
            t.last_name_encrypted,
            t.middle_name_encrypted,
            t.district_code,
            t.cfo_classification,
            t.registration_date,
            t.created_at,
            lc.local_name,
            d.district_name
        FROM tarheta_control t
        LEFT JOIN local_congregations lc ON t.local_code = lc.local_code
        LEFT JOIN districts d ON t.district_code = d.district_code
        $transferInClause
        ORDER BY COALESCE(t.registration_date, t.created_at) DESC
        LIMIT 50
    ");
    $stmt->execute($transferInParams);
    $records = $stmt->fetchAll();
    
    foreach ($records as $record) {
        $lastName = Encryption::decrypt($record['last_name_encrypted'], $record['district_code']);
        $firstName = Encryption::decrypt($record['first_name_encrypted'], $record['district_code']);
        $middleName = $record['middle_name_encrypted'] ? Encryption::decrypt($record['middle_name_encrypted'], $record['district_code']) : '';
        $fullName = trim("$firstName $middleName $lastName");
        
        $transferIns[] = [
            'id' => $record['id'],
            'name' => $fullName,
            'classification' => $record['cfo_classification'],
            'registration_date' => $record['registration_date'] ? date('M d, Y', strtotime($record['registration_date'])) : date('M d, Y', strtotime($record['created_at'])),
            'local' => $record['local_name'],
            'district' => $record['district_name']
        ];
    }
} catch (Exception $e) {
    error_log("Error loading transfer-ins: " . $e->getMessage());
}

// Purok Statistics
$purokStats = [];
try {
    $stmt = $db->prepare("
        SELECT 
            t.purok,
            COUNT(*) as total,
            SUM(CASE WHEN t.cfo_status = 'active' THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN t.cfo_classification = 'Buklod' THEN 1 ELSE 0 END) as buklod,
            SUM(CASE WHEN t.cfo_classification = 'Kadiwa' THEN 1 ELSE 0 END) as kadiwa,
            SUM(CASE WHEN t.cfo_classification = 'Binhi' THEN 1 ELSE 0 END) as binhi
        FROM tarheta_control t
        $whereClause
          AND t.purok IS NOT NULL
          AND t.purok != ''
        GROUP BY t.purok
        ORDER BY t.purok
    ");
    $stmt->execute($params);
    $purokStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error loading purok stats: " . $e->getMessage());
}

$pageTitle = 'CFO Reports';
ob_start();
?>

<!-- Additional scripts for DataTables -->
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>

<div class="container mx-auto px-4 py-8 max-w-7xl">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900 flex items-center">
            <i class="fa-solid fa-chart-bar mr-3 text-blue-600 text-2xl"></i>
            CFO Reports
        </h1>
        <p class="text-sm text-gray-500 mt-1">Christian Family Organization Statistics and Analysis</p>
    </div>
    
    <!-- Tabs Navigation -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-6">
        <div class="border-b border-gray-200">
            <nav class="flex -mb-px" role="tablist">
                <button onclick="switchTab('overview')" id="tab-overview" class="tab-button active px-6 py-4 text-sm font-medium border-b-2 border-blue-600 text-blue-600">
                    <i class="fa-solid fa-chart-column mr-2"></i>
                    Overview
                </button>
                <button onclick="switchTab('transactions')" id="tab-transactions" class="tab-button px-6 py-4 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300">
                    <i class="fa-solid fa-arrow-right-arrow-left mr-2"></i>
                    Transactions
                </button>
                <button onclick="switchTab('lipat-kapisanan')" id="tab-lipat-kapisanan" class="tab-button px-6 py-4 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300">
                    <i class="fa-solid fa-people-arrows mr-2"></i>
                    Lipat-Kapisanan
                </button>
                <button onclick="switchTab('purok')" id="tab-purok" class="tab-button px-6 py-4 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300">
                    <i class="fa-solid fa-map-location-dot mr-2"></i>
                    By Purok
                </button>
            </nav>
        </div>
    </div>
    
    <!-- Tab Content: Overview -->
    <div id="content-overview" class="tab-content">
    <!-- Organization Count Statistics -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-6">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-900 flex items-center">
                <i class="fa-solid fa-chart-column mr-2 text-blue-600"></i>
                Organization Count by Classification
            </h2>
        </div>
        <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- Buklod -->
                <div class="bg-gradient-to-br from-red-50 to-red-100 rounded-lg p-6 border border-red-200">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 bg-red-200 rounded-full flex items-center justify-center">
                            <i class="fa-solid fa-rings-wedding text-2xl text-red-600"></i>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-medium text-red-700">Buklod</p>
                            <p class="text-xs text-red-600">Married Couples</p>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <div class="flex justify-between items-center">
                            <span class="text-sm text-red-700">Total:</span>
                            <span class="text-2xl font-bold text-red-900"><?php echo number_format($stats['Buklod']['total'] ?? 0); ?></span>
                        </div>
                        <div class="flex justify-between items-center text-xs">
                            <span class="text-red-600">Active:</span>
                            <span class="font-semibold text-red-800"><?php echo number_format($stats['Buklod']['active'] ?? 0); ?></span>
                        </div>
                        <div class="flex justify-between items-center text-xs">
                            <span class="text-red-600">Transferred:</span>
                            <span class="font-semibold text-red-700"><?php echo number_format($stats['Buklod']['transferred'] ?? 0); ?></span>
                        </div>
                        <div class="border-t border-red-200 mt-3 pt-3">
                            <div class="flex justify-between items-center text-xs mb-1">
                                <span class="text-red-600">This Week:</span>
                                <span class="font-semibold <?php echo $weeklyChanges['Buklod'] >= 0 ? 'text-green-600' : 'text-red-600'; ?>">
                                    <?php echo $weeklyChanges['Buklod'] >= 0 ? '+' : ''; ?><?php echo number_format($weeklyChanges['Buklod']); ?>
                                </span>
                            </div>
                            <div class="flex justify-between items-center text-xs">
                                <span class="text-red-600">This Month:</span>
                                <span class="font-semibold <?php echo $monthlyChanges['Buklod'] >= 0 ? 'text-green-600' : 'text-red-600'; ?>">
                                    <?php echo $monthlyChanges['Buklod'] >= 0 ? '+' : ''; ?><?php echo number_format($monthlyChanges['Buklod']); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Kadiwa -->
                <div class="bg-gradient-to-br from-blue-50 to-blue-100 rounded-lg p-6 border border-blue-200">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 bg-blue-200 rounded-full flex items-center justify-center">
                            <i class="fa-solid fa-user-group text-2xl text-blue-700"></i>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-medium text-blue-800">Kadiwa</p>
                            <p class="text-xs text-blue-700">Youth (18+)</p>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <div class="flex justify-between items-center">
                            <span class="text-sm text-blue-800">Total:</span>
                            <span class="text-2xl font-bold text-blue-900"><?php echo number_format($stats['Kadiwa']['total'] ?? 0); ?></span>
                        </div>
                        <div class="flex justify-between items-center text-xs">
                            <span class="text-blue-700">Active:</span>
                            <span class="font-semibold text-blue-800"><?php echo number_format($stats['Kadiwa']['active'] ?? 0); ?></span>
                        </div>
                        <div class="flex justify-between items-center text-xs">
                            <span class="text-blue-700">Transferred:</span>
                            <span class="font-semibold text-blue-800"><?php echo number_format($stats['Kadiwa']['transferred'] ?? 0); ?></span>
                        </div>
                        <div class="border-t border-blue-200 mt-3 pt-3">
                            <div class="flex justify-between items-center text-xs mb-1">
                                <span class="text-blue-700">This Week:</span>
                                <span class="font-semibold <?php echo $weeklyChanges['Kadiwa'] >= 0 ? 'text-green-600' : 'text-red-600'; ?>">
                                    <?php echo $weeklyChanges['Kadiwa'] >= 0 ? '+' : ''; ?><?php echo number_format($weeklyChanges['Kadiwa']); ?>
                                </span>
                            </div>
                            <div class="flex justify-between items-center text-xs">
                                <span class="text-blue-700">This Month:</span>
                                <span class="font-semibold <?php echo $monthlyChanges['Kadiwa'] >= 0 ? 'text-green-600' : 'text-red-600'; ?>">
                                    <?php echo $monthlyChanges['Kadiwa'] >= 0 ? '+' : ''; ?><?php echo number_format($monthlyChanges['Kadiwa']); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Binhi -->
                <div class="bg-gradient-to-br from-green-50 to-green-100 rounded-lg p-6 border border-green-200">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 bg-green-200 rounded-full flex items-center justify-center">
                            <i class="fa-solid fa-seedling text-2xl text-green-600"></i>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-medium text-green-700">Binhi</p>
                            <p class="text-xs text-green-600">Children (<18)</p>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <div class="flex justify-between items-center">
                            <span class="text-sm text-green-700">Total:</span>
                            <span class="text-2xl font-bold text-green-900"><?php echo number_format($stats['Binhi']['total'] ?? 0); ?></span>
                        </div>
                        <div class="flex justify-between items-center text-xs">
                            <span class="text-green-600">Active:</span>
                            <span class="font-semibold text-green-800"><?php echo number_format($stats['Binhi']['active'] ?? 0); ?></span>
                        </div>
                        <div class="flex justify-between items-center text-xs">
                            <span class="text-green-600">Transferred:</span>
                            <span class="font-semibold text-green-700"><?php echo number_format($stats['Binhi']['transferred'] ?? 0); ?></span>
                        </div>
                        <div class="border-t border-green-200 mt-3 pt-3">
                            <div class="flex justify-between items-center text-xs mb-1">
                                <span class="text-green-600">This Week:</span>
                                <span class="font-semibold <?php echo $weeklyChanges['Binhi'] >= 0 ? 'text-green-600' : 'text-red-600'; ?>">
                                    <?php echo $weeklyChanges['Binhi'] >= 0 ? '+' : ''; ?><?php echo number_format($weeklyChanges['Binhi']); ?>
                                </span>
                            </div>
                            <div class="flex justify-between items-center text-xs">
                                <span class="text-green-600">This Month:</span>
                                <span class="font-semibold <?php echo $monthlyChanges['Binhi'] >= 0 ? 'text-green-600' : 'text-red-600'; ?>">
                                    <?php echo $monthlyChanges['Binhi'] >= 0 ? '+' : ''; ?><?php echo number_format($monthlyChanges['Binhi']); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <?php if ($currentUser['role'] === 'local'): ?>
    <!-- Reset Statistics Section -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-6">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-900 flex items-center">
                <i class="fa-solid fa-rotate-right mr-2 text-gray-600"></i>
                Reset Statistics Baseline
            </h2>
            <p class="text-sm text-gray-600 mt-1">Reset the baseline for dagdag/bawas calculations without deleting any data</p>
        </div>
        <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <!-- Buklod Reset -->
                <div class="bg-gradient-to-br from-red-50 to-red-100 rounded-lg p-4 border border-red-200">
                    <h3 class="text-sm font-semibold text-red-800 mb-3">Buklod</h3>
                    <div class="space-y-2">
                        <button onclick="resetCfoStats('Buklod', 'week')" class="w-full bg-red-600 hover:bg-red-700 text-white text-xs font-medium py-2 px-3 rounded transition">
                            <i class="fa-solid fa-calendar-week mr-1"></i> Reset Week
                        </button>
                        <button onclick="resetCfoStats('Buklod', 'month')" class="w-full bg-red-600 hover:bg-red-700 text-white text-xs font-medium py-2 px-3 rounded transition">
                            <i class="fa-solid fa-calendar-days mr-1"></i> Reset Month
                        </button>
                        <button onclick="resetCfoStats('Buklod', 'both')" class="w-full bg-red-700 hover:bg-red-800 text-white text-xs font-medium py-2 px-3 rounded transition">
                            <i class="fa-solid fa-calendar mr-1"></i> Reset Both
                        </button>
                    </div>
                </div>
                
                <!-- Kadiwa Reset -->
                <div class="bg-gradient-to-br from-blue-50 to-blue-100 rounded-lg p-4 border border-blue-200">
                    <h3 class="text-sm font-semibold text-blue-800 mb-3">Kadiwa</h3>
                    <div class="space-y-2">
                        <button onclick="resetCfoStats('Kadiwa', 'week')" class="w-full bg-blue-600 hover:bg-blue-700 text-white text-xs font-medium py-2 px-3 rounded transition">
                            <i class="fa-solid fa-calendar-week mr-1"></i> Reset Week
                        </button>
                        <button onclick="resetCfoStats('Kadiwa', 'month')" class="w-full bg-blue-600 hover:bg-blue-700 text-white text-xs font-medium py-2 px-3 rounded transition">
                            <i class="fa-solid fa-calendar-days mr-1"></i> Reset Month
                        </button>
                        <button onclick="resetCfoStats('Kadiwa', 'both')" class="w-full bg-blue-700 hover:bg-blue-800 text-white text-xs font-medium py-2 px-3 rounded transition">
                            <i class="fa-solid fa-calendar mr-1"></i> Reset Both
                        </button>
                    </div>
                </div>
                
                <!-- Binhi Reset -->
                <div class="bg-gradient-to-br from-green-50 to-green-100 rounded-lg p-4 border border-green-200">
                    <h3 class="text-sm font-semibold text-green-800 mb-3">Binhi</h3>
                    <div class="space-y-2">
                        <button onclick="resetCfoStats('Binhi', 'week')" class="w-full bg-green-600 hover:bg-green-700 text-white text-xs font-medium py-2 px-3 rounded transition">
                            <i class="fa-solid fa-calendar-week mr-1"></i> Reset Week
                        </button>
                        <button onclick="resetCfoStats('Binhi', 'month')" class="w-full bg-green-600 hover:bg-green-700 text-white text-xs font-medium py-2 px-3 rounded transition">
                            <i class="fa-solid fa-calendar-days mr-1"></i> Reset Month
                        </button>
                        <button onclick="resetCfoStats('Binhi', 'both')" class="w-full bg-green-700 hover:bg-green-800 text-white text-xs font-medium py-2 px-3 rounded transition">
                            <i class="fa-solid fa-calendar mr-1"></i> Reset Both
                        </button>
                    </div>
                </div>
                
                <!-- Reset All -->
                <div class="bg-gradient-to-br from-gray-50 to-gray-100 rounded-lg p-4 border border-gray-300">
                    <h3 class="text-sm font-semibold text-gray-800 mb-3">All Classifications</h3>
                    <div class="space-y-2">
                        <button onclick="resetCfoStats('all', 'week')" class="w-full bg-gray-600 hover:bg-gray-700 text-white text-xs font-medium py-2 px-3 rounded transition">
                            <i class="fa-solid fa-calendar-week mr-1"></i> Reset Week
                        </button>
                        <button onclick="resetCfoStats('all', 'month')" class="w-full bg-gray-600 hover:bg-gray-700 text-white text-xs font-medium py-2 px-3 rounded transition">
                            <i class="fa-solid fa-calendar-days mr-1"></i> Reset Month
                        </button>
                        <button onclick="resetCfoStats('all', 'both')" class="w-full bg-gray-700 hover:bg-gray-800 text-white text-xs font-medium py-2 px-3 rounded transition">
                            <i class="fa-solid fa-calendar mr-1"></i> Reset Both
                        </button>
                    </div>
                </div>
            </div>
            <div class="mt-4 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                <div class="flex items-start">
                    <i class="fa-solid fa-circle-info text-blue-600 mt-0.5 mr-3"></i>
                    <div class="text-sm text-blue-800">
                        <p class="font-medium mb-1">How Reset Works:</p>
                        <ul class="list-disc list-inside space-y-1 text-xs">
                            <li>Resetting sets the current time as the new baseline for calculating dagdag/bawas</li>
                            <li>No data is deleted - only the reporting display is affected</li>
                            <li>After reset, statistics will show changes from the reset time forward</li>
                            <li>Reset history is tracked in the database for consistency across sessions</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Officers Count by CFO Classification -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-6">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-900 flex items-center">
                <i class="fa-solid fa-user-tie mr-2 text-indigo-600"></i>
                Church Officers by CFO Classification
            </h2>
            <p class="text-sm text-gray-600 mt-1">Active officers who are also CFO members</p>
        </div>
        <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- Buklod Officers -->
                <div class="bg-gradient-to-br from-red-50 to-red-100 rounded-lg p-6 border border-red-200">
                    <div class="flex items-center justify-between">
                        <div class="w-12 h-12 bg-red-200 rounded-full flex items-center justify-center">
                            <i class="fa-solid fa-user-tie text-2xl text-red-600"></i>
                        </div>
                        <div class="text-right">
                            <p class="text-xs font-medium text-red-700 mb-1">Buklod Officers</p>
                            <p class="text-3xl font-bold text-red-900"><?php echo number_format($officerStats['Buklod']); ?></p>
                        </div>
                    </div>
                </div>
                
                <!-- Kadiwa Officers -->
                <div class="bg-gradient-to-br from-blue-50 to-blue-100 rounded-lg p-6 border border-blue-200">
                    <div class="flex items-center justify-between">
                        <div class="w-12 h-12 bg-blue-200 rounded-full flex items-center justify-center">
                            <i class="fa-solid fa-user-tie text-2xl text-blue-700"></i>
                        </div>
                        <div class="text-right">
                            <p class="text-xs font-medium text-blue-800 mb-1">Kadiwa Officers</p>
                            <p class="text-3xl font-bold text-blue-900"><?php echo number_format($officerStats['Kadiwa']); ?></p>
                        </div>
                    </div>
                </div>
                
                <!-- Binhi Officers -->
                <div class="bg-gradient-to-br from-green-50 to-green-100 rounded-lg p-6 border border-green-200">
                    <div class="flex items-center justify-between">
                        <div class="w-12 h-12 bg-green-200 rounded-full flex items-center justify-center">
                            <i class="fa-solid fa-user-tie text-2xl text-green-600"></i>
                        </div>
                        <div class="text-right">
                            <p class="text-xs font-medium text-green-700 mb-1">Binhi Officers</p>
                            <p class="text-3xl font-bold text-green-900"><?php echo number_format($officerStats['Binhi']); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </div><!-- End Overview Tab -->
    
    <!-- Tab Content: Transactions -->
    <div id="content-transactions" class="tab-content hidden">
    
    <!-- Transaction Filters -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-6">
        <div class="p-4">
            <div class="flex items-center justify-between flex-wrap gap-3">
                <div class="flex items-center gap-2">
                    <i class="fa-solid fa-filter text-gray-600"></i>
                    <span class="text-sm font-medium text-gray-700">Time Period:</span>
                </div>
                <div class="flex gap-2">
                    <button onclick="filterTransactions('all')" 
                            class="transaction-filter-btn <?php echo $transactionFilter === 'all' ? 'active' : ''; ?> px-4 py-2 text-sm font-medium rounded-lg transition-colors"
                            data-filter="all">
                        <i class="fa-solid fa-infinity mr-2"></i>All Time
                    </button>
                    <button onclick="filterTransactions('week')" 
                            class="transaction-filter-btn <?php echo $transactionFilter === 'week' ? 'active' : ''; ?> px-4 py-2 text-sm font-medium rounded-lg transition-colors"
                            data-filter="week">
                        <i class="fa-solid fa-calendar-week mr-2"></i>This Week
                    </button>
                    <button onclick="filterTransactions('month')" 
                            class="transaction-filter-btn <?php echo $transactionFilter === 'month' ? 'active' : ''; ?> px-4 py-2 text-sm font-medium rounded-lg transition-colors"
                            data-filter="month">
                        <i class="fa-solid fa-calendar-days mr-2"></i>This Month
                    </button>
                    <button onclick="filterTransactions('year')" 
                            class="transaction-filter-btn <?php echo $transactionFilter === 'year' ? 'active' : ''; ?> px-4 py-2 text-sm font-medium rounded-lg transition-colors"
                            data-filter="year">
                        <i class="fa-solid fa-calendar mr-2"></i>This Year
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Newly Baptized Members -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-6">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-900 flex items-center">
                <i class="fa-solid fa-water mr-2 text-blue-600"></i>
                Recently Baptized Members
            </h2>
        </div>
        <div class="p-6">
            <?php if (count($newlyBaptized) > 0): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <?php foreach ($newlyBaptized as $member): ?>
                        <div class="flex items-center justify-between p-3 bg-blue-50 rounded-lg border border-blue-100">
                            <div class="flex items-center flex-1">
                                <div class="w-10 h-10 bg-blue-200 rounded-full flex items-center justify-center mr-3">
                                    <i class="fa-solid fa-user-plus text-blue-600"></i>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="font-medium text-gray-900 truncate"><?php echo Security::escape($member['name']); ?></p>
                                    <p class="text-xs text-gray-600"><?php echo Security::escape($member['local']); ?> • <?php echo Security::escape($member['classification'] ?: 'Unclassified'); ?></p>
                                </div>
                            </div>
                            <div class="text-right ml-3">
                                <p class="text-xs font-medium text-blue-600"><?php echo $member['registration_date']; ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-center text-gray-500 py-8">No newly baptized members found.</p>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Transfer-In Members -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-6">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-900 flex items-center">
                <i class="fa-solid fa-arrow-right-to-bracket mr-2 text-green-600"></i>
                Transfer-In Members
            </h2>
        </div>
        <div class="p-6">
            <?php if (count($transferIns) > 0): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <?php foreach ($transferIns as $member): ?>
                        <div class="flex items-center justify-between p-3 bg-green-50 rounded-lg border border-green-100">
                            <div class="flex items-center flex-1">
                                <div class="w-10 h-10 bg-green-200 rounded-full flex items-center justify-center mr-3">
                                    <i class="fa-solid fa-arrow-right text-green-600"></i>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="font-medium text-gray-900 truncate"><?php echo Security::escape($member['name']); ?></p>
                                    <p class="text-xs text-gray-600"><?php echo Security::escape($member['local']); ?> • <?php echo Security::escape($member['classification'] ?: 'Unclassified'); ?></p>
                                </div>
                            </div>
                            <div class="text-right ml-3">
                                <p class="text-xs font-medium text-green-600"><?php echo $member['registration_date']; ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-center text-gray-500 py-8">No transfer-in members found.</p>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Recent Transfer Outs -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-6">
        <div class="px-6 py-4 border-b border-gray-200">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold text-gray-900 flex items-center">
                    <i class="fa-solid fa-right-from-bracket mr-2 text-red-600"></i>
                    Recent Transfer Outs
                </h2>
                <?php if ($currentUser['role'] === 'local' && count($recentTransferOuts) > 0): ?>
                <button onclick="clearTransferOutHistory()" class="px-3 py-1.5 text-xs font-medium text-red-600 bg-red-50 border border-red-200 rounded-lg hover:bg-red-100 transition-colors">
                    <i class="fa-solid fa-trash-can mr-1"></i>
                    Clear History
                </button>
                <?php endif; ?>
            </div>
        </div>
        <div class="p-6">
            <?php if (count($recentTransferOuts) > 0): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <?php foreach ($recentTransferOuts as $member): 
                        $firstName = Encryption::decrypt($member['first_name_encrypted'], $member['district_code']);
                        $lastName = Encryption::decrypt($member['last_name_encrypted'], $member['district_code']);
                        $fullName = trim($firstName . ' ' . $lastName);
                        $displayName = obfuscateName($lastName . ', ' . $firstName); // Obfuscated for privacy
                    ?>
                        <div class="flex items-center justify-between p-3 bg-red-50 rounded-lg border border-red-100 hover:bg-red-100 transition-colors">
                            <div class="flex items-center flex-1 min-w-0">
                                <div class="w-10 h-10 bg-red-200 rounded-full flex items-center justify-center text-red-700 font-bold flex-shrink-0">
                                    <?php echo strtoupper(substr($fullName, 0, 1)); ?>
                                </div>
                                <div class="ml-3 flex-1 min-w-0">
                                    <p class="font-semibold text-gray-900 truncate cursor-help" title="<?php echo Security::escape($fullName); ?>"><?php echo Security::escape($displayName); ?></p>
                                    <p class="text-sm text-gray-600 truncate"><?php echo Security::escape($member['cfo_classification'] ?? 'Unclassified'); ?> • <?php echo Security::escape($member['local_name'] ?? 'Unknown Local'); ?></p>
                                </div>
                            </div>
                            <div class="text-right ml-3 flex-shrink-0">
                                <p class="text-xs text-gray-500"><?php echo date('M d, Y', strtotime($member['updated_at'])); ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-8 text-gray-500">
                    <svg class="w-16 h-16 mx-auto text-gray-300 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <p class="text-gray-500">No recent transfers out</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    </div><!-- End Transactions Tab -->
    
    <!-- Tab Content: Lipat-Kapisanan -->
    <div id="content-lipat-kapisanan" class="tab-content hidden">

    <!-- Classification Changes (Lipat Kapisanan) -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-6">
        <div class="px-6 py-4 border-b border-gray-200">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold text-gray-900 flex items-center">
                    <i class="fa-solid fa-arrow-right-arrow-left mr-2 text-red-600"></i>
                    Classification Changes (Lipat Kapisanan)
                </h2>
                <?php if ($currentUser['role'] === 'local' && count($classificationChanges) > 0): ?>
                <button onclick="clearClassificationHistory()" class="px-3 py-1.5 text-xs font-medium text-red-600 bg-red-50 border border-red-200 rounded-lg hover:bg-red-100 transition-colors">
                    <i class="fa-solid fa-trash-can mr-1"></i>
                    Clear History
                </button>
                <?php endif; ?>
            </div>
        </div>
        <div class="p-6">
            <?php if (count($classificationChanges) > 0): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <?php foreach ($classificationChanges as $member): 
                        $firstName = Encryption::decrypt($member['first_name_encrypted'], $member['district_code']);
                        $lastName = Encryption::decrypt($member['last_name_encrypted'], $member['district_code']);
                        $fullName = trim($firstName . ' ' . $lastName);
                        $displayName = obfuscateName($lastName . ', ' . $firstName); // Obfuscated for privacy
                        $from = $member['cfo_classification_auto'] ?? 'Unknown';
                        $to = $member['cfo_classification'] ?? 'Unclassified';
                    ?>
                        <div class="flex items-center justify-between p-3 bg-red-50 rounded-lg border border-red-100 hover:bg-red-100 transition-colors">
                            <div class="flex items-center flex-1 min-w-0">
                                <div class="w-10 h-10 bg-red-200 rounded-full flex items-center justify-center text-red-700 font-bold flex-shrink-0">
                                    <?php echo strtoupper(substr($fullName, 0, 1)); ?>
                                </div>
                                <div class="ml-3 flex-1 min-w-0">
                                    <p class="font-semibold text-gray-900 truncate cursor-help" title="<?php echo Security::escape($fullName); ?>"><?php echo Security::escape($displayName); ?></p>
                                    <div class="flex items-center gap-2 text-sm">
                                        <span class="text-gray-600"><?php echo Security::escape($from); ?></span>
                                        <i class="fa-solid fa-arrow-right text-red-500"></i>
                                        <span class="font-medium text-red-700"><?php echo Security::escape($to); ?></span>
                                        <span class="text-xs text-gray-500">• <?php echo Security::escape($member['local_name'] ?? 'Unknown Local'); ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="text-right ml-3 flex-shrink-0">
                                <p class="text-xs text-gray-500"><?php echo date('M d, Y', strtotime($member['updated_at'])); ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-8 text-gray-500">
                    <i class="fa-solid fa-circle-check text-6xl text-gray-300 mb-3"></i>
                    <p class="text-gray-500">No classification changes</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    </div>
    <!-- End Lipat-Kapisanan Tab -->

    <!-- Purok Tab -->
    <div id="content-purok" class="tab-content hidden">
    <!-- Purok Statistics -->
    <?php if (!empty($purokStats)): ?>
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-6">
        <div class="px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-indigo-50 to-purple-50">
            <h2 class="text-lg font-semibold text-gray-900 flex items-center">
                <i class="fa-solid fa-map-location-dot mr-2 text-indigo-600"></i>
                CFO Members by Purok
            </h2>
            <p class="text-sm text-gray-600 mt-1">Distribution of members across puroks</p>
        </div>
        <div class="p-6">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-indigo-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-indigo-900 uppercase tracking-wider">Purok</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-indigo-900 uppercase tracking-wider">CFO Organization</th>
                            <th class="px-6 py-3 text-right text-xs font-semibold text-indigo-900 uppercase tracking-wider">Count</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($purokStats as $stat): ?>
                            <!-- Buklod Row -->
                            <tr class="hover:bg-red-50 transition-colors">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-indigo-900" rowspan="4">
                                    <i class="fa-solid fa-map-pin mr-2 text-indigo-600"></i>
                                    Purok <?php echo Security::escape($stat['purok']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <span class="inline-flex items-center">
                                        <i class="fa-solid fa-rings-wedding mr-2 text-red-600"></i>
                                        <span class="font-medium text-red-700">Buklod</span>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-semibold text-red-900">
                                    <?php echo number_format($stat['buklod']); ?>
                                </td>
                            </tr>
                            <!-- Kadiwa Row -->
                            <tr class="hover:bg-blue-50 transition-colors">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <span class="inline-flex items-center">
                                        <i class="fa-solid fa-user-group mr-2 text-blue-700"></i>
                                        <span class="font-medium text-blue-800">Kadiwa</span>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-semibold text-blue-900">
                                    <?php echo number_format($stat['kadiwa']); ?>
                                </td>
                            </tr>
                            <!-- Binhi Row -->
                            <tr class="hover:bg-green-50 transition-colors">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <span class="inline-flex items-center">
                                        <i class="fa-solid fa-seedling mr-2 text-green-600"></i>
                                        <span class="font-medium text-green-700">Binhi</span>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-semibold text-green-900">
                                    <?php echo number_format($stat['binhi']); ?>
                                </td>
                            </tr>
                            <!-- Total Row -->
                            <tr class="bg-gray-50 hover:bg-gray-100 transition-colors">
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <i class="fa-solid fa-calculator mr-2 text-gray-600"></i>
                                    <span class="font-bold text-gray-900">Total</span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-bold text-gray-900">
                                    <?php echo number_format($stat['total']); ?>
                                </td>
                            </tr>
                            <!-- Separator Row -->
                            <tr class="bg-gray-100">
                                <td colspan="3" class="px-6 py-1"></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
    </div>
    <!-- End Purok Tab -->

    <!-- Back to Lipat-Kapisanan Tab for Turning 18 Section -->
    <div id="content-lipat-kapisanan-continued" class="tab-content hidden">
    <!-- Lipat-Kapisanan: Turning 18 Soon -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200">
        <div class="px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-blue-50 to-indigo-50">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900 flex items-center">
                        <svg class="w-5 h-5 mr-2 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                        </svg>
                        Lipat-Kapisanan: Binhi → Kadiwa
                    </h2>
                    <p class="text-sm text-gray-600 mt-1">Members turning 18 within the next 3 months</p>
                </div>
                <div class="flex items-center space-x-2">
                    <span class="px-4 py-2 bg-indigo-100 text-indigo-800 rounded-full text-sm font-semibold">
                        <?php echo count($turningEighteen); ?> member<?php echo count($turningEighteen) !== 1 ? 's' : ''; ?>
                    </span>
                </div>
            </div>
        </div>
        <div class="p-6">
            <?php if (empty($turningEighteen)): ?>
                <div class="text-center py-12">
                    <svg class="w-16 h-16 mx-auto text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <p class="text-gray-500 text-lg font-medium">No members turning 18 soon</p>
                    <p class="text-gray-400 text-sm mt-2">All Binhi members are under 17 years old or have no birthday data</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200" id="turning18Table">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Current Age</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Birthday</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Turning 18 On</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Days Until</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Local</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Action</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($turningEighteen as $member): 
                                $displayName = obfuscateName($member['name']); // Obfuscated for privacy
                            ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900 cursor-help" title="<?php echo Security::escape($member['name']); ?>"><?php echo Security::escape($displayName); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="text-sm text-gray-900"><?php echo $member['age']; ?> years old</span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="text-sm text-gray-700"><?php echo $member['birthday']; ?></span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="text-sm font-semibold text-indigo-600"><?php echo $member['turning_18_date']; ?></span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php 
                                        $days = $member['days_until_18'];
                                        $badgeColor = $days <= 7 ? 'bg-red-100 text-red-800' : ($days <= 30 ? 'bg-orange-100 text-orange-800' : 'bg-blue-100 text-blue-800');
                                        ?>
                                        <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $badgeColor; ?>">
                                            <?php echo $days; ?> day<?php echo $days !== 1 ? 's' : ''; ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-700"><?php echo Security::escape($member['local']); ?></div>
                                        <div class="text-xs text-gray-500"><?php echo Security::escape($member['district']); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <a href="<?php echo BASE_URL; ?>/cfo-registry.php" class="text-indigo-600 hover:text-indigo-900">
                                            View in Registry
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    </div>
    <!-- End Lipat-Kapisanan Tab -->
</div>
<!-- End Main Container -->

<script>
$(document).ready(function() {
    <?php if (!empty($turningEighteen)): ?>
    $('#turning18Table').DataTable({
        pageLength: 25,
        order: [[4, 'asc']], // Sort by days until
        dom: 'Bfrtip',
        buttons: [
            {
                extend: 'excel',
                text: '<svg class="w-4 h-4 mr-2 inline" fill="currentColor" viewBox="0 0 20 20"><path d="M9 2a2 2 0 00-2 2v8a2 2 0 002 2h6a2 2 0 002-2V6.414A2 2 0 0016.414 5L14 2.586A2 2 0 0012.586 2H9z"></path><path d="M3 8a2 2 0 012-2v10h8a2 2 0 01-2 2H5a2 2 0 01-2-2V8z"></path></svg> Export to Excel',
                className: 'bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-medium transition-colors',
                title: 'CFO Lipat-Kapisanan Report - Turning 18',
                exportOptions: {
                    columns: [0, 1, 2, 3, 4, 5]
                }
            },
            {
                extend: 'print',
                text: '<svg class="w-4 h-4 mr-2 inline" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5 4v3H4a2 2 0 00-2 2v3a2 2 0 002 2h1v2a2 2 0 002 2h6a2 2 0 002-2v-2h1a2 2 0 002-2V9a2 2 0 00-2-2h-1V4a2 2 0 00-2-2H7a2 2 0 00-2 2zm8 0H7v3h6V4zm0 8H7v4h6v-4z" clip-rule="evenodd"></path></svg> Print',
                className: 'bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg font-medium transition-colors',
                title: 'CFO Lipat-Kapisanan Report',
                exportOptions: {
                    columns: [0, 1, 2, 3, 4, 5]
                }
            }
        ]
    });
    <?php endif; ?>
});

function clearTransferOutHistory() {
    if (!confirm('Are you sure you want to permanently delete all transferred-out members from your local? This action cannot be undone.')) {
        return;
    }
    
    fetch('<?php echo BASE_URL; ?>/api/clear-transfer-history.php', {
        method: 'POST',
        headers: { 
            'Content-Type': 'application/json',
            'X-CSRF-Token': '<?php echo Security::generateCSRFToken(); ?>'
        },
        body: JSON.stringify({ action: 'transfer_out' })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(`Successfully deleted ${data.deleted_count} transferred-out member(s).`);
            location.reload();
        } else {
            alert('Error: ' + (data.error || 'Failed to clear history'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Network error. Please try again.');
    });
}

function clearClassificationHistory() {
    if (!confirm('Are you sure you want to clear the classification changes history? This will hide the current list without deleting any data from the database.')) {
        return;
    }
    
    fetch('<?php echo BASE_URL; ?>/api/clear-transfer-history.php', {
        method: 'POST',
        headers: { 
            'Content-Type': 'application/json',
            'X-CSRF-Token': '<?php echo Security::generateCSRFToken(); ?>'
        },
        body: JSON.stringify({ action: 'classification_changes' })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(`Successfully cleared history for ${data.cleared_count} classification change(s).`);
            location.reload();
        } else {
            alert('Error: ' + (data.error || 'Failed to clear history'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Network error. Please try again.');
    });
}

// Tab switching functionality
function switchTab(tabName) {
    // Hide all tab contents
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.add('hidden');
    });
    
    // Remove active class from all tab buttons
    document.querySelectorAll('.tab-button').forEach(button => {
        button.classList.remove('active', 'border-blue-600', 'text-blue-600');
        button.classList.add('border-transparent', 'text-gray-500');
    });
    
    // Show selected tab content
    const selectedContent = document.getElementById('content-' + tabName);
    if (selectedContent) {
        selectedContent.classList.remove('hidden');
    }
    
    // Special handling for Lipat-Kapisanan tab (shows both sections)
    if (tabName === 'lipat-kapisanan') {
        const continuedContent = document.getElementById('content-lipat-kapisanan-continued');
        if (continuedContent) {
            continuedContent.classList.remove('hidden');
        }
    }
    
    // Add active class to selected tab button
    const selectedButton = document.getElementById('tab-' + tabName);
    if (selectedButton) {
        selectedButton.classList.add('active', 'border-blue-600', 'text-blue-600');
        selectedButton.classList.remove('border-transparent', 'text-gray-500');
    }
}

// Transaction filter functionality
function filterTransactions(filter) {
    const url = new URL(window.location.href);
    url.searchParams.set('filter', filter);
    window.location.href = url.toString();
}

// Initialize tabs on page load
document.addEventListener('DOMContentLoaded', function() {
    // Show overview tab by default
    switchTab('overview');
});

// Reset CFO statistics baseline
function resetCfoStats(classification, period) {
    const classificationLabel = classification === 'all' ? 'all classifications' : classification;
    const periodLabel = period === 'both' ? 'week and month' : (period === 'week' ? 'weekly' : 'monthly');
    
    if (!confirm(`Reset ${periodLabel} statistics for ${classificationLabel}?\n\nThis will set the current time as the new baseline for calculating dagdag/bawas. No data will be deleted.`)) {
        return;
    }
    
    fetch('<?php echo BASE_URL; ?>/api/reset-cfo-stats.php', {
        method: 'POST',
        headers: { 
            'Content-Type': 'application/json',
            'X-CSRF-Token': '<?php echo Security::generateCSRFToken(); ?>'
        },
        body: JSON.stringify({ 
            classification: classification,
            period: period
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(`Successfully reset ${periodLabel} statistics for ${classificationLabel}.\n\nStatistics will now be calculated from ${data.reset_at}.`);
            location.reload();
        } else {
            alert('Error: ' + (data.error || 'Failed to reset statistics'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Network error. Please try again.');
    });
}
</script>

<style>
.tab-content {
    animation: fadeIn 0.3s ease-in;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.tab-button.active {
    transition: all 0.2s ease;
}

.transaction-filter-btn {
    border: 2px solid #e5e7eb;
    background-color: white;
    color: #6b7280;
}

.transaction-filter-btn:hover {
    border-color: #3b82f6;
    color: #3b82f6;
    background-color: #eff6ff;
}

.transaction-filter-btn.active {
    border-color: #3b82f6;
    background-color: #3b82f6;
    color: white;
}
</style>

<?php
$content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
