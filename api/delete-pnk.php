<?php
/**
 * Delete PNK Record API
 * Permanently deletes a PNK registry record from the system
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
        SELECT p.*, d.district_name, lc.local_name
        FROM pnk_registry p
        LEFT JOIN districts d ON p.district_code = d.district_code
        LEFT JOIN local_congregations lc ON p.local_code = lc.local_code
        WHERE p.id = ?
    ");
    $stmt->execute([$recordId]);
    $record = $stmt->fetch();
    
    if (!$record) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'PNK record not found.'
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
    
    // Get encryption key
    $districtCode = $record['district_code'];
    $encryptionKey = Encryption::getDistrictKey($districtCode);
    
    // Decrypt member name for logging
    $firstName = Encryption::decrypt($record['first_name_encrypted'], $encryptionKey);
    $lastName = Encryption::decrypt($record['last_name_encrypted'], $encryptionKey);
    $fullName = trim($firstName . ' ' . $lastName);
    
    // Delete the record
    $stmt = $db->prepare("DELETE FROM pnk_registry WHERE id = ?");
    $stmt->execute([$recordId]);
    
    // Log the deletion
    logAudit('delete_pnk', 'pnk_registry', $recordId, [
        'member_name' => $fullName,
        'local_code' => $record['local_code'],
        'district_code' => $record['district_code']
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'PNK record deleted successfully.'
    ]);
    
} catch (Exception $e) {
    error_log("Error deleting PNK record: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while deleting the record.'
    ]);
}
