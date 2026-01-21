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
                
                redirect(BASE_URL . '/launchpad.php');
            }
        } catch (Exception $e) {
            error_log("Auto-login error: " . $e->getMessage());
            Security::clearRememberMeToken();
        }
    }
}

// Redirect if already logged in
if (Security::isLoggedIn()) {
    redirect(BASE_URL . '/launchpad.php');
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
                            // Check if 2FA is enabled
                            if (isset($user['totp_enabled']) && $user['totp_enabled']) {
                                // Store user ID in session for 2FA verification
                                $_SESSION['totp_pending_user_id'] = $user['user_id'];
                                $_SESSION['totp_pending_remember_me'] = $rememberMe;
                                
                                // Clear login attempts on successful password
                                Security::resetLoginAttempts($username);
                                
                                // Redirect to show 2FA form (we'll handle this with JavaScript)
                                $_SESSION['totp_required'] = true;
                                
                                // Log audit for password verification
                                $auditStmt = $db->prepare("
                                    INSERT INTO audit_log (user_id, action, ip_address, user_agent) 
                                    VALUES (?, 'login_password_verified', ?, ?)
                                ");
                                $auditStmt->execute([
                                    $user['user_id'],
                                    $_SERVER['REMOTE_ADDR'],
                                    $_SERVER['HTTP_USER_AGENT'] ?? ''
                                ]);
                                
                                // Don't redirect, let JavaScript handle 2FA UI
                                $error = ''; // Clear any errors
                            } else {
                                // No 2FA - complete login
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
                                
                                redirect(BASE_URL . '/launchpad.php');
                            }
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
$show2FA = isset($_SESSION['totp_required']) && $_SESSION['totp_required'];
if ($show2FA) {
    unset($_SESSION['totp_required']); // Clear flag after reading
}
?>
<!DOCTYPE html>
<html lang="en" x-data="{ darkMode: localStorage.getItem('darkMode') === 'true' }" :class="{ 'dark': darkMode }">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?> - Sign In</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'system-ui', 'sans-serif'],
                    }
                }
            }
        }
    </script>
    <style>
        * { font-family: 'Inter', sans-serif; }
        input { text-transform: none !important; }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="min-h-screen bg-gray-50 dark:bg-gray-900 flex items-center justify-center p-4">
    
    <div class="w-full max-w-sm">
        <!-- Main Card -->
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
            
            <!-- Header -->
            <div class="text-center pt-10 pb-6 px-8">
                <div class="inline-flex items-center justify-center w-14 h-14 bg-blue-100 dark:bg-blue-900/30 rounded-xl mb-4">
                    <svg class="w-7 h-7 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                    </svg>
                </div>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100"><?php echo APP_NAME; ?></h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1" id="subtitle">
                    <?php echo $show2FA ? 'Two-Factor Authentication' : 'Sign in to your account'; ?>
                </p>
            </div>
            
            <!-- Form -->
            <div class="px-8 pb-8">
                <?php if (!empty($error) && !$show2FA): ?>
                <div class="mb-5 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-3">
                    <p class="text-sm text-red-700 dark:text-red-300"><?php echo Security::escape($error); ?></p>
                </div>
                <?php endif; ?>
                
                <!-- Regular Login Form -->
                <form method="POST" action="" class="space-y-4" id="login-form" <?php echo $show2FA ? 'style="display:none;"' : ''; ?>>
                    <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken('login'); ?>">
                    
                    <div>
                        <label for="username" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Username</label>
                        <input 
                            type="text" 
                            id="username"
                            name="username" 
                            placeholder="Enter username" 
                            class="block w-full px-4 py-2.5 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors" 
                            value="<?php echo Security::escape($_POST['username'] ?? ''); ?>"
                            autocapitalize="off"
                            autocorrect="off"
                            autocomplete="username"
                            required 
                            autofocus
                        >
                    </div>
                    
                    <div x-data="{ show: false }">
                        <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Password</label>
                        <div class="relative">
                            <input 
                                :type="show ? 'text' : 'password'" 
                                id="password"
                                name="password" 
                                placeholder="Enter password" 
                                class="block w-full px-4 py-2.5 pr-10 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors" 
                                autocapitalize="off"
                                autocorrect="off"
                                autocomplete="current-password"
                                required
                            >
                            <button type="button" @click="show = !show" class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                                <svg x-show="!show" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                </svg>
                                <svg x-show="show" x-cloak class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"></path>
                                </svg>
                            </button>
                        </div>
                    </div>
                    
                    <div class="flex items-center">
                        <input 
                            type="checkbox" 
                            id="remember_me"
                            name="remember_me"
                            value="1"
                            class="h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500"
                        >
                        <label for="remember_me" class="ml-2 text-sm text-gray-600 dark:text-gray-400">
                            Remember me for 90 days
                        </label>
                    </div>
                    
                    <button 
                        type="submit" 
                        class="w-full mt-2 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors"
                    >
                        Sign In
                    </button>
                </form>
                
                <!-- 2FA Verification Form -->
                <div id="totp-form" class="space-y-4" <?php echo !$show2FA ? 'style="display:none;"' : ''; ?>>
                    <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-3">
                        <p class="text-sm text-blue-700 dark:text-blue-300">Enter the 6-digit code from your authenticator app</p>
                    </div>
                    
                    <div id="totp-error" class="hidden bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-3">
                        <p class="text-sm text-red-700 dark:text-red-300"></p>
                    </div>
                    
                    <div>
                        <label for="totp-code" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Verification Code</label>
                        <input 
                            type="text" 
                            id="totp-code"
                            placeholder="000000" 
                            maxlength="6"
                            pattern="[0-9]*"
                            inputmode="numeric"
                            class="block w-full px-4 py-3 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-center text-xl tracking-widest font-mono text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-blue-500" 
                            autofocus
                        >
                    </div>
                    
                    <button 
                        type="button"
                        id="verify-totp-btn" 
                        class="w-full py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors"
                    >
                        Verify
                    </button>
                    
                    <div class="text-center">
                        <button 
                            type="button"
                            id="use-backup-code-btn"
                            class="text-sm text-blue-600 dark:text-blue-400 hover:underline"
                        >
                            Use backup code instead
                        </button>
                    </div>
                    
                    <!-- Backup Code Form -->
                    <div id="backup-code-section" class="hidden pt-4 border-t border-gray-200 dark:border-gray-700">
                        <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg p-3 mb-4">
                            <p class="text-sm text-amber-700 dark:text-amber-300">Enter one of your backup codes. Each code can only be used once.</p>
                        </div>
                        <div>
                            <label for="backup-code" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Backup Code</label>
                            <input 
                                type="text" 
                                id="backup-code"
                                placeholder="XXXXXXXX" 
                                maxlength="8"
                                class="block w-full px-4 py-3 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-center font-mono text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-amber-500 focus:border-amber-500" 
                            >
                        </div>
                        <button 
                            type="button"
                            id="verify-backup-btn" 
                            class="w-full mt-3 py-2.5 bg-amber-600 hover:bg-amber-700 text-white font-medium rounded-lg focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-amber-500 transition-colors"
                        >
                            Use Backup Code
                        </button>
                        <div class="text-center mt-3">
                            <button 
                                type="button"
                                id="back-to-totp-btn"
                                class="text-sm text-gray-600 dark:text-gray-400 hover:underline"
                            >
                                ← Back to authenticator code
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Footer -->
        <p class="text-center text-xs text-gray-400 dark:text-gray-500 mt-6">
            &copy; <?php echo date('Y'); ?> <?php echo APP_NAME; ?>
        </p>
    </div>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const totpCodeInput = document.getElementById('totp-code');
        const verifyTotpBtn = document.getElementById('verify-totp-btn');
        const totpError = document.getElementById('totp-error');
        const useBackupBtn = document.getElementById('use-backup-code-btn');
        const backupSection = document.getElementById('backup-code-section');
        const backupCodeInput = document.getElementById('backup-code');
        const verifyBackupBtn = document.getElementById('verify-backup-btn');
        const backToTotpBtn = document.getElementById('back-to-totp-btn');
        
        if (totpCodeInput) {
            totpCodeInput.addEventListener('input', function(e) {
                this.value = this.value.replace(/[^0-9]/g, '');
                if (this.value.length === 6) verifyTotpBtn.click();
            });
        }
        
        if (verifyTotpBtn) {
            verifyTotpBtn.addEventListener('click', function() {
                const code = totpCodeInput.value.trim();
                if (code.length !== 6 || !/^\d+$/.test(code)) {
                    showError('Please enter a valid 6-digit code');
                    return;
                }
                verifyCode(code, false);
            });
        }
        
        if (useBackupBtn) {
            useBackupBtn.addEventListener('click', function() {
                backupSection.classList.remove('hidden');
                this.classList.add('hidden');
                backupCodeInput.focus();
            });
        }
        
        if (backToTotpBtn) {
            backToTotpBtn.addEventListener('click', function() {
                backupSection.classList.add('hidden');
                useBackupBtn.classList.remove('hidden');
                totpCodeInput.value = '';
                totpCodeInput.focus();
            });
        }
        
        if (verifyBackupBtn) {
            verifyBackupBtn.addEventListener('click', function() {
                const code = backupCodeInput.value.trim();
                if (code.length !== 8 || !/^[A-Za-z0-9]+$/.test(code)) {
                    showError('Please enter a valid 8-character backup code');
                    return;
                }
                verifyCode(code, true);
            });
        }
        
        if (backupCodeInput) {
            backupCodeInput.addEventListener('input', function(e) {
                this.value = this.value.replace(/[^A-Za-z0-9]/g, '');
            });
        }
        
        function verifyCode(code, isBackup) {
            const btn = isBackup ? verifyBackupBtn : verifyTotpBtn;
            const originalText = btn.textContent;
            btn.disabled = true;
            btn.textContent = 'Verifying...';
            totpError.classList.add('hidden');
            
            const formData = new FormData();
            formData.append('code', code);
            formData.append('is_backup', isBackup ? '1' : '0');
            formData.append('csrf_token', '<?php echo Security::generateCSRFToken('login'); ?>');
            
            fetch('<?php echo BASE_URL; ?>/api/totp-login-verify.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    window.location.href = data.redirect || '<?php echo BASE_URL; ?>/launchpad.php';
                } else {
                    showError(data.message || 'Invalid code. Please try again.');
                    if (isBackup) { backupCodeInput.value = ''; backupCodeInput.focus(); }
                    else { totpCodeInput.value = ''; totpCodeInput.focus(); }
                }
            })
            .catch(error => {
                showError('Network error. Please try again.');
            })
            .finally(() => {
                btn.disabled = false;
                btn.textContent = originalText;
            });
        }
        
        function showError(message) {
            totpError.querySelector('p').textContent = message;
            totpError.classList.remove('hidden');
        }
    });
    </script>
</body>
</html>
