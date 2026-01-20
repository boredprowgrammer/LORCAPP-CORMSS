<?php
require_once __DIR__ . '/config/config.php';

Security::requireLogin();

$currentUser = getCurrentUser();

// Get 2FA status
$db = Database::getInstance()->getConnection();
$stmt = $db->prepare("SELECT totp_enabled, totp_verified_at, totp_last_used FROM users WHERE user_id = ?");
$stmt->execute([$currentUser['user_id']]);
$totpData = $stmt->fetch();
$totpEnabled = (bool)($totpData['totp_enabled'] ?? false);

// Handle dark mode update via AJAX is done in api/update-dark-mode.php

// Settings page
$pageTitle = 'Settings';
ob_start();
?>

<div class="max-w-4xl mx-auto space-y-6">
    <!-- Header -->
    <div>
        <h2 class="text-3xl font-bold text-gray-900 dark:text-gray-100">Settings</h2>
        <p class="text-sm text-gray-600 dark:text-gray-400">Configure your preferences</p>
    </div>
    
    <!-- General Settings -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <div class="flex items-center space-x-3 mb-6">
            <div class="w-10 h-10 bg-blue-100 dark:bg-blue-900/30 rounded-lg flex items-center justify-center">
                <svg class="w-6 h-6 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                </svg>
            </div>
            <h3 class="text-xl font-semibold text-gray-900 dark:text-gray-100">General Settings</h3>
        </div>
        
        <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4 mb-6">
            <div class="flex items-start">
                <svg class="w-5 h-5 text-blue-600 dark:text-blue-400 mr-3 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                </svg>
                <div>
                    <p class="font-semibold text-blue-800 dark:text-blue-300">Settings Coming Soon</p>
                    <p class="text-sm text-blue-700 dark:text-blue-400 mt-1">Additional settings and preferences will be available in future updates.</p>
                </div>
            </div>
        </div>
        
        <div class="space-y-4">
            <!-- Dark Mode Toggle -->
            <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700 rounded-lg border border-gray-200 dark:border-gray-700 dark:border-gray-600">
                <div class="flex-1">
                    <div class="flex items-center space-x-3">
                        <svg class="w-5 h-5 text-gray-700 dark:text-gray-300 dark:text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path>
                        </svg>
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="font-semibold text-gray-900 dark:text-gray-100 dark:text-gray-100">Dark Mode</span>
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-400 border border-blue-200 dark:border-blue-800">
                                    <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                                    </svg>
                                    BETA
                                </span>
                            </div>
                            <p class="text-xs text-gray-600 dark:text-gray-400 dark:text-gray-400 mt-1">Reduce eye strain in low-light environments</p>
                            <p class="text-xs text-yellow-600 dark:text-yellow-400 mt-1 italic">⚠️ Some areas still need refinement</p>
                        </div>
                    </div>
                </div>
                <div>
                    <!-- Toggle Switch -->
                    <button type="button" id="darkModeToggle" 
                            class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
                            role="switch">
                        <span class="sr-only">Enable dark mode</span>
                        <span class="toggle-bg absolute inset-0 rounded-full transition-colors bg-gray-200"></span>
                        <span class="toggle-dot inline-block h-4 w-4 transform rounded-full bg-white transition-transform translate-x-1"></span>
                    </button>
                </div>
            </div>
            
            <!-- Notifications (Placeholder) -->
            <div class="flex items-start space-x-3 p-4 bg-gray-50 dark:bg-gray-700/50 rounded-lg border border-gray-200 dark:border-gray-600">
                <input type="checkbox" disabled class="mt-1 h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 dark:border-gray-600 rounded">
                <div class="flex-1">
                    <span class="font-semibold text-gray-900 dark:text-gray-100 block">Email Notifications</span>
                    <p class="text-xs text-gray-600 dark:text-gray-400 mt-1">Coming soon</p>
                </div>
            </div>
            
            <!-- Language (Placeholder) -->
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Language</label>
                <select class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100" disabled>
                    <option>English (Default)</option>
                    <option>Filipino</option>
                </select>
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Multi-language support coming soon</p>
            </div>
        </div>
    </div>
    
    <!-- Security Settings -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <div class="flex items-center space-x-3 mb-6">
            <div class="w-10 h-10 bg-green-100 dark:bg-green-900/30 rounded-lg flex items-center justify-center">
                <svg class="w-6 h-6 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                </svg>
            </div>
            <h3 class="text-xl font-semibold text-gray-900 dark:text-gray-100">Security Settings</h3>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4">
                <div class="text-xs text-gray-600 dark:text-gray-400 mb-1">Session Timeout</div>
                <div class="text-2xl font-bold text-gray-900 dark:text-gray-100"><?php echo SESSION_TIMEOUT / 60; ?> min</div>
                <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">Auto-logout after inactivity</div>
            </div>
            
            <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4">
                <div class="text-xs text-gray-600 dark:text-gray-400 mb-1">Password Strength</div>
                <div class="text-2xl font-bold text-gray-900 dark:text-gray-100">Strong</div>
                <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">Using Argon2ID</div>
            </div>
            
            <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4">
                <div class="text-xs text-gray-600 dark:text-gray-400 mb-1">2FA Status</div>
                <div class="text-2xl font-bold <?php echo $totpEnabled ? 'text-green-600' : 'text-gray-400'; ?>">
                    <?php echo $totpEnabled ? 'Enabled' : 'Disabled'; ?>
                </div>
                <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                    <?php 
                    if ($totpEnabled && $totpData['totp_verified_at']) {
                        echo 'Active since ' . date('M d, Y', strtotime($totpData['totp_verified_at']));
                    } else {
                        echo 'Extra security layer';
                    }
                    ?>
                </div>
            </div>
        </div>
        
        <div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-700 rounded-lg p-4">
            <div class="flex items-start">
                <svg class="w-5 h-5 text-yellow-600 mr-3 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                </svg>
                <div>
                    <p class="font-semibold text-yellow-800 dark:text-yellow-300">Security Reminder</p>
                    <p class="text-sm text-yellow-700 dark:text-yellow-400 mt-1">Always use a strong password and never share your login credentials.</p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Two-Factor Authentication -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <div class="flex items-center space-x-3 mb-6">
            <div class="w-10 h-10 bg-indigo-100 dark:bg-indigo-900/30 rounded-lg flex items-center justify-center">
                <svg class="w-6 h-6 text-indigo-600 dark:text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                </svg>
            </div>
            <div class="flex-1">
                <h3 class="text-xl font-semibold text-gray-900 dark:text-gray-100">Two-Factor Authentication</h3>
                <p class="text-sm text-gray-600 dark:text-gray-400">Add an extra layer of security to your account</p>
            </div>
            <?php if ($totpEnabled): ?>
            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800">
                <svg class="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                </svg>
                Enabled
            </span>
            <?php else: ?>
            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-gray-100 text-gray-800">
                Disabled
            </span>
            <?php endif; ?>
        </div>
        
        <?php if (!$totpEnabled): ?>
        <!-- Setup 2FA -->
        <div id="totp-setup-section">
            <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4 mb-4">
                <div class="flex items-start">
                    <svg class="w-5 h-5 text-blue-600 dark:text-blue-400 mr-3 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                    </svg>
                    <div>
                        <p class="font-semibold text-blue-800 dark:text-blue-300">What is Two-Factor Authentication?</p>
                        <p class="text-sm text-blue-700 dark:text-blue-400 mt-1">
                            2FA adds an extra security layer by requiring a time-based code from your phone in addition to your password. 
                            You'll need an authenticator app like <strong>Google Authenticator</strong>, <strong>Microsoft Authenticator</strong>, or <strong>Authy</strong>.
                        </p>
                    </div>
                </div>
            </div>
            
            <button id="setup-totp-btn" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                </svg>
                Enable Two-Factor Authentication
            </button>
            
            <!-- QR Code Modal (hidden by default) -->
            <div id="totp-qr-modal" class="hidden fixed inset-0 bg-gray-900 bg-opacity-50 z-50 flex items-center justify-center p-4">
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-md w-full max-h-screen overflow-y-auto">
                    <div class="p-6">
                        <h4 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Set Up Two-Factor Authentication</h4>
                        
                        <div id="totp-setup-step1" class="space-y-4">
                            <p class="text-sm text-gray-600 dark:text-gray-400">
                                1. Scan this QR code with your authenticator app:
                            </p>
                            <div id="qr-code-container" class="flex justify-center bg-white dark:bg-gray-700 p-4 rounded-lg">
                                <!-- QR code will be generated here by qrcodejs -->
                            </div>
                            
                            <div class="border-t pt-4">
                                <p class="text-sm text-gray-600 dark:text-gray-400 mb-2">Or enter this secret key manually:</p>
                                <div class="flex items-center gap-2">
                                    <code id="totp-secret" class="flex-1 px-3 py-2 bg-gray-100 dark:bg-gray-700 rounded text-sm font-mono break-all"></code>
                                    <button onclick="copySecret()" class="px-3 py-2 bg-gray-200 dark:bg-gray-600 hover:bg-gray-300 dark:hover:bg-gray-500 rounded transition-colors">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                                        </svg>
                                    </button>
                                </div>
                            </div>
                            
                            <div class="border-t pt-4">
                                <p class="text-sm text-gray-600 dark:text-gray-400 mb-2">
                                    2. Save these backup codes in a safe place. Each can only be used once:
                                </p>
                                <div id="backup-codes" class="grid grid-cols-2 gap-2 mb-2">
                                    <!-- Backup codes will be inserted here -->
                                </div>
                                <button onclick="downloadBackupCodes()" class="w-full px-3 py-2 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 rounded transition-colors text-sm">
                                    📥 Download Backup Codes
                                </button>
                            </div>
                            
                            <div class="border-t pt-4">
                                <p class="text-sm text-gray-600 dark:text-gray-400 mb-2">
                                    3. Enter the 6-digit code from your app to verify:
                                </p>
                                <input type="text" id="totp-verify-code" 
                                       class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-gray-100"
                                       placeholder="000000" maxlength="6" pattern="[0-9]*" inputmode="numeric">
                            </div>
                        </div>
                        
                        <div class="flex gap-3 mt-6">
                            <button id="verify-totp-btn" class="flex-1 px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-medium">
                                Verify & Enable
                            </button>
                            <button id="cancel-totp-setup" class="px-4 py-2 bg-gray-200 dark:bg-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-500 transition-colors">
                                Cancel
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php else: ?>
        <!-- 2FA Enabled -->
        <div class="space-y-4">
            <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg p-4">
                <div class="flex items-start">
                    <svg class="w-5 h-5 text-green-600 dark:text-green-400 mr-3 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                    </svg>
                    <div class="flex-1">
                        <p class="font-semibold text-green-800 dark:text-green-300">Two-Factor Authentication is Active</p>
                        <p class="text-sm text-green-700 dark:text-green-400 mt-1">
                            Your account is protected with an extra layer of security.
                            <?php if ($totpData['totp_last_used']): ?>
                            Last used: <?php echo date('F d, Y \a\t g:i A', strtotime($totpData['totp_last_used'])); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>
            
            <button id="disable-totp-btn" class="inline-flex items-center px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors font-medium">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
                Disable Two-Factor Authentication
            </button>
            
            <!-- Disable Modal -->
            <div id="totp-disable-modal" class="hidden fixed inset-0 bg-gray-900 bg-opacity-50 z-50 flex items-center justify-center p-4">
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-md w-full">
                    <div class="p-6">
                        <div class="flex items-center mb-4">
                            <div class="w-12 h-12 bg-red-100 dark:bg-red-900/30 rounded-full flex items-center justify-center mr-4">
                                <svg class="w-6 h-6 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                                </svg>
                            </div>
                            <div>
                                <h4 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Disable Two-Factor Authentication</h4>
                                <p class="text-sm text-gray-600 dark:text-gray-400">This will reduce your account security</p>
                            </div>
                        </div>
                        
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Confirm Your Password
                                </label>
                                <input type="password" id="disable-password" 
                                       class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-red-500 dark:bg-gray-700 dark:text-gray-100"
                                       placeholder="Enter your password">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Enter Current 2FA Code
                                </label>
                                <input type="text" id="disable-totp-code" 
                                       class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-red-500 dark:bg-gray-700 dark:text-gray-100"
                                       placeholder="000000" maxlength="6" pattern="[0-9]*" inputmode="numeric">
                            </div>
                        </div>
                        
                        <div class="flex gap-3 mt-6">
                            <button id="confirm-disable-totp" class="flex-1 px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors font-medium">
                                Disable 2FA
                            </button>
                            <button id="cancel-disable-totp" class="px-4 py-2 bg-gray-200 dark:bg-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-500 transition-colors">
                                Cancel
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- System Information -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <div class="flex items-center space-x-3 mb-6">
            <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center">
                <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"></path>
                </svg>
            </div>
            <h3 class="text-xl font-semibold text-gray-900 dark:text-gray-100">System Information</h3>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <p class="text-sm text-gray-600 dark:text-gray-400">Application Name</p>
                <p class="font-semibold text-gray-900 dark:text-gray-100"><?php echo APP_NAME; ?></p>
            </div>
            
            <div>
                <p class="text-sm text-gray-600 dark:text-gray-400">Version</p>
                <p class="font-semibold text-gray-900 dark:text-gray-100"><?php echo APP_VERSION; ?></p>
            </div>
            
            <div>
                <p class="text-sm text-gray-600 dark:text-gray-400">PHP Version</p>
                <p class="font-semibold text-gray-900 dark:text-gray-100"><?php echo phpversion(); ?></p>
            </div>
            
            <div>
                <p class="text-sm text-gray-600 dark:text-gray-400">Server Time</p>
                <p class="font-semibold text-gray-900 dark:text-gray-100"><?php echo date('Y-m-d H:i:s'); ?></p>
            </div>
            
            <div>
                <p class="text-sm text-gray-600 dark:text-gray-400">Encryption</p>
                <p class="font-semibold text-gray-900 dark:text-gray-100"><?php echo ENCRYPTION_METHOD; ?></p>
            </div>
            
            <div>
                <p class="text-sm text-gray-600 dark:text-gray-400">Database</p>
                <p class="font-semibold text-gray-900 dark:text-gray-100">MySQL/MariaDB</p>
            </div>
        </div>
    </div>
    
    <!-- Quick Links -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <div class="flex items-center space-x-3 mb-6">
            <div class="w-10 h-10 bg-indigo-100 rounded-lg flex items-center justify-center">
                <svg class="w-6 h-6 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path>
                </svg>
            </div>
            <h3 class="text-xl font-semibold text-gray-900 dark:text-gray-100">Quick Links</h3>
        </div>
        
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <a href="<?php echo BASE_URL; ?>/profile.php" class="inline-flex items-center justify-center px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                </svg>
                Profile
            </a>
            <a href="<?php echo BASE_URL; ?>/launchpad.php" class="inline-flex items-center justify-center px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path>
                </svg>
                Launchpad
            </a>
            <a href="<?php echo BASE_URL; ?>/reports/headcount.php" class="inline-flex items-center justify-center px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                </svg>
                Reports
            </a>
            <a href="<?php echo BASE_URL; ?>/logout.php" class="inline-flex items-center justify-center px-4 py-2 border border-red-300 dark:border-red-800 rounded-lg font-medium text-red-700 dark:text-red-400 bg-red-50 dark:bg-red-900/20 hover:bg-red-100 dark:hover:bg-red-900/30 transition-colors">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                </svg>
                Logout
            </a>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" integrity="sha512-CNgIRecGo7nphbeZ04Sc13ka07paqdeTu0WR1IM4kNcpmBAUSHSQX0FslNhTDadL4O5SAGapGt4FodqL8My0mA==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Dark Mode Toggle with localStorage
    const toggle = document.getElementById('darkModeToggle');
    
    if (toggle) {
        // Initialize toggle state from current theme
        const isDark = document.documentElement.classList.contains('dark');
        const bg = toggle.querySelector('.toggle-bg');
        const dot = toggle.querySelector('.toggle-dot');
        
        if (isDark) {
            bg.classList.remove('bg-gray-200');
            bg.classList.add('bg-blue-600');
            dot.classList.remove('translate-x-1');
            dot.classList.add('translate-x-6');
            toggle.setAttribute('aria-checked', 'true');
        } else {
            toggle.setAttribute('aria-checked', 'false');
        }
        
        toggle.addEventListener('click', function() {
            const isDarkNow = document.documentElement.classList.contains('dark');
            
            // Toggle dark mode
            if (isDarkNow) {
                document.documentElement.classList.remove('dark');
                bg.classList.remove('bg-blue-600');
                bg.classList.add('bg-gray-200');
                dot.classList.remove('translate-x-6');
                dot.classList.add('translate-x-1');
                toggle.setAttribute('aria-checked', 'false');
                localStorage.setItem('theme', 'light');
                showNotification('Light mode enabled', 'success');
            } else {
                document.documentElement.classList.add('dark');
                bg.classList.remove('bg-gray-200');
                bg.classList.add('bg-blue-600');
                dot.classList.remove('translate-x-1');
                dot.classList.add('translate-x-6');
                toggle.setAttribute('aria-checked', 'true');
                localStorage.setItem('theme', 'dark');
                showNotification('Dark mode enabled', 'success');
            }
        });
    }
    
    // Two-Factor Authentication Setup
    let currentBackupCodes = [];
    let currentSecret = '';
    
    const setupBtn = document.getElementById('setup-totp-btn');
    const qrModal = document.getElementById('totp-qr-modal');
    const cancelSetup = document.getElementById('cancel-totp-setup');
    const verifyBtn = document.getElementById('verify-totp-btn');
    const verifyInput = document.getElementById('totp-verify-code');
    
    if (setupBtn) {
        setupBtn.addEventListener('click', function() {
            this.disabled = true;
            this.innerHTML = '<svg class="animate-spin h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Setting up...';
            
            const formData = new FormData();
            formData.append('csrf_token', '<?php echo Security::generateCSRFToken(); ?>');
            
            fetch('<?php echo BASE_URL; ?>/api/totp-setup.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Store data for later
                    currentSecret = data.secret;
                    currentBackupCodes = data.backupCodes;
                    
                    // Clear previous QR code
                    const qrContainer = document.getElementById('qr-code-container');
                    qrContainer.innerHTML = '';
                    
                    // Generate QR code using qrcodejs
                    new QRCode(qrContainer, {
                        text: data.qrCodeText,
                        width: 256,
                        height: 256,
                        colorDark: "#000000",
                        colorLight: "#ffffff",
                        correctLevel: QRCode.CorrectLevel.M
                    });
                    
                    document.getElementById('totp-secret').textContent = data.secret;
                    
                    // Display backup codes
                    const backupContainer = document.getElementById('backup-codes');
                    backupContainer.innerHTML = data.backupCodes.map(code => 
                        `<code class="px-2 py-1 bg-gray-100 dark:bg-gray-700 rounded text-sm font-mono">${code}</code>`
                    ).join('');
                    
                    // Show modal
                    qrModal.classList.remove('hidden');
                } else {
                    showNotification(data.message || 'Failed to set up 2FA', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Network error', 'error');
            })
            .finally(() => {
                setupBtn.disabled = false;
                setupBtn.innerHTML = '<svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path></svg>Enable Two-Factor Authentication';
            });
        });
    }
    
    if (cancelSetup) {
        cancelSetup.addEventListener('click', function() {
            qrModal.classList.add('hidden');
            verifyInput.value = '';
        });
    }
    
    if (verifyBtn) {
        verifyBtn.addEventListener('click', function() {
            const code = verifyInput.value.trim();
            
            if (code.length !== 6 || !/^\d+$/.test(code)) {
                showNotification('Please enter a valid 6-digit code', 'error');
                return;
            }
            
            this.disabled = true;
            this.textContent = 'Verifying...';
            
            const formData = new FormData();
            formData.append('code', code);
            formData.append('csrf_token', '<?php echo Security::generateCSRFToken(); ?>');
            
            fetch('<?php echo BASE_URL; ?>/api/totp-verify.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showNotification(data.message || 'Invalid code', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Network error', 'error');
            })
            .finally(() => {
                verifyBtn.disabled = false;
                verifyBtn.textContent = 'Verify & Enable';
            });
        });
    }
    
    // Auto-submit on 6 digits
    if (verifyInput) {
        verifyInput.addEventListener('input', function(e) {
            this.value = this.value.replace(/[^0-9]/g, '');
            if (this.value.length === 6) {
                verifyBtn.click();
            }
        });
    }
    
    // Two-Factor Authentication Disable
    const disableBtn = document.getElementById('disable-totp-btn');
    const disableModal = document.getElementById('totp-disable-modal');
    const cancelDisable = document.getElementById('cancel-disable-totp');
    const confirmDisable = document.getElementById('confirm-disable-totp');
    
    if (disableBtn) {
        disableBtn.addEventListener('click', function() {
            disableModal.classList.remove('hidden');
        });
    }
    
    if (cancelDisable) {
        cancelDisable.addEventListener('click', function() {
            disableModal.classList.add('hidden');
            document.getElementById('disable-password').value = '';
            document.getElementById('disable-totp-code').value = '';
        });
    }
    
    if (confirmDisable) {
        confirmDisable.addEventListener('click', function() {
            const password = document.getElementById('disable-password').value;
            const code = document.getElementById('disable-totp-code').value.trim();
            
            if (!password || code.length !== 6 || !/^\d+$/.test(code)) {
                showNotification('Please enter your password and a valid 6-digit code', 'error');
                return;
            }
            
            this.disabled = true;
            this.textContent = 'Disabling...';
            
            const formData = new FormData();
            formData.append('password', password);
            formData.append('code', code);
            formData.append('csrf_token', '<?php echo Security::generateCSRFToken(); ?>');
            
            fetch('<?php echo BASE_URL; ?>/api/totp-disable.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showNotification(data.message || 'Failed to disable 2FA', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Network error', 'error');
            })
            .finally(() => {
                confirmDisable.disabled = false;
                confirmDisable.textContent = 'Disable 2FA';
            });
        });
    }
    
    function showNotification(message, type) {
        const notification = document.createElement('div');
        notification.className = `fixed top-4 right-4 px-6 py-3 rounded-lg shadow-lg z-50 animate-fade-in ${
            type === 'success' ? 'bg-green-500 text-white' : 'bg-red-500 text-white'
        }`;
        notification.textContent = message;
        document.body.appendChild(notification);
        
        setTimeout(() => {
            notification.style.opacity = '0';
            setTimeout(() => notification.remove(), 300);
        }, 3000);
    }
    
    // Helper functions
    window.copySecret = function() {
        const secret = document.getElementById('totp-secret').textContent;
        navigator.clipboard.writeText(secret).then(() => {
            showNotification('Secret copied to clipboard', 'success');
        });
    };
    
    window.downloadBackupCodes = function() {
        const codes = currentBackupCodes.join('\n');
        const blob = new Blob([`<?php echo APP_NAME; ?> - Two-Factor Authentication Backup Codes\n\nGenerated: ${new Date().toLocaleString()}\n\n${codes}\n\nKeep these codes in a safe place. Each code can only be used once.`], { type: 'text/plain' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = '2fa-backup-codes.txt';
        a.click();
        URL.revokeObjectURL(url);
        showNotification('Backup codes downloaded', 'success');
    };
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/includes/layout.php';
?>
