<?php
/**
 * Add PNK Youth
 * Add new youth to PNK Registry (Pagsamba ng Kabataan)
 */

require_once __DIR__ . '/config/config.php';

Security::requireLogin();

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

// Check if user has permission to add PNK records
$canAdd = false;
$needsApproval = false;

if ($currentUser['role'] === 'admin' || $currentUser['role'] === 'local') {
    $canAdd = true;
    $needsApproval = false;
} elseif ($currentUser['role'] === 'local_cfo' || $currentUser['role'] === 'local_limited') {
    // Check pnk_data_access for add permission
    $stmt = $db->prepare("
        SELECT can_add FROM pnk_data_access 
        WHERE user_id = ? AND is_active = 1 
        AND (expires_at IS NULL OR expires_at >= CURDATE())
    ");
    $stmt->execute([$currentUser['user_id']]);
    $dataAccess = $stmt->fetch();
    
    if ($dataAccess && $dataAccess['can_add'] == 1) {
        $canAdd = true;
        $needsApproval = true; // These users need pending action approval
    }
}

if (!$canAdd) {
    $_SESSION['error'] = "You do not have permission to add PNK records.";
    header('Location: ' . BASE_URL . '/pnk-registry.php');
    exit;
}

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!Security::validateCSRFToken($csrfToken, 'add_pnk')) {
        $error = 'Invalid security token.';
    } else {
        $districtCode = Security::sanitizeInput($_POST['district_code'] ?? '');
        $localCode = Security::sanitizeInput($_POST['local_code'] ?? '');
        
        // Youth information
        $firstName = Security::sanitizeInput($_POST['first_name'] ?? '');
        $middleName = Security::sanitizeInput($_POST['middle_name'] ?? '');
        $lastName = Security::sanitizeInput($_POST['last_name'] ?? '');
        $birthday = Security::sanitizeInput($_POST['birthday'] ?? '');
        $birthplace = Security::sanitizeInput($_POST['birthplace'] ?? '');
        $sex = Security::sanitizeInput($_POST['sex'] ?? '');
        $ageCategory = Security::sanitizeInput($_POST['age_category'] ?? 'preteen');
        
        // Parent information (simplified - just full names)
        $fatherName = Security::sanitizeInput($_POST['father_name'] ?? '');
        $motherName = Security::sanitizeInput($_POST['mother_name'] ?? '');
        $parentAddress = Security::sanitizeInput($_POST['parent_address'] ?? '');
        $parentContact = Security::sanitizeInput($_POST['parent_contact'] ?? '');
        
        // Registry information
        $registryNumber = Security::sanitizeInput($_POST['registry_number'] ?? '');
        $registrationDate = Security::sanitizeInput($_POST['registration_date'] ?? date('Y-m-d'));
        $dakoId = Security::sanitizeInput($_POST['dako_id'] ?? '');
        $purokGrupo = Security::sanitizeInput($_POST['purok_grupo'] ?? '');
        $notes = Security::sanitizeInput($_POST['notes'] ?? '');
        
        // Validation
        if (empty($districtCode) || empty($localCode)) {
            $error = 'District and local congregation are required.';
        } elseif (empty($firstName) || empty($lastName) || empty($registryNumber)) {
            $error = 'First name, last name, and registry number are required.';
        } elseif (empty($sex)) {
            $error = 'Sex is required.';
        } elseif (!in_array($ageCategory, ['preteen', 'teen', 'young_adult'])) {
            $error = 'Invalid age category.';
        } elseif (!hasDistrictAccess($districtCode) || !hasLocalAccess($localCode)) {
            $error = 'You do not have access to this district/local.';
        } else {
            try {
                $db->beginTransaction();
                
                // Check for duplicate registry number
                $registryNumberHash = hash('sha256', strtolower(trim($registryNumber)));
                $stmt = $db->prepare("SELECT id FROM pnk_registry WHERE registry_number_hash = ?");
                $stmt->execute([$registryNumberHash]);
                
                if ($stmt->fetch()) {
                    throw new Exception('This registry number already exists in the PNK database.');
                }
                
                // For users who need approval, save to pending_actions instead of direct insert
                if ($needsApproval) {
                    // Find senior approver (local account from same congregation)
                    $stmt = $db->prepare("
                        SELECT user_id FROM users 
                        WHERE role = 'local' AND local_code = ? AND is_active = 1 
                        LIMIT 1
                    ");
                    $stmt->execute([$currentUser['local_code']]);
                    $seniorApprover = $stmt->fetch();
                    
                    if (!$seniorApprover) {
                        throw new Exception('No senior approver (LORC/LCRC) found for your local congregation.');
                    }
                    
                    // Prepare action data (store raw data, encryption happens on approval)
                    $actionData = json_encode([
                        'first_name' => $firstName,
                        'middle_name' => $middleName,
                        'last_name' => $lastName,
                        'birthday' => $birthday,
                        'birthplace' => $birthplace,
                        'sex' => $sex,
                        'age_category' => $ageCategory,
                        'father_name' => $fatherName,
                        'mother_name' => $motherName,
                        'parent_address' => $parentAddress,
                        'parent_contact' => $parentContact,
                        'registry_number' => $registryNumber,
                        'registration_date' => $registrationDate,
                        'dako_id' => $dakoId,
                        'purok_grupo' => $purokGrupo,
                        'notes' => $notes,
                        'district_code' => $districtCode,
                        'local_code' => $localCode
                    ]);
                    
                    $actionDescription = "Add PNK member: $firstName $lastName (" . ucfirst($ageCategory) . ")";
                    
                    $stmt = $db->prepare("
                        INSERT INTO pending_actions (
                            requester_user_id, approver_user_id, action_type, action_data,
                            action_description, target_table, status, created_at, expires_at
                        ) VALUES (?, ?, 'add_pnk', ?, ?, 'pnk_registry', 'pending', NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY))
                    ");
                    $stmt->execute([
                        $currentUser['user_id'],
                        $seniorApprover['user_id'],
                        $actionData,
                        $actionDescription
                    ]);
                    
                    $db->commit();
                    
                    $success = 'PNK member submitted for LORC/LCRC review. You will be notified once approved.';
                    $_POST = [];
                    
                } else {
                    // Direct insert for admin/local users
                    // Encrypt data
                    $firstNameEnc = Encryption::encrypt($firstName, $districtCode);
                    $middleNameEnc = !empty($middleName) ? Encryption::encrypt($middleName, $districtCode) : null;
                    $lastNameEnc = Encryption::encrypt($lastName, $districtCode);
                    $birthdayEnc = !empty($birthday) ? Encryption::encrypt($birthday, $districtCode) : null;
                    $birthplaceEnc = !empty($birthplace) ? Encryption::encrypt($birthplace, $districtCode) : null;
                    
                    // Simplified parent names - store as combined format "Father Name / Mother Name"
                    $parentGuardian = '';
                    if (!empty($fatherName) || !empty($motherName)) {
                        $parentGuardian = trim($fatherName) . ' / ' . trim($motherName);
                    }
                    $parentGuardianEnc = !empty($parentGuardian) ? Encryption::encrypt($parentGuardian, $districtCode) : null;
                    
                    $parentAddressEnc = !empty($parentAddress) ? Encryption::encrypt($parentAddress, $districtCode) : null;
                    $parentContactEnc = !empty($parentContact) ? Encryption::encrypt($parentContact, $districtCode) : null;
                    
                    $registryNumberEnc = Encryption::encrypt($registryNumber, $districtCode);
                    
                    // Get Dako name if selected
                    $dakoEnc = null;
                    if (!empty($dakoId)) {
                        $stmt = $db->prepare("SELECT dako_name FROM pnk_dako WHERE id = ?");
                        $stmt->execute([$dakoId]);
                        $dakoRow = $stmt->fetch();
                        if ($dakoRow) {
                            $dakoEnc = Encryption::encrypt($dakoRow['dako_name'], $districtCode);
                        }
                    }
                    
                    // Insert record
                    $stmt = $db->prepare("
                        INSERT INTO pnk_registry (
                            district_code, local_code,
                            first_name_encrypted, middle_name_encrypted, last_name_encrypted,
                            birthday_encrypted, birthplace_encrypted, sex, pnk_category,
                            parent_guardian_encrypted,
                            address_encrypted, contact_number_encrypted,
                            registry_number, registry_number_hash,
                            registration_date, dako_encrypted,
                            purok_grupo, notes,
                            created_by, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    
                    $stmt->execute([
                        $districtCode, $localCode,
                        $firstNameEnc, $middleNameEnc, $lastNameEnc,
                        $birthdayEnc, $birthplaceEnc, $sex, ucfirst($ageCategory),
                        $parentGuardianEnc,
                        $parentAddressEnc, $parentContactEnc,
                        $registryNumberEnc, $registryNumberHash,
                        $registrationDate, $dakoEnc,
                        $purokGrupo, $notes,
                        $currentUser['user_id']
                    ]);
                    
                    $recordId = $db->lastInsertId();
                    
                    // Log activity
                    $stmt = $db->prepare("
                        INSERT INTO pnk_activity_log (pnk_record_id, user_id, action, details, ip_address, user_agent)
                        VALUES (?, ?, 'create', ?, ?, ?)
                    ");
                    $stmt->execute([
                        $recordId,
                        $currentUser['user_id'],
                        json_encode(['registry_number' => $registryNumber, 'age_category' => $ageCategory]),
                        $_SERVER['REMOTE_ADDR'] ?? null,
                        $_SERVER['HTTP_USER_AGENT'] ?? null
                    ]);
                    
                    $db->commit();
                    
                    $success = 'PNK youth record added successfully!';
                    
                    // Clear form
                    $_POST = [];
                }
                
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $error = 'Error adding PNK record: ' . $e->getMessage();
                error_log("Add PNK error: " . $e->getMessage());
            }
        }
    }
}

// Get districts for dropdown
$districts = [];
try {
    if ($currentUser['role'] === 'admin') {
        $stmt = $db->query("SELECT district_code, district_name FROM districts ORDER BY district_name");
    } else {
        $stmt = $db->prepare("SELECT district_code, district_name FROM districts WHERE district_code = ? ORDER BY district_name");
        $stmt->execute([$currentUser['district_code']]);
    }
    $districts = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Load districts error: " . $e->getMessage());
}

$csrfToken = Security::generateCSRFToken('add_pnk');

$pageTitle = 'Add PNK Youth';
ob_start();
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                    <span class="text-3xl">👥</span>
                    Add PNK Youth
                </h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Pagsamba ng Kabataan - Register youth participant</p>
            </div>
            <a href="pnk-registry.php" class="inline-flex items-center px-4 py-2 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                </svg>
                Back to Registry
            </a>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400 px-4 py-3 rounded-lg">
            <?php echo Security::escape($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-400 px-4 py-3 rounded-lg">
            <?php echo Security::escape($success); ?>
        </div>
    <?php endif; ?>

    <!-- Form -->
    <form method="POST" class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 space-y-6">
        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
        
        <!-- Location Section -->
        <div>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Location Information</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">District <span class="text-red-500">*</span></label>
                    <select name="district_code" id="districtCode" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="">Select District</option>
                        <?php foreach ($districts as $district): ?>
                            <option value="<?php echo Security::escape($district['district_code']); ?>"
                                    <?php echo (isset($_POST['district_code']) && $_POST['district_code'] == $district['district_code']) ? 'selected' : ''; ?>>
                                <?php echo Security::escape($district['district_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Local Congregation <span class="text-red-500">*</span></label>
                    <select name="local_code" id="localCode" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="">Select District First</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Youth Information -->
        <div>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Youth Information</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">First Name <span class="text-red-500">*</span></label>
                    <input type="text" name="first_name" required value="<?php echo Security::escape($_POST['first_name'] ?? ''); ?>"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Middle Name</label>
                    <input type="text" name="middle_name" value="<?php echo Security::escape($_POST['middle_name'] ?? ''); ?>"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Last Name <span class="text-red-500">*</span></label>
                    <input type="text" name="last_name" required value="<?php echo Security::escape($_POST['last_name'] ?? ''); ?>"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Birthday</label>
                    <input type="date" name="birthday" value="<?php echo Security::escape($_POST['birthday'] ?? ''); ?>"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Birthplace</label>
                    <input type="text" name="birthplace" value="<?php echo Security::escape($_POST['birthplace'] ?? ''); ?>"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Sex <span class="text-red-500">*</span></label>
                    <select name="sex" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="">Select</option>
                        <option value="Male" <?php echo (isset($_POST['sex']) && $_POST['sex'] == 'Male') ? 'selected' : ''; ?>>Male</option>
                        <option value="Female" <?php echo (isset($_POST['sex']) && $_POST['sex'] == 'Female') ? 'selected' : ''; ?>>Female</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Age Category <span class="text-red-500">*</span></label>
                    <select name="age_category" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="preteen" <?php echo (isset($_POST['age_category']) && $_POST['age_category'] == 'preteen') ? 'selected' : ''; ?>>Preteen (7-12)</option>
                        <option value="teen" <?php echo (isset($_POST['age_category']) && $_POST['age_category'] == 'teen') ? 'selected' : ''; ?>>Teen (13-17)</option>
                        <option value="young_adult" <?php echo (isset($_POST['age_category']) && $_POST['age_category'] == 'young_adult') ? 'selected' : ''; ?>>Young Adult (18-24)</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Parent Information -->
        <div>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Parent/Guardian Information</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Father's Name</label>
                    <input type="text" name="father_name" value="<?php echo Security::escape($_POST['father_name'] ?? ''); ?>"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500"
                           placeholder="Full name of father">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Mother's Name</label>
                    <input type="text" name="mother_name" value="<?php echo Security::escape($_POST['mother_name'] ?? ''); ?>"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500"
                           placeholder="Full name of mother">
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Address</label>
                    <textarea name="parent_address" rows="2"
                              class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500"><?php echo Security::escape($_POST['parent_address'] ?? ''); ?></textarea>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Contact Number</label>
                    <input type="text" name="parent_contact" value="<?php echo Security::escape($_POST['parent_contact'] ?? ''); ?>"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
            </div>
        </div>

        <!-- Registry Information -->
        <div>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Registry Information</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Registry Number <span class="text-red-500">*</span></label>
                    <input type="text" name="registry_number" required value="<?php echo Security::escape($_POST['registry_number'] ?? ''); ?>"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Registration Date</label>
                    <input type="date" name="registration_date" value="<?php echo Security::escape($_POST['registration_date'] ?? date('Y-m-d')); ?>"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Dako (Chapter/Group)</label>
                    <select name="dako_id" id="dakoSelect" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="">Select Local First</option>
                    </select>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Manage Dako in PNK Registry → Dako Manager tab</p>
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Purok/Grupo</label>
                    <input type="text" name="purok_grupo" value="<?php echo Security::escape($_POST['purok_grupo'] ?? ''); ?>"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Notes</label>
                    <textarea name="notes" rows="2"
                              class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500"><?php echo Security::escape($_POST['notes'] ?? ''); ?></textarea>
                </div>
            </div>
        </div>

        <!-- Submit Button -->
        <div class="flex justify-end gap-2 pt-4 border-t border-gray-200 dark:border-gray-700">
            <a href="pnk-registry.php" class="px-6 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors">
                Cancel
            </a>
            <button type="submit" class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors">
                <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                </svg>
                Add PNK Youth
            </button>
        </div>
    </form>
</div>

<script src="assets/js/district-local-selector.js"></script>
<script>
// Load Dako list when local is selected
document.getElementById('localCode')?.addEventListener('change', async function() {
    const localCode = this.value;
    const districtCode = document.getElementById('districtCode').value;
    const dakoSelect = document.getElementById('dakoSelect');
    
    if (!localCode || !districtCode) {
        dakoSelect.innerHTML = '<option value="">Select Local First</option>';
        return;
    }
    
    try {
        const response = await fetch(`api/get-dako-list.php?district_code=${districtCode}&local_code=${localCode}`);
        const data = await response.json();
        
        if (data.success) {
            dakoSelect.innerHTML = '<option value="">No Dako (Optional)</option>';
            data.dakos.forEach(dako => {
                dakoSelect.innerHTML += `<option value="${dako.id}">${dako.dako_name}</option>`;
            });
        }
    } catch (error) {
        console.error('Error loading Dako list:', error);
    }
});
</script><?php
$content = ob_get_clean();
require_once __DIR__ . '/includes/layout.php';
?>
