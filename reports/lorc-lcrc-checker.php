<?php
/**
 * LORC/LCRC Checker Report
 * Verify officer records completeness
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/ui-components.php';

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
$filterIssue = Security::sanitizeInput($_GET['issue'] ?? ''); // missing_control, missing_registry, missing_both, complete
$filterDepartment = Security::sanitizeInput($_GET['department'] ?? '');

// Customizable field display options
// If any filter parameter is present, we assume form was submitted
$formSubmitted = isset($_GET['district']) || isset($_GET['local']) || isset($_GET['status']) || isset($_GET['issue']);

// When form is submitted, checkbox only sends value if checked
// When form is not submitted (first load), show all columns by default
$showControlNumber = $formSubmitted ? isset($_GET['show_control']) : true;
$showRegistryNumber = $formSubmitted ? isset($_GET['show_registry']) : true;
$showOathDate = $formSubmitted ? isset($_GET['show_oath']) : true;
$showDepartments = $formSubmitted ? isset($_GET['show_departments']) : true;

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
    'complete' => 0,
    'missing_control' => 0,
    'missing_registry' => 0,
    'missing_both' => 0,
    'duplicates' => 0
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
        $whereConditions[] = 'od.department = ?';
        $params[] = $filterDepartment;
    }
    
    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    // Get officers with registry information
    $stmt = $db->prepare("
        SELECT 
            o.*,
            d.district_name,
            lc.local_name,
            tc.registry_number_encrypted,
            GROUP_CONCAT(
                DISTINCT CONCAT(
                    od.department, 
                    IF(od.duty IS NOT NULL AND od.duty != '', CONCAT(' - ', od.duty), '')
                ) 
                ORDER BY od.department 
                SEPARATOR ', '
            ) as departments,
            GROUP_CONCAT(
                DISTINCT DATE_FORMAT(od.oath_date, '%m/%d/%Y')
                ORDER BY od.department
                SEPARATOR ', '
            ) as oath_dates
        FROM officers o
        LEFT JOIN districts d ON o.district_code = d.district_code
        LEFT JOIN local_congregations lc ON o.local_code = lc.local_code
        LEFT JOIN officer_departments od ON o.officer_id = od.officer_id AND od.is_active = 1
        LEFT JOIN tarheta_control tc ON o.tarheta_control_id = tc.id
        $whereClause
        GROUP BY o.officer_id
        ORDER BY lc.local_name, o.officer_id
    ");
    
    $stmt->execute($params);
    $allOfficers = $stmt->fetchAll();
    
    // FIRST PASS: Build nameMap to detect ALL duplicates
    $nameMap = []; // Track names by local to find duplicates
    $decryptedData = []; // Store decrypted data for second pass
    
    foreach ($allOfficers as $officer) {
        $decrypted = Encryption::decryptOfficerName(
            $officer['last_name_encrypted'],
            $officer['first_name_encrypted'],
            $officer['middle_initial_encrypted'],
            $officer['district_code']
        );
        
        $fullName = trim($decrypted['last_name'] . ', ' . $decrypted['first_name'] . 
                         (!empty($decrypted['middle_initial']) ? ' ' . $decrypted['middle_initial'] . '.' : ''));
        
        // Track duplicates by local + name
        $localKey = $officer['local_code'] ?? 'unknown';
        $nameKey = strtolower(str_replace([' ', ',', '.'], '', $fullName)); // Normalized name
        
        if (!isset($nameMap[$localKey])) {
            $nameMap[$localKey] = [];
        }
        
        if (!isset($nameMap[$localKey][$nameKey])) {
            $nameMap[$localKey][$nameKey] = [];
        }
        $nameMap[$localKey][$nameKey][] = $officer['officer_id'];
        
        // Store decrypted data
        $decryptedData[$officer['officer_id']] = [
            'decrypted' => $decrypted,
            'full_name' => $fullName,
            'local_key' => $localKey,
            'name_key' => $nameKey
        ];
    }
    
    // Count duplicates for statistics
    foreach ($nameMap as $localKey => $names) {
        foreach ($names as $nameKey => $officerIds) {
            if (count($officerIds) > 1) {
                $statistics['duplicates'] += count($officerIds);
            }
        }
    }
    
    // SECOND PASS: Process officers with filters
    $rowNumber = 1;
    
    foreach ($allOfficers as $officer) {
        $officerId = $officer['officer_id'];
        $decrypted = $decryptedData[$officerId]['decrypted'];
        $fullName = $decryptedData[$officerId]['full_name'];
        $localKey = $decryptedData[$officerId]['local_key'];
        $nameKey = $decryptedData[$officerId]['name_key'];
        
        // Decrypt registry number if available
        $registryNumber = null;
        if (!empty($officer['registry_number_encrypted'])) {
            try {
                $registryNumber = Encryption::decrypt($officer['registry_number_encrypted'], $officer['district_code']);
            } catch (Exception $e) {
                error_log("Failed to decrypt registry number for officer {$officer['officer_id']}: " . $e->getMessage());
            }
        }
        
        // Decrypt control number
        $controlNumber = null;
        if (!empty($officer['control_number_encrypted'])) {
            try {
                $controlNumber = Encryption::decrypt($officer['control_number_encrypted'], $officer['district_code']);
            } catch (Exception $e) {
                error_log("Failed to decrypt control number for officer {$officer['officer_id']}: " . $e->getMessage());
            }
        }
        
        // Check purok and grupo assignments
        $hasPurok = !empty($officer['purok']);
        $hasGrupo = !empty($officer['grupo']);
        
        // Check R5-18 requirements using new dedicated fields
        $hasR518 = isset($officer['r518_submitted']) && $officer['r518_submitted'] == 1;
        $hasPicture = isset($officer['r518_picture_attached']) && $officer['r518_picture_attached'] == 1;
        $hasSignatories = isset($officer['r518_signatories_complete']) && $officer['r518_signatories_complete'] == 1;
        $r518Status = $officer['r518_completion_status'] ?? 'pending';
        
        // Determine issue status
        $hasControl = !empty($controlNumber);
        $hasRegistry = !empty($registryNumber);
        
        $issueType = null;
        if (!$hasControl && !$hasRegistry) {
            $issueType = 'missing_both';
            $statistics['missing_both']++;
        } elseif (!$hasControl) {
            $issueType = 'missing_control';
            $statistics['missing_control']++;
        } elseif (!$hasRegistry) {
            $issueType = 'missing_registry';
            $statistics['missing_registry']++;
        } else {
            $issueType = 'complete';
            $statistics['complete']++;
        }
        
        // Check for purok/grupo assignment issues
        if (!$hasPurok && !$hasGrupo) {
            $issueType = 'no_purok_grupo';
        } elseif (!$hasPurok) {
            $issueType = 'no_purok';
        } elseif (!$hasGrupo) {
            $issueType = 'no_grupo';
        }
        
        // R5-18 Checker mode - check if officer is missing any R5-18 requirements
        if ($filterIssue === 'r518_checker') {
            if (!$hasR518 || !$hasPicture || !$hasSignatories) {
                $issueType = 'r518_checker';
            } else {
                // Skip if complete
                continue;
            }
        }
        
        $statistics['total']++;
        
        // Check if duplicate
        $isDuplicate = count($nameMap[$localKey][$nameKey]) > 1;
        
        // Apply issue filter
        if (!empty($filterIssue)) {
            if ($filterIssue === 'duplicates' && !$isDuplicate) {
                continue;
            } elseif ($filterIssue !== 'duplicates' && $filterIssue !== $issueType) {
                continue;
            }
        }
        
        $officers[] = [
            'row_number' => $rowNumber++,
            'officer_id' => $officer['officer_id'],
            'officer_uuid' => $officer['officer_uuid'],
            'full_name' => $fullName,
            'is_duplicate' => $isDuplicate,
            'duplicate_ids' => $isDuplicate ? $nameMap[$localKey][$nameKey] : [],
            'control_number' => $controlNumber,
            'registry_number' => $registryNumber,
            'oath_dates' => $officer['oath_dates'] ?? null,
            'departments' => $officer['departments'],
            'district_name' => $officer['district_name'],
            'local_name' => $officer['local_name'],
            'purok' => $officer['purok'] ?? null,
            'grupo' => $officer['grupo'] ?? null,
            'issue_type' => $issueType,
            'has_control' => $hasControl,
            'has_registry' => $hasRegistry,
            'has_purok' => $hasPurok,
            'has_grupo' => $hasGrupo,
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
            'total_officers' => count($officers)
        ];
    }
    
} catch (Exception $e) {
    error_log("Load LORC/LCRC checker error: " . $e->getMessage());
    $error = 'Error loading report data.';
}

$pageTitle = 'LORC/LCRC Checker';
ob_start();
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 p-4 sm:p-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-xl sm:text-2xl font-semibold text-gray-900">LORC/LCRC Checker</h1>
                <p class="text-xs sm:text-sm text-gray-500 mt-1">Verify officer record completeness and identify missing information</p>
            </div>
            <?php if (!empty($officers)): ?>
            <div class="flex gap-2">
                <a href="<?php echo BASE_URL; ?>/reports/r518-checker.php" class="inline-flex items-center justify-center px-3 sm:px-4 py-2 bg-purple-500 text-white rounded-lg hover:bg-purple-600 transition-colors shadow-sm print:hidden text-sm">
                    <svg class="w-4 h-4 sm:mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    <span class="hidden sm:inline">R5-18 Checker</span>
                </a>
                <button onclick="openPrintView()" class="inline-flex items-center justify-center px-3 sm:px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors shadow-sm print:hidden text-sm">
                    <svg class="w-4 h-4 sm:mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>
                    </svg>
                    <span class="hidden sm:inline">Print View</span>
                </button>
                <button onclick="window.print()" class="inline-flex items-center justify-center px-3 sm:px-4 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600 transition-colors shadow-sm print:hidden text-sm">
                    <svg class="w-4 h-4 sm:mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>
                    </svg>
                    <span class="hidden sm:inline">Quick Print</span>
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
            <?php echo Security::escape($error); ?>
        </div>
    <?php endif; ?>

    <!-- Editable Fields Tip -->
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 print:hidden">
        <div class="flex items-start gap-3">
            <div class="flex-shrink-0">
                <svg class="w-5 h-5 text-blue-600 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                </svg>
            </div>
            <div class="flex-1 min-w-0">
                <h3 class="text-sm font-medium text-blue-900 mb-1">💡 Quick Edit Tip</h3>
                <p class="text-sm text-blue-800">
                    <strong>Control Number</strong>, <strong>Registry Number</strong>, <strong>Purok</strong>, and <strong>Grupo</strong> fields are editable! 
                    Simply <strong>click on any field</strong> to edit. For Control and Registry numbers, start typing to search existing records. Changes save automatically.
                </p>
            </div>
        </div>
    </div>

    <!-- Statistics Summary -->
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 sm:gap-4 print:hidden">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 p-3 sm:p-4">
            <div class="flex items-center">
                <div class="flex-shrink-0 bg-blue-100 rounded-lg p-2 sm:p-3">
                    <svg class="w-5 h-5 sm:w-6 sm:h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                    </svg>
                </div>
                <div class="ml-3 sm:ml-4 min-w-0">
                    <p class="text-xs sm:text-sm text-gray-500 truncate">Total Officers</p>
                    <p class="text-lg sm:text-2xl font-semibold text-gray-900"><?php echo number_format($statistics['total']); ?></p>
                </div>
            </div>
        </div>
        
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 p-3 sm:p-4">
            <div class="flex items-center">
                <div class="flex-shrink-0 bg-green-100 rounded-lg p-2 sm:p-3">
                    <svg class="w-5 h-5 sm:w-6 sm:h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
                <div class="ml-3 sm:ml-4 min-w-0">
                    <p class="text-xs sm:text-sm text-gray-500 truncate">Complete</p>
                    <p class="text-lg sm:text-2xl font-semibold text-green-600"><?php echo number_format($statistics['complete']); ?></p>
                </div>
            </div>
        </div>
        
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 p-3 sm:p-4">
            <div class="flex items-center">
                <div class="flex-shrink-0 bg-orange-100 rounded-lg p-2 sm:p-3">
                    <svg class="w-5 h-5 sm:w-6 sm:h-6 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
                    </svg>
                </div>
                <div class="ml-3 sm:ml-4 min-w-0">
                    <p class="text-xs sm:text-sm text-gray-500 truncate">Duplicates</p>
                    <p class="text-lg sm:text-2xl font-semibold text-orange-600"><?php echo number_format($statistics['duplicates']); ?></p>
                </div>
            </div>
        </div>
        
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 p-3 sm:p-4">
            <div class="flex items-center">
                <div class="flex-shrink-0 bg-yellow-100 rounded-lg p-2 sm:p-3">
                    <svg class="w-5 h-5 sm:w-6 sm:h-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                    </svg>
                </div>
                <div class="ml-3 sm:ml-4 min-w-0">
                    <p class="text-xs sm:text-sm text-gray-500 truncate">No Control #</p>
                    <p class="text-lg sm:text-2xl font-semibold text-yellow-600"><?php echo number_format($statistics['missing_control']); ?></p>
                </div>
            </div>
        </div>
        
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 p-3 sm:p-4">
            <div class="flex items-center">
                <div class="flex-shrink-0 bg-orange-100 rounded-lg p-2 sm:p-3">
                    <svg class="w-5 h-5 sm:w-6 sm:h-6 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                    </svg>
                </div>
                <div class="ml-3 sm:ml-4 min-w-0">
                    <p class="text-xs sm:text-sm text-gray-500 truncate">No Registry #</p>
                    <p class="text-lg sm:text-2xl font-semibold text-orange-600"><?php echo number_format($statistics['missing_registry']); ?></p>
                </div>
            </div>
        </div>
        
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 p-3 sm:p-4 col-span-2 sm:col-span-1">
            <div class="flex items-center">
                <div class="flex-shrink-0 bg-red-100 rounded-lg p-2 sm:p-3">
                    <svg class="w-5 h-5 sm:w-6 sm:h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </div>
                <div class="ml-3 sm:ml-4 min-w-0">
                    <p class="text-sm text-gray-500">Missing Both</p>
                    <p class="text-2xl font-semibold text-red-600"><?php echo number_format($statistics['missing_both']); ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 p-4 sm:p-5 print:hidden">
        <form method="GET" action="" class="space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 sm:gap-4">
                <div>
                    <label class="block text-xs sm:text-sm font-medium text-gray-700 mb-1 sm:mb-2">District</label>
                    <select name="district" id="district" class="block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm" onchange="loadLocals(this.value)">
                        <option value="">All Districts</option>
                        <?php foreach ($districts as $district): ?>
                            <option value="<?php echo Security::escape($district['district_code']); ?>" <?php echo $filterDistrict === $district['district_code'] ? 'selected' : ''; ?>>
                                <?php echo Security::escape($district['district_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label class="block text-xs sm:text-sm font-medium text-gray-700 mb-1 sm:mb-2">Local Congregation</label>
                    <select name="local" id="local" class="block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                        <option value="">All Locals</option>
                        <?php foreach ($locals as $local): ?>
                            <option value="<?php echo Security::escape($local['local_code']); ?>" <?php echo $filterLocal === $local['local_code'] ? 'selected' : ''; ?>>
                                <?php echo Security::escape($local['local_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label class="block text-xs sm:text-sm font-medium text-gray-700 mb-1 sm:mb-2">Department</label>
                    <select name="department" class="block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                        <option value="">All Departments</option>
                        <?php foreach (getDepartments() as $dept): ?>
                            <option value="<?php echo Security::escape($dept); ?>" <?php echo $filterDepartment === $dept ? 'selected' : ''; ?>>
                                <?php echo Security::escape($dept); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label class="block text-xs sm:text-sm font-medium text-gray-700 mb-1 sm:mb-2">Status</label>
                    <select name="status" class="block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                        <option value="active" <?php echo $filterStatus === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $filterStatus === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        <option value="all" <?php echo $filterStatus === 'all' ? 'selected' : ''; ?>>All</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-xs sm:text-sm font-medium text-gray-700 mb-1 sm:mb-2">Issue Filter</label>
                    <select name="issue" class="block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                        <option value="">All Records</option>
                        <option value="duplicates" <?php echo $filterIssue === 'duplicates' ? 'selected' : ''; ?>>⚠ Show Duplicates Only</option>
                        <option value="complete" <?php echo $filterIssue === 'complete' ? 'selected' : ''; ?>>Complete Records</option>
                        <option value="missing_control" <?php echo $filterIssue === 'missing_control' ? 'selected' : ''; ?>>Missing Control Number</option>
                        <option value="missing_registry" <?php echo $filterIssue === 'missing_registry' ? 'selected' : ''; ?>>Missing Registry Number</option>
                        <option value="missing_both" <?php echo $filterIssue === 'missing_both' ? 'selected' : ''; ?>>Missing Both</option>
                        <option value="no_purok" <?php echo $filterIssue === 'no_purok' ? 'selected' : ''; ?>>No Purok</option>
                        <option value="no_grupo" <?php echo $filterIssue === 'no_grupo' ? 'selected' : ''; ?>>No Grupo</option>
                        <option value="no_purok_grupo" <?php echo $filterIssue === 'no_purok_grupo' ? 'selected' : ''; ?>>No Purok & Grupo</option>
                    </select>
                </div>
            </div>
            
            <!-- Field Display Options -->
            <div class="border-t pt-4">
                <label class="block text-xs sm:text-sm font-medium text-gray-700 mb-2 sm:mb-3">Display Columns</label>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2 sm:gap-3">
                    <label class="flex items-center">
                        <input type="checkbox" name="show_control" value="1" <?php echo $showControlNumber ? 'checked' : ''; ?> class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <span class="ml-2 text-xs sm:text-sm text-gray-700">Control Number</span>
                    </label>
                    <label class="flex items-center">
                        <input type="checkbox" name="show_registry" value="1" <?php echo $showRegistryNumber ? 'checked' : ''; ?> class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <span class="ml-2 text-xs sm:text-sm text-gray-700">Registry Number</span>
                    </label>
                    <label class="flex items-center">
                        <input type="checkbox" name="show_oath" value="1" <?php echo $showOathDate ? 'checked' : ''; ?> class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <span class="ml-2 text-xs sm:text-sm text-gray-700">Oath Date</span>
                    </label>
                    <label class="flex items-center">
                        <input type="checkbox" name="show_departments" value="1" <?php echo $showDepartments ? 'checked' : ''; ?> class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <span class="ml-2 text-xs sm:text-sm text-gray-700">Departments</span>
                    </label>
                </div>
            </div>
            
            <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2">
                <button type="submit" class="w-full sm:w-auto px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors text-sm font-medium">
                    Generate Report
                </button>
                <a href="?" class="w-full sm:w-auto text-center px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors text-sm font-medium">
                    Clear Filters
                </a>
            </div>
        </form>
    </div>

    <!-- Report Table -->
    <?php if (!empty($officers)): ?>
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 overflow-hidden">
        <!-- Desktop Table View - Hidden on mobile -->
        <div class="hidden md:block overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">#</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Officer Name</th>
                        <?php if ($showControlNumber): ?>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Control Number</th>
                        <?php endif; ?>
                        <?php if ($showRegistryNumber): ?>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Registry Number</th>
                        <?php endif; ?>
                        <?php if ($showOathDate): ?>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Oath Date</th>
                        <?php endif; ?>
                        <?php if ($showDepartments): ?>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tungkulin</th>
                        <?php endif; ?>
                        <?php if ($filterIssue === 'r518_checker'): ?>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Has R5-18?</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Has 2x2 Picture?</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Complete Signatories?</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Data Verification</th>
                        <?php else: ?>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Purok</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Grupo</th>
                        <?php endif; ?>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">LORC/LCRC Check</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider print:hidden">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200">
                    <?php foreach ($officers as $officer): 
                        $rowClass = '';
                        $statusBadge = '';
                        switch ($officer['issue_type']) {
                            case 'complete':
                                $rowClass = 'bg-green-50';
                                $statusBadge = '<span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-green-100 text-green-800">✓ Complete</span>';
                                break;
                            case 'missing_control':
                                $rowClass = 'bg-yellow-50';
                                $statusBadge = '<span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-yellow-100 text-yellow-800">⚠ No Control #</span>';
                                break;
                            case 'missing_registry':
                                $rowClass = 'bg-orange-50';
                                $statusBadge = '<span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-orange-100 text-orange-800">⚠ No Registry #</span>';
                                break;
                            case 'missing_both':
                                $rowClass = 'bg-red-50';
                                $statusBadge = '<span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-red-100 text-red-800">✗ Missing Both</span>';
                                break;
                            case 'no_purok':
                                $rowClass = 'bg-purple-50';
                                $statusBadge = '<span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-purple-100 text-purple-800">⚠ No Purok</span>';
                                break;
                            case 'no_grupo':
                                $rowClass = 'bg-indigo-50';
                                $statusBadge = '<span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-indigo-100 text-indigo-800">⚠ No Grupo</span>';
                                break;
                            case 'no_purok_grupo':
                                $rowClass = 'bg-pink-50';
                                $statusBadge = '<span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-pink-100 text-pink-800">✗ No Purok & Grupo</span>';
                                break;
                            case 'r518_checker':
                                $rowClass = 'bg-blue-50';
                                $r518StatusLabel = '';
                                switch($officer['r518_status']) {
                                    case 'verified':
                                        $r518StatusLabel = '<span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-blue-100 text-blue-800" title="Verified: ' . ($officer['r518_verified_at'] ? date('M d, Y', strtotime($officer['r518_verified_at'])) : '') . '">✓ Verified</span>';
                                        break;
                                    case 'complete':
                                        $r518StatusLabel = '<span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-green-100 text-green-800">✓ Complete</span>';
                                        break;
                                    case 'incomplete':
                                        $r518StatusLabel = '<span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-yellow-100 text-yellow-800">⚠ Incomplete</span>';
                                        break;
                                    default:
                                        $r518StatusLabel = '<span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-gray-100 text-gray-800">○ Pending</span>';
                                }
                                $statusBadge = $r518StatusLabel;
                                if (!empty($officer['r518_notes'])) {
                                    $statusBadge .= '<div class="text-xs text-gray-500 mt-1" title="' . Security::escape($officer['r518_notes']) . '">📝 Has Notes</div>';
                                }
                                break;
                        }
                    ?>
                    <tr class="<?php echo $rowClass; ?>">
                        <td class="px-4 py-3 text-sm text-gray-900"><?php echo $officer['row_number']; ?></td>
                        <td class="px-4 py-3 text-sm text-gray-900 font-medium">
                            <?php echo Security::escape($officer['full_name']); ?>
                            <?php if ($officer['is_duplicate']): ?>
                            <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-orange-100 text-orange-800" title="Duplicate detected">⚠ DUP</span>
                            <?php endif; ?>
                        </td>
                        <?php if ($showControlNumber): ?>
                        <td class="px-4 py-3 text-sm <?php echo $officer['has_control'] ? 'text-gray-900' : 'text-red-500 font-semibold'; ?>">
                            <div class="editable-cell-wrapper relative" style="min-width: 120px;">
                                <span class="editable-cell editable-searchable" 
                                      contenteditable="true" 
                                      data-officer-id="<?php echo $officer['officer_id']; ?>" 
                                      data-field="control_number"
                                      data-original="<?php echo Security::escape($officer['control_number'] ?? ''); ?>"
                                      data-search-type="control"
                                      title="Click to edit or search"
                                      ><?php echo $officer['control_number'] ? Security::escape($officer['control_number']) : '—'; ?></span>
                                <div class="search-dropdown hidden absolute z-50 mt-1 w-64 bg-white dark:bg-gray-800 border border-gray-300 rounded-lg shadow-lg max-h-48 overflow-y-auto"></div>
                                <span class="loading-spinner hidden"></span>
                                <span class="save-indicator hidden ml-2 text-xs text-green-600">✓</span>
                            </div>
                        </td>
                        <?php endif; ?>
                        <?php if ($showRegistryNumber): ?>
                        <td class="px-4 py-3 text-sm <?php echo $officer['has_registry'] ? 'text-gray-900' : 'text-red-500 font-semibold'; ?>">
                            <div class="editable-cell-wrapper relative" style="min-width: 120px;">
                                <span class="editable-cell editable-searchable" 
                                      contenteditable="true" 
                                      data-officer-id="<?php echo $officer['officer_id']; ?>" 
                                      data-field="registry_number"
                                      data-original="<?php echo Security::escape($officer['registry_number'] ?? ''); ?>"
                                      data-search-type="registry"
                                      title="Click to edit or search"
                                      ><?php echo $officer['registry_number'] ? Security::escape($officer['registry_number']) : '—'; ?></span>
                                <div class="search-dropdown hidden absolute z-50 mt-1 w-96 bg-white dark:bg-gray-800 border border-gray-300 rounded-lg shadow-lg max-h-64 overflow-y-auto"></div>
                                <span class="loading-spinner hidden"></span>
                                <span class="save-indicator hidden ml-2 text-xs text-green-600">✓</span>
                            </div>
                        </td>
                        <?php endif; ?>
                        <?php if ($showOathDate): ?>
                        <td class="px-4 py-3 text-sm text-gray-900">
                            <?php echo $officer['oath_dates'] ? Security::escape($officer['oath_dates']) : '—'; ?>
                        </td>
                        <?php endif; ?>
                        <?php if ($showDepartments): ?>
                        <td class="px-4 py-3 text-sm text-gray-700">
                            <?php echo $officer['departments'] ? Security::escape($officer['departments']) : '—'; ?>
                        </td>
                        <?php endif; ?>
                        <?php if ($filterIssue === 'r518_checker'): ?>
                        <td class="px-4 py-3 text-center">
                            <button type="button" 
                                    class="r518-toggle-btn inline-flex items-center justify-center w-10 h-10 rounded-lg transition-all duration-200 <?php echo $officer['has_r518'] ? 'bg-green-100 hover:bg-green-200 text-green-700' : 'bg-gray-100 hover:bg-gray-200 text-gray-400'; ?>" 
                                    data-officer-id="<?php echo $officer['officer_id']; ?>" 
                                    data-field="r518_submitted" 
                                    data-value="<?php echo $officer['has_r518'] ? 1 : 0; ?>"
                                    title="<?php echo $officer['has_r518'] ? 'R5-18 Submitted - Click to mark as Not Submitted' : 'R5-18 Not Submitted - Click to mark as Submitted'; ?>">
                                <i class="<?php echo $officer['has_r518'] ? 'fa-solid' : 'fa-regular'; ?> fa-thumbs-up text-xl"></i>
                            </button>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <button type="button" 
                                    class="r518-toggle-btn inline-flex items-center justify-center w-10 h-10 rounded-lg transition-all duration-200 <?php echo $officer['has_picture'] ? 'bg-green-100 hover:bg-green-200 text-green-700' : 'bg-gray-100 hover:bg-gray-200 text-gray-400'; ?>" 
                                    data-officer-id="<?php echo $officer['officer_id']; ?>" 
                                    data-field="r518_picture_attached" 
                                    data-value="<?php echo $officer['has_picture'] ? 1 : 0; ?>"
                                    title="<?php echo $officer['has_picture'] ? '2x2 Picture Attached - Click to mark as Missing' : '2x2 Picture Missing - Click to mark as Attached'; ?>">
                                <i class="<?php echo $officer['has_picture'] ? 'fa-solid' : 'fa-regular'; ?> fa-thumbs-up text-xl"></i>
                            </button>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <button type="button" 
                                    class="r518-toggle-btn inline-flex items-center justify-center w-10 h-10 rounded-lg transition-all duration-200 <?php echo $officer['has_signatories'] ? 'bg-green-100 hover:bg-green-200 text-green-700' : 'bg-gray-100 hover:bg-gray-200 text-gray-400'; ?>" 
                                    data-officer-id="<?php echo $officer['officer_id']; ?>" 
                                    data-field="r518_signatories_complete" 
                                    data-value="<?php echo $officer['has_signatories'] ? 1 : 0; ?>"
                                    title="<?php echo $officer['has_signatories'] ? 'Signatories Complete - Click to mark as Incomplete' : 'Signatories Incomplete - Click to mark as Complete'; ?>">
                                <i class="<?php echo $officer['has_signatories'] ? 'fa-solid' : 'fa-regular'; ?> fa-thumbs-up text-xl"></i>
                            </button>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <?php 
                            $canVerifyData = $officer['has_r518'] && $officer['has_picture'] && $officer['has_signatories'];
                            $isLocked = !$canVerifyData;
                            ?>
                            <button type="button" 
                                    class="r518-toggle-btn inline-flex items-center justify-center w-10 h-10 rounded-lg transition-all duration-200 <?php echo $isLocked ? 'bg-gray-50 cursor-not-allowed opacity-50' : ($officer['has_data_verify'] ? 'bg-green-100 hover:bg-green-200 text-green-700' : 'bg-gray-100 hover:bg-gray-200 text-gray-400'); ?>" 
                                    data-officer-id="<?php echo $officer['officer_id']; ?>" 
                                    data-field="r518_data_verify" 
                                    data-value="<?php echo $officer['has_data_verify'] ? 1 : 0; ?>"
                                    data-locked="<?php echo $isLocked ? '1' : '0'; ?>"
                                    <?php echo $isLocked ? 'disabled' : ''; ?>
                                    title="<?php echo $isLocked ? '🔒 Complete all R5-18 requirements first (R5-18, Picture, Signatories)' : ($officer['has_data_verify'] ? 'Data Verified - Click to mark as Not Verified' : 'Data Not Verified - Click to mark as Verified'); ?>">
                                <i class="<?php echo $officer['has_data_verify'] ? 'fa-solid' : 'fa-regular'; ?> fa-thumbs-up text-xl <?php echo $isLocked ? 'text-gray-300' : ''; ?>"></i>
                            </button>
                        </td>
                        <?php else: ?>
                        <td class="px-4 py-3 text-sm">
                            <div class="editable-cell-wrapper relative inline-flex items-center">
                                <span class="editable-cell" 
                                      contenteditable="true" 
                                      data-officer-id="<?php echo $officer['officer_id']; ?>" 
                                      data-field="purok"
                                      data-original="<?php echo Security::escape($officer['purok'] ?? ''); ?>"
                                      title="Click to edit"
                                      ><?php echo $officer['purok'] ? Security::escape($officer['purok']) : '—'; ?></span>
                                <span class="loading-spinner hidden"></span>
                                <span class="save-indicator hidden ml-2 text-xs text-green-600">✓</span>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <div class="editable-cell-wrapper relative inline-flex items-center">
                                <span class="editable-cell" 
                                      contenteditable="true" 
                                      data-officer-id="<?php echo $officer['officer_id']; ?>" 
                                      data-field="grupo"
                                      data-original="<?php echo Security::escape($officer['grupo'] ?? ''); ?>"
                                      title="Click to edit"
                                      ><?php echo $officer['grupo'] ? Security::escape($officer['grupo']) : '—'; ?></span>
                                <span class="loading-spinner hidden"></span>
                                <span class="save-indicator hidden ml-2 text-xs text-green-600">✓</span>
                            </div>
                        </td>
                        <?php endif; ?>
                        <td class="px-4 py-3 text-center">
                            <?php echo $statusBadge; ?>
                        </td>
                        <td class="px-4 py-3 text-center print:hidden">
                            <div class="flex items-center justify-center gap-2">
                                <?php if ($officer['is_duplicate']): ?>
                                <button onclick="showMergeModal(<?php echo $officer['officer_id']; ?>, <?php echo htmlspecialchars(json_encode($officer['duplicate_ids'])); ?>, '<?php echo Security::escape($officer['full_name']); ?>')" 
                                   class="inline-flex items-center px-2 py-1 text-xs font-medium text-orange-600 hover:text-orange-800 hover:bg-orange-50 rounded transition-colors"
                                   title="Merge Duplicate">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
                                    </svg>
                                </button>
                                <?php endif; ?>
                                <button onclick="OfficerDetailsModal.open('<?php echo $officer['officer_uuid']; ?>')" 
                                   class="inline-flex items-center px-2 py-1 text-xs font-medium text-purple-600 hover:text-purple-800 hover:bg-purple-50 rounded transition-colors"
                                   title="Quick View">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                </button>
                                <a href="<?php echo BASE_URL; ?>/officers/view.php?id=<?php echo urlencode($officer['officer_uuid']); ?>" 
                                   class="inline-flex items-center px-2 py-1 text-xs font-medium text-blue-600 hover:text-blue-800 hover:bg-blue-50 rounded transition-colors"
                                   title="View Full Page">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                                    </svg>
                                </a>
                                <a href="<?php echo BASE_URL; ?>/officers/edit.php?id=<?php echo urlencode($officer['officer_uuid']); ?>" 
                                   class="inline-flex items-center px-2 py-1 text-xs font-medium text-gray-600 hover:text-gray-800 hover:bg-gray-50 rounded transition-colors"
                                   target="_blank"
                                   rel="noopener noreferrer"
                                   title="Edit Officer">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                    </svg>
                                </a>
                                <button onclick="deleteOfficer(<?php echo $officer['officer_id']; ?>, '<?php echo Security::escape($officer['full_name']); ?>')" 
                                   class="inline-flex items-center px-2 py-1 text-xs font-medium text-red-600 hover:text-red-800 hover:bg-red-50 rounded transition-colors"
                                   title="Delete Officer">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                    </svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Mobile Card View - Hidden on desktop -->
        <div class="md:hidden divide-y divide-gray-200">
            <?php foreach ($officers as $officer): 
                $rowClass = '';
                $statusBadge = '';
                switch ($officer['issue_type']) {
                    case 'complete':
                        $rowClass = 'bg-green-50';
                        $statusBadge = '<span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-green-100 text-green-800">✓ Complete</span>';
                        break;
                    case 'missing_control':
                        $rowClass = 'bg-yellow-50';
                        $statusBadge = '<span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-yellow-100 text-yellow-800">⚠ No Control #</span>';
                        break;
                    case 'missing_registry':
                        $rowClass = 'bg-orange-50';
                        $statusBadge = '<span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-orange-100 text-orange-800">⚠ No Registry #</span>';
                        break;
                    case 'missing_both':
                        $rowClass = 'bg-red-50';
                        $statusBadge = '<span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-red-100 text-red-800">✗ Missing Both</span>';
                        break;
                    case 'no_purok':
                        $rowClass = 'bg-purple-50';
                        $statusBadge = '<span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-purple-100 text-purple-800">⚠ No Purok</span>';
                        break;
                    case 'no_grupo':
                        $rowClass = 'bg-indigo-50';
                        $statusBadge = '<span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-indigo-100 text-indigo-800">⚠ No Grupo</span>';
                        break;
                    case 'no_purok_grupo':
                        $rowClass = 'bg-pink-50';
                        $statusBadge = '<span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-pink-100 text-pink-800">✗ No Purok & Grupo</span>';
                        break;
                }
            ?>
            <div class="p-4 <?php echo $rowClass; ?>">
                <div class="flex items-start justify-between mb-3">
                    <div class="flex-1">
                        <div class="flex items-center gap-2 mb-1">
                            <span class="text-xs font-medium text-gray-500">#<?php echo $officer['row_number']; ?></span>
                            <?php echo $statusBadge; ?>
                        </div>
                        <h3 class="text-sm font-semibold text-gray-900"><?php echo Security::escape($officer['full_name']); ?></h3>
                    </div>
                    <div class="flex gap-2 ml-2">
                        <button onclick="OfficerDetailsModal.open('<?php echo $officer['officer_uuid']; ?>')" 
                           class="inline-flex items-center justify-center w-8 h-8 text-purple-600 hover:bg-purple-50 rounded-lg transition-colors"
                           title="Quick View">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </button>
                        <a href="<?php echo BASE_URL; ?>/officers/view.php?id=<?php echo urlencode($officer['officer_uuid']); ?>" 
                           class="inline-flex items-center justify-center w-8 h-8 text-blue-600 hover:bg-blue-50 rounded-lg transition-colors"
                           title="View Full Page">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                            </svg>
                        </a>
                        <a href="<?php echo BASE_URL; ?>/officers/edit.php?id=<?php echo urlencode($officer['officer_uuid']); ?>" 
                           class="inline-flex items-center justify-center w-8 h-8 text-gray-600 hover:bg-gray-50 rounded-lg transition-colors"
                           target="_blank"
                           title="Edit">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                            </svg>
                        </a>
                    </div>
                </div>
                
                <div class="grid grid-cols-2 gap-x-4 gap-y-2 text-xs">
                    <?php if ($showControlNumber): ?>
                    <div>
                        <span class="text-gray-500">Control #:</span>
                        <span class="ml-1 <?php echo $officer['has_control'] ? 'text-gray-900' : 'text-red-500 font-semibold'; ?>">
                            <?php echo $officer['control_number'] ? Security::escape($officer['control_number']) : '—'; ?>
                        </span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($showRegistryNumber): ?>
                    <div>
                        <span class="text-gray-500">Registry #:</span>
                        <span class="ml-1 <?php echo $officer['has_registry'] ? 'text-gray-900' : 'text-red-500 font-semibold'; ?>">
                            <?php echo $officer['registry_number'] ? Security::escape($officer['registry_number']) : '—'; ?>
                        </span>
                    </div>
                    <?php endif; ?>
                    
                    <div>
                        <span class="text-gray-500">Purok:</span>
                        <span class="editable-cell ml-1 text-gray-900" 
                              contenteditable="true" 
                              data-officer-id="<?php echo $officer['officer_id']; ?>" 
                              data-field="purok"
                              data-original="<?php echo Security::escape($officer['purok'] ?? ''); ?>"
                              title="Click to edit"><?php echo $officer['purok'] ? Security::escape($officer['purok']) : '—'; ?></span>
                    </div>
                    
                    <div>
                        <span class="text-gray-500">Grupo:</span>
                        <span class="editable-cell ml-1 text-gray-900" 
                              contenteditable="true" 
                              data-officer-id="<?php echo $officer['officer_id']; ?>" 
                              data-field="grupo"
                              data-original="<?php echo Security::escape($officer['grupo'] ?? ''); ?>"
                              title="Click to edit"><?php echo $officer['grupo'] ? Security::escape($officer['grupo']) : '—'; ?></span>
                    </div>
                    
                    <?php if ($showOathDate && $officer['oath_dates']): ?>
                    <div class="col-span-2">
                        <span class="text-gray-500">Oath Date:</span>
                        <span class="ml-1 text-gray-900"><?php echo Security::escape($officer['oath_dates']); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($showDepartments && $officer['departments']): ?>
                    <div class="col-span-2">
                        <span class="text-gray-500">Tungkulin:</span>
                        <span class="ml-1 text-gray-900"><?php echo Security::escape($officer['departments']); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php else: ?>
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 p-12">
        <div class="text-center">
            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
            </svg>
            <h3 class="mt-2 text-sm font-medium text-gray-900">No records found</h3>
            <p class="mt-1 text-sm text-gray-500">Try adjusting your filters or generate a new report.</p>
        </div>
    </div>
    <?php endif; ?>
</div>

<style>
/* Mobile responsiveness improvements */
@media (max-width: 767px) {
    /* Improve touch targets on mobile */
    .editable-cell {
        min-width: 50px;
        padding: 4px 6px;
        min-height: 32px;
        display: inline-block;
    }
    
    /* Better spacing for mobile cards */
    .space-y-6 > * + * {
        margin-top: 1rem;
    }
    
    /* Optimize form inputs for mobile */
    select, input[type="checkbox"] {
        font-size: 16px; /* Prevents zoom on iOS */
    }
}

/* Editable cell styling - minimalist */
.editable-cell {
    position: relative;
    outline: none;
    display: inline-block;
    min-width: 60px;
    padding: 2px 4px;
    cursor: pointer;
    border-bottom: 1px dashed transparent;
    transition: all 0.15s ease;
}

.editable-cell:hover {
    border-bottom-color: #93c5fd;
}

.editable-cell:focus {
    border-bottom: 2px solid #3b82f6;
    background-color: #eff6ff;
    padding: 2px 6px;
    border-radius: 2px;
}

/* Search dropdown styling */
.search-dropdown {
    box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
}

.search-dropdown:not(.hidden) {
    animation: fadeIn 0.15s ease-in;
}

.editable-cell-wrapper {
    position: relative;
    display: inline-block;
}

/* Animation for save indicator */
@keyframes fadeIn {
    from {
        opacity: 0;
    }
    to {
        opacity: 1;
    }
}

/* Saving state */
.saving {
    opacity: 0.7;
    pointer-events: none;
}

/* Loading spinner */
.loading-spinner {
    display: inline-block;
    width: 12px;
    height: 12px;
    border: 2px solid #e5e7eb;
    border-top-color: #3b82f6;
    border-radius: 50%;
    animation: spin 0.6s linear infinite;
    margin-left: 6px;
    vertical-align: middle;
}

/* Hidden state for spinner */
.loading-spinner.hidden {
    display: none !important;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

@media print {
    @page {
        size: auto portrait;
        margin: 0.75in 0.5in;
    }
    
    body {
        margin: 0;
        padding: 0;
        font-family: 'Times New Roman', Times, serif;
        font-size: 11pt;
        color: #000;
        line-height: 1.4;
    }
    
    * {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    
    .print\:hidden {
        display: none !important;
    }
    
    /* Hide all cards and styling for clean print */
    .space-y-6 {
        display: block;
    }
    
    .space-y-6 > * {
        margin: 0 !important;
        padding: 0 !important;
        border: none !important;
        box-shadow: none !important;
        border-radius: 0 !important;
        background: white !important;
    }
    
    /* Print Header */
    .space-y-6 > .bg-white:first-child {
        margin-bottom: 20px !important;
        padding-bottom: 10px !important;
        border-bottom: 2px solid #000 !important;
        page-break-after: avoid;
    }
    
    .space-y-6 > .bg-white:first-child h1 {
        font-size: 16pt;
        font-weight: bold;
        text-align: center;
        margin: 0 0 5px 0;
        text-transform: uppercase;
        font-family: Arial, sans-serif;
    }
    
    .space-y-6 > .bg-white:first-child p {
        font-size: 10pt;
        text-align: center;
        margin: 0;
        font-style: italic;
    }
    
    /* Table container */
    .overflow-x-auto {
        overflow: visible !important;
        margin-top: 15px;
    }
    
    /* Clean professional table */
    table {
        width: 100%;
        border-collapse: collapse;
        page-break-inside: auto;
        font-family: Arial, sans-serif;
        font-size: 9pt;
        border: 1px solid #000;
    }
    
    thead {
        display: table-header-group;
        background-color: #fff !important;
    }
    
    thead th {
        background-color: #fff !important;
        border: 1px solid #000 !important;
        padding: 6px 4px !important;
        font-weight: bold;
        text-align: left;
        color: #000 !important;
        font-size: 9pt;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    tbody tr {
        page-break-inside: avoid;
        page-break-after: auto;
        background-color: #fff !important;
    }
    
    tbody td {
        border: 1px solid #000 !important;
        padding: 4px 4px !important;
        font-size: 9pt;
        color: #000 !important;
        background-color: #fff !important;
    }
    
    /* Remove all background colors for clean print */
    .bg-green-50,
    .bg-yellow-50,
    .bg-orange-50,
    .bg-red-50,
    .bg-purple-50,
    .bg-indigo-50,
    .bg-pink-50 {
        background-color: #fff !important;
    }
    
    /* Status badges - simple text */
    tbody td span {
        background: none !important;
        color: #000 !important;
        border: none !important;
        padding: 0 !important;
        font-weight: normal !important;
        font-size: 9pt !important;
    }
    
    /* Text colors - all black */
    .text-red-500,
    .text-gray-900,
    .text-gray-700,
    .text-blue-600 {
        color: #000 !important;
        font-weight: normal !important;
    }
    
    /* Missing values emphasis */
    td:has(.text-red-500) {
        font-style: italic;
    }
    
    /* Footer with page numbers */
    @page {
        @bottom-right {
            content: "Page " counter(page) " of " counter(pages);
            font-size: 8pt;
            font-family: Arial, sans-serif;
        }
    }
}

/* Screen styles for table */
table {
    border-collapse: collapse;
}

thead th {
    position: sticky;
    top: 0;
    z-index: 10;
}

</style>

<script>
// Editable fields functionality
let searchTimeout;
document.addEventListener('DOMContentLoaded', function() {
    const editableCells = document.querySelectorAll('.editable-cell');
    
    editableCells.forEach(cell => {
        // Store original value
        cell.dataset.original = cell.textContent.trim();
        
        // Handle input for searchable fields
        if (cell.classList.contains('editable-searchable')) {
            cell.addEventListener('input', function() {
                const searchType = this.dataset.searchType;
                const value = this.textContent.trim();
                
                clearTimeout(searchTimeout);
                
                if (value.length >= 2 && searchType) {
                    searchTimeout = setTimeout(() => {
                        performSearch(this, searchType, value);
                    }, 300);
                } else {
                    hideSearchDropdown(this);
                }
            });
        }
        
        // Handle focus - select all text
        cell.addEventListener('focus', function() {
            if (this.textContent === '—') {
                this.textContent = '';
            }
            // Select all text
            setTimeout(() => {
                const range = document.createRange();
                range.selectNodeContents(this);
                const sel = window.getSelection();
                sel.removeAllRanges();
                sel.addRange(range);
            }, 0);
        });
        
        // Handle blur - save changes (with delay for dropdown clicks)
        cell.addEventListener('blur', function() {
            setTimeout(() => {
                const dropdown = this.parentElement.querySelector('.search-dropdown');
                
                // Check if dropdown exists and is being hovered (for searchable fields)
                if (dropdown && dropdown.matches(':hover')) {
                    return;
                }
                
                const newValue = this.textContent.trim();
                const originalValue = this.dataset.original || '';
                
                // Normalize the new value for comparison (— is same as empty)
                const normalizedNewValue = newValue === '—' ? '' : newValue;
                const normalizedOriginalValue = originalValue === '—' ? '' : originalValue;
                
                // Update display to show dash if empty
                if (newValue === '') {
                    this.textContent = '—';
                }
                
                // Save if value actually changed
                if (normalizedNewValue !== normalizedOriginalValue) {
                    savePurokGrupo(this);
                }
                
                hideSearchDropdown(this);
            }, 200);
        });
        
        // Handle Enter key - save and blur
        cell.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                hideSearchDropdown(this);
                this.blur();
            }
            if (e.key === 'Escape') {
                const original = this.dataset.original;
                this.textContent = original || '—';
                hideSearchDropdown(this);
                this.blur();
            }
        });
    });
    
    // Close dropdowns when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.editable-cell-wrapper')) {
            document.querySelectorAll('.search-dropdown').forEach(dropdown => {
                dropdown.classList.add('hidden');
            });
        }
    });
});

function performSearch(element, searchType, query) {
    const dropdown = element.parentElement.querySelector('.search-dropdown');
    if (!dropdown) return;
    
    const apiUrl = searchType === 'control' 
        ? '<?php echo BASE_URL; ?>/api/search-legacy.php?search=' + encodeURIComponent(query)
        : '<?php echo BASE_URL; ?>/api/search-tarheta.php?search=' + encodeURIComponent(query);
    
    fetch(apiUrl)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.records && data.records.length > 0) {
                let html = '<div class="py-1">';
                
                // Add results count header
                html += `<div class="px-3 py-1.5 bg-gray-50 border-b border-gray-200 text-xs text-gray-600 font-medium">
                    Found ${data.records.length} match${data.records.length > 1 ? 'es' : ''}
                </div>`;
                
                data.records.forEach(record => {
                    const displayValue = searchType === 'control' 
                        ? record.control_number 
                        : record.registry_number;
                    // Handle both full_name (from search-tarheta) and name (from search-legacy)
                    const displayName = record.full_name || record.name || '';
                    const location = record.local_name || record.district_name || '';
                    
                    html += `
                        <div class="px-3 py-2 hover:bg-blue-50 cursor-pointer border-b border-gray-100 last:border-0 transition-colors"
                             onclick="selectSearchResult('${escapeHtml(displayValue)}', this.closest('.editable-cell-wrapper'))">
                            <div class="flex items-center justify-between gap-2">
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-semibold text-blue-600">${escapeHtml(displayValue)}</div>
                                    ${displayName ? `<div class="text-sm text-gray-900 mt-0.5">${escapeHtml(displayName)}</div>` : ''}
                                    ${location ? `<div class="text-xs text-gray-500 mt-0.5">${escapeHtml(location)}</div>` : ''}
                                </div>
                                <svg class="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                            </div>
                        </div>
                    `;
                });
                
                html += '</div>';
                dropdown.innerHTML = html;
                dropdown.classList.remove('hidden');
            } else {
                dropdown.innerHTML = '<div class="px-3 py-2 text-sm text-gray-500 text-center">No matches found</div>';
                dropdown.classList.remove('hidden');
            }
        })
        .catch(error => {
            console.error('Search error:', error);
            hideSearchDropdown(element);
        });
}

function selectSearchResult(value, wrapper) {
    const cell = wrapper.querySelector('.editable-cell');
    const oldValue = cell.textContent.trim();
    cell.textContent = value;
    hideSearchDropdown(cell);
    
    // Save if value changed
    if (value !== oldValue && value !== cell.dataset.original) {
        savePurokGrupo(cell);
    }
}

function hideSearchDropdown(element) {
    const dropdown = element.parentElement?.querySelector('.search-dropdown');
    if (dropdown) {
        dropdown.classList.add('hidden');
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text || '';
    return div.innerHTML;
}

function savePurokGrupo(element) {
    const officerId = element.dataset.officerId;
    const field = element.dataset.field;
    let value = element.textContent.trim();
    
    // Convert placeholder to empty string
    if (value === '—') {
        value = '';
    }
    
    // Get wrapper and UI elements
    const wrapper = element.closest('.editable-cell-wrapper');
    if (!wrapper) {
        return;
    }
    
    const indicator = wrapper.querySelector('.save-indicator');
    const spinner = wrapper.querySelector('.loading-spinner');
    
    // Show saving state - ONLY during API call
    element.classList.add('saving');
    if (indicator) indicator.classList.add('hidden');
    if (spinner) spinner.classList.remove('hidden');
    
    const payload = {
        officer_id: officerId,
        field: field,
        value: value,
        csrf_token: '<?php echo Security::generateCSRFToken(); ?>'
    };
    
    fetch('<?php echo BASE_URL; ?>/api/update-purok-grupo.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(payload)
    })
    .then(response => response.json())
    .then(data => {
        // Hide spinner immediately after response
        element.classList.remove('saving');
        if (spinner) spinner.classList.add('hidden');
        
        if (data.success) {
            // Update original value
            element.dataset.original = value || '';
            
            // Update display
            if (!value) {
                element.textContent = '—';
            }
            
            // Show success indicator briefly
            if (indicator) {
                indicator.classList.remove('hidden');
                setTimeout(() => {
                    indicator.classList.add('hidden');
                }, 2000);
            }
        } else {
            alert('Error: ' + (data.message || 'Could not save'));
            element.textContent = element.dataset.original || '—';
        }
    })
    .catch(error => {
        // Hide spinner on error
        element.classList.remove('saving');
        if (spinner) spinner.classList.add('hidden');
        alert('Error saving changes');
        element.textContent = element.dataset.original || '—';
    });
}

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

function openPrintView() {
    // Get current URL parameters
    const urlParams = new URLSearchParams(window.location.search);
    
    // Build print view URL with same parameters
    const printUrl = '<?php echo BASE_URL; ?>/reports/lorc-lcrc-print.php?' + urlParams.toString();
    
    // Open in new window
    window.open(printUrl, '_blank', 'width=1200,height=800');
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
</script>

<!-- Merge Modal -->
<div id="mergeModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-md bg-white dark:bg-gray-800">
        <div class="mt-3">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Merge Duplicate Officers</h3>
            <p class="text-sm text-gray-600 mb-4">Select which officer record to keep as the primary. All duties will be merged.</p>
            
            <div id="mergeOfficersList" class="space-y-2 mb-6">
                <!-- Will be populated by JavaScript -->
            </div>
            
            <div class="flex justify-end gap-3">
                <button onclick="closeMergeModal()" class="px-4 py-2 bg-gray-300 text-gray-800 rounded-lg hover:bg-gray-400">Cancel</button>
                <button id="confirmMergeBtn" onclick="confirmMerge()" class="px-4 py-2 bg-orange-600 text-white rounded-lg hover:bg-orange-700">Merge Officers</button>
            </div>
        </div>
    </div>
</div>

<script>
let mergeData = {
    primaryId: null,
    duplicateIds: [],
    officerName: ''
};

function showMergeModal(currentOfficerId, allDuplicateIds, officerName) {
    mergeData.duplicateIds = allDuplicateIds;
    mergeData.officerName = officerName;
    
    const modal = document.getElementById('mergeModal');
    const list = document.getElementById('mergeOfficersList');
    
    // Show modal immediately with loading state
    modal.classList.remove('hidden');
    list.innerHTML = `
        <div class="flex flex-col items-center justify-center py-8">
            <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-orange-600 mb-4"></div>
            <p class="text-gray-600 font-medium">Loading officer details...</p>
            <p class="text-gray-500 text-sm mt-1">Please wait</p>
        </div>
    `;
    
    // Fetch officer details for each duplicate
    Promise.all(allDuplicateIds.map(id => 
        fetch(`<?php echo BASE_URL; ?>/api/get-officer-details.php?officer_id=${id}`)
            .then(r => r.json())
    )).then(officers => {
        list.innerHTML = officers.map(officer => {
            // Format departments array into string
            let deptString = '—';
            if (officer.departments && Array.isArray(officer.departments) && officer.departments.length > 0) {
                deptString = officer.departments.map(d => {
                    let dept = d.department;
                    if (d.duty) dept += ` (${d.duty})`;
                    return dept;
                }).join(', ');
            }
            
            return `
            <label class="flex items-start p-3 border rounded-lg cursor-pointer hover:bg-gray-50">
                <input type="radio" name="primaryOfficer" value="${officer.officer_id}" 
                       class="mt-1 mr-3" ${officer.officer_id === currentOfficerId ? 'checked' : ''}>
                <div class="flex-1">
                    <div class="font-medium text-gray-900">${officer.full_name || officerName}</div>
                    <div class="text-sm text-gray-600">Control: ${officer.control_number || '—'} | Registry: ${officer.registry_number || '—'}</div>
                    <div class="text-sm text-gray-500">Departments: ${deptString}</div>
                </div>
            </label>
            `;
        }).join('');
        
        // Modal is already visible, just update content
    }).catch(error => {
        console.error('Error loading officers:', error);
        list.innerHTML = `
            <div class="text-center py-8">
                <svg class="mx-auto h-12 w-12 text-red-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <p class="text-red-600 font-medium">Failed to load officer details</p>
                <p class="text-gray-500 text-sm mt-1">Please try again</p>
            </div>
        `;
    });
}

function closeMergeModal() {
    document.getElementById('mergeModal').classList.add('hidden');
    mergeData = { primaryId: null, duplicateIds: [], officerName: '' };
}

async function confirmMerge() {
    const selectedRadio = document.querySelector('input[name="primaryOfficer"]:checked');
    if (!selectedRadio) {
        alert('Please select the primary officer to keep');
        return;
    }
    
    mergeData.primaryId = parseInt(selectedRadio.value);
    
    if (!confirm(`Merge all duplicate records into the selected officer?\\n\\nAll duties will be combined. Other duplicate records will be deleted.`)) {
        return;
    }
    
    const btn = document.getElementById('confirmMergeBtn');
    btn.disabled = true;
    btn.textContent = 'Merging...';
    
    try {
        const formData = new FormData();
        formData.append('primary_id', mergeData.primaryId);
        formData.append('duplicate_ids', JSON.stringify(mergeData.duplicateIds));
        formData.append('csrf_token', '<?php echo Security::generateCSRFToken(); ?>');
        
        const response = await fetch('<?php echo BASE_URL; ?>/api/merge-officers.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert('Officers merged successfully!');
            window.location.reload();
        } else {
            alert('Failed to merge: ' + result.message);
        }
    } catch (error) {
        console.error('Error merging officers:', error);
        alert('An error occurred while merging');
    } finally {
        btn.disabled = false;
        btn.textContent = 'Merge Officers';
    }
}

async function deleteOfficer(officerId, officerName) {
    if (!confirm(`Are you sure you want to DELETE this officer?\\n\\n${officerName}\\n\\nThis action cannot be undone!`)) {
        return;
    }
    
    if (!confirm(`FINAL CONFIRMATION:\\n\\nDelete ${officerName}?\\n\\nAll related data (departments, requests, etc.) will also be removed.`)) {
        return;
    }
    
    try {
        const formData = new FormData();
        formData.append('officer_id', officerId);
        formData.append('csrf_token', '<?php echo Security::generateCSRFToken(); ?>');
        
        const response = await fetch('<?php echo BASE_URL; ?>/api/delete-officer.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert('Officer deleted successfully');
            window.location.reload();
        } else {
            alert('Failed to delete: ' + result.message);
        }
    } catch (error) {
        console.error('Error deleting officer:', error);
        alert('An error occurred while deleting');
    }
}
</script>

<?php
// Render the reusable officer details modal
renderOfficerDetailsModal();

$content = ob_get_clean();

// Add the JavaScript file for the officer modal
$extraScripts = '<script src="' . BASE_URL . '/assets/js/officer-details-modal.js"></script>';

include __DIR__ . '/../includes/layout.php';
?>
