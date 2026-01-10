<?php
/**
 * CFO Checker - Edit and verify CFO member information with full names
 */

require_once __DIR__ . '/config/config.php';

Security::requireLogin();

$currentUser = getCurrentUser();

// Restrict CFO Checker to admin and local accounts only
if ($currentUser['role'] !== 'admin' && $currentUser['role'] !== 'local') {
    header('Location: ' . BASE_URL . '/cfo-registry.php');
    exit();
}
$db = Database::getInstance()->getConnection();

$error = '';
$success = '';

$pageTitle = 'CFO Checker';
ob_start();
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900">CFO Checker</h1>
                <p class="text-sm text-gray-500 mt-1">Edit and verify CFO member information - Full names displayed</p>
            </div>
            <div class="flex gap-2">
                <a href="cfo-registry.php" class="inline-flex items-center px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    Back to Registry
                </a>
            </div>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4">
            <?php echo Security::escape($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4">
            <?php echo Security::escape($success); ?>
        </div>
    <?php endif; ?>
    
    <!-- Info Banner -->
    <div class="bg-blue-50 border-l-4 border-blue-500 p-4 mb-6 rounded-r-lg">
        <div class="flex items-start">
            <svg class="w-5 h-5 text-blue-500 mr-3 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
            </svg>
            <div class="flex-1">
                <h3 class="text-sm font-semibold text-blue-800">Full Data Editing Mode</h3>
                <p class="text-xs text-blue-700 mt-1">This page displays <strong>real names</strong> and allows full editing of member information including classification, status, purok/grupo, and personal details.</p>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
        <form id="filterForm" class="grid grid-cols-1 md:grid-cols-<?php 
            // Adjust grid based on role
            if ($currentUser['role'] === 'local' || $currentUser['role'] === 'local_cfo') {
                echo '4'; // Classification, Status, Missing Birthday, Apply button
            } elseif ($currentUser['role'] === 'district') {
                echo '5'; // Classification, Status, Missing Birthday, Local, Apply button
            } else {
                echo '6'; // All filters
            }
        ?> gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">CFO Classification</label>
                <select id="filterClassification" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="">All</option>
                    <option value="Buklod">Buklod</option>
                    <option value="Kadiwa">Kadiwa</option>
                    <option value="Binhi">Binhi</option>
                    <option value="null">Unclassified</option>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                <select id="filterStatus" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="">All</option>
                    <option value="active" selected>Active</option>
                    <option value="transferred-out">Transferred Out</option>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Missing Data</label>
                <div class="flex items-center h-10">
                    <label class="inline-flex items-center cursor-pointer">
                        <input type="checkbox" id="filterMissingBirthday" class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500 focus:ring-2">
                        <span class="ml-2 text-sm text-gray-700">Missing Birthday</span>
                    </label>
                </div>
            </div>
            
            <?php if ($currentUser['role'] === 'admin'): ?>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">District</label>
                <select id="filterDistrict" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="">All Districts</option>
                </select>
            </div>
            <?php elseif ($currentUser['role'] === 'district'): ?>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">District</label>
                <input type="text" id="filterDistrictDisplay" value="<?php 
                    try {
                        $stmt = $db->prepare("SELECT district_name FROM districts WHERE district_code = ?");
                        $stmt->execute([$currentUser['district_code']]);
                        $districtRow = $stmt->fetch();
                        echo Security::escape($districtRow ? $districtRow['district_name'] : '');
                    } catch (Exception $e) {}
                ?>" readonly class="w-full px-3 py-2 bg-gray-100 border border-gray-300 rounded-lg text-gray-600 cursor-not-allowed">
                <input type="hidden" id="filterDistrict" value="<?php echo Security::escape($currentUser['district_code']); ?>">
            </div>
            <?php endif; ?>
            
            <?php if ($currentUser['role'] === 'admin'): ?>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Local</label>
                <select id="filterLocal" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="">All Locals</option>
                </select>
            </div>
            <?php elseif ($currentUser['role'] === 'district'): ?>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Local</label>
                <select id="filterLocal" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="">All Locals</option>
                </select>
            </div>
            <?php elseif ($currentUser['role'] === 'local' || $currentUser['role'] === 'local_cfo'): ?>
            <input type="hidden" id="filterDistrict" value="<?php echo Security::escape($currentUser['district_code']); ?>">
            <input type="hidden" id="filterLocal" value="<?php echo Security::escape($currentUser['local_code']); ?>">
            <?php endif; ?>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">&nbsp;</label>
                <button type="button" onclick="applyFilters();" class="w-full px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                    Apply Filters
                </button>
            </div>
        </form>
    </div>

    <!-- CFO Checker Table -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
        <div class="p-4 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-900">CFO Members - Full Details</h2>
        </div>
        <div class="p-4">
            <div class="overflow-x-auto">
                <table id="cfoTable" class="display nowrap" style="width:100%">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Last Name</th>
                            <th>First Name</th>
                            <th>Middle Name</th>
                            <th>Registry Number</th>
                            <th>Husband's Surname</th>
                            <th>Birthday</th>
                            <th>CFO Classification</th>
                            <th>Status</th>
                            <th>Purok-Grupo</th>
                            <th>District</th>
                            <th>Local</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Edit CFO Modal -->
<div id="editModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4 transition-opacity duration-300">
    <div class="bg-white rounded-xl max-w-2xl w-full max-h-[90vh] overflow-y-auto shadow-2xl transform transition-all duration-300 scale-95" id="modalContent">
        <!-- Modal Header -->
        <div class="sticky top-0 bg-gradient-to-r from-blue-600 to-blue-700 p-6 rounded-t-xl z-10">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 bg-white bg-opacity-20 rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-xl font-bold text-white">Edit CFO Information</h3>
                        <p class="text-blue-100 text-sm">Update member details and classification</p>
                    </div>
                </div>
                <button onclick="closeEditModal()" class="text-white hover:bg-white hover:bg-opacity-20 rounded-lg p-2 transition-all duration-200">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
        </div>
        
        <form id="editForm" class="p-6 space-y-6 relative">
            <input type="hidden" id="edit_id" name="id">
            
            <!-- Member Info -->
            <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                <h4 class="text-sm font-semibold text-gray-700 mb-3 flex items-center">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                    </svg>
                    Member Information
                </h4>
                <div class="space-y-3">
                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">First Name <span class="text-red-600">*</span></label>
                            <input type="text" id="edit_first_name" name="first_name" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Middle Name</label>
                            <input type="text" id="edit_middle_name" name="middle_name" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Last Name <span class="text-red-600">*</span></label>
                            <input type="text" id="edit_last_name" name="last_name" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        </div>
                    </div>
                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">Registry Number</label>
                            <input type="text" id="edit_registry" readonly class="w-full px-3 py-2 border border-gray-200 rounded-lg bg-white text-gray-700 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Birthday</label>
                            <input type="date" id="edit_birthday" name="birthday" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Husband's Surname</label>
                            <input type="text" id="edit_husbands_surname" name="husbands_surname" placeholder="For married women" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">
                                <i class="fa-solid fa-map-location-dot mr-1 text-blue-600"></i>
                                Purok
                            </label>
                            <input type="text" id="edit_purok" name="purok" placeholder="e.g., 1, 2, 3" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">
                                <i class="fa-solid fa-users mr-1 text-green-600"></i>
                                Grupo
                            </label>
                            <input type="text" id="edit_grupo" name="grupo" placeholder="e.g., 7, 8, 9" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Editable Fields -->
            <div class="space-y-4">
                <!-- Registration Type Section -->
                <div class="bg-yellow-50 rounded-lg p-4 border border-yellow-200">
                    <h4 class="text-sm font-semibold text-gray-700 mb-3 flex items-center">
                        <svg class="w-4 h-4 mr-2 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        Registration Type
                    </h4>
                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Type</label>
                            <select id="edit_registration_type" name="registration_type" onchange="handleEditRegistrationTypeChange()" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                                <option value="">-- Not specified --</option>
                                <option value="transfer-in">Transfer-In</option>
                                <option value="newly-baptized">Newly Baptized</option>
                                <option value="others">Others (Specify)</option>
                            </select>
                        </div>
                        <div id="edit_registration_date_field">
                            <label class="block text-xs font-medium text-gray-700 mb-1">Registration Date</label>
                            <input type="date" id="edit_registration_date" name="registration_date" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                        </div>
                        <div id="edit_registration_others_field" style="display: none;">
                            <label class="block text-xs font-medium text-gray-700 mb-1">Specify</label>
                            <input type="text" id="edit_registration_others_specify" name="registration_others_specify" maxlength="255" placeholder="Please specify..." class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                        </div>
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
                        <svg class="w-4 h-4 mr-2 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                        </svg>
                        CFO Classification
                        <span class="text-red-600 ml-1">*</span>
                    </label>
                    <div class="flex gap-2">
                        <select id="edit_classification" name="cfo_classification" required onchange="handleClassificationChange()" class="flex-1 px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200">
                            <option value="">-- Select Classification --</option>
                            <option value="Buklod">💑 Buklod (Married Couples)</option>
                            <option value="Kadiwa">👥 Kadiwa (Youth 18+)</option>
                            <option value="Binhi">🌱 Binhi (Children under 18)</option>
                        </select>
                        <button type="button" id="lipatKapisananBtn" onclick="openLipatKapisananModal()" class="px-4 py-3 bg-purple-500 text-white rounded-lg hover:bg-purple-600 transition-colors flex items-center gap-2" title="Lipat-Kapisanan">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
                            </svg>
                            <span class="text-xs hidden md:inline">Lipat</span>
                        </button>
                    </div>
                    <input type="hidden" id="edit_marriage_date" name="marriage_date">
                    <input type="hidden" id="edit_classification_change_date" name="classification_change_date">
                    <input type="hidden" id="edit_classification_change_reason" name="classification_change_reason">
                    <div id="marriage_date_display" class="hidden mt-2 text-sm text-gray-600">
                        <span class="font-medium">Marriage Date:</span> <span id="marriage_date_text"></span>
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
                        <svg class="w-4 h-4 mr-2 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        Status
                        <span class="text-red-600 ml-1">*</span>
                    </label>
                    <div class="flex gap-2">
                        <select id="edit_status" name="cfo_status" required onchange="handleStatusChange()" class="flex-1 px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200">
                            <option value="active">✓ Active</option>
                            <option value="transferred-out">→ Transferred Out</option>
                        </select>
                        <button type="button" id="transferOutBtn" onclick="openTransferOutModal()" class="hidden px-4 py-3 bg-orange-500 text-white rounded-lg hover:bg-orange-600 transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
                            </svg>
                        </button>
                    </div>
                    <div id="transfer_out_date_display" class="hidden mt-2 text-sm text-gray-600">
                        <span class="font-medium">Transfer Out Date:</span> 
                        <span id="transfer_out_date_text"></span>
                        <input type="hidden" id="edit_transfer_out_date" name="transfer_out_date">
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
                        <svg class="w-4 h-4 mr-2 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"></path>
                        </svg>
                        Notes
                        <span class="text-gray-400 text-xs ml-2">(Optional)</span>
                    </label>
                    <textarea id="edit_notes" name="cfo_notes" rows="3" placeholder="Add any additional notes or remarks..." class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200 resize-none"></textarea>
                </div>
            </div>
            
            <!-- Action Buttons -->
            <div class="flex gap-3 pt-4 border-t border-gray-200">
                <button type="submit" id="saveBtn" class="flex-1 px-6 py-3 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-lg hover:from-blue-700 hover:to-blue-800 transition-all duration-200 font-semibold shadow-lg hover:shadow-xl transform hover:scale-105 flex items-center justify-center">
                    <svg class="w-5 h-5 mr-2" id="saveIcon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                    <span id="saveBtnText">Save Changes</span>
                    <svg class="animate-spin h-5 w-5 mr-2 hidden" id="saveSpinner" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                </button>
                <button type="button" onclick="closeEditModal()" class="px-6 py-3 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-all duration-200 font-semibold">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Transfer Out Modal -->
<div id="transferOutModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4 transition-opacity duration-300 opacity-0">
    <div id="transferOutModalContent" class="bg-white rounded-2xl shadow-2xl max-w-md w-full transform transition-all duration-300 scale-95">
        <!-- Modal Header -->
        <div class="bg-gradient-to-r from-orange-600 to-red-600 px-6 py-4 rounded-t-2xl">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 bg-white bg-opacity-20 rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-xl font-bold text-white">Transfer Out</h3>
                        <p class="text-orange-100 text-sm">Set transfer out date</p>
                    </div>
                </div>
                <button onclick="closeTransferOutModal()" class="text-white hover:bg-white hover:bg-opacity-20 rounded-lg p-2 transition-all duration-200">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
        </div>
        
        <div class="p-6">
            <div class="mb-4">
                <label class="block text-sm font-semibold text-gray-700 mb-2">
                    Transfer Out Date <span class="text-red-600">*</span>
                </label>
                <input type="date" id="transfer_out_date_input" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-orange-500">
                <p class="text-xs text-gray-500 mt-2">Select the date when the member was transferred out.</p>
            </div>
            
            <div class="flex gap-3 pt-4">
                <button type="button" onclick="confirmTransferOut()" class="flex-1 px-6 py-3 bg-gradient-to-r from-orange-600 to-red-600 text-white rounded-lg hover:from-orange-700 hover:to-red-700 transition-all duration-200 font-semibold">
                    Confirm Transfer Out
                </button>
                <button type="button" onclick="closeTransferOutModal()" class="px-6 py-3 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-all duration-200 font-semibold">
                    Cancel
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Success Toast -->
<div id="successToast" class="hidden fixed top-4 right-4 z-50 bg-green-500 text-white px-6 py-4 rounded-lg shadow-lg transform transition-all duration-300 translate-x-full">
    <div class="flex items-center space-x-3">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
        </svg>
        <span class="font-semibold" id="toastMessage">Success!</span>
    </div>
</div>

<!-- Error Toast -->
<div id="errorToast" class="hidden fixed top-4 right-4 z-50 bg-red-500 text-white px-6 py-4 rounded-lg shadow-lg transform transition-all duration-300 translate-x-full">
    <div class="flex items-center space-x-3">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
        </svg>
        <span class="font-semibold" id="errorMessage">Error occurred!</span>
    </div>
</div>

<!-- Transfer Out Modal -->
<div id="transferOutModal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-black bg-opacity-50 flex items-center justify-center p-4 transition-opacity duration-300 opacity-0">
    <div id="transferOutModalContent" class="bg-white rounded-lg shadow-xl max-w-md w-full transform transition-all duration-300 scale-95">
        <div class="bg-gradient-to-r from-orange-600 to-orange-700 p-6 rounded-t-lg">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 bg-white bg-opacity-20 rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"></path>
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-xl font-bold text-white">Transfer Out</h3>
                        <p class="text-orange-100 text-sm">Set transfer out date</p>
                    </div>
                </div>
                <button onclick="closeTransferOutModal()" class="text-white hover:bg-white hover:bg-opacity-20 rounded-lg p-2 transition-all duration-200">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
        </div>
        
        <div class="p-6">
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Transfer Out Date <span class="text-red-600">*</span>
                </label>
                <input type="date" id="transfer_out_date_input" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-orange-500">
                <p class="text-xs text-gray-500 mt-1">Date when member was transferred out</p>
            </div>
            
            <div class="flex gap-3 pt-4">
                <button onclick="confirmTransferOut()" class="flex-1 px-6 py-2 bg-orange-600 text-white rounded-lg hover:bg-orange-700 transition-colors font-medium">
                    Confirm Transfer Out
                </button>
                <button onclick="closeTransferOutModal()" class="px-6 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors font-medium">
                    Cancel
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Transfer In Modal -->
<div id="transferInModal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-black bg-opacity-50 flex items-center justify-center p-4 transition-opacity duration-300 opacity-0">
    <div id="transferInModalContent" class="bg-white rounded-lg shadow-xl max-w-md w-full transform transition-all duration-300 scale-95">
        <div class="bg-gradient-to-r from-green-600 to-green-700 p-6 rounded-t-lg">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 bg-white bg-opacity-20 rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path>
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-xl font-bold text-white">Transfer In</h3>
                        <p class="text-green-100 text-sm">Set transfer in date</p>
                    </div>
                </div>
                <button onclick="closeTransferInModal()" class="text-white hover:bg-white hover:bg-opacity-20 rounded-lg p-2 transition-all duration-200">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
        </div>
        
        <div class="p-6">
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Transfer In Date <span class="text-red-600">*</span>
                </label>
                <input type="date" id="transfer_in_date_input" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500">
                <p class="text-xs text-gray-500 mt-1">Date when member was transferred back in</p>
            </div>
            
            <div class="flex gap-3 pt-4">
                <button onclick="confirmTransferIn()" class="flex-1 px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors font-medium">
                    Confirm Transfer In
                </button>
                <button onclick="closeTransferInModal()" class="px-6 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors font-medium">
                    Cancel
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Lipat-Kapisanan Modal -->
<div id="lipatKapisananModal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-black bg-opacity-50 flex items-center justify-center p-4 transition-opacity duration-300 opacity-0">
    <div id="lipatKapisananModalContent" class="bg-white rounded-lg shadow-xl max-w-lg w-full transform transition-all duration-300 scale-95">
        <div class="bg-gradient-to-r from-purple-600 to-purple-700 p-6 rounded-t-lg">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 bg-white bg-opacity-20 rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-xl font-bold text-white">Lipat-Kapisanan</h3>
                        <p class="text-purple-100 text-sm">Change CFO classification</p>
                    </div>
                </div>
                <button onclick="closeLipatKapisananModal()" class="text-white hover:bg-white hover:bg-opacity-20 rounded-lg p-2 transition-all duration-200">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
        </div>
        
        <div class="p-6">
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Current Classification
                </label>
                <input type="text" id="lipat_current_classification" readonly class="w-full px-4 py-2 bg-gray-100 border border-gray-300 rounded-lg text-gray-700">
            </div>
            
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    New Classification <span class="text-red-600">*</span>
                </label>
                <select id="lipat_new_classification" onchange="handleLipatClassificationChange()" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                    <option value="">-- Select New Classification --</option>
                    <option value="Binhi">🌱 Binhi (Children under 18)</option>
                    <option value="Kadiwa">👥 Kadiwa (Youth 18+)</option>
                    <option value="Buklod">💑 Buklod (Married Couples)</option>
                </select>
            </div>
            
            <div id="lipat_marriage_date_field" class="mb-4 hidden">
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Marriage Date <span class="text-red-600">*</span>
                </label>
                <input type="date" id="lipat_marriage_date" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                <p class="text-xs text-gray-500 mt-1">Required when transferring to Buklod</p>
            </div>
            
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Change Date <span class="text-red-600">*</span>
                </label>
                <input type="date" id="lipat_change_date" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                <p class="text-xs text-gray-500 mt-1">Date of classification change</p>
            </div>
            
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Reason
                </label>
                <textarea id="lipat_reason" rows="2" placeholder="Optional reason for change..." class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500"></textarea>
            </div>
            
            <div class="flex gap-3 pt-4">
                <button onclick="confirmLipatKapisanan()" class="flex-1 px-6 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors font-medium">
                    Confirm Change
                </button>
                <button onclick="closeLipatKapisananModal()" class="px-6 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors font-medium">
                    Cancel
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Include DataTables -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>

<script>
let table;

function applyFilters() {
    table.ajax.reload();
}

$(document).ready(function() {
    // Initialize DataTable with REAL NAMES (not obfuscated)
    table = $('#cfoTable').DataTable({
        processing: true,
        serverSide: true,
        searchDelay: 500,
        ajax: {
            url: 'api/get-cfo-data.php',
            data: function(d) {
                d.classification = $('#filterClassification').val();
                d.status = $('#filterStatus').val();
                d.district = $('#filterDistrict').val();
                d.local = $('#filterLocal').val();
                d.missing_birthday = $('#filterMissingBirthday').is(':checked') ? '1' : '';
            },
            dataSrc: function(json) {
                return json.data;
            },
            error: function(xhr, error, code) {
                showError('Failed to load data. Please refresh the page.');
            }
        },
        columns: [
            { data: 'id' },
            { data: 'name_real' }, // Use REAL name
            { data: 'last_name_real', visible: false }, // Use REAL name
            { data: 'first_name_real', visible: false }, // Use REAL name
            { data: 'middle_name_real', visible: false }, // Use REAL name
            { data: 'registry_number' },
            { data: 'husbands_surname_real' }, // Use REAL name
            { data: 'birthday' },
            { data: 'cfo_classification' },
            { data: 'cfo_status' },
            { 
                data: 'purok_grupo',
                render: function(data, type, row) {
                    if (!data || data === '-') {
                        return '<span class="text-gray-400 text-xs">-</span>';
                    }
                    return '<span class="px-2 py-1 bg-indigo-50 text-indigo-700 rounded text-xs font-medium">' + data + '</span>';
                }
            },
            { data: 'district_name' },
            { data: 'local_name' },
            { 
                data: null,
                orderable: false,
                render: function(data, type, row) {
                    return `
                        <button onclick="editCFO(${row.id})" class="inline-flex items-center px-3 py-2 bg-blue-50 text-blue-700 rounded-lg hover:bg-blue-100 transition-all duration-200 text-sm font-medium" title="Edit">
                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                            </svg>
                            Edit
                        </button>
                    `;
                }
            }
        ],
        responsive: true,
        pageLength: 25,
        order: [[0, 'desc']],
        dom: 'Bfrtip',
        buttons: [
            {
                text: '<svg class="w-4 h-4 mr-2 inline" fill="currentColor" viewBox="0 0 20 20"><path d="M9 2a2 2 0 00-2 2v8a2 2 0 002 2h6a2 2 0 002-2V6.414A2 2 0 0016.414 5L14 2.586A2 2 0 0012.586 2H9z"></path><path d="M3 8a2 2 0 012-2v10h8a2 2 0 01-2 2H5a2 2 0 01-2-2V8z"></path></svg> Export to Excel',
                className: 'bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-medium transition-colors',
                action: function(e, dt, node, config) {
                    exportToExcel();
                }
            }
        ],
        language: {
            processing: '<div class="flex items-center justify-center"><svg class="animate-spin h-8 w-8 text-blue-600 mr-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg><span class="text-gray-700">Loading data...</span></div>',
            emptyTable: '<div class="text-center py-8"><svg class="w-16 h-16 mx-auto text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path></svg><p class="text-gray-500 text-lg font-medium">No CFO members found</p><p class="text-gray-400 text-sm">Start by adding members or adjusting your filters</p></div>'
        }
    });
    
    // Load districts and locals
    <?php if ($currentUser['role'] === 'district' || $currentUser['role'] === 'local' || $currentUser['role'] === 'local_cfo'): ?>
    const userDistrictCode = '<?php echo Security::escape($currentUser['district_code']); ?>';
    <?php if ($currentUser['role'] === 'district'): ?>
    if (userDistrictCode) {
        loadDistricts().then(() => {
            loadLocalsForDistrict(userDistrictCode);
        });
    }
    <?php endif; ?>
    <?php else: ?>
    loadDistricts();
    <?php endif; ?>
    
    $('#filterMissingBirthday').on('change', function() {
        applyFilters();
    });
});

function showSuccess(message) {
    const toast = $('#successToast');
    $('#toastMessage').text(message);
    toast.removeClass('hidden translate-x-full');
    setTimeout(() => {
        toast.addClass('translate-x-full');
        setTimeout(() => toast.addClass('hidden'), 300);
    }, 3000);
}

function showError(message) {
    const toast = $('#errorToast');
    $('#errorMessage').text(message);
    toast.removeClass('hidden translate-x-full');
    setTimeout(() => {
        toast.addClass('translate-x-full');
        setTimeout(() => toast.addClass('hidden'), 300);
    }, 5000);
}

async function loadDistricts() {
    try {
        const response = await fetch('api/get-districts.php');
        const result = await response.json();
        
        if (!result.success || !result.districts) {
            console.error('Failed to load districts:', result.message);
            return;
        }
        
        const data = result.districts;
        const filterDistrict = $('#filterDistrict');
        
        if (filterDistrict.is('select')) {
            let html = '<option value="">All Districts</option>';
            data.forEach(district => {
                html += `<option value="${district.district_code}">${district.district_name}</option>`;
            });
            filterDistrict.html(html);
        }
    } catch (error) {
        console.error('Error loading districts:', error);
    }
}

async function loadLocalsForDistrict(districtCode) {
    if (!districtCode) {
        const filterLocal = $('#filterLocal');
        if (filterLocal.is('select')) {
            filterLocal.html('<option value="">All Locals</option>');
        }
        return;
    }
    
    try {
        const response = await fetch('api/get-locals.php?district=' + districtCode);
        const data = await response.json();
        
        const filterLocal = $('#filterLocal');
        if (filterLocal.is('select')) {
            let html = '<option value="">All Locals</option>';
            data.forEach(local => {
                html += `<option value="${local.local_code}">${local.local_name}</option>`;
            });
            filterLocal.html(html);
        }
    } catch (error) {
        console.error('Error loading locals:', error);
    }
}

$('#filterDistrict').on('change', async function() {
    const districtCode = $(this).val();
    await loadLocalsForDistrict(districtCode);
});

async function editCFO(id) {
    const modal = $('#editModal');
    const content = $('#modalContent');
    
    modal.removeClass('hidden');
    setTimeout(() => {
        modal.removeClass('opacity-0');
        content.removeClass('scale-95').addClass('scale-100');
    }, 10);
    
    try {
        const response = await fetch('api/get-cfo-details.php?id=' + id);
        const data = await response.json();
        
        if (data.error) {
            showError(data.error);
            closeEditModal();
            return;
        }
        
        // Populate form with REAL data (not obfuscated)
        $('#edit_id').val(data.id);
        $('#edit_first_name').val(data.first_name || '');
        $('#edit_middle_name').val(data.middle_name || '');
        $('#edit_last_name').val(data.last_name || '');
        $('#edit_husbands_surname').val(data.husbands_surname || '');
        $('#edit_registry').val(data.registry_number);
        $('#edit_birthday').val(data.birthday_raw || '');
        $('#edit_purok').val(data.purok || '');
        $('#edit_grupo').val(data.grupo || '');
        $('#edit_classification').val(data.cfo_classification || '');
        $('#edit_status').val(data.cfo_status || 'active');
        $('#edit_notes').val(data.cfo_notes || '');
        
        // Populate registration type fields
        $('#edit_registration_type').val(data.registration_type || '');
        $('#edit_registration_date').val(data.registration_date || '');
        $('#edit_registration_others_specify').val(data.registration_others_specify || '');
        $('#edit_transfer_out_date').val(data.transfer_out_date || '');
        
        // Populate Lipat-Kapisanan fields
        $('#edit_marriage_date').val(data.marriage_date || '');
        $('#edit_classification_change_date').val(data.classification_change_date || '');
        $('#edit_classification_change_reason').val(data.classification_change_reason || '');
        
        // Update displays
        if (data.marriage_date) {
            $('#marriage_date_text').text(data.marriage_date);
            $('#marriage_date_display').removeClass('hidden');
        } else {
            $('#marriage_date_display').addClass('hidden');
        }
        
        // Update transfer out date display
        if (data.transfer_out_date) {
            $('#transfer_out_date_text').text(data.transfer_out_date);
            $('#transfer_out_date_display').removeClass('hidden');
        } else {
            $('#transfer_out_date_display').addClass('hidden');
        }
        
        // Set previous status for change tracking
        previousStatus = data.cfo_status || 'active';
        
        // Initialize field visibility
        handleEditRegistrationTypeChange();
    } catch (error) {
        console.error('Error loading CFO details:', error);
        showError('Error loading CFO details');
        closeEditModal();
    }
}

// Handle registration type field visibility in edit modal
function handleEditRegistrationTypeChange() {
    const registrationType = $('#edit_registration_type').val();
    const dateField = $('#edit_registration_date_field');
    const othersField = $('#edit_registration_others_field');
    
    // Always show date field for now, hide others field
    dateField.show();
    othersField.hide();
    
    // Show others field if type is 'others'
    if (registrationType === 'others') {
        othersField.show();
    }
}

// Handle status change to show/hide transfer out button
function handleStatusChange() {
    const status = $('#edit_status').val();
    const transferOutBtn = $('#transferOutBtn');
    const transferOutDisplay = $('#transfer_out_date_display');
    
    if (status === 'transferred-out') {
        transferOutBtn.removeClass('hidden');
        // Show transfer out date if it exists
        if ($('#edit_transfer_out_date').val()) {
            transferOutDisplay.removeClass('hidden');
        }
    } else {
        transferOutBtn.addClass('hidden');
        transferOutDisplay.addClass('hidden');
    }
}

// Open transfer out modal
function openTransferOutModal() {
    const modal = $('#transferOutModal');
    const content = $('#transferOutModalContent');
    
    // Set today's date as default
    const today = new Date().toISOString().split('T')[0];
    const existingDate = $('#edit_transfer_out_date').val();
    $('#transfer_out_date_input').val(existingDate || today);
    
    modal.removeClass('hidden');
    setTimeout(() => {
        modal.removeClass('opacity-0');
        content.removeClass('scale-95').addClass('scale-100');
    }, 10);
}

// Close transfer out modal
function closeTransferOutModal() {
    const modal = $('#transferOutModal');
    const content = $('#transferOutModalContent');
    
    content.removeClass('scale-100').addClass('scale-95');
    modal.addClass('opacity-0');
    
    setTimeout(() => {
        modal.addClass('hidden');
    }, 300);
}

// Confirm transfer out
function confirmTransferOut() {
    const transferDate = $('#transfer_out_date_input').val();
    
    if (!transferDate) {
        showError('Please select a transfer out date');
        return;
    }
    
    // Set the transfer out date in the main form
    $('#edit_transfer_out_date').val(transferDate);
    $('#transfer_out_date_text').text(transferDate);
    $('#transfer_out_date_display').removeClass('hidden');
    
    // Close the modal
    closeTransferOutModal();
    
    showSuccess('Transfer out date set to ' + transferDate);
}

// Transfer-In Modal Functions
function openTransferInModal() {
    const modal = $('#transferInModal');
    const content = $('#transferInModalContent');
    
    // Set today's date as default
    const today = new Date().toISOString().split('T')[0];
    $('#transfer_in_date_input').val(today);
    
    modal.removeClass('hidden');
    setTimeout(() => {
        modal.removeClass('opacity-0');
        content.removeClass('scale-95').addClass('scale-100');
    }, 10);
}

function closeTransferInModal() {
    const modal = $('#transferInModal');
    const content = $('#transferInModalContent');
    
    content.removeClass('scale-100').addClass('scale-95');
    modal.addClass('opacity-0');
    
    setTimeout(() => {
        modal.addClass('hidden');
        // Revert status to transferred-out if not confirmed
        if ($('#edit_status').val() === 'active' && !$('#edit_registration_date').val()) {
            $('#edit_status').val('transferred-out');
            previousStatus = 'transferred-out';
        }
    }, 300);
}

function confirmTransferIn() {
    const transferInDate = $('#transfer_in_date_input').val();
    
    if (!transferInDate) {
        showError('Please select a transfer in date');
        return;
    }
    
    // Update registration type and date
    $('#edit_registration_type').val('transfer-in');
    $('#edit_registration_date').val(transferInDate);
    
    // Clear transfer out date
    $('#edit_transfer_out_date').val('');
    $('#transfer_out_date_display').addClass('hidden');
    
    closeTransferInModal();
    showSuccess('Transfer in date set to ' + transferInDate);
}

// Lipat-Kapisanan Modal Functions
function openLipatKapisananModal() {
    const modal = $('#lipatKapisananModal');
    const content = $('#lipatKapisananModalContent');
    
    const currentClassification = $('#edit_classification').val();
    $('#lipat_current_classification').val(currentClassification || 'None');
    $('#lipat_new_classification').val('');
    $('#lipat_marriage_date').val('');
    $('#lipat_change_date').val(new Date().toISOString().split('T')[0]);
    $('#lipat_reason').val('');
    $('#lipat_marriage_date_field').addClass('hidden');
    
    modal.removeClass('hidden');
    setTimeout(() => {
        modal.removeClass('opacity-0');
        content.removeClass('scale-95').addClass('scale-100');
    }, 10);
}

function closeLipatKapisananModal() {
    const modal = $('#lipatKapisananModal');
    const content = $('#lipatKapisananModalContent');
    
    content.removeClass('scale-100').addClass('scale-95');
    modal.addClass('opacity-0');
    
    setTimeout(() => {
        modal.addClass('hidden');
    }, 300);
}

function handleLipatClassificationChange() {
    const newClassification = $('#lipat_new_classification').val();
    const marriageDateField = $('#lipat_marriage_date_field');
    
    if (newClassification === 'Buklod') {
        marriageDateField.removeClass('hidden');
        $('#lipat_marriage_date').attr('required', true);
    } else {
        marriageDateField.addClass('hidden');
        $('#lipat_marriage_date').removeAttr('required');
    }
}

function confirmLipatKapisanan() {
    const newClassification = $('#lipat_new_classification').val();
    const marriageDate = $('#lipat_marriage_date').val();
    const changeDate = $('#lipat_change_date').val();
    const reason = $('#lipat_reason').val();
    
    if (!newClassification) {
        showError('Please select a new classification');
        return;
    }
    
    if (!changeDate) {
        showError('Please select a change date');
        return;
    }
    
    if (newClassification === 'Buklod' && !marriageDate) {
        showError('Marriage date is required for Buklod classification');
        return;
    }
    
    // Update form fields
    $('#edit_classification').val(newClassification);
    $('#edit_classification_change_date').val(changeDate);
    $('#edit_classification_change_reason').val(reason);
    
    if (newClassification === 'Buklod' && marriageDate) {
        $('#edit_marriage_date').val(marriageDate);
        $('#marriage_date_display').removeClass('hidden');
        $('#marriage_date_text').text(marriageDate);
    }
    
    closeLipatKapisananModal();
    showSuccess('Classification changed to ' + newClassification);
}

// Handle classification change
function handleClassificationChange() {
    const classification = $('#edit_classification').val();
    // You can add logic here if needed
}

function closeEditModal() {
    const modal = $('#editModal');
    const content = $('#modalContent');
    
    content.removeClass('scale-100').addClass('scale-95');
    modal.addClass('opacity-0');
    
    setTimeout(() => {
        modal.addClass('hidden');
        $('#editForm')[0].reset();
    }, 300);
}

$('#editForm').on('submit', async function(e) {
    e.preventDefault();
    
    const saveBtn = $('#saveBtn');
    const saveBtnText = $('#saveBtnText');
    const saveIcon = $('#saveIcon');
    const saveSpinner = $('#saveSpinner');
    
    saveBtn.prop('disabled', true).removeClass('hover:scale-105');
    saveBtnText.text('Saving...');
    saveIcon.addClass('hidden');
    saveSpinner.removeClass('hidden');
    
    const formData = new FormData(this);
    
    try {
        const response = await fetch('api/update-cfo.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showSuccess('CFO information updated successfully');
            closeEditModal();
            table.ajax.reload(null, false);
        } else {
            showError(result.error || 'Error updating CFO information');
        }
    } catch (error) {
        console.error('Error updating CFO:', error);
        showError('Error updating CFO information');
    } finally {
        saveBtn.prop('disabled', false).addClass('hover:scale-105');
        saveBtnText.text('Save Changes');
        saveIcon.removeClass('hidden');
        saveSpinner.addClass('hidden');
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeEditModal();
    }
});

$('#editModal').on('click', function(e) {
    if (e.target === this) {
        closeEditModal();
    }
});

function exportToExcel() {
    const filters = {
        classification: $('#filterClassification').val(),
        status: $('#filterStatus').val(),
        district: $('#filterDistrict').val(),
        local: $('#filterLocal').val(),
        search: table.search()
    };
    
    const params = new URLSearchParams();
    if (filters.classification) params.append('classification', filters.classification);
    if (filters.status) params.append('status', filters.status);
    if (filters.district) params.append('district', filters.district);
    if (filters.local) params.append('local', filters.local);
    if (filters.search) params.append('search', filters.search);
    
    window.location.href = '<?php echo BASE_URL; ?>/api/export-cfo-excel.php?' + params.toString();
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/includes/layout.php';
?>
