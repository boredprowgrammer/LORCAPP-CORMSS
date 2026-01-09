<?php
require_once __DIR__ . '/../config/config.php';

Security::requireLogin();
requirePermission('can_manage_users');

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

$error = '';
$success = '';

// Password generator function - creates easy-to-remember passwords
function generateEasyPassword() {
    // Adjectives and nouns for memorable combinations
    $adjectives = ['Happy', 'Lucky', 'Swift', 'Bright', 'Smart', 'Quick', 'Brave', 'Calm', 'Clear', 'Bold'];
    $nouns = ['Tiger', 'Eagle', 'River', 'Mountain', 'Ocean', 'Star', 'Moon', 'Sun', 'Wind', 'Storm'];
    $numbers = rand(100, 999);
    $symbols = ['!', '@', '#', '$'];
    
    $password = $adjectives[array_rand($adjectives)] . 
                $nouns[array_rand($nouns)] . 
                $numbers . 
                $symbols[array_rand($symbols)];
    
    return $password;
}

// Handle user creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!Security::validateCSRFToken($csrfToken)) {
        $error = 'Invalid security token.';
    } else {
        $username = Security::sanitizeInput($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $email = Security::sanitizeInput($_POST['email'] ?? '');
        $fullName = Security::sanitizeInput($_POST['full_name'] ?? '');
        $role = Security::sanitizeInput($_POST['role'] ?? '');
        $districtCode = Security::sanitizeInput($_POST['district_code'] ?? '');
        $localCode = Security::sanitizeInput($_POST['local_code'] ?? '');
        $seniorApproverId = !empty($_POST['senior_approver_id']) ? (int)$_POST['senior_approver_id'] : null;
        
        if (empty($username) || empty($password) || empty($email) || empty($fullName) || empty($role)) {
            $error = 'All fields are required.';
        } elseif (!Security::validateEmail($email)) {
            $error = 'Invalid email address.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters long.';
        } elseif (($role === 'local_limited' || $role === 'local_cfo') && empty($seniorApproverId)) {
            $error = 'Senior approver is required for Local (Limited) and Local CFO accounts.';
        } else {
            try {
                $passwordHash = Security::hashPassword($password);
                
                $stmt = $db->prepare("
                    INSERT INTO users (username, password_hash, email, full_name, role, district_code, local_code, senior_approver_id, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
                ");
                
                $stmt->execute([
                    $username,
                    $passwordHash,
                    $email,
                    $fullName,
                    $role,
                    $role !== 'admin' ? $districtCode : null,
                    ($role === 'local' || $role === 'local_limited' || $role === 'local_cfo') ? $localCode : null,
                    ($role === 'local_limited' || $role === 'local_cfo') ? $seniorApproverId : null
                ]);
                
                // Get the newly created user ID
                $newUserId = $db->lastInsertId();
                
                // Create default permissions for the new user
                createDefaultPermissions($newUserId, $role);
                
                $success = 'User created successfully!';
                
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $error = 'Username or email already exists.';
                } else {
                    error_log("Create user error: " . $e->getMessage());
                    $error = 'An error occurred while creating the user.';
                }
            }
        }
    }
}

// Handle user toggle status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!Security::validateCSRFToken($csrfToken)) {
        $error = 'Invalid security token.';
    } else {
        $userId = intval($_POST['user_id'] ?? 0);
        $isActive = intval($_POST['is_active'] ?? 0);
        
        if ($userId <= 0) {
            $error = 'Invalid user ID.';
        } else {
            try {
                // Toggle: if currently active (1), set to inactive (0), and vice versa
                $newStatus = $isActive ? 0 : 1;
                
                $stmt = $db->prepare("UPDATE users SET is_active = ? WHERE user_id = ?");
                $stmt->execute([$newStatus, $userId]);
                $success = 'User status updated successfully!';
            } catch (Exception $e) {
                error_log("Toggle user error: " . $e->getMessage());
                $error = 'An error occurred while updating user status.';
            }
        }
    }
}

// Handle user edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!Security::validateCSRFToken($csrfToken)) {
        $error = 'Invalid security token.';
    } else {
        $userId = intval($_POST['user_id'] ?? 0);
        $username = Security::sanitizeInput($_POST['username'] ?? '');
        $email = Security::sanitizeInput($_POST['email'] ?? '');
        $fullName = Security::sanitizeInput($_POST['full_name'] ?? '');
        $role = Security::sanitizeInput($_POST['role'] ?? '');
        $districtCode = Security::sanitizeInput($_POST['district_code'] ?? '');
        $localCode = Security::sanitizeInput($_POST['local_code'] ?? '');
        $seniorApproverId = !empty($_POST['senior_approver_id']) ? (int)$_POST['senior_approver_id'] : null;
        
        if ($userId <= 0) {
            $error = 'Invalid user ID.';
        } elseif (empty($username) || empty($email) || empty($fullName) || empty($role)) {
            $error = 'All fields are required.';
        } elseif (!Security::validateEmail($email)) {
            $error = 'Invalid email address.';
        } elseif (($role === 'local_limited' || $role === 'local_cfo') && empty($seniorApproverId)) {
            $error = 'Senior approver is required for Local (Limited) and Local CFO accounts.';
        } else {
            try {
                $stmt = $db->prepare("
                    UPDATE users 
                    SET username = ?, email = ?, full_name = ?, role = ?, 
                        district_code = ?, local_code = ?, senior_approver_id = ?
                    WHERE user_id = ?
                ");
                
                $stmt->execute([
                    $username,
                    $email,
                    $fullName,
                    $role,
                    $role !== 'admin' ? $districtCode : null,
                    ($role === 'local' || $role === 'local_limited' || $role === 'local_cfo') ? $localCode : null,
                    ($role === 'local_limited' || $role === 'local_cfo') ? $seniorApproverId : null,
                    $userId
                ]);
                
                // Update permissions based on role if needed
                createDefaultPermissions($userId, $role);
                
                $success = 'User updated successfully!';
                
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $error = 'Username or email already exists.';
                } else {
                    error_log("Edit user error: " . $e->getMessage());
                    $error = 'An error occurred while updating the user.';
                }
            }
        }
    }
}

// Handle password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_password') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!Security::validateCSRFToken($csrfToken)) {
        $error = 'Invalid security token.';
    } else {
        $userId = intval($_POST['user_id'] ?? 0);
        $newPassword = $_POST['new_password'] ?? '';
        
        if ($userId <= 0) {
            $error = 'Invalid user ID.';
        } elseif (empty($newPassword)) {
            $error = 'New password is required.';
        } elseif (strlen($newPassword) < 8) {
            $error = 'Password must be at least 8 characters long.';
        } else {
            try {
                $passwordHash = Security::hashPassword($newPassword);
                
                $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
                $stmt->execute([$passwordHash, $userId]);
                
                // Clear all remember me tokens for this user (force re-login)
                Security::clearAllRememberMeTokens($userId);
                
                $success = 'Password reset successfully! The user must log in with the new password.';
                
            } catch (Exception $e) {
                error_log("Reset password error: " . $e->getMessage());
                $error = 'An error occurred while resetting the password.';
            }
        }
    }
}

// Handle permissions update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_permissions') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!Security::validateCSRFToken($csrfToken)) {
        $error = 'Invalid security token.';
    } else {
        $userId = intval($_POST['user_id'] ?? 0);
        
        if ($userId <= 0) {
            $error = 'Invalid user ID.';
        } else {
            // Verify user exists
            $stmt = $db->prepare("SELECT user_id FROM users WHERE user_id = ?");
            $stmt->execute([$userId]);
            $userExists = $stmt->fetch();
            
            if (!$userExists) {
                $error = 'User not found.';
            } else {
                // Get all permission fields
                $permissions = [
                    'can_view_officers' => isset($_POST['can_view_officers']) ? 1 : 0,
                    'can_add_officers' => isset($_POST['can_add_officers']) ? 1 : 0,
                    'can_edit_officers' => isset($_POST['can_edit_officers']) ? 1 : 0,
                    'can_delete_officers' => isset($_POST['can_delete_officers']) ? 1 : 0,
                    'can_transfer_in' => isset($_POST['can_transfer_in']) ? 1 : 0,
                    'can_transfer_out' => isset($_POST['can_transfer_out']) ? 1 : 0,
                    'can_remove_officers' => isset($_POST['can_remove_officers']) ? 1 : 0,
                    'can_view_requests' => isset($_POST['can_view_requests']) ? 1 : 0,
                    'can_manage_requests' => isset($_POST['can_manage_requests']) ? 1 : 0,
                    'can_view_reports' => isset($_POST['can_view_reports']) ? 1 : 0,
                    'can_view_headcount' => isset($_POST['can_view_headcount']) ? 1 : 0,
                    'can_view_departments' => isset($_POST['can_view_departments']) ? 1 : 0,
                    'can_export_reports' => isset($_POST['can_export_reports']) ? 1 : 0,
                    'can_view_calendar' => isset($_POST['can_view_calendar']) ? 1 : 0,
                    'can_view_announcements' => isset($_POST['can_view_announcements']) ? 1 : 0,
                    'can_manage_users' => isset($_POST['can_manage_users']) ? 1 : 0,
                    'can_manage_announcements' => isset($_POST['can_manage_announcements']) ? 1 : 0,
                    'can_manage_districts' => isset($_POST['can_manage_districts']) ? 1 : 0,
                    'can_view_audit_log' => isset($_POST['can_view_audit_log']) ? 1 : 0,
                    'can_view_legacy_registry' => isset($_POST['can_view_legacy_registry']) ? 1 : 0,
                ];
                
                try {
                    // Check if permissions record exists
                    $stmt = $db->prepare("SELECT permission_id FROM user_permissions WHERE user_id = ?");
                    $stmt->execute([$userId]);
                    $existingPermission = $stmt->fetch();
                    
                    if ($existingPermission) {
                        // Update existing permissions
                        $sql = "UPDATE user_permissions SET ";
                        $fields = [];
                        $values = [];
                        foreach ($permissions as $key => $value) {
                            $fields[] = "$key = ?";
                            $values[] = $value;
                        }
                        $sql .= implode(', ', $fields);
                        $sql .= " WHERE user_id = ?";
                        $values[] = $userId;
                        
                        $stmt = $db->prepare($sql);
                        $stmt->execute($values);
                    } else {
                        // Insert new permissions
                        $fields = array_keys($permissions);
                        $placeholders = array_fill(0, count($permissions), '?');
                        
                        $sql = "INSERT INTO user_permissions (user_id, " . implode(', ', $fields) . ") 
                                VALUES (?, " . implode(', ', $placeholders) . ")";
                        
                        $values = array_merge([$userId], array_values($permissions));
                        $stmt = $db->prepare($sql);
                        $stmt->execute($values);
                    }
                    
                    $success = 'User permissions updated successfully!';
                } catch (Exception $e) {
                    error_log("Update permissions error: " . $e->getMessage());
                    $error = 'An error occurred while updating permissions: ' . $e->getMessage();
                }
            }
        }
    }
}


// Get all users
try {
    $stmt = $db->query("
        SELECT 
            u.user_id,
            u.username,
            u.email,
            u.full_name,
            u.role,
            u.district_code,
            u.local_code,
            u.senior_approver_id,
            u.is_active,
            u.last_login,
            u.created_at,
            d.district_name,
            lc.local_name,
            up.can_view_officers,
            up.can_add_officers,
            up.can_edit_officers,
            up.can_delete_officers,
            up.can_transfer_in,
            up.can_transfer_out,
            up.can_remove_officers,
            up.can_view_requests,
            up.can_manage_requests,
            up.can_view_reports,
            up.can_view_headcount,
            up.can_view_departments,
            up.can_export_reports,
            up.can_view_calendar,
            up.can_view_announcements,
            up.can_manage_users,
            up.can_manage_announcements,
            up.can_manage_districts,
            up.can_view_audit_log,
            up.can_view_legacy_registry
        FROM users u
        LEFT JOIN districts d ON u.district_code = d.district_code
        LEFT JOIN local_congregations lc ON u.local_code = lc.local_code
        LEFT JOIN user_permissions up ON u.user_id = up.user_id
        ORDER BY u.created_at DESC
    ");
    $users = $stmt->fetchAll();
    
    // Get districts for dropdown
    $stmt = $db->query("SELECT * FROM districts ORDER BY district_name");
    $districts = $stmt->fetchAll();
    
} catch (Exception $e) {
    error_log("Load users error: " . $e->getMessage());
    $users = [];
    $districts = [];
}

$pageTitle = 'Manage Users';
ob_start();
?>

<style>
    /* Disable auto-capitalization on all input fields */
    input {
        text-transform: none !important;
        -webkit-text-transform: none !important;
        -moz-text-transform: none !important;
        -ms-text-transform: none !important;
    }
</style>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h2 class="text-3xl font-bold text-gray-900">User Management</h2>
            <p class="text-sm text-gray-500">Manage system users and their access levels</p>
        </div>
        <button onclick="openCreateUserModal()" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition-colors">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path>
            </svg>
            Add New User
        </button>
    </div>
    
    <?php if (!empty($error)): ?>
        <div class="bg-red-50 border border-red-200 rounded-lg p-4">
            <div class="flex items-center">
                <svg class="w-5 h-5 text-red-600 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                </svg>
                <span class="text-sm font-medium text-red-800"><?php echo Security::escape($error); ?></span>
            </div>
        </div>
    <?php endif; ?>
    
    <?php if (!empty($success)): ?>
        <div class="bg-green-50 border border-green-200 rounded-lg p-4">
            <div class="flex items-center">
                <svg class="w-5 h-5 text-green-600 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                </svg>
                <span class="text-sm font-medium text-green-800"><?php echo Security::escape($success); ?></span>
            </div>
        </div>
    <?php endif; ?>
    
    <!-- Users Table -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Role</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Location</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Last Login</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200">
                    <?php foreach ($users as $user): ?>
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 bg-blue-600 text-white rounded-full flex items-center justify-center font-semibold">
                                        <span><?php echo substr($user['full_name'], 0, 1); ?></span>
                                    </div>
                                    <div>
                                        <div class="font-semibold text-gray-900"><?php echo Security::escape($user['full_name']); ?></div>
                                        <div class="text-xs text-gray-500">@<?php echo Security::escape($user['username']); ?></div>
                                        <div class="text-xs text-gray-400"><?php echo Security::escape($user['email']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?php 
                                    echo $user['role'] === 'admin' ? 'bg-red-100 text-red-800' : 
                                        ($user['role'] === 'district' ? 'bg-yellow-100 text-yellow-800' : 
                                        ($user['role'] === 'local_limited' ? 'bg-purple-100 text-purple-800' : 'bg-blue-100 text-blue-800')); 
                                ?>">
                                    <?php 
                                    if ($user['role'] === 'local_limited') {
                                        echo 'Local (Limited)';
                                    } else {
                                        echo ucfirst($user['role']);
                                    }
                                    ?>
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <?php if ($user['role'] === 'admin'): ?>
                                    <span class="text-xs text-gray-500">All Locations</span>
                                <?php elseif ($user['role'] === 'district'): ?>
                                    <div class="text-sm text-gray-900"><?php echo Security::escape($user['district_name']); ?></div>
                                <?php else: ?>
                                    <div class="text-sm text-gray-900"><?php echo Security::escape($user['local_name']); ?></div>
                                    <div class="text-xs text-gray-500"><?php echo Security::escape($user['district_name']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4">
                                <?php if ($user['last_login']): ?>
                                    <div class="text-sm text-gray-900"><?php echo formatDateTime($user['last_login']); ?></div>
                                <?php else: ?>
                                    <span class="text-xs text-gray-400">Never</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?php echo $user['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                    <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-2">
                                    <?php if ($user['user_id'] != $currentUser['user_id']): ?>
                                        <button onclick="openEditUserModal(<?php echo htmlspecialchars(json_encode($user)); ?>)" class="inline-flex items-center p-2 text-green-600 hover:text-green-900 hover:bg-green-50 rounded transition-colors" title="Edit User">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                            </svg>
                                        </button>
                                        
                                        <button onclick="openResetPasswordModal(<?php echo $user['user_id']; ?>, '<?php echo Security::escape($user['full_name']); ?>')" class="inline-flex items-center p-2 text-orange-600 hover:text-orange-900 hover:bg-orange-50 rounded transition-colors" title="Reset Password">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"></path>
                                            </svg>
                                        </button>
                                        
                                        <button onclick="openPermissionsModal(<?php echo htmlspecialchars(json_encode($user)); ?>)" class="inline-flex items-center p-2 text-blue-600 hover:text-blue-900 hover:bg-blue-50 rounded transition-colors" title="Manage Permissions">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                            </svg>
                                        </button>
                                        
                                        <form method="POST" action="" class="inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                            <input type="hidden" name="is_active" value="<?php echo $user['is_active']; ?>">
                                            <button type="submit" class="inline-flex items-center p-2 text-gray-600 hover:text-gray-900 hover:bg-gray-100 rounded transition-colors" title="<?php echo $user['is_active'] ? 'Deactivate' : 'Activate'; ?>">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <?php if ($user['is_active']): ?>
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"></path>
                                                    <?php else: ?>
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                                    <?php endif; ?>
                                                </svg>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-xs text-gray-400">Current User</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Create User Modal -->
<div id="createUserModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex items-center justify-center min-h-screen px-4 py-6">
        <div onclick="closeCreateUserModal()" class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity"></div>
        <div onclick="event.stopPropagation()" class="relative bg-white dark:bg-gray-800 rounded-lg shadow-xl w-full max-w-2xl p-6 transform transition-all">
            
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-bold text-gray-900">Create New User</h3>
                <button onclick="closeCreateUserModal()" class="text-gray-400 hover:text-gray-500">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
        
        <form method="POST" action="" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
            <input type="hidden" name="action" value="create">
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Username *</label>
                    <input type="text" name="username" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors" autocapitalize="off" autocorrect="off" required>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Email *</label>
                    <input type="email" name="email" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors" required>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Full Name *</label>
                    <input type="text" name="full_name" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors" required>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Password *</label>
                    <div class="relative">
                        <input type="password" id="create-password" name="password" class="w-full px-4 py-2 pr-24 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors" minlength="8" autocomplete="off" autocapitalize="off" autocorrect="off" required>
                        <button type="button" onclick="generatePassword('create-password')" class="absolute right-2 top-1/2 transform -translate-y-1/2 px-3 py-1 text-xs bg-blue-100 text-blue-700 hover:bg-blue-200 rounded transition-colors">
                            Generate
                        </button>
                    </div>
                    <p class="mt-1 text-xs text-gray-500">Min 8 characters or click Generate for easy-to-remember password</p>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Role *</label>
                    <select name="role" id="userRole" onchange="toggleUserFields()" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors" required>
                        <option value="local">Local User</option>
                        <option value="local_limited">Local (Limited) - Requires Approval</option>
                        <option value="local_cfo">Local CFO - CFO Registry Only</option>
                        <option value="district">District User</option>
                        <option value="admin">Administrator</option>
                    </select>
                </div>
                
                <div id="districtSelectField">
                    <label class="block text-sm font-medium text-gray-700 mb-2">District *</label>
                    <div class="relative">
                        <input 
                            type="text" 
                            id="modal-district-display"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 cursor-pointer bg-white dark:bg-gray-800"
                            placeholder="Select District"
                            readonly
                            onclick="openDistrictModal()"
                            value=""
                        >
                        <input type="hidden" name="district_code" id="modal-district-value">
                        <div class="absolute inset-y-0 right-0 flex items-center px-3 pointer-events-none">
                            <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </div>
                    </div>
                </div>
                
                <div id="localSelectField">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Local Congregation *</label>
                    <div class="relative">
                        <input 
                            type="text" 
                            id="modal-local-display"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 cursor-pointer bg-white dark:bg-gray-800"
                            placeholder="Select Local Congregation"
                            readonly
                            onclick="openLocalModal()"
                            value=""
                        >
                        <input type="hidden" name="local_code" id="modal-local-value">
                        <div class="absolute inset-y-0 right-0 flex items-center px-3 pointer-events-none">
                            <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </div>
                    </div>
                </div>
                
                <div id="seniorApproverField" style="display: none;">
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Senior Approver (Local Account) *
                        <span class="text-xs text-gray-500">- This senior account will approve all actions</span>
                    </label>
                    <select name="senior_approver_id" id="seniorApproverSelect" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors">
                        <option value="">Select Senior Approver</option>
                    </select>
                </div>
            </div>
            
            <div class="flex items-center justify-end space-x-3 pt-4">
                <button type="button" onclick="closeCreateUserModal()" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg text-gray-700 bg-white dark:bg-gray-800 hover:bg-gray-50 transition-colors">Cancel</button>
                <button type="submit" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition-colors">Create User</button>
            </div>
        </form>
        </div>
    </div>
</div>

<script>
// Modal functions
let currentLocals = [];
let editCurrentLocals = [];

// Password generator function
function generatePassword(fieldId) {
    const adjectives = ['Happy', 'Lucky', 'Swift', 'Bright', 'Smart', 'Quick', 'Brave', 'Calm', 'Clear', 'Bold'];
    const nouns = ['Tiger', 'Eagle', 'River', 'Mountain', 'Ocean', 'Star', 'Moon', 'Sun', 'Wind', 'Storm'];
    const numbers = Math.floor(Math.random() * 900) + 100;
    const symbols = ['!', '@', '#', '$'];
    
    const password = adjectives[Math.floor(Math.random() * adjectives.length)] + 
                    nouns[Math.floor(Math.random() * nouns.length)] + 
                    numbers + 
                    symbols[Math.floor(Math.random() * symbols.length)];
    
    const field = document.getElementById(fieldId);
    field.type = 'text'; // Show generated password
    field.value = password;
    
    // Copy to clipboard
    navigator.clipboard.writeText(password).then(() => {
        // Show temporary success message
        const btn = field.nextElementSibling;
        const originalText = btn.textContent;
        btn.textContent = 'Copied!';
        btn.classList.add('bg-green-100', 'text-green-700');
        btn.classList.remove('bg-blue-100', 'text-blue-700');
        
        setTimeout(() => {
            btn.textContent = originalText;
            btn.classList.remove('bg-green-100', 'text-green-700');
            btn.classList.add('bg-blue-100', 'text-blue-700');
        }, 2000);
    });
}

// Current user data for auto-selection
const currentUserDistrict = '<?php echo $currentUser['district_code'] ?? ''; ?>';
const currentUserDistrictName = '<?php echo isset($currentUser['district_code']) ? Security::escape($currentUser['district_name'] ?? '') : ''; ?>';
const currentUserLocal = '<?php echo $currentUser['local_code'] ?? ''; ?>';
const currentUserLocalName = '<?php echo isset($currentUser['local_code']) ? Security::escape($currentUser['local_name'] ?? '') : ''; ?>';
const currentUserRole = '<?php echo $currentUser['role']; ?>';

function openCreateUserModal() {
    const modal = document.getElementById('createUserModal');
    modal.classList.remove('hidden');
    modal.style.display = 'block';
    toggleUserFields();
}

function closeCreateUserModal() {
    const modal = document.getElementById('createUserModal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
}

function toggleUserFields() {
    const role = document.getElementById('userRole').value;
    const districtField = document.getElementById('districtSelectField');
    const localField = document.getElementById('localSelectField');
    const seniorApproverField = document.getElementById('seniorApproverField');
    const seniorApproverSelect = document.getElementById('seniorApproverSelect');
    const localValue = document.getElementById('modal-local-value').value;
    
    const districtDisplay = document.getElementById('modal-district-display');
    const localDisplay = document.getElementById('modal-local-display');
    
    if (role === 'admin') {
        districtField.style.display = 'none';
        localField.style.display = 'none';
        seniorApproverField.style.display = 'none';
        seniorApproverSelect.removeAttribute('required');
    } else if (role === 'district') {
        districtField.style.display = 'block';
        localField.style.display = 'none';
        seniorApproverField.style.display = 'none';
        seniorApproverSelect.removeAttribute('required');
        
        // Re-enable district field
        districtDisplay.onclick = () => openDistrictModal();
        districtDisplay.classList.remove('bg-gray-100', 'cursor-not-allowed');
        districtDisplay.classList.add('cursor-pointer', 'bg-white');
    } else if (role === 'local_limited') {
        districtField.style.display = 'block';
        localField.style.display = 'block';
        seniorApproverField.style.display = 'block';
        seniorApproverSelect.setAttribute('required', 'required');
        
        // Auto-select current user's district and local for local_limited users
        if (currentUserRole === 'local' && currentUserDistrict && currentUserLocal) {
            // Set district
            document.getElementById('modal-district-value').value = currentUserDistrict;
            document.getElementById('modal-district-display').value = currentUserDistrictName;
            
            // Set local
            document.getElementById('modal-local-value').value = currentUserLocal;
            document.getElementById('modal-local-display').value = currentUserLocalName;
            
            // Disable district and local fields (auto-locked to senior's location)
            districtDisplay.onclick = null;
            districtDisplay.classList.add('bg-gray-100', 'cursor-not-allowed');
            districtDisplay.classList.remove('cursor-pointer', 'bg-white');
            
            localDisplay.onclick = null;
            localDisplay.classList.add('bg-gray-100', 'cursor-not-allowed');
            localDisplay.classList.remove('cursor-pointer', 'bg-white');
            
            // Load locals for this district (for internal state)
            loadLocalsForModal(currentUserDistrict);
            
            // Load senior approvers
            loadSeniorApprovers(currentUserLocal);
        } else if (localValue) {
            // If local is already selected manually, load senior approvers
            loadSeniorApprovers(localValue);
        }
    } else if (role === 'local_cfo') {
        districtField.style.display = 'block';
        localField.style.display = 'block';
        seniorApproverField.style.display = 'block';
        seniorApproverSelect.setAttribute('required', 'required');
        
        // Re-enable fields for manual selection
        districtDisplay.onclick = () => openDistrictModal();
        districtDisplay.classList.remove('bg-gray-100', 'cursor-not-allowed');
        districtDisplay.classList.add('cursor-pointer', 'bg-white');
        
        localDisplay.onclick = () => openLocalModal();
        localDisplay.classList.remove('bg-gray-100', 'cursor-not-allowed');
        localDisplay.classList.add('cursor-pointer', 'bg-white');
        
        // Load senior approvers if local already selected
        if (localValue) {
            loadSeniorApprovers(localValue);
        }
    } else { // local
        districtField.style.display = 'block';
        localField.style.display = 'block';
        seniorApproverField.style.display = 'none';
        seniorApproverSelect.removeAttribute('required');
        
        // Re-enable fields
        districtDisplay.onclick = () => openDistrictModal();
        districtDisplay.classList.remove('bg-gray-100', 'cursor-not-allowed');
        districtDisplay.classList.add('cursor-pointer', 'bg-white');
        
        localDisplay.onclick = () => openLocalModal();
        localDisplay.classList.remove('bg-gray-100', 'cursor-not-allowed');
        localDisplay.classList.add('cursor-pointer', 'bg-white');
    }
}

function openDistrictModal() {
    const modal = document.getElementById('district-modal');
    modal.classList.remove('hidden');
    modal.style.display = 'block';
    document.getElementById('district-search').focus();
}

function closeDistrictModal() {
    const modal = document.getElementById('district-modal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
    document.getElementById('district-search').value = '';
    filterDistricts();
}

function selectDistrict(code, name) {
    document.getElementById('modal-district-value').value = code;
    document.getElementById('modal-district-display').value = name;
    
    // Clear local selection
    document.getElementById('modal-local-value').value = '';
    document.getElementById('modal-local-display').value = '';
    
    // Load locals for this district
    loadLocalsForModal(code);
    closeDistrictModal();
}

function filterDistricts() {
    const search = document.getElementById('district-search').value.toLowerCase();
    const items = document.querySelectorAll('.district-item');
    items.forEach(item => {
        const text = item.textContent.toLowerCase();
        item.style.display = text.includes(search) ? 'block' : 'none';
    });
}

function loadLocalsForModal(districtCode) {
    fetch('<?php echo BASE_URL; ?>/api/get-locals.php?district=' + districtCode)
        .then(response => response.json())
        .then(data => {
            currentLocals = data;
        })
        .catch(error => console.error('Error loading locals:', error));
}

function openLocalModal() {
    const districtCode = document.getElementById('modal-district-value').value;
    if (!districtCode) {
        alert('Please select a district first');
        return;
    }
    
    const modal = document.getElementById('local-modal');
    const listContainer = document.getElementById('local-list');
    
    // Populate list
    listContainer.innerHTML = '';
    currentLocals.forEach(local => {
        const div = document.createElement('div');
        div.className = 'local-item px-4 py-3 hover:bg-gray-50 cursor-pointer border-b border-gray-100';
        div.textContent = local.local_name;
        div.onclick = () => selectLocal(local.local_code, local.local_name);
        listContainer.appendChild(div);
    });
    
    modal.classList.remove('hidden');
    modal.style.display = 'block';
    document.getElementById('local-search').focus();
}

function closeLocalModal() {
    const modal = document.getElementById('local-modal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
    document.getElementById('local-search').value = '';
    filterLocals();
}

function selectLocal(code, name) {
    document.getElementById('modal-local-value').value = code;
    document.getElementById('modal-local-display').value = name;
    
    // Load senior approvers for this local if role is local_limited
    const role = document.getElementById('userRole').value;
    if (role === 'local_limited') {
        loadSeniorApprovers(code);
    }
    
    closeLocalModal();
}

function loadSeniorApprovers(localCode) {
    // Fetch local users from the same local congregation who can be approvers
    fetch('<?php echo BASE_URL; ?>/api/get-senior-approvers.php?local=' + localCode)
        .then(response => response.json())
        .then(data => {
            const select = document.getElementById('seniorApproverSelect');
            select.innerHTML = '<option value="">Select Senior Approver</option>';
            
            data.forEach(user => {
                const option = document.createElement('option');
                option.value = user.user_id;
                option.textContent = user.full_name + ' (' + user.username + ')';
                select.appendChild(option);
            });
            
            if (data.length === 0) {
                select.innerHTML = '<option value="">No local users available</option>';
            }
        })
        .catch(error => {
            console.error('Error loading senior approvers:', error);
            const select = document.getElementById('seniorApproverSelect');
            select.innerHTML = '<option value="">Error loading approvers</option>';
        });
}

function filterLocals() {
    const search = document.getElementById('local-search').value.toLowerCase();
    const items = document.querySelectorAll('.local-item');
    items.forEach(item => {
        const text = item.textContent.toLowerCase();
        item.style.display = text.includes(search) ? 'block' : 'none';
    });
}

// Permissions modal functions
function openPermissionsModal(user) {
    const modal = document.getElementById('permissionsModal');
    const form = document.getElementById('permissionsForm');
    const title = document.getElementById('permissionsModalTitle');
    
    console.log('Opening permissions modal for user:', user);
    
    // Set title with user name (use full_name from PHP)
    title.textContent = 'Manage Permissions: ' + (user.full_name || user.name || 'User');
    
    // Set user_id
    document.getElementById('permissions_user_id').value = user.user_id;
    
    console.log('Set user_id to:', user.user_id);
    
    // Define all permission fields
    const permissions = [
        'can_view_officers',
        'can_add_officers',
        'can_edit_officers',
        'can_delete_officers',
        'can_transfer_in',
        'can_transfer_out',
        'can_remove_officers',
        'can_view_requests',
        'can_manage_requests',
        'can_view_reports',
        'can_view_headcount',
        'can_view_departments',
        'can_export_reports',
        'can_view_calendar',
        'can_view_announcements',
        'can_manage_users',
        'can_manage_announcements',
        'can_manage_districts',
        'can_view_audit_log',
        'can_view_legacy_registry'
    ];
    
    // Check boxes based on user permissions
    permissions.forEach(permission => {
        const checkbox = form.querySelector(`input[name="${permission}"]`);
        if (checkbox) {
            // Check if user has this permission (value is 1 or true)
            checkbox.checked = user[permission] == 1 || user[permission] === true;
        }
    });
    
    // Show modal
    modal.classList.remove('hidden');
    modal.style.display = 'block';
}

function closePermissionsModal() {
    const modal = document.getElementById('permissionsModal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
    
    // Reset form
    document.getElementById('permissionsForm').reset();
}

// Close modal with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const permissionsModal = document.getElementById('permissionsModal');
        if (permissionsModal && !permissionsModal.classList.contains('hidden')) {
            closePermissionsModal();
        }
        const editModal = document.getElementById('editUserModal');
        if (editModal && !editModal.classList.contains('hidden')) {
            closeEditUserModal();
        }
        const resetModal = document.getElementById('resetPasswordModal');
        if (resetModal && !resetModal.classList.contains('hidden')) {
            closeResetPasswordModal();
        }
    }
});

// Edit User Modal Functions
function openEditUserModal(user) {
    const modal = document.getElementById('editUserModal');
    
    // Populate form fields
    document.getElementById('edit-user-id').value = user.user_id;
    document.getElementById('edit-username').value = user.username;
    document.getElementById('edit-email').value = user.email;
    document.getElementById('edit-full-name').value = user.full_name;
    document.getElementById('edit-userRole').value = user.role;
    
    // Set district and local if available
    if (user.district_code) {
        document.getElementById('edit-district-value').value = user.district_code;
        document.getElementById('edit-district-display').value = user.district_name || '';
        loadLocalsForEditModal(user.district_code);
    }
    
    if (user.local_code) {
        document.getElementById('edit-local-value').value = user.local_code;
        document.getElementById('edit-local-display').value = user.local_name || '';
    }
    
    // Store senior approver ID for later use
    if (user.senior_approver_id) {
        window.editUserSeniorApproverId = user.senior_approver_id;
    } else {
        window.editUserSeniorApproverId = null;
    }
    
    // Toggle fields based on role
    toggleEditUserFields();
    
    // Show modal
    modal.classList.remove('hidden');
    modal.style.display = 'block';
}

function closeEditUserModal() {
    const modal = document.getElementById('editUserModal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
}

function toggleEditUserFields() {
    const role = document.getElementById('edit-userRole').value;
    const districtField = document.getElementById('edit-districtSelectField');
    const localField = document.getElementById('edit-localSelectField');
    const seniorApproverField = document.getElementById('edit-seniorApproverField');
    const seniorApproverSelect = document.getElementById('edit-seniorApproverSelect');
    
    const districtDisplay = document.getElementById('edit-district-display');
    const localDisplay = document.getElementById('edit-local-display');
    
    if (role === 'admin') {
        districtField.style.display = 'none';
        localField.style.display = 'none';
        seniorApproverField.style.display = 'none';
        seniorApproverSelect.removeAttribute('required');
    } else if (role === 'district') {
        districtField.style.display = 'block';
        localField.style.display = 'none';
        seniorApproverField.style.display = 'none';
        seniorApproverSelect.removeAttribute('required');
    } else if (role === 'local_limited') {
        districtField.style.display = 'block';
        localField.style.display = 'block';
        seniorApproverField.style.display = 'block';
        seniorApproverSelect.setAttribute('required', 'required');
        
        const localValue = document.getElementById('edit-local-value').value;
        if (localValue) {
            loadSeniorApproversForEdit(localValue);
        }
    } else if (role === 'local_cfo') {
        districtField.style.display = 'block';
        localField.style.display = 'block';
        seniorApproverField.style.display = 'block';
        seniorApproverSelect.setAttribute('required', 'required');
        
        const localValue = document.getElementById('edit-local-value').value;
        if (localValue) {
            loadSeniorApproversForEdit(localValue);
        }
    } else { // local
        districtField.style.display = 'block';
        localField.style.display = 'block';
        seniorApproverField.style.display = 'none';
        seniorApproverSelect.removeAttribute('required');
    }
}

function openEditDistrictModal() {
    const modal = document.getElementById('district-modal');
    modal.classList.remove('hidden');
    modal.style.display = 'block';
    
    // Override selectDistrict to update edit fields
    window.tempSelectDistrict = window.selectDistrict;
    window.selectDistrict = (code, name) => {
        document.getElementById('edit-district-value').value = code;
        document.getElementById('edit-district-display').value = name;
        document.getElementById('edit-local-value').value = '';
        document.getElementById('edit-local-display').value = '';
        loadLocalsForEditModal(code);
        closeDistrictModal();
        window.selectDistrict = window.tempSelectDistrict;
    };
    
    document.getElementById('district-search').focus();
}

function openEditLocalModal() {
    const districtCode = document.getElementById('edit-district-value').value;
    if (!districtCode) {
        alert('Please select a district first');
        return;
    }
    
    const modal = document.getElementById('local-modal');
    const listContainer = document.getElementById('local-list');
    
    // Populate list
    listContainer.innerHTML = '';
    editCurrentLocals.forEach(local => {
        const div = document.createElement('div');
        div.className = 'local-item px-4 py-3 hover:bg-gray-50 cursor-pointer border-b border-gray-100';
        div.textContent = local.local_name;
        div.onclick = () => selectEditLocal(local.local_code, local.local_name);
        listContainer.appendChild(div);
    });
    
    modal.classList.remove('hidden');
    modal.style.display = 'block';
    document.getElementById('local-search').focus();
}

function selectEditLocal(code, name) {
    document.getElementById('edit-local-value').value = code;
    document.getElementById('edit-local-display').value = name;
    
    const role = document.getElementById('edit-userRole').value;
    if (role === 'local_limited') {
        loadSeniorApproversForEdit(code);
    }
    
    closeLocalModal();
}

function loadLocalsForEditModal(districtCode) {
    fetch('<?php echo BASE_URL; ?>/api/get-locals.php?district=' + districtCode)
        .then(response => response.json())
        .then(data => {
            editCurrentLocals = data;
        })
        .catch(error => console.error('Error loading locals:', error));
}

function loadSeniorApproversForEdit(localCode) {
    fetch('<?php echo BASE_URL; ?>/api/get-senior-approvers.php?local=' + localCode)
        .then(response => response.json())
        .then(data => {
            const select = document.getElementById('edit-seniorApproverSelect');
            select.innerHTML = '<option value="">Select Senior Approver</option>';
            
            data.forEach(user => {
                const option = document.createElement('option');
                option.value = user.user_id;
                option.textContent = user.full_name + ' (' + user.username + ')';
                select.appendChild(option);
            });
            
            // Set the selected value if we have one stored
            if (window.editUserSeniorApproverId) {
                select.value = window.editUserSeniorApproverId;
            }
            
            if (data.length === 0) {
                select.innerHTML = '<option value="">No local users available</option>';
            }
        })
        .catch(error => {
            console.error('Error loading senior approvers:', error);
        });
}

// Reset Password Modal Functions
function openResetPasswordModal(userId, userName) {
    const modal = document.getElementById('resetPasswordModal');
    document.getElementById('reset-user-id').value = userId;
    document.getElementById('reset-user-name').textContent = userName;
    document.getElementById('reset-password').value = '';
    
    modal.classList.remove('hidden');
    modal.style.display = 'block';
}

function closeResetPasswordModal() {
    const modal = document.getElementById('resetPasswordModal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
}

// Close modal with Escape key
</script>

<!-- District Modal -->
<div id="district-modal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity" onclick="closeDistrictModal()"></div>
        <div class="relative bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-md w-full max-h-[80vh] flex flex-col">
            <div class="flex items-center justify-between p-4 border-b">
                <h3 class="text-lg font-semibold text-gray-900">Select District</h3>
                <button type="button" onclick="closeDistrictModal()" class="text-gray-400 hover:text-gray-500">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            <div class="p-4 border-b">
                <input type="text" id="district-search" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Search districts..." oninput="filterDistricts()">
            </div>
            <div class="overflow-y-auto flex-1">
                <?php foreach ($districts as $district): ?>
                    <div class="district-item px-4 py-3 hover:bg-gray-50 cursor-pointer border-b border-gray-100" onclick="selectDistrict('<?php echo Security::escape($district['district_code']); ?>', '<?php echo Security::escape($district['district_name']); ?>')">
                        <span class="text-gray-900"><?php echo Security::escape($district['district_name']); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- Local Modal -->
<div id="local-modal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity" onclick="closeLocalModal()"></div>
        <div class="relative bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-md w-full max-h-[80vh] flex flex-col">
            <div class="flex items-center justify-between p-4 border-b">
                <h3 class="text-lg font-semibold text-gray-900">Select Local Congregation</h3>
                <button type="button" onclick="closeLocalModal()" class="text-gray-400 hover:text-gray-500">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            <div class="p-4 border-b">
                <input type="text" id="local-search" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Search local congregations..." oninput="filterLocals()">
            </div>
            <div id="local-list" class="overflow-y-auto flex-1">
                <!-- Will be populated dynamically -->
            </div>
        </div>
    </div>
</div>

<!-- Edit User Modal -->
<div id="editUserModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex items-center justify-center min-h-screen px-4 py-6">
        <div onclick="closeEditUserModal()" class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity"></div>
        <div onclick="event.stopPropagation()" class="relative bg-white dark:bg-gray-800 rounded-lg shadow-xl w-full max-w-2xl p-6 transform transition-all">
            
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-bold text-gray-900">Edit User</h3>
                <button onclick="closeEditUserModal()" class="text-gray-400 hover:text-gray-500">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
        
        <form method="POST" action="" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="user_id" id="edit-user-id">
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Username *</label>
                    <input type="text" name="username" id="edit-username" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors" autocapitalize="off" autocorrect="off" required>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Email *</label>
                    <input type="email" name="email" id="edit-email" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors" required>
                </div>
                
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Full Name *</label>
                    <input type="text" name="full_name" id="edit-full-name" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors" required>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Role *</label>
                    <select name="role" id="edit-userRole" onchange="toggleEditUserFields()" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors" required>
                        <option value="local">Local User</option>
                        <option value="local_limited">Local (Limited) - Requires Approval</option>
                        <option value="local_cfo">Local CFO - CFO Registry Only</option>
                        <option value="district">District User</option>
                        <option value="admin">Administrator</option>
                    </select>
                </div>
                
                <div id="edit-districtSelectField">
                    <label class="block text-sm font-medium text-gray-700 mb-2">District *</label>
                    <div class="relative">
                        <input 
                            type="text" 
                            id="edit-district-display"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 cursor-pointer bg-white dark:bg-gray-800"
                            placeholder="Select District"
                            readonly
                            onclick="openEditDistrictModal()"
                            value=""
                        >
                        <input type="hidden" name="district_code" id="edit-district-value">
                        <div class="absolute inset-y-0 right-0 flex items-center px-3 pointer-events-none">
                            <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </div>
                    </div>
                </div>
                
                <div id="edit-localSelectField">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Local Congregation *</label>
                    <div class="relative">
                        <input 
                            type="text" 
                            id="edit-local-display"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 cursor-pointer bg-white dark:bg-gray-800"
                            placeholder="Select Local Congregation"
                            readonly
                            onclick="openEditLocalModal()"
                            value=""
                        >
                        <input type="hidden" name="local_code" id="edit-local-value">
                        <div class="absolute inset-y-0 right-0 flex items-center px-3 pointer-events-none">
                            <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </div>
                    </div>
                </div>
                
                <div id="edit-seniorApproverField" style="display: none;" class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Senior Approver (Local Account) *
                        <span class="text-xs text-gray-500">- This senior account will approve all actions</span>
                    </label>
                    <select name="senior_approver_id" id="edit-seniorApproverSelect" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors">
                        <option value="">Select Senior Approver</option>
                    </select>
                </div>
            </div>
            
            <div class="flex items-center justify-end space-x-3 pt-4">
                <button type="button" onclick="closeEditUserModal()" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg text-gray-700 bg-white dark:bg-gray-800 hover:bg-gray-50 transition-colors">Cancel</button>
                <button type="submit" class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 focus:ring-2 focus:ring-green-500 focus:ring-offset-2 transition-colors">Update User</button>
            </div>
        </form>
        </div>
    </div>
</div>

<!-- Reset Password Modal -->
<div id="resetPasswordModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex items-center justify-center min-h-screen px-4 py-6">
        <div onclick="closeResetPasswordModal()" class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity"></div>
        <div onclick="event.stopPropagation()" class="relative bg-white dark:bg-gray-800 rounded-lg shadow-xl w-full max-w-md p-6 transform transition-all">
            
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-bold text-gray-900">Reset Password</h3>
                <button onclick="closeResetPasswordModal()" class="text-gray-400 hover:text-gray-500">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            
            <p class="text-sm text-gray-600 mb-4">Reset password for: <span id="reset-user-name" class="font-semibold"></span></p>
        
        <form method="POST" action="" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="user_id" id="reset-user-id">
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">New Password *</label>
                <div class="relative">
                    <input type="text" id="reset-password" name="new_password" class="w-full px-4 py-2 pr-24 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors" minlength="8" autocomplete="off" autocapitalize="off" required>
                    <button type="button" onclick="generatePassword('reset-password')" class="absolute right-2 top-1/2 transform -translate-y-1/2 px-3 py-1 text-xs bg-blue-100 text-blue-700 hover:bg-blue-200 rounded transition-colors">
                        Generate
                    </button>
                </div>
                <p class="mt-1 text-xs text-gray-500">Min 8 characters or click Generate for easy-to-remember password</p>
            </div>
            
            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-3">
                <div class="flex items-start">
                    <svg class="w-5 h-5 text-yellow-600 mr-2 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                    </svg>
                    <p class="text-xs text-yellow-800">The user will be forced to log out from all devices and must use this new password to log in.</p>
                </div>
            </div>
            
            <div class="flex items-center justify-end space-x-3 pt-4">
                <button type="button" onclick="closeResetPasswordModal()" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg text-gray-700 bg-white dark:bg-gray-800 hover:bg-gray-50 transition-colors">Cancel</button>
                <button type="submit" class="inline-flex items-center px-4 py-2 bg-orange-600 text-white rounded-lg hover:bg-orange-700 focus:ring-2 focus:ring-orange-500 focus:ring-offset-2 transition-colors">Reset Password</button>
            </div>
        </form>
        </div>
    </div>
</div>

<!-- Permissions Management Modal -->
<div id="permissionsModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex items-center justify-center min-h-screen px-4 py-6">
        <div onclick="closePermissionsModal()" class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity"></div>
        <div onclick="event.stopPropagation()" class="relative bg-white dark:bg-gray-800 rounded-lg shadow-xl w-full max-w-4xl p-6 transform transition-all max-h-[85vh] overflow-y-auto">
            
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-bold text-gray-900" id="permissionsModalTitle">Manage User Permissions</h3>
                <button onclick="closePermissionsModal()" class="text-gray-400 hover:text-gray-500">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            
            <form method="POST" action="" id="permissionsForm" class="space-y-6">
                <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="update_permissions">
                <input type="hidden" name="user_id" id="permissions_user_id" value="">
                
                <!-- Officer Management -->
                <div class="bg-gray-50 rounded-lg p-4">
                    <h4 class="text-md font-bold text-gray-900 mb-3 flex items-center">
                        <svg class="w-5 h-5 mr-2 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                        </svg>
                        Officer Management
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <label class="flex items-center p-3 bg-white dark:bg-gray-800 rounded border border-gray-200 hover:bg-blue-50 cursor-pointer transition-colors">
                            <input type="checkbox" name="can_view_officers" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                            <span class="ml-2 text-sm text-gray-700">View Officers</span>
                        </label>
                        <label class="flex items-center p-3 bg-white dark:bg-gray-800 rounded border border-gray-200 hover:bg-blue-50 cursor-pointer transition-colors">
                            <input type="checkbox" name="can_add_officers" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                            <span class="ml-2 text-sm text-gray-700">Add Officers</span>
                        </label>
                        <label class="flex items-center p-3 bg-white dark:bg-gray-800 rounded border border-gray-200 hover:bg-blue-50 cursor-pointer transition-colors">
                            <input type="checkbox" name="can_edit_officers" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                            <span class="ml-2 text-sm text-gray-700">Edit Officers</span>
                        </label>
                        <label class="flex items-center p-3 bg-white dark:bg-gray-800 rounded border border-gray-200 hover:bg-blue-50 cursor-pointer transition-colors">
                            <input type="checkbox" name="can_delete_officers" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                            <span class="ml-2 text-sm text-gray-700">Delete Officers</span>
                        </label>
                    </div>
                </div>
                
                <!-- Transfers & Removal -->
                <div class="bg-gray-50 rounded-lg p-4">
                    <h4 class="text-md font-bold text-gray-900 mb-3 flex items-center">
                        <svg class="w-5 h-5 mr-2 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"></path>
                        </svg>
                        Transfers & Removal
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <label class="flex items-center p-3 bg-white dark:bg-gray-800 rounded border border-gray-200 hover:bg-green-50 cursor-pointer transition-colors">
                            <input type="checkbox" name="can_transfer_in" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                            <span class="ml-2 text-sm text-gray-700">Transfer In</span>
                        </label>
                        <label class="flex items-center p-3 bg-white dark:bg-gray-800 rounded border border-gray-200 hover:bg-green-50 cursor-pointer transition-colors">
                            <input type="checkbox" name="can_transfer_out" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                            <span class="ml-2 text-sm text-gray-700">Transfer Out</span>
                        </label>
                        <label class="flex items-center p-3 bg-white dark:bg-gray-800 rounded border border-gray-200 hover:bg-green-50 cursor-pointer transition-colors">
                            <input type="checkbox" name="can_remove_officers" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                            <span class="ml-2 text-sm text-gray-700">Remove Officers</span>
                        </label>
                    </div>
                </div>
                
                <!-- Requests Management -->
                <div class="bg-gray-50 rounded-lg p-4">
                    <h4 class="text-md font-bold text-gray-900 mb-3 flex items-center">
                        <svg class="w-5 h-5 mr-2 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        Officer Requests
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <label class="flex items-center p-3 bg-white dark:bg-gray-800 rounded border border-gray-200 hover:bg-purple-50 cursor-pointer transition-colors">
                            <input type="checkbox" name="can_view_requests" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                            <span class="ml-2 text-sm text-gray-700">View Requests</span>
                        </label>
                        <label class="flex items-center p-3 bg-white dark:bg-gray-800 rounded border border-gray-200 hover:bg-purple-50 cursor-pointer transition-colors">
                            <input type="checkbox" name="can_manage_requests" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                            <span class="ml-2 text-sm text-gray-700">Manage Requests (Approve/Reject)</span>
                        </label>
                    </div>
                </div>
                
                <!-- Reports -->
                <div class="bg-gray-50 rounded-lg p-4">
                    <h4 class="text-md font-bold text-gray-900 mb-3 flex items-center">
                        <svg class="w-5 h-5 mr-2 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                        </svg>
                        Reports & Analytics
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <label class="flex items-center p-3 bg-white dark:bg-gray-800 rounded border border-gray-200 hover:bg-yellow-50 cursor-pointer transition-colors">
                            <input type="checkbox" name="can_view_reports" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                            <span class="ml-2 text-sm text-gray-700">View Reports</span>
                        </label>
                        <label class="flex items-center p-3 bg-white dark:bg-gray-800 rounded border border-gray-200 hover:bg-yellow-50 cursor-pointer transition-colors">
                            <input type="checkbox" name="can_view_headcount" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                            <span class="ml-2 text-sm text-gray-700">View Headcount</span>
                        </label>
                        <label class="flex items-center p-3 bg-white dark:bg-gray-800 rounded border border-gray-200 hover:bg-yellow-50 cursor-pointer transition-colors">
                            <input type="checkbox" name="can_view_departments" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                            <span class="ml-2 text-sm text-gray-700">View Departments</span>
                        </label>
                        <label class="flex items-center p-3 bg-white dark:bg-gray-800 rounded border border-gray-200 hover:bg-yellow-50 cursor-pointer transition-colors">
                            <input type="checkbox" name="can_export_reports" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                            <span class="ml-2 text-sm text-gray-700">Export Reports</span>
                        </label>
                    </div>
                </div>
                
                <!-- Other Features -->
                <div class="bg-gray-50 rounded-lg p-4">
                    <h4 class="text-md font-bold text-gray-900 mb-3 flex items-center">
                        <svg class="w-5 h-5 mr-2 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"></path>
                        </svg>
                        Other Features
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <label class="flex items-center p-3 bg-white dark:bg-gray-800 rounded border border-gray-200 hover:bg-indigo-50 cursor-pointer transition-colors">
                            <input type="checkbox" name="can_view_calendar" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                            <span class="ml-2 text-sm text-gray-700">View Calendar</span>
                        </label>
                        <label class="flex items-center p-3 bg-white dark:bg-gray-800 rounded border border-gray-200 hover:bg-indigo-50 cursor-pointer transition-colors">
                            <input type="checkbox" name="can_view_announcements" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                            <span class="ml-2 text-sm text-gray-700">View Announcements</span>
                        </label>
                    </div>
                </div>
                
                <!-- Admin Features -->
                <div class="bg-red-50 rounded-lg p-4">
                    <h4 class="text-md font-bold text-gray-900 mb-3 flex items-center">
                        <svg class="w-5 h-5 mr-2 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                        </svg>
                        Admin Features
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <label class="flex items-center p-3 bg-white dark:bg-gray-800 rounded border border-gray-200 hover:bg-red-100 cursor-pointer transition-colors">
                            <input type="checkbox" name="can_manage_users" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                            <span class="ml-2 text-sm text-gray-700">Manage Users</span>
                        </label>
                        <label class="flex items-center p-3 bg-white dark:bg-gray-800 rounded border border-gray-200 hover:bg-red-100 cursor-pointer transition-colors">
                            <input type="checkbox" name="can_manage_announcements" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                            <span class="ml-2 text-sm text-gray-700">Manage Announcements</span>
                        </label>
                        <label class="flex items-center p-3 bg-white dark:bg-gray-800 rounded border border-gray-200 hover:bg-red-100 cursor-pointer transition-colors">
                            <input type="checkbox" name="can_manage_districts" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                            <span class="ml-2 text-sm text-gray-700">Manage Districts & Locals</span>
                        </label>
                        <label class="flex items-center p-3 bg-white dark:bg-gray-800 rounded border border-gray-200 hover:bg-red-100 cursor-pointer transition-colors">
                            <input type="checkbox" name="can_view_audit_log" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                            <span class="ml-2 text-sm text-gray-700">View Audit Log</span>
                        </label>
                        <label class="flex items-center p-3 bg-white dark:bg-gray-800 rounded border border-gray-200 hover:bg-red-100 cursor-pointer transition-colors">
                            <input type="checkbox" name="can_view_legacy_registry" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                            <span class="ml-2 text-sm text-gray-700">View Legacy Registry</span>
                        </label>
                    </div>
                </div>
                
                <div class="flex items-center justify-end space-x-3 pt-4 border-t">
                    <button type="button" onclick="closePermissionsModal()" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg text-gray-700 bg-white dark:bg-gray-800 hover:bg-gray-50 transition-colors">Cancel</button>
                    <button type="submit" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition-colors">
                        Save Permissions
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>

