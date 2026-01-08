<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/encryption.php';

Security::requireLogin();

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

// Check permissions
$canManage = in_array($currentUser['role'], ['admin', 'district', 'local']);
if (!$canManage) {
    die('Unauthorized access');
}

$pageTitle = 'Overseers Contact Registry';

// Page actions
$pageActions = [];
if ($canManage) {
    $pageActions[] = '<button onclick="openAddModal()" class="inline-flex items-center justify-center px-3 sm:px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors shadow-sm text-xs sm:text-sm"><svg class="w-4 h-4 sm:mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg><span class="hidden sm:inline">Add Contact</span></button>';
}

ob_start();
?>

<!-- Load QRCode library before page content -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

<div class="space-y-6">
    <!-- Header -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-4 sm:p-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 sm:gap-4">
            <div>
                <h1 class="text-xl sm:text-2xl font-semibold text-gray-900 dark:text-gray-100">
                    <svg class="w-6 h-6 sm:w-7 sm:h-7 inline-block mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path>
                    </svg>
                    Overseers Contact Registry
                </h1>
                <p class="text-xs sm:text-sm text-gray-500 dark:text-gray-400 mt-1">
                    Contact information for Grupo and Purok level overseers
                </p>
            </div>
        </div>
    </div>

    <!-- Filter Section -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-4 sm:p-5">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
            <div>
                <label class="block text-xs sm:text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Type</label>
                <select id="filterType" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="">All Types</option>
                    <option value="grupo">Grupo Level</option>
                    <option value="purok">Purok Level</option>
                </select>
            </div>
            <div>
                <label class="block text-xs sm:text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">District</label>
                <select id="filterDistrict" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="">All Districts</option>
                </select>
            </div>
            <div>
                <label class="block text-xs sm:text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Local</label>
                <select id="filterLocal" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="">All Locals</option>
                </select>
            </div>
            <div>
                <label class="block text-xs sm:text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Search</label>
                <input type="text" id="searchBox" placeholder="Search..." class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
        </div>
    </div>

    <!-- Contacts Table -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-4 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Type</th>
                        <th class="px-4 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Area</th>
                        <th class="px-4 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Katiwala</th>
                        <th class="px-4 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">II Katiwala</th>
                        <th class="px-4 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Kalihim</th>
                        <th class="px-4 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody id="contactsTableBody" class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                    <!-- Populated by JavaScript -->
                </tbody>
            </table>
        </div>
    </div>
</div>

    <!-- Add/Edit Modal -->
    <div id="contactModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 overflow-y-auto">
        <div class="flex min-h-screen items-center justify-center p-4">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl w-full max-w-4xl max-h-[90vh] overflow-hidden flex flex-col">
                <!-- Modal Header - Fixed -->
                <div class="px-4 sm:px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                    <h3 class="text-lg sm:text-xl font-semibold text-gray-900 dark:text-gray-100" id="modalTitle">Add Contact</h3>
                    <button type="button" onclick="closeModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </button>
                </div>
                
                <!-- Modal Body - Scrollable -->
                <div class="overflow-y-auto flex-1">
                    <form id="contactForm" class="p-4 sm:p-6">
                        <input type="hidden" id="contactId" name="contactId">
                        
                        <!-- Type Selection -->
                        <div class="mb-4 sm:mb-6">
                            <label class="block text-xs sm:text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Select Type *</label>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 sm:gap-4">
                                <label class="flex items-start p-3 sm:p-4 border-2 border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:border-blue-500 dark:hover:border-blue-400 transition-colors">
                                    <input type="radio" name="contactType" value="grupo" class="mt-0.5 mr-3" required>
                                    <div>
                                        <div class="text-sm sm:text-base font-semibold text-gray-900 dark:text-gray-100">Grupo Level</div>
                                        <div class="text-xs sm:text-sm text-gray-600 dark:text-gray-400">Purok Grupo Overseers</div>
                                    </div>
                                </label>
                                <label class="flex items-start p-3 sm:p-4 border-2 border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:border-blue-500 dark:hover:border-blue-400 transition-colors">
                                    <input type="radio" name="contactType" value="purok" class="mt-0.5 mr-3" required>
                                    <div>
                                        <div class="text-sm sm:text-base font-semibold text-gray-900 dark:text-gray-100">Purok Level</div>
                                        <div class="text-xs sm:text-sm text-gray-600 dark:text-gray-400">Purok Overseers</div>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <!-- District and Local -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 sm:gap-4 mb-4">
                            <div>
                                <label class="block text-xs sm:text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 sm:mb-2">District *</label>
                                <select id="modalDistrict" required class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                    <option value="">Select District</option>
                                </select>
                                <input type="hidden" name="district" id="modalDistrictHidden">
                            </div>
                            <div>
                                <label class="block text-xs sm:text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 sm:mb-2">Local *</label>
                                <select id="modalLocal" required class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                    <option value="">Select Local</option>
                                </select>
                                <input type="hidden" name="local" id="modalLocalHidden">
                            </div>
                        </div>

                        <!-- Grupo/Purok Name -->
                        <div id="grupoNameField" class="mb-4 hidden">
                            <label class="block text-xs sm:text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 sm:mb-2">Purok Grupo *</label>
                            <input type="text" id="purokGrupo" name="purokGrupo" class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div id="purokNameField" class="mb-4 hidden">
                            <label class="block text-xs sm:text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 sm:mb-2">Purok *</label>
                            <input type="text" id="purok" name="purok" class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        </div>

                        <!-- Officer Positions -->
                        <div class="space-y-4">
                            <!-- Katiwala -->
                            <div class="border border-gray-300 dark:border-gray-600 rounded-lg p-3 sm:p-4">
                                <h4 class="text-sm sm:text-base font-semibold text-gray-900 dark:text-gray-100 mb-3">
                                    <span id="katiwalaLabel">Katiwala ng Grupo</span>
                                </h4>
                                <div class="space-y-3">
                                    <div>
                                        <label class="block text-xs sm:text-sm text-gray-700 dark:text-gray-300 mb-1">Officer Name</label>
                                        <input type="text" id="katiwalaName" name="katiwalaName" class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Enter officer name...">
                                    </div>
                                    <div>
                                        <label class="block text-xs sm:text-sm text-gray-700 dark:text-gray-300 mb-1">Contact Number</label>
                                        <input type="text" id="katiwalaContact" name="katiwalaContact" class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="+63 XXX XXX XXXX">
                                    </div>
                                    <div>
                                        <label class="block text-xs sm:text-sm text-gray-700 dark:text-gray-300 mb-1">Telegram Account</label>
                                        <input type="text" id="katiwalaTelegram" name="katiwalaTelegram" class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="@username">
                                    </div>
                                </div>
                            </div>

                            <!-- II Katiwala -->
                            <div class="border border-gray-300 dark:border-gray-600 rounded-lg p-3 sm:p-4">
                                <h4 class="text-sm sm:text-base font-semibold text-gray-900 dark:text-gray-100 mb-3">
                                    <span id="iiKatiwalaLabel">II Katiwala ng Grupo</span>
                                </h4>
                                <div class="space-y-3">
                                    <div>
                                        <label class="block text-xs sm:text-sm text-gray-700 dark:text-gray-300 mb-1">Officer Name</label>
                                        <input type="text" id="iiKatiwalaName" name="iiKatiwalaName" class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Enter officer name...">
                                    </div>
                                    <div>
                                        <label class="block text-xs sm:text-sm text-gray-700 dark:text-gray-300 mb-1">Contact Number</label>
                                        <input type="text" id="iiKatiwalaContact" name="iiKatiwalaContact" class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="+63 XXX XXX XXXX">
                                    </div>
                                    <div>
                                        <label class="block text-xs sm:text-sm text-gray-700 dark:text-gray-300 mb-1">Telegram Account</label>
                                        <input type="text" id="iiKatiwalaTelegram" name="iiKatiwalaTelegram" class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="@username">
                                    </div>
                                </div>
                            </div>

                            <!-- Kalihim -->
                            <div class="border border-gray-300 dark:border-gray-600 rounded-lg p-3 sm:p-4">
                                <h4 class="text-sm sm:text-base font-semibold text-gray-900 dark:text-gray-100 mb-3">
                                    <span id="kalihimLabel">Kalihim ng Grupo</span>
                                </h4>
                                <div class="space-y-3">
                                    <div>
                                        <label class="block text-xs sm:text-sm text-gray-700 dark:text-gray-300 mb-1">Officer Name</label>
                                        <input type="text" id="kalihimName" name="kalihimName" class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Enter officer name...">
                                    </div>
                                    <div>
                                        <label class="block text-xs sm:text-sm text-gray-700 dark:text-gray-300 mb-1">Contact Number</label>
                                        <input type="text" id="kalihimContact" name="kalihimContact" class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="+63 XXX XXX XXXX">
                                    </div>
                                    <div>
                                        <label class="block text-xs sm:text-sm text-gray-700 dark:text-gray-300 mb-1">Telegram Account</label>
                                        <input type="text" id="kalihimTelegram" name="kalihimTelegram" class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="@username">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                
                <!-- Modal Footer - Fixed -->
                <div class="px-4 sm:px-6 py-4 border-t border-gray-200 dark:border-gray-700 flex flex-col-reverse sm:flex-row sm:justify-end gap-2 sm:gap-3">
                    <button type="button" onclick="closeModal()" class="w-full sm:w-auto px-4 py-2 text-sm border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                        Cancel
                    </button>
                    <button type="submit" form="contactForm" class="w-full sm:w-auto px-4 py-2 text-sm bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors">
                        Save Contact
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- QR Code Modal -->
    <div id="qrModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-md w-full mx-4 p-6">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-semibold text-gray-900 dark:text-gray-100" id="qrModalTitle"></h3>
                <button onclick="closeQrModal()" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 transition-colors">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            <div id="qrCodeContainer" class="flex justify-center items-center bg-white p-4 rounded">
                <!-- QR code will be generated here -->
            </div>
            <p id="qrText" class="text-center mt-4 text-gray-700 dark:text-gray-300 font-mono text-sm break-all"></p>
            <div class="mt-4 flex justify-center">
                <button onclick="downloadQR()" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors text-sm">
                    <svg class="w-4 h-4 inline-block mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                    Download QR Code
                </button>
            </div>
        </div>
    </div>

    <script>
        let contacts = [];
        let districts = [];
        let locals = [];
        let currentQRData = null;

        // User data from PHP
        const currentUser = <?php echo json_encode([
            'role' => $currentUser['role'],
            'district_code' => $currentUser['district_code'] ?? null,
            'local_code' => $currentUser['local_code'] ?? null
        ]); ?>;

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            loadDistricts();
            loadContacts();
            setupEventListeners();
        });

        function setupEventListeners() {
            // Type radio buttons
            document.querySelectorAll('input[name="contactType"]').forEach(radio => {
                radio.addEventListener('change', function() {
                    updateFormLabels(this.value);
                });
            });

            // District change in modal
            document.getElementById('modalDistrict').addEventListener('change', function() {
                document.getElementById('modalDistrictHidden').value = this.value;
                loadLocalsForDistrict(this.value, 'modalLocal');
            });
            
            // Local change in modal
            document.getElementById('modalLocal').addEventListener('change', function() {
                document.getElementById('modalLocalHidden').value = this.value;
            });

            // Filter district change
            document.getElementById('filterDistrict').addEventListener('change', function() {
                loadLocalsForDistrict(this.value, 'filterLocal');
                filterContacts();
            });

            // Other filters
            document.getElementById('filterType').addEventListener('change', filterContacts);
            document.getElementById('filterLocal').addEventListener('change', filterContacts);
            document.getElementById('searchBox').addEventListener('input', filterContacts);

            // Form submission
            document.getElementById('contactForm').addEventListener('submit', handleFormSubmit);
        }

        function updateFormLabels(type) {
            const suffix = type === 'grupo' ? 'ng Grupo' : 'ng Purok';
            document.getElementById('katiwalaLabel').textContent = `Katiwala ${suffix}`;
            document.getElementById('iiKatiwalaLabel').textContent = `II Katiwala ${suffix}`;
            document.getElementById('kalihimLabel').textContent = `Kalihim ${suffix}`;

            // Show/hide appropriate fields
            if (type === 'grupo') {
                document.getElementById('grupoNameField').classList.remove('hidden');
                document.getElementById('purokNameField').classList.add('hidden');
                document.getElementById('purokGrupo').required = true;
                document.getElementById('purok').required = false;
            } else {
                document.getElementById('grupoNameField').classList.add('hidden');
                document.getElementById('purokNameField').classList.remove('hidden');
                document.getElementById('purokGrupo').required = false;
                document.getElementById('purok').required = true;
            }
        }

        async function loadDistricts() {
            try {
                const response = await fetch('api/get-districts.php');
                const data = await response.json();
                if (data.success) {
                    districts = data.districts;
                    populateDistrictSelects();
                    await autoSetFilters();
                }
            } catch (error) {
                console.error('Error loading districts:', error);
            }
        }

        function populateDistrictSelects() {
            const selects = ['filterDistrict', 'modalDistrict'];
            selects.forEach(selectId => {
                const select = document.getElementById(selectId);
                const currentValue = select.value;
                
                // Keep first option (All Districts / Select District)
                const firstOption = select.options[0];
                select.innerHTML = '';
                select.appendChild(firstOption);
                
                districts.forEach(district => {
                    const option = document.createElement('option');
                    option.value = district.district_code;
                    option.textContent = `${district.district_code} - ${district.district_name}`;
                    select.appendChild(option);
                });
                
                if (currentValue) {
                    select.value = currentValue;
                }
            });
        }

        async function autoSetFilters() {
            // Auto-set filters based on user role
            if (currentUser.role === 'district' && currentUser.district_code) {
                // District users: auto-select their district
                document.getElementById('filterDistrict').value = currentUser.district_code;
                document.getElementById('modalDistrict').value = currentUser.district_code;
                document.getElementById('modalDistrictHidden').value = currentUser.district_code;
                
                // Load locals for this district and wait
                await Promise.all([
                    loadLocalsForDistrict(currentUser.district_code, 'filterLocal'),
                    loadLocalsForDistrict(currentUser.district_code, 'modalLocal')
                ]);
                
                // Disable district selects
                document.getElementById('filterDistrict').disabled = true;
                document.getElementById('modalDistrict').disabled = true;
            } else if (currentUser.role === 'local' && currentUser.district_code && currentUser.local_code) {
                // Local users: auto-select their district and local
                document.getElementById('filterDistrict').value = currentUser.district_code;
                document.getElementById('modalDistrict').value = currentUser.district_code;
                document.getElementById('modalDistrictHidden').value = currentUser.district_code;
                
                // Load locals for this district and wait
                await Promise.all([
                    loadLocalsForDistrict(currentUser.district_code, 'filterLocal'),
                    loadLocalsForDistrict(currentUser.district_code, 'modalLocal')
                ]);
                
                // Set local values after locals are loaded
                document.getElementById('filterLocal').value = currentUser.local_code;
                document.getElementById('modalLocal').value = currentUser.local_code;
                document.getElementById('modalLocalHidden').value = currentUser.local_code;
                
                // Disable both selects
                document.getElementById('filterDistrict').disabled = true;
                document.getElementById('modalDistrict').disabled = true;
                document.getElementById('filterLocal').disabled = true;
                document.getElementById('modalLocal').disabled = true;
                
                // Trigger filter to show only their local's contacts
                filterContacts();
            }
            // Admin users: no restrictions, all options available
        }

        async function loadLocalsForDistrict(districtCode, selectId) {
            const select = document.getElementById(selectId);
            
            // Reset
            const firstOption = select.options[0];
            select.innerHTML = '';
            select.appendChild(firstOption);
            
            if (!districtCode) return;
            
            try {
                const response = await fetch(`api/get-locals.php?district=${districtCode}`);
                const data = await response.json();
                
                // Handle both response formats: direct array or {success: true, locals: [...]}
                const locals = Array.isArray(data) ? data : (data.locals || []);
                
                locals.forEach(local => {
                    const option = document.createElement('option');
                    option.value = local.local_code;
                    option.textContent = `${local.local_code} - ${local.local_name}`;
                    select.appendChild(option);
                });
            } catch (error) {
                console.error('Error loading locals:', error);
            }
        }

        async function loadContacts() {
            try {
                // Build query params based on user role
                let queryParams = '';
                if (currentUser.role === 'district' && currentUser.district_code) {
                    queryParams = `?district_code=${currentUser.district_code}`;
                } else if (currentUser.role === 'local' && currentUser.district_code && currentUser.local_code) {
                    queryParams = `?district_code=${currentUser.district_code}&local_code=${currentUser.local_code}`;
                }
                
                const response = await fetch(`api/overseers-contacts/list.php${queryParams}`);
                const data = await response.json();
                if (data.success) {
                    contacts = data.contacts;
                    renderContacts();
                }
            } catch (error) {
                console.error('Error loading contacts:', error);
            }
        }

        function renderContacts() {
            const tbody = document.getElementById('contactsTableBody');
            const filtered = getFilteredContacts();
            
            if (filtered.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">No contacts found</td></tr>';
                return;
            }
            
            tbody.innerHTML = filtered.map(contact => `
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                    <td class="px-4 sm:px-6 py-4 whitespace-nowrap">
                        <span class="px-2 py-1 text-xs font-semibold rounded ${contact.contact_type === 'grupo' ? 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200' : 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'}">
                            ${contact.contact_type === 'grupo' ? 'Grupo' : 'Purok'}
                        </span>
                    </td>
                    <td class="px-4 sm:px-6 py-4">
                        <div class="text-sm font-medium text-gray-900 dark:text-gray-100">
                            ${contact.contact_type === 'grupo' ? contact.purok_grupo : contact.purok}
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">
                            ${contact.local_name} (${contact.local_code})
                        </div>
                    </td>
                    <td class="px-4 sm:px-6 py-4">
                        ${renderOfficerCell(contact.katiwala_names, contact.katiwala_contact, contact.katiwala_telegram, 'Katiwala')}
                    </td>
                    <td class="px-4 sm:px-6 py-4">
                        ${renderOfficerCell(contact.ii_katiwala_names, contact.ii_katiwala_contact, contact.ii_katiwala_telegram, 'II Katiwala')}
                    </td>
                    <td class="px-4 sm:px-6 py-4">
                        ${renderOfficerCell(contact.kalihim_names, contact.kalihim_contact, contact.kalihim_telegram, 'Kalihim')}
                    </td>
                    <td class="px-4 sm:px-6 py-4 whitespace-nowrap">
                        <div class="flex items-center space-x-2">
                            <button onclick="viewContact(${contact.contact_id})" class="p-2 text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/20 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 rounded-lg transition-colors" title="View Details">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                            </button>
                            <button onclick="editContact(${contact.contact_id})" class="p-2 text-green-600 hover:bg-green-50 dark:hover:bg-green-900/20 hover:text-green-800 dark:text-green-400 dark:hover:text-green-300 rounded-lg transition-colors" title="Edit Contact">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                            </button>
                            <button onclick="deleteContact(${contact.contact_id})" class="p-2 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300 rounded-lg transition-colors" title="Delete Contact">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                            </button>
                        </div>
                    </td>
                </tr>
            `).join('');
        }

        function renderOfficerCell(names, contact, telegram, position) {
            if (!names && !contact && !telegram) {
                return '<span class="text-gray-400 dark:text-gray-500 text-sm">—</span>';
            }
            
            return `
                <div class="text-sm">
                    ${names ? `<div class="font-medium text-gray-900 dark:text-gray-100 mb-1">${names}</div>` : ''}
                    ${contact ? `
                        <div class="flex items-center text-gray-600 dark:text-gray-400 mb-1">
                            <svg class="w-3 h-3 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path></svg>
                            <span>${contact}</span>
                            <button onclick="showQR('${contact}', '${position} - Phone', 'tel:${contact}')" class="ml-2 text-blue-500 hover:text-blue-700 transition-colors" title="Show QR">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"></path></svg>
                            </button>
                        </div>
                    ` : ''}
                    ${telegram ? `
                        <div class="flex items-center text-gray-600 dark:text-gray-400">
                            <svg class="w-3 h-3 mr-2" fill="currentColor" viewBox="0 0 24 24"><path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.894 8.221l-1.97 9.28c-.145.658-.537.818-1.084.508l-3-2.21-1.446 1.394c-.14.18-.357.295-.6.295-.002 0-.003 0-.005 0l.213-3.054 5.56-5.022c.24-.213-.054-.334-.373-.121l-6.869 4.326-2.96-.924c-.64-.203-.658-.64.135-.954l11.566-4.458c.538-.196 1.006.128.832.941z"/></svg>
                            <span>${telegram}</span>
                            <button onclick="showQR('https://t.me/${telegram.replace('@', '')}', '${position} - Telegram', 'https://t.me/${telegram.replace('@', '')}')" class="ml-2 text-blue-500 hover:text-blue-700 transition-colors" title="Show QR">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"></path></svg>
                            </button>
                        </div>
                    ` : ''}
                </div>
            `;
        }

        function getFilteredContacts() {
            const type = document.getElementById('filterType').value;
            const district = document.getElementById('filterDistrict').value;
            const local = document.getElementById('filterLocal').value;
            const search = document.getElementById('searchBox').value.toLowerCase();
            
            return contacts.filter(contact => {
                // Enforce role-based restrictions
                if (currentUser.role === 'district' && currentUser.district_code) {
                    if (contact.district_code !== currentUser.district_code) return false;
                } else if (currentUser.role === 'local' && currentUser.district_code && currentUser.local_code) {
                    if (contact.district_code !== currentUser.district_code) return false;
                    if (contact.local_code !== currentUser.local_code) return false;
                }
                
                if (type && contact.contact_type !== type) return false;
                if (district && contact.district_code !== district) return false;
                if (local && contact.local_code !== local) return false;
                if (search) {
                    const searchIn = [
                        contact.purok_grupo,
                        contact.purok,
                        contact.local_name,
                        contact.katiwala_names,
                        contact.ii_katiwala_names,
                        contact.kalihim_names,
                        contact.katiwala_contact,
                        contact.katiwala_telegram
                    ].filter(Boolean).join(' ').toLowerCase();
                    
                    if (!searchIn.includes(search)) return false;
                }
                return true;
            });
        }

        function filterContacts() {
            renderContacts();
        }

        function openAddModal() {
            document.getElementById('modalTitle').textContent = 'Add Contact';
            document.getElementById('contactForm').reset();
            document.getElementById('contactId').value = '';
            
            // Restore district/local values for restricted users after reset
            if (currentUser.role === 'district' && currentUser.district_code) {
                document.getElementById('modalDistrict').value = currentUser.district_code;
                document.getElementById('modalDistrictHidden').value = currentUser.district_code;
                loadLocalsForDistrict(currentUser.district_code, 'modalLocal');
            } else if (currentUser.role === 'local' && currentUser.district_code && currentUser.local_code) {
                document.getElementById('modalDistrict').value = currentUser.district_code;
                document.getElementById('modalDistrictHidden').value = currentUser.district_code;
                loadLocalsForDistrict(currentUser.district_code, 'modalLocal').then(() => {
                    document.getElementById('modalLocal').value = currentUser.local_code;
                    document.getElementById('modalLocalHidden').value = currentUser.local_code;
                });
            }
            
            document.getElementById('contactModal').classList.remove('hidden');
        }

        function closeModal() {
            document.getElementById('contactModal').classList.add('hidden');
        }

        async function editContact(contactId) {
            const contact = contacts.find(c => c.contact_id === contactId);
            if (!contact) return;
            
            document.getElementById('modalTitle').textContent = 'Edit Contact';
            document.getElementById('contactId').value = contactId;
            
            // Set type
            document.querySelector(`input[name="contactType"][value="${contact.contact_type}"]`).checked = true;
            updateFormLabels(contact.contact_type);
            
            // Set district and local
            document.getElementById('modalDistrict').value = contact.district_code;
            document.getElementById('modalDistrictHidden').value = contact.district_code;
            await loadLocalsForDistrict(contact.district_code, 'modalLocal');
            document.getElementById('modalLocal').value = contact.local_code;
            document.getElementById('modalLocalHidden').value = contact.local_code;
            
            // Set area name
            if (contact.contact_type === 'grupo') {
                document.getElementById('purokGrupo').value = contact.purok_grupo;
            } else {
                document.getElementById('purok').value = contact.purok;
            }
            
            // Set officer data
            setOfficerData('katiwala', contact);
            setOfficerData('iiKatiwala', contact);
            setOfficerData('kalihim', contact);
            
            document.getElementById('contactModal').classList.remove('hidden');
        }

        function setOfficerData(prefix, contact) {
            const fieldMap = {
                'katiwala': 'katiwala',
                'iiKatiwala': 'ii_katiwala',
                'kalihim': 'kalihim'
            };
            
            const field = fieldMap[prefix];
            document.getElementById(`${prefix}Name`).value = contact[`${field}_names`] || '';
            document.getElementById(`${prefix}Contact`).value = contact[`${field}_contact`] || '';
            document.getElementById(`${prefix}Telegram`).value = contact[`${field}_telegram`] || '';
        }

        async function handleFormSubmit(e) {
            e.preventDefault();
            
            // Sync visible selects to hidden inputs before submission
            const districtValue = document.getElementById('modalDistrict').value;
            const localValue = document.getElementById('modalLocal').value;
            
            document.getElementById('modalDistrictHidden').value = districtValue;
            document.getElementById('modalLocalHidden').value = localValue;
            
            // Validate required fields
            if (!districtValue) {
                alert('Please select a district');
                return;
            }
            if (!localValue) {
                alert('Please select a local congregation');
                return;
            }
            
            const formData = new FormData(e.target);
            const data = Object.fromEntries(formData.entries());
            
            // Show loading state
            const submitBtn = e.submitter || e.target.querySelector('button[type="submit"]');
            const originalBtnText = submitBtn?.innerHTML;
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<svg class="animate-spin h-5 w-5 mx-auto" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>';
            }
            
            try {
                const url = data.contactId ? 'api/overseers-contacts/update.php' : 'api/overseers-contacts/create.php';
                const response = await fetch(url, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(data)
                });
                
                const result = await response.json();
                if (result.success) {
                    // Close modal immediately
                    closeModal();
                    
                    // Show success message with better styling
                    const message = data.contactId ? 'Contact updated successfully!' : 'Contact added successfully!';
                    showToast(message, 'success');
                    
                    // Reload contacts
                    await loadContacts();
                } else {
                    showToast('Error: ' + result.message, 'error');
                    // Re-enable button on error
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalBtnText;
                    }
                }
            } catch (error) {
                console.error('Error saving contact:', error);
                showToast('An error occurred while saving the contact.', 'error');
                // Re-enable button on error
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalBtnText;
                }
            }
        }
        
        function showToast(message, type = 'success') {
            const toast = document.createElement('div');
            const colors = {
                success: 'bg-green-500 text-white',
                error: 'bg-red-500 text-white',
                info: 'bg-blue-500 text-white'
            };
            toast.className = `fixed top-4 right-4 px-6 py-3 rounded-lg shadow-lg z-50 transform transition-all duration-300 ${colors[type] || colors.success}`;
            toast.textContent = message;
            document.body.appendChild(toast);
            
            // Fade in
            setTimeout(() => toast.style.opacity = '1', 10);
            
            // Remove after 3 seconds
            setTimeout(() => {
                toast.style.opacity = '0';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }

        async function deleteContact(contactId) {
            // Better confirmation dialog
            const contact = contacts.find(c => c.contact_id === contactId);
            const contactName = contact ? (contact.purok_grupo || contact.purok) : 'this contact';
            
            if (!confirm(`Are you sure you want to delete ${contactName}?\n\nThis action cannot be undone.`)) return;
            
            // Show loading state
            showToast('Deleting contact...', 'info');
            
            try {
                const response = await fetch('api/overseers-contacts/delete.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({contactId})
                });
                
                const result = await response.json();
                if (result.success) {
                    showToast('Contact deleted successfully!', 'success');
                    await loadContacts();
                } else {
                    showToast('Error: ' + result.message, 'error');
                }
            } catch (error) {
                console.error('Error deleting contact:', error);
                showToast('An error occurred while deleting the contact.', 'error');
            }
        }

        function showQR(data, title, url) {
            document.getElementById('qrModalTitle').textContent = title;
            document.getElementById('qrText').textContent = data;
            currentQRData = {data: url || data, title};
            
            const container = document.getElementById('qrCodeContainer');
            container.innerHTML = '';
            
            try {
                new QRCode(container, {
                    text: url || data,
                    width: 300,
                    height: 300,
                    colorDark: '#000000',
                    colorLight: '#ffffff',
                    correctLevel: QRCode.CorrectLevel.H
                });
                
                document.getElementById('qrModal').classList.remove('hidden');
            } catch (error) {
                console.error('Error generating QR:', error);
                showToast('Failed to generate QR code: ' + error.message, 'error');
            }
        }

        function closeQrModal() {
            document.getElementById('qrModal').classList.add('hidden');
        }

        function downloadQR() {
            if (!currentQRData) return;
            
            const img = document.querySelector('#qrCodeContainer img');
            if (!img) return;
            
            // Create a canvas from the image
            const canvas = document.createElement('canvas');
            canvas.width = 300;
            canvas.height = 300;
            const ctx = canvas.getContext('2d');
            
            // Draw white background
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, 300, 300);
            
            // Draw the image
            ctx.drawImage(img, 0, 0, 300, 300);
            
            const link = document.createElement('a');
            link.download = `${currentQRData.title.replace(/[^a-z0-9]/gi, '_')}_QR.png`;
            link.href = canvas.toDataURL();
            link.click();
        }

        function viewContact(contactId) {
            // Navigate to view page or open view modal
            window.location.href = `overseers-contacts-view.php?id=${contactId}`;
        }
    </script>
</body>
</html>

<?php
$content = ob_get_clean();
include __DIR__ . '/includes/layout.php';
?>
