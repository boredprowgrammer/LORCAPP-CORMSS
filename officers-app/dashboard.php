<?php
/**
 * Church Officers App - Dashboard
 * Manage officers, transfers, and appointments
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
    // Total officers
    $stmt = $db->query("SELECT COUNT(*) as total FROM officers WHERE is_active = 1");
    $stats['total_officers'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Active officers
    $stmt = $db->query("SELECT COUNT(*) as total FROM officers WHERE is_active = 1");
    $stats['active_officers'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Pending requests
    $stmt = $db->query("SELECT COUNT(*) as total FROM officer_requests WHERE status = 'pending'");
    $stats['pending_requests'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Recent transfers (last 30 days)
    $stmt = $db->query("SELECT COUNT(*) as total FROM transfers WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $stats['recent_transfers'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

} catch (Exception $e) {
    error_log("Error fetching officers stats: " . $e->getMessage());
    $stats = [
        'total_officers' => 0,
        'active_officers' => 0,
        'pending_requests' => 0,
        'recent_transfers' => 0
    ];
}

$pageTitle = 'Church Officers App';
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

    <?php renderAppNavigation('officers', 'dashboard.php', true); ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-8">

        <!-- Statistics Cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
            
            <div class="bg-white dark:bg-gray-800 p-4 sm:p-6 rounded-xl border border-gray-200 dark:border-gray-700 hover:shadow-lg transition-shadow">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs sm:text-sm text-gray-500 dark:text-gray-400">Total Officers</span>
                    <div class="w-10 h-10 bg-blue-100 dark:bg-blue-900/30 rounded-lg flex items-center justify-center">
                        <svg class="w-5 h-5 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                        </svg>
                    </div>
                </div>
                <p class="text-2xl sm:text-3xl font-bold text-blue-600 dark:text-blue-400"><?php echo number_format($stats['total_officers']); ?></p>
            </div>

            <div class="bg-white dark:bg-gray-800 p-4 sm:p-6 rounded-xl border border-gray-200 dark:border-gray-700 hover:shadow-lg transition-shadow">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs sm:text-sm text-gray-500 dark:text-gray-400">Active Officers</span>
                    <div class="w-10 h-10 bg-green-100 dark:bg-green-900/30 rounded-lg flex items-center justify-center">
                        <svg class="w-5 h-5 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                </div>
                <p class="text-2xl sm:text-3xl font-bold text-green-600 dark:text-green-400"><?php echo number_format($stats['active_officers']); ?></p>
            </div>

            <div class="bg-white dark:bg-gray-800 p-4 sm:p-6 rounded-xl border border-gray-200 dark:border-gray-700 hover:shadow-lg transition-shadow">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs sm:text-sm text-gray-500 dark:text-gray-400">Pending Requests</span>
                    <div class="w-10 h-10 bg-amber-100 dark:bg-amber-900/30 rounded-lg flex items-center justify-center">
                        <svg class="w-5 h-5 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                </div>
                <p class="text-2xl sm:text-3xl font-bold text-amber-600 dark:text-amber-400"><?php echo number_format($stats['pending_requests']); ?></p>
            </div>

            <div class="bg-white dark:bg-gray-800 p-4 sm:p-6 rounded-xl border border-gray-200 dark:border-gray-700 hover:shadow-lg transition-shadow">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs sm:text-sm text-gray-500 dark:text-gray-400">Recent Transfers</span>
                    <div class="w-10 h-10 bg-purple-100 dark:bg-purple-900/30 rounded-lg flex items-center justify-center">
                        <svg class="w-5 h-5 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
                        </svg>
                    </div>
                </div>
                <p class="text-2xl sm:text-3xl font-bold text-purple-600 dark:text-purple-400"><?php echo number_format($stats['recent_transfers']); ?></p>
            </div>

        </div>

        <!-- Quick Actions Grid -->
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Quick Actions</h2>
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4 mb-8">

            <!-- View Officers -->
            <?php if (hasPermission('can_view_officers')): ?>
            <a href="../officers/list.php" class="group flex flex-col items-center p-6 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl hover:border-blue-400 dark:hover:border-blue-500 hover:shadow-lg transition-all">
                <div class="w-14 h-14 bg-blue-100 dark:bg-blue-900/30 rounded-xl flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                    <svg class="w-7 h-7 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                    </svg>
                </div>
                <span class="text-sm font-medium text-gray-900 dark:text-gray-100 text-center">View Officers</span>
                <span class="text-xs text-gray-500 dark:text-gray-400 text-center mt-1">Browse Registry</span>
            </a>
            <?php endif; ?>

            <!-- Add Officer -->
            <?php if (hasPermission('can_add_officers')): ?>
            <a href="../officers/add.php" class="group flex flex-col items-center p-6 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl hover:border-green-400 dark:hover:border-green-500 hover:shadow-lg transition-all">
                <div class="w-14 h-14 bg-green-100 dark:bg-green-900/30 rounded-xl flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                    <svg class="w-7 h-7 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                    </svg>
                </div>
                <span class="text-sm font-medium text-gray-900 dark:text-gray-100 text-center">Add Officer</span>
                <span class="text-xs text-gray-500 dark:text-gray-400 text-center mt-1">New Entry</span>
            </a>
            <?php endif; ?>

            <!-- Transfer Out -->
            <?php if (hasPermission('can_transfer_out')): ?>
            <a href="../transfers/transfer-out.php" class="group flex flex-col items-center p-6 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl hover:border-purple-400 dark:hover:border-purple-500 hover:shadow-lg transition-all">
                <div class="w-14 h-14 bg-purple-100 dark:bg-purple-900/30 rounded-xl flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                    <svg class="w-7 h-7 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
                    </svg>
                </div>
                <span class="text-sm font-medium text-gray-900 dark:text-gray-100 text-center">Transfer Out</span>
                <span class="text-xs text-gray-500 dark:text-gray-400 text-center mt-1">Move Officers</span>
            </a>
            <?php endif; ?>

            <!-- Search Officers -->
            <?php if (hasPermission('can_view_officers')): ?>
            <a href="../officers/list.php" class="group flex flex-col items-center p-6 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl hover:border-amber-400 dark:hover:border-amber-500 hover:shadow-lg transition-all">
                <div class="w-14 h-14 bg-amber-100 dark:bg-amber-900/30 rounded-xl flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                    <svg class="w-7 h-7 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                </div>
                <span class="text-sm font-medium text-gray-900 dark:text-gray-100 text-center">Search</span>
                <span class="text-xs text-gray-500 dark:text-gray-400 text-center mt-1">Find Officers</span>
            </a>
            <?php endif; ?>

            <!-- View Requests -->
            <?php if (hasPermission('can_view_requests')): ?>
            <a href="../requests/list.php" class="group flex flex-col items-center p-6 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl hover:border-rose-400 dark:hover:border-rose-500 hover:shadow-lg transition-all">
                <div class="w-14 h-14 bg-rose-100 dark:bg-rose-900/30 rounded-xl flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                    <svg class="w-7 h-7 text-rose-600 dark:text-rose-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path>
                    </svg>
                </div>
                <span class="text-sm font-medium text-gray-900 dark:text-gray-100 text-center">Requests</span>
                <span class="text-xs text-gray-500 dark:text-gray-400 text-center mt-1">Pending Approvals</span>
            </a>
            <?php endif; ?>

            <!-- Bulk Update -->
            <?php if (hasPermission('can_edit_officers')): ?>
            <a href="../officers/bulk-update.php" class="group flex flex-col items-center p-6 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl hover:border-cyan-400 dark:hover:border-cyan-500 hover:shadow-lg transition-all">
                <div class="w-14 h-14 bg-cyan-100 dark:bg-cyan-900/30 rounded-xl flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                    <svg class="w-7 h-7 text-cyan-600 dark:text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                    </svg>
                </div>
                <span class="text-sm font-medium text-gray-900 dark:text-gray-100 text-center">Bulk Update</span>
                <span class="text-xs text-gray-500 dark:text-gray-400 text-center mt-1">Mass Changes</span>
            </a>
            <?php endif; ?>

            <!-- Transfer In -->
            <?php if (hasPermission('can_transfer_in')): ?>
            <a href="../transfers/transfer-in.php" class="group flex flex-col items-center p-6 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl hover:border-indigo-400 dark:hover:border-indigo-500 hover:shadow-lg transition-all">
                <div class="w-14 h-14 bg-indigo-100 dark:bg-indigo-900/30 rounded-xl flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                    <svg class="w-7 h-7 text-indigo-600 dark:text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path>
                    </svg>
                </div>
                <span class="text-sm font-medium text-gray-900 dark:text-gray-100 text-center">Transfer In</span>
                <span class="text-xs text-gray-500 dark:text-gray-400 text-center mt-1">Receive Officers</span>
            </a>
            <?php endif; ?>

            <!-- Call-Up Slips -->
            <?php if (hasPermission('can_view_officers')): ?>
            <a href="../officers/call-up-list.php" class="group flex flex-col items-center p-6 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl hover:border-teal-400 dark:hover:border-teal-500 hover:shadow-lg transition-all">
                <div class="w-14 h-14 bg-teal-100 dark:bg-teal-900/30 rounded-xl flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                    <svg class="w-7 h-7 text-teal-600 dark:text-teal-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                </div>
                <span class="text-sm font-medium text-gray-900 dark:text-gray-100 text-center">Call-Up List</span>
                <span class="text-xs text-gray-500 dark:text-gray-400 text-center mt-1">View Slips</span>
            </a>
            <?php endif; ?>

        </div>

        <!-- Reports Section -->
        <?php if (hasPermission('can_view_reports')): ?>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Reports & Analytics</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">

            <a href="../reports/masterlist.php" class="flex items-center gap-4 p-4 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl hover:border-emerald-400 dark:hover:border-emerald-500 hover:shadow-lg transition-all">
                <div class="w-12 h-12 bg-emerald-100 dark:bg-emerald-900/30 rounded-lg flex items-center justify-center flex-shrink-0">
                    <svg class="w-6 h-6 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                </div>
                <div>
                    <span class="font-medium text-gray-900 dark:text-gray-100">Officer Reports</span>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Generate masterlist</p>
                </div>
            </a>

            <a href="../reports/departments.php" class="flex items-center gap-4 p-4 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl hover:border-cyan-400 dark:hover:border-cyan-500 hover:shadow-lg transition-all">
                <div class="w-12 h-12 bg-cyan-100 dark:bg-cyan-900/30 rounded-lg flex items-center justify-center flex-shrink-0">
                    <svg class="w-6 h-6 text-cyan-600 dark:text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                    </svg>
                </div>
                <div>
                    <span class="font-medium text-gray-900 dark:text-gray-100">District Summary</span>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Per-district analytics</p>
                </div>
            </a>

            <a href="../reports/headcount.php" class="flex items-center gap-4 p-4 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl hover:border-violet-400 dark:hover:border-violet-500 hover:shadow-lg transition-all">
                <div class="w-12 h-12 bg-violet-100 dark:bg-violet-900/30 rounded-lg flex items-center justify-center flex-shrink-0">
                    <svg class="w-6 h-6 text-violet-600 dark:text-violet-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                    </svg>
                </div>
                <div>
                    <span class="font-medium text-gray-900 dark:text-gray-100">Headcount</span>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Officer statistics</p>
                </div>
            </a>

        </div>
        <?php endif; ?>

    </div>

</body>
</html>
