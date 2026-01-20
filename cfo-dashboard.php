<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/announcements.php';

Security::requireLogin();

// Restrict access to local_cfo role only
$currentUser = getCurrentUser();
if ($currentUser['role'] !== 'local_cfo') {
    $_SESSION['error'] = 'Access denied. This page is only for CFO officers.';
    header('Location: ' . BASE_URL . '/launchpad.php');
    exit;
}

$db = Database::getInstance()->getConnection();

$pageTitle = 'CFO Dashboard';
ob_start();

// Get CFO statistics for the user's local
$stats = [
    'total' => 0,
    'buklod' => 0,
    'kadiwa' => 0,
    'binhi' => 0,
    'active' => 0,
    'transferred' => 0,
    'new_this_month' => 0,
    'inactive' => 0
];

try {
    $localCode = $currentUser['local_code'];
    
    // Total CFO members (active only)
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM tarheta_control WHERE local_code = ? AND cfo_status = 'active'");
    $stmt->execute([$localCode]);
    $stats['total'] = $stmt->fetch()['total'];
    
    // By classification (active only)
    $stmt = $db->prepare("
        SELECT 
            cfo_classification,
            COUNT(*) as count 
        FROM tarheta_control 
        WHERE local_code = ? AND cfo_status = 'active'
        GROUP BY cfo_classification
    ");
    $stmt->execute([$localCode]);
    while ($row = $stmt->fetch()) {
        if ($row['cfo_classification']) {
            $stats[strtolower($row['cfo_classification'])] = $row['count'];
        }
    }
    
    // By status
    $stmt = $db->prepare("
        SELECT 
            cfo_status,
            COUNT(*) as count 
        FROM tarheta_control 
        WHERE local_code = ?
        GROUP BY cfo_status
    ");
    $stmt->execute([$localCode]);
    while ($row = $stmt->fetch()) {
        if ($row['cfo_status'] === 'active') {
            $stats['active'] = $row['count'];
        } elseif ($row['cfo_status'] === 'transferred-out') {
            $stats['transferred'] = $row['count'];
        }
    }
    
    // New members this month
    $stmt = $db->prepare("
        SELECT COUNT(*) as count 
        FROM tarheta_control 
        WHERE local_code = ? 
        AND DATE_FORMAT(created_at, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')
    ");
    $stmt->execute([$localCode]);
    $stats['new_this_month'] = $stmt->fetch()['count'];
    
    // Get local info
    $stmt = $db->prepare("
        SELECT lc.local_name, d.district_name 
        FROM local_congregations lc
        LEFT JOIN districts d ON lc.district_code = d.district_code
        WHERE lc.local_code = ?
    ");
    $stmt->execute([$localCode]);
    $localInfo = $stmt->fetch();
    
    // Recent Transfer Outs
    $recentTransferOuts = [];
    $stmt = $db->prepare("
        SELECT 
            t.id,
            t.first_name_encrypted,
            t.last_name_encrypted,
            t.district_code,
            t.cfo_classification,
            t.updated_at,
            t.cfo_notes
        FROM tarheta_control t
        WHERE t.local_code = ? 
        AND t.cfo_status = 'transferred-out'
        ORDER BY t.updated_at DESC
        LIMIT 10
    ");
    $stmt->execute([$localCode]);
    $recentTransferOuts = $stmt->fetchAll();
    
    // Recent Classification Changes (from audit log or recent updates)
    $classificationChanges = [];
    $stmt = $db->prepare("
        SELECT 
            t.id,
            t.first_name_encrypted,
            t.last_name_encrypted,
            t.district_code,
            t.cfo_classification,
            t.cfo_classification_auto,
            t.updated_at
        FROM tarheta_control t
        WHERE t.local_code = ? 
        AND t.cfo_status = 'active'
        AND t.cfo_classification != t.cfo_classification_auto
        AND t.cfo_classification IS NOT NULL
        ORDER BY t.updated_at DESC
        LIMIT 10
    ");
    $stmt->execute([$localCode]);
    $classificationChanges = $stmt->fetchAll();
    
} catch (Exception $e) {
    error_log("Error loading CFO dashboard stats: " . $e->getMessage());
}
?>

<div class="space-y-6">
    <!-- Welcome Header -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-4 sm:p-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 sm:gap-0">
            <div>
                <h1 class="text-xl sm:text-2xl font-semibold text-gray-900 dark:text-gray-100"><?php echo Security::escape($currentUser['full_name']); ?></h1>
                <p class="text-xs sm:text-sm text-gray-500 mt-1"><?php echo formatDate(date('Y-m-d'), 'l, F d, Y'); ?> • Week <?php echo getCurrentWeekNumber(); ?></p>
            </div>
            <div class="flex items-center gap-2 sm:gap-3">
                <?php 
                $userAnnouncements = getUserAnnouncements($currentUser['user_id']);
                if (!empty($userAnnouncements)): 
                ?>
                <button onclick="openAnnouncementsModal()" class="inline-flex items-center px-3 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition-colors">
                    <svg class="w-4 h-4 sm:mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path>
                    </svg>
                    <span class="hidden sm:inline">Announcements</span>
                    <span class="ml-1 sm:ml-2 inline-flex items-center justify-center px-2 py-0.5 text-xs font-bold leading-none text-white bg-red-600 rounded-full"><?php echo count($userAnnouncements); ?></span>
                </button>
                <?php endif; ?>
                <div class="px-3 sm:px-4 py-2 bg-purple-100 text-purple-700 rounded-lg text-xs sm:text-sm font-medium">
                    CFO OFFICER
                </div>
            </div>
        </div>
    </div>

    <!-- Key Metrics Grid - 4 Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
        <!-- Total CFO Members -->
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-3 sm:p-4 hover:shadow-md transition-shadow">
            <div class="flex flex-col sm:flex-row items-start sm:items-center sm:space-x-3 space-y-2 sm:space-y-0">
                <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-lg bg-blue-100 flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 sm:w-6 sm:h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                    </svg>
                </div>
                <div class="min-w-0">
                    <p class="text-xl sm:text-2xl font-bold text-gray-900 dark:text-gray-100 truncate"><?php echo number_format($stats['total']); ?></p>
                    <p class="text-xs text-gray-500 truncate">Total Members</p>
                </div>
            </div>
        </div>
        
        <!-- Active Members -->
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-3 sm:p-4 hover:shadow-md transition-shadow">
            <div class="flex flex-col sm:flex-row items-start sm:items-center sm:space-x-3 space-y-2 sm:space-y-0">
                <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-lg bg-green-100 flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 sm:w-6 sm:h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
                <div class="min-w-0">
                    <p class="text-xl sm:text-2xl font-bold text-green-600 truncate"><?php echo number_format($stats['active']); ?></p>
                    <p class="text-xs text-gray-500 truncate">Active</p>
                </div>
            </div>
        </div>
        
        <!-- Transferred Out -->
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-3 sm:p-4 hover:shadow-md transition-shadow">
            <div class="flex flex-col sm:flex-row items-start sm:items-center sm:space-x-3 space-y-2 sm:space-y-0">
                <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-lg bg-cyan-100 flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 sm:w-6 sm:h-6 text-cyan-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"></path>
                    </svg>
                </div>
                <div class="min-w-0">
                    <p class="text-xl sm:text-2xl font-bold text-cyan-600 truncate"><?php echo number_format($stats['transferred']); ?></p>
                    <p class="text-xs text-gray-500 truncate">Transferred</p>
                </div>
            </div>
        </div>
        
        <!-- New This Month -->
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-3 sm:p-4 hover:shadow-md transition-shadow">
            <div class="flex flex-col sm:flex-row items-start sm:items-center sm:space-x-3 space-y-2 sm:space-y-0">
                <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-lg bg-purple-100 flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 sm:w-6 sm:h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path>
                    </svg>
                </div>
                <div class="min-w-0">
                    <p class="text-xl sm:text-2xl font-bold text-purple-600 truncate"><?php echo number_format($stats['new_this_month']); ?></p>
                    <p class="text-xs text-gray-500 truncate">New/Month</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 sm:gap-6">
        <!-- CFO Classifications -->
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-5">
            <h2 class="text-lg font-bold text-gray-900 dark:text-gray-100 mb-4 flex items-center">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                </svg>
                By Classification
            </h2>
            <div class="space-y-3">
                <div class="flex items-center justify-between p-3 bg-purple-50 dark:bg-purple-900/20 rounded-lg border border-purple-100 dark:border-purple-800">
                    <div class="flex items-center">
                        <div class="w-10 h-10 bg-purple-100 dark:bg-purple-900/50 rounded-full flex items-center justify-center mr-3">
                            <svg class="w-5 h-5 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"></path>
                            </svg>
                        </div>
                        <span class="font-medium text-gray-900 dark:text-gray-100">Buklod</span>
                    </div>
                    <span class="text-2xl font-bold text-purple-600 dark:text-purple-400"><?php echo number_format($stats['buklod']); ?></span>
                </div>
                
                <div class="flex items-center justify-between p-3 bg-green-50 dark:bg-green-900/20 rounded-lg border border-green-100 dark:border-green-800">
                    <div class="flex items-center">
                        <div class="w-10 h-10 bg-green-100 dark:bg-green-900/50 rounded-full flex items-center justify-center mr-3">
                            <svg class="w-5 h-5 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                            </svg>
                        </div>
                        <span class="font-medium text-gray-900 dark:text-gray-100">Kadiwa</span>
                    </div>
                    <span class="text-2xl font-bold text-green-600 dark:text-green-400"><?php echo number_format($stats['kadiwa']); ?></span>
                </div>
                
                <div class="flex items-center justify-between p-3 bg-orange-50 dark:bg-orange-900/20 rounded-lg border border-orange-100 dark:border-orange-800">
                    <div class="flex items-center">
                        <div class="w-10 h-10 bg-orange-100 dark:bg-orange-900/50 rounded-full flex items-center justify-center mr-3">
                            <svg class="w-5 h-5 text-orange-600 dark:text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                        <span class="font-medium text-gray-900 dark:text-gray-100">Binhi</span>
                    </div>
                    <span class="text-2xl font-bold text-orange-600 dark:text-orange-400"><?php echo number_format($stats['binhi']); ?></span>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-5">
            <h2 class="text-lg font-bold text-gray-900 dark:text-gray-100 mb-4 flex items-center">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                </svg>
                Quick Actions
            </h2>
            <div class="space-y-2">
                <a href="cfo-registry.php" class="flex items-center p-3 rounded-lg border border-gray-200 dark:border-gray-700 hover:bg-blue-50 dark:hover:bg-blue-900/20 hover:border-blue-300 dark:hover:border-blue-700 transition-all group">
                    <div class="w-10 h-10 bg-blue-100 dark:bg-blue-900/50 rounded-lg flex items-center justify-center mr-3 group-hover:bg-blue-200 dark:group-hover:bg-blue-900/70">
                        <svg class="w-5 h-5 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                    </div>
                    <div class="flex-1">
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100 group-hover:text-blue-600 dark:group-hover:text-blue-400">CFO Registry</h3>
                        <p class="text-xs text-gray-500">View and manage members</p>
                    </div>
                    <svg class="w-5 h-5 text-gray-400 group-hover:text-blue-600 dark:group-hover:text-blue-400 transform group-hover:translate-x-1 transition-all" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </a>

                <a href="cfo-add.php" class="flex items-center p-3 rounded-lg border border-gray-200 dark:border-gray-700 hover:bg-green-50 dark:hover:bg-green-900/20 hover:border-green-300 dark:hover:border-green-700 transition-all group">
                    <div class="w-10 h-10 bg-green-100 dark:bg-green-900/50 rounded-lg flex items-center justify-center mr-3 group-hover:bg-green-200 dark:group-hover:bg-green-900/70">
                        <svg class="w-5 h-5 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                        </svg>
                    </div>
                    <div class="flex-1">
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100 group-hover:text-green-600 dark:group-hover:text-green-400">Add CFO Member</h3>
                        <p class="text-xs text-gray-500">Register new member</p>
                    </div>
                    <svg class="w-5 h-5 text-gray-400 group-hover:text-green-600 dark:group-hover:text-green-400 transform group-hover:translate-x-1 transition-all" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </a>

                <a href="reports/cfo-reports.php" class="flex items-center p-3 rounded-lg border border-gray-200 dark:border-gray-700 hover:bg-purple-50 dark:hover:bg-purple-900/20 hover:border-purple-300 dark:hover:border-purple-700 transition-all group">
                    <div class="w-10 h-10 bg-purple-100 dark:bg-purple-900/50 rounded-lg flex items-center justify-center mr-3 group-hover:bg-purple-200 dark:group-hover:bg-purple-900/70">
                        <svg class="w-5 h-5 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                    </div>
                    <div class="flex-1">
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100 group-hover:text-purple-600 dark:group-hover:text-purple-400">CFO Reports</h3>
                        <p class="text-xs text-gray-500">View statistics and reports</p>
                    </div>
                    <svg class="w-5 h-5 text-gray-400 group-hover:text-purple-600 dark:group-hover:text-purple-400 transform group-hover:translate-x-1 transition-all" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </a>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-5">
            <h2 class="text-lg font-bold text-gray-900 dark:text-gray-100 mb-4 flex items-center">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                Recent Activity
            </h2>
            <?php
            try {
                $stmt = $db->prepare("
                    SELECT 
                        first_name_encrypted,
                        last_name_encrypted,
                        district_code,
                        cfo_classification,
                        created_at
                    FROM tarheta_control
                    WHERE local_code = ?
                    ORDER BY created_at DESC
                    LIMIT 5
                ");
                $stmt->execute([$localCode]);
                $recentMembers = $stmt->fetchAll();
                
                if (count($recentMembers) > 0): ?>
                    <div class="space-y-2">
                        <?php foreach ($recentMembers as $member): 
                            $firstName = Encryption::decrypt($member['first_name_encrypted'], $member['district_code']);
                            $lastName = Encryption::decrypt($member['last_name_encrypted'], $member['district_code']);
                            $fullName = trim($firstName . ' ' . $lastName);
                        ?>
                            <div class="flex items-center p-3 bg-gray-50 dark:bg-gray-700/50 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                                <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-purple-500 rounded-full flex items-center justify-center text-white font-bold flex-shrink-0">
                                    <?php echo strtoupper(substr($fullName, 0, 1)); ?>
                                </div>
                                <div class="ml-3 flex-1 min-w-0">
                                    <p class="font-semibold text-gray-900 dark:text-gray-100 truncate"><?php echo Security::escape($fullName); ?></p>
                                    <p class="text-sm text-gray-500 truncate"><?php echo Security::escape($member['cfo_classification'] ?? 'Unclassified'); ?></p>
                                </div>
                                <div class="text-right ml-2">
                                    <p class="text-xs text-gray-500"><?php echo date('M d', strtotime($member['created_at'])); ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-8 text-gray-500">
                        <svg class="w-16 h-16 mx-auto text-gray-300 dark:text-gray-600 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                        </svg>
                        <p class="text-gray-500 dark:text-gray-400">No recent activity</p>
                    </div>
                <?php endif;
            } catch (Exception $e) {
                error_log("Error loading recent activity: " . $e->getMessage());
            }
            ?>
        </div>
    </div>

    <!-- Recent Transfer Outs -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-5">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-bold text-gray-900 dark:text-gray-100 flex items-center">
                <svg class="w-5 h-5 mr-2 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                </svg>
                Recent Transfer Outs
            </h2>
        </div>
        <?php if (count($recentTransferOuts) > 0): ?>
            <div class="space-y-2">
                <?php foreach ($recentTransferOuts as $member): 
                    $firstName = Encryption::decrypt($member['first_name_encrypted'], $member['district_code']);
                    $lastName = Encryption::decrypt($member['last_name_encrypted'], $member['district_code']);
                    $fullName = trim($firstName . ' ' . $lastName);
                ?>
                    <div class="flex items-center justify-between p-3 bg-red-50 dark:bg-red-900/20 rounded-lg border border-red-100 dark:border-red-900 hover:bg-red-100 dark:hover:bg-red-900/30 transition-colors group">
                        <div class="flex items-center flex-1 min-w-0">
                            <div class="w-10 h-10 bg-red-200 dark:bg-red-900/50 rounded-full flex items-center justify-center text-red-700 dark:text-red-300 font-bold flex-shrink-0">
                                <?php echo strtoupper(substr($fullName, 0, 1)); ?>
                            </div>
                            <div class="ml-3 flex-1 min-w-0">
                                <p class="font-semibold text-gray-900 dark:text-gray-100 truncate"><?php echo Security::escape($fullName); ?></p>
                                <p class="text-sm text-gray-600 dark:text-gray-400 truncate"><?php echo Security::escape($member['cfo_classification'] ?? 'Unclassified'); ?></p>
                            </div>
                        </div>
                        <div class="text-right ml-3 flex-shrink-0">
                            <p class="text-xs text-gray-500 dark:text-gray-400"><?php echo date('M d, Y', strtotime($member['updated_at'])); ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="text-center py-8 text-gray-500">
                <svg class="w-16 h-16 mx-auto text-gray-300 dark:text-gray-600 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <p class="text-gray-500 dark:text-gray-400">No recent transfers out</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Classification Changes (Lipat Kapisanan) -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-5">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-bold text-gray-900 dark:text-gray-100 flex items-center">
                <svg class="w-5 h-5 mr-2 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
                </svg>
                Lipat Kapisanan (Classification Changes)
            </h2>
        </div>
        <?php if (count($classificationChanges) > 0): ?>
            <div class="space-y-2">
                <?php foreach ($classificationChanges as $member): 
                    $firstName = Encryption::decrypt($member['first_name_encrypted'], $member['district_code']);
                    $lastName = Encryption::decrypt($member['last_name_encrypted'], $member['district_code']);
                    $fullName = trim($firstName . ' ' . $lastName);
                    $from = $member['cfo_classification_auto'] ?? 'Unknown';
                    $to = $member['cfo_classification'] ?? 'Unclassified';
                ?>
                    <div class="flex items-center justify-between p-3 bg-purple-50 dark:bg-purple-900/20 rounded-lg border border-purple-100 dark:border-purple-900 hover:bg-purple-100 dark:hover:bg-purple-900/30 transition-colors group">
                        <div class="flex items-center flex-1 min-w-0">
                            <div class="w-10 h-10 bg-purple-200 dark:bg-purple-900/50 rounded-full flex items-center justify-center text-purple-700 dark:text-purple-300 font-bold flex-shrink-0">
                                <?php echo strtoupper(substr($fullName, 0, 1)); ?>
                            </div>
                            <div class="ml-3 flex-1 min-w-0">
                                <p class="font-semibold text-gray-900 dark:text-gray-100 truncate"><?php echo Security::escape($fullName); ?></p>
                                <div class="flex items-center gap-2 text-sm">
                                    <span class="text-gray-600 dark:text-gray-400"><?php echo Security::escape($from); ?></span>
                                    <svg class="w-4 h-4 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                                    </svg>
                                    <span class="font-medium text-purple-700 dark:text-purple-400"><?php echo Security::escape($to); ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="text-right ml-3 flex-shrink-0">
                            <p class="text-xs text-gray-500 dark:text-gray-400"><?php echo date('M d, Y', strtotime($member['updated_at'])); ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="text-center py-8 text-gray-500">
                <svg class="w-16 h-16 mx-auto text-gray-300 dark:text-gray-600 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <p class="text-gray-500 dark:text-gray-400">No classification changes</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Location Info Footer -->
    <div class="bg-gradient-to-r from-indigo-50 to-purple-50 dark:from-indigo-900/20 dark:to-purple-900/20 border-l-4 border-indigo-500 p-6 rounded-r-lg">
        <div class="flex items-start">
            <svg class="w-6 h-6 text-indigo-500 mr-3 mt-1 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
            </svg>
            <div>
                <h3 class="text-lg font-semibold text-indigo-900 dark:text-indigo-300 mb-2">Welcome, CFO Officer!</h3>
                <p class="text-indigo-800 dark:text-indigo-400 mb-2">
                    <span class="font-medium"><?php echo Security::escape($localInfo['local_name'] ?? 'Local Congregation'); ?></span> - 
                    <?php echo Security::escape($localInfo['district_name'] ?? 'District'); ?>
                </p>
                <p class="text-sm text-indigo-700 dark:text-indigo-400">
                    You have access to CFO Registry management, member registration, and reports for your local congregation.
                </p>
            </div>
        </div>
    </div>
</div>

<!-- Announcements Modal -->
<?php if (!empty($userAnnouncements)): ?>
<div id="announcementsModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex items-center justify-center min-h-screen px-4">
        <!-- Backdrop -->
        <div class="fixed inset-0 bg-black/50 transition-opacity" onclick="closeAnnouncementsModal()"></div>
        
        <!-- Modal Container -->
        <div class="relative bg-white dark:bg-gray-800 rounded-lg shadow-xl w-full max-w-4xl max-h-[85vh] flex flex-col">
            
            <!-- Header -->
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Announcements (<?php echo count($userAnnouncements); ?>)</h3>
                <button onclick="closeAnnouncementsModal()" class="text-gray-400 hover:text-gray-600 dark:text-gray-400 p-1">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            
            <!-- Announcements List -->
            <div id="announcementsContainer" class="flex-1 overflow-y-auto p-4">
                <?php foreach ($userAnnouncements as $announcement): ?>
                    <?php
                    $announcement['type'] = $announcement['type'] ?? 'info';
                    $announcement['priority'] = $announcement['priority'] ?? 'medium';
                    $announcement['target_role'] = $announcement['target_role'] ?? 'all';
                    $announcement['start_date'] = $announcement['start_date'] ?? null;
                    
                    $typeColors = [
                        'info' => 'border-l-4 border-blue-500 bg-blue-50 dark:bg-blue-900/20',
                        'success' => 'border-l-4 border-green-500 bg-green-50 dark:bg-green-900/20',
                        'warning' => 'border-l-4 border-yellow-500 bg-yellow-50 dark:bg-yellow-900/20',
                        'error' => 'border-l-4 border-red-500 bg-red-50 dark:bg-red-900/20'
                    ];
                    
                    $priorityBadges = [
                        'low' => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300',
                        'medium' => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
                        'high' => 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-300',
                        'urgent' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300'
                    ];
                    
                    $colorClass = $typeColors[$announcement['type']] ?? $typeColors['info'];
                    $badgeClass = $priorityBadges[$announcement['priority']] ?? $priorityBadges['medium'];
                    ?>
                    
                    <div class="announcement-item mb-3" data-announcement-id="<?php echo $announcement['announcement_id']; ?>">
                        <div class="<?php echo $colorClass; ?> rounded p-4 relative">
                            <!-- Close button -->
                            <button onclick="dismissAnnouncement(<?php echo $announcement['announcement_id']; ?>)" 
                                    class="absolute top-3 right-3 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                </svg>
                            </button>
                            
                            <!-- Content -->
                            <div class="pr-6">
                                <!-- Title with badge -->
                                <div class="flex items-center gap-2 mb-2">
                                    <h4 class="font-semibold text-gray-900 dark:text-gray-100"><?php echo Security::escape($announcement['title']); ?></h4>
                                    <span class="inline-block px-2 py-0.5 text-xs font-medium rounded <?php echo $badgeClass; ?>">
                                        <?php echo strtoupper($announcement['priority']); ?>
                                    </span>
                                </div>
                                
                                <!-- Message -->
                                <p class="text-sm text-gray-700 dark:text-gray-300 mb-2 whitespace-pre-wrap"><?php echo nl2br(Security::escape($announcement['message'])); ?></p>
                                
                                <!-- Metadata -->
                                <div class="flex items-center gap-3 text-xs text-gray-500 dark:text-gray-400">
                                    <?php if (!empty($announcement['start_date'])): ?>
                                    <span class="flex items-center gap-1">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                        </svg>
                                        <?php echo date('M d, Y', strtotime($announcement['start_date'])); ?>
                                    </span>
                                    <?php endif; ?>
                                    
                                    <?php if ($announcement['target_role'] !== 'all'): ?>
                                    <span class="flex items-center gap-1">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                                        </svg>
                                        <?php echo ucfirst($announcement['target_role']); ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Footer -->
            <div class="flex items-center justify-end gap-2 px-6 py-3 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50">
                <button onclick="closeAnnouncementsModal()" 
                        class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded hover:bg-gray-50 dark:hover:bg-gray-700">
                    Close
                </button>
                <button onclick="dismissAllAnnouncements()" 
                        class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded hover:bg-blue-700">
                    Dismiss All
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function openAnnouncementsModal() {
    const modal = document.getElementById('announcementsModal');
    if (modal) {
        modal.classList.remove('hidden');
        modal.style.display = 'block';
    }
}

function closeAnnouncementsModal() {
    const modal = document.getElementById('announcementsModal');
    if (modal) {
        modal.classList.add('hidden');
        modal.style.display = 'none';
    }
}

function dismissAnnouncement(announcementId) {
    fetch('<?php echo BASE_URL; ?>/api/dismiss-announcement.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ announcement_id: announcementId })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const element = document.querySelector(`[data-announcement-id="${announcementId}"]`);
            if (element) {
                element.style.transition = 'opacity 0.3s, transform 0.3s';
                element.style.opacity = '0';
                element.style.transform = 'translateX(100%)';
                setTimeout(() => {
                    element.remove();
                    const container = document.getElementById('announcementsContainer');
                    if (container && container.querySelectorAll('.announcement-item').length === 0) {
                        closeAnnouncementsModal();
                        const btn = document.querySelector('[onclick="openAnnouncementsModal()"]');
                        if (btn) btn.remove();
                    } else {
                        updateAnnouncementCount();
                    }
                }, 300);
            }
        }
    })
    .catch(error => console.error('Error:', error));
}

function dismissAllAnnouncements() {
    const announcements = document.querySelectorAll('.announcement-item');
    const announcementIds = Array.from(announcements).map(el => el.dataset.announcementId);
    
    Promise.all(
        announcementIds.map(id => 
            fetch('<?php echo BASE_URL; ?>/api/dismiss-announcement.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ announcement_id: parseInt(id) })
            })
        )
    ).then(() => {
        closeAnnouncementsModal();
        const btn = document.querySelector('[onclick="openAnnouncementsModal()"]');
        if (btn) btn.remove();
    }).catch(error => console.error('Error:', error));
}

function updateAnnouncementCount() {
    const container = document.getElementById('announcementsContainer');
    const count = container ? container.querySelectorAll('.announcement-item').length : 0;
    const badge = document.querySelector('[onclick="openAnnouncementsModal()"] span.bg-red-600');
    if (badge) badge.textContent = count;
    const title = document.querySelector('#announcementsModal h3');
    if (title) title.textContent = `Announcements (${count})`;
}
</script>
<?php endif; ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/includes/layout.php';
?>
