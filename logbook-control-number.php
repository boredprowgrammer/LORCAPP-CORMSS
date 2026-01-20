<?php
require_once __DIR__ . '/config/config.php';

Security::requireLogin();
requirePermission('can_view_officers');

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

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

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Get officers with control numbers
try {
    $stmt = $db->prepare("
        SELECT 
            o.officer_id,
            o.officer_uuid,
            o.last_name_encrypted,
            o.first_name_encrypted,
            o.middle_initial_encrypted,
            o.district_code,
            o.local_code,
            o.purok,
            o.grupo,
            o.control_number,
            o.registry_number_encrypted,
            o.is_active,
            o.created_at,
            d.district_name,
            lc.local_name,
            GROUP_CONCAT(DISTINCT od.department SEPARATOR ', ') as departments,
            GROUP_CONCAT(DISTINCT DATE_FORMAT(od.oath_date, '%Y-%m-%d') SEPARATOR ', ') as oath_dates
        FROM officers o
        LEFT JOIN districts d ON o.district_code = d.district_code
        LEFT JOIN local_congregations lc ON o.local_code = lc.local_code
        LEFT JOIN officer_departments od ON o.officer_id = od.officer_id AND od.is_active = 1
        $whereClause
        GROUP BY o.officer_id
        ORDER BY 
            CASE 
                WHEN o.control_number IS NULL OR o.control_number = '' THEN 1
                ELSE 0
            END,
            CAST(o.control_number AS UNSIGNED) ASC,
            o.control_number ASC
    ");
    
    $stmt->execute($params);
    $allOfficers = $stmt->fetchAll();
    
    // Decrypt names and prepare data
    $officers = [];
    $controlNumbers = [];
    
    foreach ($allOfficers as $officer) {
        $decrypted = Encryption::decryptOfficerName(
            $officer['last_name_encrypted'],
            $officer['first_name_encrypted'],
            $officer['middle_initial_encrypted'],
            $officer['district_code']
        );
        
        $officer['last_name'] = $decrypted['last_name'];
        $officer['first_name'] = $decrypted['first_name'];
        $officer['middle_initial'] = $decrypted['middle_initial'];
        
        // Decrypt registry number if available
        if (!empty($officer['registry_number_encrypted'])) {
            try {
                $officer['registry_number'] = Encryption::decrypt(
                    $officer['registry_number_encrypted'],
                    $officer['district_code']
                );
            } catch (Exception $e) {
                $officer['registry_number'] = '';
            }
        } else {
            $officer['registry_number'] = '';
        }
        
        $officers[] = $officer;
        
        // Track control numbers for discrepancy detection
        if (!empty($officer['control_number'])) {
            $cn = $officer['control_number'];
            if (!isset($controlNumbers[$cn])) {
                $controlNumbers[$cn] = [];
            }
            $controlNumbers[$cn][] = $officer;
        }
    }
    
    // Find discrepancies
    $discrepancies = [];
    
    // 1. Duplicate control numbers
    foreach ($controlNumbers as $cn => $officers_with_cn) {
        if (count($officers_with_cn) > 1) {
            $discrepancies[] = [
                'type' => 'Duplicate Control Number',
                'control_number' => $cn,
                'description' => 'Control number ' . $cn . ' is assigned to ' . count($officers_with_cn) . ' officers',
                'officers' => $officers_with_cn,
                'severity' => 'high'
            ];
        }
    }
    
    // 2. Missing control numbers
    $missingControlNumbers = array_filter($officers, function($o) {
        return empty($o['control_number']);
    });
    
    if (count($missingControlNumbers) > 0) {
        $discrepancies[] = [
            'type' => 'Missing Control Numbers',
            'control_number' => 'N/A',
            'description' => count($missingControlNumbers) . ' officers do not have a control number assigned',
            'officers' => $missingControlNumbers,
            'severity' => 'medium'
        ];
    }
    
    // 3. Check for gaps in control number sequence
    $controlNumbersOnly = array_filter(array_keys($controlNumbers), function($cn) {
        return is_numeric($cn) || preg_match('/^\d+$/', $cn);
    });
    
    if (!empty($controlNumbersOnly)) {
        $controlNumbersNumeric = array_map('intval', $controlNumbersOnly);
        sort($controlNumbersNumeric);
        
        $gaps = [];
        for ($i = 0; $i < count($controlNumbersNumeric) - 1; $i++) {
            $current = $controlNumbersNumeric[$i];
            $next = $controlNumbersNumeric[$i + 1];
            
            if ($next - $current > 1) {
                $gapStart = $current + 1;
                $gapEnd = $next - 1;
                $gaps[] = [
                    'start' => str_pad($gapStart, 4, '0', STR_PAD_LEFT),
                    'end' => str_pad($gapEnd, 4, '0', STR_PAD_LEFT)
                ];
            }
        }
        
        if (!empty($gaps)) {
            $gapDescription = 'Gaps found in control number sequence: ';
            $gapList = array_map(function($gap) {
                return $gap['start'] . ($gap['start'] !== $gap['end'] ? '-' . $gap['end'] : '');
            }, $gaps);
            $gapDescription .= implode(', ', $gapList);
            
            $discrepancies[] = [
                'type' => 'Sequence Gaps',
                'control_number' => 'N/A',
                'description' => $gapDescription,
                'officers' => [],
                'severity' => 'low'
            ];
        }
    }
    
} catch (Exception $e) {
    error_log("Logbook control number error: " . $e->getMessage());
    $officers = [];
    $discrepancies = [];
    $missingControlNumbers = [];
}

$pageTitle = 'Logbook Control Number';
$pageActions = [];

ob_start();
?>

<div class="space-y-6">
    <!-- Header with Stats -->
    <div class="bg-gradient-to-r from-blue-500 to-blue-600 rounded-lg shadow-lg p-6 text-white no-print">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-2xl sm:text-3xl font-bold">Logbook Control Number Registry</h1>
                <p class="text-blue-100 mt-2">Track and manage control numbers with discrepancy detection</p>
            </div>
            <div class="flex gap-2">
                <button 
                    onclick="window.open('<?php echo BASE_URL; ?>/logbook-control-number-pdf.php', '_blank')"
                    class="inline-flex items-center px-4 py-2 bg-red-500 hover:bg-red-600 text-white font-semibold rounded-lg shadow-lg transition-all duration-200"
                >
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                    </svg>
                    Export PDF
                </button>
                <?php if (!empty($discrepancies)): ?>
                <button 
                    onclick="openDiscrepancyModal()"
                    class="inline-flex items-center px-4 py-2 bg-yellow-500 hover:bg-yellow-600 text-white font-semibold rounded-lg shadow-lg transition-all duration-200 transform hover:scale-105"
                >
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                    </svg>
                    View Discrepancies
                    <span class="ml-2 px-2 py-1 text-xs font-bold bg-white text-yellow-600 rounded-full">
                        <?php echo count($discrepancies); ?>
                    </span>
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Print Header (only visible when printing) -->
    <div class="print-only">
        <div class="text-center mb-6">
            <h1 class="text-3xl font-bold text-gray-900">Logbook Control Number Registry</h1>
            <p class="text-gray-600 mt-2">Church Officers Registry and Management System</p>
            <p class="text-sm text-gray-500 mt-1">Printed on: <?php echo date('F d, Y'); ?></p>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 no-print">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-4">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Total Officers</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-gray-100 mt-1"><?php echo number_format(count($officers)); ?></p>
                </div>
                <div class="p-3 bg-blue-100 rounded-full">
                    <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                    </svg>
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-4">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-600 dark:text-gray-400">With Control #</p>
                    <p class="text-2xl font-bold text-green-600 dark:text-green-400 mt-1"><?php echo number_format(count($officers) - count($missingControlNumbers)); ?></p>
                </div>
                <div class="p-3 bg-green-100 rounded-full">
                    <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-4">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Missing Control #</p>
                    <p class="text-2xl font-bold text-red-600 dark:text-red-400 mt-1"><?php echo number_format(count($missingControlNumbers)); ?></p>
                </div>
                <div class="p-3 bg-red-100 rounded-full">
                    <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
            </div>
        </div>
    </div>

    <!-- Print Summary (only visible when printing) -->
    <div class="print-only">
        <div class="bg-gray-100 border border-gray-300 p-4 mb-4">
            <h2 class="text-lg font-bold mb-2">Summary Statistics</h2>
            <div class="grid grid-cols-3 gap-4 text-sm">
                <div>
                    <strong>Total Officers:</strong> <?php echo number_format(count($officers)); ?>
                </div>
                <div>
                    <strong>With Control #:</strong> <?php echo number_format(count($officers) - count($missingControlNumbers)); ?>
                </div>
                <div>
                    <strong>Missing Control #:</strong> <?php echo number_format(count($missingControlNumbers)); ?>
                </div>
            </div>
            <?php if (!empty($discrepancies)): ?>
            <div class="mt-3 pt-3 border-t border-gray-400">
                <strong>Discrepancies Found:</strong> <?php echo count($discrepancies); ?> issue<?php echo count($discrepancies) !== 1 ? 's' : ''; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Officers Table -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
        <div class="px-4 sm:px-6 py-4 border-b border-gray-200 bg-gray-50">
            <h2 class="text-lg font-semibold text-gray-900 flex items-center">
                <svg class="w-5 h-5 mr-2 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                </svg>
                Officers Registry
            </h2>
        </div>
        <div class="overflow-x-auto">
            <table id="logbookTable" class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="px-3 py-1.5 text-center text-xs font-medium text-gray-500 uppercase tracking-wider" style="width: 15%;">Control #</th>
                        <th scope="col" class="px-3 py-1.5 text-center text-xs font-medium text-gray-500 uppercase tracking-wider" style="width: 35%;">Officer Name</th>
                        <th scope="col" class="px-3 py-1.5 text-center text-xs font-medium text-gray-500 uppercase tracking-wider" style="width: 30%;">Department</th>
                        <th scope="col" class="px-3 py-1.5 text-center text-xs font-medium text-gray-500 uppercase tracking-wider" style="width: 20%;">Oath Date</th>
                    </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                    <?php foreach ($officers as $officer): ?>
                    <tr class="hover:bg-gray-50 transition-colors">
                        <td class="px-3 py-1.5 text-center">
                            <?php if (!empty($officer['control_number'])): ?>
                                <span class="text-sm font-mono font-semibold text-gray-900 px-2 py-0.5 bg-blue-50 rounded"><?php echo Security::escape($officer['control_number']); ?></span>
                            <?php else: ?>
                                <span class="text-xs text-red-500 font-semibold px-2 py-0.5 bg-red-50 rounded">NOT SET</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-3 py-1.5 text-center">
                            <?php 
                            $fullName = $officer['last_name'] . ', ' . $officer['first_name'];
                            if (!empty($officer['middle_initial'])) {
                                $fullName .= ' ' . $officer['middle_initial'] . '.';
                            }
                            ?>
                            <div class="text-sm font-medium text-gray-900" 
                                 data-search="<?php echo Security::escape($fullName); ?>"
                                 title="<?php echo Security::escape($fullName); ?>">
                                <?php echo Security::escape(obfuscateName($fullName)); ?>
                            </div>
                        </td>
                        <td class="px-3 py-1.5 text-center">
                            <?php if (!empty($officer['departments'])): ?>
                                <span class="text-xs text-gray-700"><?php echo Security::escape($officer['departments']); ?></span>
                            <?php else: ?>
                                <span class="text-xs text-gray-400">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-3 py-1.5 text-center">
                            <?php 
                            if (!empty($officer['oath_dates'])) {
                                $oathDates = explode(', ', $officer['oath_dates']);
                                $firstOathDate = $oathDates[0];
                                if ($firstOathDate && $firstOathDate !== '0000-00-00') {
                                    echo '<span class="text-xs text-gray-700">' . date('M d, Y', strtotime($firstOathDate)) . '</span>';
                                } else {
                                    echo '<span class="text-xs text-gray-400">-</span>';
                                }
                            } else {
                                echo '<span class="text-xs text-gray-400">-</span>';
                            }
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Discrepancy Modal -->
<div id="discrepancyModal" class="hidden fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
    <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
        <!-- Background overlay -->
        <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true" onclick="closeDiscrepancyModal()"></div>

        <!-- Center modal -->
        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

        <!-- Modal panel -->
        <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-4xl sm:w-full">
            <!-- Header -->
            <div class="bg-gradient-to-r from-yellow-500 to-orange-500 px-6 py-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <svg class="w-6 h-6 text-white mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                        </svg>
                        <h3 class="text-xl font-bold text-white" id="modal-title">Discrepancy Tracker</h3>
                        <span class="ml-3 px-3 py-1 text-sm font-bold bg-white text-orange-600 rounded-full">
                            <?php echo count($discrepancies); ?> issue<?php echo count($discrepancies) !== 1 ? 's' : ''; ?>
                        </span>
                    </div>
                    <button onclick="closeDiscrepancyModal()" class="text-white hover:text-gray-200 transition-colors">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Body -->
            <div class="bg-white px-6 py-4 max-h-[70vh] overflow-y-auto">
                <?php if (!empty($discrepancies)): ?>
                <div class="space-y-4">
                    <?php foreach ($discrepancies as $index => $discrepancy): ?>
                    <div class="border-l-4 rounded-lg p-4 <?php 
                        echo $discrepancy['severity'] === 'high' ? 'border-red-500 bg-red-50' : 
                            ($discrepancy['severity'] === 'medium' ? 'border-yellow-500 bg-yellow-50' : 'border-blue-500 bg-blue-50'); 
                    ?>">
                        <div class="flex items-start justify-between mb-3">
                            <div class="flex-1">
                                <div class="flex items-center gap-2 mb-2">
                                    <span class="px-3 py-1 text-xs font-bold rounded-full <?php 
                                        echo $discrepancy['severity'] === 'high' ? 'bg-red-200 text-red-800' : 
                                            ($discrepancy['severity'] === 'medium' ? 'bg-yellow-200 text-yellow-800' : 'bg-blue-200 text-blue-800'); 
                                    ?>">
                                        <?php echo Security::escape($discrepancy['type']); ?>
                                    </span>
                                    <?php if ($discrepancy['control_number'] !== 'N/A'): ?>
                                    <span class="text-sm font-mono font-bold text-gray-900 px-2 py-1 bg-white rounded shadow-sm">
                                        #<?php echo Security::escape($discrepancy['control_number']); ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                                <p class="text-sm font-medium text-gray-900"><?php echo Security::escape($discrepancy['description']); ?></p>
                            </div>
                        </div>
                        
                        <?php if (!empty($discrepancy['officers']) && $discrepancy['type'] !== 'Sequence Gaps'): ?>
                        <div class="mt-3">
                            <button 
                                onclick="toggleModalDetails(<?php echo $index; ?>)"
                                class="text-sm font-semibold text-blue-600 hover:text-blue-800 flex items-center"
                            >
                                <span id="modal-toggle-text-<?php echo $index; ?>">Show Details (<?php echo count($discrepancy['officers']); ?> officers)</span>
                                <svg id="modal-toggle-icon-<?php echo $index; ?>" class="w-4 h-4 ml-1 transform transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                </svg>
                            </button>
                            <div id="modal-details-<?php echo $index; ?>" class="hidden mt-3 space-y-2">
                                <?php foreach ($discrepancy['officers'] as $officer): ?>
                                <div class="bg-white border border-gray-200 rounded-lg p-3 hover:shadow-md transition-shadow">
                                    <div class="flex items-center justify-between">
                                        <div class="flex-1">
                                            <p class="text-sm font-semibold text-gray-900">
                                                <?php echo Security::escape($officer['last_name'] . ', ' . $officer['first_name']); ?>
                                                <?php if (!empty($officer['middle_initial'])): ?>
                                                    <?php echo Security::escape($officer['middle_initial']); ?>.
                                                <?php endif; ?>
                                            </p>
                                            <p class="text-xs text-gray-600 mt-1">
                                                <?php echo Security::escape($officer['local_name']); ?>
                                                <?php if (!empty($officer['purok']) || !empty($officer['grupo'])): ?>
                                                    • Purok <?php echo Security::escape($officer['purok'] ?: '-'); ?> 
                                                    • Grupo <?php echo Security::escape($officer['grupo'] ?: '-'); ?>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                        <a href="<?php echo BASE_URL; ?>/officers/view.php?id=<?php echo Security::escape($officer['officer_uuid']); ?>" 
                                           target="_blank"
                                           class="ml-4 inline-flex items-center px-3 py-1.5 text-xs font-semibold text-blue-600 bg-blue-50 hover:bg-blue-100 rounded-lg transition-colors">
                                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                                            </svg>
                                            View
                                        </a>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="text-center py-8">
                    <svg class="w-16 h-16 text-green-500 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <p class="text-lg font-semibold text-gray-900">No Discrepancies Found</p>
                    <p class="text-sm text-gray-600 mt-2">All control numbers are properly assigned and sequenced.</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Footer -->
            <div class="bg-gray-50 px-6 py-4 flex justify-end">
                <button 
                    onclick="closeDiscrepancyModal()"
                    class="px-4 py-2 bg-gray-200 hover:bg-gray-300 text-gray-800 font-semibold rounded-lg transition-colors"
                >
                    Close
                </button>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();

// DataTables CSS
$extraStyles = <<<'STYLES'
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
<style>
/* Custom DataTables Styling */
.dataTables_wrapper {
    padding: 0.5rem;
}

/* DataTables Dark Mode */
.dark .dataTables_wrapper .dataTables_length,
.dark .dataTables_wrapper .dataTables_filter,
.dark .dataTables_wrapper .dataTables_info,
.dark .dataTables_wrapper .dataTables_processing,
.dark .dataTables_wrapper .dataTables_paginate {
    color: #e5e7eb !important;
}
.dark .dataTables_wrapper .dataTables_filter input,
.dark .dataTables_wrapper .dataTables_length select {
    background-color: #374151 !important;
    border: 1px solid #4b5563 !important;
    color: #f3f4f6 !important;
    padding: 0.375rem 0.75rem;
    border-radius: 0.5rem;
}
.dark table.dataTable thead th,
.dark table.dataTable thead td {
    background-color: #1f2937 !important;
    border-bottom: 2px solid #374151 !important;
    color: #f3f4f6 !important;
}
.dark table.dataTable tbody tr {
    background-color: #111827 !important;
}
.dark table.dataTable tbody tr:hover {
    background-color: #1f2937 !important;
}
.dark table.dataTable tbody td {
    border-top: 1px solid #374151 !important;
    color: #e5e7eb !important;
}
.dark .dataTables_wrapper .dataTables_paginate .paginate_button {
    color: #e5e7eb !important;
    background: transparent !important;
    border: 1px solid #4b5563 !important;
}
.dark .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
    background: #374151 !important;
    border: 1px solid #4b5563 !important;
    color: #f3f4f6 !important;
}
.dark .dataTables_wrapper .dataTables_paginate .paginate_button.current {
    background: #3b82f6 !important;
    border: 1px solid #3b82f6 !important;
    color: white !important;
}
.dark .dataTables_wrapper .dataTables_paginate .paginate_button.disabled {
    color: #6b7280 !important;
    background: transparent !important;
    border: 1px solid #374151 !important;
}

.dataTables_filter input {
    border: 1px solid #d1d5db;
    border-radius: 0.5rem;
    padding: 0.5rem 1rem;
    margin-left: 0.5rem;
    font-size: 0.875rem;
}

.dataTables_filter input:focus {
    outline: none;
    border-color: #3b82f6;
    ring: 2px;
    ring-color: #3b82f6;
}

.dataTables_length select {
    border: 1px solid #d1d5db;
    border-radius: 0.5rem;
    padding: 0.5rem 2rem 0.5rem 1rem;
    margin: 0 0.5rem;
    font-size: 0.875rem;
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e");
    background-position: right 0.5rem center;
    background-repeat: no-repeat;
    background-size: 1.5em 1.5em;
}

.dataTables_paginate .paginate_button {
    padding: 0.5rem 0.75rem;
    margin: 0 0.125rem;
    border-radius: 0.375rem;
    border: 1px solid #d1d5db;
    background: white;
    color: #374151;
    font-size: 0.875rem;
}

.dataTables_paginate .paginate_button:hover {
    background: #f3f4f6;
    border-color: #9ca3af;
    color: #1f2937;
}

.dataTables_paginate .paginate_button.current {
    background: #3b82f6;
    border-color: #3b82f6;
    color: white;
}

.dataTables_paginate .paginate_button.disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.dataTables_info {
    color: #6b7280;
    font-size: 0.875rem;
}

table.dataTable thead th {
    font-weight: 600;
    text-align: center !important;
    vertical-align: middle !important;
}

table.dataTable tbody td {
    text-align: center !important;
    vertical-align: middle !important;
}

/* Fix column widths */
table.dataTable thead th:nth-child(1),
table.dataTable tbody td:nth-child(1) {
    width: 15% !important;
}

table.dataTable thead th:nth-child(2),
table.dataTable tbody td:nth-child(2) {
    width: 35% !important;
}

table.dataTable thead th:nth-child(3),
table.dataTable tbody td:nth-child(3) {
    width: 30% !important;
}

table.dataTable thead th:nth-child(4),
table.dataTable tbody td:nth-child(4) {
    width: 20% !important;
}

/* Print Styles */
.print-only {
    display: none;
}

@media print {
    /* Hide elements not needed in print */
    .no-print,
    .dataTables_wrapper .dataTables_filter,
    .dataTables_wrapper .dataTables_length,
    .dataTables_wrapper .dataTables_info,
    .dataTables_wrapper .dataTables_paginate {
        display: none !important;
    }
    
    /* Show print-only elements */
    .print-only {
        display: block !important;
    }
    
    /* Page setup */
    @page {
        size: landscape;
        margin: 1cm;
    }
    
    body {
        font-size: 10pt;
        line-height: 1.3;
    }
    
    /* Table styling for print */
    table {
        width: 100%;
        border-collapse: collapse;
        page-break-inside: auto;
    }
    
    tr {
        page-break-inside: avoid;
        page-break-after: auto;
    }
    
    thead {
        display: table-header-group;
    }
    
    tfoot {
        display: table-footer-group;
    }
    
    /* Remove shadows and backgrounds */
    * {
        box-shadow: none !important;
        background-image: none !important;
    }
    
    .bg-gradient-to-r,
    .bg-blue-50,
    .bg-green-50,
    .bg-red-50,
    .bg-gray-50 {
        background: white !important;
    }
    
    /* Table borders */
    table, th, td {
        border: 1px solid #333 !important;
    }
    
    th {
        background-color: #f3f4f6 !important;
        font-weight: bold;
        padding: 8px !important;
    }
    
    td {
        padding: 6px !important;
    }
    
    /* Ensure text is black for better print quality */
    body, th, td, p, span, div {
        color: #000 !important;
    }
    
    /* Make badges visible */
    .bg-green-100, .bg-red-100, .bg-blue-50, .bg-red-50 {
        border: 1px solid #333 !important;
        background: #f9f9f9 !important;
    }
    
    /* Hide action column if needed */
    .print-hide-actions {
        display: none !important;
    }
}
</style>
STYLES;

// DataTables JS
$extraScripts = <<<'SCRIPTS'
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>

<script>
$(document).ready(function() {
    // Custom search function to search data-search attribute for names
    $.fn.dataTable.ext.search.push(
        function(settings, searchData, index, rowData, counter) {
            var searchTerm = $('.dataTables_filter input').val().toLowerCase();
            if (!searchTerm) return true;
            
            // Get the row element
            var row = $(settings.nTable).find('tbody tr').eq(index);
            var nameCell = row.find('td:eq(1) div');
            var actualName = nameCell.attr('data-search');
            
            // Search in control number, actual name, department, and oath date
            var controlNum = searchData[0].toLowerCase();
            var department = searchData[2].toLowerCase();
            var oathDate = searchData[3].toLowerCase();
            
            if (actualName) {
                actualName = actualName.toLowerCase();
                if (actualName.indexOf(searchTerm) !== -1) return true;
            }
            
            if (controlNum.indexOf(searchTerm) !== -1) return true;
            if (department.indexOf(searchTerm) !== -1) return true;
            if (oathDate.indexOf(searchTerm) !== -1) return true;
            
            return false;
        }
    );
    
    var table = $('#logbookTable').DataTable({
        "pageLength": 50,
        "order": [[0, 'asc']],
        "ordering": false,
        "autoWidth": false,
        "scrollX": false,
        "search": {
            "smart": true,
            "regex": false,
            "caseInsensitive": true
        },
        "columnDefs": [
            {
                "targets": 0,
                "width": "15%",
                "className": "text-center",
                "type": "num"
            },
            {
                "targets": 1,
                "width": "35%",
                "className": "text-center"
            },
            {
                "targets": 2,
                "width": "30%",
                "className": "text-center"
            },
            {
                "targets": 3,
                "width": "20%",
                "className": "text-center"
            }
        ],
        "language": {
            "search": "Instant Lookup:",
            "searchPlaceholder": "Search by control #, name, department...",
            "lengthMenu": "Show _MENU_ entries per page",
            "info": "Showing _START_ to _END_ of _TOTAL_ officers",
            "infoEmpty": "Showing 0 to 0 of 0 officers",
            "infoFiltered": "(filtered from _MAX_ total officers)",
            "paginate": {
                "first": "First",
                "last": "Last",
                "next": "Next",
                "previous": "Previous"
            }
        },
        "lengthMenu": [[25, 50, 100, -1], [25, 50, 100, "All"]],
        "dom": '<"flex flex-col sm:flex-row justify-between items-center mb-4"lf>rt<"flex flex-col sm:flex-row justify-between items-center mt-4"ip>'
    });
    
    // Focus on search input and add placeholder
    setTimeout(function() {
        var searchInput = $('.dataTables_filter input');
        searchInput.attr('placeholder', 'Search by control #, name, department...');
        searchInput.focus();
    }, 100);
});

// Modal functions
function openDiscrepancyModal() {
    document.getElementById('discrepancyModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeDiscrepancyModal() {
    document.getElementById('discrepancyModal').classList.add('hidden');
    document.body.style.overflow = 'auto';
}

// Toggle details in modal
function toggleModalDetails(index) {
    const details = document.getElementById('modal-details-' + index);
    const toggleText = document.getElementById('modal-toggle-text-' + index);
    const toggleIcon = document.getElementById('modal-toggle-icon-' + index);
    
    if (details.classList.contains('hidden')) {
        details.classList.remove('hidden');
        const count = details.querySelectorAll('.bg-white').length;
        toggleText.textContent = 'Hide Details (' + count + ' officers)';
        toggleIcon.style.transform = 'rotate(180deg)';
    } else {
        details.classList.add('hidden');
        const count = details.querySelectorAll('.bg-white').length;
        toggleText.textContent = 'Show Details (' + count + ' officers)';
        toggleIcon.style.transform = 'rotate(0deg)';
    }
}

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeDiscrepancyModal();
    }
});
</script>
SCRIPTS;

include __DIR__ . '/includes/layout.php';
?>
