<?php
/**
 * Pending Actions Management Page
 * For senior local accounts to approve/reject actions from local_limited users
 */

require_once __DIR__ . '/config/config.php';

Security::requireLogin();

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

$error = '';
$success = '';

// Check if user can view pending actions
// Allow: local (senior approvers) OR local_limited (to view their own submissions) OR local_cfo (for CFO submissions)
if ($currentUser['role'] !== 'local' && $currentUser['role'] !== 'local_limited' && $currentUser['role'] !== 'local_cfo' && $currentUser['role'] !== 'admin') {
    $_SESSION['error'] = "You do not have permission to view pending actions.";
    header('Location: ' . BASE_URL . '/launchpad.php');
    exit;
}

// Determine if user is a senior approver or limited user viewing their own
$isSeniorApprover = ($currentUser['role'] === 'local' || $currentUser['role'] === 'admin');
$isLimitedUser = ($currentUser['role'] === 'local_limited' || $currentUser['role'] === 'local_cfo');

// Handle approve/reject actions (only for senior approvers)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isSeniorApprover) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!Security::validateCSRFToken($csrfToken)) {
        $error = 'Invalid security token.';
    } else {
        $actionId = intval($_POST['action_id'] ?? 0);
        $decision = $_POST['decision'] ?? ''; // 'approve', 'reject', or 'forward'
        $rejectionReason = Security::sanitizeInput($_POST['rejection_reason'] ?? '');
        $forwardToUserId = intval($_POST['forward_to_user_id'] ?? 0);
        $forwardNotes = Security::sanitizeInput($_POST['forward_notes'] ?? '');
        
        if ($actionId <= 0) {
            $error = 'Invalid action ID.';
        } elseif (!in_array($decision, ['approve', 'reject', 'forward'])) {
            $error = 'Invalid decision.';
        } elseif ($decision === 'reject' && empty($rejectionReason)) {
            $error = 'Please provide a reason for rejection.';
        } elseif ($decision === 'forward' && $forwardToUserId <= 0) {
            $error = 'Please select a user to forward to.';
        } else {
            try {
                $db->beginTransaction();
                
                // Get the pending action
                $stmt = $db->prepare("
                    SELECT * FROM pending_actions 
                    WHERE action_id = ? AND approver_user_id = ? AND status = 'pending'
                ");
                $stmt->execute([$actionId, $currentUser['user_id']]);
                $pendingAction = $stmt->fetch();
                
                if (!$pendingAction) {
                    throw new Exception('Pending action not found or you do not have permission to review it.');
                }
                
                if ($decision === 'approve') {
                    // Execute the action based on action_type
                    $actionData = json_decode($pendingAction['action_data'], true);
                    $success = executeApprovedAction($pendingAction['action_type'], $actionData, $pendingAction, $db);
                    
                    // Update pending action status
                    $stmt = $db->prepare("
                        UPDATE pending_actions 
                        SET status = 'approved', reviewed_at = NOW(), reviewed_by = ?
                        WHERE action_id = ?
                    ");
                    $stmt->execute([$currentUser['user_id'], $actionId]);
                    
                    $success = 'Action approved and executed successfully!';
                    
                } elseif ($decision === 'forward') {
                    // Verify target user is a valid local account
                    $stmt = $db->prepare("SELECT user_id, full_name, local_code FROM users WHERE user_id = ? AND role = 'local' AND is_active = 1");
                    $stmt->execute([$forwardToUserId]);
                    $targetUser = $stmt->fetch();
                    
                    if (!$targetUser) {
                        throw new Exception('Invalid target user for forwarding.');
                    }
                    
                    // Update the approver_user_id to the new user
                    $stmt = $db->prepare("
                        UPDATE pending_actions 
                        SET approver_user_id = ?
                        WHERE action_id = ?
                    ");
                    $stmt->execute([$forwardToUserId, $actionId]);
                    
                    // Log the forward action
                    secureLog('Pending action forwarded', [
                        'action_id' => $actionId,
                        'forwarded_from' => $currentUser['user_id'],
                        'forwarded_to' => $forwardToUserId,
                        'notes' => $forwardNotes
                    ]);
                    
                    $success = 'Action forwarded to ' . $targetUser['full_name'] . ' successfully!';
                    
                } else { // reject
                    // Update pending action status
                    $stmt = $db->prepare("
                        UPDATE pending_actions 
                        SET status = 'rejected', reviewed_at = NOW(), reviewed_by = ?, rejection_reason = ?
                        WHERE action_id = ?
                    ");
                    $stmt->execute([$currentUser['user_id'], $rejectionReason, $actionId]);
                    
                    $success = 'Action rejected successfully.';
                }
                
                $db->commit();
                
            } catch (Exception $e) {
                $db->rollBack();
                error_log("Pending action error: " . $e->getMessage());
                $error = 'An error occurred: ' . $e->getMessage();
            }
        }
    }
}

// Handle update action data
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isSeniorApprover && isset($_POST['update_action'])) {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        try {
            $actionId = (int)($_POST['action_id'] ?? 0);
            
            if ($actionId <= 0) {
                throw new Exception('Invalid action ID.');
            }
            
            $db->beginTransaction();
            
            // Get the pending action
            $stmt = $db->prepare("
                SELECT * FROM pending_actions 
                WHERE action_id = ? AND approver_user_id = ? AND status = 'pending'
            ");
            $stmt->execute([$actionId, $currentUser['user_id']]);
            $pendingAction = $stmt->fetch();
            
            if (!$pendingAction) {
                throw new Exception('Pending action not found or you do not have permission to edit it.');
            }
            
            // Get current action data
            $actionData = json_decode($pendingAction['action_data'], true);
            
            // Update with new values from form
            $updatedData = $actionData;
            foreach ($_POST as $key => $value) {
                if ($key !== 'csrf_token' && $key !== 'action_id' && $key !== 'update_action') {
                    $updatedData[$key] = Security::sanitizeInput($value);
                }
            }
            
            // Rebuild oath_date if date fields were modified
            if (isset($updatedData['oath_year'])) {
                $oathMonth = $updatedData['oath_month'] ?? '';
                $oathDay = $updatedData['oath_day'] ?? '';
                $oathYear = $updatedData['oath_year'];
                
                if (!empty($oathMonth) && !empty($oathDay) && !empty($oathYear)) {
                    $updatedData['oath_date'] = $oathYear . '-' . str_pad($oathMonth, 2, '0', STR_PAD_LEFT) . '-' . str_pad($oathDay, 2, '0', STR_PAD_LEFT);
                } elseif (!empty($oathYear)) {
                    $updatedData['oath_date'] = $oathYear . '-07-27';
                } else {
                    $updatedData['oath_date'] = '';
                }
            }
            
            // Update the pending action with modified data
            $stmt = $db->prepare("
                UPDATE pending_actions 
                SET action_data = ?
                WHERE action_id = ?
            ");
            $stmt->execute([json_encode($updatedData), $actionId]);
            
            $db->commit();
            $success = 'Pending action updated successfully! You can now approve it with the modified data.';
            
        } catch (Exception $e) {
            $db->rollBack();
            error_log("Update pending action error: " . $e->getMessage());
            $error = 'An error occurred while updating: ' . $e->getMessage();
        }
    }
}

// Get pending actions based on user role
if ($isLimitedUser) {
    // Limited users see only their own pending actions
    $stmt = $db->prepare("
        SELECT 
            pa.*,
            u.username as requester_username,
            u.full_name as requester_name,
            u.email as requester_email,
            u2.full_name as approver_name,
            o.last_name_encrypted,
            o.first_name_encrypted,
            o.middle_initial_encrypted,
            o.district_code as officer_district
        FROM pending_actions pa
        JOIN users u ON pa.requester_user_id = u.user_id
        LEFT JOIN users u2 ON pa.approver_user_id = u2.user_id
        LEFT JOIN officers o ON pa.officer_id = o.officer_id
        WHERE pa.requester_user_id = ? AND pa.status = 'pending'
        ORDER BY pa.created_at DESC
    ");
    $stmt->execute([$currentUser['user_id']]);
    $pendingActions = $stmt->fetchAll();
    
    // Get history of own reviewed actions
    $stmt = $db->prepare("
        SELECT 
            pa.*,
            u.username as requester_username,
            u.full_name as requester_name,
            u2.full_name as reviewer_name
        FROM pending_actions pa
        JOIN users u ON pa.requester_user_id = u.user_id
        LEFT JOIN users u2 ON pa.reviewed_by = u2.user_id
        WHERE pa.requester_user_id = ? AND pa.status != 'pending'
        ORDER BY pa.reviewed_at DESC
        LIMIT 50
    ");
    $stmt->execute([$currentUser['user_id']]);
    $reviewedActions = $stmt->fetchAll();
    
} else {
    // Senior approvers see actions they need to approve
    $stmt = $db->prepare("
        SELECT 
            pa.*,
            u.username as requester_username,
            u.full_name as requester_name,
            u.email as requester_email,
            o.last_name_encrypted,
            o.first_name_encrypted,
            o.middle_initial_encrypted,
            o.district_code as officer_district
        FROM pending_actions pa
        JOIN users u ON pa.requester_user_id = u.user_id
        LEFT JOIN officers o ON pa.officer_id = o.officer_id
        WHERE pa.approver_user_id = ? AND pa.status = 'pending'
        ORDER BY pa.created_at DESC
    ");
    $stmt->execute([$currentUser['user_id']]);
    $pendingActions = $stmt->fetchAll();

    // Get history of reviewed actions by this approver
    $stmt = $db->prepare("
        SELECT 
            pa.*,
            u.username as requester_username,
            u.full_name as requester_name
        FROM pending_actions pa
        JOIN users u ON pa.requester_user_id = u.user_id
        WHERE pa.approver_user_id = ? AND pa.status != 'pending'
        ORDER BY pa.reviewed_at DESC
        LIMIT 50
    ");
    $stmt->execute([$currentUser['user_id']]);
    $reviewedActions = $stmt->fetchAll();
}

/**
 * Format action data for display
 */
function formatActionDetails($actionType, $actionData, $db) {
    $html = '<div class="space-y-2 text-sm">';
    
    // Helper for consistent styling
    $labelClass = 'font-semibold text-gray-700 dark:text-gray-300';
    $valueClass = 'text-gray-900 dark:text-gray-100';
    
    switch ($actionType) {
        case 'add_officer':
        case 'add_cfo':
        case 'edit_cfo':
            $html .= '<div class="grid grid-cols-2 gap-2">';
            
            if (!empty($actionData['last_name']) || !empty($actionData['first_name'])) {
                $fullName = trim(($actionData['last_name'] ?? '') . ', ' . ($actionData['first_name'] ?? '') . ' ' . ($actionData['middle_initial'] ?? $actionData['middle_name'] ?? ''));
                $html .= '<div class="col-span-2"><span class="' . $labelClass . '">Name:</span> <span class="' . $valueClass . '">' . Security::escape($fullName) . '</span></div>';
            }
            
            if (!empty($actionData['department'])) {
                $html .= '<div class="col-span-2"><span class="' . $labelClass . '">Department:</span> <span class="' . $valueClass . '">' . Security::escape($actionData['department']) . '</span></div>';
            }
            
            if (!empty($actionData['duty'])) {
                $html .= '<div class="col-span-2"><span class="' . $labelClass . '">Duty:</span> <span class="' . $valueClass . '">' . Security::escape($actionData['duty']) . '</span></div>';
            }
            
            // CFO Classification
            if (!empty($actionData['cfo_classification'])) {
                $html .= '<div><span class="' . $labelClass . '">CFO Type:</span> <span class="' . $valueClass . '">' . Security::escape($actionData['cfo_classification']) . '</span></div>';
            }
            
            if (!empty($actionData['cfo_status'])) {
                $html .= '<div><span class="' . $labelClass . '">Status:</span> <span class="' . $valueClass . '">' . Security::escape($actionData['cfo_status']) . '</span></div>';
            }
            
            // District and Local
            if (!empty($actionData['district_code'])) {
                try {
                    $stmt = $db->prepare("SELECT district_name FROM districts WHERE district_code = ?");
                    $stmt->execute([$actionData['district_code']]);
                    $district = $stmt->fetch();
                    $districtName = $district ? $district['district_name'] : $actionData['district_code'];
                } catch (Exception $e) {
                    $districtName = $actionData['district_code'];
                }
                $html .= '<div><span class="' . $labelClass . '">District:</span> <span class="' . $valueClass . '">' . Security::escape($districtName) . '</span></div>';
            }
            
            if (!empty($actionData['local_code'])) {
                try {
                    $stmt = $db->prepare("SELECT local_name FROM local_congregations WHERE local_code = ?");
                    $stmt->execute([$actionData['local_code']]);
                    $local = $stmt->fetch();
                    $localName = $local ? $local['local_name'] : $actionData['local_code'];
                } catch (Exception $e) {
                    $localName = $actionData['local_code'];
                }
                $html .= '<div><span class="' . $labelClass . '">Local:</span> <span class="' . $valueClass . '">' . Security::escape($localName) . '</span></div>';
            }
            
            // Purok/Grupo
            if (!empty($actionData['purok'])) {
                $html .= '<div><span class="' . $labelClass . '">Purok:</span> <span class="' . $valueClass . '">' . Security::escape($actionData['purok']) . '</span></div>';
            }
            
            if (!empty($actionData['grupo'])) {
                $html .= '<div><span class="' . $labelClass . '">Grupo:</span> <span class="' . $valueClass . '">' . Security::escape($actionData['grupo']) . '</span></div>';
            }
            
            // Birthday
            if (!empty($actionData['birthday'])) {
                $html .= '<div><span class="' . $labelClass . '">Birthday:</span> <span class="' . $valueClass . '">' . Security::escape($actionData['birthday']) . '</span></div>';
            }
            
            // Registry Number
            if (!empty($actionData['registry_number'])) {
                $html .= '<div><span class="' . $labelClass . '">Registry #:</span> <span class="' . $valueClass . '">' . Security::escape($actionData['registry_number']) . '</span></div>';
            }
            
            // Oath Date
            if (!empty($actionData['oath_date'])) {
                $oathDate = date('F d, Y', strtotime($actionData['oath_date']));
                $html .= '<div class="col-span-2"><span class="' . $labelClass . '">Oath Date:</span> <span class="' . $valueClass . '">' . Security::escape($oathDate) . '</span></div>';
            }
            
            // Control Numbers
            if (!empty($actionData['control_number'])) {
                $html .= '<div><span class="' . $labelClass . '">Control #:</span> <span class="' . $valueClass . '">' . Security::escape($actionData['control_number']) . '</span></div>';
            }
            
            $html .= '</div>';
            break;
            
        case 'edit_officer':
            $html .= '<div class="grid grid-cols-2 gap-2">';
            
            foreach ($actionData as $key => $value) {
                if ($key === 'officer_uuid' || $key === 'has_existing_record' || empty($value)) continue;
                
                $label = ucwords(str_replace('_', ' ', $key));
                $html .= '<div><span class="' . $labelClass . '">' . Security::escape($label) . ':</span> <span class="' . $valueClass . '">' . Security::escape($value) . '</span></div>';
            }
            
            $html .= '</div>';
            break;
            
        case 'remove_officer':
            if (!empty($actionData['removal_code'])) {
                $html .= '<div><span class="' . $labelClass . '">Removal Code:</span> <span class="' . $valueClass . '">' . Security::escape($actionData['removal_code']) . '</span></div>';
            }
            if (!empty($actionData['removal_reason'])) {
                $html .= '<div><span class="' . $labelClass . '">Reason:</span> <span class="' . $valueClass . '">' . Security::escape($actionData['removal_reason']) . '</span></div>';
            }
            break;
            
        default:
            // For unknown action types, show formatted JSON
            $html .= '<div class="text-xs"><pre class="whitespace-pre-wrap text-gray-800 dark:text-gray-200">' . Security::escape(json_encode($actionData, JSON_PRETTY_PRINT)) . '</pre></div>';
            break;
    }
    
    $html .= '</div>';
    return $html;
}

$pageTitle = 'Pending Actions for Approval';
ob_start();
?>

<div class="container mx-auto px-4 py-6">
    <!-- Header -->
    <div class="mb-6">
        <h2 class="text-3xl font-bold text-gray-900 dark:text-gray-100">Pending Actions</h2>
        <p class="text-gray-600 dark:text-gray-400 mt-1">Review and approve actions from limited users</p>
    </div>

    <?php if ($error): ?>
        <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-3 rounded-lg mb-4">
            <?php echo Security::escape($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-300 px-4 py-3 rounded-lg mb-4">
            <?php echo Security::escape($success); ?>
        </div>
    <?php endif; ?>

    <!-- Info Banner -->
    <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4 mb-6">
        <div class="flex items-start">
            <svg class="w-5 h-5 text-blue-600 mr-3 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
            </svg>
            <div class="text-sm text-blue-800 dark:text-blue-200">
                <p class="font-semibold mb-1">About Pending Actions:</p>
                <?php if ($isSeniorApprover): ?>
                    <p>Local (Limited) users require your approval before their actions take effect. Review each action carefully before approving or rejecting.</p>
                <?php else: ?>
                    <p>Your actions require approval from your senior account before taking effect. You can track the status of your submissions here.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($isSeniorApprover): ?>
    <!-- Pending Access Requests Section -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 mb-6">
        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 flex items-center">
                <svg class="w-5 h-5 mr-2 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"></path>
                </svg>
                Registry Access Requests
                <span id="accessRequestsBadge" class="hidden ml-2 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400">0</span>
            </h3>
        </div>
        <div id="accessRequestsContainer" class="p-6">
            <div class="flex justify-center py-4">
                <svg class="animate-spin h-8 w-8 text-purple-600" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Pending Actions List -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 mb-6">
        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                <?php if ($isSeniorApprover): ?>
                    Awaiting Your Approval
                <?php else: ?>
                    My Pending Submissions
                <?php endif; ?>
                <?php if (count($pendingActions) > 0): ?>
                    <span class="ml-2 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                        <?php echo count($pendingActions); ?>
                    </span>
                <?php endif; ?>
            </h3>
        </div>

        <?php if (empty($pendingActions)): ?>
            <div class="px-6 py-12 text-center">
                <svg class="mx-auto h-12 w-12 text-gray-400 dark:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <h3 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">No pending actions</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    <?php if ($isSeniorApprover): ?>
                        All actions have been reviewed.
                    <?php else: ?>
                        You have no pending submissions.
                    <?php endif; ?>
                </p>
            </div>
        <?php else: ?>
            <div class="divide-y divide-gray-200 dark:divide-gray-700">
                <?php foreach ($pendingActions as $action): ?>
                    <?php 
                    $actionData = json_decode($action['action_data'], true);
                    $actionTypeLabels = [
                        'add_officer' => 'Add Officer',
                        'edit_officer' => 'Edit Officer',
                        'remove_officer' => 'Remove Officer',
                        'transfer_in' => 'Transfer In',
                        'transfer_out' => 'Transfer Out',
                        'bulk_update' => 'Bulk Update',
                        'add_request' => 'Add Request'
                    ];
                    $actionLabel = $actionTypeLabels[$action['action_type']] ?? ucfirst(str_replace('_', ' ', $action['action_type']));
                    
                    // Decrypt officer name if applicable
                    $officerName = '';
                    if ($action['officer_id'] && $action['officer_district']) {
                        try {
                            $decrypted = Encryption::decryptOfficerName(
                                $action['last_name_encrypted'],
                                $action['first_name_encrypted'],
                                $action['middle_initial_encrypted'],
                                $action['officer_district']
                            );
                            $officerName = $decrypted['last_name'] . ', ' . $decrypted['first_name'];
                            if (!empty($decrypted['middle_initial'])) {
                                $officerName .= ' ' . $decrypted['middle_initial'] . '.';
                            }
                        } catch (Exception $e) {
                            $officerName = '[Unable to decrypt]';
                        }
                    }
                    ?>
                    
                    <div class="px-6 py-5 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors border-l-4 border-yellow-400">
                        <div class="flex items-start justify-between">
                            <div class="flex items-start space-x-4 flex-1">
                                <!-- Status Icon -->
                                <div class="flex-shrink-0 mt-1">
                                    <div class="w-12 h-12 rounded-full bg-yellow-50 dark:bg-yellow-900/30 flex items-center justify-center">
                                        <svg class="w-6 h-6 text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 10h4.764a2 2 0 011.789 2.894l-3.5 7A2 2 0 0115.263 21h-4.017c-.163 0-.326-.02-.485-.06L7 20m7-10V5a2 2 0 00-2-2h-.095c-.5 0-.905.405-.905.905 0 .714-.211 1.412-.608 2.006L7 11v9m7-10h-2M7 20H5a2 2 0 01-2-2v-6a2 2 0 012-2h2.5"></path>
                                        </svg>
                                    </div>
                                </div>
                                
                                <!-- Content -->
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center space-x-2 mb-2">
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-gradient-to-r from-purple-100 to-purple-200 dark:from-purple-900/50 dark:to-purple-800/50 text-purple-800 dark:text-purple-300 shadow-sm">
                                            <?php echo Security::escape($actionLabel); ?>
                                        </span>
                                        <span class="text-xs text-gray-500 dark:text-gray-400 font-medium">
                                            🕒 <?php echo date('M d, Y g:i A', strtotime($action['created_at'])); ?>
                                        </span>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <p class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-1">
                                            <?php echo Security::escape($action['action_description']); ?>
                                        </p>
                                        <?php if ($officerName): ?>
                                            <p class="text-sm text-gray-600 dark:text-gray-400 flex items-center">
                                                <svg class="w-4 h-4 mr-1 text-gray-400 dark:text-gray-500" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"/>
                                                </svg>
                                                <span class="font-medium text-gray-800 dark:text-gray-200"><?php echo Security::escape($officerName); ?></span>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="text-xs text-gray-500 dark:text-gray-400 flex items-center mb-3">
                                        <svg class="w-4 h-4 mr-1 text-gray-400 dark:text-gray-500" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-6-3a2 2 0 11-4 0 2 2 0 014 0zm-2 4a5 5 0 00-4.546 2.916A5.986 5.986 0 0010 16a5.986 5.986 0 004.546-2.084A5 5 0 0010 11z" clip-rule="evenodd"/>
                                        </svg>
                                    <?php if ($isSeniorApprover): ?>
                                        <span class="font-medium">Requested by:</span>&nbsp;
                                        <?php echo Security::escape($action['requester_name']); ?> 
                                        <span class="text-gray-400">(@<?php echo Security::escape($action['requester_username']); ?>)</span>
                                    <?php else: ?>
                                        <span class="font-medium">Assigned to:</span>&nbsp;<?php echo Security::escape($action['approver_name']); ?>
                                    <?php endif; ?>
                                    </div>
                                    
                                    <!-- Action Details -->
                                    <details class="mt-3">
                                        <summary class="text-xs text-blue-600 dark:text-blue-400 cursor-pointer hover:text-blue-800 dark:hover:text-blue-300 font-medium flex items-center">
                                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                            </svg>
                                            View Full Details
                                        </summary>
                                        <div class="mt-2 p-4 bg-gradient-to-br from-gray-50 to-gray-100 dark:from-gray-700 dark:to-gray-800 rounded-lg border border-gray-200 dark:border-gray-600 shadow-sm">
                                            <?php echo formatActionDetails($action['action_type'], $actionData, $db); ?>
                                        </div>
                                    </details>
                                </div>
                            </div>
                            
                            <?php if ($isSeniorApprover): ?>
                                <div class="ml-4 flex flex-col space-y-2 min-w-[120px]">
                                    <!-- Edit Button -->
                                    <button 
                                        type="button"
                                        onclick="openEditModal(<?php echo $action['action_id']; ?>, '<?php echo Security::escape($action['action_type']); ?>', <?php echo htmlspecialchars(json_encode($actionData), ENT_QUOTES, 'UTF-8'); ?>)"
                                        class="inline-flex items-center justify-center px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition-all shadow-sm hover:shadow-md">
                                        <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                        </svg>
                                        Edit
                                    </button>
                                    
                                    <!-- Approve Button -->
                                    <form method="POST" action="" class="inline" onsubmit="return confirm('Are you sure you want to approve this action? This cannot be undone.');">
                                        <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                                        <input type="hidden" name="action_id" value="<?php echo $action['action_id']; ?>">
                                        <input type="hidden" name="decision" value="approve">
                                        <button type="submit" class="w-full inline-flex items-center justify-center px-4 py-2 bg-green-600 text-white text-sm font-medium rounded-lg hover:bg-green-700 transition-all shadow-sm hover:shadow-md">
                                            <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 10h4.764a2 2 0 011.789 2.894l-3.5 7A2 2 0 0115.263 21h-4.017c-.163 0-.326-.02-.485-.06L7 20m7-10V5a2 2 0 00-2-2h-.095c-.5 0-.905.405-.905.905 0 .714-.211 1.412-.608 2.006L7 11v9m7-10h-2M7 20H5a2 2 0 01-2-2v-6a2 2 0 012-2h2.5"></path>
                                            </svg>
                                            Approve
                                        </button>
                                    </form>
                                    
                                    <!-- Reject Button -->
                                    <button 
                                        type="button"
                                        onclick="openRejectModal(<?php echo $action['action_id']; ?>, <?php echo htmlspecialchars(json_encode($action['action_description']), ENT_QUOTES, 'UTF-8'); ?>)"
                                        class="inline-flex items-center justify-center px-4 py-2 bg-red-600 text-white text-sm font-medium rounded-lg hover:bg-red-700 transition-all shadow-sm hover:shadow-md">
                                        <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                        </svg>
                                        Reject
                                    </button>
                                    
                                    <!-- Forward Button -->
                                    <button 
                                        type="button"
                                        onclick="openForwardModal(<?php echo $action['action_id']; ?>, <?php echo htmlspecialchars(json_encode($action['action_description']), ENT_QUOTES, 'UTF-8'); ?>)"
                                        class="inline-flex items-center justify-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition-all shadow-sm hover:shadow-md">
                                        <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                                        </svg>
                                        Forward
                                    </button>
                                </div>
                            <?php else: ?>
                                <div class="ml-4 min-w-[140px]">
                                    <div class="inline-flex items-center px-4 py-2 bg-yellow-50 dark:bg-yellow-900/30 border border-yellow-200 dark:border-yellow-700 text-yellow-700 dark:text-yellow-300 text-sm font-medium rounded-lg shadow-sm">
                                        <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/>
                                        </svg>
                                        Awaiting Approval
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- History -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700">
        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                <?php if ($isSeniorApprover): ?>
                    Recent History (Your Reviews)
                <?php else: ?>
                    Recent History (My Submissions)
                <?php endif; ?>
            </h3>
        </div>

        <?php if (empty($reviewedActions)): ?>
            <div class="px-6 py-8 text-center text-gray-500 dark:text-gray-400 text-sm">
                No reviewed actions yet.
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Action</th>
                            <?php if ($isSeniorApprover): ?>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Requester</th>
                            <?php else: ?>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Reviewed By</th>
                            <?php endif; ?>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Decision</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Date</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        <?php foreach ($reviewedActions as $action): ?>
                            <?php
                            $actionTypeLabels = [
                                'add_officer' => 'Add Officer',
                                'edit_officer' => 'Edit Officer',
                                'remove_officer' => 'Remove Officer',
                                'transfer_in' => 'Transfer In',
                                'transfer_out' => 'Transfer Out',
                                'bulk_update' => 'Bulk Update',
                                'add_request' => 'Add Request'
                            ];
                            $actionLabel = $actionTypeLabels[$action['action_type']] ?? ucfirst(str_replace('_', ' ', $action['action_type']));
                            ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900 dark:text-gray-100"><?php echo Security::escape($actionLabel); ?></div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400"><?php echo Security::escape(substr($action['action_description'], 0, 60)) . (strlen($action['action_description']) > 60 ? '...' : ''); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                    <?php if ($isSeniorApprover): ?>
                                        <?php echo Security::escape($action['requester_name']); ?>
                                    <?php else: ?>
                                        <?php echo Security::escape($action['reviewer_name'] ?? 'N/A'); ?>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php if ($action['status'] === 'approved'): ?>
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-green-50 dark:bg-green-900/30 text-green-700 dark:text-green-300 border border-green-200 dark:border-green-700 shadow-sm">
                                            <svg class="w-4 h-4 mr-1.5 text-green-600 dark:text-green-400" fill="currentColor" viewBox="0 0 20 20">
                                                <path d="M2 10.5a1.5 1.5 0 113 0v6a1.5 1.5 0 01-3 0v-6zM6 10.333v5.43a2 2 0 001.106 1.79l.05.025A4 4 0 008.943 18h5.416a2 2 0 001.962-1.608l1.2-6A2 2 0 0015.56 8H12V4a2 2 0 00-2-2 1 1 0 00-1 1v.667a4 4 0 01-.8 2.4L6.8 7.933a4 4 0 00-.8 2.4z"/>
                                            </svg>
                                            Approved
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-300 border border-red-200 dark:border-red-700 shadow-sm">
                                            <svg class="w-4 h-4 mr-1.5 text-red-600 dark:text-red-400" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                                            </svg>
                                            Rejected
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                    <?php echo date('M d, Y g:i A', strtotime($action['reviewed_at'])); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex items-center justify-center min-h-screen px-4 py-8">
        <div onclick="closeEditModal()" class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity"></div>
        <div onclick="event.stopPropagation()" class="relative bg-white dark:bg-gray-800 rounded-lg shadow-xl w-full max-w-2xl p-6 max-h-[90vh] overflow-y-auto">
            <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100 mb-4">Edit Pending Action</h3>
            
            <form method="POST" action="" id="editActionForm">
                <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                <input type="hidden" name="action_id" id="editActionId" value="">
                <input type="hidden" name="update_action" value="1">
                
                <div id="editFormFields" class="space-y-4 mb-6">
                    <!-- Dynamic form fields will be inserted here -->
                </div>
                
                <div class="flex items-center justify-end space-x-3 pt-4 border-t border-gray-200 dark:border-gray-700">
                    <button type="button" onclick="closeEditModal()" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div id="rejectModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex items-center justify-center min-h-screen px-4">
        <div onclick="closeRejectModal()" class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity"></div>
        <div onclick="event.stopPropagation()" class="relative bg-white dark:bg-gray-800 rounded-lg shadow-xl w-full max-w-md p-6">
            <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100 mb-4">Reject Action</h3>
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-4" id="rejectActionDescription"></p>
            
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                <input type="hidden" name="action_id" id="rejectActionId" value="">
                <input type="hidden" name="decision" value="reject">
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Reason for Rejection *</label>
                    <textarea 
                        name="rejection_reason" 
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100" 
                        rows="4" 
                        required
                        placeholder="Explain why this action is being rejected..."></textarea>
                </div>
                
                <div class="flex items-center justify-end space-x-3">
                    <button type="button" onclick="closeRejectModal()" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">Reject Action</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Forward Modal -->
<div id="forwardModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex items-center justify-center min-h-screen px-4">
        <div onclick="closeForwardModal()" class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity"></div>
        <div onclick="event.stopPropagation()" class="relative bg-white dark:bg-gray-800 rounded-lg shadow-xl w-full max-w-md p-6">
            <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100 mb-4">
                <svg class="w-5 h-5 inline-block mr-2 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                </svg>
                Forward to Another Approver
            </h3>
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-4" id="forwardActionDescription"></p>
            
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                <input type="hidden" name="action_id" id="forwardActionId" value="">
                <input type="hidden" name="decision" value="forward">
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Select Approver *</label>
                    <select 
                        name="forward_to_user_id" 
                        id="forwardToUserId"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100" 
                        required>
                        <option value="">-- Select a local account --</option>
                        <?php
                        // Get all local accounts (excluding current user)
                        $stmt = $db->prepare("
                            SELECT u.user_id, u.full_name, u.username, l.local_name 
                            FROM users u 
                            LEFT JOIN local_congregations l ON u.local_code = l.local_code
                            WHERE u.role = 'local' AND u.is_active = 1 AND u.user_id != ?
                            ORDER BY l.local_name, u.full_name
                        ");
                        $stmt->execute([$currentUser['user_id']]);
                        $localAccounts = $stmt->fetchAll();
                        foreach ($localAccounts as $account): ?>
                            <option value="<?php echo $account['user_id']; ?>">
                                <?php echo Security::escape($account['full_name']); ?> 
                                (<?php echo Security::escape($account['local_name'] ?? $account['username']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Notes (Optional)</label>
                    <textarea 
                        name="forward_notes" 
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100" 
                        rows="3" 
                        placeholder="Add any notes for the new approver..."></textarea>
                </div>
                
                <div class="bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-200 dark:border-indigo-700 rounded-lg p-3 mb-4">
                    <p class="text-sm text-indigo-700 dark:text-indigo-300">
                        <strong>Note:</strong> The selected user will receive this pending action for their review. They will be able to approve, reject, or forward it further.
                    </p>
                </div>
                
                <div class="flex items-center justify-end space-x-3">
                    <button type="button" onclick="closeForwardModal()" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors">
                        <svg class="w-4 h-4 inline-block mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                        </svg>
                        Forward
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openRejectModal(actionId, description) {
    document.getElementById('rejectActionId').value = actionId;
    document.getElementById('rejectActionDescription').textContent = description;
    document.getElementById('rejectModal').classList.remove('hidden');
}

function closeRejectModal() {
    document.getElementById('rejectModal').classList.add('hidden');
}

function openForwardModal(actionId, description) {
    document.getElementById('forwardActionId').value = actionId;
    document.getElementById('forwardActionDescription').textContent = description;
    document.getElementById('forwardToUserId').value = ''; // Reset selection
    document.getElementById('forwardModal').classList.remove('hidden');
}

function closeForwardModal() {
    document.getElementById('forwardModal').classList.add('hidden');
}

function openEditModal(actionId, actionType, actionData) {
    document.getElementById('editActionId').value = actionId;
    
    // Build form fields based on action type
    const formFields = document.getElementById('editFormFields');
    formFields.innerHTML = '';
    
    if (actionType === 'add_officer') {
        formFields.innerHTML = `
            <div class="grid grid-cols-2 gap-4">
                <div class="col-span-2">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Last Name *</label>
                    <input type="text" name="last_name" value="${escapeHtml(actionData.last_name || '')}" 
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100" required>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">First Name *</label>
                    <input type="text" name="first_name" value="${escapeHtml(actionData.first_name || '')}" 
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100" required>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Middle Initial</label>
                    <input type="text" name="middle_initial" value="${escapeHtml(actionData.middle_initial || '')}" 
                           maxlength="2" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Department *</label>
                    <input type="text" name="department" value="${escapeHtml(actionData.department || '')}" 
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100" required>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Duty</label>
                    <input type="text" name="duty" value="${escapeHtml(actionData.duty || '')}" 
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">District Code *</label>
                    <input type="text" name="district_code" value="${escapeHtml(actionData.district_code || '')}" 
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-gray-100 dark:bg-gray-600 text-gray-900 dark:text-gray-100" required readonly>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Local Code *</label>
                    <input type="text" name="local_code" value="${escapeHtml(actionData.local_code || '')}" 
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-gray-100 dark:bg-gray-600 text-gray-900 dark:text-gray-100" required readonly>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Purok</label>
                    <input type="text" name="purok" value="${escapeHtml(actionData.purok || '')}" 
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Grupo</label>
                    <input type="text" name="grupo" value="${escapeHtml(actionData.grupo || '')}" 
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Oath Year *</label>
                    <input type="number" name="oath_year" value="${escapeHtml(actionData.oath_year || '')}" 
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100" required>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Oath Month</label>
                    <input type="number" name="oath_month" value="${escapeHtml(actionData.oath_month || '')}" 
                           min="1" max="12" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Oath Day</label>
                    <input type="number" name="oath_day" value="${escapeHtml(actionData.oath_day || '')}" 
                           min="1" max="31" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Control Number</label>
                    <input type="text" name="control_number" value="${escapeHtml(actionData.control_number || '')}" 
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Registry Number</label>
                    <input type="text" name="registry_number" value="${escapeHtml(actionData.registry_number || '')}" 
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                </div>
            </div>
        `;
    } else {
        // Generic editor for other action types
        formFields.innerHTML = '<div class="text-sm text-gray-600 dark:text-gray-400">Edit functionality for this action type is not yet available.</div>';
    }
    
    document.getElementById('editModal').classList.remove('hidden');
}

function closeEditModal() {
    document.getElementById('editModal').classList.add('hidden');
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Close modals with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeRejectModal();
        closeEditModal();
        closeForwardModal();
        closeAccessRequestRejectModal();
    }
});

// Load pending access requests on page load (for senior approvers)
<?php if ($isSeniorApprover): ?>
document.addEventListener('DOMContentLoaded', function() {
    loadPendingAccessRequests();
});

async function loadPendingAccessRequests() {
    const container = document.getElementById('accessRequestsContainer');
    const badge = document.getElementById('accessRequestsBadge');
    
    try {
        const response = await fetch('api/get-pending-access-requests.php');
        const data = await response.json();
        
        if (data.success) {
            if (data.requests.length === 0) {
                container.innerHTML = `
                    <div class="text-center py-8">
                        <svg class="mx-auto h-12 w-12 text-gray-400 dark:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <h3 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">No pending access requests</h3>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">All access requests have been reviewed.</p>
                    </div>
                `;
                badge.classList.add('hidden');
            } else {
                badge.textContent = data.requests.length;
                badge.classList.remove('hidden');
                
                let html = '<div class="divide-y divide-gray-200 dark:divide-gray-700">';
                
                data.requests.forEach(req => {
                    const registryColors = {
                        'hdb': 'blue',
                        'pnk': 'purple',
                        'cfo': 'green'
                    };
                    const color = registryColors[req.registry_type] || 'gray';
                    const registryLabel = req.registry_type.toUpperCase();
                    
                    html += `
                        <div class="py-4 flex items-center justify-between">
                            <div class="flex items-center space-x-4">
                                <div class="flex-shrink-0">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-${color}-100 text-${color}-800 dark:bg-${color}-900/30 dark:text-${color}-400">
                                        ${registryLabel}
                                    </span>
                                </div>
                                <div>
                                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100">${escapeHtml(req.requester_name)}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">
                                        ${escapeHtml(req.access_level)}
                                        ${req.cfo_type ? ' • ' + escapeHtml(req.cfo_type) : ''}
                                        ${req.dako_name ? ' • Dako: ' + escapeHtml(req.dako_name) : ''}
                                    </p>
                                </div>
                            </div>
                            <div class="flex items-center space-x-2">
                                <!-- Stepper Status -->
                                <div class="flex items-center text-xs mr-4">
                                    <span class="w-2 h-2 rounded-full bg-${color}-600 mr-1"></span>
                                    <span class="text-gray-500 dark:text-gray-400">${req.verification_status === 'submitted' ? 'Submitted' : 'Pending'}</span>
                                </div>
                                <button onclick="approveAccessRequest('${req.registry_type}', ${req.id})" 
                                        class="px-3 py-1.5 bg-green-600 text-white text-xs rounded-lg hover:bg-green-700 transition-colors">
                                    Approve
                                </button>
                                <button onclick="openAccessRequestRejectModal('${req.registry_type}', ${req.id})" 
                                        class="px-3 py-1.5 bg-red-600 text-white text-xs rounded-lg hover:bg-red-700 transition-colors">
                                    Reject
                                </button>
                            </div>
                        </div>
                    `;
                });
                
                html += '</div>';
                container.innerHTML = html;
            }
        } else {
            throw new Error(data.error || 'Failed to load');
        }
    } catch (error) {
        container.innerHTML = `
            <div class="text-center py-8 text-red-600 dark:text-red-400">
                <p>Error loading access requests: ${escapeHtml(error.message)}</p>
                <button onclick="loadPendingAccessRequests()" class="mt-2 text-sm text-blue-600 hover:text-blue-700">Try again</button>
            </div>
        `;
    }
}

async function approveAccessRequest(registryType, requestId) {
    if (!confirm('Are you sure you want to approve this access request?')) return;
    
    try {
        const response = await fetch('api/approve-access-request.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                registry_type: registryType,
                request_id: requestId,
                action: 'approve'
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert('✅ Access request approved!');
            loadPendingAccessRequests();
        } else {
            throw new Error(data.error || 'Failed to approve');
        }
    } catch (error) {
        alert('❌ Error: ' + error.message);
    }
}

let currentAccessRejectData = null;

function openAccessRequestRejectModal(registryType, requestId) {
    currentAccessRejectData = { registryType, requestId };
    document.getElementById('accessRejectModal').classList.remove('hidden');
    document.getElementById('accessRejectReason').value = '';
    document.getElementById('accessRejectReason').focus();
}

function closeAccessRequestRejectModal() {
    document.getElementById('accessRejectModal').classList.add('hidden');
    currentAccessRejectData = null;
}

async function submitAccessReject() {
    if (!currentAccessRejectData) return;
    
    const reason = document.getElementById('accessRejectReason').value.trim();
    if (!reason) {
        alert('Please provide a rejection reason.');
        return;
    }
    
    try {
        const response = await fetch('api/approve-access-request.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                registry_type: currentAccessRejectData.registryType,
                request_id: currentAccessRejectData.requestId,
                action: 'reject',
                notes: reason
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert('✅ Access request rejected.');
            closeAccessRequestRejectModal();
            loadPendingAccessRequests();
        } else {
            throw new Error(data.error || 'Failed to reject');
        }
    } catch (error) {
        alert('❌ Error: ' + error.message);
    }
}
<?php endif; ?>
</script>

<!-- Access Request Reject Modal -->
<?php if ($isSeniorApprover): ?>
<div id="accessRejectModal" class="hidden fixed inset-0 bg-gray-900 bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-lg max-w-md w-full p-6">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Reject Access Request</h3>
        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Rejection Reason</label>
            <textarea id="accessRejectReason" rows="3" required
                      class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-red-500"
                      placeholder="Explain why this request is being rejected..."></textarea>
        </div>
        <div class="flex gap-2">
            <button onclick="submitAccessReject()" class="flex-1 px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">
                Reject Request
            </button>
            <button onclick="closeAccessRequestRejectModal()" class="flex-1 px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors">
                Cancel
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/includes/layout.php';

/**
 * Execute an approved action
 */
function executeApprovedAction($actionType, $actionData, $pendingAction, $db) {
    switch ($actionType) {
        case 'add_officer':
            return executeAddOfficer($actionData, $db);
        case 'edit_officer':
            return executeEditOfficer($actionData, $pendingAction['officer_id'], $db);
        case 'remove_officer':
            return executeRemoveOfficer($actionData, $pendingAction['officer_id'], $db);
        case 'transfer_in':
            return executeTransferIn($actionData, $db);
        case 'transfer_out':
            return executeTransferOut($actionData, $db);
        case 'add_cfo':
            return executeAddCfo($actionData, $db);
        case 'edit_cfo':
            return executeEditCfo($actionData, $pendingAction['target_record_id'], $db);
        case 'add_hdb':
            return executeAddHdb($actionData, $db);
        case 'edit_hdb':
            return executeEditHdb($actionData, $pendingAction['target_record_id'], $db);
        case 'add_pnk':
            return executeAddPnk($actionData, $db);
        case 'edit_pnk':
            return executeEditPnk($actionData, $pendingAction['target_record_id'], $db);
        default:
            throw new Exception('Unknown action type: ' . $actionType);
    }
}

/**
 * Execute add officer action
 */
function executeAddOfficer($data, $db) {
    // Get current user for audit
    $currentUser = getCurrentUser();
    
    // Extract data
    $hasExistingRecord = $data['has_existing_record'] ?? false;
    $existingOfficerUuid = $data['existing_officer_uuid'] ?? '';
    $existingOfficerIdInput = $data['existing_officer_id'] ?? '';
    $lastName = $data['last_name'] ?? '';
    $firstName = $data['first_name'] ?? '';
    $middleInitial = $data['middle_initial'] ?? '';
    $districtCode = $data['district_code'] ?? '';
    $localCode = $data['local_code'] ?? '';
    $purok = $data['purok'] ?? '';
    $grupo = $data['grupo'] ?? '';
    $controlNumber = $data['control_number'] ?? '';
    $registryNumber = $data['registry_number'] ?? '';
    $tarhetaControlId = !empty($data['tarheta_control_id']) ? (int)$data['tarheta_control_id'] : null;
    $legacyOfficerId = !empty($data['legacy_officer_id']) ? (int)$data['legacy_officer_id'] : null;
    $department = $data['department'] ?? '';
    $duty = $data['duty'] ?? '';
    $oathDate = $data['oath_date'] ?? '';
    
    // Auto-detect if officer already exists
    $existingOfficerId = null;
    $existingOfficerUuid = null;
    
    if ($hasExistingRecord && !empty($existingOfficerIdInput)) {
        // User explicitly selected existing officer (by ID)
        $stmt = $db->prepare("SELECT officer_id, officer_uuid FROM officers WHERE officer_id = ?");
        $stmt->execute([$existingOfficerIdInput]);
        $officer = $stmt->fetch();
        
        if ($officer) {
            $existingOfficerId = $officer['officer_id'];
            $existingOfficerUuid = $officer['officer_uuid'];
        }
    } elseif ($hasExistingRecord && !empty($existingOfficerUuid)) {
        // Fallback: try by UUID
        $stmt = $db->prepare("SELECT officer_id, officer_uuid FROM officers WHERE officer_uuid = ?");
        $stmt->execute([$existingOfficerUuid]);
        $officer = $stmt->fetch();
        
        if ($officer) {
            $existingOfficerId = $officer['officer_id'];
            $existingOfficerUuid = $officer['officer_uuid'];
        }
    } elseif (!$hasExistingRecord && !empty($lastName) && !empty($firstName)) {
        // Auto-detect: Search for existing officer with same name
        $stmt = $db->prepare("
            SELECT officer_id, officer_uuid, last_name_encrypted, first_name_encrypted, 
                   middle_initial_encrypted, district_code, is_active
            FROM officers 
            WHERE district_code = ?
        ");
        $stmt->execute([$districtCode]);
        $allOfficers = $stmt->fetchAll();
        
        foreach ($allOfficers as $officer) {
            try {
                $decrypted = Encryption::decryptOfficerName(
                    $officer['last_name_encrypted'],
                    $officer['first_name_encrypted'],
                    $officer['middle_initial_encrypted'],
                    $officer['district_code']
                );
                
                $lastNameMatch = strcasecmp(trim($decrypted['last_name']), trim($lastName)) === 0;
                $firstNameMatch = strcasecmp(trim($decrypted['first_name']), trim($firstName)) === 0;
                
                $middleInitialMatch = true;
                if (!empty($middleInitial) && !empty($decrypted['middle_initial'])) {
                    $middleInitialMatch = strcasecmp(trim($decrypted['middle_initial']), trim($middleInitial)) === 0;
                }
                
                if ($lastNameMatch && $firstNameMatch && $middleInitialMatch) {
                    $existingOfficerId = $officer['officer_id'];
                    $existingOfficerUuid = $officer['officer_uuid'];
                    break;
                }
            } catch (Exception $e) {
                continue;
            }
        }
    }
    
    $recordCode = ($existingOfficerId !== null) ? 'D' : 'A';
    
    if ($existingOfficerId !== null) {
        // CODE D: Use existing officer
        $officerId = $existingOfficerId;
        $officerUuid = $existingOfficerUuid;
        
        $stmt = $db->prepare("UPDATE officers SET is_active = 1, local_code = ?, district_code = ? WHERE officer_id = ?");
        $stmt->execute([$localCode, $districtCode, $officerId]);
    } else {
        // CODE A: Create new officer
        $officerUuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
        
        $encrypted = Encryption::encryptOfficerName($lastName, $firstName, $middleInitial, $districtCode);
        $registryNumberEnc = !empty($registryNumber) ? Encryption::encrypt($registryNumber, $districtCode) : null;
        $controlNumberEnc = !empty($controlNumber) ? Encryption::encrypt($controlNumber, $districtCode) : null;
        
        $stmt = $db->prepare("
            INSERT INTO officers (
                officer_uuid, last_name_encrypted, first_name_encrypted, middle_initial_encrypted,
                district_code, local_code, purok, grupo, control_number, control_number_encrypted,
                registry_number_encrypted, tarheta_control_id, legacy_officer_id, record_code,
                is_active, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
        ");
        
        $stmt->execute([
            $officerUuid, $encrypted['last_name_encrypted'], $encrypted['first_name_encrypted'],
            $encrypted['middle_initial_encrypted'], $districtCode, $localCode,
            !empty($purok) ? $purok : null, !empty($grupo) ? $grupo : null,
            !empty($controlNumber) ? $controlNumber : null, $controlNumberEnc,
            $registryNumberEnc, $tarhetaControlId, $legacyOfficerId, $recordCode,
            $currentUser['user_id']
        ]);
        
        $officerId = $db->lastInsertId();
        
        if ($tarhetaControlId) {
            $stmt = $db->prepare("UPDATE tarheta_control SET linked_officer_id = ?, linked_at = NOW(), linked_by = ? WHERE id = ?");
            $stmt->execute([$officerId, $currentUser['user_id'], $tarhetaControlId]);
        }
        
        if ($legacyOfficerId) {
            $stmt = $db->prepare("UPDATE legacy_officers SET linked_officer_id = ?, linked_at = NOW() WHERE id = ?");
            $stmt->execute([$officerId, $legacyOfficerId]);
        }
    }
    
    // Add department
    $stmt = $db->prepare("INSERT INTO officer_departments (officer_id, department, duty, oath_date, is_active) VALUES (?, ?, ?, ?, 1)");
    $stmt->execute([$officerId, $department, $duty, $oathDate]);
    
    // Update headcount only if CODE A
    if ($recordCode === 'A') {
        $stmt = $db->prepare("INSERT INTO headcount (district_code, local_code, total_count) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE total_count = total_count + 1");
        $stmt->execute([$districtCode, $localCode]);
    }
    
    // Log audit
    $stmt = $db->prepare("INSERT INTO audit_log (user_id, action, table_name, record_id, new_values, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $currentUser['user_id'], 'add_officer', 'officers', $officerId,
        json_encode(['record_code' => $recordCode, 'department' => $department, 'local_code' => $localCode]),
        $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] ?? ''
    ]);
    
    return true;
}

/**
 * Execute edit officer action
 */
function executeEditOfficer($data, $officerId, $db) {
    $currentUser = getCurrentUser();
    
    // Extract data
    $lastName = $data['last_name'] ?? '';
    $firstName = $data['first_name'] ?? '';
    $middleInitial = $data['middle_initial'] ?? '';
    $districtCode = $data['district_code'] ?? '';
    $localCode = $data['local_code'] ?? '';
    $purok = $data['purok'] ?? '';
    $grupo = $data['grupo'] ?? '';
    $controlNumber = $data['control_number'] ?? '';
    $registryNumber = $data['registry_number'] ?? '';
    $tarhetaControlId = !empty($data['tarheta_control_id']) ? (int)$data['tarheta_control_id'] : null;
    $legacyOfficerId = !empty($data['legacy_officer_id']) ? (int)$data['legacy_officer_id'] : null;
    $isActive = isset($data['is_active']) ? (int)$data['is_active'] : 1;
    
    // Re-encrypt officer name
    $encrypted = Encryption::encryptOfficerName($lastName, $firstName, $middleInitial, $districtCode);
    $registryNumberEnc = !empty($registryNumber) ? Encryption::encrypt($registryNumber, $districtCode) : null;
    $controlNumberEnc = !empty($controlNumber) ? Encryption::encrypt($controlNumber, $districtCode) : null;
    
    // Update officer
    $stmt = $db->prepare("
        UPDATE officers SET
            last_name_encrypted = ?, first_name_encrypted = ?, middle_initial_encrypted = ?,
            district_code = ?, local_code = ?, purok = ?, grupo = ?,
            control_number = ?, control_number_encrypted = ?, registry_number_encrypted = ?,
            tarheta_control_id = ?, legacy_officer_id = ?, is_active = ?
        WHERE officer_id = ?
    ");
    
    $stmt->execute([
        $encrypted['last_name_encrypted'], $encrypted['first_name_encrypted'], $encrypted['middle_initial_encrypted'],
        $districtCode, $localCode, !empty($purok) ? $purok : null, !empty($grupo) ? $grupo : null,
        !empty($controlNumber) ? $controlNumber : null, $controlNumberEnc, $registryNumberEnc,
        $tarhetaControlId, $legacyOfficerId, $isActive, $officerId
    ]);
    
    if ($tarhetaControlId) {
        $stmt = $db->prepare("UPDATE tarheta_control SET linked_officer_id = ?, linked_at = NOW(), linked_by = ? WHERE id = ?");
        $stmt->execute([$officerId, $currentUser['user_id'], $tarhetaControlId]);
    }
    
    if ($legacyOfficerId) {
        $stmt = $db->prepare("UPDATE legacy_officers SET linked_officer_id = ?, linked_at = NOW() WHERE id = ?");
        $stmt->execute([$officerId, $legacyOfficerId]);
    }
    
    // Log audit
    $stmt = $db->prepare("INSERT INTO audit_log (user_id, action, table_name, record_id, new_values, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $currentUser['user_id'], 'edit_officer', 'officers', $officerId,
        json_encode(['local_code' => $localCode, 'is_active' => $isActive]),
        $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] ?? ''
    ]);
    
    return true;
}

/**
 * Execute remove officer action
 */
function executeRemoveOfficer($data, $officerId, $db) {
    $currentUser = getCurrentUser();
    
    // Get officer info
    $stmt = $db->prepare("SELECT * FROM officers WHERE officer_id = ?");
    $stmt->execute([$officerId]);
    $officer = $stmt->fetch();
    
    if (!$officer) {
        throw new Exception('Officer not found');
    }
    
    $removalCode = $data['removal_code'] ?? '';
    $removalDate = $data['removal_date'] ?? date('Y-m-d');
    $reason = $data['reason'] ?? '';
    
    // Get week info
    $weekInfo = getWeekDateRange(null, date('Y', strtotime($removalDate)));
    
    // CODE D (Lipat Kapisanan) - Process immediately
    if ($removalCode === 'D') {
        $stmt = $db->prepare("
            INSERT INTO officer_removals (
                officer_id, removal_code, removal_date, week_number, year, reason, 
                status, processed_by, completed_at
            ) VALUES (?, ?, ?, ?, ?, ?, 'approved_by_district', ?, NOW())
        ");
        
        $stmt->execute([
            $officerId, $removalCode, $removalDate, $weekInfo['week'], $weekInfo['year'],
            $reason, $currentUser['user_id']
        ]);
        
        $removalId = $db->lastInsertId();
        
        // Deactivate officer
        $stmt = $db->prepare("UPDATE officers SET is_active = 0 WHERE officer_id = ?");
        $stmt->execute([$officerId]);
        
        // Deactivate all departments
        $stmt = $db->prepare("UPDATE officer_departments SET is_active = 0, removed_at = NOW() WHERE officer_id = ?");
        $stmt->execute([$officerId]);
        
        // Update headcount (-1)
        $stmt = $db->prepare("UPDATE headcount SET total_count = GREATEST(0, total_count - 1) WHERE district_code = ? AND local_code = ?");
        $stmt->execute([$officer['district_code'], $officer['local_code']]);
        
        // Log audit
        $stmt = $db->prepare("INSERT INTO audit_log (user_id, action, table_name, record_id, new_values, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $currentUser['user_id'], 'remove_officer_code_d', 'officer_removals', $removalId,
            json_encode(['removal_code' => $removalCode, 'reason' => $reason]),
            $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
    } else {
        // Other removal codes - create removal request for deliberation
        $stmt = $db->prepare("
            INSERT INTO officer_removals (
                officer_id, removal_code, removal_date, week_number, year, reason, 
                status, requested_by
            ) VALUES (?, ?, ?, ?, ?, ?, 'pending', ?)
        ");
        
        $stmt->execute([
            $officerId, $removalCode, $removalDate, $weekInfo['week'], $weekInfo['year'],
            $reason, $currentUser['user_id']
        ]);
        
        $removalId = $db->lastInsertId();
        
        // Log audit
        $stmt = $db->prepare("INSERT INTO audit_log (user_id, action, table_name, record_id, new_values, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $currentUser['user_id'], 'request_officer_removal', 'officer_removals', $removalId,
            json_encode(['removal_code' => $removalCode, 'reason' => $reason]),
            $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
    }
    
    return true;
}

/**
 * Execute transfer in action
 */
function executeTransferIn($data, $db) {
    // Implementation similar to transfers/in.php
    throw new Exception('Transfer in execution not yet implemented');
}

/**
 * Execute transfer out action
 */
function executeTransferOut($data, $db) {
    // Implementation similar to transfers/out.php
    throw new Exception('Transfer out execution not yet implemented');
}

/**
 * Execute add CFO member action
 */
function executeAddCfo($data, $db) {
    $currentUser = getCurrentUser();
    
    $lastName = $data['last_name'] ?? '';
    $firstName = $data['first_name'] ?? '';
    $middleName = $data['middle_name'] ?? '';
    $husbandsSurname = $data['husbands_surname'] ?? '';
    $registryNumber = $data['registry_number'] ?? '';
    $districtCode = $data['district_code'] ?? '';
    $localCode = $data['local_code'] ?? '';
    $birthday = $data['birthday'] ?? '';
    $cfoClassification = $data['cfo_classification'] ?? '';
    $cfoStatus = $data['cfo_status'] ?? 'active';
    $cfoNotes = $data['cfo_notes'] ?? '';
    $registrationType = $data['registration_type'] ?? '';
    $registrationDate = $data['registration_date'] ?? '';
    $registrationOthersSpecify = $data['registration_others_specify'] ?? '';
    
    // Check for duplicate registry number
    $registryNumberHash = hash('sha256', strtolower(trim($registryNumber)));
    $stmt = $db->prepare("SELECT id FROM tarheta_control WHERE registry_number_hash = ?");
    $stmt->execute([$registryNumberHash]);
    
    if ($stmt->fetch()) {
        throw new Exception('This registry number already exists in the database.');
    }
    
    // Encrypt data
    $lastNameEnc = Encryption::encrypt($lastName, $districtCode);
    $firstNameEnc = Encryption::encrypt($firstName, $districtCode);
    $middleNameEnc = !empty($middleName) ? Encryption::encrypt($middleName, $districtCode) : null;
    $husbandsSurnameEnc = !empty($husbandsSurname) ? Encryption::encrypt($husbandsSurname, $districtCode) : null;
    $registryNumberEnc = Encryption::encrypt($registryNumber, $districtCode);
    
    // Encrypt birthday if provided
    $birthdayEnc = null;
    if (!empty($birthday)) {
        $birthdayEnc = Encryption::encrypt($birthday, $districtCode);
    }
    
    // Auto-classify if not manually set
    $cfoClassificationAuto = false;
    if (empty($cfoClassification) && !empty($birthday)) {
        $age = null;
        try {
            $birthdayDate = new DateTime($birthday);
            $today = new DateTime();
            $age = $today->diff($birthdayDate)->y;
        } catch (Exception $e) {}
        
        if (!empty($husbandsSurname) && trim($husbandsSurname) !== '' && trim($husbandsSurname) !== '-') {
            $cfoClassification = 'Buklod';
            $cfoClassificationAuto = true;
        } elseif ($age !== null) {
            if ($age >= 18) {
                $cfoClassification = 'Kadiwa';
                $cfoClassificationAuto = true;
            } else {
                $cfoClassification = 'Binhi';
                $cfoClassificationAuto = true;
            }
        }
    }
    
    // Create search index values
    $searchName = trim($firstName . ' ' . $middleName . ' ' . $lastName);
    $searchRegistry = $registryNumber;
    
    // Insert record
    $stmt = $db->prepare("
        INSERT INTO tarheta_control (
            last_name_encrypted, first_name_encrypted, middle_name_encrypted,
            husbands_surname_encrypted, registry_number_encrypted, registry_number_hash,
            district_code, local_code, birthday_encrypted,
            cfo_classification, cfo_classification_auto, cfo_status, cfo_notes,
            registration_type, registration_date, registration_others_specify,
            search_name, search_registry,
            imported_by, imported_at, cfo_updated_by, cfo_updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, NOW())
    ");
    
    $stmt->execute([
        $lastNameEnc,
        $firstNameEnc,
        $middleNameEnc,
        $husbandsSurnameEnc,
        $registryNumberEnc,
        $registryNumberHash,
        $districtCode,
        $localCode,
        $birthdayEnc,
        empty($cfoClassification) ? null : $cfoClassification,
        $cfoClassificationAuto ? 1 : 0,
        $cfoStatus,
        $cfoNotes,
        !empty($registrationType) ? $registrationType : null,
        !empty($registrationDate) ? $registrationDate : null,
        !empty($registrationOthersSpecify) ? $registrationOthersSpecify : null,
        $searchName,
        $searchRegistry,
        $currentUser['user_id'],
        $currentUser['user_id']
    ]);
    
    return true;
}

/**
 * Execute edit CFO member action
 */
function executeEditCfo($data, $recordId, $db) {
    $currentUser = getCurrentUser();
    
    if (!$recordId) {
        throw new Exception('No CFO record ID specified for edit.');
    }
    
    // Get existing record
    $stmt = $db->prepare("SELECT * FROM tarheta_control WHERE id = ?");
    $stmt->execute([$recordId]);
    $existingRecord = $stmt->fetch();
    
    if (!$existingRecord) {
        throw new Exception('CFO record not found.');
    }
    
    $districtCode = $existingRecord['district_code'];
    
    // Build update fields
    $updateFields = [];
    $updateParams = [];
    
    // Update each provided field
    if (isset($data['last_name'])) {
        $updateFields[] = 'last_name_encrypted = ?';
        $updateParams[] = Encryption::encrypt($data['last_name'], $districtCode);
    }
    if (isset($data['first_name'])) {
        $updateFields[] = 'first_name_encrypted = ?';
        $updateParams[] = Encryption::encrypt($data['first_name'], $districtCode);
    }
    if (isset($data['middle_name'])) {
        $updateFields[] = 'middle_name_encrypted = ?';
        $updateParams[] = !empty($data['middle_name']) ? Encryption::encrypt($data['middle_name'], $districtCode) : null;
    }
    if (isset($data['husbands_surname'])) {
        $updateFields[] = 'husbands_surname_encrypted = ?';
        $updateParams[] = !empty($data['husbands_surname']) ? Encryption::encrypt($data['husbands_surname'], $districtCode) : null;
    }
    if (isset($data['birthday'])) {
        $updateFields[] = 'birthday_encrypted = ?';
        $updateParams[] = !empty($data['birthday']) ? Encryption::encrypt($data['birthday'], $districtCode) : null;
    }
    if (isset($data['cfo_classification'])) {
        $updateFields[] = 'cfo_classification = ?';
        $updateParams[] = $data['cfo_classification'];
        $updateFields[] = 'cfo_classification_auto = ?';
        $updateParams[] = 0;
    }
    if (isset($data['cfo_status'])) {
        $updateFields[] = 'cfo_status = ?';
        $updateParams[] = $data['cfo_status'];
    }
    if (isset($data['cfo_notes'])) {
        $updateFields[] = 'cfo_notes = ?';
        $updateParams[] = $data['cfo_notes'];
    }
    
    // Update search index
    if (isset($data['first_name']) || isset($data['middle_name']) || isset($data['last_name'])) {
        $firstName = $data['first_name'] ?? '';
        $middleName = $data['middle_name'] ?? '';
        $lastName = $data['last_name'] ?? '';
        $updateFields[] = 'search_name = ?';
        $updateParams[] = trim($firstName . ' ' . $middleName . ' ' . $lastName);
    }
    
    $updateFields[] = 'cfo_updated_by = ?';
    $updateParams[] = $currentUser['user_id'];
    $updateFields[] = 'cfo_updated_at = NOW()';
    
    $updateParams[] = $recordId;
    
    $sql = "UPDATE tarheta_control SET " . implode(', ', $updateFields) . " WHERE id = ?";
    $stmt = $db->prepare($sql);
    $stmt->execute($updateParams);
    
    return true;
}

/**
 * Execute add HDB member action
 */
function executeAddHdb($data, $db) {
    $currentUser = getCurrentUser();
    
    // Extract data
    $districtCode = $data['district_code'] ?? '';
    $localCode = $data['local_code'] ?? '';
    $childFirstName = $data['child_first_name'] ?? '';
    $childMiddleName = $data['child_middle_name'] ?? '';
    $childLastName = $data['child_last_name'] ?? '';
    $childBirthday = $data['child_birthday'] ?? '';
    $childBirthplace = $data['child_birthplace'] ?? '';
    $childSex = $data['child_sex'] ?? '';
    $fatherName = $data['father_name'] ?? '';
    $motherName = $data['mother_name'] ?? '';
    $parentAddress = $data['parent_address'] ?? '';
    $parentContact = $data['parent_contact'] ?? '';
    $registryNumber = $data['registry_number'] ?? '';
    $registrationDate = $data['registration_date'] ?? date('Y-m-d');
    $purokGrupo = $data['purok_grupo'] ?? '';
    $notes = $data['notes'] ?? '';
    
    // Check for duplicate registry number
    $registryNumberHash = hash('sha256', strtolower(trim($registryNumber)));
    $stmt = $db->prepare("SELECT id FROM hdb_registry WHERE registry_number_hash = ?");
    $stmt->execute([$registryNumberHash]);
    
    if ($stmt->fetch()) {
        throw new Exception('This registry number already exists in the HDB database.');
    }
    
    // Encrypt data
    $childFirstNameEnc = Encryption::encrypt($childFirstName, $districtCode);
    $childMiddleNameEnc = !empty($childMiddleName) ? Encryption::encrypt($childMiddleName, $districtCode) : null;
    $childLastNameEnc = Encryption::encrypt($childLastName, $districtCode);
    $childBirthdayEnc = !empty($childBirthday) ? Encryption::encrypt($childBirthday, $districtCode) : null;
    $childBirthplaceEnc = !empty($childBirthplace) ? Encryption::encrypt($childBirthplace, $districtCode) : null;
    
    $fatherNameEnc = !empty($fatherName) ? Encryption::encrypt($fatherName, $districtCode) : null;
    $motherNameEnc = !empty($motherName) ? Encryption::encrypt($motherName, $districtCode) : null;
    $parentAddressEnc = !empty($parentAddress) ? Encryption::encrypt($parentAddress, $districtCode) : null;
    $parentContactEnc = !empty($parentContact) ? Encryption::encrypt($parentContact, $districtCode) : null;
    
    $registryNumberEnc = Encryption::encrypt($registryNumber, $districtCode);
    
    // Insert record
    $stmt = $db->prepare("
        INSERT INTO hdb_registry (
            district_code, local_code,
            child_first_name_encrypted, child_middle_name_encrypted, child_last_name_encrypted,
            child_birthday_encrypted, child_birthplace_encrypted, child_sex,
            father_name_encrypted, mother_name_encrypted,
            parent_address_encrypted, parent_contact_encrypted,
            registry_number, registry_number_hash,
            registration_date, dedication_status,
            purok_grupo, notes,
            created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?, NOW())
    ");
    
    $stmt->execute([
        $districtCode, $localCode,
        $childFirstNameEnc, $childMiddleNameEnc, $childLastNameEnc,
        $childBirthdayEnc, $childBirthplaceEnc, $childSex,
        $fatherNameEnc, $motherNameEnc,
        $parentAddressEnc, $parentContactEnc,
        $registryNumberEnc, $registryNumberHash,
        $registrationDate,
        $purokGrupo, $notes,
        $currentUser['user_id']
    ]);
    
    return 'HDB child added successfully!';
}

/**
 * Execute edit HDB member action (placeholder)
 */
function executeEditHdb($data, $recordId, $db) {
    // TODO: Implement HDB edit logic
    throw new Exception('HDB edit execution not yet implemented. Please contact administrator.');
}

/**
 * Execute add PNK member action
 */
function executeAddPnk($data, $db) {
    $currentUser = getCurrentUser();
    
    // Extract data
    $districtCode = $data['district_code'] ?? '';
    $localCode = $data['local_code'] ?? '';
    $firstName = $data['first_name'] ?? '';
    $middleName = $data['middle_name'] ?? '';
    $lastName = $data['last_name'] ?? '';
    $birthday = $data['birthday'] ?? '';
    $birthplace = $data['birthplace'] ?? '';
    $sex = $data['sex'] ?? '';
    $ageCategory = $data['age_category'] ?? 'preteen';
    $fatherName = $data['father_name'] ?? '';
    $motherName = $data['mother_name'] ?? '';
    $parentAddress = $data['parent_address'] ?? '';
    $parentContact = $data['parent_contact'] ?? '';
    $registryNumber = $data['registry_number'] ?? '';
    $registrationDate = $data['registration_date'] ?? date('Y-m-d');
    $dakoId = $data['dako_id'] ?? '';
    $purokGrupo = $data['purok_grupo'] ?? '';
    $notes = $data['notes'] ?? '';
    
    // Check for duplicate registry number
    $registryNumberHash = hash('sha256', strtolower(trim($registryNumber)));
    $stmt = $db->prepare("SELECT id FROM pnk_registry WHERE registry_number_hash = ?");
    $stmt->execute([$registryNumberHash]);
    
    if ($stmt->fetch()) {
        throw new Exception('This registry number already exists in the PNK database.');
    }
    
    // Encrypt data
    $firstNameEnc = Encryption::encrypt($firstName, $districtCode);
    $middleNameEnc = !empty($middleName) ? Encryption::encrypt($middleName, $districtCode) : null;
    $lastNameEnc = Encryption::encrypt($lastName, $districtCode);
    $birthdayEnc = !empty($birthday) ? Encryption::encrypt($birthday, $districtCode) : null;
    $birthplaceEnc = !empty($birthplace) ? Encryption::encrypt($birthplace, $districtCode) : null;
    
    // Simplified parent names - store as combined format "Father Name / Mother Name"
    $parentGuardian = '';
    if (!empty($fatherName) || !empty($motherName)) {
        $parentGuardian = trim($fatherName) . ' / ' . trim($motherName);
    }
    $parentGuardianEnc = !empty($parentGuardian) ? Encryption::encrypt($parentGuardian, $districtCode) : null;
    
    $parentAddressEnc = !empty($parentAddress) ? Encryption::encrypt($parentAddress, $districtCode) : null;
    $parentContactEnc = !empty($parentContact) ? Encryption::encrypt($parentContact, $districtCode) : null;
    
    $registryNumberEnc = Encryption::encrypt($registryNumber, $districtCode);
    
    // Get Dako name if selected
    $dakoEnc = null;
    if (!empty($dakoId)) {
        $stmt = $db->prepare("SELECT dako_name FROM pnk_dako WHERE id = ?");
        $stmt->execute([$dakoId]);
        $dakoRow = $stmt->fetch();
        if ($dakoRow) {
            $dakoEnc = Encryption::encrypt($dakoRow['dako_name'], $districtCode);
        }
    }
    
    // Insert record
    $stmt = $db->prepare("
        INSERT INTO pnk_registry (
            district_code, local_code,
            first_name_encrypted, middle_name_encrypted, last_name_encrypted,
            birthday_encrypted, birthplace_encrypted, sex, pnk_category,
            parent_guardian_encrypted,
            address_encrypted, contact_number_encrypted,
            registry_number, registry_number_hash,
            registration_date, dako_encrypted,
            purok_grupo, notes,
            created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    
    $stmt->execute([
        $districtCode, $localCode,
        $firstNameEnc, $middleNameEnc, $lastNameEnc,
        $birthdayEnc, $birthplaceEnc, $sex, ucfirst($ageCategory),
        $parentGuardianEnc,
        $parentAddressEnc, $parentContactEnc,
        $registryNumberEnc, $registryNumberHash,
        $registrationDate, $dakoEnc,
        $purokGrupo, $notes,
        $currentUser['user_id']
    ]);
    
    $recordId = $db->lastInsertId();
    
    // Log activity
    $stmt = $db->prepare("
        INSERT INTO pnk_activity_log (pnk_record_id, user_id, action, details, ip_address, user_agent)
        VALUES (?, ?, 'create', ?, ?, ?)
    ");
    $stmt->execute([
        $recordId,
        $currentUser['user_id'],
        json_encode(['registry_number' => $registryNumber, 'age_category' => $ageCategory, 'approved_action' => true]),
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);
    
    return 'PNK member added successfully!';
}

/**
 * Execute edit PNK member action (placeholder)
 */
function executeEditPnk($data, $recordId, $db) {
    // TODO: Implement PNK edit logic
    throw new Exception('PNK edit execution not yet implemented. Please contact administrator.');
}
?>
