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
                                
                                redirect(BASE_URL . '/dashboard.php');
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
            <p class="text-sm text-gray-500" id="subtitle">
                <?php echo $show2FA ? 'Two-Factor Authentication' : 'Sign in to continue'; ?>
            </p>
        </div>
        
        <!-- Form -->
        <div class="px-8 pb-10">
            <?php if (!empty($error) && !$show2FA): ?>
                <div class="mb-6 bg-red-50 rounded-lg p-3">
                    <p class="text-sm text-red-700"><?php echo Security::escape($error); ?></p>
                </div>
            <?php endif; ?>
            
            <!-- Regular Login Form -->
            <form method="POST" action="" class="space-y-4" id="login-form" <?php echo $show2FA ? 'style="display:none;"' : ''; ?>>
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
                        autocapitalize="off"
                        autocorrect="off"
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
                        autocapitalize="off"
                        autocorrect="off"
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
            
            <!-- 2FA Verification Form -->
            <div id="totp-form" class="space-y-4" <?php echo !$show2FA ? 'style="display:none;"' : ''; ?>>
                <div class="bg-blue-50 rounded-lg p-4 mb-4">
                    <div class="flex items-start">
                        <svg class="w-5 h-5 text-blue-600 mr-3 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                        </svg>
                        <div>
                            <p class="text-sm text-blue-800">Enter the 6-digit code from your authenticator app</p>
                        </div>
                    </div>
                </div>
                
                <div id="totp-error" class="hidden mb-4 bg-red-50 rounded-lg p-3">
                    <p class="text-sm text-red-700"></p>
                </div>
                
                <div>
                    <label for="totp-code" class="block text-sm font-medium text-gray-700 mb-1.5">Verification Code</label>
                    <input 
                        type="text" 
                        id="totp-code"
                        placeholder="000000" 
                        maxlength="6"
                        pattern="[0-9]*"
                        inputmode="numeric"
                        class="block w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent text-base transition-shadow text-center text-2xl tracking-widest font-mono" 
                        autofocus
                    >
                </div>
                
                <button 
                    type="button"
                    id="verify-totp-btn" 
                    class="w-full mt-6 px-4 py-3 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors"
                >
                    Verify
                </button>
                
                <div class="text-center mt-4">
                    <button 
                        type="button"
                        id="use-backup-code-btn"
                        class="text-sm text-blue-600 hover:text-blue-700 underline"
                    >
                        Use backup code instead
                    </button>
                </div>
                
                <!-- Backup Code Form (hidden by default) -->
                <div id="backup-code-section" class="hidden mt-4 pt-4 border-t">
                    <div class="bg-yellow-50 rounded-lg p-3 mb-4">
                        <p class="text-sm text-yellow-800">Enter one of your backup codes. Each code can only be used once.</p>
                    </div>
                    <div>
                        <label for="backup-code" class="block text-sm font-medium text-gray-700 mb-1.5">Backup Code</label>
                        <input 
                            type="text" 
                            id="backup-code"
                            placeholder="XXXXXXXX" 
                            maxlength="8"
                            class="block w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-500 focus:border-transparent text-base transition-shadow text-center font-mono" 
                        >
                    </div>
                    <button 
                        type="button"
                        id="verify-backup-btn" 
                        class="w-full mt-4 px-4 py-3 bg-yellow-600 hover:bg-yellow-700 text-white font-medium rounded-lg focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-yellow-500 transition-colors"
                    >
                        Use Backup Code
                    </button>
                    <div class="text-center mt-3">
                        <button 
                            type="button"
                            id="back-to-totp-btn"
                            class="text-sm text-gray-600 hover:text-gray-700 underline"
                        >
                            ← Back to authenticator code
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const totpForm = document.getElementById('totp-form');
    const totpCodeInput = document.getElementById('totp-code');
    const verifyTotpBtn = document.getElementById('verify-totp-btn');
    const totpError = document.getElementById('totp-error');
    const useBackupBtn = document.getElementById('use-backup-code-btn');
    const backupSection = document.getElementById('backup-code-section');
    const backupCodeInput = document.getElementById('backup-code');
    const verifyBackupBtn = document.getElementById('verify-backup-btn');
    const backToTotpBtn = document.getElementById('back-to-totp-btn');
    
    // Auto-submit on 6 digits for TOTP
    if (totpCodeInput) {
        totpCodeInput.addEventListener('input', function(e) {
            this.value = this.value.replace(/[^0-9]/g, '');
            if (this.value.length === 6) {
                verifyTotpBtn.click();
            }
        });
    }
    
    // Verify TOTP code
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
    
    // Toggle backup code section
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
    
    // Verify backup code
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
    
    // Sanitize backup code input
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
                // Redirect to dashboard
                window.location.href = data.redirect || '<?php echo BASE_URL; ?>/dashboard.php';
            } else {
                showError(data.message || 'Invalid code. Please try again.');
                if (isBackup) {
                    backupCodeInput.value = '';
                    backupCodeInput.focus();
                } else {
                    totpCodeInput.value = '';
                    totpCodeInput.focus();
                }
            }
        })
        .catch(error => {
            console.error('Error:', error);
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

<?php
$content = ob_get_clean();
include __DIR__ . '/includes/layout.php';
?>
