<?php
/**
 * Get family statistics
 */

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../../config/config.php';
    
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit;
    }
    
    $currentUser = getCurrentUser();
    $db = Database::getInstance()->getConnection();

    // Build WHERE conditions
    $whereConditions = ['f.deleted_at IS NULL'];
    $params = [];
    
    if ($currentUser['role'] === 'local' || $currentUser['role'] === 'local_cfo') {
        $whereConditions[] = 'f.local_code = ?';
        $params[] = $currentUser['local_code'];
    } elseif ($currentUser['role'] === 'district') {
        $whereConditions[] = 'f.district_code = ?';
        $params[] = $currentUser['district_code'];
    }
    
    $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
    
    // Get total families
    $stmt = $db->prepare("SELECT COUNT(*) FROM families f $whereClause");
    $stmt->execute($params);
    $totalFamilies = $stmt->fetchColumn();
    
    // Get member statistics
    $memberStatsSQL = "
        SELECT 
            COUNT(fm.id) as total_members,
            SUM(CASE WHEN fm.kapisanan = 'Buklod' THEN 1 ELSE 0 END) as buklod,
            SUM(CASE WHEN fm.kapisanan = 'Kadiwa' THEN 1 ELSE 0 END) as kadiwa,
            SUM(CASE WHEN fm.kapisanan = 'Binhi' THEN 1 ELSE 0 END) as binhi,
            SUM(CASE WHEN fm.kapisanan IN ('HDB', 'PNK') THEN 1 ELSE 0 END) as children
        FROM family_members fm
        INNER JOIN families f ON fm.family_id = f.id
        $whereClause AND fm.is_active = 1
    ";
    
    $stmt = $db->prepare($memberStatsSQL);
    $stmt->execute($params);
    $memberStats = $stmt->fetch();
    
    echo json_encode([
        'success' => true,
        'stats' => [
            'total' => intval($totalFamilies),
            'members' => intval($memberStats['total_members']),
            'buklod' => intval($memberStats['buklod']),
            'children' => intval($memberStats['binhi']) + intval($memberStats['children'])
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Get family stats error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Failed to load statistics']);
}
