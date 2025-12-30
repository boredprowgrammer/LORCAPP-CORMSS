<?php
/**
 * Masterlist Generator
 * Generate official masterlist of current officers
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
$filterPurok = Security::sanitizeInput($_GET['purok'] ?? '');
$filterKapisanan = Security::sanitizeInput($_GET['kapisanan'] ?? '');
$filterExtGws = Security::sanitizeInput($_GET['ext_gws'] ?? '');
$filterStatus = Security::sanitizeInput($_GET['status'] ?? 'active');

// Auto-set district and local based on user role
if ($currentUser['role'] === 'district' || $currentUser['role'] === 'local') {
    $filterDistrict = $currentUser['district_code'];
}
if ($currentUser['role'] === 'local') {
    $filterLocal = $currentUser['local_code'];
}

// Get signatory names from cookies (empty by default)
$sig1 = $_COOKIE['masterlist_sig1'] ?? '';
$sig2 = $_COOKIE['masterlist_sig2'] ?? '';
$sig3 = $_COOKIE['masterlist_sig3'] ?? '';
$sig4 = $_COOKIE['masterlist_sig4'] ?? '';
$sig5 = $_COOKIE['masterlist_sig5'] ?? '';

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

// Note: Signatories are managed via cookies and autocomplete from tarheta_control via AJAX

// Get officers for masterlist
$officers = [];
$reportInfo = [];
try {
    // Build WHERE clause
    $whereConditions = ['o.is_active = 1'];
    $params = [];
    
    if ($filterStatus === 'inactive') {
        $whereConditions = ['o.is_active = 0'];
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
    
    if (!empty($filterPurok)) {
        $whereConditions[] = 'o.purok = ?';
        $params[] = $filterPurok;
    }
    
    if (!empty($filterKapisanan)) {
        $whereConditions[] = 'o.kapisanan = ?';
        $params[] = $filterKapisanan;
    }
    
    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    // Get officers with departments
    $stmt = $db->prepare("
        SELECT 
            o.*,
            d.district_name,
            lc.local_name,
            tc.registry_number_encrypted,
            MAX(t_out.transfer_type) as transfer_status,
            MAX(t_out.transfer_date) as status_date,
            MAX(r.removal_date) as removal_date,
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
        LEFT JOIN transfers t_out ON o.officer_id = t_out.officer_id AND t_out.transfer_type = 'out'
        LEFT JOIN officer_removals r ON o.officer_id = r.officer_id
        $whereClause
        GROUP BY o.officer_id
        ORDER BY 
            CASE WHEN o.control_number IS NULL OR o.control_number = '' THEN 1 ELSE 0 END,
            o.control_number, 
            o.officer_id
    ");
    
    $stmt->execute($params);
    $allOfficers = $stmt->fetchAll();
    
    // Decrypt names
    $rowNumber = 1;
    foreach ($allOfficers as $officer) {
        $decrypted = Encryption::decryptOfficerName(
            $officer['last_name_encrypted'],
            $officer['first_name_encrypted'],
            $officer['middle_initial_encrypted'],
            $officer['district_code']
        );
        
        // Decrypt registry number if available
        $registryNumber = null;
        if (!empty($officer['registry_number_encrypted'])) {
            try {
                $registryNumber = Encryption::decrypt($officer['registry_number_encrypted'], $officer['district_code']);
            } catch (Exception $e) {
                error_log("Failed to decrypt registry number for officer {$officer['officer_id']}: " . $e->getMessage());
            }
        }
        
        // Determine status
        $status = null;
        $statusDate = null;
        if (!$officer['is_active']) {
            if ($officer['removal_date']) {
                $status = 'removed';
                $statusDate = $officer['removal_date'];
            } elseif ($officer['transfer_status'] === 'out') {
                $status = 'transferred';
                $statusDate = $officer['status_date'];
            }
        }
        
        $officers[] = [
            'row_number' => $rowNumber++,
            'full_name' => trim($decrypted['last_name'] . ', ' . $decrypted['first_name'] . 
                              (!empty($decrypted['middle_initial']) ? ' ' . $decrypted['middle_initial'] . '.' : '')),
            'control_number' => $officer['control_number'],
            'registry_number' => $registryNumber,
            'oath_dates' => $officer['oath_dates'] ?? null,
            'officer_uuid' => $officer['officer_uuid'],
            'purok' => $officer['purok'],
            'grupo' => $officer['grupo'],
            'kapisanan' => $officer['kapisanan'],
            'departments' => $officer['departments'],
            'is_active' => $officer['is_active'],
            'status' => $status,
            'status_date' => $statusDate,
            'district_name' => $officer['district_name'],
            'local_name' => $officer['local_name'],
            'district_code' => $officer['district_code'],
            'local_code' => $officer['local_code']
        ];
    }
    
    // Get report info
    if (!empty($officers)) {
        $reportInfo = [
            'district_name' => $officers[0]['district_name'],
            'local_name' => $officers[0]['local_name'],
            'purok' => $filterPurok,
            'generated_date' => date('F d, Y'),
            'total_officers' => count($officers)
        ];
    }
    
} catch (Exception $e) {
    error_log("Load masterlist error: " . $e->getMessage());
    $error = 'Error loading masterlist data.';
}

$pageTitle = 'Masterlist Generator';
ob_start();
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 p-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900">Masterlist Generator</h1>
                <p class="text-sm text-gray-500 mt-1">Generate official masterlist of current officers</p>
            </div>
            <?php if (!empty($officers)): ?>
            <button onclick="openPrintView()" class="inline-flex items-center px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors shadow-sm">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>
                </svg>
                Print Masterlist
            </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
            <?php echo Security::escape($error); ?>
        </div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 p-5">
        <form method="GET" action="" class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-5 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">District</label>
                <select name="district" id="district" class="block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm" onchange="loadLocals(this.value)" <?php echo ($currentUser['role'] !== 'admin') ? 'disabled' : ''; ?>>
                    <option value="">All Districts</option>
                    <?php foreach ($districts as $district): ?>
                        <option value="<?php echo Security::escape($district['district_code']); ?>" <?php echo $filterDistrict === $district['district_code'] ? 'selected' : ''; ?>>
                            <?php echo Security::escape($district['district_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($currentUser['role'] !== 'admin'): ?>
                    <input type="hidden" name="district" value="<?php echo Security::escape($filterDistrict); ?>">
                <?php endif; ?>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Local</label>
                <select name="local" id="local" class="block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm" <?php echo ($currentUser['role'] === 'local') ? 'disabled' : ''; ?>>
                    <option value="">All Locals</option>
                    <?php foreach ($locals as $local): ?>
                        <option value="<?php echo Security::escape($local['local_code']); ?>" <?php echo $filterLocal === $local['local_code'] ? 'selected' : ''; ?>>
                            <?php echo Security::escape($local['local_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($currentUser['role'] === 'local'): ?>
                    <input type="hidden" name="local" value="<?php echo Security::escape($filterLocal); ?>">
                <?php endif; ?>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Purok</label>
                <input type="text" name="purok" value="<?php echo Security::escape($filterPurok); ?>" placeholder="Filter by Purok" class="block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Kapisanan</label>
                <select name="kapisanan" class="block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                    <option value="">All Kapisanan</option>
                    <option value="Buklod" <?php echo $filterKapisanan === 'Buklod' ? 'selected' : ''; ?>>Buklod</option>
                    <option value="Kadiwa" <?php echo $filterKapisanan === 'Kadiwa' ? 'selected' : ''; ?>>Kadiwa</option>
                    <option value="Binhi" <?php echo $filterKapisanan === 'Binhi' ? 'selected' : ''; ?>>Binhi</option>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">EXT/GWS</label>
                <input type="text" name="ext_gws" value="<?php echo Security::escape($filterExtGws); ?>" placeholder="Filter by EXT/GWS" class="block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                <select name="status" class="block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                    <option value="active" <?php echo $filterStatus === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo $filterStatus === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    <option value="all" <?php echo $filterStatus === 'all' ? 'selected' : ''; ?>>All</option>
                </select>
            </div>
            
            <div class="flex items-end md:col-span-3 lg:col-span-5 gap-2">
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors text-sm font-medium">
                    Generate Report
                </button>
                <a href="?" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors text-sm font-medium">
                    Clear Filters
                </a>
            </div>
        </form>
    </div>

    <!-- Signature Settings -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 p-5">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Footer Signatories</h3>
        <p class="text-sm text-gray-500 mb-4">Type to search from tarheta control. Leave blank to hide signature line.</p>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
            <div class="relative">
                <label class="block text-sm font-medium text-gray-700 mb-1">Naghanda - Kalihim ng Purok</label>
                <div class="relative">
                    <input type="text" id="sig1" value="<?php echo Security::escape($sig1); ?>" placeholder="Type to search..." class="block w-full px-3 py-2 pr-10 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm" autocomplete="off">
                    <div id="sig1-loading" class="hidden absolute right-3 top-1/2 transform -translate-y-1/2">
                        <svg class="animate-spin h-4 w-4 text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                    </div>
                </div>
                <div id="sig1-suggestions" class="hidden absolute z-10 w-full mt-1 bg-white dark:bg-gray-800 border border-gray-300 rounded-lg shadow-lg max-h-60 overflow-y-auto"></div>
            </div>
            <div class="relative">
                <label class="block text-sm font-medium text-gray-700 mb-1">Pangulong Kalihim</label>
                <div class="relative">
                    <input type="text" id="sig2" value="<?php echo Security::escape($sig2); ?>" placeholder="Type to search..." class="block w-full px-3 py-2 pr-10 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm" autocomplete="off">
                    <div id="sig2-loading" class="hidden absolute right-3 top-1/2 transform -translate-y-1/2">
                        <svg class="animate-spin h-4 w-4 text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                    </div>
                </div>
                <div id="sig2-suggestions" class="hidden absolute z-10 w-full mt-1 bg-white dark:bg-gray-800 border border-gray-300 rounded-lg shadow-lg max-h-60 overflow-y-auto"></div>
            </div>
            <div class="relative">
                <label class="block text-sm font-medium text-gray-700 mb-1">Pamunuang Tagasubaybay</label>
                <div class="relative">
                    <input type="text" id="sig3" value="<?php echo Security::escape($sig3); ?>" placeholder="Type to search..." class="block w-full px-3 py-2 pr-10 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm" autocomplete="off">
                    <div id="sig3-loading" class="hidden absolute right-3 top-1/2 transform -translate-y-1/2">
                        <svg class="animate-spin h-4 w-4 text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                    </div>
                </div>
                <div id="sig3-suggestions" class="hidden absolute z-10 w-full mt-1 bg-white dark:bg-gray-800 border border-gray-300 rounded-lg shadow-lg max-h-60 overflow-y-auto"></div>
            </div>
            <div class="relative">
                <label class="block text-sm font-medium text-gray-700 mb-1">Pangulong Diakono/KSP</label>
                <div class="relative">
                    <input type="text" id="sig4" value="<?php echo Security::escape($sig4); ?>" placeholder="Type to search..." class="block w-full px-3 py-2 pr-10 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm" autocomplete="off">
                    <div id="sig4-loading" class="hidden absolute right-3 top-1/2 transform -translate-y-1/2">
                        <svg class="animate-spin h-4 w-4 text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                    </div>
                </div>
                <div id="sig4-suggestions" class="hidden absolute z-10 w-full mt-1 bg-white dark:bg-gray-800 border border-gray-300 rounded-lg shadow-lg max-h-60 overflow-y-auto"></div>
            </div>
            <div class="relative">
                <label class="block text-sm font-medium text-gray-700 mb-1">Pastor/Destinado</label>
                <div class="relative">
                    <input type="text" id="sig5" value="<?php echo Security::escape($sig5); ?>" placeholder="Type to search..." class="block w-full px-3 py-2 pr-10 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm" autocomplete="off">
                    <div id="sig5-loading" class="hidden absolute right-3 top-1/2 transform -translate-y-1/2">
                        <svg class="animate-spin h-4 w-4 text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                    </div>
                </div>
                <div id="sig5-suggestions" class="hidden absolute z-10 w-full mt-1 bg-white dark:bg-gray-800 border border-gray-300 rounded-lg shadow-lg max-h-60 overflow-y-auto"></div>
            </div>
        </div>
        <div class="mt-4">
            <button onclick="saveSignatures()" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors text-sm font-medium">
                Save Signatories
            </button>
        </div>
    </div>

    <!-- Masterlist Preview -->
    <?php if (!empty($officers)): ?>
    <?php
    // Pagination: 20 entries per page
    $entriesPerPage = 20;
    $totalPages = ceil(count($officers) / $entriesPerPage);
    
    for ($page = 0; $page < $totalPages; $page++):
        $startIndex = $page * $entriesPerPage;
        $endIndex = min($startIndex + $entriesPerPage, count($officers));
        $pageOfficers = array_slice($officers, $startIndex, $entriesPerPage);
    ?>
    <div id="masterlist-content-page-<?php echo $page + 1; ?>" class="masterlist-page" style="width: 100%; max-width: 12in; margin: 0 auto; padding: 0.3in; background: white; text-align: center; <?php echo $page > 0 ? 'page-break-before: always;' : ''; ?>">
        <p style="padding-top: 4pt;text-indent: 0pt;text-align: center; color: black; font-family:Verdana, sans-serif; font-style: normal; font-weight: bold; text-decoration: none; font-size: 14pt; margin:0pt;">MASTERLIST NG KASALUKUYANG MGA MAYTUNGKULIN</p>
        <p style="text-indent: 0pt;text-align: left;"><br/></p>
        
        <!-- Header Table -->
        <table style="border-collapse:collapse;margin-left:auto;margin-right:auto;display:inline-table;" cellspacing="0">
            <tr style="height:15pt">
                <td style="width:95pt;border-top-style:solid;border-top-width:1pt;border-left-style:solid;border-left-width:1pt;border-bottom-style:solid;border-bottom-width:1pt;border-right-style:solid;border-right-width:1pt">
                    <p style="padding-top: 1pt;padding-left: 5pt;text-indent: 0pt;text-align: left; color: black; font-family:Verdana, sans-serif; font-weight: bold; font-size: 10pt; margin:0pt;">DISTRITO</p>
                </td>
                <td style="width:230pt;border-top-style:solid;border-top-width:1pt;border-left-style:solid;border-left-width:1pt;border-bottom-style:solid;border-bottom-width:1pt;border-right-style:solid;border-right-width:1pt">
                    <p style="padding-top: 1pt;padding-left: 5pt;text-indent: 0pt;text-align: left; font-family:Verdana, sans-serif; font-size: 10pt; margin:0pt;"><?php echo Security::escape($reportInfo['district_name']); ?></p>
                </td>
                <td style="width:107pt;border-top-style:solid;border-top-width:1pt;border-left-style:solid;border-left-width:1pt;border-bottom-style:solid;border-bottom-width:1pt;border-right-style:solid;border-right-width:1pt">
                    <p style="padding-top: 1pt;padding-left: 5pt;text-indent: 0pt;text-align: left; color: black; font-family:Verdana, sans-serif; font-weight: bold; font-size: 10pt; margin:0pt;">LOKAL</p>
                </td>
                <td style="width:203pt;border-top-style:solid;border-top-width:1pt;border-left-style:solid;border-left-width:1pt;border-bottom-style:solid;border-bottom-width:1pt;border-right-style:solid;border-right-width:1pt">
                    <p style="padding-top: 1pt;padding-left: 5pt;text-indent: 0pt;text-align: left; font-family:Verdana, sans-serif; font-size: 10pt; margin:0pt;"><?php echo Security::escape($reportInfo['local_name']); ?></p>
                </td>
                <td style="width:103pt;border-top-style:solid;border-top-width:1pt;border-left-style:solid;border-left-width:1pt;border-bottom-style:solid;border-bottom-width:1pt;border-right-style:solid;border-right-width:1pt" rowspan="2">
                    <p style="padding-top: 8pt;text-indent: 0pt;text-align: center; color: black; font-family:Verdana, sans-serif; font-size: 11pt; margin:0pt;">Petsa</p>
                </td>
                <td style="width:131pt;border-top-style:solid;border-top-width:1pt;border-left-style:solid;border-left-width:1pt;border-bottom-style:solid;border-bottom-width:1pt;border-right-style:solid;border-right-width:1pt" rowspan="2">
                    <p style="padding-top: 8pt;text-indent: 0pt;text-align: center; font-family:Verdana, sans-serif; font-size: 10pt; margin:0pt;"><?php echo date('m/d/Y'); ?></p>
                </td>
            </tr>
            <tr style="height:15pt">
                <td style="width:95pt;border-top-style:solid;border-top-width:1pt;border-left-style:solid;border-left-width:1pt;border-bottom-style:solid;border-bottom-width:1pt;border-right-style:solid;border-right-width:1pt">
                    <p style="padding-top: 1pt;padding-left: 5pt;text-indent: 0pt;text-align: left; color: black; font-family:Verdana, sans-serif; font-weight: bold; font-size: 10pt; margin:0pt;">EXT/GWS</p>
                </td>
                <td style="width:230pt;border-top-style:solid;border-top-width:1pt;border-left-style:solid;border-left-width:1pt;border-bottom-style:solid;border-bottom-width:1pt;border-right-style:solid;border-right-width:1pt">
                    <p style="text-indent: 0pt;text-align: left;"><br/></p>
                </td>
                <td style="width:107pt;border-top-style:solid;border-top-width:1pt;border-left-style:solid;border-left-width:1pt;border-bottom-style:solid;border-bottom-width:1pt;border-right-style:solid;border-right-width:1pt">
                    <p style="padding-top: 1pt;padding-left: 5pt;text-indent: 0pt;text-align: left; color: black; font-family:Verdana, sans-serif; font-weight: bold; font-size: 10pt; margin:0pt;">PUROK</p>
                </td>
                <td style="width:203pt;border-top-style:solid;border-top-width:1pt;border-left-style:solid;border-left-width:1pt;border-bottom-style:solid;border-bottom-width:1pt;border-right-style:solid;border-right-width:1pt">
                    <p style="padding-top: 1pt;padding-left: 5pt;text-indent: 0pt;text-align: left; font-family:Verdana, sans-serif; font-size: 10pt; margin:0pt;"><?php echo !empty($reportInfo['purok']) ? Security::escape($reportInfo['purok']) : ''; ?></p>
                </td>
            </tr>
        </table>
        
        <p style="text-indent: 0pt;text-align: left;"><br/></p>
        
        <!-- Data Table -->
        <table style="border-collapse:collapse;margin-left:auto;margin-right:auto;display:inline-table;" cellspacing="0">
            <thead>
                <tr style="height:17pt">
                    <td style="width:27pt;border:1pt solid black" rowspan="2">
                        <p style="padding-top: 9pt;padding-left: 6pt;text-indent: 0pt;text-align: left; color: black; font-family:Verdana, sans-serif; font-size: 9pt; margin:0pt;">Blg</p>
                    </td>
                    <td style="width:162pt;border:1pt solid black" rowspan="2">
                        <p style="padding-top: 8pt;padding-left: 49pt;text-indent: 0pt;text-align: left; color: black; font-family:Verdana, sans-serif; font-weight: bold; font-size: 10pt; margin:0pt;">PANGALAN</p>
                    </td>
                    <td style="width:54pt;border:1pt solid black" rowspan="2">
                        <p style="padding-top: 4pt;padding-left: 9pt;padding-right: 6pt;text-indent: -2pt;text-align: left; color: black; font-family:Verdana, sans-serif; font-size: 8pt; margin:0pt;">CONTROL NUMBER</p>
                    </td>
                    <td style="width:95pt;border:1pt solid black" rowspan="2">
                        <p style="padding-top: 9pt;padding-left: 7pt;text-indent: 0pt;text-align: left; color: black; font-family:Verdana, sans-serif; font-size: 8pt; margin:0pt;">REGISTRY NUMBER</p>
                    </td>
                    <td style="width:49pt;border:1pt solid black" rowspan="2">
                        <p style="padding-top: 9pt;padding-left: 3pt;text-indent: 0pt;text-align: left; color: black; font-family:Verdana, sans-serif; font-size: 8pt; margin:0pt;">Kapisanan</p>
                    </td>
                    <td style="width:45pt;border:1pt solid black" rowspan="2">
                        <p style="padding-top: 4pt;padding-left: 10pt;padding-right: 9pt;text-indent: 0pt;text-align: left; color: black; font-family:Verdana, sans-serif; font-size: 8pt; margin:0pt;">Purok Grupo</p>
                    </td>
                    <td style="width:203pt;border:1pt solid black" rowspan="2">
                        <p style="padding-top: 3pt;padding-left: 20pt;padding-right: 19pt;text-indent: 9pt;text-align: left; color: black; font-family:Verdana, sans-serif; font-weight: bold; font-size: 9pt; margin:0pt;">KASALUKUYANG HAWAK NA TUNGKULIN O MGA TUNGKULIN</p>
                    </td>
                    <td style="width:103pt;border:1pt solid black" rowspan="2">
                        <p style="padding-top: 9pt;padding-left: 8pt;text-indent: 0pt;text-align: left; color: black; font-family:Verdana, sans-serif; font-size: 9pt; margin:0pt;">Petsa ng manumpa</p>
                    </td>
                    <td style="width:131pt;border:1pt solid black" colspan="2">
                        <p style="padding-top: 1pt;padding-left: 42pt;text-indent: 0pt;text-align: left; color: black; font-family:Verdana, sans-serif; font-weight: bold; font-size: 10pt; margin:0pt;">PANSIN</p>
                    </td>
                </tr>
                <tr style="height:13pt">
                    <td style="width:65pt;border:1pt solid black">
                        <p style="padding-top: 1pt;padding-left: 20pt;text-indent: 0pt;text-align: left; color: black; font-family:Verdana, sans-serif; font-size: 8pt; margin:0pt;">Active</p>
                    </td>
                    <td style="width:66pt;border:1pt solid black">
                        <p style="padding-top: 1pt;padding-left: 16pt;text-indent: 0pt;text-align: left; color: black; font-family:Verdana, sans-serif; font-size: 8pt; margin:0pt;">Inactive</p>
                    </td>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pageOfficers as $officer): 
                    $highlightStyle = '';
                    $statusText = '';
                    if ($officer['status'] === 'transferred') {
                        $highlightStyle = 'background-color: #FEF3C7;'; // Light yellow
                        $statusText = 'TRANSFERRED';
                    } elseif ($officer['status'] === 'removed') {
                        $highlightStyle = 'background-color: #FEE2E2;'; // Light red
                        $statusText = 'REMOVED';
                    }
                ?>
                <tr style="height:14pt;<?php echo $highlightStyle; ?>">
                    <td style="width:27pt;border:1pt solid black">
                        <p style="text-align: center; font-family:Verdana, sans-serif; font-size: 9pt; margin:0pt;"><?php echo $officer['row_number']; ?></p>
                    </td>
                    <td style="width:162pt;border:1pt solid black">
                        <p style="padding-left: 2pt;text-align: left; font-family:Verdana, sans-serif; font-size: 9pt; margin:0pt;">
                            <?php echo Security::escape($officer['full_name']); ?>
                            <?php if ($statusText): ?>
                                <span style="color: red; font-weight: bold; font-size: 7pt;"> [<?php echo $statusText; ?>]</span>
                            <?php endif; ?>
                        </p>
                    </td>
                    <td style="width:54pt;border:1pt solid black">
                        <p style="text-align: center; font-family:Verdana, sans-serif; font-size: 8pt; margin:0pt;"><?php echo Security::escape($officer['control_number'] ?? ''); ?></p>
                    </td>
                    <td style="width:95pt;border:1pt solid black">
                        <p style="text-align: center; font-family:Verdana, sans-serif; font-size: 7pt; margin:0pt;"><?php echo Security::escape($officer['registry_number'] ?? ''); ?></p>
                    </td>
                    <td style="width:49pt;border:1pt solid black">
                        <p style="text-align: center; font-family:Verdana, sans-serif; font-size: 8pt; margin:0pt;"><?php echo Security::escape($officer['kapisanan'] ?? ''); ?></p>
                    </td>
                    <td style="width:45pt;border:1pt solid black">
                        <p style="text-align: center; font-family:Verdana, sans-serif; font-size: 8pt; margin:0pt;">
                            <?php if (!empty($officer['purok']) || !empty($officer['grupo'])): ?>
                                <?php echo Security::escape($officer['purok'] ?? ''); ?><?php echo (!empty($officer['purok']) && !empty($officer['grupo'])) ? '/' : ''; ?><?php echo Security::escape($officer['grupo'] ?? ''); ?>
                            <?php endif; ?>
                        </p>
                    </td>
                    <td style="width:203pt;border:1pt solid black">
                        <p style="padding-left: 2pt;text-align: left; font-family:Verdana, sans-serif; font-size: 8pt; margin:0pt;"><?php echo Security::escape($officer['departments'] ?? ''); ?></p>
                    </td>
                    <td style="width:103pt;border:1pt solid black">
                        <p style="text-align: center; font-family:Verdana, sans-serif; font-size: 7pt; margin:0pt;"><?php echo Security::escape($officer['oath_dates'] ?? ''); ?></p>
                    </td>
                    <td style="width:65pt;border:1pt solid black">
                        <p style="text-align: center; font-family:Verdana, sans-serif; font-size: 12pt; margin:0pt;"><?php echo $officer['is_active'] ? '✓' : ''; ?></p>
                    </td>
                    <td style="width:66pt;border:1pt solid black">
                        <p style="text-align: center; font-family:Verdana, sans-serif; font-size: 12pt; margin:0pt;"><?php echo !$officer['is_active'] ? '✓' : ''; ?></p>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <p style="text-indent: 0pt;text-align: left;"><br/></p>
        
        <!-- Footer Table -->
        <table style="border-collapse:collapse;margin-left:auto;margin-right:auto;display:inline-table;" cellspacing="0">
            <tr style="height:27pt">
                <td style="width:174pt;border:1pt solid black">
                    <p style="padding-top: 8pt;text-align: center; color: black; font-family:Verdana, sans-serif; font-weight: bold; font-size: 9pt; margin:0pt;"><?php echo Security::escape($sig1); ?></p>
                </td>
                <td style="width:173pt;border:1pt solid black">
                    <p style="padding-top: 8pt;text-align: center; color: black; font-family:Verdana, sans-serif; font-weight: bold; font-size: 9pt; margin:0pt;"><?php echo Security::escape($sig2); ?></p>
                </td>
                <td style="width:174pt;border:1pt solid black">
                    <p style="padding-top: 8pt;text-align: center; color: black; font-family:Verdana, sans-serif; font-weight: bold; font-size: 9pt; margin:0pt;"><?php echo Security::escape($sig3); ?></p>
                </td>
                <td style="width:174pt;border:1pt solid black">
                    <p style="padding-top: 8pt;text-align: center; color: black; font-family:Verdana, sans-serif; font-weight: bold; font-size: 9pt; margin:0pt;"><?php echo Security::escape($sig4); ?></p>
                </td>
                <td style="width:174pt;border:1pt solid black">
                    <p style="padding-top: 8pt;text-align: center; color: black; font-family:Verdana, sans-serif; font-weight: bold; font-size: 9pt; margin:0pt;"><?php echo Security::escape($sig5); ?></p>
                </td>
            </tr>
            <tr style="height:22pt">
                <td style="width:174pt;border:1pt solid black">
                    <p style="padding-top: 5pt;text-align: center; color: black; font-family:Verdana, sans-serif; font-size: 8pt; margin:0pt;">Naghanda - Kalihim ng Purok</p>
                </td>
                <td style="width:173pt;border:1pt solid black">
                    <p style="padding-top: 5pt;text-align: center; color: black; font-family:Verdana, sans-serif; font-size: 8pt; margin:0pt;">Pangulong Kalihim</p>
                </td>
                <td style="width:174pt;border:1pt solid black">
                    <p style="padding-top: 5pt;text-align: center; color: black; font-family:Verdana, sans-serif; font-size: 8pt; margin:0pt;">Pamunuang Tagasubaybay sa lahat ng mga Maytungkulin</p>
                </td>
                <td style="width:174pt;border:1pt solid black">
                    <p style="padding-top: 5pt;text-align: center; color: black; font-family:Verdana, sans-serif; font-size: 8pt; margin:0pt;">Pangulong Diakono/KSP</p>
                </td>
                <td style="width:174pt;border:1pt solid black">
                    <p style="padding-top: 5pt;text-align: center; color: black; font-family:Verdana, sans-serif; font-size: 8pt; margin:0pt;">Pastor/Destinado</p>
                </td>
            </tr>
        </table>
        
        <!-- Page indicator -->
        <p style="text-align: right; padding-top: 10pt; font-family:Verdana, sans-serif; font-size: 8pt; color: #666; margin:0pt;">Page <?php echo $page + 1; ?> of <?php echo $totalPages; ?></p>
    </div>
    <?php endfor; ?>
    <?php else: ?>
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 p-12 text-center">
        <svg class="w-16 h-16 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
        </svg>
        <p class="text-gray-500 mb-4">Select filters and click "Generate Report" to create masterlist</p>
    </div>
    <?php endif; ?>
</div>

<style>
@media print {
    /* Hide navigation, header, filters, and all page UI */
    nav,
    header,
    aside,
    [role="navigation"],
    .sidebar,
    .navbar,
    button,
    form,
    .space-y-6 > .bg-white.rounded-lg.shadow-sm.border.border-gray-200 {
        display: none !important;
    }
    
    /* Show masterlist pages */
    .masterlist-page {
        display: block !important;
        margin: 0 !important;
        padding: 0.3in !important;
        text-align: center !important;
        page-break-after: always;
        page-break-before: auto;
        background: white !important;
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
    }
    
    /* First page should not have page break before */
    .masterlist-page:first-of-type {
        page-break-before: avoid;
    }
    
    /* Last page should not force a page break after */
    .masterlist-page:last-of-type {
        page-break-after: auto;
    }
    
    /* Make sure all content inside masterlist pages is visible */
    .masterlist-page * {
        display: revert !important;
    }
    
    /* Make sure tables are visible and properly formatted */
    .masterlist-page table {
        display: inline-table !important;
        border-collapse: collapse !important;
    }
    
    /* Prevent page breaks inside tables */
    table, tr, td, th, thead, tbody {
        page-break-inside: avoid;
    }
    
    /* Adjust page for printing */
    body {
        margin: 0 !important;
        padding: 0 !important;
        background: white !important;
    }
    
    /* Long bond paper: 13in x 8.5in landscape */
    @page {
        size: 13in 8.5in;
        margin: 0.3in;
    }
}
</style>

<script>
// Open print view in new tab
function openPrintView() {
    // Get all masterlist pages
    const pages = document.querySelectorAll('.masterlist-page');
    if (pages.length === 0) {
        alert('No pages to print');
        return;
    }
    
    // Create new window
    const printWindow = window.open('', '_blank');
    
    // Build HTML for print window
    let html = `
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Masterlist</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            margin: 0;
            padding: 0;
            background: white;
            font-family: Verdana, sans-serif;
        }
        
        .masterlist-page {
            width: 100%;
            max-width: 100%;
            margin: 0;
            padding: 0.3in;
            text-align: center;
            background: white;
            page-break-after: always;
            box-sizing: border-box;
        }
        
        .masterlist-page:last-of-type {
            page-break-after: auto;
        }
        
        table {
            border-collapse: collapse;
            margin-left: auto;
            margin-right: auto;
            display: inline-table;
        }
        
        table, tr, td, th, thead, tbody {
            page-break-inside: avoid;
        }
        
        @page {
            size: 13in 8.5in;
            margin: 0.3in;
        }
        
        @media print {
            body {
                margin: 0;
                padding: 0;
            }
            
            .masterlist-page {
                page-break-after: always;
            }
            
            .masterlist-page:last-of-type {
                page-break-after: auto;
            }
        }
    </style>
</head>
<body>
`;
    
    // Add each page content
    pages.forEach(page => {
        html += page.outerHTML;
    });
    
    html += `
    <script>
        // Auto-print when page loads
        window.onload = function() {
            window.print();
        };
    </scr` + `ipt>
</body>
</html>`;
    
    // Write HTML to new window
    printWindow.document.open();
    printWindow.document.write(html);
    printWindow.document.close();
}

// Save signature labels to cookies
function saveSignatures() {
    const sig1 = document.getElementById('sig1').value;
    const sig2 = document.getElementById('sig2').value;
    const sig3 = document.getElementById('sig3').value;
    const sig4 = document.getElementById('sig4').value;
    const sig5 = document.getElementById('sig5').value;
    
    // Set cookies for 1 year
    const expires = new Date();
    expires.setFullYear(expires.getFullYear() + 1);
    const expiresStr = expires.toUTCString();
    
    document.cookie = `masterlist_sig1=${encodeURIComponent(sig1)}; expires=${expiresStr}; path=/`;
    document.cookie = `masterlist_sig2=${encodeURIComponent(sig2)}; expires=${expiresStr}; path=/`;
    document.cookie = `masterlist_sig3=${encodeURIComponent(sig3)}; expires=${expiresStr}; path=/`;
    document.cookie = `masterlist_sig4=${encodeURIComponent(sig4)}; expires=${expiresStr}; path=/`;
    document.cookie = `masterlist_sig5=${encodeURIComponent(sig5)}; expires=${expiresStr}; path=/`;
    
    alert('Signatures saved successfully!');
    
    // Reload page to apply changes
    setTimeout(() => {
        window.location.reload();
    }, 500);
}

// Load locals based on district
async function loadLocals(districtCode) {
    if (!districtCode) {
        document.getElementById('local').innerHTML = '<option value="">All Locals</option>';
        return;
    }
    
    try {
        const response = await fetch('<?php echo BASE_URL; ?>/api/get-locals.php?district=' + districtCode);
        const data = await response.json();
        
        let html = '<option value="">All Locals</option>';
        data.locals.forEach(local => {
            html += `<option value="${local.local_code}">${local.local_name}</option>`;
        });
        
        document.getElementById('local').innerHTML = html;
    } catch (error) {
        console.error('Error loading locals:', error);
    }
}

// Autocomplete for signatory fields using tarheta search
let searchTimeout = null;
let currentFocusedField = null;

function setupSignatoryAutocomplete(fieldId) {
    const input = document.getElementById(fieldId);
    const suggestions = document.getElementById(fieldId + '-suggestions');
    const loading = document.getElementById(fieldId + '-loading');
    
    input.addEventListener('input', function() {
        const query = this.value.trim();
        
        clearTimeout(searchTimeout);
        
        if (query.length < 2) {
            suggestions.classList.add('hidden');
            loading.classList.add('hidden');
            return;
        }
        
        // Show loading indicator
        loading.classList.remove('hidden');
        
        searchTimeout = setTimeout(async () => {
            try {
                const response = await fetch('<?php echo BASE_URL; ?>/api/search-tarheta.php?search=' + encodeURIComponent(query));
                const data = await response.json();
                
                // Hide loading indicator
                loading.classList.add('hidden');
                
                if (data.success && data.records && data.records.length > 0) {
                    let html = '';
                    data.records.forEach(record => {
                        const displayName = record.full_name.toUpperCase();
                        html += `<div class="px-4 py-2 hover:bg-blue-50 cursor-pointer border-b border-gray-100" onclick="selectSignatory('${fieldId}', '${displayName.replace(/'/g, "\\'")}')">${displayName}</div>`;
                    });
                    suggestions.innerHTML = html;
                    suggestions.classList.remove('hidden');
                } else {
                    suggestions.innerHTML = '<div class="px-4 py-2 text-sm text-gray-500">No results found</div>';
                    suggestions.classList.remove('hidden');
                }
            } catch (error) {
                console.error('Error searching tarheta:', error);
                loading.classList.add('hidden');
                suggestions.innerHTML = '<div class="px-4 py-2 text-sm text-red-500">Error searching</div>';
                suggestions.classList.remove('hidden');
            }
        }, 300);
    });
    
    input.addEventListener('focus', function() {
        currentFocusedField = fieldId;
    });
    
    input.addEventListener('blur', function() {
        setTimeout(() => {
            suggestions.classList.add('hidden');
            loading.classList.add('hidden');
        }, 200);
    });
}

function selectSignatory(fieldId, name) {
    document.getElementById(fieldId).value = name;
    document.getElementById(fieldId + '-suggestions').classList.add('hidden');
}

// Initialize autocomplete for all signatory fields
document.addEventListener('DOMContentLoaded', function() {
    setupSignatoryAutocomplete('sig1');
    setupSignatoryAutocomplete('sig2');
    setupSignatoryAutocomplete('sig3');
    setupSignatoryAutocomplete('sig4');
    setupSignatoryAutocomplete('sig5');
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
