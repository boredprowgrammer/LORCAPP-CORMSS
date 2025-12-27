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
// Allow: local (senior approvers) OR local_limited (to view their own submissions)
if ($currentUser['role'] !== 'local' && $currentUser['role'] !== 'local_limited' && $currentUser['role'] !== 'admin') {
    $_SESSION['error'] = "You do not have permission to view pending actions.";
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

// Determine if user is a senior approver or limited user viewing their own
$isSeniorApprover = ($currentUser['role'] === 'local' || $currentUser['role'] === 'admin');
$isLimitedUser = ($currentUser['role'] === 'local_limited');

// Handle approve/reject actions (only for senior approvers)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isSeniorApprover) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!Security::validateCSRFToken($csrfToken)) {
        $error = 'Invalid security token.';
    } else {
        $actionId = intval($_POST['action_id'] ?? 0);
        $decision = $_POST['decision'] ?? ''; // 'approve' or 'reject'
        $rejectionReason = Security::sanitizeInput($_POST['rejection_reason'] ?? '');
        
        if ($actionId <= 0) {
            $error = 'Invalid action ID.';
        } elseif (!in_array($decision, ['approve', 'reject'])) {
            $error = 'Invalid decision.';
        } elseif ($decision === 'reject' && empty($rejectionReason)) {
            $error = 'Please provide a reason for rejection.';
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
    
    switch ($actionType) {
        case 'add_officer':
            $html .= '<div class="grid grid-cols-2 gap-2">';
            
            if (!empty($actionData['last_name']) || !empty($actionData['first_name'])) {
                $fullName = trim(($actionData['last_name'] ?? '') . ', ' . ($actionData['first_name'] ?? '') . ' ' . ($actionData['middle_initial'] ?? ''));
                $html .= '<div class="col-span-2"><span class="font-semibold text-gray-700">Name:</span> <span class="text-gray-900">' . Security::escape($fullName) . '</span></div>';
            }
            
            if (!empty($actionData['department'])) {
                $html .= '<div class="col-span-2"><span class="font-semibold text-gray-700">Department:</span> <span class="text-gray-900">' . Security::escape($actionData['department']) . '</span></div>';
            }
            
            if (!empty($actionData['duty'])) {
                $html .= '<div class="col-span-2"><span class="font-semibold text-gray-700">Duty:</span> <span class="text-gray-900">' . Security::escape($actionData['duty']) . '</span></div>';
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
                $html .= '<div><span class="font-semibold text-gray-700">District:</span> <span class="text-gray-900">' . Security::escape($districtName) . '</span></div>';
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
                $html .= '<div><span class="font-semibold text-gray-700">Local:</span> <span class="text-gray-900">' . Security::escape($localName) . '</span></div>';
            }
            
            // Purok/Grupo
            if (!empty($actionData['purok'])) {
                $html .= '<div><span class="font-semibold text-gray-700">Purok:</span> <span class="text-gray-900">' . Security::escape($actionData['purok']) . '</span></div>';
            }
            
            if (!empty($actionData['grupo'])) {
                $html .= '<div><span class="font-semibold text-gray-700">Grupo:</span> <span class="text-gray-900">' . Security::escape($actionData['grupo']) . '</span></div>';
            }
            
            // Oath Date
            if (!empty($actionData['oath_date'])) {
                $oathDate = date('F d, Y', strtotime($actionData['oath_date']));
                $html .= '<div class="col-span-2"><span class="font-semibold text-gray-700">Oath Date:</span> <span class="text-gray-900">' . Security::escape($oathDate) . '</span></div>';
            }
            
            // Control Numbers
            if (!empty($actionData['control_number'])) {
                $html .= '<div><span class="font-semibold text-gray-700">Control #:</span> <span class="text-gray-900">' . Security::escape($actionData['control_number']) . '</span></div>';
            }
            
            if (!empty($actionData['registry_number'])) {
                $html .= '<div><span class="font-semibold text-gray-700">Registry #:</span> <span class="text-gray-900">' . Security::escape($actionData['registry_number']) . '</span></div>';
            }
            
            $html .= '</div>';
            break;
            
        case 'edit_officer':
            $html .= '<div class="grid grid-cols-2 gap-2">';
            
            foreach ($actionData as $key => $value) {
                if ($key === 'officer_uuid' || $key === 'has_existing_record' || empty($value)) continue;
                
                $label = ucwords(str_replace('_', ' ', $key));
                $html .= '<div><span class="font-semibold text-gray-700">' . Security::escape($label) . ':</span> <span class="text-gray-900">' . Security::escape($value) . '</span></div>';
            }
            
            $html .= '</div>';
            break;
            
        case 'remove_officer':
            if (!empty($actionData['removal_code'])) {
                $html .= '<div><span class="font-semibold text-gray-700">Removal Code:</span> <span class="text-gray-900">' . Security::escape($actionData['removal_code']) . '</span></div>';
            }
            if (!empty($actionData['removal_reason'])) {
                $html .= '<div><span class="font-semibold text-gray-700">Reason:</span> <span class="text-gray-900">' . Security::escape($actionData['removal_reason']) . '</span></div>';
            }
            break;
            
        default:
            // For unknown action types, show formatted JSON
            $html .= '<div class="text-xs"><pre class="whitespace-pre-wrap">' . Security::escape(json_encode($actionData, JSON_PRETTY_PRINT)) . '</pre></div>';
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
        <h2 class="text-3xl font-bold text-gray-900">Pending Actions</h2>
        <p class="text-gray-600 mt-1">Review and approve actions from limited users</p>
    </div>

    <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4">
            <?php echo Security::escape($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4">
            <?php echo Security::escape($success); ?>
        </div>
    <?php endif; ?>

    <!-- Info Banner -->
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
        <div class="flex items-start">
            <svg class="w-5 h-5 text-blue-600 mr-3 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
            </svg>
            <div class="text-sm text-blue-800">
                <p class="font-semibold mb-1">About Pending Actions:</p>
                <?php if ($isSeniorApprover): ?>
                    <p>Local (Limited) users require your approval before their actions take effect. Review each action carefully before approving or rejecting.</p>
                <?php else: ?>
                    <p>Your actions require approval from your senior account before taking effect. You can track the status of your submissions here.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Pending Actions List -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-6">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900">
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
                <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <h3 class="mt-2 text-sm font-medium text-gray-900">No pending actions</h3>
                <p class="mt-1 text-sm text-gray-500">
                    <?php if ($isSeniorApprover): ?>
                        All actions have been reviewed.
                    <?php else: ?>
                        You have no pending submissions.
                    <?php endif; ?>
                </p>
            </div>
        <?php else: ?>
            <div class="divide-y divide-gray-200">
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
                    
                    <div class="px-6 py-5 hover:bg-gray-50 transition-colors border-l-4 border-yellow-400">
                        <div class="flex items-start justify-between">
                            <div class="flex items-start space-x-4 flex-1">
                                <!-- Status Icon -->
                                <div class="flex-shrink-0 mt-1">
                                    <div class="w-12 h-12 rounded-full bg-yellow-50 flex items-center justify-center">
                                        <svg class="w-6 h-6 text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 10h4.764a2 2 0 011.789 2.894l-3.5 7A2 2 0 0115.263 21h-4.017c-.163 0-.326-.02-.485-.06L7 20m7-10V5a2 2 0 00-2-2h-.095c-.5 0-.905.405-.905.905 0 .714-.211 1.412-.608 2.006L7 11v9m7-10h-2M7 20H5a2 2 0 01-2-2v-6a2 2 0 012-2h2.5"></path>
                                        </svg>
                                    </div>
                                </div>
                                
                                <!-- Content -->
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center space-x-2 mb-2">
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-gradient-to-r from-purple-100 to-purple-200 text-purple-800 shadow-sm">
                                            <?php echo Security::escape($actionLabel); ?>
                                        </span>
                                        <span class="text-xs text-gray-500 font-medium">
                                            🕒 <?php echo date('M d, Y g:i A', strtotime($action['created_at'])); ?>
                                        </span>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <p class="text-sm font-semibold text-gray-900 mb-1">
                                            <?php echo Security::escape($action['action_description']); ?>
                                        </p>
                                        <?php if ($officerName): ?>
                                            <p class="text-sm text-gray-600 flex items-center">
                                                <svg class="w-4 h-4 mr-1 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"/>
                                                </svg>
                                                <span class="font-medium"><?php echo Security::escape($officerName); ?></span>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="text-xs text-gray-500 flex items-center mb-3">
                                        <svg class="w-4 h-4 mr-1 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
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
                                        <summary class="text-xs text-blue-600 cursor-pointer hover:text-blue-800 font-medium flex items-center">
                                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                            </svg>
                                            View Full Details
                                        </summary>
                                        <div class="mt-2 p-4 bg-gradient-to-br from-gray-50 to-gray-100 rounded-lg border border-gray-200 shadow-sm">
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
                                        onclick="openRejectModal(<?php echo $action['action_id']; ?>, '<?php echo Security::escape($action['action_description']); ?>')"
                                        class="inline-flex items-center justify-center px-4 py-2 bg-red-600 text-white text-sm font-medium rounded-lg hover:bg-red-700 transition-all shadow-sm hover:shadow-md">
                                        <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                        </svg>
                                        Reject
                                    </button>
                                </div>
                            <?php else: ?>
                                <div class="ml-4 min-w-[140px]">
                                    <div class="inline-flex items-center px-4 py-2 bg-yellow-50 border border-yellow-200 text-yellow-700 text-sm font-medium rounded-lg shadow-sm">
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
    <div class="bg-white rounded-lg shadow-sm border border-gray-200">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900">
                <?php if ($isSeniorApprover): ?>
                    Recent History (Your Reviews)
                <?php else: ?>
                    Recent History (My Submissions)
                <?php endif; ?>
            </h3>
        </div>

        <?php if (empty($reviewedActions)): ?>
            <div class="px-6 py-8 text-center text-gray-500 text-sm">
                No reviewed actions yet.
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Action</th>
                            <?php if ($isSeniorApprover): ?>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Requester</th>
                            <?php else: ?>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Reviewed By</th>
                            <?php endif; ?>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Decision</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
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
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900"><?php echo Security::escape($actionLabel); ?></div>
                                    <div class="text-xs text-gray-500"><?php echo Security::escape(substr($action['action_description'], 0, 60)) . (strlen($action['action_description']) > 60 ? '...' : ''); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php if ($isSeniorApprover): ?>
                                        <?php echo Security::escape($action['requester_name']); ?>
                                    <?php else: ?>
                                        <?php echo Security::escape($action['reviewer_name'] ?? 'N/A'); ?>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php if ($action['status'] === 'approved'): ?>
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-green-50 text-green-700 border border-green-200 shadow-sm">
                                            <svg class="w-4 h-4 mr-1.5 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                                                <path d="M2 10.5a1.5 1.5 0 113 0v6a1.5 1.5 0 01-3 0v-6zM6 10.333v5.43a2 2 0 001.106 1.79l.05.025A4 4 0 008.943 18h5.416a2 2 0 001.962-1.608l1.2-6A2 2 0 0015.56 8H12V4a2 2 0 00-2-2 1 1 0 00-1 1v.667a4 4 0 01-.8 2.4L6.8 7.933a4 4 0 00-.8 2.4z"/>
                                            </svg>
                                            Approved
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-red-50 text-red-700 border border-red-200 shadow-sm">
                                            <svg class="w-4 h-4 mr-1.5 text-red-600" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                                            </svg>
                                            Rejected
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
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
        <div onclick="event.stopPropagation()" class="relative bg-white rounded-lg shadow-xl w-full max-w-2xl p-6 max-h-[90vh] overflow-y-auto">
            <h3 class="text-lg font-bold text-gray-900 mb-4">Edit Pending Action</h3>
            
            <form method="POST" action="" id="editActionForm">
                <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                <input type="hidden" name="action_id" id="editActionId" value="">
                <input type="hidden" name="update_action" value="1">
                
                <div id="editFormFields" class="space-y-4 mb-6">
                    <!-- Dynamic form fields will be inserted here -->
                </div>
                
                <div class="flex items-center justify-end space-x-3 pt-4 border-t">
                    <button type="button" onclick="closeEditModal()" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors">Cancel</button>
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
        <div onclick="event.stopPropagation()" class="relative bg-white rounded-lg shadow-xl w-full max-w-md p-6">
            <h3 class="text-lg font-bold text-gray-900 mb-4">Reject Action</h3>
            <p class="text-sm text-gray-600 mb-4" id="rejectActionDescription"></p>
            
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                <input type="hidden" name="action_id" id="rejectActionId" value="">
                <input type="hidden" name="decision" value="reject">
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Reason for Rejection *</label>
                    <textarea 
                        name="rejection_reason" 
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" 
                        rows="4" 
                        required
                        placeholder="Explain why this action is being rejected..."></textarea>
                </div>
                
                <div class="flex items-center justify-end space-x-3">
                    <button type="button" onclick="closeRejectModal()" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">Reject Action</button>
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

function openEditModal(actionId, actionType, actionData) {
    document.getElementById('editActionId').value = actionId;
    
    // Build form fields based on action type
    const formFields = document.getElementById('editFormFields');
    formFields.innerHTML = '';
    
    if (actionType === 'add_officer') {
        formFields.innerHTML = `
            <div class="grid grid-cols-2 gap-4">
                <div class="col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Last Name *</label>
                    <input type="text" name="last_name" value="${escapeHtml(actionData.last_name || '')}" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500" required>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">First Name *</label>
                    <input type="text" name="first_name" value="${escapeHtml(actionData.first_name || '')}" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500" required>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Middle Initial</label>
                    <input type="text" name="middle_initial" value="${escapeHtml(actionData.middle_initial || '')}" 
                           maxlength="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Department *</label>
                    <input type="text" name="department" value="${escapeHtml(actionData.department || '')}" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500" required>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Duty</label>
                    <input type="text" name="duty" value="${escapeHtml(actionData.duty || '')}" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">District Code *</label>
                    <input type="text" name="district_code" value="${escapeHtml(actionData.district_code || '')}" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500" required readonly>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Local Code *</label>
                    <input type="text" name="local_code" value="${escapeHtml(actionData.local_code || '')}" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500" required readonly>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Purok</label>
                    <input type="text" name="purok" value="${escapeHtml(actionData.purok || '')}" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Grupo</label>
                    <input type="text" name="grupo" value="${escapeHtml(actionData.grupo || '')}" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Oath Year *</label>
                    <input type="number" name="oath_year" value="${escapeHtml(actionData.oath_year || '')}" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500" required>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Oath Month</label>
                    <input type="number" name="oath_month" value="${escapeHtml(actionData.oath_month || '')}" 
                           min="1" max="12" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Oath Day</label>
                    <input type="number" name="oath_day" value="${escapeHtml(actionData.oath_day || '')}" 
                           min="1" max="31" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Control Number</label>
                    <input type="text" name="control_number" value="${escapeHtml(actionData.control_number || '')}" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Registry Number</label>
                    <input type="text" name="registry_number" value="${escapeHtml(actionData.registry_number || '')}" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
            </div>
        `;
    } else {
        // Generic editor for other action types
        formFields.innerHTML = '<div class="text-sm text-gray-600">Edit functionality for this action type is not yet available.</div>';
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
    }
});
</script>

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
?>
