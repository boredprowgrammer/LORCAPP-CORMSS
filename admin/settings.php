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

<div class="max-w-3xl mx-auto space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">System Settings</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">Configure system-wide settings</p>
        </div>
        <a href="<?php echo BASE_URL; ?>/launchpad.php" class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
            ← Launchpad
        </a>
    </div>
    
    <!-- Messages -->
    <?php if (isset($_SESSION['success'])): ?>
    <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg p-4">
        <p class="text-sm text-green-700 dark:text-green-300"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></p>
    </div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error'])): ?>
    <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
        <p class="text-sm text-red-700 dark:text-red-300"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></p>
    </div>
    <?php endif; ?>
    
    <!-- Maintenance Mode -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700">
        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Maintenance Mode</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400">Control system availability for users</p>
        </div>
        
        <form method="POST" action="" class="p-6 space-y-5">
            <input type="hidden" name="action" value="update_maintenance">
            
            <!-- Status Indicator -->
            <div class="flex items-center justify-between p-4 rounded-lg <?php echo $maintenanceMode ? 'bg-red-50 dark:bg-red-900/20' : 'bg-green-50 dark:bg-green-900/20'; ?>">
                <div class="flex items-center gap-3">
                    <div class="w-2.5 h-2.5 rounded-full <?php echo $maintenanceMode ? 'bg-red-500 animate-pulse' : 'bg-green-500'; ?>"></div>
                    <span class="font-medium <?php echo $maintenanceMode ? 'text-red-700 dark:text-red-300' : 'text-green-700 dark:text-green-300'; ?>">
                        <?php echo $maintenanceMode ? 'Maintenance Mode Active' : 'System Online'; ?>
                    </span>
                </div>
                <span class="text-sm <?php echo $maintenanceMode ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400'; ?>">
                    <?php echo $maintenanceMode ? 'Non-admin users blocked' : 'All users can access'; ?>
                </span>
            </div>
            
            <!-- Toggle -->
            <div class="flex items-center justify-between">
                <div>
                    <label for="maintenance_mode" class="font-medium text-gray-900 dark:text-gray-100">Enable Maintenance Mode</label>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Only administrators can access the system when enabled</p>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" name="maintenance_mode" id="maintenance_mode" class="sr-only peer" <?php echo $maintenanceMode ? 'checked' : ''; ?>>
                    <div class="w-11 h-6 bg-gray-200 dark:bg-gray-600 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                </label>
            </div>
            
            <!-- Message -->
            <div>
                <label for="maintenance_message" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Maintenance Message</label>
                <textarea 
                    id="maintenance_message" 
                    name="maintenance_message" 
                    rows="3" 
                    class="w-full px-4 py-2.5 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                    placeholder="Enter message to display during maintenance..."
                ><?php echo Security::escape($maintenanceMessage); ?></textarea>
            </div>
            
            <!-- End Time -->
            <div>
                <label for="maintenance_end_time" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Estimated End Time</label>
                <input 
                    type="text" 
                    id="maintenance_end_time" 
                    name="maintenance_end_time" 
                    value="<?php echo Security::escape($maintenanceEndTime); ?>"
                    class="w-full px-4 py-2.5 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                    placeholder="e.g., January 21, 2026 at 5:00 PM"
                >
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Displayed to users so they know when to check back</p>
            </div>
            
            <!-- Preview Link -->
            <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4">
                <a href="<?php echo BASE_URL; ?>/maintenance.php" target="_blank" class="text-sm text-blue-600 dark:text-blue-400 hover:underline">
                    Preview maintenance page →
                </a>
            </div>
            
            <!-- Submit -->
            <div class="flex justify-end pt-4 border-t border-gray-200 dark:border-gray-700">
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors">
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
                <p class="font-medium text-gray-900 dark:text-gray-100">Users</p>
                <p class="text-xs text-gray-500 dark:text-gray-400">Manage users</p>
            </div>
        </a>
        
        <a href="<?php echo BASE_URL; ?>/admin/audit.php" class="flex items-center gap-3 p-4 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg hover:border-green-400 dark:hover:border-green-500 transition-colors">
            <div class="w-10 h-10 bg-green-100 dark:bg-green-900/30 rounded-lg flex items-center justify-center">
                <svg class="w-5 h-5 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                </svg>
            </div>
            <div>
                <p class="font-medium text-gray-900 dark:text-gray-100">Audit Log</p>
                <p class="text-xs text-gray-500 dark:text-gray-400">View activity</p>
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
                <p class="text-xs text-gray-500 dark:text-gray-400">Manage news</p>
            </div>
        </a>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
