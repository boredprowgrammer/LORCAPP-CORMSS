<?php
/**
 * Get available puroks for filtering
 */

require_once __DIR__ . '/../../config/config.php';

Security::requireLogin();

header('Content-Type: application/json');

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

try {
    // Build WHERE conditions
    $whereConditions = ['deleted_at IS NULL', 'purok IS NOT NULL', "purok != ''"];
    $params = [];
    
    if ($currentUser['role'] === 'local' || $currentUser['role'] === 'local_cfo') {
        $whereConditions[] = 'local_code = ?';
        $params[] = $currentUser['local_code'];
    } elseif ($currentUser['role'] === 'district') {
        $whereConditions[] = 'district_code = ?';
        $params[] = $currentUser['district_code'];
    }
    
    $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
    
    $stmt = $db->prepare("
        SELECT DISTINCT purok 
        FROM families 
        $whereClause 
        ORDER BY CAST(purok AS UNSIGNED), purok
    ");
    $stmt->execute($params);
    $puroks = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo json_encode([
        'success' => true,
        'puroks' => $puroks
    ]);
    
} catch (Exception $e) {
    error_log("Get puroks error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Failed to load puroks']);
}
