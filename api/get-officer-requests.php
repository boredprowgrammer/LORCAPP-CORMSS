<?php
require_once __DIR__ . '/../config/config.php';

Security::requireLogin();

header('Content-Type: application/json');

$officerUuid = Security::sanitizeInput($_GET['officer_uuid'] ?? '');

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
