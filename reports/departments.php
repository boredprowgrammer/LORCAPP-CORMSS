<?php
require_once __DIR__ . '/../config/config.php';

Security::requireLogin();
requirePermission('can_view_departments');

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

// Build WHERE clause based on user role
$whereConditions = ['od.is_active = 1'];
$params = [];

if ($currentUser['role'] === 'local') {
    $whereConditions[] = 'o.local_code = ?';
    $params[] = $currentUser['local_code'];
} elseif ($currentUser['role'] === 'district') {
    $whereConditions[] = 'o.district_code = ?';
    $params[] = $currentUser['district_code'];
}

$whereClause = 'WHERE ' . implode(' AND ', $whereConditions);

// Get department statistics
try {
    $stmt = $db->prepare("
        SELECT 
            od.department,
            COUNT(DISTINCT od.officer_id) as officer_count
        FROM officer_departments od
        JOIN officers o ON od.officer_id = o.officer_id
        $whereClause
        GROUP BY od.department
        ORDER BY officer_count DESC
    ");
    $stmt->execute($params);
    $departments = $stmt->fetchAll();
    
    // Get total officers
    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT o.officer_id) as total
        FROM officers o
        JOIN officer_departments od ON o.officer_id = od.officer_id
        $whereClause
    ");
    $stmt->execute($params);
    $totalOfficers = $stmt->fetch()['total'];
    
    // Get eldest church officers based on oath date (earliest dates from officer_departments)
    $stmt = $db->prepare("
        SELECT 
            o.officer_id,
            o.officer_uuid,
            o.last_name_encrypted,
            o.first_name_encrypted,
            o.middle_initial_encrypted,
            MIN(od.oath_date) as earliest_oath_date,
            o.district_code,
            GROUP_CONCAT(DISTINCT od.department ORDER BY od.department SEPARATOR ', ') as departments,
            GROUP_CONCAT(DISTINCT od.duty ORDER BY od.duty SEPARATOR ', ') as duties,
            d.district_name,
            lc.local_name,
            TIMESTAMPDIFF(YEAR, MIN(od.oath_date), CURDATE()) as years_in_service
        FROM officers o
        INNER JOIN officer_departments od ON o.officer_id = od.officer_id AND od.is_active = 1
        LEFT JOIN districts d ON o.district_code = d.district_code
        LEFT JOIN local_congregations lc ON o.local_code = lc.local_code
        $whereClause
        AND od.oath_date IS NOT NULL
        GROUP BY o.officer_id
        ORDER BY earliest_oath_date ASC
        LIMIT 10
    ");
    $stmt->execute($params);
    $eldestOfficers = $stmt->fetchAll();
    
    // Get department statistics from officer requests (requests and oath taken)
    $requestWhereConditions = ['1=1'];
    $requestParams = [];
    
    if ($currentUser['role'] === 'local') {
        $requestWhereConditions[] = 'ofr.local_code = ?';
        $requestParams[] = $currentUser['local_code'];
    } elseif ($currentUser['role'] === 'district') {
        $requestWhereConditions[] = 'ofr.district_code = ?';
        $requestParams[] = $currentUser['district_code'];
    }
    
    $requestWhereClause = 'WHERE ' . implode(' AND ', $requestWhereConditions);
    
    $stmt = $db->prepare("
        SELECT 
            ofr.requested_department as department,
            COUNT(*) as total_requests,
            SUM(CASE WHEN ofr.status = 'oath_taken' THEN 1 ELSE 0 END) as oath_taken_count,
            SUM(CASE WHEN ofr.status IN ('pending', 'requested_to_seminar', 'in_seminar', 'seminar_completed', 'requested_to_oath', 'ready_to_oath') THEN 1 ELSE 0 END) as pending_count,
            SUM(CASE WHEN ofr.status IN ('rejected', 'cancelled') THEN 1 ELSE 0 END) as rejected_cancelled_count
        FROM officer_requests ofr
        $requestWhereClause
        GROUP BY ofr.requested_department
        ORDER BY oath_taken_count DESC, total_requests DESC
    ");
    $stmt->execute($requestParams);
    $departmentRequests = $stmt->fetchAll();
    
    // Get officer growth data (last 12 months) - Oath Taken from officer_requests
    $stmt = $db->prepare("
        SELECT 
            DATE_FORMAT(ofr.completed_at, '%Y-%m') as month,
            COUNT(*) as oath_count
        FROM officer_requests ofr
        $requestWhereClause
        AND ofr.status = 'oath_taken'
        AND ofr.completed_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(ofr.completed_at, '%Y-%m')
        ORDER BY month ASC
    ");
    $stmt->execute($requestParams);
    $oathGrowthData = $stmt->fetchAll();
    
    // Get officer requests growth data (last 12 months)
    $stmt = $db->prepare("
        SELECT 
            DATE_FORMAT(ofr.requested_at, '%Y-%m') as month,
            COUNT(*) as request_count
        FROM officer_requests ofr
        $requestWhereClause
        AND ofr.requested_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(ofr.requested_at, '%Y-%m')
        ORDER BY month ASC
    ");
    $stmt->execute($requestParams);
    $requestGrowthData = $stmt->fetchAll();
    
    // Prepare data for charts
    $growthMonths = [];
    $oathCounts = [];
    $requestCounts = [];
    
    // Get last 12 months
    for ($i = 11; $i >= 0; $i--) {
        $month = date('Y-m', strtotime("-$i months"));
        $growthMonths[] = date('M Y', strtotime("-$i months"));
        
        // Find oath count for this month
        $oathCount = 0;
        foreach ($oathGrowthData as $data) {
            if ($data['month'] == $month) {
                $oathCount = $data['oath_count'];
                break;
            }
        }
        $oathCounts[] = $oathCount;
        
        // Find request count for this month
        $requestCount = 0;
        foreach ($requestGrowthData as $data) {
            if ($data['month'] == $month) {
                $requestCount = $data['request_count'];
                break;
            }
        }
        $requestCounts[] = $requestCount;
    }
    
} catch (Exception $e) {
    error_log("Departments report error: " . $e->getMessage());
    $departments = [];
    $totalOfficers = 0;
    $eldestOfficers = [];
    $departmentRequests = [];
    $growthMonths = [];
    $oathCounts = [];
    $requestCounts = [];
}

$pageTitle = 'Departments Report';
ob_start();
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between p-6 bg-gradient-to-r from-blue-600 to-blue-700 rounded-lg shadow-lg text-white">
        <div>
            <h1 class="text-3xl font-bold mb-1">Departments Report</h1>
            <p class="text-sm text-blue-100">Comprehensive overview of officer distribution and statistics</p>
        </div>
        <div class="text-right">
            <div class="text-4xl font-bold mb-1"><?php echo number_format($totalOfficers); ?></div>
            <div class="text-sm text-blue-100">Total Officers</div>
        </div>
    </div>
    
    <!-- Two Column Layout: Current Officers & Recruitment Pipeline -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        
        <!-- LEFT COLUMN: Current Officers Distribution -->
        <div class="space-y-6">
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-5">
                <div class="flex items-center gap-2 mb-4 pb-3 border-b border-gray-200">
                    <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                    </svg>
                    <h2 class="text-lg font-bold text-gray-900">Current Officers by Department</h2>
                </div>
                
                <!-- Department Grid -->
                <div class="grid grid-cols-2 gap-3 mb-4">
                    <?php foreach ($departments as $dept): ?>
                        <?php
                        $percentage = $totalOfficers > 0 ? ($dept['officer_count'] / $totalOfficers * 100) : 0;
                        $maxCount = !empty($departments) ? max(array_column($departments, 'officer_count')) : 1;
                        ?>
                        <div class="bg-gradient-to-br from-blue-50 to-blue-100 rounded-lg p-3 border border-blue-200 hover:shadow-md transition-shadow">
                            <h3 class="text-xs font-semibold mb-1.5 text-gray-700 truncate" title="<?php echo Security::escape($dept['department']); ?>">
                                <?php echo Security::escape($dept['department']); ?>
                            </h3>
                            <div class="flex items-center justify-between">
                                <span class="text-2xl font-bold text-blue-700">
                                    <?php echo number_format($dept['officer_count']); ?>
                                </span>
                                <span class="text-xs text-gray-600"><?php echo number_format($percentage, 1); ?>%</span>
                            </div>
                            <div class="w-full bg-blue-200 rounded-full h-1.5 mt-2">
                                <div class="bg-blue-600 h-1.5 rounded-full transition-all" 
                                     style="width: <?php echo ($dept['officer_count'] / $maxCount) * 100; ?>%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                    <?php if (empty($departments)): ?>
                        <div class="col-span-2 text-center py-8">
                            <svg class="w-12 h-12 text-gray-300 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                            </svg>
                            <p class="text-xs text-gray-500">No department data</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- RIGHT COLUMN: Recruitment Pipeline -->
        <div class="space-y-6">
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-5">
                <div class="flex items-center gap-2 mb-4 pb-3 border-b border-gray-200">
                    <svg class="w-6 h-6 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path>
                    </svg>
                    <h2 class="text-lg font-bold text-gray-900">Officer Recruitment Pipeline</h2>
                </div>
                
                <?php if (!empty($departmentRequests)): ?>
                <div class="space-y-3">
                    <?php 
                    $rank = 1;
                    foreach (array_slice($departmentRequests, 0, 5) as $deptReq): 
                        $successRate = $deptReq['total_requests'] > 0 ? ($deptReq['oath_taken_count'] / $deptReq['total_requests'] * 100) : 0;
                    ?>
                        <div class="bg-gradient-to-br from-indigo-50 to-purple-50 rounded-lg p-4 border border-indigo-200">
                            <div class="flex items-start justify-between mb-2">
                                <div class="flex items-center gap-2">
                                    <div class="inline-flex items-center justify-center w-6 h-6 rounded-full text-xs font-bold
                                        <?php 
                                        if ($rank == 1) echo 'bg-yellow-400 text-yellow-900';
                                        elseif ($rank == 2) echo 'bg-gray-300 text-gray-800';
                                        elseif ($rank == 3) echo 'bg-orange-400 text-orange-900';
                                        else echo 'bg-indigo-200 text-indigo-800';
                                        ?>">
                                        <?php echo $rank++; ?>
                                    </div>
                                    <h3 class="font-semibold text-gray-900 text-sm"><?php echo Security::escape($deptReq['department']); ?></h3>
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-4 gap-2 mb-2">
                                <div class="text-center">
                                    <div class="text-lg font-bold text-blue-700"><?php echo $deptReq['total_requests']; ?></div>
                                    <div class="text-xs text-gray-600">Total</div>
                                </div>
                                <div class="text-center">
                                    <div class="text-lg font-bold text-green-700"><?php echo $deptReq['oath_taken_count']; ?></div>
                                    <div class="text-xs text-gray-600">Completed</div>
                                </div>
                                <div class="text-center">
                                    <div class="text-lg font-bold text-yellow-700"><?php echo $deptReq['pending_count']; ?></div>
                                    <div class="text-xs text-gray-600">Pending</div>
                                </div>
                                <div class="text-center">
                                    <div class="text-lg font-bold text-red-700"><?php echo $deptReq['rejected_cancelled_count']; ?></div>
                                    <div class="text-xs text-gray-600">Failed</div>
                                </div>
                            </div>
                            
                            <div class="flex items-center gap-2">
                                <span class="text-xs font-medium text-gray-700">Success Rate:</span>
                                <div class="flex-1 bg-gray-200 rounded-full h-2">
                                    <div class="h-2 rounded-full transition-all <?php echo $successRate >= 75 ? 'bg-green-600' : ($successRate >= 50 ? 'bg-yellow-600' : 'bg-red-600'); ?>" 
                                         style="width: <?php echo $successRate; ?>%"></div>
                                </div>
                                <span class="text-xs font-bold <?php echo $successRate >= 75 ? 'text-green-700' : ($successRate >= 50 ? 'text-yellow-700' : 'text-red-700'); ?>">
                                    <?php echo number_format($successRate, 1); ?>%
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                    <div class="text-center py-8">
                        <svg class="w-12 h-12 text-gray-300 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path>
                        </svg>
                        <p class="text-xs text-gray-500">No recruitment data</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Officer Growth Charts -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        
        <!-- Oath Taken Growth Chart -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-5">
            <div class="flex items-center gap-2 mb-4 pb-3 border-b border-gray-200">
                <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                </svg>
                <h2 class="text-lg font-bold text-gray-900">Officer Growth (Oath Taken)</h2>
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                    Last 12 Months
                </span>
            </div>
            
            <div class="relative h-64">
                <canvas id="oathGrowthChart"></canvas>
            </div>
            
            <div class="mt-4 pt-4 border-t border-gray-200">
                <div class="flex items-center justify-between text-sm">
                    <span class="text-gray-600">Total Oath Taken:</span>
                    <span class="font-bold text-green-700 text-lg"><?php echo array_sum($oathCounts); ?></span>
                </div>
            </div>
        </div>
        
        <!-- Officer Requests Growth Chart -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-5">
            <div class="flex items-center gap-2 mb-4 pb-3 border-b border-gray-200">
                <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                </svg>
                <h2 class="text-lg font-bold text-gray-900">Officer Requests Trend</h2>
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                    Last 12 Months
                </span>
            </div>
            
            <div class="relative h-64">
                <canvas id="requestGrowthChart"></canvas>
            </div>
            
            <div class="mt-4 pt-4 border-t border-gray-200">
                <div class="flex items-center justify-between text-sm">
                    <span class="text-gray-600">Total Requests:</span>
                    <span class="font-bold text-blue-700 text-lg"><?php echo array_sum($requestCounts); ?></span>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Full Width Section: Detailed Statistics Tables -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        
        <!-- Department Ranking Table -->
        
        <!-- Department Ranking Table -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
            <div class="flex items-center gap-2 mb-3">
                <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                </svg>
                <h3 class="font-semibold text-gray-900">Department Rankings</h3>
                <span class="text-xs text-gray-500">By active officer count</span>
            </div>
            
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Rank</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Department</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Officers</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Share</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php 
                        $rank = 1;
                        foreach ($departments as $dept): 
                            $percentage = $totalOfficers > 0 ? ($dept['officer_count'] / $totalOfficers * 100) : 0;
                        ?>
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-3 py-2">
                                    <div class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-700">
                                        #<?php echo $rank++; ?>
                                    </div>
                                </td>
                                <td class="px-3 py-2">
                                    <div class="text-sm font-medium text-gray-900"><?php echo Security::escape($dept['department']); ?></div>
                                </td>
                                <td class="px-3 py-2">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">
                                        <?php echo number_format($dept['officer_count']); ?>
                                    </span>
                                </td>
                                <td class="px-3 py-2">
                                    <div class="flex items-center gap-2">
                                        <div class="flex-1 w-20 bg-gray-200 rounded-full h-1.5">
                                            <div class="bg-blue-600 h-1.5 rounded-full transition-all" 
                                                 style="width: <?php echo $percentage; ?>%"></div>
                                        </div>
                                        <span class="text-xs font-medium text-gray-700"><?php echo number_format($percentage, 1); ?>%</span>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Recruitment Details Table -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
            <div class="flex items-center gap-2 mb-3">
                <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                </svg>
                <h3 class="font-semibold text-gray-900">Recruitment Details</h3>
                <span class="text-xs text-gray-500">All department requests</span>
            </div>
            
            <?php if (!empty($departmentRequests)): ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Dept</th>
                            <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Total</th>
                            <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Done</th>
                            <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Pending</th>
                            <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Failed</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($departmentRequests as $deptReq): ?>
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-3 py-2">
                                    <div class="text-sm font-medium text-gray-900"><?php echo Security::escape($deptReq['department']); ?></div>
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <span class="text-sm font-semibold text-gray-700"><?php echo $deptReq['total_requests']; ?></span>
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                        <?php echo $deptReq['oath_taken_count']; ?>
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                        <?php echo $deptReq['pending_count']; ?>
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                        <?php echo $deptReq['rejected_cancelled_count']; ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <div class="text-center py-6">
                    <p class="text-xs text-gray-500">No recruitment data</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Full Width: Eldest Officers -->
    
    <!-- Full Width: Eldest Officers -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-5">
        <div class="flex items-center gap-2 mb-4 pb-3 border-b border-gray-200">
            <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <h2 class="text-lg font-bold text-gray-900">Eldest Church Officers</h2>
            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                Top 10 Longest-Serving
            </span>
        </div>
        
        <?php if (!empty($eldestOfficers)): ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gradient-to-r from-purple-50 to-pink-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-600 uppercase">Rank</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-600 uppercase">Officer Name</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-600 uppercase">Department(s)</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-600 uppercase">Duty/Duties</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-600 uppercase">Earliest Oath Date</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-600 uppercase">Years in Service</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-600 uppercase">Location</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php 
                    $rank = 1;
                    foreach ($eldestOfficers as $officer): 
                        // Decrypt officer name
                        $fullName = Encryption::getFullName(
                            $officer['last_name_encrypted'],
                            $officer['first_name_encrypted'],
                            $officer['middle_initial_encrypted'],
                            $officer['district_code']
                        );
                    ?>
                        <tr class="hover:bg-purple-50 transition-colors">
                            <td class="px-4 py-3">
                                <div class="inline-flex items-center justify-center w-9 h-9 rounded-full text-sm font-bold shadow-sm
                                    <?php 
                                    if ($rank == 1) echo 'bg-gradient-to-br from-yellow-400 to-yellow-500 text-yellow-900';
                                    elseif ($rank == 2) echo 'bg-gradient-to-br from-gray-300 to-gray-400 text-gray-800';
                                    elseif ($rank == 3) echo 'bg-gradient-to-br from-orange-400 to-orange-500 text-orange-900';
                                    else echo 'bg-gradient-to-br from-purple-200 to-purple-300 text-purple-800';
                                    ?>">
                                    <?php echo $rank++; ?>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <a href="<?php echo BASE_URL; ?>/officers/view.php?id=<?php echo $officer['officer_uuid']; ?>" 
                                   class="text-sm font-semibold text-blue-600 hover:text-blue-800 hover:underline">
                                    <?php echo Security::escape($fullName); ?>
                                </a>
                            </td>
                            <td class="px-4 py-3">
                                <span class="text-xs text-gray-700 font-medium">
                                    <?php echo Security::escape($officer['departments'] ?? 'N/A'); ?>
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="text-xs text-gray-600">
                                    <?php echo Security::escape($officer['duties'] ?? 'N/A'); ?>
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="text-sm font-medium text-gray-900">
                                    <?php echo $officer['earliest_oath_date'] ? date('M d, Y', strtotime($officer['earliest_oath_date'])) : 'N/A'; ?>
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-bold bg-gradient-to-r from-green-100 to-emerald-100 text-green-800 shadow-sm">
                                    <?php echo $officer['years_in_service']; ?> yrs
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="text-xs">
                                    <div class="font-medium text-gray-900"><?php echo Security::escape($officer['local_name'] ?? 'N/A'); ?></div>
                                    <div class="text-gray-500"><?php echo Security::escape($officer['district_name'] ?? 'N/A'); ?></div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
            <div class="text-center py-12">
                <svg class="w-16 h-16 text-gray-300 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <p class="text-sm text-gray-500">No oath date records available</p>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Remove old sections below and keep only this organized layout -->
    
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js" integrity="sha512-CQBWl4fJHWbryGE+Pc7UAxWMUMNMWzWxF4SQo9CgkJIN1kx6djDQZjh3Y8SZ1d+6I+1zze6Z7kHXO7q3UyZAWw==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script>
// Oath Taken Growth Chart
const oathCtx = document.getElementById('oathGrowthChart').getContext('2d');
const oathGrowthChart = new Chart(oathCtx, {
    type: 'line',
    data: {
        labels: <?php echo json_encode($growthMonths); ?>,
        datasets: [{
            label: 'Oath Taken',
            data: <?php echo json_encode($oathCounts); ?>,
            borderColor: 'rgb(34, 197, 94)',
            backgroundColor: 'rgba(34, 197, 94, 0.1)',
            borderWidth: 3,
            fill: true,
            tension: 0.4,
            pointBackgroundColor: 'rgb(34, 197, 94)',
            pointBorderColor: '#fff',
            pointBorderWidth: 2,
            pointRadius: 5,
            pointHoverRadius: 7
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: false
            },
            tooltip: {
                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                padding: 12,
                titleFont: {
                    size: 14,
                    weight: 'bold'
                },
                bodyFont: {
                    size: 13
                },
                callbacks: {
                    label: function(context) {
                        return 'Oath Taken: ' + context.parsed.y + ' officers';
                    }
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    stepSize: 1,
                    font: {
                        size: 11
                    }
                },
                grid: {
                    color: 'rgba(0, 0, 0, 0.05)'
                }
            },
            x: {
                ticks: {
                    font: {
                        size: 10
                    },
                    maxRotation: 45,
                    minRotation: 45
                },
                grid: {
                    display: false
                }
            }
        }
    }
});

// Officer Requests Growth Chart
const requestCtx = document.getElementById('requestGrowthChart').getContext('2d');
const requestGrowthChart = new Chart(requestCtx, {
    type: 'bar',
    data: {
        labels: <?php echo json_encode($growthMonths); ?>,
        datasets: [{
            label: 'New Requests',
            data: <?php echo json_encode($requestCounts); ?>,
            backgroundColor: 'rgba(59, 130, 246, 0.8)',
            borderColor: 'rgb(59, 130, 246)',
            borderWidth: 2,
            borderRadius: 6,
            hoverBackgroundColor: 'rgba(59, 130, 246, 1)'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: false
            },
            tooltip: {
                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                padding: 12,
                titleFont: {
                    size: 14,
                    weight: 'bold'
                },
                bodyFont: {
                    size: 13
                },
                callbacks: {
                    label: function(context) {
                        return 'Requests: ' + context.parsed.y;
                    }
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    stepSize: 1,
                    font: {
                        size: 11
                    }
                },
                grid: {
                    color: 'rgba(0, 0, 0, 0.05)'
                }
            },
            x: {
                ticks: {
                    font: {
                        size: 10
                    },
                    maxRotation: 45,
                    minRotation: 45
                },
                grid: {
                    display: false
                }
            }
        }
    }
});
</script>
