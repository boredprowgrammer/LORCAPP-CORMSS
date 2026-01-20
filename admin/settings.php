<?php
/**
 * Admin System Settings
 * Manage maintenance mode and other system-wide settings
 */

require_once __DIR__ . '/../config/config.php';

Security::requireLogin();

// Only admins can access this page
$currentUser = getCurrentUser();
if ($currentUser['role'] !== 'admin') {
    $_SESSION['error'] = 'Access denied. Admin privileges required.';
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$db = Database::getInstance()->getConnection();

// Ensure system_settings table exists
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS system_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) NOT NULL UNIQUE,
            setting_value TEXT,
            setting_type ENUM('boolean', 'string', 'integer', 'json') DEFAULT 'string',
            description VARCHAR(255),
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            updated_by INT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    // Insert default settings if they don't exist
    $db->exec("
        INSERT IGNORE INTO system_settings (setting_key, setting_value, setting_type, description) VALUES
        ('maintenance_mode', '0', 'boolean', 'Enable maintenance mode for all users except admins'),
        ('maintenance_message', 'The system is currently undergoing scheduled maintenance. Please check back shortly.', 'string', 'Message displayed during maintenance'),
        ('maintenance_end_time', '', 'string', 'Estimated end time for maintenance (displayed to users)')
    ");
} catch (Exception $e) {
    error_log("Error creating system_settings table: " . $e->getMessage());
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_maintenance') {
        $maintenanceMode = isset($_POST['maintenance_mode']) ? '1' : '0';
        $maintenanceMessage = Security::sanitizeInput($_POST['maintenance_message'] ?? '');
        $maintenanceEndTime = Security::sanitizeInput($_POST['maintenance_end_time'] ?? '');
        
        try {
            // Update maintenance mode
            $stmt = $db->prepare("
                INSERT INTO system_settings (setting_key, setting_value, setting_type, updated_by) 
                VALUES (?, ?, 'boolean', ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = NOW()
            ");
            $stmt->execute(['maintenance_mode', $maintenanceMode, $currentUser['user_id']]);
            
            // Update maintenance message
            $stmt = $db->prepare("
                INSERT INTO system_settings (setting_key, setting_value, setting_type, updated_by) 
                VALUES (?, ?, 'string', ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = NOW()
            ");
            $stmt->execute(['maintenance_message', $maintenanceMessage, $currentUser['user_id']]);
            
            // Update maintenance end time
            $stmt = $db->prepare("
                INSERT INTO system_settings (setting_key, setting_value, setting_type, updated_by) 
                VALUES (?, ?, 'string', ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = NOW()
            ");
            $stmt->execute(['maintenance_end_time', $maintenanceEndTime, $currentUser['user_id']]);
            
            // Log the action
            logAudit('system_settings', 'update', null, [
                'maintenance_mode' => $maintenanceMode,
                'updated_by' => $currentUser['username']
            ]);
            
            $_SESSION['success'] = $maintenanceMode === '1' 
                ? 'Maintenance mode enabled. All non-admin users will see the maintenance page.'
                : 'Maintenance mode disabled. System is now accessible to all users.';
            
        } catch (Exception $e) {
            error_log("Error updating maintenance settings: " . $e->getMessage());
            $_SESSION['error'] = 'Failed to update settings. Please try again.';
        }
        
        header('Location: settings.php');
        exit;
    }
}

// Get current settings
$settings = [];
try {
    $stmt = $db->query("SELECT setting_key, setting_value FROM system_settings");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    error_log("Error fetching settings: " . $e->getMessage());
}

$maintenanceMode = ($settings['maintenance_mode'] ?? '0') === '1';
$maintenanceMessage = $settings['maintenance_message'] ?? 'The system is currently undergoing scheduled maintenance. Please check back shortly.';
$maintenanceEndTime = $settings['maintenance_end_time'] ?? '';

$pageTitle = 'System Settings';
ob_start();
?>

<div class="max-w-4xl mx-auto space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-3xl font-bold text-gray-900 dark:text-gray-100">System Settings</h2>
            <p class="text-sm text-gray-600 dark:text-gray-400">Configure system-wide settings and maintenance mode</p>
        </div>
        <a href="<?php echo BASE_URL; ?>/dashboard.php" class="inline-flex items-center px-4 py-2 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
            </svg>
            Back to Dashboard
        </a>
    </div>
    
    <!-- Success/Error Messages -->
    <?php if (isset($_SESSION['success'])): ?>
    <div class="bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 rounded-lg p-4">
        <div class="flex items-center">
            <svg class="w-5 h-5 text-green-500 mr-3" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
            </svg>
            <p class="text-green-700 dark:text-green-300"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></p>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error'])): ?>
    <div class="bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 rounded-lg p-4">
        <div class="flex items-center">
            <svg class="w-5 h-5 text-red-500 mr-3" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
            </svg>
            <p class="text-red-700 dark:text-red-300"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></p>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Maintenance Mode Section -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
        <div class="bg-gradient-to-r from-yellow-500 to-orange-500 px-6 py-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-white/20 rounded-lg flex items-center justify-center">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                    </svg>
                </div>
                <div>
                    <h3 class="text-xl font-semibold text-white">Maintenance Mode</h3>
                    <p class="text-white/80 text-sm">Control system availability for users</p>
                </div>
            </div>
        </div>
        
        <form method="POST" action="" class="p-6 space-y-6">
            <input type="hidden" name="action" value="update_maintenance">
            
            <!-- Current Status -->
            <div class="flex items-center justify-between p-4 rounded-lg <?php echo $maintenanceMode ? 'bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800' : 'bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800'; ?>">
                <div class="flex items-center gap-3">
                    <?php if ($maintenanceMode): ?>
                    <div class="w-3 h-3 bg-red-500 rounded-full animate-pulse"></div>
                    <span class="font-medium text-red-700 dark:text-red-400">Maintenance Mode is ACTIVE</span>
                    <?php else: ?>
                    <div class="w-3 h-3 bg-green-500 rounded-full"></div>
                    <span class="font-medium text-green-700 dark:text-green-400">System is ONLINE</span>
                    <?php endif; ?>
                </div>
                <span class="text-sm <?php echo $maintenanceMode ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400'; ?>">
                    <?php echo $maintenanceMode ? 'Non-admin users cannot access the system' : 'All users can access the system'; ?>
                </span>
            </div>
            
            <!-- Enable/Disable Toggle -->
            <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                <div>
                    <label for="maintenance_mode" class="font-medium text-gray-900 dark:text-gray-100">Enable Maintenance Mode</label>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">When enabled, only administrators can access the system. All other users will see the maintenance page.</p>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" name="maintenance_mode" id="maintenance_mode" class="sr-only peer" <?php echo $maintenanceMode ? 'checked' : ''; ?>>
                    <div class="w-14 h-7 bg-gray-200 dark:bg-gray-600 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-yellow-300 dark:peer-focus:ring-yellow-800 rounded-full peer peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-0.5 after:start-[4px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-6 after:w-6 after:transition-all peer-checked:bg-yellow-500"></div>
                </label>
            </div>
            
            <!-- Maintenance Message -->
            <div>
                <label for="maintenance_message" class="block font-medium text-gray-900 dark:text-gray-100 mb-2">Maintenance Message</label>
                <textarea 
                    id="maintenance_message" 
                    name="maintenance_message" 
                    rows="3" 
                    class="w-full px-4 py-3 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-yellow-500 focus:border-yellow-500 dark:text-gray-100"
                    placeholder="Enter the message to display during maintenance..."
                ><?php echo Security::escape($maintenanceMessage); ?></textarea>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">This message will be displayed to users during maintenance.</p>
            </div>
            
            <!-- Estimated End Time -->
            <div>
                <label for="maintenance_end_time" class="block font-medium text-gray-900 dark:text-gray-100 mb-2">Estimated End Time (Optional)</label>
                <input 
                    type="text" 
                    id="maintenance_end_time" 
                    name="maintenance_end_time" 
                    value="<?php echo Security::escape($maintenanceEndTime); ?>"
                    class="w-full px-4 py-3 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-yellow-500 focus:border-yellow-500 dark:text-gray-100"
                    placeholder="e.g., January 20, 2026 at 5:00 PM"
                >
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Displayed to users so they know when to check back.</p>
            </div>
            
            <!-- Preview Link -->
            <div class="p-4 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg">
                <div class="flex items-start gap-3">
                    <svg class="w-5 h-5 text-blue-500 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                    </svg>
                    <div>
                        <p class="text-blue-700 dark:text-blue-300 font-medium">Preview Maintenance Page</p>
                        <p class="text-sm text-blue-600 dark:text-blue-400 mt-1">
                            <a href="<?php echo BASE_URL; ?>/maintenance.php" target="_blank" class="underline hover:no-underline">
                                Click here to preview how the maintenance page looks
                            </a>
                        </p>
                    </div>
                </div>
            </div>
            
            <!-- Submit Button -->
            <div class="flex justify-end gap-3 pt-4 border-t border-gray-200 dark:border-gray-700">
                <button 
                    type="submit" 
                    class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-yellow-500 to-orange-500 text-white font-medium rounded-lg hover:from-yellow-600 hover:to-orange-600 focus:ring-4 focus:ring-yellow-300 transition-all shadow-lg shadow-yellow-500/25"
                >
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                    Save Settings
                </button>
            </div>
        </form>
    </div>
    
    <!-- Quick Links -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <a href="<?php echo BASE_URL; ?>/admin/users.php" class="flex items-center gap-3 p-4 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg hover:border-blue-400 dark:hover:border-blue-500 transition-colors">
            <div class="w-10 h-10 bg-blue-100 dark:bg-blue-900/30 rounded-lg flex items-center justify-center">
                <svg class="w-5 h-5 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                </svg>
            </div>
            <div>
                <p class="font-medium text-gray-900 dark:text-gray-100">User Management</p>
                <p class="text-sm text-gray-500 dark:text-gray-400">Manage system users</p>
            </div>
        </a>
        
        <a href="<?php echo BASE_URL; ?>/admin/audit.php" class="flex items-center gap-3 p-4 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg hover:border-green-400 dark:hover:border-green-500 transition-colors">
            <div class="w-10 h-10 bg-green-100 dark:bg-green-900/30 rounded-lg flex items-center justify-center">
                <svg class="w-5 h-5 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path>
                </svg>
            </div>
            <div>
                <p class="font-medium text-gray-900 dark:text-gray-100">Audit Log</p>
                <p class="text-sm text-gray-500 dark:text-gray-400">View system activity</p>
            </div>
        </a>
        
        <a href="<?php echo BASE_URL; ?>/admin/announcements.php" class="flex items-center gap-3 p-4 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg hover:border-purple-400 dark:hover:border-purple-500 transition-colors">
            <div class="w-10 h-10 bg-purple-100 dark:bg-purple-900/30 rounded-lg flex items-center justify-center">
                <svg class="w-5 h-5 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path>
                </svg>
            </div>
            <div>
                <p class="font-medium text-gray-900 dark:text-gray-100">Announcements</p>
                <p class="text-sm text-gray-500 dark:text-gray-400">Manage announcements</p>
            </div>
        </a>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
