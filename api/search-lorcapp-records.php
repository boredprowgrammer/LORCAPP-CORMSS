<?php
/**
 * Search LORCAPP Records API
 * Search for R-201 records in LORCAPP database by name
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../lorcapp/includes/config.php';
require_once __DIR__ . '/../lorcapp/includes/encryption.php';

// Suppress all errors/warnings for clean JSON output
error_reporting(0);
ini_set('display_errors', 0);

// Start session for authentication
session_start();

// Suppress any output that might interfere with JSON
ob_start();

header('Content-Type: application/json');

// Check authentication for API
if (!Security::isLoggedIn()) {
    ob_clean();
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$user = getCurrentUser();
$canManage = in_array($user['role'], ['admin', 'district', 'local']);

if (!$canManage) {
    ob_clean();
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Get search query
$query = $_GET['q'] ?? '';

if (strlen($query) < 2) {
    ob_clean();
    echo json_encode(['error' => 'Query too short', 'records' => []]);
    exit;
}

try {
    // Get LORCAPP connection
    $lorcapp_conn = getDbConnection();
    
    // Since there are only a few records and names are encrypted,
    // fetch ALL records and search after decryption
    $sql = "SELECT id, given_name, father_surname, mother_surname, birth_date 
            FROM r201_members 
            ORDER BY created_at DESC";
    
    $stmt = $lorcapp_conn->prepare($sql);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $all_records = [];
    while ($row = $result->fetch_assoc()) {
        // Decrypt names
        $row = decryptRecordNames($row);
        $all_records[] = $row;
    }
    
    // Filter decrypted records by search query (case-insensitive)
    $query_lower = strtolower($query);
    $filtered_records = [];
    
    foreach ($all_records as $record) {
        $given_name_lower = strtolower($record['given_name'] ?? '');
        $father_surname_lower = strtolower($record['father_surname'] ?? '');
        $mother_surname_lower = strtolower($record['mother_surname'] ?? '');
        
        // Check if query matches any name field
        if (strpos($given_name_lower, $query_lower) !== false ||
            strpos($father_surname_lower, $query_lower) !== false ||
            strpos($mother_surname_lower, $query_lower) !== false) {
            $filtered_records[] = $record;
        }
    }
    
    // Clear any output that may have been generated (warnings, etc)
    ob_clean();
    
    echo json_encode([
        'success' => true,
        'records' => $filtered_records,
        'count' => count($filtered_records),
        'total_records' => count($all_records)
    ]);
    
} catch (Exception $e) {
    // Clear any output that may have been generated
    ob_clean();
    
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
