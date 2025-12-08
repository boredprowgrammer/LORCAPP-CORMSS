<?php
/**
 * Bulk Update Officer Requests
 * Apply status updates to multiple requests at once
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';

Security::requireLogin();

$db = Database::getInstance()->getConnection();
$user = getCurrentUser();

// Check permissions
if (!in_array($user['role'], ['admin', 'district', 'local'])) {
    header('Location: list.php?error=' . urlencode('You do not have permission to perform bulk updates.'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: list.php');
    exit;
}

Security::validateCSRFToken($_POST['csrf_token'] ?? '');

$action = $_POST['action'] ?? '';
$requestIds = json_decode($_POST['request_ids'] ?? '[]', true);

if (empty($action) || empty($requestIds) || !is_array($requestIds)) {
    header('Location: list.php?error=' . urlencode('Invalid bulk action request.'));
    exit;
}

$success = 0;
$failed = 0;
$errors = [];

try {
    $db->beginTransaction();
    
    foreach ($requestIds as $requestId) {
        try {
            // Get request details to verify permissions
            $stmt = $db->prepare("SELECT district_code, local_code, status FROM officer_requests WHERE request_id = ?");
            $stmt->execute([$requestId]);
            $request = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$request) {
                $failed++;
                $errors[] = "Request #$requestId not found";
                continue;
            }
            
            // Check permissions
            $canManage = $user['role'] === 'admin' || 
                         ($user['role'] === 'district' && $user['district_code'] === $request['district_code']) ||
                         ($user['role'] === 'local' && $user['local_code'] === $request['local_code']);
            
            if (!$canManage) {
                $failed++;
                $errors[] = "No permission for request #$requestId";
                continue;
            }
            
            // Apply the action
            $updated = false;
            
            switch ($action) {
                case 'approve_seminar':
                    if ($request['status'] === 'pending') {
                        $stmt = $db->prepare("
                            UPDATE officer_requests 
                            SET status = 'requested_to_seminar',
                                approved_seminar_by = ?,
                                seminar_approved_at = NOW()
                            WHERE request_id = ?
                        ");
                        $stmt->execute([$user['user_id'], $requestId]);
                        $updated = true;
                    }
                    break;
                    
                case 'mark_in_seminar':
                    if ($request['status'] === 'requested_to_seminar') {
                        $stmt = $db->prepare("
                            UPDATE officer_requests 
                            SET status = 'in_seminar'
                            WHERE request_id = ?
                        ");
                        $stmt->execute([$requestId]);
                        $updated = true;
                    }
                    break;
                    
                case 'complete_seminar':
                    if ($request['status'] === 'in_seminar') {
                        $stmt = $db->prepare("
                            UPDATE officer_requests 
                            SET status = 'seminar_completed',
                                seminar_actual_date = NOW()
                            WHERE request_id = ?
                        ");
                        $stmt->execute([$requestId]);
                        $updated = true;
                    }
                    break;
                    
                case 'approve_oath':
                    if ($request['status'] === 'seminar_completed') {
                        $stmt = $db->prepare("
                            UPDATE officer_requests 
                            SET status = 'requested_to_oath',
                                approved_oath_by = ?,
                                oath_approved_at = NOW()
                            WHERE request_id = ?
                        ");
                        $stmt->execute([$user['user_id'], $requestId]);
                        $updated = true;
                    }
                    break;
                    
                case 'mark_ready_oath':
                    if ($request['status'] === 'requested_to_oath') {
                        $stmt = $db->prepare("
                            UPDATE officer_requests 
                            SET status = 'ready_to_oath'
                            WHERE request_id = ?
                        ");
                        $stmt->execute([$requestId]);
                        $updated = true;
                    }
                    break;
                    
                default:
                    $failed++;
                    $errors[] = "Invalid action for request #$requestId";
                    continue 2;
            }
            
            if ($updated) {
                // Audit log
                $stmt = $db->prepare("
                    INSERT INTO audit_log (user_id, action, table_name, record_id, new_values, ip_address, user_agent)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $user['user_id'],
                    'bulk_update_request_' . $action,
                    'officer_requests',
                    $requestId,
                    json_encode(['action' => $action]),
                    $_SERVER['REMOTE_ADDR'] ?? '',
                    $_SERVER['HTTP_USER_AGENT'] ?? ''
                ]);
                
                $success++;
            } else {
                $failed++;
                $errors[] = "Request #$requestId not eligible for this action (current status: {$request['status']})";
            }
            
        } catch (Exception $e) {
            $failed++;
            $errors[] = "Request #$requestId: " . $e->getMessage();
        }
    }
    
    $db->commit();
    
    // Build success message
    $message = "Bulk update completed. ";
    if ($success > 0) {
        $message .= "$success request(s) updated successfully. ";
    }
    if ($failed > 0) {
        $message .= "$failed request(s) failed. ";
        if (!empty($errors)) {
            $message .= "Errors: " . implode('; ', array_slice($errors, 0, 5));
            if (count($errors) > 5) {
                $message .= " (and " . (count($errors) - 5) . " more)";
            }
        }
    }
    
    if ($success > 0) {
        header('Location: list.php?success=' . urlencode($message));
    } else {
        header('Location: list.php?error=' . urlencode($message));
    }
    
} catch (Exception $e) {
    $db->rollBack();
    error_log("Bulk update error: " . $e->getMessage());
    header('Location: list.php?error=' . urlencode('Bulk update failed: ' . $e->getMessage()));
}

exit;
?>
