<?php
/**
 * CFO Checker - Edit and verify CFO member information with full names
 */

// Generate nonce for inline scripts (CSP)
if (!isset($csp_nonce)) {
    $csp_nonce = base64_encode(random_bytes(16));
}

require_once __DIR__ . '/config/config.php';

Security::requireLogin();

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

// Check access permissions
$hasEditAccess = false;
$approvedCfoTypes = [];

if ($currentUser['role'] === 'admin' || $currentUser['role'] === 'local') {
    // Admin and local have full access
    $hasEditAccess = true;
} elseif ($currentUser['role'] === 'local_cfo') {
    // Check for approved edit_member access
    $stmt = $db->prepare("
        SELECT * FROM cfo_access_requests 
        WHERE requester_user_id = ? 
        AND status = 'approved'
        AND access_mode = 'edit_member'
        AND deleted_at IS NULL
        AND (expires_at IS NULL OR expires_at > NOW())
    ");
    $stmt->execute([$currentUser['user_id']]);
    $approvedRequests = $stmt->fetchAll();
    
    if (count($approvedRequests) > 0) {
        $hasEditAccess = true;
        foreach ($approvedRequests as $request) {
            if (!in_array($request['cfo_type'], $approvedCfoTypes)) {
                $approvedCfoTypes[] = $request['cfo_type'];
            }
        }
    }
}

// Restrict access if no edit permission
if (!$hasEditAccess) {
    header('Location: ' . BASE_URL . '/cfo-registry.php?error=' . urlencode('You need approved edit access to use CFO Checker.'));
    exit();
}

$error = '';
$success = '';

// Disable loading overlay for this page
$noLoadingOverlay = true;

$pageTitle = 'CFO Checker';
ob_start();
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900 dark:text-gray-100">CFO Checker</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Edit and verify CFO member information - Full names displayed</p>
            </div>
            <div class="flex gap-2">
                <a href="cfo-registry.php" class="inline-flex items-center px-4 py-2 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    Back to Registry
                </a>
            </div>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-3 rounded-lg mb-4">
            <?php echo Security::escape($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-300 px-4 py-3 rounded-lg mb-4">
            <?php echo Security::escape($success); ?>
        </div>
    <?php endif; ?>

    <?php if ($currentUser['role'] === 'local_cfo'): ?>
    <!-- Workflow Stepper for Local CFO Users -->
    <div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg p-6">
        <h3 class="text-lg font-semibold text-yellow-800 dark:text-yellow-200 mb-4">📋 Edit Workflow</h3>
        <div class="flex items-center justify-between relative">
            <!-- Step 1: Submit -->
            <div class="flex flex-col items-center z-10">
                <div class="w-10 h-10 bg-yellow-600 text-white rounded-full flex items-center justify-center font-bold">1</div>
                <span class="text-sm text-yellow-700 dark:text-yellow-300 mt-2 text-center font-medium">Submit Edit</span>
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
                <span class="text-sm text-gray-600 dark:text-gray-400 mt-2 text-center font-medium">Approved &<br>Applied to Registry</span>
            </div>
        </div>
        <p class="text-sm text-yellow-700 dark:text-yellow-300 mt-4">
            <strong>Note:</strong> Your edits will be reviewed by your local LORC/LCRC before being applied.
            <a href="pending-actions.php" class="underline hover:text-yellow-800 dark:hover:text-yellow-200">View your pending edits →</a>
        </p>
    </div>
    <?php endif; ?>
    
    <!-- Info Banner -->
    <div class="bg-blue-50 dark:bg-blue-900/20 border-l-4 border-blue-500 dark:border-blue-700 p-4 mb-6 rounded-r-lg">
        <div class="flex items-start">
            <svg class="w-5 h-5 text-blue-500 dark:text-blue-400 mr-3 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
            </svg>
            <div class="flex-1">
                <h3 class="text-sm font-semibold text-blue-800 dark:text-blue-300">Full Data Editing Mode</h3>
                <p class="text-xs text-blue-700 dark:text-blue-400 mt-1">This page displays <strong>real names</strong> and allows full editing of member information including classification, status, purok/grupo, and personal details.</p>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-4">
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
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">CFO Classification</label>
                <select id="filterClassification" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                    <option value="">All</option>
                    <?php if ($currentUser['role'] === 'local_cfo'): ?>
                        <?php if (in_array('Buklod', $approvedCfoTypes)): ?>
                            <option value="Buklod">Buklod</option>
                        <?php endif; ?>
                        <?php if (in_array('Kadiwa', $approvedCfoTypes)): ?>
                            <option value="Kadiwa">Kadiwa</option>
                        <?php endif; ?>
                        <?php if (in_array('Binhi', $approvedCfoTypes)): ?>
                            <option value="Binhi">Binhi</option>
                        <?php endif; ?>
                    <?php else: ?>
                        <option value="Buklod">Buklod</option>
                        <option value="Kadiwa">Kadiwa</option>
                        <option value="Binhi">Binhi</option>
                        <option value="null">Unclassified</option>
                    <?php endif; ?>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Status</label>
                <select id="filterStatus" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
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

    <!-- CFO Checker Table - Tailwind -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden"
         x-data="cfoCheckerTable()"
         x-init="init()">
        
        <!-- Table Header with Search and Export -->
        <div class="p-4 border-b border-gray-200 dark:border-gray-700">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">CFO Members - Full Details</h2>
                <div class="flex flex-col sm:flex-row gap-3">
                    <!-- Search Input -->
                    <div class="relative">
                        <input type="text" 
                               x-model="searchQuery" 
                               @input.debounce.500ms="search()"
                               placeholder="Search members..."
                               class="w-full sm:w-64 pl-10 pr-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <svg class="absolute left-3 top-2.5 w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                        <button x-show="searchQuery.length > 0" @click="searchQuery = ''; search()" class="absolute right-3 top-2.5 text-gray-400 hover:text-gray-600">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                    <!-- Page Size -->
                    <select x-model="pageSize" @change="changePageSize()" 
                            class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500">
                        <option value="10">10 per page</option>
                        <option value="25">25 per page</option>
                        <option value="50">50 per page</option>
                        <option value="100">100 per page</option>
                    </select>
                    <!-- Export Button -->
                    <button @click="exportToExcel()" 
                            class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors font-medium">
                        <svg class="w-4 h-4 mr-2" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M9 2a2 2 0 00-2 2v8a2 2 0 002 2h6a2 2 0 002-2V6.414A2 2 0 0016.414 5L14 2.586A2 2 0 0012.586 2H9z"></path>
                            <path d="M3 8a2 2 0 012-2v10h8a2 2 0 01-2 2H5a2 2 0 01-2-2V8z"></path>
                        </svg>
                        Export Excel
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Table Content -->
        <div class="overflow-x-auto">
            <!-- Loading State -->
            <div x-show="loading" class="flex items-center justify-center py-12">
                <svg class="animate-spin h-8 w-8 text-blue-600 mr-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span class="text-gray-700 dark:text-gray-300">Loading data...</span>
            </div>
            
            <!-- Error State -->
            <div x-show="error && !loading" class="flex flex-col items-center justify-center py-12 text-center">
                <svg class="w-16 h-16 text-red-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <p class="text-red-600 dark:text-red-400 font-medium" x-text="error"></p>
                <button @click="fetchData()" class="mt-4 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                    Try Again
                </button>
            </div>
            
            <!-- Table -->
            <table x-show="!loading && !error" class="w-full">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th @click="sortBy('id')" class="cursor-pointer px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wider hover:bg-gray-100 dark:hover:bg-gray-600">
                            <div class="flex items-center gap-1">
                                ID
                                <template x-if="sortColumn === 'id'">
                                    <svg :class="{ 'rotate-180': sortDirection === 'asc' }" class="w-4 h-4 transition-transform" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                                    </svg>
                                </template>
                            </div>
                        </th>
                        <th @click="sortBy('name')" class="cursor-pointer px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wider hover:bg-gray-100 dark:hover:bg-gray-600">
                            <div class="flex items-center gap-1">
                                Full Name
                                <template x-if="sortColumn === 'name'">
                                    <svg :class="{ 'rotate-180': sortDirection === 'asc' }" class="w-4 h-4 transition-transform" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                                    </svg>
                                </template>
                            </div>
                        </th>
                        <th @click="sortBy('registry_number')" class="cursor-pointer px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wider hover:bg-gray-100 dark:hover:bg-gray-600">
                            <div class="flex items-center gap-1">
                                Registry #
                                <template x-if="sortColumn === 'registry_number'">
                                    <svg :class="{ 'rotate-180': sortDirection === 'asc' }" class="w-4 h-4 transition-transform" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                                    </svg>
                                </template>
                            </div>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wider">Husband's Surname</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wider">Birthday</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wider">Classification</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wider">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wider">Purok-Grupo</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wider">Local</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    <template x-for="member in members" :key="member.id">
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
                            <td class="px-4 py-3 text-sm text-gray-900 dark:text-gray-100 font-mono" x-text="member.id"></td>
                            <td class="px-4 py-3 text-sm text-gray-900 dark:text-gray-100 font-medium" x-text="member.name_real || member.name"></td>
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 font-mono" x-text="member.registry_number || '-'"></td>
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400" x-text="member.husbands_surname_real || '-'"></td>
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400" x-text="member.birthday || '-'"></td>
                            <td class="px-4 py-3">
                                <span x-show="member.cfo_classification === 'Buklod'" class="px-2 py-1 bg-purple-100 dark:bg-purple-900/50 text-purple-700 dark:text-purple-300 rounded text-xs font-medium">💑 Buklod</span>
                                <span x-show="member.cfo_classification === 'Kadiwa'" class="px-2 py-1 bg-green-100 dark:bg-green-900/50 text-green-700 dark:text-green-300 rounded text-xs font-medium">👥 Kadiwa</span>
                                <span x-show="member.cfo_classification === 'Binhi'" class="px-2 py-1 bg-orange-100 dark:bg-orange-900/50 text-orange-700 dark:text-orange-300 rounded text-xs font-medium">👶 Binhi</span>
                                <span x-show="!member.cfo_classification" class="px-2 py-1 bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 rounded text-xs">Unclassified</span>
                            </td>
                            <td class="px-4 py-3">
                                <span x-show="member.cfo_status === 'active'" class="px-2 py-1 bg-green-100 dark:bg-green-900/50 text-green-700 dark:text-green-300 rounded text-xs font-medium">✓ Active</span>
                                <span x-show="member.cfo_status === 'transferred-out'" class="px-2 py-1 bg-red-100 dark:bg-red-900/50 text-red-700 dark:text-red-300 rounded text-xs font-medium">→ Transferred</span>
                            </td>
                            <td class="px-4 py-3">
                                <span x-show="member.purok_grupo && member.purok_grupo !== '-'" class="px-2 py-1 bg-indigo-50 dark:bg-indigo-900/50 text-indigo-700 dark:text-indigo-300 rounded text-xs font-medium" x-text="member.purok_grupo"></span>
                                <span x-show="!member.purok_grupo || member.purok_grupo === '-'" class="text-gray-400 text-xs">-</span>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400" x-text="member.local_name || '-'"></td>
                            <td class="px-4 py-3 text-center">
                                <button @click="editCFO(member.id)" 
                                        class="inline-flex items-center px-3 py-1.5 bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 rounded-lg hover:bg-blue-100 dark:hover:bg-blue-900/50 transition-all text-sm font-medium">
                                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                    </svg>
                                    Edit
                                </button>
                            </td>
                        </tr>
                    </template>
                    
                    <!-- Empty State -->
                    <tr x-show="members.length === 0 && !loading && !error">
                        <td colspan="10" class="px-4 py-12 text-center">
                            <svg class="w-16 h-16 mx-auto text-gray-300 dark:text-gray-600 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                            </svg>
                            <p class="text-gray-500 dark:text-gray-400 text-lg font-medium">No CFO members found</p>
                            <p class="text-gray-400 dark:text-gray-500 text-sm mt-1">Try adjusting your filters or search query</p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <div x-show="!loading && !error && totalRecords > 0" class="px-4 py-3 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-700/50">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div class="text-sm text-gray-600 dark:text-gray-400">
                    Showing <span class="font-medium" x-text="((currentPage - 1) * pageSize) + 1"></span> to 
                    <span class="font-medium" x-text="Math.min(currentPage * pageSize, totalRecords)"></span> of 
                    <span class="font-medium" x-text="totalRecords.toLocaleString()"></span> results
                </div>
                <div class="flex items-center gap-2">
                    <button @click="goToPage(1)" :disabled="currentPage === 1" 
                            class="px-3 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-600 disabled:opacity-50 disabled:cursor-not-allowed text-gray-700 dark:text-gray-300">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"></path>
                        </svg>
                    </button>
                    <button @click="goToPage(currentPage - 1)" :disabled="currentPage === 1" 
                            class="px-3 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-600 disabled:opacity-50 disabled:cursor-not-allowed text-gray-700 dark:text-gray-300">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                        </svg>
                    </button>
                    
                    <!-- Page Numbers -->
                    <template x-for="page in visiblePages" :key="page">
                        <button @click="goToPage(page)" 
                                :class="page === currentPage ? 'bg-blue-600 text-white border-blue-600' : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-600'"
                                class="px-3 py-1.5 text-sm border rounded-lg font-medium"
                                x-text="page">
                        </button>
                    </template>
                    
                    <button @click="goToPage(currentPage + 1)" :disabled="currentPage >= totalPages" 
                            class="px-3 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-600 disabled:opacity-50 disabled:cursor-not-allowed text-gray-700 dark:text-gray-300">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                        </svg>
                    </button>
                    <button @click="goToPage(totalPages)" :disabled="currentPage >= totalPages" 
                            class="px-3 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-600 disabled:opacity-50 disabled:cursor-not-allowed text-gray-700 dark:text-gray-300">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7"></path>
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Edit CFO Modal -->
<div id="editModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4 transition-opacity duration-300">
    <div class="bg-white dark:bg-gray-800 rounded-xl max-w-2xl w-full max-h-[90vh] overflow-y-auto shadow-2xl transform transition-all duration-300 scale-95" id="modalContent">
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
                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Registry Number</label>
                            <input type="text" id="edit_registry" readonly class="w-full px-3 py-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 text-sm">
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
                            <?php if ($currentUser['role'] === 'local_cfo'): ?>
                                <?php if (in_array('Buklod', $approvedCfoTypes)): ?>
                                    <option value="Buklod">💑 Buklod (Married Couples)</option>
                                <?php endif; ?>
                                <?php if (in_array('Kadiwa', $approvedCfoTypes)): ?>
                                    <option value="Kadiwa">👥 Kadiwa (Youth 18+)</option>
                                <?php endif; ?>
                                <?php if (in_array('Binhi', $approvedCfoTypes)): ?>
                                    <option value="Binhi">🌱 Binhi (Children under 18)</option>
                                <?php endif; ?>
                            <?php else: ?>
                                <option value="Buklod">💑 Buklod (Married Couples)</option>
                                <option value="Kadiwa">👥 Kadiwa (Youth 18+)</option>
                                <option value="Binhi">🌱 Binhi (Children under 18)</option>
                            <?php endif; ?>
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
<div id="successToast" class="hidden fixed top-4 right-4 z-50 bg-green-500 dark:bg-green-600 text-white px-6 py-4 rounded-lg shadow-lg transform transition-all duration-300 translate-x-full">
    <div class="flex items-center space-x-3">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
        </svg>
        <span class="font-semibold" id="toastMessage">Success!</span>
    </div>
</div>

<!-- Error Toast -->
<div id="errorToast" class="hidden fixed top-4 right-4 z-50 bg-red-500 dark:bg-red-600 text-white px-6 py-4 rounded-lg shadow-lg transform transition-all duration-300 translate-x-full">
    <div class="flex items-center space-x-3">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
        </svg>
        <span class="font-semibold" id="errorMessage">Error occurred!</span>
    </div>
</div>

<!-- Transfer Out Modal -->
<div id="transferOutModal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-black bg-opacity-50 flex items-center justify-center p-4 transition-opacity duration-300 opacity-0">
    <div id="transferOutModalContent" class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-md w-full transform transition-all duration-300 scale-95">
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
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Transfer Out Date <span class="text-red-600">*</span>
                </label>
                <input type="date" id="transfer_out_date_input" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-orange-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Date when member was transferred out</p>
            </div>
            
            <div class="flex gap-3 pt-4">
                <button onclick="confirmTransferOut()" class="flex-1 px-6 py-2 bg-orange-600 text-white rounded-lg hover:bg-orange-700 transition-colors font-medium">
                    Confirm Transfer Out
                </button>
                <button onclick="closeTransferOutModal()" class="px-6 py-2 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors font-medium">
                    Cancel
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Transfer In Modal -->
<div id="transferInModal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-black bg-opacity-50 flex items-center justify-center p-4 transition-opacity duration-300 opacity-0">
    <div id="transferInModalContent" class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-md w-full transform transition-all duration-300 scale-95">
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
    <div id="lipatKapisananModalContent" class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-lg w-full transform transition-all duration-300 scale-95">
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
                    <?php if ($currentUser['role'] === 'local_cfo'): ?>
                        <?php if (in_array('Binhi', $approvedCfoTypes)): ?>
                            <option value="Binhi">🌱 Binhi (Children under 18)</option>
                        <?php endif; ?>
                        <?php if (in_array('Kadiwa', $approvedCfoTypes)): ?>
                            <option value="Kadiwa">👥 Kadiwa (Youth 18+)</option>
                        <?php endif; ?>
                        <?php if (in_array('Buklod', $approvedCfoTypes)): ?>
                            <option value="Buklod">💑 Buklod (Married Couples)</option>
                        <?php endif; ?>
                    <?php else: ?>
                        <option value="Binhi">🌱 Binhi (Children under 18)</option>
                        <option value="Kadiwa">👥 Kadiwa (Youth 18+)</option>
                        <option value="Buklod">💑 Buklod (Married Couples)</option>
                    <?php endif; ?>
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

<!-- Alpine.js Table Script -->
<script nonce="<?php echo $csp_nonce; ?>">
const approvedCfoTypes = <?php echo json_encode($approvedCfoTypes); ?>;
const isLocalCfo = <?php echo ($currentUser['role'] === 'local_cfo') ? 'true' : 'false'; ?>;
const baseUrl = '<?php echo BASE_URL; ?>';
let previousStatus = 'active';

// Alpine.js CFO Checker Table Component
function cfoCheckerTable() {
    return {
        members: [],
        loading: true,
        error: null,
        searchQuery: '',
        currentPage: 1,
        pageSize: 25,
        totalRecords: 0,
        totalPages: 0,
        sortColumn: 'id',
        sortDirection: 'desc',
        
        get visiblePages() {
            const pages = [];
            const maxVisible = 5;
            let start = Math.max(1, this.currentPage - Math.floor(maxVisible / 2));
            let end = Math.min(this.totalPages, start + maxVisible - 1);
            
            if (end - start + 1 < maxVisible) {
                start = Math.max(1, end - maxVisible + 1);
            }
            
            for (let i = start; i <= end; i++) {
                pages.push(i);
            }
            return pages;
        },
        
        init() {
            this.fetchData();
            this.loadDistricts();
            
            // Listen for filter changes
            document.getElementById('filterClassification')?.addEventListener('change', () => this.applyFilters());
            document.getElementById('filterStatus')?.addEventListener('change', () => this.applyFilters());
            document.getElementById('filterMissingBirthday')?.addEventListener('change', () => this.applyFilters());
        },
        
        getFilters() {
            return {
                classification: document.getElementById('filterClassification')?.value || '',
                status: document.getElementById('filterStatus')?.value || '',
                district: document.getElementById('filterDistrict')?.value || '',
                local: document.getElementById('filterLocal')?.value || '',
                missing_birthday: document.getElementById('filterMissingBirthday')?.checked ? '1' : '',
                approved_cfo_types: isLocalCfo && approvedCfoTypes.length > 0 ? approvedCfoTypes.join(',') : ''
            };
        },
        
        async fetchData() {
            this.loading = true;
            this.error = null;
            
            const filters = this.getFilters();
            const params = new URLSearchParams({
                draw: 1,
                start: (this.currentPage - 1) * this.pageSize,
                length: this.pageSize,
                'search[value]': this.searchQuery,
                'order[0][column]': this.getColumnIndex(),
                'order[0][dir]': this.sortDirection,
                ...filters
            });
            
            try {
                const response = await fetch('api/get-cfo-data.php?' + params.toString());
                const data = await response.json();
                
                if (data.error) {
                    this.error = data.error;
                    this.members = [];
                    return;
                }
                
                this.members = data.data || [];
                this.totalRecords = data.recordsFiltered || 0;
                this.totalPages = Math.ceil(this.totalRecords / this.pageSize);
                
            } catch (err) {
                console.error('Error fetching data:', err);
                this.error = 'Failed to load data. Please try again.';
                this.members = [];
            } finally {
                this.loading = false;
            }
        },
        
        getColumnIndex() {
            const columns = ['id', 'name', 'last_name', 'first_name', 'middle_name', 'registry_number', 'husbands_surname', 'birthday', 'cfo_classification', 'cfo_status', 'purok_grupo', 'district_name', 'local_name'];
            return columns.indexOf(this.sortColumn);
        },
        
        search() {
            this.currentPage = 1;
            this.fetchData();
        },
        
        sortBy(column) {
            if (this.sortColumn === column) {
                this.sortDirection = this.sortDirection === 'asc' ? 'desc' : 'asc';
            } else {
                this.sortColumn = column;
                this.sortDirection = 'asc';
            }
            this.fetchData();
        },
        
        goToPage(page) {
            if (page < 1 || page > this.totalPages) return;
            this.currentPage = page;
            this.fetchData();
        },
        
        changePageSize() {
            this.currentPage = 1;
            this.fetchData();
        },
        
        applyFilters() {
            this.currentPage = 1;
            this.fetchData();
        },
        
        editCFO(id) {
            window.editCFO(id);
        },
        
        exportToExcel() {
            const filters = this.getFilters();
            const params = new URLSearchParams();
            
            if (filters.classification) params.append('classification', filters.classification);
            if (filters.status) params.append('status', filters.status);
            if (filters.district) params.append('district', filters.district);
            if (filters.local) params.append('local', filters.local);
            if (this.searchQuery) params.append('search', this.searchQuery);
            
            window.location.href = baseUrl + '/api/export-cfo-excel.php?' + params.toString();
        },
        
        async loadDistricts() {
            try {
                const response = await fetch('api/get-districts.php');
                const result = await response.json();
                
                if (!result.success || !result.districts) return;
                
                const filterDistrict = document.getElementById('filterDistrict');
                if (filterDistrict && filterDistrict.tagName === 'SELECT') {
                    let html = '<option value="">All Districts</option>';
                    result.districts.forEach(district => {
                        html += `<option value="${district.district_code}">${district.district_name}</option>`;
                    });
                    filterDistrict.innerHTML = html;
                }
                
                const districtCode = filterDistrict?.value;
                if (districtCode) {
                    this.loadLocalsForDistrict(districtCode);
                }
            } catch (error) {
                console.error('Error loading districts:', error);
            }
        },
        
        async loadLocalsForDistrict(districtCode) {
            const filterLocal = document.getElementById('filterLocal');
            if (!filterLocal || filterLocal.tagName !== 'SELECT') return;
            
            if (!districtCode) {
                filterLocal.innerHTML = '<option value="">All Locals</option>';
                return;
            }
            
            try {
                const response = await fetch('api/get-locals.php?district=' + districtCode);
                const data = await response.json();
                
                let html = '<option value="">All Locals</option>';
                data.forEach(local => {
                    html += `<option value="${local.local_code}">${local.local_name}</option>`;
                });
                filterLocal.innerHTML = html;
            } catch (error) {
                console.error('Error loading locals:', error);
            }
        }
    };
}

// Global function for Apply Filters button
function applyFilters() {
    const alpineComponent = document.querySelector('[x-data="cfoCheckerTable()"]');
    if (alpineComponent && alpineComponent.__x) {
        alpineComponent.__x.$data.applyFilters();
    }
}

// Refresh table data (for after edit)
function refreshTableData() {
    const alpineComponent = document.querySelector('[x-data="cfoCheckerTable()"]');
    if (alpineComponent && alpineComponent.__x) {
        alpineComponent.__x.$data.fetchData();
    }
}

function showSuccess(message) {
    const toast = document.getElementById('successToast');
    document.getElementById('toastMessage').textContent = message;
    toast.classList.remove('hidden', 'translate-x-full');
    setTimeout(() => {
        toast.classList.add('translate-x-full');
        setTimeout(() => toast.classList.add('hidden'), 300);
    }, 3000);
}

function showError(message) {
    const toast = document.getElementById('errorToast');
    document.getElementById('errorMessage').textContent = message;
    toast.classList.remove('hidden', 'translate-x-full');
    setTimeout(() => {
        toast.classList.add('translate-x-full');
        setTimeout(() => toast.classList.add('hidden'), 300);
    }, 5000);
}

// District change handler
document.getElementById('filterDistrict')?.addEventListener('change', async function() {
    const districtCode = this.value;
    const filterLocal = document.getElementById('filterLocal');
    
    if (!filterLocal || filterLocal.tagName !== 'SELECT') return;
    
    if (!districtCode) {
        filterLocal.innerHTML = '<option value="">All Locals</option>';
        return;
    }
    
    try {
        const response = await fetch('api/get-locals.php?district=' + districtCode);
        const data = await response.json();
        
        let html = '<option value="">All Locals</option>';
        data.forEach(local => {
            html += `<option value="${local.local_code}">${local.local_name}</option>`;
        });
        filterLocal.innerHTML = html;
    } catch (error) {
        console.error('Error loading locals:', error);
    }
});

async function editCFO(id) {
    const modal = document.getElementById('editModal');
    const content = document.getElementById('modalContent');
    
    modal.classList.remove('hidden');
    setTimeout(() => {
        modal.classList.remove('opacity-0');
        content.classList.remove('scale-95');
        content.classList.add('scale-100');
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
        document.getElementById('edit_id').value = data.id;
        document.getElementById('edit_first_name').value = data.first_name || '';
        document.getElementById('edit_middle_name').value = data.middle_name || '';
        document.getElementById('edit_last_name').value = data.last_name || '';
        document.getElementById('edit_husbands_surname').value = data.husbands_surname || '';
        document.getElementById('edit_registry').value = data.registry_number;
        document.getElementById('edit_birthday').value = data.birthday_raw || '';
        document.getElementById('edit_purok').value = data.purok || '';
        document.getElementById('edit_grupo').value = data.grupo || '';
        document.getElementById('edit_classification').value = data.cfo_classification || '';
        document.getElementById('edit_status').value = data.cfo_status || 'active';
        document.getElementById('edit_notes').value = data.cfo_notes || '';
        
        // Populate registration type fields
        document.getElementById('edit_registration_type').value = data.registration_type || '';
        document.getElementById('edit_registration_date').value = data.registration_date || '';
        document.getElementById('edit_registration_others_specify').value = data.registration_others_specify || '';
        document.getElementById('edit_transfer_out_date').value = data.transfer_out_date || '';
        
        // Populate Lipat-Kapisanan fields
        document.getElementById('edit_marriage_date').value = data.marriage_date || '';
        document.getElementById('edit_classification_change_date').value = data.classification_change_date || '';
        document.getElementById('edit_classification_change_reason').value = data.classification_change_reason || '';
        
        // Update displays
        const marriageDateDisplay = document.getElementById('marriage_date_display');
        const marriageDateText = document.getElementById('marriage_date_text');
        if (data.marriage_date) {
            marriageDateText.textContent = data.marriage_date;
            marriageDateDisplay.classList.remove('hidden');
        } else {
            marriageDateDisplay.classList.add('hidden');
        }
        
        // Update transfer out date display
        const transferOutDateDisplay = document.getElementById('transfer_out_date_display');
        const transferOutDateText = document.getElementById('transfer_out_date_text');
        if (data.transfer_out_date) {
            transferOutDateText.textContent = data.transfer_out_date;
            transferOutDateDisplay.classList.remove('hidden');
        } else {
            transferOutDateDisplay.classList.add('hidden');
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
    const registrationType = document.getElementById('edit_registration_type').value;
    const dateField = document.getElementById('edit_registration_date_field');
    const othersField = document.getElementById('edit_registration_others_field');
    
    dateField.style.display = 'block';
    othersField.style.display = 'none';
    
    if (registrationType === 'others') {
        othersField.style.display = 'block';
    }
}

// Handle status change to show/hide transfer out button
function handleStatusChange() {
    const status = document.getElementById('edit_status').value;
    const transferOutBtn = document.getElementById('transferOutBtn');
    const transferOutDisplay = document.getElementById('transfer_out_date_display');
    
    if (status === 'transferred-out') {
        transferOutBtn.classList.remove('hidden');
        if (document.getElementById('edit_transfer_out_date').value) {
            transferOutDisplay.classList.remove('hidden');
        }
    } else {
        transferOutBtn.classList.add('hidden');
        transferOutDisplay.classList.add('hidden');
    }
}

// Open transfer out modal
function openTransferOutModal() {
    const modal = document.getElementById('transferOutModal');
    const content = document.getElementById('transferOutModalContent');
    
    const today = new Date().toISOString().split('T')[0];
    const existingDate = document.getElementById('edit_transfer_out_date').value;
    document.getElementById('transfer_out_date_input').value = existingDate || today;
    
    modal.classList.remove('hidden');
    setTimeout(() => {
        modal.classList.remove('opacity-0');
        content.classList.remove('scale-95');
        content.classList.add('scale-100');
    }, 10);
}

// Close transfer out modal
function closeTransferOutModal() {
    const modal = document.getElementById('transferOutModal');
    const content = document.getElementById('transferOutModalContent');
    
    content.classList.remove('scale-100');
    content.classList.add('scale-95');
    modal.classList.add('opacity-0');
    
    setTimeout(() => {
        modal.classList.add('hidden');
    }, 300);
}

// Confirm transfer out
function confirmTransferOut() {
    const transferDate = document.getElementById('transfer_out_date_input').value;
    
    if (!transferDate) {
        showError('Please select a transfer out date');
        return;
    }
    
    document.getElementById('edit_transfer_out_date').value = transferDate;
    document.getElementById('transfer_out_date_text').textContent = transferDate;
    document.getElementById('transfer_out_date_display').classList.remove('hidden');
    
    closeTransferOutModal();
    showSuccess('Transfer out date set to ' + transferDate);
}

// Transfer-In Modal Functions
function openTransferInModal() {
    const modal = document.getElementById('transferInModal');
    const content = document.getElementById('transferInModalContent');
    
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('transfer_in_date_input').value = today;
    
    modal.classList.remove('hidden');
    setTimeout(() => {
        modal.classList.remove('opacity-0');
        content.classList.remove('scale-95');
        content.classList.add('scale-100');
    }, 10);
}

function closeTransferInModal() {
    const modal = document.getElementById('transferInModal');
    const content = document.getElementById('transferInModalContent');
    
    content.classList.remove('scale-100');
    content.classList.add('scale-95');
    modal.classList.add('opacity-0');
    
    setTimeout(() => {
        modal.classList.add('hidden');
        if (document.getElementById('edit_status').value === 'active' && !document.getElementById('edit_registration_date').value) {
            document.getElementById('edit_status').value = 'transferred-out';
            previousStatus = 'transferred-out';
        }
    }, 300);
}

function confirmTransferIn() {
    const transferInDate = document.getElementById('transfer_in_date_input').value;
    
    if (!transferInDate) {
        showError('Please select a transfer in date');
        return;
    }
    
    document.getElementById('edit_registration_type').value = 'transfer-in';
    document.getElementById('edit_registration_date').value = transferInDate;
    
    document.getElementById('edit_transfer_out_date').value = '';
    document.getElementById('transfer_out_date_display').classList.add('hidden');
    
    closeTransferInModal();
    showSuccess('Transfer in date set to ' + transferInDate);
}

// Lipat-Kapisanan Modal Functions
function openLipatKapisananModal() {
    const modal = document.getElementById('lipatKapisananModal');
    const content = document.getElementById('lipatKapisananModalContent');
    
    const currentClassification = document.getElementById('edit_classification').value;
    document.getElementById('lipat_current_classification').value = currentClassification || 'None';
    document.getElementById('lipat_new_classification').value = '';
    document.getElementById('lipat_marriage_date').value = '';
    document.getElementById('lipat_change_date').value = new Date().toISOString().split('T')[0];
    document.getElementById('lipat_reason').value = '';
    document.getElementById('lipat_marriage_date_field').classList.add('hidden');
    
    modal.classList.remove('hidden');
    setTimeout(() => {
        modal.classList.remove('opacity-0');
        content.classList.remove('scale-95');
        content.classList.add('scale-100');
    }, 10);
}

function closeLipatKapisananModal() {
    const modal = document.getElementById('lipatKapisananModal');
    const content = document.getElementById('lipatKapisananModalContent');
    
    content.classList.remove('scale-100');
    content.classList.add('scale-95');
    modal.classList.add('opacity-0');
    
    setTimeout(() => {
        modal.classList.add('hidden');
    }, 300);
}

function handleLipatClassificationChange() {
    const newClassification = document.getElementById('lipat_new_classification').value;
    const marriageDateField = document.getElementById('lipat_marriage_date_field');
    
    if (newClassification === 'Buklod') {
        marriageDateField.classList.remove('hidden');
        document.getElementById('lipat_marriage_date').required = true;
    } else {
        marriageDateField.classList.add('hidden');
        document.getElementById('lipat_marriage_date').required = false;
    }
}

function confirmLipatKapisanan() {
    const newClassification = document.getElementById('lipat_new_classification').value;
    const marriageDate = document.getElementById('lipat_marriage_date').value;
    const changeDate = document.getElementById('lipat_change_date').value;
    const reason = document.getElementById('lipat_reason').value;
    
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
    
    document.getElementById('edit_classification').value = newClassification;
    document.getElementById('edit_classification_change_date').value = changeDate;
    document.getElementById('edit_classification_change_reason').value = reason;
    
    if (newClassification === 'Buklod' && marriageDate) {
        document.getElementById('edit_marriage_date').value = marriageDate;
        document.getElementById('marriage_date_display').classList.remove('hidden');
        document.getElementById('marriage_date_text').textContent = marriageDate;
    }
    
    closeLipatKapisananModal();
    showSuccess('Classification changed to ' + newClassification);
}

function handleClassificationChange() {
    const classification = document.getElementById('edit_classification').value;
    // Additional logic can be added here
}

function closeEditModal() {
    const modal = document.getElementById('editModal');
    const content = document.getElementById('modalContent');
    
    content.classList.remove('scale-100');
    content.classList.add('scale-95');
    modal.classList.add('opacity-0');
    
    setTimeout(() => {
        modal.classList.add('hidden');
        document.getElementById('editForm').reset();
    }, 300);
}

document.getElementById('editForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const saveBtn = document.getElementById('saveBtn');
    const saveBtnText = document.getElementById('saveBtnText');
    const saveIcon = document.getElementById('saveIcon');
    const saveSpinner = document.getElementById('saveSpinner');
    
    saveBtn.disabled = true;
    saveBtn.classList.remove('hover:scale-105');
    saveBtnText.textContent = 'Saving...';
    saveIcon.classList.add('hidden');
    saveSpinner.classList.remove('hidden');
    
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
            refreshTableData();
        } else {
            showError(result.error || 'Error updating CFO information');
        }
    } catch (error) {
        console.error('Error updating CFO:', error);
        showError('Error updating CFO information');
    } finally {
        saveBtn.disabled = false;
        saveBtn.classList.add('hover:scale-105');
        saveBtnText.textContent = 'Save Changes';
        saveIcon.classList.remove('hidden');
        saveSpinner.classList.add('hidden');
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeEditModal();
    }
});

document.getElementById('editModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeEditModal();
    }
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/includes/layout.php';
?>
