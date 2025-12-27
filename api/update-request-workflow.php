<?php
require_once __DIR__ . '/../config/config.php';

Security::requireLogin();

$db = Database::getInstance()->getConnection();
$user = getCurrentUser();

// Check permissions
$canManage = in_array($user['role'], ['admin', 'district', 'local']);
if (!$canManage) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

header('Content-Type: application/json');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $requestId = intval($input['request_id'] ?? 0);
    $action = $input['action'] ?? '';
    
    if (!$requestId || !$action) {
        throw new Exception('Request ID and action are required');
    }
    
    // Get request details
    $stmt = $db->prepare("SELECT * FROM officer_requests WHERE request_id = ?");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$request) {
        throw new Exception('Request not found');
    }
    
    // Verify permissions for this specific request
    if ($user['role'] === 'district' && $user['district_code'] !== $request['district_code']) {
        throw new Exception('You can only manage requests in your district');
    }
    if ($user['role'] === 'local' && $user['local_code'] !== $request['local_code']) {
        throw new Exception('You can only manage requests in your local congregation');
    }
    
    $message = '';
    
    switch ($action) {
        case 'set_code':
            $recordCode = $input['record_code'] ?? '';
            
            if (!in_array($recordCode, ['A', 'D'])) {
                throw new Exception('Invalid record code. Must be A or D.');
            }
            
            // If CODE D, also need to link to existing officer
            $existingOfficerUuid = null;
            if ($recordCode === 'D') {
                $existingOfficerUuid = $input['existing_officer_uuid'] ?? '';
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
            
            $message = $recordCode === 'A' 
                ? "Record code set to CODE A. Will create a new officer."
                : "Record code set to CODE D. Officer linked to existing record.";
            break;
        
        case 'approve_seminar':
            $seminarDate = $input['seminar_date'] ?? null;
            $stmt = $db->prepare("
                UPDATE officer_requests 
                SET status = 'requested_to_seminar',
                    seminar_date = ?,
                    approved_seminar_by = ?,
                    seminar_approved_at = NOW()
                WHERE request_id = ?
            ");
            $stmt->execute([$seminarDate, $user['user_id'], $requestId]);
            $message = "Request approved for seminar attendance";
            break;
            
        case 'mark_in_seminar':
            $stmt = $db->prepare("
                UPDATE officer_requests 
                SET status = 'in_seminar'
                WHERE request_id = ?
            ");
            $stmt->execute([$requestId]);
            $message = "Marked as currently in seminar";
            break;
            
        case 'complete_seminar':
            $completionDate = $input['completion_date'] ?? null;
            $certificateNumber = $input['certificate_number'] ?? '';
            $stmt = $db->prepare("
                UPDATE officer_requests 
                SET status = 'seminar_completed',
                    seminar_completion_date = ?,
                    seminar_certificate_number = ?
                WHERE request_id = ?
            ");
            $stmt->execute([$completionDate, $certificateNumber, $requestId]);
            $message = "Seminar marked as completed";
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
            $message = "Approved for oath taking ceremony";
            break;
            
        case 'mark_ready_oath':
            $stmt = $db->prepare("
                UPDATE officer_requests 
                SET status = 'ready_to_oath'
                WHERE request_id = ?
            ");
            $stmt->execute([$requestId]);
            $message = "Marked as ready for oath";
            break;
            
        case 'complete_oath':
            $actualOathDate = $input['actual_oath_date'] ?? null;
            
            // Validate record code is set
            if (empty($request['record_code'])) {
                throw new Exception('Record code must be set before completing oath. Please set CODE A or CODE D first.');
            }
            
            // Check if CODE D (existing officer) or CODE A (new officer)
            if ($request['record_code'] === 'D' && !empty($request['existing_officer_uuid'])) {
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
                
                $message = "Oath completed! Existing officer updated with new department (CODE D).";
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
                
                $message = "Oath completed! Officer record created successfully (CODE A).";
            }
            break;
            
        case 'reject':
            $reason = $input['reason'] ?? '';
            $stmt = $db->prepare("
                UPDATE officer_requests 
                SET status = 'rejected',
                    status_reason = ?,
                    reviewed_by = ?,
                    reviewed_at = NOW()
                WHERE request_id = ?
            ");
            $stmt->execute([$reason, $user['user_id'], $requestId]);
            $message = "Request has been rejected";
            break;
            
        case 'add_seminar_date':
            $seminarDate = $input['date'] ?? '';
            $location = $input['location'] ?? '';
            $daysRequired = intval($input['days_required'] ?? 3);
            
            if (empty($seminarDate)) {
                throw new Exception('Seminar date is required');
            }
            
            // Get current seminar dates
            $seminarDates = !empty($request['seminar_dates']) ? json_decode($request['seminar_dates'], true) : [];
            if (!is_array($seminarDates)) {
                $seminarDates = [];
            }
            
            // Add new date
            $seminarDates[] = [
                'date' => $seminarDate,
                'location' => $location,
                'attended' => false,
                'added_at' => date('Y-m-d H:i:s'),
                'added_by' => $user['user_id']
            ];
            
            // Update database
            $stmt = $db->prepare("
                UPDATE officer_requests 
                SET seminar_dates = ?,
                    seminar_days_required = ?
                WHERE request_id = ?
            ");
            $stmt->execute([json_encode($seminarDates), $daysRequired, $requestId]);
            $message = "Seminar date added successfully";
            break;
            
        case 'mark_attendance':
            $seminarIndex = intval($input['seminar_index'] ?? -1);
            $attended = (bool)($input['attended'] ?? false);
            
            // Get current seminar dates
            $seminarDates = !empty($request['seminar_dates']) ? json_decode($request['seminar_dates'], true) : [];
            
            if (!is_array($seminarDates) || !isset($seminarDates[$seminarIndex])) {
                throw new Exception('Invalid seminar date index');
            }
            
            // Update attendance
            $seminarDates[$seminarIndex]['attended'] = $attended;
            $seminarDates[$seminarIndex]['attendance_marked_at'] = date('Y-m-d H:i:s');
            $seminarDates[$seminarIndex]['marked_by'] = $user['user_id'];
            
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
            $stmt->execute([json_encode($seminarDates), $attendedCount, $requestId]);
            
            $message = $attended ? "Attendance marked" : "Attendance unmarked";
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
    // Audit log
    $stmt = $db->prepare("
        INSERT INTO audit_log (user_id, action, table_name, record_id, new_values, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $user['user_id'],
        "workflow_action_$action",
        'officer_requests',
        $requestId,
        json_encode(['action' => $action, 'input' => $input]),
        $_SERVER['REMOTE_ADDR'] ?? '',
        $_SERVER['HTTP_USER_AGENT'] ?? ''
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => $message
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
