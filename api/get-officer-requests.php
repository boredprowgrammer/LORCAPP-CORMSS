<?php
require_once __DIR__ . '/../config/config.php';

Security::requireLogin();

$db = Database::getInstance()->getConnection();

header('Content-Type: application/json');

// Handle single request fetch by ID
$requestId = intval($_GET['id'] ?? 0);
$officerUuid = Security::sanitizeInput($_GET['officer_uuid'] ?? '');

if ($requestId) {
    // Fetch single request with full details
    try {
        $stmt = $db->prepare("
            SELECT 
                r.*,
                d.district_name,
                l.local_name,
                u1.full_name as requested_by_name,
                u2.full_name as reviewed_by_name,
                u3.full_name as seminar_approved_by_name,
                u4.full_name as oath_approved_by_name,
                u5.full_name as completed_by_name,
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
            LEFT JOIN officers existing_o ON r.existing_officer_uuid = existing_o.officer_uuid
            WHERE r.request_id = ?
        ");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$request) {
            http_response_code(404);
            echo json_encode(['error' => 'Request not found']);
            exit;
        }
        
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
        $request['full_name'] = trim("$lastName, $firstName" . ($middleInitial ? " $middleInitial." : ""));
        
        // Format status label
        $statusLabels = [
            'pending' => 'Pending',
            'requested_to_seminar' => 'Requested to Seminar',
            'in_seminar' => 'In Seminar',
            'seminar_completed' => 'Seminar Completed',
            'requested_to_oath' => 'Requested to Oath',
            'ready_to_oath' => 'Ready to Oath',
            'oath_taken' => 'Oath Taken',
            'rejected' => 'Rejected',
            'cancelled' => 'Cancelled'
        ];
        
        $request['status_label'] = $statusLabels[$request['status']] ?? $request['status'];
        $request['requested_at_formatted'] = date('M j, Y', strtotime($request['requested_at']));
        $request['seminar_date_formatted'] = $request['seminar_date'] ? date('M j, Y', strtotime($request['seminar_date'])) : null;
        $request['oath_scheduled_date_formatted'] = $request['oath_scheduled_date'] ? date('M j, Y', strtotime($request['oath_scheduled_date'])) : null;
        
        // Parse seminar dates JSON
        $request['seminar_dates_array'] = [];
        if (!empty($request['seminar_dates'])) {
            $seminarDates = json_decode($request['seminar_dates'], true);
            if (is_array($seminarDates)) {
                $request['seminar_dates_array'] = $seminarDates;
            }
        }
        
        // Convert boolean fields
        $request['has_r515'] = (bool)$request['has_r515'];
        $request['has_patotoo_katiwala'] = (bool)$request['has_patotoo_katiwala'];
        $request['has_patotoo_kapisanan'] = (bool)$request['has_patotoo_kapisanan'];
        $request['has_salaysay_magulang'] = (bool)$request['has_salaysay_magulang'];
        $request['has_salaysay_pagtanggap'] = (bool)$request['has_salaysay_pagtanggap'];
        $request['has_picture'] = (bool)$request['has_picture'];
        
        echo json_encode($request);
        exit;
        
    } catch (Exception $e) {
        error_log("Get single request API error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Server error']);
        exit;
    }
}

if (empty($officerUuid)) {
    http_response_code(400);
    echo json_encode(['error' => 'Officer UUID is required']);
    exit;
}

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

try {
    // First, get the officer_id from UUID
    $stmt = $db->prepare("SELECT officer_id, district_code, local_code FROM officers WHERE officer_uuid = ?");
    $stmt->execute([$officerUuid]);
    $officer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$officer) {
        http_response_code(404);
        echo json_encode(['error' => 'Officer not found']);
        exit;
    }
    
    // Check permissions
    $canView = $currentUser['role'] === 'admin' || 
               ($currentUser['role'] === 'district' && $currentUser['district_code'] === $officer['district_code']) ||
               ($currentUser['role'] === 'local' && $currentUser['local_code'] === $officer['local_code']) ||
               ($currentUser['role'] === 'local_limited' && $currentUser['local_code'] === $officer['local_code']);
    
    if (!$canView) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        exit;
    }
    
    // Get requests linked to this officer (either through officer_id or existing_officer_uuid)
    // Exclude completed requests (oath_taken status)
    $stmt = $db->prepare("
        SELECT 
            request_id,
            requested_department,
            requested_duty,
            status,
            requested_at,
            record_code
        FROM officer_requests
        WHERE (officer_id = ? OR existing_officer_uuid = ?)
        AND status != 'oath_taken'
        ORDER BY requested_at DESC
    ");
    $stmt->execute([$officer['officer_id'], $officerUuid]);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format the data
    $statusLabels = [
        'pending' => 'Pending',
        'requested_to_seminar' => 'Requested to Seminar',
        'in_seminar' => 'In Seminar',
        'seminar_completed' => 'Seminar Completed',
        'requested_to_oath' => 'Requested to Oath',
        'ready_to_oath' => 'Ready to Oath',
        'oath_taken' => 'Oath Taken',
        'rejected' => 'Rejected',
        'cancelled' => 'Cancelled'
    ];
    
    $formattedRequests = array_map(function($request) use ($statusLabels) {
        return [
            'request_id' => $request['request_id'],
            'requested_department' => $request['requested_department'],
            'requested_duty' => $request['requested_duty'],
            'status' => $request['status'],
            'status_label' => $statusLabels[$request['status']] ?? $request['status'],
            'requested_at' => $request['requested_at'],
            'requested_at_formatted' => date('M j, Y', strtotime($request['requested_at'])),
            'record_code' => $request['record_code']
        ];
    }, $requests);
    
    echo json_encode($formattedRequests);
    
} catch (Exception $e) {
    error_log("Get officer requests API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
