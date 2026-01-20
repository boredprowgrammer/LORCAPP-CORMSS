<?php
require_once __DIR__ . '/config/config.php';

Security::requireLogin();

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

$error = '';
$success = '';
$passwordChanged = false;

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!Security::validateCSRFToken($csrfToken)) {
        $error = 'Invalid security token.';
    } else {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
            $error = 'All fields are required.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'New passwords do not match.';
        } elseif (strlen($newPassword) < 8) {
            $error = 'New password must be at least 8 characters long.';
        } elseif (!Security::verifyPassword($currentPassword, $currentUser['password_hash'])) {
            $error = 'Current password is incorrect.';
        } else {
            try {
                $newPasswordHash = Security::hashPassword($newPassword);
                
                // Single transaction for both password update and audit log
                $db->beginTransaction();
                
                $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
                $stmt->execute([$newPasswordHash, $currentUser['user_id']]);
                
                // Log audit
                $stmt = $db->prepare("
                    INSERT INTO audit_log (user_id, action, ip_address, user_agent) 
                    VALUES (?, 'change_password', ?, ?)
                ");
                $stmt->execute([
                    $currentUser['user_id'],
                    $_SERVER['REMOTE_ADDR'],
                    $_SERVER['HTTP_USER_AGENT'] ?? ''
                ]);
                
                $db->commit();
                $success = 'Password changed successfully!';
                $passwordChanged = true;
            } catch (Exception $e) {
                $db->rollBack();
                error_log("Change password error: " . $e->getMessage());
                $error = 'An error occurred while changing password.';
            }
        }
    }
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!Security::validateCSRFToken($csrfToken)) {
        $error = 'Invalid security token.';
    } else {
        $fullName = Security::sanitizeInput($_POST['full_name'] ?? '');
        $email = Security::sanitizeInput($_POST['email'] ?? '');
        
        if (empty($fullName) || empty($email)) {
            $error = 'Full name and email are required.';
        } elseif (!Security::validateEmail($email)) {
            $error = 'Invalid email address.';
        } else {
            try {
                // Single transaction for both profile update and audit log
                $db->beginTransaction();
                
                $stmt = $db->prepare("UPDATE users SET full_name = ?, email = ? WHERE user_id = ?");
                $stmt->execute([$fullName, $email, $currentUser['user_id']]);
                
                // Log audit
                $stmt = $db->prepare("
                    INSERT INTO audit_log (user_id, action, ip_address, user_agent) 
                    VALUES (?, 'update_profile', ?, ?)
                ");
                $stmt->execute([
                    $currentUser['user_id'],
                    $_SERVER['REMOTE_ADDR'],
                    $_SERVER['HTTP_USER_AGENT'] ?? ''
                ]);
                
                $db->commit();
                $success = 'Profile updated successfully!';
                
                // Update current user data in session
                $_SESSION['user']['full_name'] = $fullName;
                $_SESSION['user']['email'] = $email;
                $currentUser['full_name'] = $fullName;
                $currentUser['email'] = $email;
            } catch (PDOException $e) {
                $db->rollBack();
                if ($e->getCode() == 23000) {
                    $error = 'Email already exists.';
                } else {
                    error_log("Update profile error: " . $e->getMessage());
                    $error = 'An error occurred while updating profile.';
                }
            }
        }
    }
}

$pageTitle = 'Profile';
ob_start();
?>

<div class="max-w-4xl mx-auto space-y-6">
    <!-- Header -->
    <div>
        <h2 class="text-3xl font-bold text-gray-900">My Profile</h2>
        <p class="text-sm text-gray-600">Manage your account settings</p>
    </div>
    
    <?php if (!empty($error)): ?>
        <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
            <div class="flex items-center">
                <svg class="w-5 h-5 text-red-600 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                </svg>
                <span class="text-sm font-medium text-red-800"><?php echo Security::escape($error); ?></span>
            </div>
        </div>
    <?php endif; ?>
    
    <?php if (!empty($success)): ?>
        <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg p-4">
            <div class="flex items-center">
                <svg class="w-5 h-5 text-green-600 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                </svg>
                <span class="text-sm font-medium text-green-800"><?php echo Security::escape($success); ?></span>
            </div>
        </div>
    <?php endif; ?>
    
    <!-- Profile Information -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <div class="flex items-center space-x-3 mb-6">
            <div class="w-10 h-10 bg-blue-100 dark:bg-blue-900/30 rounded-lg flex items-center justify-center">
                <svg class="w-6 h-6 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                </svg>
            </div>
            <h3 class="text-xl font-semibold text-gray-900 dark:text-gray-100">Profile Information</h3>
        </div>
        
        <form method="POST" action="" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
            <input type="hidden" name="action" value="update_profile">
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Username</label>
                    <input 
                        type="text" 
                        value="<?php echo Security::escape($currentUser['username']); ?>" 
                        class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-gray-100" 
                        disabled
                    >
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Username cannot be changed</p>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Role</label>
                    <input 
                        type="text" 
                        value="<?php echo ucfirst($currentUser['role']); ?>" 
                        class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-gray-100" 
                        disabled
                    >
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Full Name <span class="text-red-600 dark:text-red-400">*</span>
                    </label>
                    <input 
                        type="text" 
                        name="full_name"
                        value="<?php echo Security::escape($currentUser['full_name']); ?>" 
                        class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                        required
                    >
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Email <span class="text-red-600 dark:text-red-400">*</span>
                    </label>
                    <input 
                        type="email" 
                        name="email"
                        value="<?php echo Security::escape($currentUser['email']); ?>" 
                        class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                        required
                    >
                </div>
            </div>
            
            <div class="flex justify-end pt-4 border-t border-gray-200 dark:border-gray-700">
                <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-lg font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"></path>
                    </svg>
                    Update Profile
                </button>
            </div>
        </form>
    </div>
    
    <!-- Change Password -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <div class="flex items-center space-x-3 mb-6">
            <div class="w-10 h-10 bg-yellow-100 dark:bg-yellow-900/30 rounded-lg flex items-center justify-center">
                <svg class="w-6 h-6 text-yellow-600 dark:text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                </svg>
            </div>
            <h3 class="text-xl font-semibold text-gray-900 dark:text-gray-100">Change Password</h3>
        </div>
        
        <form method="POST" action="" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
            <input type="hidden" name="action" value="change_password">
            
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Current Password <span class="text-red-600 dark:text-red-400">*</span>
                </label>
                <input 
                    type="password" 
                    name="current_password"
                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                    autocapitalize="off"
                    autocorrect="off"
                    required
                >
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        New Password <span class="text-red-600 dark:text-red-400">*</span>
                    </label>
                    <input 
                        type="password" 
                        name="new_password"
                        class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                        autocapitalize="off"
                        autocorrect="off"
                        minlength="8"
                        required
                    >
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Minimum 8 characters</p>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Confirm New Password <span class="text-red-600 dark:text-red-400">*</span>
                    </label>
                    <input 
                        type="password" 
                        name="confirm_password"
                        class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                        autocapitalize="off"
                        autocorrect="off"
                        minlength="8"
                        required
                    >
                </div>
            </div>
            
            <div class="flex justify-end pt-4 border-t border-gray-200">
                <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-lg font-medium text-white bg-yellow-600 hover:bg-yellow-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-yellow-500 transition-colors">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"></path>
                    </svg>
                    Change Password
                </button>
            </div>
        </form>
    </div>
    
    <!-- Account Information -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <div class="flex items-center space-x-3 mb-6">
            <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center">
                <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </div>
            <h3 class="text-xl font-semibold text-gray-900">Account Information</h3>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <p class="text-sm text-gray-600">Account Created</p>
                <p class="font-semibold text-gray-900"><?php echo formatDateTime($currentUser['created_at']); ?></p>
            </div>
            
            <div>
                <p class="text-sm text-gray-600">Last Login</p>
                <p class="font-semibold text-gray-900">
                    <?php echo $currentUser['last_login'] ? formatDateTime($currentUser['last_login']) : 'Never'; ?>
                </p>
            </div>
            
            <?php if ($currentUser['role'] !== 'admin'): ?>
                <div>
                    <p class="text-sm text-gray-600">District</p>
                    <p class="font-semibold text-gray-900"><?php echo Security::escape($currentUser['district_name'] ?? 'N/A'); ?></p>
                </div>
                
                <?php if ($currentUser['role'] === 'local'): ?>
                    <div>
                        <p class="text-sm text-gray-600">Local Congregation</p>
                        <p class="font-semibold text-gray-900"><?php echo Security::escape($currentUser['local_name'] ?? 'N/A'); ?></p>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            
            <div>
                <p class="text-sm text-gray-600">Account Status</p>
                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800">Active</span>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/includes/layout.php';
?>
