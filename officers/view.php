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

// Handle direct duty deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_duty') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!Security::validateCSRFToken($csrfToken)) {
        setFlashMessage('error', 'Invalid security token.');
    } else {
        $departmentId = intval($_POST['department_id'] ?? 0);
        
        if (empty($departmentId)) {
            setFlashMessage('error', 'Department ID is required.');
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
                
                // Actually delete the duty record from database
                $stmt = $db->prepare("
                    DELETE FROM officer_departments 
                    WHERE id = ?
                ");
                $stmt->execute([$departmentId]);
                
                // Log audit
                $stmt = $db->prepare("
                    INSERT INTO audit_log (user_id, action, table_name, record_id, ip_address, user_agent)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $currentUser['user_id'],
                    'delete_duty',
                    'officer_departments',
                    $departmentId,
                    $_SERVER['REMOTE_ADDR'],
                    $_SERVER['HTTP_USER_AGENT'] ?? ''
                ]);
                
                $db->commit();
                setFlashMessage('success', 'Duty deleted successfully!');
                redirect(BASE_URL . '/officers/view.php?id=' . $officerUuid);
                
            } catch (Exception $e) {
                $db->rollBack();
                error_log("Delete duty error: " . $e->getMessage());
                setFlashMessage('error', 'Error deleting duty: ' . $e->getMessage());
            }
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
        SELECT 
            od.*,
            r.removal_code,
            r.reason as removal_reason,
            t.transfer_type,
            t.transfer_date
        FROM officer_departments od
        LEFT JOIN officer_removals r ON r.department_id = od.id AND r.officer_id = od.officer_id
        LEFT JOIN transfers t ON t.officer_id = od.officer_id AND t.transfer_type = 'out'
        WHERE od.officer_id = ? 
        ORDER BY od.assigned_at DESC
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
$csp_nonce = base64_encode(random_bytes(16));
ob_start();
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <a href="<?php echo BASE_URL; ?>/officers/list.php" class="inline-flex items-center px-3 py-1.5 text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors mb-2">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                </svg>
                Back to List
            </a>
            <h2 class="text-3xl font-bold text-gray-900 dark:text-gray-100">Officer Profile</h2>
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
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
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
                    <h3 class="text-3xl font-bold text-gray-900 dark:text-gray-100 cursor-pointer name-mono" 
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
                    <p class="text-sm text-gray-600 dark:text-gray-400">UUID: <?php echo Security::escape($officer['officer_uuid']); ?></p>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <p class="text-sm text-gray-600 dark:text-gray-400">District</p>
                        <p class="font-semibold text-gray-900 dark:text-gray-100"><?php echo Security::escape($officer['district_name']); ?></p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Local Congregation</p>
                        <p class="font-semibold text-gray-900 dark:text-gray-100"><?php echo Security::escape($officer['local_name']); ?></p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Purok</p>
                        <p class="font-semibold text-gray-900 dark:text-gray-100">
                            <?php echo !empty($officer['purok']) ? Security::escape($officer['purok']) : '<span class="text-gray-400 italic">Not set</span>'; ?>
                        </p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Grupo</p>
                        <p class="font-semibold text-gray-900 dark:text-gray-100">
                            <?php echo !empty($officer['grupo']) ? Security::escape($officer['grupo']) : '<span class="text-gray-400 italic">Not set</span>'; ?>
                        </p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Control Number</p>
                        <p class="font-semibold text-gray-900 dark:text-gray-100">
                            <?php echo !empty($officer['control_number']) ? Security::escape($officer['control_number']) : '<span class="text-gray-400 italic">Not set</span>'; ?>
                        </p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Registry Number</p>
                        <p class="font-semibold text-gray-900 dark:text-gray-100">
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
                        <p class="text-sm text-gray-600 dark:text-gray-400">Record Code</p>
                        <div class="flex items-center gap-2">
                            <?php if ($officer['record_code']): ?>
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium <?php echo $officer['record_code'] === 'A' ? 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-400' : 'bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-400'; ?>">
                                    CODE <?php echo $officer['record_code']; ?>
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-400">
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
                                <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-purple-100 dark:bg-purple-900/30 text-purple-800 dark:text-purple-400" title="Imported from LORCAPP R-201 Database">
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
                        <p class="text-sm text-gray-600 dark:text-gray-400">Status</p>
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium <?php echo $officer['is_active'] ? 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-400' : 'bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-400'; ?>">
                            <?php echo $officer['is_active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Added By</p>
                        <p class="font-semibold text-gray-900 dark:text-gray-100"><?php echo Security::escape($officer['created_by_name'] ?? 'System'); ?></p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Date Added</p>
                        <p class="font-semibold text-gray-900 dark:text-gray-100"><?php echo formatDateTime($officer['created_at']); ?></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Departments -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <div class="flex items-center space-x-3 mb-6">
            <div class="w-10 h-10 bg-blue-100 dark:bg-blue-900/30 rounded-lg flex items-center justify-center">
                <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                </svg>
            </div>
            <h3 class="text-xl font-semibold text-gray-900 dark:text-gray-100">Departments & Duties</h3>
        </div>
        
        <?php if (empty($departments)): ?>
            <p class="text-center py-8 text-gray-600 dark:text-gray-400">No department assignments</p>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Department</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Duty</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Oath Date</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Assigned Date</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        <?php foreach ($departments as $dept): ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                <td class="px-4 py-3 font-semibold text-gray-900 dark:text-gray-100"><?php echo Security::escape($dept['department']); ?></td>
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-300"><?php echo Security::escape($dept['duty'] ?: '-'); ?></td>
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-300"><?php echo formatDate($dept['oath_date']); ?></td>
                                <td class="px-4 py-3">
                                    <?php 
                                    if ($dept['is_active']) {
                                        echo '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-400">Active</span>';
                                    } else {
                                        // Smart logic: Check transfers first, then removal codes
                                        if ($dept['transfer_type'] === 'out' && !empty($dept['transfer_date'])) {
                                            echo '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-400">TRANSFERRED-OUT</span>';
                                        } elseif ($dept['removal_code'] === 'C') {
                                            echo '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-400">SUSPENDIDO (CODE-C)</span>';
                                        } elseif ($dept['removal_code'] === 'D') {
                                            echo '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-orange-100 dark:bg-orange-900/30 text-orange-800 dark:text-orange-400">LIPAT-KAPISANAN (CODE-D)</span>';
                                        } elseif (!empty($dept['removal_reason']) && stripos($dept['removal_reason'], 'transfer') !== false) {
                                            echo '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-400">TRANSFERRED-OUT</span>';
                                        } else {
                                            echo '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-300">Inactive</span>';
                                        }
                                    }
                                    ?>
                                </td>
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-300"><?php echo formatDateTime($dept['assigned_at']); ?></td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center space-x-2">
                                        <!-- Direct Delete Button -->
                                        <button 
                                            type="button"
                                            data-dept-id="<?php echo $dept['id']; ?>"
                                            data-dept-name="<?php echo Security::escape($dept['department'] . ' - ' . ($dept['duty'] ?: 'No Duty')); ?>"
                                            onclick="openDeleteDutyModal(this.dataset.deptId, this.dataset.deptName)"
                                            class="inline-flex items-center px-2 py-1 text-xs font-medium text-white bg-red-600 rounded hover:bg-red-700 transition-colors"
                                            title="Delete Duty">
                                            <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                            </svg>
                                            Delete
                                        </button>
                                        
                                        <!-- Remove Department Button (with reason) - only for active -->
                                        <?php if ($dept['is_active']): ?>
                                        <button 
                                            type="button"
                                            data-dept-id="<?php echo $dept['id']; ?>"
                                            data-dept-name="<?php echo Security::escape($dept['department']); ?>"
                                            onclick="openRemoveDeptModal(this.dataset.deptId, this.dataset.deptName)"
                                            class="inline-flex items-center px-2 py-1 text-xs font-medium text-white bg-orange-600 rounded hover:bg-orange-700 transition-colors"
                                            title="Remove with Reason (CODE C/D)">
                                            <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                                            </svg>
                                            Remove
                                        </button>
                                        <?php endif; ?>
                                    </div>
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
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 p-6">
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
                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200">
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
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 p-6">
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
                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200">
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
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <div class="flex items-center space-x-3 mb-6">
            <div class="w-10 h-10 bg-blue-100 dark:bg-blue-900/30 rounded-lg flex items-center justify-center">
                <svg class="w-6 h-6 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
            </div>
            <h3 class="text-xl font-semibold text-gray-900 dark:text-gray-100">Officer Requests</h3>
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
                <div class="inline-flex items-center px-3 py-1.5 bg-yellow-50 dark:bg-yellow-900/30 border border-yellow-200 dark:border-yellow-700 rounded-lg">
                    <svg class="animate-spin h-4 w-4 text-yellow-600 mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span class="text-sm font-medium text-yellow-800 dark:text-yellow-400">Some requests are still being processed</span>
                </div>
            </div>
            
            <!-- Loading State -->
            <div x-show="loading" class="flex flex-col items-center justify-center py-12">
                <div class="relative w-16 h-16">
                    <div class="absolute inset-0 border-4 border-blue-200 dark:border-blue-800 rounded-full"></div>
                    <div class="absolute inset-0 border-4 border-blue-600 dark:border-blue-400 rounded-full border-t-transparent animate-spin"></div>
                </div>
                <p class="mt-4 text-sm text-gray-600 dark:text-gray-400">Loading requests...</p>
            </div>
            
            <!-- Error State -->
            <div x-show="!loading && error" class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
                <div class="flex items-center">
                    <svg class="w-5 h-5 text-red-600 mr-3" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                    </svg>
                    <span class="text-sm text-red-800 dark:text-red-400" x-text="error"></span>
                </div>
            </div>
            
            <!-- Empty State -->
            <div x-show="!loading && !error && requests.length === 0" class="text-center py-12">
                <svg class="w-16 h-16 mx-auto text-gray-300 dark:text-gray-600 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
                <p class="text-gray-500 dark:text-gray-400 font-medium">No officer requests found</p>
                <p class="text-sm text-gray-400 dark:text-gray-500 mt-1">This officer has no associated requests</p>
            </div>
            
            <!-- Requests Table -->
            <div x-show="!loading && !error && requests.length > 0" class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Request ID</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Department</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Requested Date</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        <template x-for="request in requests" :key="request.request_id">
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                <td class="px-4 py-3">
                                    <span class="text-sm font-medium text-gray-900 dark:text-gray-100" x-text="'#' + request.request_id"></span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="text-sm font-medium text-gray-900 dark:text-gray-100" x-text="request.requested_department"></div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400" x-text="request.requested_duty" x-show="request.requested_duty"></div>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium" 
                                          :class="{
                                              'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-400': request.status === 'pending',
                                              'bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-400': request.status === 'requested_to_seminar',
                                              'bg-indigo-100 dark:bg-indigo-900/30 text-indigo-800 dark:text-indigo-400': request.status === 'in_seminar',
                                              'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-400': request.status === 'seminar_completed' || request.status === 'oath_taken',
                                              'bg-purple-100 dark:bg-purple-900/30 text-purple-800 dark:text-purple-400': request.status === 'requested_to_oath',
                                              'bg-pink-100 dark:bg-pink-900/30 text-pink-800 dark:text-pink-400': request.status === 'ready_to_oath',
                                              'bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-400': request.status === 'rejected',
                                              'bg-gray-100 dark:bg-gray-900/30 text-gray-800 dark:text-gray-400': request.status === 'cancelled'
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
                                <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300" x-text="request.requested_at_formatted"></td>
                                <td class="px-4 py-3">
                                    <button type="button"
                                        @click="$dispatch('open-request-modal', { requestId: request.request_id })"
                                        class="text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 text-sm font-medium">
                                        View Details
                                    </button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Request Details Modal -->
    <div x-data="requestModalData()" 
         @open-request-modal.window="openModal($event.detail.requestId)"
         @keydown.escape.window="closeModal()"
         x-show="showModal"
         class="fixed inset-0 z-50 overflow-y-auto"
         style="display: none;">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div @click="closeModal()" class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity"></div>
            <div @click.stop class="relative bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-4xl w-full max-h-[90vh] overflow-hidden flex flex-col transform transition-all"
                 x-show="showModal"
                 x-transition:enter="ease-out duration-300"
                 x-transition:enter-start="opacity-0 translate-y-4"
                 x-transition:enter-end="opacity-100 translate-y-0"
                 x-transition:leave="ease-in duration-200"
                 x-transition:leave-start="opacity-100 translate-y-0"
                 x-transition:leave-end="opacity-0 translate-y-4">
                
                <!-- Modal Header -->
                <div class="flex items-center justify-between p-6 border-b border-gray-200">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                            <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-xl font-semibold text-gray-900">Request Details</h3>
                            <p class="text-sm text-gray-500" x-text="requestData ? '#' + requestData.request_id : ''"></p>
                        </div>
                    </div>
                    <button type="button" @click="closeModal()" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                
                <!-- Loading State -->
                <div x-show="loading" class="flex items-center justify-center p-12">
                    <div class="relative w-16 h-16">
                        <div class="absolute inset-0 border-4 border-blue-200 rounded-full"></div>
                        <div class="absolute inset-0 border-4 border-blue-600 rounded-full border-t-transparent animate-spin"></div>
                    </div>
                </div>
                
                <!-- Tabs and Content -->
                <div x-show="!loading && requestData" class="flex-1 overflow-hidden flex flex-col">
                    <!-- Tab Navigation -->
                    <div class="border-b border-gray-200 px-6">
                        <nav class="-mb-px flex space-x-8">
                            <button type="button"
                                @click="activeTab = 'overview'"
                                :class="activeTab === 'overview' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                                class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors">
                                Overview
                            </button>
                            <button type="button"
                                @click="activeTab = 'requirements'"
                                :class="activeTab === 'requirements' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                                class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors flex items-center">
                                <span>Requirements</span>
                                <span x-show="requestData && getCompletedRequirements() < 7" 
                                      class="ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                    <span x-text="getCompletedRequirements()"></span>/<span>7</span>
                                </span>
                                <span x-show="requestData && getCompletedRequirements() === 7" 
                                      class="ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                    Complete
                                </span>
                            </button>
                            <button type="button"
                                @click="activeTab = 'history'"
                                :class="activeTab === 'history' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                                class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors">
                                History
                            </button>
                        </nav>
                    </div>
                    
                    <!-- Tab Content -->
                    <div class="flex-1 overflow-y-auto p-6">
                        <!-- Overview Tab -->
                        <div x-show="activeTab === 'overview'" class="space-y-6">
                            <div class="grid grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Department</label>
                                    <p class="text-base text-gray-900" x-text="requestData?.requested_department"></p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Duty</label>
                                    <p class="text-base text-gray-900" x-text="requestData?.requested_duty || 'N/A'"></p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                                    <span class="inline-flex items-center px-2.5 py-1 rounded text-sm font-medium" 
                                          :class="{
                                              'bg-yellow-100 text-yellow-800': requestData?.status === 'pending',
                                              'bg-blue-100 text-blue-800': requestData?.status === 'requested_to_seminar',
                                              'bg-indigo-100 text-indigo-800': requestData?.status === 'in_seminar',
                                              'bg-green-100 text-green-800': requestData?.status === 'seminar_completed' || requestData?.status === 'oath_taken',
                                              'bg-purple-100 text-purple-800': requestData?.status === 'requested_to_oath',
                                              'bg-pink-100 text-pink-800': requestData?.status === 'ready_to_oath',
                                              'bg-red-100 text-red-800': requestData?.status === 'rejected',
                                              'bg-gray-100 text-gray-800': requestData?.status === 'cancelled'
                                          }"
                                          x-text="requestData?.status_label">
                                    </span>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Requested Date</label>
                                    <p class="text-base text-gray-900" x-text="requestData?.requested_at_formatted"></p>
                                </div>
                            </div>
                            
                            <div class="flex items-center justify-between pt-4 border-t">
                                <a :href="'<?php echo BASE_URL; ?>/requests/view.php?id=' + (requestData?.request_id || '')" 
                                   target="_blank"
                                   class="text-sm text-blue-600 hover:text-blue-800 font-medium">
                                    Open Full Request Page →
                                </a>
                            </div>
                        </div>
                        
                        <!-- Requirements Checklist Tab -->
                        <div x-show="activeTab === 'requirements'" class="space-y-4">
                            <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4 mb-6">
                                <div class="flex items-center">
                                    <svg class="w-5 h-5 text-blue-600 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                                    </svg>
                                    <p class="text-sm text-blue-800">Track document submission progress. Check items as they are submitted.</p>
                                </div>
                            </div>
                            
                            <div class="space-y-3">
                                <!-- R5-15/04 -->
                                <div class="flex items-center justify-between p-4 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg hover:border-blue-300 dark:hover:border-blue-600 transition-colors">
                                    <div class="flex items-center space-x-3">
                                        <input type="checkbox" 
                                               :checked="requestData?.has_r515" 
                                               @change="toggleRequirement('has_r515', $event.target.checked)"
                                               class="w-5 h-5 text-blue-600 rounded">
                                        <div>
                                            <p class="font-medium text-gray-900 dark:text-gray-100">R5-15/04</p>
                                            <p class="text-sm text-gray-500 dark:text-gray-400">Officer Application Form</p>
                                        </div>
                                    </div>
                                    <span x-show="requestData?.has_r515" class="text-green-600 dark:text-green-400">
                                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                        </svg>
                                    </span>
                                </div>
                                
                                <!-- Patotoo ng Katiwala -->
                                <div class="flex items-center justify-between p-4 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg hover:border-blue-300 dark:hover:border-blue-600 transition-colors">
                                    <div class="flex items-center space-x-3">
                                        <input type="checkbox" 
                                               :checked="requestData?.has_patotoo_katiwala" 
                                               @change="toggleRequirement('has_patotoo_katiwala', $event.target.checked)"
                                               class="w-5 h-5 text-blue-600 rounded">
                                        <div>
                                            <p class="font-medium text-gray-900 dark:text-gray-100">Patotoo ng Katiwala</p>
                                            <p class="text-sm text-gray-500 dark:text-gray-400">Recommendation from Katiwala</p>
                                        </div>
                                    </div>
                                    <span x-show="requestData?.has_patotoo_katiwala" class="text-green-600 dark:text-green-400">
                                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                        </svg>
                                    </span>
                                </div>
                                
                                <!-- Patotoo ng Kapisanan -->
                                <div class="flex items-center justify-between p-4 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg hover:border-blue-300 dark:hover:border-blue-600 transition-colors">
                                    <div class="flex items-center space-x-3">
                                        <input type="checkbox" 
                                               :checked="requestData?.has_patotoo_kapisanan" 
                                               @change="toggleRequirement('has_patotoo_kapisanan', $event.target.checked)"
                                               class="w-5 h-5 text-blue-600 rounded">
                                        <div>
                                            <p class="font-medium text-gray-900 dark:text-gray-100">Patotoo ng Kapisanan</p>
                                            <p class="text-sm text-gray-500 dark:text-gray-400">Recommendation from Organization</p>
                                        </div>
                                    </div>
                                    <span x-show="requestData?.has_patotoo_kapisanan" class="text-green-600 dark:text-green-400">
                                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                        </svg>
                                    </span>
                                </div>
                                
                                <!-- Salaysay ng Magulang -->
                                <div class="flex items-center justify-between p-4 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg hover:border-blue-300 dark:hover:border-blue-600 transition-colors">
                                    <div class="flex items-center space-x-3">
                                        <input type="checkbox" 
                                               :checked="requestData?.has_salaysay_magulang" 
                                               @change="toggleRequirement('has_salaysay_magulang', $event.target.checked)"
                                               class="w-5 h-5 text-blue-600 rounded">
                                        <div>
                                            <p class="font-medium text-gray-900 dark:text-gray-100">Salaysay ng Magulang</p>
                                            <p class="text-sm text-gray-500 dark:text-gray-400">Parent's Statement (if applicable)</p>
                                        </div>
                                    </div>
                                    <span x-show="requestData?.has_salaysay_magulang" class="text-green-600 dark:text-green-400">
                                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                        </svg>
                                    </span>
                                </div>
                                
                                <!-- Salaysay ng Pagtanggap -->
                                <div class="flex items-center justify-between p-4 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg hover:border-blue-300 dark:hover:border-blue-600 transition-colors">
                                    <div class="flex items-center space-x-3">
                                        <input type="checkbox" 
                                               :checked="requestData?.has_salaysay_pagtanggap" 
                                               @change="toggleRequirement('has_salaysay_pagtanggap', $event.target.checked)"
                                               class="w-5 h-5 text-blue-600 rounded">
                                        <div>
                                            <p class="font-medium text-gray-900 dark:text-gray-100">Salaysay ng Pagtanggap</p>
                                            <p class="text-sm text-gray-500 dark:text-gray-400">Acceptance Statement</p>
                                        </div>
                                    </div>
                                    <span x-show="requestData?.has_salaysay_pagtanggap" class="text-green-600 dark:text-green-400">
                                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                        </svg>
                                    </span>
                                </div>
                                
                                <!-- R5-13 Seminar (Auto-complete) -->
                                <div class="flex items-center justify-between p-4 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg"
                                     :class="requestData?.status === 'seminar_completed' || requestData?.status === 'requested_to_oath' || requestData?.status === 'ready_to_oath' || requestData?.status === 'oath_taken' ? 'border-green-300 dark:border-green-600 bg-green-50 dark:bg-green-900/20' : 'border-gray-200 dark:border-gray-700'">
                                    <div class="flex items-center space-x-3">
                                        <input type="checkbox" 
                                               :checked="requestData?.status === 'seminar_completed' || requestData?.status === 'requested_to_oath' || requestData?.status === 'ready_to_oath' || requestData?.status === 'oath_taken'" 
                                               disabled
                                               class="w-5 h-5 text-blue-600 rounded cursor-not-allowed">
                                        <div>
                                            <p class="font-medium text-gray-900 dark:text-gray-100">R5-13 Seminar Certificate</p>
                                            <p class="text-sm" 
                                               :class="requestData?.status === 'seminar_completed' || requestData?.status === 'requested_to_oath' || requestData?.status === 'ready_to_oath' || requestData?.status === 'oath_taken' ? 'text-green-600 dark:text-green-400' : 'text-gray-500 dark:text-gray-400'">
                                                <span x-show="requestData?.status === 'seminar_completed' || requestData?.status === 'requested_to_oath' || requestData?.status === 'ready_to_oath' || requestData?.status === 'oath_taken'">✓ Auto-completed when seminar is finished</span>
                                                <span x-show="!(requestData?.status === 'seminar_completed' || requestData?.status === 'requested_to_oath' || requestData?.status === 'ready_to_oath' || requestData?.status === 'oath_taken')">Will be auto-checked when seminar is completed</span>
                                            </p>
                                        </div>
                                    </div>
                                    <span x-show="requestData?.status === 'seminar_completed' || requestData?.status === 'requested_to_oath' || requestData?.status === 'ready_to_oath' || requestData?.status === 'oath_taken'" class="text-green-600 dark:text-green-400">
                                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                        </svg>
                                    </span>
                                </div>
                                
                                <!-- 2x2 Picture -->
                                <div class="flex items-center justify-between p-4 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg hover:border-blue-300 dark:hover:border-blue-600 transition-colors">
                                    <div class="flex items-center space-x-3">
                                        <input type="checkbox" 
                                               :checked="requestData?.has_picture" 
                                               @change="toggleRequirement('has_picture', $event.target.checked)"
                                               class="w-5 h-5 text-blue-600 rounded">
                                        <div>
                                            <p class="font-medium text-gray-900 dark:text-gray-100">2x2 Picture</p>
                                            <p class="text-sm text-gray-500 dark:text-gray-400">Recent 2x2 ID photo</p>
                                        </div>
                                    </div>
                                    <span x-show="requestData?.has_picture" class="text-green-600 dark:text-green-400">
                                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                        </svg>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg p-4 mt-6">
                                <div class="flex items-center justify-between">
                                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Progress</span>
                                    <span class="text-sm font-semibold" :class="getCompletedRequirements() === 7 ? 'text-green-600 dark:text-green-400' : 'text-gray-900 dark:text-gray-100'" x-text="getCompletedRequirements() + ' / 7 Complete'"></span>
                                </div>
                                <div class="mt-2 w-full bg-gray-200 dark:bg-gray-600 rounded-full h-2">
                                    <div class="bg-blue-600 h-2 rounded-full transition-all duration-300" 
                                         :style="`width: ${(getCompletedRequirements() / 7) * 100}%`"></div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- History Tab -->
                        <div x-show="activeTab === 'history'" class="space-y-4">
                            <div class="text-center py-8 text-gray-500 dark:text-gray-400">
                                <svg class="w-12 h-12 mx-auto mb-3 text-gray-300 dark:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                <p class="font-medium">Activity history coming soon</p>
                                <p class="text-sm mt-1">Track all changes and updates to this request</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script nonce="<?php echo $csp_nonce; ?>">
    function requestModalData() {
        return {
            showModal: false,
            loading: false,
            activeTab: 'overview',
            requestData: null,
            
            async openModal(requestId) {
                this.showModal = true;
                this.loading = true;
                this.activeTab = 'overview';
                
                try {
                    const response = await fetch(`<?php echo BASE_URL; ?>/api/get-officer-requests.php?id=${requestId}`);
                    const data = await response.json();
                    this.requestData = data;
                } catch (error) {
                    console.error('Error loading request:', error);
                } finally {
                    this.loading = false;
                }
            },
            
            closeModal() {
                this.showModal = false;
                this.requestData = null;
            },
            
            getCompletedRequirements() {
                if (!this.requestData) return 0;
                
                let count = 0;
                if (this.requestData.has_r515) count++;
                if (this.requestData.has_patotoo_katiwala) count++;
                if (this.requestData.has_patotoo_kapisanan) count++;
                if (this.requestData.has_salaysay_magulang) count++;
                if (this.requestData.has_salaysay_pagtanggap) count++;
                if (this.requestData.has_picture) count++;
                // R5-13 auto-check
                if (this.requestData.status === 'seminar_completed' || 
                    this.requestData.status === 'requested_to_oath' || 
                    this.requestData.status === 'ready_to_oath' || 
                    this.requestData.status === 'oath_taken') {
                    count++;
                }
                
                return count;
            },
            
            async toggleRequirement(field, value) {
                try {
                    const response = await fetch(`<?php echo BASE_URL; ?>/api/update-request-requirement.php`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            request_id: this.requestData.request_id,
                            field: field,
                            value: value
                        })
                    });
                    
                    if (response.ok) {
                        this.requestData[field] = value;
                    }
                } catch (error) {
                    console.error('Error updating requirement:', error);
                }
            }
        }
    }
    </script>

    <!-- Call-Up Slips Section -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 bg-yellow-100 dark:bg-yellow-900/30 rounded-lg flex items-center justify-center">
                    <svg class="w-6 h-6 text-yellow-600 dark:text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                    </svg>
                </div>
                <h3 class="text-xl font-semibold text-gray-900 dark:text-gray-100">Call-Up Slips (Tawag-Pansin)</h3>
            </div>
            <a href="<?php echo BASE_URL; ?>/officers/call-up.php?officer_uuid=<?php echo urlencode($officerUuid); ?>" 
               class="inline-flex items-center px-3 py-1.5 text-sm font-medium text-white bg-yellow-600 rounded-lg hover:bg-yellow-700 transition-colors">
                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                </svg>
                Create Call-Up
            </a>
        </div>
        
        <?php
        // Fetch call-up slips for this officer
        $stmt = $db->prepare("
            SELECT 
                c.slip_id,
                c.file_number,
                c.department,
                c.reason,
                c.issue_date,
                c.deadline_date,
                c.status,
                c.response_date,
                u.full_name as prepared_by_name
            FROM call_up_slips c
            LEFT JOIN users u ON c.prepared_by = u.user_id
            WHERE c.officer_id = ?
            ORDER BY c.issue_date DESC, c.slip_id DESC
        ");
        $stmt->execute([$officer['officer_id']]);
        $callUpSlips = $stmt->fetchAll();
        ?>
        
        <?php if (empty($callUpSlips)): ?>
            <div class="text-center py-12">
                <svg class="w-16 h-16 mx-auto text-gray-300 dark:text-gray-600 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
                <p class="text-gray-500 dark:text-gray-400 font-medium">No call-up slips issued</p>
                <p class="text-sm text-gray-400 dark:text-gray-500 mt-1">This officer has no call-up notices</p>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">File Number</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Department</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Reason</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Issue Date</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Deadline</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Prepared By</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        <?php foreach ($callUpSlips as $slip): 
                            // Determine status styling
                            $statusConfig = [
                                'issued' => ['color' => 'yellow', 'label' => 'Issued', 'icon' => true],
                                'responded' => ['color' => 'green', 'label' => 'Responded', 'icon' => false],
                                'expired' => ['color' => 'red', 'label' => 'Expired', 'icon' => false],
                                'cancelled' => ['color' => 'gray', 'label' => 'Cancelled', 'icon' => false]
                            ];
                            $statusInfo = $statusConfig[$slip['status']] ?? ['color' => 'gray', 'label' => ucfirst($slip['status']), 'icon' => false];
                            
                            // Check if expired (past deadline and still issued)
                            $isExpired = ($slip['status'] === 'issued' && strtotime($slip['deadline_date']) < time());
                            if ($isExpired) {
                                $statusInfo = ['color' => 'red', 'label' => 'Expired', 'icon' => false];
                            }
                        ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                <td class="px-4 py-3">
                                    <span class="text-sm font-medium text-gray-900 dark:text-gray-100"><?php echo Security::escape($slip['file_number']); ?></span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="text-sm text-gray-900 dark:text-gray-100"><?php echo Security::escape($slip['department']); ?></span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="text-sm text-gray-700 dark:text-gray-300 max-w-xs truncate" title="<?php echo Security::escape($slip['reason']); ?>">
                                        <?php echo Security::escape($slip['reason']); ?>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300"><?php echo formatDate($slip['issue_date']); ?></td>
                                <td class="px-4 py-3">
                                    <span class="text-sm text-gray-700 dark:text-gray-300"><?php echo formatDate($slip['deadline_date']); ?></span>
                                    <?php if ($isExpired): ?>
                                        <span class="ml-1 text-xs text-red-600 dark:text-red-400 font-medium">(Overdue)</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-<?php echo $statusInfo['color']; ?>-100 dark:bg-<?php echo $statusInfo['color']; ?>-900/30 text-<?php echo $statusInfo['color']; ?>-800 dark:text-<?php echo $statusInfo['color']; ?>-400">
                                        <?php if ($statusInfo['icon']): ?>
                                            <svg class="animate-pulse h-3 w-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                                            </svg>
                                        <?php endif; ?>
                                        <?php echo $statusInfo['label']; ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300"><?php echo Security::escape($slip['prepared_by_name']); ?></td>
                                <td class="px-4 py-3">
                                    <a href="<?php echo BASE_URL; ?>/officers/generate-call-up-pdf.php?slip_id=<?php echo $slip['slip_id']; ?>" 
                                       target="_blank"
                                       class="inline-flex items-center px-2 py-1 text-xs font-medium text-white bg-blue-600 rounded hover:bg-blue-700 transition-colors"
                                       title="View PDF">
                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                        </svg>
                                        View
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Delete Duty Confirmation Modal -->
<div x-data="{ show: false, deptId: '', deptName: '' }" 
     @open-delete-duty-modal.window="show = true; deptId = $event.detail.deptId; deptName = $event.detail.deptName"
     @keydown.escape.window="show = false"
     x-show="show"
     class="fixed inset-0 z-50 overflow-y-auto"
     style="display: none;">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div @click="show = false" class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity"></div>
        <div @click.stop class="relative bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-md w-full p-6 transform transition-all"
             x-show="show"
             x-transition:enter="ease-out duration-300"
             x-transition:enter-start="opacity-0 translate-y-4"
             x-transition:enter-end="opacity-100 translate-y-0"
             x-transition:leave="ease-in duration-200"
             x-transition:leave-start="opacity-100 translate-y-0"
             x-transition:leave-end="opacity-0 translate-y-4">
            
            <div class="text-center">
                <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-red-100 mb-4">
                    <svg class="h-6 w-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                    </svg>
                </div>
                
                <h3 class="text-lg font-bold text-gray-900 mb-2">Delete Duty</h3>
                <p class="text-sm text-gray-600 mb-4">Are you sure you want to delete this duty?</p>
                
                <div class="bg-red-50 border border-red-200 rounded-lg p-3 mb-6 text-left">
                    <p class="text-sm font-medium text-red-800" x-text="deptName"></p>
                </div>
                
                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-3 mb-6">
                    <div class="flex items-start text-left">
                        <svg class="w-5 h-5 text-yellow-600 mr-2 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                        </svg>
                        <span class="text-sm text-yellow-800">This action cannot be undone. The duty will be permanently removed.</span>
                    </div>
                </div>
                
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="delete_duty">
                    <input type="hidden" name="department_id" x-model="deptId">
                    
                    <div class="flex justify-center space-x-3">
                        <button type="button" @click="show = false" class="px-4 py-2 border border-gray-300 rounded-lg font-medium text-gray-700 bg-white dark:bg-gray-800 hover:bg-gray-50 transition-colors">
                            Cancel
                        </button>
                        <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-lg font-medium text-white bg-red-600 hover:bg-red-700 transition-colors">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                            </svg>
                            Delete Duty
                        </button>
                    </div>
                </form>
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
        <div @click.stop class="relative bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-2xl w-full p-6 transform transition-all"
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
                <button type="button" @click="show = false" class="px-4 py-2 border border-gray-300 rounded-lg font-medium text-gray-700 bg-white dark:bg-gray-800 hover:bg-gray-50 transition-colors">Cancel</button>
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

<script nonce="<?php echo $csp_nonce; ?>">
function openDeleteDutyModal(deptId, deptName) {
    window.dispatchEvent(new CustomEvent('open-delete-duty-modal', { 
        detail: { deptId: deptId, deptName: deptName }
    }));
}

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
