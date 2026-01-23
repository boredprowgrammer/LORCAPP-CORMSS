<?php
/**
 * Get families list
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

    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = min(100, max(10, intval($_GET['limit'] ?? 25)));
    $offset = ($page - 1) * $limit;
    $search = Security::sanitizeInput($_GET['search'] ?? '');
    $purok = Security::sanitizeInput($_GET['purok'] ?? '');

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
    
    if (!empty($purok)) {
        $whereConditions[] = 'f.purok = ?';
        $params[] = $purok;
    }
    
    $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
    
    // Get total count
    $countSql = "SELECT COUNT(*) FROM families f $whereClause";
    $stmt = $db->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetchColumn();
    
    // Get families
    $sql = "
        SELECT f.id, f.family_code, f.pangulo_id, f.purok, f.grupo, f.status, f.created_at,
               d.district_name, lc.local_name,
               t.first_name_encrypted, t.last_name_encrypted, t.middle_name_encrypted, t.district_code as t_district
        FROM families f
        LEFT JOIN districts d ON f.district_code = d.district_code
        LEFT JOIN local_congregations lc ON f.local_code = lc.local_code
        LEFT JOIN tarheta_control t ON f.pangulo_id = t.id
        $whereClause
        ORDER BY f.created_at DESC
        LIMIT ? OFFSET ?
    ";
    
    $params[] = $limit;
    $params[] = $offset;
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $families = $stmt->fetchAll();
    
    // Process families
    $result = [];
    $searchLower = mb_strtolower($search, 'UTF-8');
    
    foreach ($families as $f) {
        // Decrypt pangulo name
        $panguloName = 'Unknown';
        if ($f['first_name_encrypted']) {
            try {
                $first = Encryption::decrypt($f['first_name_encrypted'], $f['t_district']);
                $last = Encryption::decrypt($f['last_name_encrypted'], $f['t_district']);
                $panguloName = trim("$first $last");
            } catch (Exception $e) {
                $panguloName = 'Decryption Error';
            }
        }
        
        // Apply search filter
        if (!empty($search)) {
            $searchText = mb_strtolower($f['family_code'] . ' ' . $panguloName, 'UTF-8');
            if (mb_strpos($searchText, $searchLower) === false) {
                continue;
            }
        }
        
        // Get member counts
        $stmtCounts = $db->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN kapisanan = 'Buklod' THEN 1 ELSE 0 END) as buklod,
                SUM(CASE WHEN kapisanan = 'Kadiwa' THEN 1 ELSE 0 END) as kadiwa,
                SUM(CASE WHEN kapisanan = 'Binhi' THEN 1 ELSE 0 END) as binhi
            FROM family_members WHERE family_id = ? AND is_active = 1
        ");
        $stmtCounts->execute([$f['id']]);
        $counts = $stmtCounts->fetch();
        
        $purokGrupo = '';
        if ($f['purok']) $purokGrupo = 'Purok ' . $f['purok'];
        if ($f['grupo']) $purokGrupo .= ($purokGrupo ? '-' : 'Grupo ') . $f['grupo'];
        
        $result[] = [
            'id' => $f['id'],
            'family_code' => $f['family_code'],
            'pangulo_name' => $panguloName,
            'purok_grupo' => $purokGrupo ?: null,
            'local_name' => $f['local_name'],
            'district_name' => $f['district_name'],
            'status' => $f['status'],
            'member_count' => intval($counts['total']),
            'buklod_count' => intval($counts['buklod']),
            'kadiwa_count' => intval($counts['kadiwa']),
            'binhi_count' => intval($counts['binhi'])
        ];
    }
    
    echo json_encode([
        'success' => true,
        'families' => $result,
        'total' => intval($total),
        'page' => $page,
        'limit' => $limit
    ]);
    
} catch (Exception $e) {
    error_log("Get families error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Failed to load families']);
}
