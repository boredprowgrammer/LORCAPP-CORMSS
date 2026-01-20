<?php
/**
 * Tarheta Control - List and Management
 * View and manage legacy registry records
 */

require_once __DIR__ . '/../config/config.php';

Security::requireLogin();

// Block access for local_cfo role
if (getCurrentUser()['role'] === 'local_cfo') {
    setFlashMessage('error', 'Access denied for this feature.');
    header('Location: ' . BASE_URL . '/launchpad.php');
    exit;
}

requirePermission('can_view_reports'); // Anyone who can view reports can see this

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

$error = '';
$success = '';

// Get filter parameters
$filterDistrict = Security::sanitizeInput($_GET['district'] ?? '');
$filterLocal = Security::sanitizeInput($_GET['local'] ?? '');
$filterSearch = Security::sanitizeInput($_GET['search'] ?? '');
$filterLinked = Security::sanitizeInput($_GET['linked'] ?? 'all');

// Build query
$whereConditions = [];
$params = [];

if ($currentUser['role'] === 'district') {
    $whereConditions[] = 't.district_code = ?';
    $params[] = $currentUser['district_code'];
} elseif ($currentUser['role'] === 'local') {
    $whereConditions[] = 't.local_code = ?';
    $params[] = $currentUser['local_code'];
}

if (!empty($filterDistrict)) {
    $whereConditions[] = 't.district_code = ?';
    $params[] = $filterDistrict;
}

if (!empty($filterLocal)) {
    $whereConditions[] = 't.local_code = ?';
    $params[] = $filterLocal;
}

if ($filterLinked === 'linked') {
    $whereConditions[] = 't.linked_officer_id IS NOT NULL';
} elseif ($filterLinked === 'unlinked') {
    $whereConditions[] = 't.linked_officer_id IS NULL';
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Get total count from database (regardless of search)
$totalInDatabase = 0;
try {
    $countStmt = $db->prepare("SELECT COUNT(*) as total FROM tarheta_control t $whereClause");
    $countStmt->execute($params);
    $totalInDatabase = $countStmt->fetch()['total'] ?? 0;
} catch (Exception $e) {
    error_log("Error getting total count: " . $e->getMessage());
}

// Get records - only if search is provided OR load_all is requested
$records = [];
$totalRecords = 0;
$loadAll = isset($_GET['load_all']) && $_GET['load_all'] == '1';
$searchProvided = !empty($filterSearch) || $loadAll;
$decryptionErrors = 0;
$totalScanned = 0;
$moreResultsAvailable = false;

if ($searchProvided) {
    try {
        // When searching, scan ALL records to ensure we find matches
        // When loading all without search, limit to 5000 for display
        // When user provides search term, scan all but limit results shown
        if (!empty($filterSearch)) {
            // Search mode: Load ALL records to search through them, limit display after filtering
            $limit = 10000; // High limit to get all records
            $displayLimit = 500; // Limit results shown after search filtering
        } else {
            // Load all mode: Just show records without filtering
            $limit = $loadAll ? 5000 : 500;
            $displayLimit = $limit;
        }
        
        $stmt = $db->prepare("
            SELECT 
                t.*,
                d.district_name,
                lc.local_name,
                o.officer_uuid as linked_officer_uuid
            FROM tarheta_control t
            LEFT JOIN districts d ON t.district_code = d.district_code
            LEFT JOIN local_congregations lc ON t.local_code = lc.local_code
            LEFT JOIN officers o ON t.linked_officer_id = o.officer_id
            $whereClause
            ORDER BY t.imported_at DESC
            LIMIT $limit
        ");
        
        $stmt->execute($params);
        $allRecords = $stmt->fetchAll();
        $totalScanned = count($allRecords);
        
        // Decrypt names for display
        $matchedCount = 0;
        foreach ($allRecords as $record) {
            $decryptionFailed = false;
            
            try {
                // Decrypt each field separately with error handling
                try {
                    $lastName = Encryption::decrypt($record['last_name_encrypted'], $record['district_code']);
                } catch (Exception $e) {
                    error_log("Failed to decrypt last_name for tarheta ID {$record['id']}: " . $e->getMessage());
                    $lastName = '[DECRYPT ERROR - ID:' . $record['id'] . ']';
                    $decryptionFailed = true;
                }
                
                try {
                    $firstName = Encryption::decrypt($record['first_name_encrypted'], $record['district_code']);
                } catch (Exception $e) {
                    error_log("Failed to decrypt first_name for tarheta ID {$record['id']}: " . $e->getMessage());
                    $firstName = '[ERROR]';
                    $decryptionFailed = true;
                }
                
                try {
                    $middleName = !empty($record['middle_name_encrypted']) 
                        ? Encryption::decrypt($record['middle_name_encrypted'], $record['district_code'])
                        : '';
                } catch (Exception $e) {
                    error_log("Failed to decrypt middle_name for tarheta ID {$record['id']}: " . $e->getMessage());
                    $middleName = '';
                }
                
                try {
                    $registryNumber = Encryption::decrypt($record['registry_number_encrypted'], $record['district_code']);
                } catch (Exception $e) {
                    error_log("Failed to decrypt registry_number for tarheta ID {$record['id']}: " . $e->getMessage());
                    $registryNumber = '[DECRYPT ERROR]';
                    $decryptionFailed = true;
                }
                
                try {
                    $husbandsSurname = !empty($record['husbands_surname_encrypted']) 
                        ? Encryption::decrypt($record['husbands_surname_encrypted'], $record['district_code'])
                        : '';
                } catch (Exception $e) {
                    error_log("Failed to decrypt husbands_surname for tarheta ID {$record['id']}: " . $e->getMessage());
                    $husbandsSurname = '';
                }
                
                // DON'T skip records with decryption errors - show them so user knows they exist
                if ($decryptionFailed) {
                    $decryptionErrors++;
                }
                
                $fullName = trim($lastName . ', ' . $firstName . 
                               (!empty($middleName) ? ' ' . $middleName : ''));
                
                // Apply search filter if provided (skip if loading all)
                if (!empty($filterSearch) && !$loadAll) {
                    $match = false;
                    $searchLower = mb_strtolower(trim($filterSearch), 'UTF-8');
                    
                    // Method 1: Direct substring match in registry number
                    if (mb_stripos($registryNumber, $filterSearch) !== false) {
                        $match = true;
                    }
                    
                    // Method 2: Combined searchable text
                    if (!$match) {
                        $searchableText = mb_strtolower(
                            $lastName . ' ' . $firstName . ' ' . $middleName . ' ' . 
                            $husbandsSurname . ' ' . $registryNumber,
                            'UTF-8'
                        );
                        if (mb_strpos($searchableText, $searchLower) !== false) {
                            $match = true;
                        }
                    }
                    
                    // Method 3: Smart comma handling for "Last, First" format
                    if (!$match && strpos($filterSearch, ',') !== false) {
                        $parts = array_map('trim', explode(',', $filterSearch));
                        if (count($parts) >= 2) {
                            $searchLast = mb_strtolower($parts[0], 'UTF-8');
                            $searchFirst = mb_strtolower($parts[1], 'UTF-8');
                            $actualLast = mb_strtolower($lastName, 'UTF-8');
                            $actualFirst = mb_strtolower($firstName, 'UTF-8');
                            
                            if (mb_strpos($actualLast, $searchLast) !== false && 
                                mb_strpos($actualFirst, $searchFirst) !== false) {
                                $match = true;
                            }
                        }
                    }
                    
                    // Method 4: Token-based word matching
                    if (!$match) {
                        $searchTokens = preg_split('/\s+/', $searchLower);
                        $allTokensFound = true;
                        foreach ($searchTokens as $token) {
                            if (empty($token)) continue;
                            $found = false;
                            foreach ([$lastName, $firstName, $middleName, $husbandsSurname, $registryNumber] as $field) {
                                if (mb_stripos($field, $token) !== false) {
                                    $found = true;
                                    break;
                                }
                            }
                            if (!$found) {
                                $allTokensFound = false;
                                break;
                            }
                        }
                        if ($allTokensFound && count($searchTokens) > 0) {
                            $match = true;
                        }
                    }
                    
                    // Method 5: Starts-with matching (more relevant)
                    if (!$match) {
                        $lastNameLower = mb_strtolower($lastName, 'UTF-8');
                        $firstNameLower = mb_strtolower($firstName, 'UTF-8');
                        if (mb_strpos($lastNameLower, $searchLower) === 0 || 
                            mb_strpos($firstNameLower, $searchLower) === 0) {
                            $match = true;
                        }
                    }
                    
                    // Method 6: ASCII fallback (non-UTF8 compatibility)
                    if (!$match) {
                        if (stripos($lastName, $filterSearch) !== false ||
                            stripos($firstName, $filterSearch) !== false ||
                            stripos($middleName, $filterSearch) !== false ||
                            stripos($husbandsSurname, $filterSearch) !== false ||
                            stripos($registryNumber, $filterSearch) !== false) {
                            $match = true;
                        }
                    }
                    
                    if (!$match) {
                        continue;
                    }
                    
                    $matchedCount++;
                }
                
                // Obfuscate the name using the function from includes/functions.php
                $obfuscatedName = obfuscateName($fullName);
                
                // Check if we've reached display limit for search results
                if (!empty($filterSearch) && count($records) >= $displayLimit) {
                    // Track that more results are available but not shown
                    $moreResultsAvailable = true;
                    continue; // Skip adding but continue counting matches
                }
                
                $records[] = [
                    'id' => $record['id'],
                    'full_name' => $fullName,
                    'obfuscated_name' => $obfuscatedName,
                    'husbands_surname' => $husbandsSurname,
                    'registry_number' => $registryNumber,
                    'district_name' => $record['district_name'],
                    'local_name' => $record['local_name'],
                    'import_batch' => $record['import_batch'],
                    'imported_at' => $record['imported_at'],
                    'linked_officer_id' => $record['linked_officer_id'],
                    'linked_officer_uuid' => $record['linked_officer_uuid'] ?? null,
                    'linked_at' => $record['linked_at'],
                    'has_decrypt_error' => $decryptionFailed
                ];
            } catch (Exception $e) {
                error_log("Unexpected error processing tarheta ID {$record['id']}: " . $e->getMessage());
                $decryptionErrors++;
            }
        }
        
    } catch (Exception $e) {
        error_log("Query error: " . $e->getMessage());
        $error = 'Error loading records.';
    }
}

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

// Get locals if district selected
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

$pageTitle = 'Tarheta Control Records';
ob_start();
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900 dark:text-gray-100">Tarheta Control Records</h1>
                <p class="text-sm text-gray-500 mt-1">
                    Legacy registry data for linking to officers
                    <?php if ($totalInDatabase > 0): ?>
                        <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">
                            <?php echo number_format($totalInDatabase); ?> total in database
                        </span>
                    <?php endif; ?>
                </p>
            </div>
            <?php if (hasPermission('can_add_officers')): ?>
            <a href="import.php" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                </svg>
                Import CSV
            </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
            <?php echo Security::escape($error); ?>
        </div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-5">
        <form method="GET" action="" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">District</label>
                <select name="district" id="district" class="block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 text-sm" onchange="this.form.submit()">
                    <option value="">All Districts</option>
                    <?php foreach ($districts as $district): ?>
                        <option value="<?php echo Security::escape($district['district_code']); ?>" <?php echo $filterDistrict === $district['district_code'] ? 'selected' : ''; ?>>
                            <?php echo Security::escape($district['district_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Local Congregation</label>
                <select name="local" class="block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 text-sm" onchange="this.form.submit()">
                    <option value="">All Locals</option>
                    <?php foreach ($locals as $local): ?>
                        <option value="<?php echo Security::escape($local['local_code']); ?>" <?php echo $filterLocal === $local['local_code'] ? 'selected' : ''; ?>>
                            <?php echo Security::escape($local['local_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Linked Status</label>
                <select name="linked" class="block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 text-sm" onchange="this.form.submit()">
                    <option value="all" <?php echo $filterLinked === 'all' ? 'selected' : ''; ?>>All Records</option>
                    <option value="linked" <?php echo $filterLinked === 'linked' ? 'selected' : ''; ?>>Linked Only</option>
                    <option value="unlinked" <?php echo $filterLinked === 'unlinked' ? 'selected' : ''; ?>>Unlinked Only</option>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Search</label>
                <input type="text" name="search" value="<?php echo Security::escape($filterSearch); ?>" placeholder="Name or Registry #" class="block w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 text-sm">
            </div>

            <div class="flex items-end gap-2">
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors text-sm font-medium">
                    Filter
                </button>
                <a href="?load_all=1<?php echo !empty($filterDistrict) ? '&district=' . urlencode($filterDistrict) : ''; ?><?php echo !empty($filterLocal) ? '&local=' . urlencode($filterLocal) : ''; ?><?php echo !empty($filterLinked) && $filterLinked !== 'all' ? '&linked=' . urlencode($filterLinked) : ''; ?>" 
                   class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors text-sm font-medium">
                    Load All Records
                </a>
                <a href="?" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors text-sm font-medium">
                    Clear
                </a>
            </div>
        </form>
    </div>

    <!-- Records Table -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
        <?php if ($searchProvided && count($records) > 0): ?>
            <!-- Search Statistics -->
            <div class="px-4 py-3 bg-blue-50 border-b border-blue-100">
                <div class="flex items-center justify-between text-sm">
                    <div class="text-blue-700">
                        <span class="font-medium"><?php echo count($records); ?></span> record<?php echo count($records) != 1 ? 's' : ''; ?> found
                        <span class="text-blue-600 ml-2">(scanned <?php echo $totalScanned; ?> total)</span>
                    </div>
                    <?php if ($decryptionErrors > 0): ?>
                        <div class="text-amber-700 font-medium">
                            <svg class="w-4 h-4 inline-block mr-1" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                            </svg>
                            <?php echo $decryptionErrors; ?> record<?php echo $decryptionErrors != 1 ? 's' : ''; ?> skipped (decryption error)
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Name</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Registry Number</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">District/Local</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Imported</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                    <?php if (!$searchProvided): ?>
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
                                <svg class="w-12 h-12 mx-auto mb-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                </svg>
                                <p class="font-medium">Enter a search term to view records</p>
                                <p class="text-sm mt-1">Use the search box above to find names or registry numbers</p>
                            </td>
                        </tr>
                    <?php elseif (empty($records)): ?>
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
                                No records found matching your search. <a href="import.php" class="text-blue-600 hover:underline">Import CSV data</a>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($records as $record): ?>
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700 <?php echo $record['has_decrypt_error'] ? 'bg-red-50 dark:bg-red-900/20' : ''; ?>">
                            <td class="px-4 py-3">
                                <?php if ($record['has_decrypt_error']): ?>
                                    <div class="flex items-center gap-2">
                                        <svg class="w-4 h-4 text-red-600" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                                        </svg>
                                        <div>
                                            <div class="text-sm font-medium text-red-900" title="Decryption Error - Check encryption key">
                                                <?php echo Security::escape($record['obfuscated_name']); ?>
                                            </div>
                                            <div class="text-xs text-red-600">Decryption Error</div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="text-sm font-medium text-gray-900 dark:text-gray-100" title="<?php echo Security::escape($record['full_name']); ?>" style="cursor: help;">
                                        <?php echo Security::escape($record['obfuscated_name']); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($record['husbands_surname'])): ?>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">
                                        Husband's Surname: <?php echo Security::escape($record['husbands_surname']); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3">
                                <span class="text-sm font-mono <?php echo $record['has_decrypt_error'] ? 'text-red-900' : 'text-gray-900 dark:text-gray-100'; ?>">
                                    <?php echo Security::escape($record['registry_number']); ?>
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="text-sm text-gray-900 dark:text-gray-100"><?php echo Security::escape($record['district_name']); ?></div>
                                <div class="text-xs text-gray-500 dark:text-gray-400"><?php echo Security::escape($record['local_name']); ?></div>
                            </td>
                            <td class="px-4 py-3">
                                <?php if ($record['linked_officer_id']): ?>
                                    <span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full bg-green-100 text-green-800">
                                        <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                        </svg>
                                        Linked
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full bg-yellow-100 text-yellow-800">
                                        Unlinked
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-500">
                                <?php echo date('M d, Y', strtotime($record['imported_at'])); ?>
                            </td>
                            <td class="px-4 py-3 text-right text-sm space-x-2">
                                <?php if ($record['linked_officer_id']): ?>
                                    <a href="<?php echo BASE_URL; ?>/officers/view.php?id=<?php echo $record['linked_officer_uuid']; ?>" class="text-blue-600 hover:text-blue-800">
                                        View Officer
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Stats -->
    <?php if (!empty($records)): ?>
    <div class="bg-gray-50 rounded-lg p-4">
        <p class="text-sm text-gray-600">
            Showing <?php echo count($records); ?> records
            <?php if (!empty($filterSearch) && isset($matchedCount) && $matchedCount > count($records)): ?>
                (<?php echo $matchedCount; ?> total matches, displaying first <?php echo count($records); ?>)
            <?php endif; ?>
            <?php 
            $linkedCount = count(array_filter($records, function($r) { return $r['linked_officer_id']; }));
            $unlinkedCount = count($records) - $linkedCount;
            ?>
            - <?php echo $linkedCount; ?> linked, <?php echo $unlinkedCount; ?> unlinked
        </p>
        <?php if ($moreResultsAvailable): ?>
        <p class="text-xs text-orange-600 mt-2">
            ⚠️ More results available. Refine your search to see other matches.
        </p>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
