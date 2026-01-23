<?php
// Generate nonce for inline scripts (CSP) - use existing one if already set
if (!isset($csp_nonce)) {
    $csp_nonce = base64_encode(random_bytes(16));
}

// Check maintenance mode for all pages using layout
checkMaintenanceMode();

// Detect current app context based on URL path
function detectAppContext() {
    $path = $_SERVER['REQUEST_URI'];
    $scriptName = basename($_SERVER['SCRIPT_NAME'], '.php');
    
    // Officers app context
    if (strpos($path, '/officers') !== false || strpos($path, '/transfers') !== false || 
        in_array($scriptName, ['officers-list', 'officer-add', 'bulk-update', 'transfer-in', 'transfer-out'])) {
        return 'officers';
    }
    
    // CFO app context
    if (strpos($path, '/cfo') !== false || strpos($path, '/hdb') !== false || strpos($path, '/pnk') !== false ||
        in_array($scriptName, ['cfo-registry', 'cfo-add', 'cfo-checker', 'hdb-registry', 'hdb-add', 'pnk-registry', 'pnk-add'])) {
        return 'cfo';
    }
    
    // Registry app context
    if (strpos($path, '/legacy') !== false || strpos($path, '/lorcapp') !== false || 
        in_array($scriptName, ['cfo-import', 'cfo-import-purok-grupo'])) {
        return 'registry';
    }
    
    // Reports app context
    if (strpos($path, '/reports') !== false || 
        in_array($scriptName, ['masterlist', 'headcount', 'departments'])) {
        return 'reports';
    }
    
    // Call-up app context
    if (strpos($path, '/call-up') !== false || strpos($path, 'logbook') !== false || 
        strpos($path, 'r513') !== false || strpos($path, 'palasumpaan') !== false ||
        in_array($scriptName, ['generate-r513', 'generate-palasumpaan', 'logbook-control-number'])) {
        return 'callup';
    }
    
    // Requests app context
    if (strpos($path, '/requests') !== false || strpos($path, 'pending-actions') !== false ||
        strpos($path, 'access-requests') !== false) {
        return 'requests';
    }
    
    return null;
}

$currentAppContext = detectAppContext();
?>
<!DOCTYPE html>
<html lang="en" x-data="{ darkMode: localStorage.getItem('darkMode') === 'true' }" :class="{ 'dark': darkMode }" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Content-Security-Policy" content="script-src 'self' 'unsafe-inline' 'unsafe-eval' 'unsafe-hashes' https://cdn.tailwindcss.com https://cdn.jsdelivr.net https://code.jquery.com https://cdn.datatables.net https://cdnjs.cloudflare.com https://js.puter.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.datatables.net https://site-assets.fontawesome.com; font-src 'self' https://fonts.gstatic.com https://site-assets.fontawesome.com; img-src 'self' data: https:; connect-src 'self' https://api.puter.com https://*.puter.com wss://api.puter.com wss://*.puter.com;">

    <title><?php echo isset($pageTitle) ? Security::escape($pageTitle) . ' - LORCAPP' : 'LORCAPP - Church Officers Registry'; ?></title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script nonce="<?php echo $csp_nonce; ?>">
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'system-ui', 'sans-serif'],
                    },
                }
            }
        }
    </script>
    
    <!-- Alpine.js -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Font Awesome Pro -->
    <link rel="stylesheet" href="https://site-assets.fontawesome.com/releases/v6.5.1/css/all.css">
    <link rel="stylesheet" href="https://site-assets.fontawesome.com/releases/v6.5.1/css/sharp-solid.css">
    <link rel="stylesheet" href="https://site-assets.fontawesome.com/releases/v6.5.1/css/sharp-regular.css">
    <link rel="stylesheet" href="https://site-assets.fontawesome.com/releases/v6.5.1/css/sharp-light.css">
    
    <style nonce="<?php echo $csp_nonce; ?>">
        * { font-family: 'Inter', sans-serif; }
        
        .watermark-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 9999;
            opacity: 0.03;
            background-image: repeating-linear-gradient(
                45deg,
                transparent,
                transparent 100px,
                currentColor 100px,
                currentColor 200px
            );
        }
        
        .watermark-overlay::before {
            content: 'LORCAPP';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 8rem;
            font-weight: 900;
            color: currentColor;
            opacity: 0.5;
        }
        
        @keyframes fade-in {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .animate-fade-in {
            animation: fade-in 0.3s ease-out;
        }
        
        [x-cloak] { display: none !important; }
        
        /* Global dark mode for form inputs */
        .dark input[type="text"]:not(.no-dark):not([class*="bg-white"]),
        .dark input[type="email"]:not(.no-dark),
        .dark input[type="password"]:not(.no-dark),
        .dark input[type="tel"]:not(.no-dark),
        .dark input[type="number"]:not(.no-dark),
        .dark input[type="date"]:not(.no-dark),
        .dark input[type="datetime-local"]:not(.no-dark),
        .dark input[type="time"]:not(.no-dark),
        .dark input[type="url"]:not(.no-dark),
        .dark input[type="search"]:not(.no-dark),
        .dark select:not(.no-dark),
        .dark textarea:not(.no-dark) {
            background-color: #374151 !important;
            border-color: #4b5563 !important;
            color: #f3f4f6 !important;
        }
        .dark input[type="text"]:disabled,
        .dark input[type="email"]:disabled,
        .dark select:disabled {
            background-color: #1f2937 !important;
            color: #9ca3af !important;
        }
        .dark input:focus,
        .dark select:focus,
        .dark textarea:focus {
            border-color: #60a5fa !important;
        }
    </style>
</head>
<body class="h-full bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-gray-100 antialiased">
    
    <!-- Loading Overlay -->
    <div id="loadingOverlay" class="fixed inset-0 bg-gray-900/50 dark:bg-black/70 backdrop-blur-sm z-50 hidden items-center justify-center transition-all duration-200">
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl p-8 flex flex-col items-center gap-4">
            <div class="animate-spin rounded-full h-16 w-16 border-4 border-gray-200 dark:border-gray-700 border-t-blue-600 dark:border-t-blue-500"></div>
            <div class="loading-text text-lg font-medium text-gray-900 dark:text-gray-100">Loading...</div>
        </div>
    </div>
    
    <?php if (Security::isLoggedIn()): ?>
        <?php 
        $currentUser = getCurrentUser(); 
        require_once __DIR__ . '/app-navigation.php';
        ?>
        
        <!-- Main Layout - Full Width with App Navigation -->
        <div class="flex flex-col h-screen overflow-hidden bg-gray-50 dark:bg-gray-900">
            
            <?php if ($currentAppContext): ?>
                <!-- App-Specific Navigation -->
                <?php renderAppNavigation($currentAppContext, basename($_SERVER['PHP_SELF'])); ?>
            <?php endif; ?>
            
            <!-- Main Content Area -->
            <div class="flex-1 flex flex-col overflow-hidden">
                <!-- Page Content -->
                <main class="flex-1 overflow-x-hidden overflow-y-auto bg-gray-50 dark:bg-gray-900">
                    <div class="max-w-7xl mx-auto px-6 py-8">
                        <?php 
                        $flash = getFlashMessage();
                        if ($flash): 
                        ?>
                            <div class="mb-6 animate-fade-in">
                                <div class="rounded-xl p-4 shadow-sm <?php 
                                    echo $flash['type'] === 'success' ? 'bg-green-50 dark:bg-green-900/20 text-green-800 dark:text-green-300 border border-green-200 dark:border-green-800' : 
                                        ($flash['type'] === 'error' ? 'bg-red-50 dark:bg-red-900/20 text-red-800 dark:text-red-300 border border-red-200 dark:border-red-800' : 
                                        ($flash['type'] === 'warning' ? 'bg-yellow-50 dark:bg-yellow-900/20 text-yellow-800 dark:text-yellow-300 border border-yellow-200 dark:border-yellow-800' : 
                                        'bg-blue-50 dark:bg-blue-900/20 text-blue-800 dark:text-blue-300 border border-blue-200 dark:border-blue-800')); 
                                ?>">
                                    <div class="flex items-center">
                                        <svg class="w-5 h-5 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                            <?php if ($flash['type'] === 'success'): ?>
                                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                            <?php elseif ($flash['type'] === 'error'): ?>
                                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                                            <?php else: ?>
                                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                                            <?php endif; ?>
                                        </svg>
                                        <span class="font-medium"><?php echo Security::escape($flash['message']); ?></span>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <?php echo $content ?? ''; ?>
                    </div>
                </main>
            </div>
        </div>
        
        <!-- Diagonal Watermark Overlay -->
        <div id="watermarkOverlay" class="watermark-overlay text-gray-400 dark:text-gray-700"></div>
        
    <?php else: ?>
        <!-- Guest Layout (Login/Register Pages) -->
        <div class="min-h-screen flex items-center justify-center p-4 bg-gradient-to-br from-blue-50 to-indigo-100 dark:from-gray-900 dark:to-gray-800">
            <?php echo $content ?? ''; ?>
        </div>
        
        <!-- Diagonal Watermark Overlay -->
        <div id="watermarkOverlay" class="watermark-overlay text-gray-400 dark:text-gray-700"></div>
    <?php endif; ?>
    
    <!-- Scripts -->
    <script nonce="<?php echo $csp_nonce; ?>">
        // Loading Spinner Functions
        function showLoader(message = 'Loading...') {
            const overlay = document.getElementById('loadingOverlay');
            const textElement = overlay.querySelector('.loading-text');
            if (textElement) textElement.textContent = message;
            overlay.classList.remove('hidden');
            overlay.classList.add('flex');
        }
        
        function hideLoader() {
            const overlay = document.getElementById('loadingOverlay');
            overlay.classList.add('hidden');
            overlay.classList.remove('flex');
        }
        
        async function loaderPromise(promise, message = 'Loading...') {
            showLoader(message);
            try {
                const result = await promise;
                hideLoader();
                return result;
            } catch (error) {
                hideLoader();
                throw error;
            }
        }
        
        // Clock Functions
        function updateClock() {
            const now = new Date();
            const timeElement = document.getElementById('currentTime');
            if (timeElement) {
                const hours = now.getHours();
                const minutes = now.getMinutes().toString().padStart(2, '0');
                const seconds = now.getSeconds().toString().padStart(2, '0');
                const ampm = hours >= 12 ? 'PM' : 'AM';
                const displayHours = hours % 12 || 12;
                timeElement.textContent = `${displayHours}:${minutes}:${seconds} ${ampm}`;
            }
            
            const weekElement = document.getElementById('weekNumber');
            if (weekElement) {
                const weekNum = getWeekNumber(now);
                weekElement.textContent = `Week ${weekNum}`;
            }
        }
        
        function getWeekNumber(date) {
            const d = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()));
            const dayNum = d.getUTCDay() || 7;
            d.setUTCDate(d.getUTCDate() + 4 - dayNum);
            const yearStart = new Date(Date.UTC(d.getUTCFullYear(), 0, 1));
            return Math.ceil((((d - yearStart) / 86400000) + 1) / 7);
        }
        
        // Initialize clock
        updateClock();
        setInterval(updateClock, 1000);
        
        // Auto-hide flash messages after 5 seconds
        setTimeout(() => {
            const flashMessage = document.querySelector('.animate-fade-in');
            if (flashMessage) {
                flashMessage.style.transition = 'opacity 0.5s ease-out';
                flashMessage.style.opacity = '0';
                setTimeout(() => flashMessage.remove(), 500);
            }
        }, 5000);
    </script>
</body>
</html>
