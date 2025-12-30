<?php
/**
 * R5-18 Checker Report
 * Verify R5-18 form completeness for officers
 */

require_once __DIR__ . '/../config/config.php';

Security::requireLogin();
requirePermission('can_view_reports');

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

$error = '';
$success = '';

// Get filter parameters
$filterDistrict = Security::sanitizeInput($_GET['district'] ?? '');
$filterLocal = Security::sanitizeInput($_GET['local'] ?? '');
$filterStatus = Security::sanitizeInput($_GET['status'] ?? 'active');
$filterDepartment = Security::sanitizeInput($_GET['department'] ?? '');
$filterRequirement = Security::sanitizeInput($_GET['requirement'] ?? ''); // missing_r518, missing_picture, missing_signatories, missing_verify

// Get districts for filter
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

// Get locals for filter
$locals = [];
if (!empty($filterDistrict)) {
    try {
        $stmt = $db->prepare("SELECT local_code, local_name FROM local_congregations WHERE district_code = ? ORDER BY local_name");
        $stmt->execute([$filterDistrict]);
        $locals = $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Load locals error: " . $e->getMessage());
    }
}

// Get officers for report
$officers = [];
$reportInfo = [];
$statistics = [
    'total' => 0,
    'verified' => 0,
    'complete' => 0,
    'incomplete' => 0,
    'pending' => 0
];

try {
    // Build WHERE clause
    $whereConditions = [];
    $params = [];
    
    if ($filterStatus === 'active') {
        $whereConditions[] = 'o.is_active = 1';
    } elseif ($filterStatus === 'inactive') {
        $whereConditions[] = 'o.is_active = 0';
    }
    
    if ($currentUser['role'] === 'district') {
        $whereConditions[] = 'o.district_code = ?';
        $params[] = $currentUser['district_code'];
    } elseif ($currentUser['role'] === 'local') {
        $whereConditions[] = 'o.local_code = ?';
        $params[] = $currentUser['local_code'];
    }
    
    if (!empty($filterDistrict)) {
        $whereConditions[] = 'o.district_code = ?';
        $params[] = $filterDistrict;
    }
    
    if (!empty($filterLocal)) {
        $whereConditions[] = 'o.local_code = ?';
        $params[] = $filterLocal;
    }
    
    if (!empty($filterDepartment)) {
        $whereConditions[] = 'EXISTS (SELECT 1 FROM officer_departments od2 WHERE od2.officer_id = o.officer_id AND od2.department = ? AND od2.is_active = 1)';
        $params[] = $filterDepartment;
    }
    
    // Requirement-specific filters
    if ($filterRequirement === 'missing_r518') {
        $whereConditions[] = '(o.r518_submitted IS NULL OR o.r518_submitted = 0)';
    } elseif ($filterRequirement === 'missing_picture') {
        $whereConditions[] = '(o.r518_picture_attached IS NULL OR o.r518_picture_attached = 0)';
    } elseif ($filterRequirement === 'missing_signatories') {
        $whereConditions[] = '(o.r518_signatories_complete IS NULL OR o.r518_signatories_complete = 0)';
    } elseif ($filterRequirement === 'missing_verify') {
        $whereConditions[] = '(o.r518_data_verify IS NULL OR o.r518_data_verify = 0)';
    }
    
    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    // Get officers with R5-18 data
    $stmt = $db->prepare("
        SELECT 
            o.officer_id,
            o.officer_uuid,
            o.first_name_encrypted,
            o.last_name_encrypted,
            o.middle_initial_encrypted,
            o.district_code,
            o.local_code,
            o.purok,
            o.grupo,
            o.control_number_encrypted,
            o.registry_number_encrypted,
            o.r518_submitted,
            o.r518_picture_attached,
            o.r518_signatories_complete,
            o.r518_data_verify,
            o.r518_completion_status,
            o.r518_notes,
            o.r518_verified_at,
            o.r518_verified_by,
            d.district_name,
            lc.local_name,
            GROUP_CONCAT(DISTINCT od.department SEPARATOR ', ') as departments
        FROM officers o
        LEFT JOIN districts d ON o.district_code = d.district_code
        LEFT JOIN local_congregations lc ON o.local_code = lc.local_code
        LEFT JOIN officer_departments od ON o.officer_id = od.officer_id AND od.is_active = 1
        $whereClause
        GROUP BY o.officer_id
        ORDER BY o.r518_completion_status DESC, d.district_name, lc.local_name, o.last_name_encrypted
    ");
    
    $stmt->execute($params);
    $results = $stmt->fetchAll();
    
    $rowNumber = 1;
    foreach ($results as $officer) {
        $hasR518 = $officer['r518_submitted'] == 1;
        $hasPicture = $officer['r518_picture_attached'] == 1;
        $hasSignatories = $officer['r518_signatories_complete'] == 1;
        
        $r518Status = $officer['r518_completion_status'];
        
        // Decrypt control and registry numbers
        $controlNumber = null;
        if (!empty($officer['control_number_encrypted'])) {
            try {
                $controlNumber = Encryption::decrypt($officer['control_number_encrypted'], $officer['district_code']);
            } catch (Exception $e) {
                error_log("Failed to decrypt control number for officer {$officer['officer_id']}: " . $e->getMessage());
            }
        }
        
        $registryNumber = null;
        if (!empty($officer['registry_number_encrypted'])) {
            try {
                $registryNumber = Encryption::decrypt($officer['registry_number_encrypted'], $officer['district_code']);
            } catch (Exception $e) {
                error_log("Failed to decrypt registry number for officer {$officer['officer_id']}: " . $e->getMessage());
            }
        }
        
        // Update statistics
        $statistics['total']++;
        if ($r518Status === 'verified') $statistics['verified']++;
        elseif ($r518Status === 'complete') $statistics['complete']++;
        elseif ($r518Status === 'incomplete') $statistics['incomplete']++;
        else $statistics['pending']++;
        
        $officers[] = [
            'row_number' => $rowNumber++,
            'officer_id' => $officer['officer_id'],
            'officer_uuid' => $officer['officer_uuid'],
            'full_name' => Encryption::getFullName(
                $officer['last_name_encrypted'],
                $officer['first_name_encrypted'],
                $officer['middle_initial_encrypted'],
                $officer['district_code']
            ),
            'district_name' => $officer['district_name'],
            'local_name' => $officer['local_name'],
            'purok' => $officer['purok'],
            'grupo' => $officer['grupo'],
            'control_number' => $controlNumber,
            'registry_number' => $registryNumber,
            'departments' => $officer['departments'],
            'has_r518' => $hasR518,
            'has_picture' => $hasPicture,
            'has_data_verify' => $officer['r518_data_verify'] == 1,
            'r518_status' => $r518Status,
            'r518_notes' => $officer['r518_notes'] ?? null,
            'r518_verified_at' => $officer['r518_verified_at'] ?? null,
            'has_signatories' => $hasSignatories
        ];
    }
    
    // Get report info
    if (!empty($officers)) {
        $reportInfo = [
            'district_name' => $officers[0]['district_name'] ?? 'All Districts',
            'local_name' => $officers[0]['local_name'] ?? 'All Locals',
            'generated_date' => date('F d, Y'),
            'generated_by' => $currentUser['username'] ?? $currentUser['email'] ?? 'Unknown',
            'total_officers' => count($officers)
        ];
    }
    
} catch (Exception $e) {
    $error = 'Failed to load officers: ' . $e->getMessage();
    error_log("Load officers error: " . $e->getMessage());
}

ob_start();
$pageTitle = 'R5-18 Checker';
?>

<div class="container mx-auto px-4 py-6 max-w-7xl">
    <!-- Header -->
    <div class="mb-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">📋 R5-18 Checker</h1>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Verify R5-18 form completeness for all officers</p>
            </div>
            <div class="flex gap-2">
                <button onclick="openPrintView()" 
                   class="inline-flex items-center justify-center px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors shadow-sm text-sm print:hidden">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>
                    </svg>
                    Print Report
                </button>
                <a href="<?php echo BASE_URL; ?>/reports/lorc-lcrc-checker.php" 
                   class="inline-flex items-center justify-center px-4 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600 transition-colors shadow-sm text-sm">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                    </svg>
                    Back to LORC/LCRC Checker
                </a>
            </div>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="mb-4 p-4 bg-red-50 border-l-4 border-red-500 text-red-700">
            <p class="font-medium">Error</p>
            <p class="text-sm"><?php echo Security::escape($error); ?></p>
        </div>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
            <div class="text-sm text-gray-600 dark:text-gray-400">Total Officers</div>
            <div class="text-2xl font-bold text-gray-900 dark:text-gray-100"><?php echo number_format($statistics['total']); ?></div>
        </div>
        <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg shadow p-4">
            <div class="text-sm text-blue-600 dark:text-blue-400">✓ Verified</div>
            <div class="text-2xl font-bold text-blue-700 dark:text-blue-300"><?php echo number_format($statistics['verified']); ?></div>
        </div>
        <div class="bg-green-50 dark:bg-green-900/20 rounded-lg shadow p-4">
            <div class="text-sm text-green-600 dark:text-green-400">✓ Complete</div>
            <div class="text-2xl font-bold text-green-700 dark:text-green-300"><?php echo number_format($statistics['complete']); ?></div>
        </div>
        <div class="bg-yellow-50 dark:bg-yellow-900/20 rounded-lg shadow p-4">
            <div class="text-sm text-yellow-600 dark:text-yellow-400">⚠ Incomplete</div>
            <div class="text-2xl font-bold text-yellow-700 dark:text-yellow-300"><?php echo number_format($statistics['incomplete']); ?></div>
        </div>
        <div class="bg-gray-50 dark:bg-gray-700 rounded-lg shadow p-4">
            <div class="text-sm text-gray-600 dark:text-gray-400">○ Pending</div>
            <div class="text-2xl font-bold text-gray-700 dark:text-gray-300"><?php echo number_format($statistics['pending']); ?></div>
        </div>
    </div>

    <!-- Filters -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow mb-6 p-6">
        <form method="GET" action="" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                <!-- District Filter -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">District</label>
                    <select name="district" id="district" onchange="loadLocals(this.value); this.form.submit();" 
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100">
                        <option value="">All Districts</option>
                        <?php foreach ($districts as $district): ?>
                            <option value="<?php echo Security::escape($district['district_code']); ?>" 
                                    <?php echo $filterDistrict === $district['district_code'] ? 'selected' : ''; ?>>
                                <?php echo Security::escape($district['district_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Local Filter -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Local</label>
                    <select name="local" id="local" onchange="this.form.submit();" 
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100">
                        <option value="">All Locals</option>
                        <?php foreach ($locals as $local): ?>
                            <option value="<?php echo Security::escape($local['local_code']); ?>" 
                                    <?php echo $filterLocal === $local['local_code'] ? 'selected' : ''; ?>>
                                <?php echo Security::escape($local['local_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Status Filter -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Status</label>
                    <select name="status" onchange="this.form.submit();" 
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100">
                        <option value="active" <?php echo $filterStatus === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $filterStatus === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        <option value="all" <?php echo $filterStatus === 'all' ? 'selected' : ''; ?>>All</option>
                    </select>
                </div>

                <!-- Department Filter -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Department</label>
                    <select name="department" onchange="this.form.submit();" 
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100">
                        <option value="">All Departments</option>
                        <?php foreach (getDepartments() as $dept): ?>
                            <option value="<?php echo Security::escape($dept); ?>" 
                                    <?php echo $filterDepartment === $dept ? 'selected' : ''; ?>>
                                <?php echo Security::escape($dept); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Requirement Filter -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Show Missing</label>
                    <select name="requirement" onchange="this.form.submit();" 
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-gray-100">
                        <option value="">All Officers</option>
                        <option value="missing_r518" <?php echo $filterRequirement === 'missing_r518' ? 'selected' : ''; ?>>Missing R5-18</option>
                        <option value="missing_picture" <?php echo $filterRequirement === 'missing_picture' ? 'selected' : ''; ?>>Missing 2x2 Picture</option>
                        <option value="missing_signatories" <?php echo $filterRequirement === 'missing_signatories' ? 'selected' : ''; ?>>Missing Signatories</option>
                        <option value="missing_verify" <?php echo $filterRequirement === 'missing_verify' ? 'selected' : ''; ?>>Not Verified</option>
                    </select>
                </div>
            </div>
            
            <div class="flex items-center justify-between pt-2 border-t border-gray-200 dark:border-gray-700">
                <div class="text-sm text-gray-600 dark:text-gray-400">
                    Showing <span class="font-semibold"><?php echo number_format(count($officers)); ?></span> officers
                </div>
                <a href="?" class="inline-flex items-center px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                    </svg>
                    Clear Filters
                </a>
            </div>
        </form>
    </div>

    <!-- Search Box -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow mb-4 p-4">
        <div class="relative">
            <input type="text" 
                   id="searchInput" 
                   placeholder="Search by name, local, district, control #, registry #, or department..." 
                   class="w-full px-4 py-3 pl-10 pr-10 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-gray-100 transition-all">
            <svg class="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
            </svg>
            <button id="clearSearch" 
                    class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 hidden">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>
        <div id="searchResults" class="mt-2 text-sm text-gray-600 dark:text-gray-400"></div>
    </div>

    <!-- Officers Table -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                <thead class="bg-gray-50 dark:bg-gray-900">
                    <tr>
                        <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">#</th>
                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Officer Name</th>
                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Local/District</th>
                        <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Control #</th>
                        <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Registry #</th>
                        <th class="px-2 py-2 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider" title="Has R5-18?">R5-18</th>
                        <th class="px-2 py-2 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider" title="Has 2x2 Picture?">Picture</th>
                        <th class="px-2 py-2 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider" title="Complete Signatories?">Signatories</th>
                        <th class="px-2 py-2 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider" title="Data Verification">Verified</th>
                        <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                        <th class="px-2 py-2 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                    <?php if (empty($officers)): ?>
                        <tr>
                            <td colspan="11" class="px-6 py-12 text-center">
                                <svg class="w-12 h-12 text-gray-300 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                </svg>
                                <p class="text-sm text-gray-500">No officers found</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($officers as $officer): ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                <td class="px-2 py-2 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400"><?php echo $officer['row_number']; ?></td>
                                <td class="px-3 py-2">
                                    <div class="text-sm font-medium text-gray-900 dark:text-gray-100"><?php echo Security::escape($officer['full_name']); ?></div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400"><?php echo Security::escape($officer['departments'] ?? ''); ?></div>
                                </td>
                                <td class="px-3 py-2">
                                    <div class="text-sm text-gray-900 dark:text-gray-100"><?php echo Security::escape($officer['local_name']); ?></div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400"><?php echo Security::escape($officer['district_name']); ?></div>
                                </td>
                                <td class="px-2 py-2 text-sm text-gray-700 dark:text-gray-300">
                                    <?php echo $officer['control_number'] ? Security::escape($officer['control_number']) : '<span class="text-gray-400">—</span>'; ?>
                                </td>
                                <td class="px-2 py-2 text-sm text-gray-700 dark:text-gray-300">
                                    <?php echo $officer['registry_number'] ? Security::escape($officer['registry_number']) : '<span class="text-gray-400">—</span>'; ?>
                                </td>
                                <td class="px-2 py-2 text-center">
                                    <button type="button" 
                                            class="r518-toggle-btn inline-flex items-center justify-center w-9 h-9 rounded-lg transition-all duration-200 <?php echo $officer['has_r518'] ? 'bg-green-100 hover:bg-green-200 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-gray-100 hover:bg-gray-200 text-gray-400 dark:bg-gray-700 dark:text-gray-500'; ?>" 
                                            data-officer-id="<?php echo $officer['officer_id']; ?>" 
                                            data-field="r518_submitted" 
                                            data-value="<?php echo $officer['has_r518'] ? 1 : 0; ?>"
                                            title="<?php echo $officer['has_r518'] ? 'R5-18 Submitted' : 'R5-18 Not Submitted'; ?>">
                                        <i class="<?php echo $officer['has_r518'] ? 'fa-solid' : 'fa-regular'; ?> fa-thumbs-up text-lg"></i>
                                    </button>
                                </td>
                                <td class="px-2 py-2 text-center">
                                    <button type="button" 
                                            class="r518-toggle-btn inline-flex items-center justify-center w-9 h-9 rounded-lg transition-all duration-200 <?php echo $officer['has_picture'] ? 'bg-green-100 hover:bg-green-200 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-gray-100 hover:bg-gray-200 text-gray-400 dark:bg-gray-700 dark:text-gray-500'; ?>" 
                                            data-officer-id="<?php echo $officer['officer_id']; ?>" 
                                            data-field="r518_picture_attached" 
                                            data-value="<?php echo $officer['has_picture'] ? 1 : 0; ?>"
                                            title="<?php echo $officer['has_picture'] ? '2x2 Picture Attached' : '2x2 Picture Missing'; ?>">
                                        <i class="<?php echo $officer['has_picture'] ? 'fa-solid' : 'fa-regular'; ?> fa-thumbs-up text-lg"></i>
                                    </button>
                                </td>
                                <td class="px-2 py-2 text-center">
                                    <button type="button" 
                                            class="r518-toggle-btn inline-flex items-center justify-center w-9 h-9 rounded-lg transition-all duration-200 <?php echo $officer['has_signatories'] ? 'bg-green-100 hover:bg-green-200 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-gray-100 hover:bg-gray-200 text-gray-400 dark:bg-gray-700 dark:text-gray-500'; ?>" 
                                            data-officer-id="<?php echo $officer['officer_id']; ?>" 
                                            data-field="r518_signatories_complete" 
                                            data-value="<?php echo $officer['has_signatories'] ? 1 : 0; ?>"
                                            title="<?php echo $officer['has_signatories'] ? 'Signatories Complete' : 'Signatories Incomplete'; ?>">
                                        <i class="<?php echo $officer['has_signatories'] ? 'fa-solid' : 'fa-regular'; ?> fa-thumbs-up text-lg"></i>
                                    </button>
                                </td>
                                <td class="px-2 py-2 text-center">
                                    <?php 
                                    $canVerifyData = $officer['has_r518'] && $officer['has_picture'] && $officer['has_signatories'];
                                    $isLocked = !$canVerifyData;
                                    ?>
                                    <button type="button" 
                                            class="r518-toggle-btn inline-flex items-center justify-center w-9 h-9 rounded-lg transition-all duration-200 <?php echo $isLocked ? 'bg-gray-50 dark:bg-gray-800 cursor-not-allowed opacity-50' : ($officer['has_data_verify'] ? 'bg-green-100 hover:bg-green-200 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-gray-100 hover:bg-gray-200 text-gray-400 dark:bg-gray-700 dark:text-gray-500'); ?>" 
                                            data-officer-id="<?php echo $officer['officer_id']; ?>" 
                                            data-field="r518_data_verify" 
                                            data-value="<?php echo $officer['has_data_verify'] ? 1 : 0; ?>"
                                            data-locked="<?php echo $isLocked ? '1' : '0'; ?>"
                                            <?php echo $isLocked ? 'disabled' : ''; ?>
                                            title="<?php echo $isLocked ? '🔒 Complete all requirements first' : ($officer['has_data_verify'] ? 'Data Verified' : 'Data Not Verified'); ?>">
                                        <i class="<?php echo $officer['has_data_verify'] ? 'fa-solid' : 'fa-regular'; ?> fa-thumbs-up text-lg <?php echo $isLocked ? 'text-gray-300 dark:text-gray-600' : ''; ?>"></i>
                                    </button>
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <?php
                                    $statusBadges = [
                                        'verified' => '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400">✓ Verified</span>',
                                        'complete' => '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400">✓ Complete</span>',
                                        'incomplete' => '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400">⚠ Incomplete</span>',
                                        'pending' => '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-400">○ Pending</span>'
                                    ];
                                    echo $statusBadges[$officer['r518_status']] ?? '';
                                    
                                    if ($officer['r518_notes']):
                                    ?>
                                        <div class="mt-1 text-xs text-gray-600 dark:text-gray-400" title="<?php echo Security::escape($officer['r518_notes']); ?>">📝</div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-2 py-2 whitespace-nowrap text-center text-sm font-medium">
                                    <div class="flex items-center justify-center gap-1">
                                        <a href="<?php echo BASE_URL; ?>/officers/view.php?id=<?php echo urlencode($officer['officer_uuid']); ?>" 
                                           class="text-blue-600 hover:text-blue-900 dark:text-blue-400 dark:hover:text-blue-300" target="_blank" title="View">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                            </svg>
                                        </a>
                                        <a href="<?php echo BASE_URL; ?>/officers/edit.php?id=<?php echo urlencode($officer['officer_uuid']); ?>" 
                                           class="text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-300" target="_blank" title="Edit">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                            </svg>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
function loadLocals(districtCode) {
    const localSelect = document.getElementById('local');
    if (!districtCode) {
        localSelect.innerHTML = '<option value="">All Locals</option>';
        return;
    }
    
    fetch('<?php echo BASE_URL; ?>/api/get-locals.php?district=' + districtCode)
        .then(response => response.json())
        .then(data => {
            localSelect.innerHTML = '<option value="">All Locals</option>';
            data.forEach(local => {
                const option = document.createElement('option');
                option.value = local.local_code;
                option.textContent = local.local_name;
                localSelect.appendChild(option);
            });
        })
        .catch(error => console.error('Error loading locals:', error));
}

// R5-18 Toggle Handler
document.querySelectorAll('.r518-toggle-btn').forEach(btn => {
    btn.addEventListener('click', async function() {
        // Check if button is locked
        if (this.dataset.locked === '1') {
            return;
        }
        
        const officerId = this.dataset.officerId;
        const field = this.dataset.field;
        const currentValue = parseInt(this.dataset.value);
        const newValue = currentValue === 1 ? 0 : 1;
        
        // Disable button during update
        this.disabled = true;
        this.style.opacity = '0.6';
        this.style.cursor = 'wait';
        
        try {
            const formData = new FormData();
            formData.append('officer_id', officerId);
            formData.append('field', field);
            formData.append('value', newValue);
            formData.append('csrf_token', '<?php echo Security::generateCSRFToken(); ?>');
            
            const response = await fetch('<?php echo BASE_URL; ?>/api/update-r518-field.php', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                // Update button state
                this.dataset.value = newValue;
                const thumbsIcon = this.querySelector('i');
                
                if (newValue === 1) {
                    thumbsIcon.className = 'fa-solid fa-thumbs-up text-xl';
                    this.className = 'r518-toggle-btn inline-flex items-center justify-center w-10 h-10 rounded-lg transition-all duration-200 bg-green-100 hover:bg-green-200 text-green-700';
                    this.title = this.title.replace('Click to mark as Submitted', 'Click to mark as Not Submitted')
                                          .replace('Click to mark as Attached', 'Click to mark as Missing')
                                          .replace('Click to mark as Complete', 'Click to mark as Incomplete')
                                          .replace('Click to mark as Verified', 'Click to mark as Not Verified');
                } else {
                    thumbsIcon.className = 'fa-regular fa-thumbs-up text-xl';
                    this.className = 'r518-toggle-btn inline-flex items-center justify-center w-10 h-10 rounded-lg transition-all duration-200 bg-gray-100 hover:bg-gray-200 text-gray-400';
                    this.title = this.title.replace('Click to mark as Not Submitted', 'Click to mark as Submitted')
                                          .replace('Click to mark as Missing', 'Click to mark as Attached')
                                          .replace('Click to mark as Incomplete', 'Click to mark as Complete')
                                          .replace('Click to mark as Not Verified', 'Click to mark as Verified');
                }
                
                // Update data verify button lock state for this officer
                updateDataVerifyLock(officerId);
                
                // Show success feedback
                const originalTransform = this.style.transform;
                this.style.transform = 'scale(1.2)';
                setTimeout(() => {
                    this.style.transform = originalTransform;
                }, 200);
            } else {
                alert('Failed to update: ' + result.message);
            }
        } catch (error) {
            console.error('Error updating R5-18 field:', error);
            alert('An error occurred while updating. Please try again.');
        } finally {
            // Re-enable button
            this.disabled = false;
            this.style.opacity = '1';
            this.style.cursor = 'pointer';
        }
    });
});

// Function to update data verify button lock state
function updateDataVerifyLock(officerId) {
    // Find all buttons for this officer in the same row
    const row = document.querySelector(`tr:has(button[data-officer-id="${officerId}"])`);
    if (!row) return;
    
    const buttons = row.querySelectorAll('.r518-toggle-btn');
    let hasR518 = false, hasPicture = false, hasSignatories = false;
    let dataVerifyBtn = null;
    
    buttons.forEach(btn => {
        const field = btn.dataset.field;
        const value = parseInt(btn.dataset.value);
        
        if (field === 'r518_submitted') hasR518 = value === 1;
        if (field === 'r518_picture_attached') hasPicture = value === 1;
        if (field === 'r518_signatories_complete') hasSignatories = value === 1;
        if (field === 'r518_data_verify') dataVerifyBtn = btn;
    });
    
    if (!dataVerifyBtn) return;
    
    const canVerify = hasR518 && hasPicture && hasSignatories;
    const isLocked = !canVerify;
    
    dataVerifyBtn.dataset.locked = isLocked ? '1' : '0';
    dataVerifyBtn.disabled = isLocked;
    
    const icon = dataVerifyBtn.querySelector('i');
    
    if (isLocked) {
        dataVerifyBtn.className = 'r518-toggle-btn inline-flex items-center justify-center w-10 h-10 rounded-lg transition-all duration-200 bg-gray-50 cursor-not-allowed opacity-50';
        dataVerifyBtn.title = '🔒 Complete all R5-18 requirements first (R5-18, Picture, Signatories)';
        icon.className = 'fa-regular fa-thumbs-up text-xl text-gray-300';
        // Reset to not verified if locked
        dataVerifyBtn.dataset.value = '0';
    } else {
        const currentValue = parseInt(dataVerifyBtn.dataset.value);
        if (currentValue === 1) {
            dataVerifyBtn.className = 'r518-toggle-btn inline-flex items-center justify-center w-10 h-10 rounded-lg transition-all duration-200 bg-green-100 hover:bg-green-200 text-green-700';
            dataVerifyBtn.title = 'Data Verified - Click to mark as Not Verified';
            icon.className = 'fa-solid fa-thumbs-up text-xl';
        } else {
            dataVerifyBtn.className = 'r518-toggle-btn inline-flex items-center justify-center w-10 h-10 rounded-lg transition-all duration-200 bg-gray-100 hover:bg-gray-200 text-gray-400';
            dataVerifyBtn.title = 'Data Not Verified - Click to mark as Verified';
            icon.className = 'fa-regular fa-thumbs-up text-xl';
        }
    }
}

// Print View Function
function openPrintView() {
    // Get current URL parameters
    const urlParams = new URLSearchParams(window.location.search);
    const printUrl = '<?php echo BASE_URL; ?>/reports/r518-checker-print.php?' + urlParams.toString();
    window.open(printUrl, '_blank', 'width=1200,height=800');
}

// Instant Search Functionality
const searchInput = document.getElementById('searchInput');
const searchResults = document.getElementById('searchResults');
const clearSearchBtn = document.getElementById('clearSearch');
const tableBody = document.querySelector('tbody');
const tableRows = tableBody.querySelectorAll('tr:not(:has(td[colspan]))');
const totalRecords = tableRows.length;

if (searchInput && tableRows.length > 0) {
    searchInput.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase().trim();
        let visibleCount = 0;
        
        // Show/hide clear button
        if (searchTerm) {
            clearSearchBtn.classList.remove('hidden');
        } else {
            clearSearchBtn.classList.add('hidden');
        }
        
        tableRows.forEach(row => {
            const text = row.textContent.toLowerCase();
            
            if (searchTerm === '' || text.includes(searchTerm)) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });
        
        // Update search results message
        if (searchTerm === '') {
            searchResults.textContent = '';
        } else {
            if (visibleCount === 0) {
                searchResults.innerHTML = '<span class="text-red-600 dark:text-red-400">⚠ No officers found matching "' + escapeHtml(searchTerm) + '"</span>';
            } else if (visibleCount === totalRecords) {
                searchResults.innerHTML = '<span class="text-green-600 dark:text-green-400">✓ Showing all ' + totalRecords + ' officers</span>';
            } else {
                searchResults.innerHTML = '<span class="text-blue-600 dark:text-blue-400">📋 Showing ' + visibleCount + ' of ' + totalRecords + ' officers</span>';
            }
        }
    });
    
    // Clear search button
    clearSearchBtn.addEventListener('click', function() {
        searchInput.value = '';
        searchInput.dispatchEvent(new Event('input'));
        searchInput.focus();
    });
    
    // Helper function to escape HTML
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
