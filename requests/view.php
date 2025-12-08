<?php
/**
 * View and Manage Officer Request
 * View details and progress request through workflow
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';

Security::requireLogin();

$db = Database::getInstance()->getConnection();
$user = getCurrentUser();

$requestId = $_GET['id'] ?? 0;
$success = '';
$error = '';

// Get request details
$stmt = $db->prepare("
    SELECT r.*, 
           d.district_name, d.district_code,
           l.local_name, l.local_code,
           u1.full_name as requested_by_name,
           u2.full_name as reviewed_by_name,
           u3.full_name as seminar_approved_by_name,
           u4.full_name as oath_approved_by_name,
           u5.full_name as completed_by_name,
           o.officer_uuid,
           existing_o.last_name_encrypted as existing_last_name,
           existing_o.first_name_encrypted as existing_first_name,
           existing_o.middle_initial_encrypted as existing_middle_initial
    FROM officer_requests r
    LEFT JOIN districts d ON r.district_code = d.district_code
    LEFT JOIN local_congregations l ON r.local_code = l.local_code
    LEFT JOIN users u1 ON r.requested_by = u1.user_id
    LEFT JOIN users u2 ON r.reviewed_by = u2.user_id
    LEFT JOIN users u3 ON r.approved_seminar_by = u3.user_id
    LEFT JOIN users u4 ON r.approved_oath_by = u4.user_id
    LEFT JOIN users u5 ON r.completed_by = u5.user_id
    LEFT JOIN officers o ON r.officer_id = o.officer_id
    LEFT JOIN officers existing_o ON r.existing_officer_uuid = existing_o.officer_uuid
    WHERE r.request_id = ?
");
$stmt->execute([$requestId]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    header('Location: list.php');
    exit;
}

// Check permissions
$canManage = $user['role'] === 'admin' || 
             ($user['role'] === 'district' && $user['district_code'] === $request['district_code']) ||
             ($user['role'] === 'local' && $user['local_code'] === $request['local_code']);

// Decrypt personal information - use existing officer's name for CODE D
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

// Handle workflow actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    Security::validateCSRFToken($_POST['csrf_token'] ?? '');
    
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'set_code':
                $recordCode = $_POST['record_code'] ?? '';
                
                if (!in_array($recordCode, ['A', 'D'])) {
                    throw new Exception('Invalid record code. Must be A or D.');
                }
                
                // If CODE D, also need to link to existing officer
                $existingOfficerUuid = null;
                if ($recordCode === 'D') {
                    $existingOfficerUuid = $_POST['existing_officer_uuid'] ?? '';
                    if (empty($existingOfficerUuid)) {
                        throw new Exception('Please select an existing officer to link for CODE D.');
                    }
                    
                    // Verify officer exists
                    $stmt = $db->prepare("SELECT officer_id FROM officers WHERE officer_uuid = ?");
                    $stmt->execute([$existingOfficerUuid]);
                    if (!$stmt->fetch()) {
                        throw new Exception('Selected officer not found.');
                    }
                }
                
                $stmt = $db->prepare("
                    UPDATE officer_requests 
                    SET record_code = ?,
                        existing_officer_uuid = ?
                    WHERE request_id = ?
                ");
                $stmt->execute([$recordCode, $existingOfficerUuid, $requestId]);
                
                if ($recordCode === 'A') {
                    $success = "Record code set to CODE A successfully. This will create a new officer.";
                } else {
                    $success = "Record code set to CODE D successfully. Officer linked to existing record.";
                }
                
                // Refresh request data with all joins
                $stmt = $db->prepare("
                    SELECT r.*, 
                           d.district_name, d.district_code,
                           l.local_name, l.local_code,
                           u1.full_name as requested_by_name,
                           u2.full_name as reviewed_by_name,
                           u3.full_name as seminar_approved_by_name,
                           u4.full_name as oath_approved_by_name,
                           u5.full_name as completed_by_name,
                           o.officer_uuid,
                           existing_o.last_name_encrypted as existing_last_name,
                           existing_o.first_name_encrypted as existing_first_name,
                           existing_o.middle_initial_encrypted as existing_middle_initial
                    FROM officer_requests r
                    LEFT JOIN districts d ON r.district_code = d.district_code
                    LEFT JOIN local_congregations l ON r.local_code = l.local_code
                    LEFT JOIN users u1 ON r.requested_by = u1.user_id
                    LEFT JOIN users u2 ON r.reviewed_by = u2.user_id
                    LEFT JOIN users u3 ON r.approved_seminar_by = u3.user_id
                    LEFT JOIN users u4 ON r.approved_oath_by = u4.user_id
                    LEFT JOIN users u5 ON r.completed_by = u5.user_id
                    LEFT JOIN officers o ON r.officer_id = o.officer_id
                    LEFT JOIN officers existing_o ON r.existing_officer_uuid = existing_o.officer_uuid
                    WHERE r.request_id = ?
                ");
                $stmt->execute([$requestId]);
                $request = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Re-decrypt names after refresh
                if ($request['record_code'] === 'D' && $request['existing_officer_uuid']) {
                    $lastName = Encryption::decrypt($request['existing_last_name'], $request['district_code']);
                    $firstName = Encryption::decrypt($request['existing_first_name'], $request['district_code']);
                    $middleInitial = $request['existing_middle_initial'] ? Encryption::decrypt($request['existing_middle_initial'], $request['district_code']) : '';
                } else {
                    $lastName = Encryption::decrypt($request['last_name_encrypted'], $request['district_code']);
                    $firstName = Encryption::decrypt($request['first_name_encrypted'], $request['district_code']);
                    $middleInitial = $request['middle_initial_encrypted'] ? Encryption::decrypt($request['middle_initial_encrypted'], $request['district_code']) : '';
                }
                $fullName = trim("$lastName, $firstName" . ($middleInitial ? " $middleInitial" : ""));
                break;
                
            case 'approve_seminar':
                $seminarDate = $_POST['seminar_date'] ?? null;
                
                $stmt = $db->prepare("
                    UPDATE officer_requests 
                    SET status = 'requested_to_seminar',
                        seminar_date = ?,
                        approved_seminar_by = ?,
                        seminar_approved_at = NOW()
                    WHERE request_id = ?
                ");
                $stmt->execute([$seminarDate, $user['user_id'], $requestId]);
                $success = "Request approved for seminar attendance.";
                break;
                
            case 'mark_in_seminar':
                $stmt = $db->prepare("
                    UPDATE officer_requests 
                    SET status = 'in_seminar'
                    WHERE request_id = ?
                ");
                $stmt->execute([$requestId]);
                $success = "Marked as currently in seminar.";
                break;
                
            case 'complete_seminar':
                $completionDate = $_POST['completion_date'] ?? null;
                $certificateNumber = $_POST['certificate_number'] ?? '';
                
                $stmt = $db->prepare("
                    UPDATE officer_requests 
                    SET status = 'seminar_completed',
                        seminar_completion_date = ?,
                        seminar_certificate_number = ?
                    WHERE request_id = ?
                ");
                $stmt->execute([$completionDate, $certificateNumber, $requestId]);
                $success = "Seminar marked as completed.";
                break;
                
            case 'approve_oath':
                $stmt = $db->prepare("
                    UPDATE officer_requests 
                    SET status = 'requested_to_oath',
                        approved_oath_by = ?,
                        oath_approved_at = NOW()
                    WHERE request_id = ?
                ");
                $stmt->execute([$user['user_id'], $requestId]);
                $success = "Approved for oath taking ceremony.";
                break;
                
            case 'mark_ready_oath':
                $stmt = $db->prepare("
                    UPDATE officer_requests 
                    SET status = 'ready_to_oath'
                    WHERE request_id = ?
                ");
                $stmt->execute([$requestId]);
                $success = "Marked as ready for oath.";
                break;
                
            case 'complete_oath':
                $actualOathDate = $_POST['actual_oath_date'] ?? null;
                
                // Validate record code is set (required for imported requests)
                if (empty($request['record_code'])) {
                    throw new Exception('Record code must be set before completing oath. Please set CODE A or CODE D first.');
                }
                
                // Check if CODE D (existing officer) or CODE A (new officer)
                if (!empty($request['record_code']) && $request['record_code'] === 'D' && !empty($request['existing_officer_uuid'])) {
                    // CODE D: Link to existing officer and add department
                    $stmt = $db->prepare("SELECT officer_id FROM officers WHERE officer_uuid = ?");
                    $stmt->execute([$request['existing_officer_uuid']]);
                    $officer = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$officer) {
                        throw new Exception('Existing officer not found for CODE D.');
                    }
                    
                    $officerId = $officer['officer_id'];
                    
                    // Reactivate officer and update location
                    $stmt = $db->prepare("UPDATE officers SET is_active = 1, local_code = ?, district_code = ? WHERE officer_id = ?");
                    $stmt->execute([$request['local_code'], $request['district_code'], $officerId]);
                    
                    // Add new department to existing officer
                    $stmt = $db->prepare("
                        INSERT INTO officer_departments (officer_id, department, duty, oath_date, is_active)
                        VALUES (?, ?, ?, ?, 1)
                    ");
                    $stmt->execute([
                        $officerId,
                        $request['requested_department'],
                        $request['requested_duty'],
                        $actualOathDate
                    ]);
                    
                    // Update request status
                    $stmt = $db->prepare("
                        UPDATE officer_requests 
                        SET status = 'oath_taken',
                            oath_actual_date = ?,
                            officer_id = ?,
                            completed_by = ?,
                            completed_at = NOW()
                        WHERE request_id = ?
                    ");
                    $stmt->execute([$actualOathDate, $officerId, $user['user_id'], $requestId]);
                    
                    // Audit log
                    $stmt = $db->prepare("
                        INSERT INTO audit_log (user_id, action, table_name, record_id, new_values, ip_address, user_agent)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $user['user_id'],
                        'complete_officer_request_code_d',
                        'officers',
                        $officerId,
                        json_encode([
                            'request_id' => $requestId,
                            'existing_officer_uuid' => $request['existing_officer_uuid'],
                            'new_department' => $request['requested_department']
                        ]),
                        $_SERVER['REMOTE_ADDR'] ?? '',
                        $_SERVER['HTTP_USER_AGENT'] ?? ''
                    ]);
                    
                    $success = "Oath completed! Existing officer updated with new department (CODE D).";
                } else {
                    // CODE A: Create new officer record
                    $officerUuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                        mt_rand(0, 0xffff),
                        mt_rand(0, 0x0fff) | 0x4000,
                        mt_rand(0, 0x3fff) | 0x8000,
                        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
                    );
                    
                    // Create officer record
                    $stmt = $db->prepare("
                        INSERT INTO officers (
                            officer_uuid, last_name_encrypted, first_name_encrypted, middle_initial_encrypted,
                            district_code, local_code, record_code, is_imported, is_active, created_by
                        ) VALUES (?, ?, ?, ?, ?, ?, 'A', ?, 1, ?)
                    ");
                    $stmt->execute([
                        $officerUuid,
                        $request['last_name_encrypted'],
                        $request['first_name_encrypted'],
                        $request['middle_initial_encrypted'],
                        $request['district_code'],
                        $request['local_code'],
                        $request['is_imported'] ?? 0,
                        $user['user_id']
                    ]);
                    
                    $officerId = $db->lastInsertId();
                    
                    // Assign department
                    $stmt = $db->prepare("
                        INSERT INTO officer_departments (officer_id, department, duty, oath_date, is_active)
                        VALUES (?, ?, ?, ?, 1)
                    ");
                    $stmt->execute([
                        $officerId,
                        $request['requested_department'],
                        $request['requested_duty'],
                        $actualOathDate
                    ]);
                    
                    // Update request status
                    $stmt = $db->prepare("
                        UPDATE officer_requests 
                        SET status = 'oath_taken',
                            oath_actual_date = ?,
                            officer_id = ?,
                            completed_by = ?,
                            completed_at = NOW()
                        WHERE request_id = ?
                    ");
                    $stmt->execute([$actualOathDate, $officerId, $user['user_id'], $requestId]);
                    
                    // Update headcount (only for new officers)
                    $stmt = $db->prepare("
                        INSERT INTO headcount (district_code, local_code, total_count)
                        VALUES (?, ?, 1)
                        ON DUPLICATE KEY UPDATE total_count = total_count + 1
                    ");
                    $stmt->execute([$request['district_code'], $request['local_code']]);
                    
                    // Audit log
                    $stmt = $db->prepare("
                        INSERT INTO audit_log (user_id, action, table_name, record_id, new_values, ip_address, user_agent)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $user['user_id'],
                        'complete_officer_request_code_a',
                        'officers',
                        $officerId,
                        json_encode([
                            'request_id' => $requestId,
                            'officer_uuid' => $officerUuid
                        ]),
                        $_SERVER['REMOTE_ADDR'] ?? '',
                        $_SERVER['HTTP_USER_AGENT'] ?? ''
                    ]);
                    
                    $success = "Oath completed! Officer record created successfully (CODE A).";
                }
                break;
                
            case 'reject':
                $reason = $_POST['rejection_reason'] ?? '';
                
                $stmt = $db->prepare("
                    UPDATE officer_requests 
                    SET status = 'rejected',
                        status_reason = ?,
                        reviewed_by = ?,
                        reviewed_at = NOW()
                    WHERE request_id = ?
                ");
                $stmt->execute([$reason, $user['user_id'], $requestId]);
                $success = "Request has been rejected.";
                break;
                
            case 'delete':
                // Delete the request
                $stmt = $db->prepare("DELETE FROM officer_requests WHERE request_id = ?");
                $stmt->execute([$requestId]);
                
                // Audit log
                $stmt = $db->prepare("
                    INSERT INTO audit_log (user_id, action, table_name, record_id, old_values, ip_address, user_agent)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $user['user_id'],
                    'delete_officer_request',
                    'officer_requests',
                    $requestId,
                    json_encode([
                        'department' => $request['requested_department'],
                        'local_code' => $request['local_code'],
                        'status' => $request['status']
                    ]),
                    $_SERVER['REMOTE_ADDR'] ?? '',
                    $_SERVER['HTTP_USER_AGENT'] ?? ''
                ]);
                
                // Redirect to list after deletion
                setFlashMessage('success', 'Request has been deleted successfully.');
                redirect(BASE_URL . '/requests/list.php');
                break;
        }
        
        // Refresh request data
        $stmt = $db->prepare("SELECT * FROM officer_requests WHERE request_id = ?");
        $stmt->execute([$requestId]);
        $request = array_merge($request, $stmt->fetch(PDO::FETCH_ASSOC));
        
    } catch (Exception $e) {
        error_log("Error updating request: " . $e->getMessage());
        $error = "Failed to update request. Please try again.";
    }
}

// Workflow configuration
$workflowSteps = [
    'pending' => ['next' => 'requested_to_seminar', 'label' => 'Approve for Seminar', 'color' => 'yellow'],
    'requested_to_seminar' => ['next' => 'in_seminar', 'label' => 'Mark In Seminar', 'color' => 'blue'],
    'in_seminar' => ['next' => 'seminar_completed', 'label' => 'Complete Seminar', 'color' => 'indigo'],
    'seminar_completed' => ['next' => 'requested_to_oath', 'label' => 'Approve for Oath', 'color' => 'purple'],
    'requested_to_oath' => ['next' => 'ready_to_oath', 'label' => 'Mark Ready', 'color' => 'pink'],
    'ready_to_oath' => ['next' => 'oath_taken', 'label' => 'Complete Oath', 'color' => 'green']
];

$currentStep = $request['status'];
$canProgress = isset($workflowSteps[$currentStep]) && $canManage;

$pageTitle = "View Request";
ob_start();
?>

<div class="p-6 max-w-6xl mx-auto space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-3xl font-bold text-gray-900">Officer Request Details</h2>
            <p class="text-sm text-gray-500">Request #<?php echo $request['request_id']; ?></p>
        </div>
        <a href="list.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg text-gray-700 bg-white hover:bg-gray-50 transition-colors">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
            </svg>
            Back to List
        </a>
    </div>

    <?php if ($success): ?>
    <div class="bg-green-50 border border-green-200 rounded-lg p-4">
        <div class="flex items-start">
            <svg class="w-5 h-5 text-green-600 mr-3 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
            </svg>
            <span class="text-sm text-green-800"><?php echo Security::escape($success); ?></span>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="bg-red-50 border border-red-200 rounded-lg p-4">
        <div class="flex items-start">
            <svg class="w-5 h-5 text-red-600 mr-3 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
            </svg>
            <span class="text-sm text-red-800"><?php echo Security::escape($error); ?></span>
        </div>
    </div>
    <?php endif; ?>

    <!-- Record Code Setter (for imported LORCAPP requests) -->
    <?php if ($canManage && empty($request['record_code']) && !empty($request['is_imported'])): ?>
    <div class="bg-yellow-50 border-l-4 border-yellow-400 p-6 rounded-lg shadow-sm">
        <div class="flex items-start">
            <svg class="w-6 h-6 text-yellow-600 mr-3 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
            </svg>
            <div class="flex-1">
                <h3 class="font-semibold text-yellow-900 mb-2">⚠️ Record Code Not Set</h3>
                <p class="text-sm text-yellow-800 mb-4">
                    This request was imported from <strong>LORCAPP R-201 system</strong>. Before proceeding with the oath, you must set the record code:
                </p>
                <div class="bg-yellow-100 border border-yellow-300 rounded-lg p-4 mb-4 text-sm text-yellow-900">
                    <p class="mb-2"><strong class="font-semibold">CODE A:</strong> New officer (will create a new record)</p>
                    <p><strong class="font-semibold">CODE D:</strong> Existing officer (will link to an existing officer record)</p>
                </div>
                
                <!-- CODE A Form -->
                <form method="POST" class="mb-4">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="set_code">
                    <button type="submit" name="record_code" value="A" 
                            class="inline-flex items-center px-5 py-2.5 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors font-medium shadow-sm">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                        </svg>
                        Set CODE A (New Officer)
                    </button>
                </form>
                
                <!-- CODE D Form with Officer Search -->
                <div class="border-t border-yellow-300 pt-4">
                    <h4 class="font-semibold text-yellow-900 mb-3">Or link to existing officer (CODE D):</h4>
                    <form method="POST" id="codeDForm">
                        <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                        <input type="hidden" name="action" value="set_code">
                        <input type="hidden" name="record_code" value="D">
                        <input type="hidden" name="existing_officer_uuid" id="selectedOfficerUuid">
                        
                        <div class="mb-3">
                            <label class="block text-sm font-medium text-yellow-900 mb-2">Search for existing officer:</label>
                            <input type="text" 
                                   id="officerSearch" 
                                   placeholder="Type officer name to search..."
                                   class="w-full px-4 py-2 border border-yellow-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        
                        <div id="searchResults" class="mb-3 hidden">
                            <div class="bg-white border border-yellow-300 rounded-lg max-h-60 overflow-y-auto"></div>
                        </div>
                        
                        <div id="selectedOfficer" class="hidden mb-3">
                            <div class="bg-white border-2 border-blue-500 rounded-lg p-3">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <p class="font-semibold text-gray-900" id="selectedOfficerName"></p>
                                        <p class="text-sm text-gray-600" id="selectedOfficerInfo"></p>
                                    </div>
                                    <button type="button" onclick="clearSelection()" class="text-red-600 hover:text-red-700">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <button type="submit" 
                                id="submitCodeD"
                                disabled
                                class="inline-flex items-center px-5 py-2.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-medium shadow-sm disabled:opacity-50 disabled:cursor-not-allowed">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path>
                            </svg>
                            Set CODE D (Link Existing Officer)
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <script>
    // Officer search functionality
    let searchTimeout;
    const searchInput = document.getElementById('officerSearch');
    const searchResults = document.getElementById('searchResults');
    const selectedOfficer = document.getElementById('selectedOfficer');
    const submitButton = document.getElementById('submitCodeD');
    
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        const query = this.value.trim();
        
        if (query.length < 2) {
            searchResults.classList.add('hidden');
            return;
        }
        
        searchTimeout = setTimeout(() => {
            fetch('../api/search-officers.php?q=' + encodeURIComponent(query))
                .then(response => response.json())
                .then(data => {
                    if (Array.isArray(data) && data.length > 0) {
                        displayResults(data);
                    } else {
                        searchResults.querySelector('div').innerHTML = '<div class="p-4 text-sm text-gray-500">No officers found</div>';
                        searchResults.classList.remove('hidden');
                    }
                })
                .catch(error => {
                    console.error('Search error:', error);
                    searchResults.querySelector('div').innerHTML = '<div class="p-4 text-sm text-red-500">Error searching officers</div>';
                    searchResults.classList.remove('hidden');
                });
        }, 300);
    });
    
    function displayResults(officers) {
        const resultsHtml = officers.map(officer => `
            <div class="p-3 hover:bg-gray-50 cursor-pointer border-b border-gray-200 last:border-0" 
                 onclick="selectOfficer('${officer.id}', '${escapeHtml(officer.name)}', '${escapeHtml(officer.location)}')">
                <p class="font-semibold text-gray-900">${escapeHtml(officer.name)}</p>
                <p class="text-sm text-gray-600">${escapeHtml(officer.location)}</p>
            </div>
        `).join('');
        
        searchResults.querySelector('div').innerHTML = resultsHtml;
        searchResults.classList.remove('hidden');
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    function selectOfficer(uuid, name, location) {
        document.getElementById('selectedOfficerUuid').value = uuid;
        document.getElementById('selectedOfficerName').textContent = name;
        document.getElementById('selectedOfficerInfo').textContent = location;
        
        selectedOfficer.classList.remove('hidden');
        searchResults.classList.add('hidden');
        submitButton.disabled = false;
        searchInput.value = '';
    }
    
    function clearSelection() {
        document.getElementById('selectedOfficerUuid').value = '';
        selectedOfficer.classList.add('hidden');
        submitButton.disabled = true;
    }
    </script>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main Content -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Personal Information -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4 flex items-center">
                    <svg class="w-5 h-5 text-blue-600 mr-2" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"/>
                    </svg>
                    Applicant Information
                </h3>
                
                <div class="space-y-3">
                    <div>
                        <p class="text-sm text-gray-500">Full Name</p>
                        <p class="font-mono text-lg font-semibold text-gray-900 uppercase"><?php echo Security::escape($fullName); ?></p>
                    </div>
                    
                    <?php if ($request['email']): ?>
                    <div>
                        <p class="text-sm text-gray-500">Email</p>
                        <p class="text-gray-900"><?php echo Security::escape($request['email']); ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($request['phone']): ?>
                    <div>
                        <p class="text-sm text-gray-500">Phone</p>
                        <p class="text-gray-900"><?php echo Security::escape($request['phone']); ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Location and Position -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Location & Position</h3>
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <p class="text-sm text-gray-500">District</p>
                        <p class="font-medium text-gray-900"><?php echo Security::escape($request['district_name']); ?></p>
                    </div>
                    
                    <div>
                        <p class="text-sm text-gray-500">Local Congregation</p>
                        <p class="font-medium text-gray-900"><?php echo Security::escape($request['local_name']); ?></p>
                    </div>
                    
                    <div>
                        <p class="text-sm text-gray-500">Department</p>
                        <p class="font-medium text-gray-900"><?php echo Security::escape($request['requested_department']); ?></p>
                    </div>
                    
                    <?php if ($request['requested_duty']): ?>
                    <div>
                        <p class="text-sm text-gray-500">Specific Duty</p>
                        <p class="font-medium text-gray-900"><?php echo Security::escape($request['requested_duty']); ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Seminar Details -->
            <?php if (in_array($request['status'], ['requested_to_seminar', 'in_seminar', 'seminar_completed', 'requested_to_oath', 'ready_to_oath', 'oath_taken'])): ?>
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4 flex items-center">
                    <svg class="w-5 h-5 text-indigo-600 mr-2" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M10.394 2.08a1 1 0 00-.788 0l-7 3a1 1 0 000 1.84L5.25 8.051a.999.999 0 01.356-.257l4-1.714a1 1 0 11.788 1.838L7.667 9.088l1.94.831a1 1 0 00.787 0l7-3a1 1 0 000-1.838l-7-3zM3.31 9.397L5 10.12v4.102a8.969 8.969 0 00-1.05-.174 1 1 0 01-.89-.89 11.115 11.115 0 01.25-3.762zM9.3 16.573A9.026 9.026 0 007 14.935v-3.957l1.818.78a3 3 0 002.364 0l5.508-2.361a11.026 11.026 0 01.25 3.762 1 1 0 01-.89.89 8.968 8.968 0 00-5.35 2.524 1 1 0 01-1.4 0zM6 18a1 1 0 001-1v-2.065a8.935 8.935 0 00-2-.712V17a1 1 0 001 1z"/>
                    </svg>
                    Seminar Details
                </h3>
                
                <div class="space-y-3">
                    <?php if ($request['seminar_date']): ?>
                    <div>
                        <p class="text-sm text-gray-500">Seminar Date</p>
                        <p class="font-medium text-gray-900"><?php echo date('F j, Y', strtotime($request['seminar_date'])); ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($request['seminar_location']): ?>
                    <div>
                        <p class="text-sm text-gray-500">Location</p>
                        <p class="font-medium text-gray-900"><?php echo Security::escape($request['seminar_location']); ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($request['seminar_completion_date']): ?>
                    <div>
                        <p class="text-sm text-gray-500">Completion Date</p>
                        <p class="font-medium text-gray-900"><?php echo date('F j, Y', strtotime($request['seminar_completion_date'])); ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($request['seminar_certificate_number']): ?>
                    <div>
                        <p class="text-sm text-gray-500">Certificate Number</p>
                        <p class="font-medium text-gray-900"><?php echo Security::escape($request['seminar_certificate_number']); ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($request['seminar_notes']): ?>
                    <div>
                        <p class="text-sm text-gray-500">Notes</p>
                        <p class="text-gray-900"><?php echo nl2br(Security::escape($request['seminar_notes'])); ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Oath Details -->
            <?php if (in_array($request['status'], ['requested_to_oath', 'ready_to_oath', 'oath_taken'])): ?>
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4 flex items-center">
                    <svg class="w-5 h-5 text-purple-600 mr-2" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M6.267 3.455a3.066 3.066 0 001.745-.723 3.066 3.066 0 013.976 0 3.066 3.066 0 001.745.723 3.066 3.066 0 012.812 2.812c.051.643.304 1.254.723 1.745a3.066 3.066 0 010 3.976 3.066 3.066 0 00-.723 1.745 3.066 3.066 0 01-2.812 2.812 3.066 3.066 0 00-1.745.723 3.066 3.066 0 01-3.976 0 3.066 3.066 0 00-1.745-.723 3.066 3.066 0 01-2.812-2.812 3.066 3.066 0 00-.723-1.745 3.066 3.066 0 010-3.976 3.066 3.066 0 00.723-1.745 3.066 3.066 0 012.812-2.812zm7.44 5.252a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                    </svg>
                    Oath Taking Details
                </h3>
                
                <div class="space-y-3">
                    <?php if ($request['oath_scheduled_date']): ?>
                    <div>
                        <p class="text-sm text-gray-500">Scheduled Date</p>
                        <p class="font-medium text-gray-900"><?php echo date('F j, Y', strtotime($request['oath_scheduled_date'])); ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($request['oath_actual_date']): ?>
                    <div>
                        <p class="text-sm text-gray-500">Actual Oath Date</p>
                        <p class="font-medium text-green-600"><?php echo date('F j, Y', strtotime($request['oath_actual_date'])); ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($request['oath_location']): ?>
                    <div>
                        <p class="text-sm text-gray-500">Location</p>
                        <p class="font-medium text-gray-900"><?php echo Security::escape($request['oath_location']); ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($request['oath_notes']): ?>
                    <div>
                        <p class="text-sm text-gray-500">Notes</p>
                        <p class="text-gray-900"><?php echo nl2br(Security::escape($request['oath_notes'])); ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($request['officer_uuid']): ?>
                    <div class="bg-green-50 border border-green-200 rounded-lg p-4">
                        <p class="text-sm text-green-700 font-medium mb-1">Officer Created</p>
                        <p class="text-xs text-green-600 font-mono"><?php echo Security::escape($request['officer_uuid']); ?></p>
                        <a href="../officers/view.php?id=<?php echo $request['officer_id']; ?>" class="text-sm text-green-700 underline mt-2 inline-block">
                            View Officer Profile →
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar -->
        <div class="space-y-6">
            <!-- Status Card -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                <h3 class="text-sm font-medium text-gray-500 mb-3">Current Status</h3>
                <?php
                $statusColors = [
                    'pending' => 'yellow',
                    'requested_to_seminar' => 'blue',
                    'in_seminar' => 'indigo',
                    'seminar_completed' => 'green',
                    'requested_to_oath' => 'purple',
                    'ready_to_oath' => 'pink',
                    'oath_taken' => 'green',
                    'rejected' => 'red',
                    'cancelled' => 'gray'
                ];
                $color = $statusColors[$request['status']] ?? 'gray';
                ?>
                <div class="inline-flex items-center px-3 py-2 bg-<?php echo $color; ?>-100 text-<?php echo $color; ?>-800 rounded-lg font-medium">
                    <?php echo ucwords(str_replace('_', ' ', $request['status'])); ?>
                </div>
                
                <!-- Record Code Badge (if imported from LORCAPP) -->
                <?php if (!empty($request['is_imported'])): ?>
                <div class="mt-3 space-y-2">
                    <div class="inline-flex items-center px-3 py-1.5 bg-purple-100 text-purple-800 rounded-lg text-xs font-semibold">
                        <svg class="w-4 h-4 mr-1.5" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M3 12v3c0 1.657 3.134 3 7 3s7-1.343 7-3v-3c0 1.657-3.134 3-7 3s-7-1.343-7-3z"/>
                            <path d="M3 7v3c0 1.657 3.134 3 7 3s7-1.343 7-3V7c0 1.657-3.134 3-7 3S3 8.657 3 7z"/>
                            <path d="M17 5c0 1.657-3.134 3-7 3S3 6.657 3 5s3.134-3 7-3 7 1.343 7 3z"/>
                        </svg>
                        LORCAPP
                    </div>
                    <?php if (!empty($request['record_code'])): ?>
                    <div class="inline-flex items-center px-3 py-1.5 <?php echo $request['record_code'] === 'A' ? 'bg-green-100 text-green-800' : 'bg-blue-100 text-blue-800'; ?> rounded-lg text-xs font-semibold ml-2">
                        CODE <?php echo Security::escape($request['record_code']); ?>
                        <?php if ($request['record_code'] === 'A'): ?>
                        (New Officer)
                        <?php else: ?>
                        (Existing Officer)
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="inline-flex items-center px-3 py-1.5 bg-yellow-100 text-yellow-800 rounded-lg text-xs font-semibold ml-2">
                        ⚠️ Code Not Set
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <div class="mt-4 space-y-2 text-sm">
                    <div>
                        <span class="text-gray-500">Requested:</span>
                        <span class="text-gray-900"><?php echo date('M j, Y', strtotime($request['requested_at'])); ?></span>
                    </div>
                    <div>
                        <span class="text-gray-500">By:</span>
                        <span class="text-gray-900"><?php echo Security::escape($request['requested_by_name']); ?></span>
                    </div>
                </div>
            </div>

            <!-- Palasumpaan Generator (Ready to Oath and Completed Oaths) -->
            <?php if (in_array($request['status'], ['ready_to_oath', 'oath_taken'])): ?>
            <div class="bg-green-50 border border-green-200 rounded-lg p-6">
                <h3 class="text-sm font-semibold text-green-900 mb-4">Certificate</h3>
                
                <?php if ($request['status'] === 'oath_taken'): ?>
                <!-- Preview button for completed oaths -->
                <a href="../generate-palasumpaan.php?request_id=<?= $requestId ?>&preview=1"
                   target="_blank"
                   class="w-full inline-flex items-center justify-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors text-sm font-medium mb-2">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                    </svg>
                    Preview Palasumpaan
                </a>
                <?php endif; ?>
                
                <button onclick="document.dispatchEvent(new CustomEvent('open-modal', { detail: 'palasumpaanModal' }))"
                   class="w-full inline-flex items-center justify-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors text-sm font-medium">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    Generate Palasumpaan
                </button>
                
                <p class="text-xs text-green-700 mt-2">
                    <?php if ($request['status'] === 'oath_taken'): ?>
                    Preview or generate the officer's oath certificate
                    <?php else: ?>
                    Generate and print the officer's oath certificate
                    <?php endif; ?>
                </p>
            </div>
            <?php endif; ?>

            <!-- R-201 Certificate (if linked to LORCAPP) -->
            <?php if (!empty($request['lorcapp_id'])): ?>
            <div class="bg-purple-50 border border-purple-200 rounded-lg p-6">
                <h3 class="text-sm font-semibold text-purple-900 mb-4">R-201 Certificate</h3>
                
                <div class="mb-3 p-3 bg-white rounded-lg border border-purple-100">
                    <p class="text-xs text-gray-600 mb-1">LORCAPP Record ID</p>
                    <p class="font-mono font-semibold text-purple-900"><?= htmlspecialchars($request['lorcapp_id']) ?></p>
                </div>
                
                <a href="../lorcapp/view.php?id=<?= htmlspecialchars($request['lorcapp_id']) ?>"
                   target="_blank"
                   class="w-full inline-flex items-center justify-center px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors text-sm font-medium mb-2">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                    </svg>
                    View R-201 Record
                </a>
                
                <a href="../lorcapp/print_r201.php?id=<?= htmlspecialchars($request['lorcapp_id']) ?>"
                   target="_blank"
                   class="w-full inline-flex items-center justify-center px-4 py-2 border border-purple-300 text-purple-700 rounded-lg hover:bg-purple-50 transition-colors text-sm font-medium">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>
                    </svg>
                    Print R-201 Certificate
                </a>
                
                <p class="text-xs text-purple-700 mt-2">
                    This officer is linked to LORCAPP R-201 record system
                </p>
            </div>
            <?php elseif ($canManage): ?>
            <div class="bg-gray-50 border border-gray-200 rounded-lg p-6">
                <h3 class="text-sm font-semibold text-gray-900 mb-4">R-201 Certificate</h3>
                
                <p class="text-sm text-gray-600 mb-3">
                    This officer is not yet linked to a LORCAPP R-201 record.
                </p>
                
                <a href="link-to-lorcapp.php"
                   class="w-full inline-flex items-center justify-center px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-100 transition-colors text-sm font-medium">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path>
                    </svg>
                    Link to LORCAPP Record
                </a>
            </div>
            <?php endif; ?>

            <!-- Workflow Actions -->
            <?php if ($canProgress && $request['status'] !== 'oath_taken'): ?>
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-6">
                <h3 class="text-sm font-semibold text-blue-900 mb-4">Next Action</h3>
                
                <div id="workflowActions">
                    <?php include 'workflow-actions.php'; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Reject Option -->
            <?php if ($canManage && !in_array($request['status'], ['oath_taken', 'rejected', 'cancelled'])): ?>
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                <h3 class="text-sm font-semibold text-gray-900 mb-4">Admin Actions</h3>
                
                <div class="space-y-2">
                    <button onclick="document.dispatchEvent(new CustomEvent('open-modal', { detail: 'rejectModal' }))" 
                            class="w-full inline-flex items-center justify-center px-4 py-2 bg-red-50 text-red-700 rounded-lg hover:bg-red-100 transition-colors text-sm font-medium">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                        Reject Request
                    </button>
                    
                    <button onclick="document.dispatchEvent(new CustomEvent('open-modal', { detail: 'deleteModal' }))" 
                            class="w-full inline-flex items-center justify-center px-4 py-2 bg-gray-50 text-gray-700 rounded-lg hover:bg-gray-100 transition-colors text-sm font-medium border border-gray-300">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                        </svg>
                        Delete Request
                    </button>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Timeline -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                <h3 class="text-sm font-semibold text-gray-900 mb-4">Timeline</h3>
                <div class="space-y-4">
                    <div class="flex">
                        <div class="flex-shrink-0 w-2 h-2 bg-blue-600 rounded-full mt-1.5 mr-3"></div>
                        <div>
                            <p class="text-sm font-medium text-gray-900">Request Submitted</p>
                            <p class="text-xs text-gray-500"><?php echo date('M j, Y g:i A', strtotime($request['requested_at'])); ?></p>
                        </div>
                    </div>
                    
                    <?php if ($request['seminar_approved_at']): ?>
                    <div class="flex">
                        <div class="flex-shrink-0 w-2 h-2 bg-blue-600 rounded-full mt-1.5 mr-3"></div>
                        <div>
                            <p class="text-sm font-medium text-gray-900">Approved for Seminar</p>
                            <p class="text-xs text-gray-500"><?php echo date('M j, Y g:i A', strtotime($request['seminar_approved_at'])); ?></p>
                            <p class="text-xs text-gray-500">By: <?php echo Security::escape($request['seminar_approved_by_name']); ?></p>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($request['oath_approved_at']): ?>
                    <div class="flex">
                        <div class="flex-shrink-0 w-2 h-2 bg-purple-600 rounded-full mt-1.5 mr-3"></div>
                        <div>
                            <p class="text-sm font-medium text-gray-900">Approved for Oath</p>
                            <p class="text-xs text-gray-500"><?php echo date('M j, Y g:i A', strtotime($request['oath_approved_at'])); ?></p>
                            <p class="text-xs text-gray-500">By: <?php echo Security::escape($request['oath_approved_by_name']); ?></p>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($request['completed_at']): ?>
                    <div class="flex">
                        <div class="flex-shrink-0 w-2 h-2 bg-green-600 rounded-full mt-1.5 mr-3"></div>
                        <div>
                            <p class="text-sm font-medium text-gray-900">Oath Completed</p>
                            <p class="text-xs text-gray-500"><?php echo date('M j, Y g:i A', strtotime($request['completed_at'])); ?></p>
                            <p class="text-xs text-gray-500">By: <?php echo Security::escape($request['completed_by_name']); ?></p>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<?php if ($canManage && !in_array($request['status'], ['oath_taken', 'rejected', 'cancelled'])): ?>
<div x-data="{ show: false }" 
     @open-modal.window="show = ($event.detail === 'rejectModal')"
     @keydown.escape.window="show = false"
     x-show="show"
     class="fixed inset-0 z-50 overflow-y-auto"
     style="display: none;">
    <div class="flex items-center justify-center min-h-screen px-4">
        <div @click="show = false" class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity"></div>
        <div @click.stop class="relative bg-white rounded-lg shadow-xl w-full max-w-md p-6 transform transition-all"
             x-show="show"
             x-transition>
            
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-bold text-gray-900">Reject Request</h3>
                <button @click="show = false" type="button" class="text-gray-400 hover:text-gray-500">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            
            <form method="POST" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="reject">
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Reason for Rejection *</label>
                    <textarea 
                        name="rejection_reason" 
                        rows="4" 
                        required
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-red-500 resize-none"
                        placeholder="Explain why this request is being rejected..."
                    ></textarea>
                </div>
                
                <div class="flex items-center justify-end space-x-3 pt-4">
                    <button type="button" @click="show = false" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 bg-white hover:bg-gray-50 transition-colors">
                        Cancel
                    </button>
                    <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">
                        Reject Request
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Palasumpaan Generator Modal -->
<?php if (in_array($request['status'], ['ready_to_oath', 'oath_taken'])): ?>
<div x-data="{ show: false, oathDate: '<?= $request['oath_actual_date'] ?>', oathLokal: '', oathDistrito: '' }" 
     @open-modal.document="if ($event.detail === 'palasumpaanModal') show = true"
     x-show="show"
     x-cloak
     class="fixed inset-0 z-50 overflow-y-auto"
     style="display: none;">
    <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
        <div x-show="show" 
             x-transition:enter="ease-out duration-300"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="ease-in duration-200"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             class="fixed inset-0 transition-opacity bg-gray-500 bg-opacity-75"
             @click="show = false"></div>

        <div x-show="show"
             x-transition:enter="ease-out duration-300"
             x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
             x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
             x-transition:leave="ease-in duration-200"
             x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
             x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
             class="inline-block w-full max-w-lg p-6 my-8 overflow-hidden text-left align-middle transition-all transform bg-white shadow-xl rounded-2xl">
            
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-bold text-gray-900">Generate Palasumpaan</h3>
                <button @click="show = false" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            
            <form method="GET" action="../generate-palasumpaan.php" target="_blank" @submit="setTimeout(() => show = false, 100)">
                <input type="hidden" name="request_id" value="<?= $requestId ?>">
                
                <div class="space-y-4 mb-6">
                    <!-- Oath Date -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Oath Date <span class="text-red-500">*</span>
                        </label>
                        <input type="date" 
                               name="oath_date" 
                               x-model="oathDate"
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
                               name="oath_lokal" 
                               x-model="oathLokal"
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
                               name="oath_distrito" 
                               x-model="oathDistrito"
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
                    <button type="button" @click="show = false" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 bg-white hover:bg-gray-50 transition-colors">
                        Cancel
                    </button>
                    <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors">
                        Generate Certificate
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Delete Request Modal -->
<?php if ($canManage && !in_array($request['status'], ['oath_taken'])): ?>
<div x-data="{ show: false }" 
     @open-modal.document="if ($event.detail === 'deleteModal') show = true"
     x-show="show"
     x-cloak
     class="fixed inset-0 z-50 overflow-y-auto"
     style="display: none;">
    <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
        <div x-show="show" 
             x-transition:enter="ease-out duration-300"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="ease-in duration-200"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             class="fixed inset-0 transition-opacity bg-gray-500 bg-opacity-75"
             @click="show = false"></div>

        <div x-show="show"
             x-transition:enter="ease-out duration-300"
             x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
             x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
             x-transition:leave="ease-in duration-200"
             x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
             x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
             class="inline-block w-full max-w-lg p-6 my-8 overflow-hidden text-left align-middle transition-all transform bg-white shadow-xl rounded-2xl">
            
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-bold text-gray-900">Delete Request</h3>
                <button @click="show = false" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="delete">
                
                <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4">
                    <div class="flex items-start">
                        <svg class="w-6 h-6 text-red-600 mr-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                        </svg>
                        <div class="flex-1">
                            <p class="text-sm font-semibold text-red-800">Warning: This action cannot be undone</p>
                            <p class="text-xs text-red-700 mt-1">Deleting this request will permanently remove it from the system. All associated data will be lost.</p>
                        </div>
                    </div>
                </div>
                
                <div class="mb-4">
                    <p class="text-sm text-gray-700">Are you sure you want to delete this officer request for <strong><?php echo Security::escape($fullName); ?></strong>?</p>
                </div>
                
                <div class="flex items-center justify-end space-x-3 pt-4">
                    <button type="button" @click="show = false" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 bg-white hover:bg-gray-50 transition-colors">
                        Cancel
                    </button>
                    <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">
                        Delete Request
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
