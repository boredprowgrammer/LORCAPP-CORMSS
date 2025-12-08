<!DOCTYPE html>
<html lang="en">
<head>
    <?php
    // Generate nonce for inline scripts (CSP) - kept for backward compatibility
    $csp_nonce = base64_encode(random_bytes(16));
    
    // Content Security Policy - Balanced security with functionality
    // Note: 'unsafe-inline' and 'unsafe-eval' are required for Alpine.js and inline event handlers
    // Nonce is not used in script-src to allow 'unsafe-inline' to work
    // All CDN sources are explicitly whitelisted for maximum security
    $cspPolicy = "default-src 'self'; " .
        "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.tailwindcss.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; " .
        "script-src-elem 'self' 'unsafe-inline' https://cdn.tailwindcss.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; " .
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.tailwindcss.com; " .
        "font-src 'self' https://fonts.gstatic.com; " .
        "img-src 'self' data:; " .
        "connect-src 'self' https://cdnjs.cloudflare.com; " .
        "frame-ancestors 'none'; " .
        "base-uri 'self'; " .
        "form-action 'self'; " .
        "object-src 'none'; " .
        "upgrade-insecure-requests;";
    
    header("Content-Security-Policy: " . $cspPolicy);
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: DENY");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? Security::escape($pageTitle) . ' - ' : ''; ?><?php echo APP_NAME; ?></title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Heroicons -->
    <script src="https://cdn.jsdelivr.net/npm/heroicons@2.0.18/24/outline/index.js"></script>
    
    <!-- Inter Font -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Alpine.js for interactivity -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.3/dist/cdn.min.js"></script>
    
    <!-- Tailwind Config -->
    <script nonce="<?php echo $csp_nonce; ?>">
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: {
                            50: '#eff6ff',
                            100: '#dbeafe',
                            200: '#bfdbfe',
                            300: '#93c5fd',
                            400: '#60a5fa',
                            500: '#3b82f6',
                            600: '#2563eb',
                            700: '#1d4ed8',
                            800: '#1e40af',
                            900: '#1e3a8a',
                        },
                    },
                    fontFamily: {
                        sans: ['Inter', 'system-ui', 'sans-serif'],
                    },
                }
            }
        }
    </script>
    
    <!-- Custom Styles -->
    <style nonce="<?php echo $csp_nonce; ?>">
        [x-cloak] { display: none !important; }
        
        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
        }
        
        /* Smooth transitions */
        * {
            transition-property: background-color, border-color, color, fill, stroke, opacity, box-shadow, transform;
            transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
            transition-duration: 150ms;
        }
        
        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f3f4f6;
        }
        
        ::-webkit-scrollbar-thumb {
            background: #d1d5db;
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #9ca3af;
        }
        
        /* Input uppercase */
        input[type="text"],
        input[type="search"],
        input[type="email"],
        input[type="tel"],
        textarea,
        select {
            text-transform: uppercase;
        }

        input::placeholder, 
        textarea::placeholder {
            text-transform: uppercase;
            opacity: 0.5;
        }
        
        /* Fade in animation */
        @keyframes fadeIn {
            from { 
                opacity: 0; 
                transform: translateY(-10px); 
            }
            to { 
                opacity: 1; 
                transform: translateY(0); 
            }
        }
        
        .animate-fade-in {
            animation: fadeIn 0.3s ease-in;
        }
        
        /* Focus ring */
        .focus-ring:focus {
            outline: none;
            ring: 2px;
            ring-color: #3b82f6;
            ring-offset: 2px;
        }
        
        /* Simple Loading Overlay */
        #loadingOverlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.9);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 99999;
            flex-direction: column;
        }
        
        #loadingOverlay.active {
            display: flex !important;
        }
        
        /* Simple Spinner */
        .simple-spinner {
            width: 50px;
            height: 50px;
            border: 4px solid #e5e7eb;
            border-top: 4px solid #3b82f6;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .loading-text {
            color: #374151;
            font-size: 14px;
            font-weight: 500;
            margin-top: 16px;
        }
    </style>
    
    <?php if (isset($extraStyles)): ?>
        <?php echo $extraStyles; ?>
    <?php endif; ?>
<?php
// Include announcements helper for all logged in users
if (Security::isLoggedIn()) {
    require_once __DIR__ . '/announcements.php';
}
?>
</head>
<body class="bg-gray-50 text-gray-900 min-h-screen antialiased">
    
    <!-- Simple Loading Spinner Overlay -->
    <div id="loadingOverlay">
        <div class="simple-spinner"></div>
        <div class="loading-text">Loading...</div>
    </div>
    
    <?php if (Security::isLoggedIn()): ?>
        <?php $currentUser = getCurrentUser(); ?>
        
        <!-- Main Layout with Sidebar -->
        <div class="flex h-screen overflow-hidden">
            
            <!-- Sidebar - Desktop -->
            <aside class="hidden lg:flex lg:flex-shrink-0">
                <div class="flex flex-col w-64 bg-white border-r border-gray-200">
                    <!-- Logo -->
                    <div class="flex items-center justify-between h-16 px-6 border-b border-gray-200">
                        <div class="flex items-center space-x-3">
                            <div class="w-10 h-10 bg-blue-500 rounded-lg flex items-center justify-center">
                                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                                </svg>
                            </div>
                            <div>
                                <h2 class="text-lg font-semibold text-gray-900">LORCAPP</h2>
                                <p class="text-xs text-gray-500">CORRMS</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Navigation Menu -->
                    <nav class="flex-1 px-4 py-6 space-y-2 overflow-y-auto">
                        <a href="<?php echo BASE_URL; ?>/dashboard.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                            </svg>
                            Dashboard
                        </a>
                        
                        <div class="pt-4 pb-2">
                            <p class="px-4 text-xs font-semibold text-gray-400 uppercase tracking-wider">Officer Management</p>
                        </div>
                        
                        <?php if (hasPermission('can_view_officers')): ?>
                        <a href="<?php echo BASE_URL; ?>/officers/list.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], '/officers/list.php') !== false ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                            </svg>
                            Officers List
                        </a>
                        <?php endif; ?>
                        
                        <?php if (hasPermission('can_add_officers')): ?>
                        <a href="<?php echo BASE_URL; ?>/officers/add.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], '/officers/add.php') !== false ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path>
                            </svg>
                            Add Officer
                        </a>
                        <?php endif; ?>
                        
                        <?php if (hasPermission('can_transfer_in')): ?>
                        <a href="<?php echo BASE_URL; ?>/transfers/transfer-in.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], 'transfer-in.php') !== false ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"></path>
                            </svg>
                            Transfer In
                        </a>
                        <?php endif; ?>
                        
                        <?php if (hasPermission('can_transfer_out')): ?>
                        <a href="<?php echo BASE_URL; ?>/transfers/transfer-out.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], 'transfer-out.php') !== false ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16V4m0 0l4 4m-4-4l-4 4m-6 0v12m0 0l-4-4m4 4l4-4"></path>
                            </svg>
                            Transfer Out
                        </a>
                        <?php endif; ?>
                        
                        <?php if (hasPermission('can_remove_officers')): ?>
                        <a href="<?php echo BASE_URL; ?>/officers/removal-requests.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], '/officers/remove.php') !== false ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7a4 4 0 11-8 0 4 4 0 018 0zM9 14a6 6 0 00-6 6v1h12v-1a6 6 0 00-6-6zM21 12h-6"></path>
                            </svg>
                            Remove Officer
                        </a>
                        <?php endif; ?>
                        
                        <?php 
                        // Show Pending Actions link for:
                        // 1. Local (senior) accounts who approve actions
                        // 2. Local Limited accounts who submit actions for approval
                        $showPendingActions = false;
                        $pendingCount = 0;
                        
                        // Get database connection
                        $db = Database::getInstance()->getConnection();
                        
                        if ($currentUser['role'] === 'local') {
                            // Check if this local user is a senior approver for any local_limited users
                            $stmt = $db->prepare("SELECT COUNT(*) as count FROM users WHERE senior_approver_id = ? AND role = 'local_limited'");
                            $stmt->execute([$currentUser['user_id']]);
                            $result = $stmt->fetch();
                            if ($result['count'] > 0) {
                                $showPendingActions = true;
                                $pendingCount = getPendingActionsCount();
                            }
                        } elseif ($currentUser['role'] === 'local_limited') {
                            // Local limited users can see their own pending actions
                            $showPendingActions = true;
                            // Get count of their own pending actions
                            $stmt = $db->prepare("SELECT COUNT(*) as count FROM pending_actions WHERE requester_user_id = ? AND status = 'pending'");
                            $stmt->execute([$currentUser['user_id']]);
                            $result = $stmt->fetch();
                            $pendingCount = (int)($result['count'] ?? 0);
                        }
                        
                        if ($showPendingActions): 
                        ?>
                        <a href="<?php echo BASE_URL; ?>/pending-actions.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], 'pending-actions.php') !== false ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <span class="flex-1">Pending Actions</span>
                            <?php if ($pendingCount > 0): ?>
                                <span class="inline-flex items-center justify-center px-2 py-1 text-xs font-bold leading-none text-white bg-red-600 rounded-full">
                                    <?php echo $pendingCount; ?>
                                </span>
                            <?php endif; ?>
                        </a>
                        <?php endif; ?>
                        
                        <div class="pt-4 pb-2">
                            <p class="px-4 text-xs font-semibold text-gray-400 uppercase tracking-wider">Call-Up Slips</p>
                        </div>
                        
                        <a href="<?php echo BASE_URL; ?>/officers/call-up-list.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], '/officers/call-up') !== false ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                            View Call-Ups
                        </a>
                        
                        <a href="<?php echo BASE_URL; ?>/officers/call-up.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], '/officers/call-up.php') !== false && strpos($_SERVER['PHP_SELF'], 'call-up-list') === false ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                            </svg>
                            Create Call-Up
                        </a>
                        
                        <?php if (hasPermission('can_view_legacy_registry')): ?>
                        <div class="pt-4 pb-2">
                            <p class="px-4 text-xs font-semibold text-gray-400 uppercase tracking-wider">Legacy Registry</p>
                        </div>
                        
                        <a href="<?php echo BASE_URL; ?>/tarheta/list.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], '/tarheta/list.php') !== false ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                            </svg>
                            Tarheta Control
                        </a>
                        
                        <a href="<?php echo BASE_URL; ?>/legacy/list.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], '/legacy/list.php') !== false ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path>
                            </svg>
                            Legacy Control Numbers
                        </a>
                        <?php endif; ?>
                        
                        <?php if (hasPermission('can_add_officers')): ?>
                        <a href="<?php echo BASE_URL; ?>/tarheta/import.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], '/tarheta/import.php') !== false ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                            </svg>
                            Import CSV
                        </a>
                        <?php endif; ?>
                        
                        <?php if (hasPermission('can_view_requests')): ?>
                        <div class="pt-4 pb-2">
                            <p class="px-4 text-xs font-semibold text-gray-400 uppercase tracking-wider">Requests</p>
                        </div>
                        
                        <a href="<?php echo BASE_URL; ?>/requests/list.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], '/requests/') !== false ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                            Officer Requests
                        </a>
                        <?php endif; ?>
                        
                        <?php if (in_array($currentUser['role'], ['local', 'district'])): ?>
                        <div class="pt-4 pb-2">
                            <p class="px-4 text-xs font-semibold text-gray-400 uppercase tracking-wider">Communication</p>
                        </div>
                        
                        <a href="<?php echo BASE_URL; ?>/chat.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo basename($_SERVER['PHP_SELF']) === 'chat.php' ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
                            </svg>
                            Messages
                        </a>
                        <?php endif; ?>
                        
                        <?php if (hasAnyPermission(['can_view_reports', 'can_view_headcount', 'can_view_departments'])): ?>
                        <div class="pt-4 pb-2">
                            <p class="px-4 text-xs font-semibold text-gray-400 uppercase tracking-wider">Reports</p>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (hasPermission('can_view_headcount')): ?>
                        <a href="<?php echo BASE_URL; ?>/reports/headcount.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], 'headcount.php') !== false ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                            </svg>
                            Headcount Report
                        </a>
                        <?php endif; ?>
                        
                        <?php if (hasPermission('can_view_reports')): ?>
                        <a href="<?php echo BASE_URL; ?>/reports/masterlist.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], 'masterlist.php') !== false ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                            Masterlist
                        </a>
                        <?php endif; ?>
                        
                        <?php if (hasPermission('can_view_reports')): ?>
                        <a href="<?php echo BASE_URL; ?>/reports/lorc-lcrc-checker.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], 'lorc-lcrc-checker.php') !== false ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path>
                            </svg>
                            LORC/LCRC Checker
                        </a>
                        <?php endif; ?>
                        
                        <?php if (hasPermission('can_view_departments')): ?>
                        <a href="<?php echo BASE_URL; ?>/reports/departments.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], 'departments.php') !== false ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z"></path>
                            </svg>
                            Departments
                        </a>
                        <?php endif; ?>
                        
                        <?php if ($currentUser['role'] === 'admin'): ?>
                        <div class="pt-4 pb-2">
                            <p class="px-4 text-xs font-semibold text-gray-400 uppercase tracking-wider">Administration</p>
                        </div>
                        
                        <a href="<?php echo BASE_URL; ?>/admin/users.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], '/admin/users.php') !== false ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                            </svg>
                            Manage Users
                        </a>
                        
                        <a href="<?php echo BASE_URL; ?>/admin/announcements.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], '/admin/announcements.php') !== false ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path>
                            </svg>
                            Announcements
                        </a>
                        
                        <a href="<?php echo BASE_URL; ?>/admin/districts.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], '/admin/districts.php') !== false ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"></path>
                            </svg>
                            Districts & Locals
                        </a>
                        
                        <a href="<?php echo BASE_URL; ?>/admin/audit.php" 
                           class="flex items-center px-4 py-3 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], '/admin/audit.php') !== false ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100'; ?>">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            Audit Log
                        </a>
                        <?php endif; ?>
                    </nav>
                    
                    <!-- User Info Card -->
                    <div class="p-4 border-t border-gray-200">
                        <div class="bg-gray-50 rounded-lg p-4">
                            <p class="text-xs font-semibold text-gray-900 mb-1">Current Location</p>
                            <?php if ($currentUser['role'] === 'admin'): ?>
                                <p class="text-xs text-gray-600">All Districts</p>
                            <?php elseif ($currentUser['role'] === 'district'): ?>
                                <p class="text-xs text-gray-600"><?php echo Security::escape($currentUser['district_name']); ?></p>
                            <?php else: ?>
                                <p class="text-xs text-gray-600"><?php echo Security::escape($currentUser['local_name']); ?></p>
                                <p class="text-xs text-gray-500"><?php echo Security::escape($currentUser['district_name']); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </aside>
            
            <!-- Mobile Sidebar -->
            <div x-data="{ open: false }" x-cloak class="lg:hidden">
                <!-- Mobile Header Bar -->
                <div class="fixed top-0 left-0 right-0 z-40 bg-white border-b border-gray-200 px-4 py-3 flex items-center justify-between">
                    <button @click="open = true" type="button" class="p-2 -ml-2 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                        </svg>
                    </button>
                    <div class="flex items-center space-x-2">
                        <div class="w-8 h-8 bg-blue-500 rounded-lg flex items-center justify-center">
                            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                            </svg>
                        </div>
                        <span class="text-base font-semibold text-gray-900">LORCAPP</span>
                    </div>
                    <!-- Mobile quick actions (icons) -->
                    <div class="flex items-center space-x-2">
                        <?php if (!empty($pageActions) && is_array($pageActions)): ?>
                            <?php foreach ($pageActions as $actHtml): ?>
                                <?php
                                // Render a compact icon-only version by attempting to extract the href and svg from the provided HTML
                                // If not possible, render the full HTML but hide text on small screens
                                echo str_replace('hidden sm:inline', 'hidden', $actHtml);
                                ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <div class="w-10"></div>
                </div>
                
                <!-- Mobile Sidebar Overlay -->
                <div 
                    x-show="open" 
                    @click="open = false"
                    x-transition:enter="transition-opacity ease-linear duration-300"
                    x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100"
                    x-transition:leave="transition-opacity ease-linear duration-300"
                    x-transition:leave-start="opacity-100"
                    x-transition:leave-end="opacity-0"
                    class="fixed inset-0 bg-gray-900 bg-opacity-50 z-40"
                ></div>
                
                <!-- Mobile Sidebar Panel -->
                <div 
                    x-show="open" 
                    x-transition:enter="transition ease-in-out duration-300 transform"
                    x-transition:enter-start="-translate-x-full"
                    x-transition:enter-end="translate-x-0"
                    x-transition:leave="transition ease-in-out duration-300 transform"
                    x-transition:leave-start="translate-x-0"
                    x-transition:leave-end="-translate-x-full"
                    class="fixed inset-y-0 left-0 flex flex-col w-72 max-w-[85vw] bg-white shadow-2xl z-50"
                >
                    <!-- Sidebar Header -->
                    <div class="flex items-center justify-between h-16 px-4 border-b border-gray-200 flex-shrink-0">
                        <div class="flex items-center space-x-3">
                            <div class="w-10 h-10 bg-blue-500 rounded-lg flex items-center justify-center">
                                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                                </svg>
                            </div>
                            <div>
                                <h2 class="text-base font-semibold text-gray-900">LORCAPP</h2>
                                <p class="text-xs text-gray-500">CORRMS</p>
                            </div>
                        </div>
                        <button @click="open = false" type="button" class="p-2 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                    
                    <!-- User Info Card - Mobile -->
                    <div class="p-4 border-b border-gray-200 bg-gray-50 flex-shrink-0">
                        <div class="flex items-center space-x-3">
                            <div class="w-10 h-10 bg-blue-500 rounded-full flex items-center justify-center text-white font-medium">
                                <?php echo strtoupper(substr($currentUser['full_name'], 0, 1)); ?>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="text-sm font-medium text-gray-900 truncate"><?php echo Security::escape($currentUser['full_name']); ?></div>
                                <div class="text-xs text-gray-500"><?php echo ucfirst($currentUser['role']); ?></div>
                            </div>
                        </div>
                        <?php if ($currentUser['role'] !== 'admin'): ?>
                        <div class="mt-3 text-xs">
                            <div class="text-gray-500">Location:</div>
                            <div class="text-gray-900 font-medium"><?php echo Security::escape($currentUser['local_name'] ?? $currentUser['district_name']); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Navigation Menu - Scrollable -->
                    <nav class="flex-1 px-3 py-4 space-y-1 overflow-y-auto">
                        <?php require __DIR__ . '/mobile-nav.php'; ?>
                    </nav>
                    
                    <!-- Bottom Actions -->
                    <div class="p-3 border-t border-gray-200 space-y-1 flex-shrink-0">
                        <a href="<?php echo BASE_URL; ?>/settings.php" class="flex items-center px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-lg">
                            <svg class="w-5 h-5 mr-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            </svg>
                            Settings
                        </a>
                        <a href="<?php echo BASE_URL; ?>/logout.php" class="flex items-center px-3 py-2.5 text-sm font-medium text-red-600 hover:bg-red-50 rounded-lg">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                            </svg>
                            Logout
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Main Content -->
            <div class="flex-1 flex flex-col overflow-hidden pt-16 lg:pt-0">
                <!-- Navbar - Desktop only -->
                <header class="hidden lg:block bg-white shadow-sm border-b border-gray-200">
                    <div class="flex items-center justify-between h-16 px-6">
                        <div class="flex items-center">
                            <h1 class="text-xl font-semibold text-gray-900">
                                <?php echo isset($pageTitle) ? Security::escape($pageTitle) : 'Dashboard'; ?>
                            </h1>
                        </div>
                        
                        <!-- Clock and Week Number -->
                        <div class="flex items-center space-x-3 text-sm">
                            <div class="flex items-center space-x-2 px-3 py-1.5 bg-gray-50 rounded-lg border border-gray-200">
                                <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                </svg>
                                <span id="weekNumber" class="font-medium text-gray-700">Week --</span>
                            </div>
                            <div class="flex items-center space-x-2 px-3 py-1.5 bg-blue-50 rounded-lg border border-blue-200">
                                <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                <span id="currentTime" class="font-medium text-blue-700">--:--:-- --</span>
                            </div>
                        </div>
                        
                        <div class="flex items-center space-x-4">
                            <!-- Page Actions (set by pages) - visible on desktop -->
                            <?php if (!empty($pageActions) && is_array($pageActions)): ?>
                            <div class="hidden lg:flex items-center space-x-2 mr-4">
                                <?php foreach ($pageActions as $actHtml): ?>
                                    <?php echo $actHtml; ?>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            <!-- Notifications -->
                            <div x-data="{ open: false }" class="relative">
                                <button @click="open = !open" class="relative p-2 text-gray-500 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500 rounded-lg">
                                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
                                    </svg>
                                    <span class="absolute top-1 right-1 w-2 h-2 bg-blue-500 rounded-full"></span>
                                </button>
                            </div>
                            
                            <!-- User Menu -->
                            <div x-data="{ open: false }" class="relative">
                                <button @click="open = !open" class="flex items-center space-x-3 focus:outline-none focus:ring-2 focus:ring-blue-500 rounded-lg p-2">
                                    <div class="w-8 h-8 bg-blue-500 rounded-full flex items-center justify-center text-white font-medium text-sm">
                                        <?php echo strtoupper(substr($currentUser['full_name'], 0, 1)); ?>
                                    </div>
                                    <div class="hidden md:block text-left">
                                        <div class="text-sm font-medium text-gray-900"><?php echo Security::escape($currentUser['full_name']); ?></div>
                                        <div class="text-xs text-gray-500"><?php echo ucfirst($currentUser['role']); ?></div>
                                    </div>
                                    <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
                                    class="absolute right-0 mt-2 w-48 bg-white rounded-lg shadow-lg py-1 border border-gray-200 z-50"
                                    style="display: none;"
                                >
                                    <a href="<?php echo BASE_URL; ?>/profile.php" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                                        <svg class="w-4 h-4 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                        </svg>
                                        Profile
                                    </a>
                                    <a href="<?php echo BASE_URL; ?>/settings.php" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                                        <svg class="w-4 h-4 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                        </svg>
                                        Settings
                                    </a>
                                    <hr class="my-1 border-gray-200">
                                    <a href="<?php echo BASE_URL; ?>/logout.php" class="flex items-center px-4 py-2 text-sm text-red-600 hover:bg-red-50">
                                        <svg class="w-4 h-4 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                                        </svg>
                                        Logout
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </header>
                
                <!-- Page Content -->
                <main class="flex-1 overflow-x-hidden overflow-y-auto bg-gray-50 p-6">
                    <?php 
                    $flash = getFlashMessage();
                    if ($flash): 
                    ?>
                        <div class="mb-6 animate-fade-in">
                            <div class="rounded-lg p-4 <?php 
                                echo $flash['type'] === 'success' ? 'bg-green-50 text-green-800 border border-green-200' : 
                                    ($flash['type'] === 'error' ? 'bg-red-50 text-red-800 border border-red-200' : 
                                    ($flash['type'] === 'warning' ? 'bg-yellow-50 text-yellow-800 border border-yellow-200' : 
                                    'bg-blue-50 text-blue-800 border border-blue-200')); 
                            ?>">
                                <div class="flex items-center">
                                    <svg class="w-5 h-5 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                        <?php if ($flash['type'] === 'success'): ?>
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                        <?php elseif ($flash['type'] === 'error'): ?>
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                                        <?php else: ?>
                                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                                        <?php endif; ?>
                                    </svg>
                                    <span class="font-medium"><?php echo Security::escape($flash['message']); ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php echo $content ?? ''; ?>
                </main>
            </div>
        </div>
        
    <?php else: ?>
        <!-- Guest Layout (Login/Register Pages) -->
        <div class="min-h-screen flex items-center justify-center p-4 bg-gradient-to-br from-blue-50 to-indigo-100">
            <?php echo $content ?? ''; ?>
        </div>
    <?php endif; ?>
    
    <!-- Scripts -->
    <script nonce="<?php echo $csp_nonce; ?>">
        // Simple Loading Spinner Functions
        function showLoader(message = 'Loading...') {
            var overlay = document.getElementById('loadingOverlay');
            var textElement = overlay.querySelector('.loading-text');
            
            if (textElement) {
                textElement.textContent = message;
            }
            
            overlay.classList.add('active');
        }
        
        function hideLoader() {
            var overlay = document.getElementById('loadingOverlay');
            overlay.classList.remove('active');
        }
        
        // Loader Promise wrapper
        async function loaderPromise(promise, message = 'Loading...') {
            showLoader(message);
            try {
                const result = await promise;
                hideLoader();
                return result;
            } catch (error) {
                hideLoader();
                throw error;
            }
        }
        
        // Auto-show loader on form submissions and links
        document.addEventListener('DOMContentLoaded', function() {
            // Show loader on form submit
            document.querySelectorAll('form').forEach(function(form) {
                // Skip forms with data-no-loader attribute
                if (form.hasAttribute('data-no-loader')) return;
                
                form.addEventListener('submit', function(e) {
                    // Get custom loading message if provided
                    const loadingMessage = form.getAttribute('data-loading-message') || 'Processing...';
                    showLoader(loadingMessage);
                });
            });
            
            // Show loader on links that navigate away (with data-loader attribute)
            document.querySelectorAll('a[data-loader]').forEach(function(link) {
                link.addEventListener('click', function(e) {
                    const loadingMessage = link.getAttribute('data-loader') || 'Loading...';
                    showLoader(loadingMessage);
                });
            });
        });
        
        // Hide loader on errors
        window.addEventListener('error', function() {
            hideLoader();
        });
        
        // Mobile sidebar toggle
        function toggleMobileSidebar() {
            const mobileSidebar = document.querySelector('[x-data]');
            if (mobileSidebar) {
                // Trigger Alpine.js toggle
                mobileSidebar.__x.$data.open = !mobileSidebar.__x.$data.open;
            }
        }
        
        // Auto-dismiss alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.animate-fade-in');
            alerts.forEach(alert => {
                if (alert.parentElement && alert.parentElement.classList.contains('mb-6')) {
                    alert.style.transition = 'opacity 0.3s, transform 0.3s';
                    alert.style.opacity = '0';
                    alert.style.transform = 'translateY(-10px)';
                    setTimeout(() => alert.remove(), 300);
                }
            });
        }, 5000);
        
        // CSRF Token for AJAX requests
        const csrfToken = '<?php echo Security::generateCSRFToken(); ?>';
        
        // Global fetch wrapper with CSRF token and loader
        window.secureFetch = function(url, options = {}) {
            options.headers = options.headers || {};
            options.headers['X-CSRF-Token'] = csrfToken;
            
            // Show loader if not explicitly disabled
            if (!options.noLoader) {
                showLoader(options.loadingMessage || 'Loading...');
            }
            
            return fetch(url, options)
                .then(response => {
                    if (!options.noLoader) {
                        hideLoader();
                    }
                    return response;
                })
                .catch(error => {
                    if (!options.noLoader) {
                        hideLoader();
                    }
                    throw error;
                });
        };

        // Auto-uppercase form inputs on submit
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('form').forEach(function(form) {
                form.addEventListener('submit', function(e) {
                    try {
                        const elements = form.querySelectorAll('input, textarea, select');
                        elements.forEach(function(el) {
                            const type = (el.getAttribute('type') || '').toLowerCase();
                            // Skip elements that should not be uppercased
                            if (['hidden','password','file','checkbox','radio','submit','button','image'].includes(type)) return;
                            if (el.hasAttribute('data-preserve-case')) return;

                            if (el.tagName !== 'SELECT' && typeof el.value === 'string' && el.value.length > 0) {
                                el.value = el.value.toUpperCase();
                            }
                        });
                    } catch (err) {
                        console.error('Auto-uppercase error:', err);
                    }
                });
            });
        });

        // Live Clock and Week Number
        function updateClock() {
            const now = new Date();
            
            // Format time: HH:MM:SS AM/PM
            let hours = now.getHours();
            const minutes = String(now.getMinutes()).padStart(2, '0');
            const seconds = String(now.getSeconds()).padStart(2, '0');
            const ampm = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12;
            hours = hours ? hours : 12; // 0 becomes 12
            const timeString = `${hours}:${minutes}:${seconds} ${ampm}`;
            
            // Update time display
            const timeElement = document.getElementById('currentTime');
            if (timeElement) {
                timeElement.textContent = timeString;
            }
            
            // Calculate ISO week number
            const date = new Date(now.getTime());
            date.setHours(0, 0, 0, 0);
            // Thursday in current week decides the year
            date.setDate(date.getDate() + 3 - (date.getDay() + 6) % 7);
            // January 4 is always in week 1
            const week1 = new Date(date.getFullYear(), 0, 4);
            // Adjust to Thursday in week 1 and count number of weeks from date to week1
            const weekNum = 1 + Math.round(((date.getTime() - week1.getTime()) / 86400000 - 3 + (week1.getDay() + 6) % 7) / 7);
            
            // Update week number display
            const weekElement = document.getElementById('weekNumber');
            if (weekElement) {
                weekElement.textContent = `Week ${weekNum}`;
            }
        }
        
        // Update clock immediately and then every second
        document.addEventListener('DOMContentLoaded', function() {
            updateClock();
            setInterval(updateClock, 1000);
        });
    </script>
    
    <?php if (isset($extraScripts)): ?>
        <?php echo $extraScripts; ?>
    <?php endif; ?>
</body>
</html>
