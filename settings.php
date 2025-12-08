<?php
require_once __DIR__ . '/config/config.php';

Security::requireLogin();

$currentUser = getCurrentUser();

// Settings page - placeholder for future features
$pageTitle = 'Settings';
ob_start();
?>

<div class="max-w-4xl mx-auto space-y-6">
    <!-- Header -->
    <div>
        <h2 class="text-3xl font-bold text-gray-900">Settings</h2>
        <p class="text-sm text-gray-600">Configure your preferences</p>
    </div>
    
    <!-- General Settings -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <div class="flex items-center space-x-3 mb-6">
            <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                </svg>
            </div>
            <h3 class="text-xl font-semibold text-gray-900">General Settings</h3>
        </div>
        
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
            <div class="flex items-start">
                <svg class="w-5 h-5 text-blue-600 mr-3 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                </svg>
                <div>
                    <p class="font-semibold text-blue-800">Settings Coming Soon</p>
                    <p class="text-sm text-blue-700 mt-1">Additional settings and preferences will be available in future updates.</p>
                </div>
            </div>
        </div>
        
        <div class="space-y-4">
            <!-- Theme Toggle (Visual only for now) -->
            <div class="flex items-start space-x-3 p-4 bg-gray-50 rounded-lg border border-gray-200">
                <input type="checkbox" checked disabled class="mt-1 h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                <div class="flex-1">
                    <span class="font-semibold text-gray-900 block">Dark Mode</span>
                    <p class="text-xs text-gray-600 mt-1">Currently enabled by default</p>
                </div>
            </div>
            
            <!-- Notifications (Placeholder) -->
            <div class="flex items-start space-x-3 p-4 bg-gray-50 rounded-lg border border-gray-200">
                <input type="checkbox" disabled class="mt-1 h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                <div class="flex-1">
                    <span class="font-semibold text-gray-900 block">Email Notifications</span>
                    <p class="text-xs text-gray-600 mt-1">Coming soon</p>
                </div>
            </div>
            
            <!-- Language (Placeholder) -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Language</label>
                <select class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" disabled>
                    <option>English (Default)</option>
                    <option>Filipino</option>
                </select>
                <p class="mt-2 text-xs text-gray-500">Multi-language support coming soon</p>
            </div>
        </div>
    </div>
    
    <!-- Security Settings -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <div class="flex items-center space-x-3 mb-6">
            <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center">
                <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                </svg>
            </div>
            <h3 class="text-xl font-semibold text-gray-900">Security Settings</h3>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="bg-gray-50 rounded-lg p-4">
                <div class="text-xs text-gray-600 mb-1">Session Timeout</div>
                <div class="text-2xl font-bold text-gray-900"><?php echo SESSION_TIMEOUT / 60; ?> min</div>
                <div class="text-xs text-gray-500 mt-1">Auto-logout after inactivity</div>
            </div>
            
            <div class="bg-gray-50 rounded-lg p-4">
                <div class="text-xs text-gray-600 mb-1">Password Strength</div>
                <div class="text-2xl font-bold text-gray-900">Strong</div>
                <div class="text-xs text-gray-500 mt-1">Using Argon2ID</div>
            </div>
            
            <div class="bg-gray-50 rounded-lg p-4">
                <div class="text-xs text-gray-600 mb-1">2FA Status</div>
                <div class="text-2xl font-bold text-gray-900">Soon</div>
                <div class="text-xs text-gray-500 mt-1">Coming in next release</div>
            </div>
        </div>
        
        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
            <div class="flex items-start">
                <svg class="w-5 h-5 text-yellow-600 mr-3 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                </svg>
                <div>
                    <p class="font-semibold text-yellow-800">Security Reminder</p>
                    <p class="text-sm text-yellow-700 mt-1">Always use a strong password and never share your login credentials.</p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- System Information -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <div class="flex items-center space-x-3 mb-6">
            <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center">
                <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"></path>
                </svg>
            </div>
            <h3 class="text-xl font-semibold text-gray-900">System Information</h3>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <p class="text-sm text-gray-600">Application Name</p>
                <p class="font-semibold text-gray-900"><?php echo APP_NAME; ?></p>
            </div>
            
            <div>
                <p class="text-sm text-gray-600">Version</p>
                <p class="font-semibold text-gray-900"><?php echo APP_VERSION; ?></p>
            </div>
            
            <div>
                <p class="text-sm text-gray-600">PHP Version</p>
                <p class="font-semibold text-gray-900"><?php echo phpversion(); ?></p>
            </div>
            
            <div>
                <p class="text-sm text-gray-600">Server Time</p>
                <p class="font-semibold text-gray-900"><?php echo date('Y-m-d H:i:s'); ?></p>
            </div>
            
            <div>
                <p class="text-sm text-gray-600">Encryption</p>
                <p class="font-semibold text-gray-900"><?php echo ENCRYPTION_METHOD; ?></p>
            </div>
            
            <div>
                <p class="text-sm text-gray-600">Database</p>
                <p class="font-semibold text-gray-900">MySQL/MariaDB</p>
            </div>
        </div>
    </div>
    
    <!-- Quick Links -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <div class="flex items-center space-x-3 mb-6">
            <div class="w-10 h-10 bg-indigo-100 rounded-lg flex items-center justify-center">
                <svg class="w-6 h-6 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path>
                </svg>
            </div>
            <h3 class="text-xl font-semibold text-gray-900">Quick Links</h3>
        </div>
        
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <a href="<?php echo BASE_URL; ?>/profile.php" class="inline-flex items-center justify-center px-4 py-2 border border-gray-300 rounded-lg font-medium text-gray-700 bg-white hover:bg-gray-50 transition-colors">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                </svg>
                Profile
            </a>
            <a href="<?php echo BASE_URL; ?>/dashboard.php" class="inline-flex items-center justify-center px-4 py-2 border border-gray-300 rounded-lg font-medium text-gray-700 bg-white hover:bg-gray-50 transition-colors">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                </svg>
                Dashboard
            </a>
            <a href="<?php echo BASE_URL; ?>/reports/headcount.php" class="inline-flex items-center justify-center px-4 py-2 border border-gray-300 rounded-lg font-medium text-gray-700 bg-white hover:bg-gray-50 transition-colors">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                </svg>
                Reports
            </a>
            <a href="<?php echo BASE_URL; ?>/logout.php" class="inline-flex items-center justify-center px-4 py-2 border border-red-300 rounded-lg font-medium text-red-700 bg-red-50 hover:bg-red-100 transition-colors">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                </svg>
                Logout
            </a>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/includes/layout.php';
?>
