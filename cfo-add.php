<?php
/**
 * Add CFO Member
 * Add new Tarheta Control entry with CFO classification
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
    
    if (!Security::validateCSRFToken($csrfToken, 'add_cfo')) {
        $error = 'Invalid security token.';
    } else {
        $districtCode = Security::sanitizeInput($_POST['district_code'] ?? '');
        $localCode = Security::sanitizeInput($_POST['local_code'] ?? '');
        $lastName = Security::sanitizeInput($_POST['last_name'] ?? '');
        $firstName = Security::sanitizeInput($_POST['first_name'] ?? '');
        $middleName = Security::sanitizeInput($_POST['middle_name'] ?? '');
        $husbandsSurname = Security::sanitizeInput($_POST['husbands_surname'] ?? '');
        $registryNumber = Security::sanitizeInput($_POST['registry_number'] ?? '');
        $birthday = Security::sanitizeInput($_POST['birthday'] ?? '');
        $cfoClassification = Security::sanitizeInput($_POST['cfo_classification'] ?? '');
        $cfoStatus = Security::sanitizeInput($_POST['cfo_status'] ?? 'active');
        $cfoNotes = Security::sanitizeInput($_POST['cfo_notes'] ?? '');
        
        // Validation
        if (empty($districtCode) || empty($localCode)) {
            $error = 'District and local congregation are required.';
        } elseif (empty($lastName) || empty($firstName) || empty($registryNumber)) {
            $error = 'Last name, first name, and registry number are required.';
        } elseif (!hasDistrictAccess($districtCode) || !hasLocalAccess($localCode)) {
            $error = 'You do not have access to this district/local.';
        } else {
            try {
                $db->beginTransaction();
                
                // Check for duplicate registry number
                $registryNumberHash = hash('sha256', strtolower(trim($registryNumber)));
                $stmt = $db->prepare("SELECT id FROM tarheta_control WHERE registry_number_hash = ?");
                $stmt->execute([$registryNumberHash]);
                
                if ($stmt->fetch()) {
                    throw new Exception('This registry number already exists in the database.');
                }
                
                // Encrypt data
                $lastNameEnc = Encryption::encrypt($lastName, $districtCode);
                $firstNameEnc = Encryption::encrypt($firstName, $districtCode);
                $middleNameEnc = !empty($middleName) ? Encryption::encrypt($middleName, $districtCode) : null;
                $husbandsSurnameEnc = !empty($husbandsSurname) ? Encryption::encrypt($husbandsSurname, $districtCode) : null;
                $registryNumberEnc = Encryption::encrypt($registryNumber, $districtCode);
                
                // Encrypt birthday if provided
                $birthdayEnc = null;
                if (!empty($birthday)) {
                    try {
                        $birthdayDate = new DateTime($birthday);
                        $birthdayEnc = Encryption::encrypt($birthdayDate->format('Y-m-d'), $districtCode);
                    } catch (Exception $e) {
                        throw new Exception('Invalid birthday format. Please use YYYY-MM-DD format.');
                    }
                }
                
                // Auto-classify if not manually set
                $cfoClassificationAuto = false;
                $age = null;
                
                // Calculate age if birthday provided
                if (!empty($birthday)) {
                    try {
                        $birthdayDate = new DateTime($birthday);
                        $today = new DateTime();
                        $age = $today->diff($birthdayDate)->y;
                    } catch (Exception $e) {
                        // Age remains null if birthday is invalid
                    }
                }
                
                if (empty($cfoClassification)) {
                    // Classification requires birthday
                    if (empty($birthday)) {
                        // No birthday = unclassified
                        $cfoClassification = null;
                        $cfoClassificationAuto = false;
                    } else {
                        // Priority: Married (Buklod) > Age-based (Kadiwa/Binhi)
                        // ONLY classify as Buklod if husband's surname is available (not empty, not "-")
                        if (!empty($husbandsSurname) && trim($husbandsSurname) !== '' && trim($husbandsSurname) !== '-') {
                            $cfoClassification = 'Buklod';
                            $cfoClassificationAuto = true;
                        } elseif ($age !== null) {
                            // Age-based classification (only if NOT married)
                            if ($age >= 18) {
                                $cfoClassification = 'Kadiwa'; // Youth (18+)
                                $cfoClassificationAuto = true;
                            } else {
                                $cfoClassification = 'Binhi'; // Children (under 18)
                                $cfoClassificationAuto = true;
                            }
                        }
                    }
                }
                
                // Create search index values
                $searchName = trim($firstName . ' ' . $middleName . ' ' . $lastName);
                $searchRegistry = $registryNumber;
                
                // Insert record
                $stmt = $db->prepare("
                    INSERT INTO tarheta_control (
                        last_name_encrypted, first_name_encrypted, middle_name_encrypted,
                        husbands_surname_encrypted, registry_number_encrypted, registry_number_hash,
                        district_code, local_code, birthday_encrypted,
                        cfo_classification, cfo_classification_auto, cfo_status, cfo_notes,
                        search_name, search_registry,
                        imported_by, imported_at, cfo_updated_by, cfo_updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, NOW())
                ");
                
                $stmt->execute([
                    $lastNameEnc,
                    $firstNameEnc,
                    $middleNameEnc,
                    $husbandsSurnameEnc,
                    $registryNumberEnc,
                    $registryNumberHash,
                    $districtCode,
                    $localCode,
                    $birthdayEnc,
                    empty($cfoClassification) ? null : $cfoClassification,
                    $cfoClassificationAuto ? 1 : 0,
                    $cfoStatus,
                    $cfoNotes,
                    $searchName,
                    $searchRegistry,
                    $currentUser['user_id'],
                    $currentUser['user_id']
                ]);
                
                $db->commit();
                
                $success = 'CFO member added successfully!';
                
                // Clear form
                $_POST = [];
                
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $error = 'Error adding CFO member: ' . $e->getMessage();
                error_log("Add CFO error: " . $e->getMessage());
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

$pageTitle = 'Add CFO Member';
ob_start();
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900">Add CFO Member</h1>
                <p class="text-sm text-gray-500 mt-1">Add new member to CFO Registry</p>
            </div>
            <a href="cfo-registry.php" class="inline-flex items-center px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                </svg>
                Back to CFO Registry
            </a>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
            <?php echo Security::escape($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg">
            <?php echo Security::escape($success); ?>
        </div>
    <?php endif; ?>

    <!-- Add Form -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <form method="POST" class="space-y-6">
            <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken('add_cfo'); ?>">
            
            <!-- Location Information -->
            <div>
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Location Information</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            District <span class="text-red-600">*</span>
                        </label>
                        <?php if ($currentUser['role'] === 'district' || $currentUser['role'] === 'local'): ?>
                            <!-- District/Local users: show readonly field -->
                            <input type="text" value="<?php 
                                foreach ($districts as $d) {
                                    if ($d['district_code'] === $currentUser['district_code']) {
                                        echo Security::escape($d['district_name']);
                                        break;
                                    }
                                }
                            ?>" readonly class="w-full px-4 py-2 bg-gray-100 border border-gray-300 rounded-lg text-gray-700">
                            <input type="hidden" name="district_code" id="district_code" value="<?php echo Security::escape($currentUser['district_code']); ?>">
                        <?php else: ?>
                            <!-- Admin: show dropdown -->
                            <select name="district_code" id="district_code" required onchange="loadLocals(this.value)" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                <option value="">Select District</option>
                                <?php foreach ($districts as $district): ?>
                                    <option value="<?php echo Security::escape($district['district_code']); ?>" 
                                        <?php echo (isset($_POST['district_code']) && $_POST['district_code'] === $district['district_code']) ? 'selected' : ''; ?>>
                                        <?php echo Security::escape($district['district_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Local Congregation <span class="text-red-600">*</span>
                        </label>
                        <?php if ($currentUser['role'] === 'local'): ?>
                            <!-- Local users: show readonly field -->
                            <?php
                            $localName = '';
                            try {
                                $stmt = $db->prepare("SELECT local_name FROM local_congregations WHERE local_code = ?");
                                $stmt->execute([$currentUser['local_code']]);
                                $localRow = $stmt->fetch();
                                $localName = $localRow ? $localRow['local_name'] : '';
                            } catch (Exception $e) {}
                            ?>
                            <input type="text" value="<?php echo Security::escape($localName); ?>" readonly class="w-full px-4 py-2 bg-gray-100 border border-gray-300 rounded-lg text-gray-700">
                            <input type="hidden" name="local_code" id="local_code" value="<?php echo Security::escape($currentUser['local_code']); ?>">
                        <?php else: ?>
                            <!-- Admin/District: show dropdown -->
                            <select name="local_code" id="local_code" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                <option value="">Select District First</option>
                            </select>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Personal Information -->
            <div>
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Personal Information</h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Last Name <span class="text-red-600">*</span>
                        </label>
                        <input type="text" name="last_name" required maxlength="100" 
                            value="<?php echo Security::escape($_POST['last_name'] ?? ''); ?>"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            First Name <span class="text-red-600">*</span>
                        </label>
                        <input type="text" name="first_name" required maxlength="100" 
                            value="<?php echo Security::escape($_POST['first_name'] ?? ''); ?>"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Middle Name <span class="text-gray-400">(Optional)</span>
                        </label>
                        <input type="text" name="middle_name" maxlength="100" 
                            value="<?php echo Security::escape($_POST['middle_name'] ?? ''); ?>"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mt-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Husband's Surname <span class="text-gray-400">(Optional)</span>
                        </label>
                        <input type="text" name="husbands_surname" id="husbands_surname" maxlength="100" 
                            value="<?php echo Security::escape($_POST['husbands_surname'] ?? ''); ?>"
                            onchange="autoClassify()"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <p class="text-xs text-gray-500 mt-1">💑 If provided → Auto-classifies as <strong>Buklod</strong> (married)</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Birthday <span class="text-gray-400">(Optional)</span>
                        </label>
                        <input type="date" name="birthday" 
                            value="<?php echo Security::escape($_POST['birthday'] ?? ''); ?>"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Registry Number <span class="text-red-600">*</span>
                        </label>
                        <input type="text" name="registry_number" required maxlength="100" 
                            value="<?php echo Security::escape($_POST['registry_number'] ?? ''); ?>"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                </div>
            </div>

            <!-- CFO Information -->
            <div>
                <h3 class="text-lg font-semibold text-gray-900 mb-4">CFO Information</h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            CFO Classification
                        </label>
                        <select name="cfo_classification" id="cfo_classification" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="">-- Auto-classify --</option>
                            <option value="Buklod" <?php echo (isset($_POST['cfo_classification']) && $_POST['cfo_classification'] === 'Buklod') ? 'selected' : ''; ?>>Buklod (Married Couples)</option>
                            <option value="Kadiwa" <?php echo (isset($_POST['cfo_classification']) && $_POST['cfo_classification'] === 'Kadiwa') ? 'selected' : ''; ?>>Kadiwa (Youth)</option>
                            <option value="Binhi" <?php echo (isset($_POST['cfo_classification']) && $_POST['cfo_classification'] === 'Binhi') ? 'selected' : ''; ?>>Binhi (Children)</option>
                        </select>
                        <p class="text-xs text-gray-500 mt-1" id="auto-classify-msg"></p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Status <span class="text-red-600">*</span>
                        </label>
                        <select name="cfo_status" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="active" <?php echo (!isset($_POST['cfo_status']) || $_POST['cfo_status'] === 'active') ? 'selected' : ''; ?>>Active</option>
                            <option value="transferred-out" <?php echo (isset($_POST['cfo_status']) && $_POST['cfo_status'] === 'transferred-out') ? 'selected' : ''; ?>>Transferred Out</option>
                        </select>
                    </div>

                    <div class="md:col-span-1">
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Notes
                        </label>
                        <textarea name="cfo_notes" rows="3" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"><?php echo Security::escape($_POST['cfo_notes'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Submit Buttons -->
            <div class="flex gap-3 pt-4">
                <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-medium">
                    <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                    </svg>
                    Add CFO Member
                </button>
                <a href="cfo-registry.php" class="px-6 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors font-medium">
                    Cancel
                </a>
            </div>
        </form>
    </div>
</div>

<script>
// Load locals based on district
async function loadLocals(districtCode) {
    const localSelect = document.getElementById('local_code');
    
    if (!districtCode) {
        localSelect.innerHTML = '<option value="">Select District First</option>';
        return;
    }
    
    try {
        const response = await fetch('api/get-locals.php?district=' + districtCode);
        const data = await response.json();
        
        let html = '<option value="">Select Local Congregation</option>';
        
        if (Array.isArray(data)) {
            data.forEach(local => {
                html += `<option value="${local.local_code}">${local.local_name}</option>`;
            });
        }
        
        localSelect.innerHTML = html;
    } catch (error) {
        console.error('Error loading locals:', error);
        localSelect.innerHTML = '<option value="">Error loading locals</option>';
    }
}

// Auto-classify based on husband's surname and age
function autoClassify() {
    const husbandsSurname = document.getElementById('husbands_surname').value.trim();
    const birthdayInput = document.querySelector('input[name="birthday"]');
    const classificationSelect = document.getElementById('cfo_classification');
    const autoMsg = document.getElementById('auto-classify-msg');
    
    if (classificationSelect.value !== '') {
        autoMsg.textContent = '';
        return;
    }
    
    // Priority: Married (Buklod) ONLY if husband's surname exists (not empty, not "-") > Age-based
    if (husbandsSurname && husbandsSurname !== '' && husbandsSurname !== '-') {
        autoMsg.textContent = 'Will be auto-classified as Buklod (married)';
        autoMsg.classList.remove('text-gray-500', 'text-green-600', 'text-orange-600');
        autoMsg.classList.add('text-purple-600', 'font-medium');
    } else if (birthdayInput && birthdayInput.value) {
        // Calculate age
        const birthday = new Date(birthdayInput.value);
        const today = new Date();
        let age = today.getFullYear() - birthday.getFullYear();
        const monthDiff = today.getMonth() - birthday.getMonth();
        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthday.getDate())) {
            age--;
        }
        
        if (age >= 18) {
            autoMsg.textContent = `Age ${age} - Will be auto-classified as Kadiwa (youth)`;
            autoMsg.classList.remove('text-gray-500', 'text-purple-600', 'text-orange-600');
            autoMsg.classList.add('text-green-600', 'font-medium');
        } else if (age >= 0) {
            autoMsg.textContent = `Age ${age} - Will be auto-classified as Binhi (children)`;
            autoMsg.classList.remove('text-gray-500', 'text-purple-600', 'text-green-600');
            autoMsg.classList.add('text-orange-600', 'font-medium');
        } else {
            autoMsg.textContent = '';
        }
    } else {
        autoMsg.textContent = '';
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    autoClassify();
    
    // Auto-load locals if district is pre-set (for district/local users with hidden district field)
    const districtInput = document.getElementById('district_code');
    if (districtInput) {
        const districtCode = districtInput.value;
        if (districtCode) {
            console.log('Auto-loading locals for district:', districtCode);
            loadLocals(districtCode);
        }
    }
    
    // Add event listener for birthday field
    const birthdayInput = document.querySelector('input[name="birthday"]');
    if (birthdayInput) {
        birthdayInput.addEventListener('change', autoClassify);
    }
    
    // Add event listener for classification select
    const classificationSelect = document.getElementById('cfo_classification');
    if (classificationSelect) {
        classificationSelect.addEventListener('change', autoClassify);
    }
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/includes/layout.php';
?>
