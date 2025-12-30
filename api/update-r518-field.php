<?php
/**
 * API endpoint to update R5-18 checker fields
 */

require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

// Check if user is logged in
$currentUser = getCurrentUser();
if (!$currentUser) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Check CSRF token
if (!isset($_POST['csrf_token']) || !Security::validateCSRFToken($_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

// Validate required fields
if (!isset($_POST['officer_id']) || !isset($_POST['field']) || !isset($_POST['value'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

$officerId = intval($_POST['officer_id']);
$field = $_POST['field'];
$value = intval($_POST['value']); // 0 or 1

// Validate field name
$allowedFields = ['r518_submitted', 'r518_picture_attached', 'r518_signatories_complete', 'r518_data_verify'];
if (!in_array($field, $allowedFields)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid field name']);
    exit;
}

// Validate value
if ($value !== 0 && $value !== 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid value. Must be 0 or 1']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    
    // Update the field - use backticks for column name since it's dynamic
    $sql = "UPDATE officers SET `$field` = ? WHERE officer_id = ?";
    $stmt = $db->prepare($sql);
    $stmt->execute([$value, $officerId]);
    
    // Get updated completion status (trigger should have updated this, but we'll recalculate)
    $stmt = $db->prepare("
        SELECT 
            r518_submitted,
            r518_picture_attached,
            r518_signatories_complete,
            r518_completion_status
        FROM officers 
        WHERE officer_id = ?
    ");
    $stmt->execute([$officerId]);
    $officer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$officer) {
        throw new Exception('Officer not found');
    }
    
    // Calculate status
    $allComplete = $officer['r518_submitted'] == 1 && 
                   $officer['r518_picture_attached'] == 1 && 
                   $officer['r518_signatories_complete'] == 1;
    $allPending = $officer['r518_submitted'] == 0 && 
                  $officer['r518_picture_attached'] == 0 && 
                  $officer['r518_signatories_complete'] == 0;
    
    if ($allPending) {
        $newStatus = 'pending';
    } elseif ($allComplete) {
        $newStatus = 'complete';
    } else {
        $newStatus = 'incomplete';
    }
    
    // Update status if needed
    if ($officer['r518_completion_status'] !== $newStatus) {
        $stmt = $db->prepare("UPDATE officers SET r518_completion_status = ? WHERE officer_id = ?");
        $stmt->execute([$newStatus, $officerId]);
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Field updated successfully',
        'data' => [
            'officer_id' => $officerId,
            'field' => $field,
            'value' => $value,
            'completion_status' => $newStatus,
            'all_fields' => $officer
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to update field: ' . $e->getMessage()
    ]);
}
