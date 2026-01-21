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

<div class="max-w-3xl mx-auto space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">My Profile</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">Manage your account settings</p>
        </div>
        <a href="<?php echo BASE_URL; ?>/launchpad.php" class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
            ← Launchpad
        </a>
    </div>
    
    <?php if (!empty($error)): ?>
    <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
        <p class="text-sm text-red-700 dark:text-red-300"><?php echo Security::escape($error); ?></p>
    </div>
    <?php endif; ?>
    
    <?php if (!empty($success)): ?>
    <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg p-4">
        <p class="text-sm text-green-700 dark:text-green-300"><?php echo Security::escape($success); ?></p>
    </div>
    <?php endif; ?>
    
    <!-- Profile Information -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700">
        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Profile Information</h2>
        </div>
        <form method="POST" action="" class="p-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
            <input type="hidden" name="action" value="update_profile">
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Username</label>
                    <input 
                        type="text" 
                        value="<?php echo Security::escape($currentUser['username']); ?>" 
                        class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-700 text-gray-500 dark:text-gray-400" 
                        disabled
                    >
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Role</label>
                    <input 
                        type="text" 
                        value="<?php echo ucfirst($currentUser['role']); ?>" 
                        class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-700 text-gray-500 dark:text-gray-400" 
                        disabled
                    >
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Full Name</label>
                    <input 
                        type="text" 
                        name="full_name"
                        value="<?php echo Security::escape($currentUser['full_name']); ?>" 
                        class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                        required
                    >
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Email</label>
                    <input 
                        type="email" 
                        name="email"
                        value="<?php echo Security::escape($currentUser['email']); ?>" 
                        class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                        required
                    >
                </div>
            </div>
            
            <div class="flex justify-end pt-4">
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors">
                    Save Changes
                </button>
            </div>
        </form>
    </div>
    
    <!-- Change Password -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700">
        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Change Password</h2>
        </div>
        <form method="POST" action="" class="p-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
            <input type="hidden" name="action" value="change_password">
            
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Current Password</label>
                <input 
                    type="password" 
                    name="current_password"
                    class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                    autocomplete="current-password"
                    required
                >
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">New Password</label>
                    <input 
                        type="password" 
                        name="new_password"
                        class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                        autocomplete="new-password"
                        minlength="8"
                        required
                    >
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Minimum 8 characters</p>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Confirm New Password</label>
                    <input 
                        type="password" 
                        name="confirm_password"
                        class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                        autocomplete="new-password"
                        minlength="8"
                        required
                    >
                </div>
            </div>
            
            <div class="flex justify-end pt-4">
                <button type="submit" class="px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white font-medium rounded-lg transition-colors">
                    Change Password
                </button>
            </div>
        </form>
    </div>
    
    <!-- Account Information -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700">
        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Account Information</h2>
        </div>
        <div class="p-6">
            <dl class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <dt class="text-sm text-gray-500 dark:text-gray-400">Account Created</dt>
                    <dd class="mt-1 text-sm font-medium text-gray-900 dark:text-gray-100"><?php echo formatDateTime($currentUser['created_at']); ?></dd>
                </div>
                
                <div>
                    <dt class="text-sm text-gray-500 dark:text-gray-400">Last Login</dt>
                    <dd class="mt-1 text-sm font-medium text-gray-900 dark:text-gray-100">
                        <?php echo $currentUser['last_login'] ? formatDateTime($currentUser['last_login']) : 'Never'; ?>
                    </dd>
                </div>
                
                <?php if ($currentUser['role'] !== 'admin'): ?>
                <div>
                    <dt class="text-sm text-gray-500 dark:text-gray-400">District</dt>
                    <dd class="mt-1 text-sm font-medium text-gray-900 dark:text-gray-100"><?php echo Security::escape($currentUser['district_name'] ?? 'N/A'); ?></dd>
                </div>
                
                <?php if ($currentUser['role'] === 'local'): ?>
                <div>
                    <dt class="text-sm text-gray-500 dark:text-gray-400">Local Congregation</dt>
                    <dd class="mt-1 text-sm font-medium text-gray-900 dark:text-gray-100"><?php echo Security::escape($currentUser['local_name'] ?? 'N/A'); ?></dd>
                </div>
                <?php endif; ?>
                <?php endif; ?>
                
                <div>
                    <dt class="text-sm text-gray-500 dark:text-gray-400">Status</dt>
                    <dd class="mt-1">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300">Active</span>
                    </dd>
                </div>
            </dl>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/includes/layout.php';
?>
