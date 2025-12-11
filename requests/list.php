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
if ($statusFilter !== 'all') {
    $query .= " AND r.status = ?";
    $params[] = $statusFilter;
}


// Search filter (include decrypted applicant names)
if (!empty($searchQuery)) {
    $query .= " AND (r.requested_department LIKE ? OR d.district_name LIKE ? OR l.local_name LIKE ?";
    $searchParam = "%$searchQuery%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;

    // Add applicant name search (decrypted)
    $query .= " OR LOWER(AES_DECRYPT(r.last_name_encrypted, UNHEX(SHA2(r.district_code, 256)))) LIKE ?";
    $query .= " OR LOWER(AES_DECRYPT(r.first_name_encrypted, UNHEX(SHA2(r.district_code, 256)))) LIKE ?";
    $params[] = '%' . strtolower($searchQuery) . '%';
    $params[] = '%' . strtolower($searchQuery) . '%';

    // For CODE D, also search existing officer names
    $query .= " OR LOWER(AES_DECRYPT(o.last_name_encrypted, UNHEX(SHA2(r.district_code, 256)))) LIKE ?";
    $query .= " OR LOWER(AES_DECRYPT(o.first_name_encrypted, UNHEX(SHA2(r.district_code, 256)))) LIKE ?";
    $params[] = '%' . strtolower($searchQuery) . '%';
    $params[] = '%' . strtolower($searchQuery) . '%';

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

    <!-- Requests Table -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <?php if ($canManage): ?>
                        <th class="px-4 py-3 text-left">
                            <input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)" class="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded focus:ring-blue-500">
                        </th>
                        <?php endif; ?>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Applicant</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Location</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Department</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Dates</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Requested By</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($requests)): ?>
                    <tr>
                        <td colspan="<?php echo $canManage ? '8' : '7'; ?>" class="px-4 py-8 text-center text-gray-500">
                            <svg class="w-12 h-12 mx-auto text-gray-300 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                            </svg>
                            <p class="font-medium">No requests found</p>
                            <p class="text-sm">Try adjusting your filters or create a new request</p>
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($requests as $request): 
                            // For CODE D, use the existing officer's name; for CODE A, use the request's name
                            if ($request['record_code'] === 'D' && $request['existing_officer_uuid']) {
                                $lastName = Encryption::decrypt($request['existing_last_name'], $request['district_code']);
                                $firstName = Encryption::decrypt($request['existing_first_name'], $request['district_code']);
                                $middleInitial = $request['existing_middle_initial'] ? Encryption::decrypt($request['existing_middle_initial'], $request['district_code']) : '';
                            } else {
                                $lastName = Encryption::decrypt($request['last_name_encrypted'], $request['district_code']);
                                $firstName = Encryption::decrypt($request['first_name_encrypted'], $request['district_code']);
                                $middleInitial = $request['middle_initial_encrypted'] ? Encryption::decrypt($request['middle_initial_encrypted'], $request['district_code']) : '';
                            }
                            $fullName = "$lastName, $firstName" . ($middleInitial ? " $middleInitial." : "");
                            $statusInfo = $statusConfig[$request['status']];
                        ?>
                        <tr class="hover:bg-gray-50 transition-colors">
                            <?php if ($canManage): ?>
                            <td class="px-4 py-3">
                                <input type="checkbox" class="request-checkbox w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded focus:ring-blue-500" 
                                       value="<?php echo $request['request_id']; ?>" 
                                       data-status="<?php echo $request['status']; ?>"
                                       onchange="updateBulkActionBar()">
                            </td>
                            <?php endif; ?>
                            <td class="px-4 py-3">
                                <div class="font-mono font-semibold text-gray-900 uppercase"><?php echo Security::escape($fullName); ?></div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="text-sm text-gray-900"><?php echo Security::escape($request['local_name']); ?></div>
                                <div class="text-xs text-gray-500"><?php echo Security::escape($request['district_name']); ?></div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <div>
                                        <div class="text-sm font-medium text-gray-900"><?php echo Security::escape($request['requested_department']); ?></div>
                                        <?php if ($request['requested_duty']): ?>
                                        <div class="text-xs text-gray-500"><?php echo Security::escape($request['requested_duty']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($request['is_imported']): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-800" title="Imported from LORCAPP R-201 Database">
                                            <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                <path d="M3 12v3c0 1.657 3.134 3 7 3s7-1.343 7-3v-3c0 1.657-3.134 3-7 3s-7-1.343-7-3z"/>
                                                <path d="M3 7v3c0 1.657 3.134 3 7 3s7-1.343 7-3V7c0 1.657-3.134 3-7 3S3 8.657 3 7z"/>
                                                <path d="M17 5c0 1.657-3.134 3-7 3S3 6.657 3 5s3.134-3 7-3 7 1.343 7 3z"/>
                                            </svg>
                                            LORCAPP
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-<?php echo $statusInfo['color']; ?>-100 text-<?php echo $statusInfo['color']; ?>-800">
                                    <?php echo $statusInfo['label']; ?>
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="text-xs text-gray-900">Requested: <?php echo date('m/d/Y', strtotime($request['requested_at'])); ?></div>
                                <?php if ($request['seminar_date']): ?>
                                <div class="text-xs text-gray-500">Seminar: <?php echo date('m/d/Y', strtotime($request['seminar_date'])); ?></div>
                                <?php endif; ?>
                                <?php if ($request['oath_scheduled_date']): ?>
                                <div class="text-xs text-gray-500">Oath: <?php echo date('m/d/Y', strtotime($request['oath_scheduled_date'])); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3">
                                <div class="text-sm text-gray-900"><?php echo Security::escape($request['requested_by_name']); ?></div>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="view.php?id=<?php echo $request['request_id']; ?>" class="inline-flex items-center px-3 py-1 bg-blue-50 text-blue-700 rounded-lg hover:bg-blue-100 transition-colors text-sm font-medium">
                                        View Details
                                    </a>
                                    <?php if ($canManage): ?>
                                    <form method="POST" action="delete-request.php" class="delete-request-form" style="display:inline;">
                                        <input type="hidden" name="request_id" value="<?php echo $request['request_id']; ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                                        <button type="button" class="inline-flex items-center px-3 py-1 bg-red-50 text-red-700 rounded-lg hover:bg-red-100 transition-colors text-sm font-medium" onclick="openDeleteModal(this)">
                                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                            </svg>
                                            Delete
                                        </button>
                                    </form>
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
                                    <?php endif; ?>
                                    <?php if ($canManage && $request['status'] !== 'oath_taken'): ?>
                                    <!-- Quick Status Update Dropdown -->
                                    <div class="relative inline-block text-left" x-data="{ open: false }">
                                        <button @click="open = !open" @click.away="open = false" type="button" class="inline-flex items-center px-3 py-1 bg-green-50 text-green-700 rounded-lg hover:bg-green-100 transition-colors text-sm font-medium">
                                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                                            </svg>
                                            Update Status
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
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
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

<?php
$content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>

