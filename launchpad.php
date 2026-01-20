<?php
/**
 * LORCAPP - App Launchpad
 * Main application launcher with all ministry apps
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/announcements.php';
require_once __DIR__ . '/includes/permissions.php';

Security::requireLogin();

$currentUser = getCurrentUser();

// Get announcements for the current user
$userAnnouncements = getUserAnnouncements($currentUser['user_id']);

// Check if we should show the announcement modal (once per login session)
$showAnnouncementModal = false;
if (!empty($userAnnouncements) && !isset($_SESSION['launchpad_announcements_shown'])) {
    $showAnnouncementModal = true;
    $_SESSION['launchpad_announcements_shown'] = true;
}

$pageTitle = 'App Launchpad';
ob_start();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LORCAPP - Launchpad</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
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
</head>
<body class="bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-gray-100 min-h-screen antialiased">

    <div x-data="{ darkMode: localStorage.getItem('darkMode') === 'true' }" 
         x-init="$watch('darkMode', val => { localStorage.setItem('darkMode', val); document.documentElement.classList.toggle('dark', val) }); document.documentElement.classList.toggle('dark', darkMode)"
         class="min-h-screen">

        <!-- Header -->
        <header class="bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 sticky top-0 z-50">
            <div class="max-w-7xl mx-auto px-6 py-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl flex items-center justify-center shadow-lg">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                            </svg>
                        </div>
                        <div>
                            <h1 class="text-lg font-bold tracking-tight">LORCAPP</h1>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Church Officers Registry System</p>
                        </div>
                    </div>
                    
                    <div class="flex items-center gap-2">
                        <!-- Dark Mode Toggle -->
                        <button @click="darkMode = !darkMode" 
                                class="p-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition-colors"
                                :title="darkMode ? 'Switch to Light Mode' : 'Switch to Dark Mode'">
                            <svg x-show="!darkMode" class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path>
                            </svg>
                            <svg x-show="darkMode" class="w-5 h-5 text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path>
                            </svg>
                        </button>
                        
                        <!-- Profile Dropdown -->
                        <div class="relative" x-data="{ profileOpen: false }">
                            <button @click="profileOpen = !profileOpen" 
                                    @click.away="profileOpen = false"
                                    class="flex items-center gap-2 p-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition-colors">
                                <div class="w-8 h-8 bg-gradient-to-br from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white text-sm font-bold">
                                    <?php echo strtoupper(substr($currentUser['full_name'], 0, 1)); ?>
                                </div>
                                <span class="hidden sm:block text-sm font-medium text-gray-700 dark:text-gray-300 max-w-[120px] truncate"><?php echo Security::escape($currentUser['full_name']); ?></span>
                                <svg class="w-4 h-4 text-gray-500 dark:text-gray-400 transition-transform" :class="{ 'rotate-180': profileOpen }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                </svg>
                            </button>
                            
                            <!-- Dropdown Menu -->
                            <div x-show="profileOpen" 
                                 x-transition:enter="transition ease-out duration-100"
                                 x-transition:enter-start="transform opacity-0 scale-95"
                                 x-transition:enter-end="transform opacity-100 scale-100"
                                 x-transition:leave="transition ease-in duration-75"
                                 x-transition:leave-start="transform opacity-100 scale-100"
                                 x-transition:leave-end="transform opacity-0 scale-95"
                                 class="absolute right-0 mt-2 w-64 bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 py-2 z-50"
                                 style="display: none;">
                                
                                <!-- User Info -->
                                <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white font-bold">
                                            <?php echo strtoupper(substr($currentUser['full_name'], 0, 1)); ?>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <p class="text-sm font-semibold text-gray-900 dark:text-gray-100 truncate"><?php echo Security::escape($currentUser['full_name']); ?></p>
                                            <p class="text-xs text-gray-500 dark:text-gray-400 truncate"><?php echo Security::escape($currentUser['username']); ?></p>
                                            <span class="inline-block mt-1 px-2 py-0.5 text-xs font-medium bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 rounded-full"><?php echo ucfirst(Security::escape($currentUser['role'])); ?></span>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Menu Items -->
                                <div class="py-1">
                                    <a href="profile.php" class="flex items-center gap-3 px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                                        <svg class="w-5 h-5 text-gray-500 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                        </svg>
                                        My Profile
                                    </a>
                                    <a href="settings.php" class="flex items-center gap-3 px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                                        <svg class="w-5 h-5 text-gray-500 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                        </svg>
                                        Settings
                                    </a>
                                    <?php if ($currentUser['role'] === 'admin'): ?>
                                    <a href="admin/users.php" class="flex items-center gap-3 px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                                        <svg class="w-5 h-5 text-gray-500 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                                        </svg>
                                        User Management
                                    </a>
                                    <a href="admin/audit.php" class="flex items-center gap-3 px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                                        <svg class="w-5 h-5 text-gray-500 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path>
                                        </svg>
                                        Audit Log
                                    </a>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Logout -->
                                <div class="border-t border-gray-200 dark:border-gray-700 pt-1 mt-1">
                                    <a href="logout.php" class="flex items-center gap-3 px-4 py-2 text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                                        </svg>
                                        Logout
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <div class="max-w-7xl mx-auto px-6 py-8">
            
            <!-- Welcome Section -->
            <div class="mb-10">
                <h2 class="text-2xl font-bold mb-1">Welcome back, <span class="text-blue-600 dark:text-blue-400"><?php echo Security::escape($currentUser['full_name']); ?></span></h2>
                <p class="text-gray-500 dark:text-gray-400">Select an app to get started</p>
            </div>

            <!-- Apps Section -->
            <div class="mb-12">
                <div class="flex items-center gap-4 mb-6">
                    <h2 class="text-xs font-bold uppercase tracking-widest text-gray-400 dark:text-gray-500">Applications</h2>
                    <div class="h-px bg-gray-200 dark:bg-gray-700 flex-1"></div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
                    
                    <!-- Church Officers App - Only show if user can view officers -->
                    <?php if (hasPermission('can_view_officers')): ?>
                    <a href="officers-app/dashboard.php" class="group flex flex-col p-6 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl hover:border-blue-500 dark:hover:border-blue-500 hover:shadow-lg transition-all duration-200">
                        <div class="w-12 h-12 mb-4 text-blue-600 dark:text-blue-400 group-hover:scale-110 transition-transform duration-200">
                            <svg class="w-full h-full" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                            </svg>
                        </div>
                        <h3 class="font-semibold text-lg mb-1">Church Officers</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Manage officers, transfers, and appointments</p>
                        <div class="mt-4 flex items-center text-xs text-blue-600 dark:text-blue-400 font-medium">
                            <span>Open App</span>
                            <svg class="w-4 h-4 ml-1 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                            </svg>
                        </div>
                    </a>
                    <?php endif; ?>

                    <!-- CFO App - Only show if user can access CFO registry -->
                    <?php if (hasPermission('can_access_cfo_registry')): ?>
                    <a href="cfo-app/dashboard.php" class="group flex flex-col p-6 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl hover:border-purple-500 dark:hover:border-purple-500 hover:shadow-lg transition-all duration-200">
                        <div class="w-12 h-12 mb-4 text-purple-600 dark:text-purple-400 group-hover:scale-110 transition-transform duration-200">
                            <svg class="w-full h-full" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                            </svg>
                        </div>
                        <h3 class="font-semibold text-lg mb-1">CFO Registry</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400">CFO, HDB, PNK - Family & Youth Ministry</p>
                        <div class="mt-4 flex items-center text-xs text-purple-600 dark:text-purple-400 font-medium">
                            <span>Open App</span>
                            <svg class="w-4 h-4 ml-1 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                            </svg>
                        </div>
                    </a>
                    <?php endif; ?>

                    <!-- Registry App - Hide from local_cfo users -->
                    <?php if ($currentUser['role'] !== 'local_cfo'): ?>
                    <a href="registry-app/dashboard.php" class="group flex flex-col p-6 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl hover:border-green-500 dark:hover:border-green-500 hover:shadow-lg transition-all duration-200">
                        <div class="w-12 h-12 mb-4 text-green-600 dark:text-green-400 group-hover:scale-110 transition-transform duration-200">
                            <svg class="w-full h-full" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                        </div>
                        <h3 class="font-semibold text-lg mb-1">Legacy Registry</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Tarheta Control & Legacy Records</p>
                        <div class="mt-4 flex items-center text-xs text-green-600 dark:text-green-400 font-medium">
                            <span>Open App</span>
                            <svg class="w-4 h-4 ml-1 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                            </svg>
                        </div>
                    </a>
                    <?php endif; ?>

                    <!-- Reports App - Only show if user can view reports -->
                    <?php if (hasPermission('can_view_reports')): ?>
                    <a href="reports-app/dashboard.php" class="group flex flex-col p-6 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl hover:border-amber-500 dark:hover:border-amber-500 hover:shadow-lg transition-all duration-200">
                        <div class="w-12 h-12 mb-4 text-amber-600 dark:text-amber-400 group-hover:scale-110 transition-transform duration-200">
                            <svg class="w-full h-full" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                        </div>
                        <h3 class="font-semibold text-lg mb-1">Reports</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Analytics, Statistics & Masterlist</p>
                        <div class="mt-4 flex items-center text-xs text-amber-600 dark:text-amber-400 font-medium">
                            <span>Open App</span>
                            <svg class="w-4 h-4 ml-1 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                            </svg>
                        </div>
                    </a>
                    <?php endif; ?>

                    <!-- Call-Up Controller - Only show if user can view officers -->
                    <?php if (hasPermission('can_view_officers')): ?>
                    <a href="callup-app/dashboard.php" class="group flex flex-col p-6 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl hover:border-cyan-500 dark:hover:border-cyan-500 hover:shadow-lg transition-all duration-200">
                        <div class="w-12 h-12 mb-4 text-cyan-600 dark:text-cyan-400 group-hover:scale-110 transition-transform duration-200">
                            <svg class="w-full h-full" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                        </div>
                        <h3 class="font-semibold text-lg mb-1">Call-Up Controller</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Manage officer call-up slips</p>
                        <div class="mt-4 flex items-center text-xs text-cyan-600 dark:text-cyan-400 font-medium">
                            <span>Open App</span>
                            <svg class="w-4 h-4 ml-1 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                            </svg>
                        </div>
                    </a>
                    <?php endif; ?>

                    <!-- Requests App - Only show if user can view requests -->
                    <?php if (hasPermission('can_view_requests')): ?>
                    <a href="requests-app/dashboard.php" class="group flex flex-col p-6 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl hover:border-rose-500 dark:hover:border-rose-500 hover:shadow-lg transition-all duration-200">
                        <div class="w-12 h-12 mb-4 text-rose-600 dark:text-rose-400 group-hover:scale-110 transition-transform duration-200">
                            <svg class="w-full h-full" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path>
                            </svg>
                        </div>
                        <h3 class="font-semibold text-lg mb-1">Requests</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Officer requests & approvals</p>
                        <div class="mt-4 flex items-center text-xs text-rose-600 dark:text-rose-400 font-medium">
                            <span>Open App</span>
                            <svg class="w-4 h-4 ml-1 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                            </svg>
                        </div>
                    </a>
                    <?php endif; ?>

                </div>
            </div>

            <?php if ($currentUser['role'] === 'admin'): ?>
            <!-- Administration -->
            <div class="mb-12">
                <div class="flex items-center gap-4 mb-6">
                    <h2 class="text-xs font-bold uppercase tracking-widest text-gray-400 dark:text-gray-500">Administration</h2>
                    <div class="h-px bg-gray-200 dark:bg-gray-700 flex-1"></div>
                </div>

                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    
                    <a href="admin/users.php" class="flex items-center gap-3 p-4 bg-transparent border border-transparent hover:bg-white dark:hover:bg-gray-800 hover:border-gray-200 dark:hover:border-gray-700 rounded-lg transition-all duration-200">
                       <div class="w-2 h-2 rounded-full bg-blue-500"></div>
                       <span class="text-sm font-medium">Users</span>
                    </a>

                    <a href="admin/districts.php" class="flex items-center gap-3 p-4 bg-transparent border border-transparent hover:bg-white dark:hover:bg-gray-800 hover:border-gray-200 dark:hover:border-gray-700 rounded-lg transition-all duration-200">
                       <div class="w-2 h-2 rounded-full bg-green-500"></div>
                       <span class="text-sm font-medium">Districts</span>
                    </a>

                    <a href="admin/audit.php" class="flex items-center gap-3 p-4 bg-transparent border border-transparent hover:bg-white dark:hover:bg-gray-800 hover:border-gray-200 dark:hover:border-gray-700 rounded-lg transition-all duration-200">
                       <div class="w-2 h-2 rounded-full bg-amber-500"></div>
                       <span class="text-sm font-medium">Audit Log</span>
                    </a>

                    <a href="admin/announcements.php" class="flex items-center gap-3 p-4 bg-transparent border border-transparent hover:bg-white dark:hover:bg-gray-800 hover:border-gray-200 dark:hover:border-gray-700 rounded-lg transition-all duration-200">
                       <div class="w-2 h-2 rounded-full bg-purple-500"></div>
                       <span class="text-sm font-medium">Announcements</span>
                    </a>

                </div>
            </div>
            <?php endif; ?>

            <!-- Quick Actions -->
            <div>
                <div class="flex items-center gap-4 mb-6">
                    <h2 class="text-xs font-bold uppercase tracking-widest text-gray-400 dark:text-gray-500">Quick Links</h2>
                    <div class="h-px bg-gray-200 dark:bg-gray-700 flex-1"></div>
                </div>

                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    
                    <a href="profile.php" class="flex items-center gap-3 p-4 bg-transparent border border-transparent hover:bg-white dark:hover:bg-gray-800 hover:border-gray-200 dark:hover:border-gray-700 rounded-lg transition-all duration-200">
                       <div class="w-2 h-2 rounded-full bg-gray-400 dark:bg-gray-500"></div>
                       <span class="text-sm font-medium">My Profile</span>
                    </a>

                    <a href="settings.php" class="flex items-center gap-3 p-4 bg-transparent border border-transparent hover:bg-white dark:hover:bg-gray-800 hover:border-gray-200 dark:hover:border-gray-700 rounded-lg transition-all duration-200">
                       <div class="w-2 h-2 rounded-full bg-gray-400 dark:bg-gray-500"></div>
                       <span class="text-sm font-medium">Settings</span>
                    </a>

                    <?php if (in_array($currentUser['role'], ['local', 'district'])): ?>
                    <a href="chat.php" class="flex items-center gap-3 p-4 bg-transparent border border-transparent hover:bg-white dark:hover:bg-gray-800 hover:border-gray-200 dark:hover:border-gray-700 rounded-lg transition-all duration-200">
                       <div class="w-2 h-2 rounded-full bg-green-500"></div>
                       <span class="text-sm font-medium">Messages</span>
                    </a>
                    <?php endif; ?>

                </div>
            </div>

        </div>

        <!-- Footer -->
        <footer class="max-w-7xl mx-auto px-6 py-8 border-t border-gray-200 dark:border-gray-700">
            <div class="flex flex-col md:flex-row justify-between items-center gap-4 text-xs text-gray-400 dark:text-gray-500">
                <p>&copy; <?php echo date('Y'); ?> LORCAPP - Church Officers Registry System</p>
                <div class="flex gap-4">
                    <span><?php echo Security::escape($currentUser['local_name'] ?? $currentUser['district_name'] ?? 'All Districts'); ?></span>
                    <span>•</span>
                    <span class="capitalize"><?php echo Security::escape($currentUser['role']); ?></span>
                </div>
            </div>
        </footer>

    </div>

<!-- Announcements Modal -->
<?php if (!empty($userAnnouncements)): ?>
<div id="announcementsModal" class="<?php echo $showAnnouncementModal ? '' : 'hidden'; ?> fixed inset-0 z-50 overflow-y-auto" x-data="{ open: <?php echo $showAnnouncementModal ? 'true' : 'false'; ?> }" x-show="open" x-cloak>
    <div class="flex items-center justify-center min-h-screen px-4 py-8">
        <!-- Backdrop -->
        <div class="fixed inset-0 bg-black/50 transition-opacity" @click="open = false; document.getElementById('announcementsModal').classList.add('hidden')"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"></div>
        
        <!-- Modal Container -->
        <div class="relative bg-white dark:bg-gray-800 rounded-xl shadow-2xl w-full max-w-2xl max-h-[85vh] flex flex-col"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 transform scale-95"
             x-transition:enter-end="opacity-100 transform scale-100"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100 transform scale-100"
             x-transition:leave-end="opacity-0 transform scale-95">
            
            <!-- Header -->
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl flex items-center justify-center">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path>
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Announcements</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400"><?php echo count($userAnnouncements); ?> new announcement<?php echo count($userAnnouncements) > 1 ? 's' : ''; ?></p>
                    </div>
                </div>
                <button @click="open = false; document.getElementById('announcementsModal').classList.add('hidden')" 
                        class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 p-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            
            <!-- Announcements List -->
            <div id="announcementsContainer" class="flex-1 overflow-y-auto p-4 space-y-3">
                <?php foreach ($userAnnouncements as $announcement): ?>
                    <?php
                    $type = $announcement['announcement_type'] ?? 'info';
                    $priority = $announcement['priority'] ?? 'medium';
                    
                    $typeStyles = [
                        'info' => 'border-l-4 border-blue-500 bg-blue-50 dark:bg-blue-900/20',
                        'success' => 'border-l-4 border-green-500 bg-green-50 dark:bg-green-900/20',
                        'warning' => 'border-l-4 border-yellow-500 bg-yellow-50 dark:bg-yellow-900/20',
                        'error' => 'border-l-4 border-red-500 bg-red-50 dark:bg-red-900/20'
                    ];
                    
                    $priorityBadges = [
                        'low' => 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
                        'medium' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/50 dark:text-blue-300',
                        'high' => 'bg-orange-100 text-orange-700 dark:bg-orange-900/50 dark:text-orange-300',
                        'urgent' => 'bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-300'
                    ];
                    
                    $styleClass = $typeStyles[$type] ?? $typeStyles['info'];
                    $badgeClass = $priorityBadges[$priority] ?? $priorityBadges['medium'];
                    $isPinned = ($announcement['is_pinned'] ?? 0) == 1;
                    ?>
                    
                    <div class="announcement-item" data-announcement-id="<?php echo $announcement['announcement_id']; ?>">
                        <div class="<?php echo $styleClass; ?> rounded-lg p-4 relative">
                            <!-- Dismiss button -->
                            <button onclick="dismissAnnouncement(<?php echo $announcement['announcement_id']; ?>)" 
                                    class="absolute top-3 right-3 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 p-1 hover:bg-white/50 dark:hover:bg-gray-700/50 rounded transition-colors"
                                    title="Dismiss">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                </svg>
                            </button>
                            
                            <!-- Content -->
                            <div class="pr-8">
                                <!-- Title with badges -->
                                <div class="flex items-center gap-2 mb-2 flex-wrap">
                                    <?php if ($isPinned): ?>
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium bg-purple-100 text-purple-700 dark:bg-purple-900/50 dark:text-purple-300 rounded">
                                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                            <path d="M5 5a2 2 0 012-2h6a2 2 0 012 2v2H5V5zm0 4h10v7a2 2 0 01-2 2H7a2 2 0 01-2-2V9z"></path>
                                        </svg>
                                        Pinned
                                    </span>
                                    <?php endif; ?>
                                    <span class="inline-block px-2 py-0.5 text-xs font-medium rounded <?php echo $badgeClass; ?>">
                                        <?php echo strtoupper($priority); ?>
                                    </span>
                                </div>
                                
                                <h4 class="font-semibold text-gray-900 dark:text-gray-100 mb-2"><?php echo Security::escape($announcement['title']); ?></h4>
                                
                                <!-- Message -->
                                <p class="text-sm text-gray-700 dark:text-gray-300 whitespace-pre-wrap leading-relaxed"><?php echo nl2br(Security::escape($announcement['message'])); ?></p>
                                
                                <!-- Metadata -->
                                <div class="flex items-center gap-4 mt-3 text-xs text-gray-500 dark:text-gray-400">
                                    <span class="flex items-center gap-1">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                        </svg>
                                        <?php echo date('M d, Y', strtotime($announcement['created_at'])); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Footer -->
            <div class="flex items-center justify-between gap-3 px-6 py-4 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 rounded-b-xl">
                <p class="text-xs text-gray-500 dark:text-gray-400">Click <span class="font-medium">×</span> to dismiss individual announcements</p>
                <div class="flex gap-2">
                    <button @click="open = false; document.getElementById('announcementsModal').classList.add('hidden')" 
                            class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors">
                        Close
                    </button>
                    <button onclick="dismissAllAnnouncements()" 
                            class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 transition-colors">
                        Dismiss All
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
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
                element.style.transform = 'translateX(20px)';
                setTimeout(() => {
                    element.remove();
                    // Close modal if no more announcements
                    const container = document.getElementById('announcementsContainer');
                    if (container && container.children.length === 0) {
                        document.getElementById('announcementsModal').classList.add('hidden');
                    }
                }, 300);
            }
        }
    })
    .catch(err => console.error('Error dismissing announcement:', err));
}

function dismissAllAnnouncements() {
    const items = document.querySelectorAll('.announcement-item');
    const ids = Array.from(items).map(el => el.dataset.announcementId);
    
    // Dismiss all via API
    Promise.all(ids.map(id => 
        fetch('<?php echo BASE_URL; ?>/api/dismiss-announcement.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ announcement_id: parseInt(id) })
        })
    )).then(() => {
        document.getElementById('announcementsModal').classList.add('hidden');
    }).catch(err => console.error('Error dismissing announcements:', err));
}
</script>
<?php endif; ?>

</body>
</html>

<?php
$content = ob_get_clean();
echo $content;
?>
