<?php
/**
 * PNK Registry (Pagsamba ng Kabataan - Youth Worship Registry)
 * View and manage PNK members
 */

require_once __DIR__ . '/config/config.php';

Security::requireLogin();
requirePermission('can_view_reports');

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

// Similar access control as HDB
$needsAccessRequest = ($currentUser['role'] === 'local_cfo' || $currentUser['role'] === 'local_limited');
$hasApprovedAccess = false;
$approvedRequests = [];
$pendingRequests = [];

if ($needsAccessRequest) {
    $stmt = $db->prepare("
        SELECT * FROM pnk_access_requests 
        WHERE requester_user_id = ? 
        AND status = 'approved'
        AND deleted_at IS NULL
        AND is_locked = FALSE
        ORDER BY approval_date DESC
    ");
    $stmt->execute([$currentUser['user_id']]);
    $approvedRequests = $stmt->fetchAll();
    $hasApprovedAccess = count($approvedRequests) > 0;
    
    $stmt = $db->prepare("
        SELECT * FROM pnk_access_requests 
        WHERE requester_user_id = ? 
        AND status = 'pending'
        AND deleted_at IS NULL
        ORDER BY request_date DESC
    ");
    $stmt->execute([$currentUser['user_id']]);
    $pendingRequests = $stmt->fetchAll();
}

// Check for granular add/edit permissions from data_access table or approved requests
$hasAddAccess = false;
$hasEditAccess = false;
$hasViewAccess = false;

if ($currentUser['role'] === 'admin' || $currentUser['role'] === 'local') {
    // Admin and local have full access
    $hasAddAccess = true;
    $hasEditAccess = true;
    $hasViewAccess = true;
} elseif ($needsAccessRequest) {
    // Check pnk_data_access for active permissions
    $stmt = $db->prepare("
        SELECT can_view, can_add, can_edit FROM pnk_data_access 
        WHERE user_id = ? AND is_active = 1 
        AND (expires_at IS NULL OR expires_at >= CURDATE())
    ");
    $stmt->execute([$currentUser['user_id']]);
    $dataAccess = $stmt->fetch();
    
    if ($dataAccess) {
        $hasViewAccess = $dataAccess['can_view'] == 1;
        $hasAddAccess = $dataAccess['can_add'] == 1;
        $hasEditAccess = $dataAccess['can_edit'] == 1;
    }
    
    // Also check approved requests for add/edit types (backwards compatibility)
    foreach ($approvedRequests as $req) {
        $requestType = $req['request_type'] ?? 'view';
        if ($requestType === 'add' || $requestType === 'edit') {
            $hasAddAccess = true;
        }
        if ($requestType === 'edit') {
            $hasEditAccess = true;
        }
        if ($requestType === 'view') {
            $hasViewAccess = true;
        }
    }
}

$error = '';
$success = '';

// Get user's district and local info for Transfer-In modal and Dako Manager
$userDistrictCode = $currentUser['district_code'] ?? '';
$userLocalCode = $currentUser['local_code'] ?? '';
$userDistrictName = '';
$userLocalName = '';
$isAdmin = ($currentUser['role'] === 'admin');

// Get district name
if (!empty($userDistrictCode)) {
    $stmt = $db->prepare("SELECT district_name FROM districts WHERE district_code = ?");
    $stmt->execute([$userDistrictCode]);
    $districtRow = $stmt->fetch();
    $userDistrictName = $districtRow['district_name'] ?? '';
}

// Get local name
if (!empty($userLocalCode)) {
    $stmt = $db->prepare("SELECT local_name FROM local_congregations WHERE local_code = ?");
    $stmt->execute([$userLocalCode]);
    $localRow = $stmt->fetch();
    $userLocalName = $localRow['local_name'] ?? '';
}

$pageTitle = 'PNK Registry';
ob_start();
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900 dark:text-gray-100">PNK Registry <?php echo $needsAccessRequest && !$hasApprovedAccess ? '(Access Required)' : '(View)'; ?></h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Pagsamba ng Kabataan - Youth Worship Registry</p>
            </div>
            <?php if ($hasAddAccess || $hasEditAccess): ?>
            <!-- Action buttons: Show based on access permissions -->
            <div class="flex gap-2">
                <?php if ($hasAddAccess): ?>
                <a href="pnk-add.php" class="inline-flex items-center px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                    </svg>
                    Add PNK Member
                </a>
                <?php endif; ?>
                <?php if ($currentUser['role'] === 'admin' || $currentUser['role'] === 'local'): ?>
                <a href="pnk-import.php" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                    </svg>
                    Import CSV
                </a>
                <button onclick="showPNKTransferInModal()" class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16l-4-4m0 0l4-4m-4 4h18"></path>
                    </svg>
                    Transfer In
                </button>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400 px-4 py-3 rounded-lg">
            <?php echo Security::escape($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-400 px-4 py-3 rounded-lg">
            <?php echo Security::escape($success); ?>
        </div>
    <?php endif; ?>

    <?php if ($needsAccessRequest && !$hasApprovedAccess): ?>
    <!-- Access Request UI (similar to HDB) -->
    <div class="bg-yellow-50 dark:bg-yellow-900/30 border-l-4 border-yellow-500 p-6 rounded-r-lg">
        <div class="flex items-start">
            <svg class="w-6 h-6 text-yellow-500 mr-3 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"></path>
            </svg>
            <div class="flex-1">
                <h3 class="text-lg font-semibold text-yellow-800 dark:text-yellow-300">Access Required</h3>
                <p class="text-sm text-yellow-700 dark:text-yellow-400 mt-2">You need approval to access the PNK Registry.</p>
                <button onclick="openPnkAccessRequestModal()" class="mt-4 px-4 py-2 bg-yellow-600 text-white rounded-lg hover:bg-yellow-700 transition-colors">
                    <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"></path>
                    </svg>
                    Request Access
                </button>
            </div>
        </div>
    </div>
    
    <?php if (count($pendingRequests) > 0): ?>
    <div class="mt-4 bg-blue-50 dark:bg-blue-900/30 border-l-4 border-blue-500 p-4 rounded-r-lg">
        <div class="flex items-center">
            <svg class="w-5 h-5 text-blue-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <p class="text-sm text-blue-700 dark:text-blue-400">
                You have <?php echo count($pendingRequests); ?> pending access request(s).
            </p>
        </div>
    </div>
    <?php endif; ?>
    <?php else: ?>
    <!-- Main Content: Tabs Interface -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700">
        <!-- Tab Navigation -->
        <div class="border-b border-gray-200 dark:border-gray-700">
            <nav class="-mb-px flex flex-wrap" aria-label="Tabs">
                <button id="tab-search" onclick="switchTab('search')" class="tab-button active border-b-2 border-purple-600 text-purple-600 dark:text-purple-400 py-4 px-6 text-sm font-medium hover:text-purple-700 dark:hover:text-purple-300 transition-colors">
                    <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                    Search Records
                </button>
                <button id="tab-eligible" onclick="switchTab('eligible')" class="tab-button border-b-2 border-transparent text-gray-500 dark:text-gray-400 py-4 px-6 text-sm font-medium hover:text-gray-700 dark:hover:text-gray-300 transition-colors">
                    <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    Eligible for R3-01 (12+ years)
                </button>
                <button id="tab-r301" onclick="switchTab('r301')" class="tab-button border-b-2 border-transparent text-gray-500 dark:text-gray-400 py-4 px-6 text-sm font-medium hover:text-gray-700 dark:hover:text-gray-300 transition-colors">
                    <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    R3-01 Members
                </button>
                <?php if ($currentUser['role'] === 'admin' || $currentUser['role'] === 'local'): ?>
                <button id="tab-dako" onclick="switchTab('dako')" class="tab-button border-b-2 border-transparent text-gray-500 dark:text-gray-400 py-4 px-6 text-sm font-medium hover:text-gray-700 dark:hover:text-gray-300 transition-colors">
                    <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                    </svg>
                    Dako Manager
                </button>
                <button id="tab-pending" onclick="switchTab('pending')" class="tab-button border-b-2 border-transparent text-gray-500 dark:text-gray-400 py-4 px-6 text-sm font-medium hover:text-gray-700 dark:hover:text-gray-300 transition-colors">
                    <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path>
                    </svg>
                    Pending Verification
                    <span id="pnkPendingBadge" class="hidden ml-2 px-2 py-0.5 text-xs bg-yellow-500 text-white rounded-full">0</span>
                </button>
                <?php endif; ?>
            </nav>
        </div>

        <!-- Tab Content: Search -->
        <div id="content-search" class="tab-content p-6">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Search PNK Registry</h2>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Member Name</label>
                    <input type="text" id="searchName" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-purple-500" placeholder="Enter name">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Registry Number</label>
                    <input type="text" id="searchRegistry" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-purple-500" placeholder="Registry #">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Status</label>
                    <select id="searchStatus" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-purple-500">
                        <option value="">All Statuses</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="transferred-out">Transferred Out</option>
                        <option value="baptized">Baptized</option>
                    </select>
                </div>
            </div>
            <div class="mt-4 flex gap-2">
                <button onclick="searchPNK()" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors">
                    <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                    Search
                </button>
                <button onclick="clearSearch()" class="px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors">
                    Clear
                </button>
            </div>

            <div id="resultsContainer" class="mt-6">
                <p class="text-gray-500 dark:text-gray-400 text-center py-8">Enter search criteria to view PNK records</p>
            </div>
        </div>

        <!-- Tab Content: Eligible for R3-01 -->
        <div id="content-eligible" class="tab-content hidden p-6">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Eligible for R3-01</h2>
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-6">PNK members who are 12 years or older and eligible for baptismal preparation (R3-01 form).</p>
            
            <div id="eligibleBaptismContainer">
                <div class="text-center py-8">
                    <svg class="w-8 h-8 mx-auto text-gray-400 dark:text-gray-600 animate-spin mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <p class="text-gray-500 dark:text-gray-400">Loading...</p>
                </div>
            </div>
        </div>

        <!-- Tab Content: R3-01 Members -->
        <div id="content-r301" class="tab-content hidden p-6">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">R3-01 Members</h2>
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-6">Members enrolled in baptismal preparation classes.</p>
            
            <div id="r301Container">
                <div class="text-center py-8">
                    <svg class="w-8 h-8 mx-auto text-gray-400 dark:text-gray-600 animate-spin mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <p class="text-gray-500 dark:text-gray-400">Loading...</p>
                </div>
            </div>
        </div>

        <?php if ($currentUser['role'] === 'admin' || $currentUser['role'] === 'local'): ?>
        <!-- Tab Content: Dako Manager -->
        <div id="content-dako" class="tab-content hidden p-6">
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Dako Manager</h2>
                    <p class="text-sm text-gray-600 dark:text-gray-400">Manage Dako (Chapter/Group) entries for PNK members</p>
                </div>
                <button onclick="showAddDakoModal()" class="inline-flex items-center px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                    </svg>
                    Add Dako
                </button>
            </div>
            
            <?php
            // Get districts for admin
            $districts = [];
            if ($isAdmin) {
                $stmt = $db->query("SELECT district_code, district_name FROM districts ORDER BY district_name");
                $districts = $stmt->fetchAll();
            }
            ?>
            
            <!-- Dako Location Info -->
            <?php if (!$isAdmin): ?>
            <!-- Non-admin: Show frozen location -->
            <div class="bg-purple-50 dark:bg-purple-900/20 border border-purple-200 dark:border-purple-800 rounded-lg p-4 mb-6">
                <div class="flex items-center gap-2 mb-2">
                    <svg class="w-5 h-5 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                    </svg>
                    <span class="font-medium text-purple-800 dark:text-purple-300">Your Location</span>
                </div>
                <p class="text-purple-700 dark:text-purple-400">
                    <strong><?php echo Security::escape($userLocalName); ?></strong>
                    <span class="text-purple-500 dark:text-purple-500"> — <?php echo Security::escape($userDistrictName); ?></span>
                </p>
                <input type="hidden" id="dakoDistrictFilter" value="<?php echo Security::escape($userDistrictCode); ?>">
                <input type="hidden" id="dakoLocalFilter" value="<?php echo Security::escape($userLocalCode); ?>">
            </div>
            <?php else: ?>
            <!-- Admin: Show selectable dropdowns -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">District</label>
                    <select id="dakoDistrictFilter" onchange="loadDakoLocals()" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg">
                        <option value="">Select District</option>
                        <?php foreach ($districts as $district): ?>
                            <option value="<?php echo Security::escape($district['district_code']); ?>"><?php echo Security::escape($district['district_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Local Congregation</label>
                    <select id="dakoLocalFilter" onchange="loadDakoList()" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg">
                        <option value="">Select District First</option>
                    </select>
                </div>
            </div>
            <?php endif; ?>

            <!-- Dako List -->
            <div id="dakoListContainer">
                <?php if (!$isAdmin): ?>
                <div class="text-center py-8">
                    <svg class="w-8 h-8 mx-auto text-gray-400 dark:text-gray-600 animate-spin mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <p class="text-gray-500 dark:text-gray-400">Loading...</p>
                </div>
                <?php else: ?>
                <p class="text-gray-500 dark:text-gray-400 text-center py-8">Select a local congregation to view Dako entries</p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Pending Verification Tab Content -->
        <div id="content-pending" class="tab-content hidden p-6">
            <div class="mb-6">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Pending Verification</h2>
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">Records submitted by users that require LORC verification before being added/updated</p>
            </div>
            
            <!-- Stepper Legend -->
            <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4 mb-6">
                <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Verification Workflow</h3>
                <div class="flex items-center justify-center">
                    <div class="flex items-center">
                        <!-- Step 1: Submitted -->
                        <div class="flex flex-col items-center">
                            <div class="w-10 h-10 rounded-full bg-purple-500 flex items-center justify-center">
                                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                            </div>
                            <span class="mt-2 text-xs font-medium text-gray-600 dark:text-gray-400">SUBMITTED</span>
                        </div>
                        <!-- Connector -->
                        <div class="w-24 h-1 bg-gray-300 dark:bg-gray-600 mx-2"></div>
                        <!-- Step 2: Pending LORC Check -->
                        <div class="flex flex-col items-center">
                            <div class="w-10 h-10 rounded-full bg-yellow-500 flex items-center justify-center">
                                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                            </div>
                            <span class="mt-2 text-xs font-medium text-gray-600 dark:text-gray-400">PENDING LORC CHECK</span>
                        </div>
                        <!-- Connector -->
                        <div class="w-24 h-1 bg-gray-300 dark:bg-gray-600 mx-2"></div>
                        <!-- Step 3: Verified -->
                        <div class="flex flex-col items-center">
                            <div class="w-10 h-10 rounded-full bg-green-500 flex items-center justify-center">
                                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                </svg>
                            </div>
                            <span class="mt-2 text-xs font-medium text-green-600 dark:text-green-400 font-semibold">(VERIFIED)</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Pending Verifications List -->
            <div id="pnkPendingVerificationsContainer">
                <div class="flex flex-col items-center justify-center py-12">
                    <div class="relative w-16 h-16">
                        <div class="absolute inset-0 border-4 border-purple-200 dark:border-purple-800 rounded-full"></div>
                        <div class="absolute inset-0 border-4 border-purple-600 dark:border-purple-400 rounded-full border-t-transparent animate-spin"></div>
                    </div>
                    <p class="mt-4 text-sm text-gray-600 dark:text-gray-400">Loading pending verifications...</p>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Add Dako Modal -->
<div id="addDakoModal" class="hidden fixed inset-0 bg-gray-900 bg-opacity-50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-md w-full p-6">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4 flex items-center">
            <svg class="w-6 h-6 mr-2 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
            </svg>
            Add Dako
        </h3>
        <form id="addDakoForm" class="space-y-4">
            <?php if (!$isAdmin): ?>
            <!-- Non-admin: Show frozen location -->
            <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-3">
                <p class="text-sm text-gray-600 dark:text-gray-400">Location:</p>
                <p class="font-medium text-gray-900 dark:text-gray-100"><?php echo Security::escape($userLocalName); ?> — <?php echo Security::escape($userDistrictName); ?></p>
                <input type="hidden" id="addDakoDistrict" value="<?php echo Security::escape($userDistrictCode); ?>">
                <input type="hidden" id="addDakoLocal" value="<?php echo Security::escape($userLocalCode); ?>">
            </div>
            <?php else: ?>
            <!-- Admin: Show selectable dropdowns -->
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">District <span class="text-red-500">*</span></label>
                <select id="addDakoDistrict" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg">
                    <option value="">Select District</option>
                    <?php foreach ($districts as $district): ?>
                        <option value="<?php echo Security::escape($district['district_code']); ?>"><?php echo Security::escape($district['district_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Local Congregation <span class="text-red-500">*</span></label>
                <select id="addDakoLocal" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg">
                    <option value="">Select District First</option>
                </select>
            </div>
            <?php endif; ?>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Dako Name <span class="text-red-500">*</span></label>
                <input type="text" id="addDakoName" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg" placeholder="Enter Dako name">
            </div>
            <div class="flex justify-end gap-3 pt-4">
                <button type="button" onclick="closeAddDakoModal()" class="px-4 py-2 text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors">
                    Add Dako
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Confirmation Modal -->
<div id="confirmationModal" class="hidden fixed inset-0 bg-gray-900 bg-opacity-50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-md w-full transform transition-all">
        <div class="p-6">
            <div class="flex items-start">
                <div id="modalIcon" class="flex-shrink-0 w-12 h-12 rounded-full flex items-center justify-center mr-4">
                    <!-- Icon will be inserted here -->
                </div>
                <div class="flex-1">
                    <h3 id="modalTitle" class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">
                        <!-- Title will be inserted here -->
                    </h3>
                    <p id="modalMessage" class="text-sm text-gray-600 dark:text-gray-400">
                        <!-- Message will be inserted here -->
                    </p>
                </div>
            </div>
        </div>
        <div class="bg-gray-50 dark:bg-gray-700 px-6 py-4 flex justify-end gap-3 rounded-b-lg">
            <button onclick="closeConfirmationModal()" class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-600 border border-gray-300 dark:border-gray-500 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-500 transition-colors">
                Cancel
            </button>
            <button id="modalConfirmButton" onclick="confirmModalAction()" class="px-4 py-2 text-sm font-medium text-white rounded-lg transition-colors">
                <!-- Button text will be set dynamically -->
            </button>
        </div>
    </div>
</div>

<!-- PNK Transfer-In Modal -->
<div id="pnkTransferInModal" class="hidden fixed inset-0 bg-gray-900 bg-opacity-50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-lg max-w-2xl w-full p-6 shadow-xl">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4 flex items-center">
            <svg class="w-6 h-6 mr-2 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16l-4-4m0 0l4-4m-4 4h18"></path>
            </svg>
            Transfer PNK Member In
        </h3>
        <form id="pnkTransferInForm" class="space-y-4">
            <!-- Destination Location (auto-set and frozen) -->
            <div class="bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 rounded-lg p-4 mb-4">
                <div class="flex items-center">
                    <svg class="w-5 h-5 text-green-600 dark:text-green-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                    </svg>
                    <div>
                        <p class="text-xs text-green-600 dark:text-green-400 font-medium uppercase">Transferring To</p>
                        <p class="font-medium text-gray-900 dark:text-gray-100"><?php echo Security::escape($userLocalName); ?> — <?php echo Security::escape($userDistrictName); ?></p>
                    </div>
                </div>
                <input type="hidden" name="district_code" value="<?php echo Security::escape($userDistrictCode); ?>">
                <input type="hidden" name="local_code" value="<?php echo Security::escape($userLocalCode); ?>">
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Member Information -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">First Name *</label>
                    <input type="text" name="first_name" required 
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-green-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Middle Name</label>
                    <input type="text" name="middle_name" 
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-green-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Last Name *</label>
                    <input type="text" name="last_name" required 
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-green-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Date of Birth *</label>
                    <input type="date" name="birthday" required 
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-green-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Father's Name</label>
                    <input type="text" name="father_name" 
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-green-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Mother's Name</label>
                    <input type="text" name="mother_name" 
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-green-500">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Address</label>
                    <input type="text" name="address" 
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-green-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Dako (Chapter/Group)</label>
                    <input type="text" name="dako" 
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-green-500"
                           placeholder="e.g., Dako 1, Grupo A">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Status</label>
                    <select name="baptism_status" 
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-green-500">
                        <option value="active">Active</option>
                        <option value="r301">R3-01</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Attendance Status</label>
                    <select name="attendance_status" 
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-green-500">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
            
            <!-- Transfer Information -->
            <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
                <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-3">Transfer Information</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Transferring From Local *</label>
                        <input type="text" name="transfer_from" required 
                               class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-green-500"
                               placeholder="e.g., Local 1, Manila">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Transfer Date</label>
                        <input type="date" name="transfer_date" 
                               class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-green-500"
                               value="<?php echo date('Y-m-d'); ?>">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Transfer From District</label>
                    <input type="text" name="transfer_from_district" 
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-green-500"
                           placeholder="e.g., Manila District">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Transfer Reason</label>
                    <textarea name="transfer_reason" rows="3" 
                              class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-green-500"
                              placeholder="e.g., Family relocation, Transferred from another congregation"></textarea>
                </div>
            </div>
            
            <div class="flex gap-2 pt-4">
                <button type="submit" class="flex-1 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors">
                    Complete Transfer
                </button>
                <button type="button" onclick="closePNKTransferInModal()" class="flex-1 px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Confirmation Modal Functions
let confirmationCallback = null;

function showConfirmationModal(options) {
    const modal = document.getElementById('confirmationModal');
    const icon = document.getElementById('modalIcon');
    const title = document.getElementById('modalTitle');
    const message = document.getElementById('modalMessage');
    const confirmButton = document.getElementById('modalConfirmButton');
    
    // Set icon
    const iconColors = {
        'warning': 'bg-yellow-100 dark:bg-yellow-900/30',
        'danger': 'bg-red-100 dark:bg-red-900/30',
        'success': 'bg-green-100 dark:bg-green-900/30',
        'info': 'bg-blue-100 dark:bg-blue-900/30'
    };
    
    const iconSvgs = {
        'warning': '<svg class="w-6 h-6 text-yellow-600 dark:text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>',
        'danger': '<svg class="w-6 h-6 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>',
        'success': '<svg class="w-6 h-6 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>',
        'info': '<svg class="w-6 h-6 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>'
    };
    
    const buttonColors = {
        'warning': 'bg-yellow-600 hover:bg-yellow-700 dark:bg-yellow-500 dark:hover:bg-yellow-600',
        'danger': 'bg-red-600 hover:bg-red-700 dark:bg-red-500 dark:hover:bg-red-600',
        'success': 'bg-green-600 hover:bg-green-700 dark:bg-green-500 dark:hover:bg-green-600',
        'info': 'bg-blue-600 hover:bg-blue-700 dark:bg-blue-500 dark:hover:bg-blue-600'
    };
    
    const type = options.type || 'info';
    
    icon.className = `flex-shrink-0 w-12 h-12 rounded-full flex items-center justify-center mr-4 ${iconColors[type]}`;
    icon.innerHTML = iconSvgs[type];
    title.textContent = options.title || 'Confirm Action';
    message.textContent = options.message || 'Are you sure you want to proceed?';
    confirmButton.textContent = options.confirmText || 'Confirm';
    confirmButton.className = `px-4 py-2 text-sm font-medium text-white rounded-lg transition-colors ${buttonColors[type]}`;
    
    confirmationCallback = options.onConfirm;
    
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeConfirmationModal() {
    const modal = document.getElementById('confirmationModal');
    modal.classList.add('hidden');
    document.body.style.overflow = '';
    confirmationCallback = null;
}

function confirmModalAction() {
    if (confirmationCallback) {
        confirmationCallback();
    }
    closeConfirmationModal();
}

// Close modal on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeConfirmationModal();
    }
});

// Close modal on backdrop click
document.getElementById('confirmationModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeConfirmationModal();
    }
});

// Tab switching
function switchTab(tabName) {
    // Hide all tab contents
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.add('hidden');
    });
    
    // Remove active class from all tabs
    document.querySelectorAll('.tab-button').forEach(button => {
        button.classList.remove('active', 'border-purple-600', 'text-purple-600', 'dark:text-purple-400');
        button.classList.add('border-transparent', 'text-gray-500', 'dark:text-gray-400');
    });
    
    // Show selected tab content
    document.getElementById('content-' + tabName).classList.remove('hidden');
    
    // Add active class to selected tab
    const activeTab = document.getElementById('tab-' + tabName);
    activeTab.classList.add('active', 'border-purple-600', 'text-purple-600', 'dark:text-purple-400');
    activeTab.classList.remove('border-transparent', 'text-gray-500', 'dark:text-gray-400');
    
    // Load data for eligible baptism tab when switched
    if (tabName === 'eligible' && !window.eligibleBaptismLoaded) {
        loadEligibleForBaptism();
        window.eligibleBaptismLoaded = true;
    }
    
    // Load data for R3-01 tab when switched
    if (tabName === 'r301' && !window.r301Loaded) {
        loadR301Members();
        window.r301Loaded = true;
    }
    
    // Load Dako list for non-admin users when switching to dako tab
    if (tabName === 'dako' && !window.dakoLoaded) {
        const isAdmin = <?php echo $isAdmin ? 'true' : 'false'; ?>;
        if (!isAdmin) {
            // Auto-load for non-admin users
            loadDakoList();
        }
        window.dakoLoaded = true;
    }
    
    // Load pending verifications when switched
    if (tabName === 'pending' && !window.pnkPendingLoaded) {
        loadPnkPendingVerifications();
        window.pnkPendingLoaded = true;
    }
}

// Load PNK members eligible for baptism (12+ years old)
async function loadEligibleForBaptism() {
    const container = document.getElementById('eligibleBaptismContainer');
    
    try {
        const response = await fetch('api/get-eligible-for-baptism.php');
        const data = await response.json();
        
        if (!data.success) {
            throw new Error(data.error || 'Failed to load eligible members');
        }
        
        if (data.results.length === 0) {
            container.innerHTML = `
                <div class="text-center py-12">
                    <svg class="w-16 h-16 mx-auto text-gray-300 dark:text-gray-600 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                    </svg>
                    <p class="text-gray-500 dark:text-gray-400 font-medium">No members eligible for baptism</p>
                    <p class="text-sm text-gray-400 dark:text-gray-500 mt-1">Members must be 12 years or older</p>
                </div>
            `;
            return;
        }
        
        let html = `
            <div class="mb-4 flex items-center justify-between">
                <p class="text-sm text-gray-600 dark:text-gray-400">Found ${data.count} eligible member(s)</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Member Name</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Age</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Father's Name</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Mother's Name</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Local</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
        `;
        
        data.results.forEach(member => {
            const fullName = [member.first_name, member.middle_name, member.last_name].filter(Boolean).join(' ');
            html += `
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                    <td class="px-4 py-3">
                        <div class="text-sm font-medium text-gray-900 dark:text-gray-100">${fullName}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">${member.registry_number}</div>
                    </td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-400">
                            ${member.age} years old
                        </span>
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">${member.father_name}</td>
                    <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">${member.mother_name}</td>
                    <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">${member.local_name || member.local_code}</td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-2">
                            <a href="pnk-view.php?id=${member.id}" class="text-purple-600 dark:text-purple-400 hover:text-purple-800 dark:hover:text-purple-300 text-sm font-medium">
                                View
                            </a>
                            <button onclick="promoteToR301(${member.id}, '${fullName}')" class="text-green-600 dark:text-green-400 hover:text-green-800 dark:hover:text-green-300 text-sm font-medium">
                                Promote to R3-01
                            </button>
                        </div>
                    </td>
                </tr>
            `;
        });
        
        html += `
                    </tbody>
                </table>
            </div>
        `;
        
        container.innerHTML = html;
        
    } catch (error) {
        console.error('Error loading eligible members:', error);
        container.innerHTML = `
            <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
                <div class="flex items-center">
                    <svg class="w-5 h-5 text-red-600 dark:text-red-400 mr-3" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                    </svg>
                    <span class="text-sm text-red-800 dark:text-red-400">${error.message}</span>
                </div>
            </div>
        `;
    }
}

// Promote member to R3-01
async function promoteToR301(pnkId, memberName) {
    showConfirmationModal({
        type: 'info',
        title: 'Enroll in R3-01',
        message: `Enroll ${memberName} in R3-01 (Baptismal Preparation) classes?`,
        confirmText: 'Enroll',
        onConfirm: async () => {
            await executePromoteToR301(pnkId, memberName);
        }
    });
}

async function executePromoteToR301(pnkId, memberName) {
    try {
        const response = await fetch('api/promote-to-r301.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ pnk_id: pnkId })
        });
        
        const data = await response.json();
        
        if (!data.success) {
            throw new Error(data.error || 'Failed to enroll in R3-01');
        }
        
        showConfirmationModal({
            type: 'success',
            title: 'Success',
            message: data.message || 'Successfully enrolled in R3-01!',
            confirmText: 'OK',
            onConfirm: () => {}
        });
        
        // Reload eligible list
        window.eligibleBaptismLoaded = false;
        loadEligibleForBaptism();
        
        // Reload R3-01 list if it was loaded
        if (window.r301Loaded) {
            window.r301Loaded = false;
            loadR301Members();
        }
    } catch (error) {
        console.error('Error promoting to R3-01:', error);
        showConfirmationModal({
            type: 'danger',
            title: 'Error',
            message: error.message || 'Failed to enroll in R3-01',
            confirmText: 'OK',
            onConfirm: () => {}
        });
    }
}

// Load R3-01 members
async function loadR301Members() {
    const container = document.getElementById('r301Container');
    
    try {
        const response = await fetch('api/get-r301-members.php');
        const data = await response.json();
        
        if (!data.success) {
            throw new Error(data.error || 'Failed to load R3-01 members');
        }
        
        if (data.results.length === 0) {
            container.innerHTML = `
                <div class="text-center py-12">
                    <svg class="w-16 h-16 mx-auto text-gray-300 dark:text-gray-600 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    <p class="text-gray-500 dark:text-gray-400 font-medium">No R3-01 members yet</p>
                    <p class="text-sm text-gray-400 dark:text-gray-500 mt-1">Members enrolled in baptismal preparation will appear here</p>
                </div>
            `;
            return;
        }
        
        let html = `
            <div class="mb-4 flex items-center justify-between">
                <p class="text-sm text-gray-600 dark:text-gray-400">Found ${data.count} R3-01 member(s)</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Member Name</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Age</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">R3-01 Date</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Local</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
        `;
        
        data.results.forEach(member => {
            const fullName = [member.first_name, member.middle_name, member.last_name].filter(Boolean).join(' ');
            
            html += `
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                    <td class="px-4 py-3">
                        <div class="text-sm font-medium text-gray-900 dark:text-gray-100">${fullName}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">${member.registry_number}</div>
                    </td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-400">
                            ${member.age} years old
                        </span>
                    </td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-400">
                            Candidate
                        </span>
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">${member.r301_date || 'N/A'}</td>
                    <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">${member.local_name || member.local_code}</td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-2">
                            <a href="pnk-view.php?id=${member.id}" class="text-purple-600 dark:text-purple-400 hover:text-purple-800 dark:hover:text-purple-300 text-sm font-medium">
                                View
                            </a>
                            <button onclick="baptizeAndPromote(${member.id}, '${fullName}')" class="text-green-600 dark:text-green-400 hover:text-green-800 dark:hover:text-green-300 text-sm font-medium">
                                Baptize
                            </button>
                        </div>
                    </td>
                </tr>
            `;
        });
        
        html += `
                    </tbody>
                </table>
            </div>
        `;
        
        container.innerHTML = html;
        
    } catch (error) {
        console.error('Error loading R3-01 members:', error);
        container.innerHTML = `
            <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
                <div class="flex items-center">
                    <svg class="w-5 h-5 text-red-600 dark:text-red-400 mr-3" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                    </svg>
                    <span class="text-sm text-red-800 dark:text-red-400">${error.message}</span>
                </div>
            </div>
        `;
    }
}

// Mark member as ready for baptism (simplified - just updates timestamp)
async function markForBaptism(pnkId, memberName) {
    // This function is now unused since we go directly from Candidate to Baptized
    // Keeping for backwards compatibility but it just updates timestamp
    showConfirmationModal({
        type: 'info',
        title: 'Note',
        message: `${memberName} is already a candidate for baptism. Use the "Baptize" button when ready to perform baptism.`,
        confirmText: 'OK',
        onConfirm: () => {}
    });
}

async function executeMarkForBaptism(pnkId, memberName) {
    // Deprecated - no longer needed
}

// Baptize member and promote to Tarheta
async function baptizeAndPromote(pnkId, memberName) {
    showConfirmationModal({
        type: 'warning',
        title: 'Baptize Member',
        message: `Baptize ${memberName} and promote to Tarheta registry? This will mark the candidate as baptized and move them from PNK to Tarheta.`,
        confirmText: 'Baptize & Promote',
        onConfirm: async () => {
            await executeBaptizeAndPromote(pnkId, memberName);
        }
    });
}

async function executeBaptizeAndPromote(pnkId, memberName) {
    
    try {
        const response = await fetch('api/baptize-and-promote.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ pnk_id: pnkId })
        });
        
        const data = await response.json();
        
        if (!data.success) {
            throw new Error(data.error || 'Failed to baptize and promote');
        }
        
        showConfirmationModal({
            type: 'success',
            title: 'Success',
            message: data.message || 'Successfully baptized and promoted to Tarheta!',
            confirmText: 'OK',
            onConfirm: () => {}
        });
        
        // Reload R3-01 list
        window.r301Loaded = false;
        loadR301Members();
    } catch (error) {
        console.error('Error baptizing member:', error);
        showConfirmationModal({
            type: 'danger',
            title: 'Error',
            message: error.message || 'Failed to baptize member',
            confirmText: 'OK',
            onConfirm: () => {}
        });
    }
}

async function searchPNK() {
    const name = document.getElementById('searchName').value.trim();
    const registry = document.getElementById('searchRegistry').value.trim();
    const status = document.getElementById('searchStatus').value;
    const container = document.getElementById('resultsContainer');
    
    // Build query params
    const params = new URLSearchParams();
    if (name) params.append('name', name);
    if (registry) params.append('registry', registry);
    if (status) params.append('status', status);
    
    // Require at least one search criteria
    if (!name && !registry && !status) {
        container.innerHTML = '<p class="text-gray-500 dark:text-gray-400 text-center py-8">Enter search criteria to view PNK records</p>';
        return;
    }
    
    // Show loading
    container.innerHTML = `
        <div class="text-center py-8">
            <svg class="w-8 h-8 mx-auto text-purple-500 animate-spin mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <p class="text-gray-500 dark:text-gray-400">Searching...</p>
        </div>
    `;
    
    try {
        const response = await fetch('api/search-pnk.php?' + params.toString());
        const data = await response.json();
        
        if (!data.success) {
            throw new Error(data.error || 'Search failed');
        }
        
        if (data.records.length === 0) {
            container.innerHTML = '<p class="text-gray-500 dark:text-gray-400 text-center py-8">No records found matching your criteria.</p>';
            return;
        }
        
        // Render results table
        let html = `
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Name</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Registry #</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Age</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Sex</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Category</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
        `;
        
        data.records.forEach(record => {
            const statusBadge = getStatusBadge(record.attendance_status, record.baptism_status);
            html += `
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">${escapeHtml(record.full_name)}</td>
                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">${escapeHtml(record.registry_number)}</td>
                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">${record.age || '-'}</td>
                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">${escapeHtml(record.sex)}</td>
                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">${escapeHtml(record.pnk_category || '-')}</td>
                    <td class="px-4 py-3 whitespace-nowrap">${statusBadge}</td>
                    <td class="px-4 py-3 whitespace-nowrap text-sm">
                        <a href="pnk-view.php?id=${record.id}" class="text-purple-600 hover:text-purple-800 dark:text-purple-400 dark:hover:text-purple-300 mr-3">View</a>
                    </td>
                </tr>
            `;
        });
        
        html += `
                    </tbody>
                </table>
            </div>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-4">Found ${data.total} record(s)</p>
        `;
        
        container.innerHTML = html;
        
    } catch (error) {
        console.error('Error searching PNK:', error);
        container.innerHTML = '<p class="text-red-500 text-center py-8">Error: ' + escapeHtml(error.message) + '</p>';
    }
}

function getStatusBadge(attendanceStatus, baptismStatus) {
    if (baptismStatus === 'baptized') {
        return '<span class="px-2 py-1 text-xs font-medium rounded-full bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">Baptized</span>';
    }
    if (attendanceStatus === 'transferred-out') {
        return '<span class="px-2 py-1 text-xs font-medium rounded-full bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200">Transferred Out</span>';
    }
    if (baptismStatus === 'r301') {
        return '<span class="px-2 py-1 text-xs font-medium rounded-full bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200">R3-01</span>';
    }
    if (attendanceStatus === 'inactive') {
        return '<span class="px-2 py-1 text-xs font-medium rounded-full bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300">Inactive</span>';
    }
    return '<span class="px-2 py-1 text-xs font-medium rounded-full bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">Active</span>';
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function clearSearch() {
    document.getElementById('searchName').value = '';
    document.getElementById('searchRegistry').value = '';
    document.getElementById('searchStatus').value = '';
    document.getElementById('resultsContainer').innerHTML = '<p class="text-gray-500 dark:text-gray-400 text-center py-8">Enter search criteria to view PNK records</p>';
}

// PNK Transfer-In Modal Functions
function showPNKTransferInModal() {
    document.getElementById('pnkTransferInModal').classList.remove('hidden');
}

function closePNKTransferInModal() {
    document.getElementById('pnkTransferInModal').classList.add('hidden');
    document.getElementById('pnkTransferInForm').reset();
}

// Handle PNK Transfer-In Form Submission
document.getElementById('pnkTransferInForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const formData = new FormData(e.target);
    const data = Object.fromEntries(formData.entries());
    
    try {
        const response = await fetch('api/transfer-pnk-in.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        if (result.success) {
            closePNKTransferInModal();
            showConfirmationModal({
                type: 'success',
                title: 'Transfer Completed',
                message: result.message || 'PNK member transferred in successfully!',
                confirmText: 'OK',
                onConfirm: () => {
                    location.reload();
                }
            });
        } else {
            showConfirmationModal({
                type: 'danger',
                title: 'Transfer Failed',
                message: result.error || 'Failed to transfer PNK member',
                confirmText: 'OK',
                onConfirm: () => {}
            });
        }
    } catch (error) {
        console.error('Error:', error);
        showConfirmationModal({
            type: 'danger',
            title: 'Error',
            message: error.message || 'Error transferring PNK member',
            confirmText: 'OK',
            onConfirm: () => {}
        });
    }
});

// ============================================
// Dako Manager Functions
// ============================================

function showAddDakoModal() {
    document.getElementById('addDakoModal').classList.remove('hidden');
}

function closeAddDakoModal() {
    document.getElementById('addDakoModal').classList.add('hidden');
    document.getElementById('addDakoForm').reset();
    document.getElementById('addDakoLocal').innerHTML = '<option value="">Select District First</option>';
}

// Load locals for Add Dako modal
document.getElementById('addDakoDistrict')?.addEventListener('change', async function() {
    const districtCode = this.value;
    const localSelect = document.getElementById('addDakoLocal');
    
    if (!districtCode) {
        localSelect.innerHTML = '<option value="">Select District First</option>';
        return;
    }
    
    try {
        const response = await fetch(`api/get-locals.php?district_code=${districtCode}`);
        const data = await response.json();
        
        if (data.success) {
            localSelect.innerHTML = '<option value="">Select Local</option>';
            data.locals.forEach(local => {
                localSelect.innerHTML += `<option value="${local.local_code}">${local.local_name}</option>`;
            });
        }
    } catch (error) {
        console.error('Error loading locals:', error);
    }
});

// Handle Add Dako form submission
document.getElementById('addDakoForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const districtCode = document.getElementById('addDakoDistrict').value;
    const localCode = document.getElementById('addDakoLocal').value;
    const dakoName = document.getElementById('addDakoName').value;
    
    if (!districtCode || !localCode || !dakoName) {
        alert('Please fill in all required fields');
        return;
    }
    
    try {
        const response = await fetch('api/add-dako.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                district_code: districtCode,
                local_code: localCode,
                dako_name: dakoName
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            closeAddDakoModal();
            showConfirmationModal({
                type: 'success',
                title: 'Dako Added',
                message: result.message || 'Dako added successfully!',
                confirmText: 'OK',
                onConfirm: () => {
                    // Reload dako list if on dako tab
                    if (document.getElementById('dakoDistrictFilter').value && document.getElementById('dakoLocalFilter').value) {
                        loadDakoList();
                    }
                }
            });
        } else {
            alert(result.error || 'Failed to add Dako');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error adding Dako');
    }
});

// Load locals for Dako filter
async function loadDakoLocals() {
    const districtCode = document.getElementById('dakoDistrictFilter').value;
    const localSelect = document.getElementById('dakoLocalFilter');
    
    if (!districtCode) {
        localSelect.innerHTML = '<option value="">Select District First</option>';
        document.getElementById('dakoListContainer').innerHTML = '<p class="text-gray-500 dark:text-gray-400 text-center py-8">Select a local congregation to view Dako entries</p>';
        return;
    }
    
    try {
        const response = await fetch(`api/get-locals.php?district_code=${districtCode}`);
        const data = await response.json();
        
        if (data.success) {
            localSelect.innerHTML = '<option value="">Select Local</option>';
            data.locals.forEach(local => {
                localSelect.innerHTML += `<option value="${local.local_code}">${local.local_name}</option>`;
            });
        }
    } catch (error) {
        console.error('Error loading locals:', error);
    }
}

// Load Dako list
async function loadDakoList() {
    const districtCode = document.getElementById('dakoDistrictFilter').value;
    const localCode = document.getElementById('dakoLocalFilter').value;
    const container = document.getElementById('dakoListContainer');
    
    if (!districtCode || !localCode) {
        container.innerHTML = '<p class="text-gray-500 dark:text-gray-400 text-center py-8">Select a local congregation to view Dako entries</p>';
        return;
    }
    
    container.innerHTML = `
        <div class="text-center py-8">
            <svg class="w-8 h-8 mx-auto text-gray-400 dark:text-gray-600 animate-spin mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <p class="text-gray-500 dark:text-gray-400">Loading...</p>
        </div>
    `;
    
    try {
        const response = await fetch(`api/get-dako-list.php?district_code=${districtCode}&local_code=${localCode}`);
        const data = await response.json();
        
        if (!data.success) {
            throw new Error(data.error || 'Failed to load Dako list');
        }
        
        if (data.dakos.length === 0) {
            container.innerHTML = `
                <div class="text-center py-12">
                    <svg class="w-16 h-16 mx-auto text-gray-300 dark:text-gray-600 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                    </svg>
                    <p class="text-gray-500 dark:text-gray-400 font-medium">No Dako entries found</p>
                    <p class="text-sm text-gray-400 dark:text-gray-500 mt-1">Click "Add Dako" to create one</p>
                </div>
            `;
            return;
        }
        
        let html = `
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        `;
        
        data.dakos.forEach(dako => {
            html += `
                <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-4 border border-gray-200 dark:border-gray-600">
                    <div class="flex items-center justify-between">
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">${escapeHtml(dako.dako_name)}</h3>
                        <button onclick="deleteDako(${dako.id}, '${escapeHtml(dako.dako_name)}')" class="text-red-500 hover:text-red-700 p-1" title="Delete Dako">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                            </svg>
                        </button>
                    </div>
                </div>
            `;
        });
        
        html += '</div>';
        container.innerHTML = html;
        
    } catch (error) {
        console.error('Error:', error);
        container.innerHTML = `<p class="text-red-500 text-center py-8">Error loading Dako list</p>`;
    }
}

// Delete Dako
async function deleteDako(dakoId, dakoName) {
    showConfirmationModal({
        type: 'danger',
        title: 'Delete Dako',
        message: `Are you sure you want to delete "${dakoName}"?`,
        confirmText: 'Delete',
        cancelText: 'Cancel',
        onConfirm: async () => {
            try {
                const response = await fetch('api/delete-dako.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ dako_id: dakoId })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    loadDakoList();
                } else {
                    alert(result.error || 'Failed to delete Dako');
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error deleting Dako');
            }
        }
    });
}

// Helper function to escape HTML
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Load PNK pending verifications
async function loadPnkPendingVerifications() {
    const container = document.getElementById('pnkPendingVerificationsContainer');
    
    try {
        const response = await fetch('api/get-pending-verifications.php?registry_type=pnk');
        const data = await response.json();
        
        if (!data.success) {
            throw new Error(data.error || 'Failed to load pending verifications');
        }
        
        // Update badge
        const badge = document.getElementById('pnkPendingBadge');
        if (badge) {
            if (data.count > 0) {
                badge.textContent = data.count;
                badge.classList.remove('hidden');
            } else {
                badge.classList.add('hidden');
            }
        }
        
        if (data.results.length === 0) {
            container.innerHTML = `
                <div class="text-center py-12">
                    <svg class="w-16 h-16 mx-auto text-gray-300 dark:text-gray-600 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <p class="text-gray-500 dark:text-gray-400 font-medium">No pending verifications</p>
                    <p class="text-sm text-gray-400 dark:text-gray-500 mt-1">All submissions have been verified</p>
                </div>
            `;
            return;
        }
        
        let html = `
            <div class="mb-4">
                <p class="text-sm text-gray-600 dark:text-gray-400">Found ${data.count} pending verification(s)</p>
            </div>
            <div class="space-y-4">
        `;
        
        data.results.forEach(item => {
            const actionBadgeClass = item.action_type === 'add' 
                ? 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-400' 
                : 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-400';
            
            const statusSteps = getPnkStepperHtml(item.verification_status);
            
            html += `
                <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                    <div class="flex items-start justify-between mb-4">
                        <div>
                            <h4 class="font-medium text-gray-900 dark:text-gray-100">${item.child_name || 'N/A'}</h4>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Submitted by: ${item.submitted_by_name || 'Unknown'}</p>
                            <p class="text-xs text-gray-400 dark:text-gray-500">${item.submitted_at}</p>
                        </div>
                        <span class="px-2 py-1 text-xs font-medium rounded ${actionBadgeClass}">
                            ${item.action_type === 'add' ? 'NEW RECORD' : 'EDIT'}
                        </span>
                    </div>
                    
                    <!-- Mini Stepper -->
                    ${statusSteps}
                    
                    <div class="mt-4 flex gap-2">
                        <button onclick="verifyPnkRecord(${item.id})" class="px-3 py-1.5 text-sm bg-green-600 text-white rounded hover:bg-green-700 transition-colors">
                            <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            Verify
                        </button>
                        <button onclick="rejectPnkRecord(${item.id})" class="px-3 py-1.5 text-sm bg-red-600 text-white rounded hover:bg-red-700 transition-colors">
                            <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                            Reject
                        </button>
                        <button onclick="viewPnkRecordDetails(${item.id})" class="px-3 py-1.5 text-sm bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors">
                            View Details
                        </button>
                    </div>
                </div>
            `;
        });
        
        html += '</div>';
        container.innerHTML = html;
        
    } catch (error) {
        console.error('Error loading pending verifications:', error);
        container.innerHTML = `
            <div class="text-center py-12">
                <svg class="w-16 h-16 mx-auto text-red-300 dark:text-red-600 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                </svg>
                <p class="text-red-500 dark:text-red-400 font-medium">Failed to load</p>
                <p class="text-sm text-gray-400 dark:text-gray-500 mt-1">${error.message}</p>
            </div>
        `;
    }
}

// Generate stepper HTML for PNK
function getPnkStepperHtml(status) {
    const steps = [
        { key: 'submitted', label: 'Submitted', color: 'purple' },
        { key: 'pending_lorc_check', label: 'Pending LORC', color: 'yellow' },
        { key: 'verified', label: 'Verified', color: 'green' }
    ];
    
    const currentIndex = steps.findIndex(s => s.key === status);
    
    let html = '<div class="flex items-center justify-center py-2">';
    
    steps.forEach((step, index) => {
        const isCompleted = index <= currentIndex;
        const isCurrent = index === currentIndex;
        
        const bgColor = isCompleted 
            ? (step.color === 'purple' ? 'bg-purple-500' : step.color === 'yellow' ? 'bg-yellow-500' : 'bg-green-500')
            : 'bg-gray-300 dark:bg-gray-600';
        
        const textColor = isCurrent ? 'text-gray-900 dark:text-gray-100 font-semibold' : 'text-gray-500 dark:text-gray-400';
        
        html += `
            <div class="flex flex-col items-center">
                <div class="w-6 h-6 rounded-full ${bgColor} flex items-center justify-center">
                    ${isCompleted ? '<svg class="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path></svg>' : ''}
                </div>
                <span class="text-xs ${textColor} mt-1">${step.label}</span>
            </div>
        `;
        
        if (index < steps.length - 1) {
            const lineColor = index < currentIndex ? 'bg-green-500' : 'bg-gray-300 dark:bg-gray-600';
            html += `<div class="w-12 h-0.5 ${lineColor} mx-1"></div>`;
        }
    });
    
    html += '</div>';
    return html;
}

// Verify PNK record
async function verifyPnkRecord(id) {
    showConfirmationModal({
        type: 'success',
        title: 'Verify Record',
        message: 'Are you sure you want to verify this record? It will be added to the PNK Registry.',
        confirmText: 'Verify',
        onConfirm: async () => {
            try {
                const response = await fetch('api/verify-record.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id, action: 'verify', registry_type: 'pnk' })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    window.pnkPendingLoaded = false;
                    loadPnkPendingVerifications();
                    showConfirmationModal({
                        type: 'success',
                        title: 'Verified',
                        message: 'Record has been verified and added to the registry.',
                        confirmText: 'OK',
                        onConfirm: () => {}
                    });
                } else {
                    throw new Error(data.error || 'Failed to verify');
                }
            } catch (error) {
                showConfirmationModal({
                    type: 'danger',
                    title: 'Error',
                    message: error.message,
                    confirmText: 'OK',
                    onConfirm: () => {}
                });
            }
        }
    });
}

// Reject PNK record
async function rejectPnkRecord(id) {
    const reason = prompt('Please provide a reason for rejection:');
    if (!reason) return;
    
    try {
        const response = await fetch('api/verify-record.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id, action: 'reject', registry_type: 'pnk', reason })
        });
        
        const data = await response.json();
        
        if (data.success) {
            window.pnkPendingLoaded = false;
            loadPnkPendingVerifications();
            showConfirmationModal({
                type: 'info',
                title: 'Rejected',
                message: 'Record has been rejected.',
                confirmText: 'OK',
                onConfirm: () => {}
            });
        } else {
            throw new Error(data.error || 'Failed to reject');
        }
    } catch (error) {
        showConfirmationModal({
            type: 'danger',
            title: 'Error',
            message: error.message,
            confirmText: 'OK',
            onConfirm: () => {}
        });
    }
}

// View PNK record details
function viewPnkRecordDetails(id) {
    window.location.href = `pending-verification-view.php?id=${id}&type=pnk`;
}

// PNK Access Request Modal Functions
function openPnkAccessRequestModal() {
    document.getElementById('pnkAccessRequestModal').classList.remove('hidden');
    loadDakoOptions();
}

function closePnkAccessRequestModal() {
    document.getElementById('pnkAccessRequestModal').classList.add('hidden');
}

async function loadDakoOptions() {
    const select = document.getElementById('accessRequestDako');
    select.innerHTML = '<option value="">Loading Dako...</option>';
    select.disabled = true;
    
    try {
        const response = await fetch('api/get-dako-list.php');
        const data = await response.json();
        select.innerHTML = '<option value="">-- Select a Dako --</option>';
        if (data.success && data.dakos) {
            data.dakos.forEach(dako => {
                const option = document.createElement('option');
                option.value = dako.id;
                option.textContent = dako.name;
                select.appendChild(option);
            });
        }
    } catch (error) {
        console.error('Error loading dako list:', error);
        select.innerHTML = '<option value="">Failed to load Dako</option>';
    } finally {
        select.disabled = false;
    }
}

async function submitPnkAccessRequest() {
    // Get all checked access types
    const checkedTypes = document.querySelectorAll('input[name="pnk_request_types[]"]:checked');
    const requestTypes = Array.from(checkedTypes).map(cb => cb.value);
    const dakoId = document.getElementById('accessRequestDako').value;
    const submitBtn = document.getElementById('submitPnkAccessRequestBtn');
    
    if (requestTypes.length === 0) {
        alert('Please select at least one access type.');
        return;
    }
    
    if (!dakoId) {
        alert('Please select a Dako.');
        return;
    }
    
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<svg class="animate-spin h-4 w-4 mr-2 inline-block" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>Submitting...';
    
    try {
        const response = await fetch('api/request-pnk-access.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                request_types: requestTypes,
                dako_id: dakoId
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert('✅ Access request submitted successfully! You will be notified when approved.');
            closePnkAccessRequestModal();
            location.reload();
        } else {
            alert('❌ ' + (data.error || 'Failed to submit access request'));
        }
    } catch (error) {
        alert('❌ Network error: ' + error.message);
    } finally {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>Submit Request';
    }
}
</script>

<!-- PNK Access Request Modal -->
<div id="pnkAccessRequestModal" class="hidden fixed inset-0 bg-gray-900 bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-lg max-w-md w-full p-6">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4 flex items-center">
            <svg class="w-5 h-5 mr-2 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"></path>
            </svg>
            Request PNK Registry Access
        </h3>
        
        <!-- 7-Day Expiration Notice -->
        <div class="bg-amber-50 dark:bg-amber-900/30 border border-amber-200 dark:border-amber-800 rounded-lg p-3 mb-4">
            <div class="flex items-center">
                <svg class="w-5 h-5 text-amber-500 mr-2" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"></path>
                </svg>
                <span class="text-sm font-medium text-amber-800 dark:text-amber-300">Access will expire after 7 days</span>
            </div>
            <p class="text-xs text-amber-600 dark:text-amber-400 mt-1 ml-7">You can request renewal before expiration</p>
        </div>
        
        <!-- Stepper Preview -->
        <div class="mb-4 bg-purple-50 dark:bg-purple-900/30 border border-purple-200 dark:border-purple-800 rounded-lg p-4">
            <p class="text-xs text-purple-600 dark:text-purple-400 font-medium mb-3">Your request will go through this process:</p>
            <div class="flex items-center justify-between">
                <div class="flex flex-col items-center">
                    <div class="w-6 h-6 rounded-full bg-purple-600 text-white flex items-center justify-center text-xs font-medium">1</div>
                    <span class="text-[10px] text-purple-600 dark:text-purple-400 mt-1 text-center">SUBMITTED</span>
                </div>
                <div class="flex-1 h-0.5 bg-purple-300 dark:bg-purple-700 mx-2"></div>
                <div class="flex flex-col items-center">
                    <div class="w-6 h-6 rounded-full bg-purple-300 dark:bg-purple-700 text-purple-600 dark:text-purple-400 flex items-center justify-center text-xs font-medium">2</div>
                    <span class="text-[10px] text-purple-600 dark:text-purple-400 mt-1 text-center">PENDING LORC</span>
                </div>
                <div class="flex-1 h-0.5 bg-purple-300 dark:bg-purple-700 mx-2"></div>
                <div class="flex flex-col items-center" id="pnkStepperFinalStep">
                    <div class="w-6 h-6 rounded-full bg-purple-300 dark:bg-purple-700 text-purple-600 dark:text-purple-400 flex items-center justify-center text-xs font-medium">3</div>
                    <span class="text-[10px] text-purple-600 dark:text-purple-400 mt-1 text-center">(VERIFIED)</span>
                </div>
            </div>
        </div>
        
        <div class="space-y-4">
            <!-- Permission Type Selection (Multiple) -->
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Access Types <span class="text-xs text-gray-500">(select all that apply)</span></label>
                <div class="space-y-2">
                    <label class="flex items-center p-3 border border-gray-200 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                        <input type="checkbox" name="pnk_request_types[]" value="view" checked 
                               class="w-4 h-4 text-purple-600 border-gray-300 rounded focus:ring-purple-500">
                        <div class="ml-3">
                            <span class="block text-sm font-medium text-gray-900 dark:text-gray-100">View Data</span>
                            <span class="block text-xs text-gray-500 dark:text-gray-400">View PNK data table entries</span>
                        </div>
                    </label>
                    <label class="flex items-center p-3 border border-gray-200 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                        <input type="checkbox" name="pnk_request_types[]" value="edit" 
                               class="w-4 h-4 text-yellow-600 border-gray-300 rounded focus:ring-yellow-500">
                        <div class="ml-3">
                            <span class="block text-sm font-medium text-gray-900 dark:text-gray-100">Edit Records</span>
                            <span class="block text-xs text-gray-500 dark:text-gray-400">Edit existing PNK entries (requires LORC verification)</span>
                        </div>
                    </label>
                </div>
            </div>
            
            <!-- Dako Selection -->
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Dako <span class="text-red-500">*</span></label>
                <select id="accessRequestDako" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-purple-500">
                    <option value="">-- Select a Dako --</option>
                </select>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Select the dako you need access to</p>
            </div>
        </div>
        
        <div class="mt-6 flex gap-2">
            <button type="button" onclick="submitPnkAccessRequest()" id="submitPnkAccessRequestBtn" class="flex-1 px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors flex items-center justify-center">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                Submit Request
            </button>
            <button type="button" onclick="closePnkAccessRequestModal()" class="flex-1 px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors">
                Cancel
            </button>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/includes/layout.php';
?>
