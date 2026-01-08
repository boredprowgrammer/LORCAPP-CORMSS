<?php
/**
 * Get Districts API
 * Returns list of districts for dropdown selection
 */
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

Security::requireLogin();

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

try {
    // Build query based on user role
    $whereClause = '';
    $params = [];
    
    if ($currentUser['role'] === 'district') {
        $whereClause = 'WHERE district_code = ?';
        $params[] = $currentUser['district_code'];
    }
    
    // Get districts
    $stmt = $db->prepare("
        SELECT district_code, district_name 
        FROM districts 
        $whereClause
        ORDER BY district_name
    ");
    
    $stmt->execute($params);
    $districts = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'districts' => $districts
    ]);
    
} catch (Exception $e) {
    error_log("Error loading districts: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to load districts'
    ]);
}
