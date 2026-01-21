<?php
/**
 * Add CFO Member
 * Add new Tarheta Control entry with CFO classification
 */

require_once __DIR__ . '/config/config.php';

Security::requireLogin();

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

// Check access permissions
$hasAddAccess = false;
$approvedCfoTypes = [];

if ($currentUser['role'] === 'admin' || $currentUser['role'] === 'local') {
    // Admin and local have full access (if they have can_add_officers permission)
    if (hasPermission('can_add_officers')) {
        $hasAddAccess = true;
    }
} elseif ($currentUser['role'] === 'local_cfo') {
    // Check for approved add_member access
    $stmt = $db->prepare("
        SELECT * FROM cfo_access_requests 
        WHERE requester_user_id = ? 
        AND status = 'approved'
        AND access_mode = 'add_member'
        AND deleted_at IS NULL
        AND (expires_at IS NULL OR expires_at > NOW())
    ");
    $stmt->execute([$currentUser['user_id']]);
    $approvedRequests = $stmt->fetchAll();
    
    if (count($approvedRequests) > 0) {
        $hasAddAccess = true;
        foreach ($approvedRequests as $request) {
            if (!in_array($request['cfo_type'], $approvedCfoTypes)) {
                $approvedCfoTypes[] = $request['cfo_type'];
            }
        }
    }
}

// Restrict access if no add permission
if (!$hasAddAccess) {
    header('Location: ' . BASE_URL . '/cfo-registry.php?error=' . urlencode('You need approved add access to add CFO members.'));
    exit();
}

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
        $registrationType = Security::sanitizeInput($_POST['registration_type'] ?? '');
        $registrationDate = Security::sanitizeInput($_POST['registration_date'] ?? '');
        $registrationOthersSpecify = Security::sanitizeInput($_POST['registration_others_specify'] ?? '');
        
        // Validation
        if (empty($districtCode) || empty($localCode)) {
            $error = 'District and local congregation are required.';
        } elseif (empty($lastName) || empty($firstName) || empty($registryNumber)) {
            $error = 'Last name, first name, and registry number are required.';
        } elseif (!empty($registrationType) && $registrationType === 'transfer-in' && empty($registrationDate)) {
            $error = 'Registration date is required for Transfer-In type.';
        } elseif (!empty($registrationType) && $registrationType === 'others' && empty($registrationOthersSpecify)) {
            $error = 'Please specify details for "Others" registration type.';
        } elseif (!hasDistrictAccess($districtCode) || !hasLocalAccess($localCode)) {
            $error = 'You do not have access to this district/local.';
        } elseif ($currentUser['role'] === 'local_cfo' && !empty($approvedCfoTypes) && !empty($cfoClassification) && !in_array($cfoClassification, $approvedCfoTypes)) {
            $error = 'You can only add members with your approved CFO classification: ' . implode(', ', $approvedCfoTypes);
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
                
                // For local_cfo users, save to pending_actions instead of direct insert
                if ($currentUser['role'] === 'local_cfo') {
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
                        'last_name' => $lastName,
                        'first_name' => $firstName,
                        'middle_name' => $middleName,
                        'husbands_surname' => $husbandsSurname,
                        'registry_number' => $registryNumber,
                        'district_code' => $districtCode,
                        'local_code' => $localCode,
                        'birthday' => $birthday,
                        'cfo_classification' => $cfoClassification,
                        'cfo_status' => $cfoStatus,
                        'cfo_notes' => $cfoNotes,
                        'registration_type' => $registrationType,
                        'registration_date' => $registrationDate,
                        'registration_others_specify' => $registrationOthersSpecify
                    ]);
                    
                    $actionDescription = "Add CFO member: $firstName $lastName ($cfoClassification)";
                    
                    $stmt = $db->prepare("
                        INSERT INTO pending_actions (
                            requester_user_id, approver_user_id, action_type, action_data,
                            action_description, target_table, status, created_at, expires_at
                        ) VALUES (?, ?, 'add_cfo', ?, ?, 'tarheta_control', 'pending', NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY))
                    ");
                    $stmt->execute([
                        $currentUser['user_id'],
                        $seniorApprover['user_id'],
                        $actionData,
                        $actionDescription
                    ]);
                    
                    $db->commit();
                    
                    $success = 'CFO member submitted for LORC/LCRC review. You will be notified once approved.';
                    $_POST = [];
                    
                } else {
                    // Direct insert for admin/local users
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
                
                // Insert record
                $stmt = $db->prepare("
                    INSERT INTO tarheta_control (
                        last_name_encrypted, first_name_encrypted, middle_name_encrypted,
                        husbands_surname_encrypted, registry_number_encrypted, registry_number_hash,
                        district_code, local_code, birthday_encrypted,
                        cfo_classification, cfo_classification_auto, cfo_status, cfo_notes,
                        registration_type, registration_date, registration_others_specify,
                        imported_by, imported_at, cfo_updated_by, cfo_updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, NOW())
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
                    !empty($registrationType) ? $registrationType : null,
                    !empty($registrationDate) ? $registrationDate : null,
                    !empty($registrationOthersSpecify) ? $registrationOthersSpecify : null,
                    $currentUser['user_id'],
                    $currentUser['user_id']
                ]);
                
                $db->commit();
                
                $success = 'CFO member added successfully!';
                
                // Clear form
                $_POST = [];
                } // End of else block for admin/local direct insert
                
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
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900 dark:text-gray-100">Add CFO Member</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Add new member to CFO Registry</p>
            </div>
            <a href="cfo-registry.php" class="inline-flex items-center px-4 py-2 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                </svg>
                Back to CFO Registry
            </a>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-3 rounded-lg">
            <?php echo Security::escape($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-300 px-4 py-3 rounded-lg">
            <?php echo Security::escape($success); ?>
        </div>
    <?php endif; ?>

    <?php if ($currentUser['role'] === 'local_cfo'): ?>
    <!-- Workflow Stepper for Local CFO Users -->
    <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-6">
        <h3 class="text-lg font-semibold text-blue-800 dark:text-blue-200 mb-4">📋 Submission Workflow</h3>
        <div class="flex items-center justify-between relative">
            <!-- Step 1: Submit -->
            <div class="flex flex-col items-center z-10">
                <div class="w-10 h-10 bg-blue-600 text-white rounded-full flex items-center justify-center font-bold">1</div>
                <span class="text-sm text-blue-700 dark:text-blue-300 mt-2 text-center font-medium">Submit Request</span>
            </div>
            <!-- Line -->
            <div class="flex-1 h-1 bg-gray-300 dark:bg-gray-600 mx-2 relative top-[-1rem]"></div>
            <!-- Step 2: Pending Review -->
            <div class="flex flex-col items-center z-10">
                <div class="w-10 h-10 bg-yellow-500 text-white rounded-full flex items-center justify-center font-bold">2</div>
                <span class="text-sm text-yellow-700 dark:text-yellow-300 mt-2 text-center font-medium">Pending LORC/LCRC<br>Review</span>
            </div>
            <!-- Line -->
            <div class="flex-1 h-1 bg-gray-300 dark:bg-gray-600 mx-2 relative top-[-1rem]"></div>
            <!-- Step 3: Approved -->
            <div class="flex flex-col items-center z-10">
                <div class="w-10 h-10 bg-gray-400 text-white rounded-full flex items-center justify-center font-bold">3</div>
                <span class="text-sm text-gray-600 dark:text-gray-400 mt-2 text-center font-medium">Approved &<br>Added to Registry</span>
            </div>
        </div>
        <p class="text-sm text-blue-700 dark:text-blue-300 mt-4">
            <strong>Note:</strong> Your submissions will be reviewed by your local LORC/LCRC before being added to the registry.
            <a href="pending-actions.php" class="underline hover:text-blue-800 dark:hover:text-blue-200">View your pending submissions →</a>
        </p>
    </div>
    <?php endif; ?>

    <!-- Add Form -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <form method="POST" class="space-y-6">
            <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken('add_cfo'); ?>">
            
            <!-- Location Information -->
            <div>
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Location Information</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            District <span class="text-red-600">*</span>
                        </label>
                        <?php if ($currentUser['role'] === 'district' || $currentUser['role'] === 'local' || $currentUser['role'] === 'local_cfo'): ?>
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
                        <?php if ($currentUser['role'] === 'local' || $currentUser['role'] === 'local_cfo'): ?>
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
                
                <!-- Registration Type Section -->
                <div class="mb-6 p-4 bg-gray-50 rounded-lg border border-gray-200">
                    <h4 class="text-sm font-semibold text-gray-700 mb-3">Registration Type</h4>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Type
                            </label>
                            <select name="registration_type" id="registration_type" onchange="handleRegistrationTypeChange()" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                <option value="">-- Not specified --</option>
                                <option value="transfer-in" <?php echo (isset($_POST['registration_type']) && $_POST['registration_type'] === 'transfer-in') ? 'selected' : ''; ?>>Transfer-In</option>
                                <option value="newly-baptized" <?php echo (isset($_POST['registration_type']) && $_POST['registration_type'] === 'newly-baptized') ? 'selected' : ''; ?>>Newly Baptized</option>
                                <option value="others" <?php echo (isset($_POST['registration_type']) && $_POST['registration_type'] === 'others') ? 'selected' : ''; ?>>Others (Specify)</option>
                            </select>
                        </div>

                        <div id="registration_date_field" style="display: none;">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Registration Date <span class="text-red-600" id="date_required_indicator">*</span>
                            </label>
                            <input type="date" name="registration_date" id="registration_date"
                                value="<?php echo Security::escape($_POST['registration_date'] ?? ''); ?>"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <p class="text-xs text-gray-500 mt-1">Date transferred in</p>
                        </div>

                        <div id="registration_others_field" style="display: none;">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Specify <span class="text-red-600">*</span>
                            </label>
                            <input type="text" name="registration_others_specify" id="registration_others_specify" maxlength="255"
                                value="<?php echo Security::escape($_POST['registration_others_specify'] ?? ''); ?>"
                                placeholder="Please specify..."
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        </div>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            CFO Classification
                        </label>
                        <select name="cfo_classification" id="cfo_classification" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <?php if ($currentUser['role'] === 'local_cfo' && !empty($approvedCfoTypes)): ?>
                                <!-- Restricted to approved CFO types -->
                                <?php if (count($approvedCfoTypes) == 1): ?>
                                    <option value="<?php echo Security::escape($approvedCfoTypes[0]); ?>" selected><?php echo Security::escape($approvedCfoTypes[0]); ?></option>
                                <?php else: ?>
                                    <option value="">-- Select Classification --</option>
                                    <?php foreach ($approvedCfoTypes as $type): ?>
                                        <option value="<?php echo Security::escape($type); ?>" <?php echo (isset($_POST['cfo_classification']) && $_POST['cfo_classification'] === $type) ? 'selected' : ''; ?>><?php echo Security::escape($type); ?></option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            <?php else: ?>
                                <!-- Full access - all options -->
                                <option value="">-- Auto-classify --</option>
                                <option value="Buklod" <?php echo (isset($_POST['cfo_classification']) && $_POST['cfo_classification'] === 'Buklod') ? 'selected' : ''; ?>>Buklod (Married Couples)</option>
                                <option value="Kadiwa" <?php echo (isset($_POST['cfo_classification']) && $_POST['cfo_classification'] === 'Kadiwa') ? 'selected' : ''; ?>>Kadiwa (Youth)</option>
                                <option value="Binhi" <?php echo (isset($_POST['cfo_classification']) && $_POST['cfo_classification'] === 'Binhi') ? 'selected' : ''; ?>>Binhi (Children)</option>
                            <?php endif; ?>
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
    handleRegistrationTypeChange(); // Initialize registration type fields
    
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

// Handle registration type field visibility
function handleRegistrationTypeChange() {
    const registrationType = document.getElementById('registration_type').value;
    const dateField = document.getElementById('registration_date_field');
    const othersField = document.getElementById('registration_others_field');
    const dateInput = document.getElementById('registration_date');
    const othersInput = document.getElementById('registration_others_specify');
    
    // Hide all conditional fields first
    dateField.style.display = 'none';
    othersField.style.display = 'none';
    
    // Clear required attributes
    dateInput.removeAttribute('required');
    othersInput.removeAttribute('required');
    
    // Show relevant fields based on selection
    if (registrationType === 'transfer-in') {
        dateField.style.display = 'block';
        dateInput.setAttribute('required', 'required');
    } else if (registrationType === 'newly-baptized') {
        dateField.style.display = 'block';
        // Date is optional for newly baptized
    } else if (registrationType === 'others') {
        dateField.style.display = 'block';
        othersField.style.display = 'block';
        othersInput.setAttribute('required', 'required');
    }
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/includes/layout.php';
?>
