<?php
/**
 * View HDB Child Record
 * Display details of a child in HDB Registry (Handog Di Bautisado)
 */

require_once __DIR__ . '/config/config.php';

Security::requireLogin();
requirePermission('can_view_reports');

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

// Check if user needs to request access
$needsAccessRequest = ($currentUser['role'] === 'local_cfo' || $currentUser['role'] === 'local_limited');
$hasApprovedAccess = false;

if ($needsAccessRequest) {
    $stmt = $db->prepare("
        SELECT COUNT(*) as count FROM hdb_access_requests 
        WHERE requester_user_id = ? 
        AND status = 'approved'
        AND deleted_at IS NULL
        AND is_locked = FALSE
    ");
    $stmt->execute([$currentUser['user_id']]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $hasApprovedAccess = ($result['count'] > 0);
    
    if (!$hasApprovedAccess) {
        header('Location: hdb-registry.php');
        exit;
    }
}

// Get record ID
$recordId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$recordId) {
    $_SESSION['error'] = 'Invalid record ID.';
    header('Location: hdb-registry.php');
    exit;
}

// Fetch record
$stmt = $db->prepare("SELECT * FROM hdb_registry WHERE id = ? AND deleted_at IS NULL");
$stmt->execute([$recordId]);
$record = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$record) {
    $_SESSION['error'] = 'Record not found.';
    header('Location: hdb-registry.php');
    exit;
}

// Check access to district
if (!hasDistrictAccess($record['district_code'])) {
    $_SESSION['error'] = 'You do not have access to view this record.';
    header('Location: hdb-registry.php');
    exit;
}

// Decrypt sensitive data
try {
    $districtCode = $record['district_code'];
    
    $childFirstName = Encryption::decrypt($record['child_first_name_encrypted'], $districtCode);
    $childMiddleName = !empty($record['child_middle_name_encrypted']) 
        ? Encryption::decrypt($record['child_middle_name_encrypted'], $districtCode) 
        : '';
    $childLastName = Encryption::decrypt($record['child_last_name_encrypted'], $districtCode);
    $childBirthday = !empty($record['child_birthday_encrypted']) 
        ? Encryption::decrypt($record['child_birthday_encrypted'], $districtCode) 
        : '';
    $childBirthplace = !empty($record['child_birthplace_encrypted']) 
        ? Encryption::decrypt($record['child_birthplace_encrypted'], $districtCode) 
        : '';
    
    $fatherFirstName = !empty($record['father_first_name_encrypted']) 
        ? Encryption::decrypt($record['father_first_name_encrypted'], $districtCode) 
        : '';
    $fatherMiddleName = !empty($record['father_middle_name_encrypted']) 
        ? Encryption::decrypt($record['father_middle_name_encrypted'], $districtCode) 
        : '';
    $fatherLastName = !empty($record['father_last_name_encrypted']) 
        ? Encryption::decrypt($record['father_last_name_encrypted'], $districtCode) 
        : '';
    
    $motherFirstName = !empty($record['mother_first_name_encrypted']) 
        ? Encryption::decrypt($record['mother_first_name_encrypted'], $districtCode) 
        : '';
    $motherMiddleName = !empty($record['mother_middle_name_encrypted']) 
        ? Encryption::decrypt($record['mother_middle_name_encrypted'], $districtCode) 
        : '';
    $motherMaidenName = !empty($record['mother_maiden_name_encrypted']) 
        ? Encryption::decrypt($record['mother_maiden_name_encrypted'], $districtCode) 
        : '';
    $motherMarriedName = !empty($record['mother_married_name_encrypted']) 
        ? Encryption::decrypt($record['mother_married_name_encrypted'], $districtCode) 
        : '';
    
    $parentAddress = !empty($record['parent_address_encrypted']) 
        ? Encryption::decrypt($record['parent_address_encrypted'], $districtCode) 
        : '';
    $parentContact = !empty($record['parent_contact_encrypted']) 
        ? Encryption::decrypt($record['parent_contact_encrypted'], $districtCode) 
        : '';
    
    $registryNumber = Encryption::decrypt($record['registry_number'], $districtCode);
    
} catch (Exception $e) {
    error_log("Error decrypting HDB record: " . $e->getMessage());
    $_SESSION['error'] = 'Unable to decrypt record data.';
    header('Location: hdb-registry.php');
    exit;
}

// Build full names
$childFullName = trim($childFirstName . ' ' . $childMiddleName . ' ' . $childLastName);
$fatherFullName = trim($fatherFirstName . ' ' . $fatherMiddleName . ' ' . $fatherLastName);
$motherFullName = trim($motherFirstName . ' ' . $motherMiddleName . ' ' . ($motherMarriedName ?: $motherMaidenName));

// Calculate age if birthday is available
$childAge = null;
$canPromoteToPNK = false;
if ($childBirthday) {
    try {
        $birthDate = new DateTime($childBirthday);
        $today = new DateTime();
        $childAge = $today->diff($birthDate)->y;
        
        // Can promote to PNK if 4 years or older and status is active
        $canPromoteToPNK = ($childAge >= 4 && $record['dedication_status'] === 'active');
    } catch (Exception $e) {
        error_log("Error calculating age: " . $e->getMessage());
    }
}

// Get district and local names
$stmt = $db->prepare("SELECT district_name FROM districts WHERE district_code = ?");
$stmt->execute([$record['district_code']]);
$districtInfo = $stmt->fetch(PDO::FETCH_ASSOC);
$districtName = $districtInfo ? $districtInfo['district_name'] : $record['district_code'];

$stmt = $db->prepare("SELECT local_name FROM local_congregations WHERE local_code = ?");
$stmt->execute([$record['local_code']]);
$localInfo = $stmt->fetch(PDO::FETCH_ASSOC);
$localName = $localInfo ? $localInfo['local_name'] : $record['local_code'];

// Status configuration for HDB
// Statuses: Active, PNK (promoted), Transferred-Out, Baptized
$statusConfig = [
    'active' => ['color' => 'green', 'label' => 'Active', 'icon' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'],
    'pnk' => ['color' => 'purple', 'label' => 'PNK', 'icon' => 'M13 7l5 5m0 0l-5 5m5-5H6'],
    'baptized' => ['color' => 'blue', 'label' => 'Baptized', 'icon' => 'M5 13l4 4L19 7'],
    'transferred-out' => ['color' => 'gray', 'label' => 'Transferred Out', 'icon' => 'M17 8l4 4m0 0l-4 4m4-4H3']
];

// Check if promoted to PNK based on transfer_to field or dedication_status
$isPromotedToPNK = ($record['dedication_status'] === 'pnk' || 
                   ($record['dedication_status'] === 'transferred-out' && $record['transfer_to'] === 'PNK Registry'));

// Override status if promoted to PNK
if ($isPromotedToPNK) {
    $statusInfo = ['color' => 'purple', 'label' => 'PNK', 'icon' => 'M13 7l5 5m0 0l-5 5m5-5H6'];
} else {
    $statusInfo = $statusConfig[$record['dedication_status']] ?? ['color' => 'gray', 'label' => 'Unknown', 'icon' => 'M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z'];
}

$pageTitle = 'View HDB Child - ' . $childFullName;
$breadcrumbs = [
    ['label' => 'HDB Registry', 'url' => 'hdb-registry.php'],
    ['label' => $childFullName]
];

ob_start();
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center gap-4">
                <a href="hdb-registry.php" class="text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                    </svg>
                </a>
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900 dark:text-gray-100"><?php echo Security::escape($childFullName); ?></h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">HDB Registry Record</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center px-3 py-1.5 rounded-lg text-sm font-medium bg-<?php echo $statusInfo['color']; ?>-100 dark:bg-<?php echo $statusInfo['color']; ?>-900/30 text-<?php echo $statusInfo['color']; ?>-800 dark:text-<?php echo $statusInfo['color']; ?>-400">
                    <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?php echo $statusInfo['icon']; ?>"></path>
                    </svg>
                    <?php echo $statusInfo['label']; ?>
                </span>
                <?php if ($currentUser['role'] === 'admin' || $currentUser['role'] === 'local'): ?>
                <?php if ($canPromoteToPNK): ?>
                <button onclick="promoteToPNK()" class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                    </svg>
                    Promote to PNK
                </button>
                <?php endif; ?>
                <button onclick="showTransferOutModal()" class="inline-flex items-center px-4 py-2 bg-yellow-600 text-white rounded-lg hover:bg-yellow-700 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                    </svg>
                    Transfer Out
                </button>
                <a href="hdb-edit.php?id=<?php echo $record['id']; ?>" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                    </svg>
                    Edit
                </a>
                <button onclick="showDeleteModal()" class="inline-flex items-center px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                    </svg>
                    Delete
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Child Information -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <div class="flex items-center gap-3 mb-6">
            <div class="w-10 h-10 bg-blue-100 dark:bg-blue-900/30 rounded-lg flex items-center justify-center">
                <svg class="w-6 h-6 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                </svg>
            </div>
            <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100">Child Information</h2>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">First Name</label>
                <p class="text-base text-gray-900 dark:text-gray-100"><?php echo Security::escape($childFirstName); ?></p>
            </div>
            <?php if ($childMiddleName): ?>
            <div>
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Middle Name</label>
                <p class="text-base text-gray-900 dark:text-gray-100"><?php echo Security::escape($childMiddleName); ?></p>
            </div>
            <?php endif; ?>
            <div>
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Last Name</label>
                <p class="text-base text-gray-900 dark:text-gray-100"><?php echo Security::escape($childLastName); ?></p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Sex</label>
                <p class="text-base text-gray-900 dark:text-gray-100"><?php echo Security::escape($record['child_sex']); ?></p>
            </div>
            <?php if ($childBirthday): ?>
            <div>
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Date of Birth</label>
                <p class="text-base text-gray-900 dark:text-gray-100">
                    <?php echo Security::escape($childBirthday); ?>
                    <?php if ($childAge !== null): ?>
                        <span class="ml-2 text-sm text-gray-500 dark:text-gray-400">(<?php echo $childAge; ?> years old)</span>
                    <?php endif; ?>
                </p>
            </div>
            <?php endif; ?>
            <?php if ($childBirthplace): ?>
            <div>
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Place of Birth</label>
                <p class="text-base text-gray-900 dark:text-gray-100"><?php echo Security::escape($childBirthplace); ?></p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Parent Information -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <div class="flex items-center gap-3 mb-6">
            <div class="w-10 h-10 bg-purple-100 dark:bg-purple-900/30 rounded-lg flex items-center justify-center">
                <svg class="w-6 h-6 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                </svg>
            </div>
            <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100">Parent Information</h2>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <?php if ($fatherFullName): ?>
            <div>
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Father's Name</label>
                <p class="text-base text-gray-900 dark:text-gray-100"><?php echo Security::escape($fatherFullName); ?></p>
            </div>
            <?php endif; ?>
            <?php if ($motherFullName): ?>
            <div>
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Mother's Name</label>
                <p class="text-base text-gray-900 dark:text-gray-100"><?php echo Security::escape($motherFullName); ?></p>
            </div>
            <?php endif; ?>
            <?php if ($parentAddress): ?>
            <div class="md:col-span-2">
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Address</label>
                <p class="text-base text-gray-900 dark:text-gray-100"><?php echo Security::escape($parentAddress); ?></p>
            </div>
            <?php endif; ?>
            <?php if ($parentContact): ?>
            <div>
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Contact Number</label>
                <p class="text-base text-gray-900 dark:text-gray-100"><?php echo Security::escape($parentContact); ?></p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Registry Information -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <div class="flex items-center gap-3 mb-6">
            <div class="w-10 h-10 bg-green-100 dark:bg-green-900/30 rounded-lg flex items-center justify-center">
                <svg class="w-6 h-6 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
            </div>
            <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100">Registry Information</h2>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Registry Number</label>
                <p class="text-base font-mono text-gray-900 dark:text-gray-100"><?php echo Security::escape($registryNumber); ?></p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Registration Date</label>
                <p class="text-base text-gray-900 dark:text-gray-100"><?php echo date('F j, Y', strtotime($record['registration_date'])); ?></p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">District</label>
                <p class="text-base text-gray-900 dark:text-gray-100"><?php echo Security::escape($districtName); ?></p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Local Congregation</label>
                <p class="text-base text-gray-900 dark:text-gray-100"><?php echo Security::escape($localName); ?></p>
            </div>
            <?php if ($record['purok_grupo']): ?>
            <div>
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Purok/Grupo</label>
                <p class="text-base text-gray-900 dark:text-gray-100"><?php echo Security::escape($record['purok_grupo']); ?></p>
            </div>
            <?php endif; ?>
            <div>
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Status</label>
                <p class="text-base text-gray-900 dark:text-gray-100"><?php echo $statusInfo['label']; ?></p>
            </div>
            <?php if ($record['dedication_date']): ?>
            <div>
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Dedication Date</label>
                <p class="text-base text-gray-900 dark:text-gray-100"><?php echo date('F j, Y', strtotime($record['dedication_date'])); ?></p>
            </div>
            <?php endif; ?>
            <?php if ($record['baptism_date']): ?>
            <div>
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Baptism Date</label>
                <p class="text-base text-gray-900 dark:text-gray-100"><?php echo date('F j, Y', strtotime($record['baptism_date'])); ?></p>
            </div>
            <?php endif; ?>
            <?php if ($record['notes']): ?>
            <div class="md:col-span-2">
                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Notes</label>
                <p class="text-base text-gray-900 dark:text-gray-100"><?php echo nl2br(Security::escape($record['notes'])); ?></p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Promotion Status -->
    <?php if ($record['dedication_status'] === 'transferred-out'): ?>
    <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg p-4">
        <div class="flex items-center">
            <svg class="w-5 h-5 text-green-600 dark:text-green-400 mr-3" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
            </svg>
            <div>
                <p class="text-sm font-medium text-green-800 dark:text-green-400">Promoted to PNK Registry</p>
                <p class="text-xs text-green-600 dark:text-green-500 mt-0.5">
                    This child has been promoted to PNK
                </p>
            </div>
        </div>
    </div>
    <?php elseif ($canPromoteToPNK): ?>
    <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
        <div class="flex items-center">
            <svg class="w-5 h-5 text-blue-600 dark:text-blue-400 mr-3" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
            </svg>
            <div>
                <p class="text-sm font-medium text-blue-800 dark:text-blue-400">Eligible for PNK Promotion</p>
                <p class="text-xs text-blue-600 dark:text-blue-500 mt-0.5">
                    This child is <?php echo $childAge; ?> years old and can be promoted to PNK Registry (Paaralan ng mga Kabataan)
                </p>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Metadata -->
    <div class="bg-gray-50 dark:bg-gray-800/50 rounded-lg border border-gray-200 dark:border-gray-700 p-4">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
            <div>
                <span class="text-gray-500 dark:text-gray-400">Created:</span>
                <span class="text-gray-900 dark:text-gray-100 ml-2"><?php echo date('F j, Y g:i A', strtotime($record['created_at'])); ?></span>
            </div>
            <?php if ($record['updated_at']): ?>
            <div>
                <span class="text-gray-500 dark:text-gray-400">Last Updated:</span>
                <span class="text-gray-900 dark:text-gray-100 ml-2"><?php echo date('F j, Y g:i A', strtotime($record['updated_at'])); ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="hidden fixed inset-0 bg-gray-900 bg-opacity-50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-md w-full p-6">
        <div class="flex items-center gap-4 mb-4">
            <div class="w-12 h-12 bg-red-100 dark:bg-red-900/30 rounded-full flex items-center justify-center">
                <svg class="w-6 h-6 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                </svg>
            </div>
            <div>
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Delete Record</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400">This action cannot be undone</p>
            </div>
        </div>
        <p class="text-gray-700 dark:text-gray-300 mb-6">
            Are you sure you want to permanently delete the HDB record for <strong><?php echo Security::escape($childFullName); ?></strong>?
        </p>
        <div class="flex justify-end gap-3">
            <button type="button" onclick="closeDeleteModal()" class="px-4 py-2 text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors">
                Cancel
            </button>
            <button type="button" onclick="confirmDelete()" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">
                Delete Permanently
            </button>
        </div>
    </div>
</div>

<!-- Transfer Out Modal -->
<div id="transferOutModal" class="hidden fixed inset-0 bg-gray-900 bg-opacity-50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-md w-full p-6">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Transfer Out Child</h3>
        <form id="transferOutForm">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Transfer To (Local)</label>
                    <input type="text" id="transferToLocal" required 
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-yellow-500" 
                           placeholder="Enter destination local">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Reason for Transfer</label>
                    <textarea id="transferReason" rows="3" required
                              class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-yellow-500"
                              placeholder="Enter reason for transfer"></textarea>
                </div>
            </div>
            <div class="mt-6 flex gap-2">
                <button type="submit" class="flex-1 px-4 py-2 bg-yellow-600 text-white rounded-lg hover:bg-yellow-700 transition-colors">
                    Transfer Out
                </button>
                <button type="button" onclick="closeTransferOutModal()" class="flex-1 px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function showTransferOutModal() {
    document.getElementById('transferOutModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeTransferOutModal() {
    document.getElementById('transferOutModal').classList.add('hidden');
    document.body.style.overflow = '';
    document.getElementById('transferOutForm').reset();
}

document.getElementById('transferOutForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const transferTo = document.getElementById('transferToLocal').value;
    const transferToDistrict = document.getElementById('transferToDistrict').value;
    const transferReason = document.getElementById('transferReason').value;
    
    try {
        const response = await fetch('api/transfer-hdb-out.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                hdb_id: <?php echo $recordId; ?>,
                transfer_to: transferTo,
                transfer_to_district: transferToDistrict,
                transfer_reason: reason
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert(data.message || 'Child transferred out successfully!');
            window.location.reload();
        } else {
            alert('Error: ' + (data.error || 'Transfer failed'));
        }
    } catch (error) {
        console.error('Transfer error:', error);
        alert('An error occurred during transfer');
    }
});

async function promoteToPNK() {
    if (!confirm('Are you sure you want to promote this child to PNK Registry?\n\nThis child will be moved from HDB (Handog Di Bautisado) to PNK (Paaralan ng mga Kabataan).')) {
        return;
    }
    
    try {
        const response = await fetch('<?php echo BASE_URL; ?>/api/promote-hdb-to-pnk.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                hdb_record_id: <?php echo $record['id']; ?>
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert('Child successfully promoted to PNK Registry!\n\nPNK Registry Number: ' + data.pnk_registry_number);
            // Reload the page to show updated status
            window.location.reload();
        } else {
            alert('Error: ' + (data.error || 'Failed to promote to PNK'));
        }
    } catch (error) {
        console.error('Promotion error:', error);
        alert('An error occurred while promoting to PNK');
    }
}

// Delete Modal Functions
function showDeleteModal() {
    document.getElementById('deleteModal').classList.remove('hidden');
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.add('hidden');
}

async function confirmDelete() {
    try {
        const response = await fetch('<?php echo BASE_URL; ?>/api/delete-hdb.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                id: <?php echo $record['id']; ?>,
                csrf_token: '<?php echo Security::generateCSRFToken(); ?>'
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert(data.message || 'Record deleted successfully!');
            window.location.href = 'hdb-registry.php';
        } else {
            alert('Error: ' + (data.message || 'Failed to delete record'));
            closeDeleteModal();
        }
    } catch (error) {
        console.error('Delete error:', error);
        alert('An error occurred while deleting the record');
        closeDeleteModal();
    }
}
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/includes/layout.php';
?>
