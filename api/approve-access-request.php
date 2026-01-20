<?php
/**
 * Approve/Reject Access Requests (HDB, PNK, CFO)
 * For LORC and admin users to manage access requests
 */

require_once __DIR__ . '/../config/config.php';

Security::requireLogin();

header('Content-Type: application/json');

$currentUser = getCurrentUser();

// Only admin, local, and lorc can approve requests
if (!in_array($currentUser['role'], ['admin', 'local', 'lorc'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    $input = json_decode(file_get_contents('php://input'), true);
    
    $registryType = Security::sanitizeInput($input['registry_type'] ?? '');
    $requestId = intval($input['request_id'] ?? 0);
    $action = Security::sanitizeInput($input['action'] ?? '');
    $notes = Security::sanitizeInput($input['notes'] ?? '');
    
    // Validate inputs
    if (!in_array($registryType, ['hdb', 'pnk', 'cfo'])) {
        throw new Exception('Invalid registry type');
    }
    
    if (!$requestId) {
        throw new Exception('Invalid request ID');
    }
    
    if (!in_array($action, ['approve', 'reject'])) {
        throw new Exception('Invalid action');
    }
    
    // Determine table name
    $tableName = match($registryType) {
        'hdb' => 'hdb_access_requests',
        'pnk' => 'pnk_access_requests',
        'cfo' => 'cfo_access_requests',
        default => throw new Exception('Invalid registry type')
    };
    
    // Fetch the request
    $stmt = $db->prepare("
        SELECT * FROM {$tableName} 
        WHERE id = ? AND status = 'pending' AND deleted_at IS NULL
    ");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();
    
    if (!$request) {
        throw new Exception('Request not found or already processed');
    }
    
    // Verify permissions: admin can approve any, local/lorc can only approve from their congregation
    if ($currentUser['role'] !== 'admin' && $request['requester_local_code'] !== $currentUser['local_code']) {
        throw new Exception('You can only manage requests from your local congregation');
    }
    
    if ($action === 'approve') {
        // For view requests, mark as approved directly
        // For add/edit requests, mark as pending_lorc_check first (LORC needs to verify the actual data)
        $requestType = $request['request_type'] ?? $request['access_mode'] ?? 'view';
        
        if ($requestType === 'view' || $requestType === 'view_data') {
            // Direct approval for view-only access
            $newStatus = 'approved';
            $verificationStatus = 'verified';
        } else {
            // For add/edit, set to pending_lorc_check - actual data will need verification
            $newStatus = 'approved';
            $verificationStatus = 'pending_lorc_check';
        }
        
        // Determine correct column names based on registry type
        // CFO uses approver_user_id/approval_notes, HDB/PNK use approved_by and no approval_notes
        if ($registryType === 'cfo') {
            $stmt = $db->prepare("
                UPDATE {$tableName} 
                SET status = ?,
                    verification_status = ?,
                    approver_user_id = ?,
                    approval_date = NOW(),
                    approval_notes = ?
                WHERE id = ?
            ");
            $stmt->execute([$newStatus, $verificationStatus, $currentUser['user_id'], $notes, $requestId]);
        } else {
            // HDB and PNK use approved_by column
            $stmt = $db->prepare("
                UPDATE {$tableName} 
                SET status = ?,
                    verification_status = ?,
                    approved_by = ?,
                    approval_date = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$newStatus, $verificationStatus, $currentUser['user_id'], $requestId]);
        }
        
        // Also insert into the data_access table for granular tracking
        $dataAccessTable = "{$registryType}_data_access";
        $accessLevel = match($requestType) {
            'view', 'view_data' => 'view',
            'add', 'add_member' => 'add',
            'edit', 'edit_member' => 'edit',
            default => 'view'
        };
        
        // Check if entry already exists for this access request
        $stmt = $db->prepare("
            SELECT id FROM {$dataAccessTable} 
            WHERE user_id = ? AND access_request_id = ?
        ");
        $stmt->execute([$request['requester_user_id'], $requestId]);
        $existingAccess = $stmt->fetch();
        
        $canView = in_array($accessLevel, ['view', 'add', 'edit']) ? 1 : 0;
        $canAdd = in_array($accessLevel, ['add', 'edit']) ? 1 : 0;
        $canEdit = $accessLevel === 'edit' ? 1 : 0;
        
        // Calculate expires_at (7 days from now)
        $expiresAt = date('Y-m-d', strtotime('+7 days'));
        
        if ($existingAccess) {
            // Update existing access
            $stmt = $db->prepare("
                UPDATE {$dataAccessTable} 
                SET can_view = ?, can_add = ?, can_edit = ?, 
                    granted_by = ?, granted_at = NOW(), expires_at = ?, is_active = 1
                WHERE id = ?
            ");
            $stmt->execute([$canView, $canAdd, $canEdit, $currentUser['user_id'], $expiresAt, $existingAccess['id']]);
        } else {
            // Insert new access - handle different table structures
            if ($registryType === 'pnk') {
                $dakoId = $request['dako_id'] ?? null;
                $stmt = $db->prepare("
                    INSERT INTO {$dataAccessTable} 
                    (user_id, access_request_id, dako_id, can_view, can_add, can_edit, granted_by, granted_at, expires_at, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, 1)
                ");
                $stmt->execute([
                    $request['requester_user_id'],
                    $requestId,
                    $dakoId,
                    $canView,
                    $canAdd,
                    $canEdit,
                    $currentUser['user_id'],
                    $expiresAt
                ]);
            } elseif ($registryType === 'cfo') {
                $cfoType = $request['cfo_type'] ?? 'Buklod';
                $stmt = $db->prepare("
                    INSERT INTO {$dataAccessTable} 
                    (user_id, access_request_id, cfo_type, can_view, can_add, can_edit, granted_by, granted_at, expires_at, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, 1)
                ");
                $stmt->execute([
                    $request['requester_user_id'],
                    $requestId,
                    $cfoType,
                    $canView,
                    $canAdd,
                    $canEdit,
                    $currentUser['user_id'],
                    $expiresAt
                ]);
            } else {
                // HDB
                $stmt = $db->prepare("
                    INSERT INTO {$dataAccessTable} 
                    (user_id, access_request_id, can_view, can_add, can_edit, granted_by, granted_at, expires_at, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, 1)
                ");
                $stmt->execute([
                    $request['requester_user_id'],
                    $requestId,
                    $canView,
                    $canAdd,
                    $canEdit,
                    $currentUser['user_id'],
                    $expiresAt
                ]);
            }
        }
        
        // Log the action
        secureLog("Access request approved", [
            'registry_type' => $registryType,
            'request_id' => $requestId,
            'requester_user_id' => $request['requester_user_id'],
            'access_level' => $accessLevel,
            'approved_by' => $currentUser['user_id']
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Access request approved successfully'
        ]);
        
    } else {
        // Reject the request
        if (empty($notes)) {
            throw new Exception('Rejection reason is required');
        }
        
        // Determine correct column names based on registry type
        if ($registryType === 'cfo') {
            $stmt = $db->prepare("
                UPDATE {$tableName} 
                SET status = 'rejected',
                    verification_status = 'rejected',
                    approver_user_id = ?,
                    approval_date = NOW(),
                    approval_notes = ?
                WHERE id = ?
            ");
            $stmt->execute([$currentUser['user_id'], $notes, $requestId]);
        } else {
            // HDB and PNK use approved_by and rejection_reason columns
            $stmt = $db->prepare("
                UPDATE {$tableName} 
                SET status = 'rejected',
                    verification_status = 'rejected',
                    approved_by = ?,
                    approval_date = NOW(),
                    rejection_reason = ?
                WHERE id = ?
            ");
            $stmt->execute([$currentUser['user_id'], $notes, $requestId]);
        }
        
        // Log the action
        secureLog("Access request rejected", [
            'registry_type' => $registryType,
            'request_id' => $requestId,
            'requester_user_id' => $request['requester_user_id'],
            'rejected_by' => $currentUser['user_id'],
            'reason' => $notes
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Access request rejected'
        ]);
    }
    
} catch (Exception $e) {
    error_log("Error in approve-access-request.php: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
