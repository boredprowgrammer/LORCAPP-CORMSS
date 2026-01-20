<?php
/**
 * HDB Registry (Handog Di Bautisado - Unbaptized Children Registry)
 * View and manage HDB members
 */

require_once __DIR__ . '/config/config.php';

Security::requireLogin();
requirePermission('can_view_reports'); // Anyone who can view reports can see this

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

// Check if user needs to request access
$needsAccessRequest = ($currentUser['role'] === 'local_cfo' || $currentUser['role'] === 'local_limited');
$hasApprovedAccess = false;
$approvedRequests = [];
$pendingRequests = [];

if ($needsAccessRequest) {
    // Check for approved access requests
    $stmt = $db->prepare("
        SELECT * FROM hdb_access_requests 
        WHERE requester_user_id = ? 
        AND status = 'approved'
        AND deleted_at IS NULL
        AND is_locked = FALSE
        ORDER BY approval_date DESC
    ");
    $stmt->execute([$currentUser['user_id']]);
    $approvedRequests = $stmt->fetchAll();
    $hasApprovedAccess = count($approvedRequests) > 0;
    
    // Check for pending access requests
    $stmt = $db->prepare("
        SELECT * FROM hdb_access_requests 
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
    // Check hdb_data_access for active permissions
    $stmt = $db->prepare("
        SELECT can_view, can_add, can_edit FROM hdb_data_access 
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

$pageTitle = 'HDB Registry';
ob_start();
?>

<script>
// Define modal functions early for inline onclick handlers
function openAccessRequestModal() {
    document.getElementById('accessRequestModal').classList.remove('hidden');
}

function closeAccessRequestModal() {
    document.getElementById('accessRequestModal').classList.add('hidden');
}
</script>

<div class="space-y-6">
    <!-- Header -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900 dark:text-gray-100">HDB Registry <?php echo $needsAccessRequest && !$hasApprovedAccess ? '(Access Required)' : '(View)'; ?></h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Handog Di Bautisado - Unbaptized Children Registry</p>
            </div>
            <?php if ($hasAddAccess || $hasEditAccess): ?>
            <!-- Action buttons: Show based on access permissions -->
            <div class="flex gap-2">
                <?php if ($hasAddAccess): ?>
                <a href="hdb-add.php" class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                    </svg>
                    Add HDB Child
                </a>
                <?php endif; ?>
                <?php if ($currentUser['role'] === 'admin' || $currentUser['role'] === 'local'): ?>
                <a href="hdb-import.php" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                    </svg>
                    Import CSV
                </a>
                <button onclick="showTransferInModal()" class="inline-flex items-center px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors">
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
        <div class="bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400 px-4 py-3 rounded-lg mb-4">
            <?php echo Security::escape($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-400 px-4 py-3 rounded-lg mb-4">
            <?php echo Security::escape($success); ?>
        </div>
    <?php endif; ?>
    
    <?php if ($needsAccessRequest && !$hasApprovedAccess): ?>
    <!-- Access Request Required -->
    <div class="bg-yellow-50 dark:bg-yellow-900/30 border-l-4 border-yellow-500 p-6 rounded-r-lg">
        <div class="flex items-start">
            <svg class="w-6 h-6 text-yellow-500 mr-3 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"></path>
            </svg>
            <div class="flex-1">
                <h3 class="text-lg font-semibold text-yellow-800 dark:text-yellow-300">Access Required</h3>
                <p class="text-sm text-yellow-700 dark:text-yellow-400 mt-2">You need approval from a senior account to access the HDB Registry. Click the button below to request access.</p>
                <button onclick="openAccessRequestModal()" class="mt-4 inline-flex items-center px-4 py-2 bg-yellow-600 text-white rounded-lg hover:bg-yellow-700 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"></path>
                    </svg>
                    Request Access
                </button>
            </div>
        </div>
    </div>
    
    <?php if (count($pendingRequests) > 0): ?>
    <!-- Pending Requests -->
    <div class="bg-blue-50 dark:bg-blue-900/30 border-l-4 border-blue-500 p-6 rounded-r-lg mt-4">
        <div class="flex items-start">
            <svg class="w-6 h-6 text-blue-500 mr-3 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"></path>
            </svg>
            <div class="flex-1">
                <h3 class="text-lg font-semibold text-blue-800 dark:text-blue-300">Pending Requests</h3>
                <p class="text-sm text-blue-700 dark:text-blue-400 mt-2">Your access requests are awaiting approval:</p>
                <div class="mt-4 space-y-2">
                    <?php foreach ($pendingRequests as $request): ?>
                    <div class="bg-white dark:bg-gray-800 p-4 rounded-lg shadow-sm border border-blue-200 dark:border-blue-800">
                        <div class="flex items-center justify-between">
                            <div class="flex-1">
                                <h4 class="font-semibold text-gray-900 dark:text-gray-100">HDB Registry Access</h4>
                                <p class="text-xs text-gray-600 dark:text-gray-400 mt-1">
                                    Requested: <?php echo date('M j, Y g:i A', strtotime($request['request_date'])); ?>
                                </p>
                            </div>
                            <span class="inline-flex items-center px-3 py-1 bg-blue-100 dark:bg-blue-900 text-blue-800 dark:text-blue-300 text-xs font-medium rounded-full">
                                <svg class="w-3 h-3 mr-1 animate-pulse" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"></path>
                                </svg>
                                Pending Review
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php else: ?>
    <!-- Tabs -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700">
        <div class="border-b border-gray-200 dark:border-gray-700">
            <nav class="flex -mb-px overflow-x-auto">
                <button onclick="switchTab('search')" id="tab-search" class="tab-button active px-6 py-4 text-sm font-medium border-b-2 border-blue-600 text-blue-600 dark:text-blue-400 whitespace-nowrap">
                    <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                    Search Records
                </button>
                <button onclick="switchTab('eligible')" id="tab-eligible" class="tab-button px-6 py-4 text-sm font-medium border-b-2 border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 hover:border-gray-300 whitespace-nowrap">
                    <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                    </svg>
                    Eligible for PNK (4+ years)
                </button>
                <?php if ($currentUser['role'] === 'admin' || $currentUser['role'] === 'local'): ?>
                <button onclick="switchTab('pending')" id="tab-pending" class="tab-button px-6 py-4 text-sm font-medium border-b-2 border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 hover:border-gray-300 whitespace-nowrap">
                    <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path>
                    </svg>
                    Pending Verification
                    <span id="pendingBadge" class="hidden ml-2 px-2 py-0.5 text-xs bg-yellow-500 text-white rounded-full">0</span>
                </button>
                <?php endif; ?>
            </nav>
        </div>

        <!-- Search Tab Content -->
        <div id="content-search" class="tab-content p-6">
            <div class="mb-6">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Search HDB Registry</h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Child's Name</label>
                        <input type="text" id="searchName" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="Enter name">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Registry Number</label>
                        <input type="text" id="searchRegistry" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="Enter registry number">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Status</label>
                        <select id="searchStatus" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500">
                            <option value="">All Statuses</option>
                            <option value="pending">Pending</option>
                            <option value="dedicated">Dedicated</option>
                            <option value="baptized">Baptized</option>
                            <option value="transferred-out">Transferred Out</option>
                        </select>
                    </div>
                </div>
                <div class="mt-4 flex gap-2">
                    <button onclick="searchHDB()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                        <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                        Search
                    </button>
                    <button onclick="clearSearch()" class="px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors">
                        Clear
                    </button>
                </div>
            </div>

            <div id="resultsContainer" class="mt-6">
                <p class="text-gray-500 dark:text-gray-400 text-center py-8">Enter search criteria to view HDB records</p>
            </div>
        </div>

        <!-- Eligible for PNK Tab Content -->
        <div id="content-eligible" class="tab-content hidden p-6">
            <div class="mb-6">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Children Eligible for PNK Promotion</h2>
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">Children who are 4 years or older and ready to be promoted to PNK (Paaralan ng mga Kabataan)</p>
            </div>

            <div id="eligibleContainer">
                <div class="flex flex-col items-center justify-center py-12">
                    <div class="relative w-16 h-16">
                        <div class="absolute inset-0 border-4 border-blue-200 dark:border-blue-800 rounded-full"></div>
                        <div class="absolute inset-0 border-4 border-blue-600 dark:border-blue-400 rounded-full border-t-transparent animate-spin"></div>
                    </div>
                    <p class="mt-4 text-sm text-gray-600 dark:text-gray-400">Loading eligible children...</p>
                </div>
            </div>
        </div>
        
        <?php if ($currentUser['role'] === 'admin' || $currentUser['role'] === 'local'): ?>
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
                            <div class="w-10 h-10 rounded-full bg-blue-500 flex items-center justify-center">
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
            <div id="pendingVerificationsContainer">
                <div class="flex flex-col items-center justify-center py-12">
                    <div class="relative w-16 h-16">
                        <div class="absolute inset-0 border-4 border-yellow-200 dark:border-yellow-800 rounded-full"></div>
                        <div class="absolute inset-0 border-4 border-yellow-600 dark:border-yellow-400 rounded-full border-t-transparent animate-spin"></div>
                    </div>
                    <p class="mt-4 text-sm text-gray-600 dark:text-gray-400">Loading pending verifications...</p>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Confirmation Modal -->
<div id="confirmationModal" class="hidden fixed inset-0 bg-gray-900 bg-opacity-50 backdrop-blur-sm z-[60] flex items-center justify-center p-4">
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
            <button id="modalCancelButton" onclick="closeConfirmationModal()" class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-600 border border-gray-300 dark:border-gray-500 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-500 transition-colors">
                Cancel
            </button>
            <button id="modalConfirmButton" onclick="confirmModalAction()" class="px-4 py-2 text-sm font-medium text-white rounded-lg transition-colors">
                <!-- Button text will be set dynamically -->
            </button>
        </div>
    </div>
</div>

<!-- Access Request Modal -->
<div id="accessRequestModal" class="hidden fixed inset-0 bg-gray-900 bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-lg max-w-md w-full p-6">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Request HDB Registry Access</h3>
        <form id="accessRequestForm">
            <div class="space-y-4">
                <!-- 7-Day Expiration Notice -->
                <div class="bg-amber-50 dark:bg-amber-900/30 border border-amber-200 dark:border-amber-800 rounded-lg p-3">
                    <div class="flex items-center">
                        <svg class="w-5 h-5 text-amber-500 mr-2" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"></path>
                        </svg>
                        <span class="text-sm font-medium text-amber-800 dark:text-amber-300">Access will expire after 7 days</span>
                    </div>
                    <p class="text-xs text-amber-600 dark:text-amber-400 mt-1 ml-7">You can request renewal before expiration</p>
                </div>
                
                <!-- Permission Type Selection (Multiple) -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Access Types <span class="text-xs text-gray-500">(select all that apply)</span></label>
                    <div class="space-y-2">
                        <label class="flex items-center p-3 border border-gray-200 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                            <input type="checkbox" name="request_types[]" value="view" checked 
                                   class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                            <div class="ml-3">
                                <span class="block text-sm font-medium text-gray-900 dark:text-gray-100">View Data</span>
                                <span class="block text-xs text-gray-500 dark:text-gray-400">View HDB data table entries</span>
                            </div>
                        </label>
                        <label class="flex items-center p-3 border border-gray-200 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                            <input type="checkbox" name="request_types[]" value="add" 
                                   class="w-4 h-4 text-green-600 border-gray-300 rounded focus:ring-green-500">
                            <div class="ml-3">
                                <span class="block text-sm font-medium text-gray-900 dark:text-gray-100">Add Records</span>
                                <span class="block text-xs text-gray-500 dark:text-gray-400">Add new HDB entries (requires LORC verification)</span>
                            </div>
                        </label>
                        <label class="flex items-center p-3 border border-gray-200 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                            <input type="checkbox" name="request_types[]" value="edit" 
                                   class="w-4 h-4 text-yellow-600 border-gray-300 rounded focus:ring-yellow-500">
                            <div class="ml-3">
                                <span class="block text-sm font-medium text-gray-900 dark:text-gray-100">Edit Records</span>
                                <span class="block text-xs text-gray-500 dark:text-gray-400">Edit existing HDB entries (requires LORC verification)</span>
                            </div>
                        </label>
                    </div>
                </div>
            </div>
            <div class="mt-6 flex gap-2">
                <button type="submit" id="submitHdbAccessRequestBtn" class="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors flex items-center justify-center">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    Submit Request
                </button>
                <button type="button" onclick="closeAccessRequestModal()" class="flex-1 px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Transfer-In Modal -->
<div id="transferInModal" class="hidden fixed inset-0 bg-gray-900 bg-opacity-50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-lg max-w-2xl w-full p-6 shadow-xl">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4 flex items-center">
            <svg class="w-6 h-6 mr-2 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16l-4-4m0 0l4-4m-4 4h18"></path>
            </svg>
            Transfer Child Into HDB Registry
        </h3>
        <form id="transferInForm" class="space-y-4">
            <!-- Destination Location (auto-set and frozen) -->
            <div class="bg-blue-50 dark:bg-blue-900/30 border border-blue-200 dark:border-blue-800 rounded-lg p-4 mb-4">
                <div class="flex items-center">
                    <svg class="w-5 h-5 text-blue-600 dark:text-blue-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                    </svg>
                    <div>
                        <p class="text-xs text-blue-600 dark:text-blue-400 font-medium uppercase">Transferring To</p>
                        <p class="font-medium text-gray-900 dark:text-gray-100"><?php echo Security::escape($userLocalName); ?> — <?php echo Security::escape($userDistrictName); ?></p>
                    </div>
                </div>
                <input type="hidden" name="district_code" value="<?php echo Security::escape($userDistrictCode); ?>">
                <input type="hidden" name="local_code" value="<?php echo Security::escape($userLocalCode); ?>">
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Child Information -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Child's Full Name *</label>
                    <input type="text" name="child_name" required 
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-purple-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Date of Birth *</label>
                    <input type="date" name="birthday" required 
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-purple-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Father's Name</label>
                    <input type="text" name="father_name" 
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-purple-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Mother's Name</label>
                    <input type="text" name="mother_name" 
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-purple-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Address</label>
                    <input type="text" name="address" 
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-purple-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Status</label>
                    <select name="dedication_status" 
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-purple-500">
                        <option value="active" selected>Active</option>
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
                               class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-purple-500"
                               placeholder="e.g., Local 1, Manila">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Transfer Date</label>
                        <input type="date" name="transfer_date" 
                               class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-purple-500"
                               value="<?php echo date('Y-m-d'); ?>">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Transfer From District</label>
                    <input type="text" name="transferFromDistrict" 
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-purple-500"
                           placeholder="e.g., Manila District">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Transfer Reason</label>
                    <textarea name="transfer_reason" rows="3" 
                              class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-purple-500"
                              placeholder="e.g., Family relocation, Transferred from another congregation"></textarea>
                </div>
            </div>
            
            <div class="flex gap-2 pt-4">
                <button type="submit" class="flex-1 px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors">
                    Complete Transfer
                </button>
                <button type="button" onclick="closeTransferInModal()" class="flex-1 px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors">
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
    const cancelButton = document.getElementById('modalCancelButton');
    
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
    
    // Hide cancel button for success/info type modals (single action acknowledgments)
    if (type === 'success' || options.hideCancel) {
        cancelButton.classList.add('hidden');
    } else {
        cancelButton.classList.remove('hidden');
    }
    
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
        button.classList.remove('active', 'border-blue-600', 'text-blue-600', 'dark:text-blue-400');
        button.classList.add('border-transparent', 'text-gray-500', 'dark:text-gray-400');
    });
    
    // Show selected tab content
    document.getElementById('content-' + tabName).classList.remove('hidden');
    
    // Add active class to selected tab
    const activeTab = document.getElementById('tab-' + tabName);
    activeTab.classList.add('active', 'border-blue-600', 'text-blue-600', 'dark:text-blue-400');
    activeTab.classList.remove('border-transparent', 'text-gray-500', 'dark:text-gray-400');
    
    // Load data for eligible tab when switched
    if (tabName === 'eligible' && !window.eligibleLoaded) {
        loadEligibleChildren();
        window.eligibleLoaded = true;
    }
    
    // Load data for pending tab when switched
    if (tabName === 'pending' && !window.pendingLoaded) {
        loadPendingVerifications();
        window.pendingLoaded = true;
    }
}

// Load pending verifications
async function loadPendingVerifications() {
    const container = document.getElementById('pendingVerificationsContainer');
    
    try {
        const response = await fetch('api/get-pending-verifications.php?registry_type=hdb');
        const data = await response.json();
        
        if (!data.success) {
            throw new Error(data.error || 'Failed to load pending verifications');
        }
        
        // Update badge
        const badge = document.getElementById('pendingBadge');
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
            
            const statusSteps = getStepperHtml(item.verification_status);
            
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
                        <button onclick="verifyRecord(${item.id})" class="px-3 py-1.5 text-sm bg-green-600 text-white rounded hover:bg-green-700 transition-colors">
                            <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            Verify
                        </button>
                        <button onclick="rejectRecord(${item.id})" class="px-3 py-1.5 text-sm bg-red-600 text-white rounded hover:bg-red-700 transition-colors">
                            <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                            Reject
                        </button>
                        <button onclick="viewRecordDetails(${item.id})" class="px-3 py-1.5 text-sm bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors">
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

// Generate stepper HTML based on status
function getStepperHtml(status) {
    const steps = [
        { key: 'submitted', label: 'Submitted', color: 'blue' },
        { key: 'pending_lorc_check', label: 'Pending LORC', color: 'yellow' },
        { key: 'verified', label: 'Verified', color: 'green' }
    ];
    
    const currentIndex = steps.findIndex(s => s.key === status);
    
    let html = '<div class="flex items-center justify-center py-2">';
    
    steps.forEach((step, index) => {
        const isCompleted = index <= currentIndex;
        const isCurrent = index === currentIndex;
        
        const bgColor = isCompleted 
            ? (step.color === 'blue' ? 'bg-blue-500' : step.color === 'yellow' ? 'bg-yellow-500' : 'bg-green-500')
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

// Verify record
async function verifyRecord(id) {
    showConfirmationModal({
        type: 'success',
        title: 'Verify Record',
        message: 'Are you sure you want to verify this record? It will be added to the HDB Registry.',
        confirmText: 'Verify',
        onConfirm: async () => {
            try {
                const response = await fetch('api/verify-record.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id, action: 'verify', registry_type: 'hdb' })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    window.pendingLoaded = false;
                    loadPendingVerifications();
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

// Reject record
async function rejectRecord(id) {
    const reason = prompt('Please provide a reason for rejection:');
    if (!reason) return;
    
    try {
        const response = await fetch('api/verify-record.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id, action: 'reject', registry_type: 'hdb', reason })
        });
        
        const data = await response.json();
        
        if (data.success) {
            window.pendingLoaded = false;
            loadPendingVerifications();
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

// View record details
function viewRecordDetails(id) {
    window.location.href = `pending-verification-view.php?id=${id}&type=hdb`;
}

// Load children eligible for PNK promotion
async function loadEligibleChildren() {
    const container = document.getElementById('eligibleContainer');
    
    try {
        const response = await fetch('api/get-eligible-for-pnk.php');
        const data = await response.json();
        
        if (!data.success) {
            throw new Error(data.error || 'Failed to load eligible children');
        }
        
        if (data.results.length === 0) {
            container.innerHTML = `
                <div class="text-center py-12">
                    <svg class="w-16 h-16 mx-auto text-gray-300 dark:text-gray-600 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                    </svg>
                    <p class="text-gray-500 dark:text-gray-400 font-medium">No children eligible for promotion</p>
                    <p class="text-sm text-gray-400 dark:text-gray-500 mt-1">Children must be 4 years or older</p>
                </div>
            `;
            return;
        }
        
        let html = `
            <div class="mb-4 flex items-center justify-between">
                <p class="text-sm text-gray-600 dark:text-gray-400">Found ${data.count} eligible child(ren)</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Child Name</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Age</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Registry #</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Local</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
        `;
        
        data.results.forEach(child => {
            const fullName = [child.first_name, child.middle_name, child.last_name].filter(Boolean).join(' ');
            html += `
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                    <td class="px-4 py-3">
                        <div class="text-sm font-medium text-gray-900 dark:text-gray-100">${fullName}</div>
                    </td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-400">
                            ${child.age} years old
                        </span>
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">${child.registry_number}</td>
                    <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">${child.local_name || child.local_code}</td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-2">
                            <a href="hdb-view.php?id=${child.id}" class="text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 text-sm font-medium">
                                View
                            </a>
                            <button onclick="promoteToPNKFromList(${child.id}, '${fullName.replace(/'/g, "\\'")}')" class="text-green-600 dark:text-green-400 hover:text-green-800 dark:hover:text-green-300 text-sm font-medium">
                                Promote
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
        console.error('Error loading eligible children:', error);
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

// Promote to PNK from list
async function promoteToPNKFromList(hdbRecordId, childName) {
    showConfirmationModal({
        type: 'info',
        title: 'Promote to PNK',
        message: `Promote ${childName} to PNK (Pagsamba ng Kabataan) Registry? This will move the child from HDB to PNK.`,
        confirmText: 'Promote',
        onConfirm: async () => {
            await executePromoteToPNK(hdbRecordId, childName);
        }
    });
}

async function executePromoteToPNK(hdbRecordId, childName) {
    try {
        const response = await fetch('api/promote-hdb-to-pnk.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ hdb_record_id: hdbRecordId })
        });
        
        const data = await response.json();
        
        if (data.success) {
            showConfirmationModal({
                type: 'success',
                title: 'Successfully Promoted',
                message: `${childName} has been promoted to PNK!\n\nPNK Registry Number: ${data.pnk_registry_number}`,
                confirmText: 'OK',
                onConfirm: () => {
                    window.eligibleLoaded = false;
                    switchTab('eligible');
                }
            });
        } else {
            throw new Error(data.error || 'Failed to promote');
        }
    } catch (error) {
        console.error('Promotion error:', error);
        showConfirmationModal({
            type: 'danger',
            title: 'Promotion Failed',
            message: error.message || 'An error occurred while promoting to PNK',
            confirmText: 'OK',
            onConfirm: () => {}
        });
    }
}

async function searchHDB() {
    const name = document.getElementById('searchName').value;
    const registry = document.getElementById('searchRegistry').value;
    const status = document.getElementById('searchStatus').value;
    
    const resultsContainer = document.getElementById('resultsContainer');
    
    // Show loading state
    resultsContainer.innerHTML = `
        <div class="flex flex-col items-center justify-center py-12">
            <div class="relative w-16 h-16">
                <div class="absolute inset-0 border-4 border-blue-200 dark:border-blue-800 rounded-full"></div>
                <div class="absolute inset-0 border-4 border-blue-600 dark:border-blue-400 rounded-full border-t-transparent animate-spin"></div>
            </div>
            <p class="mt-4 text-sm text-gray-600 dark:text-gray-400">Searching...</p>
        </div>
    `;
    
    try {
        const params = new URLSearchParams();
        if (name) params.append('name', name);
        if (registry) params.append('registry', registry);
        if (status) params.append('status', status);
        
        const response = await fetch(`api/search-hdb.php?${params.toString()}`);
        const data = await response.json();
        
        if (!data.success) {
            throw new Error(data.error || 'Search failed');
        }
        
        if (data.results.length === 0) {
            resultsContainer.innerHTML = `
                <div class="text-center py-12">
                    <svg class="w-16 h-16 mx-auto text-gray-300 dark:text-gray-600 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <p class="text-gray-500 dark:text-gray-400 font-medium">No records found</p>
                    <p class="text-sm text-gray-400 dark:text-gray-500 mt-1">Try adjusting your search criteria</p>
                </div>
            `;
            return;
        }
        
        // Display results in a table
        let html = `
            <div class="mb-4">
                <p class="text-sm text-gray-600 dark:text-gray-400">Found ${data.count} record(s)</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Registry #</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Name</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Date of Birth</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Parents</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
        `;
        
        data.results.forEach(record => {
            const fullName = [record.first_name, record.middle_name, record.last_name].filter(Boolean).join(' ');
            const statusColors = {
                'pending': 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-400',
                'dedicated': 'bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-400',
                'baptized': 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-400',
                'transferred-out': 'bg-gray-100 dark:bg-gray-900/30 text-gray-800 dark:text-gray-400'
            };
            const statusClass = statusColors[record.status] || 'bg-gray-100 dark:bg-gray-900/30 text-gray-800 dark:text-gray-400';
            
            html += `
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                    <td class="px-4 py-3">
                        <span class="text-sm font-medium text-gray-900 dark:text-gray-100">${record.registry_number || 'N/A'}</span>
                    </td>
                    <td class="px-4 py-3">
                        <div class="text-sm font-medium text-gray-900 dark:text-gray-100">${fullName}</div>
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">${record.date_of_birth || 'N/A'}</td>
                    <td class="px-4 py-3">
                        <div class="text-sm text-gray-700 dark:text-gray-300">${record.father_name || 'N/A'}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">${record.mother_name || 'N/A'}</div>
                    </td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${statusClass}">
                            ${record.status ? record.status.charAt(0).toUpperCase() + record.status.slice(1).replace('-', ' ') : 'Unknown'}
                        </span>
                    </td>
                    <td class="px-4 py-3">
                        <a href="hdb-view.php?id=${record.id}" class="text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 text-sm font-medium">
                            View Details
                        </a>
                    </td>
                </tr>
            `;
        });
        
        html += `
                    </tbody>
                </table>
            </div>
        `;
        
        resultsContainer.innerHTML = html;
        
    } catch (error) {
        console.error('Search error:', error);
        resultsContainer.innerHTML = `
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

function clearSearch() {
    document.getElementById('searchName').value = '';
    document.getElementById('searchRegistry').value = '';
    document.getElementById('searchStatus').value = '';
    document.getElementById('resultsContainer').innerHTML = '<p class="text-gray-500 dark:text-gray-400 text-center py-8">Enter search criteria to view HDB records</p>';
}

// Access request form submission
document.getElementById('accessRequestForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const checkedBoxes = document.querySelectorAll('input[name="request_types[]"]:checked');
    const requestTypes = Array.from(checkedBoxes).map(cb => cb.value);
    const submitBtn = document.getElementById('submitHdbAccessRequestBtn');
    
    if (requestTypes.length === 0) {
        showConfirmationModal({
            type: 'danger',
            title: 'Selection Required',
            message: 'Please select at least one access type.',
            confirmText: 'OK',
            onConfirm: () => {}
        });
        return;
    }
    
    // Show loading state
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<svg class="animate-spin h-4 w-4 mr-2 inline-block" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>Submitting...';
    
    try {
        const response = await fetch('api/request-hdb-access.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ request_types: requestTypes })
        });
        
        const result = await response.json();
        
        if (result.success) {
            showConfirmationModal({
                type: 'success',
                title: 'Request Submitted',
                message: 'Access request submitted successfully! You will be notified when approved.',
                confirmText: 'OK',
                onConfirm: () => {
                    location.reload();
                }
            });
        } else {
            showConfirmationModal({
                type: 'danger',
                title: 'Request Failed',
                message: result.error || 'Failed to submit access request',
                confirmText: 'OK',
                onConfirm: () => {}
            });
        }
    } catch (error) {
        console.error('Error:', error);
        showConfirmationModal({
            type: 'danger',
            title: 'Error',
            message: error.message || 'Error submitting request',
            confirmText: 'OK',
            onConfirm: () => {}
        });
    } finally {
        // Reset button state
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>Submit Request';
    }
});

// Transfer-In Modal Functions
function showTransferInModal() {
    document.getElementById('transferInModal').classList.remove('hidden');
}

function closeTransferInModal() {
    document.getElementById('transferInModal').classList.add('hidden');
    document.getElementById('transferInForm').reset();
}

// Handle Transfer-In Form Submission
document.getElementById('transferInForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const formData = new FormData(e.target);
    const data = Object.fromEntries(formData.entries());
    
    try {
        const response = await fetch('api/transfer-hdb-in.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        if (result.success) {
            closeTransferInModal();
            showConfirmationModal({
                type: 'success',
                title: 'Transfer Completed',
                message: result.message || 'Child transferred in successfully!',
                confirmText: 'OK',
                onConfirm: () => {
                    location.reload();
                }
            });
        } else {
            showConfirmationModal({
                type: 'danger',
                title: 'Transfer Failed',
                message: result.error || 'Failed to transfer child',
                confirmText: 'OK',
                onConfirm: () => {}
            });
        }
    } catch (error) {
        console.error('Error:', error);
        showConfirmationModal({
            type: 'danger',
            title: 'Error',
            message: error.message || 'Error transferring child',
            confirmText: 'OK',
            onConfirm: () => {}
        });
    }
});
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/includes/layout.php';
?>
