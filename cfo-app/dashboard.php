<?php
/**
 * CFO App - Dashboard
 * Manage CFO, HDB, and PNK registries
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
    // CFO statistics (from tarheta_control)
    $stmt = $db->query("SELECT COUNT(*) as total FROM tarheta_control WHERE cfo_status = 'active'");
    $stats['total_cfo'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // HDB statistics
    $stmt = $db->query("SELECT COUNT(*) as total FROM hdb_registry");
    $stats['total_hdb'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // PNK statistics
    $stmt = $db->query("SELECT COUNT(*) as total FROM pnk_registry");
    $stats['total_pnk'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Pending access requests
    $stmt = $db->query("
        SELECT COUNT(*) as total FROM (
            SELECT id FROM cfo_access_requests WHERE status = 'pending'
            UNION ALL
            SELECT id FROM hdb_access_requests WHERE status = 'pending'
            UNION ALL
            SELECT id FROM pnk_access_requests WHERE status = 'pending'
        ) as pending
    ");
    $stats['pending_requests'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

} catch (Exception $e) {
    error_log("Error fetching CFO stats: " . $e->getMessage());
    $stats = [
        'total_cfo' => 0,
        'total_hdb' => 0,
        'total_pnk' => 0,
        'pending_requests' => 0
    ];
}

$pageTitle = 'CFO Registry App';
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

    <?php renderAppNavigation('cfo', 'dashboard.php', true); ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-8">

        <!-- Statistics Cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
            
            <div class="bg-white dark:bg-gray-800 p-4 sm:p-6 rounded-xl border border-gray-200 dark:border-gray-700 hover:shadow-lg transition-shadow">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs sm:text-sm text-gray-500 dark:text-gray-400">CFO (Families)</span>
                    <div class="w-10 h-10 bg-purple-100 dark:bg-purple-900/30 rounded-lg flex items-center justify-center">
                        <svg class="w-5 h-5 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                        </svg>
                    </div>
                </div>
                <p class="text-2xl sm:text-3xl font-bold text-purple-600 dark:text-purple-400"><?php echo number_format($stats['total_cfo']); ?></p>
            </div>

            <div class="bg-white dark:bg-gray-800 p-4 sm:p-6 rounded-xl border border-gray-200 dark:border-gray-700 hover:shadow-lg transition-shadow">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs sm:text-sm text-gray-500 dark:text-gray-400">HDB (Children)</span>
                    <div class="w-10 h-10 bg-blue-100 dark:bg-blue-900/30 rounded-lg flex items-center justify-center">
                        <svg class="w-5 h-5 text-blue-600 dark:text-blue-400" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M12 2C13.1 2 14 2.9 14 4C14 5.1 13.1 6 12 6C10.9 6 10 5.1 10 4C10 2.9 10.9 2 12 2ZM15.9 8.1C15.5 7.7 14.8 7 13.5 7H10.5C9.2 7 8.5 7.7 8.1 8.1L5 11.2L6.4 12.6L8.5 10.5V22H10.5V16H13.5V22H15.5V10.5L17.6 12.6L19 11.2L15.9 8.1Z"/>
                        </svg>
                    </div>
                </div>
                <p class="text-2xl sm:text-3xl font-bold text-blue-600 dark:text-blue-400"><?php echo number_format($stats['total_hdb']); ?></p>
            </div>

            <div class="bg-white dark:bg-gray-800 p-4 sm:p-6 rounded-xl border border-gray-200 dark:border-gray-700 hover:shadow-lg transition-shadow">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs sm:text-sm text-gray-500 dark:text-gray-400">PNK (Youth)</span>
                    <div class="w-10 h-10 bg-green-100 dark:bg-green-900/30 rounded-lg flex items-center justify-center">
                        <svg class="w-5 h-5 text-green-600 dark:text-green-400" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M16 4C16 2.9 15.1 2 14 2C12.9 2 12 2.9 12 4C12 5.1 12.9 6 14 6C15.1 6 16 5.1 16 4ZM20 17V22H18V18H15V22H13V15L10.8 16.1L11.6 20H9.5L8.7 16.5L6 18V22H4V16.5L9.4 13.6L8.3 8.1C8.1 7.3 8.4 6.5 9 6L11.3 4C11.7 3.6 12.3 3.4 12.8 3.5L15.3 4.1C16.3 4.3 17 5.2 17 6.2V9H20V11H15V6.1L13.2 5.7L14.1 10.1L11.1 11.5L11.6 13L17 10.3C17.8 9.9 18.8 10.2 19.3 11L20 12.3C20.3 12.7 20.4 13.1 20.4 13.6V17H20Z"/>
                        </svg>
                    </div>
                </div>
                <p class="text-2xl sm:text-3xl font-bold text-green-600 dark:text-green-400"><?php echo number_format($stats['total_pnk']); ?></p>
            </div>

            <div class="bg-white dark:bg-gray-800 p-4 sm:p-6 rounded-xl border border-gray-200 dark:border-gray-700 hover:shadow-lg transition-shadow">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs sm:text-sm text-gray-500 dark:text-gray-400">Pending Access</span>
                    <div class="w-10 h-10 bg-amber-100 dark:bg-amber-900/30 rounded-lg flex items-center justify-center">
                        <svg class="w-5 h-5 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                        </svg>
                    </div>
                </div>
                <p class="text-2xl sm:text-3xl font-bold text-amber-600 dark:text-amber-400"><?php echo number_format($stats['pending_requests']); ?></p>
            </div>

        </div>

        <!-- Quick Actions Grid -->
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Quick Actions</h2>
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4 mb-8">

            <!-- CFO Registry -->
            <a href="../cfo-registry.php" class="group flex flex-col items-center p-6 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl hover:border-purple-400 dark:hover:border-purple-500 hover:shadow-lg transition-all">
                <div class="w-14 h-14 bg-purple-100 dark:bg-purple-900/30 rounded-xl flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                    <svg class="w-7 h-7 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                    </svg>
                </div>
                <span class="text-sm font-medium text-gray-900 dark:text-gray-100 text-center">CFO Registry</span>
                <span class="text-xs text-gray-500 dark:text-gray-400 text-center mt-1">View & Manage</span>
            </a>

            <!-- HDB Registry - Only for users with HDB access -->
            <?php if (hasPermission('can_access_hdb')): ?>
            <a href="../hdb-registry.php" class="group flex flex-col items-center p-6 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl hover:border-blue-400 dark:hover:border-blue-500 hover:shadow-lg transition-all">
                <div class="w-14 h-14 bg-blue-100 dark:bg-blue-900/30 rounded-xl flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                    <svg class="w-7 h-7 text-blue-600 dark:text-blue-400" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M12 2C13.1 2 14 2.9 14 4C14 5.1 13.1 6 12 6C10.9 6 10 5.1 10 4C10 2.9 10.9 2 12 2ZM15.9 8.1C15.5 7.7 14.8 7 13.5 7H10.5C9.2 7 8.5 7.7 8.1 8.1L5 11.2L6.4 12.6L8.5 10.5V22H10.5V16H13.5V22H15.5V10.5L17.6 12.6L19 11.2L15.9 8.1Z"/>
                    </svg>
                </div>
                <span class="text-sm font-medium text-gray-900 dark:text-gray-100 text-center">HDB Registry</span>
                <span class="text-xs text-gray-500 dark:text-gray-400 text-center mt-1">Children</span>
            </a>
            <?php endif; ?>

            <!-- PNK Registry - Only for users with PNK access -->
            <?php if (hasPermission('can_access_pnk')): ?>
            <a href="../pnk-registry.php" class="group flex flex-col items-center p-6 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl hover:border-green-400 dark:hover:border-green-500 hover:shadow-lg transition-all">
                <div class="w-14 h-14 bg-green-100 dark:bg-green-900/30 rounded-xl flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                    <svg class="w-7 h-7 text-green-600 dark:text-green-400" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M16 4C16 2.9 15.1 2 14 2C12.9 2 12 2.9 12 4C12 5.1 12.9 6 14 6C15.1 6 16 5.1 16 4ZM20 17V22H18V18H15V22H13V15L10.8 16.1L11.6 20H9.5L8.7 16.5L6 18V22H4V16.5L9.4 13.6L8.3 8.1C8.1 7.3 8.4 6.5 9 6L11.3 4C11.7 3.6 12.3 3.4 12.8 3.5L15.3 4.1C16.3 4.3 17 5.2 17 6.2V9H20V11H15V6.1L13.2 5.7L14.1 10.1L11.1 11.5L11.6 13L17 10.3C17.8 9.9 18.8 10.2 19.3 11L20 12.3C20.3 12.7 20.4 13.1 20.4 13.6V17H20Z"/>
                    </svg>
                </div>
                <span class="text-sm font-medium text-gray-900 dark:text-gray-100 text-center">PNK Registry</span>
                <span class="text-xs text-gray-500 dark:text-gray-400 text-center mt-1">Youth</span>
            </a>
            <?php endif; ?>

            <!-- CFO Checker -->
            <a href="../cfo-checker.php" class="group flex flex-col items-center p-6 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl hover:border-indigo-400 dark:hover:border-indigo-500 hover:shadow-lg transition-all">
                <div class="w-14 h-14 bg-indigo-100 dark:bg-indigo-900/30 rounded-xl flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                    <svg class="w-7 h-7 text-indigo-600 dark:text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
                <span class="text-sm font-medium text-gray-900 dark:text-gray-100 text-center">CFO Checker</span>
                <span class="text-xs text-gray-500 dark:text-gray-400 text-center mt-1">Verify Records</span>
            </a>

            <!-- Add CFO -->
            <a href="../cfo-add.php" class="group flex flex-col items-center p-6 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl hover:border-purple-400 dark:hover:border-purple-500 hover:shadow-lg transition-all">
                <div class="w-14 h-14 bg-purple-100 dark:bg-purple-900/30 rounded-xl flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                    <svg class="w-7 h-7 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                    </svg>
                </div>
                <span class="text-sm font-medium text-gray-900 dark:text-gray-100 text-center">Add CFO</span>
                <span class="text-xs text-gray-500 dark:text-gray-400 text-center mt-1">New Entry</span>
            </a>

            <!-- Add HDB - Only for users with HDB access -->
            <?php if (hasPermission('can_access_hdb')): ?>
            <a href="../hdb-add.php" class="group flex flex-col items-center p-6 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl hover:border-blue-400 dark:hover:border-blue-500 hover:shadow-lg transition-all">
                <div class="w-14 h-14 bg-blue-100 dark:bg-blue-900/30 rounded-xl flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                    <svg class="w-7 h-7 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                    </svg>
                </div>
                <span class="text-sm font-medium text-gray-900 dark:text-gray-100 text-center">Add HDB</span>
                <span class="text-xs text-gray-500 dark:text-gray-400 text-center mt-1">New Child</span>
            </a>
            <?php endif; ?>

            <!-- Add PNK - Only for users with PNK access -->
            <?php if (hasPermission('can_access_pnk')): ?>
            <a href="../pnk-add.php" class="group flex flex-col items-center p-6 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl hover:border-green-400 dark:hover:border-green-500 hover:shadow-lg transition-all">
                <div class="w-14 h-14 bg-green-100 dark:bg-green-900/30 rounded-xl flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                    <svg class="w-7 h-7 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                    </svg>
                </div>
                <span class="text-sm font-medium text-gray-900 dark:text-gray-100 text-center">Add PNK</span>
                <span class="text-xs text-gray-500 dark:text-gray-400 text-center mt-1">New Youth</span>
            </a>
            <?php endif; ?>

            <!-- Access Requests - Only for local or admin role -->
            <?php if ($currentUser['role'] === 'admin' || $currentUser['role'] === 'local'): ?>
            <a href="../pending-cfo-access.php" class="group flex flex-col items-center p-6 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl hover:border-amber-400 dark:hover:border-amber-500 hover:shadow-lg transition-all">
                <div class="w-14 h-14 bg-amber-100 dark:bg-amber-900/30 rounded-xl flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                    <svg class="w-7 h-7 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"></path>
                    </svg>
                </div>
                <span class="text-sm font-medium text-gray-900 dark:text-gray-100 text-center">Access Requests</span>
                <span class="text-xs text-gray-500 dark:text-gray-400 text-center mt-1">Approve/Reject</span>
            </a>
            <?php endif; ?>

            <!-- CFO Activities Suggestions (AI) - Coming Soon -->
            <button onclick="openComingSoonModal('ai-suggestions')" class="group flex flex-col items-center p-6 bg-gradient-to-br from-violet-50 to-purple-50 dark:from-violet-900/20 dark:to-purple-900/20 border border-violet-200 dark:border-violet-700 rounded-xl hover:border-violet-400 dark:hover:border-violet-500 hover:shadow-lg transition-all relative overflow-hidden">
                <div class="absolute top-2 right-2">
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-violet-100 dark:bg-violet-900/50 text-violet-700 dark:text-violet-300">
                        <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                        </svg>
                        AI
                    </span>
                </div>
                <div class="w-14 h-14 bg-gradient-to-br from-violet-500 to-purple-600 rounded-xl flex items-center justify-center mb-3 group-hover:scale-110 transition-transform shadow-lg">
                    <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path>
                    </svg>
                </div>
                <span class="text-sm font-medium text-gray-900 dark:text-gray-100 text-center">AI Suggestions</span>
                <span class="text-xs text-violet-600 dark:text-violet-400 text-center mt-1">CFO Activities</span>
            </button>

            <!-- R7-02 Generator - Coming Soon -->
            <button onclick="openComingSoonModal('r702')" class="group flex flex-col items-center p-6 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl hover:border-teal-400 dark:hover:border-teal-500 hover:shadow-lg transition-all relative">
                <div class="absolute top-2 right-2">
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 dark:bg-amber-900/50 text-amber-700 dark:text-amber-300">
                        Soon
                    </span>
                </div>
                <div class="w-14 h-14 bg-teal-100 dark:bg-teal-900/30 rounded-xl flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                    <svg class="w-7 h-7 text-teal-600 dark:text-teal-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                </div>
                <span class="text-sm font-medium text-gray-900 dark:text-gray-100 text-center">R7-02 Generator</span>
                <span class="text-xs text-gray-500 dark:text-gray-400 text-center mt-1">Family Report</span>
            </button>

        </div>

        <!-- Reports Section -->
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Reports & Analytics</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">

            <a href="../reports/cfo-reports.php" class="flex items-center gap-4 p-4 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl hover:border-emerald-400 dark:hover:border-emerald-500 hover:shadow-lg transition-all">
                <div class="w-12 h-12 bg-emerald-100 dark:bg-emerald-900/30 rounded-lg flex items-center justify-center flex-shrink-0">
                    <svg class="w-6 h-6 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                </div>
                <div>
                    <span class="font-medium text-gray-900 dark:text-gray-100">CFO Reports</span>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Generate family reports</p>
                </div>
            </a>

            <?php if ($currentUser['role'] === 'admin' || $currentUser['role'] === 'local' || $currentUser['role'] === 'district'): ?>
            <a href="../cfo-import.php" class="flex items-center gap-4 p-4 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl hover:border-cyan-400 dark:hover:border-cyan-500 hover:shadow-lg transition-all">
                <div class="w-12 h-12 bg-cyan-100 dark:bg-cyan-900/30 rounded-lg flex items-center justify-center flex-shrink-0">
                    <svg class="w-6 h-6 text-cyan-600 dark:text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                    </svg>
                </div>
                <div>
                    <span class="font-medium text-gray-900 dark:text-gray-100">Import Data</span>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Import from CSV</p>
                </div>
            </a>
            <?php endif; ?>

            <?php if ($currentUser['role'] === 'admin' || $currentUser['role'] === 'local'): ?>
            <a href="../pending-actions.php" class="flex items-center gap-4 p-4 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl hover:border-rose-400 dark:hover:border-rose-500 hover:shadow-lg transition-all">
                <div class="w-12 h-12 bg-rose-100 dark:bg-rose-900/30 rounded-lg flex items-center justify-center flex-shrink-0">
                    <svg class="w-6 h-6 text-rose-600 dark:text-rose-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
                <div>
                    <span class="font-medium text-gray-900 dark:text-gray-100">Pending Actions</span>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Review submissions</p>
                </div>
            </a>
            <?php endif; ?>

        </div>

    </div>

    <!-- Coming Soon Modal -->
    <div id="comingSoonModal" class="fixed inset-0 z-50 hidden overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
        <div class="flex items-center justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <!-- Background overlay -->
            <div class="fixed inset-0 bg-gray-500 dark:bg-gray-900 bg-opacity-75 dark:bg-opacity-80 transition-opacity" onclick="closeComingSoonModal()"></div>

            <!-- Modal panel -->
            <div class="inline-block align-bottom bg-white dark:bg-gray-800 rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                <!-- AI Suggestions Content -->
                <div id="modal-ai-suggestions" class="hidden">
                    <div class="bg-gradient-to-br from-violet-500 to-purple-600 px-6 py-8 text-center">
                        <div class="mx-auto w-20 h-20 bg-white/20 rounded-full flex items-center justify-center mb-4">
                            <svg class="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path>
                            </svg>
                        </div>
                        <h3 class="text-2xl font-bold text-white mb-2">CFO Activities Suggestions</h3>
                        <p class="text-violet-100">Powered by Artificial Intelligence</p>
                    </div>
                    <div class="px-6 py-6">
                        <div class="flex items-center justify-center mb-4">
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300">
                                <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                Coming Soon
                            </span>
                        </div>
                        <p class="text-gray-600 dark:text-gray-300 text-center mb-4">
                            Get intelligent activity suggestions for your CFO based on patterns and best practices. Our AI will help you plan meaningful family-centered activities.
                        </p>
                        <ul class="space-y-2 text-sm text-gray-500 dark:text-gray-400">
                            <li class="flex items-center gap-2">
                                <svg class="w-4 h-4 text-violet-500" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                </svg>
                                Smart activity recommendations
                            </li>
                            <li class="flex items-center gap-2">
                                <svg class="w-4 h-4 text-violet-500" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                </svg>
                                Personalized based on family data
                            </li>
                            <li class="flex items-center gap-2">
                                <svg class="w-4 h-4 text-violet-500" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                </svg>
                                Seasonal and event-based suggestions
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- R7-02 Generator Content -->
                <div id="modal-r702" class="hidden">
                    <div class="bg-gradient-to-br from-teal-500 to-emerald-600 px-6 py-8 text-center">
                        <div class="mx-auto w-20 h-20 bg-white/20 rounded-full flex items-center justify-center mb-4">
                            <svg class="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                        </div>
                        <h3 class="text-2xl font-bold text-white mb-2">R7-02 Generator</h3>
                        <p class="text-teal-100">CFO Monthly Report</p>
                    </div>
                    <div class="px-6 py-6">
                        <div class="flex items-center justify-center mb-4">
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300">
                                <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                Coming Soon
                            </span>
                        </div>
                        <p class="text-gray-600 dark:text-gray-300 text-center mb-4">
                            Generate R7-02 forms automatically based on your CFO registry data
                        </p>
                        <ul class="space-y-2 text-sm text-gray-500 dark:text-gray-400">
                            <li class="flex items-center gap-2">
                                <svg class="w-4 h-4 text-teal-500" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                </svg>                            </li>
                            <li class="flex items-center gap-2">
                                <svg class="w-4 h-4 text-teal-500" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                </svg>
                                PDF export ready for printing
                            </li>
                            <li class="flex items-center gap-2">
                                <svg class="w-4 h-4 text-teal-500" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                </svg>
                                Batch generation support
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Modal Footer -->
                <div class="bg-gray-50 dark:bg-gray-700/50 px-6 py-4 flex justify-end">
                    <button type="button" onclick="closeComingSoonModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-lg shadow-sm text-white bg-purple-600 hover:bg-purple-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500 transition-colors">
                        Got it, thanks!
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Coming Soon Modal functions
        function openComingSoonModal(type) {
            const modal = document.getElementById('comingSoonModal');
            const aiContent = document.getElementById('modal-ai-suggestions');
            const r702Content = document.getElementById('modal-r702');
            
            // Hide all content first
            aiContent.classList.add('hidden');
            r702Content.classList.add('hidden');
            
            // Show relevant content
            if (type === 'ai-suggestions') {
                aiContent.classList.remove('hidden');
            } else if (type === 'r702') {
                r702Content.classList.remove('hidden');
            }
            
            // Show modal
            modal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function closeComingSoonModal() {
            const modal = document.getElementById('comingSoonModal');
            modal.classList.add('hidden');
            document.body.style.overflow = '';
        }

        // Close modal on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeComingSoonModal();
            }
        });

        // Clock widget
        function updateClock() {
            const now = new Date();
            const options = { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true };
            const timeElement = document.getElementById('currentTime');
            if (timeElement) {
                timeElement.textContent = now.toLocaleTimeString('en-US', options);
            }
            
            // Calculate week number
            const start = new Date(now.getFullYear(), 0, 1);
            const diff = now - start;
            const oneDay = 1000 * 60 * 60 * 24;
            const dayOfYear = Math.floor(diff / oneDay);
            const weekNum = Math.ceil((dayOfYear + start.getDay() + 1) / 7);
            const weekElement = document.getElementById('weekNumber');
            if (weekElement) {
                weekElement.textContent = 'Week ' + weekNum;
            }
        }
        updateClock();
        setInterval(updateClock, 1000);
    </script>

</body>
</html>
