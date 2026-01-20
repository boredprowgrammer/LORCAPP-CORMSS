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
           existing_o.middle_initial_encrypted as existing_middle_initial,
           existing_o.district_code as existing_district_code
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
try {
    if ($request['record_code'] === 'D' && $request['existing_officer_uuid']) {
        // Use existing officer's district code for decryption
        $districtCodeForDecryption = $request['existing_district_code'] ?? $request['district_code'];
        
        // Check if we have the encrypted data
        if (empty($request['existing_last_name']) || empty($request['existing_first_name'])) {
            throw new Exception("Missing encrypted name data for existing officer. UUID: {$request['existing_officer_uuid']}");
        }
        
        $lastName = Encryption::decrypt($request['existing_last_name'], $districtCodeForDecryption);
        $firstName = Encryption::decrypt($request['existing_first_name'], $districtCodeForDecryption);
        $middleInitial = $request['existing_middle_initial'] ? Encryption::decrypt($request['existing_middle_initial'], $districtCodeForDecryption) : '';
    } else {
        $lastName = Encryption::decrypt($request['last_name_encrypted'], $request['district_code']);
        $firstName = Encryption::decrypt($request['first_name_encrypted'], $request['district_code']);
        $middleInitial = $request['middle_initial_encrypted'] ? Encryption::decrypt($request['middle_initial_encrypted'], $request['district_code']) : '';
    }
    $fullName = "$lastName, $firstName" . ($middleInitial ? " $middleInitial." : "");
} catch (Exception $e) {
    error_log("Request decryption error for request_id {$request['request_id']}: " . $e->getMessage());
    error_log("Request data: record_code={$request['record_code']}, existing_uuid={$request['existing_officer_uuid']}, existing_district={$request['existing_district_code']}, request_district={$request['district_code']}");
    $fullName = '[DECRYPT ERROR]';
    $lastName = '';
    $firstName = '';
    $middleInitial = '';
}

// Handle workflow actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    Security::validateCSRFToken($_POST['csrf_token'] ?? '');
    
    $action = $_POST['action'] ?? '';
    
    try {
        // Handle seminar progress update
        if ($action === 'update_seminar_progress') {
            // Recalculate seminar days completed from seminar_dates JSON
            $seminarDates = !empty($request['seminar_dates']) ? json_decode($request['seminar_dates'], true) : [];
            $attendedCount = 0;
            
            if (is_array($seminarDates)) {
                foreach ($seminarDates as $seminar) {
                    if (!empty($seminar['attended'])) {
                        $attendedCount++;
                    }
                }
            }
            
            // Update the count in database
            $stmt = $db->prepare("
                UPDATE officer_requests 
                SET seminar_days_completed = ?
                WHERE request_id = ?
            ");
            $stmt->execute([$attendedCount, $requestId]);
            
            $success = "Seminar progress updated: $attendedCount days completed.";
            
            // Refresh request data
            $stmt = $db->prepare("SELECT * FROM officer_requests WHERE request_id = ?");
            $stmt->execute([$requestId]);
            $request = array_merge($request, $stmt->fetch(PDO::FETCH_ASSOC));
        }
        
        // Handle mark attendance
        if ($action === 'mark_attendance') {
            $seminarIndex = intval($_POST['seminar_index'] ?? -1);
            $attended = ($_POST['attended'] ?? '0') === '1';
            
            // Get current seminar dates
            $seminarDates = !empty($request['seminar_dates']) ? json_decode($request['seminar_dates'], true) : [];
            
            if (is_array($seminarDates) && isset($seminarDates[$seminarIndex])) {
                // Update attendance status
                $seminarDates[$seminarIndex]['attended'] = $attended;
                $seminarDates[$seminarIndex]['attendance_marked_at'] = date('Y-m-d H:i:s');
                
                // Count attended dates
                $attendedCount = 0;
                foreach ($seminarDates as $seminar) {
                    if (!empty($seminar['attended'])) {
                        $attendedCount++;
                    }
                }
                
                // Update database
                $stmt = $db->prepare("
                    UPDATE officer_requests 
                    SET seminar_dates = ?,
                        seminar_days_completed = ?
                    WHERE request_id = ?
                ");
                $stmt->execute([
                    json_encode($seminarDates),
                    $attendedCount,
                    $requestId
                ]);
                
                $daysRequired = $request['seminar_days_required'] ?? 0;
                
                // Return JSON for AJAX requests
                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => true,
                        'message' => $attended ? "Attendance marked" : "Attendance unmarked",
                        'days_completed' => $attendedCount,
                        'days_required' => $daysRequired
                    ]);
                    exit;
                }
                
                $success = $attended ? "Attendance marked for day " . ($seminarIndex + 1) : "Attendance unmarked for day " . ($seminarIndex + 1);
                
                // Refresh request data
                $stmt = $db->prepare("SELECT * FROM officer_requests WHERE request_id = ?");
                $stmt->execute([$requestId]);
                $request = array_merge($request, $stmt->fetch(PDO::FETCH_ASSOC));
            } else {
                // Return JSON error for AJAX requests
                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => false,
                        'error' => 'Invalid seminar date index'
                    ]);
                    exit;
                }
                $error = "Invalid seminar date index.";
            }
        }
        
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
                           existing_o.middle_initial_encrypted as existing_middle_initial,
                           existing_o.district_code as existing_district_code
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
                try {
                    if ($request['record_code'] === 'D' && $request['existing_officer_uuid']) {
                        // Use existing officer's district code for decryption
                        $districtCodeForDecryption = $request['existing_district_code'] ?? $request['district_code'];
                        $lastName = Encryption::decrypt($request['existing_last_name'], $districtCodeForDecryption);
                        $firstName = Encryption::decrypt($request['existing_first_name'], $districtCodeForDecryption);
                        $middleInitial = $request['existing_middle_initial'] ? Encryption::decrypt($request['existing_middle_initial'], $districtCodeForDecryption) : '';
                    } else {
                        $lastName = Encryption::decrypt($request['last_name_encrypted'], $request['district_code']);
                        $firstName = Encryption::decrypt($request['first_name_encrypted'], $request['district_code']);
                        $middleInitial = $request['middle_initial_encrypted'] ? Encryption::decrypt($request['middle_initial_encrypted'], $request['district_code']) : '';
                    }
                    $fullName = "$lastName, $firstName" . ($middleInitial ? " $middleInitial." : "");
                } catch (Exception $e) {
                    error_log("Request re-decryption error for request_id {$request['request_id']}: " . $e->getMessage());
                    $fullName = '[DECRYPT ERROR]';
                    $lastName = '';
                    $firstName = '';
                    $middleInitial = '';
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
        <a href="list.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg text-gray-700 bg-white dark:bg-gray-800 hover:bg-gray-50 transition-colors">
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
                            <div class="bg-white dark:bg-gray-800 border border-yellow-300 rounded-lg max-h-60 overflow-y-auto"></div>
                        </div>
                        
                        <div id="selectedOfficer" class="hidden mb-3">
                            <div class="bg-white dark:bg-gray-800 border-2 border-blue-500 rounded-lg p-3">
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
                 onclick="selectOfficer('${officer.uuid}', '${escapeHtml(officer.name)}', '${escapeHtml(officer.location)}')">
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
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 p-6">
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
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 p-6">
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
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 p-6">
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

            <?php 
            // Initialize seminar tracking variables globally
            $seminarDates = !empty($request['seminar_dates']) ? json_decode($request['seminar_dates'], true) : [];
            $daysRequired = $request['seminar_days_required'] ?? 0;
            
            // Calculate days completed based on attended status
            $daysCompleted = 0;
            if (!empty($seminarDates)) {
                foreach ($seminarDates as $seminar) {
                    if (!empty($seminar['attended'])) {
                        $daysCompleted++;
                    }
                }
            }
            ?>

            <!-- Seminar Progress Tracker (8 Lessons or 33 Lessons) -->
            <?php if (!empty($request['request_class']) && in_array($request['status'], ['requested_to_seminar', 'in_seminar', 'seminar_completed', 'requested_to_oath', 'ready_to_oath', 'oath_taken'])): ?>
            <?php 
            // Auto-initialize if empty
            $needsInit = empty($seminarDates) && $daysRequired > 0;
            
            $progressPercent = $daysRequired > 0 ? ($daysCompleted / $daysRequired) * 100 : 0;
            $isComplete = $daysCompleted >= $daysRequired && $daysRequired > 0;
            ?>
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 p-6" x-data="{ needsInit: <?= $needsInit ? 'true' : 'false' ?> }">
                <?php if ($needsInit): ?>
                <!-- Auto-initialization prompt -->
                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-4">
                    <div class="flex items-start">
                        <svg class="w-5 h-5 text-yellow-600 mr-2 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                        </svg>
                        <div class="flex-1">
                            <p class="text-sm font-semibold text-yellow-900">Seminar dates not initialized</p>
                            <p class="text-xs text-yellow-800 mt-1">
                                Click below to auto-generate <?= $daysRequired ?> seminar dates (daily consecutive schedule)
                            </p>
                            <button @click="initializeSeminar()" :disabled="!needsInit"
                                class="mt-2 px-3 py-1.5 bg-yellow-600 text-white text-xs rounded-lg hover:bg-yellow-700 transition-colors disabled:opacity-50">
                                Initialize Daily Seminar Schedule
                            </button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-900 flex items-center">
                        <svg class="w-5 h-5 text-purple-600 mr-2" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M9 2a1 1 0 000 2h2a1 1 0 100-2H9z"></path>
                            <path fill-rule="evenodd" d="M4 5a2 2 0 012-2 3 3 0 003 3h2a3 3 0 003-3 2 2 0 012 2v11a2 2 0 01-2 2H6a2 2 0 01-2-2V5zm3 4a1 1 0 000 2h.01a1 1 0 100-2H7zm3 0a1 1 0 000 2h3a1 1 0 100-2h-3zm-3 4a1 1 0 100 2h.01a1 1 0 100-2H7zm3 0a1 1 0 100 2h3a1 1 0 100-2h-3z" clip-rule="evenodd"></path>
                        </svg>
                        Seminar Progress — <?= Security::escape($request['request_class']) ?>
                    </h3>
                    <?php if ($canManage && !$isComplete && !$needsInit): ?>
                    <button onclick="document.dispatchEvent(new CustomEvent('open-modal', { detail: 'addSeminarDateModal' }))"
                        class="inline-flex items-center px-3 py-1.5 bg-purple-600 text-white text-sm rounded-lg hover:bg-purple-700 transition-colors">
                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                        </svg>
                        Add Extra Date
                    </button>
                    <?php endif; ?>
                </div>
                
                <!-- Progress Bar -->
                <div class="mb-4">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-sm font-medium text-gray-700">
                            <?= $daysCompleted ?> of <?= $daysRequired ?> days completed
                        </span>
                        <span class="text-sm font-bold <?= $isComplete ? 'text-green-600' : 'text-purple-600' ?>">
                            <?= number_format($progressPercent, 0) ?>%
                        </span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-3 overflow-hidden">
                        <div class="<?= $isComplete ? 'bg-green-600' : 'bg-purple-600' ?> h-3 rounded-full transition-all duration-500" 
                             style="width: <?= min($progressPercent, 100) ?>%"></div>
                    </div>
                    <?php if ($isComplete): ?>
                    <div class="mt-2 flex items-center text-sm text-green-700 font-medium">
                        <svg class="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                        </svg>
                        All required seminar days completed!
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Seminar Dates List -->
                <?php if (!empty($seminarDates)): ?>
                <div class="space-y-2">
                    <h4 class="text-sm font-semibold text-gray-700 mb-2">Seminar Schedule:</h4>
                    <?php foreach ($seminarDates as $index => $seminar): ?>
                    <?php 
                    $isAttended = !empty($seminar['attended']);
                    $isPast = strtotime($seminar['date']) < strtotime('today');
                    $isToday = date('Y-m-d', strtotime($seminar['date'])) === date('Y-m-d');
                    ?>
                    <div data-seminar-index="<?= $index ?>" class="flex items-start p-3 <?= $isAttended ? 'bg-green-50 border-green-200' : 'bg-gray-50 border-gray-200' ?> rounded-lg border">
                        <div class="flex-shrink-0 w-8 h-8 <?= $isAttended ? 'bg-green-600' : 'bg-purple-600' ?> text-white rounded-full flex items-center justify-center text-sm font-bold mr-3 badge-container">
                            <?php if ($isAttended): ?>
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                            </svg>
                            <?php else: ?>
                            <?= $index + 1 ?>
                            <?php endif; ?>
                        </div>
                        <div class="flex-grow">
                            <div class="flex items-center justify-between">
                                <div>
                                    <span class="font-medium <?= $isAttended ? 'text-green-900' : 'text-gray-900' ?>">
                                        <?= date('F j, Y', strtotime($seminar['date'])) ?>
                                    </span>
                                    <?php if ($isToday): ?>
                                    <span class="ml-2 px-2 py-0.5 bg-blue-100 text-blue-800 text-xs font-semibold rounded">TODAY</span>
                                    <?php endif; ?>
                                    <?php if ($isAttended): ?>
                                    <span class="ml-2 px-2 py-0.5 bg-green-100 text-green-800 text-xs font-semibold rounded">ATTENDED</span>
                                    <?php elseif ($isPast): ?>
                                    <span class="ml-2 px-2 py-0.5 bg-red-100 text-red-800 text-xs font-semibold rounded">MISSED</span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($canManage): ?>
                                <div class="flex items-center space-x-2">
                                    <?php if (!$isAttended): ?>
                                    <button onclick="markAttendance(<?= $index ?>, true)" 
                                        class="text-green-600 hover:text-green-800 text-sm px-2 py-1 rounded hover:bg-green-100">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                        </svg>
                                    </button>
                                    <?php else: ?>
                                    <button onclick="markAttendance(<?= $index ?>, false)" 
                                        class="text-yellow-600 hover:text-yellow-800 text-sm px-2 py-1 rounded hover:bg-yellow-100">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                        </svg>
                                    </button>
                                    <?php endif; ?>
                                    <button onclick="editSeminarDate(<?= $index ?>, '<?= htmlspecialchars($seminar['date']) ?>', '<?= htmlspecialchars($seminar['topic'] ?? '') ?>', '<?= htmlspecialchars($seminar['notes'] ?? '') ?>')" 
                                        class="text-blue-600 hover:text-blue-800 text-sm">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                        </svg>
                                    </button>
                                    <button onclick="deleteSeminarDate(<?= $index ?>)" 
                                        class="text-red-600 hover:text-red-800 text-sm">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                        </svg>
                                    </button>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($seminar['topic'])): ?>
                            <p class="text-sm text-gray-600 mt-1">Topic: <?= Security::escape($seminar['topic']) ?></p>
                            <?php endif; ?>
                            <?php if (!empty($seminar['notes'])): ?>
                            <p class="text-xs text-gray-500 mt-1"><?= Security::escape($seminar['notes']) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php elseif (!$needsInit): ?>
                <div class="text-center py-6 text-gray-500">
                    <svg class="w-12 h-12 mx-auto text-gray-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                    </svg>
                    <p class="text-sm">No seminar dates recorded yet</p>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Oath Details -->
            <?php if (in_array($request['status'], ['requested_to_oath', 'ready_to_oath', 'oath_taken'])): ?>
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 p-6">
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
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 p-6">
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
                        <span class="text-gray-900"><?php echo date('m/d/Y', strtotime($request['requested_at'])); ?></span>
                    </div>
                    <div>
                        <span class="text-gray-500">By:</span>
                        <span class="text-gray-900"><?php echo Security::escape($request['requested_by_name']); ?></span>
                    </div>
                </div>
            </div>

            <!-- R5-13 Certificate Generator (When seminar is completed) -->
            <?php if (!empty($request['request_class']) && $daysCompleted >= $daysRequired && $daysRequired > 0): ?>
            <div class="bg-purple-50 border border-purple-200 rounded-lg p-6">
                <h3 class="text-sm font-semibold text-purple-900 mb-4">
                    R5-13 Certificate (Form 513)
                </h3>
                
                <?php if (!empty($request['r513_generated_at'])): ?>
                <!-- Certificate already generated - show preview -->
                <div class="mb-3 p-3 bg-white dark:bg-gray-800 rounded-lg border border-purple-100">
                    <div class="flex items-center text-sm text-green-700 mb-2">
                        <svg class="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                        </svg>
                        Certificate Generated
                    </div>
                    <p class="text-xs text-gray-600">
                        Generated: <?= date('F j, Y g:i A', strtotime($request['r513_generated_at'])) ?>
                    </p>
                </div>
                
                <a href="../generate-r513-html.php?request_id=<?= $requestId ?>&preview=1"
                   target="_blank"
                   class="w-full inline-flex items-center justify-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors text-sm font-medium mb-2">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                    </svg>
                    Preview R5-13 Certificate
                </a>
                
                <button onclick="document.dispatchEvent(new CustomEvent('open-modal', { detail: 'r513Modal' }))"
                   class="w-full inline-flex items-center justify-center px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors text-sm font-medium">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path>
                    </svg>
                    Regenerate Certificate
                </button>
                <?php else: ?>
                <!-- Generate new certificate -->
                <div class="mb-3 p-3 bg-white dark:bg-gray-800 rounded-lg border border-purple-100">
                    <div class="flex items-center text-sm text-purple-700 mb-2">
                        <svg class="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                        </svg>
                        Ready to Generate
                    </div>
                    <p class="text-xs text-gray-600">
                        All <?= $daysRequired ?> seminar days completed
                    </p>
                </div>
                
                <button onclick="document.dispatchEvent(new CustomEvent('open-modal', { detail: 'r513Modal' }))"
                   class="w-full inline-flex items-center justify-center px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors text-sm font-medium">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    Generate R5-13 Certificate
                </button>
                <?php endif; ?>
                
                <p class="text-xs text-purple-700 mt-3">
                    <?= $request['request_class'] === '33_lessons' ? '33 lessons (30-day extended)' : '8 lessons (standard)' ?> seminar certificate
                </p>
            </div>
            <?php endif; ?>

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
                
                <div class="mb-3 p-3 bg-white dark:bg-gray-800 rounded-lg border border-purple-100">
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
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 p-6">
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
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 p-6">
                <h3 class="text-sm font-semibold text-gray-900 mb-4">Timeline</h3>
                <div class="space-y-4">
                    <div class="flex">
                        <div class="flex-shrink-0 w-2 h-2 bg-blue-600 rounded-full mt-1.5 mr-3"></div>
                        <div>
                            <p class="text-sm font-medium text-gray-900">Request Submitted</p>
                            <p class="text-xs text-gray-500"><?php echo date('m/d/Y g:i A', strtotime($request['requested_at'])); ?></p>
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
        <div @click.stop class="relative bg-white dark:bg-gray-800 rounded-lg shadow-xl w-full max-w-md p-6 transform transition-all"
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
                    <button type="button" @click="show = false" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 bg-white dark:bg-gray-800 hover:bg-gray-50 transition-colors">
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
<div x-data="{ 
        show: false, 
        oathDate: '<?= $request['oath_actual_date'] ?>', 
        oathLokal: '', 
        oathDistrito: '',
        generating: false,
        generatePalasumpaan(event) {
            this.generating = true;
            const form = event.target;
            const formData = new FormData(form);
            const queryString = new URLSearchParams(formData).toString();
            const url = form.action + '?' + queryString;
            
            // Prevent global loading overlay
            event.stopPropagation();
            
            // Open in new tab
            window.open(url, '_blank');
            
            // Close modal after a short delay
            setTimeout(() => {
                this.generating = false;
                this.show = false;
            }, 1000);
        }
    }" 
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
             class="inline-block w-full max-w-lg p-6 my-8 overflow-hidden text-left align-middle transition-all transform bg-white dark:bg-gray-800 shadow-xl rounded-2xl">
            
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-bold text-gray-900">Generate Palasumpaan</h3>
                <button @click="show = false" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            
            <form method="GET" action="../generate-palasumpaan.php" target="_blank" 
                  data-no-loader
                  @submit.prevent="generatePalasumpaan($event)">
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
                    <button type="button" 
                            @click="show = false" 
                            :disabled="generating"
                            class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 bg-white dark:bg-gray-800 hover:bg-gray-50 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                        Cancel
                    </button>
                    <button type="submit" 
                            :disabled="generating"
                            class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed flex items-center">
                        <svg x-show="generating" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span x-text="generating ? 'Generating...' : 'Generate Certificate'"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- R5-13 Certificate Generator Modal -->
<?php if (!empty($request['request_class']) && $daysCompleted >= $daysRequired && $daysRequired > 0): ?>
<div x-data="{ show: false, generating: false, message: '', error: '' }"
     @open-modal.document="if ($event.detail === 'r513Modal') { show = true; message = ''; error = ''; }"
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
             class="inline-block w-full max-w-lg p-6 my-8 overflow-hidden text-left align-middle transition-all transform bg-white dark:bg-gray-800 shadow-xl rounded-2xl">
            
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-bold text-gray-900">Generate R5-13 Certificate</h3>
                <button @click="show = false" :disabled="generating" class="text-gray-400 hover:text-gray-600 disabled:opacity-50">
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
                                This generates Form 513 (Seminar Certificate) with all <?= $daysCompleted ?> completed seminar dates.
                                The certificate will be saved as an encrypted PDF.
                            </p>
                        </div>
                    </div>
                </div>
                
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-600">Officer:</span>
                        <span class="font-medium text-gray-900"><?= Security::escape($fullName) ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Class:</span>
                        <span class="font-medium text-gray-900"><?= Security::escape($request['request_class']) ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Seminar Days:</span>
                        <span class="font-medium text-green-700"><?= $daysCompleted ?> / <?= $daysRequired ?> completed</span>
                    </div>
                </div>
            </div>
            
            <!-- Success/Error Messages -->
            <div x-show="message" class="mb-4 p-3 bg-green-50 border border-green-200 rounded-lg">
                <p class="text-sm text-green-800" x-text="message"></p>
            </div>
            <div x-show="error" class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg">
                <p class="text-sm text-red-800" x-text="error"></p>
            </div>
            
            <div class="flex items-center justify-end space-x-3 pt-4">
                <button type="button" @click="show = false" :disabled="generating"
                        class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 bg-white dark:bg-gray-800 hover:bg-gray-50 transition-colors disabled:opacity-50">
                    Cancel
                </button>
                <button type="button" @click="generateR513()" :disabled="generating"
                        class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors disabled:opacity-50 flex items-center">
                    <span x-show="!generating">Generate Certificate</span>
                    <span x-show="generating" class="flex items-center">
                        <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        Generating...
                    </span>
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Add Seminar Date Modal -->
<?php if ($canManage && !empty($request['request_class'])): ?>
<div x-data="{ show: false, date: '', topic: '', notes: '', submitting: false, editIndex: null }" 
     @open-modal.document="if ($event.detail === 'addSeminarDateModal') { show = true; date = ''; topic = ''; notes = ''; editIndex = null; }"
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
             class="inline-block w-full max-w-lg p-6 my-8 overflow-hidden text-left align-middle transition-all transform bg-white dark:bg-gray-800 shadow-xl rounded-2xl">
            
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-bold text-gray-900" x-text="editIndex !== null ? 'Edit Seminar Date' : 'Add Seminar Date'"></h3>
                <button @click="show = false" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            
            <form @submit.prevent="addSeminarDate()" method="POST">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Seminar Date <span class="text-red-600">*</span>
                        </label>
                        <input type="date" x-model="date" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Topic / Lesson
                        </label>
                        <input type="text" x-model="topic" placeholder="e.g., Pananampalataya"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Notes (optional)
                        </label>
                        <textarea x-model="notes" rows="3" placeholder="Additional notes..."
                                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent"></textarea>
                    </div>
                </div>
                
                <div class="flex items-center justify-end space-x-3 pt-6">
                    <button type="button" @click="show = false" :disabled="submitting"
                            class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 bg-white dark:bg-gray-800 hover:bg-gray-50 transition-colors disabled:opacity-50">
                        Cancel
                    </button>
                    <button type="submit" :disabled="submitting || !date"
                            class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors disabled:opacity-50 flex items-center">
                        <span x-show="!submitting" x-text="editIndex !== null ? 'Update Date' : 'Add Date'"></span>
                        <span x-show="submitting" class="flex items-center">
                            <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            Saving...
                        </span>
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
             class="inline-block w-full max-w-lg p-6 my-8 overflow-hidden text-left align-middle transition-all transform bg-white dark:bg-gray-800 shadow-xl rounded-2xl">
            
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
                    <button type="button" @click="show = false" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 bg-white dark:bg-gray-800 hover:bg-gray-50 transition-colors">
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

<script>
// Seminar Auto-Initialization
async function initializeSeminar() {
    if (!confirm('This will auto-generate all seminar dates with daily consecutive intervals. Continue?')) {
        return;
    }
    
    try {
        const response = await fetch('auto-init-seminar.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                request_id: <?= $requestId ?>
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert('Daily seminar schedule initialized successfully!');
            window.location.reload();
        } else {
            alert('Error: ' + (result.error || 'Failed to initialize seminar'));
        }
    } catch (error) {
        console.error('Error initializing seminar:', error);
        alert('An error occurred while initializing the seminar schedule');
    }
}

// Mark Attendance - AJAX without page refresh
async function markAttendance(index, attended) {
    try {
        const formData = new FormData();
        formData.append('csrf_token', '<?= Security::generateCSRFToken() ?>');
        formData.append('action', 'mark_attendance');
        formData.append('seminar_index', index);
        formData.append('attended', attended ? '1' : '0');
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const text = await response.text();
        
        // Check if response is JSON (AJAX response) or HTML (full page)
        try {
            const result = JSON.parse(text);
            if (result.success) {
                // Update UI without refresh
                updateSeminarUI(index, attended, result.days_completed, result.days_required);
            } else {
                alert('Error: ' + (result.error || 'Failed to mark attendance'));
            }
        } catch (e) {
            // Response is HTML, reload to see changes
            window.location.reload();
        }
    } catch (error) {
        console.error('Error marking attendance:', error);
        alert('An error occurred while updating attendance');
    }
}

// Update seminar UI without page refresh
function updateSeminarUI(index, attended, daysCompleted, daysRequired) {
    // Update the specific seminar card
    const seminarCards = document.querySelectorAll('[data-seminar-index]');
    if (seminarCards[index]) {
        const card = seminarCards[index];
        
        // Update card styling
        if (attended) {
            card.classList.remove('bg-gray-50', 'border-gray-200');
            card.classList.add('bg-green-50', 'border-green-200');
        } else {
            card.classList.remove('bg-green-50', 'border-green-200');
            card.classList.add('bg-gray-50', 'border-gray-200');
        }
        
        // Update badge
        const badge = card.querySelector('.badge-container');
        if (badge) {
            if (attended) {
                badge.className = 'flex-shrink-0 w-8 h-8 bg-green-600 text-white rounded-full flex items-center justify-center text-sm font-bold mr-3 badge-container';
                badge.innerHTML = '<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path></svg>';
            } else {
                badge.className = 'flex-shrink-0 w-8 h-8 bg-purple-600 text-white rounded-full flex items-center justify-center text-sm font-bold mr-3 badge-container';
                badge.textContent = (index + 1);
            }
        }
        
        // Update status badge
        const statusBadge = card.querySelector('.status-badge');
        if (statusBadge) {
            statusBadge.remove();
        }
        
        const dateSpan = card.querySelector('.font-medium');
        if (dateSpan && attended) {
            const newBadge = document.createElement('span');
            newBadge.className = 'ml-2 px-2 py-0.5 bg-green-100 text-green-800 text-xs font-semibold rounded status-badge';
            newBadge.textContent = 'ATTENDED';
            dateSpan.parentNode.appendChild(newBadge);
        }
        
        // Update buttons
        const buttonContainer = card.querySelector('.flex.items-center.space-x-2');
        if (buttonContainer) {
            if (attended) {
                buttonContainer.innerHTML = `
                    <button onclick="markAttendance(${index}, false)" 
                        class="text-yellow-600 hover:text-yellow-800 text-sm px-2 py-1 rounded hover:bg-yellow-100">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                ` + buttonContainer.querySelector('button[onclick^="editSeminarDate"]')?.outerHTML + 
                    buttonContainer.querySelector('button[onclick^="deleteSeminarDate"]')?.outerHTML;
            } else {
                const editBtn = buttonContainer.querySelector('button[onclick^="editSeminarDate"]')?.outerHTML || '';
                const deleteBtn = buttonContainer.querySelector('button[onclick^="deleteSeminarDate"]')?.outerHTML || '';
                buttonContainer.innerHTML = `
                    <button onclick="markAttendance(${index}, true)" 
                        class="text-green-600 hover:text-green-800 text-sm px-2 py-1 rounded hover:bg-green-100">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                    </button>
                ` + editBtn + deleteBtn;
            }
        }
    }
    
    // Update progress bar
    const progressText = document.querySelector('.text-sm.font-medium.text-gray-700');
    if (progressText) {
        progressText.textContent = `${daysCompleted} of ${daysRequired} days completed`;
    }
    
    const progressPercent = daysRequired > 0 ? (daysCompleted / daysRequired) * 100 : 0;
    const progressBar = document.querySelector('.bg-purple-600.h-3, .bg-green-600.h-3');
    if (progressBar) {
        progressBar.style.width = Math.min(progressPercent, 100) + '%';
        
        if (daysCompleted >= daysRequired) {
            progressBar.classList.remove('bg-purple-600');
            progressBar.classList.add('bg-green-600');
        }
    }
    
    const progressPercentText = document.querySelector('.text-sm.font-bold');
    if (progressPercentText) {
        progressPercentText.textContent = Math.round(progressPercent) + '%';
        
        if (daysCompleted >= daysRequired) {
            progressPercentText.classList.remove('text-purple-600');
            progressPercentText.classList.add('text-green-600');
        }
    }
    
    // Show completion message if all done
    if (daysCompleted >= daysRequired && daysRequired > 0) {
        const completionMsg = document.querySelector('.text-green-700.font-medium');
        if (!completionMsg) {
            const progressBar = document.querySelector('.w-full.bg-gray-200.rounded-full');
            if (progressBar) {
                const msg = document.createElement('div');
                msg.className = 'mt-2 flex items-center text-sm text-green-700 font-medium';
                msg.innerHTML = `
                    <svg class="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                    </svg>
                    All required seminar days completed!
                `;
                progressBar.parentNode.appendChild(msg);
            }
        }
        
        // Reload after a delay to show R5-13 button if it should appear
        setTimeout(() => {
            window.location.reload();
        }, 2000);
    }
}

// Edit Seminar Date
function editSeminarDate(index, date, topic, notes) {
    const modalElement = document.querySelector('[x-data*="editIndex"]');
    const alpineData = Alpine.$data(modalElement);
    
    alpineData.editIndex = index;
    alpineData.date = date;
    alpineData.topic = topic;
    alpineData.notes = notes;
    alpineData.show = true;
}

// Seminar Date Management Functions
async function addSeminarDate() {
    const alpineData = Alpine.$data(document.querySelector('[x-data*="date:"]'));
    
    if (!alpineData.date) {
        alert('Please select a seminar date');
        return;
    }
    
    alpineData.submitting = true;
    
    try {
        const response = await fetch('update-seminar.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                request_id: <?= $requestId ?>,
                action: alpineData.editIndex !== null ? 'edit' : 'add',
                index: alpineData.editIndex,
                date: alpineData.date,
                topic: alpineData.topic || '',
                notes: alpineData.notes || ''
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert(alpineData.editIndex !== null ? 'Seminar date updated successfully!' : 'Seminar date added successfully!');
            window.location.reload();
        } else {
            alert('Error: ' + (result.error || 'Failed to save seminar date'));
        }
    } catch (error) {
        console.error('Error saving seminar date:', error);
        alert('An error occurred while saving the seminar date');
    } finally {
        alpineData.submitting = false;
    }
}

async function deleteSeminarDate(index) {
    if (!confirm('Are you sure you want to remove this seminar date?')) {
        return;
    }
    
    try {
        const response = await fetch('update-seminar.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                request_id: <?= $requestId ?>,
                action: 'delete',
                index: index
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert('Seminar date removed successfully!');
            window.location.reload();
        } else {
            alert('Error: ' + (result.error || 'Failed to remove seminar date'));
        }
    } catch (error) {
        console.error('Error removing seminar date:', error);
        alert('An error occurred while removing the seminar date');
    }
}

// R5-13 Certificate Generation
async function generateR513() {
    const modalElement = document.querySelector('[x-data*="generating"]');
    const alpineData = Alpine.$data(modalElement);
    
    alpineData.generating = true;
    alpineData.error = '';
    alpineData.message = '';
    
    try {
        const response = await fetch('../generate-r513-html.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                request_id: <?= $requestId ?>,
                csrf_token: '<?= Security::generateCSRFToken() ?>'
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            alpineData.message = 'R5-13 Certificate generated successfully!';
            
            setTimeout(() => {
                window.location.reload();
            }, 2000);
        } else {
            alpineData.error = result.error || 'Failed to generate certificate';
        }
    } catch (error) {
        console.error('Error generating R5-13:', error);
        alpineData.error = 'An error occurred while generating the certificate';
    } finally {
        alpineData.generating = false;
    }
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>

