<?php
/**
 * API: Update Purok or Grupo for an Officer
 * Used by LORC/LCRC Checker for quick inline editing
 */

require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

// Check if logged in
if (!Security::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Check permission
if (!hasPermission('can_edit_officers')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

// Get request data
$data = json_decode(file_get_contents('php://input'), true);

// Validate CSRF token
if (!Security::validateCSRFToken($data['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token']);
    exit;
}

// Validate input
$officerId = filter_var($data['officer_id'] ?? '', FILTER_VALIDATE_INT);
$field = $data['field'] ?? '';
$value = Security::sanitizeInput($data['value'] ?? '');

if (!$officerId || !in_array($field, ['purok', 'grupo'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    $currentUser = getCurrentUser();
    
    // Get officer to verify access
    $stmt = $db->prepare("SELECT officer_id, district_code, local_code FROM officers WHERE officer_id = ?");
    $stmt->execute([$officerId]);
    $officer = $stmt->fetch();
    
    if (!$officer) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Officer not found']);
        exit;
    }
    
    // Check access based on role
    if ($currentUser['role'] === 'district' && $officer['district_code'] !== $currentUser['district_code']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied to this officer']);
        exit;
    }
    
    if ($currentUser['role'] === 'local' && $officer['local_code'] !== $currentUser['local_code']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied to this officer']);
        exit;
    }
    
    // Update the field
    $stmt = $db->prepare("UPDATE officers SET $field = ?, updated_at = NOW() WHERE officer_id = ?");
    $stmt->execute([
        !empty($value) ? $value : null,
        $officerId
    ]);
    
    // Log the change in audit trail
    $stmt = $db->prepare("
        INSERT INTO audit_log (user_id, action, table_name, record_id, new_values, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $currentUser['user_id'],
        'edit_officer_field',
        'officers',
        $officerId,
        json_encode(['field' => $field, 'value' => $value]),
        $_SERVER['REMOTE_ADDR'],
        $_SERVER['HTTP_USER_AGENT'] ?? ''
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => ucfirst($field) . ' updated successfully',
        'value' => $value
    ]);
    
} catch (Exception $e) {
    error_log("Update purok/grupo error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
