<?php
/**
 * Edit HDB Child
 * Edit child record in HDB Registry (Handog Di Bautisado)
 */

require_once __DIR__ . '/config/config.php';

Security::requireLogin();
requirePermission('can_add_officers');

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

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

// Check access
if (!hasDistrictAccess($record['district_code'])) {
    $_SESSION['error'] = 'You do not have access to edit this record.';
    header('Location: hdb-registry.php');
    exit;
}

// Decrypt current data
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
    
    $fatherName = !empty($record['father_name_encrypted']) 
        ? Encryption::decrypt($record['father_name_encrypted'], $districtCode) 
        : '';
    $motherName = !empty($record['mother_name_encrypted']) 
        ? Encryption::decrypt($record['mother_name_encrypted'], $districtCode) 
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

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!Security::validateCSRFToken($csrfToken, 'edit_hdb')) {
        $error = 'Invalid security token.';
    } else {
        // Child information
        $newChildFirstName = Security::sanitizeInput($_POST['child_first_name'] ?? '');
        $newChildMiddleName = Security::sanitizeInput($_POST['child_middle_name'] ?? '');
        $newChildLastName = Security::sanitizeInput($_POST['child_last_name'] ?? '');
        $newChildBirthday = Security::sanitizeInput($_POST['child_birthday'] ?? '');
        $newChildBirthplace = Security::sanitizeInput($_POST['child_birthplace'] ?? '');
        $newChildSex = Security::sanitizeInput($_POST['child_sex'] ?? '');
        
        // Parent information
        $newFatherName = Security::sanitizeInput($_POST['father_name'] ?? '');
        $newMotherName = Security::sanitizeInput($_POST['mother_name'] ?? '');
        $newParentAddress = Security::sanitizeInput($_POST['parent_address'] ?? '');
        $newParentContact = Security::sanitizeInput($_POST['parent_contact'] ?? '');
        
        // Registry information
        $newRegistryNumber = Security::sanitizeInput($_POST['registry_number'] ?? '');
        $newRegistrationDate = Security::sanitizeInput($_POST['registration_date'] ?? '');
        $newDedicationStatus = Security::sanitizeInput($_POST['dedication_status'] ?? '');
        $newDedicationDate = Security::sanitizeInput($_POST['dedication_date'] ?? '');
        $newBaptismDate = Security::sanitizeInput($_POST['baptism_date'] ?? '');
        $newPurokGrupo = Security::sanitizeInput($_POST['purok_grupo'] ?? '');
        $newNotes = Security::sanitizeInput($_POST['notes'] ?? '');
        
        // Validation
        if (empty($newChildFirstName) || empty($newChildLastName) || empty($newRegistryNumber)) {
            $error = 'Child first name, last name, and registry number are required.';
        } elseif (empty($newChildSex)) {
            $error = 'Child sex is required.';
        } else {
            try {
                $db->beginTransaction();
                
                // Check if registry number changed and if new number exists
                if ($newRegistryNumber !== $registryNumber) {
                    $registryNumberHash = hash('sha256', strtolower(trim($newRegistryNumber)));
                    $stmt = $db->prepare("SELECT id FROM hdb_registry WHERE registry_number_hash = ? AND id != ?");
                    $stmt->execute([$registryNumberHash, $recordId]);
                    
                    if ($stmt->fetch()) {
                        throw new Exception('This registry number already exists in the HDB database.');
                    }
                    $registryNumberEnc = Encryption::encrypt($newRegistryNumber, $districtCode);
                } else {
                    $registryNumberHash = hash('sha256', strtolower(trim($newRegistryNumber)));
                    $registryNumberEnc = $record['registry_number'];
                }
                
                // Encrypt data
                $childFirstNameEnc = Encryption::encrypt($newChildFirstName, $districtCode);
                $childMiddleNameEnc = !empty($newChildMiddleName) ? Encryption::encrypt($newChildMiddleName, $districtCode) : null;
                $childLastNameEnc = Encryption::encrypt($newChildLastName, $districtCode);
                $childBirthdayEnc = !empty($newChildBirthday) ? Encryption::encrypt($newChildBirthday, $districtCode) : null;
                $childBirthplaceEnc = !empty($newChildBirthplace) ? Encryption::encrypt($newChildBirthplace, $districtCode) : null;
                
                $fatherNameEnc = !empty($newFatherName) ? Encryption::encrypt($newFatherName, $districtCode) : null;
                $motherNameEnc = !empty($newMotherName) ? Encryption::encrypt($newMotherName, $districtCode) : null;
                $parentAddressEnc = !empty($newParentAddress) ? Encryption::encrypt($newParentAddress, $districtCode) : null;
                $parentContactEnc = !empty($newParentContact) ? Encryption::encrypt($newParentContact, $districtCode) : null;
                
                // Update record
                $stmt = $db->prepare("
                    UPDATE hdb_registry SET
                        child_first_name_encrypted = ?,
                        child_middle_name_encrypted = ?,
                        child_last_name_encrypted = ?,
                        child_birthday_encrypted = ?,
                        child_birthplace_encrypted = ?,
                        child_sex = ?,
                        father_name_encrypted = ?,
                        mother_name_encrypted = ?,
                        parent_address_encrypted = ?,
                        parent_contact_encrypted = ?,
                        registry_number = ?,
                        registry_number_hash = ?,
                        registration_date = ?,
                        dedication_status = ?,
                        dedication_date = ?,
                        baptism_date = ?,
                        purok_grupo = ?,
                        notes = ?,
                        updated_by = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                
                $stmt->execute([
                    $childFirstNameEnc, $childMiddleNameEnc, $childLastNameEnc,
                    $childBirthdayEnc, $childBirthplaceEnc, $newChildSex,
                    $fatherNameEnc, $motherNameEnc,
                    $parentAddressEnc, $parentContactEnc,
                    $registryNumberEnc, $registryNumberHash,
                    $newRegistrationDate, $newDedicationStatus,
                    $newDedicationDate ?: null, $newBaptismDate ?: null,
                    $newPurokGrupo, $newNotes,
                    $currentUser['user_id'],
                    $recordId
                ]);
                
                // Log activity
                $stmt = $db->prepare("
                    INSERT INTO hdb_activity_log (hdb_record_id, user_id, action, details, ip_address, user_agent)
                    VALUES (?, ?, 'update', ?, ?, ?)
                ");
                $stmt->execute([
                    $recordId,
                    $currentUser['user_id'],
                    json_encode(['registry_number' => $newRegistryNumber]),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null
                ]);
                
                $db->commit();
                
                $_SESSION['success'] = 'HDB child record updated successfully!';
                header('Location: hdb-view.php?id=' . $recordId);
                exit;
                
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $error = 'Error updating HDB record: ' . $e->getMessage();
                error_log("Update HDB error: " . $e->getMessage());
            }
        }
    }
}

$csrfToken = Security::generateCSRFToken('edit_hdb');

$pageTitle = 'Edit HDB Child - ' . $childFirstName . ' ' . $childLastName;
ob_start();
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900 dark:text-gray-100">Edit HDB Child</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Update child record in HDB Registry</p>
            </div>
            <a href="hdb-view.php?id=<?php echo $recordId; ?>" class="inline-flex items-center px-4 py-2 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                </svg>
                Back to View
            </a>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400 px-4 py-3 rounded-lg">
            <?php echo Security::escape($error); ?>
        </div>
    <?php endif; ?>

    <!-- Form -->
    <form method="POST" class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 space-y-6">
        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
        
        <!-- Child Information -->
        <div>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Child Information</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">First Name <span class="text-red-500">*</span></label>
                    <input type="text" name="child_first_name" required value="<?php echo Security::escape($childFirstName); ?>"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Middle Name</label>
                    <input type="text" name="child_middle_name" value="<?php echo Security::escape($childMiddleName); ?>"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Last Name <span class="text-red-500">*</span></label>
                    <input type="text" name="child_last_name" required value="<?php echo Security::escape($childLastName); ?>"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Birthday</label>
                    <input type="date" name="child_birthday" value="<?php echo Security::escape($childBirthday); ?>"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Birthplace</label>
                    <input type="text" name="child_birthplace" value="<?php echo Security::escape($childBirthplace); ?>"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Sex <span class="text-red-500">*</span></label>
                    <select name="child_sex" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="">Select</option>
                        <option value="Male" <?php echo ($record['child_sex'] == 'Male') ? 'selected' : ''; ?>>Male</option>
                        <option value="Female" <?php echo ($record['child_sex'] == 'Female') ? 'selected' : ''; ?>>Female</option>
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
                    <input type="text" name="father_name" value="<?php echo Security::escape($fatherName); ?>"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Mother's Name</label>
                    <input type="text" name="mother_name" value="<?php echo Security::escape($motherName); ?>"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Address</label>
                    <textarea name="parent_address" rows="2"
                              class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500"><?php echo Security::escape($parentAddress); ?></textarea>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Contact Number</label>
                    <input type="text" name="parent_contact" value="<?php echo Security::escape($parentContact); ?>"
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
                    <input type="text" name="registry_number" required value="<?php echo Security::escape($registryNumber); ?>"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Registration Date</label>
                    <input type="date" name="registration_date" value="<?php echo Security::escape($record['registration_date']); ?>"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Status</label>
                    <select name="dedication_status" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="active" <?php echo ($record['dedication_status'] == 'active' || $record['dedication_status'] == 'pending' || $record['dedication_status'] == 'dedicated') ? 'selected' : ''; ?>>Active</option>
                        <option value="pnk" <?php echo ($record['dedication_status'] == 'pnk') ? 'selected' : ''; ?>>PNK</option>
                        <option value="baptized" <?php echo ($record['dedication_status'] == 'baptized') ? 'selected' : ''; ?>>Baptized</option>
                        <option value="transferred-out" <?php echo ($record['dedication_status'] == 'transferred-out') ? 'selected' : ''; ?>>Transferred Out</option>
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Dedication Date</label>
                    <input type="date" name="dedication_date" value="<?php echo Security::escape($record['dedication_date'] ?? ''); ?>"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Baptism Date</label>
                    <input type="date" name="baptism_date" value="<?php echo Security::escape($record['baptism_date'] ?? ''); ?>"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Purok/Grupo</label>
                    <input type="text" name="purok_grupo" value="<?php echo Security::escape($record['purok_grupo'] ?? ''); ?>"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Notes</label>
                    <textarea name="notes" rows="2"
                              class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500"><?php echo Security::escape($record['notes'] ?? ''); ?></textarea>
                </div>
            </div>
        </div>

        <!-- Submit Button -->
        <div class="flex justify-end gap-2 pt-4 border-t border-gray-200 dark:border-gray-700">
            <a href="hdb-view.php?id=<?php echo $recordId; ?>" class="px-6 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors">
                Cancel
            </a>
            <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
                Update Record
            </button>
        </div>
    </form>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/includes/layout.php';
?>
