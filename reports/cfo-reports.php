<?php
require_once __DIR__ . '/../config/config.php';

Security::requireLogin();
requirePermission('can_view_reports');

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

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
    $transferOutClause = 'WHERE ' . implode(' AND ', $transferOutWhere);
    
    $stmt = $db->prepare("
        SELECT 
            t.id,
            t.first_name_encrypted,
            t.last_name_encrypted,
            t.district_code,
            t.cfo_classification,
            t.updated_at,
            lc.local_name
        FROM tarheta_control t
        LEFT JOIN local_congregations lc ON t.local_code = lc.local_code
        $transferOutClause
        ORDER BY t.updated_at DESC
        LIMIT 10
    ");
    $stmt->execute($params);
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
        AND (t.classification_history_cleared_at IS NULL OR t.classification_history_cleared_at < t.cfo_updated_at)
        ORDER BY t.updated_at DESC
        LIMIT 10
    ");
    $stmt->execute($params);
    $classificationChanges = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Error loading classification changes: " . $e->getMessage());
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
                <div class="bg-gradient-to-br from-purple-50 to-purple-100 rounded-lg p-6 border border-purple-200">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 bg-purple-200 rounded-full flex items-center justify-center">
                            <i class="fa-solid fa-rings-wedding text-2xl text-purple-600"></i>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-medium text-purple-700">Buklod</p>
                            <p class="text-xs text-purple-600">Married Couples</p>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <div class="flex justify-between items-center">
                            <span class="text-sm text-purple-700">Total:</span>
                            <span class="text-2xl font-bold text-purple-900"><?php echo number_format($stats['Buklod']['total'] ?? 0); ?></span>
                        </div>
                        <div class="flex justify-between items-center text-xs">
                            <span class="text-purple-600">Active:</span>
                            <span class="font-semibold text-purple-800"><?php echo number_format($stats['Buklod']['active'] ?? 0); ?></span>
                        </div>
                        <div class="flex justify-between items-center text-xs">
                            <span class="text-purple-600">Transferred:</span>
                            <span class="font-semibold text-purple-700"><?php echo number_format($stats['Buklod']['transferred'] ?? 0); ?></span>
                        </div>
                    </div>
                </div>
                
                <!-- Kadiwa -->
                <div class="bg-gradient-to-br from-green-50 to-green-100 rounded-lg p-6 border border-green-200">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 bg-green-200 rounded-full flex items-center justify-center">
                            <i class="fa-solid fa-user-group text-2xl text-green-600"></i>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-medium text-green-700">Kadiwa</p>
                            <p class="text-xs text-green-600">Youth (18+)</p>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <div class="flex justify-between items-center">
                            <span class="text-sm text-green-700">Total:</span>
                            <span class="text-2xl font-bold text-green-900"><?php echo number_format($stats['Kadiwa']['total'] ?? 0); ?></span>
                        </div>
                        <div class="flex justify-between items-center text-xs">
                            <span class="text-green-600">Active:</span>
                            <span class="font-semibold text-green-800"><?php echo number_format($stats['Kadiwa']['active'] ?? 0); ?></span>
                        </div>
                        <div class="flex justify-between items-center text-xs">
                            <span class="text-green-600">Transferred:</span>
                            <span class="font-semibold text-green-700"><?php echo number_format($stats['Kadiwa']['transferred'] ?? 0); ?></span>
                        </div>
                    </div>
                </div>
                
                <!-- Binhi -->
                <div class="bg-gradient-to-br from-orange-50 to-orange-100 rounded-lg p-6 border border-orange-200">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 bg-orange-200 rounded-full flex items-center justify-center">
                            <i class="fa-solid fa-seedling text-2xl text-orange-600"></i>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-medium text-orange-700">Binhi</p>
                            <p class="text-xs text-orange-600">Children (<18)</p>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <div class="flex justify-between items-center">
                            <span class="text-sm text-orange-700">Total:</span>
                            <span class="text-2xl font-bold text-orange-900"><?php echo number_format($stats['Binhi']['total'] ?? 0); ?></span>
                        </div>
                        <div class="flex justify-between items-center text-xs">
                            <span class="text-orange-600">Active:</span>
                            <span class="font-semibold text-orange-800"><?php echo number_format($stats['Binhi']['active'] ?? 0); ?></span>
                        </div>
                        <div class="flex justify-between items-center text-xs">
                            <span class="text-orange-600">Transferred:</span>
                            <span class="font-semibold text-orange-700"><?php echo number_format($stats['Binhi']['transferred'] ?? 0); ?></span>
                        </div>
                    </div>
                </div>
            </div>
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
                    ?>
                        <div class="flex items-center justify-between p-3 bg-red-50 rounded-lg border border-red-100 hover:bg-red-100 transition-colors">
                            <div class="flex items-center flex-1 min-w-0">
                                <div class="w-10 h-10 bg-red-200 rounded-full flex items-center justify-center text-red-700 font-bold flex-shrink-0">
                                    <?php echo strtoupper(substr($fullName, 0, 1)); ?>
                                </div>
                                <div class="ml-3 flex-1 min-w-0">
                                    <p class="font-semibold text-gray-900 truncate"><?php echo Security::escape($fullName); ?></p>
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

    <!-- Classification Changes (Lipat Kapisanan) -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-6">
        <div class="px-6 py-4 border-b border-gray-200">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold text-gray-900 flex items-center">
                    <i class="fa-solid fa-arrow-right-arrow-left mr-2 text-purple-600"></i>
                    Classification Changes (Lipat Kapisanan)
                </h2>
                <?php if ($currentUser['role'] === 'local' && count($classificationChanges) > 0): ?>
                <button onclick="clearClassificationHistory()" class="px-3 py-1.5 text-xs font-medium text-purple-600 bg-purple-50 border border-purple-200 rounded-lg hover:bg-purple-100 transition-colors">
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
                        $from = $member['cfo_classification_auto'] ?? 'Unknown';
                        $to = $member['cfo_classification'] ?? 'Unclassified';
                    ?>
                        <div class="flex items-center justify-between p-3 bg-purple-50 rounded-lg border border-purple-100 hover:bg-purple-100 transition-colors">
                            <div class="flex items-center flex-1 min-w-0">
                                <div class="w-10 h-10 bg-purple-200 rounded-full flex items-center justify-center text-purple-700 font-bold flex-shrink-0">
                                    <?php echo strtoupper(substr($fullName, 0, 1)); ?>
                                </div>
                                <div class="ml-3 flex-1 min-w-0">
                                    <p class="font-semibold text-gray-900 truncate"><?php echo Security::escape($fullName); ?></p>
                                    <div class="flex items-center gap-2 text-sm">
                                        <span class="text-gray-600"><?php echo Security::escape($from); ?></span>
                                        <i class="fa-solid fa-arrow-right text-purple-500"></i>
                                        <span class="font-medium text-purple-700"><?php echo Security::escape($to); ?></span>
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
                            <tr class="hover:bg-purple-50 transition-colors">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-indigo-900" rowspan="4">
                                    Purok <?php echo Security::escape($stat['purok']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <span class="inline-flex items-center">
                                        <span class="mr-2">💑</span>
                                        <span class="font-medium text-purple-700">Buklod</span>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-semibold text-purple-900">
                                    <?php echo number_format($stat['buklod']); ?>
                                </td>
                            </tr>
                            <!-- Kadiwa Row -->
                            <tr class="hover:bg-blue-50 transition-colors">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <span class="inline-flex items-center">
                                        <span class="mr-2">👥</span>
                                        <span class="font-medium text-blue-700">Kadiwa</span>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-semibold text-blue-900">
                                    <?php echo number_format($stat['kadiwa']); ?>
                                </td>
                            </tr>
                            <!-- Binhi Row -->
                            <tr class="hover:bg-pink-50 transition-colors">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <span class="inline-flex items-center">
                                        <span class="mr-2">👶</span>
                                        <span class="font-medium text-pink-700">Binhi</span>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-semibold text-pink-900">
                                    <?php echo number_format($stat['binhi']); ?>
                                </td>
                            </tr>
                            <!-- Total Row -->
                            <tr class="bg-gray-50 hover:bg-gray-100 transition-colors">
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
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
                            <?php foreach ($turningEighteen as $member): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900"><?php echo Security::escape($member['name']); ?></div>
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
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
