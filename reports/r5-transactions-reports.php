<?php
/**
 * R5's Transactions - Reports Tab
 * Shows newly oath officers, transfer-in, and removals/out officers
 */

// This file is included from r5-transactions.php
// Variables available: $db, $currentUser, $roleConditions, $roleParams, $startDate, $endDate, $filterDistrict, $filterLocal

// Add filter conditions
$additionalConditions = [];
$additionalParams = [];

if (!empty($filterDistrict)) {
    $additionalConditions[] = 'o.district_code = ?';
    $additionalParams[] = $filterDistrict;
}

if (!empty($filterLocal)) {
    $additionalConditions[] = 'o.local_code = ?';
    $additionalParams[] = $filterLocal;
}

$whereClause = implode(' AND ', array_merge($roleConditions, $additionalConditions));
if (!empty($whereClause)) {
    $whereClause = 'AND ' . $whereClause;
}

$allParams = array_merge($roleParams, $additionalParams);

// 1. NEWLY OATH OFFICERS
$newlyOathOfficers = [];
try {
    $sql = "
        SELECT 
            o.officer_uuid,
            o.last_name_encrypted,
            o.first_name_encrypted,
            o.middle_initial_encrypted,
            o.registry_number_encrypted,
            o.district_code,
            od.department,
            od.oath_date,
            d.district_name,
            l.local_name
        FROM officers o
        INNER JOIN officer_departments od ON o.officer_id = od.officer_id AND od.is_active = 1
        INNER JOIN districts d ON o.district_code = d.district_code
        INNER JOIN local_congregations l ON o.local_code = l.local_code
        WHERE od.oath_date >= ? AND od.oath_date <= ?
        $whereClause
        ORDER BY od.oath_date DESC, o.last_name_encrypted ASC
    ";
    
    $params = [];
    $params[] = $startDate;
    $params[] = $endDate;
    $params = array_merge($params, $allParams);
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $newlyOathOfficers = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Newly oath query error: " . $e->getMessage());
}

// 2. TRANSFER-IN OFFICERS
$transferInOfficers = [];
try {
    $sql = "
        SELECT 
            o.officer_uuid,
            o.last_name_encrypted,
            o.first_name_encrypted,
            o.middle_initial_encrypted,
            o.registry_number_encrypted,
            o.district_code,
            od.department,
            t.transfer_date,
            t.from_local_code,
            t.from_district_code,
            d.district_name,
            l.local_name
        FROM officers o
        INNER JOIN officer_departments od ON o.officer_id = od.officer_id AND od.is_active = 1
        INNER JOIN districts d ON o.district_code = d.district_code
        INNER JOIN local_congregations l ON o.local_code = l.local_code
        INNER JOIN transfers t ON o.officer_id = t.officer_id 
            AND t.transfer_type = 'in'
            AND t.transfer_date >= ? AND t.transfer_date <= ?
        WHERE 1=1 $whereClause
        ORDER BY t.transfer_date DESC, o.last_name_encrypted ASC
    ";
    
    $params = [
        $startDate,
        $endDate
    ];
    $params = array_merge($params, $allParams);
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $transferInOfficers = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Transfer-in query error: " . $e->getMessage());
}

// 3. REMOVALS/OUT OFFICERS
$removalsOutOfficers = [];
try {
    $sql = "
        SELECT 
            o.officer_uuid,
            o.last_name_encrypted,
            o.first_name_encrypted,
            o.middle_initial_encrypted,
            o.registry_number_encrypted,
            o.district_code,
            od.department,
            r.removal_code,
            r.reason,
            r.removal_date,
            t.transfer_type,
            t.transfer_date,
            t.to_local_code,
            t.to_district_code,
            d.district_name,
            l.local_name
        FROM officers o
        LEFT JOIN officer_departments od ON o.officer_id = od.officer_id AND od.is_active = 0
        INNER JOIN districts d ON o.district_code = d.district_code
        INNER JOIN local_congregations l ON o.local_code = l.local_code
        LEFT JOIN officer_removals r ON od.id = r.department_id
            AND r.removal_date >= ? AND r.removal_date <= ?
        LEFT JOIN transfers t ON o.officer_id = t.officer_id 
            AND t.transfer_type = 'out'
            AND t.transfer_date >= ? AND t.transfer_date <= ?
        WHERE o.is_active = 0 
        AND (r.removal_date IS NOT NULL OR t.transfer_date IS NOT NULL)
        $whereClause
        ORDER BY COALESCE(t.transfer_date, r.removal_date) DESC, o.last_name_encrypted ASC
    ";
    
    $params = [
        $startDate,
        $endDate,
        $startDate,
        $endDate
    ];
    $params = array_merge($params, $allParams);
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $removalsOutOfficers = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Removals/Out query error: " . $e->getMessage());
}
?>

<div class="space-y-6">
    <!-- Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <!-- Newly Oath Card -->
        <div class="bg-gradient-to-br from-green-50 to-green-100 dark:from-green-900 dark:to-green-800 rounded-lg p-6 border border-green-200 dark:border-green-700">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-green-800 dark:text-green-200">Newly Oath</p>
                    <p class="text-3xl font-bold text-green-900 dark:text-green-100 mt-1"><?php echo count($newlyOathOfficers); ?></p>
                </div>
                <div class="p-3 bg-green-500 rounded-full">
                    <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
            </div>
        </div>

        <!-- Transfer-In Card -->
        <div class="bg-gradient-to-br from-blue-50 to-blue-100 dark:from-blue-900 dark:to-blue-800 rounded-lg p-6 border border-blue-200 dark:border-blue-700">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-blue-800 dark:text-blue-200">Transfer-In</p>
                    <p class="text-3xl font-bold text-blue-900 dark:text-blue-100 mt-1"><?php echo count($transferInOfficers); ?></p>
                </div>
                <div class="p-3 bg-blue-500 rounded-full">
                    <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"></path>
                    </svg>
                </div>
            </div>
        </div>

        <!-- Removals/Out Card -->
        <div class="bg-gradient-to-br from-red-50 to-red-100 dark:from-red-900 dark:to-red-800 rounded-lg p-6 border border-red-200 dark:border-red-700">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-red-800 dark:text-red-200">Removals/Out</p>
                    <p class="text-3xl font-bold text-red-900 dark:text-red-100 mt-1"><?php echo count($removalsOutOfficers); ?></p>
                </div>
                <div class="p-3 bg-red-500 rounded-full">
                    <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </div>
            </div>
        </div>
    </div>

    <!-- 1. NEWLY OATH OFFICERS -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700">
        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 flex items-center">
                <svg class="w-5 h-5 text-green-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                Newly Oath Officers
            </h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Officer</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Registry Number</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Department</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Oath Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">District</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Local</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    <?php if (empty($newlyOathOfficers)): ?>
                        <tr>
                            <td colspan="7" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">
                                <svg class="w-12 h-12 mx-auto mb-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                                </svg>
                                No newly oath officers found for this period.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($newlyOathOfficers as $officer): ?>
                            <?php
                            $decrypted = Encryption::decryptOfficerName(
                                $officer['last_name_encrypted'],
                                $officer['first_name_encrypted'],
                                $officer['middle_initial_encrypted'],
                                $officer['district_code']
                            );
                            $decryptedRegistryNumber = !empty($officer['registry_number_encrypted']) 
                                ? Encryption::decrypt($officer['registry_number_encrypted'], $officer['district_code'])
                                : 'N/A';
                            ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900 dark:text-gray-100">
                                        <?php echo Security::escape($decrypted['first_name'] . ' ' . $decrypted['last_name']); ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                    <?php echo Security::escape($decryptedRegistryNumber); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                    <?php echo Security::escape($officer['department']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                    <?php echo date('M d, Y', strtotime($officer['oath_date'])); ?>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-900 dark:text-gray-100">
                                    <?php echo Security::escape($officer['district_name']); ?>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-900 dark:text-gray-100">
                                    <?php echo Security::escape($officer['local_name']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center text-sm">
                                    <button onclick="OfficerDetailsModal.open('<?php echo Security::escape($officer['officer_uuid']); ?>')" 
                                            class="text-purple-600 hover:text-purple-900 dark:text-purple-400 dark:hover:text-purple-300">
                                        Quick View
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 2. TRANSFER-IN OFFICERS -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700">
        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 flex items-center">
                <svg class="w-5 h-5 text-blue-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"></path>
                </svg>
                Transfer-In Officers
            </h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Officer</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Registry Number</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Department</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Transfer Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">From</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Current Location</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    <?php if (empty($transferInOfficers)): ?>
                        <tr>
                            <td colspan="7" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">
                                <svg class="w-12 h-12 mx-auto mb-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                                </svg>
                                No transfer-in officers found for this period.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($transferInOfficers as $officer): ?>
                            <?php
                            $decrypted = Encryption::decryptOfficerName(
                                $officer['last_name_encrypted'],
                                $officer['first_name_encrypted'],
                                $officer['middle_initial_encrypted'],
                                $officer['district_code']
                            );
                            $decryptedRegistryNumber = !empty($officer['registry_number_encrypted']) 
                                ? Encryption::decrypt($officer['registry_number_encrypted'], $officer['district_code'])
                                : 'N/A';
                            ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900 dark:text-gray-100">
                                        <?php echo Security::escape($decrypted['first_name'] . ' ' . $decrypted['last_name']); ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                    <?php echo Security::escape($decryptedRegistryNumber); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                    <?php echo Security::escape($officer['department']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                    <?php echo date('M d, Y', strtotime($officer['transfer_date'])); ?>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">
                                    <?php echo Security::escape($officer['from_local_code'] ?? 'N/A'); ?>
                                    <?php if (!empty($officer['from_district_code'])): ?>
                                        <br><span class="text-xs">(<?php echo Security::escape($officer['from_district_code']); ?>)</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-900 dark:text-gray-100">
                                    <?php echo Security::escape($officer['local_name']); ?>
                                    <br><span class="text-xs text-gray-500">(<?php echo Security::escape($officer['district_name']); ?>)</span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center text-sm">
                                    <button onclick="OfficerDetailsModal.open('<?php echo Security::escape($officer['officer_uuid']); ?>')" 
                                            class="text-purple-600 hover:text-purple-900 dark:text-purple-400 dark:hover:text-purple-300">
                                        Quick View
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 3. REMOVALS/OUT OFFICERS -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700">
        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 flex items-center">
                <svg class="w-5 h-5 text-red-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
                Removals/Out Officers
            </h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Officer</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Registry Number</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Department</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Type</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Details</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    <?php if (empty($removalsOutOfficers)): ?>
                        <tr>
                            <td colspan="7" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">
                                <svg class="w-12 h-12 mx-auto mb-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                                </svg>
                                No removals or out officers found for this period.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($removalsOutOfficers as $officer): ?>
                            <?php
                            $decrypted = Encryption::decryptOfficerName(
                                $officer['last_name_encrypted'],
                                $officer['first_name_encrypted'],
                                $officer['middle_initial_encrypted'],
                                $officer['district_code']
                            );
                            $decryptedRegistryNumber = !empty($officer['registry_number_encrypted']) 
                                ? Encryption::decrypt($officer['registry_number_encrypted'], $officer['district_code'])
                                : 'N/A';
                            
                            // Determine type
                            $type = '';
                            $typeBadge = '';
                            $date = '';
                            $details = '';
                            
                            if ($officer['transfer_type'] === 'out' && !empty($officer['transfer_date'])) {
                                $type = 'TRANSFERRED-OUT';
                                $typeBadge = 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200';
                                $date = $officer['transfer_date'];
                                $details = $officer['to_local_code'] ?? 'N/A';
                                if (!empty($officer['to_district_code'])) {
                                    $details .= ' (' . $officer['to_district_code'] . ')';
                                }
                            } elseif ($officer['removal_code'] === 'C') {
                                $type = 'SUSPENDIDO (CODE-C)';
                                $typeBadge = 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200';
                                $date = $officer['removal_date'];
                                $details = $officer['reason'] ?? '';
                            } elseif ($officer['removal_code'] === 'D') {
                                $type = 'LIPAT-KAPISANAN (CODE-D)';
                                $typeBadge = 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200';
                                $date = $officer['removal_date'];
                                $details = $officer['reason'] ?? '';
                            } elseif (!empty($officer['removal_date'])) {
                                $type = 'REMOVED';
                                $typeBadge = 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200';
                                $date = $officer['removal_date'];
                                $details = $officer['reason'] ?? '';
                            }
                            ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900 dark:text-gray-100">
                                        <?php echo Security::escape($decrypted['first_name'] . ' ' . $decrypted['last_name']); ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                    <?php echo Security::escape($decryptedRegistryNumber); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                    <?php echo Security::escape($officer['department'] ?? 'N/A'); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo $typeBadge; ?>">
                                        <?php echo Security::escape($type); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                    <?php echo $date ? date('M d, Y', strtotime($date)) : 'N/A'; ?>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">
                                    <?php echo Security::escape($details); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center text-sm">
                                    <button onclick="OfficerDetailsModal.open('<?php echo Security::escape($officer['officer_uuid']); ?>')" 
                                            class="text-purple-600 hover:text-purple-900 dark:text-purple-400 dark:hover:text-purple-300">
                                        Quick View
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
