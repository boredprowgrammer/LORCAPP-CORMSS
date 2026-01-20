<?php
/**
 * Requests App - Dashboard
 * Manage officer requests & approvals
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/app-navigation.php';
require_once __DIR__ . '/../includes/permissions.php';
Security::requireLogin();

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

// Get statistics
$stats = [];

try {
    // Pending officer requests
    $stmt = $db->query("SELECT COUNT(*) as total FROM officer_requests WHERE status = 'pending'");
    $stats['pending_officer'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Pending CFO access
    $stmt = $db->query("
        SELECT COUNT(*) as total FROM (
            SELECT id FROM cfo_access_requests WHERE status = 'pending'
            UNION ALL
            SELECT id FROM hdb_access_requests WHERE status = 'pending'
            UNION ALL
            SELECT id FROM pnk_access_requests WHERE status = 'pending'
        ) as pending
    ");
    $stats['pending_cfo_access'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Total approved this month
    $stmt = $db->query("
        SELECT COUNT(*) as total FROM officer_requests 
        WHERE status = 'approved' 
        AND DATE_FORMAT(updated_at, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')
    ");
    $stats['monthly_approved'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Total rejected this month
    $stmt = $db->query("
        SELECT COUNT(*) as total FROM officer_requests 
        WHERE status = 'rejected' 
        AND DATE_FORMAT(updated_at, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')
    ");
    $stats['monthly_rejected'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

} catch (Exception $e) {
    error_log("Error fetching requests stats: " . $e->getMessage());
    $stats = [
        'pending_officer' => 0,
        'pending_cfo_access' => 0,
        'monthly_approved' => 0,
        'monthly_rejected' => 0
    ];
}

$pageTitle = 'Requests App';
?>
<!DOCTYPE html>
<html lang="en" x-data="{ darkMode: localStorage.getItem('darkMode') === 'true' }" :class="{ 'dark': darkMode }">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                    }
                }
            }
        }
    </script>
    <style>
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-gray-100 min-h-screen">

    <?php renderAppNavigation('requests', 'dashboard.php', true); ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-8">

        <!-- Statistics Cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
            
            <div class="bg-white dark:bg-gray-800 p-4 sm:p-6 rounded-xl border border-gray-200 dark:border-gray-700 hover:shadow-lg transition-shadow">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs sm:text-sm text-gray-500 dark:text-gray-400">Pending Officer</span>
                    <div class="w-10 h-10 bg-rose-100 dark:bg-rose-900/30 rounded-lg flex items-center justify-center">
                        <svg class="w-5 h-5 text-rose-600 dark:text-rose-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path>
                        </svg>
                    </div>
                </div>
                <p class="text-2xl sm:text-3xl font-bold text-rose-600 dark:text-rose-400"><?php echo number_format($stats['pending_officer']); ?></p>
            </div>

            <div class="bg-white dark:bg-gray-800 p-4 sm:p-6 rounded-xl border border-gray-200 dark:border-gray-700 hover:shadow-lg transition-shadow">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs sm:text-sm text-gray-500 dark:text-gray-400">Pending CFO Access</span>
                    <div class="w-10 h-10 bg-amber-100 dark:bg-amber-900/30 rounded-lg flex items-center justify-center">
                        <svg class="w-5 h-5 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                        </svg>
                    </div>
                </div>
                <p class="text-2xl sm:text-3xl font-bold text-amber-600 dark:text-amber-400"><?php echo number_format($stats['pending_cfo_access']); ?></p>
            </div>

            <div class="bg-white dark:bg-gray-800 p-4 sm:p-6 rounded-xl border border-gray-200 dark:border-gray-700 hover:shadow-lg transition-shadow">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs sm:text-sm text-gray-500 dark:text-gray-400">Approved (Month)</span>
                    <div class="w-10 h-10 bg-green-100 dark:bg-green-900/30 rounded-lg flex items-center justify-center">
                        <svg class="w-5 h-5 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                </div>
                <p class="text-2xl sm:text-3xl font-bold text-green-600 dark:text-green-400"><?php echo number_format($stats['monthly_approved']); ?></p>
            </div>

            <div class="bg-white dark:bg-gray-800 p-4 sm:p-6 rounded-xl border border-gray-200 dark:border-gray-700 hover:shadow-lg transition-shadow">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs sm:text-sm text-gray-500 dark:text-gray-400">Rejected (Month)</span>
                    <div class="w-10 h-10 bg-red-100 dark:bg-red-900/30 rounded-lg flex items-center justify-center">
                        <svg class="w-5 h-5 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                </div>
                <p class="text-2xl sm:text-3xl font-bold text-red-600 dark:text-red-400"><?php echo number_format($stats['monthly_rejected']); ?></p>
            </div>

        </div>

        <!-- Quick Actions Grid -->
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Quick Actions</h2>
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4 mb-8">

            <!-- Officer Requests -->
            <?php if (hasPermission('can_view_requests')): ?>
            <a href="../requests/list.php" class="group flex flex-col items-center p-6 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl hover:border-rose-400 dark:hover:border-rose-500 hover:shadow-lg transition-all">
                <div class="w-14 h-14 bg-rose-100 dark:bg-rose-900/30 rounded-xl flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                    <svg class="w-7 h-7 text-rose-600 dark:text-rose-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path>
                    </svg>
                </div>
                <span class="text-sm font-medium text-gray-900 dark:text-gray-100 text-center">Officer Requests</span>
                <span class="text-xs text-gray-500 dark:text-gray-400 text-center mt-1">View All</span>
            </a>
            <?php endif; ?>

            <!-- CFO Access Requests -->
            <?php if ($currentUser['role'] === 'admin' || $currentUser['role'] === 'local'): ?>
            <a href="../pending-cfo-access.php" class="group flex flex-col items-center p-6 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl hover:border-purple-400 dark:hover:border-purple-500 hover:shadow-lg transition-all">
                <div class="w-14 h-14 bg-purple-100 dark:bg-purple-900/30 rounded-xl flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                    <svg class="w-7 h-7 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                    </svg>
                </div>
                <span class="text-sm font-medium text-gray-900 dark:text-gray-100 text-center">CFO Access</span>
                <span class="text-xs text-gray-500 dark:text-gray-400 text-center mt-1">Pending</span>
            </a>
            <?php endif; ?>

            <!-- Approved Requests -->
            <?php if (hasPermission('can_view_requests')): ?>
            <a href="../requests/list.php?status=approved" class="group flex flex-col items-center p-6 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl hover:border-green-400 dark:hover:border-green-500 hover:shadow-lg transition-all">
                <div class="w-14 h-14 bg-green-100 dark:bg-green-900/30 rounded-xl flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                    <svg class="w-7 h-7 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
                <span class="text-sm font-medium text-gray-900 dark:text-gray-100 text-center">Approved</span>
                <span class="text-xs text-gray-500 dark:text-gray-400 text-center mt-1">View All</span>
            </a>
            <?php endif; ?>

            <!-- Rejected Requests -->
            <?php if (hasPermission('can_view_requests')): ?>
            <a href="../requests/list.php?status=rejected" class="group flex flex-col items-center p-6 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl hover:border-red-400 dark:hover:border-red-500 hover:shadow-lg transition-all">
                <div class="w-14 h-14 bg-red-100 dark:bg-red-900/30 rounded-xl flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                    <svg class="w-7 h-7 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
                <span class="text-sm font-medium text-gray-900 dark:text-gray-100 text-center">Rejected</span>
                <span class="text-xs text-gray-500 dark:text-gray-400 text-center mt-1">View All</span>
            </a>
            <?php endif; ?>

            <!-- Pending Actions -->
            <?php if ($currentUser['role'] === 'admin' || $currentUser['role'] === 'local'): ?>
            <a href="../pending-actions.php" class="group flex flex-col items-center p-6 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl hover:border-blue-400 dark:hover:border-blue-500 hover:shadow-lg transition-all">
                <div class="w-14 h-14 bg-blue-100 dark:bg-blue-900/30 rounded-xl flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                    <svg class="w-7 h-7 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
                <span class="text-sm font-medium text-gray-900 dark:text-gray-100 text-center">Pending Actions</span>
                <span class="text-xs text-gray-500 dark:text-gray-400 text-center mt-1">All Items</span>
            </a>
            <?php endif; ?>

            <!-- CFO Request List -->
            <?php if ($currentUser['role'] === 'admin' || $currentUser['role'] === 'local'): ?>
            <a href="../cfo-access-requests.php" class="group flex flex-col items-center p-6 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl hover:border-cyan-400 dark:hover:border-cyan-500 hover:shadow-lg transition-all">
                <div class="w-14 h-14 bg-cyan-100 dark:bg-cyan-900/30 rounded-xl flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                    <svg class="w-7 h-7 text-cyan-600 dark:text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                </div>
                <span class="text-sm font-medium text-gray-900 dark:text-gray-100 text-center">CFO Request List</span>
                <span class="text-xs text-gray-500 dark:text-gray-400 text-center mt-1">All Requests</span>
            </a>
            <?php endif; ?>

            <!-- Transfer Requests -->
            <?php if (hasPermission('can_transfer_in') || hasPermission('can_transfer_out')): ?>
            <a href="../transfers/list.php" class="group flex flex-col items-center p-6 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl hover:border-indigo-400 dark:hover:border-indigo-500 hover:shadow-lg transition-all">
                <div class="w-14 h-14 bg-indigo-100 dark:bg-indigo-900/30 rounded-xl flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                    <svg class="w-7 h-7 text-indigo-600 dark:text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
                    </svg>
                </div>
                <span class="text-sm font-medium text-gray-900 dark:text-gray-100 text-center">Transfers</span>
                <span class="text-xs text-gray-500 dark:text-gray-400 text-center mt-1">Pending</span>
            </a>
            <?php endif; ?>

            <!-- Search -->
            <?php if (hasPermission('can_view_officers')): ?>
            <a href="../officers/search.php" class="group flex flex-col items-center p-6 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl hover:border-teal-400 dark:hover:border-teal-500 hover:shadow-lg transition-all">
                <div class="w-14 h-14 bg-teal-100 dark:bg-teal-900/30 rounded-xl flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                    <svg class="w-7 h-7 text-teal-600 dark:text-teal-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                </div>
                <span class="text-sm font-medium text-gray-900 dark:text-gray-100 text-center">Search</span>
                <span class="text-xs text-gray-500 dark:text-gray-400 text-center mt-1">Find Officer</span>
            </a>
            <?php endif; ?>

        </div>

        <!-- Reports Section -->
        <?php if (hasPermission('can_view_reports')): ?>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Reports & History</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">

            <a href="../requests/list.php" class="flex items-center gap-4 p-4 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl hover:border-emerald-400 dark:hover:border-emerald-500 hover:shadow-lg transition-all">
                <div class="w-12 h-12 bg-emerald-100 dark:bg-emerald-900/30 rounded-lg flex items-center justify-center flex-shrink-0">
                    <svg class="w-6 h-6 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
                <div>
                    <span class="font-medium text-gray-900 dark:text-gray-100">All Requests</span>
                    <p class="text-xs text-gray-500 dark:text-gray-400">View complete history</p>
                </div>
            </a>

            <a href="../reports/masterlist.php" class="flex items-center gap-4 p-4 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl hover:border-violet-400 dark:hover:border-violet-500 hover:shadow-lg transition-all">
                <div class="w-12 h-12 bg-violet-100 dark:bg-violet-900/30 rounded-lg flex items-center justify-center flex-shrink-0">
                    <svg class="w-6 h-6 text-violet-600 dark:text-violet-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                </div>
                <div>
                    <span class="font-medium text-gray-900 dark:text-gray-100">Masterlist</span>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Officer statistics</p>
                </div>
            </a>

            <?php if ($currentUser['role'] === 'admin'): ?>
            <a href="../admin/audit.php" class="flex items-center gap-4 p-4 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl hover:border-orange-400 dark:hover:border-orange-500 hover:shadow-lg transition-all">
                <div class="w-12 h-12 bg-orange-100 dark:bg-orange-900/30 rounded-lg flex items-center justify-center flex-shrink-0">
                    <svg class="w-6 h-6 text-orange-600 dark:text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path>
                    </svg>
                </div>
                <div>
                    <span class="font-medium text-gray-900 dark:text-gray-100">Audit Log</span>
                    <p class="text-xs text-gray-500 dark:text-gray-400">System activity</p>
                </div>
            </a>
            <?php endif; ?>

        </div>
        <?php endif; ?>

    </div>

</body>
</html>
