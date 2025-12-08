<?php
/**
 * Pending Action Handlers
 * Helper functions to create pending actions for local_limited users
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/includes/permissions.php';

/**
 * Check if action should be pending (for local_limited users)
 * @return bool True if action requires approval
 */
function shouldPendAction() {
    return isLocalLimitedUser();
}

/**
 * Create pending action for adding an officer
 */
function createPendingAddOfficer($formData, $officerName) {
    $description = "Add new officer: {$officerName} to " . ($formData['local_name'] ?? 'local congregation');
    
    return createPendingAction(
        'add_officer',
        $formData,
        $description,
        null,
        $formData['officer_uuid'] ?? null
    );
}

/**
 * Create pending action for editing an officer
 */
function createPendingEditOfficer($officerId, $formData, $officerName) {
    $description = "Edit officer: {$officerName}";
    
    return createPendingAction(
        'edit_officer',
        $formData,
        $description,
        $officerId,
        null
    );
}

/**
 * Create pending action for removing an officer
 */
function createPendingRemoveOfficer($officerId, $removalData, $officerName) {
    $description = "Remove officer: {$officerName} - Reason: " . ($removalData['removal_reason'] ?? 'Not specified');
    
    return createPendingAction(
        'remove_officer',
        $removalData,
        $description,
        $officerId,
        null
    );
}

/**
 * Create pending action for transfer in
 */
function createPendingTransferIn($transferData, $officerName) {
    $description = "Transfer in officer: {$officerName} from " . ($transferData['from_local'] ?? 'another local');
    
    return createPendingAction(
        'transfer_in',
        $transferData,
        $description,
        $transferData['officer_id'] ?? null,
        null
    );
}

/**
 * Create pending action for transfer out
 */
function createPendingTransferOut($transferData, $officerName) {
    $description = "Transfer out officer: {$officerName} to " . ($transferData['to_local'] ?? 'another local');
    
    return createPendingAction(
        'transfer_out',
        $transferData,
        $description,
        $transferData['officer_id'] ?? null,
        null
    );
}

/**
 * Create pending action for bulk update
 */
function createPendingBulkUpdate($bulkData, $count) {
    $description = "Bulk update of {$count} officers";
    
    return createPendingAction(
        'bulk_update',
        $bulkData,
        $description,
        null,
        null
    );
}

/**
 * Create pending action for adding a request
 */
function createPendingAddRequest($requestData, $requestType) {
    $description = "Add new request: {$requestType}";
    
    return createPendingAction(
        'add_request',
        $requestData,
        $description,
        null,
        null
    );
}

/**
 * Get pending action message for user
 */
function getPendingActionMessage($actionType = 'action') {
    $approver = getSeniorApprover();
    $approverName = $approver ? $approver['full_name'] : 'your senior approver';
    
    return "Your {$actionType} has been submitted for approval to {$approverName}. You will be notified once it is reviewed.";
}
