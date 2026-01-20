<?php
/**
 * Delete HDB Record API
 * Permanently deletes an HDB registry record from the system
 */

require_once __DIR__ . '/../config/config.php';

// Set JSON header
header('Content-Type: application/json');

// Require login
Security::requireLogin();

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
$recordId = intval($input['id'] ?? 0);
$csrfToken = $input['csrf_token'] ?? '';

if (!Security::validateCSRFToken($csrfToken)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid security token.'
    ]);
    exit;
}

if ($recordId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Record ID is required.'
    ]);
    exit;
}

$db = Database::getInstance()->getConnection();
$currentUser = getCurrentUser();

try {
    // Get record details before deletion
    $stmt = $db->prepare("
        SELECT h.*, d.district_name, lc.local_name
        FROM hdb_registry h
        LEFT JOIN districts d ON h.district_code = d.district_code
        LEFT JOIN local_congregations lc ON h.local_code = lc.local_code
        WHERE h.id = ?
    ");
    $stmt->execute([$recordId]);
    $record = $stmt->fetch();
    
    if (!$record) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'HDB record not found.'
        ]);
        exit;
    }
    
    // Check permission - admin can delete any, local can only delete their own
    if ($currentUser['role'] !== 'admin') {
        if ($currentUser['role'] !== 'local' || $currentUser['local_code'] !== $record['local_code']) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'You do not have permission to delete this record.'
            ]);
            exit;
        }
    }
    
    // Decrypt child name for logging using district code
    $districtCode = $record['district_code'];
    $childFirstName = Encryption::decrypt($record['child_first_name_encrypted'], $districtCode);
    $childLastName = Encryption::decrypt($record['child_last_name_encrypted'], $districtCode);
    $childFullName = trim($childFirstName . ' ' . $childLastName);
    
    // Delete the record
    $stmt = $db->prepare("DELETE FROM hdb_registry WHERE id = ?");
    $stmt->execute([$recordId]);
    
    // Log the deletion
    logAudit('delete_hdb', 'hdb_registry', $recordId, [
        'child_name' => $childFullName,
        'local_code' => $record['local_code'],
        'district_code' => $record['district_code']
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'HDB record deleted successfully.'
    ]);
    
} catch (Exception $e) {
    error_log("Error deleting HDB record: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while deleting the record.'
    ]);
}
