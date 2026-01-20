<?php
require_once __DIR__ . '/../config/config.php';

Security::requireLogin();
requirePermission('can_transfer_in');

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!Security::validateCSRFToken($csrfToken)) {
        $error = 'Invalid security token.';
    } else {
        $lastName = Security::sanitizeInput($_POST['last_name'] ?? '');
        $firstName = Security::sanitizeInput($_POST['first_name'] ?? '');
        $middleInitial = Security::sanitizeInput($_POST['middle_initial'] ?? '');
        $fromLocalName = Security::sanitizeInput($_POST['from_local_name'] ?? '');
        $fromDistrict = Security::sanitizeInput($_POST['from_district'] ?? '');
        $toDistrictCode = Security::sanitizeInput($_POST['to_district_code'] ?? '');
        $toLocalCode = Security::sanitizeInput($_POST['to_local_code'] ?? '');
        $purok = Security::sanitizeInput($_POST['purok'] ?? '');
        $grupo = Security::sanitizeInput($_POST['grupo'] ?? '');
        $controlNumber = Security::sanitizeInput($_POST['control_number'] ?? '');
        $department = Security::sanitizeInput($_POST['department'] ?? '');
        $duty = Security::sanitizeInput($_POST['duty'] ?? '');
        $oathDate = Security::sanitizeInput($_POST['oath_date'] ?? '');
        $transferDate = Security::sanitizeInput($_POST['transfer_date'] ?? date('Y-m-d'));
        
        // Validation
        if (empty($lastName) || empty($firstName)) {
            $error = 'First name and last name are required.';
        } elseif (empty($toDistrictCode) || empty($toLocalCode)) {
            $error = 'Destination district and local congregation are required.';
        } elseif (empty($department) || empty($oathDate)) {
            $error = 'Department and oath date are required.';
        } elseif (!hasDistrictAccess($toDistrictCode)) {
            $error = 'You do not have access to the destination district.';
        } elseif (!hasLocalAccess($toLocalCode)) {
            $error = 'You do not have access to the destination local congregation.';
        } else {
            try {
                $db->beginTransaction();
                
                // Generate UUID and week info
                $officerUuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                    mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
                    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
                );
                
                // Calculate week number from transfer date
                $transferDateObj = new DateTime($transferDate);
                $weekNumber = (int)$transferDateObj->format('W');
                $year = (int)$transferDateObj->format('Y');
                $weekInfo = getWeekDateRange($weekNumber, $year);
                
                // Encrypt officer name
                $encrypted = Encryption::encryptOfficerName($lastName, $firstName, $middleInitial, $toDistrictCode);
                
                // Insert officer with CODE A (transfer in counts as new to this local)
                $stmt = $db->prepare("
                    INSERT INTO officers (
                        officer_uuid, 
                        last_name_encrypted, 
                        first_name_encrypted, 
                        middle_initial_encrypted,
                        district_code,
                        local_code,
                        purok,
                        grupo,
                        control_number,
                        record_code,
                        is_active,
                        created_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'A', 1, ?)
                ");
                
                $stmt->execute([
                    $officerUuid,
                    $encrypted['last_name_encrypted'],
                    $encrypted['first_name_encrypted'],
                    $encrypted['middle_initial_encrypted'],
                    $toDistrictCode,
                    $toLocalCode,
                    !empty($purok) ? $purok : null,
                    !empty($grupo) ? $grupo : null,
                    !empty($controlNumber) ? $controlNumber : null,
                    $currentUser['user_id']
                ]);
                
                $officerId = $db->lastInsertId();
                
                // Add department
                $stmt = $db->prepare("
                    INSERT INTO officer_departments (officer_id, department, duty, oath_date, is_active)
                    VALUES (?, ?, ?, ?, 1)
                ");
                $stmt->execute([$officerId, $department, $duty, $oathDate]);
                
                // Record transfer
                $stmt = $db->prepare("
                    INSERT INTO transfers (
                        officer_id, transfer_type, from_local_code, from_district_code,
                        to_local_code, to_district_code, department, duty, oath_date,
                        transfer_date, week_number, year, processed_by, notes
                    ) VALUES (?, 'in', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $officerId, $fromLocalName, $fromDistrict, $toLocalCode, $toDistrictCode,
                    $department, $duty, $oathDate, $transferDate,
                    $weekInfo['week'], $weekInfo['year'], $currentUser['user_id'],
                    "Transfer in from $fromLocalName, $fromDistrict"
                ]);
                
                // Update headcount (+1)
                $stmt = $db->prepare("
                    INSERT INTO headcount (district_code, local_code, total_count)
                    VALUES (?, ?, 1)
                    ON DUPLICATE KEY UPDATE total_count = total_count + 1
                ");
                $stmt->execute([$toDistrictCode, $toLocalCode]);
                
                // Log audit
                $stmt = $db->prepare("
                    INSERT INTO audit_log (user_id, action, table_name, record_id, new_values, ip_address, user_agent)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $currentUser['user_id'], 'transfer_in', 'officers', $officerId,
                    json_encode(['from' => "$fromLocalName, $fromDistrict", 'to' => $toLocalCode]),
                    $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] ?? ''
                ]);
                
                $db->commit();
                
                setFlashMessage('success', 'Officer transferred in successfully! Headcount +1');
                redirect(BASE_URL . '/officers/view.php?id=' . $officerUuid);
                
            } catch (Exception $e) {
                $db->rollBack();
                error_log("Transfer in error: " . $e->getMessage());
                $error = 'An error occurred during the transfer.';
            }
        }
    }
}

// Get districts and locals
$districts = [];
$locals = [];

try {
    if ($currentUser['role'] === 'admin') {
        $stmt = $db->query("SELECT * FROM districts ORDER BY district_name");
        $districts = $stmt->fetchAll();
    } elseif ($currentUser['role'] === 'district') {
        $stmt = $db->prepare("SELECT * FROM districts WHERE district_code = ?");
        $stmt->execute([$currentUser['district_code']]);
        $districts = $stmt->fetchAll();
        
        $stmt = $db->prepare("SELECT * FROM local_congregations WHERE district_code = ? ORDER BY local_name");
        $stmt->execute([$currentUser['district_code']]);
        $locals = $stmt->fetchAll();
    } else {
        $stmt = $db->prepare("SELECT * FROM districts WHERE district_code = ?");
        $stmt->execute([$currentUser['district_code']]);
        $districts = $stmt->fetchAll();
        
        $stmt = $db->prepare("SELECT * FROM local_congregations WHERE local_code = ?");
        $stmt->execute([$currentUser['local_code']]);
        $locals = $stmt->fetchAll();
    }
} catch (Exception $e) {
    error_log("Load districts error: " . $e->getMessage());
}

$pageTitle = 'Transfer In Officer';
ob_start();
?>

<div class="max-w-4xl mx-auto">
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <div class="flex items-center space-x-3 mb-6">
            <div class="w-10 h-10 bg-green-100 dark:bg-green-900/30 rounded-lg flex items-center justify-center">
                <svg class="w-6 h-6 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path>
                </svg>
            </div>
            <h2 class="text-2xl font-semibold text-gray-900 dark:text-gray-100">Transfer In Officer</h2>
        </div>
        
        <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4 mb-6">
            <div class="flex items-start">
                <svg class="w-5 h-5 text-blue-600 dark:text-blue-400 mr-3 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                </svg>
                <div>
                    <p class="font-semibold text-blue-800 dark:text-blue-300">Transfer In Process</p>
                    <p class="text-sm text-blue-700 dark:text-blue-400 mt-1">This will add the officer to your congregation and increase headcount by +1. Week number will be auto-generated based on transfer date.</p>
                </div>
            </div>
        </div>
        
        <?php if (!empty($error)): ?>
            <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4 mb-4">
                <div class="flex items-center">
                    <svg class="w-5 h-5 text-red-600 dark:text-red-400 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                    </svg>
                    <span class="text-sm font-medium text-red-800 dark:text-red-300"><?php echo Security::escape($error); ?></span>
                </div>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="" class="space-y-6">
            <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
            
            <!-- Personal Information -->
            <div class="border-b border-gray-200 dark:border-gray-700 pb-2 mb-6">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Officer Information</h3>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Last Name <span class="text-red-600">*</span>
                    </label>
                    <input 
                        type="text" 
                        name="last_name" 
                        placeholder="Last Name" 
                        class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 transition-colors"
                        value="<?php echo Security::escape($_POST['last_name'] ?? ''); ?>"
                        required
                    >
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        First Name <span class="text-red-600">*</span>
                    </label>
                    <input 
                        type="text" 
                        name="first_name" 
                        placeholder="First Name" 
                        class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 transition-colors"
                        value="<?php echo Security::escape($_POST['first_name'] ?? ''); ?>"
                        required
                    >
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        M.I.
                    </label>
                    <input 
                        type="text" 
                        name="middle_initial" 
                        placeholder="M.I." 
                        class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 transition-colors"
                        maxlength="2"
                        value="<?php echo Security::escape($_POST['middle_initial'] ?? ''); ?>"
                    >
                </div>
            </div>
            
            <!-- Origin Information -->
            <div class="border-b border-gray-200 dark:border-gray-700 pb-2 mb-6">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">From (Origin)</h3>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        From Local Congregation <span class="text-red-600">*</span>
                    </label>
                    <input 
                        type="text" 
                        name="from_local_name" 
                        placeholder="e.g., San Juan Local" 
                        class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 transition-colors"
                        value="<?php echo Security::escape($_POST['from_local_name'] ?? ''); ?>"
                        required
                    >
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        From District <span class="text-red-600">*</span>
                    </label>
                    <input 
                        type="text" 
                        name="from_district" 
                        placeholder="e.g., District 5" 
                        class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 transition-colors"
                        value="<?php echo Security::escape($_POST['from_district'] ?? ''); ?>"
                        required
                    >
                </div>
            </div>
            
            <!-- Destination Information -->
            <div class="border-b border-gray-200 dark:border-gray-700 pb-2 mb-6">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">To (Destination)</h3>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        To District <span class="text-red-600">*</span>
                    </label>
                    <?php if ($currentUser['role'] !== 'local'): ?>
                        <select 
                            name="to_district_code" 
                            class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 transition-colors"
                            id="district-select"
                            required
                        >
                            <option value="">Select District</option>
                            <?php foreach ($districts as $district): ?>
                                <option value="<?php echo Security::escape($district['district_code']); ?>"
                                    <?php echo ($currentUser['district_code'] === $district['district_code']) ? 'selected' : ''; ?>>
                                    <?php echo Security::escape($district['district_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="text" class="w-full px-4 py-2 border border-gray-300 rounded-lg bg-gray-100" 
                               value="<?php 
                                   foreach ($districts as $district) {
                                       if ($district['district_code'] === $currentUser['district_code']) {
                                           echo Security::escape($district['district_name']);
                                           break;
                                       }
                                   }
                               ?>" readonly>
                        <input type="hidden" name="to_district_code" value="<?php echo Security::escape($currentUser['district_code']); ?>">
                    <?php endif; ?>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        To Local Congregation <span class="text-red-600">*</span>
                    </label>
                    <?php if ($currentUser['role'] !== 'local'): ?>
                        <div class="relative">
                            <input 
                                type="text" 
                                id="local-display"
                                class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 cursor-pointer bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100"
                                placeholder="Select Local Congregation"
                                readonly
                                onclick="openLocalModal()"
                                value=""
                            >
                            <input type="hidden" name="to_local_code" id="local-value" required>
                            <div class="absolute inset-y-0 right-0 flex items-center px-3 pointer-events-none">
                                <svg class="w-5 h-5 text-gray-400 dark:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                </svg>
                            </div>
                        </div>
                    <?php else: ?>
                        <input type="text" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-gray-100 dark:bg-gray-600 text-gray-700 dark:text-gray-300" 
                               value="<?php echo Security::escape($locals[0]['local_name'] ?? ''); ?>" readonly>
                        <input type="hidden" name="to_local_code" value="<?php echo Security::escape($currentUser['local_code']); ?>">
                    <?php endif; ?>
                </div>
            </div>

            <!-- Purok, Grupo, Control Number (Optional Fields) -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Purok <span class="text-gray-400 dark:text-gray-500 text-xs">(Optional)</span>
                    </label>
                    <input 
                        type="text" 
                        name="purok" 
                        class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100"
                        placeholder="Enter purok"
                        value="<?php echo Security::escape($_POST['purok'] ?? ''); ?>"
                    >
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Grupo <span class="text-gray-400 dark:text-gray-500 text-xs">(Optional)</span>
                    </label>
                    <input 
                        type="text" 
                        name="grupo" 
                        class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100"
                        placeholder="Enter grupo"
                        value="<?php echo Security::escape($_POST['grupo'] ?? ''); ?>"
                    >
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Control Number <span class="text-gray-400 dark:text-gray-500 text-xs">(Optional)</span>
                    </label>
                    <input 
                        type="text" 
                        name="control_number" 
                        class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100"
                        placeholder="Enter control number"
                        value="<?php echo Security::escape($_POST['control_number'] ?? ''); ?>"
                    >
                </div>
            </div>
            
            <!-- Department and Duty -->
            <div class="border-b border-gray-200 dark:border-gray-700 pb-2 mb-6 mt-6">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Assignment Information</h3>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Department <span class="text-red-600">*</span>
                    </label>
                    <div class="relative">
                        <input 
                            type="text" 
                            id="department-display"
                            class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 cursor-pointer bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100"
                            placeholder="Select Department"
                            readonly
                            onclick="openDepartmentModal()"
                            value="<?php echo Security::escape($_POST['department'] ?? ''); ?>"
                        >
                        <input type="hidden" name="department" id="department-value" value="<?php echo Security::escape($_POST['department'] ?? ''); ?>" required>
                        <div class="absolute inset-y-0 right-0 flex items-center px-3 pointer-events-none">
                            <svg class="w-5 h-5 text-gray-400 dark:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </div>
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Oath Date <span class="text-red-600">*</span>
                    </label>
                    <input 
                        type="date" 
                        name="oath_date" 
                        class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 transition-colors"
                        value="<?php echo Security::escape($_POST['oath_date'] ?? date('Y-m-d')); ?>"
                        max="<?php echo date('Y-m-d'); ?>"
                        required
                    >
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Transfer Date <span class="text-red-600">*</span>
                    </label>
                    <input 
                        type="date" 
                        name="transfer_date" 
                        class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 transition-colors"
                        value="<?php echo date('Y-m-d'); ?>"
                        max="<?php echo date('Y-m-d'); ?>"
                        required
                    >
                </div>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Duty/Specific Role
                </label>
                <textarea 
                    name="duty" 
                    placeholder="Describe the specific duty or role (optional)" 
                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 transition-colors resize-none h-24"
                    ><?php echo Security::escape($_POST['duty'] ?? ''); ?></textarea>
            </div>
            
            <!-- Week Info Display -->
            <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg p-4">
                <div class="flex items-center">
                    <svg class="w-5 h-5 text-green-600 dark:text-green-400 mr-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                    </svg>
                    <span class="text-sm font-medium text-green-800 dark:text-green-300">Current Week: <strong>Week <?php echo getCurrentWeekNumber(); ?>, <?php echo date('Y'); ?></strong></span>
                </div>
            </div>
            
            <!-- Submit Button -->
            <div class="flex items-center justify-end space-x-3 pt-4">
                <a href="<?php echo BASE_URL; ?>/officers/list.php" class="inline-flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                    Cancel
                </a>
                <button type="submit" class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 focus:ring-2 focus:ring-green-500 focus:ring-offset-2 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path>
                    </svg>
                    Transfer In Officer
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Dynamic local congregation loading
let currentLocals = <?php echo json_encode($locals); ?>;

document.getElementById('district-select')?.addEventListener('change', function() {
    const districtCode = this.value;
    const localDisplay = document.getElementById('local-display');
    const localValue = document.getElementById('local-value');
    
    if (!districtCode) {
        localDisplay.value = '';
        localValue.value = '';
        return;
    }
    
    // Clear current selection
    localDisplay.value = '';
    localValue.value = '';
    
    // Load locals for modal
    loadLocalsForModal(districtCode);
});

function loadLocalsForModal(districtCode) {
    fetch('<?php echo BASE_URL; ?>/api/get-locals.php?district=' + districtCode)
        .then(response => response.json())
        .then(data => {
            currentLocals = data;
        })
        .catch(error => console.error('Error loading locals:', error));
}

function openLocalModal() {
    const districtCode = document.getElementById('district-select').value;
    if (!districtCode) {
        alert('Please select a district first');
        return;
    }
    
    const modal = document.getElementById('local-modal');
    const listContainer = document.getElementById('local-list');
    
    // Populate list
    listContainer.innerHTML = '';
    currentLocals.forEach(local => {
        const div = document.createElement('div');
        div.className = 'local-item px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-700 cursor-pointer border-b border-gray-100 dark:border-gray-700 text-gray-900 dark:text-gray-100';
        div.textContent = local.local_name;
        div.onclick = () => selectLocal(local.local_code, local.local_name);
        listContainer.appendChild(div);
    });
    
    modal.classList.remove('hidden');
    modal.style.display = 'block';
    document.getElementById('local-search').focus();
}

function closeLocalModal() {
    const modal = document.getElementById('local-modal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
    document.getElementById('local-search').value = '';
    filterLocals();
}

function selectLocal(code, name) {
    document.getElementById('local-value').value = code;
    document.getElementById('local-display').value = name;
    closeLocalModal();
}

function filterLocals() {
    const search = document.getElementById('local-search').value.toLowerCase();
    const items = document.querySelectorAll('.local-item');
    items.forEach(item => {
        const text = item.textContent.toLowerCase();
        item.style.display = text.includes(search) ? 'block' : 'none';
    });
}

// Department Modal
function openDepartmentModal() {
    const modal = document.getElementById('department-modal');
    modal.classList.remove('hidden');
    modal.style.display = 'block';
    document.getElementById('department-search').focus();
}

function closeDepartmentModal() {
    const modal = document.getElementById('department-modal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
    document.getElementById('department-search').value = '';
    filterDepartments();
}

function selectDepartment(value) {
    document.getElementById('department-value').value = value;
    document.getElementById('department-display').value = value;
    closeDepartmentModal();
}

function filterDepartments() {
    const search = document.getElementById('department-search').value.toLowerCase();
    const items = document.querySelectorAll('.department-item');
    items.forEach(item => {
        const text = item.textContent.toLowerCase();
        item.style.display = text.includes(search) ? 'block' : 'none';
    });
}
</script>

<!-- Department Modal -->
<div id="department-modal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity" onclick="closeDepartmentModal()"></div>
        <div class="relative bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-md w-full max-h-[80vh] flex flex-col">
            <!-- Header -->
            <div class="flex items-center justify-between p-4 border-b dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Select Department</h3>
                <button type="button" onclick="closeDepartmentModal()" class="text-gray-400 hover:text-gray-500 dark:hover:text-gray-300">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            
            <!-- Search -->
            <div class="p-4 border-b dark:border-gray-700">
                <input 
                    type="text" 
                    id="department-search"
                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100"
                    placeholder="Search departments..."
                    oninput="filterDepartments()"
                >
            </div>
            
            <!-- List -->
            <div class="overflow-y-auto flex-1">
                <?php foreach (getDepartments() as $dept): ?>
                    <div class="department-item px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-700 cursor-pointer border-b border-gray-100 dark:border-gray-700"
                         onclick="selectDepartment('<?php echo Security::escape($dept); ?>')">
                        <span class="text-gray-900 dark:text-gray-100"><?php echo Security::escape($dept); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- Local Modal -->
<div id="local-modal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity" onclick="closeLocalModal()"></div>
        <div class="relative bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-md w-full max-h-[80vh] flex flex-col">
            <!-- Header -->
            <div class="flex items-center justify-between p-4 border-b dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Select Local Congregation</h3>
                <button type="button" onclick="closeLocalModal()" class="text-gray-400 hover:text-gray-500 dark:hover:text-gray-300">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            
            <!-- Search -->
            <div class="p-4 border-b dark:border-gray-700">
                <input 
                    type="text" 
                    id="local-search"
                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100"
                    placeholder="Search local congregations..."
                    oninput="filterLocals()"
                >
            </div>
            
            <!-- List -->
            <div id="local-list" class="overflow-y-auto flex-1">
                <!-- Will be populated dynamically -->
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
