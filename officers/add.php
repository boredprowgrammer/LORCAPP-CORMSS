<?php
require_once __DIR__ . '/../config/config.php';

Security::requireLogin();
requirePermission('can_add_officers');

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
    $hasExistingRecord = isset($_POST['has_existing_record']) && $_POST['has_existing_record'] === '1';
    $existingOfficerIdInput = Security::sanitizeInput($_POST['existing_officer_id'] ?? '');
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
        $department = Security::sanitizeInput($_POST['department'] ?? '');
        $duty = Security::sanitizeInput($_POST['duty'] ?? '');
        
        // Get separate date fields
        $oathMonth = Security::sanitizeInput($_POST['oath_month'] ?? '');
        $oathDay = Security::sanitizeInput($_POST['oath_day'] ?? '');
        $oathYear = Security::sanitizeInput($_POST['oath_year'] ?? '');
        
        // Build oath_date: use full date if MM/DD provided, otherwise just year
        if (!empty($oathMonth) && !empty($oathDay) && !empty($oathYear)) {
            // Full date format: YYYY-MM-DD
            $oathDate = $oathYear . '-' . str_pad($oathMonth, 2, '0', STR_PAD_LEFT) . '-' . str_pad($oathDay, 2, '0', STR_PAD_LEFT);
        } elseif (!empty($oathYear)) {
            // Only year provided - default to January 1st
            $oathDate = $oathYear . '-07-27';
        } else {
            $oathDate = '';
        }
        
        // Validation
        if ($hasExistingRecord && empty($existingOfficerIdInput)) {
            $error = 'Please select an existing officer for CODE D.';
        } elseif (!$hasExistingRecord && (empty($lastName) || empty($firstName))) {
            $error = 'First name and last name are required.';
        } elseif (empty($districtCode) || empty($localCode)) {
            $error = 'District and local congregation are required.';
        } elseif (empty($department) || empty($oathYear)) {
            $error = 'Department and oath year are required.';
        } elseif (!hasDistrictAccess($districtCode)) {
            $error = 'You do not have access to this district.';
        } elseif (!hasLocalAccess($localCode)) {
            $error = 'You do not have access to this local congregation.';
        } else {
            // Check if user is local_limited and needs approval
            require_once __DIR__ . '/../includes/pending-actions.php';
            
            if (shouldPendAction()) {
                // Create pending action instead of executing immediately
                $officerName = $hasExistingRecord ? 'Existing Officer' : $lastName . ', ' . $firstName;
                
                $actionData = [
                    'has_existing_record' => $hasExistingRecord,
                    'existing_officer_uuid' => $existingOfficerUuid,
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
                    'department' => $department,
                    'duty' => $duty,
                    'oath_month' => $oathMonth,
                    'oath_day' => $oathDay,
                    'oath_year' => $oathYear,
                    'oath_date' => $oathDate,
                ];
                
                $actionId = createPendingAddOfficer($actionData, $officerName);
                
                if ($actionId) {
                    $_SESSION['success'] = getPendingActionMessage('add officer request');
                    header('Location: ' . BASE_URL . '/officers/list.php');
                    exit;
                } else {
                    $error = 'Failed to submit action for approval. Please try again.';
                }
            } else {
                // Execute action normally for non-limited users
            try {
                $db->beginTransaction();
                
                // Auto-detect if officer already exists (regardless of checkbox)
                $existingOfficerId = null;
                $existingOfficerUuid = null;
                $autoDetectedExisting = false;
                
                if ($hasExistingRecord && !empty($existingOfficerIdInput)) {
                    // User explicitly selected existing officer (by ID)
                    $stmt = $db->prepare("SELECT officer_id, officer_uuid FROM officers WHERE officer_id = ?");
                    $stmt->execute([$existingOfficerIdInput]);
                    $officer = $stmt->fetch();
                    if (!$officer) {
                        throw new Exception('Selected officer not found.');
                    }
                    $existingOfficerId = $officer['officer_id'];
                    $existingOfficerUuid = $officer['officer_uuid'];
                    
                } elseif (!$hasExistingRecord && !empty($lastName) && !empty($firstName)) {
                    // Auto-detect: Search for existing officer with same name
                    $stmt = $db->prepare("
                        SELECT officer_id, officer_uuid, last_name_encrypted, first_name_encrypted, 
                               middle_initial_encrypted, district_code, is_active
                        FROM officers 
                        WHERE district_code = ?
                    ");
                    $stmt->execute([$districtCode]);
                    $allOfficers = $stmt->fetchAll();
                    
                    // Check each officer to find matching name
                    foreach ($allOfficers as $officer) {
                        try {
                            $decrypted = Encryption::decryptOfficerName(
                                $officer['last_name_encrypted'],
                                $officer['first_name_encrypted'],
                                $officer['middle_initial_encrypted'],
                                $officer['district_code']
                            );
                            
                            // Case-insensitive comparison
                            $lastNameMatch = strcasecmp(trim($decrypted['last_name']), trim($lastName)) === 0;
                            $firstNameMatch = strcasecmp(trim($decrypted['first_name']), trim($firstName)) === 0;
                            
                            // Check middle initial if provided
                            $middleInitialMatch = true;
                            if (!empty($middleInitial) && !empty($decrypted['middle_initial'])) {
                                $middleInitialMatch = strcasecmp(trim($decrypted['middle_initial']), trim($middleInitial)) === 0;
                            }
                            
                            if ($lastNameMatch && $firstNameMatch && $middleInitialMatch) {
                                // Found existing officer!
                                $existingOfficerId = $officer['officer_id'];
                                $existingOfficerUuid = $officer['officer_uuid'];
                                $autoDetectedExisting = true;
                                break;
                            }
                        } catch (Exception $e) {
                            // Skip if decryption fails
                            continue;
                        }
                    }
                }
                
                // Determine record code
                $recordCode = ($existingOfficerId !== null) ? 'D' : 'A';
                
                if ($existingOfficerId !== null) {
                    // CODE D: Use existing officer
                    $officerId = $existingOfficerId;
                    $officerUuid = $existingOfficerUuid;
                    
                    // Reactivate officer if inactive and update location
                    $stmt = $db->prepare("UPDATE officers SET is_active = 1, local_code = ?, district_code = ? WHERE officer_id = ?");
                    $stmt->execute([$localCode, $districtCode, $officerId]);
                    
                } else {
                    // CODE A: Create new officer
                    // Generate UUID for officer
                    $officerUuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                        mt_rand(0, 0xffff),
                        mt_rand(0, 0x0fff) | 0x4000,
                        mt_rand(0, 0x3fff) | 0x8000,
                        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
                    );
                    
                    // Encrypt officer name
                    $encrypted = Encryption::encryptOfficerName($lastName, $firstName, $middleInitial, $districtCode);
                    
                    // Encrypt registry number if provided
                    $registryNumberEnc = !empty($registryNumber) ? Encryption::encrypt($registryNumber, $districtCode) : null;
                    
                    // Insert officer
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
                            control_number_encrypted,
                            registry_number_encrypted,
                            tarheta_control_id,
                            legacy_officer_id,
                            record_code,
                            is_active,
                            created_by
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
                    ");
                    
                    // Encrypt control number if provided
                    $controlNumberEnc = !empty($controlNumber) ? Encryption::encrypt($controlNumber, $districtCode) : null;
                    
                    $stmt->execute([
                        $officerUuid,
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
                        $recordCode,
                        $currentUser['user_id']
                    ]);
                    
                    $officerId = $db->lastInsertId();
                    
                    // If linked to tarheta, update tarheta_control table
                    if ($tarhetaControlId) {
                        $stmt = $db->prepare("
                            UPDATE tarheta_control 
                            SET linked_officer_id = ?, linked_at = NOW(), linked_by = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([$officerId, $currentUser['user_id'], $tarhetaControlId]);
                    }
                    
                    // If linked to legacy officer, update legacy_officers table
                    if ($legacyOfficerId) {
                        $stmt = $db->prepare("
                            UPDATE legacy_officers 
                            SET linked_officer_id = ?, linked_at = NOW()
                            WHERE id = ?
                        ");
                        $stmt->execute([$officerId, $legacyOfficerId]);
                    }
                }
                
                // Add department - check if this department already exists for this officer
                $stmt = $db->prepare("
                    SELECT id FROM officer_departments 
                    WHERE officer_id = ? AND department = ? AND is_active = 1
                ");
                $stmt->execute([$officerId, $department]);
                $existingDept = $stmt->fetch();
                
                if ($existingDept) {
                    // Update existing department instead of creating new one
                    $stmt = $db->prepare("
                        UPDATE officer_departments 
                        SET duty = ?, oath_date = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$duty, $oathDate, $existingDept['id']]);
                } else {
                    // Add new department
                    $stmt = $db->prepare("
                        INSERT INTO officer_departments (officer_id, department, duty, oath_date, is_active)
                        VALUES (?, ?, ?, ?, 1)
                    ");
                    $stmt->execute([$officerId, $department, $duty, $oathDate]);
                }
                
                // Update headcount only if CODE A (new record)
                if ($recordCode === 'A') {
                    $stmt = $db->prepare("
                        INSERT INTO headcount (district_code, local_code, total_count)
                        VALUES (?, ?, 1)
                        ON DUPLICATE KEY UPDATE total_count = total_count + 1
                    ");
                    $stmt->execute([$districtCode, $localCode]);
                }
                
                // Log audit
                $stmt = $db->prepare("
                    INSERT INTO audit_log (user_id, action, table_name, record_id, new_values, ip_address, user_agent)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $currentUser['user_id'],
                    'add_officer',
                    'officers',
                    $officerId,
                    json_encode([
                        'record_code' => $recordCode,
                        'department' => $department,
                        'local_code' => $localCode
                    ]),
                    $_SERVER['REMOTE_ADDR'],
                    $_SERVER['HTTP_USER_AGENT'] ?? ''
                ]);
                
                $db->commit();
                
                // Success message based on whether auto-detection found existing officer
                if ($recordCode === 'D') {
                    if ($autoDetectedExisting) {
                        $success = 'Existing officer auto-detected! Officer reactivated with CODE D and assigned to new department.';
                    } else {
                        $success = 'Officer merged successfully with CODE D! Existing officer assigned to new department.';
                    }
                } else {
                    $success = 'Officer added successfully with CODE A (New Record)!';
                }
                
                // Reset form fields after successful submission
                $_POST = [];
                
            } catch (Exception $e) {
                $db->rollBack();
                error_log("Add officer error: " . $e->getMessage());
                $error = 'An error occurred while adding the officer.';
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

$pageTitle = 'Add Officer';
ob_start();
?>

<div class="max-w-4xl mx-auto space-y-6">
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <div class="flex items-center space-x-3 mb-6">
            <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path>
                </svg>
            </div>
            <h2 class="text-2xl font-semibold text-gray-900">Add New Officer</h2>
        </div>
        

        <?php if (!empty($success)): ?>
            <div class="mb-6 bg-green-50 border border-green-200 rounded-lg p-4">
                <div class="flex items-center">
                    <svg class="w-5 h-5 text-green-600 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                    </svg>
                    <span class="text-sm font-medium text-green-800"><?php echo Security::escape($success); ?></span>
                </div>
            </div>
        <?php endif; ?>

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
        
        <form method="POST" action="" class="space-y-6" x-data="{ 
            hasExisting: false,
            selectedOfficer: null,
            selectedOfficerId: null,
            searchQuery: '',
            searchResults: [],
            searching: false
        }">
            <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
            <input type="hidden" name="existing_officer_id" x-model="selectedOfficerId">
            
            <!-- Record Type -->
            <div class="flex items-start space-x-3 p-4 bg-gray-50 rounded-lg border border-gray-200">
                <input 
                    type="checkbox" 
                    id="has_existing_record"
                    name="has_existing_record" 
                    value="1" 
                    class="mt-1 h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
                    x-model="hasExisting"
                    @change="selectedOfficer = null; searchQuery = ''; searchResults = []"
                >
                <label for="has_existing_record" class="flex-1 cursor-pointer">
                    <span class="font-semibold text-gray-900 block">Officer has existing record?</span>
                    <p class="text-xs text-gray-600 mt-1">Check this if the officer has served before (CODE D)</p>
                </label>
            </div>
            
            <div class="rounded-lg p-4 border" :class="hasExisting ? 'bg-blue-50 border-blue-200' : 'bg-green-50 border-green-200'">
                <div class="flex items-start">
                    <svg class="w-5 h-5 mt-0.5 mr-3 flex-shrink-0" :class="hasExisting ? 'text-blue-600' : 'text-green-600'" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                    </svg>
                    <div class="flex-1">
                        <p class="text-sm font-medium" :class="hasExisting ? 'text-blue-800' : 'text-green-800'" x-text="hasExisting ? 'CODE D: Returned Officer' : 'CODE A: New Officer'"></p>
                        <p class="text-xs mt-1" :class="hasExisting ? 'text-blue-700' : 'text-green-700'" x-text="hasExisting ? 'Select an existing officer from the search below' : 'Enter new officer details below'"></p>
                    </div>
                </div>
            </div>
            
            <!-- Officer Search (CODE D) -->
            <div x-show="hasExisting" x-cloak class="space-y-4">
                <div class="border-t border-gray-200 my-6 pt-6">
                    <h3 class="text-sm font-medium text-gray-700 mb-4">Search Existing Officer</h3>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Search Officer <span class="text-red-600">*</span>
                    </label>
                    <div class="relative">
                        <input 
                            type="text" 
                            x-model="searchQuery"
                            @input.debounce.500ms="
                                if (searchQuery.length >= 2) {
                                    searching = true;
                                    fetch('<?php echo BASE_URL; ?>/api/search-officers.php?q=' + encodeURIComponent(searchQuery))
                                        .then(r => r.json())
                                        .then(data => {
                                            searchResults = data;
                                            searching = false;
                                        });
                                } else {
                                    searchResults = [];
                                }
                            "
                            placeholder="Type officer name to search..." 
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                            :class="{ 'border-green-500 bg-green-50': selectedOfficer }"
                        >
                        <span class="absolute right-3 top-3" x-show="searching">
                            <svg class="animate-spin h-5 w-5 text-blue-500" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                        </span>
                    </div>
                    
                    <!-- Search Results Dropdown -->
                    <div x-show="searchResults.length > 0 && !selectedOfficer" 
                         class="mt-2 bg-white rounded-lg shadow-lg border border-gray-200 max-h-64 overflow-y-auto">
                        <ul class="divide-y divide-gray-200">
                            <template x-for="officer in searchResults" :key="officer.id">
                                <li>
                                    <button type="button" @click="selectedOfficer = officer; selectedOfficerId = officer.id; searchQuery = officer.name; searchResults = []" class="w-full px-4 py-3 hover:bg-gray-50 text-left transition-colors">
                                        <div class="font-semibold text-gray-900 cursor-pointer name-mono" 
                                             :title="officer.full_name"
                                             @dblclick="$el.textContent = officer.full_name"
                                             x-text="officer.name"></div>
                                        <div class="text-xs text-gray-600 mt-1" x-text="officer.location"></div>
                                    </button>
                                </li>
                            </template>
                        </ul>
                    </div>
                    
                    <!-- Selected Officer Display -->
                    <div x-show="selectedOfficer" class="mt-2 bg-green-50 border border-green-200 rounded-lg p-4">
                        <div class="flex items-start justify-between">
                            <div class="flex items-start">
                                <svg class="w-5 h-5 text-green-600 mr-3 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                </svg>
                                <div>
                                    <div class="font-semibold text-gray-900 cursor-pointer name-mono" 
                                         :title="selectedOfficer?.full_name"
                                         @dblclick="$el.textContent = selectedOfficer?.full_name"
                                         x-text="selectedOfficer?.name"></div>
                                    <div class="text-xs text-gray-600 mt-1" x-text="selectedOfficer?.location"></div>
                                </div>
                            </div>
                            <button type="button" class="ml-4 text-gray-400 hover:text-gray-600 transition-colors" @click="selectedOfficer = null; searchQuery = ''">
                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>                <!-- Personal Information (CODE A only) -->
                <div x-show="!hasExisting" x-cloak>
                    <div class="border-t border-gray-200 my-6 pt-6">
                        <h3 class="text-sm font-medium text-gray-700 mb-4">Personal Information</h3>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="md:col-span-1">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Last Name <span class="text-red-600">*</span>
                            </label>
                            <div class="relative">
                                <input 
                                    type="text" 
                                    id="last_name"
                                    name="last_name" 
                                    placeholder="Last Name" 
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                    value="<?php echo Security::escape($_POST['last_name'] ?? ''); ?>"
                                    :required="!hasExisting"
                                >
                                <button 
                                    type="button"
                                    onclick="insertEnye('last_name')"
                                    class="absolute right-2 top-1/2 -translate-y-1/2 px-2 py-1 text-sm font-bold text-gray-600 hover:text-gray-900 hover:bg-gray-100 rounded"
                                    title="Insert ñ"
                                >ñ</button>
                            </div>
                        </div>
                        
                        <div class="md:col-span-1">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                First Name <span class="text-red-600">*</span>
                            </label>
                            <div class="relative">
                                <input 
                                    type="text" 
                                    id="first_name"
                                    name="first_name" 
                                    placeholder="First Name" 
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                    value="<?php echo Security::escape($_POST['first_name'] ?? ''); ?>"
                                    :required="!hasExisting"
                                >
                                <button 
                                    type="button"
                                    onclick="insertEnye('first_name')"
                                    class="absolute right-2 top-1/2 -translate-y-1/2 px-2 py-1 text-sm font-bold text-gray-600 hover:text-gray-900 hover:bg-gray-100 rounded"
                                    title="Insert ñ"
                                >ñ</button>
                            </div>
                        </div>
                        
                        <div class="md:col-span-1">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                M.I. (Optional)
                            </label>
                            <input 
                                type="text" 
                                name="middle_initial" 
                                placeholder="M.I." 
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                maxlength="2"
                                value="<?php echo Security::escape($_POST['middle_initial'] ?? ''); ?>"
                            >
                        </div>
                    </div>
                </div>
                
                <!-- Location Information -->
                <div class="border-t border-gray-200 my-6 pt-6">
                    <h3 class="text-sm font-medium text-gray-700 mb-4">Location Information</h3>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            District <span class="text-red-600">*</span>
                        </label>
                        <select 
                            name="district_code" 
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 <?php echo ($currentUser['role'] === 'local' || $currentUser['role'] === 'local_limited') ? 'bg-gray-100 cursor-not-allowed' : ''; ?>"
                            id="district-select"
                            required
                            <?php echo ($currentUser['role'] === 'local' || $currentUser['role'] === 'local_limited') ? 'disabled' : ''; ?>
                        >
                            <option value="">Select District</option>
                            <?php foreach ($districts as $district): ?>
                                <option value="<?php echo Security::escape($district['district_code']); ?>"
                                    <?php echo ($currentUser['district_code'] === $district['district_code']) ? 'selected' : ''; ?>>
                                    <?php echo Security::escape($district['district_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($currentUser['role'] === 'local' || $currentUser['role'] === 'local_limited'): ?>
                            <input type="hidden" name="district_code" value="<?php echo Security::escape($currentUser['district_code']); ?>">
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
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 cursor-pointer bg-white"
                                    placeholder="Select Local Congregation"
                                    readonly
                                    onclick="openLocalModal()"
                                    value=""
                                >
                                <input type="hidden" name="local_code" id="local-value" required>
                                <div class="absolute inset-y-0 right-0 flex items-center px-3 pointer-events-none">
                                    <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                    </svg>
                                </div>
                            </div>
                        <?php else: ?>
                            <input type="text" class="w-full px-4 py-2 border border-gray-300 rounded-lg bg-gray-100" 
                                   value="<?php echo Security::escape($locals[0]['local_name'] ?? ''); ?>" readonly>
                            <input type="hidden" name="local_code" value="<?php echo Security::escape($currentUser['local_code']); ?>">
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
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                            placeholder="Enter purok"
                            value="<?php echo Security::escape($_POST['purok'] ?? ''); ?>"
                        >
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Grupo <span class="text-gray-400 text-xs">(Optional)</span>
                        </label>
                        <input 
                            type="text" 
                            name="grupo" 
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                            placeholder="Enter grupo"
                            value="<?php echo Security::escape($_POST['grupo'] ?? ''); ?>"
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
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                placeholder="Search by name or control #..."
                                value="<?php echo Security::escape($_POST['control_number'] ?? ''); ?>"
                                autocomplete="off"
                                onkeyup="searchControl(this.value)"
                                onfocus="if(this.value.length >= 1) searchControl(this.value)"
                            >
                            <input type="hidden" name="legacy_officer_id" id="legacy_officer_id" value="<?php echo Security::escape($_POST['legacy_officer_id'] ?? ''); ?>">
                            
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
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Registry Number <span class="text-gray-400 text-xs">(Optional - Search Tarheta)</span>
                        </label>
                        <div class="relative">
                            <input 
                                type="text" 
                                id="registry_search"
                                name="registry_number" 
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                placeholder="Search by name or number..."
                                value="<?php echo Security::escape($_POST['registry_number'] ?? ''); ?>"
                                autocomplete="off"
                                onkeyup="searchTarheta(this.value)"
                            >
                            <input type="hidden" name="tarheta_control_id" id="tarheta_control_id" value="">
                            <!-- Search Results Dropdown -->
                            <div id="tarheta_results" class="hidden absolute z-10 w-full mt-1 bg-white border border-gray-300 rounded-lg shadow-lg max-h-60 overflow-y-auto"></div>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">Search for registry numbers or enter manually</p>
                    </div>
                </div>
                
                <!-- Department and Duty -->
                <div class="border-t border-gray-200 my-6 pt-6">
                    <h3 class="text-sm font-medium text-gray-700 mb-4">Officer Assignment</h3>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Department <span class="text-red-600">*</span>
                        </label>
                        <div class="relative">
                            <input 
                                type="text" 
                                id="department-display"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 cursor-pointer bg-white"
                                placeholder="Select Department"
                                readonly
                                onclick="openDepartmentModal()"
                                value="<?php echo Security::escape($_POST['department'] ?? ''); ?>"
                            >
                            <input type="hidden" name="department" id="department-value" value="<?php echo Security::escape($_POST['department'] ?? ''); ?>" required>
                            <div class="absolute inset-y-0 right-0 flex items-center px-3 pointer-events-none">
                                <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                </svg>
                            </div>
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Oath Date <span class="text-red-600">*</span>
                        </label>
                        <div class="grid grid-cols-3 gap-3">
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Month</label>
                                <select 
                                    name="oath_month" 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                                    <option value="">MM</option>
                                    <?php for($m = 1; $m <= 12; $m++): ?>
                                        <option value="<?php echo str_pad($m, 2, '0', STR_PAD_LEFT); ?>" 
                                            <?php echo (isset($_POST['oath_month']) && $_POST['oath_month'] == str_pad($m, 2, '0', STR_PAD_LEFT)) ? 'selected' : ''; ?>>
                                            <?php echo str_pad($m, 2, '0', STR_PAD_LEFT); ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Day</label>
                                <select 
                                    name="oath_day" 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                                    <option value="">DD</option>
                                    <?php for($d = 1; $d <= 31; $d++): ?>
                                        <option value="<?php echo str_pad($d, 2, '0', STR_PAD_LEFT); ?>" 
                                            <?php echo (isset($_POST['oath_day']) && $_POST['oath_day'] == str_pad($d, 2, '0', STR_PAD_LEFT)) ? 'selected' : ''; ?>>
                                            <?php echo str_pad($d, 2, '0', STR_PAD_LEFT); ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Year <span class="text-red-600">*</span></label>
                                <select 
                                    name="oath_year" 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                                    required>
                                    <option value="">YYYY</option>
                                    <?php 
                                    $currentYear = date('Y');
                                    for($y = $currentYear; $y >= 1950; $y--): 
                                    ?>
                                        <option value="<?php echo $y; ?>" 
                                            <?php echo (isset($_POST['oath_year']) && $_POST['oath_year'] == $y) ? 'selected' : ''; ?>>
                                            <?php echo $y; ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">Month and day are optional. If provided, full date will be saved; otherwise only year.</p>
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Duty/Specific Role
                    </label>
                    <textarea 
                        name="duty" 
                        placeholder="Describe the specific duty or role (optional)" 
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 h-24 resize-none"
                    ><?php echo Security::escape($_POST['duty'] ?? ''); ?></textarea>
                </div>
                
                <!-- Submit Button -->
                <div class="flex items-center justify-end space-x-3 pt-6 border-t border-gray-200">
                    <a href="<?php echo BASE_URL; ?>/officers/list.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                        Cancel
                    </a>
                    <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-lg font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"></path>
                        </svg>
                        Add Officer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Dynamic local congregation loading
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

let currentLocals = [];

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
        <div class="relative bg-white rounded-lg shadow-xl max-w-md w-full max-h-[80vh] flex flex-col">
            <!-- Header -->
            <div class="flex items-center justify-between p-4 border-b">
                <h3 class="text-lg font-semibold text-gray-900">Select Department</h3>
                <button type="button" onclick="closeDepartmentModal()" class="text-gray-400 hover:text-gray-500">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            
            <!-- Search -->
            <div class="p-4 border-b">
                <input 
                    type="text" 
                    id="department-search"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                    placeholder="Search departments..."
                    oninput="filterDepartments()"
                >
            </div>
            
            <!-- List -->
            <div class="overflow-y-auto flex-1">
                <?php foreach (getDepartments() as $dept): ?>
                    <div class="department-item px-4 py-3 hover:bg-gray-50 cursor-pointer border-b border-gray-100"
                         onclick="selectDepartment('<?php echo Security::escape($dept); ?>')">
                        <span class="text-gray-900"><?php echo Security::escape($dept); ?></span>
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

<script>
// Insert ñ (enye) at cursor position in input field
function insertEnye(fieldId) {
    const input = document.getElementById(fieldId);
    if (!input) return;
    
    const start = input.selectionStart;
    const end = input.selectionEnd;
    const text = input.value;
    
    // Insert ñ at cursor position
    input.value = text.substring(0, start) + 'ñ' + text.substring(end);
    
    // Move cursor after the inserted ñ
    input.selectionStart = input.selectionEnd = start + 1;
    
    // Focus back on input
    input.focus();
}

// Search Tarheta Control records
let tarhetaSearchTimeout;
function searchTarheta(search) {
    clearTimeout(tarhetaSearchTimeout);
    
    const resultsDiv = document.getElementById('tarheta_results');
    
    if (search.length < 1) {
        resultsDiv.classList.add('hidden');
        return;
    }
    
    tarhetaSearchTimeout = setTimeout(async () => {
        const districtCode = document.querySelector('[name="district_code"]').value;
        const localCode = document.querySelector('[name="local_code"]').value;
        
        if (!districtCode || !localCode) {
            resultsDiv.innerHTML = '<div class="p-3 text-sm text-gray-500">Please select district and local first</div>';
            resultsDiv.classList.remove('hidden');
            return;
        }
        
        try {
            const response = await fetch(
                `<?php echo BASE_URL; ?>/api/search-tarheta.php?search=${encodeURIComponent(search)}&district=${districtCode}&local=${localCode}`
            );
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
                    // Build additional info
                    let additionalInfo = [`Registry: ${escapeHtml(record.registry_number)}`];
                    if (record.husbands_surname && record.husbands_surname.trim() !== '') {
                        additionalInfo.push(`Husband: ${escapeHtml(record.husbands_surname)}`);
                    }
                    
                    html += `
                        <div class="px-3 py-2 hover:bg-blue-50 cursor-pointer border-b border-gray-100 last:border-0 transition-colors" 
                             onclick="selectTarheta(${record.id}, '${escapeHtml(record.full_name)}', '${escapeHtml(record.registry_number)}', '${escapeHtml(record.last_name)}', '${escapeHtml(record.first_name)}', '${escapeHtml(record.middle_name)}', '${escapeHtml(record.husbands_surname || '')}')">
                            <div class="text-sm font-medium text-gray-900">${escapeHtml(record.full_name)}</div>
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
                message += '. Try searching by last name, first name, registry number, or husband\'s surname.';
                resultsDiv.innerHTML = `<div class="p-3 text-sm text-gray-500 text-center">${message}</div>`;
                resultsDiv.classList.remove('hidden');
            }
        } catch (error) {
            console.error('Error searching tarheta:', error);
            resultsDiv.innerHTML = '<div class="p-3 text-sm text-red-500 text-center">Error searching records. Please try again.</div>';
            resultsDiv.classList.remove('hidden');
        }
    }, 300);
}

function selectTarheta(id, fullName, registryNumber, lastName, firstName, middleName, husbandsSurname) {
    // Set registry number and hidden ID
    document.getElementById('registry_search').value = registryNumber + ' - ' + fullName;
    document.querySelector('[name="registry_number"]').value = registryNumber;
    document.getElementById('tarheta_control_id').value = id;
    
    // Auto-fill name fields if empty
    const lastNameField = document.getElementById('last_name');
    const firstNameField = document.getElementById('first_name');
    const middleInitialField = document.querySelector('[name="middle_initial"]');
    
    if (lastNameField && !lastNameField.value) {
        lastNameField.value = lastName;
    }
    if (firstNameField && !firstNameField.value) {
        firstNameField.value = firstName;
    }
    if (middleInitialField && !middleInitialField.value && middleName) {
        middleInitialField.value = middleName.substring(0, 2);
    }
    
    // Hide results
    document.getElementById('tarheta_results').classList.add('hidden');
    
    // Show success feedback
    const searchInput = document.getElementById('registry_search');
    const originalBorder = searchInput.className;
    searchInput.className = searchInput.className.replace('border-gray-300', 'border-green-500');
    setTimeout(() => {
        searchInput.className = originalBorder;
    }, 1000);
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

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
    const registrySearchInput = document.getElementById('registry_search');
    const tarhetaResultsDiv = document.getElementById('tarheta_results');
    const controlSearchInput = document.getElementById('control_search');
    const controlResultsDiv = document.getElementById('control_results');
    
    if (registrySearchInput && tarhetaResultsDiv && !registrySearchInput.contains(e.target) && !tarhetaResultsDiv.contains(e.target)) {
        tarhetaResultsDiv.classList.add('hidden');
    }
    
    if (controlSearchInput && controlResultsDiv && !controlSearchInput.contains(e.target) && !controlResultsDiv.contains(e.target)) {
        controlResultsDiv.classList.add('hidden');
    }
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
