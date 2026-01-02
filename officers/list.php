<?php
require_once __DIR__ . '/../config/config.php';

Security::requireLogin();
requirePermission('can_view_officers');

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

// Get filter parameters
$searchQuery = Security::sanitizeInput($_GET['search'] ?? '');
$filterDepartment = Security::sanitizeInput($_GET['department'] ?? '');
$filterStatus = Security::sanitizeInput($_GET['status'] ?? 'active');
$currentPage = max(1, intval($_GET['page'] ?? 1));

// Build WHERE clause based on user role
$whereConditions = [];
$params = [];

if ($currentUser['role'] === 'local') {
    $whereConditions[] = 'o.local_code = ?';
    $params[] = $currentUser['local_code'];
} elseif ($currentUser['role'] === 'district') {
    $whereConditions[] = 'o.district_code = ?';
    $params[] = $currentUser['district_code'];
}

// Status filter
if ($filterStatus === 'active') {
    $whereConditions[] = 'o.is_active = 1';
} elseif ($filterStatus === 'inactive') {
    $whereConditions[] = 'o.is_active = 0';
}

// Department filter
if (!empty($filterDepartment)) {
    $whereConditions[] = 'EXISTS (SELECT 1 FROM officer_departments od2 WHERE od2.officer_id = o.officer_id AND od2.department = ? AND od2.is_active = 1)';
    $params[] = $filterDepartment;
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Get all officers for search filtering (without pagination first)
try {
    // Get officers
    $stmt = $db->prepare("
        SELECT 
            o.*,
            d.district_name,
            lc.local_name,
            GROUP_CONCAT(DISTINCT od.department SEPARATOR ', ') as departments,
            COUNT(DISTINCT od.department) as dept_count,
            o.r518_data_verify,
            MAX(r.removal_code) as latest_removal_code,
            MAX(r.reason) as latest_removal_reason,
            MAX(t.transfer_type) as latest_transfer_type,
            MAX(t.transfer_date) as latest_transfer_date
        FROM officers o
        LEFT JOIN districts d ON o.district_code = d.district_code
        LEFT JOIN local_congregations lc ON o.local_code = lc.local_code
        LEFT JOIN officer_departments od ON o.officer_id = od.officer_id AND od.is_active = 1
        LEFT JOIN officer_removals r ON r.officer_id = o.officer_id
        LEFT JOIN transfers t ON t.officer_id = o.officer_id
        $whereClause
        GROUP BY o.officer_id
        ORDER BY o.created_at DESC
    ");
    
    $stmt->execute($params);
    $allOfficers = $stmt->fetchAll();
    
    // Filter by search query (decrypt names for searching)
    $officers = [];
    if (!empty($searchQuery)) {
        foreach ($allOfficers as $officer) {
            $decrypted = Encryption::decryptOfficerName(
                $officer['last_name_encrypted'],
                $officer['first_name_encrypted'],
                $officer['middle_initial_encrypted'],
                $officer['district_code']
            );
            
            $fullName = trim($decrypted['first_name'] . ' ' . 
                            ($decrypted['middle_initial'] ? $decrypted['middle_initial'] . '. ' : '') . 
                            $decrypted['last_name']);
            
            // Case-insensitive search in full name, local name, and district name
            if (stripos($fullName, $searchQuery) !== false ||
                stripos($officer['local_name'], $searchQuery) !== false ||
                stripos($officer['district_name'], $searchQuery) !== false) {
                $officers[] = $officer;
            }
        }
    } else {
        $officers = $allOfficers;
    }
    
    // Now apply pagination to filtered results
    $totalRecords = count($officers);
    $pagination = paginate($totalRecords, $currentPage);
    
    // Apply pagination
    $offset = $pagination['offset'];
    $limit = $pagination['per_page'];
    $officers = array_slice($officers, $offset, $limit);
    
} catch (Exception $e) {
    error_log("List officers error: " . $e->getMessage());
    $officers = [];
}

$pageTitle = 'Officers List';

// Page actions to be rendered in the top navbar (desktop) and as compact icons on mobile
$pageActions = [];
if (hasPermission('can_edit_officers')) {
    $pageActions[] = '<a href="' . BASE_URL . '/officers/add.php" class="inline-flex items-center justify-center px-3 sm:px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors shadow-sm text-xs sm:text-sm"><svg class="w-4 h-4 sm:mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg><span class="hidden sm:inline">Add Officer</span></a>';
}
if (hasPermission('can_view_officers')) {
    $pageActions[] = '<a href="' . BASE_URL . '/officers/bulk-update.php" class="inline-flex items-center justify-center px-3 sm:px-4 py-2 bg-purple-500 text-white rounded-lg hover:bg-purple-600 transition-colors shadow-sm text-xs sm:text-sm"><svg class="w-4 h-4 sm:mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg><span class="hidden sm:inline">Bulk Update</span></a>';
}

ob_start();
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-4 sm:p-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 sm:gap-4">
            <div>
                <h1 class="text-xl sm:text-2xl font-semibold text-gray-900 dark:text-gray-100">Officers Registry</h1>
                <p class="text-xs sm:text-sm text-gray-500 mt-1">Total: <?php echo number_format($totalRecords); ?> officers</p>
            </div>
            <!-- Actions moved to navbar for desktop; mobile shows compact icons in header -->
        </div>
    </div>
    
    <!-- Filters -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-4 sm:p-5">
        <form method="GET" action="" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
            <div>
                <label class="block text-xs sm:text-sm font-medium text-gray-700 mb-2">Search</label>
                <input 
                    type="text" 
                    name="search" 
                    placeholder="Search officers..." 
                    class="block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                    value="<?php echo Security::escape($searchQuery); ?>"
                >
            </div>
            
            <div>
                <label class="block text-xs sm:text-sm font-medium text-gray-700 mb-2">Department</label>
                <div class="relative">
                    <input 
                        type="text" 
                        id="department-display"
                        class="block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm bg-white dark:bg-gray-800 cursor-pointer"
                        placeholder="All Departments"
                        readonly
                        onclick="openDepartmentModal()"
                        value="<?php echo $filterDepartment ? Security::escape($filterDepartment) : 'All Departments'; ?>"
                    >
                    <input type="hidden" name="department" id="department-value" value="<?php echo Security::escape($filterDepartment); ?>">
                    <div class="absolute inset-y-0 right-0 flex items-center px-3 pointer-events-none">
                        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                </div>
            </div>
            
            <div>
                <label class="block text-xs sm:text-sm font-medium text-gray-700 mb-2">Status</label>
                <select name="status" class="block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm bg-white dark:bg-gray-800">
                    <option value="all" <?php echo $filterStatus === 'all' ? 'selected' : ''; ?>>All Status</option>
                    <option value="active" <?php echo $filterStatus === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo $filterStatus === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>
            
            <div>
                <label class="block text-xs sm:text-sm font-medium text-gray-700 mb-2">&nbsp;</label>
                <button type="submit" class="w-full inline-flex items-center justify-center px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors shadow-sm text-sm">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                    Filter
                </button>
            </div>
        </form>
    </div>
    
    <!-- Officers Table - Desktop -->
    <div class="hidden md:block bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Officer Name</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Location</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Purok / Grupo / Control #</th>
                        <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Data Verified</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200">
                    <?php if (empty($officers)): ?>
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center">
                                <svg class="w-12 h-12 text-gray-300 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                                </svg>
                                <p class="text-sm text-gray-500">No officers found</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($officers as $officer): ?>
                            <?php
                            // Decrypt officer name
                            $fullName = Encryption::getFullName(
                                $officer['last_name_encrypted'],
                                $officer['first_name_encrypted'],
                                $officer['middle_initial_encrypted'],
                                $officer['district_code']
                            );
                            ?>
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="flex-shrink-0 h-10 w-10">
                                            <div class="h-10 w-10 rounded-full bg-blue-100 flex items-center justify-center">
                                                <span class="text-sm font-medium text-blue-600"><?php echo strtoupper(substr($fullName, 0, 1)); ?></span>
                                            </div>
                                        </div>
                                        <div class="ml-4">
                                            <div class="text-sm font-medium text-gray-900 dark:text-gray-100 cursor-pointer" 
                                                 title="<?php echo Security::escape($fullName); ?>"
                                                 ondblclick="this.textContent='<?php echo Security::escape($fullName); ?>'">
                                                <?php echo Security::escape(obfuscateName($fullName)); ?>
                                            </div>
                                            <div class="text-xs text-gray-500">ID: <?php echo Security::escape(substr($officer['officer_uuid'], 0, 8)); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900 dark:text-gray-100"><?php echo Security::escape($officer['local_name']); ?></div>
                                    <div class="text-xs text-gray-500"><?php echo Security::escape($officer['district_name']); ?></div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="text-sm space-y-1">
                                        <?php if (!empty($officer['purok'])): ?>
                                            <div class="flex items-center text-gray-700">
                                                <svg class="w-3 h-3 mr-1.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                </svg>
                                                <span class="text-xs">Purok: <span class="font-medium"><?php echo Security::escape($officer['purok']); ?></span></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($officer['grupo'])): ?>
                                            <div class="flex items-center text-gray-700">
                                                <svg class="w-3 h-3 mr-1.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                                                </svg>
                                                <span class="text-xs">Grupo: <span class="font-medium"><?php echo Security::escape($officer['grupo']); ?></span></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($officer['control_number'])): ?>
                                            <div class="flex items-center text-gray-700">
                                                <svg class="w-3 h-3 mr-1.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 20l4-16m2 16l4-16M6 9h14M4 15h14"></path>
                                                </svg>
                                                <span class="text-xs">Control: <span class="font-medium"><?php echo Security::escape($officer['control_number']); ?></span></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (empty($officer['purok']) && empty($officer['grupo']) && empty($officer['control_number'])): ?>
                                            <span class="text-xs text-gray-400 italic">Not set</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <i class="<?php echo $officer['r518_data_verify'] == 1 ? 'fa-solid' : 'fa-regular'; ?> fa-thumbs-up text-2xl <?php echo $officer['r518_data_verify'] == 1 ? 'text-green-600' : 'text-gray-400'; ?>" title="<?php echo $officer['r518_data_verify'] == 1 ? 'Data Verified' : 'Data Not Verified'; ?>"></i>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap" style="display:none;">
                                    <div class="space-y-1">
                                        <?php if ($officer['dept_count'] > 0): ?>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                <?php echo $officer['dept_count']; ?> dept(s)
                                            </span>
                                        <?php else: ?>
                                            <span class="text-xs text-gray-400">None</span>
                                        <?php endif; ?>
                                        
                                        <div class="flex flex-wrap gap-1 mt-1">
                                            <?php if (!empty($officer['registry_number_encrypted'])): ?>
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-emerald-100 text-emerald-800" title="Registry number from Tarheta Control is linked">
                                                    <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                        <path fill-rule="evenodd" d="M6 6V5a3 3 0 013-3h2a3 3 0 013 3v1h2a2 2 0 012 2v3.57A22.952 22.952 0 0110 13a22.95 22.95 0 01-8-1.43V8a2 2 0 012-2h2zm2-1a1 1 0 011-1h2a1 1 0 011 1v1H8V5zm1 5a1 1 0 011-1h.01a1 1 0 110 2H10a1 1 0 01-1-1z" clip-rule="evenodd"></path>
                                                        <path d="M2 13.692V16a2 2 0 002 2h12a2 2 0 002-2v-2.308A24.974 24.974 0 0110 15c-2.796 0-5.487-.46-8-1.308z"></path>
                                                    </svg>
                                                    Registry #
                                                </span>
                                            <?php endif; ?>
                                            
                                            <?php if ($officer['is_imported']): ?>
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-800" title="Imported from LORCAPP">
                                                    <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                        <path d="M3 12v3c0 1.657 3.134 3 7 3s7-1.343 7-3v-3c0 1.657-3.134 3-7 3s-7-1.343-7-3z"/>
                                                        <path d="M3 7v3c0 1.657 3.134 3 7 3s7-1.343 7-3V7c0 1.657-3.134 3-7 3S3 8.657 3 7z"/>
                                                        <path d="M17 5c0 1.657-3.134 3-7 3S3 6.657 3 5s3.134-3 7-3 7 1.343 7 3z"/>
                                                    </svg>
                                                    LORCAPP
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php 
                                    if ($officer['is_active']) {
                                        echo '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">Active</span>';
                                    } else {
                                        // Smart logic to determine inactive type
                                        if ($officer['latest_transfer_type'] === 'out' && !empty($officer['latest_transfer_date'])) {
                                            echo '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">TRANSFERRED-OUT</span>';
                                        } elseif ($officer['latest_removal_code'] === 'C') {
                                            echo '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">SUSPENDIDO (CODE-C)</span>';
                                        } elseif ($officer['latest_removal_code'] === 'D') {
                                            echo '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-orange-100 text-orange-800">LIPAT-KAPISANAN (CODE-D)</span>';
                                        } elseif (!empty($officer['latest_removal_reason']) && stripos($officer['latest_removal_reason'], 'transfer') !== false) {
                                            echo '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">TRANSFERRED-OUT</span>';
                                        } else {
                                            echo '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">Inactive</span>';
                                        }
                                    }
                                    ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <div class="flex items-center space-x-2">
                                        <a href="<?php echo BASE_URL; ?>/officers/view.php?id=<?php echo urlencode($officer['officer_uuid']); ?>" 
                                           class="text-blue-600 hover:text-blue-900" 
                                           title="View">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                            </svg>
                                        </a>
                                        <?php if (hasPermission('can_edit_officers')): ?>
                                        <a href="<?php echo BASE_URL; ?>/officers/edit.php?id=<?php echo urlencode($officer['officer_uuid']); ?>" 
                                           class="text-gray-600 hover:text-gray-900 dark:text-gray-100" 
                                           title="Edit"
                                           target="_blank"
                                           rel="noopener noreferrer">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                            </svg>
                                        </a>
                                        <?php endif; ?>
                                        <?php if (hasPermission('can_delete_officers')): ?>
                                        <button 
                                           onclick="confirmDelete('<?php echo Security::escape($officer['officer_uuid']); ?>', '<?php echo Security::escape(obfuscateName($fullName)); ?>')" 
                                           class="text-red-600 hover:text-red-900" 
                                           title="Delete">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                            </svg>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        
        <!-- Pagination -->
        <?php if ($pagination['total_pages'] > 1): ?>
            <div class="bg-gray-50 px-6 py-3 border-t border-gray-200 dark:border-gray-700">
                <div class="flex items-center justify-center">
                    <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                        <?php if ($pagination['has_prev']): ?>
                            <a href="?page=<?php echo $pagination['current_page'] - 1; ?><?php echo $searchQuery ? '&search=' . urlencode($searchQuery) : ''; ?><?php echo $filterDepartment ? '&department=' . urlencode($filterDepartment) : ''; ?><?php echo $filterStatus ? '&status=' . urlencode($filterStatus) : ''; ?>" 
                               class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white dark:bg-gray-800 text-sm font-medium text-gray-500 hover:bg-gray-50">
                                <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                </svg>
                            </a>
                        <?php endif; ?>
                        
                        <?php for ($i = 1; $i <= $pagination['total_pages']; $i++): ?>
                            <a href="?page=<?php echo $i; ?><?php echo $searchQuery ? '&search=' . urlencode($searchQuery) : ''; ?><?php echo $filterDepartment ? '&department=' . urlencode($filterDepartment) : ''; ?><?php echo $filterStatus ? '&status=' . urlencode($filterStatus) : ''; ?>" 
                               class="relative inline-flex items-center px-4 py-2 border border-gray-300 <?php echo $i === $pagination['current_page'] ? 'bg-blue-500 text-white' : 'bg-white dark:bg-gray-800 text-gray-700 hover:bg-gray-50'; ?> text-sm font-medium">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($pagination['has_next']): ?>
                            <a href="?page=<?php echo $pagination['current_page'] + 1; ?><?php echo $searchQuery ? '&search=' . urlencode($searchQuery) : ''; ?><?php echo $filterDepartment ? '&department=' . urlencode($filterDepartment) : ''; ?><?php echo $filterStatus ? '&status=' . urlencode($filterStatus) : ''; ?>" 
                               class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white dark:bg-gray-800 text-sm font-medium text-gray-500 hover:bg-gray-50">
                                <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"></path>
                                </svg>
                            </a>
                        <?php endif; ?>
                    </nav>
                </div>
            </div>
        <?php endif; ?>
        </div>
    </div>
    
    <!-- Officers Cards - Mobile -->
    <div class="md:hidden space-y-3">
        <?php if (empty($officers)): ?>
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-8 text-center">
                <svg class="w-12 h-12 text-gray-300 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                </svg>
                <p class="text-sm text-gray-500">No officers found</p>
            </div>
        <?php else: ?>
            <?php foreach ($officers as $officer): ?>
                <?php
                // Decrypt officer name
                $fullName = Encryption::getFullName(
                    $officer['last_name_encrypted'],
                    $officer['first_name_encrypted'],
                    $officer['middle_initial_encrypted'],
                    $officer['district_code']
                );
                ?>
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-4">
                    <!-- Header with Avatar and Name -->
                    <div class="flex items-start gap-3 mb-3 pb-3 border-b border-gray-100">
                        <div class="flex-shrink-0">
                            <div class="w-12 h-12 rounded-full bg-blue-100 flex items-center justify-center">
                                <span class="text-base font-medium text-blue-600"><?php echo strtoupper(substr($fullName, 0, 1)); ?></span>
                            </div>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-medium text-gray-900 dark:text-gray-100 cursor-pointer mb-1" 
                                 title="<?php echo Security::escape($fullName); ?>"
                                 ondblclick="this.textContent='<?php echo Security::escape($fullName); ?>'">
                                <?php echo Security::escape(obfuscateName($fullName)); ?>
                            </div>
                            <div class="text-xs text-gray-500">ID: <?php echo Security::escape(substr($officer['officer_uuid'], 0, 8)); ?></div>
                            <div class="mt-1">
                                <?php 
                                if ($officer['is_active']) {
                                    echo '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">Active</span>';
                                } else {
                                    // Smart logic to determine inactive type
                                    if ($officer['latest_transfer_type'] === 'out' && !empty($officer['latest_transfer_date'])) {
                                        echo '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">TRANSFERRED-OUT</span>';
                                    } elseif ($officer['latest_removal_code'] === 'C') {
                                        echo '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">SUSPENDIDO (CODE-C)</span>';
                                    } elseif ($officer['latest_removal_code'] === 'D') {
                                        echo '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-orange-100 text-orange-800">LIPAT-KAPISANAN (CODE-D)</span>';
                                    } elseif (!empty($officer['latest_removal_reason']) && stripos($officer['latest_removal_reason'], 'transfer') !== false) {
                                        echo '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">TRANSFERRED-OUT</span>';
                                    } else {
                                        echo '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">Inactive</span>';
                                    }
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Officer Details -->
                    <div class="space-y-2 text-xs mb-3">
                        <div>
                            <span class="text-gray-500">Local:</span>
                            <span class="text-gray-900 dark:text-gray-100 font-medium ml-1"><?php echo Security::escape($officer['local_name']); ?></span>
                        </div>
                        <div>
                            <span class="text-gray-500">District:</span>
                            <span class="text-gray-900 dark:text-gray-100 ml-1"><?php echo Security::escape($officer['district_name']); ?></span>
                        </div>
                        
                        <?php if (!empty($officer['purok']) || !empty($officer['grupo']) || !empty($officer['control_number'])): ?>
                            <div class="pt-2 border-t border-gray-100">
                                <?php if (!empty($officer['purok'])): ?>
                                    <div class="flex items-center text-gray-700 mb-1">
                                        <svg class="w-3 h-3 mr-1.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                        </svg>
                                        <span>Purok: <span class="font-medium"><?php echo Security::escape($officer['purok']); ?></span></span>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($officer['grupo'])): ?>
                                    <div class="flex items-center text-gray-700 mb-1">
                                        <svg class="w-3 h-3 mr-1.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                                        </svg>
                                        <span>Grupo: <span class="font-medium"><?php echo Security::escape($officer['grupo']); ?></span></span>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($officer['control_number'])): ?>
                                    <div class="flex items-center text-gray-700">
                                        <svg class="w-3 h-3 mr-1.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 20l4-16m2 16l4-16M6 9h14M4 15h14"></path>
                                        </svg>
                                        <span>Control: <span class="font-medium"><?php echo Security::escape($officer['control_number']); ?></span></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="pt-2 border-t border-gray-100">
                            <span class="text-gray-500">Departments:</span>
                            <?php if ($officer['dept_count'] > 0): ?>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 ml-1">
                                    <?php echo $officer['dept_count']; ?> dept(s)
                                </span>
                            <?php else: ?>
                                <span class="text-gray-400 ml-1">None</span>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (!empty($officer['registry_number_encrypted']) || $officer['is_imported']): ?>
                            <div class="flex flex-wrap gap-1 pt-2">
                                <?php if (!empty($officer['registry_number_encrypted'])): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-emerald-100 text-emerald-800">
                                        <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M6 6V5a3 3 0 013-3h2a3 3 0 013 3v1h2a2 2 0 012 2v3.57A22.952 22.952 0 0110 13a22.95 22.95 0 01-8-1.43V8a2 2 0 012-2h2zm2-1a1 1 0 011-1h2a1 1 0 011 1v1H8V5zm1 5a1 1 0 011-1h.01a1 1 0 110 2H10a1 1 0 01-1-1z" clip-rule="evenodd"></path>
                                            <path d="M2 13.692V16a2 2 0 002 2h12a2 2 0 002-2v-2.308A24.974 24.974 0 0110 15c-2.796 0-5.487-.46-8-1.308z"></path>
                                        </svg>
                                        Registry #
                                    </span>
                                <?php endif; ?>
                                <?php if ($officer['is_imported']): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-800">
                                        <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                            <path d="M3 12v3c0 1.657 3.134 3 7 3s7-1.343 7-3v-3c0 1.657-3.134 3-7 3s-7-1.343-7-3z"/>
                                            <path d="M3 7v3c0 1.657 3.134 3 7 3s7-1.343 7-3V7c0 1.657-3.134 3-7 3S3 8.657 3 7z"/>
                                            <path d="M17 5c0 1.657-3.134 3-7 3S3 6.657 3 5s3.134-3 7-3 7 1.343 7 3z"/>
                                        </svg>
                                        LORCAPP
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Actions -->
                    <div class="flex items-center gap-2 pt-3 border-t border-gray-100">
                        <a href="<?php echo BASE_URL; ?>/officers/view.php?id=<?php echo urlencode($officer['officer_uuid']); ?>" 
                           class="flex-1 inline-flex items-center justify-center px-3 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors text-xs font-medium">
                            <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                            </svg>
                            View
                        </a>
                        <?php if (hasPermission('can_edit_officers')): ?>
                        <a href="<?php echo BASE_URL; ?>/officers/edit.php?id=<?php echo urlencode($officer['officer_uuid']); ?>" 
                           class="inline-flex items-center justify-center px-3 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors"
                           title="Edit"
                           target="_blank"
                           rel="noopener noreferrer">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                            </svg>
                        </a>
                        <?php endif; ?>
                        <?php if (hasPermission('can_delete_officers')): ?>
                        <button 
                           onclick="confirmDelete('<?php echo Security::escape($officer['officer_uuid']); ?>', '<?php echo Security::escape(obfuscateName($fullName)); ?>')" 
                           class="inline-flex items-center justify-center px-3 py-2 bg-red-50 text-red-600 rounded-lg hover:bg-red-100 transition-colors"
                           title="Delete">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                            </svg>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <!-- Pagination - Mobile -->
        <?php if ($pagination['total_pages'] > 1): ?>
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-3">
                <div class="flex items-center justify-center">
                    <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                        <?php if ($pagination['has_prev']): ?>
                            <a href="?page=<?php echo $pagination['current_page'] - 1; ?><?php echo $searchQuery ? '&search=' . urlencode($searchQuery) : ''; ?><?php echo $filterDepartment ? '&department=' . urlencode($filterDepartment) : ''; ?><?php echo $filterStatus ? '&status=' . urlencode($filterStatus) : ''; ?>" 
                               class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white dark:bg-gray-800 text-sm font-medium text-gray-500 hover:bg-gray-50">
                                <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                </svg>
                            </a>
                        <?php endif; ?>
                        
                        <span class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white dark:bg-gray-800 text-sm font-medium text-gray-700">
                            <?php echo $pagination['current_page']; ?> / <?php echo $pagination['total_pages']; ?>
                        </span>
                        
                        <?php if ($pagination['has_next']): ?>
                            <a href="?page=<?php echo $pagination['current_page'] + 1; ?><?php echo $searchQuery ? '&search=' . urlencode($searchQuery) : ''; ?><?php echo $filterDepartment ? '&department=' . urlencode($filterDepartment) : ''; ?><?php echo $filterStatus ? '&status=' . urlencode($filterStatus) : ''; ?>" 
                               class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white dark:bg-gray-800 text-sm font-medium text-gray-500 hover:bg-gray-50">
                                <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"></path>
                                </svg>
                            </a>
                        <?php endif; ?>
                    </nav>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
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

function selectDepartment(value, displayText) {
    document.getElementById('department-value').value = value;
    document.getElementById('department-display').value = displayText;
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

// Delete Officer Modal
let deleteOfficerData = { uuid: '', name: '' };

function confirmDelete(officerUuid, officerName) {
    deleteOfficerData = { uuid: officerUuid, name: officerName };
    document.getElementById('delete-officer-name').textContent = officerName;
    
    const modal = document.getElementById('delete-modal');
    modal.classList.remove('hidden');
    modal.style.display = 'block';
}

function closeDeleteModal() {
    const modal = document.getElementById('delete-modal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
    deleteOfficerData = { uuid: '', name: '' };
    
    // Reset button state
    const btn = document.getElementById('confirm-delete-btn');
    btn.disabled = false;
    document.getElementById('delete-btn-text').classList.remove('hidden');
    document.getElementById('delete-btn-loading').classList.add('hidden');
}

async function confirmDeleteOfficer() {
    const btn = document.getElementById('confirm-delete-btn');
    
    // Disable button and show loading state
    btn.disabled = true;
    document.getElementById('delete-btn-text').classList.add('hidden');
    document.getElementById('delete-btn-loading').classList.remove('hidden');
    
    try {
        const response = await fetch('<?php echo BASE_URL; ?>/api/delete-officer.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                officer_uuid: deleteOfficerData.uuid,
                csrf_token: '<?php echo Security::generateCSRFToken(); ?>'
            })
        });

        const data = await response.json();

        if (data.success) {
            // Show success state
            closeDeleteModal();
            
            // Show success notification
            showNotification('success', `Officer "${deleteOfficerData.name}" has been deleted successfully.`);
            
            // Reload the page after a short delay
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        } else {
            // Re-enable button
            btn.disabled = false;
            document.getElementById('delete-btn-text').classList.remove('hidden');
            document.getElementById('delete-btn-loading').classList.add('hidden');
            
            showNotification('error', data.message || 'Failed to delete officer.');
        }
    } catch (error) {
        console.error('Delete error:', error);
        
        // Re-enable button
        btn.disabled = false;
        document.getElementById('delete-btn-text').classList.remove('hidden');
        document.getElementById('delete-btn-loading').classList.add('hidden');
        
        showNotification('error', 'An error occurred while deleting the officer. Please try again.');
    }
}

// Simple notification function
function showNotification(type, message) {
    const bgColor = type === 'success' ? 'bg-green-500' : 'bg-red-500';
    const notification = document.createElement('div');
    notification.className = `fixed top-4 right-4 ${bgColor} text-white px-6 py-3 rounded-lg shadow-lg z-50 transform transition-all duration-300`;
    notification.textContent = message;
    
    document.body.appendChild(notification);
    
    // Fade out and remove after 3 seconds
    setTimeout(() => {
        notification.style.opacity = '0';
        setTimeout(() => notification.remove(), 300);
    }, 3000);
}

// Close modal on ESC key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeDeleteModal();
        closeDepartmentModal();
    }
});
</script>

<!-- Department Modal -->
<div id="department-modal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity" onclick="closeDepartmentModal()"></div>
        <div class="relative bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-md w-full max-h-[80vh] flex flex-col">
            <!-- Header -->
            <div class="flex items-center justify-between p-4 border-b">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Select Department</h3>
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
                <div class="department-item px-4 py-3 hover:bg-gray-50 cursor-pointer border-b border-gray-100"
                     onclick="selectDepartment('', 'All Departments')">
                    <span class="text-gray-900 dark:text-gray-100 font-medium">All Departments</span>
                </div>
                <?php foreach (getDepartments() as $dept): ?>
                    <div class="department-item px-4 py-3 hover:bg-gray-50 cursor-pointer border-b border-gray-100"
                         onclick="selectDepartment('<?php echo Security::escape($dept); ?>', '<?php echo Security::escape($dept); ?>')">
                        <span class="text-gray-900 dark:text-gray-100"><?php echo Security::escape($dept); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="delete-modal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity" onclick="closeDeleteModal()"></div>
        <div class="relative bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-lg w-full">
            <!-- Header -->
            <div class="flex items-center justify-between p-6 border-b border-gray-200 dark:border-gray-700">
                <div class="flex items-center">
                    <div class="flex-shrink-0 w-12 h-12 rounded-full bg-red-100 flex items-center justify-center mr-4">
                        <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                        </svg>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Confirm Delete Officer</h3>
                </div>
                <button type="button" onclick="closeDeleteModal()" class="text-gray-400 hover:text-gray-500">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            
            <!-- Body -->
            <div class="p-6">
                <p class="text-sm text-gray-700 mb-4">
                    Are you sure you want to permanently delete officer <strong id="delete-officer-name" class="text-gray-900 dark:text-gray-100"></strong>?
                </p>
                <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4">
                    <p class="text-sm font-semibold text-red-800 mb-2">⚠️ Warning: This action cannot be undone!</p>
                    <p class="text-sm text-red-700 mb-2">Deleting this officer will permanently remove:</p>
                    <ul class="text-sm text-red-700 list-disc list-inside space-y-1">
                        <li>Officer's personal information</li>
                        <li>All department assignments</li>
                        <li>Call-up records and history</li>
                        <li>Transfer history</li>
                        <li>Request history</li>
                    </ul>
                </div>
                <p class="text-sm text-gray-600">
                    This will be logged in the audit trail for security purposes.
                </p>
            </div>
            
            <!-- Footer -->
            <div class="flex items-center justify-end space-x-3 px-6 py-4 bg-gray-50 border-t border-gray-200 dark:border-gray-700 rounded-b-lg">
                <button 
                    type="button" 
                    onclick="closeDeleteModal()" 
                    class="px-4 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 bg-white dark:bg-gray-800 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors">
                    Cancel
                </button>
                <button 
                    type="button" 
                    id="confirm-delete-btn"
                    onclick="confirmDeleteOfficer()" 
                    class="px-4 py-2 border border-transparent rounded-lg text-sm font-medium text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 transition-colors">
                    <span id="delete-btn-text">Delete Officer</span>
                    <span id="delete-btn-loading" class="hidden">
                        <svg class="animate-spin inline-block w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        Deleting...
                    </span>
                </button>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>

