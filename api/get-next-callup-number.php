<?php
/**
 * API: Get Next Call-Up File Number
 * Returns the next sequential file number for a given prefix
 */

require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$prefix = $_GET['prefix'] ?? '';

if (empty($prefix)) {
    echo json_encode(['success' => false, 'error' => 'Prefix required']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    
    // Find the highest number with this prefix
    $stmt = $db->prepare("
        SELECT file_number 
        FROM call_up_slips 
        WHERE file_number LIKE ? 
        ORDER BY file_number DESC 
        LIMIT 1
    ");
    
    $stmt->execute([$prefix . '%']);
    $lastRecord = $stmt->fetch();
    
    if ($lastRecord) {
        // Extract number from file_number (e.g., "BUK-2025-005" -> 5)
        $lastNumber = (int)substr($lastRecord['file_number'], strlen($prefix));
        $nextNumber = $lastNumber + 1;
    } else {
        // No records yet, start with 1
        $nextNumber = 1;
    }
    
    // Format with leading zeros (3 digits)
    $fileNumber = $prefix . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
    
    echo json_encode([
        'success' => true,
        'file_number' => $fileNumber,
        'next_number' => $nextNumber
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
