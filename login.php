<?php
require_once __DIR__ . '/config/config.php';

// Try to auto-login with remember me token
if (!Security::isLoggedIn() && isset($_COOKIE['remember_me'])) {
    $userId = Security::validateRememberMeToken();
    if ($userId) {
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("
                SELECT u.*, d.district_name, lc.local_name 
                FROM users u
                LEFT JOIN districts d ON u.district_code = d.district_code
                LEFT JOIN local_congregations lc ON u.local_code = lc.local_code
                WHERE u.user_id = ? AND u.is_active = 1
            ");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            
            if ($user) {
                // Regenerate session ID
                session_regenerate_id(true);
                
                // Set session variables
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['district_code'] = $user['district_code'];
                $_SESSION['local_code'] = $user['local_code'];
                $_SESSION['last_activity'] = time();
                $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'];
                $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
                
                // Update last login
                $updateStmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?");
                $updateStmt->execute([$user['user_id']]);
                
                // Log audit
                $auditStmt = $db->prepare("
                    INSERT INTO audit_log (user_id, action, ip_address, user_agent) 
                    VALUES (?, 'auto_login_remember_me', ?, ?)
                ");
                $auditStmt->execute([
                    $user['user_id'],
                    $_SERVER['REMOTE_ADDR'],
                    $_SERVER['HTTP_USER_AGENT'] ?? ''
                ]);
                
                redirect(BASE_URL . '/dashboard.php');
            }
        } catch (Exception $e) {
            error_log("Auto-login error: " . $e->getMessage());
            Security::clearRememberMeToken();
        }
    }
}

// Redirect if already logged in
if (Security::isLoggedIn()) {
    redirect(BASE_URL . '/dashboard.php');
}

$error = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = Security::sanitizeInput($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $csrfToken = $_POST['csrf_token'] ?? '';
    $rememberMe = isset($_POST['remember_me']) && $_POST['remember_me'] === '1';
    
    // Validate CSRF token (action-specific, reusable for login form to allow retry)
    if (!Security::validateCSRFToken($csrfToken, 'login', false)) {
        $error = 'Invalid security token. Please try again.';
    } else {
        // Check login attempts (with IP address)
        $attemptCheck = Security::checkLoginAttempts($username, $_SERVER['REMOTE_ADDR']);
        
        if (!$attemptCheck['allowed']) {
            $error = $attemptCheck['message'];
        } else {
            // Validate credentials
            if (empty($username) || empty($password)) {
                $error = 'Please enter both username and password.';
            } else {
                try {
                    $db = Database::getInstance()->getConnection();
                    
                    // First check if user exists (including inactive ones)
                    $checkStmt = $db->prepare("
                        SELECT u.*, d.district_name, lc.local_name 
                        FROM users u
                        LEFT JOIN districts d ON u.district_code = d.district_code
                        LEFT JOIN local_congregations lc ON u.local_code = lc.local_code
                        WHERE u.username = ?
                    ");
                    $checkStmt->execute([$username]);
                    $userCheck = $checkStmt->fetch();
                    
                    // Check if account is inactive
                    if ($userCheck && !$userCheck['is_active']) {
                        if (Security::verifyPassword($password, $userCheck['password_hash'])) {
                            Security::recordFailedLogin($username, $_SERVER['REMOTE_ADDR']);
                            $error = 'Your account has been deactivated. Please contact your administrator for assistance.';
                        } else {
                            Security::recordFailedLogin($username, $_SERVER['REMOTE_ADDR']);
                            $error = 'Invalid username or password.';
                        }
                    } else {
                        // Now check for active user
                        $stmt = $db->prepare("
                            SELECT u.*, d.district_name, lc.local_name 
                            FROM users u
                            LEFT JOIN districts d ON u.district_code = d.district_code
                            LEFT JOIN local_congregations lc ON u.local_code = lc.local_code
                            WHERE u.username = ? AND u.is_active = 1
                        ");
                        $stmt->execute([$username]);
                        $user = $stmt->fetch();
                        
                        if ($user && Security::verifyPassword($password, $user['password_hash'])) {
                            // Regenerate session ID to prevent fixation attacks
                            session_regenerate_id(true);
                            
                            // Login successful
                            Security::resetLoginAttempts($username);
                            
                            // Set session variables with security bindings
                            $_SESSION['user_id'] = $user['user_id'];
                            $_SESSION['username'] = $user['username'];
                            $_SESSION['user_role'] = $user['role'];
                            $_SESSION['district_code'] = $user['district_code'];
                            $_SESSION['local_code'] = $user['local_code'];
                            $_SESSION['last_activity'] = time();
                            $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'];
                            $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
                            
                            // Handle remember me
                            if ($rememberMe) {
                                Security::generateRememberMeToken($user['user_id']);
                            }
                            
                            // Update last login
                            $updateStmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?");
                            $updateStmt->execute([$user['user_id']]);
                            
                            // Log audit
                            $auditStmt = $db->prepare("
                                INSERT INTO audit_log (user_id, action, ip_address, user_agent) 
                                VALUES (?, 'login', ?, ?)
                            ");
                            $auditStmt->execute([
                                $user['user_id'],
                                $_SERVER['REMOTE_ADDR'],
                                $_SERVER['HTTP_USER_AGENT'] ?? ''
                            ]);
                            
                            redirect(BASE_URL . '/dashboard.php');
                        } else {
                            Security::recordFailedLogin($username, $_SERVER['REMOTE_ADDR']);
                            $error = 'Invalid username or password.';
                        }
                    }
                } catch (Exception $e) {
                    error_log("Login error: " . $e->getMessage());
                    $error = 'An error occurred during login. Please try again.';
                }
            }
        }
    }
}

$pageTitle = 'Login';
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

<div class="w-full max-w-sm">
    <div class="bg-white rounded-2xl shadow-lg border border-gray-100 overflow-hidden">
        <!-- Header -->
        <div class="text-center pt-12 pb-8 px-8">
            <h1 class="text-3xl font-bold text-gray-900 mb-2"><?php echo APP_NAME; ?></h1>
            <p class="text-sm text-gray-500">Sign in to continue</p>
        </div>
        
        <!-- Form -->
        <div class="px-8 pb-10">
            <?php if (!empty($error)): ?>
                <div class="mb-6 bg-red-50 rounded-lg p-3">
                    <p class="text-sm text-red-700"><?php echo Security::escape($error); ?></p>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken('login'); ?>">
                
                <div>
                    <label for="username" class="block text-sm font-medium text-gray-700 mb-1.5">Username</label>
                    <input 
                        type="text" 
                        id="username"
                        name="username" 
                        placeholder="Enter username" 
                        class="block w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent text-base transition-shadow" 
                        value="<?php echo Security::escape($_POST['username'] ?? ''); ?>"
                        required 
                        autofocus
                    >
                </div>
                
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 mb-1.5">Password</label>
                    <input 
                        type="password" 
                        id="password"
                        name="password" 
                        placeholder="Enter password" 
                        class="block w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent text-base transition-shadow" 
                        required
                    >
                </div>
                
                <div class="flex items-center">
                    <input 
                        type="checkbox" 
                        id="remember_me"
                        name="remember_me"
                        value="1"
                        class="h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500"
                    >
                    <label for="remember_me" class="ml-2 block text-sm text-gray-700">
                        Remember this device for 90 days
                    </label>
                </div>
                
                <button 
                    type="submit" 
                    class="w-full mt-6 px-4 py-3 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors"
                >
                    Sign In
                </button>
            </form>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/includes/layout.php';
?>
