<?php
/**
 * Legacy Officers - List and Management
 * View and manage legacy control numbers
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
$filterSearch = Security::sanitizeInput($_GET['search'] ?? '');
$filterLinked = Security::sanitizeInput($_GET['linked'] ?? 'all');

// Build query
$whereConditions = [];
$params = [];

if ($currentUser['role'] === 'district') {
    $whereConditions[] = 'l.district_code = ?';
    $params[] = $currentUser['district_code'];
} elseif ($currentUser['role'] === 'local') {
    $whereConditions[] = 'l.local_code = ?';
    $params[] = $currentUser['local_code'];
}

if (!empty($filterDistrict)) {
    $whereConditions[] = 'l.district_code = ?';
    $params[] = $filterDistrict;
}

if (!empty($filterLocal)) {
    $whereConditions[] = 'l.local_code = ?';
    $params[] = $filterLocal;
}

if ($filterLinked === 'linked') {
    $whereConditions[] = 'l.linked_officer_id IS NOT NULL';
} elseif ($filterLinked === 'unlinked') {
    $whereConditions[] = 'l.linked_officer_id IS NULL';
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Get total count
$totalInDatabase = 0;
try {
    $countStmt = $db->prepare("SELECT COUNT(*) as total FROM legacy_officers l $whereClause");
    $countStmt->execute($params);
    $totalInDatabase = $countStmt->fetch()['total'] ?? 0;
} catch (Exception $e) {
    error_log("Error getting total count: " . $e->getMessage());
}

// Get records
$records = [];
$loadAll = isset($_GET['load_all']) && $_GET['load_all'] == '1';
$searchProvided = !empty($filterSearch) || $loadAll;
$decryptionErrors = 0;
$totalScanned = 0;
$moreResultsAvailable = false;

if ($searchProvided) {
    try {
        if (!empty($filterSearch)) {
            $limit = 10000;
            $displayLimit = 500;
        } else {
            $limit = $loadAll ? 5000 : 500;
            $displayLimit = $limit;
        }
        
        $stmt = $db->prepare("
            SELECT 
                l.*,
                d.district_name,
                lc.local_name,
                o.officer_uuid as linked_officer_uuid
            FROM legacy_officers l
            LEFT JOIN districts d ON l.district_code = d.district_code
            LEFT JOIN local_congregations lc ON l.local_code = lc.local_code
            LEFT JOIN officers o ON l.linked_officer_id = o.officer_id
            $whereClause
            ORDER BY l.imported_at DESC
            LIMIT $limit
        ");
        
        $stmt->execute($params);
        $allRecords = $stmt->fetchAll();
        $totalScanned = count($allRecords);
        
        $matchedCount = 0;
        foreach ($allRecords as $record) {
            $decryptionFailed = false;
            
            try {
                try {
                    $name = Encryption::decrypt($record['name_encrypted'], $record['district_code']);
                } catch (Exception $e) {
                    error_log("Failed to decrypt name for legacy ID {$record['id']}: " . $e->getMessage());
                    $name = '[DECRYPT ERROR - ID:' . $record['id'] . ']';
                    $decryptionFailed = true;
                }
                
                try {
                    $controlNumber = Encryption::decrypt($record['control_number_encrypted'], $record['district_code']);
                } catch (Exception $e) {
                    error_log("Failed to decrypt control_number for legacy ID {$record['id']}: " . $e->getMessage());
                    $controlNumber = '[DECRYPT ERROR]';
                    $decryptionFailed = true;
                }
                
                if ($decryptionFailed) {
                    $decryptionErrors++;
                }
                
                // Apply search filter
                if (!empty($filterSearch) && !$loadAll) {
                    $match = false;
                    $searchLower = mb_strtolower(trim($filterSearch), 'UTF-8');
                    
                    // Method 1: Direct substring match
                    if (mb_stripos($name, $filterSearch) !== false) {
                        $match = true;
                    }
                    
                    // Method 2: Control number match
                    if (!$match && mb_stripos($controlNumber, $filterSearch) !== false) {
                        $match = true;
                    }
                    
                    // Method 3: Token-based matching
                    if (!$match) {
                        $searchTokens = preg_split('/\s+/', $searchLower);
                        $allTokensFound = true;
                        foreach ($searchTokens as $token) {
                            if (empty($token)) continue;
                            if (mb_stripos($name, $token) === false && mb_stripos($controlNumber, $token) === false) {
                                $allTokensFound = false;
                                break;
                            }
                        }
                        if ($allTokensFound && count($searchTokens) > 0) {
                            $match = true;
                        }
                    }
                    
                    if (!$match) {
                        continue;
                    }
                    
                    $matchedCount++;
                }
                
                // Check display limit
                if (!empty($filterSearch) && count($records) >= $displayLimit) {
                    $moreResultsAvailable = true;
                    continue;
                }
                
                $obfuscatedName = obfuscateName($name);
                
                $records[] = [
                    'id' => $record['id'],
                    'name' => $name,
                    'obfuscated_name' => $obfuscatedName,
                    'control_number' => $controlNumber,
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
                error_log("Unexpected error processing legacy ID {$record['id']}: " . $e->getMessage());
                $decryptionErrors++;
            }
        }
        
    } catch (Exception $e) {
        error_log("Query error: " . $e->getMessage());
        $error = 'Error loading records.';
    }
}

// Get districts
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

$totalRecords = count($records);

$pageTitle = 'Legacy Control Numbers';
ob_start();
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <div class="flex items-center justify-between flex-wrap gap-4">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                    Legacy Control Numbers
                    <span class="text-sm px-3 py-1 bg-blue-100 text-blue-800 rounded-full font-medium">
                        <?php echo number_format($totalInDatabase); ?> total
                    </span>
                </h1>
                <p class="text-sm text-gray-500 mt-1">Legacy officer control numbers</p>
            </div>
            <div class="flex gap-2">
                <a href="import.php" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                    </svg>
                    Import CSV
                </a>
            </div>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
            <?php echo Security::escape($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg">
            <?php echo Security::escape($success); ?>
        </div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <form method="GET" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">District</label>
                    <select name="district" id="district" onchange="loadLocalsForFilter(this.value)" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="">All Districts</option>
                        <?php foreach ($districts as $district): ?>
                            <option value="<?php echo Security::escape($district['district_code']); ?>" 
                                <?php echo $filterDistrict === $district['district_code'] ? 'selected' : ''; ?>>
                                <?php echo Security::escape($district['district_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Local</label>
                    <select name="local" id="local" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="">All Locals</option>
                        <?php foreach ($locals as $local): ?>
                            <option value="<?php echo Security::escape($local['local_code']); ?>"
                                <?php echo $filterLocal === $local['local_code'] ? 'selected' : ''; ?>>
                                <?php echo Security::escape($local['local_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Linked Status</label>
                    <select name="linked" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="all" <?php echo $filterLinked === 'all' ? 'selected' : ''; ?>>All</option>
                        <option value="linked" <?php echo $filterLinked === 'linked' ? 'selected' : ''; ?>>Linked</option>
                        <option value="unlinked" <?php echo $filterLinked === 'unlinked' ? 'selected' : ''; ?>>Unlinked</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Search</label>
                    <input 
                        type="text" 
                        name="search" 
                        value="<?php echo Security::escape($filterSearch); ?>"
                        placeholder="Name or control#..." 
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                    >
                </div>
            </div>

            <div class="flex gap-3">
                <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-medium">
                    Filter
                </button>
                <a href="list.php" class="px-6 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors font-medium">
                    Clear
                </a>
                <?php
                $loadAllUrl = 'list.php?load_all=1';
                if (!empty($filterDistrict)) $loadAllUrl .= '&district=' . urlencode($filterDistrict);
                if (!empty($filterLocal)) $loadAllUrl .= '&local=' . urlencode($filterLocal);
                if ($filterLinked !== 'all') $loadAllUrl .= '&linked=' . urlencode($filterLinked);
                ?>
                <a href="<?php echo $loadAllUrl; ?>" class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors font-medium">
                    Load All Records
                </a>
            </div>
        </form>
    </div>

    <!-- Statistics -->
    <?php if ($searchProvided): ?>
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
        <p class="text-sm text-blue-900">
            <strong>Search Statistics:</strong>
            Found <?php echo count($records); ?> records
            <?php if (!empty($filterSearch) && isset($matchedCount) && $matchedCount > count($records)): ?>
                (<?php echo $matchedCount; ?> total matches, showing first <?php echo count($records); ?>)
            <?php endif; ?>
            - Scanned <?php echo $totalScanned; ?> records from database
            <?php if ($decryptionErrors > 0): ?>
                - <span class="text-orange-700"><?php echo $decryptionErrors; ?> decryption errors</span>
            <?php endif; ?>
        </p>
    </div>
    <?php endif; ?>

    <!-- Records Table -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Control Number</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">District/Local</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Link Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Imported</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($records) && !$searchProvided): ?>
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center text-gray-500">
                                <svg class="w-12 h-12 mx-auto text-gray-400 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                </svg>
                                <p class="text-lg font-medium mb-1">Search to view records</p>
                                <p class="text-sm">Enter a search term above or click "Load All Records"</p>
                            </td>
                        </tr>
                    <?php elseif (empty($records)): ?>
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center text-gray-500">
                                <p class="text-lg font-medium mb-1">No records found</p>
                                <p class="text-sm">Try adjusting your search criteria</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($records as $record): ?>
                            <tr class="hover:bg-gray-50 <?php echo $record['has_decrypt_error'] ? 'bg-red-50' : ''; ?>">
                                <td class="px-6 py-4">
                                    <?php if ($record['has_decrypt_error']): ?>
                                        <div class="flex items-center text-red-700">
                                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                                            </svg>
                                            <span class="text-sm font-medium">Decryption Error</span>
                                        </div>
                                        <p class="text-xs text-red-600 mt-1">ID: <?php echo $record['id']; ?></p>
                                    <?php else: ?>
                                        <span class="text-sm font-medium text-gray-900" title="<?php echo Security::escape($record['name']); ?>">
                                            <?php echo Security::escape($record['obfuscated_name']); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="text-sm font-mono text-gray-900">
                                        <?php echo Security::escape($record['control_number']); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="text-sm text-gray-900"><?php echo Security::escape($record['district_name']); ?></div>
                                    <div class="text-xs text-gray-500"><?php echo Security::escape($record['local_name']); ?></div>
                                </td>
                                <td class="px-6 py-4">
                                    <?php if ($record['linked_officer_id']): ?>
                                        <span class="inline-flex items-center px-2 py-1 bg-green-100 text-green-800 text-xs font-medium rounded">
                                            <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path>
                                            </svg>
                                            Linked
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2 py-1 bg-gray-100 text-gray-600 text-xs font-medium rounded">
                                            Unlinked
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500">
                                    <?php echo date('M d, Y', strtotime($record['imported_at'])); ?>
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

<script>
async function loadLocalsForFilter(districtCode) {
    const localSelect = document.getElementById('local');
    
    if (!districtCode) {
        localSelect.innerHTML = '<option value="">All Locals</option>';
        return;
    }
    
    try {
        const response = await fetch('../api/get-locals.php?district=' + districtCode);
        const data = await response.json();
        
        let html = '<option value="">All Locals</option>';
        
        if (Array.isArray(data)) {
            data.forEach(local => {
                html += `<option value="${local.local_code}">${local.local_name}</option>`;
            });
        }
        
        localSelect.innerHTML = html;
    } catch (error) {
        console.error('Error loading locals:', error);
    }
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
