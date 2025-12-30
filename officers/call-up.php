<?php
/**
 * Create Call-Up Slip for Officers
 * Tawag-Pansin / Call-Up Notice
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

Security::requireLogin();

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Security::validateCSRFToken($_POST['csrf_token'] ?? '');
    
    $officerUuid = Security::sanitizeInput($_POST['officer_uuid'] ?? '');
    $manualOfficerName = Security::sanitizeInput($_POST['manual_officer_name'] ?? '');
    $manualLocalCode = Security::sanitizeInput($_POST['manual_local_code'] ?? '');
    $manualDistrictCode = Security::sanitizeInput($_POST['manual_district_code'] ?? '');
    $department = Security::sanitizeInput($_POST['department'] ?? '');
    $fileNumber = Security::sanitizeInput($_POST['file_number'] ?? '');
    $reason = Security::sanitizeInput($_POST['reason'] ?? '');
    $destinado = Security::sanitizeInput($_POST['destinado'] ?? '');
    $deadlineDate = Security::sanitizeInput($_POST['deadline_date'] ?? '');
    
    $errors = [];
    
    if (empty($officerUuid) && empty($manualOfficerName)) $errors[] = "Please select an officer or enter a name manually.";
    if (!empty($manualOfficerName)) {
        if (empty($manualLocalCode)) $errors[] = "Local congregation is required for manual entry.";
        if (empty($manualDistrictCode)) $errors[] = "District is required for manual entry.";
    }
    if (empty($department)) $errors[] = "Department is required.";
    if (empty($fileNumber)) $errors[] = "File number is required.";
    if (empty($reason)) $errors[] = "Reason is required.";
    if (empty($destinado)) $errors[] = "Destinado (Signatory) is required.";
    if (empty($deadlineDate)) $errors[] = "Deadline date is required.";
    
    if (empty($errors)) {
        try {
            $db->beginTransaction();
            
            $officerId = null;
            $localCode = $currentUser['local_code'] ?? null;
            $districtCode = $currentUser['district_code'] ?? null;
            
            // Check if using manual name or officer UUID
            if (!empty($officerUuid)) {
                // Get officer details from database
                $stmt = $db->prepare("
                    SELECT officer_id, local_code, district_code 
                    FROM officers 
                    WHERE officer_uuid = ?
                ");
                $stmt->execute([$officerUuid]);
                $officer = $stmt->fetch();
                
                if (!$officer) {
                    throw new Exception('Officer not found.');
                }
                
                // Check access rights
                if ($currentUser['role'] === 'local' && $officer['local_code'] !== $currentUser['local_code']) {
                    throw new Exception('Access denied.');
                } elseif ($currentUser['role'] === 'district' && $officer['district_code'] !== $currentUser['district_code']) {
                    throw new Exception('Access denied.');
                }
                
                $officerId = $officer['officer_id'];
                $localCode = $officer['local_code'];
                $districtCode = $officer['district_code'];
            } else {
                // Manual entry - use manually selected local and district
                $localCode = $manualLocalCode;
                $districtCode = $manualDistrictCode;
            }
            
            // Ensure local_code and district_code are not null
            if (empty($localCode) || empty($districtCode)) {
                throw new Exception('Local and district information is required. Please ensure your account has proper local/district assignment.');
            }
            
            // Insert call-up slip
            $stmt = $db->prepare("
                INSERT INTO call_up_slips (
                    officer_id, manual_officer_name, file_number, department, reason, 
                    destinado, issue_date, deadline_date, prepared_by, 
                    local_code, district_code, status
                ) VALUES (?, ?, ?, ?, ?, ?, CURRENT_DATE, ?, ?, ?, ?, 'issued')
            ");
            
            $stmt->execute([
                $officerId,
                $manualOfficerName ?: null,
                $fileNumber,
                $department,
                $reason,
                $destinado,
                $deadlineDate,
                $currentUser['user_id'],
                $localCode,
                $districtCode
            ]);
            
            $slipId = $db->lastInsertId();
            
            // Audit log
            $stmt = $db->prepare("
                INSERT INTO audit_log (user_id, action, table_name, record_id, new_values, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $currentUser['user_id'],
                'create_callup_slip',
                'call_up_slips',
                $slipId,
                json_encode([
                    'officer_id' => $officerId,
                    'manual_officer_name' => $manualOfficerName,
                    'file_number' => $fileNumber,
                    'department' => $department
                ]),
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
            
            $db->commit();
            
            // Set session variable for PDF generation and redirect to list
            $_SESSION['generate_pdf_slip_id'] = $slipId;
            setFlashMessage('Call-up slip created successfully!', 'success');
            header("Location: call-up-list.php");
            exit;
            
        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = $e->getMessage();
        }
    }
}

// Get user's district and local for defaults
$userDistrict = $currentUser['district_code'] ?? '';
$userLocal = $currentUser['local_code'] ?? '';

// Get district name
$districtName = '';
if ($userDistrict) {
    $stmt = $db->prepare("SELECT district_name FROM districts WHERE district_code = ?");
    $stmt->execute([$userDistrict]);
    $district = $stmt->fetch();
    $districtName = $district['district_name'] ?? '';
}

// Get local name
$localName = '';
if ($userLocal) {
    $stmt = $db->prepare("SELECT local_name FROM local_congregations WHERE local_code = ?");
    $stmt->execute([$userLocal]);
    $local = $stmt->fetch();
    $localName = $local['local_name'] ?? '';
}

// Get departments for dropdown
$departments = getDepartments();

// Get all districts for manual entry dropdown
$stmt = $db->query("SELECT district_code, district_name FROM districts ORDER BY district_name");
$allDistricts = $stmt->fetchAll();

// Get all locals for manual entry dropdown (will be filtered by district via JavaScript)
$stmt = $db->query("SELECT local_code, local_name, district_code FROM local_congregations ORDER BY local_name");
$allLocals = $stmt->fetchAll();

$pageTitle = "Create Call-Up Slip";
ob_start();
?>

<style>
    [x-cloak] { display: none !important; }
</style>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('callupForm', () => ({
        searchQuery: '',
        searchResults: [],
        selectedOfficer: null,
        searching: false,
        useManualInput: false,
        manualName: '',
        manualDistrict: '',
        manualLocal: '',
        allLocals: <?php echo json_encode(array_map(function($l) { 
            return ['code' => $l['local_code'], 'name' => $l['local_name'], 'district' => $l['district_code']]; 
        }, $allLocals)); ?>,
        filteredLocals: [],
        
        filterLocalsByDistrict() {
            this.manualLocal = '';
            if (this.manualDistrict) {
                this.filteredLocals = this.allLocals.filter(l => l.district === this.manualDistrict);
            } else {
                this.filteredLocals = [];
            }
        },
        
        async searchOfficers() {
            if (this.searchQuery.length < 2) {
                this.searchResults = [];
                return;
            }
            this.searching = true;
            try {
                const response = await fetch('<?php echo BASE_URL; ?>/api/search-officers.php?q=' + encodeURIComponent(this.searchQuery));
                this.searchResults = await response.json();
            } catch (error) {
                console.error('Search error:', error);
                this.searchResults = [];
            }
            this.searching = false;
        },
        
        selectOfficer(officer) {
            this.selectedOfficer = officer;
            this.searchQuery = '';
            this.searchResults = [];
            this.useManualInput = false;
            this.manualName = '';
            this.manualDistrict = '';
            this.manualLocal = '';
        },
        
        clearSelection() {
            this.selectedOfficer = null;
            this.useManualInput = false;
            this.manualName = '';
            this.manualDistrict = '';
            this.manualLocal = '';
        },
        
        switchToManual() {
            this.useManualInput = true;
            this.selectedOfficer = null;
            this.searchQuery = '';
            this.searchResults = [];
            setTimeout(() => {
                document.getElementById('manual-officer-name')?.focus();
            }, 100);
        },
        
        switchToSearch() {
            this.useManualInput = false;
            this.manualName = '';
            this.manualDistrict = '';
            this.manualLocal = '';
            this.filteredLocals = [];
        }
    }));
});
</script>

<div class="max-w-4xl mx-auto space-y-6" x-data="callupForm" x-cloak>

    <!-- Header -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 p-6">
        <div class="flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                    <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                </div>
                <div>
                    <h1 class="text-2xl font-semibold text-gray-900">Create Call-Up Slip</h1>
                    <p class="text-sm text-gray-500 mt-1">Tawag-Pansin / Officer Call-Up Notice</p>
                </div>
            </div>
            <a href="call-up-list.php" class="inline-flex items-center px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/>
                </svg>
                View All Call-Ups
            </a>
        </div>
    </div>

    <!-- Error Messages -->
    <?php if (!empty($errors)): ?>
    <div class="bg-red-50 border border-red-200 rounded-lg p-4">
        <div class="flex items-start">
            <svg class="w-5 h-5 text-red-600 mr-3 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
            </svg>
            <div class="flex-1">
                <ul class="list-disc list-inside text-sm font-medium text-red-800">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo Security::escape($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Form -->
    <form method="POST" class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 p-6 space-y-6">
        <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
        <input type="hidden" name="officer_uuid" :value="selectedOfficer ? selectedOfficer.uuid : ''">
        
        <!-- Officer Search/Manual Input -->
        <div>
            <div class="flex items-center justify-between mb-2">
                <label class="block text-sm font-medium text-gray-700">
                    Officer Name <span class="text-red-600">*</span>
                </label>
                <button type="button" 
                    @click="useManualInput ? switchToSearch() : switchToManual()"
                    class="text-xs text-blue-600 hover:text-blue-700 font-medium">
                    <span x-show="!useManualInput">Or enter manually</span>
                    <span x-show="useManualInput">Or search from list</span>
                </button>
            </div>
            
            <!-- Manual Input Mode -->
            <div x-show="useManualInput" x-cloak class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Officer Name</label>
                    <input type="text" 
                        id="manual-officer-name"
                        name="manual_officer_name"
                        x-model="manualName"
                        placeholder="Enter officer's full name..."
                        class="block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                    <p class="text-xs text-gray-500 mt-1">Type the officer's name if not found in the system</p>
                </div>
                
                <!-- District Selection -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        District <span class="text-red-600">*</span>
                    </label>
                    <select name="manual_district_code" 
                        id="manual-district"
                        x-model="manualDistrict"
                        @change="filterLocalsByDistrict()"
                        class="block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm bg-white dark:bg-gray-800"
                        :required="useManualInput">
                        <option value="">Select District</option>
                        <?php foreach ($allDistricts as $dist): ?>
                            <option value="<?php echo Security::escape($dist['district_code']); ?>">
                                <?php echo Security::escape($dist['district_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Local Selection -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Local Congregation <span class="text-red-600">*</span>
                    </label>
                    <select name="manual_local_code" 
                        id="manual-local"
                        x-model="manualLocal"
                        class="block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm bg-white dark:bg-gray-800"
                        :disabled="!manualDistrict"
                        :required="useManualInput">
                        <option value="">Select Local</option>
                        <template x-for="local in filteredLocals" :key="local.code">
                            <option :value="local.code" x-text="local.name"></option>
                        </template>
                    </select>
                    <p class="text-xs text-gray-500 mt-1" x-show="!manualDistrict">Select a district first</p>
                </div>
            </div>
            
            <!-- Search Mode -->
            <div x-show="!useManualInput" x-cloak>
                <!-- Search Input -->
                <div x-show="!selectedOfficer" class="relative">
                    <input type="text" 
                        x-model="searchQuery"
                        @input.debounce.300ms="searchOfficers()"
                        placeholder="Search by name..."
                        class="block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                    
                    <!-- Search Results -->
                    <div x-show="searchResults.length > 0" 
                        class="absolute z-10 w-full mt-1 bg-white dark:bg-gray-800 border border-gray-300 rounded-lg shadow-lg max-h-60 overflow-y-auto">
                        <template x-for="officer in searchResults" :key="officer.id">
                            <div @click="selectOfficer(officer)" 
                                class="p-3 hover:bg-gray-50 cursor-pointer border-b border-gray-100 last:border-0">
                                <div class="font-semibold text-gray-900 name-mono text-sm" 
                                    :title="officer.full_name"
                                    @dblclick="$el.textContent = officer.full_name"
                                    x-text="officer.name"></div>
                                <div class="text-xs text-gray-600 mt-1" x-text="officer.location"></div>
                                <div class="text-xs text-gray-500" x-text="officer.departments"></div>
                            </div>
                        </template>
                    </div>
                    
                    <div x-show="searching" class="text-sm text-gray-500 mt-2">Searching...</div>
                </div>
                
                <!-- Selected Officer Display -->
                <div x-show="selectedOfficer" class="bg-green-50 border border-green-200 rounded-lg p-4">
                    <div class="flex items-start justify-between">
                        <div class="flex items-start flex-1">
                            <svg class="w-5 h-5 text-green-600 mr-3 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                            </svg>
                            <div class="flex-1">
                                <div class="font-semibold text-gray-900 name-mono cursor-pointer"
                                    :title="selectedOfficer?.full_name"
                                    @dblclick="$el.textContent = selectedOfficer?.full_name"
                                    x-text="selectedOfficer?.name"></div>
                                <div class="text-sm text-gray-600 mt-1" x-text="selectedOfficer?.location"></div>
                                <div class="text-sm text-gray-500" x-text="selectedOfficer?.departments"></div>
                            </div>
                        </div>
                        <button type="button" @click="clearSelection()" class="text-gray-400 hover:text-gray-600">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Department -->
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
                Department/Kapisanan <span class="text-red-600">*</span>
            </label>
            <div class="relative">
                <input 
                    type="text" 
                    id="department-display"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 cursor-pointer bg-white dark:bg-gray-800 text-sm"
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

        <!-- File Number (Auto-generated) -->
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
                Call-Up File # <span class="text-red-600">*</span>
            </label>
            <input type="text" 
                name="file_number" 
                id="file-number"
                placeholder="Select department first..."
                class="block w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                readonly
                required>
            <p class="text-xs text-gray-500 mt-1">Auto-generated: DEPT-YEAR-### (e.g., BUK-2025-001)</p>
        </div>

        
        <!-- Reason -->
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
                Reason (Dahilan) <span class="text-red-600">*</span>
            </label>
            <textarea name="reason" 
                rows="4" 
                placeholder="Hindi po pagsumite ng R7-02 noong..."
                class="block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                required></textarea>
            <p class="text-xs text-gray-500 mt-1">Explain the reason for the call-up notice</p>
        </div>

        <!-- Deadline Date -->
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
                Deadline Date <span class="text-red-600">*</span>
            </label>
            <input type="date" 
                name="deadline_date" 
                class="block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                required>
        </div>

        <!-- Destinado (Signatory/Resident Minister) -->
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
                Destinado (Signatory) <span class="text-red-600">*</span>
            </label>
            <input type="text" 
                name="destinado" 
                placeholder="e.g., Kap. Juan Dela Cruz, Resident Minister"
                class="block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                required>
            <p class="text-xs text-gray-500 mt-1">Name and title of the resident minister who will sign this call-up</p>
        </div>

        <!-- Actions -->
        <div class="flex items-center justify-between pt-4 border-t border-gray-200">
            <a href="list.php" class="inline-flex items-center px-4 py-2 text-sm font-medium text-gray-700 bg-white dark:bg-gray-800 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                Cancel
            </a>
            <button type="submit" 
                class="inline-flex items-center px-4 py-2 bg-blue-500 text-white text-sm font-medium rounded-lg hover:bg-blue-600 transition-colors shadow-sm disabled:opacity-50 disabled:cursor-not-allowed"
                x-bind:disabled="!selectedOfficer && (!manualName || !manualDistrict || !manualLocal)">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                </svg>
                Create & Print Call-Up Slip
            </button>
        </div>
    </form>
</div>

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

<script>
// Department to Initial mapping (based on getDepartments() function)
const departmentInitials = {
    'PAMUNUAN': 'PAM',
    'KNTSTSP': 'KNT',
    'KATIWALA NG DAKO (GWS)': 'KDK',
    'KATIWALA NG PUROK': 'KPR',
    'II KATIWALA NG PUROK': 'K2P',
    'KATIWALA NG GRUPO': 'KGR',
    'II KATIWALA NG GRUPO': 'K2G',
    'KALIHIM NG GRUPO': 'KLG',
    'DIAKONO': 'DKN',
    'DIAKONESA': 'DKS',
    'LUPON SA PAGPAPATIBAY': 'LPP',
    'ILAW NG KALIGTASAN': 'ILAW',
    'MANG-AAWIT': 'MWT',
    'ORGANISTA': 'ORG',
    'PANANALAPI': 'PAN',
    'KALIHIMAN': 'KLH',
    'BUKLOD': 'BUK',
    'KADIWA': 'KAD',
    'BINHI': 'BIN',
    'PNK': 'PNK',
    'GURO': 'GURO',
    'SCAN': 'SCN',
    'TSV': 'TSV',
    'CBI': 'CBI'
};

// Get department initial (first 3 letters uppercase if not in map)
function getDepartmentInitial(department) {
    const upper = department.trim().toUpperCase();
    
    // Check if exact match in mapping
    if (departmentInitials[upper]) {
        return departmentInitials[upper];
    }
    
    // Check if department starts with any key
    for (const [key, initial] of Object.entries(departmentInitials)) {
        if (upper.startsWith(key)) {
            return initial;
        }
    }
    
    // Default: take first 3 letters
    return upper.substring(0, 3);
}

// Generate file number when department is selected
async function generateFileNumber(department) {
    const initial = getDepartmentInitial(department);
    const year = new Date().getFullYear();
    const prefix = `${initial}-${year}-`;
    
    try {
        // Fetch next number from server
        const response = await fetch('<?php echo BASE_URL; ?>/api/get-next-callup-number.php?prefix=' + encodeURIComponent(prefix));
        const data = await response.json();
        
        if (data.success) {
            document.getElementById('file-number').value = data.file_number;
        } else {
            // Fallback to 001 if API fails
            document.getElementById('file-number').value = prefix + '001';
        }
    } catch (error) {
        console.error('Error generating file number:', error);
        // Fallback to 001
        document.getElementById('file-number').value = prefix + '001';
    }
}

// Department Modal
function openDepartmentModal() {
    const modal = document.getElementById('department-modal');
    modal.classList.remove('hidden');
    document.getElementById('department-search').focus();
}

function closeDepartmentModal() {
    const modal = document.getElementById('department-modal');
    modal.classList.add('hidden');
}

function selectDepartment(department) {
    document.getElementById('department-display').value = department;
    document.getElementById('department-value').value = department;
    closeDepartmentModal();
    
    // Auto-generate file number
    generateFileNumber(department);
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

<?php
$content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
