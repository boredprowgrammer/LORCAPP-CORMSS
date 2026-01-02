<?php
/**
 * R5's Transactions - Transaction Search Tab
 * Search by control number, registry number, or name (format and spacing insensitive)
 */

// This file is included from r5-transactions.php
// Variables available: $db, $currentUser, $searchQuery, $roleConditions, $roleParams, $filterDistrict, $filterLocal

$searchResults = [];
$searchPerformed = !empty($searchQuery);

if ($searchPerformed) {
    // Normalize search query - remove spaces, hyphens, and make lowercase
    $normalizedSearch = strtolower(preg_replace('/[\s\-]/', '', $searchQuery));
    
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
    
    try {
        // Search across multiple transaction types
        $sql = "
            SELECT 
                o.officer_uuid,
                o.last_name_encrypted,
                o.first_name_encrypted,
                o.middle_initial_encrypted,
                o.registry_number_encrypted,
                o.control_number,
                o.district_code,
                o.is_active,
                d.district_name,
                l.local_name,
                GROUP_CONCAT(DISTINCT od.department ORDER BY od.department SEPARATOR ', ') as department,
                MAX(od.oath_date) as oath_date,
                MAX(od.is_active) as dept_is_active,
                MAX(r.removal_code) as removal_code,
                MAX(r.reason) as reason,
                MAX(r.removal_date) as removal_date,
                MAX(t.transfer_type) as transfer_type,
                MAX(t.transfer_date) as transfer_date,
                MAX(t.from_local_code) as from_local_code,
                MAX(t.from_district_code) as from_district_code,
                MAX(t.to_local_code) as to_local_code,
                MAX(t.to_district_code) as to_district_code,
                CASE 
                    WHEN MAX(od.oath_date) IS NOT NULL THEN MAX(od.oath_date)
                    WHEN MAX(t.transfer_date) IS NOT NULL THEN MAX(t.transfer_date)
                    WHEN MAX(r.removal_date) IS NOT NULL THEN MAX(r.removal_date)
                    ELSE o.created_at
                END as transaction_date
            FROM officers o
            INNER JOIN districts d ON o.district_code = d.district_code
            INNER JOIN local_congregations l ON o.local_code = l.local_code
            LEFT JOIN officer_departments od ON o.officer_id = od.officer_id
            LEFT JOIN officer_removals r ON od.id = r.department_id
            LEFT JOIN transfers t ON o.officer_id = t.officer_id
            WHERE 1=1
            $whereClause
            GROUP BY o.officer_uuid
            ORDER BY transaction_date DESC
        ";
        
        $params = [];
        
        $params = array_merge($params, $allParams);
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $allResults = $stmt->fetchAll();
        
        // Filter results by search query (decrypt and search in PHP)
        $searchResults = [];
        foreach ($allResults as $result) {
            $decrypted = Encryption::decryptOfficerName(
                $result['last_name_encrypted'],
                $result['first_name_encrypted'],
                $result['middle_initial_encrypted'],
                $result['district_code']
            );
            
            $registryNumber = !empty($result['registry_number_encrypted'])
                ? Encryption::decrypt($result['registry_number_encrypted'], $result['district_code'])
                : '';
            
            $controlNumber = $result['control_number'] ?? '';
            
            // Normalize and check if any field matches
            $normalizedFullName = strtolower(preg_replace('/[\s\-]/', '', $decrypted['first_name'] . $decrypted['last_name']));
            $normalizedReverseName = strtolower(preg_replace('/[\s\-]/', '', $decrypted['last_name'] . $decrypted['first_name']));
            $normalizedRegistry = strtolower(preg_replace('/[\s\-]/', '', $registryNumber));
            $normalizedControl = strtolower(preg_replace('/[\s\-]/', '', $controlNumber));
            
            if (strpos($normalizedFullName, $normalizedSearch) !== false ||
                strpos($normalizedReverseName, $normalizedSearch) !== false ||
                strpos($normalizedRegistry, $normalizedSearch) !== false ||
                strpos($normalizedControl, $normalizedSearch) !== false) {
                $searchResults[] = $result;
            }
        }
    } catch (Exception $e) {
        error_log("Transaction search error: " . $e->getMessage());
        $errorMessage = "Search error: " . $e->getMessage();
    }
}
?>

<div class="space-y-6">
    <!-- Search Form -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <form method="GET" action="" class="space-y-4">
            <input type="hidden" name="tab" value="transactions">
            <input type="hidden" name="period" value="<?php echo Security::escape($filterPeriod); ?>">
            <input type="hidden" name="district" value="<?php echo Security::escape($filterDistrict); ?>">
            <input type="hidden" name="local" value="<?php echo Security::escape($filterLocal); ?>">
            
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Search by Control Number, Registry Number, or Officer Name
                </label>
                <div class="flex gap-2">
                    <div class="relative flex-1">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                        </div>
                        <input 
                            type="text" 
                            name="search" 
                            value="<?php echo Security::escape($searchQuery); ?>"
                            placeholder="e.g., CN-2024-001, RN-001-2024, Juan Dela Cruz"
                            class="w-full pl-10 pr-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100"
                            autofocus
                        >
                    </div>
                    <button 
                        type="submit"
                        class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors duration-200 flex items-center gap-2"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                        Search
                    </button>
                </div>
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                    <strong>Note:</strong> Search is format and spacing insensitive. You can search without hyphens or spaces.
                </p>
            </div>
        </form>
    </div>

    <?php if (isset($errorMessage)): ?>
        <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
            <div class="flex items-center">
                <svg class="w-5 h-5 text-red-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <span class="text-red-800 dark:text-red-200"><?php echo Security::escape($errorMessage); ?></span>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($searchPerformed): ?>
        <!-- Search Results -->
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                    Search Results
                    <span class="ml-2 text-sm font-normal text-gray-500 dark:text-gray-400">
                        (<?php echo count($searchResults); ?> <?php echo count($searchResults) === 1 ? 'result' : 'results'; ?> found)
                    </span>
                </h2>
            </div>

            <?php if (empty($searchResults)): ?>
                <div class="p-12 text-center">
                    <svg class="w-16 h-16 mx-auto mb-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <p class="text-lg text-gray-500 dark:text-gray-400 mb-2">No transactions found</p>
                    <p class="text-sm text-gray-400 dark:text-gray-500">
                        Try adjusting your search term or filters
                    </p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Officer</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Control Number</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Registry Number</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Department</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Location</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Latest Transaction</th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            <?php foreach ($searchResults as $result): ?>
                                <?php
                                $decrypted = Encryption::decryptOfficerName(
                                    $result['last_name_encrypted'],
                                    $result['first_name_encrypted'],
                                    $result['middle_initial_encrypted'],
                                    $result['district_code']
                                );
                                $decryptedRegistryNumber = !empty($result['registry_number_encrypted']) 
                                    ? Encryption::decrypt($result['registry_number_encrypted'], $result['district_code'])
                                    : 'N/A';
                                
                                // Determine status
                                $status = '';
                                $statusBadge = '';
                                
                                if ($result['is_active'] && $result['dept_is_active']) {
                                    $status = 'ACTIVE';
                                    $statusBadge = 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200';
                                } elseif ($result['transfer_type'] === 'out') {
                                    $status = 'TRANSFERRED-OUT';
                                    $statusBadge = 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200';
                                } elseif ($result['removal_code'] === 'C') {
                                    $status = 'SUSPENDIDO (CODE-C)';
                                    $statusBadge = 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200';
                                } elseif ($result['removal_code'] === 'D') {
                                    $status = 'LIPAT-KAPISANAN (CODE-D)';
                                    $statusBadge = 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200';
                                } else {
                                    $status = 'INACTIVE';
                                    $statusBadge = 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200';
                                }
                                
                                // Determine latest transaction
                                $latestTransaction = '';
                                if (!empty($result['oath_date'])) {
                                    $latestTransaction = 'Oath: ' . date('M d, Y', strtotime($result['oath_date']));
                                } elseif (!empty($result['transfer_date'])) {
                                    $transType = $result['transfer_type'] === 'in' ? 'Transfer-In' : 'Transfer-Out';
                                    $latestTransaction = $transType . ': ' . date('M d, Y', strtotime($result['transfer_date']));
                                } elseif (!empty($result['removal_date'])) {
                                    $latestTransaction = 'Removal: ' . date('M d, Y', strtotime($result['removal_date']));
                                }
                                ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900 dark:text-gray-100">
                                            <?php echo Security::escape($decrypted['first_name'] . ' ' . $decrypted['last_name']); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900 dark:text-gray-100 font-mono">
                                            <?php echo Security::escape($result['control_number'] ?? 'N/A'); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900 dark:text-gray-100 font-mono">
                                            <?php echo Security::escape($decryptedRegistryNumber); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo $statusBadge; ?>">
                                            <?php echo Security::escape($status); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                        <?php echo Security::escape($result['department'] ?? 'N/A'); ?>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-900 dark:text-gray-100">
                                        <?php echo Security::escape($result['local_name']); ?>
                                        <br><span class="text-xs text-gray-500">(<?php echo Security::escape($result['district_name']); ?>)</span>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">
                                        <?php echo Security::escape($latestTransaction); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-center text-sm">
                                        <button onclick="OfficerDetailsModal.open('<?php echo Security::escape($result['officer_uuid']); ?>')" 
                                                class="text-purple-600 hover:text-purple-900 dark:text-purple-400 dark:hover:text-purple-300 font-medium">
                                            Quick View
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <!-- Empty State -->
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-12 text-center">
            <svg class="w-20 h-20 mx-auto mb-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
            </svg>
            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-2">Search for Transactions</h3>
            <p class="text-gray-500 dark:text-gray-400 mb-4">
                Enter a control number, registry number, or officer name to find their transactions.
            </p>
            <div class="max-w-md mx-auto text-left bg-gray-50 dark:bg-gray-700 rounded-lg p-4">
                <p class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Search Examples:</p>
                <ul class="text-sm text-gray-600 dark:text-gray-400 space-y-1">
                    <li>• <code class="px-2 py-1 bg-white dark:bg-gray-800 rounded">CN-2024-001</code> or <code class="px-2 py-1 bg-white dark:bg-gray-800 rounded">CN2024001</code></li>
                    <li>• <code class="px-2 py-1 bg-white dark:bg-gray-800 rounded">RN-001-2024</code> or <code class="px-2 py-1 bg-white dark:bg-gray-800 rounded">RN0012024</code></li>
                    <li>• <code class="px-2 py-1 bg-white dark:bg-gray-800 rounded">Juan Dela Cruz</code> or <code class="px-2 py-1 bg-white dark:bg-gray-800 rounded">juandelacruz</code></li>
                </ul>
            </div>
        </div>
    <?php endif; ?>
</div>
