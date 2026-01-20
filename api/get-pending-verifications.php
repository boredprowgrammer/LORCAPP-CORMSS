<?php
/**
 * Get Pending Verifications API
 * Returns pending add/edit verifications for a specific registry type
 */

require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

try {
    Security::requireLogin();
    
    $currentUser = getCurrentUser();
    
    // Only admin and local users can view pending verifications
    if (!in_array($currentUser['role'], ['admin', 'local'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Insufficient permissions']);
        exit;
    }
    
    $registryType = isset($_GET['registry_type']) ? trim($_GET['registry_type']) : '';
    
    if (!in_array($registryType, ['hdb', 'pnk', 'cfo'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid registry type']);
        exit;
    }
    
    $db = Database::getInstance()->getConnection();
    
    // Build query based on registry type and user's location
    $query = "
        SELECT 
            pv.*,
            u.full_name as submitted_by_name
        FROM pending_verifications pv
        LEFT JOIN users u ON pv.submitted_by = u.user_id
        WHERE pv.registry_type = ?
        AND pv.verification_status IN ('submitted', 'pending_lorc_check')
    ";
    $params = [$registryType];
    
    // Non-admin users can only see verifications for their local
    if ($currentUser['role'] !== 'admin') {
        // Get district/local codes based on registry type
        $query .= " AND JSON_EXTRACT(pv.new_data, '$.local_code') = ?";
        $params[] = $currentUser['local_code'];
    }
    
    $query .= " ORDER BY pv.submitted_at DESC LIMIT 100";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Process results to extract useful data
    $processedResults = [];
    foreach ($results as $row) {
        $newData = json_decode($row['new_data'], true) ?? [];
        
        // Extract display name based on registry type
        $displayName = '';
        if ($registryType === 'hdb') {
            $displayName = $newData['child_name'] ?? 'Unknown Child';
        } elseif ($registryType === 'pnk') {
            $firstName = $newData['first_name'] ?? '';
            $lastName = $newData['last_name'] ?? '';
            $displayName = trim("$firstName $lastName") ?: 'Unknown Member';
        } elseif ($registryType === 'cfo') {
            $displayName = $newData['full_name'] ?? 'Unknown Member';
        }
        
        $processedResults[] = [
            'id' => $row['id'],
            'action_type' => $row['action_type'],
            'child_name' => $displayName,
            'submitted_by_name' => $row['submitted_by_name'],
            'submitted_at' => date('M j, Y g:i A', strtotime($row['submitted_at'])),
            'verification_status' => $row['verification_status'],
            'new_data' => $newData,
            'original_data' => $row['original_data'] ? json_decode($row['original_data'], true) : null
        ];
    }
    
    echo json_encode([
        'success' => true,
        'results' => $processedResults,
        'count' => count($processedResults)
    ]);
    
} catch (Exception $e) {
    error_log("Get Pending Verifications Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to load pending verifications']);
}
