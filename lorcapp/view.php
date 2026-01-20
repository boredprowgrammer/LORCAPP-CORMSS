<?php
/**
 * LORCAPP
 * View Record
 * Accessible from both LORCAPP and CORegistry with proper authentication
 */

// Start output buffering to catch any errors
ob_start();

// Suppress all errors in production
error_reporting(0);
ini_set('display_errors', 0);

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
    require_once __DIR__ . '/../includes/permissions.php';

require_once 'includes/config.php';
require_once 'includes/encryption.php';
require_once 'includes/photo.php';
require_once 'includes/settings.php';

// Check if accessed from CORegistry (has CORegistry session)
$fromCORegistry = false;
if (isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
    $fromCORegistry = true;
    // Verify user is authenticated
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        die('Unauthorized access. Please log in.');
    }
    
    // Check if user has permission to view legacy registry
    if (!hasPermission('can_view_legacy_registry')) {
        http_response_code(403);
        die('Access denied. You do not have permission to view the Legacy Registry.');
    }
    
    // Load security functions needed for record validation
    require_once 'includes/security.php';
} else {
    // Use LORCAPP authentication
    require_once 'includes/security.php';
    require_once 'includes/auth.php';
    
    startSecureSession();
    requireAuth();
}

/**
 * Obfuscate a name by replacing middle characters with asterisks
 * Example: "Juan" becomes "J**n", "Dela Cruz" becomes "De*a C**z"
 * 
 * @param string $name The name to obfuscate
 * @return string The obfuscated name
 */
function obfuscateName($name) {
    if (empty($name)) {
        return $name;
    }
    
    $words = explode(' ', trim($name));
    $obfuscatedWords = [];
    
    foreach ($words as $word) {
        $length = mb_strlen($word);
        
        if ($length <= 2) {
            // Very short words: show as is or with one asterisk
            $obfuscatedWords[] = $length == 1 ? $word : $word[0] . '*';
        } elseif ($length == 3) {
            // 3-letter words: show first and last
            $obfuscatedWords[] = $word[0] . '*' . $word[2];
        } else {
            // Longer words: show first, last, and replace middle with asterisks
            $first = mb_substr($word, 0, 1);
            $last = mb_substr($word, -1);
            $middleLength = $length - 2;
            $asterisks = str_repeat('*', min($middleLength, 2)); // Max 2 asterisks
            $obfuscatedWords[] = $first . $asterisks . $last;
        }
    }
    
    return implode(' ', $obfuscatedWords);
}

// Rate limit record viewing to prevent mass data scraping (max 30 views per minute)
if (!checkRateLimit('record_view', 30, 60)) {
    logSecurityEvent('RATE_LIMIT_EXCEEDED', ['action' => 'record_view', 'user' => $_SESSION['admin_username'] ?? 'unknown']);
    die('
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Rate Limit Exceeded</title>
        <script src="https://cdn.tailwindcss.com"></script>
    </head>
    <body class="bg-red-50 min-h-screen flex items-center justify-center p-4">
        <div class="bg-white rounded-lg shadow-2xl p-8 max-w-md w-full text-center border-t-4 border-red-500">
            <h1 class="text-3xl font-bold text-gray-900 mb-4">Too Many Requests</h1>
            <p class="text-gray-600 mb-6">You are viewing records too quickly. Please wait a moment and try again.</p>
            <a href="dashboard.php" class="inline-block bg-red-500 hover:bg-red-600 text-white font-semibold py-3 px-6 rounded-lg transition">
                Back to Dashboard
            </a>
        </div>
    </body>
    </html>
    ');
}

$conn = getDbConnection();
$id = isset($_GET['id']) ? $_GET['id'] : '';

// Validate record ID to prevent enumeration attacks
if (!validateRecordId($id)) {
    logSecurityEvent('RECORD_ACCESS_DENIED', [
        'reason' => 'invalid_id',
        'id' => $id,
        'user' => $_SESSION['admin_username'] ?? 'unknown'
    ]);
    header('Location: ../launchpad.php');
    exit();
}

// No need to cast to int - ID is now VARCHAR
$id = sanitize($id);

// Check if record exists before proceeding
if (!recordExists($conn, $id)) {
    logSecurityEvent('RECORD_ACCESS_DENIED', [
        'reason' => 'record_not_found',
        'id' => $id,
        'user' => $_SESSION['admin_username'] ?? 'unknown'
    ]);
    header('Location: ../launchpad.php');
    exit();
}

$query = "SELECT * FROM r201_members WHERE id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("s", $id); // Changed from "i" to "s" for VARCHAR
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header('Location: ../launchpad.php');
    exit();
}

$record = $result->fetch_assoc();

// Decrypt encrypted name fields
$record = decryptRecordNames($record);

// Check if record is archived
$is_archived = !empty($record['is_archived']) && $record['is_archived'] == 1;

// Log data access for audit trail (GDPR compliance)
logDataAccess('RECORD_VIEWED', $id, $_SESSION['admin_id'] ?? null, [
    'record_name' => $record['given_name'] ?? 'Unknown',
    'access_type' => 'web_view',
    'is_archived' => $is_archived
]);

// Helper function to display JSON data
function displayJson($json) {
    if (empty($json)) return '—';
    $data = jsonDecode($json);
    if (empty($data)) return '—';
    return $data;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Record #<?php echo $id; ?> - LORCAPP</title>
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- FontAwesome Icons -->
    <link rel="stylesheet" href="https://site-assets.fontawesome.com/releases/v6.4.2/css/all.css">
    
    <style>
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e8ecf1 100%);
            min-height: 100vh;
        }
        .glass {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(0, 0, 0, 0.05);
        }
        .glass-strong {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            overflow: visible !important;
        }
        
        /* Ensure dropdown appears above all content */
        nav {
            position: relative;
            z-index: 1000;
            overflow: visible !important;
        }
        
        .section-header {
            position: relative;
            padding-left: 20px;
        }
        .section-header::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 4px;
            height: 24px;
            background: #000000;
        }
        .info-label {
            font-size: 0.875rem;
            font-weight: 600;
            color: rgba(0, 0, 0, 0.6);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.25rem;
        }
        .info-value {
            font-weight: 600;
            color: #000000;
            font-size: 1rem;
        }
        
        /* Name obfuscation tooltip */
        .obfuscated-name {
            cursor: help;
            position: relative;
            display: inline-block;
            border-bottom: 1px dotted currentColor;
        }
        
        .obfuscated-name .tooltip {
            visibility: hidden;
            background-color: #1f2937;
            color: #fff;
            text-align: center;
            padding: 8px 12px;
            border-radius: 6px;
            position: absolute;
            z-index: 1000;
            bottom: 125%;
            left: 50%;
            transform: translateX(-50%);
            white-space: nowrap;
            opacity: 0;
            transition: opacity 0.3s, visibility 0.3s;
            font-size: 13px;
            font-weight: 500;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        }
        
        .obfuscated-name .tooltip::after {
            content: "";
            position: absolute;
            top: 100%;
            left: 50%;
            margin-left: -5px;
            border-width: 5px;
            border-style: solid;
            border-color: #1f2937 transparent transparent transparent;
        }
        
        .obfuscated-name:hover .tooltip {
            visibility: visible;
            opacity: 1;
        }
        
        /* Loading Overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(4px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            transition: opacity 0.3s ease;
        }
        
        .loading-overlay.active {
            display: flex;
        }
        
        .loading-spinner-container {
            text-align: center;
            animation: slideUpLoad 0.4s ease-out;
        }
        
        .loading-spinner {
            border: 4px solid rgba(255, 255, 255, 0.3);
            border-top: 4px solid #ffffff;
            border-radius: 50%;
            width: 64px;
            height: 64px;
            animation: spin 0.8s linear infinite;
            margin: 0 auto 20px;
        }
        
        .loading-text {
            color: #ffffff;
            font-size: 18px;
            font-weight: 600;
            text-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        @keyframes slideUpLoad {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
    
    <!-- Modal System -->
    <link rel="stylesheet" href="../assets/css/modal.css">
    <script src="../assets/js/modal.js"></script>
    
    <!-- Security: Prevent Right Click & Developer Tools -->
    <script src="../assets/js/security.js"></script>
    
    <!-- Confidential Watermark -->
    <link rel="stylesheet" href="../assets/css/watermark.css">
</head>
<body class="p-4 md:p-8">
    <?php 
    // Include and render watermark
    require_once 'includes/watermark.php';
    renderWatermark('111225'); // CONFIDENTIAL 111225
    ?>
    
    <!-- Loading Overlay -->
    <div id="loadingOverlay" class="loading-overlay">
        <div class="loading-spinner-container">
            <div class="loading-spinner"></div>
            <div class="loading-text">Loading...</div>
        </div>
    </div>
    <div class="max-w-7xl mx-auto" style="position: relative; z-index: 10;">
        <!-- Modern Navbar -->
        <nav class="glass-strong rounded-none mb-6 border-2 border-black" style="position: relative; z-index: 1000; overflow: visible;">
            <div class="flex items-center justify-between px-8 py-4">
                <!-- Left: Logo & Title -->
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-black rounded-lg flex items-center justify-center">
                        <i class="fa-solid fa-eye text-white text-xl"></i>
                    </div>
                    <div>
                        <h1 class="text-2xl font-black tracking-tight">
                            View Record
                            <?php if ($is_archived): ?>
                            <span class="ml-2 text-sm font-bold px-3 py-1 bg-orange-500 text-white rounded-none shadow-lg">
                                <i class="fa-solid fa-archive"></i> ARCHIVED
                            </span>
                            <?php endif; ?>
                        </h1>
                        <p class="text-xs text-gray-600 font-medium uppercase tracking-wide">Record ID: <?php echo htmlspecialchars($id); ?></p>
                    </div>
                </div>

                <!-- Right: Navigation & Profile -->
                <div class="flex items-center gap-2">
                    <!-- Quick Actions -->
                    <a href="dashboard.php" 
                       class="px-4 py-2 bg-white hover:bg-gray-100 text-black rounded-none font-bold text-xs uppercase tracking-wide border-2 border-gray-200 hover:border-black transition inline-flex items-center gap-2">
                        <i class="fa-solid fa-arrow-left"></i>
                        <span class="hidden lg:inline">Dashboard</span>
                    </a>
                    
                    <?php if (!$is_archived): ?>
                    <a href="edit.php?id=<?php echo $id; ?>" 
                       class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-none font-bold text-xs uppercase tracking-wide border-2 border-blue-600 transition inline-flex items-center gap-2">
                        <i class="fa-solid fa-pen-to-square"></i>
                        <span class="hidden lg:inline">Edit Record</span>
                    </a>
                    <?php else: ?>
                    <span class="px-4 py-2 bg-gray-400 text-white rounded-none font-bold text-xs uppercase tracking-wide border-2 border-gray-400 inline-flex items-center gap-2 cursor-not-allowed opacity-60" title="Cannot edit archived record">
                        <i class="fa-solid fa-lock"></i>
                        <span class="hidden lg:inline">Edit Locked</span>
                    </span>
                    <?php endif; ?>

                    <a href="print_r201.php?id=<?php echo $id; ?>" target="_blank"
                       class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-none font-bold text-xs uppercase tracking-wide border-2 border-green-600 transition inline-flex items-center gap-2">
                        <i class="fa-solid fa-print"></i>
                        <span class="hidden lg:inline">Print R-201</span>
                    </a>

                    <!-- Profile Dropdown -->
                    <div class="relative" x-data="{ open: false }" @click.away="open = false">
                        <button @click="open = !open" 
                                class="flex items-center gap-3 px-4 py-2 bg-black hover:bg-gray-900 text-white rounded-none font-bold text-xs uppercase tracking-wide border-2 border-black transition">
                            <div class="w-8 h-8 bg-white rounded-full flex items-center justify-center">
                                <i class="fa-solid fa-user text-black text-sm"></i>
                            </div>
                            <span class="hidden md:inline"><?php echo htmlspecialchars($_SESSION['admin_name']); ?></span>
                            <i class="fa-solid fa-chevron-down text-xs transition-transform" :class="{ 'rotate-180': open }"></i>
                        </button>

                        <!-- Dropdown Menu -->
                        <div x-show="open" 
                             x-transition:enter="transition ease-out duration-200"
                             x-transition:enter-start="opacity-0 scale-95"
                             x-transition:enter-end="opacity-100 scale-100"
                             x-transition:leave="transition ease-in duration-150"
                             x-transition:leave-start="opacity-100 scale-100"
                             x-transition:leave-end="opacity-0 scale-95"
                             class="absolute right-0 mt-2 w-64 bg-white border-2 border-black shadow-[8px_8px_0px_0px_rgba(0,0,0,1)] z-[9999]"
                             style="display: none;">
                            
                            <!-- Profile Header -->
                            <div class="px-4 py-3 border-b-2 border-black bg-gray-50">
                                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Signed in as</p>
                                <p class="text-sm font-black text-black mt-1"><?php echo htmlspecialchars($_SESSION['admin_name']); ?></p>
                                <p class="text-xs text-gray-600 mt-1"><?php echo htmlspecialchars($_SESSION['admin_username']); ?></p>
                            </div>

                            <!-- Menu Items -->
                            <div class="py-2">
                                <a href="dashboard.php" class="flex items-center gap-3 px-4 py-2.5 hover:bg-gray-100 transition group">
                                    <div class="w-8 h-8 bg-black group-hover:bg-gray-900 rounded-lg flex items-center justify-center transition">
                                        <i class="fa-solid fa-house text-white text-sm"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm font-bold text-black">Dashboard</p>
                                        <p class="text-xs text-gray-500">Overview & stats</p>
                                    </div>
                                </a>

                                <a href="settings.php" class="flex items-center gap-3 px-4 py-2.5 hover:bg-gray-100 transition group">
                                    <div class="w-8 h-8 bg-purple-600 group-hover:bg-purple-700 rounded-lg flex items-center justify-center transition">
                                        <i class="fa-solid fa-gear text-white text-sm"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm font-bold text-black">Settings</p>
                                        <p class="text-xs text-gray-500">Account preferences</p>
                                    </div>
                                </a>

                                <a href="auth_links.php" class="flex items-center gap-3 px-4 py-2.5 hover:bg-gray-100 transition group">
                                    <div class="w-8 h-8 bg-blue-600 group-hover:bg-blue-700 rounded-lg flex items-center justify-center transition">
                                        <i class="fa-solid fa-link-horizontal text-white text-sm"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm font-bold text-black">Auth Links</p>
                                        <p class="text-xs text-gray-500">Manage access</p>
                                    </div>
                                </a>
                            </div>

                            <!-- Logout -->
                            <div class="border-t-2 border-black">
                                <button onclick="confirmLogout()" class="w-full flex items-center gap-3 px-4 py-3 hover:bg-red-50 transition group">
                                    <div class="w-8 h-8 bg-red-600 group-hover:bg-red-700 rounded-lg flex items-center justify-center transition">
                                        <i class="fa-solid fa-right-from-bracket text-white text-sm"></i>
                                    </div>
                                    <div class="text-left">
                                        <p class="text-sm font-bold text-red-600">Logout</p>
                                        <p class="text-xs text-gray-500">Sign out of account</p>
                                    </div>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Member Information Card -->
        <div class="glass rounded-none shadow-2xl p-6 mb-6 border-l-4 border-black">
            <div class="flex flex-col md:flex-row justify-between items-start gap-6">
                <?php if (!empty($record['photo_data'])): ?>
                <!-- Photo Display (Base64 - Encrypted) -->
                <div class="flex-shrink-0">
                    <?php 
                    $decryptedPhoto = decryptPhotoBase64($record['photo_data']);
                    if ($decryptedPhoto !== false): 
                    ?>
                    <img src="data:<?php echo htmlspecialchars($record['photo_mime_type'] ?? 'image/jpeg'); ?>;base64,<?php echo htmlspecialchars($decryptedPhoto); ?>" 
                         alt="Member Photo" 
                         class="w-40 h-40 object-cover rounded-none border-4 border-black shadow-xl">
                    <?php else: ?>
                    <div class="w-40 h-40 flex items-center justify-center bg-gray-200 rounded-none border-4 border-black">
                        <span class="text-red-600 text-sm">Photo Decryption Failed</span>
                    </div>
                    <?php endif; ?>
                </div>
                <?php elseif (!empty($record['photo']) && photoExists($record['photo'])): ?>
                <!-- Photo Display (Legacy File System) -->
                <div class="flex-shrink-0">
                    <img src="../<?php echo htmlspecialchars(getPhotoUrl($record['photo'])); ?>" 
                         alt="Member Photo" 
                         class="w-40 h-40 object-cover rounded-none border-4 border-black shadow-xl">
                </div>
                <?php endif; ?>
                
                <div class="flex-1">
                                    <div>
                    <h1 class="text-4xl font-black text-black mb-2">Record <span class="font-mono"><?php echo htmlspecialchars($id); ?></span></h1>
                    <p class="text-xl font-bold text-black/80">
                        <?php 
                        $fullGivenName = htmlspecialchars($record['given_name']);
                        $obfuscatedGivenName = htmlspecialchars(obfuscateName($record['given_name']));
                        ?>
                        <span class="obfuscated-name">
                            <?php echo $obfuscatedGivenName; ?>
                            <span class="tooltip"><?php echo $fullGivenName; ?></span>
                        </span>
                    </p>
                    <?php if (empty($record['photo_data']) && empty($record['photo'])): ?>
                        <p class="text-sm text-gray-500 mt-2 font-medium">No photo uploaded</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Preliminary Questions -->
        <div class="glass rounded-none shadow-xl p-6 md:p-8 mb-6 border-l-4 border-black">
            <h2 class="text-2xl font-bold text-black mb-4">Preliminary Questions</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-black">
                <div>
                    <p class="text-black/70 text-sm">Kapisanan (Organization)</p>
                    <p class="font-semibold text-lg"><?php echo htmlspecialchars($record['kapisanan'] ?? '—'); ?></p>
                </div>
                <div>
                    <p class="text-black/70 text-sm">Tungkuling Hihilingin (Requested Office)</p>
                    <p class="font-semibold text-lg"><?php echo htmlspecialchars($record['tungkulin_hinihiling'] ?? '—'); ?></p>
                </div>
            </div>
        </div>

        <!-- Section C: Personal Information -->
        <div class="glass rounded-none shadow-xl p-6 md:p-8 mb-6">
            <h2 class="text-2xl font-bold text-black mb-4">C. Personal Information</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-black">
                <div>
                    <p class="text-black/70 text-sm">Given Name</p>
                    <p class="font-semibold">
                        <?php 
                        $fullGivenName2 = htmlspecialchars($record['given_name'] ?? '—');
                        $obfGivenName2 = htmlspecialchars(obfuscateName($record['given_name'] ?? '—'));
                        ?>
                        <span class="obfuscated-name">
                            <?php echo $obfGivenName2; ?>
                            <span class="tooltip"><?php echo $fullGivenName2; ?></span>
                        </span>
                    </p>
                </div>
                <div>
                    <p class="text-black/70 text-sm">Mother's Surname</p>
                    <p class="font-semibold">
                        <?php 
                        $fullMotherSurname = htmlspecialchars($record['mother_surname'] ?? '—');
                        $obfMotherSurname = htmlspecialchars(obfuscateName($record['mother_surname'] ?? '—'));
                        ?>
                        <span class="obfuscated-name">
                            <?php echo $obfMotherSurname; ?>
                            <span class="tooltip"><?php echo $fullMotherSurname; ?></span>
                        </span>
                    </p>
                </div>
                <div>
                    <p class="text-black/70 text-sm">Father's Surname</p>
                    <p class="font-semibold">
                        <?php 
                        $fullFatherSurname = htmlspecialchars($record['father_surname'] ?? '—');
                        $obfFatherSurname = htmlspecialchars(obfuscateName($record['father_surname'] ?? '—'));
                        ?>
                        <span class="obfuscated-name">
                            <?php echo $obfFatherSurname; ?>
                            <span class="tooltip"><?php echo $fullFatherSurname; ?></span>
                        </span>
                    </p>
                </div>
                <div>
                    <p class="text-black/70 text-sm">Husband's Surname</p>
                    <p class="font-semibold">
                        <?php 
                        $fullHusbandSurname = htmlspecialchars($record['husband_surname'] ?? '—');
                        $obfHusbandSurname = htmlspecialchars(obfuscateName($record['husband_surname'] ?? '—'));
                        ?>
                        <span class="obfuscated-name">
                            <?php echo $obfHusbandSurname; ?>
                            <span class="tooltip"><?php echo $fullHusbandSurname; ?></span>
                        </span>
                    </p>
                </div>
                <div>
                    <p class="text-black/70 text-sm">Date of Birth</p>
                    <p class="font-semibold"><?php echo $record['birth_date'] ? date('F d, Y', strtotime($record['birth_date'])) : '—'; ?></p>
                </div>
                <div>
                    <p class="text-black/70 text-sm">Place of Birth</p>
                    <p class="font-semibold"><?php echo htmlspecialchars($record['birth_place'] ?? '—'); ?></p>
                </div>
                <div>
                    <p class="text-black/70 text-sm">Gender</p>
                    <p class="font-semibold"><?php echo htmlspecialchars($record['gender'] ?? '—'); ?></p>
                </div>
                <div>
                    <p class="text-black/70 text-sm">Blood Type</p>
                    <p class="font-semibold"><?php echo htmlspecialchars($record['blood_type'] ?? '—'); ?></p>
                </div>
                <div>
                    <p class="text-black/70 text-sm">Civil Status</p>
                    <p class="font-semibold"><?php echo htmlspecialchars($record['civil_status'] ?? '—'); ?></p>
                </div>
                <div>
                    <p class="text-black/70 text-sm">Ethnic Origin</p>
                    <p class="font-semibold"><?php echo htmlspecialchars($record['ethnic_origin'] ?? '—'); ?></p>
                </div>
                <div>
                    <p class="text-black/70 text-sm">Citizenship</p>
                    <p class="font-semibold"><?php echo htmlspecialchars($record['citizenship'] ?? '—'); ?></p>
                </div>
                <div>
                    <p class="text-black/70 text-sm">Languages Spoken</p>
                    <p class="font-semibold"><?php echo htmlspecialchars($record['languages_spoken'] ?? '—'); ?></p>
                </div>
                <div class="md:col-span-2">
                    <p class="text-black/70 text-sm">Present Address</p>
                    <p class="font-semibold"><?php echo htmlspecialchars($record['present_address'] ?? '—'); ?></p>
                </div>
                <div class="md:col-span-2">
                    <p class="text-black/70 text-sm">Other Address</p>
                    <p class="font-semibold"><?php echo htmlspecialchars($record['other_address'] ?? '—'); ?></p>
                </div>
            </div>
        </div>

        <!-- Section D: Contact Information -->
        <div class="glass rounded-none shadow-xl p-6 md:p-8 mb-6">
            <h2 class="text-2xl font-bold text-black mb-4">D. Contact Information</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-black">
                <div>
                    <p class="text-black/70 text-sm">Landline Numbers</p>
                    <p class="font-semibold"><?php echo htmlspecialchars($record['landline_numbers'] ?? '—'); ?></p>
                </div>
                <div>
                    <p class="text-black/70 text-sm">Mobile Numbers</p>
                    <p class="font-semibold"><?php echo htmlspecialchars($record['mobile_numbers'] ?? '—'); ?></p>
                </div>
                <div class="md:col-span-2">
                    <p class="text-black/70 text-sm">Email Accounts</p>
                    <p class="font-semibold"><?php echo htmlspecialchars($record['email_accounts'] ?? '—'); ?></p>
                </div>
                <div>
                    <p class="text-black/70 text-sm">Facebook</p>
                    <p class="font-semibold"><?php echo htmlspecialchars($record['facebook'] ?? '—'); ?></p>
                </div>
                <div>
                    <p class="text-black/70 text-sm">Twitter</p>
                    <p class="font-semibold"><?php echo htmlspecialchars($record['twitter'] ?? '—'); ?></p>
                </div>
                <div>
                    <p class="text-black/70 text-sm">Instagram</p>
                    <p class="font-semibold"><?php echo htmlspecialchars($record['instagram'] ?? '—'); ?></p>
                </div>
                <div>
                    <p class="text-black/70 text-sm">LinkedIn</p>
                    <p class="font-semibold"><?php echo htmlspecialchars($record['linkedin'] ?? '—'); ?></p>
                </div>
            </div>
        </div>

        <!-- Section E: Family Background -->
        <div class="glass rounded-none shadow-xl p-6 md:p-8 mb-6">
            <h2 class="text-2xl font-bold text-black mb-4">E. Family Background</h2>
            
            <h3 class="text-xl font-semibold text-black mb-3">Father</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-black mb-6">
                <div>
                    <p class="text-black/70 text-sm">Name</p>
                    <p class="font-semibold"><?php echo htmlspecialchars($record['father_name'] ?? '—'); ?></p>
                </div>
                <div>
                    <p class="text-black/70 text-sm">Address</p>
                    <p class="font-semibold"><?php echo htmlspecialchars($record['father_address'] ?? '—'); ?></p>
                </div>
                <div>
                    <p class="text-black/70 text-sm">Religion</p>
                    <p class="font-semibold"><?php echo htmlspecialchars($record['father_religion'] ?? '—'); ?></p>
                </div>
                <div>
                    <p class="text-black/70 text-sm">Church Office</p>
                    <p class="font-semibold"><?php echo htmlspecialchars($record['father_church_office'] ?? '—'); ?></p>
                </div>
            </div>
            
            <h3 class="text-xl font-semibold text-black mb-3">Mother</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-black mb-6">
                <div>
                    <p class="text-black/70 text-sm">Name</p>
                    <p class="font-semibold"><?php echo htmlspecialchars($record['mother_name'] ?? '—'); ?></p>
                </div>
                <div>
                    <p class="text-black/70 text-sm">Address</p>
                    <p class="font-semibold"><?php echo htmlspecialchars($record['mother_address'] ?? '—'); ?></p>
                </div>
                <div>
                    <p class="text-black/70 text-sm">Religion</p>
                    <p class="font-semibold"><?php echo htmlspecialchars($record['mother_religion'] ?? '—'); ?></p>
                </div>
                <div>
                    <p class="text-black/70 text-sm">Church Office</p>
                    <p class="font-semibold"><?php echo htmlspecialchars($record['mother_church_office'] ?? '—'); ?></p>
                </div>
            </div>
            
            <h3 class="text-xl font-semibold text-black mb-3">Siblings</h3>
            <?php
            $siblings = displayJson($record['siblings']);
            if (is_array($siblings) && !empty($siblings)):
            ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-black text-sm">
                        <thead>
                            <tr class="border-b border-white/20">
                                <th class="text-left py-2">Name</th>
                                <th class="text-left py-2">Religion</th>
                                <th class="text-left py-2">Locale/District</th>
                                <th class="text-left py-2">Tungkulin</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($siblings as $sibling): ?>
                                <tr class="border-b border-white/10">
                                    <td class="py-2"><?php echo htmlspecialchars($sibling['name'] ?? '—'); ?></td>
                                    <td class="py-2"><?php echo htmlspecialchars($sibling['religion'] ?? '—'); ?></td>
                                    <td class="py-2"><?php echo htmlspecialchars($sibling['locale_district'] ?? '—'); ?></td>
                                    <td class="py-2"><?php echo htmlspecialchars($sibling['tungkulin'] ?? '—'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="text-black/70">No siblings recorded.</p>
            <?php endif; ?>
        </div>

        <!-- Section G: Educational Background -->
        <div class="glass rounded-none shadow-xl p-6 md:p-8 mb-6">
            <h2 class="text-2xl font-bold text-black mb-4">G. Educational Background</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-black mb-4">
                <div>
                    <p class="text-black/70 text-sm">Highest Educational Attainment</p>
                    <p class="font-semibold"><?php echo htmlspecialchars($record['highest_educational_attainment'] ?? '—'); ?></p>
                </div>
            </div>
            
            <?php
            $education = displayJson($record['education']);
            if (is_array($education) && !empty($education)):
            ?>
                <h3 class="text-lg font-semibold text-black mb-3 mt-4">School Records</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-black text-sm">
                        <thead>
                            <tr class="border-b border-white/20">
                                <th class="text-left py-2">School</th>
                                <th class="text-left py-2">Courses/Program</th>
                                <th class="text-left py-2">Level</th>
                                <th class="text-left py-2">Year</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($education as $edu): ?>
                                <tr class="border-b border-white/10">
                                    <td class="py-2"><?php echo htmlspecialchars($edu['school'] ?? '—'); ?></td>
                                    <td class="py-2"><?php echo htmlspecialchars($edu['courses'] ?? '—'); ?></td>
                                    <td class="py-2"><?php echo htmlspecialchars($edu['level'] ?? '—'); ?></td>
                                    <td class="py-2"><?php echo htmlspecialchars($edu['year'] ?? '—'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="text-black/70 text-sm mt-2">No school records available.</p>
            <?php endif; ?>
        </div>

        <!-- Section H: Employment Background -->
        <div class="glass rounded-none shadow-xl p-6 md:p-8 mb-6 border-l-4 border-black">
            <h2 class="text-2xl font-black text-black mb-6 section-header">H. Employment Background</h2>
            
            <div class="mb-6">
                <p class="info-label">Uri ng Hanapbuhay (Nature of Work)</p>
                <div class="grid grid-cols-1 gap-3 bg-white/50 p-4 rounded-none border-2 border-black/10 mt-3">
                    <?php
                    $workNatureOptions = [
                        'Sarili' => 'Self-employed',
                        'Namamasukan (Pamahalaan/Pribado)' => 'Employee (Government/Private)',
                 
                    ];
                    
                    $currentWorkNature = $record['work_nature'] ?? '';
                    $workNatureArray = !empty($currentWorkNature) ? explode(',', $currentWorkNature) : [];
                    
                    if (empty($workNatureArray)):
                    ?>
                        <p class="text-black/60 font-medium">No work nature specified</p>
                    <?php else:
                        foreach ($workNatureOptions as $value => $label):
                            $isChecked = in_array($value, $workNatureArray);
                        ?>
                            <label class="flex items-center space-x-3 text-black <?php echo $isChecked ? 'bg-black/5' : 'opacity-40'; ?> p-3 rounded-none border border-black/10 transition">
                                <div class="w-5 h-5 rounded-none border-2 border-black/30 flex items-center justify-center <?php echo $isChecked ? 'bg-black' : 'bg-white'; ?>">
                                    <?php if ($isChecked): ?>
                                        <svg class="w-3 h-3 text-white" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                                        </svg>
                                    <?php endif; ?>
                                </div>
                                <span class="font-bold"><?php echo htmlspecialchars($label); ?></span>
                                <span class="text-black/60 text-sm">(<?php echo htmlspecialchars($value); ?>)</span>
                            </label>
                        <?php endforeach;
                    endif; ?>
                </div>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-black">
                <div>
                    <p class="info-label">Company Name</p>
                    <p class="info-value"><?php echo htmlspecialchars($record['company_name'] ?? '—'); ?></p>
                </div>
                <div>
                    <p class="info-label">Position</p>
                    <p class="info-value"><?php echo htmlspecialchars($record['position'] ?? '—'); ?></p>
                </div>
                <div class="md:col-span-2">
                    <p class="info-label">Work Address</p>
                    <p class="info-value"><?php echo htmlspecialchars($record['work_address'] ?? '—'); ?></p>
                </div>
                <div>
                    <p class="info-label">Work Contact Numbers</p>
                    <p class="info-value"><?php echo htmlspecialchars($record['work_contact_numbers'] ?? '—'); ?></p>
                </div>
            </div>
        </div>

        <!-- Section J: Religious Background -->
        <div class="glass rounded-none shadow-xl p-6 md:p-8 mb-6">
            <h2 class="text-2xl font-bold text-black mb-4">J. Religious Background</h2>
            
            <div class="mb-6">
                <p class="text-black/70 text-sm mb-2">URI NG KAANIB (Membership Category)</p>
                <div class="space-y-2">
                    <?php
                    $membershipOptions = [
                        'Hindi Handog' => 'Hindi Handog',
                        'Handog - Nakatala' => 'Handog - Nakatala',
                        'Handog - Di nakatala' => 'Handog - Di nakatala'
                    ];
                    
                    $currentMembership = $record['membership_category'] ?? '';
                    
                    foreach ($membershipOptions as $value => $label):
                        $isChecked = (strcasecmp($currentMembership, $value) === 0);
                    ?>
                        <label class="flex items-center space-x-3 text-black">
                            <input type="checkbox" 
                                   <?php echo $isChecked ? 'checked' : ''; ?> 
                                   disabled
                                   class="w-5 h-5 rounded border-2 border-black/20">
                            <span class="font-medium"><?php echo htmlspecialchars($label); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-black">
                <div>
                    <p class="text-black/70 text-sm">Membership Category (Text)</p>
                    <p class="font-semibold"><?php echo htmlspecialchars($record['membership_category'] ?? '—'); ?></p>
                </div>
                <div>
                    <p class="text-black/70 text-sm">Evangelist</p>
                    <p class="font-semibold"><?php echo htmlspecialchars($record['evangelist'] ?? '—'); ?></p>
                </div>
                <div>
                    <p class="text-black/70 text-sm">Baptism Date</p>
                    <p class="font-semibold"><?php echo $record['baptism_date'] ? date('F d, Y', strtotime($record['baptism_date'])) : '—'; ?></p>
                </div>
                <div>
                    <p class="text-black/70 text-sm">Baptism Place</p>
                    <p class="font-semibold"><?php echo htmlspecialchars($record['baptism_place'] ?? '—'); ?></p>
                </div>
            </div>
        </div>

        <!-- Record Info -->
        <div class="glass rounded-none shadow-xl p-6 mb-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-black text-sm">
                <div>
                    <p class="text-black/70">Created At</p>
                    <p class="font-semibold"><?php echo date('F d, Y h:i A', strtotime($record['created_at'])); ?></p>
                </div>
                <div>
                    <p class="text-black/70">Last Updated</p>
                    <p class="font-semibold"><?php echo date('F d, Y h:i A', strtotime($record['updated_at'])); ?></p>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Loading overlay functions
        function showLoading(message = 'Loading...') {
            const overlay = document.getElementById('loadingOverlay');
            const text = overlay.querySelector('.loading-text');
            text.textContent = message;
            overlay.classList.add('active');
        }
        
        function hideLoading() {
            const overlay = document.getElementById('loadingOverlay');
            overlay.classList.remove('active');
        }
        
        // Show loading on navigation
        document.addEventListener('DOMContentLoaded', function() {
            // Show loading when clicking navigation links
            const navLinks = document.querySelectorAll('a[href]:not([href^="#"]):not([target="_blank"])');
            navLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    const href = this.getAttribute('href');
                    if (href && href !== '#' && !href.startsWith('javascript:')) {
                        showLoading('Loading page...');
                    }
                });
            });
            
            // Show loading for print action
            const printLink = document.querySelector('a[href*="print_r201.php"]');
            if (printLink) {
                printLink.addEventListener('click', function(e) {
                    showLoading('Preparing document...');
                    // Hide after a delay since print opens in new tab
                    setTimeout(() => hideLoading(), 2000);
                });
            }
        });
        
        // Logout confirmation
        function confirmLogout() {
            if (confirm('Are you sure you want to logout?')) {
                showLoading('Logging out...');
                window.location.href = 'logout.php';
            }
        }
    </script>
    
    <!-- Alpine.js for dropdown -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</body>
</html>
