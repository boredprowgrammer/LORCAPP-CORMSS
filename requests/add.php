<?php
/**
 * Add New Officer Request
 * Create a new aspiring officer application
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';

Security::requireLogin();

$db = Database::getInstance()->getConnection();
$user = getCurrentUser();

// Only local and district users can create requests
if ($user['role'] === 'admin') {
    header('Location: list.php');
    exit;
}

$success = false;
$error = '';

// Get districts and locals for selection
$districtsStmt = $db->query("SELECT district_code, district_name FROM districts ORDER BY district_name");
$districts = $districtsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get locals based on user's district
$localsQuery = "SELECT local_code, local_name FROM local_congregations WHERE district_code = ? ORDER BY local_name";
$localsStmt = $db->prepare($localsQuery);
$localsStmt->execute([$user['district_code']]);
$locals = $localsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get departments
$departments = getDepartments();

// Get record codes
$recordCodes = [
    'A' => 'CODE A - New Officer (No existing record)',
    'D' => 'CODE D - Existing Officer (Add new department)'
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Security::validateCSRFToken($_POST['csrf_token'] ?? '');
    
    $recordCode = $_POST['record_code'] ?? '';
    $lastName = trim($_POST['last_name'] ?? '');
    $firstName = trim($_POST['first_name'] ?? '');
    $middleInitial = trim($_POST['middle_initial'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $districtCode = $_POST['district_code'] ?? $user['district_code'];
    $localCode = $_POST['local_code'] ?? $user['local_code'];
    $department = $_POST['department'] ?? '';
    $duty = trim($_POST['duty'] ?? '');
    $existingOfficerId = $_POST['existing_officer_id'] ?? null;
    $requestClass = $_POST['request_class'] ?? '8_lessons'; // Default to 8 lessons
    
    // Set seminar days based on request class
    $seminarDaysRequired = ($requestClass === '33_lessons') ? 30 : 8;
    
    // Validation
    if (empty($recordCode)) {
        $error = "Please select a record code.";
    } elseif ($recordCode === 'D' && empty($existingOfficerId)) {
        $error = "Please select an existing officer for CODE D.";
    } elseif ($recordCode === 'A' && (empty($lastName) || empty($firstName))) {
        $error = "Last name and first name are required for CODE A.";
    } elseif (empty($localCode) || empty($department)) {
        $error = "Please select local congregation and department.";
    } else {
        try {
            // Encrypt personal information with district code (only for CODE A)
            if ($recordCode === 'A') {
                $lastNameEnc = Encryption::encrypt($lastName, $districtCode);
                $firstNameEnc = Encryption::encrypt($firstName, $districtCode);
                $middleInitialEnc = !empty($middleInitial) ? Encryption::encrypt($middleInitial, $districtCode) : null;
            } else {
                // For CODE D, we don't need to store personal info since it's linked to existing officer
                $lastNameEnc = null;
                $firstNameEnc = null;
                $middleInitialEnc = null;
            }
            
            // Insert request
            $stmt = $db->prepare("
                INSERT INTO officer_requests (
                    last_name_encrypted, first_name_encrypted, middle_initial_encrypted,
                    email, phone, district_code, local_code,
                    requested_department, requested_duty, record_code,
                    existing_officer_uuid,
                    request_class, seminar_days_required, seminar_days_completed,
                    status, requested_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'pending', ?)
            ");
            
            $stmt->execute([
                $lastNameEnc, $firstNameEnc, $middleInitialEnc,
                $email, $phone, $districtCode, $localCode,
                $department, $duty, $recordCode,
                $existingOfficerId,
                $requestClass, $seminarDaysRequired,
                $user['user_id']
            ]);
            
            $requestId = $db->lastInsertId();
            
            // Audit log
            $stmt = $db->prepare("
                INSERT INTO audit_log (user_id, action, table_name, record_id, new_values, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $user['user_id'],
                'create_officer_request',
                'officer_requests',
                $requestId,
                json_encode([
                    'record_code' => $recordCode,
                    'department' => $department,
                    'local_code' => $localCode
                ]),
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
            
            $success = true;
            
        } catch (Exception $e) {
            error_log("Error creating officer request: " . $e->getMessage());
            $error = "Failed to submit request. Please try again.";
        }
    }
}

$pageTitle = "New Officer Request";
ob_start();
?>

<div class="p-6 max-w-3xl mx-auto space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-3xl font-bold text-gray-900">New Officer Request</h2>
            <p class="text-sm text-gray-500">Submit an application for aspiring church officer</p>
        </div>
        <a href="list.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg text-gray-700 bg-white dark:bg-gray-800 hover:bg-gray-50 transition-colors">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
            </svg>
            Back to List
        </a>
    </div>

    <?php if ($success): ?>
    <div class="bg-green-50 border border-green-200 rounded-lg p-4">
        <div class="flex items-start">
            <svg class="w-5 h-5 text-green-600 mr-3 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
            </svg>
            <div>
                <p class="font-semibold text-green-800">Request submitted successfully!</p>
                <p class="text-sm text-green-700 mt-1">The request is now pending.</p>
                <a href="list.php" class="text-sm text-green-700 underline mt-2 inline-block">View all requests</a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="bg-red-50 border border-red-200 rounded-lg p-4">
        <div class="flex items-start">
            <svg class="w-5 h-5 text-red-600 mr-3 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
            </svg>
            <span class="text-sm text-red-800"><?php echo Security::escape($error); ?></span>
        </div>
    </div>
    <?php endif; ?>

    <!-- Request Form -->
    <form method="POST" class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 p-6 space-y-6" x-data="{
            code: 'A',
            searchQuery: '',
            searchResults: [],
            selectedOfficer: null,
            selectedOfficerId: null,
            searching: false,
            lastName: '',
            firstName: '',
            middleInitial: '',
            async search() {
                if (this.searchQuery.length < 2) { this.searchResults = []; return; }
                this.searching = true;
                try {
                    const res = await fetch('../api/search-officers.php?q=' + encodeURIComponent(this.searchQuery));
                    this.searchResults = await res.json();
                } catch (e) {
                    this.searchResults = [];
                }
                this.searching = false;
            },
            selectOfficer(officer) {
                this.selectedOfficer = officer;
                this.selectedOfficerId = officer.id;
                // Auto-fill personal information from selected officer
                this.lastName = officer.last_name || '';
                this.firstName = officer.first_name || '';
                this.middleInitial = officer.middle_initial || '';
            },
            resetForm() {
                this.lastName = '';
                this.firstName = '';
                this.middleInitial = '';
                this.selectedOfficer = null;
                this.selectedOfficerId = null;
                this.searchQuery = '';
                this.searchResults = [];
            }
        }">
        <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
        <input type="hidden" name="existing_officer_id" x-model="selectedOfficerId">
        
        <!-- Personal Information -->
        <div>
            <h3 class="text-lg font-semibold text-gray-900 mb-4 flex items-center">
                <svg class="w-5 h-5 text-blue-600 mr-2" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"/>
                </svg>
                Personal Information
            </h3>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Last Name *</label>
                    <input type="text" name="last_name" x-model="lastName" required 
                           :readonly="code === 'D' && selectedOfficer"
                           :class="code === 'D' && selectedOfficer ? 'bg-gray-100' : ''"
                           oninput="this.value = this.value.toUpperCase()"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 uppercase"
                           style="text-transform: uppercase;">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">First Name *</label>
                    <input type="text" name="first_name" x-model="firstName" required 
                           :readonly="code === 'D' && selectedOfficer"
                           :class="code === 'D' && selectedOfficer ? 'bg-gray-100' : ''"
                           oninput="this.value = this.value.toUpperCase()"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 uppercase"
                           style="text-transform: uppercase;">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">M.I.</label>
                    <input type="text" name="middle_initial" x-model="middleInitial" maxlength="1" 
                           :readonly="code === 'D' && selectedOfficer"
                           :class="code === 'D' && selectedOfficer ? 'bg-gray-100' : ''"
                           oninput="this.value = this.value.toUpperCase()"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 uppercase"
                           style="text-transform: uppercase;">
                </div>
            </div>
        </div>

        <!-- Contact Information -->
        <div>
            <h3 class="text-lg font-semibold text-gray-900 mb-4 flex items-center">
                <svg class="w-5 h-5 text-blue-600 mr-2" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z"/>
                    <path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z"/>
                </svg>
                Contact Information (Optional)
            </h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Email</label>
                    <input type="email" name="email" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Phone</label>
                    <input type="tel" name="phone" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
            </div>
        </div>

        <!-- Location -->
        <div>
            <h3 class="text-lg font-semibold text-gray-900 mb-4 flex items-center">
                <svg class="w-5 h-5 text-blue-600 mr-2" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd"/>
                </svg>
                Location
            </h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">District *</label>
                    <input type="text" value="<?php echo Security::escape($user['district_code']); ?>" disabled class="w-full px-4 py-2 border border-gray-300 rounded-lg bg-gray-50">
                    <input type="hidden" name="district_code" value="<?php echo Security::escape($user['district_code']); ?>">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Local Congregation *</label>
                    <?php if ($user['role'] === 'local'): ?>
                        <input type="text" value="<?php echo Security::escape($user['local_code']); ?>" disabled class="w-full px-4 py-2 border border-gray-300 rounded-lg bg-gray-50">
                        <input type="hidden" name="local_code" value="<?php echo Security::escape($user['local_code']); ?>">
                    <?php else: ?>
                        <div class="relative">
                            <input 
                                type="text" 
                                id="local-display"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 cursor-pointer bg-white dark:bg-gray-800"
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
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Position Details -->
        <div>
            <h3 class="text-lg font-semibold text-gray-900 mb-4 flex items-center">
                <svg class="w-5 h-5 text-blue-600 mr-2" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M6 6V5a3 3 0 013-3h2a3 3 0 013 3v1h2a2 2 0 012 2v3.57A22.952 22.952 0 0110 13a22.95 22.95 0 01-8-1.43V8a2 2 0 012-2h2zm2-1a1 1 0 011-1h2a1 1 0 011 1v1H8V5zm1 5a1 1 0 011-1h.01a1 1 0 110 2H10a1 1 0 01-1-1z" clip-rule="evenodd"/>
                    <path d="M2 13.692V16a2 2 0 002 2h12a2 2 0 002-2v-2.308A24.974 24.974 0 0110 15c-2.796 0-5.487-.46-8-1.308z"/>
                </svg>
                Position Details
            </h3>
            
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Department *</label>
                    <div class="relative">
                        <input 
                            type="text" 
                            id="department-display"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 cursor-pointer bg-white dark:bg-gray-800"
                            placeholder="Select Department"
                            readonly
                            onclick="openDepartmentModal()"
                            value=""
                        >
                        <input type="hidden" name="department" id="department-value" required>
                        <div class="absolute inset-y-0 right-0 flex items-center px-3 pointer-events-none">
                            <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </div>
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Specific Duty/Role (Optional)</label>
                    <textarea 
                        name="duty" 
                        rows="3" 
                        placeholder="e.g., Choir Member, Usher, Teacher, etc."
                        oninput="this.value = this.value.toUpperCase()"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 resize-none uppercase"
                        style="text-transform: uppercase;"
                    ></textarea>
                    <p class="text-xs text-gray-500 mt-1">Specify the role or position you're applying for</p>
                </div>
            </div>
        </div>

        <!-- Seminar Class (8 Lessons or 33 Lessons) -->
        <div>
            <h3 class="text-lg font-semibold text-gray-900 mb-4 flex items-center">
                <svg class="w-5 h-5 text-purple-600 mr-2" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M10.394 2.08a1 1 0 00-.788 0l-7 3a1 1 0 000 1.84L5.25 8.051a.999.999 0 01.356-.257l4-1.714a1 1 0 11.788 1.838L7.667 9.088l1.94.831a1 1 0 00.787 0l7-3a1 1 0 000-1.838l-7-3zM3.31 9.397L5 10.12v4.102a8.969 8.969 0 00-1.05-.174 1 1 0 01-.89-.89 11.115 11.115 0 01.25-3.762zM9.3 16.573A9.026 9.026 0 007 14.935v-3.957l1.818.78a3 3 0 002.364 0l5.508-2.361a11.026 11.026 0 01.25 3.762 1 1 0 01-.89.89 8.968 8.968 0 00-5.35 2.524 1 1 0 01-1.4 0zM6 18a1 1 0 001-1v-2.065a8.935 8.935 0 00-2-.712V17a1 1 0 001 1z"/>
                </svg>
                Seminar Class
            </h3>
            
            <div class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <label class="flex items-start p-4 border-2 border-gray-200 rounded-lg cursor-pointer hover:border-purple-500 hover:bg-purple-50 transition-colors">
                        <input type="radio" name="request_class" value="8_lessons" class="mt-1 h-4 w-4 text-purple-600 border-gray-300" checked>
                        <div class="ml-3">
                            <div class="text-sm font-semibold text-gray-900">8 Lessons — Standard Class</div>
                            <div class="text-xs text-gray-600 mt-1">Requires 8 days of seminar attendance</div>
                            <div class="text-xs text-purple-600 mt-1 font-medium">✓ Most common for regular officers</div>
                        </div>
                    </label>
                    
                    <label class="flex items-start p-4 border-2 border-gray-200 rounded-lg cursor-pointer hover:border-purple-500 hover:bg-purple-50 transition-colors">
                        <input type="radio" name="request_class" value="33_lessons" class="mt-1 h-4 w-4 text-purple-600 border-gray-300">
                        <div class="ml-3">
                            <div class="text-sm font-semibold text-gray-900">33 Lessons — Extended Class</div>
                            <div class="text-xs text-gray-600 mt-1">Requires 30 days of seminar attendance</div>
                            <div class="text-xs text-orange-600 mt-1 font-medium">⚠ For special assignments</div>
                        </div>
                    </label>
                </div>
                
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-3">
                    <p class="text-xs text-blue-800">
                        <strong>Note:</strong> The seminar class determines how many days of training are required before the officer can take their oath. 
                        R5-13 certificate will be generated after completing all required seminar days.
                    </p>
                </div>
            </div>
        </div>

        <!-- Record Code (A = New, D = Existing) -->
        <div>
            <h3 class="text-lg font-semibold text-gray-900 mb-4 flex items-center">
                <svg class="w-5 h-5 text-indigo-600 mr-2" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M4 3h12v2H4V3zm0 4h12v2H4V7zm0 4h12v2H4v-2zm0 4h12v2H4v-2z" />
                </svg>
                Record Type
            </h3>

            <div class="flex items-center space-x-6">
                <label class="inline-flex items-center">
                    <input type="radio" name="record_code" value="A" x-model="code" class="h-4 w-4 text-indigo-600 border-gray-300" checked>
                    <span class="ml-2 text-sm">CODE A — New Officer (create new record on oath)</span>
                </label>

                <label class="inline-flex items-center">
                    <input type="radio" name="record_code" value="D" x-model="code" class="h-4 w-4 text-indigo-600 border-gray-300">
                    <span class="ml-2 text-sm">CODE D — Existing Officer (select existing record)</span>
                </label>
            </div>

            <!-- Existing officer search shown when CODE D selected -->
            <div x-show="code === 'D'" x-cloak class="mt-4 bg-blue-50 border border-blue-100 rounded-lg p-4">
                <label class="block text-sm font-medium text-gray-700">Search Existing Officer (type name)</label>
                <div class="flex items-center mt-2 space-x-2">
                    <input type="text" x-model="searchQuery" @input.debounce="search()" placeholder="Enter full or partial name" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                    <button type="button" @click.prevent="search()" class="px-3 py-2 bg-blue-600 text-white rounded-lg">Search</button>
                </div>

                <div class="mt-3">
                    <template x-if="searching">
                        <div class="text-sm text-gray-500">Searching...</div>
                    </template>

                    <template x-if="!searching && searchResults.length === 0">
                        <div class="text-sm text-gray-500 mt-2">No results</div>
                    </template>

                    <ul class="mt-2 space-y-2 max-h-48 overflow-auto">
                        <template x-for="r in searchResults" :key="r.id">
                            <li class="flex items-center justify-between p-2 rounded hover:bg-blue-100 cursor-pointer" @click="selectOfficer(r)">
                                <div>
                                    <div class="text-sm font-medium" x-text="r.name"></div>
                                    <div class="text-xs text-gray-500" x-text="r.location"></div>
                                </div>
                                <div class="text-xs text-green-700 font-medium" x-show="selectedOfficer && selectedOfficer.id === r.id">✓ Selected</div>
                            </li>
                        </template>
                    </ul>

                    <template x-if="selectedOfficer">
                        <div class="mt-3 p-3 bg-green-50 border border-green-200 rounded-lg">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-semibold text-green-800">Selected Officer:</p>
                                    <p class="text-sm text-green-700" x-text="selectedOfficer.full_name"></p>
                                </div>
                                <button type="button" @click="resetForm()" class="text-xs text-red-600 hover:text-red-800 underline">Clear Selection</button>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <!-- Submit Buttons -->
        <div class="flex items-center justify-end space-x-3 pt-4 border-t border-gray-200">
            <a href="list.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg text-gray-700 bg-white dark:bg-gray-800 hover:bg-gray-50 transition-colors">
                Cancel
            </a>
            <button type="submit" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
                Submit Request
            </button>
        </div>
    </form>
</div>

<script>
// Modal functions
let currentLocals = <?php echo json_encode($locals); ?>;

function openLocalModal() {
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
        <div class="relative bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-md w-full max-h-[80vh] flex flex-col">
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
                <?php foreach ($departments as $dept): ?>
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
        <div class="relative bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-md w-full max-h-[80vh] flex flex-col">
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
