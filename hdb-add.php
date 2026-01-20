<?php
/**
 * Add HDB Child
 * Add new child to HDB Registry (Handog Di Bautisado)
 */

require_once __DIR__ . '/config/config.php';

Security::requireLogin();
requirePermission('can_add_officers');

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!Security::validateCSRFToken($csrfToken, 'add_hdb')) {
        $error = 'Invalid security token.';
    } else {
        $districtCode = Security::sanitizeInput($_POST['district_code'] ?? '');
        $localCode = Security::sanitizeInput($_POST['local_code'] ?? '');
        
        // Child information
        $childFirstName = Security::sanitizeInput($_POST['child_first_name'] ?? '');
        $childMiddleName = Security::sanitizeInput($_POST['child_middle_name'] ?? '');
        $childLastName = Security::sanitizeInput($_POST['child_last_name'] ?? '');
        $childBirthday = Security::sanitizeInput($_POST['child_birthday'] ?? '');
        $childBirthplace = Security::sanitizeInput($_POST['child_birthplace'] ?? '');
        $childSex = Security::sanitizeInput($_POST['child_sex'] ?? '');
        
        // Parent information
        $fatherName = Security::sanitizeInput($_POST['father_name'] ?? '');
        $motherName = Security::sanitizeInput($_POST['mother_name'] ?? '');
        $parentAddress = Security::sanitizeInput($_POST['parent_address'] ?? '');
        $parentContact = Security::sanitizeInput($_POST['parent_contact'] ?? '');
        
        // Registry information
        $registryNumber = Security::sanitizeInput($_POST['registry_number'] ?? '');
        $registrationDate = Security::sanitizeInput($_POST['registration_date'] ?? date('Y-m-d'));
        $dedicationStatus = 'active'; // All new HDB entries are active
        $purokGrupo = Security::sanitizeInput($_POST['purok_grupo'] ?? '');
        $notes = Security::sanitizeInput($_POST['notes'] ?? '');
        
        // Validation
        if (empty($districtCode) || empty($localCode)) {
            $error = 'District and local congregation are required.';
        } elseif (empty($childFirstName) || empty($childLastName) || empty($registryNumber)) {
            $error = 'Child first name, last name, and registry number are required.';
        } elseif (empty($childSex)) {
            $error = 'Child sex is required.';
        } elseif (!hasDistrictAccess($districtCode) || !hasLocalAccess($localCode)) {
            $error = 'You do not have access to this district/local.';
        } else {
            try {
                $db->beginTransaction();
                
                // Check for duplicate registry number
                $registryNumberHash = hash('sha256', strtolower(trim($registryNumber)));
                $stmt = $db->prepare("SELECT id FROM hdb_registry WHERE registry_number_hash = ?");
                $stmt->execute([$registryNumberHash]);
                
                if ($stmt->fetch()) {
                    throw new Exception('This registry number already exists in the HDB database.');
                }
                
                // Encrypt data
                $childFirstNameEnc = Encryption::encrypt($childFirstName, $districtCode);
                $childMiddleNameEnc = !empty($childMiddleName) ? Encryption::encrypt($childMiddleName, $districtCode) : null;
                $childLastNameEnc = Encryption::encrypt($childLastName, $districtCode);
                $childBirthdayEnc = !empty($childBirthday) ? Encryption::encrypt($childBirthday, $districtCode) : null;
                $childBirthplaceEnc = !empty($childBirthplace) ? Encryption::encrypt($childBirthplace, $districtCode) : null;
                
                $fatherNameEnc = !empty($fatherName) ? Encryption::encrypt($fatherName, $districtCode) : null;
                $motherNameEnc = !empty($motherName) ? Encryption::encrypt($motherName, $districtCode) : null;
                $parentAddressEnc = !empty($parentAddress) ? Encryption::encrypt($parentAddress, $districtCode) : null;
                $parentContactEnc = !empty($parentContact) ? Encryption::encrypt($parentContact, $districtCode) : null;
                
                $registryNumberEnc = Encryption::encrypt($registryNumber, $districtCode);
                
                // Insert record
                $stmt = $db->prepare("
                    INSERT INTO hdb_registry (
                        district_code, local_code,
                        child_first_name_encrypted, child_middle_name_encrypted, child_last_name_encrypted,
                        child_birthday_encrypted, child_birthplace_encrypted, child_sex,
                        father_name_encrypted, mother_name_encrypted,
                        parent_address_encrypted, parent_contact_encrypted,
                        registry_number, registry_number_hash,
                        registration_date, dedication_status,
                        purok_grupo, notes,
                        created_by, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                
                $stmt->execute([
                    $districtCode, $localCode,
                    $childFirstNameEnc, $childMiddleNameEnc, $childLastNameEnc,
                    $childBirthdayEnc, $childBirthplaceEnc, $childSex,
                    $fatherNameEnc, $motherNameEnc,
                    $parentAddressEnc, $parentContactEnc,
                    $registryNumberEnc, $registryNumberHash,
                    $registrationDate, $dedicationStatus,
                    $purokGrupo, $notes,
                    $currentUser['user_id']
                ]);
                
                $recordId = $db->lastInsertId();
                
                // Log activity
                $stmt = $db->prepare("
                    INSERT INTO hdb_activity_log (hdb_record_id, user_id, action, details, ip_address, user_agent)
                    VALUES (?, ?, 'create', ?, ?, ?)
                ");
                $stmt->execute([
                    $recordId,
                    $currentUser['user_id'],
                    json_encode(['registry_number' => $registryNumber]),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null
                ]);
                
                $db->commit();
                
                $success = 'HDB child record added successfully!';
                
                // Clear form
                $_POST = [];
                
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $error = 'Error adding HDB record: ' . $e->getMessage();
                error_log("Add HDB error: " . $e->getMessage());
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

$csrfToken = Security::generateCSRFToken('add_hdb');

$pageTitle = 'Add HDB Child';
ob_start();
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900 dark:text-gray-100">Add HDB Child</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Handog Di Bautisado - Register unbaptized child</p>
            </div>
            <a href="hdb-registry.php" class="inline-flex items-center px-4 py-2 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors">
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

        <!-- Child Information -->
        <div>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Child Information</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">First Name <span class="text-red-500">*</span></label>
                    <input type="text" name="child_first_name" required value="<?php echo Security::escape($_POST['child_first_name'] ?? ''); ?>"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Middle Name</label>
                    <input type="text" name="child_middle_name" value="<?php echo Security::escape($_POST['child_middle_name'] ?? ''); ?>"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Last Name <span class="text-red-500">*</span></label>
                    <input type="text" name="child_last_name" required value="<?php echo Security::escape($_POST['child_last_name'] ?? ''); ?>"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Birthday</label>
                    <input type="date" name="child_birthday" value="<?php echo Security::escape($_POST['child_birthday'] ?? ''); ?>"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Birthplace</label>
                    <input type="text" name="child_birthplace" value="<?php echo Security::escape($_POST['child_birthplace'] ?? ''); ?>"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Sex <span class="text-red-500">*</span></label>
                    <select name="child_sex" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="">Select</option>
                        <option value="Male" <?php echo (isset($_POST['child_sex']) && $_POST['child_sex'] == 'Male') ? 'selected' : ''; ?>>Male</option>
                        <option value="Female" <?php echo (isset($_POST['child_sex']) && $_POST['child_sex'] == 'Female') ? 'selected' : ''; ?>>Female</option>
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
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Mother's Name</label>
                    <input type="text" name="mother_name" value="<?php echo Security::escape($_POST['mother_name'] ?? ''); ?>"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500">
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
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Status</label>
                    <select name="dedication_status" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="pending" selected>Pending</option>
                        <option value="dedicated">Dedicated</option>
                        <option value="baptized">Baptized</option>
                        <option value="transferred-out">Transferred Out</option>
                    </select>
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
            <a href="hdb-registry.php" class="px-6 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors">
                Cancel
            </a>
            <button type="submit" class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors">
                <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                </svg>
                Add HDB Child
            </button>
        </div>
    </form>
</div>

<script src="assets/js/district-local-selector.js"></script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/includes/layout.php';
?>
