<?php
/**
 * Get Pending Access Requests (HDB, PNK, CFO)
 * For LORC and admin users to see requests needing approval
 */

require_once __DIR__ . '/../config/config.php';

Security::requireLogin();

header('Content-Type: application/json');

$currentUser = getCurrentUser();

// Only admin, local, and lorc can view pending requests
if (!in_array($currentUser['role'], ['admin', 'local', 'lorc'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    
    $registryType = isset($_GET['registry_type']) ? Security::sanitizeInput($_GET['registry_type']) : 'all';
    
    $requests = [];
    
    // Build local code filter
    $localFilter = "";
    $params = [];
    if ($currentUser['role'] !== 'admin') {
        $localFilter = " AND r.requester_local_code = ?";
        $params = [$currentUser['local_code']];
    }
    
    // Get HDB requests
    if ($registryType === 'all' || $registryType === 'hdb') {
        $sql = "
            SELECT r.*, u.username, u.full_name,
                   'hdb' as registry_type,
                   r.request_type as access_level,
                   r.request_date as created_at
            FROM hdb_access_requests r
            JOIN users u ON r.requester_user_id = u.user_id
            WHERE r.status = 'pending' 
            AND r.deleted_at IS NULL
            {$localFilter}
            ORDER BY r.request_date DESC
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $hdbRequests = $stmt->fetchAll();
        $requests = array_merge($requests, $hdbRequests);
    }
    
    // Get PNK requests
    if ($registryType === 'all' || $registryType === 'pnk') {
        $sql = "
            SELECT r.*, u.username, u.full_name,
                   'pnk' as registry_type,
                   r.request_type as access_level,
                   d.dako_name as dako_name,
                   r.request_date as created_at
            FROM pnk_access_requests r
            JOIN users u ON r.requester_user_id = u.user_id
            LEFT JOIN pnk_dako d ON r.dako_id = d.id
            WHERE r.status = 'pending' 
            AND r.deleted_at IS NULL
            {$localFilter}
            ORDER BY r.request_date DESC
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $pnkRequests = $stmt->fetchAll();
        $requests = array_merge($requests, $pnkRequests);
    }
    
    // Get CFO requests
    if ($registryType === 'all' || $registryType === 'cfo') {
        $sql = "
            SELECT r.*, u.username, u.full_name,
                   'cfo' as registry_type,
                   COALESCE(r.access_mode, 'view_data') as access_level,
                   r.cfo_type
            FROM cfo_access_requests r
            JOIN users u ON r.requester_user_id = u.user_id
            WHERE r.status = 'pending' 
            AND r.deleted_at IS NULL
            {$localFilter}
            ORDER BY r.created_at DESC
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $cfoRequests = $stmt->fetchAll();
        $requests = array_merge($requests, $cfoRequests);
    }
    
    // Format requests for display
    $formattedRequests = array_map(function($req) {
        $accessLevelLabel = match($req['access_level'] ?? 'view') {
            'view', 'view_data' => 'View Data',
            'add', 'add_member' => 'Add Records',
            'edit', 'edit_member' => 'Edit Records',
            default => $req['access_level'] ?? 'View'
        };
        
        return [
            'id' => $req['id'],
            'registry_type' => $req['registry_type'],
            'requester_name' => $req['full_name'],
            'requester_username' => $req['username'],
            'access_level' => $accessLevelLabel,
            'access_level_raw' => $req['access_level'] ?? 'view',
            'cfo_type' => $req['cfo_type'] ?? null,
            'dako_name' => $req['dako_name'] ?? null,
            'verification_status' => $req['verification_status'] ?? 'submitted',
            'created_at' => $req['created_at'],
            'request_reason' => $req['request_reason'] ?? ''
        ];
    }, $requests);
    
    // Sort by created_at descending
    usort($formattedRequests, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
    
    echo json_encode([
        'success' => true,
        'requests' => $formattedRequests,
        'count' => count($formattedRequests)
    ]);
    
} catch (Exception $e) {
    error_log("Error in get-pending-access-requests.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch pending requests']);
}
