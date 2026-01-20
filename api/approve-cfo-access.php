<?php
/**
 * Approve/Reject CFO Access Request
 * New permission-based system (no PDF upload required)
 */

require_once __DIR__ . '/../config/config.php';

Security::requireLogin();

$currentUser = getCurrentUser();

// Only admin and local accounts can approve requests
if ($currentUser['role'] !== 'admin' && $currentUser['role'] !== 'local') {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied. Only admin and local accounts can approve requests.']);
    exit;
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$db = Database::getInstance()->getConnection();

// Read JSON input
$jsonInput = file_get_contents('php://input');
$data = json_decode($jsonInput, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON input']);
    exit;
}

$action = $data['action'] ?? 'approve';
$requestId = intval($data['request_id'] ?? 0);

if (!$requestId) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request ID']);
    exit;
}

try {
    // Verify request exists and is pending
    $stmt = $db->prepare("
        SELECT * FROM cfo_access_requests 
        WHERE id = ? AND status = 'pending' AND deleted_at IS NULL
    ");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();
    
    if (!$request) {
        throw new Exception('Request not found or already processed');
    }
    
    // Admin can handle any request, local can only handle from their congregation
    if ($currentUser['role'] !== 'admin' && $request['requester_local_code'] !== $currentUser['local_code']) {
        throw new Exception('You can only manage requests from your local congregation');
    }
    
    if ($action === 'reject') {
        // Handle rejection
        $rejectionReason = Security::sanitizeInput($data['rejection_reason'] ?? '');
        
        if (empty($rejectionReason)) {
            throw new Exception('Rejection reason is required');
        }
        
        $stmt = $db->prepare("
            UPDATE cfo_access_requests 
            SET status = 'rejected',
                approver_user_id = ?,
                approval_date = NOW(),
                approval_notes = ?
            WHERE id = ?
        ");
        $stmt->execute([$currentUser['user_id'], $rejectionReason, $requestId]);
        
        secureLog("CFO access request rejected", [
            'request_id' => $requestId,
            'rejector_id' => $currentUser['user_id'],
            'reason' => $rejectionReason
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Request rejected']);
        exit;
    }
    
    // Handle approval
    $approvalNotes = Security::sanitizeInput($data['approval_notes'] ?? '');
    $approverUserId = $currentUser['user_id'];
    
    // If admin is approving, they can optionally specify which senior account approves
    if ($currentUser['role'] === 'admin' && !empty($data['senior_user_id'])) {
        $seniorUserId = intval($data['senior_user_id']);
        
        // Verify the senior user exists and is a local account from the same congregation
        $stmt = $db->prepare("SELECT * FROM users WHERE user_id = ? AND role = 'local' AND is_active = 1");
        $stmt->execute([$seniorUserId]);
        $seniorUser = $stmt->fetch();
        
        if (!$seniorUser) {
            throw new Exception('Invalid senior approver selected');
        }
        
        if ($seniorUser['local_code'] !== $request['requester_local_code']) {
            throw new Exception('Selected senior must be from the same local as the requester');
        }
        
        $approverUserId = $seniorUserId;
    }
    
    // Update request status to approved
    $stmt = $db->prepare("
        UPDATE cfo_access_requests 
        SET status = 'approved',
            approver_user_id = ?,
            approval_date = NOW(),
            approval_notes = ?
        WHERE id = ?
    ");
    $stmt->execute([$approverUserId, $approvalNotes, $requestId]);
    
    // Log the action
    secureLog("CFO access request approved", [
        'request_id' => $requestId,
        'approver_id' => $approverUserId,
        'assigned_by' => $currentUser['user_id'],
        'cfo_type' => $request['cfo_type'],
        'access_mode' => $request['access_mode'],
        'requester_user_id' => $request['requester_user_id']
    ]);
    
    echo json_encode([
        'success' => true, 
        'message' => 'Access request approved successfully'
    ]);
    
} catch (Exception $e) {
    error_log("Error in approve-cfo-access.php: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
