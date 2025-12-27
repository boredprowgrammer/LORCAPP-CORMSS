<?php
/**
 * Officer Requests List
 * View and manage aspiring church officer requests
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';

Security::requireLogin();

$db = Database::getInstance()->getConnection();
$user = getCurrentUser();

// Check permissions - admin, district, and local users can manage
$canManage = in_array($user['role'], ['admin', 'district', 'local']);

// Get filter parameters
$statusFilter = $_GET['status'] ?? 'all';
$searchQuery = $_GET['search'] ?? '';

// Build query
$query = "SELECT 
    r.request_id,
    r.last_name_encrypted,
    r.first_name_encrypted,
    r.middle_initial_encrypted,
    r.record_code,
    r.existing_officer_uuid,
    r.district_code,
    r.requested_department,
    r.requested_duty,
    r.status,
    r.requested_at,
    r.seminar_date,
    r.oath_scheduled_date,
    r.is_imported,
    r.lorcapp_id,
    d.district_name,
    l.local_name,
    u.full_name as requested_by_name,
    o.last_name_encrypted as existing_last_name,
    o.first_name_encrypted as existing_first_name,
    o.middle_initial_encrypted as existing_middle_initial
FROM officer_requests r
LEFT JOIN districts d ON r.district_code = d.district_code
LEFT JOIN local_congregations l ON r.local_code = l.local_code
LEFT JOIN users u ON r.requested_by = u.user_id
LEFT JOIN officers o ON r.existing_officer_uuid = o.officer_uuid
WHERE 1=1";

$params = [];

// Role-based filtering
if ($user['role'] === 'district') {
    $query .= " AND r.district_code = ?";
    $params[] = $user['district_code'];
} elseif ($user['role'] === 'local') {
    $query .= " AND r.local_code = ?";
    $params[] = $user['local_code'];
}

// Status filter
if ($statusFilter === 'all') {
    // Auto-hide oath_taken by default when showing all
    $query .= " AND r.status != 'oath_taken'";
} elseif ($statusFilter !== 'all') {
    $query .= " AND r.status = ?";
    $params[] = $statusFilter;
}


// Powerful search filter (multi-word, partial, fuzzy)
if (!empty($searchQuery)) {
    $query .= " AND (r.requested_department LIKE ? OR d.district_name LIKE ? OR l.local_name LIKE ?";
    $searchParam = "%$searchQuery%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;

    // Split search query into words for multi-word matching
    $words = preg_split('/\s+/', strtolower($searchQuery));
    foreach ($words as $word) {
        if (strlen($word) < 2) continue;
        // Applicant names
        $query .= " OR LOWER(AES_DECRYPT(r.last_name_encrypted, UNHEX(SHA2(r.district_code, 256)))) LIKE ?";
        $query .= " OR LOWER(AES_DECRYPT(r.first_name_encrypted, UNHEX(SHA2(r.district_code, 256)))) LIKE ?";
        $params[] = "%$word%";
        $params[] = "%$word%";
        // Existing officer names (CODE D)
        $query .= " OR LOWER(AES_DECRYPT(o.last_name_encrypted, UNHEX(SHA2(r.district_code, 256)))) LIKE ?";
        $query .= " OR LOWER(AES_DECRYPT(o.first_name_encrypted, UNHEX(SHA2(r.district_code, 256)))) LIKE ?";
        $params[] = "%$word%";
        $params[] = "%$word%";
    }
    $query .= ")";
}

$query .= " ORDER BY r.requested_at DESC";

$stmt = $db->prepare($query);
if (!empty($params)) {
    $stmt->execute($params);
} else {
    $stmt->execute();
}
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get counts for each status
$countsQuery = "SELECT status, COUNT(*) as count FROM officer_requests WHERE 1=1";
$countsParams = [];

if ($user['role'] === 'district') {
    $countsQuery .= " AND district_code = ?";
    $countsParams[] = $user['district_code'];
} elseif ($user['role'] === 'local') {
    $countsQuery .= " AND local_code = ?";
    $countsParams[] = $user['local_code'];
}

$countsQuery .= " GROUP BY status";
$countsStmt = $db->prepare($countsQuery);
$countsStmt->execute($countsParams);
$statusCounts = $countsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Status display configuration
$statusConfig = [
    'pending' => ['label' => 'Pending', 'color' => 'yellow', 'icon' => 'clock'],
    'requested_to_seminar' => ['label' => 'Requested to Seminar', 'color' => 'blue', 'icon' => 'book'],
    'in_seminar' => ['label' => 'In Seminar/Circular', 'color' => 'indigo', 'icon' => 'academic-cap'],
    'seminar_completed' => ['label' => 'Seminar Completed', 'color' => 'green', 'icon' => 'check-circle'],
    'requested_to_oath' => ['label' => 'Requested to Oath', 'color' => 'purple', 'icon' => 'hand'],
    'ready_to_oath' => ['label' => 'Ready to Oath', 'color' => 'pink', 'icon' => 'calendar'],
    'oath_taken' => ['label' => 'Oath Taken', 'color' => 'green', 'icon' => 'badge-check'],
    'rejected' => ['label' => 'Rejected', 'color' => 'red', 'icon' => 'x-circle'],
    'cancelled' => ['label' => 'Cancelled', 'color' => 'gray', 'icon' => 'ban']
];

$pageTitle = "Officer Requests";
ob_start();
?>

<div class="p-6 space-y-6">
    <?php if (!empty($_SESSION['success'])): ?>
        <div class="mb-6 bg-green-50 border border-green-200 rounded-lg p-4">
            <div class="flex items-center">
                <svg class="w-5 h-5 text-green-600 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                </svg>
                <span class="text-sm font-medium text-green-800"><?php echo Security::escape($_SESSION['success']); ?></span>
            </div>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>
    <?php if (!empty($_SESSION['error'])): ?>
        <div class="mb-6 bg-red-50 border border-red-200 rounded-lg p-4">
            <div class="flex items-center">
                <svg class="w-5 h-5 text-red-600 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                </svg>
                <span class="text-sm font-medium text-red-800"><?php echo Security::escape($_SESSION['error']); ?></span>
            </div>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-3xl font-bold text-gray-900">Officer Requests</h2>
            <p class="text-sm text-gray-500">Manage aspiring church officer applications</p>
        </div>
        <div class="flex items-center space-x-3">
            <?php if ($canManage): ?>
            <a href="import-from-lorcapp.php" class="inline-flex items-center px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 focus:ring-2 focus:ring-purple-500 focus:ring-offset-2 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3V10"></path>
                </svg>
                Import from LORCAPP
            </a>
            <a href="link-to-lorcapp.php" class="inline-flex items-center px-4 py-2 border border-purple-300 text-purple-700 rounded-lg hover:bg-purple-50 focus:ring-2 focus:ring-purple-500 focus:ring-offset-2 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path>
                </svg>
                Link to LORCAPP
            </a>
            <a href="bulk-palasumpaan.php" class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 focus:ring-2 focus:ring-green-500 focus:ring-offset-2 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
                Bulk Palasumpaan
            </a>
            <?php endif; ?>
            <?php if ($user['role'] !== 'admin'): ?>
            <a href="add.php" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                </svg>
                New Request
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
        <?php
        $displayStatuses = ['pending', 'requested_to_seminar', 'in_seminar', 'requested_to_oath', 'ready_to_oath'];
        foreach ($displayStatuses as $status):
            $count = $statusCounts[$status] ?? 0;
            $config = $statusConfig[$status];
        ?>
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500"><?php echo $config['label']; ?></p>
                    <p class="text-2xl font-bold text-<?php echo $config['color']; ?>-600"><?php echo $count; ?></p>
                </div>
                <div class="w-10 h-10 bg-<?php echo $config['color']; ?>-100 rounded-lg flex items-center justify-center">
                    <svg class="w-6 h-6 text-<?php echo $config['color']; ?>-600" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/>
                    </svg>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
        <form method="GET" class="flex flex-wrap gap-4">
            <div class="flex-1 min-w-[200px]">
                <input 
                    type="text" 
                    name="search" 
                    value="<?php echo Security::escape($searchQuery); ?>"
                    placeholder="Search by name, department, district..." 
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                >
            </div>
            <div class="min-w-[200px]">
                <div class="relative">
                    <input 
                        type="text" 
                        id="status-display"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 cursor-pointer bg-white"
                        placeholder="All Status"
                        readonly
                        onclick="openStatusModal()"
                        value="<?php 
                            if ($statusFilter === 'all') {
                                echo 'All Status';
                            } else {
                                echo Security::escape($statusConfig[$statusFilter]['label'] ?? 'All Status');
                            }
                        ?>"
                    >
                    <input type="hidden" name="status" id="status-value" value="<?php echo Security::escape($statusFilter); ?>">
                    <div class="absolute inset-y-0 right-0 flex items-center px-3 pointer-events-none">
                        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                </div>
            </div>
            <button type="submit" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                </svg>
                Filter
            </button>
            <?php if ($statusFilter !== 'all' || !empty($searchQuery)): ?>
            <a href="list.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg text-gray-700 bg-white hover:bg-gray-50 transition-colors">
                Clear
            </a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Bulk Actions Toolbar (hidden by default) -->
    <?php if ($canManage): ?>
    <div id="bulkActionsBar" class="bg-blue-50 border-l-4 border-blue-500 rounded-lg shadow-sm p-4 mb-4 hidden">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <span class="text-sm font-medium text-blue-900">
                    <span id="selectedCount">0</span> selected
                </span>
                <button onclick="clearSelection()" class="text-sm text-blue-700 hover:text-blue-800 underline">
                    Clear selection
                </button>
            </div>
            <div class="flex items-center gap-2">
                <select id="bulkAction" class="px-3 py-2 border border-blue-300 rounded-lg text-sm bg-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="">Select action...</option>
                    <option value="approve_seminar">Approve for Seminar</option>
                    <option value="mark_in_seminar">Mark In Seminar</option>
                    <option value="complete_seminar">Complete Seminar</option>
                    <option value="approve_oath">Approve for Oath</option>
                    <option value="mark_ready_oath">Mark Ready for Oath</option>
                    <option value="export">Export Selected</option>
                </select>
                <button onclick="applyBulkAction()" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors text-sm font-medium">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                    Apply
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Requests Cards -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
        <?php if (empty($requests)): ?>
            <div class="p-8 text-center text-gray-500">
                <p class="font-medium">No requests found</p>
                <p class="text-sm">Try adjusting your filters or create a new request</p>
            </div>
        <?php else: ?>
            <!-- Hidden selectAll to keep JS compatibility -->
            <input type="checkbox" id="selectAll" style="display:none;" onchange="toggleSelectAll(this)">

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php foreach ($requests as $request):
                    // Decrypt name
                    if ($request['record_code'] === 'D' && $request['existing_officer_uuid']) {
                        $lastName = Encryption::decrypt($request['existing_last_name'], $request['district_code']);
                        $firstName = Encryption::decrypt($request['existing_first_name'], $request['district_code']);
                        $middleInitial = $request['existing_middle_initial'] ? Encryption::decrypt($request['existing_middle_initial'], $request['district_code']) : '';
                    } else {
                        $lastName = Encryption::decrypt($request['last_name_encrypted'], $request['district_code']);
                        $firstName = Encryption::decrypt($request['first_name_encrypted'], $request['district_code']);
                        $middleInitial = $request['middle_initial_encrypted'] ? Encryption::decrypt($request['middle_initial_encrypted'], $request['district_code']) : '';
                    }
                    if ($lastName === '' && $firstName === '') {
                        $fullName = '[DECRYPT ERROR]';
                    } else {
                        $fullName = "$lastName, $firstName" . ($middleInitial ? " $middleInitial." : "");
                    }
                    $statusInfo = $statusConfig[$request['status']];
                ?>
                <div class="bg-white border border-gray-100 rounded-lg p-4 shadow-sm hover:shadow-md transition-shadow">
                    <div class="flex items-start justify-between">
                        <div class="flex items-start gap-3">
                            <div class="h-10 w-10 rounded-full bg-blue-100 flex items-center justify-center text-blue-600 font-medium">
                                <?php echo strtoupper(substr($fullName, 0, 1)); ?>
                            </div>
                            <div>
                                <div class="text-sm font-semibold text-gray-900"><?php echo Security::escape($fullName); ?></div>
                                <div class="text-xs text-gray-500"><?php echo Security::escape($request['local_name']); ?> • <?php echo Security::escape($request['district_name']); ?></div>
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-<?php echo $statusInfo['color']; ?>-100 text-<?php echo $statusInfo['color']; ?>-800">
                                <?php echo $statusInfo['label']; ?>
                            </div>
                        </div>
                    </div>

                    <div class="mt-3 text-sm text-gray-700">
                        <div><?php echo Security::escape($request['requested_department']); ?> <?php if ($request['requested_duty']): ?><span class="text-xs text-gray-500">• <?php echo Security::escape($request['requested_duty']); ?></span><?php endif; ?></div>
                        <div class="text-xs text-gray-500 mt-2">Requested: <?php echo date('m/d/Y', strtotime($request['requested_at'])); ?></div>
                        <?php if ($request['seminar_date']): ?><div class="text-xs text-gray-500">Seminar: <?php echo date('m/d/Y', strtotime($request['seminar_date'])); ?></div><?php endif; ?>
                        <?php if ($request['oath_scheduled_date']): ?><div class="text-xs text-gray-500">Oath: <?php echo date('m/d/Y', strtotime($request['oath_scheduled_date'])); ?></div><?php endif; ?>
                    </div>

                    <div class="mt-4 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <?php if ($canManage): ?>
                                <input type="checkbox" class="request-checkbox" value="<?php echo $request['request_id']; ?>" data-status="<?php echo $request['status']; ?>" onchange="updateBulkActionBar()">
                            <?php endif; ?>
                            <div class="text-xs text-gray-500">Requested by <?php echo Security::escape($request['requested_by_name']); ?></div>
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="button" 
                                onclick="openRequestModal(<?php echo $request['request_id']; ?>)"
                                class="px-3 py-1 bg-blue-50 text-blue-700 rounded-lg text-sm hover:bg-blue-100 transition-colors">
                                View
                            </button>
                            <?php if ($canManage): ?>
                                <form method="POST" action="delete-request.php" class="delete-request-form inline">
                                    <input type="hidden" name="request_id" value="<?php echo $request['request_id']; ?>">
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                                    <button type="button" class="px-3 py-1 bg-red-50 text-red-700 rounded-lg text-sm" onclick="openDeleteModal(this)">Delete</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($canManage && $request['status'] !== 'oath_taken'): ?>
                                <!-- Quick Status Update Dropdown -->
                                <div class="relative inline-block text-left" x-data="{ open: false }">
                                    <button @click="open = !open" @click.away="open = false" type="button" class="px-3 py-1 bg-green-50 text-green-700 rounded-lg text-sm">
                                        Update
                                    </button>
                                    <div x-show="open" x-transition class="absolute right-0 mt-2 w-56 rounded-lg shadow-lg bg-white ring-1 ring-black ring-opacity-5 z-10" style="display: none;">
                                        <div class="py-1">
                                            <?php
                                            // Define next possible statuses based on current status
                                            $nextStatuses = [];
                                            switch ($request['status']) {
                                                case 'pending':
                                                    $nextStatuses = [
                                                        'requested_to_seminar' => 'Approve for Seminar',
                                                        'rejected' => 'Reject Request'
                                                    ];
                                                    break;
                                                case 'requested_to_seminar':
                                                    $nextStatuses = [
                                                        'in_seminar' => 'Mark In Seminar',
                                                        'cancelled' => 'Cancel'
                                                    ];
                                                    break;
                                                case 'in_seminar':
                                                    $nextStatuses = [
                                                        'seminar_completed' => 'Complete Seminar'
                                                    ];
                                                    break;
                                                case 'seminar_completed':
                                                    $nextStatuses = [
                                                        'requested_to_oath' => 'Approve for Oath'
                                                    ];
                                                    break;
                                                case 'requested_to_oath':
                                                    $nextStatuses = [
                                                        'ready_to_oath' => 'Mark Ready for Oath'
                                                    ];
                                                    break;
                                                case 'ready_to_oath':
                                                    $nextStatuses = [
                                                        'oath_taken' => 'Complete Oath'
                                                    ];
                                                    break;
                                            }
                                            
                                            if (!empty($nextStatuses)):
                                                foreach ($nextStatuses as $status => $label):
                                            ?>
                                            <a href="view.php?id=<?php echo $request['request_id']; ?>#workflow" 
                                               class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                                <?php echo Security::escape($label); ?>
                                            </a>
                                            <?php 
                                                endforeach;
                                            else:
                                            ?>
                                            <div class="px-4 py-2 text-sm text-gray-500">
                                                No quick actions available
                                            </div>
                                            <?php endif; ?>
                                            
                                            <div class="border-t border-gray-100"></div>
                                            <a href="view.php?id=<?php echo $request['request_id']; ?>" 
                                               class="block px-4 py-2 text-sm text-blue-700 hover:bg-blue-50">
                                                View Full Details →
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="delete-modal" class="hidden fixed inset-0 z-50 overflow-y-auto">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity" onclick="closeDeleteModal()"></div>
            <div class="relative bg-white rounded-lg shadow-xl max-w-md w-full flex flex-col">
                <div class="flex items-center justify-between p-4 border-b">
                    <h3 class="text-lg font-semibold text-gray-900">Delete Request</h3>
                    <button type="button" onclick="closeDeleteModal()" class="text-gray-400 hover:text-gray-500">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                <div class="p-6">
                    <p class="text-gray-700 mb-4">Are you sure you want to delete this request? This action cannot be undone.</p>
                    <div class="flex justify-end gap-3">
                        <button type="button" onclick="closeDeleteModal()" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200">Cancel</button>
                        <button type="button" onclick="confirmDeleteRequest()" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">Delete</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    let pendingDeleteForm = null;
    function openDeleteModal(button) {
        pendingDeleteForm = button.closest('form');
        document.getElementById('delete-modal').classList.remove('hidden');
        document.getElementById('delete-modal').style.display = 'block';
    }
    function closeDeleteModal() {
        document.getElementById('delete-modal').classList.add('hidden');
        document.getElementById('delete-modal').style.display = 'none';
        pendingDeleteForm = null;
    }
    function confirmDeleteRequest() {
        if (pendingDeleteForm) {
            pendingDeleteForm.submit();
            closeDeleteModal();
        }
    }
    </script>
</div>

<script>
// Bulk action functionality
function toggleSelectAll(checkbox) {
    const checkboxes = document.querySelectorAll('.request-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = checkbox.checked;
    });
    updateBulkActionBar();
}

function updateBulkActionBar() {
    const checkboxes = document.querySelectorAll('.request-checkbox:checked');
    const count = checkboxes.length;
    const bulkBar = document.getElementById('bulkActionsBar');
    const countSpan = document.getElementById('selectedCount');
    const selectAllCheckbox = document.getElementById('selectAll');
    
    if (count > 0) {
        bulkBar.classList.remove('hidden');
        countSpan.textContent = count;
    } else {
        bulkBar.classList.add('hidden');
        selectAllCheckbox.checked = false;
    }
    
    // Update select all checkbox state
    const allCheckboxes = document.querySelectorAll('.request-checkbox');
    selectAllCheckbox.checked = allCheckboxes.length > 0 && count === allCheckboxes.length;
}

function clearSelection() {
    const checkboxes = document.querySelectorAll('.request-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = false;
    });
    document.getElementById('selectAll').checked = false;
    updateBulkActionBar();
}

function applyBulkAction() {
    const action = document.getElementById('bulkAction').value;
    if (!action) {
        alert('Please select an action first.');
        return;
    }
    
    const checkboxes = document.querySelectorAll('.request-checkbox:checked');
    const selectedIds = Array.from(checkboxes).map(cb => cb.value);
    
    if (selectedIds.length === 0) {
        alert('Please select at least one request.');
        return;
    }
    
    if (action === 'export') {
        // Export selected requests
        window.location.href = 'export.php?ids=' + selectedIds.join(',');
        return;
    }
    
    // Confirm bulk status update
    const confirmMsg = `Are you sure you want to apply this action to ${selectedIds.length} request(s)?`;
    if (!confirm(confirmMsg)) {
        return;
    }
    
    // Create form and submit
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'bulk-update.php';
    
    const actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'action';
    actionInput.value = action;
    form.appendChild(actionInput);
    
    const idsInput = document.createElement('input');
    idsInput.type = 'hidden';
    idsInput.name = 'request_ids';
    idsInput.value = JSON.stringify(selectedIds);
    form.appendChild(idsInput);
    
    const csrfInput = document.createElement('input');
    csrfInput.type = 'hidden';
    csrfInput.name = 'csrf_token';
    csrfInput.value = '<?php echo Security::generateCSRFToken(); ?>';
    form.appendChild(csrfInput);
    
    document.body.appendChild(form);
    form.submit();
}

// Status Modal
function openStatusModal() {
    const modal = document.getElementById('status-modal');
    modal.classList.remove('hidden');
    modal.style.display = 'block';
    document.getElementById('status-search').focus();
}

function closeStatusModal() {
    const modal = document.getElementById('status-modal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
    document.getElementById('status-search').value = '';
    filterStatuses();
}

function selectStatus(value, displayText) {
    document.getElementById('status-value').value = value;
    document.getElementById('status-display').value = displayText;
    closeStatusModal();
}

function filterStatuses() {
    const search = document.getElementById('status-search').value.toLowerCase();
    const items = document.querySelectorAll('.status-item');
    items.forEach(item => {
        const text = item.textContent.toLowerCase();
        item.style.display = text.includes(search) ? 'block' : 'none';
    });
}
</script>

<!-- Status Modal -->
<div id="status-modal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity" onclick="closeStatusModal()"></div>
        <div class="relative bg-white rounded-lg shadow-xl max-w-md w-full max-h-[80vh] flex flex-col">
            <!-- Header -->
            <div class="flex items-center justify-between p-4 border-b">
                <h3 class="text-lg font-semibold text-gray-900">Select Status</h3>
                <button type="button" onclick="closeStatusModal()" class="text-gray-400 hover:text-gray-500">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            
            <!-- Search -->
            <div class="p-4 border-b">
                <input 
                    type="text" 
                    id="status-search"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                    placeholder="Search statuses..."
                    oninput="filterStatuses()"
                >
            </div>
            
            <!-- List -->
            <div class="overflow-y-auto flex-1">
                <div class="status-item px-4 py-3 hover:bg-gray-50 cursor-pointer border-b border-gray-100"
                     onclick="selectStatus('all', 'All Status')">
                    <span class="text-gray-900 font-medium">All Status</span>
                </div>
                <?php foreach ($statusConfig as $value => $config): ?>
                    <div class="status-item px-4 py-3 hover:bg-gray-50 cursor-pointer border-b border-gray-100"
                         onclick="selectStatus('<?php echo Security::escape($value); ?>', '<?php echo Security::escape($config['label']); ?>')">
                        <div class="flex items-center gap-2">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-<?php echo $config['color']; ?>-100 text-<?php echo $config['color']; ?>-800">
                                <?php echo Security::escape($config['label']); ?>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- Request Details Modal -->
<div id="request-modal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity" onclick="closeRequestModal()"></div>
        <div onclick="event.stopPropagation()" class="relative bg-white rounded-lg shadow-xl max-w-4xl w-full max-h-[90vh] overflow-hidden flex flex-col">
            
            <!-- Modal Header -->
            <div class="flex items-center justify-between p-6 border-b border-gray-200">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-xl font-semibold text-gray-900">Request Details</h3>
                        <p class="text-sm text-gray-500" id="modal-request-id"></p>
                    </div>
                </div>
                <button type="button" onclick="closeRequestModal()" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            
            <!-- Loading State -->
            <div id="modal-loading" class="flex items-center justify-center p-12">
                <div class="relative w-16 h-16">
                    <div class="absolute inset-0 border-4 border-blue-200 rounded-full"></div>
                    <div class="absolute inset-0 border-4 border-blue-600 rounded-full border-t-transparent animate-spin"></div>
                </div>
            </div>
            
            <!-- Action Loading Overlay -->
            <div id="modal-action-loading" class="hidden absolute inset-0 bg-white bg-opacity-90 flex items-center justify-center z-50">
                <div class="text-center">
                    <div class="relative w-16 h-16 mx-auto mb-4">
                        <div class="absolute inset-0 border-4 border-blue-200 rounded-full"></div>
                        <div class="absolute inset-0 border-4 border-blue-600 rounded-full border-t-transparent animate-spin"></div>
                    </div>
                    <p class="text-sm font-medium text-gray-700" id="action-loading-text">Processing...</p>
                </div>
            </div>
            
            <!-- Content -->
            <div id="modal-content" class="hidden flex-1 overflow-hidden flex flex-col">
                <!-- Tab Navigation -->
                <div class="border-b border-gray-200 px-6">
                    <nav class="-mb-px flex space-x-8">
                        <button type="button" onclick="switchTab('overview')" id="tab-overview"
                            class="tab-button whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors border-blue-500 text-blue-600">
                            Overview
                        </button>
                        <button type="button" onclick="switchTab('requirements')" id="tab-requirements"
                            class="tab-button whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300">
                            <span>Requirements</span>
                            <span id="requirements-badge" class="ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                0/7
                            </span>
                        </button>
                        <?php if ($canManage): ?>
                        <button type="button" onclick="switchTab('workflow')" id="tab-workflow"
                            class="tab-button whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300">
                            Workflow
                        </button>
                        <button type="button" onclick="switchTab('seminar')" id="tab-seminar"
                            class="tab-button whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300">
                            <span>Seminar</span>
                            <span id="seminar-badge" class="ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800"></span>
                        </button>
                        <button type="button" onclick="switchTab('documents')" id="tab-documents"
                            class="tab-button whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300">
                            Documents
                        </button>
                        <?php endif; ?>
                        <button type="button" onclick="switchTab('timeline')" id="tab-timeline"
                            class="tab-button whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300">
                            Timeline
                        </button>
                    </nav>
                </div>
                
                <!-- Tab Content -->
                <div class="flex-1 overflow-y-auto p-6">
                    <!-- Overview Tab -->
                    <div id="content-overview" class="tab-content space-y-6">
                        <!-- Officer Information -->
                        <div class="bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-200 rounded-lg p-6">
                            <div class="flex items-center space-x-4">
                                <div class="w-16 h-16 bg-blue-600 rounded-full flex items-center justify-center text-white text-2xl font-bold" id="overview-avatar">
                                    ?
                                </div>
                                <div>
                                    <h4 class="text-xl font-bold text-gray-900" id="overview-name">Loading...</h4>
                                    <p class="text-sm text-gray-600">
                                        <span id="overview-location"></span>
                                        <span id="overview-code-badge"></span>
                                    </p>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Position & Status -->
                        <div class="grid grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Department</label>
                                <p class="text-base text-gray-900" id="overview-department"></p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Duty</label>
                                <p class="text-base text-gray-900" id="overview-duty"></p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                                <span id="overview-status" class="inline-flex items-center px-2.5 py-1 rounded text-sm font-medium"></span>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Requested Date</label>
                                <p class="text-base text-gray-900" id="overview-date"></p>
                            </div>
                        </div>
                        
                        <!-- Additional Details -->
                        <div id="overview-additional" class="grid grid-cols-2 gap-6 pt-4 border-t">
                            <!-- Populated dynamically -->
                        </div>
                        
                        <div class="flex items-center justify-between pt-4 border-t">
                            <a href="#" id="full-details-link" target="_blank"
                               class="text-sm text-blue-600 hover:text-blue-800 font-medium">
                                Open Full Request Page →
                            </a>
                        </div>
                    </div>
                    
                    <!-- Requirements Tab -->
                    <div id="content-requirements" class="tab-content hidden space-y-4">
                        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                            <div class="flex items-center">
                                <svg class="w-5 h-5 text-blue-600 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                                </svg>
                                <p class="text-sm text-blue-800">Track document submission progress. Check items as they are submitted.</p>
                            </div>
                        </div>
                        
                        <div id="requirements-list" class="space-y-3"></div>
                        
                        <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 mt-6">
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-medium text-gray-700">Progress</span>
                                <span class="text-sm font-semibold text-gray-900" id="progress-text">0 / 7 Complete</span>
                            </div>
                            <div class="mt-2 w-full bg-gray-200 rounded-full h-2">
                                <div id="progress-bar" class="bg-blue-600 h-2 rounded-full transition-all duration-300" style="width: 0%"></div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Workflow Tab -->
                    <?php if ($canManage): ?>
                    <div id="content-workflow" class="tab-content hidden space-y-4">
                        <!-- Record Code Section -->
                        <div id="record-code-section" class="bg-white border border-gray-200 rounded-lg p-4">
                            <h4 class="font-semibold text-gray-900 mb-3">Record Code</h4>
                            <div id="record-code-content"></div>
                        </div>
                        
                        <!-- Workflow Actions -->
                        <div id="workflow-actions" class="space-y-3">
                            <!-- Populated dynamically based on status -->
                        </div>
                    </div>
                    
                    <!-- Seminar Tab -->
                    <div id="content-seminar" class="tab-content hidden space-y-4">
                        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                            <p class="text-sm text-blue-800">Track seminar attendance and progress</p>
                        </div>
                        <div id="seminar-dates-list" class="space-y-3"></div>
                        <button type="button" onclick="showAddSeminarModal()" 
                            class="w-full px-4 py-2 border-2 border-dashed border-gray-300 rounded-lg text-gray-600 hover:border-blue-400 hover:text-blue-600 transition-colors">
                            + Add Seminar Date
                        </button>
                    </div>
                    
                    <!-- Documents Tab -->
                    <div id="content-documents" class="tab-content hidden space-y-4">
                        <div class="bg-purple-50 border border-purple-200 rounded-lg p-4 mb-4">
                            <p class="text-sm text-purple-800">Generate and download official documents</p>
                        </div>
                        <div id="documents-list" class="space-y-3">
                            <!-- Populated dynamically -->
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Timeline Tab -->
                    <div id="content-timeline" class="tab-content hidden space-y-4">
                        <div id="timeline-content" class="space-y-4">
                            <!-- Populated dynamically -->
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Seminar Date Modal -->
<div id="seminar-modal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="fixed inset-0 bg-gray-900 bg-opacity-75" onclick="closeAdd SeminarModal()"></div>
        <div class="relative bg-white rounded-lg shadow-xl max-w-md w-full p-6">
            <h3 class="text-lg font-bold text-gray-900 mb-4">Add Seminar Date</h3>
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Seminar Date *</label>
                    <input type="date" id="new-seminar-date" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Location (optional)</label>
                    <input type="text" id="new-seminar-location" placeholder="e.g., Central Church" 
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Days Required</label>
                    <input type="number" id="new-seminar-days-required" value="3" min="1" max="10"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                </div>
            </div>
            <div class="flex gap-3 mt-6">
                <button type="button" onclick="closeSeminarModal()" 
                    class="flex-1 px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                    Cancel
                </button>
                <button type="button" onclick="addSeminarDate()" 
                    class="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                    Add Date
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let currentRequestData = null;

function openRequestModal(requestId) {
    const modal = document.getElementById('request-modal');
    const loading = document.getElementById('modal-loading');
    const content = document.getElementById('modal-content');
    
    modal.classList.remove('hidden');
    loading.classList.remove('hidden');
    content.classList.add('hidden');
    
    // Fetch request data
    fetch(`<?php echo BASE_URL; ?>/api/get-officer-requests.php?id=${requestId}`)
        .then(response => response.json())
        .then(data => {
            currentRequestData = data;
            populateModal(data);
            loading.classList.add('hidden');
            content.classList.remove('hidden');
        })
        .catch(error => {
            console.error('Error loading request:', error);
            alert('Error loading request details');
            closeRequestModal();
        });
}

function closeRequestModal() {
    document.getElementById('request-modal').classList.add('hidden');
    currentRequestData = null;
}

function switchTab(tabName) {
    // Update tab buttons
    document.querySelectorAll('.tab-button').forEach(btn => {
        btn.classList.remove('border-blue-500', 'text-blue-600');
        btn.classList.add('border-transparent', 'text-gray-500');
    });
    document.getElementById(`tab-${tabName}`).classList.remove('border-transparent', 'text-gray-500');
    document.getElementById(`tab-${tabName}`).classList.add('border-blue-500', 'text-blue-600');
    
    // Update content
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.add('hidden');
    });
    document.getElementById(`content-${tabName}`).classList.remove('hidden');
}

function populateModal(data) {
    // Update modal header
    document.getElementById('modal-request-id').textContent = '#' + data.request_id;
    
    // Overview tab - Officer info
    const avatar = document.getElementById('overview-avatar');
    avatar.textContent = data.full_name ? data.full_name.charAt(0).toUpperCase() : '?';
    document.getElementById('overview-name').textContent = data.full_name || 'Unknown';
    document.getElementById('overview-location').textContent = `${data.local_name} • ${data.district_name}`;
    
    // Record code badge
    const codeBadge = document.getElementById('overview-code-badge');
    if (data.record_code) {
        codeBadge.innerHTML = ` <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${data.record_code === 'A' ? 'bg-green-100 text-green-800' : 'bg-blue-100 text-blue-800'}">CODE ${data.record_code}</span>`;
    }
    
    // Position & Status
    document.getElementById('overview-department').textContent = data.requested_department;
    document.getElementById('overview-duty').textContent = data.requested_duty || 'N/A';
    document.getElementById('overview-date').textContent = data.requested_at_formatted;
    document.getElementById('full-details-link').href = `<?php echo BASE_URL; ?>/requests/view.php?id=${data.request_id}`;
    
    // Status badge
    const statusBadge = document.getElementById('overview-status');
    statusBadge.textContent = data.status_label;
    statusBadge.className = 'inline-flex items-center px-2.5 py-1 rounded text-sm font-medium';
    
    const statusColors = {
        'pending': 'bg-yellow-100 text-yellow-800',
        'requested_to_seminar': 'bg-blue-100 text-blue-800',
        'in_seminar': 'bg-indigo-100 text-indigo-800',
        'seminar_completed': 'bg-green-100 text-green-800',
        'requested_to_oath': 'bg-purple-100 text-purple-800',
        'ready_to_oath': 'bg-pink-100 text-pink-800',
        'oath_taken': 'bg-green-100 text-green-800',
        'rejected': 'bg-red-100 text-red-800',
        'cancelled': 'bg-gray-100 text-gray-800'
    };
    statusBadge.className += ' ' + (statusColors[data.status] || 'bg-gray-100 text-gray-800');
    
    // Additional details
    let additionalHTML = '';
    if (data.seminar_date) {
        additionalHTML += `
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Seminar Date</label>
                <p class="text-base text-gray-900">${data.seminar_date_formatted}</p>
            </div>
        `;
    }
    if (data.oath_scheduled_date) {
        additionalHTML += `
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Scheduled Oath Date</label>
                <p class="text-base text-gray-900">${data.oath_scheduled_date_formatted}</p>
            </div>
        `;
    }
    if (data.requested_by_name) {
        additionalHTML += `
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Requested By</label>
                <p class="text-base text-gray-900">${data.requested_by_name}</p>
            </div>
        `;
    }
    if (data.status_reason) {
        additionalHTML += `
            <div class="col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-1">Status Reason</label>
                <p class="text-base text-gray-900">${data.status_reason}</p>
            </div>
        `;
    }
    document.getElementById('overview-additional').innerHTML = additionalHTML;
    
    // Requirements tab
    populateRequirements(data);
    
    // Workflow tab
    <?php if ($canManage): ?>
    populateRecordCode(data);
    populateWorkflow(data);
    populateSeminar(data);
    populateDocuments(data);
    <?php endif; ?>
    
    // Timeline tab
    populateTimeline(data);
}

function populateRequirements(data) {
    const requirementsList = document.getElementById('requirements-list');
    
    const requirements = [
        { field: 'has_r515', label: 'R5-15/04', description: 'Officer Application Form' },
        { field: 'has_patotoo_katiwala', label: 'Patotoo ng Katiwala', description: 'Recommendation from Katiwala' },
        { field: 'has_patotoo_kapisanan', label: 'Patotoo ng Kapisanan', description: 'Recommendation from Organization' },
        { field: 'has_salaysay_magulang', label: 'Salaysay ng Magulang', description: 'Parent\'s Statement (if applicable)' },
        { field: 'has_salaysay_pagtanggap', label: 'Salaysay ng Pagtanggap', description: 'Acceptance Statement' },
        { field: 'has_picture', label: '2x2 Picture', description: 'Recent 2x2 ID photo' }
    ];
    
    // Check if R5-13 is complete (seminar completed)
    const r513Complete = ['seminar_completed', 'requested_to_oath', 'ready_to_oath', 'oath_taken'].includes(data.status);
    
    let html = '';
    let completedCount = 0;
    
    requirements.forEach(req => {
        const checked = data[req.field];
        if (checked) completedCount++;
        
        html += `
            <div class="flex items-center justify-between p-4 bg-white border border-gray-200 rounded-lg hover:border-blue-300 transition-colors">
                <div class="flex items-center space-x-3">
                    <input type="checkbox" 
                           ${checked ? 'checked' : ''} 
                           onchange="toggleRequirement('${req.field}', this.checked)"
                           class="w-5 h-5 text-blue-600 rounded">
                    <div>
                        <p class="font-medium text-gray-900">${req.label}</p>
                        <p class="text-sm text-gray-500">${req.description}</p>
                    </div>
                </div>
                ${checked ? `
                    <span class="text-green-600">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                        </svg>
                    </span>
                ` : ''}
            </div>
        `;
    });
    
    // R5-13 Seminar (auto-complete)
    if (r513Complete) completedCount++;
    html += `
        <div class="flex items-center justify-between p-4 bg-white border ${r513Complete ? 'border-green-300 bg-green-50' : 'border-gray-200'} rounded-lg">
            <div class="flex items-center space-x-3">
                <input type="checkbox" 
                       ${r513Complete ? 'checked' : ''} 
                       disabled
                       class="w-5 h-5 text-blue-600 rounded cursor-not-allowed">
                <div>
                    <p class="font-medium text-gray-900">R5-13 Seminar Certificate</p>
                    <p class="text-sm ${r513Complete ? 'text-green-600' : 'text-gray-500'}">
                        ${r513Complete ? '✓ Auto-completed when seminar is finished' : 'Will be auto-checked when seminar is completed'}
                    </p>
                </div>
            </div>
            ${r513Complete ? `
                <span class="text-green-600">
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                    </svg>
                </span>
            ` : ''}
        </div>
    `;
    
    requirementsList.innerHTML = html;
    
    // Update progress
    const progressPercent = (completedCount / 7) * 100;
    document.getElementById('progress-bar').style.width = progressPercent + '%';
    document.getElementById('progress-text').textContent = `${completedCount} / 7 Complete`;
    if (completedCount === 7) {
        document.getElementById('progress-text').classList.add('text-green-600');
    }
    
    // Update badge
    const badge = document.getElementById('requirements-badge');
    badge.textContent = `${completedCount}/7`;
    if (completedCount === 7) {
        badge.className = 'ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800';
        badge.textContent = 'Complete';
    }
}

async function toggleRequirement(field, value) {
    try {
        const response = await fetch(`<?php echo BASE_URL; ?>/api/update-request-requirement.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                request_id: currentRequestData.request_id,
                field: field,
                value: value
            })
        });
        
        if (response.ok) {
            currentRequestData[field] = value;
            populateRequirements(currentRequestData);
        } else {
            alert('Error updating requirement');
            // Revert checkbox
            event.target.checked = !value;
        }
    } catch (error) {
        console.error('Error updating requirement:', error);
        alert('Error updating requirement');
        event.target.checked = !value;
    }
}

<?php if ($canManage): ?>
function populateRecordCode(data) {
    const container = document.getElementById('record-code-content');
    let html = '';
    
    if (!data.record_code) {
        html = `
            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-3 mb-3">
                <p class="text-sm text-yellow-800">⚠️ Record code not set. Set CODE A (new officer) or CODE D (existing officer) before completing oath.</p>
            </div>
            <div class="space-y-3">
                <button type="button" onclick="setRecordCode('A')" 
                    class="w-full p-3 border-2 border-green-200 rounded-lg hover:border-green-400 hover:bg-green-50 text-left">
                    <div class="font-medium text-gray-900">CODE A - New Officer</div>
                    <div class="text-sm text-gray-600">Will create a brand new officer record</div>
                </button>
                <button type="button" onclick="showCodeDSearch()" 
                    class="w-full p-3 border-2 border-blue-200 rounded-lg hover:border-blue-400 hover:bg-blue-50 text-left">
                    <div class="font-medium text-gray-900">CODE D - Existing Officer</div>
                    <div class="text-sm text-gray-600">Link to an existing officer (new department/duty)</div>
                </button>
            </div>
            <div id="code-d-search" class="hidden mt-3 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                <label class="block text-sm font-medium text-gray-700 mb-2">Search Existing Officer</label>
                <input type="text" id="officer-search" placeholder="Search by name..." 
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg mb-2"
                    onkeyup="searchOfficers(this.value)">
                <div id="officer-search-results" class="max-h-48 overflow-y-auto"></div>
            </div>
        `;
    } else {
        const badge = data.record_code === 'A' 
            ? '<span class="px-2 py-1 bg-green-100 text-green-800 rounded text-sm font-medium">CODE A</span>'
            : '<span class="px-2 py-1 bg-blue-100 text-blue-800 rounded text-sm font-medium">CODE D</span>';
        html = `
            <div class="mb-3">
                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                    <div>
                        <div class="font-medium text-gray-900">Record Code Set</div>
                        <div class="text-sm text-gray-600">${data.record_code === 'A' ? 'Will create new officer' : 'Linked to existing officer'}</div>
                    </div>
                    ${badge}
                </div>
            </div>
            <button type="button" onclick="showChangeCode()" 
                class="w-full px-3 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 text-sm">
                Change Code
            </button>
            <div id="change-code-section" class="hidden mt-3 space-y-3">
                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-3">
                    <p class="text-sm text-yellow-800">⚠️ Changing the code will update the officer assignment method.</p>
                </div>
                <button type="button" onclick="setRecordCode('A')" 
                    class="w-full p-3 border-2 ${data.record_code === 'A' ? 'border-green-500 bg-green-50' : 'border-green-200 hover:border-green-400 hover:bg-green-50'} rounded-lg text-left">
                    <div class="font-medium text-gray-900">CODE A - New Officer</div>
                    <div class="text-sm text-gray-600">Will create a brand new officer record</div>
                </button>
                <button type="button" onclick="showCodeDChangeSearch()" 
                    class="w-full p-3 border-2 ${data.record_code === 'D' ? 'border-blue-500 bg-blue-50' : 'border-blue-200 hover:border-blue-400 hover:bg-blue-50'} rounded-lg text-left">
                    <div class="font-medium text-gray-900">CODE D - Existing Officer</div>
                    <div class="text-sm text-gray-600">Link to an existing officer (new department/duty)</div>
                </button>
                <div id="code-d-change-search" class="hidden p-3 bg-blue-50 border border-blue-200 rounded-lg">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Search Existing Officer</label>
                    <input type="text" id="officer-change-search" placeholder="Search by name..." 
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg mb-2"
                        onkeyup="searchOfficersChange(this.value)">
                    <div id="officer-change-search-results" class="max-h-48 overflow-y-auto"></div>
                </div>
            </div>
        `;
    }
    
    container.innerHTML = html;
}

function showCodeDSearch() {
    document.getElementById('code-d-search').classList.remove('hidden');
    document.getElementById('officer-search').focus();
}

function showChangeCode() {
    document.getElementById('change-code-section').classList.remove('hidden');
}

function showCodeDChangeSearch() {
    document.getElementById('code-d-change-search').classList.remove('hidden');
    document.getElementById('officer-change-search').focus();
}

async function searchOfficersChange(query) {
    if (query.length < 2) {
        document.getElementById('officer-change-search-results').innerHTML = '';
        return;
    }
    
    try {
        const response = await fetch(`<?php echo BASE_URL; ?>/api/search-officers.php?q=${encodeURIComponent(query)}`);
        const results = await response.json();
        
        let html = '';
        if (results.length === 0) {
            html = '<p class="text-sm text-gray-500 p-2">No officers found</p>';
        } else {
            results.forEach(officer => {
                html += `
                    <div class="p-2 hover:bg-blue-100 cursor-pointer border-b border-blue-200" 
                         onclick="selectOfficer('${officer.uuid}', '${officer.full_name.replace(/'/g, "\\'")}')">
                        <div class="font-medium text-gray-900">${officer.full_name}</div>
                        <div class="text-xs text-gray-600">${officer.location || ''}</div>
                    </div>
                `;
            });
        }
        document.getElementById('officer-change-search-results').innerHTML = html;
    } catch (error) {
        console.error('Error searching officers:', error);
    }
}

async function searchOfficers(query) {
    if (query.length < 2) {
        document.getElementById('officer-search-results').innerHTML = '';
        return;
    }
    
    try {
        const response = await fetch(`<?php echo BASE_URL; ?>/api/search-officers.php?q=${encodeURIComponent(query)}`);
        const results = await response.json();
        
        let html = '';
        if (results.length > 0) {
            results.forEach(officer => {
                html += `
                    <div class="p-2 hover:bg-blue-100 cursor-pointer rounded" onclick="selectOfficer('${officer.uuid}', '${officer.full_name}')">
                        <div class="font-medium text-sm">${officer.full_name}</div>
                        <div class="text-xs text-gray-600">${officer.location || ''}</div>
                    </div>
                `;
            });
        } else {
            html = '<div class="text-sm text-gray-500 p-2">No officers found</div>';
        }
        document.getElementById('officer-search-results').innerHTML = html;
    } catch (error) {
        console.error('Error searching officers:', error);
    }
}

function selectOfficer(uuid, name) {
    if (confirm(`Link this request to ${name}?`)) {
        setRecordCode('D', uuid);
    }
}

async function setRecordCode(code, officerUuid = null) {
    showActionLoading('Setting record code...');
    
    try {
        const response = await fetch('<?php echo BASE_URL; ?>/api/update-request-workflow.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                request_id: currentRequestData.request_id,
                action: 'set_code',
                record_code: code,
                existing_officer_uuid: officerUuid
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Reload data
            const dataResponse = await fetch(`<?php echo BASE_URL; ?>/api/get-officer-requests.php?id=${currentRequestData.request_id}`);
            const newData = await dataResponse.json();
            currentRequestData = newData;
            populateModal(newData);
            hideActionLoading();
            alert(result.message);
        } else {
            hideActionLoading();
            alert('Error: ' + result.error);
        }
    } catch (error) {
        hideActionLoading();
        console.error('Error setting record code:', error);
        alert('Error setting record code');
    }
}

function populateSeminar(data) {
    const listContainer = document.getElementById('seminar-dates-list');
    const badge = document.getElementById('seminar-badge');
    
    const seminarDates = data.seminar_dates_array || [];
    const daysRequired = data.seminar_days_required || 3;
    const daysCompleted = data.seminar_days_completed || 0;
    
    badge.textContent = `${daysCompleted}/${daysRequired}`;
    badge.className = `ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${
        daysCompleted >= daysRequired ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'
    }`;
    
    if (seminarDates.length === 0) {
        listContainer.innerHTML = `
            <div class="text-center py-8 text-gray-500">
                <p class="text-sm">No seminar dates scheduled yet</p>
            </div>
        `;
        return;
    }
    
    let html = '';
    seminarDates.forEach((seminar, index) => {
        const attended = seminar.attended || false;
        html += `
            <div class="p-4 border ${attended ? 'border-green-300 bg-green-50' : 'border-gray-200 bg-white'} rounded-lg">
                <div class="flex items-center justify-between">
                    <div class="flex-1">
                        <div class="font-medium text-gray-900">${new Date(seminar.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}</div>
                        ${seminar.location ? `<div class="text-sm text-gray-600">${seminar.location}</div>` : ''}
                    </div>
                    <label class="flex items-center cursor-pointer">
                        <input type="checkbox" ${attended ? 'checked' : ''} 
                            onchange="markAttendance(${index}, this.checked)"
                            class="w-5 h-5 text-green-600 rounded">
                        <span class="ml-2 text-sm text-gray-700">Attended</span>
                    </label>
                </div>
            </div>
        `;
    });
    
    listContainer.innerHTML = html;
}

function showAddSeminarModal() {
    document.getElementById('seminar-modal').classList.remove('hidden');
}

function closeSeminarModal() {
    document.getElementById('seminar-modal').classList.add('hidden');
    document.getElementById('new-seminar-date').value = '';
    document.getElementById('new-seminar-location').value = '';
    document.getElementById('new-seminar-days-required').value = '3';
}

async function addSeminarDate() {
    const date = document.getElementById('new-seminar-date').value;
    const location = document.getElementById('new-seminar-location').value;
    const daysRequired = document.getElementById('new-seminar-days-required').value;
    
    if (!date) {
        alert('Please select a seminar date');
        return;
    }
    
    closeSeminarModal();
    showActionLoading('Adding seminar date...');
    
    try {
        const response = await fetch('<?php echo BASE_URL; ?>/api/update-request-workflow.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                request_id: currentRequestData.request_id,
                action: 'add_seminar_date',
                date: date,
                location: location,
                days_required: daysRequired
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Reload data
            const dataResponse = await fetch(`<?php echo BASE_URL; ?>/api/get-officer-requests.php?id=${currentRequestData.request_id}`);
            const newData = await dataResponse.json();
            currentRequestData = newData;
            populateModal(newData);
            hideActionLoading();
            alert(result.message);
        } else {
            hideActionLoading();
            alert('Error: ' + result.error);
        }
    } catch (error) {
        hideActionLoading();
        console.error('Error adding seminar date:', error);
        alert('Error adding seminar date');
    }
}

async function markAttendance(index, attended) {
    showActionLoading('Updating attendance...');
    
    try {
        const response = await fetch('<?php echo BASE_URL; ?>/api/update-request-workflow.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                request_id: currentRequestData.request_id,
                action: 'mark_attendance',
                seminar_index: index,
                attended: attended
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Reload data
            const dataResponse = await fetch(`<?php echo BASE_URL; ?>/api/get-officer-requests.php?id=${currentRequestData.request_id}`);
            const newData = await dataResponse.json();
            currentRequestData = newData;
            populateModal(newData);
            hideActionLoading();
        } else {
            hideActionLoading();
            alert('Error: ' + result.error);
            // Revert checkbox
            event.target.checked = !attended;
        }
    } catch (error) {
        hideActionLoading();
        console.error('Error marking attendance:', error);
        alert('Error updating attendance');
        event.target.checked = !attended;
    }
}

function populateDocuments(data) {
    const container = document.getElementById('documents-list');
    let html = '';
    let documentCount = 0;
    
    // Palasumpaan (Oath Document)
    if (['ready_to_oath', 'oath_taken'].includes(data.status)) {
        documentCount++;
        html += `
            <div class="p-4 border border-purple-200 bg-purple-50 rounded-lg">
                <div class="flex items-center justify-between">
                    <div class="flex-1">
                        <div class="font-medium text-gray-900">Palasumpaan (Oath Document)</div>
                        <div class="text-sm text-gray-600">Official oath-taking certificate</div>
                    </div>
                    <div class="flex gap-2">
                        ${data.status === 'oath_taken' ? `
                            <a href="<?php echo BASE_URL; ?>/generate-palasumpaan.php?request_id=${data.request_id}&preview=1" 
                               target="_blank"
                               class="px-3 py-2 border border-purple-300 text-purple-700 rounded-lg hover:bg-purple-100 text-sm">
                                Preview
                            </a>
                        ` : ''}
                        <button onclick="openPalasumpaanModal(${data.request_id}, '${data.oath_actual_date || ''}')"
                                class="px-3 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm">
                            Generate PDF
                        </button>
                    </div>
                </div>
            </div>
        `;
    }
    
    // R5-13 Certificate
    if (['seminar_completed', 'requested_to_oath', 'ready_to_oath', 'oath_taken'].includes(data.status)) {
        documentCount++;
        const hasR513 = data.r513_pdf_file_id;
        html += `
            <div class="p-4 border border-green-200 bg-green-50 rounded-lg">
                <div class="flex items-center justify-between">
                    <div class="flex-1">
                        <div class="font-medium text-gray-900">R5-13 Seminar Certificate</div>
                        <div class="text-sm text-gray-600">
                            ${data.request_class === '33_lessons' ? '33 lessons (30-day extended)' : '8 lessons (standard)'} seminar certificate
                            ${hasR513 ? '<span class="ml-2 text-green-700">✓ Generated</span>' : ''}
                        </div>
                    </div>
                    <div class="flex gap-2">
                        ${hasR513 ? `
                            <a href="<?php echo BASE_URL; ?>/generate-r513-html.php?request_id=${data.request_id}&preview=1" 
                               target="_blank"
                               class="px-3 py-2 border border-green-300 text-green-700 rounded-lg hover:bg-green-100 text-sm">
                                Preview
                            </a>
                        ` : ''}
                        <button onclick="openR513Modal(${data.request_id}, '${data.full_name}', '${data.request_class}', ${data.seminar_dates_array ? data.seminar_dates_array.length : 0})"
                                class="px-3 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm">
                            ${hasR513 ? 'Regenerate' : 'Generate'} Certificate
                        </button>
                    </div>
                </div>
            </div>
        `;
    }
    
    // R2-01 Certificate (LORCAPP Record)
    if (data.lorcapp_id) {
        documentCount++;
        html += `
            <div class="p-4 border border-blue-200 bg-blue-50 rounded-lg">
                <div class="flex items-center justify-between">
                    <div class="flex-1">
                        <div class="font-medium text-gray-900">R2-01 Certificate</div>
                        <div class="text-sm text-gray-600">
                            LORCAPP Record ID: <span class="font-mono font-semibold">${data.lorcapp_id}</span>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <a href="<?php echo BASE_URL; ?>/lorcapp/view.php?id=${data.lorcapp_id}" 
                           target="_blank"
                           class="px-3 py-2 border border-blue-300 text-blue-700 rounded-lg hover:bg-blue-100 text-sm">
                            View Record
                        </a>
                        <a href="<?php echo BASE_URL; ?>/lorcapp/print_r201.php?id=${data.lorcapp_id}" 
                           target="_blank"
                           class="px-3 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm">
                            Print R2-01
                        </a>
                    </div>
                </div>
            </div>
        `;
    } else if (['oath_taken'].includes(data.status)) {
        // Show link to connect to LORCAPP for completed oaths
        html += `
            <div class="p-4 border border-gray-300 bg-gray-50 rounded-lg">
                <div class="flex items-center justify-between">
                    <div class="flex-1">
                        <div class="font-medium text-gray-900">R2-01 Certificate</div>
                        <div class="text-sm text-gray-600">Not yet linked to LORCAPP record</div>
                    </div>
                    <a href="<?php echo BASE_URL; ?>/requests/link-to-lorcapp.php?request_id=${data.request_id}" 
                       class="px-3 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-100 text-sm">
                        Link to LORCAPP
                    </a>
                </div>
            </div>
        `;
    }
    
    if (documentCount === 0) {
        html = `
            <div class="text-center py-8 text-gray-500">
                <svg class="w-12 h-12 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
                <p class="text-sm">Documents will be available as request progresses</p>
            </div>
        `;
    }
    
    container.innerHTML = html;
}

function populateWorkflow(data) {
    const workflowContainer = document.getElementById('workflow-actions');
    let html = '';
    
    // Define next possible actions based on current status
    const nextActions = [];
    switch (data.status) {
        case 'pending':
            nextActions.push({
                label: 'Approve for Seminar',
                action: 'approve_seminar',
                color: 'blue',
                icon: 'book',
                needsInput: true,
                inputType: 'date',
                inputLabel: 'Seminar Date (optional)',
                inputName: 'seminar_date'
            });
            nextActions.push({
                label: 'Reject Request',
                action: 'reject',
                color: 'red',
                icon: 'x-circle',
                needsInput: true,
                inputType: 'textarea',
                inputLabel: 'Rejection Reason',
                inputName: 'reason'
            });
            break;
        case 'requested_to_seminar':
            nextActions.push({
                label: 'Mark In Seminar',
                action: 'mark_in_seminar',
                color: 'indigo',
                icon: 'academic-cap'
            });
            break;
        case 'in_seminar':
            nextActions.push({
                label: 'Complete Seminar',
                action: 'complete_seminar',
                color: 'green',
                icon: 'check-circle',
                needsInput: true,
                inputType: 'date',
                inputLabel: 'Completion Date',
                inputName: 'completion_date'
            });
            break;
        case 'seminar_completed':
            nextActions.push({
                label: 'Approve for Oath',
                action: 'approve_oath',
                color: 'purple',
                icon: 'hand'
            });
            break;
        case 'requested_to_oath':
            nextActions.push({
                label: 'Mark Ready for Oath',
                action: 'mark_ready_oath',
                color: 'pink',
                icon: 'calendar'
            });
            break;
        case 'ready_to_oath':
            if (!data.record_code) {
                html += `
                    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-4">
                        <p class="text-sm text-yellow-800"><strong>⚠️ Action Required:</strong> Record code must be set before completing oath</p>
                        <button type="button" onclick="switchTab('workflow')" 
                            class="mt-2 text-sm text-yellow-900 underline">Set Record Code</button>
                    </div>
                `;
            } else {
                nextActions.push({
                    label: 'Complete Oath',
                    action: 'complete_oath',
                    color: 'green',
                    icon: 'badge-check',
                    needsInput: true,
                    inputType: 'date',
                    inputLabel: 'Actual Oath Date',
                    inputName: 'actual_oath_date'
                });
            }
            break;
    }
    
    if (nextActions.length > 0) {
        html += '<div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-4">';
        html += '<p class="text-sm text-blue-800"><strong>Quick Actions:</strong> Perform workflow actions directly from here</p>';
        html += '</div>';
        
        nextActions.forEach(action => {
            html += `
                <div class="bg-white border-2 border-${action.color}-200 rounded-lg p-4 mb-3">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-base font-medium text-gray-900">${action.label}</span>
                        <svg class="w-5 h-5 text-${action.color}-600" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                        </svg>
                    </div>
            `;
            
            if (action.needsInput) {
                html += `<div class="mb-3">`;
                if (action.inputType === 'textarea') {
                    html += `
                        <label class="block text-sm font-medium text-gray-700 mb-1">${action.inputLabel}</label>
                        <textarea id="input-${action.action}" rows="3" 
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-${action.color}-500 focus:border-${action.color}-500"
                            placeholder="Enter ${action.inputLabel.toLowerCase()}..."></textarea>
                    `;
                } else if (action.inputType === 'date') {
                    html += `
                        <label class="block text-sm font-medium text-gray-700 mb-1">${action.inputLabel}</label>
                        <input type="date" id="input-${action.action}"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-${action.color}-500 focus:border-${action.color}-500">
                    `;
                }
                html += `</div>`;
            }
            
            html += `
                    <button type="button" onclick="executeWorkflowAction('${action.action}', ${action.needsInput})"
                        class="w-full px-4 py-2 bg-${action.color}-600 text-white rounded-lg hover:bg-${action.color}-700 transition-colors font-medium">
                        ${action.label}
                    </button>
                </div>
            `;
        });
    } else {
        html += `
            <div class="text-center py-8 text-gray-500">
                <svg class="w-12 h-12 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
                <p class="font-medium">No workflow actions available</p>
                <p class="text-sm mt-1">Current status: ${data.status_label}</p>
            </div>
        `;
    }
    
    workflowContainer.innerHTML = html;
}

function showActionLoading(message = 'Processing...') {
    document.getElementById('action-loading-text').textContent = message;
    document.getElementById('modal-action-loading').classList.remove('hidden');
}

function hideActionLoading() {
    document.getElementById('modal-action-loading').classList.add('hidden');
}

async function executeWorkflowAction(action, needsInput) {
    let inputData = {};
    
    if (needsInput) {
        const inputElement = document.getElementById(`input-${action}`);
        if (!inputElement) {
            alert('Input field not found');
            return;
        }
        
        const value = inputElement.value.trim();
        
        // Validate required fields
        if (action === 'reject' && !value) {
            alert('Please provide a reason for rejection');
            return;
        }
        
        if (action === 'complete_seminar' && !value) {
            alert('Please select a completion date');
            return;
        }
        
        // Map input field to API parameter
        const fieldMap = {
            'approve_seminar': 'seminar_date',
            'complete_seminar': 'completion_date',
            'reject': 'reason'
        };
        
        const fieldName = fieldMap[action];
        if (fieldName) {
            inputData[fieldName] = value;
        }
    }
    
    // Confirm action
    const actionLabels = {
        'approve_seminar': 'approve this request for seminar',
        'mark_in_seminar': 'mark this request as in seminar',
        'complete_seminar': 'complete the seminar',
        'approve_oath': 'approve this request for oath',
        'mark_ready_oath': 'mark this request as ready for oath',
        'reject': 'reject this request'
    };
    
    if (!confirm(`Are you sure you want to ${actionLabels[action] || 'perform this action'}?`)) {
        return;
    }
    
    showActionLoading('Updating request...');
    
    try {
        const response = await fetch('<?php echo BASE_URL; ?>/api/update-request-workflow.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                request_id: currentRequestData.request_id,
                action: action,
                ...inputData
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Show success message briefly
            showActionLoading(result.message + ' ✓');
            
            // Reload the modal data
            setTimeout(async () => {
                try {
                    const dataResponse = await fetch(`<?php echo BASE_URL; ?>/api/get-officer-requests.php?id=${currentRequestData.request_id}`);
                    const newData = await dataResponse.json();
                    currentRequestData = newData;
                    populateModal(newData);
                    
                    // Switch to overview tab to show updated status
                    switchTab('overview');
                    
                    hideActionLoading();
                    
                    // Show success notification
                    alert(result.message);
                    
                    // Reload the page to update the card list
                    setTimeout(() => window.location.reload(), 500);
                } catch (error) {
                    console.error('Error reloading data:', error);
                    hideActionLoading();
                    alert('Action completed, but failed to refresh. Please reload the page.');
                    window.location.reload();
                }
            }, 1000);
        } else {
            hideActionLoading();
            alert('Error: ' + (result.error || 'Unknown error occurred'));
        }
    } catch (error) {
        hideActionLoading();
        console.error('Workflow action error:', error);
        alert('Error performing action. Please try again.');
    }
}
<?php endif; ?>

function populateTimeline(data) {
    const timelineContainer = document.getElementById('timeline-content');
    let html = '<div class="space-y-4">';
    
    // Build timeline items
    const events = [];
    
    if (data.requested_at) {
        events.push({
            date: data.requested_at,
            label: 'Request Created',
            description: `By ${data.requested_by_name || 'Unknown'}`,
            icon: 'plus',
            color: 'blue'
        });
    }
    
    if (data.seminar_approved_at) {
        events.push({
            date: data.seminar_approved_at,
            label: 'Approved for Seminar',
            description: `By ${data.seminar_approved_by_name || 'Unknown'}`,
            icon: 'check',
            color: 'blue'
        });
    }
    
    if (data.status === 'in_seminar' || data.status === 'seminar_completed' || 
        data.status === 'requested_to_oath' || data.status === 'ready_to_oath' || data.status === 'oath_taken') {
        events.push({
            date: data.seminar_date || data.requested_at,
            label: 'Seminar In Progress',
            description: data.seminar_date ? `Scheduled: ${data.seminar_date_formatted}` : 'Date TBD',
            icon: 'academic-cap',
            color: 'indigo'
        });
    }
    
    if (data.status === 'seminar_completed' || data.status === 'requested_to_oath' || 
        data.status === 'ready_to_oath' || data.status === 'oath_taken') {
        events.push({
            date: data.seminar_completion_date || data.requested_at,
            label: 'Seminar Completed',
            description: data.seminar_certificate_number ? `Certificate: ${data.seminar_certificate_number}` : '',
            icon: 'badge-check',
            color: 'green'
        });
    }
    
    if (data.oath_approved_at) {
        events.push({
            date: data.oath_approved_at,
            label: 'Approved for Oath',
            description: `By ${data.oath_approved_by_name || 'Unknown'}`,
            icon: 'check',
            color: 'purple'
        });
    }
    
    if (data.status === 'oath_taken' && data.oath_actual_date) {
        events.push({
            date: data.oath_actual_date,
            label: 'Oath Taken',
            description: data.completed_by_name ? `Completed by ${data.completed_by_name}` : '',
            icon: 'badge-check',
            color: 'green'
        });
    }
    
    if (data.status === 'rejected' && data.reviewed_at) {
        events.push({
            date: data.reviewed_at,
            label: 'Request Rejected',
            description: data.status_reason || 'No reason provided',
            icon: 'x-circle',
            color: 'red'
        });
    }
    
    // Render events
    if (events.length > 0) {
        events.forEach((event, index) => {
            const isLast = index === events.length - 1;
            html += `
                <div class="flex items-start space-x-3">
                    <div class="flex flex-col items-center">
                        <div class="w-10 h-10 rounded-full bg-${event.color}-100 flex items-center justify-center">
                            <svg class="w-5 h-5 text-${event.color}-600" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                            </svg>
                        </div>
                        ${!isLast ? '<div class="w-0.5 h-12 bg-gray-200 my-1"></div>' : ''}
                    </div>
                    <div class="flex-1 pb-4">
                        <p class="font-medium text-gray-900">${event.label}</p>
                        <p class="text-sm text-gray-500">${new Date(event.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}</p>
                        ${event.description ? `<p class="text-sm text-gray-600 mt-1">${event.description}</p>` : ''}
                    </div>
                </div>
            `;
        });
    } else {
        html += `
            <div class="text-center py-8 text-gray-500">
                <p class="text-sm">No timeline events available</p>
            </div>
        `;
    }
    
    html += '</div>';
    timelineContainer.innerHTML = html;
}

// Close modal on Escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeRequestModal();
        closePalasumpaanModal();
        closeR513Modal();
    }
});

// R5-13 Modal Functions
let r513RequestId = null;
let r513OfficerName = '';
let r513RequestClass = '';
let r513SeminarDays = 0;

function openR513Modal(requestId, officerName, requestClass, seminarDays) {
    r513RequestId = requestId;
    r513OfficerName = officerName;
    r513RequestClass = requestClass;
    r513SeminarDays = seminarDays;
    
    document.getElementById('r513-officer-name').textContent = officerName;
    document.getElementById('r513-request-class').textContent = requestClass;
    document.getElementById('r513-seminar-days').textContent = seminarDays;
    document.getElementById('r513-message').classList.add('hidden');
    document.getElementById('r513-error').classList.add('hidden');
    document.getElementById('r513Modal').classList.remove('hidden');
}

function closeR513Modal() {
    document.getElementById('r513Modal').classList.add('hidden');
    r513RequestId = null;
}

async function generateR513Certificate() {
    const submitBtn = document.getElementById('r513-submit-btn');
    const messageDiv = document.getElementById('r513-message');
    const errorDiv = document.getElementById('r513-error');
    
    // Show generating state
    submitBtn.disabled = true;
    submitBtn.innerHTML = `
        <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white inline" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
        Generating...
    `;
    messageDiv.classList.add('hidden');
    errorDiv.classList.add('hidden');
    
    try {
        const response = await fetch('<?php echo BASE_URL; ?>/generate-r513-html.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                request_id: r513RequestId
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            messageDiv.textContent = 'R5-13 Certificate generated successfully!';
            messageDiv.classList.remove('hidden');
            
            setTimeout(() => {
                closeR513Modal();
                // Refresh the modal data to show updated certificate status
                if (currentRequestId) {
                    openRequestModal(currentRequestId);
                }
            }, 2000);
        } else {
            errorDiv.textContent = result.error || 'Failed to generate certificate';
            errorDiv.classList.remove('hidden');
            submitBtn.disabled = false;
            submitBtn.innerHTML = 'Generate Certificate';
        }
    } catch (error) {
        console.error('Error generating R5-13:', error);
        errorDiv.textContent = 'An error occurred while generating the certificate';
        errorDiv.classList.remove('hidden');
        submitBtn.disabled = false;
        submitBtn.innerHTML = 'Generate Certificate';
    }
}

// Palasumpaan Modal Functions
let palasumpaanRequestId = null;
let palasumpaanOathDate = '';

function openPalasumpaanModal(requestId, oathDate) {
    palasumpaanRequestId = requestId;
    palasumpaanOathDate = oathDate || '';
    document.getElementById('palasumpaan-oath-date').value = palasumpaanOathDate;
    document.getElementById('palasumpaan-lokal').value = '';
    document.getElementById('palasumpaan-distrito').value = '';
    document.getElementById('palasumpaanModal').classList.remove('hidden');
}

function closePalasumpaanModal() {
    document.getElementById('palasumpaanModal').classList.add('hidden');
    palasumpaanRequestId = null;
}

function generatePalasumpaan(event) {
    event.preventDefault();
    
    const form = event.target;
    const oathDate = document.getElementById('palasumpaan-oath-date').value;
    const oathLokal = document.getElementById('palasumpaan-lokal').value;
    const oathDistrito = document.getElementById('palasumpaan-distrito').value;
    
    if (!oathDate || !oathLokal || !oathDistrito) {
        alert('Please fill in all required fields');
        return;
    }
    
    // Show generating state
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = `
        <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white inline" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
        Generating...
    `;
    
    // Build URL with parameters
    const params = new URLSearchParams({
        request_id: palasumpaanRequestId,
        oath_date: oathDate,
        oath_lokal: oathLokal,
        oath_distrito: oathDistrito
    });
    
    const url = '<?php echo BASE_URL; ?>/generate-palasumpaan.php?' + params.toString();
    
    // Open in new tab
    window.open(url, '_blank');
    
    // Close modal and reset button after short delay
    setTimeout(() => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
        closePalasumpaanModal();
    }, 1000);
}
</script>

<!-- Palasumpaan Generator Modal -->
<div id="palasumpaanModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
        <!-- Background overlay -->
        <div class="fixed inset-0 transition-opacity bg-gray-500 bg-opacity-75" onclick="closePalasumpaanModal()"></div>

        <!-- Modal panel -->
        <div class="inline-block w-full max-w-lg p-6 my-8 overflow-hidden text-left align-middle transition-all transform bg-white shadow-xl rounded-2xl">
            
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-bold text-gray-900">Generate Palasumpaan</h3>
                <button onclick="closePalasumpaanModal()" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            
            <form onsubmit="generatePalasumpaan(event)">
                <div class="space-y-4 mb-6">
                    <!-- Oath Date -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Oath Date <span class="text-red-500">*</span>
                        </label>
                        <input type="date" 
                               id="palasumpaan-oath-date"
                               required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <p class="text-xs text-gray-500 mt-1">Date when the oath was taken</p>
                    </div>

                    <!-- Oath Location - Lokal -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Lokal ng: <span class="text-red-500">*</span>
                        </label>
                        <input type="text" 
                               id="palasumpaan-lokal"
                               required
                               placeholder="e.g., San Fernando"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <p class="text-xs text-gray-500 mt-1">Local congregation where oath was administered</p>
                    </div>

                    <!-- Oath Location - Distrito -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Distrito Eklesiastiko ng: <span class="text-red-500">*</span>
                        </label>
                        <input type="text" 
                               id="palasumpaan-distrito"
                               required
                               placeholder="e.g., Pampanga East"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <p class="text-xs text-gray-500 mt-1">District where oath was administered</p>
                    </div>
                </div>

                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                    <div class="flex items-start">
                        <svg class="w-5 h-5 text-blue-600 mr-2 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <div class="flex-1">
                            <p class="text-sm text-blue-800">
                                This information will be printed on the Palasumpaan certificate. 
                                Please ensure the details are accurate before generating.
                            </p>
                        </div>
                    </div>
                </div>
                
                <div class="flex items-center justify-end space-x-3 pt-4">
                    <button type="button" 
                            onclick="closePalasumpaanModal()"
                            class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 bg-white hover:bg-gray-50 transition-colors">
                        Cancel
                    </button>
                    <button type="submit" 
                            class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors flex items-center">
                        Generate Certificate
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- R5-13 Certificate Generator Modal -->
<div id="r513Modal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
        <!-- Background overlay -->
        <div class="fixed inset-0 transition-opacity bg-gray-500 bg-opacity-75" onclick="closeR513Modal()"></div>

        <!-- Modal panel -->
        <div class="inline-block w-full max-w-lg p-6 my-8 overflow-hidden text-left align-middle transition-all transform bg-white shadow-xl rounded-2xl">
            
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-bold text-gray-900">Generate R5-13 Certificate</h3>
                <button onclick="closeR513Modal()" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            
            <div class="mb-4">
                <div class="bg-purple-50 border border-purple-200 rounded-lg p-4 mb-4">
                    <div class="flex items-start">
                        <svg class="w-5 h-5 text-purple-600 mr-2 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                        </svg>
                        <div class="flex-1">
                            <p class="text-sm font-semibold text-purple-900">About R5-13 Certificate</p>
                            <p class="text-xs text-purple-800 mt-1">
                                This generates Form 513 (Seminar Certificate) with all completed seminar dates.
                                The certificate will be saved as an encrypted PDF.
                            </p>
                        </div>
                    </div>
                </div>
                
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-600">Officer:</span>
                        <span class="font-medium text-gray-900" id="r513-officer-name"></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Class:</span>
                        <span class="font-medium text-gray-900" id="r513-request-class"></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Seminar Days:</span>
                        <span class="font-medium text-green-700"><span id="r513-seminar-days"></span> completed</span>
                    </div>
                </div>
            </div>
            
            <!-- Success/Error Messages -->
            <div id="r513-message" class="hidden mb-4 p-3 bg-green-50 border border-green-200 rounded-lg">
                <p class="text-sm text-green-800"></p>
            </div>
            <div id="r513-error" class="hidden mb-4 p-3 bg-red-50 border border-red-200 rounded-lg">
                <p class="text-sm text-red-800"></p>
            </div>
            
            <div class="flex items-center justify-end space-x-3 pt-4">
                <button type="button" 
                        onclick="closeR513Modal()"
                        class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 bg-white hover:bg-gray-50 transition-colors">
                    Cancel
                </button>
                <button type="button"
                        id="r513-submit-btn"
                        onclick="generateR513Certificate()"
                        class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors flex items-center">
                    Generate Certificate
                </button>
            </div>
        </div>
    </div>
</div>

</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';

