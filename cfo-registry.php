<?php
/**
 * CFO Registry (Christian Family Organization)
 * View and manage CFO members from Tarheta Control
 */

// Generate nonce for inline scripts (CSP)
if (!isset($csp_nonce)) {
    $csp_nonce = base64_encode(random_bytes(16));
}

require_once __DIR__ . '/config/config.php';

Security::requireLogin();
requirePermission('can_view_reports'); // Anyone who can view reports can see this

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

// Check if local_cfo needs to request access
$needsAccessRequest = ($currentUser['role'] === 'local_cfo');
$hasApprovedAccess = false;
$hasViewAccess = false;
$hasAddAccess = false;
$hasEditAccess = false;
$approvedRequests = [];
$pendingRequests = [];
$approvedCfoTypes = []; // Track which CFO types user has access to

if ($needsAccessRequest) {
    // Check for approved access requests (not expired)
    $stmt = $db->prepare("
        SELECT * FROM cfo_access_requests 
        WHERE requester_user_id = ? 
        AND status = 'approved'
        AND deleted_at IS NULL
        AND (expires_at IS NULL OR expires_at > NOW())
        ORDER BY approval_date DESC
    ");
    $stmt->execute([$currentUser['user_id']]);
    $approvedRequests = $stmt->fetchAll();
    $hasApprovedAccess = count($approvedRequests) > 0;
    
    // Check specific access types and collect approved CFO types
    foreach ($approvedRequests as $request) {
        if ($request['access_mode'] === 'view_data') {
            $hasViewAccess = true;
            // Add CFO type to approved list (avoid duplicates)
            if (!in_array($request['cfo_type'], $approvedCfoTypes)) {
                $approvedCfoTypes[] = $request['cfo_type'];
            }
        } elseif ($request['access_mode'] === 'add_member') {
            $hasAddAccess = true;
            if (!in_array($request['cfo_type'], $approvedCfoTypes)) {
                $approvedCfoTypes[] = $request['cfo_type'];
            }
        } elseif ($request['access_mode'] === 'edit_member') {
            $hasEditAccess = true;
            if (!in_array($request['cfo_type'], $approvedCfoTypes)) {
                $approvedCfoTypes[] = $request['cfo_type'];
            }
        }
    }
    
    // Check for pending access requests
    $stmt = $db->prepare("
        SELECT * FROM cfo_access_requests 
        WHERE requester_user_id = ? 
        AND status = 'pending'
        AND deleted_at IS NULL
        ORDER BY request_date DESC
    ");
    $stmt->execute([$currentUser['user_id']]);
    $pendingRequests = $stmt->fetchAll();
}

// Determine if user can view the data table
$canViewDataTable = !$needsAccessRequest || $hasViewAccess;

// JSON encode approved CFO types for JavaScript
$approvedCfoTypesJson = json_encode($approvedCfoTypes);

$error = '';
$success = '';

$pageTitle = 'CFO Registry';
ob_start();
?>

<script nonce="<?php echo $csp_nonce; ?>">
// Define modal functions early for inline onclick handlers
function openAccessRequestModal() {
    document.getElementById('accessRequestModal').classList.remove('hidden');
    document.getElementById('accessRequestPassword').value = '';
    document.getElementById('accessRequestCfoType').value = 'Buklod';
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
                <h1 class="text-2xl font-semibold text-gray-900 dark:text-gray-100">CFO Registry <?php 
                    if ($needsAccessRequest && !$hasApprovedAccess) {
                        echo '(Access Required)';
                    } elseif ($hasViewAccess) {
                        echo '(View Access)';
                    } else {
                        echo '(View)';
                    }
                ?></h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Christian Family Organization - Privacy-protected view</p>
            </div>
            <?php if ($currentUser['role'] === 'admin' || $currentUser['role'] === 'local'): ?>
            <!-- Edit buttons: Only admin and local accounts can edit CFO registry -->
            <div class="flex gap-2">
                <a href="cfo-checker.php" class="inline-flex items-center px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                    </svg>
                    Edit Mode (CFO Checker)
                </a>
                <a href="cfo-import.php" class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                    </svg>
                    Import CSV
                </a>
                <a href="cfo-add.php" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                    </svg>
                    Add CFO Member
                </a>
                <a href="tarheta/list.php" class="inline-flex items-center px-4 py-2 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    Tarheta Control
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-3 rounded-lg mb-4">
            <?php echo Security::escape($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-300 px-4 py-3 rounded-lg mb-4">
            <?php echo Security::escape($success); ?>
        </div>
    <?php endif; ?>
    
    <?php if ($needsAccessRequest && !$hasApprovedAccess): ?>
    <!-- Access Request Required -->
    <div class="bg-yellow-50 dark:bg-yellow-900/20 border-l-4 border-yellow-500 dark:border-yellow-700 p-6 rounded-r-lg">
        <div class="flex items-start">
            <svg class="w-6 h-6 text-yellow-500 mr-3 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"></path>
            </svg>
            <div class="flex-1">
                <h3 class="text-lg font-semibold text-yellow-800">Access Required</h3>
                <p class="text-sm text-yellow-700 mt-2">You need approval from a senior account to access the CFO Registry. Click the button below to request access.</p>
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
    <!-- Pending Requests (Collapsible) -->
    <div class="bg-blue-50 dark:bg-blue-900/20 border-l-4 border-blue-500 dark:border-blue-700 p-4 rounded-r-lg mt-4" x-data="{ expanded: false }">
        <button @click="expanded = !expanded" class="w-full flex items-center justify-between text-left">
            <div class="flex items-center">
                <svg class="w-6 h-6 text-blue-500 mr-3" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"></path>
                </svg>
                <div>
                    <h3 class="text-lg font-semibold text-blue-800 dark:text-blue-300">Pending Requests</h3>
                    <p class="text-sm text-blue-700 dark:text-blue-400">You have <?php echo count($pendingRequests); ?> request(s) awaiting approval</p>
                </div>
            </div>
            <svg class="w-5 h-5 text-blue-600 transform transition-transform duration-200" :class="{ 'rotate-180': expanded }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
            </svg>
        </button>
        <div x-show="expanded" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 transform -translate-y-2" x-transition:enter-end="opacity-100 transform translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 transform translate-y-0" x-transition:leave-end="opacity-0 transform -translate-y-2" class="mt-4">
            <div class="space-y-2">
                <?php foreach ($pendingRequests as $request): ?>
                <div class="bg-white dark:bg-gray-800 p-4 rounded-lg shadow-sm border border-blue-200 dark:border-blue-800">
                    <div class="flex items-center justify-between">
                        <div class="flex-1">
                            <h4 class="font-semibold text-gray-900 dark:text-gray-100"><?php echo Security::escape($request['cfo_type']); ?> CFO Registry</h4>
                            <p class="text-xs text-gray-600 dark:text-gray-400 mt-1">
                                Requested: <?php echo date('M j, Y g:i A', strtotime($request['request_date'])); ?>
                            </p>
                        </div>
                        <span class="inline-flex items-center px-3 py-1 bg-blue-100 dark:bg-blue-900/50 text-blue-800 dark:text-blue-300 text-xs font-medium rounded-full">
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
    <?php endif; ?>
    <?php elseif ($needsAccessRequest && $hasApprovedAccess): ?>
    <!-- Display approved access permissions (Collapsible) -->
    <div class="bg-green-50 dark:bg-green-900/20 border-l-4 border-green-500 dark:border-green-700 p-4 rounded-r-lg" x-data="{ expanded: false }">
        <button @click="expanded = !expanded" class="w-full flex items-center justify-between text-left">
            <div class="flex items-center">
                <svg class="w-6 h-6 text-green-500 mr-3" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                </svg>
                <div>
                    <h3 class="text-lg font-semibold text-green-800 dark:text-green-300">Approved Access Permissions</h3>
                    <p class="text-sm text-green-700 dark:text-green-400">You have <?php echo count($approvedRequests); ?> approved access permission(s)</p>
                </div>
            </div>
            <svg class="w-5 h-5 text-green-600 transform transition-transform duration-200" :class="{ 'rotate-180': expanded }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
            </svg>
        </button>
        <div x-show="expanded" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 transform -translate-y-2" x-transition:enter-end="opacity-100 transform translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 transform translate-y-0" x-transition:leave-end="opacity-0 transform -translate-y-2" class="mt-4">
            <div class="flex items-start">
                <div class="flex-1">
                <p class="text-sm text-green-700 dark:text-green-400 mt-2">You have the following approved access to CFO registry:</p>
                <div class="mt-4 space-y-2">
                    <?php foreach ($approvedRequests as $request): 
                        $expiresAt = $request['expires_at'] ?? null;
                        $daysRemaining = null;
                        $isExpired = false;
                        if ($expiresAt) {
                            $daysRemaining = max(0, floor((strtotime($expiresAt) - time()) / 86400));
                            $isExpired = strtotime($expiresAt) < time();
                        }
                        $accessModeLabels = [
                            'view_data' => ['label' => 'View Data', 'color' => 'blue', 'icon' => '👁️'],
                            'add_member' => ['label' => 'Add Members', 'color' => 'green', 'icon' => '➕'],
                            'edit_member' => ['label' => 'Edit Members', 'color' => 'yellow', 'icon' => '✏️']
                        ];
                        $accessMode = $request['access_mode'] ?? 'view_data';
                        $modeConfig = $accessModeLabels[$accessMode] ?? $accessModeLabels['view_data'];
                    ?>
                    <div class="bg-white dark:bg-gray-800 p-4 rounded-lg shadow-sm border <?php echo $isExpired ? 'border-red-300 dark:border-red-700' : 'border-green-200 dark:border-green-800'; ?>">
                        <div class="flex items-center justify-between">
                            <div class="flex-1">
                                <div class="flex items-center gap-2">
                                    <h4 class="font-semibold text-gray-900 dark:text-gray-100"><?php echo Security::escape($request['cfo_type']); ?> CFO Registry</h4>
                                    <span class="px-2 py-0.5 text-xs font-medium rounded bg-<?php echo $modeConfig['color']; ?>-100 text-<?php echo $modeConfig['color']; ?>-700 dark:bg-<?php echo $modeConfig['color']; ?>-900/50 dark:text-<?php echo $modeConfig['color']; ?>-300">
                                        <?php echo $modeConfig['icon']; ?> <?php echo $modeConfig['label']; ?>
                                    </span>
                                </div>
                                <p class="text-xs text-gray-600 dark:text-gray-400 mt-1">
                                    Approved: <?php echo date('M j, Y g:i A', strtotime($request['approval_date'])); ?>
                                    <?php if ($expiresAt): ?>
                                        <br>
                                        <?php if ($isExpired): ?>
                                            <span class="text-red-600 dark:text-red-400">⏱️ Expired</span>
                                        <?php elseif ($daysRemaining <= 2): ?>
                                            <span class="text-red-600 dark:text-red-400">⏱️ Expires in <?php echo $daysRemaining; ?> day(s)</span>
                                        <?php else: ?>
                                            <span class="text-green-600 dark:text-green-400">⏱️ <?php echo $daysRemaining; ?> day(s) remaining</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <?php if (!$isExpired): ?>
                            <span class="inline-flex items-center px-3 py-1.5 bg-green-100 dark:bg-green-900/50 text-green-700 dark:text-green-300 text-xs font-medium rounded-lg">
                                <svg class="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                </svg>
                                Active
                            </span>
                            <?php else: ?>
                            <span class="inline-flex items-center px-3 py-1.5 bg-red-100 dark:bg-red-900/50 text-red-700 dark:text-red-300 text-xs font-medium rounded-lg">
                                <svg class="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                                </svg>
                                Expired
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button onclick="openAccessRequestModal()" class="mt-4 inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                    </svg>
                    Request Additional Access
                </button>
                </div>
            </div>
        </div>
    </div>
    
    <?php if (count($pendingRequests) > 0): ?>
    <!-- Pending Requests with approved access (Collapsible) -->
    <div class="bg-blue-50 dark:bg-blue-900/20 border-l-4 border-blue-500 dark:border-blue-700 p-4 rounded-r-lg mt-4" x-data="{ expanded: false }">
        <button @click="expanded = !expanded" class="w-full flex items-center justify-between text-left">
            <div class="flex items-center">
                <svg class="w-6 h-6 text-blue-500 mr-3" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"></path>
                </svg>
                <div>
                    <h3 class="text-lg font-semibold text-blue-800 dark:text-blue-300">Pending Requests</h3>
                    <p class="text-sm text-blue-700 dark:text-blue-400"><?php echo count($pendingRequests); ?> additional request(s) awaiting approval</p>
                </div>
            </div>
            <svg class="w-5 h-5 text-blue-600 transform transition-transform duration-200" :class="{ 'rotate-180': expanded }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
            </svg>
        </button>
        <div x-show="expanded" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 transform -translate-y-2" x-transition:enter-end="opacity-100 transform translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 transform translate-y-0" x-transition:leave-end="opacity-0 transform -translate-y-2" class="mt-4">
            <div class="space-y-2">
                <?php foreach ($pendingRequests as $request): ?>
                <div class="bg-white dark:bg-gray-800 p-4 rounded-lg shadow-sm border border-blue-200 dark:border-blue-800">
                    <div class="flex items-center justify-between">
                        <div class="flex-1">
                            <h4 class="font-semibold text-gray-900 dark:text-gray-100"><?php echo Security::escape($request['cfo_type']); ?> CFO Registry</h4>
                            <p class="text-xs text-gray-600 dark:text-gray-400 mt-1">
                                Requested: <?php echo date('M j, Y g:i A', strtotime($request['request_date'])); ?>
                            </p>
                        </div>
                        <span class="inline-flex items-center px-3 py-1 bg-blue-100 dark:bg-blue-900/50 text-blue-800 dark:text-blue-300 text-xs font-medium rounded-full">
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
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($canViewDataTable): ?>
    <!-- Show statistics and DataTable to users with view access -->
    
    <?php if ($hasApprovedAccess): ?>
    <!-- Show approved access banner for local_cfo -->
    <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg p-4 mb-4">
        <div class="flex items-center">
            <svg class="w-5 h-5 text-green-500 mr-2" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
            </svg>
            <span class="text-sm font-medium text-green-700 dark:text-green-300">You have approved access to view CFO data</span>
            <?php 
            // Find the view_data request and show expiration
            foreach ($approvedRequests as $req) {
                if ($req['access_mode'] === 'view_data' && $req['expires_at']) {
                    $daysRemaining = max(0, floor((strtotime($req['expires_at']) - time()) / 86400));
                    echo '<span class="ml-2 text-xs text-green-600 dark:text-green-400">(expires in ' . $daysRemaining . ' days)</span>';
                    break;
                }
            }
            ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Info Banner -->
    <div class="bg-blue-50 dark:bg-blue-900/20 border-l-4 border-blue-500 dark:border-blue-700 p-4 mb-6 rounded-r-lg">
        <div class="flex items-start">
            <svg class="w-5 h-5 text-blue-500 mr-3 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
            </svg>
            <div class="flex-1">
                <h3 class="text-sm font-semibold text-blue-800 dark:text-blue-300">Privacy-Protected View</h3>
                <p class="text-xs text-blue-700 dark:text-blue-400 mt-1">Names are <strong>obfuscated for privacy</strong>. Hover over names to see full details. To edit member information with full names displayed, use <a href="cfo-checker.php" class="underline font-semibold">CFO Checker</a>.</p>
            </div>
        </div>
    </div>
    
    <!-- Statistics -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <?php
        // Get CFO statistics
        $stats = [
            'total' => 0,
            'buklod' => 0,
            'kadiwa' => 0,
            'binhi' => 0,
            'active' => 0,
            'transferred' => 0
        ];
        
        try {
            $whereConditions = [];
            $params = [];
            
            // Default filter: active status
            $whereConditions[] = 'cfo_status = ?';
            $params[] = 'active';
            
            if ($currentUser['role'] === 'district') {
                $whereConditions[] = 'district_code = ?';
                $params[] = $currentUser['district_code'];
            } elseif ($currentUser['role'] === 'local' || $currentUser['role'] === 'local_cfo') {
                $whereConditions[] = 'local_code = ?';
                $params[] = $currentUser['local_code'];
            }
            
            $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
            
            // Total CFO members
            $stmt = $db->prepare("SELECT COUNT(*) as total FROM tarheta_control $whereClause");
            $stmt->execute($params);
            $stats['total'] = $stmt->fetch()['total'];
            
            // By classification
            $stmt = $db->prepare("
                SELECT 
                    cfo_classification,
                    COUNT(*) as count 
                FROM tarheta_control 
                $whereClause 
                GROUP BY cfo_classification
            ");
            $stmt->execute($params);
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
                $whereClause 
                GROUP BY cfo_status
            ");
            $stmt->execute($params);
            while ($row = $stmt->fetch()) {
                if ($row['cfo_status'] === 'active') {
                    $stats['active'] = $row['count'];
                } elseif ($row['cfo_status'] === 'transferred-out') {
                    $stats['transferred'] = $row['count'];
                }
            }
        } catch (Exception $e) {
            error_log("Error loading CFO stats: " . $e->getMessage());
        }
        ?>
        
        <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4 border border-blue-200 dark:border-blue-800">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-blue-700 font-medium">Total Members</p>
                    <p class="text-3xl font-bold text-blue-900" id="stat-total"><?php echo number_format($stats['total']); ?></p>
                </div>
                <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center">
                    <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                    </svg>
                </div>
            </div>
        </div>
        
        <div class="bg-purple-50 rounded-lg p-4 border border-purple-200">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-purple-700 font-medium">Buklod</p>
                    <p class="text-3xl font-bold text-purple-900" id="stat-buklod"><?php echo number_format($stats['buklod']); ?></p>
                    <p class="text-xs text-purple-600 mt-1">Married Couples</p>
                </div>
                <div class="w-12 h-12 bg-purple-100 rounded-full flex items-center justify-center">
                    <i class="fa-solid fa-rings-wedding text-xl text-purple-600"></i>
                </div>
            </div>
        </div>
        
        <div class="bg-green-50 dark:bg-green-900/20 rounded-lg p-4 border border-green-200 dark:border-green-800">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-green-700 font-medium">Kadiwa</p>
                    <p class="text-3xl font-bold text-green-900" id="stat-kadiwa"><?php echo number_format($stats['kadiwa']); ?></p>
                    <p class="text-xs text-green-600 mt-1">Youth</p>
                </div>
                <div class="w-12 h-12 bg-green-100 rounded-full flex items-center justify-center">
                    <i class="fa-solid fa-user-group text-xl text-green-600"></i>
                </div>
            </div>
        </div>
        
        <div class="bg-orange-50 rounded-lg p-4 border border-orange-200">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-orange-700 font-medium">Binhi</p>
                    <p class="text-3xl font-bold text-orange-900" id="stat-binhi"><?php echo number_format($stats['binhi']); ?></p>
                    <p class="text-xs text-orange-600 mt-1">Children</p>
                </div>
                <div class="w-12 h-12 bg-orange-100 rounded-full flex items-center justify-center">
                    <i class="fa-solid fa-seedling text-xl text-orange-600"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-4">
        <form id="filterForm" class="grid grid-cols-1 md:grid-cols-<?php 
            // Adjust grid based on role
            if ($currentUser['role'] === 'local' || $currentUser['role'] === 'local_cfo') {
                echo '4'; // Classification, Status, Missing Birthday, Apply button
            } elseif ($currentUser['role'] === 'district') {
                echo '5'; // Classification, Status, Missing Birthday, Local, Apply button
            } else {
                echo '6'; // All filters
            }
        ?> gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">CFO Classification</label>
                <select id="filterClassification" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                    <?php if ($needsAccessRequest && !empty($approvedCfoTypes)): ?>
                        <!-- Restricted user: only show approved CFO types -->
                        <?php if (count($approvedCfoTypes) > 1): ?>
                            <option value="">All Approved Types</option>
                        <?php endif; ?>
                        <?php foreach ($approvedCfoTypes as $type): ?>
                            <option value="<?php echo Security::escape($type); ?>"><?php echo Security::escape($type); ?></option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <!-- Full access: show all options -->
                        <option value="">All</option>
                        <option value="Buklod">Buklod</option>
                        <option value="Kadiwa">Kadiwa</option>
                        <option value="Binhi">Binhi</option>
                        <option value="null">Unclassified</option>
                    <?php endif; ?>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Status</label>
                <select id="filterStatus" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                    <option value="">All</option>
                    <option value="active" selected>Active</option>
                    <option value="transferred-out">Transferred Out</option>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Missing Data</label>
                <div class="flex items-center h-10">
                    <label class="inline-flex items-center cursor-pointer">
                        <input type="checkbox" id="filterMissingBirthday" class="w-4 h-4 text-blue-600 bg-gray-100 dark:bg-gray-700 border-gray-300 dark:border-gray-600 rounded focus:ring-blue-500 focus:ring-2">
                        <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">Missing Birthday</span>
                    </label>
                </div>
            </div>
            
            <?php if ($currentUser['role'] === 'admin'): ?>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">District</label>
                <select id="filterDistrict" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                    <option value="">All Districts</option>
                </select>
            </div>
            <?php elseif ($currentUser['role'] === 'district'): ?>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">District</label>
                <input type="text" id="filterDistrictDisplay" value="<?php 
                    try {
                        $stmt = $db->prepare("SELECT district_name FROM districts WHERE district_code = ?");
                        $stmt->execute([$currentUser['district_code']]);
                        $districtRow = $stmt->fetch();
                        echo Security::escape($districtRow ? $districtRow['district_name'] : '');
                    } catch (Exception $e) {}
                ?>" readonly class="w-full px-3 py-2 bg-gray-100 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-600 dark:text-gray-400 cursor-not-allowed">
                <input type="hidden" id="filterDistrict" value="<?php echo Security::escape($currentUser['district_code']); ?>">
            </div>
            <?php endif; ?>
            
            <?php if ($currentUser['role'] === 'admin'): ?>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Local</label>
                <select id="filterLocal" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="">All Locals</option>
                </select>
            </div>
            <?php elseif ($currentUser['role'] === 'district'): ?>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Local</label>
                <select id="filterLocal" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="">All Locals</option>
                </select>
            </div>
            <?php elseif ($currentUser['role'] === 'local' || $currentUser['role'] === 'local_cfo'): ?>
            <input type="hidden" id="filterDistrict" value="<?php echo Security::escape($currentUser['district_code']); ?>">
            <input type="hidden" id="filterLocal" value="<?php echo Security::escape($currentUser['local_code']); ?>">
            <?php endif; ?>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">&nbsp;</label>
                <button type="button" onclick="applyFilters();" class="w-full px-4 py-2 bg-blue-600 dark:bg-blue-500 text-white rounded-lg hover:bg-blue-700 dark:hover:bg-blue-600 transition-colors">
                    Apply Filters
                </button>
            </div>
        </form>
    </div>

    <!-- CFO Registry Table - Tailwind -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden" 
         x-data="cfoTable()" 
         x-init="init()">
        
        <!-- Table Header with Search and Export -->
        <div class="p-4 border-b border-gray-200 dark:border-gray-700">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">CFO Members</h2>
                <div class="flex flex-col sm:flex-row gap-3">
                    <!-- Search Input -->
                    <div class="relative">
                        <input type="text" 
                               x-model="searchQuery" 
                               @input.debounce.150ms="search()"
                               @keydown.enter="search()"
                               placeholder="Search members..."
                               class="w-full sm:w-64 pl-10 pr-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <svg class="absolute left-3 top-2.5 w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                        <button x-show="searchQuery.length > 0" @click="searchQuery = ''; search()" class="absolute right-3 top-2.5 text-gray-400 hover:text-gray-600">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                    <!-- Page Size -->
                    <select x-model="pageSize" @change="changePageSize()" 
                            class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500">
                        <option value="10">10 per page</option>
                        <option value="25">25 per page</option>
                        <option value="50">50 per page</option>
                        <option value="100">100 per page</option>
                    </select>
                    <!-- Export Button -->
                    <button @click="exportToExcel()" 
                            class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors font-medium">
                        <svg class="w-4 h-4 mr-2" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M9 2a2 2 0 00-2 2v8a2 2 0 002 2h6a2 2 0 002-2V6.414A2 2 0 0016.414 5L14 2.586A2 2 0 0012.586 2H9z"></path>
                            <path d="M3 8a2 2 0 012-2v10h8a2 2 0 01-2 2H5a2 2 0 01-2-2V8z"></path>
                        </svg>
                        Export Excel
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Table Content -->
        <div class="overflow-x-auto">
            <!-- Loading State -->
            <div x-show="loading" class="flex items-center justify-center py-12">
                <svg class="animate-spin h-8 w-8 text-blue-600 mr-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span class="text-gray-700 dark:text-gray-300">Loading data...</span>
            </div>
            
            <!-- Error State -->
            <div x-show="error && !loading" class="flex flex-col items-center justify-center py-12 text-center">
                <svg class="w-16 h-16 text-red-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <p class="text-red-600 dark:text-red-400 font-medium" x-text="error"></p>
                <button @click="fetchData()" class="mt-4 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                    Try Again
                </button>
            </div>
            
            <!-- Table -->
            <table x-show="!loading && !error" class="w-full">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th @click="sortBy('id')" class="cursor-pointer px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wider hover:bg-gray-100 dark:hover:bg-gray-600">
                            <div class="flex items-center gap-1">
                                ID
                                <template x-if="sortColumn === 'id'">
                                    <svg :class="{ 'rotate-180': sortDirection === 'asc' }" class="w-4 h-4 transition-transform" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                                    </svg>
                                </template>
                            </div>
                        </th>
                        <th @click="sortBy('name')" class="cursor-pointer px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wider hover:bg-gray-100 dark:hover:bg-gray-600">
                            <div class="flex items-center gap-1">
                                Name
                                <template x-if="sortColumn === 'name'">
                                    <svg :class="{ 'rotate-180': sortDirection === 'asc' }" class="w-4 h-4 transition-transform" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                                    </svg>
                                </template>
                            </div>
                        </th>
                        <th @click="sortBy('registry_number')" class="cursor-pointer px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wider hover:bg-gray-100 dark:hover:bg-gray-600">
                            <div class="flex items-center gap-1">
                                Registry #
                                <template x-if="sortColumn === 'registry_number'">
                                    <svg :class="{ 'rotate-180': sortDirection === 'asc' }" class="w-4 h-4 transition-transform" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                                    </svg>
                                </template>
                            </div>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wider">Birthday</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wider">Classification</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wider">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wider">Purok-Grupo</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wider">Local</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    <template x-for="member in members" :key="member.id">
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
                            <td class="px-4 py-3 text-sm text-gray-900 dark:text-gray-100 font-mono" x-text="member.id"></td>
                            <td class="px-4 py-3">
                                <span class="text-sm text-gray-900 dark:text-gray-100 cursor-help" 
                                      :title="member.name_real || ''"
                                      x-text="member.name"></span>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 font-mono" x-text="member.registry_number || '-'"></td>
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400" x-text="member.birthday || '-'"></td>
                            <td class="px-4 py-3">
                                <span x-show="member.cfo_classification === 'Buklod'" class="px-2 py-1 bg-purple-100 dark:bg-purple-900/50 text-purple-700 dark:text-purple-300 rounded text-xs font-medium">💑 Buklod</span>
                                <span x-show="member.cfo_classification === 'Kadiwa'" class="px-2 py-1 bg-green-100 dark:bg-green-900/50 text-green-700 dark:text-green-300 rounded text-xs font-medium">👥 Kadiwa</span>
                                <span x-show="member.cfo_classification === 'Binhi'" class="px-2 py-1 bg-orange-100 dark:bg-orange-900/50 text-orange-700 dark:text-orange-300 rounded text-xs font-medium">👶 Binhi</span>
                                <span x-show="!member.cfo_classification" class="px-2 py-1 bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 rounded text-xs">Unclassified</span>
                            </td>
                            <td class="px-4 py-3">
                                <span x-show="member.cfo_status === 'active'" class="px-2 py-1 bg-green-100 dark:bg-green-900/50 text-green-700 dark:text-green-300 rounded text-xs font-medium">✓ Active</span>
                                <span x-show="member.cfo_status === 'transferred-out'" class="px-2 py-1 bg-red-100 dark:bg-red-900/50 text-red-700 dark:text-red-300 rounded text-xs font-medium">→ Transferred</span>
                            </td>
                            <td class="px-4 py-3">
                                <span x-show="member.purok_grupo && member.purok_grupo !== '-'" class="px-2 py-1 bg-indigo-50 dark:bg-indigo-900/50 text-indigo-700 dark:text-indigo-300 rounded text-xs font-medium" x-text="member.purok_grupo"></span>
                                <span x-show="!member.purok_grupo || member.purok_grupo === '-'" class="text-gray-400 text-xs">-</span>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400" x-text="member.local_name || '-'"></td>
                            <td class="px-4 py-3 text-center">
                                <button @click="viewDetails(member.id)" 
                                        class="inline-flex items-center px-3 py-1.5 bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 rounded-lg hover:bg-blue-100 dark:hover:bg-blue-900/50 transition-all text-sm font-medium">
                                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                    </svg>
                                    View
                                </button>
                            </td>
                        </tr>
                    </template>
                    
                    <!-- Empty State -->
                    <tr x-show="members.length === 0 && !loading && !error">
                        <td colspan="9" class="px-4 py-12 text-center">
                            <svg class="w-16 h-16 mx-auto text-gray-300 dark:text-gray-600 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                            </svg>
                            <p class="text-gray-500 dark:text-gray-400 text-lg font-medium">No CFO members found</p>
                            <p class="text-gray-400 dark:text-gray-500 text-sm mt-1">Try adjusting your filters or search query</p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <div x-show="!loading && !error && totalRecords > 0" class="px-4 py-3 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-700/50">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div class="text-sm text-gray-600 dark:text-gray-400">
                    Showing <span class="font-medium" x-text="((currentPage - 1) * pageSize) + 1"></span> to 
                    <span class="font-medium" x-text="Math.min(currentPage * pageSize, totalRecords)"></span> of 
                    <span class="font-medium" x-text="totalRecords.toLocaleString()"></span> results
                </div>
                <div class="flex items-center gap-2">
                    <button @click="goToPage(1)" :disabled="currentPage === 1" 
                            class="px-3 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-600 disabled:opacity-50 disabled:cursor-not-allowed text-gray-700 dark:text-gray-300">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"></path>
                        </svg>
                    </button>
                    <button @click="goToPage(currentPage - 1)" :disabled="currentPage === 1" 
                            class="px-3 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-600 disabled:opacity-50 disabled:cursor-not-allowed text-gray-700 dark:text-gray-300">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                        </svg>
                    </button>
                    
                    <!-- Page Numbers -->
                    <template x-for="page in visiblePages" :key="page">
                        <button @click="goToPage(page)" 
                                :class="page === currentPage ? 'bg-blue-600 text-white border-blue-600' : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-600'"
                                class="px-3 py-1.5 text-sm border rounded-lg font-medium"
                                x-text="page">
                        </button>
                    </template>
                    
                    <button @click="goToPage(currentPage + 1)" :disabled="currentPage >= totalPages" 
                            class="px-3 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-600 disabled:opacity-50 disabled:cursor-not-allowed text-gray-700 dark:text-gray-300">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                        </svg>
                    </button>
                    <button @click="goToPage(totalPages)" :disabled="currentPage >= totalPages" 
                            class="px-3 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-600 disabled:opacity-50 disabled:cursor-not-allowed text-gray-700 dark:text-gray-300">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7"></path>
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Edit CFO Modal -->
<div id="editModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4 transition-opacity duration-300">
    <div class="bg-white dark:bg-gray-800 rounded-xl max-w-2xl w-full max-h-[90vh] overflow-y-auto shadow-2xl transform transition-all duration-300 scale-95" id="modalContent">
        <!-- Modal Header -->
        <div class="sticky top-0 bg-gradient-to-r from-blue-600 to-blue-700 p-6 rounded-t-xl z-10">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 bg-white bg-opacity-20 rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-xl font-bold text-white">View CFO Information</h3>
                        <p class="text-blue-100 text-sm">Member details (read-only)</p>
                    </div>
                </div>
                <button onclick="closeEditModal()" class="text-white hover:bg-white hover:bg-opacity-20 rounded-lg p-2 transition-all duration-200">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
        </div>
        
        <div class="p-6 space-y-6 relative">
            <input type="hidden" id="edit_id">
            
            <!-- Member Info -->
            <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-4 border border-gray-200 dark:border-gray-600">
                <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3 flex items-center">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                    </svg>
                    Member Information
                </h4>
                <div class="space-y-3">
                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">First Name</label>
                            <input type="text" id="edit_first_name" readonly class="w-full px-3 py-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-700 text-gray-700 dark:text-gray-300">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Middle Name</label>
                            <input type="text" id="edit_middle_name" readonly class="w-full px-3 py-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-700 text-gray-700 dark:text-gray-300">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Last Name</label>
                            <input type="text" id="edit_last_name" readonly class="w-full px-3 py-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-700 text-gray-700 dark:text-gray-300">
                        </div>
                    </div>
                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Registry Number</label>
                            <input type="text" id="edit_registry" readonly class="w-full px-3 py-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Birthday</label>
                            <input type="text" id="edit_birthday" readonly class="w-full px-3 py-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-700 text-gray-700 dark:text-gray-300 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Husband's Surname</label>
                            <input type="text" id="edit_husbands_surname" readonly class="w-full px-3 py-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-700 text-gray-700 dark:text-gray-300 text-sm">
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fa-solid fa-map-location-dot mr-1 text-blue-600 dark:text-blue-400"></i>
                                Purok
                            </label>
                            <input type="text" id="edit_purok" readonly class="w-full px-3 py-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-700 text-gray-700 dark:text-gray-300 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                                <i class="fa-solid fa-users mr-1 text-green-600 dark:text-green-400"></i>
                                Grupo
                            </label>
                            <input type="text" id="edit_grupo" readonly class="w-full px-3 py-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-700 text-gray-700 dark:text-gray-300 text-sm">
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- View-Only Fields -->
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2 flex items-center">
                        <svg class="w-4 h-4 mr-2 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                        </svg>
                        CFO Classification
                    </label>
                    <input type="text" id="edit_classification" readonly class="w-full px-4 py-3 border border-gray-200 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-700 text-gray-700 dark:text-gray-300">
                </div>
                
                <div>
                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2 flex items-center">
                        <svg class="w-4 h-4 mr-2 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        Status
                    </label>
                    <input type="text" id="edit_status" readonly class="w-full px-4 py-3 border border-gray-200 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-700 text-gray-700 dark:text-gray-300">
                </div>
                
                <div>
                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2 flex items-center">
                        <svg class="w-4 h-4 mr-2 text-gray-600 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"></path>
                        </svg>
                        Notes
                    </label>
                    <textarea id="edit_notes" readonly rows="3" class="w-full px-4 py-3 border border-gray-200 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-700 text-gray-700 dark:text-gray-300 resize-none"></textarea>
                </div>
            </div>
            
            <!-- Action Buttons -->
            <div class="flex gap-3 pt-4 border-t border-gray-200 dark:border-gray-700">
                <a href="cfo-checker.php" class="flex-1 px-6 py-3 bg-gradient-to-r from-purple-600 to-purple-700 text-white rounded-lg hover:from-purple-700 hover:to-purple-800 transition-all duration-200 font-semibold shadow-lg hover:shadow-xl transform hover:scale-105 flex items-center justify-center">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                    </svg>
                    Edit in CFO Checker
                </a>
                <button type="button" onclick="closeEditModal()" class="px-6 py-3 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-all duration-200 font-semibold">
                    Close
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Loading Overlay -->
<div id="loadingOverlay" class="hidden fixed inset-0 bg-black bg-opacity-30 z-40 flex items-center justify-center">
    <div class="bg-white dark:bg-gray-800 rounded-xl p-6 shadow-2xl flex flex-col items-center space-y-3">
        <svg class="animate-spin h-12 w-12 text-blue-600 dark:text-blue-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
        <p class="text-gray-700 dark:text-gray-300 font-medium">Loading...</p>
    </div>
</div>

<!-- Success Toast -->
<div id="successToast" class="hidden fixed top-4 right-4 z-50 bg-green-500 dark:bg-green-600 text-white px-6 py-4 rounded-lg shadow-lg transform transition-all duration-300 translate-x-full">
    <div class="flex items-center space-x-3">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
        </svg>
        <span class="font-semibold" id="toastMessage">Success!</span>
    </div>
</div>

<!-- Error Toast -->
<div id="errorToast" class="hidden fixed top-4 right-4 z-50 bg-red-500 dark:bg-red-600 text-white px-6 py-4 rounded-lg shadow-lg transform transition-all duration-300 translate-x-full">
    <div class="flex items-center space-x-3">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
        </svg>
        <span class="font-semibold" id="errorMessage">Error occurred!</span>
    </div>
</div>

<script nonce="<?php echo $csp_nonce; ?>">
// Approved CFO types for local_cfo users (empty array means all types allowed)
const approvedCfoTypes = <?php echo $approvedCfoTypesJson ?? '[]'; ?>;
const needsAccessRestriction = <?php echo $needsAccessRequest ? 'true' : 'false'; ?>;
const baseUrl = '<?php echo BASE_URL; ?>';

// Alpine.js CFO Table Component
function cfoTable() {
    return {
        members: [],
        loading: true,
        error: null,
        searchQuery: '',
        currentPage: 1,
        pageSize: 25,
        totalRecords: 0,
        totalPages: 0,
        sortColumn: 'id',
        sortDirection: 'desc',
        
        get visiblePages() {
            const pages = [];
            const maxVisible = 5;
            let start = Math.max(1, this.currentPage - Math.floor(maxVisible / 2));
            let end = Math.min(this.totalPages, start + maxVisible - 1);
            
            if (end - start + 1 < maxVisible) {
                start = Math.max(1, end - maxVisible + 1);
            }
            
            for (let i = start; i <= end; i++) {
                pages.push(i);
            }
            return pages;
        },
        
        init() {
            this.fetchData();
            this.loadDistricts();
            
            // Listen for filter changes
            document.getElementById('filterClassification')?.addEventListener('change', () => this.applyFilters());
            document.getElementById('filterStatus')?.addEventListener('change', () => this.applyFilters());
            document.getElementById('filterMissingBirthday')?.addEventListener('change', () => this.applyFilters());
        },
        
        getFilters() {
            return {
                classification: document.getElementById('filterClassification')?.value || '',
                status: document.getElementById('filterStatus')?.value || '',
                district: document.getElementById('filterDistrict')?.value || '',
                local: document.getElementById('filterLocal')?.value || '',
                missing_birthday: document.getElementById('filterMissingBirthday')?.checked ? '1' : '',
                approved_cfo_types: needsAccessRestriction && approvedCfoTypes.length > 0 ? approvedCfoTypes.join(',') : ''
            };
        },
        
        async fetchData() {
            this.loading = true;
            this.error = null;
            
            const filters = this.getFilters();
            const params = new URLSearchParams({
                draw: 1,
                start: (this.currentPage - 1) * this.pageSize,
                length: this.pageSize,
                'search[value]': this.searchQuery,
                'order[0][column]': this.getColumnIndex(),
                'order[0][dir]': this.sortDirection,
                ...filters
            });
            
            try {
                const response = await fetch('api/get-cfo-data.php?' + params.toString());
                const data = await response.json();
                
                if (data.error) {
                    this.error = data.error;
                    this.members = [];
                    return;
                }
                
                this.members = data.data || [];
                this.totalRecords = data.recordsFiltered || 0;
                this.totalPages = Math.ceil(this.totalRecords / this.pageSize);
                
            } catch (err) {
                console.error('Error fetching data:', err);
                this.error = 'Failed to load data. Please try again.';
                this.members = [];
            } finally {
                this.loading = false;
            }
        },
        
        getColumnIndex() {
            const columns = ['id', 'name', 'last_name', 'first_name', 'middle_name', 'registry_number', 'husbands_surname', 'birthday', 'cfo_classification', 'cfo_status', 'purok_grupo', 'district_name', 'local_name'];
            return columns.indexOf(this.sortColumn);
        },
        
        search() {
            this.currentPage = 1;
            this.fetchData();
        },
        
        sortBy(column) {
            if (this.sortColumn === column) {
                this.sortDirection = this.sortDirection === 'asc' ? 'desc' : 'asc';
            } else {
                this.sortColumn = column;
                this.sortDirection = 'asc';
            }
            this.fetchData();
        },
        
        goToPage(page) {
            if (page < 1 || page > this.totalPages) return;
            this.currentPage = page;
            this.fetchData();
        },
        
        changePageSize() {
            this.currentPage = 1;
            this.fetchData();
        },
        
        applyFilters() {
            this.currentPage = 1;
            this.fetchData();
            this.updateStats();
        },
        
        async updateStats() {
            const filters = this.getFilters();
            
            try {
                const response = await fetch('api/get-cfo-data.php?' + new URLSearchParams({
                    action: 'stats',
                    ...filters
                }));
                const data = await response.json();
                
                if (data.stats) {
                    document.getElementById('stat-total').textContent = data.stats.total.toLocaleString();
                    document.getElementById('stat-buklod').textContent = data.stats.buklod.toLocaleString();
                    document.getElementById('stat-kadiwa').textContent = data.stats.kadiwa.toLocaleString();
                    document.getElementById('stat-binhi').textContent = data.stats.binhi.toLocaleString();
                }
            } catch (error) {
                console.error('Error updating stats:', error);
            }
        },
        
        async viewDetails(id) {
            window.viewDetails(id);
        },
        
        exportToExcel() {
            const filters = this.getFilters();
            const params = new URLSearchParams();
            
            if (filters.classification) params.append('classification', filters.classification);
            if (filters.status) params.append('status', filters.status);
            if (filters.district) params.append('district', filters.district);
            if (filters.local) params.append('local', filters.local);
            if (this.searchQuery) params.append('search', this.searchQuery);
            
            window.location.href = baseUrl + '/api/export-cfo-excel.php?' + params.toString();
        },
        
        async loadDistricts() {
            try {
                const response = await fetch('api/get-districts.php');
                const result = await response.json();
                
                if (!result.success || !result.districts) return;
                
                const filterDistrict = document.getElementById('filterDistrict');
                if (filterDistrict && filterDistrict.tagName === 'SELECT') {
                    let html = '<option value="">All Districts</option>';
                    result.districts.forEach(district => {
                        html += `<option value="${district.district_code}">${district.district_name}</option>`;
                    });
                    filterDistrict.innerHTML = html;
                }
                
                // Load locals if district is pre-selected
                const districtCode = filterDistrict?.value;
                if (districtCode) {
                    this.loadLocalsForDistrict(districtCode);
                }
            } catch (error) {
                console.error('Error loading districts:', error);
            }
        },
        
        async loadLocalsForDistrict(districtCode) {
            const filterLocal = document.getElementById('filterLocal');
            if (!filterLocal || filterLocal.tagName !== 'SELECT') return;
            
            if (!districtCode) {
                filterLocal.innerHTML = '<option value="">All Locals</option>';
                return;
            }
            
            try {
                const response = await fetch('api/get-locals.php?district=' + districtCode);
                const data = await response.json();
                
                let html = '<option value="">All Locals</option>';
                data.forEach(local => {
                    html += `<option value="${local.local_code}">${local.local_name}</option>`;
                });
                filterLocal.innerHTML = html;
            } catch (error) {
                console.error('Error loading locals:', error);
            }
        }
    };
}

// Global function for Apply Filters button
function applyFilters() {
    // Dispatch custom event that Alpine can listen to
    document.querySelector('[x-data]')?.dispatchEvent(new CustomEvent('apply-filters'));
    
    // Also trigger the Alpine component directly
    const alpineComponent = document.querySelector('[x-data="cfoTable()"]');
    if (alpineComponent && alpineComponent.__x) {
        alpineComponent.__x.$data.applyFilters();
    }
}

function showLoading() {
    document.getElementById('loadingOverlay').classList.remove('hidden');
}

function hideLoading() {
    document.getElementById('loadingOverlay').classList.add('hidden');
}

function showSuccess(message) {
    const toast = document.getElementById('successToast');
    document.getElementById('toastMessage').textContent = message;
    toast.classList.remove('hidden', 'translate-x-full');
    setTimeout(() => {
        toast.classList.add('translate-x-full');
        setTimeout(() => toast.classList.add('hidden'), 300);
    }, 3000);
}

function showError(message) {
    const toast = document.getElementById('errorToast');
    document.getElementById('errorMessage').textContent = message;
    toast.classList.remove('hidden', 'translate-x-full');
    setTimeout(() => {
        toast.classList.add('translate-x-full');
        setTimeout(() => toast.classList.add('hidden'), 300);
    }, 5000);
}

// District change handler
document.getElementById('filterDistrict')?.addEventListener('change', async function() {
    const districtCode = this.value;
    const filterLocal = document.getElementById('filterLocal');
    
    if (!filterLocal || filterLocal.tagName !== 'SELECT') return;
    
    if (!districtCode) {
        filterLocal.innerHTML = '<option value="">All Locals</option>';
        return;
    }
    
    try {
        const response = await fetch('api/get-locals.php?district=' + districtCode);
        const data = await response.json();
        
        let html = '<option value="">All Locals</option>';
        data.forEach(local => {
            html += `<option value="${local.local_code}">${local.local_name}</option>`;
        });
        filterLocal.innerHTML = html;
    } catch (error) {
        console.error('Error loading locals:', error);
    }
});

async function viewDetails(id) {
    // Show modal
    const modal = document.getElementById('editModal');
    const content = document.getElementById('modalContent');
    
    modal.classList.remove('hidden');
    setTimeout(() => {
        modal.classList.remove('opacity-0');
        content.classList.remove('scale-95');
        content.classList.add('scale-100');
    }, 10);
    
    try {
        const response = await fetch('api/get-cfo-details.php?id=' + id);
        const data = await response.json();
        
        if (data.error) {
            showError(data.error);
            closeEditModal();
            return;
        }
        
        // Show details in modal (all readonly)
        document.getElementById('edit_id').value = data.id;
        document.getElementById('edit_first_name').value = data.first_name || '';
        document.getElementById('edit_middle_name').value = data.middle_name || '';
        document.getElementById('edit_last_name').value = data.last_name || '';
        document.getElementById('edit_husbands_surname').value = data.husbands_surname || '';
        document.getElementById('edit_registry').value = data.registry_number;
        document.getElementById('edit_birthday').value = data.birthday || '';
        document.getElementById('edit_purok').value = data.purok || '';
        document.getElementById('edit_grupo').value = data.grupo || '';
        
        // Format classification with emoji
        const classifications = {
            'Buklod': '💑 Buklod (Married Couples)',
            'Kadiwa': '👥 Kadiwa (Youth 18+)',
            'Binhi': '🌱 Binhi (Children under 18)'
        };
        document.getElementById('edit_classification').value = classifications[data.cfo_classification] || data.cfo_classification || 'Unclassified';
        
        // Format status
        const status = data.cfo_status === 'transferred-out' ? '→ Transferred Out' : '✓ Active';
        document.getElementById('edit_status').value = status;
        
        document.getElementById('edit_notes').value = data.cfo_notes || '';
        
    } catch (error) {
        showError('Failed to load details');
        closeEditModal();
    }
}

// editCFO function removed - editing moved to CFO Checker page

function closeEditModal() {
    const modal = document.getElementById('editModal');
    const content = document.getElementById('modalContent');
    
    content.classList.remove('scale-100');
    content.classList.add('scale-95');
    modal.classList.add('opacity-0');
    
    setTimeout(() => {
        modal.classList.add('hidden');
        // Clear all fields
        ['edit_id', 'edit_first_name', 'edit_middle_name', 'edit_last_name', 'edit_husbands_surname', 'edit_registry', 'edit_birthday', 'edit_purok', 'edit_grupo', 'edit_classification', 'edit_status', 'edit_notes'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.value = '';
        });
    }, 300);
}

// Form submission removed - editing moved to CFO Checker page

// Close modal on ESC key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeEditModal();
    }
});

// Close modal on backdrop click
document.getElementById('editModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeEditModal();
    }
});

// submitAccessRequest function
async function submitAccessRequest() {
    // Get all checked access modes
    const checkedModes = document.querySelectorAll('input[name="access_modes[]"]:checked');
    const accessModes = Array.from(checkedModes).map(cb => cb.value);
    const cfoType = document.getElementById('accessRequestCfoType').value;
    const submitBtn = document.getElementById('submitAccessRequestBtn');
    
    if (accessModes.length === 0) {
        alert('Please select at least one access type.');
        return;
    }
    
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<svg class="animate-spin h-4 w-4 mr-2 inline-block" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>Submitting...';
    
    try {
        const response = await fetch('<?php echo BASE_URL; ?>/api/request-cfo-access.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                cfo_type: cfoType,
                access_modes: accessModes
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert('✅ Access request submitted successfully! You will be notified when approved.');
            closeAccessRequestModal();
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

// Update stepper final step label based on access modes (checkboxes)
function updateCfoStepperLabel() {
    const checkedModes = document.querySelectorAll('input[name="access_modes[]"]:checked');
    const values = Array.from(checkedModes).map(cb => cb.value);
    const stepLabel = document.querySelector('#stepperFinalStep span');
    if (stepLabel) {
        // If add_member or edit_member selected, show VERIFIED
        if (values.includes('add_member') || values.includes('edit_member')) {
            stepLabel.textContent = '(VERIFIED)';
        } else {
            stepLabel.textContent = '(APPROVED)';
        }
    }
}

document.querySelectorAll('input[name="access_modes[]"]').forEach(checkbox => {
    checkbox.addEventListener('change', updateCfoStepperLabel);
});

</script>

<!-- Access Request Modal -->
<?php if ($needsAccessRequest): ?>
<div id="accessRequestModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border border-gray-200 dark:border-gray-700 w-full max-w-lg shadow-lg rounded-lg bg-white dark:bg-gray-800">
        <div class="flex items-center justify-between pb-3 border-b border-gray-200 dark:border-gray-700">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Request CFO Registry Access</h3>
            <button onclick="closeAccessRequestModal()" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>
        
        <div class="mt-4 space-y-4">
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
            
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">CFO Type</label>
                <select id="accessRequestCfoType" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                    <option value="Buklod">Buklod</option>
                    <option value="Kadiwa">Kadiwa</option>
                    <option value="Binhi">Binhi</option>
                </select>
                <p class="text-xs text-gray-500 mt-1">Select which CFO registry you need access to.</p>
            </div>
            
            <!-- Access Mode Selection (Multiple Checkboxes) -->
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Access Types <span class="text-xs text-gray-500">(select all that apply)</span></label>
                <div class="space-y-2">
                    <label class="flex items-center p-3 border border-gray-200 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                        <input type="checkbox" name="access_modes[]" value="view_data" checked 
                               class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                        <div class="ml-3">
                            <span class="block text-sm font-medium text-gray-900 dark:text-gray-100">View Data Table</span>
                            <span class="block text-xs text-gray-500 dark:text-gray-400">View CFO members in data table format</span>
                        </div>
                    </label>
                    <label class="flex items-center p-3 border border-gray-200 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                        <input type="checkbox" name="access_modes[]" value="add_member" 
                               class="w-4 h-4 text-green-600 border-gray-300 rounded focus:ring-green-500">
                        <div class="ml-3">
                            <span class="block text-sm font-medium text-gray-900 dark:text-gray-100">Add Members</span>
                            <span class="block text-xs text-gray-500 dark:text-gray-400">Add new CFO members (requires LORC verification)</span>
                        </div>
                    </label>
                    <label class="flex items-center p-3 border border-gray-200 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                        <input type="checkbox" name="access_modes[]" value="edit_member" 
                               class="w-4 h-4 text-yellow-600 border-gray-300 rounded focus:ring-yellow-500">
                        <div class="ml-3">
                            <span class="block text-sm font-medium text-gray-900 dark:text-gray-100">Edit Members</span>
                            <span class="block text-xs text-gray-500 dark:text-gray-400">Edit existing CFO members (requires LORC verification)</span>
                        </div>
                    </label>
                </div>
            </div>
            
            <!-- Stepper Preview -->
            <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4">
                <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Approval Workflow</h4>
                <div class="flex items-center justify-center">
                    <div class="flex items-center">
                        <div class="flex flex-col items-center">
                            <div class="w-8 h-8 rounded-full bg-blue-500 flex items-center justify-center">
                                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                            </div>
                            <span class="mt-1 text-xs text-gray-600 dark:text-gray-400">SUBMITTED</span>
                        </div>
                        <div class="w-16 h-0.5 bg-gray-300 dark:bg-gray-600 mx-1"></div>
                        <div class="flex flex-col items-center">
                            <div class="w-8 h-8 rounded-full bg-yellow-500 flex items-center justify-center">
                                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                            </div>
                            <span class="mt-1 text-xs text-gray-600 dark:text-gray-400">PENDING LORC</span>
                        </div>
                        <div class="w-16 h-0.5 bg-gray-300 dark:bg-gray-600 mx-1"></div>
                        <div class="flex flex-col items-center" id="stepperFinalStep">
                            <div class="w-8 h-8 rounded-full bg-green-500 flex items-center justify-center">
                                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                </svg>
                            </div>
                            <span class="mt-1 text-xs text-green-600 dark:text-green-400 font-semibold">(APPROVED)</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-3">
                <p class="text-xs text-blue-800 dark:text-blue-300">
                    <strong>📋 What happens next:</strong>
                </p>
                <ul class="text-xs text-blue-700 dark:text-blue-400 mt-2 space-y-1 list-disc list-inside">
                    <li>Your request will be sent to senior accounts for approval</li>
                    <li>Once approved, you'll be able to view the CFO data table</li>
                    <li>Add/Edit requests require additional LORC verification</li>
                    <li>All access is logged for security purposes</li>
                </ul>
            </div>
        </div>
        
        <div class="mt-6 flex gap-3 justify-end border-t border-gray-200 dark:border-gray-700 pt-4">
            <button onclick="closeAccessRequestModal()" 
                    class="px-4 py-2 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors">
                Cancel
            </button>
            <button id="submitAccessRequestBtn"
                    onclick="submitAccessRequest()" 
                    class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                Submit Request
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/includes/layout.php';
?>
