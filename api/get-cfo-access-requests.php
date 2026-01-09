<?php
/**
 * Get CFO Access Requests
 * For senior accounts to see pending requests
 * For local_cfo to see their own requests and approved PDFs
 */

require_once __DIR__ . '/../config/config.php';

Security::requireLogin();

header('Content-Type: application/json');

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

try {
    $action = Security::sanitizeInput($_GET['action'] ?? 'list');
    
    if ($action === 'pending' && ($currentUser['role'] === 'admin' || $currentUser['role'] === 'local')) {
        // Senior account viewing pending requests
        $whereClause = '';
        $params = [];
        
        if ($currentUser['role'] === 'local') {
            // Local accounts see only their congregation
            $whereClause = 'AND car.requester_local_code = ?';
            $params[] = $currentUser['local_code'];
        }
        // Admin sees all pending requests (no additional filter)
        
        $stmt = $db->prepare("
            SELECT 
                car.*,
                car.requester_local_code,
                u.full_name as requester_name,
                u.username as requester_username,
                lc.local_name,
                d.district_name
            FROM cfo_access_requests car
            JOIN users u ON car.requester_user_id = u.user_id
            LEFT JOIN local_congregations lc ON car.requester_local_code = lc.local_code
            LEFT JOIN districts d ON lc.district_code = d.district_code
            WHERE car.status = 'pending'
            AND car.deleted_at IS NULL
            $whereClause
            ORDER BY car.request_date DESC
        ");
        $stmt->execute($params);
        $requests = $stmt->fetchAll();
        
        echo json_encode(['success' => true, 'data' => $requests]);
        
    } elseif ($action === 'my-requests' && $currentUser['role'] === 'local_cfo') {
        // Local_CFO viewing their own requests
        $stmt = $db->prepare("
            SELECT 
                car.*,
                u.full_name as approver_name
            FROM cfo_access_requests car
            LEFT JOIN users u ON car.approver_user_id = u.user_id
            WHERE car.requester_user_id = ?
            AND car.deleted_at IS NULL
            ORDER BY 
                CASE 
                    WHEN car.status = 'approved' AND car.is_locked = FALSE THEN 1
                    WHEN car.status = 'pending' THEN 2
                    WHEN car.status = 'approved' AND car.is_locked = TRUE THEN 3
                    ELSE 4
                END,
                car.request_date DESC
        ");
        $stmt->execute([$currentUser['user_id']]);
        $requests = $stmt->fetchAll();
        
        // Add days remaining info for approved requests
        foreach ($requests as &$request) {
            if ($request['status'] === 'approved' && $request['first_opened_at']) {
                $firstOpened = new DateTime($request['first_opened_at']);
                $now = new DateTime();
                $daysSinceOpened = $now->diff($firstOpened)->days;
                
                $request['days_since_opened'] = $daysSinceOpened;
                $request['days_until_lock'] = max(0, 7 - $daysSinceOpened);
                $request['days_until_delete'] = max(0, 30 - $daysSinceOpened);
            }
        }
        
        echo json_encode(['success' => true, 'requests' => $requests]);
        
    } else {
        throw new Exception('Invalid action or insufficient permissions');
    }
    
} catch (Exception $e) {
    error_log("Error in get-cfo-access-requests.php: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
