<?php
require_once __DIR__ . '/../config/config.php';

Security::requireLogin();

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

$error = '';
$success = '';
$officerUuid = Security::sanitizeInput($_GET['id'] ?? '');

if (empty($officerUuid)) {
    setFlashMessage('error', 'Invalid officer ID.');
    redirect(BASE_URL . '/officers/list.php');
}

// Get officer details
try {
    $stmt = $db->prepare("
        SELECT o.*, d.district_name, lc.local_name
        FROM officers o
        LEFT JOIN districts d ON o.district_code = d.district_code
        LEFT JOIN local_congregations lc ON o.local_code = lc.local_code
        WHERE o.officer_uuid = ?
    ");
    $stmt->execute([$officerUuid]);
    $officer = $stmt->fetch();
    
    if (!$officer) {
        setFlashMessage('error', 'Officer not found.');
        redirect(BASE_URL . '/officers/list.php');
    }
    
    // Check access
    if (!hasLocalAccess($officer['local_code'])) {
        setFlashMessage('error', 'Access denied.');
        redirect(BASE_URL . '/officers/list.php');
    }
    
    // Decrypt officer name
    $decrypted = Encryption::decryptOfficerName(
        $officer['last_name_encrypted'],
        $officer['first_name_encrypted'],
        $officer['middle_initial_encrypted'],
        $officer['district_code']
    );
    
    // Decrypt registry number if exists
    $registryNumber = null;
    if (!empty($officer['registry_number_encrypted'])) {
        $registryNumber = Encryption::decrypt($officer['registry_number_encrypted'], $officer['district_code']);
    }
    
    // Get departments
    $stmt = $db->prepare("
        SELECT * FROM officer_departments 
        WHERE officer_id = ? 
        ORDER BY assigned_at DESC
    ");
    $stmt->execute([$officer['officer_id']]);
    $departments = $stmt->fetchAll();
    
} catch (Exception $e) {
    error_log("Load officer error: " . $e->getMessage());
    setFlashMessage('error', 'Error loading officer details.');
    redirect(BASE_URL . '/officers/list.php');
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!Security::validateCSRFToken($csrfToken)) {
        $error = 'Invalid security token.';
    } else {
        $lastName = Security::sanitizeInput($_POST['last_name'] ?? '');
        $firstName = Security::sanitizeInput($_POST['first_name'] ?? '');
        $middleInitial = Security::sanitizeInput($_POST['middle_initial'] ?? '');
        $districtCode = Security::sanitizeInput($_POST['district_code'] ?? '');
        $localCode = Security::sanitizeInput($_POST['local_code'] ?? '');
        $purok = Security::sanitizeInput($_POST['purok'] ?? '');
        $grupo = Security::sanitizeInput($_POST['grupo'] ?? '');
        $controlNumber = Security::sanitizeInput($_POST['control_number'] ?? '');
        $registryNumber = Security::sanitizeInput($_POST['registry_number'] ?? '');
        $tarhetaControlId = !empty($_POST['tarheta_control_id']) ? (int)$_POST['tarheta_control_id'] : null;
        $legacyOfficerId = !empty($_POST['legacy_officer_id']) ? (int)$_POST['legacy_officer_id'] : null;
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $departments = $_POST['departments'] ?? [];
        
        // Validation
        if (empty($lastName) || empty($firstName)) {
            $error = 'First name and last name are required.';
        } elseif (empty($districtCode) || empty($localCode)) {
            $error = 'District and local congregation are required.';
        } elseif (!hasDistrictAccess($districtCode)) {
            $error = 'You do not have access to this district.';
        } elseif (!hasLocalAccess($localCode)) {
            $error = 'You do not have access to this local congregation.';
        } else {
            // Check if user is local_limited and needs approval
            require_once __DIR__ . '/../includes/pending-actions.php';
            
            if (shouldPendAction()) {
                // Create pending action instead of executing immediately
                $officerName = $lastName . ', ' . $firstName;
                
                $actionData = [
                    'officer_uuid' => $officerUuid,
                    'last_name' => $lastName,
                    'first_name' => $firstName,
                    'middle_initial' => $middleInitial,
                    'district_code' => $districtCode,
                    'local_code' => $localCode,
                    'purok' => $purok,
                    'grupo' => $grupo,
                    'control_number' => $controlNumber,
                    'registry_number' => $registryNumber,
                    'tarheta_control_id' => $tarhetaControlId,
                    'legacy_officer_id' => $legacyOfficerId,
                    'is_active' => $isActive,
                ];
                
                $actionId = createPendingEditOfficer($officer['officer_id'], $actionData, $officerName);
                
                if ($actionId) {
                    $_SESSION['success'] = getPendingActionMessage('edit officer request');
                    header('Location: ' . BASE_URL . '/officers/view.php?id=' . $officerUuid);
                    exit;
                } else {
                    $error = 'Failed to submit action for approval. Please try again.';
                }
            } else {
                // Execute action normally for non-limited users
            try {
                $db->beginTransaction();
                
                // Re-encrypt officer name if district changed or name changed
                $encrypted = Encryption::encryptOfficerName($lastName, $firstName, $middleInitial, $districtCode);
                
                // Encrypt registry number if provided
                $registryNumberEnc = !empty($registryNumber) ? Encryption::encrypt($registryNumber, $districtCode) : null;
                
                // Encrypt control number if provided
                $controlNumberEnc = !empty($controlNumber) ? Encryption::encrypt($controlNumber, $districtCode) : null;
                
                // Update officer
                $stmt = $db->prepare("
                    UPDATE officers SET
                        last_name_encrypted = ?,
                        first_name_encrypted = ?,
                        middle_initial_encrypted = ?,
                        district_code = ?,
                        local_code = ?,
                        purok = ?,
                        grupo = ?,
                        control_number = ?,
                        control_number_encrypted = ?,
                        registry_number_encrypted = ?,
                        tarheta_control_id = ?,
                        legacy_officer_id = ?,
                        is_active = ?
                    WHERE officer_uuid = ?
                ");
                
                $stmt->execute([
                    $encrypted['last_name_encrypted'],
                    $encrypted['first_name_encrypted'],
                    $encrypted['middle_initial_encrypted'],
                    $districtCode,
                    $localCode,
                    !empty($purok) ? $purok : null,
                    !empty($grupo) ? $grupo : null,
                    !empty($controlNumber) ? $controlNumber : null,
                    $controlNumberEnc,
                    $registryNumberEnc,
                    $tarhetaControlId,
                    $legacyOfficerId,
                    $isActive,
                    $officerUuid
                ]);
                
                // Update tarheta_control linking if provided
                if ($tarhetaControlId) {
                    $stmt = $db->prepare("
                        UPDATE tarheta_control 
                        SET linked_officer_id = ?, linked_at = NOW(), linked_by = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$officer['officer_id'], $currentUser['user_id'], $tarhetaControlId]);
                }
                
                // Update legacy_officers linking if provided
                if ($legacyOfficerId) {
                    $stmt = $db->prepare("
                        UPDATE legacy_officers 
                        SET linked_officer_id = ?, linked_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$officer['officer_id'], $legacyOfficerId]);
                }
                
                // If officer is being reactivated, reactivate departments that weren't removed through removal-request
                if ($isActive == 1 && $officer['is_active'] == 0) {
                    // Get departments that were NOT removed through removal-request (department_id is NULL in officer_removals)
                    // Reactivate only departments that don't have a removal record with department_id
                    $stmt = $db->prepare("
                        UPDATE officer_departments od
                        SET od.is_active = 1, od.removed_at = NULL
                        WHERE od.officer_id = ? 
                        AND od.is_active = 0
                        AND NOT EXISTS (
                            SELECT 1 FROM officer_removals r 
                            WHERE r.department_id = od.id 
                            AND r.officer_id = od.officer_id
                        )
                    ");
                    $stmt->execute([$officer['officer_id']]);
                }
                
                // Update departments
                foreach ($departments as $deptData) {
                    $deptId = intval($deptData['id'] ?? 0);
                    $duty = Security::sanitizeInput($deptData['duty'] ?? '');
                    
                    // Get separate date fields
                    $oathMonth = Security::sanitizeInput($deptData['oath_month'] ?? '');
                    $oathDay = Security::sanitizeInput($deptData['oath_day'] ?? '');
                    $oathYear = Security::sanitizeInput($deptData['oath_year'] ?? '');
                    
                    // Build oath_date: use full date if MM/DD provided, otherwise just year
                    if (!empty($oathMonth) && !empty($oathDay) && !empty($oathYear)) {
                        // Full date format: YYYY-MM-DD
                        $oathDate = $oathYear . '-' . str_pad($oathMonth, 2, '0', STR_PAD_LEFT) . '-' . str_pad($oathDay, 2, '0', STR_PAD_LEFT);
                    } elseif (!empty($oathYear)) {
                        // Only year provided - default to January 1st
                        $oathDate = $oathYear . '-01-01';
                    } else {
                        $oathDate = '';
                    }
                    
                    if ($deptId > 0) {
                        // Only update if oath_date is not empty (required field)
                        if (!empty($oathDate)) {
                            $stmt = $db->prepare("
                                UPDATE officer_departments 
                                SET duty = ?, oath_date = ?
                                WHERE id = ? AND officer_id = ?
                            ");
                            $stmt->execute([$duty, $oathDate, $deptId, $officer['officer_id']]);
                        } else {
                            // Update only duty if oath_date is empty
                            $stmt = $db->prepare("
                                UPDATE officer_departments 
                                SET duty = ?
                                WHERE id = ? AND officer_id = ?
                            ");
                            $stmt->execute([$duty, $deptId, $officer['officer_id']]);
                        }
                    }
                }
                
                // Log audit
                $stmt = $db->prepare("
                    INSERT INTO audit_log (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $currentUser['user_id'],
                    'update_officer',
                    'officers',
                    $officer['officer_id'],
                    json_encode([
                        'district_code' => $officer['district_code'],
                        'local_code' => $officer['local_code'],
                        'is_active' => $officer['is_active']
                    ]),
                    json_encode([
                        'district_code' => $districtCode,
                        'local_code' => $localCode,
                        'is_active' => $isActive
                    ]),
                    $_SERVER['REMOTE_ADDR'],
                    $_SERVER['HTTP_USER_AGENT'] ?? ''
                ]);
                
                $db->commit();
                
                setFlashMessage('success', 'Officer updated successfully!');
                redirect(BASE_URL . '/officers/view.php?id=' . $officerUuid);
                
            } catch (Exception $e) {
                $db->rollBack();
                error_log("Update officer error: " . $e->getMessage());
                $error = 'An error occurred while updating the officer.';
            }
            } // End of non-limited user execution
        }
    }
}

// Get districts and locals for dropdowns
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

$pageTitle = 'Edit Officer';
ob_start();
?>

<div class="max-w-4xl mx-auto space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between bg-white rounded-lg shadow-sm border border-gray-200 p-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Edit Officer</h1>
            <p class="text-sm text-gray-600">Update officer information</p>
        </div>
        <a href="<?php echo BASE_URL; ?>/officers/view.php?id=<?php echo urlencode($officerUuid); ?>" class="inline-flex items-center px-3 py-1.5 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 transition-colors">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
            </svg>
            Back
        </a>
    </div>
    
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <?php if (!empty($error)): ?>
            <div class="mb-6 bg-red-50 border border-red-200 rounded-lg p-4">
                <div class="flex items-center">
                    <svg class="w-5 h-5 text-red-600 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                    </svg>
                    <span class="text-sm font-medium text-red-800"><?php echo Security::escape($error); ?></span>
                </div>
            </div>
        <?php endif; ?>
            
            <form method="POST" action="" class="space-y-6">
                <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                
                <!-- Personal Information -->
                <div>
                    <div class="flex items-center space-x-2 mb-4">
                        <div class="w-8 h-8 bg-blue-100 rounded-lg flex items-center justify-center">
                            <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                            </svg>
                        </div>
                        <h3 class="font-semibold text-gray-900">Personal Information</h3>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Last Name <span class="text-red-600">*</span>
                            </label>
                            <input 
                                type="text" 
                                name="last_name" 
                                placeholder="Last Name" 
                                class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                value="<?php echo Security::escape($decrypted['last_name']); ?>"
                                required
                            >
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                First Name <span class="text-red-600">*</span>
                            </label>
                            <input 
                                type="text" 
                                name="first_name" 
                                placeholder="First Name" 
                                class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                value="<?php echo Security::escape($decrypted['first_name']); ?>"
                                required
                            >
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">M.I.</label>
                            <input 
                                type="text" 
                                name="middle_initial" 
                                placeholder="M.I." 
                                class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                maxlength="2"
                                value="<?php echo Security::escape($decrypted['middle_initial']); ?>"
                            >
                        </div>
                    </div>
                </div>
                
                <!-- Location Information -->
                <div>
                    <div class="flex items-center space-x-2 mb-4">
                        <div class="w-8 h-8 bg-green-100 rounded-lg flex items-center justify-center">
                            <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            </svg>
                        </div>
                        <h3 class="font-semibold text-gray-900">Location Information</h3>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                District <span class="text-red-600">*</span>
                            </label>
                            <?php if ($currentUser['role'] !== 'local' && $currentUser['role'] !== 'local_limited'): ?>
                                <select 
                                    name="district_code" 
                                    class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                    id="district-select"
                                    required
                                >
                                    <option value="">Select District</option>
                                    <?php foreach ($districts as $district): ?>
                                        <option value="<?php echo Security::escape($district['district_code']); ?>"
                                            <?php echo ($officer['district_code'] === $district['district_code']) ? 'selected' : ''; ?>>
                                            <?php echo Security::escape($district['district_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <input type="text" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg bg-gray-100 cursor-not-allowed" 
                                       value="<?php echo Security::escape($officer['district_name'] ?? ''); ?>" readonly>
                                <input type="hidden" name="district_code" value="<?php echo Security::escape($officer['district_code']); ?>">
                            <?php endif; ?>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Local Congregation <span class="text-red-600">*</span>
                            </label>
                            <?php if ($currentUser['role'] !== 'local' && $currentUser['role'] !== 'local_limited'): ?>
                                <div class="relative">
                                    <input 
                                        type="text" 
                                        id="local-display"
                                        class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 cursor-pointer bg-white"
                                        placeholder="Select Local Congregation"
                                        readonly
                                        onclick="openLocalModal()"
                                        value="<?php 
                                            foreach ($locals as $local) {
                                                if ($local['local_code'] === $officer['local_code']) {
                                                    echo Security::escape($local['local_name']);
                                                    break;
                                                }
                                            }
                                        ?>"
                                    >
                                    <input type="hidden" name="local_code" id="local-value" value="<?php echo Security::escape($officer['local_code']); ?>" required>
                                    <div class="absolute inset-y-0 right-0 flex items-center px-3 pointer-events-none">
                                        <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                        </svg>
                                    </div>
                                </div>
                            <?php else: ?>
                                <input type="text" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg bg-gray-100 cursor-not-allowed" 
                                       value="<?php echo Security::escape($officer['local_name'] ?? ''); ?>" readonly>
                                <input type="hidden" name="local_code" value="<?php echo Security::escape($officer['local_code']); ?>">
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Purok, Grupo, Control Number (Optional Fields) -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Purok <span class="text-gray-400 text-xs">(Optional)</span>
                            </label>
                            <input 
                                type="text" 
                                name="purok" 
                                class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                placeholder="Enter purok"
                                value="<?php echo Security::escape($officer['purok'] ?? ''); ?>"
                            >
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Grupo <span class="text-gray-400 text-xs">(Optional)</span>
                            </label>
                            <input 
                                type="text" 
                                name="grupo" 
                                class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                placeholder="Enter grupo"
                                value="<?php echo Security::escape($officer['grupo'] ?? ''); ?>"
                            >
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Control Number <span class="text-gray-400 text-xs">(Optional - Search Legacy)</span>
                            </label>
                            <div class="relative">
                                <input 
                                    type="text" 
                                    id="control_search"
                                    name="control_number" 
                                    class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                    placeholder="Search by name or control #..."
                                    value="<?php echo Security::escape($officer['control_number'] ?? ''); ?>"
                                    autocomplete="off"
                                    onkeyup="searchControl(this.value)"
                                    onfocus="if(this.value.length >= 1) searchControl(this.value)"
                                >
                                <input type="hidden" name="legacy_officer_id" id="legacy_officer_id" value="<?php echo Security::escape($officer['legacy_officer_id'] ?? ''); ?>">
                                
                                <!-- Search Results Dropdown -->
                                <div id="control_results" class="hidden absolute z-50 w-full mt-1 bg-white border border-gray-300 rounded-lg shadow-lg max-h-96 overflow-y-auto">
                                    <div id="control_results_list"></div>
                                </div>
                                
                                <!-- Loading Indicator -->
                                <div id="control_loading" class="hidden absolute right-3 top-3">
                                    <svg class="animate-spin h-5 w-5 text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                </div>
                            </div>
                            <p class="text-xs text-gray-500 mt-1">Search for legacy control numbers or enter manually</p>
                        </div>
                    </div>

                    <!-- Registry Number (Optional Field) -->
                    <div class="mt-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Registry Number <span class="text-gray-400 text-xs">(Optional - from Tarheta Control)</span>
                        </label>
                        <div class="relative">
                            <input 
                                type="text" 
                                id="registry_search" 
                                class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                placeholder="Search registry number or leave blank..."
                                value="<?php echo Security::escape($registryNumber ?? ''); ?>"
                                onkeyup="searchTarheta(this.value)"
                                autocomplete="off"
                            >
                            <input type="hidden" name="registry_number" id="registry_number_hidden" value="<?php echo Security::escape($registryNumber ?? ''); ?>">
                            <input type="hidden" name="tarheta_control_id" id="tarheta_control_id" value="<?php echo Security::escape($officer['tarheta_control_id'] ?? ''); ?>">
                            <div id="tarheta_results" class="absolute z-10 w-full mt-1 bg-white border border-gray-300 rounded-lg shadow-lg hidden max-h-60 overflow-y-auto"></div>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">Search for records from Tarheta Control or enter manually</p>
                    </div>
                </div>
                
                <!-- Departments & Duties -->
                <div>
                    <div class="flex items-center space-x-2 mb-4">
                        <div class="w-8 h-8 bg-yellow-100 rounded-lg flex items-center justify-center">
                            <svg class="w-5 h-5 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                            </svg>
                        </div>
                        <h3 class="font-semibold text-gray-900">Departments & Duties</h3>
                    </div>
                    
                    <?php if (empty($departments)): ?>
                        <p class="text-sm text-gray-600 p-4 bg-gray-50 rounded-lg text-center">No departments assigned</p>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($departments as $dept): ?>
                                <div class="p-4 bg-gray-50 rounded-lg border border-gray-200">
                                    <input type="hidden" name="departments[<?php echo $dept['id']; ?>][id]" value="<?php echo $dept['id']; ?>">
                                    
                                    <div class="flex items-center justify-between mb-3">
                                        <div class="flex items-center space-x-2">
                                            <span class="font-semibold text-sm text-gray-900"><?php echo Security::escape($dept['department'] ?? 'N/A'); ?></span>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?php echo ($dept['is_active'] ?? 1) ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                                <?php echo ($dept['is_active'] ?? 1) ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <?php if ($dept['is_active'] ?? 1): ?>
                                        <div class="grid grid-cols-1 gap-3">
                                            <div>
                                                <label class="block text-xs font-medium text-gray-700 mb-1">Duty/Role</label>
                                                <input 
                                                    type="text" 
                                                    name="departments[<?php echo $dept['id']; ?>][duty]"
                                                    placeholder="Specific duty or role"
                                                    class="w-full px-3 py-1.5 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                                    value="<?php echo Security::escape($dept['duty'] ?? ''); ?>"
                                                >
                                            </div>
                                            
                                            <div>
                                                <label class="block text-xs font-medium text-gray-700 mb-1">Oath Date</label>
                                                <div class="grid grid-cols-3 gap-2">
                                                    <?php 
                                                    // Parse saved oath_date
                                                    $savedMonth = '';
                                                    $savedDay = '';
                                                    $savedYear = '';
                                                    
                                                    if (!empty($dept['oath_date'])) {
                                                        // Try to parse as full date (YYYY-MM-DD)
                                                        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dept['oath_date'], $matches)) {
                                                            $savedYear = $matches[1];
                                                            $savedMonth = $matches[2];
                                                            $savedDay = $matches[3];
                                                        } 
                                                        // If just a year (YYYY)
                                                        elseif (preg_match('/^\d{4}$/', $dept['oath_date'])) {
                                                            $savedYear = $dept['oath_date'];
                                                        }
                                                    }
                                                    ?>
                                                    <div>
                                                        <label class="block text-xs text-gray-500 mb-0.5">Month</label>
                                                        <select 
                                                            name="departments[<?php echo $dept['id']; ?>][oath_month]" 
                                                            class="w-full px-2 py-1.5 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                                            <option value="">MM</option>
                                                            <?php for($m = 1; $m <= 12; $m++): 
                                                                $monthVal = str_pad($m, 2, '0', STR_PAD_LEFT);
                                                            ?>
                                                                <option value="<?php echo $monthVal; ?>"
                                                                    <?php echo ($savedMonth === $monthVal) ? 'selected' : ''; ?>>
                                                                    <?php echo $monthVal; ?>
                                                                </option>
                                                            <?php endfor; ?>
                                                        </select>
                                                    </div>
                                                    <div>
                                                        <label class="block text-xs text-gray-500 mb-0.5">Day</label>
                                                        <select 
                                                            name="departments[<?php echo $dept['id']; ?>][oath_day]" 
                                                            class="w-full px-2 py-1.5 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                                            <option value="">DD</option>
                                                            <?php for($d = 1; $d <= 31; $d++): 
                                                                $dayVal = str_pad($d, 2, '0', STR_PAD_LEFT);
                                                            ?>
                                                                <option value="<?php echo $dayVal; ?>"
                                                                    <?php echo ($savedDay === $dayVal) ? 'selected' : ''; ?>>
                                                                    <?php echo $dayVal; ?>
                                                                </option>
                                                            <?php endfor; ?>
                                                        </select>
                                                    </div>
                                                    <div>
                                                        <label class="block text-xs text-gray-500 mb-0.5">Year</label>
                                                        <select 
                                                            name="departments[<?php echo $dept['id']; ?>][oath_year]" 
                                                            class="w-full px-2 py-1.5 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                                            <option value="">YYYY</option>
                                                            <?php 
                                                            $currentYear = date('Y');
                                                            for($y = $currentYear; $y >= 1950; $y--): 
                                                            ?>
                                                                <option value="<?php echo $y; ?>" 
                                                                    <?php echo ($savedYear == $y) ? 'selected' : ''; ?>>
                                                                    <?php echo $y; ?>
                                                                </option>
                                                            <?php endfor; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <p class="text-xs text-gray-500 mt-1">Month and day are optional. If provided, full date will be saved; otherwise only year.</p>
                                            </div>
                                        </div>
                                        
                                        <p class="text-xs text-gray-500 mt-2 flex items-center">
                                            <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                            </svg>
                                            Assigned: <?php echo isset($dept['assigned_at']) ? formatDateTime($dept['assigned_at']) : 'N/A'; ?>
                                        </p>
                                    <?php else: ?>
                                        <p class="text-xs text-gray-600 mt-2">This department has been deactivated and cannot be edited.</p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 mt-4">
                            <div class="flex items-start">
                                <svg class="w-4 h-4 text-blue-600 mr-2 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                                </svg>
                                <span class="text-xs text-blue-800">To add new departments or remove existing ones, please use the officer view page.</span>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Status -->
                <div>
                    <div class="flex items-center space-x-2 mb-4">
                        <div class="w-8 h-8 bg-purple-100 rounded-lg flex items-center justify-center">
                            <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                        <h3 class="font-semibold text-gray-900">Status</h3>
                    </div>
                    
                    <div class="flex items-start space-x-3 p-4 bg-gray-50 rounded-lg border border-gray-200">
                        <input 
                            type="checkbox" 
                            name="is_active" 
                            id="is_active"
                            class="mt-1 h-4 w-4 text-green-600 focus:ring-green-500 border-gray-300 rounded"
                            <?php echo $officer['is_active'] ? 'checked' : ''; ?>
                        >
                        <label for="is_active" class="flex-1 cursor-pointer">
                            <span class="font-medium text-gray-900 block">Active Officer</span>
                            <p class="text-xs text-gray-600 mt-1">Uncheck to mark officer as inactive</p>
                        </label>
                    </div>
                </div>
                
                <!-- Officer Info (Read-only) -->
                <div>
                    <div class="flex items-center space-x-2 mb-4">
                        <div class="w-8 h-8 bg-indigo-100 rounded-lg flex items-center justify-center">
                            <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                        <h3 class="font-semibold text-gray-900">Officer Information</h3>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div class="p-3 bg-gray-50 rounded-lg border border-gray-200">
                            <p class="text-xs text-gray-600 mb-1">Officer UUID</p>
                            <p class="text-sm font-mono text-gray-900"><?php echo Security::escape($officer['officer_uuid']); ?></p>
                        </div>
                        <div class="p-3 bg-gray-50 rounded-lg border border-gray-200">
                            <p class="text-xs text-gray-600 mb-1">Record Code</p>
                            <div class="flex items-center">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?php echo $officer['record_code'] === 'A' ? 'bg-green-100 text-green-800' : 'bg-blue-100 text-blue-800'; ?>">
                                    CODE <?php echo Security::escape($officer['record_code']); ?>
                                </span>
                                <span class="ml-2 text-sm text-gray-700"><?php echo $officer['record_code'] === 'A' ? 'New Record' : 'Existing Record'; ?></span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Submit Buttons -->
                <div class="flex justify-end space-x-3 pt-6 border-t border-gray-200">
                    <a href="<?php echo BASE_URL; ?>/officers/view.php?id=<?php echo urlencode($officerUuid); ?>" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg font-medium text-gray-700 bg-white hover:bg-gray-50 transition-colors">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                        Cancel
                    </a>
                    <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-lg font-medium text-white bg-blue-600 hover:bg-blue-700 transition-colors">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"></path>
                        </svg>
                        Update Officer
                    </button>
                </div>
            </form>
        </div>
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
        div.className = 'local-item px-4 py-3 hover:bg-gray-50 cursor-pointer border-b border-gray-100';
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

// Tarheta Search Functions
let searchTimeout;
function searchTarheta(query) {
    clearTimeout(searchTimeout);
    
    const resultsDiv = document.getElementById('tarheta_results');
    
    if (query.length < 1) {
        resultsDiv.classList.add('hidden');
        return;
    }
    
    searchTimeout = setTimeout(async () => {
        try {
            const response = await fetch('../api/search-tarheta.php?search=' + encodeURIComponent(query));
            const data = await response.json();
            
            if (data.success && data.records && data.records.length > 0) {
                let html = '<div class="py-1">';
                
                // Add search stats with debugging info
                let statsText = `Found ${data.total_matches} match${data.total_matches > 1 ? 'es' : ''} 
                    (scanned ${data.total_scanned} record${data.total_scanned > 1 ? 's' : ''})`;
                
                if (data.decryption_errors > 0) {
                    statsText += ` - ${data.decryption_errors} decrypt error${data.decryption_errors > 1 ? 's' : ''}`;
                }
                
                html += `<div class="px-3 py-1.5 bg-blue-50 border-b border-blue-100 text-xs text-blue-700">
                    ${statsText}
                </div>`;
                
                data.records.forEach(record => {
                    // Build additional info line
                    let additionalInfo = [];
                    additionalInfo.push(`Registry: ${escapeHtml(record.registry_number)}`);
                    
                    if (record.husbands_surname && record.husbands_surname.trim() !== '') {
                        additionalInfo.push(`Husband: ${escapeHtml(record.husbands_surname)}`);
                    }
                    
                    additionalInfo.push(`${escapeHtml(record.district_name)} - ${escapeHtml(record.local_name)}`);
                    
                    html += `
                        <div class="px-3 py-2 hover:bg-blue-50 cursor-pointer border-b border-gray-100 text-sm transition-colors"
                             onclick="selectTarheta(${record.id}, '${escapeHtml(record.registry_number)}', '${escapeHtml(record.last_name)}', '${escapeHtml(record.first_name)}', '${escapeHtml(record.middle_name)}', '${escapeHtml(record.husbands_surname || '')}')">
                            <div class="font-medium text-gray-900">${escapeHtml(record.full_name)}</div>
                            <div class="text-xs text-gray-600 mt-0.5">${additionalInfo.join(' • ')}</div>
                        </div>
                    `;
                });
                html += '</div>';
                resultsDiv.innerHTML = html;
                resultsDiv.classList.remove('hidden');
            } else {
                let message = 'No matching records found';
                if (data.total_scanned > 0) {
                    message += ` (searched ${data.total_scanned} records)`;
                }
                if (data.decryption_errors > 0) {
                    message += ` - Warning: ${data.decryption_errors} record${data.decryption_errors > 1 ? 's' : ''} skipped due to decryption errors`;
                }
                resultsDiv.innerHTML = `<div class="px-3 py-3 text-sm text-gray-500 text-center">${message}</div>`;
                resultsDiv.classList.remove('hidden');
            }
        } catch (error) {
            console.error('Error searching tarheta:', error);
            resultsDiv.innerHTML = '<div class="px-3 py-3 text-sm text-red-600 text-center">Error searching records. Please try again.</div>';
            resultsDiv.classList.remove('hidden');
        }
    }, 300);
}

function selectTarheta(id, registryNumber, lastName, firstName, middleName, husbandsSurname) {
    document.getElementById('registry_search').value = registryNumber;
    document.getElementById('registry_number_hidden').value = registryNumber;
    document.getElementById('tarheta_control_id').value = id;
    document.getElementById('tarheta_results').classList.add('hidden');
    
    // Show success feedback
    const searchInput = document.getElementById('registry_search');
    const originalBorder = searchInput.className;
    searchInput.className = searchInput.className.replace('border-gray-300', 'border-green-500');
    setTimeout(() => {
        searchInput.className = originalBorder;
    }, 1000);
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text || '';
    return div.innerHTML;
}

// Search Legacy Control Numbers
let controlSearchTimeout;
function searchControl(search) {
    clearTimeout(controlSearchTimeout);
    
    const resultsDiv = document.getElementById('control_results');
    const resultsListDiv = document.getElementById('control_results_list');
    const loadingDiv = document.getElementById('control_loading');
    
    if (search.length < 1) {
        resultsDiv.classList.add('hidden');
        return;
    }
    
    controlSearchTimeout = setTimeout(async () => {
        const districtCode = document.querySelector('[name="district_code"]').value;
        const localCode = document.querySelector('[name="local_code"]').value;
        
        if (!districtCode || !localCode) {
            resultsListDiv.innerHTML = '<div class="p-3 text-sm text-gray-500">Please select district and local first</div>';
            resultsDiv.classList.remove('hidden');
            return;
        }
        
        loadingDiv.classList.remove('hidden');
        
        try {
            const response = await fetch(
                `<?php echo BASE_URL; ?>/api/search-legacy.php?search=${encodeURIComponent(search)}&district=${districtCode}&local=${localCode}`
            );
            const data = await response.json();
            
            loadingDiv.classList.add('hidden');
            
            if (data.success && data.records && data.records.length > 0) {
                let html = '<div class="py-1">';
                
                let statsText = `Found ${data.total_matches} match${data.total_matches > 1 ? 'es' : ''} 
                    (scanned ${data.total_scanned} record${data.total_scanned > 1 ? 's' : ''})`;
                
                if (data.decryption_errors > 0) {
                    statsText += ` - ${data.decryption_errors} decrypt error${data.decryption_errors > 1 ? 's' : ''}`;
                }
                
                html += `<div class="px-3 py-1.5 bg-blue-50 border-b border-blue-100 text-xs text-blue-700">
                    ${statsText}
                </div>`;
                
                data.records.forEach(record => {
                    html += `
                        <div class="px-3 py-2 hover:bg-blue-50 cursor-pointer border-b border-gray-100 last:border-0 transition-colors" 
                             onclick="selectLegacyControl(${record.id}, '${escapeHtml(record.name)}', '${escapeHtml(record.control_number)}')">
                            <div class="text-sm font-medium text-gray-900">${escapeHtml(record.name)}</div>
                            <div class="text-xs text-gray-600 mt-0.5">Control#: ${escapeHtml(record.control_number)}</div>
                        </div>
                    `;
                });
                html += '</div>';
                resultsListDiv.innerHTML = html;
                resultsDiv.classList.remove('hidden');
            } else {
                let message = 'No matching records found';
                if (data.total_scanned > 0) {
                    message += ` (searched ${data.total_scanned} records)`;
                }
                if (data.decryption_errors > 0) {
                    message += ` - Warning: ${data.decryption_errors} record${data.decryption_errors > 1 ? 's' : ''} skipped due to decryption errors`;
                }
                resultsListDiv.innerHTML = `<div class="p-3 text-sm text-gray-500 text-center">${message}</div>`;
                resultsDiv.classList.remove('hidden');
            }
        } catch (error) {
            console.error('Error searching legacy control:', error);
            loadingDiv.classList.add('hidden');
            resultsListDiv.innerHTML = '<div class="p-3 text-sm text-red-500 text-center">Error searching records. Please try again.</div>';
            resultsDiv.classList.remove('hidden');
        }
    }, 300);
}

function selectLegacyControl(id, name, controlNumber) {
    // Set control number and hidden ID
    document.getElementById('control_search').value = controlNumber + ' - ' + name;
    document.querySelector('[name="control_number"]').value = controlNumber;
    document.getElementById('legacy_officer_id').value = id;
    
    // Hide results
    document.getElementById('control_results').classList.add('hidden');
    
    // Show success feedback
    const searchInput = document.getElementById('control_search');
    const originalBorder = searchInput.className;
    searchInput.className = searchInput.className.replace('border-gray-300', 'border-green-500');
    setTimeout(() => {
        searchInput.className = originalBorder;
    }, 1000);
}

// Close tarheta results when clicking outside
document.addEventListener('click', function(event) {
    const registrySearchInput = document.getElementById('registry_search');
    const tarhetaResultsDiv = document.getElementById('tarheta_results');
    const controlSearchInput = document.getElementById('control_search');
    const controlResultsDiv = document.getElementById('control_results');
    
    if (registrySearchInput && tarhetaResultsDiv && !registrySearchInput.contains(event.target) && !tarhetaResultsDiv.contains(event.target)) {
        tarhetaResultsDiv.classList.add('hidden');
    }
    
    if (controlSearchInput && controlResultsDiv && !controlSearchInput.contains(event.target) && !controlResultsDiv.contains(event.target)) {
        controlResultsDiv.classList.add('hidden');
    }
});
</script>

<!-- Local Modal -->
<div id="local-modal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity" onclick="closeLocalModal()"></div>
        <div class="relative bg-white rounded-lg shadow-xl max-w-md w-full max-h-[80vh] flex flex-col">
            <!-- Header -->
            <div class="flex items-center justify-between p-4 border-b">
                <h3 class="text-lg font-semibold text-gray-900">Select Local Congregation</h3>
                <button type="button" onclick="closeLocalModal()" class="text-gray-400 hover:text-gray-500">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            
            <!-- Search -->
            <div class="p-4 border-b">
                <input 
                    type="text" 
                    id="local-search"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
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
