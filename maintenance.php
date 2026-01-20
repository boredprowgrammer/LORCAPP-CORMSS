<?php
/**
 * Maintenance Mode Page
 * Displays when the system is under maintenance or when configuration errors occur
 */

$maintenanceMessage = 'The system is currently undergoing scheduled maintenance. Please check back shortly.';
$maintenanceEndTime = null;
$isConfigError = isset($_GET['reason']) && $_GET['reason'] === 'config';

// If this is a config error, use a generic message (can't access database anyway)
if ($isConfigError) {
    $maintenanceMessage = 'The system is temporarily unavailable due to configuration issues. Our team has been notified and is working to resolve this.';
} else {
    // Try to load maintenance settings from database
    try {
        // Load database config without full config.php (which might trigger maintenance redirect)
        $dbHost = getenv('DB_HOST') ?: 'localhost';
        $dbName = getenv('DB_NAME') ?: 'cfo_registry';
        $dbUser = getenv('DB_USER') ?: 'root';
        $dbPass = getenv('DB_PASS') ?: '';
        
        $pdo = new PDO(
            "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
            $dbUser,
            $dbPass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        // Get maintenance settings
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('maintenance_message', 'maintenance_end_time')");
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        if (!empty($settings['maintenance_message'])) {
            $maintenanceMessage = $settings['maintenance_message'];
        }
        if (!empty($settings['maintenance_end_time'])) {
            $maintenanceEndTime = $settings['maintenance_end_time'];
        }
    } catch (Exception $e) {
        // Silent fail - use default message
        error_log('Maintenance page could not load settings: ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Maintenance - CORMSS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .animate-spin-slow { animation: spin 3s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .animate-pulse-slow { animation: pulse 3s ease-in-out infinite; }
    </style>
</head>
<body class="bg-gradient-to-br from-slate-900 via-blue-900 to-slate-900 min-h-screen flex items-center justify-center p-4">
    
    <div class="max-w-lg w-full">
        <!-- Main Card -->
        <div class="bg-white/10 backdrop-blur-lg rounded-2xl p-8 shadow-2xl border border-white/20 text-center">
            
            <!-- Animated Icon -->
            <div class="relative w-24 h-24 mx-auto mb-6">
                <div class="absolute inset-0 bg-blue-500/20 rounded-full animate-ping"></div>
                <div class="relative w-24 h-24 bg-gradient-to-br from-blue-500 to-indigo-600 rounded-full flex items-center justify-center shadow-lg shadow-blue-500/30">
                    <svg class="w-12 h-12 text-white animate-spin-slow" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                    </svg>
                </div>
            </div>
            
            <!-- Title -->
            <h1 class="text-3xl font-bold text-white mb-3">Under Maintenance</h1>
            
            <!-- Message -->
            <p class="text-blue-200 text-lg mb-6" id="maintenanceMessage">
                <?php echo isset($maintenanceMessage) ? htmlspecialchars($maintenanceMessage) : 'The system is currently undergoing scheduled maintenance. Please check back shortly.'; ?>
            </p>
            
            <!-- Estimated Time -->
            <?php if (!empty($maintenanceEndTime)): ?>
            <div class="bg-white/10 rounded-xl p-4 mb-6">
                <p class="text-blue-300 text-sm mb-1">Estimated Completion</p>
                <p class="text-white font-semibold text-lg"><?php echo htmlspecialchars($maintenanceEndTime); ?></p>
            </div>
            <?php endif; ?>
            
            <!-- Progress Indicator -->
            <div class="flex items-center justify-center gap-2 mb-6">
                <div class="w-2 h-2 bg-blue-400 rounded-full animate-bounce" style="animation-delay: 0ms;"></div>
                <div class="w-2 h-2 bg-blue-400 rounded-full animate-bounce" style="animation-delay: 150ms;"></div>
                <div class="w-2 h-2 bg-blue-400 rounded-full animate-bounce" style="animation-delay: 300ms;"></div>
            </div>
            
            <!-- Status -->
            <div class="inline-flex items-center gap-2 px-4 py-2 <?php echo $isConfigError ? 'bg-red-500/20 border-red-500/40 text-red-300' : 'bg-yellow-500/20 border-yellow-500/40 text-yellow-300'; ?> border rounded-full text-sm">
                <span class="w-2 h-2 <?php echo $isConfigError ? 'bg-red-400' : 'bg-yellow-400'; ?> rounded-full animate-pulse"></span>
                <?php echo $isConfigError ? 'System Issue Detected' : 'Maintenance in Progress'; ?>
            </div>
        </div>
        
        <!-- Footer Info -->
        <div class="text-center mt-6">
            <p class="text-blue-300/60 text-sm">
                If you need immediate assistance, please contact the system administrator.
            </p>
            
            <!-- Refresh Button -->
            <button onclick="location.reload()" class="mt-4 inline-flex items-center gap-2 px-4 py-2 bg-white/10 hover:bg-white/20 text-white rounded-lg transition-colors text-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                </svg>
                Check Again
            </button>
            
            <!-- Admin Login Link (hidden by default, shown via JS if needed) -->
            <div class="mt-4">
                <a href="login.php?bypass_maintenance=1" class="text-blue-400/50 hover:text-blue-400 text-xs transition-colors">
                    Administrator Access
                </a>
            </div>
        </div>
    </div>
    
    <!-- Auto-refresh every 60 seconds -->
    <script>
        setTimeout(() => location.reload(), 60000);
    </script>
</body>
</html>
