<?php
/**
 * Delete Overseers Contact API
 */
// Suppress warnings and errors from output
error_reporting(E_ERROR | E_PARSE);
ob_start();

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/encryption.php';

// Clean any output buffer before sending JSON
ob_clean();
header('Content-Type: application/json');

Security::requireLogin();

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

// Check permissions
if (!in_array($currentUser['role'], ['admin', 'district', 'local'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    // Get POST data
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['contactId'])) {
        throw new Exception("Contact ID is required");
    }
    
    // Get existing contact for audit log
    $stmt = $db->prepare("SELECT * FROM overseers_contacts WHERE contact_id = ?");
    $stmt->execute([$data['contactId']]);
    $contact = $stmt->fetch();
    
    if (!$contact) {
        throw new Exception("Contact not found");
    }
    
    // Check if user has permission to delete this contact
    if ($currentUser['role'] === 'local' && $contact['local_code'] !== $currentUser['local_code']) {
        throw new Exception("You don't have permission to delete this contact");
    }
    if ($currentUser['role'] === 'district' && $contact['district_code'] !== $currentUser['district_code']) {
        throw new Exception("You don't have permission to delete this contact");
    }
    
    // Soft delete - mark as inactive
    $stmt = $db->prepare("
        UPDATE overseers_contacts 
        SET is_active = 0, updated_by = ? 
        WHERE contact_id = ?
    ");
    $stmt->execute([$currentUser['user_id'], $data['contactId']]);
    
    // Log audit
    logAudit($data['contactId'], 'delete', $contact, null, $currentUser['user_id'], $db);
    
    echo json_encode([
        'success' => true,
        'message' => 'Contact deleted successfully'
    ]);
    
} catch (Exception $e) {
    error_log("Error deleting overseers contact: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * Log audit trail
 */
function logAudit($contactId, $action, $oldValues, $newValues, $userId, $db) {
    try {
        $stmt = $db->prepare("
            INSERT INTO overseers_contacts_audit (
                contact_id, action, old_values, new_values, changed_by, ip_address, user_agent
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $contactId,
            $action,
            is_array($oldValues) ? json_encode($oldValues) : null,
            is_array($newValues) ? json_encode($newValues) : null,
            $userId,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    } catch (Exception $e) {
        error_log("Audit log error: " . $e->getMessage());
    }
}
