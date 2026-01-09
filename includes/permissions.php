<?php
/**
 * User Permissions Helper Functions
 * Provides functions to check and enforce user permissions throughout the application
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/config/database.php';

/**
 * Get user permissions from database
 * 
 * @param int $userId The user ID
 * @return array|null Array of permissions or null if not found
 */
function getUserPermissions($userId) {
    $db = Database::getInstance()->getConnection();
    
    try {
        $stmt = $db->prepare("
            SELECT * 
            FROM user_permissions 
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching user permissions: " . $e->getMessage());
        return null;
    }
}

/**
 * Check if user has a specific permission
 * 
 * @param string $permission The permission name (e.g., 'can_add_officers')
 * @param int|null $userId Optional user ID, defaults to current session user
 * @return bool True if user has permission, false otherwise
 */
function hasPermission($permission, $userId = null) {
    // If no user ID provided, use current session user
    if ($userId === null) {
        if (!isset($_SESSION['user_id'])) {
            return false;
        }
        $userId = $_SESSION['user_id'];
    }
    
    // Admin users have all permissions
    if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') {
        return true;
    }
    
    // Get user permissions from database
    $permissions = getUserPermissions($userId);
    
    // If no permissions found, return false
    if (!$permissions) {
        return false;
    }
    
    // Check if the specific permission exists and is enabled
    return isset($permissions[$permission]) && $permissions[$permission] == 1;
}

/**
 * Require a specific permission or redirect to access denied page
 * 
 * @param string $permission The permission name
 * @param string $redirectUrl Optional redirect URL, defaults to dashboard
 */
function requirePermission($permission, $redirectUrl = null) {
    if (!hasPermission($permission)) {
        $_SESSION['error'] = "You don't have permission to access this feature.";
        
        if ($redirectUrl === null) {
            $redirectUrl = BASE_URL . '/dashboard.php';
        }
        
        header('Location: ' . $redirectUrl);
        exit;
    }
}

/**
 * Check if user has any of the specified permissions
 * 
 * @param array $permissions Array of permission names
 * @param int|null $userId Optional user ID
 * @return bool True if user has at least one permission
 */
function hasAnyPermission($permissions, $userId = null) {
    foreach ($permissions as $permission) {
        if (hasPermission($permission, $userId)) {
            return true;
        }
    }
    return false;
}

/**
 * Check if user has all of the specified permissions
 * 
 * @param array $permissions Array of permission names
 * @param int|null $userId Optional user ID
 * @return bool True if user has all permissions
 */
function hasAllPermissions($permissions, $userId = null) {
    foreach ($permissions as $permission) {
        if (!hasPermission($permission, $userId)) {
            return false;
        }
    }
    return true;
}

/**
 * Update existing user permissions based on their role
 * 
 * @param int $userId The user ID
 * @param string $role The user role
 * @return bool True on success, false on failure
 */
function updateUserPermissionsByRole($userId, $role) {
    $db = Database::getInstance()->getConnection();
    
    try {
        // Define permission values based on role
        $permissions = [];
        
        if ($role === 'admin') {
            $permissions = [1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1];
        } elseif ($role === 'district' || $role === 'district_user') {
            $permissions = [1, 1, 1, 0, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0, 0, 0, 0];
        } elseif ($role === 'local' || $role === 'local_limited') {
            $permissions = [1, 1, 1, 0, 1, 1, 1, 1, 0, 1, 1, 1, 0, 1, 1, 0, 0, 0, 0];
        } elseif ($role === 'local_cfo') {
            // Local CFO: Only CFO reports and viewing
            $permissions = [0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 1, 0, 0, 0, 0, 0, 0];
        } else {
            $permissions = [1, 0, 0, 0, 0, 0, 0, 1, 0, 1, 1, 1, 0, 1, 1, 0, 0, 0, 0];
        }
        
        $stmt = $db->prepare("
            UPDATE user_permissions SET
                can_view_officers = ?, can_add_officers = ?, can_edit_officers = ?, can_delete_officers = ?,
                can_transfer_in = ?, can_transfer_out = ?, can_remove_officers = ?,
                can_view_requests = ?, can_manage_requests = ?,
                can_view_reports = ?, can_view_headcount = ?, can_view_departments = ?, can_export_reports = ?,
                can_view_calendar = ?, can_view_announcements = ?,
                can_manage_users = ?, can_manage_announcements = ?, can_manage_districts = ?, can_view_audit_log = ?
            WHERE user_id = ?
        ");
        
        $permissions[] = $userId; // Add user_id at the end
        $stmt->execute($permissions);
        return true;
    } catch (PDOException $e) {
        error_log("Error updating permissions by role: " . $e->getMessage());
        return false;
    }
}

/**
 * Create default permissions for a new user based on their role
 * 
 * @param int $userId The user ID
 * @param string $role The user role (admin, district_user, local_user)
 * @return bool True on success, false on failure
 */
function createDefaultPermissions($userId, $role) {
    $db = Database::getInstance()->getConnection();
    
    try {
        // Check if permissions already exist
        $stmt = $db->prepare("SELECT user_id FROM user_permissions WHERE user_id = ?");
        $stmt->execute([$userId]);
        $exists = $stmt->fetch();
        
        // If permissions already exist, update them instead
        if ($exists) {
            return updateUserPermissionsByRole($userId, $role);
        }
        
        // Set default permissions based on role
        if ($role === 'admin') {
            // Admin gets all permissions
            $stmt = $db->prepare("
                INSERT INTO user_permissions (
                    user_id,
                    can_view_officers, can_add_officers, can_edit_officers, can_delete_officers,
                    can_transfer_in, can_transfer_out, can_remove_officers,
                    can_view_requests, can_manage_requests,
                    can_view_reports, can_view_headcount, can_view_departments, can_export_reports,
                    can_view_calendar, can_view_announcements,
                    can_manage_users, can_manage_announcements, can_manage_districts, can_view_audit_log
                ) VALUES (
                    ?, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1
                )
            ");
        } elseif ($role === 'district' || $role === 'district_user') {
            // District users get most permissions except admin features
            $stmt = $db->prepare("
                INSERT INTO user_permissions (
                    user_id,
                    can_view_officers, can_add_officers, can_edit_officers, can_delete_officers,
                    can_transfer_in, can_transfer_out, can_remove_officers,
                    can_view_requests, can_manage_requests,
                    can_view_reports, can_view_headcount, can_view_departments, can_export_reports,
                    can_view_calendar, can_view_announcements,
                    can_manage_users, can_manage_announcements, can_manage_districts, can_view_audit_log
                ) VALUES (
                    ?, 1, 1, 1, 0, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0, 0, 0, 0
                )
            ");
        } elseif ($role === 'local') {
            // Local users get basic permissions
            $stmt = $db->prepare("
                INSERT INTO user_permissions (
                    user_id,
                    can_view_officers, can_add_officers, can_edit_officers, can_delete_officers,
                    can_transfer_in, can_transfer_out, can_remove_officers,
                    can_view_requests, can_manage_requests,
                    can_view_reports, can_view_headcount, can_view_departments, can_export_reports,
                    can_view_calendar, can_view_announcements,
                    can_manage_users, can_manage_announcements, can_manage_districts, can_view_audit_log
                ) VALUES (
                    ?, 1, 1, 1, 0, 1, 1, 1, 1, 0, 1, 1, 1, 0, 1, 1, 0, 0, 0, 0
                )
            ");
        } elseif ($role === 'local_limited') {
            // Local Limited users can view but all modifications require approval
            // They can submit actions but cannot execute them directly
            $stmt = $db->prepare("
                INSERT INTO user_permissions (
                    user_id,
                    can_view_officers, can_add_officers, can_edit_officers, can_delete_officers,
                    can_transfer_in, can_transfer_out, can_remove_officers,
                    can_view_requests, can_manage_requests,
                    can_view_reports, can_view_headcount, can_view_departments, can_export_reports,
                    can_view_calendar, can_view_announcements,
                    can_manage_users, can_manage_announcements, can_manage_districts, can_view_audit_log
                ) VALUES (
                    ?, 1, 1, 1, 0, 1, 1, 1, 1, 0, 1, 1, 1, 0, 1, 1, 0, 0, 0, 0
                )
            ");
        } elseif ($role === 'local_cfo') {
            // Local CFO users - restricted to CFO registry only
            $stmt = $db->prepare("
                INSERT INTO user_permissions (
                    user_id,
                    can_view_officers, can_add_officers, can_edit_officers, can_delete_officers,
                    can_transfer_in, can_transfer_out, can_remove_officers,
                    can_view_requests, can_manage_requests,
                    can_view_reports, can_view_headcount, can_view_departments, can_export_reports,
                    can_view_calendar, can_view_announcements,
                    can_manage_users, can_manage_announcements, can_manage_districts, can_view_audit_log
                ) VALUES (
                    ?, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 1, 0, 0, 0, 0, 0, 0
                )
            ");
        } else {
            // Default: minimal permissions
            $stmt = $db->prepare("
                INSERT INTO user_permissions (
                    user_id,
                    can_view_officers, can_add_officers, can_edit_officers, can_delete_officers,
                    can_transfer_in, can_transfer_out, can_remove_officers,
                    can_view_requests, can_manage_requests,
                    can_view_reports, can_view_headcount, can_view_departments, can_export_reports,
                    can_view_calendar, can_view_announcements,
                    can_manage_users, can_manage_announcements, can_manage_districts, can_view_audit_log
                ) VALUES (
                    ?, 1, 0, 0, 0, 0, 0, 0, 1, 0, 1, 1, 1, 0, 1, 1, 0, 0, 0, 0
                )
            ");
        }
        
        $stmt->execute([$userId]);
        return true;
    } catch (PDOException $e) {
        error_log("Error creating default permissions: " . $e->getMessage());
        return false;
    }
}

/**
 * Get a user-friendly permission name
 * 
 * @param string $permission The permission code
 * @return string The friendly name
 */
function getPermissionName($permission) {
    $names = [
        'can_view_officers' => 'View Officers',
        'can_add_officers' => 'Add Officers',
        'can_edit_officers' => 'Edit Officers',
        'can_delete_officers' => 'Delete Officers',
        'can_transfer_in' => 'Transfer In',
        'can_transfer_out' => 'Transfer Out',
        'can_remove_officers' => 'Remove Officers',
        'can_view_requests' => 'View Requests',
        'can_manage_requests' => 'Manage Requests',
        'can_view_reports' => 'View Reports',
        'can_view_headcount' => 'View Headcount',
        'can_view_departments' => 'View Departments',
        'can_export_reports' => 'Export Reports',
        'can_view_calendar' => 'View Calendar',
        'can_view_announcements' => 'View Announcements',
        'can_manage_users' => 'Manage Users',
        'can_manage_announcements' => 'Manage Announcements',
        'can_manage_districts' => 'Manage Districts',
        'can_view_audit_log' => 'View Audit Log'
    ];
    
    return isset($names[$permission]) ? $names[$permission] : $permission;
}

/**
 * Check if current user is a local_limited role that requires approval
 * 
 * @return bool True if user is local_limited
 */
function isLocalLimitedUser() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'local_limited';
}

/**
 * Get the senior approver for a local_limited user
 * 
 * @param int|null $userId Optional user ID, defaults to current session user
 * @return array|null Senior approver user data or null
 */
function getSeniorApprover($userId = null) {
    if ($userId === null) {
        if (!isset($_SESSION['user_id'])) {
            return null;
        }
        $userId = $_SESSION['user_id'];
    }
    
    $db = Database::getInstance()->getConnection();
    
    try {
        $stmt = $db->prepare("
            SELECT u2.user_id, u2.username, u2.full_name, u2.email, u2.role
            FROM users u1
            JOIN users u2 ON u1.senior_approver_id = u2.user_id
            WHERE u1.user_id = ? AND u1.role = 'local_limited'
        ");
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching senior approver: " . $e->getMessage());
        return null;
    }
}

/**
 * Get pending actions count for an approver
 * 
 * @param int|null $approverId Optional approver user ID, defaults to current session user
 * @return int Number of pending actions
 */
function getPendingActionsCount($approverId = null) {
    if ($approverId === null) {
        if (!isset($_SESSION['user_id'])) {
            return 0;
        }
        $approverId = $_SESSION['user_id'];
    }
    
    $db = Database::getInstance()->getConnection();
    
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) as count
            FROM pending_actions
            WHERE approver_user_id = ? AND status = 'pending'
        ");
        $stmt->execute([$approverId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($result['count'] ?? 0);
    } catch (PDOException $e) {
        error_log("Error fetching pending actions count: " . $e->getMessage());
        return 0;
    }
}

/**
 * Create a pending action for approval
 * 
 * @param string $actionType Type of action (add_officer, edit_officer, etc.)
 * @param array $actionData Complete data needed to execute the action
 * @param string $description Human-readable description
 * @param int|null $officerId Optional officer ID reference
 * @param string|null $officerUuid Optional officer UUID for new additions
 * @return int|false Action ID on success, false on failure
 */
function createPendingAction($actionType, $actionData, $description, $officerId = null, $officerUuid = null) {
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    
    $requesterId = $_SESSION['user_id'];
    
    // Get senior approver for this user
    $approver = getSeniorApprover($requesterId);
    if (!$approver) {
        error_log("No senior approver found for user $requesterId");
        return false;
    }
    
    $db = Database::getInstance()->getConnection();
    
    try {
        $stmt = $db->prepare("
            INSERT INTO pending_actions (
                requester_user_id, 
                approver_user_id, 
                action_type, 
                action_data, 
                action_description,
                officer_id,
                officer_uuid,
                status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
        ");
        
        $stmt->execute([
            $requesterId,
            $approver['user_id'],
            $actionType,
            json_encode($actionData),
            $description,
            $officerId,
            $officerUuid
        ]);
        
        return $db->lastInsertId();
    } catch (PDOException $e) {
        error_log("Error creating pending action: " . $e->getMessage());
        return false;
    }
}

