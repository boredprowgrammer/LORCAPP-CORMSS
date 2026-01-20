<?php
/**
 * App Navigation Component
 * Centralized, reusable navigation for each app
 */

// Include permissions helper
require_once __DIR__ . '/permissions.php';

/**
 * Get navigation items for a specific app
 * @param string $app - App identifier (officers, cfo, registry, reports, callup, requests)
 * @param string $currentPage - Current page basename for active state
 * @return array Navigation items
 */
function getAppNavigation($app, $currentPage = '') {
    $currentUser = getCurrentUser();
    
    $navigations = [
        'officers' => [
            'title' => 'Church Officers',
            'subtitle' => 'Manage officers, transfers & appointments',
            'color' => 'blue',
            'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>',
            'items' => [
                ['label' => 'Dashboard', 'url' => BASE_URL . '/officers-app/dashboard.php', 'icon' => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
                ['label' => 'Officers List', 'url' => BASE_URL . '/officers/list.php', 'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2', 'permission' => 'can_view_officers'],
                ['label' => 'Add Officer', 'url' => BASE_URL . '/officers/add.php', 'icon' => 'M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z', 'permission' => 'can_add_officers'],
                ['label' => 'Transfer In', 'url' => BASE_URL . '/transfers/transfer-in.php', 'icon' => 'M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4', 'permission' => 'can_transfer_in'],
                ['label' => 'Transfer Out', 'url' => BASE_URL . '/transfers/transfer-out.php', 'icon' => 'M17 16V4m0 0l4 4m-4-4l-4 4m-6 0v12m0 0l-4-4m4 4l4-4', 'permission' => 'can_transfer_out'],
                ['label' => 'Bulk Update', 'url' => BASE_URL . '/officers/bulk-update.php', 'icon' => 'M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15', 'permission' => 'can_edit_officers'],
            ]
        ],
        'cfo' => [
            'title' => 'CFO Registry',
            'subtitle' => 'Family & Youth Ministry Management',
            'color' => 'purple',
            'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>',
            'items' => [
                ['label' => 'Dashboard', 'url' => BASE_URL . '/cfo-app/dashboard.php', 'icon' => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
                ['label' => 'CFO Registry', 'url' => BASE_URL . '/cfo-registry.php', 'icon' => 'M10.5 1.5H8.25A2.25 2.25 0 006 3.75v16.5a2.25 2.25 0 002.25 2.25h7.5A2.25 2.25 0 0018 20.25V3.75a2.25 2.25 0 00-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-3 18.75h3', 'color' => 'purple', 'permission' => 'can_access_cfo_registry', 'iconType' => 'stroke'],
                ['label' => 'HDB Registry', 'url' => BASE_URL . '/hdb-registry.php', 'icon' => 'M12 2C13.1 2 14 2.9 14 4C14 5.1 13.1 6 12 6C10.9 6 10 5.1 10 4C10 2.9 10.9 2 12 2ZM15.9 8.1C15.5 7.7 14.8 7 13.5 7H10.5C9.2 7 8.5 7.7 8.1 8.1L5 11.2L6.4 12.6L8.5 10.5V22H10.5V16H13.5V22H15.5V10.5L17.6 12.6L19 11.2L15.9 8.1Z', 'color' => 'blue', 'permission' => 'can_access_hdb', 'iconType' => 'fill'],
                ['label' => 'PNK Registry', 'url' => BASE_URL . '/pnk-registry.php', 'icon' => 'M16 4C16 2.9 15.1 2 14 2C12.9 2 12 2.9 12 4C12 5.1 12.9 6 14 6C15.1 6 16 5.1 16 4ZM20 17V22H18V18H15V22H13V15L10.8 16.1L11.6 20H9.5L8.7 16.5L6 18V22H4V16.5L9.4 13.6L8.3 8.1C8.1 7.3 8.4 6.5 9 6L11.3 4C11.7 3.6 12.3 3.4 12.8 3.5L15.3 4.1C16.3 4.3 17 5.2 17 6.2V9H20V11H15V6.1L13.2 5.7L14.1 10.1L11.1 11.5L11.6 13L17 10.3C17.8 9.9 18.8 10.2 19.3 11L20 12.3C20.3 12.7 20.4 13.1 20.4 13.6V17H20Z', 'color' => 'green', 'permission' => 'can_access_pnk', 'iconType' => 'fill'],
                ['label' => 'CFO Checker', 'url' => BASE_URL . '/cfo-checker.php', 'icon' => 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z', 'permission' => 'can_access_cfo_registry'],
                ['label' => 'CFO Reports', 'url' => BASE_URL . '/reports/cfo-reports.php', 'icon' => 'M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z', 'permission' => 'can_view_reports'],
            ]
        ],
        'registry' => [
            'title' => 'Registry & Records',
            'subtitle' => 'Tarheta Control & Legacy Records',
            'color' => 'indigo',
            'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>',
            'items' => [
                ['label' => 'Dashboard', 'url' => BASE_URL . '/registry-app/dashboard.php', 'icon' => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
                ['label' => 'Tarheta Control', 'url' => BASE_URL . '/cfo-registry.php', 'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z', 'permission' => 'can_access_cfo_registry'],
                ['label' => 'Legacy Records', 'url' => BASE_URL . '/legacy/search.php', 'icon' => 'M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z', 'permission' => 'can_view_legacy_registry'],
                ['label' => 'LORCAPP Records', 'url' => BASE_URL . '/lorcapp/lorcapp_search.php', 'icon' => 'M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4', 'permission' => 'can_view_legacy_registry'],
                ['label' => 'Import Data', 'url' => BASE_URL . '/cfo-import.php', 'icon' => 'M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12', 'roles' => ['admin', 'local', 'district']],
            ]
        ],
        'reports' => [
            'title' => 'Reports & Analytics',
            'subtitle' => 'Generate reports and view statistics',
            'color' => 'emerald',
            'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>',
            'items' => [
                ['label' => 'Dashboard', 'url' => BASE_URL . '/reports-app/dashboard.php', 'icon' => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
                ['label' => 'Masterlist', 'url' => BASE_URL . '/reports/masterlist.php', 'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2', 'permission' => 'can_view_reports'],
                ['label' => 'CFO Reports', 'url' => BASE_URL . '/reports/cfo-reports.php', 'icon' => 'M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z', 'permission' => 'can_access_cfo_registry'],
                ['label' => 'Departments', 'url' => BASE_URL . '/reports/departments.php', 'icon' => 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4', 'permission' => 'can_view_departments'],
                ['label' => 'Headcount', 'url' => BASE_URL . '/reports/headcount.php', 'icon' => 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z', 'permission' => 'can_view_headcount'],
                ['label' => 'R5-13 Transactions', 'url' => BASE_URL . '/reports/r5-transactions.php', 'icon' => 'M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2', 'permission' => 'can_view_reports'],
            ]
        ],
        'callup' => [
            'title' => 'Call-Up Management',
            'subtitle' => 'Manage call-up slips and tracking',
            'color' => 'amber',
            'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>',
            'items' => [
                ['label' => 'Dashboard', 'url' => BASE_URL . '/callup-app/dashboard.php', 'icon' => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
                ['label' => 'Call-Up List', 'url' => BASE_URL . '/officers/call-up-list.php', 'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2', 'permission' => 'can_view_officers'],
                ['label' => 'Generate R5-13', 'url' => BASE_URL . '/generate-r513.php', 'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z', 'permission' => 'can_view_officers'],
                ['label' => 'Generate Palasumpaan', 'url' => BASE_URL . '/generate-palasumpaan.php', 'icon' => 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z', 'permission' => 'can_view_officers'],
                ['label' => 'Logbook Control', 'url' => BASE_URL . '/logbook-control-number.php', 'icon' => 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253', 'permission' => 'can_view_officers'],
            ]
        ],
        'requests' => [
            'title' => 'Request Management',
            'subtitle' => 'Approve and manage requests',
            'color' => 'rose',
            'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>',
            'items' => [
                ['label' => 'Dashboard', 'url' => BASE_URL . '/requests-app/dashboard.php', 'icon' => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
                ['label' => 'Officer Requests', 'url' => BASE_URL . '/requests/list.php', 'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2', 'permission' => 'can_view_requests'],
                ['label' => 'CFO Access Requests', 'url' => BASE_URL . '/cfo-access-requests.php', 'icon' => 'M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z', 'roles' => ['admin', 'local']],
                ['label' => 'Pending Actions', 'url' => BASE_URL . '/pending-actions.php', 'icon' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z', 'roles' => ['admin', 'local']],
            ]
        ],
    ];
    
    return $navigations[$app] ?? null;
}

/**
 * Render app navigation
 * @param string $app - App identifier
 * @param string $currentPage - Current page basename for active state
 * @param bool $headerOnly - If true, only render header without nav tabs (for dashboards)
 */
function renderAppNavigation($app, $currentPage = '', $headerOnly = false) {
    $nav = getAppNavigation($app, $currentPage);
    if (!$nav) return;
    
    $currentUser = getCurrentUser();
    $colorClasses = [
        'blue' => 'bg-blue-600 hover:bg-blue-700 border-blue-500',
        'purple' => 'bg-purple-600 hover:bg-purple-700 border-purple-500',
        'indigo' => 'bg-indigo-600 hover:bg-indigo-700 border-indigo-500',
        'emerald' => 'bg-emerald-600 hover:bg-emerald-700 border-emerald-500',
        'amber' => 'bg-amber-600 hover:bg-amber-700 border-amber-500',
        'rose' => 'bg-rose-600 hover:bg-rose-700 border-rose-500',
        'green' => 'bg-green-600 hover:bg-green-700 border-green-500',
    ];
    
    $colorClass = $colorClasses[$nav['color']] ?? $colorClasses['blue'];
    ?>
    
    <!-- App Header with Navigation -->
    <header class="bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 shadow-sm sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6">
            <div class="flex items-center justify-between py-3">
                <!-- Left: Launchpad + App Title -->
                <div class="flex items-center gap-2 sm:gap-4">
                    <a href="<?php echo BASE_URL; ?>/launchpad.php" 
                       class="p-1.5 sm:p-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg sm:rounded-xl transition-all duration-200"
                       title="Go to App Launchpad">
                        <svg class="w-4 h-4 sm:w-5 sm:h-5 text-gray-600 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path>
                        </svg>
                    </a>
                    <div class="flex items-center gap-2 sm:gap-3">
                        <div class="w-8 h-8 sm:w-10 sm:h-10 <?php echo $colorClass; ?> rounded-lg sm:rounded-xl flex items-center justify-center shadow-lg">
                            <svg class="w-4 h-4 sm:w-5 sm:h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <?php echo $nav['icon']; ?>
                            </svg>
                        </div>
                        <div>
                            <h1 class="text-sm sm:text-lg font-bold text-gray-900 dark:text-gray-100 leading-tight"><?php echo $nav['title']; ?></h1>
                            <p class="text-[10px] sm:text-xs text-gray-500 dark:text-gray-400 hidden sm:block"><?php echo $nav['subtitle']; ?></p>
                        </div>
                    </div>
                </div>
                
                <!-- Right: Clock, Dark Mode, User Menu -->
                <div class="flex items-center gap-2 sm:gap-3">
                    <!-- Clock Widget -->
                    <div class="hidden lg:flex items-center gap-2 text-sm">
                        <div class="flex items-center gap-2 px-2 sm:px-3 py-1 sm:py-1.5 bg-gray-50 dark:bg-gray-700/50 rounded-lg sm:rounded-xl border border-gray-200 dark:border-gray-600">
                            <svg class="w-3.5 h-3.5 sm:w-4 sm:h-4 text-gray-500 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                            </svg>
                            <span id="weekNumber" class="font-medium text-gray-700 dark:text-gray-300 text-xs sm:text-sm">Week --</span>
                        </div>
                        <div class="flex items-center gap-2 px-2 sm:px-3 py-1 sm:py-1.5 bg-blue-50 dark:bg-blue-900/30 rounded-lg sm:rounded-xl border border-blue-200 dark:border-blue-700">
                            <svg class="w-3.5 h-3.5 sm:w-4 sm:h-4 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <span id="currentTime" class="font-medium text-blue-700 dark:text-blue-300 text-xs sm:text-sm">--:--:-- --</span>
                        </div>
                    </div>
                    
                    <!-- Dark Mode Toggle -->
                    <button x-data="{}" @click="darkMode = !darkMode; localStorage.setItem('darkMode', darkMode)" 
                            class="p-1.5 sm:p-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg sm:rounded-xl transition-all duration-200"
                            title="Toggle Dark Mode">
                        <svg x-show="!darkMode" class="w-4 h-4 sm:w-5 sm:h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path>
                        </svg>
                        <svg x-show="darkMode" class="w-4 h-4 sm:w-5 sm:h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path>
                        </svg>
                    </button>
                    
                    <!-- User Menu -->
                    <div x-data="{ open: false }" class="relative">
                        <button @click="open = !open" 
                                class="flex items-center gap-2 sm:gap-3 px-2 sm:px-3 py-1.5 sm:py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg sm:rounded-xl transition-all duration-200">
                            <div class="w-7 h-7 sm:w-9 sm:h-9 bg-gradient-to-br from-blue-600 to-blue-700 dark:from-blue-500 dark:to-blue-600 rounded-lg sm:rounded-xl flex items-center justify-center text-white font-medium text-xs sm:text-sm shadow-lg">
                                <?php echo strtoupper(substr($currentUser['full_name'], 0, 1)); ?>
                            </div>
                            <div class="hidden md:block text-left">
                                <div class="text-xs sm:text-sm font-medium text-gray-900 dark:text-gray-100 truncate max-w-[120px]"><?php echo Security::escape($currentUser['full_name']); ?></div>
                                <div class="text-[10px] sm:text-xs text-gray-500 dark:text-gray-400"><?php echo ucfirst($currentUser['role']); ?></div>
                            </div>
                            <svg class="w-3.5 h-3.5 sm:w-4 sm:h-4 text-gray-500 dark:text-gray-400 hidden sm:block" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </button>
                        
                        <div 
                            x-show="open" 
                            @click.away="open = false"
                            x-transition:enter="transition ease-out duration-100"
                            x-transition:enter-start="transform opacity-0 scale-95"
                            x-transition:enter-end="transform opacity-100 scale-100"
                            x-transition:leave="transition ease-in duration-75"
                            x-transition:leave-start="transform opacity-100 scale-100"
                            x-transition:leave-end="transform opacity-0 scale-95"
                            class="absolute right-0 mt-2 w-56 bg-white dark:bg-gray-800 rounded-xl shadow-xl py-2 border border-gray-200 dark:border-gray-700 z-50"
                            style="display: none;"
                        >
                            <a href="<?php echo BASE_URL; ?>/profile.php" 
                               class="flex items-center px-4 py-2.5 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg mx-2">
                                <svg class="w-4 h-4 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                </svg>
                                Profile
                            </a>
                            <a href="<?php echo BASE_URL; ?>/settings.php" 
                               class="flex items-center px-4 py-2.5 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg mx-2">
                                <svg class="w-4 h-4 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                </svg>
                                Settings
                            </a>
                            <hr class="my-2 border-gray-200 dark:border-gray-700">
                            <a href="<?php echo BASE_URL; ?>/logout.php" 
                               class="flex items-center px-4 py-2.5 text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg mx-2">
                                <svg class="w-4 h-4 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                                </svg>
                                Logout
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php if (!$headerOnly): ?>
            <!-- Navigation Tabs -->
            <nav class="flex gap-2 overflow-x-auto pb-2">
                <?php foreach ($nav['items'] as $item): 
                    // Check permission if specified
                    if (isset($item['permission']) && !hasPermission($item['permission'])) {
                        continue;
                    }
                    // Check role if specified
                    if (isset($item['roles']) && !in_array($currentUser['role'], $item['roles'])) {
                        continue;
                    }
                    
                    $isActive = strpos($_SERVER['REQUEST_URI'], basename($item['url'])) !== false;
                    $itemColor = $item['color'] ?? $nav['color'];
                    $activeClass = $isActive ? "bg-{$itemColor}-50 dark:bg-{$itemColor}-900/30 text-{$itemColor}-600 dark:text-{$itemColor}-400 border-{$itemColor}-200 dark:border-{$itemColor}-800" : "text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 border-transparent";
                ?>
                <a href="<?php echo $item['url']; ?>" 
                   class="flex items-center gap-2 px-4 py-2.5 text-sm font-medium rounded-xl border transition-all duration-200 whitespace-nowrap <?php echo $activeClass; ?>">
                    <?php if (isset($item['iconType']) && $item['iconType'] === 'fill'): ?>
                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                        <path d="<?php echo $item['icon']; ?>"></path>
                    </svg>
                    <?php else: ?>
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?php echo $item['icon']; ?>"></path>
                    </svg>
                    <?php endif; ?>
                    <?php echo $item['label']; ?>
                </a>
                <?php endforeach; ?>
            </nav>
            <?php endif; ?>
        </div>
    </header>
    
    <?php
}
?>
