<?php
/**
 * Overseers Contact View Page
 * Detailed view of a single contact with QR codes
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/encryption.php';

Security::requireLogin();

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

// Get contact ID
$contactId = $_GET['id'] ?? null;
if (!$contactId) {
    die('Invalid contact ID');
}

// Fetch contact details
$stmt = $db->prepare("
    SELECT 
        oc.*,
        l.local_name,
        d.district_name,
        u1.username as created_by_username,
        u2.username as updated_by_username
    FROM overseers_contacts oc
    LEFT JOIN local_congregations l ON oc.local_code = l.local_code
    LEFT JOIN districts d ON oc.district_code = d.district_code
    LEFT JOIN users u1 ON oc.created_by = u1.user_id
    LEFT JOIN users u2 ON oc.updated_by = u2.user_id
    WHERE oc.contact_id = ?
");
$stmt->execute([$contactId]);
$contact = $stmt->fetch();

if (!$contact) {
    die('Contact not found');
}

// Check permissions
if ($currentUser['role'] === 'local' && $contact['local_code'] !== $currentUser['local_code']) {
    die('Unauthorized access');
}
if ($currentUser['role'] === 'district' && $contact['district_code'] !== $currentUser['district_code']) {
    die('Unauthorized access');
}

// Decrypt and get officer names
function getOfficerDetails($encryptedIds, $districtCode, $db) {
    if (empty($encryptedIds)) {
        return null;
    }
    
    try {
        $decrypted = Encryption::decrypt($encryptedIds, $districtCode);
        $officerIds = json_decode($decrypted, true);
        
        if (empty($officerIds) || !is_array($officerIds)) {
            return null;
        }
        
        $placeholders = implode(',', array_fill(0, count($officerIds), '?'));
        $stmt = $db->prepare("
            SELECT officer_id, full_name_encrypted, local_code
            FROM officers 
            WHERE officer_id IN ($placeholders)
        ");
        $stmt->execute($officerIds);
        $officers = $stmt->fetchAll();
        
        $result = [];
        foreach ($officers as $officer) {
            $name = Encryption::decrypt($officer['full_name_encrypted'], $districtCode);
            if (!empty($name)) {
                $result[] = [
                    'id' => $officer['officer_id'],
                    'name' => $name,
                    'local_code' => $officer['local_code']
                ];
            }
        }
        
        return $result;
    } catch (Exception $e) {
        error_log("Error getting officer details: " . $e->getMessage());
        return null;
    }
}

$katiwalaOfficers = getOfficerDetails($contact['katiwala_officer_ids'], $contact['district_code'], $db);
$iiKatiwalaOfficers = getOfficerDetails($contact['ii_katiwala_officer_ids'], $contact['district_code'], $db);
$kalihimOfficers = getOfficerDetails($contact['kalihim_officer_ids'], $contact['district_code'], $db);

$pageTitle = 'Overseers Contact Details';

// Page actions
$pageActions = [];
$pageActions[] = '<a href="overseers-contacts.php" class="inline-flex items-center justify-center px-3 sm:px-4 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors shadow-sm text-xs sm:text-sm"><svg class="w-4 h-4 sm:mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg><span class="hidden sm:inline">Back to List</span></a>';

ob_start();
?>

<!-- Load QRCode library before page content -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
?>

<div class="space-y-6">
    <!-- Header Card -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-4 sm:p-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 sm:gap-4">
            <div>
                <h1 class="text-xl sm:text-2xl font-semibold text-gray-900 dark:text-gray-100">
                    <svg class="w-6 h-6 sm:w-7 sm:h-7 inline-block mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                    </svg>
                    Contact Details
                </h1>
                <p class="text-xs sm:text-sm text-gray-500 dark:text-gray-400 mt-1">
                    Viewing contact information for <?php echo htmlspecialchars($contact['contact_type'] === 'grupo' ? $contact['purok_grupo'] : $contact['purok']); ?>
                </p>
            </div>
        </div>
    </div>

    <!-- Contact Information Card -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-4 sm:p-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Basic Information -->
            <div>
                <h3 class="text-base sm:text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4 flex items-center">
                    <svg class="w-5 h-5 mr-2 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    Basic Information
                </h3>
                <dl class="space-y-4">
                    <div>
                        <dt class="text-xs sm:text-sm font-medium text-gray-500 dark:text-gray-400">Type</dt>
                        <dd class="mt-1">
                            <span class="inline-flex px-3 py-1 text-xs sm:text-sm font-semibold rounded-full <?php echo $contact['contact_type'] === 'grupo' ? 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200' : 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'; ?>">
                                <?php echo $contact['contact_type'] === 'grupo' ? 'Grupo Level' : 'Purok Level'; ?>
                            </span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs sm:text-sm font-medium text-gray-500 dark:text-gray-400">
                            <?php echo $contact['contact_type'] === 'grupo' ? 'Purok Grupo' : 'Purok'; ?>
                        </dt>
                        <dd class="mt-1 text-base sm:text-lg font-semibold text-gray-900 dark:text-gray-100">
                            <?php echo htmlspecialchars($contact['contact_type'] === 'grupo' ? $contact['purok_grupo'] : $contact['purok']); ?>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs sm:text-sm font-medium text-gray-500 dark:text-gray-400">District</dt>
                        <dd class="mt-1 text-sm sm:text-base text-gray-900 dark:text-gray-100">
                            <?php echo htmlspecialchars($contact['district_name']); ?> (<?php echo htmlspecialchars($contact['district_code']); ?>)
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs sm:text-sm font-medium text-gray-500 dark:text-gray-400">Local</dt>
                        <dd class="mt-1 text-sm sm:text-base text-gray-900 dark:text-gray-100">
                            <?php echo htmlspecialchars($contact['local_name']); ?> (<?php echo htmlspecialchars($contact['local_code']); ?>)
                        </dd>
                    </div>
                </dl>
            </div>

            <!-- Metadata -->
            <div>
                <h3 class="text-base sm:text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4 flex items-center">
                    <svg class="w-5 h-5 mr-2 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    Record Information
                </h3>
                <dl class="space-y-4">
                    <div>
                        <dt class="text-xs sm:text-sm font-medium text-gray-500 dark:text-gray-400">Status</dt>
                        <dd class="mt-1">
                            <span class="inline-flex px-3 py-1 text-xs sm:text-sm font-semibold rounded-full <?php echo $contact['is_active'] ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'; ?>">
                                <?php echo $contact['is_active'] ? 'Active' : 'Inactive'; ?>
                            </span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs sm:text-sm font-medium text-gray-500 dark:text-gray-400">Created</dt>
                        <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">
                            <?php echo date('F j, Y g:i A', strtotime($contact['created_at'])); ?>
                            <?php if ($contact['created_by_username']): ?>
                                <br><span class="text-xs text-gray-600 dark:text-gray-400">by <?php echo htmlspecialchars($contact['created_by_username']); ?></span>
                            <?php endif; ?>
                        </dd>
                    </div>
                    <?php if ($contact['updated_at'] && $contact['updated_at'] !== $contact['created_at']): ?>
                    <div>
                        <dt class="text-xs sm:text-sm font-medium text-gray-500 dark:text-gray-400">Last Updated</dt>
                        <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">
                            <?php echo date('F j, Y g:i A', strtotime($contact['updated_at'])); ?>
                            <?php if ($contact['updated_by_username']): ?>
                                <br><span class="text-xs text-gray-600 dark:text-gray-400">by <?php echo htmlspecialchars($contact['updated_by_username']); ?></span>
                            <?php endif; ?>
                        </dd>
                    </div>
                    <?php endif; ?>
                </dl>
            </div>
        </div>
    </div>

    <!-- Officers Contact Cards -->
    <?php
    $positions = [
        ['title' => 'Katiwala ng ' . ($contact['contact_type'] === 'grupo' ? 'Grupo' : 'Purok'), 
         'officers' => $katiwalaOfficers, 
         'contact' => $contact['katiwala_contact'], 
         'telegram' => $contact['katiwala_telegram'],
         'icon' => 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z'],
        ['title' => 'II Katiwala ng ' . ($contact['contact_type'] === 'grupo' ? 'Grupo' : 'Purok'), 
         'officers' => $iiKatiwalaOfficers, 
         'contact' => $contact['ii_katiwala_contact'], 
         'telegram' => $contact['ii_katiwala_telegram'],
         'icon' => 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z'],
        ['title' => 'Kalihim ng ' . ($contact['contact_type'] === 'grupo' ? 'Grupo' : 'Purok'), 
         'officers' => $kalihimOfficers, 
         'contact' => $contact['kalihim_contact'], 
         'telegram' => $contact['kalihim_telegram'],
         'icon' => 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z']
    ];

    foreach ($positions as $position):
        if (!$position['officers'] && !$position['contact'] && !$position['telegram']) continue;
    ?>
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-4 sm:p-6">
        <h3 class="text-base sm:text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4 flex items-center">
            <svg class="w-5 h-5 mr-2 text-blue-500" fill="currentColor" viewBox="0 0 24 24">
                <path d="<?php echo $position['icon']; ?>"></path>
            </svg>
            <?php echo htmlspecialchars($position['title']); ?>
        </h3>
        
        <?php if ($position['officers']): ?>
        <div class="mb-4">
            <label class="text-xs sm:text-sm font-medium text-gray-500 dark:text-gray-400">Officer(s)</label>
            <div class="mt-2 space-y-2">
                <?php foreach ($position['officers'] as $officer): ?>
                <div class="flex items-center px-3 py-2 bg-gray-50 dark:bg-gray-700 rounded-lg">
                    <svg class="w-4 h-4 mr-2 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                    </svg>
                    <span class="text-sm sm:text-base text-gray-900 dark:text-gray-100"><?php echo htmlspecialchars($officer['name']); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <?php if ($position['contact']): ?>
                <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4 hover:shadow-md transition-shadow">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs sm:text-sm font-medium text-gray-700 dark:text-gray-300 flex items-center">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path>
                            </svg>
                            Contact Number
                        </span>
                        <button onclick="showQR('tel:<?php echo htmlspecialchars($position['contact']); ?>', '<?php echo htmlspecialchars($position['title']); ?> - Phone')" 
                                class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 transition-colors">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"></path>
                            </svg>
                        </button>
                    </div>
                    <p class="text-base sm:text-lg font-semibold text-gray-900 dark:text-gray-100 mb-3">
                        <?php echo htmlspecialchars($position['contact']); ?>
                    </p>
                    <div class="flex gap-2">
                        <a href="tel:<?php echo htmlspecialchars($position['contact']); ?>" 
                           class="inline-flex items-center justify-center flex-1 px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg text-xs sm:text-sm transition-colors">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path>
                            </svg>
                            Call
                        </a>
                        <button onclick="copyToClipboard('<?php echo htmlspecialchars($position['contact']); ?>', 'Phone number')" 
                                class="inline-flex items-center justify-center px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded-lg text-xs sm:text-sm transition-colors">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                            </svg>
                        </button>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($position['telegram']): ?>
                <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4 hover:shadow-md transition-shadow">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs sm:text-sm font-medium text-gray-700 dark:text-gray-300 flex items-center">
                            <svg class="w-4 h-4 mr-2" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.894 8.221l-1.97 9.28c-.145.658-.537.818-1.084.508l-3-2.21-1.446 1.394c-.14.18-.357.295-.6.295-.002 0-.003 0-.005 0l.213-3.054 5.56-5.022c.24-.213-.054-.334-.373-.121l-6.869 4.326-2.96-.924c-.64-.203-.658-.64.135-.954l11.566-4.458c.538-.196 1.006.128.832.941z"/>
                            </svg>
                            Telegram Account
                        </span>
                        <button onclick="showQR('https://t.me/<?php echo htmlspecialchars(ltrim($position['telegram'], '@')); ?>', '<?php echo htmlspecialchars($position['title']); ?> - Telegram')" 
                                class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 transition-colors">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"></path>
                            </svg>
                        </button>
                    </div>
                    <p class="text-base sm:text-lg font-semibold text-gray-900 dark:text-gray-100 mb-3">
                        <?php echo htmlspecialchars($position['telegram']); ?>
                    </p>
                    <div class="flex gap-2">
                        <a href="https://t.me/<?php echo htmlspecialchars(ltrim($position['telegram'], '@')); ?>" 
                           target="_blank"
                           class="inline-flex items-center justify-center flex-1 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-xs sm:text-sm transition-colors">
                            <svg class="w-4 h-4 mr-2" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.894 8.221l-1.97 9.28c-.145.658-.537.818-1.084.508l-3-2.21-1.446 1.394c-.14.18-.357.295-.6.295-.002 0-.003 0-.005 0l.213-3.054 5.56-5.022c.24-.213-.054-.334-.373-.121l-6.869 4.326-2.96-.924c-.64-.203-.658-.64.135-.954l11.566-4.458c.538-.196 1.006.128.832.941z"/>
                            </svg>
                            Open
                        </a>
                        <button onclick="copyToClipboard('<?php echo htmlspecialchars($position['telegram']); ?>', 'Telegram username')" 
                                class="inline-flex items-center justify-center px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded-lg text-xs sm:text-sm transition-colors">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                            </svg>
                        </button>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- QR Code Modal -->
    <div id="qrModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-md w-full p-6">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg sm:text-xl font-semibold text-gray-900 dark:text-gray-100" id="qrModalTitle"></h3>
                <button onclick="closeQrModal()" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 transition-colors">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            <div id="qrCodeContainer" class="flex justify-center items-center bg-white p-4 rounded mb-4">
                <!-- QR code will be generated here -->
            </div>
            <p id="qrText" class="text-center text-sm text-gray-700 dark:text-gray-300 font-mono break-all mb-4"></p>
            <div class="flex justify-center space-x-3">
                <button onclick="downloadQR()" class="inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                    </svg>
                    Download
                </button>
            </div>
        </div>
    </div>

    <script>
        let currentQRData = null;

        function showQR(data, title) {
            document.getElementById('qrModalTitle').textContent = title;
            document.getElementById('qrText').textContent = data;
            currentQRData = {data, title};
            
            const container = document.getElementById('qrCodeContainer');
            container.innerHTML = '';
            
            try {
                new QRCode(container, {
                    text: data,
                    width: 300,
                    height: 300,
                    colorDark: '#000000',
                    colorLight: '#ffffff',
                    correctLevel: QRCode.CorrectLevel.H
                });
                
                document.getElementById('qrModal').classList.remove('hidden');
            } catch (error) {
                console.error('Error generating QR:', error);
                showToast('Failed to generate QR code: ' + error.message, 'error');
            }
        }

        function closeQrModal() {
            document.getElementById('qrModal').classList.add('hidden');
        }

        function downloadQR() {
            if (!currentQRData) return;
            
            const img = document.querySelector('#qrCodeContainer img');
            if (!img) return;
            
            // Create a canvas from the image
            const canvas = document.createElement('canvas');
            canvas.width = 300;
            canvas.height = 300;
            const ctx = canvas.getContext('2d');
            
            // Draw white background
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, 300, 300);
            
            // Draw the image
            ctx.drawImage(img, 0, 0, 300, 300);
            
            const link = document.createElement('a');
            link.download = `${currentQRData.title.replace(/[^a-z0-9]/gi, '_')}_QR.png`;
            link.href = canvas.toDataURL();
            link.click();
            showToast('QR Code downloaded successfully', 'success');
        }
        
        function copyToClipboard(text, label) {
            navigator.clipboard.writeText(text).then(() => {
                showToast(`${label} copied to clipboard`, 'success');
            }).catch(err => {
                console.error('Failed to copy:', err);
                showToast('Failed to copy to clipboard', 'error');
            });
        }
        
        function showToast(message, type = 'success') {
            const toast = document.createElement('div');
            toast.className = `fixed top-4 right-4 px-6 py-3 rounded-lg shadow-lg z-50 transform transition-all duration-300 ${
                type === 'success' ? 'bg-green-500 text-white' : 'bg-red-500 text-white'
            }`;
            toast.style.opacity = '0';
            toast.textContent = message;
            document.body.appendChild(toast);
            
            // Fade in
            setTimeout(() => toast.style.opacity = '1', 10);
            
            // Remove after 3 seconds
            setTimeout(() => {
                toast.style.opacity = '0';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }
    </script>

<?php
$content = ob_get_clean();
include __DIR__ . '/includes/layout.php';