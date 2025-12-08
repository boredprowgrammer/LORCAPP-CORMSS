<?php
/**
 * Delete Officer API
 * Permanently deletes an officer record from the system
 */

require_once __DIR__ . '/../config/config.php';

// Set JSON header
header('Content-Type: application/json');

// Require login and permission
Security::requireLogin();

if (!hasPermission('can_delete_officers')) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'You do not have permission to delete officers.'
    ]);
    exit;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate input
$officerUuid = Security::sanitizeInput($input['officer_uuid'] ?? '');
$csrfToken = $input['csrf_token'] ?? '';

if (!Security::validateCSRFToken($csrfToken)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid security token.'
    ]);
    exit;
}

if (empty($officerUuid)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Officer ID is required.'
    ]);
    exit;
}

$db = Database::getInstance()->getConnection();
$currentUser = getCurrentUser();

try {
    // Get officer details before deletion
    $stmt = $db->prepare("
        SELECT 
            o.*,
            d.district_name,
            lc.local_name
        FROM officers o
        LEFT JOIN districts d ON o.district_code = d.district_code
        LEFT JOIN local_congregations lc ON o.local_code = lc.local_code
        WHERE o.officer_uuid = ?
    ");
    $stmt->execute([$officerUuid]);
    $officer = $stmt->fetch();
    
    if (!$officer) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Officer not found.'
        ]);
        exit;
    }
    
    // Check access permissions based on user role
    if ($currentUser['role'] === 'local') {
        if (!hasLocalAccess($officer['local_code'])) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'You do not have access to delete this officer.'
            ]);
            exit;
        }
    } elseif ($currentUser['role'] === 'district') {
        if (!hasDistrictAccess($officer['district_code'])) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'You do not have access to delete this officer.'
            ]);
            exit;
        }
    }
    // Admin and superadmin have full access
    
    // Decrypt officer name for audit log
    $decrypted = Encryption::decryptOfficerName(
        $officer['last_name_encrypted'],
        $officer['first_name_encrypted'],
        $officer['middle_initial_encrypted'],
        $officer['district_code']
    );
    
    $fullName = trim($decrypted['first_name'] . ' ' . 
                    ($decrypted['middle_initial'] ? $decrypted['middle_initial'] . '. ' : '') . 
                    $decrypted['last_name']);
    
    // Begin transaction
    $db->beginTransaction();
    
    // Store officer data for audit log
    $auditData = [
        'officer_uuid' => $officer['officer_uuid'],
        'officer_name' => $fullName,
        'local_code' => $officer['local_code'],
        'local_name' => $officer['local_name'],
        'district_code' => $officer['district_code'],
        'district_name' => $officer['district_name'],
        'is_active' => $officer['is_active'],
        'created_at' => $officer['created_at']
    ];
    
    // Delete the officer (CASCADE will handle related tables)
    $stmt = $db->prepare("DELETE FROM officers WHERE officer_uuid = ?");
    $stmt->execute([$officerUuid]);
    
    // Log the deletion in audit log
    $stmt = $db->prepare("
        INSERT INTO audit_log (user_id, action, table_name, record_id, old_values, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $currentUser['user_id'],
        'delete_officer',
        'officers',
        $officer['officer_id'],
        json_encode($auditData),
        $_SERVER['REMOTE_ADDR'],
        $_SERVER['HTTP_USER_AGENT'] ?? ''
    ]);
    
    // Commit transaction
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Officer deleted successfully.',
        'officer_name' => $fullName
    ]);
    
} catch (Exception $e) {
    // Rollback on error
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    error_log("Delete officer error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while deleting the officer.'
    ]);
}
