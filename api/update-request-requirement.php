<?php
require_once __DIR__ . '/../config/config.php';

Security::requireLogin();

header('Content-Type: application/json');

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

$requestId = intval($input['request_id'] ?? 0);
$field = $input['field'] ?? '';
$value = $input['value'] ?? false;

if (!$requestId || !$field) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input']);
    exit;
}

// Validate field name (whitelist)
$allowedFields = [
    'has_r515',
    'has_patotoo_katiwala',
    'has_patotoo_kapisanan',
    'has_salaysay_magulang',
    'has_salaysay_pagtanggap',
    'has_picture'
];

if (!in_array($field, $allowedFields)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid field']);
    exit;
}

try {
    // Check if request exists and user has permission
    $stmt = $db->prepare("
        SELECT district_code, local_code 
        FROM officer_requests 
        WHERE request_id = ?
    ");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$request) {
        http_response_code(404);
        echo json_encode(['error' => 'Request not found']);
        exit;
    }
    
    // Check permissions
    $canUpdate = $currentUser['role'] === 'admin' || 
                 ($currentUser['role'] === 'district' && $currentUser['district_code'] === $request['district_code']) ||
                 ($currentUser['role'] === 'local' && $currentUser['local_code'] === $request['local_code']);
    
    if (!$canUpdate) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        exit;
    }
    
    // Update the field
    $sql = "UPDATE officer_requests SET $field = ? WHERE request_id = ?";
    $stmt = $db->prepare($sql);
    $stmt->execute([$value ? 1 : 0, $requestId]);
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    error_log("Update request requirement API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
