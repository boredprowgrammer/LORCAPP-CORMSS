<?php
/**
 * Get Families API
 * Lists families with filtering and pagination
 */

require_once __DIR__ . '/../config/config.php';

Security::requireLogin();

header('Content-Type: application/json');

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

try {
    $action = Security::sanitizeInput($_GET['action'] ?? 'list');
    
    if ($action === 'get') {
        // Get single family details
        $id = intval($_GET['id'] ?? 0);
        
        if ($id <= 0) {
            throw new Exception('Invalid family ID');
        }
        
        // Get family
        $stmt = $db->prepare("
            SELECT f.*, d.district_name, lc.local_name, u.full_name as created_by_name
            FROM families f
            LEFT JOIN districts d ON f.district_code = d.district_code
            LEFT JOIN local_congregations lc ON f.local_code = lc.local_code
            LEFT JOIN users u ON f.created_by = u.user_id
            WHERE f.id = ? AND f.deleted_at IS NULL
        ");
        $stmt->execute([$id]);
        $family = $stmt->fetch();
        
        if (!$family) {
            throw new Exception('Family not found');
        }
        
        // Check access
        if ($currentUser['role'] === 'district' && $family['district_code'] !== $currentUser['district_code']) {
            throw new Exception('Access denied');
        }
        if (in_array($currentUser['role'], ['local', 'local_cfo']) && $family['local_code'] !== $currentUser['local_code']) {
            throw new Exception('Access denied');
        }
        
        // Decrypt family data
        $family['family_name'] = Encryption::decrypt($family['family_name_encrypted'], $family['district_code']);
        $family['address'] = $family['address_encrypted'] ? Encryption::decrypt($family['address_encrypted'], $family['district_code']) : '';
        $family['contact'] = $family['contact_encrypted'] ? Encryption::decrypt($family['contact_encrypted'], $family['district_code']) : '';
        
        // Get family members
        $stmt = $db->prepare("
            SELECT * FROM family_members 
            WHERE family_id = ? 
            ORDER BY is_head DESC, sort_order ASC
        ");
        $stmt->execute([$id]);
        $members = $stmt->fetchAll();
        
        // Decrypt member names
        foreach ($members as &$member) {
            $member['name'] = Encryption::decrypt($member['name_encrypted'], $family['district_code']);
            $member['birthday'] = $member['birthday_encrypted'] ? Encryption::decrypt($member['birthday_encrypted'], $family['district_code']) : '';
            
            // Format relationship display
            $relationships = [
                'asawa' => 'Asawa',
                'anak' => 'Anak',
                'pamangkin' => 'Pamangkin',
                'apo' => 'Apo',
                'magulang' => 'Magulang',
                'kapatid' => 'Kapatid',
                'indibidwal' => 'Indibidwal',
                'others' => $member['relationship_specify'] ?? 'Others'
            ];
            $member['relationship_display'] = $relationships[$member['relationship']] ?? $member['relationship'];
        }
        
        $family['members'] = $members;
        
        echo json_encode([
            'success' => true,
            'data' => $family
        ]);
        exit;
    }
    
    // List families
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = intval($_GET['limit'] ?? 25);
    $offset = ($page - 1) * $limit;
    $search = Security::sanitizeInput($_GET['search'] ?? '');
    $filterDistrict = Security::sanitizeInput($_GET['district'] ?? '');
    $filterLocal = Security::sanitizeInput($_GET['local'] ?? '');
    $filterPurok = Security::sanitizeInput($_GET['purok'] ?? '');
    $filterStatus = Security::sanitizeInput($_GET['status'] ?? '');
    
    // Build WHERE conditions
    $whereConditions = ['f.deleted_at IS NULL'];
    $params = [];
    
    // Role-based filtering
    if ($currentUser['role'] === 'district') {
        $whereConditions[] = 'f.district_code = ?';
        $params[] = $currentUser['district_code'];
    } elseif (in_array($currentUser['role'], ['local', 'local_cfo'])) {
        $whereConditions[] = 'f.local_code = ?';
        $params[] = $currentUser['local_code'];
    }
    
    // Additional filters
    if (!empty($filterDistrict)) {
        $whereConditions[] = 'f.district_code = ?';
        $params[] = $filterDistrict;
    }
    if (!empty($filterLocal)) {
        $whereConditions[] = 'f.local_code = ?';
        $params[] = $filterLocal;
    }
    if (!empty($filterPurok)) {
        $whereConditions[] = 'f.purok = ?';
        $params[] = $filterPurok;
    }
    if (!empty($filterStatus)) {
        $whereConditions[] = 'f.status = ?';
        $params[] = $filterStatus;
    }
    
    $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
    
    // Get total count
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM families f $whereClause");
    $stmt->execute($params);
    $totalRecords = $stmt->fetch()['total'];
    
    // Get families
    $stmt = $db->prepare("
        SELECT 
            f.*,
            d.district_name,
            lc.local_name,
            (SELECT COUNT(*) FROM family_members fm WHERE fm.family_id = f.id AND fm.status = 'active') as member_count
        FROM families f
        LEFT JOIN districts d ON f.district_code = d.district_code
        LEFT JOIN local_congregations lc ON f.local_code = lc.local_code
        $whereClause
        ORDER BY f.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $params[] = $limit;
    $params[] = $offset;
    $stmt->execute($params);
    $families = $stmt->fetchAll();
    
    // Process families
    $results = [];
    foreach ($families as $family) {
        $familyName = Encryption::decrypt($family['family_name_encrypted'], $family['district_code']);
        
        // Apply search filter on decrypted name
        if (!empty($search)) {
            $searchLower = mb_strtolower($search, 'UTF-8');
            $nameLower = mb_strtolower($familyName, 'UTF-8');
            if (mb_strpos($nameLower, $searchLower, 0, 'UTF-8') === false) {
                continue;
            }
        }
        
        $results[] = [
            'id' => $family['id'],
            'family_name' => $familyName,
            'purok' => $family['purok'],
            'grupo' => $family['grupo'],
            'purok_grupo' => ($family['purok'] && $family['grupo']) ? $family['purok'] . '-' . $family['grupo'] : ($family['purok'] ?: $family['grupo'] ?: '-'),
            'district_name' => $family['district_name'],
            'local_name' => $family['local_name'],
            'member_count' => $family['member_count'],
            'status' => $family['status'],
            'created_at' => $family['created_at']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'data' => $results,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $totalRecords,
            'pages' => ceil($totalRecords / $limit)
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
