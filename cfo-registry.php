<?php
/**
 * CFO Registry (Christian Family Organization)
 * View and manage CFO members from Tarheta Control
 */

require_once __DIR__ . '/config/config.php';

Security::requireLogin();
requirePermission('can_view_reports'); // Anyone who can view reports can see this

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

$error = '';
$success = '';

$pageTitle = 'CFO Registry';
ob_start();
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900">CFO Registry</h1>
                <p class="text-sm text-gray-500 mt-1">Christian Family Organization - Buklod, Kadiwa, Binhi</p>
            </div>
            <div class="flex gap-2">
                <a href="cfo-import.php" class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                    </svg>
                    Import CSV
                </a>
                <a href="cfo-add.php" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                    </svg>
                    Add CFO Member
                </a>
                <a href="tarheta/list.php" class="inline-flex items-center px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    Tarheta Control
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
                <h3 class="text-sm font-semibold text-blue-800">Quick Inline Editing</h3>
                <p class="text-xs text-blue-700 mt-1">Click on the <strong>Classification</strong> or <strong>Status</strong> dropdowns in the table below to update records instantly. Changes are saved automatically in real-time.</p>
            </div>
        </div>
    </div>

    <!-- Statistics -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <?php
        // Get CFO statistics
        $stats = [
            'total' => 0,
            'buklod' => 0,
            'kadiwa' => 0,
            'binhi' => 0,
            'active' => 0,
            'transferred' => 0
        ];
        
        try {
            $whereConditions = [];
            $params = [];
            
            // Default filter: active status
            $whereConditions[] = 'cfo_status = ?';
            $params[] = 'active';
            
            if ($currentUser['role'] === 'district') {
                $whereConditions[] = 'district_code = ?';
                $params[] = $currentUser['district_code'];
            } elseif ($currentUser['role'] === 'local' || $currentUser['role'] === 'local_cfo') {
                $whereConditions[] = 'local_code = ?';
                $params[] = $currentUser['local_code'];
            }
            
            $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
            
            // Total CFO members
            $stmt = $db->prepare("SELECT COUNT(*) as total FROM tarheta_control $whereClause");
            $stmt->execute($params);
            $stats['total'] = $stmt->fetch()['total'];
            
            // By classification
            $stmt = $db->prepare("
                SELECT 
                    cfo_classification,
                    COUNT(*) as count 
                FROM tarheta_control 
                $whereClause 
                GROUP BY cfo_classification
            ");
            $stmt->execute($params);
            while ($row = $stmt->fetch()) {
                if ($row['cfo_classification']) {
                    $stats[strtolower($row['cfo_classification'])] = $row['count'];
                }
            }
            
            // By status
            $stmt = $db->prepare("
                SELECT 
                    cfo_status,
                    COUNT(*) as count 
                FROM tarheta_control 
                $whereClause 
                GROUP BY cfo_status
            ");
            $stmt->execute($params);
            while ($row = $stmt->fetch()) {
                if ($row['cfo_status'] === 'active') {
                    $stats['active'] = $row['count'];
                } elseif ($row['cfo_status'] === 'transferred-out') {
                    $stats['transferred'] = $row['count'];
                }
            }
        } catch (Exception $e) {
            error_log("Error loading CFO stats: " . $e->getMessage());
        }
        ?>
        
        <div class="bg-blue-50 rounded-lg p-4 border border-blue-200">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-blue-700 font-medium">Total Members</p>
                    <p class="text-3xl font-bold text-blue-900" id="stat-total"><?php echo number_format($stats['total']); ?></p>
                </div>
                <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center">
                    <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                    </svg>
                </div>
            </div>
        </div>
        
        <div class="bg-purple-50 rounded-lg p-4 border border-purple-200">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-purple-700 font-medium">Buklod</p>
                    <p class="text-3xl font-bold text-purple-900" id="stat-buklod"><?php echo number_format($stats['buklod']); ?></p>
                    <p class="text-xs text-purple-600 mt-1">Married Couples</p>
                </div>
                <div class="w-12 h-12 bg-purple-100 rounded-full flex items-center justify-center">
                    <i class="fa-solid fa-rings-wedding text-xl text-purple-600"></i>
                </div>
            </div>
        </div>
        
        <div class="bg-green-50 rounded-lg p-4 border border-green-200">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-green-700 font-medium">Kadiwa</p>
                    <p class="text-3xl font-bold text-green-900" id="stat-kadiwa"><?php echo number_format($stats['kadiwa']); ?></p>
                    <p class="text-xs text-green-600 mt-1">Youth</p>
                </div>
                <div class="w-12 h-12 bg-green-100 rounded-full flex items-center justify-center">
                    <i class="fa-solid fa-user-group text-xl text-green-600"></i>
                </div>
            </div>
        </div>
        
        <div class="bg-orange-50 rounded-lg p-4 border border-orange-200">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-orange-700 font-medium">Binhi</p>
                    <p class="text-3xl font-bold text-orange-900" id="stat-binhi"><?php echo number_format($stats['binhi']); ?></p>
                    <p class="text-xs text-orange-600 mt-1">Children</p>
                </div>
                <div class="w-12 h-12 bg-orange-100 rounded-full flex items-center justify-center">
                    <i class="fa-solid fa-seedling text-xl text-orange-600"></i>
                </div>
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

    <!-- CFO Registry Table -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
        <div class="p-4 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-900">CFO Members</h2>
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
                        <p class="text-blue-100 text-sm">Update member classification and status</p>
                    </div>
                </div>
                <button onclick="closeEditModal()" class="text-white hover:bg-white hover:bg-opacity-20 rounded-lg p-2 transition-all duration-200">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
        </div>
        
        <form id="editForm" class="p-6 space-y-6 relative" data-no-loader>
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
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
                        <svg class="w-4 h-4 mr-2 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                        </svg>
                        CFO Classification
                        <span class="text-red-600 ml-1">*</span>
                    </label>
                    <select id="edit_classification" name="cfo_classification" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200">
                        <option value="">-- Select Classification --</option>
                        <option value="Buklod">� Buklod (Married Couples)</option>
                        <option value="Kadiwa">👥 Kadiwa (Youth 18+)</option>
                        <option value="Binhi">🌱 Binhi (Children under 18)</option>
                    </select>
                    <p class="text-xs text-gray-500 mt-1 flex items-center" id="classification_hint">
                        <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                        </svg>
                        Select the appropriate classification for this member
                    </p>
                </div>
                
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
                        <svg class="w-4 h-4 mr-2 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        Status
                        <span class="text-red-600 ml-1">*</span>
                    </label>
                    <select id="edit_status" name="cfo_status" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200">
                        <option value="active">✓ Active</option>
                        <option value="transferred-out">→ Transferred Out</option>
                    </select>
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
                <button type="button" id="editModeBtn" class="hidden flex-1 px-6 py-3 bg-gradient-to-r from-green-600 to-green-700 text-white rounded-lg hover:from-green-700 hover:to-green-800 transition-all duration-200 font-semibold shadow-lg hover:shadow-xl transform hover:scale-105 flex items-center justify-center" onclick="enableEditMode()">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                    </svg>
                    Edit Information
                </button>
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

<!-- Loading Overlay -->
<div id="loadingOverlay" class="hidden fixed inset-0 bg-black bg-opacity-30 z-40 flex items-center justify-center">
    <div class="bg-white rounded-xl p-6 shadow-2xl flex flex-col items-center space-y-3">
        <svg class="animate-spin h-12 w-12 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
        <p class="text-gray-700 font-medium">Loading...</p>
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

// Function to update statistics based on current filters
function updateStats() {
    const filters = {
        classification: $('#filterClassification').val(),
        status: $('#filterStatus').val(),
        district: $('#filterDistrict').val(),
        local: $('#filterLocal').val(),
        missing_birthday: $('#filterMissingBirthday').is(':checked') ? '1' : ''
    };
    
    fetch('api/get-cfo-data.php?' + new URLSearchParams({
        action: 'stats',
        ...filters
    }))
    .then(response => response.json())
    .then(data => {
        if (data.stats) {
            $('#stat-total').text(data.stats.total.toLocaleString());
            $('#stat-buklod').text(data.stats.buklod.toLocaleString());
            $('#stat-kadiwa').text(data.stats.kadiwa.toLocaleString());
            $('#stat-binhi').text(data.stats.binhi.toLocaleString());
        }
    })
    .catch(error => {
        console.error('Error updating stats:', error);
    });
}

// Function to apply filters
function applyFilters() {
    table.ajax.reload();
    updateStats();
}

$(document).ready(function() {
    // Initialize DataTable
    table = $('#cfoTable').DataTable({
        processing: true,
        serverSide: true,
        searchDelay: 500, // Debounce search by 500ms
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
            { data: 'name' },
            { data: 'last_name', visible: false },
            { data: 'first_name', visible: false },
            { data: 'middle_name', visible: false },
            { data: 'registry_number' },
            { data: 'husbands_surname' },
            { data: 'birthday' },
            { 
                data: 'cfo_classification',
                render: function(data, type, row) {
                    // For export, return plain text
                    if (type === 'export') {
                        return data || 'Unclassified';
                    }
                    
                    let options = [
                        {value: '', label: '-- Select --', selected: !data},
                        {value: 'Buklod', label: '💑 Buklod', selected: data === 'Buklod'},
                        {value: 'Kadiwa', label: '👥 Kadiwa', selected: data === 'Kadiwa'},
                        {value: 'Binhi', label: '👶 Binhi', selected: data === 'Binhi'}
                    ];
                    
                    let selectHtml = '<select class="inline-edit-classification w-full px-3 py-1.5 text-xs border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 cursor-pointer hover:border-blue-400 transition-colors" data-id="' + row.id + '" data-original="' + (data || '') + '">';
                    options.forEach(opt => {
                        selectHtml += '<option value="' + opt.value + '"' + (opt.selected ? ' selected' : '') + '>' + opt.label + '</option>';
                    });
                    selectHtml += '</select>';
                    
                    return selectHtml;
                }
            },
            { 
                data: 'cfo_status',
                render: function(data, type, row) {
                    // For export, return plain text
                    if (type === 'export') {
                        if (data === 'transferred-out') return 'Transferred Out';
                        return 'Active';
                    }
                    
                    let options = [
                        {value: 'active', label: '✓ Active', selected: data === 'active' || !data},
                        {value: 'transferred-out', label: '→ Transferred Out', selected: data === 'transferred-out'}
                    ];
                    
                    let selectHtml = '<select class="inline-edit-status w-full px-3 py-1.5 text-xs border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500 cursor-pointer hover:border-green-400 transition-colors" data-id="' + row.id + '" data-original="' + (data || 'active') + '">';
                    options.forEach(opt => {
                        selectHtml += '<option value="' + opt.value + '"' + (opt.selected ? ' selected' : '') + '>' + opt.label + '</option>';
                    });
                    selectHtml += '</select>';
                    
                    return selectHtml;
                }
            },
            { 
                data: 'purok_grupo',
                render: function(data, type, row) {
                    if (type === 'export') return data;
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
                        <button onclick="viewDetails(${row.id})" class="inline-flex items-center px-3 py-2 bg-blue-50 text-blue-700 rounded-lg hover:bg-blue-100 transition-all duration-200 text-sm font-medium" title="View Details">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                            </svg>
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
                text: '<svg class="w-4 h-4 mr-2 inline" fill="currentColor" viewBox="0 0 20 20"><path d="M9 2a2 2 0 00-2 2v8a2 2 0 002 2h6a2 2 0 002-2V6.414A2 2 0 0016.414 5L14 2.586A2 2 0 0012.586 2H9z"></path><path d="M3 8a2 2 0 012-2v10h8a2 2 0 01-2 2H5a2 2 0 01-2-2V8z"></path></svg> Export All to Excel',
                className: 'bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-medium transition-colors',
                action: function(e, dt, node, config) {
                    exportAllToExcel();
                }
            }
        ],
        language: {
            processing: '<div class="flex items-center justify-center"><svg class="animate-spin h-8 w-8 text-blue-600 mr-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg><span class="text-gray-700">Loading data...</span></div>',
            emptyTable: '<div class="text-center py-8"><svg class="w-16 h-16 mx-auto text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path></svg><p class="text-gray-500 text-lg font-medium">No CFO members found</p><p class="text-gray-400 text-sm">Start by adding members or adjusting your filters</p></div>'
        }
    });
    
    // Auto-load districts and locals AFTER DataTable initialization
    <?php if ($currentUser['role'] === 'district' || $currentUser['role'] === 'local' || $currentUser['role'] === 'local_cfo'): ?>
    const userDistrictCode = '<?php echo Security::escape($currentUser['district_code']); ?>';
    console.log('User role: <?php echo $currentUser['role']; ?>, District code:', userDistrictCode);
    
    // Load locals for district users
    <?php if ($currentUser['role'] === 'district'): ?>
    if (userDistrictCode) {
        loadDistricts().then(() => {
            console.log('Loading locals for district:', userDistrictCode);
            loadLocalsForDistrict(userDistrictCode);
        });
    }
    <?php endif; ?>
    <?php else: ?>
    // Load districts for admin
    loadDistricts();
    <?php endif; ?>
    
    // Inline editing for CFO Classification
    $('#cfoTable').on('change', '.inline-edit-classification', function() {
        const $select = $(this);
        const id = $select.data('id');
        const newValue = $select.val();
        const originalValue = $select.data('original');
        
        if (newValue === originalValue) {
            return; // No change
        }
        
        if (!newValue) {
            $select.val(originalValue);
            showError('Please select a classification');
            return;
        }
        
        // Show loading state
        $select.prop('disabled', true);
        $select.addClass('opacity-50 cursor-wait');
        
        // Update via AJAX
        $.ajax({
            url: '<?php echo BASE_URL; ?>/api/update-cfo.php',
            method: 'POST',
            data: {
                id: id,
                cfo_classification: newValue,
                csrf_token: $('meta[name=\"csrf-token\"]').attr('content')
            },
            success: function(response) {
                if (response.success) {
                    $select.data('original', newValue);
                    $select.removeClass('opacity-50 cursor-wait').addClass('border-green-400 bg-green-50');
                    showSuccess('Classification updated successfully');
                    
                    // Reset styling after animation
                    setTimeout(() => {
                        $select.removeClass('border-green-400 bg-green-50');
                    }, 2000);
                    
                    // Reload DataTable and stats in background without page refresh
                    table.ajax.reload(null, false); // false = stay on current page
                    
                    // Reload statistics
                    $.ajax({
                        url: window.location.href,
                        success: function(html) {
                            // Extract and update statistics cards
                            const $newStats = $(html).find('.grid.grid-cols-1.md\\:grid-cols-4 > div');
                            $('.grid.grid-cols-1.md\\:grid-cols-4 > div').each(function(index) {
                                $(this).html($newStats.eq(index).html());
                            });
                        }
                    });
                } else {
                    $select.val(originalValue);
                    showError(response.error || 'Failed to update classification');
                }
                $select.prop('disabled', false);
            },
            error: function() {
                $select.val(originalValue);
                $select.prop('disabled', false);
                $select.removeClass('opacity-50 cursor-wait');
                showError('Network error. Please try again.');
            }
        });
    });
    
    // Inline editing for CFO Status
    $('#cfoTable').on('change', '.inline-edit-status', function() {
        const $select = $(this);
        const id = $select.data('id');
        const newValue = $select.val();
        const originalValue = $select.data('original');
        
        if (newValue === originalValue) {
            return; // No change
        }
        
        // Show loading state
        $select.prop('disabled', true);
        $select.addClass('opacity-50 cursor-wait');
        
        // Update via AJAX
        $.ajax({
            url: '<?php echo BASE_URL; ?>/api/update-cfo.php',
            method: 'POST',
            data: {
                id: id,
                cfo_status: newValue,
                csrf_token: $('meta[name=\"csrf-token\"]').attr('content')
            },
            success: function(response) {
                if (response.success) {
                    $select.data('original', newValue);
                    $select.removeClass('opacity-50 cursor-wait').addClass('border-green-400 bg-green-50');
                    showSuccess('Status updated successfully');
                    
                    // Reset styling after animation
                    setTimeout(() => {
                        $select.removeClass('border-green-400 bg-green-50');
                    }, 2000);
                    
                    // Reload DataTable and stats in background without page refresh
                    table.ajax.reload(null, false); // false = stay on current page
                    
                    // Reload statistics
                    $.ajax({
                        url: window.location.href,
                        success: function(html) {
                            // Extract and update statistics cards
                            const $newStats = $(html).find('.grid.grid-cols-1.md\\:grid-cols-4 > div');
                            $('.grid.grid-cols-1.md\\:grid-cols-4 > div').each(function(index) {
                                $(this).html($newStats.eq(index).html());
                            });
                        }
                    });
                } else {
                    $select.val(originalValue);
                    showError(response.error || 'Failed to update status');
                }
                $select.prop('disabled', false);
            },
            error: function() {
                $select.val(originalValue);
                $select.prop('disabled', false);
                $select.removeClass('opacity-50 cursor-wait');
                showError('Network error. Please try again.');
            }
        });
    });
    
    // Trigger filter on checkbox change
    $('#filterMissingBirthday').on('change', function() {
        applyFilters();
    });
    
    // Update stats on page load to match default filter
    updateStats();
});

function enableEditMode() {
    // Enable all form fields
    $('#edit_first_name, #edit_middle_name, #edit_last_name, #edit_husbands_surname, #edit_birthday, #edit_classification, #edit_status, #edit_notes').prop('disabled', false);
    
    // Remove readonly styling
    $('#edit_first_name, #edit_middle_name, #edit_last_name, #edit_husbands_surname, #edit_birthday, #edit_classification, #edit_status, #edit_notes').removeClass('bg-gray-100 cursor-not-allowed');
    
    // Hide edit button, show save button
    $('#editModeBtn').addClass('hidden');
    $('#saveBtn').show();
    
    // Update modal title
    $('#modalContent h3').text('Edit CFO Information');
    
    // Add visual feedback
    const fields = $('#edit_first_name, #edit_middle_name, #edit_last_name, #edit_husbands_surname, #edit_birthday, #edit_classification, #edit_status, #edit_notes');
    fields.addClass('ring-2 ring-blue-200');
    setTimeout(() => {
        fields.removeClass('ring-2 ring-blue-200');
    }, 1000);
}

function showLoading() {
    $('#loadingOverlay').removeClass('hidden');
}

function hideLoading() {
    $('#loadingOverlay').addClass('hidden');
}

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
        
        // Only populate if it's a select element (admin users)
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
        // Only populate if it's a select element
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

async function viewDetails(id) {
    // Show modal
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
        
        // Show details in modal
        $('#edit_id').val(data.id);
        $('#edit_first_name').val(data.first_name || '').prop('disabled', true);
        $('#edit_middle_name').val(data.middle_name || '').prop('disabled', true);
        $('#edit_last_name').val(data.last_name || '').prop('disabled', true);
        $('#edit_husbands_surname').val(data.husbands_surname || '').prop('disabled', true);
        $('#edit_registry').val(data.registry_number);
        $('#edit_birthday').val(data.birthday_raw || '').prop('disabled', true);
        $('#edit_classification').val(data.cfo_classification || '').prop('disabled', true);
        $('#edit_status').val(data.cfo_status || 'active').prop('disabled', true);
        $('#edit_notes').val(data.cfo_notes || '').prop('disabled', true);
        
        // Hide save button, show edit button
        $('#saveBtn').hide();
        $('#editModeBtn').removeClass('hidden');
        $('#modalContent h3').text('View CFO Information');
        
    } catch (error) {
        showError('Failed to load details');
        closeEditModal();
    }
}

async function editCFO(id) {
    // Show modal
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
        
        // Populate form
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
        
        // Update hint text
        updateClassificationHint();
    } catch (error) {
        console.error('Error loading CFO details:', error);
        showError('Error loading CFO details');
        closeEditModal();
    }
}

function closeEditModal() {
    const modal = $('#editModal');
    const content = $('#modalContent');
    
    content.removeClass('scale-100').addClass('scale-95');
    modal.addClass('opacity-0');
    
    setTimeout(() => {
        modal.addClass('hidden');
        $('#editForm')[0].reset();
        // Re-enable all fields
        $('#edit_first_name, #edit_middle_name, #edit_last_name, #edit_husbands_surname, #edit_birthday, #edit_purok, #edit_grupo, #edit_classification, #edit_status, #edit_notes').prop('disabled', false);
        // Show save button, hide edit button
        $('#saveBtn').show();
        $('#editModeBtn').addClass('hidden');
        $('#modalContent h3').text('Edit CFO Information');
    }, 300);
}

function updateClassificationHint() {
    const classification = $('#edit_classification').val();
    const hint = $('#classification_hint');
    
    const hints = {
        'Buklod': '💑 For married couples and families',
        'Kadiwa': '👥 For youth members 18 years and above',
        'Binhi': '👶 For children under 18 years old',
        '': 'Select the appropriate classification for this member'
    };
    
    hint.html(`<svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path></svg>${hints[classification] || hints['']}`);
}

$('#edit_classification').on('change', updateClassificationHint);

$('#editForm').on('submit', async function(e) {
    e.preventDefault();
    
    const saveBtn = $('#saveBtn');
    const saveBtnText = $('#saveBtnText');
    const saveIcon = $('#saveIcon');
    const saveSpinner = $('#saveSpinner');
    
    // Disable button and show loading
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
            table.ajax.reload(null, false); // Reload without resetting pagination
        } else {
            showError(result.error || 'Error updating CFO information');
        }
    } catch (error) {
        console.error('Error updating CFO:', error);
        showError('Error updating CFO information');
    } finally {
        // Re-enable button
        saveBtn.prop('disabled', false).addClass('hover:scale-105');
        saveBtnText.text('Save Changes');
        saveIcon.removeClass('hidden');
        saveSpinner.addClass('hidden');
    }
});

// Close modal on ESC key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeEditModal();
    }
});

// Close modal on backdrop click
$('#editModal').on('click', function(e) {
    if (e.target === this) {
        closeEditModal();
    }
});

// Export all filtered data to Excel
function exportAllToExcel() {
    // Get current filter values
    const filters = {
        classification: $('#filterClassification').val(),
        status: $('#filterStatus').val(),
        district: $('#filterDistrict').val(),
        local: $('#filterLocal').val(),
        search: table.search()
    };
    
    // Build query string
    const params = new URLSearchParams();
    if (filters.classification) params.append('classification', filters.classification);
    if (filters.status) params.append('status', filters.status);
    if (filters.district) params.append('district', filters.district);
    if (filters.local) params.append('local', filters.local);
    if (filters.search) params.append('search', filters.search);
    
    // Open export endpoint in new window
    window.location.href = '<?php echo BASE_URL; ?>/api/export-cfo-excel.php?' + params.toString();
}

</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/includes/layout.php';
?>
