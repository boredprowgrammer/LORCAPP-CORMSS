<?php
require_once __DIR__ . '/../config/config.php';

Security::requireLogin();

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

$officerUuid = Security::sanitizeInput($_GET['id'] ?? '');

if (empty($officerUuid)) {
    setFlashMessage('error', 'Invalid officer ID.');
    redirect(BASE_URL . '/officers/list.php');
}

// Handle manual code setting for imported officers
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'set_code') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!Security::validateCSRFToken($csrfToken)) {
        setFlashMessage('error', 'Invalid security token.');
    } else {
        try {
            // Update the officer's record_code to 'A'
            $stmt = $db->prepare("UPDATE officers SET record_code = 'A' WHERE officer_uuid = ?");
            $stmt->execute([$officerUuid]);
            
            setFlashMessage('success', 'Officer code set to CODE A successfully!');
            redirect(BASE_URL . '/officers/view.php?id=' . $officerUuid);
            
        } catch (Exception $e) {
            error_log("Set code error: " . $e->getMessage());
            setFlashMessage('error', 'Error setting code: ' . $e->getMessage());
        }
    }
}

// Handle department removal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'remove_department') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!Security::validateCSRFToken($csrfToken)) {
        setFlashMessage('error', 'Invalid security token.');
    } else {
        $departmentId = intval($_POST['department_id'] ?? 0);
        $removalCode = Security::sanitizeInput($_POST['removal_code'] ?? '');
        $removalReason = Security::sanitizeInput($_POST['removal_reason'] ?? '');
        
        if (empty($departmentId) || empty($removalCode)) {
            setFlashMessage('error', 'Department ID and removal code are required.');
        } elseif (!in_array($removalCode, ['C', 'D'])) {
            setFlashMessage('error', 'Invalid removal code.');
        } else {
            try {
                $db->beginTransaction();
                
                // Verify department belongs to this officer
                $stmt = $db->prepare("
                    SELECT od.*, o.officer_id 
                    FROM officer_departments od
                    JOIN officers o ON od.officer_id = o.officer_id
                    WHERE od.id = ? AND o.officer_uuid = ?
                ");
                $stmt->execute([$departmentId, $officerUuid]);
                $dept = $stmt->fetch();
                
                if (!$dept) {
                    throw new Exception('Department not found.');
                }
                
                // Deactivate the department
                $stmt = $db->prepare("
                    UPDATE officer_departments 
                    SET is_active = 0 
                    WHERE id = ?
                ");
                $stmt->execute([$departmentId]);
                
                // Log the removal in officer_removals table
                $stmt = $db->prepare("
                    INSERT INTO officer_removals (
                        officer_id, department_id, removal_code, removal_date, reason, 
                        week_number, year, processed_by
                    ) VALUES (?, ?, ?, CURRENT_DATE, ?, ?, ?, ?)
                ");
                $weekInfo = getWeekDateRange();
                $stmt->execute([
                    $dept['officer_id'],
                    $departmentId,
                    $removalCode,
                    "Department: {$dept['department']} - {$removalReason}",
                    $weekInfo['week'],
                    $weekInfo['year'],
                    $currentUser['user_id']
                ]);
                
                // Log audit
                $stmt = $db->prepare("
                    INSERT INTO audit_log (user_id, action, table_name, record_id, old_values, ip_address, user_agent)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $currentUser['user_id'],
                    'remove_department',
                    'officer_departments',
                    $departmentId,
                    json_encode([
                        'department' => $dept['department'],
                        'removal_code' => $removalCode,
                        'reason' => $removalReason
                    ]),
                    $_SERVER['REMOTE_ADDR'],
                    $_SERVER['HTTP_USER_AGENT'] ?? ''
                ]);
                
                $db->commit();
                setFlashMessage('success', 'Department removed successfully.');
                redirect(BASE_URL . '/officers/view.php?id=' . $officerUuid);
                
            } catch (Exception $e) {
                $db->rollBack();
                error_log("Remove department error: " . $e->getMessage());
                setFlashMessage('error', 'Error removing department: ' . $e->getMessage());
            }
        }
    }
}

try {
    // Get officer details
    $stmt = $db->prepare("
        SELECT 
            o.*,
            d.district_name,
            lc.local_name,
            u.full_name as created_by_name
        FROM officers o
        LEFT JOIN districts d ON o.district_code = d.district_code
        LEFT JOIN local_congregations lc ON o.local_code = lc.local_code
        LEFT JOIN users u ON o.created_by = u.user_id
        WHERE o.officer_uuid = ?
    ");
    $stmt->execute([$officerUuid]);
    $officer = $stmt->fetch();
    
    if (!$officer) {
        setFlashMessage('error', 'Officer not found.');
        redirect(BASE_URL . '/officers/list.php');
    }
    
    // Check access
    if (!hasDistrictAccess($officer['district_code']) || !hasLocalAccess($officer['local_code'])) {
        setFlashMessage('error', 'Access denied.');
        redirect(BASE_URL . '/officers/list.php');
    }
    
    // Decrypt officer name
    $decryptedName = Encryption::decryptOfficerName(
        $officer['last_name_encrypted'],
        $officer['first_name_encrypted'],
        $officer['middle_initial_encrypted'],
        $officer['district_code']
    );
    
    // Decrypt registry number if exists
    $registryNumber = null;
    if (!empty($officer['registry_number_encrypted'])) {
        $registryNumber = Encryption::decrypt($officer['registry_number_encrypted'], $officer['district_code']);
    }
    
    // Get departments
    $stmt = $db->prepare("
        SELECT * FROM officer_departments 
        WHERE officer_id = ? 
        ORDER BY assigned_at DESC
    ");
    $stmt->execute([$officer['officer_id']]);
    $departments = $stmt->fetchAll();
    
    // Get transfers
    $stmt = $db->prepare("
        SELECT t.*, u.full_name as processed_by_name
        FROM transfers t
        LEFT JOIN users u ON t.processed_by = u.user_id
        WHERE t.officer_id = ?
        ORDER BY t.transfer_date DESC
    ");
    $stmt->execute([$officer['officer_id']]);
    $transfers = $stmt->fetchAll();
    
    // Get removals
    $stmt = $db->prepare("
        SELECT r.*, u.full_name as processed_by_name
        FROM officer_removals r
        LEFT JOIN users u ON r.processed_by = u.user_id
        WHERE r.officer_id = ?
        ORDER BY r.removal_date DESC
    ");
    $stmt->execute([$officer['officer_id']]);
    $removals = $stmt->fetchAll();
    
    // Check if there are pending removals
    $hasPendingRemovals = false;
    foreach ($removals as $removal) {
        if (in_array($removal['status'], ['deliberated_by_local', 'requested'])) {
            $hasPendingRemovals = true;
            break;
        }
    }
    
} catch (Exception $e) {
    error_log("View officer error: " . $e->getMessage());
    setFlashMessage('error', 'An error occurred while loading officer details.');
    redirect(BASE_URL . '/officers/list.php');
}

$pageTitle = 'Officer Details';
ob_start();
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <a href="<?php echo BASE_URL; ?>/officers/list.php" class="inline-flex items-center px-3 py-1.5 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors mb-2">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                </svg>
                Back to List
            </a>
            <h2 class="text-3xl font-bold text-gray-900">Officer Profile</h2>
        </div>
        <div class="flex gap-2">
            <a href="<?php echo BASE_URL; ?>/officers/edit.php?id=<?php echo urlencode($officerUuid); ?>" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                </svg>
                Edit
            </a>
        </div>
    </div>
    
    <!-- Officer Card -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <div class="flex flex-col md:flex-row gap-6">
            <!-- Avatar -->
            <div class="flex-shrink-0">
                <div class="w-32 h-32 bg-blue-600 text-white rounded-full flex items-center justify-center">
                    <span class="text-5xl font-bold">
                        <?php echo strtoupper(substr($decryptedName['first_name'], 0, 1)); ?>
                    </span>
                </div>
            </div>
            
            <!-- Details -->
            <div class="flex-1 space-y-4">
                <div>
                    <h3 class="text-3xl font-bold text-gray-900 cursor-pointer name-mono" 
                        title="<?php echo Security::escape($decryptedName['first_name'] . ' ' . 
                            ($decryptedName['middle_initial'] ? $decryptedName['middle_initial'] . '. ' : '') . 
                            $decryptedName['last_name']); ?>"
                        ondblclick="this.textContent='<?php echo Security::escape($decryptedName['first_name'] . ' ' . 
                            ($decryptedName['middle_initial'] ? $decryptedName['middle_initial'] . '. ' : '') . 
                            $decryptedName['last_name']); ?>'"
                        style="font-family: 'JetBrains Mono', monospace;">
                        <?php 
                        $fullName = $decryptedName['first_name'] . ' ' . 
                                   ($decryptedName['middle_initial'] ? $decryptedName['middle_initial'] . '. ' : '') . 
                                   $decryptedName['last_name'];
                        echo Security::escape(obfuscateName($fullName)); 
                        ?>
                    </h3>
                    <p class="text-sm text-gray-600">UUID: <?php echo Security::escape($officer['officer_uuid']); ?></p>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <p class="text-sm text-gray-600">District</p>
                        <p class="font-semibold text-gray-900"><?php echo Security::escape($officer['district_name']); ?></p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Local Congregation</p>
                        <p class="font-semibold text-gray-900"><?php echo Security::escape($officer['local_name']); ?></p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Purok</p>
                        <p class="font-semibold text-gray-900">
                            <?php echo !empty($officer['purok']) ? Security::escape($officer['purok']) : '<span class="text-gray-400 italic">Not set</span>'; ?>
                        </p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Grupo</p>
                        <p class="font-semibold text-gray-900">
                            <?php echo !empty($officer['grupo']) ? Security::escape($officer['grupo']) : '<span class="text-gray-400 italic">Not set</span>'; ?>
                        </p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Control Number</p>
                        <p class="font-semibold text-gray-900">
                            <?php echo !empty($officer['control_number']) ? Security::escape($officer['control_number']) : '<span class="text-gray-400 italic">Not set</span>'; ?>
                        </p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Registry Number</p>
                        <p class="font-semibold text-gray-900">
                            <?php if (!empty($registryNumber)): ?>
                                <?php echo Security::escape($registryNumber); ?>
                                <?php if ($officer['tarheta_control_id']): ?>
                                    <a href="<?php echo BASE_URL; ?>/tarheta/list.php?search=<?php echo urlencode($registryNumber); ?>" 
                                       class="ml-2 text-xs text-blue-600 hover:text-blue-800" 
                                       title="View in Tarheta Control">
                                        <svg class="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                                        </svg>
                                    </a>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-gray-400 italic">Not set</span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Record Code</p>
                        <div class="flex items-center gap-2">
                            <?php if ($officer['record_code']): ?>
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium <?php echo $officer['record_code'] === 'A' ? 'bg-green-100 text-green-800' : 'bg-blue-100 text-blue-800'; ?>">
                                    CODE <?php echo $officer['record_code']; ?>
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-yellow-100 text-yellow-800">
                                    ⚠️ Not Set
                                </span>
                                <form method="POST" class="inline-block ml-2" onsubmit="return confirm('Set this officer as CODE A (New Record)?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                                    <input type="hidden" name="action" value="set_code">
                                    <button type="submit" class="text-xs px-2 py-1 bg-blue-600 text-white rounded hover:bg-blue-700 transition-colors">
                                        Set CODE A
                                    </button>
                                </form>
                            <?php endif; ?>
                            <?php if ($officer['is_imported']): ?>
                                <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-purple-100 text-purple-800" title="Imported from LORCAPP R-201 Database">
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
                    <div>
                        <p class="text-sm text-gray-600">Status</p>
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium <?php echo $officer['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                            <?php echo $officer['is_active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Added By</p>
                        <p class="font-semibold text-gray-900"><?php echo Security::escape($officer['created_by_name'] ?? 'System'); ?></p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600">Date Added</p>
                        <p class="font-semibold text-gray-900"><?php echo formatDateTime($officer['created_at']); ?></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Departments -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <div class="flex items-center space-x-3 mb-6">
            <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                </svg>
            </div>
            <h3 class="text-xl font-semibold text-gray-900">Departments & Duties</h3>
        </div>
        
        <?php if (empty($departments)): ?>
            <p class="text-center py-8 text-gray-600">No department assignments</p>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Department</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Duty</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Oath Date</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Assigned Date</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($departments as $dept): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 font-semibold text-gray-900"><?php echo Security::escape($dept['department']); ?></td>
                                <td class="px-4 py-3 text-gray-700"><?php echo Security::escape($dept['duty'] ?: '-'); ?></td>
                                <td class="px-4 py-3 text-gray-700"><?php echo formatDate($dept['oath_date']); ?></td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?php echo $dept['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                        <?php echo $dept['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-gray-700"><?php echo formatDateTime($dept['assigned_at']); ?></td>
                                <td class="px-4 py-3">
                                    <?php if ($dept['is_active']): ?>
                                        <button 
                                            data-dept-id="<?php echo $dept['id']; ?>"
                                            data-dept-name="<?php echo Security::escape($dept['department']); ?>"
                                            onclick="openRemoveDeptModal(this.dataset.deptId, this.dataset.deptName)"
                                            class="inline-flex items-center px-2 py-1 text-xs font-medium text-white bg-red-600 rounded hover:bg-red-700 transition-colors"
                                            title="Remove Department">
                                            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                                            </svg>
                                        </button>
                                    <?php else: ?>
                                        <span class="text-xs text-gray-500">Inactive</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Transfers -->
    <?php if (!empty($transfers)): ?>
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <div class="flex items-center space-x-3 mb-6">
            <div class="w-10 h-10 bg-indigo-100 rounded-lg flex items-center justify-center">
                <svg class="w-6 h-6 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
                </svg>
            </div>
            <h3 class="text-xl font-semibold text-gray-900">Transfer History</h3>
        </div>
        
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">From</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">To</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Transfer Date</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Week</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Processed By</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($transfers as $transfer): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?php echo $transfer['transfer_type'] === 'in' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                                    Transfer <?php echo strtoupper($transfer['transfer_type']); ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-gray-700"><?php echo Security::escape($transfer['from_local_code'] ?: '-'); ?></td>
                            <td class="px-4 py-3 text-gray-700"><?php echo Security::escape($transfer['to_local_code'] ?: '-'); ?></td>
                            <td class="px-4 py-3 text-gray-700"><?php echo formatDate($transfer['transfer_date']); ?></td>
                            <td class="px-4 py-3 text-gray-700">Week <?php echo $transfer['week_number']; ?>, <?php echo $transfer['year']; ?></td>
                            <td class="px-4 py-3 text-gray-700"><?php echo Security::escape($transfer['processed_by_name']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Removals -->
    <?php if (!empty($removals)): ?>
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <div class="flex items-center space-x-3 mb-6">
            <div class="w-10 h-10 bg-red-100 rounded-lg flex items-center justify-center">
                <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7a4 4 0 11-8 0 4 4 0 018 0zM9 14a6 6 0 00-6 6v1h12v-1a6 6 0 00-6-6zM21 12h-6"></path>
                </svg>
            </div>
            <h3 class="text-xl font-semibold text-gray-900">Removal History</h3>
            <?php if ($hasPendingRemovals): ?>
            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                <svg class="animate-spin h-3 w-3 mr-1" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                Processing
            </span>
            <?php endif; ?>
        </div>
        
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Code</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Reason</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Removal Date</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Week</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Processed By</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php 
                    $removalCodes = getRemovalCodes();
                    $statusConfig = [
                        'staged' => ['color' => 'gray', 'label' => 'Staged', 'icon' => ''],
                        'deliberated_by_local' => ['color' => 'yellow', 'label' => 'Deliberated by Local', 'icon' => 'animate-pulse'],
                        'requested' => ['color' => 'blue', 'label' => 'Requested to District', 'icon' => 'animate-pulse'],
                        'approved_by_district' => ['color' => 'green', 'label' => 'Approved', 'icon' => ''],
                        'completed' => ['color' => 'green', 'label' => 'Completed', 'icon' => '']
                    ];
                    foreach ($removals as $removal): 
                        $statusInfo = $statusConfig[$removal['status']] ?? ['color' => 'gray', 'label' => ucfirst(str_replace('_', ' ', $removal['status'])), 'icon' => ''];
                    ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-<?php echo $statusInfo['color']; ?>-100 text-<?php echo $statusInfo['color']; ?>-800 <?php echo $statusInfo['icon']; ?>">
                                    <?php if (!empty($statusInfo['icon'])): ?>
                                    <svg class="animate-spin h-3 w-3 mr-1" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    <?php endif; ?>
                                    <?php echo $statusInfo['label']; ?>
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800">CODE <?php echo $removal['removal_code']; ?></span>
                            </td>
                            <td class="px-4 py-3 text-gray-700"><?php echo Security::escape($removalCodes[$removal['removal_code']] ?? ''); ?></td>
                            <td class="px-4 py-3 text-gray-700"><?php echo formatDate($removal['removal_date']); ?></td>
                            <td class="px-4 py-3 text-gray-700">Week <?php echo $removal['week_number']; ?>, <?php echo $removal['year']; ?></td>
                            <td class="px-4 py-3 text-gray-700"><?php echo Security::escape($removal['processed_by_name']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Officer Requests History -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <div class="flex items-center space-x-3 mb-6">
            <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
            </div>
            <h3 class="text-xl font-semibold text-gray-900">Officer Requests</h3>
        </div>
        
        <div x-data="{ 
            loading: true, 
            requests: [],
            error: null,
            hasPendingRequests() {
                return this.requests.some(r => r.status !== 'oath_taken');
            },
            async fetchRequests() {
                try {
                    const response = await fetch('<?php echo BASE_URL; ?>/api/get-officer-requests.php?officer_uuid=<?php echo urlencode($officerUuid); ?>');
                    if (!response.ok) throw new Error('Failed to fetch requests');
                    const data = await response.json();
                    this.requests = data;
                    this.loading = false;
                } catch (err) {
                    this.error = err.message;
                    this.loading = false;
                }
            }
        }" x-init="fetchRequests()">
            
            <!-- Processing Badge -->
            <div x-show="!loading && !error && hasPendingRequests()" class="mb-4">
                <div class="inline-flex items-center px-3 py-1.5 bg-yellow-50 border border-yellow-200 rounded-lg">
                    <svg class="animate-spin h-4 w-4 text-yellow-600 mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span class="text-sm font-medium text-yellow-800">Some requests are still being processed</span>
                </div>
            </div>
            
            <!-- Loading State -->
            <div x-show="loading" class="flex flex-col items-center justify-center py-12">
                <div class="relative w-16 h-16">
                    <div class="absolute inset-0 border-4 border-blue-200 rounded-full"></div>
                    <div class="absolute inset-0 border-4 border-blue-600 rounded-full border-t-transparent animate-spin"></div>
                </div>
                <p class="mt-4 text-sm text-gray-600">Loading requests...</p>
            </div>
            
            <!-- Error State -->
            <div x-show="!loading && error" class="bg-red-50 border border-red-200 rounded-lg p-4">
                <div class="flex items-center">
                    <svg class="w-5 h-5 text-red-600 mr-3" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                    </svg>
                    <span class="text-sm text-red-800" x-text="error"></span>
                </div>
            </div>
            
            <!-- Empty State -->
            <div x-show="!loading && !error && requests.length === 0" class="text-center py-12">
                <svg class="w-16 h-16 mx-auto text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
                <p class="text-gray-500 font-medium">No officer requests found</p>
                <p class="text-sm text-gray-400 mt-1">This officer has no associated requests</p>
            </div>
            
            <!-- Requests Table -->
            <div x-show="!loading && !error && requests.length > 0" class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Request ID</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Department</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Requested Date</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <template x-for="request in requests" :key="request.request_id">
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3">
                                    <span class="text-sm font-medium text-gray-900" x-text="'#' + request.request_id"></span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="text-sm font-medium text-gray-900" x-text="request.requested_department"></div>
                                    <div class="text-xs text-gray-500" x-text="request.requested_duty" x-show="request.requested_duty"></div>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium" 
                                          :class="{
                                              'bg-yellow-100 text-yellow-800': request.status === 'pending',
                                              'bg-blue-100 text-blue-800': request.status === 'requested_to_seminar',
                                              'bg-indigo-100 text-indigo-800': request.status === 'in_seminar',
                                              'bg-green-100 text-green-800': request.status === 'seminar_completed' || request.status === 'oath_taken',
                                              'bg-purple-100 text-purple-800': request.status === 'requested_to_oath',
                                              'bg-pink-100 text-pink-800': request.status === 'ready_to_oath',
                                              'bg-red-100 text-red-800': request.status === 'rejected',
                                              'bg-gray-100 text-gray-800': request.status === 'cancelled'
                                          }">
                                        <!-- Animated spinner for pending statuses -->
                                        <template x-if="request.status !== 'oath_taken' && request.status !== 'rejected' && request.status !== 'cancelled'">
                                            <svg class="animate-spin h-3 w-3 mr-1" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                            </svg>
                                        </template>
                                        <span x-text="request.status_label"></span>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-700" x-text="request.requested_at_formatted"></td>
                                <td class="px-4 py-3">
                                    <a :href="'<?php echo BASE_URL; ?>/requests/view.php?id=' + request.request_id" 
                                       class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                                        View Details
                                    </a>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Remove Department Modal -->
<div x-data="{ show: false, deptId: '', deptName: '' }" 
     @open-remove-dept-modal.window="show = true; deptId = $event.detail.deptId; deptName = $event.detail.deptName"
     @keydown.escape.window="show = false"
     x-show="show"
     class="fixed inset-0 z-50 overflow-y-auto"
     style="display: none;">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div @click="show = false" class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity"></div>
        <div @click.stop class="relative bg-white rounded-lg shadow-xl max-w-2xl w-full p-6 transform transition-all"
             x-show="show"
             x-transition:enter="ease-out duration-300"
             x-transition:enter-start="opacity-0 translate-y-4"
             x-transition:enter-end="opacity-100 translate-y-0"
             x-transition:leave="ease-in duration-200"
             x-transition:leave-start="opacity-100 translate-y-0"
             x-transition:leave-end="opacity-0 translate-y-4">
            
            <div class="flex items-center justify-between mb-4">
                <h3 class="flex items-center text-lg font-bold text-gray-900">
                    <svg class="w-6 h-6 text-red-600 mr-2" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                    </svg>
                    Remove Department
                </h3>
                <button @click="show = false" type="button" class="text-gray-400 hover:text-gray-500">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
        
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
            <input type="hidden" name="action" value="remove_department">
            <input type="hidden" name="department_id" x-model="deptId">
            
            <div class="space-y-4">
                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                    <div class="flex items-start">
                        <svg class="w-5 h-5 text-yellow-600 mr-3 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                        </svg>
                        <div>
                            <p class="font-semibold text-yellow-800">You are about to remove:</p>
                            <p x-text="deptName" class="text-sm text-yellow-700"></p>
                        </div>
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-3">
                        Removal Code <span class="text-red-600">*</span>
                    </label>
                    <div class="space-y-2">
                        <label class="flex items-start p-3 bg-gray-50 rounded-lg border border-gray-200 cursor-pointer hover:bg-gray-100 transition-colors">
                            <input type="radio" name="removal_code" value="C" class="mt-1 h-4 w-4 text-red-600 focus:ring-red-500 border-gray-300" required>
                            <div class="ml-3">
                                <span class="font-semibold text-gray-900">CODE C</span>
                                <p class="text-xs text-gray-600 mt-1">Inalis sa karapatan - Suspendido (Suspended from position)</p>
                            </div>
                        </label>
                        
                        <label class="flex items-start p-3 bg-gray-50 rounded-lg border border-gray-200 cursor-pointer hover:bg-gray-100 transition-colors">
                            <input type="radio" name="removal_code" value="D" class="mt-1 h-4 w-4 text-red-600 focus:ring-red-500 border-gray-300" required>
                            <div class="ml-3">
                                <span class="font-semibold text-gray-900">CODE D</span>
                                <p class="text-xs text-gray-600 mt-1">Lipat Kapisanan (Transfer to another CFO)</p>
                            </div>
                        </label>
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Additional Reason (Optional)</label>
                    <textarea 
                        name="removal_reason" 
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 h-24 resize-none"
                        placeholder="Enter additional details or notes about this removal..."
                    ></textarea>
                </div>
                
                <div class="bg-red-50 border border-red-200 rounded-lg p-4">
                    <div class="flex items-start">
                        <svg class="w-5 h-5 text-red-600 mr-3 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                        </svg>
                        <span class="text-sm text-red-800">This will deactivate the department assignment and create a removal record. This action cannot be undone.</span>
                    </div>
                </div>
            </div>
            
            <div class="flex justify-end space-x-3 mt-6">
                <button type="button" @click="show = false" class="px-4 py-2 border border-gray-300 rounded-lg font-medium text-gray-700 bg-white hover:bg-gray-50 transition-colors">Cancel</button>
                <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-lg font-medium text-white bg-red-600 hover:bg-red-700 transition-colors">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                    Confirm Removal
                </button>
            </div>
        </form>
        </div>
    </div>
</div>

<script>
function openRemoveDeptModal(deptId, deptName) {
    window.dispatchEvent(new CustomEvent('open-remove-dept-modal', { 
        detail: { deptId: deptId, deptName: deptName }
    }));
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
