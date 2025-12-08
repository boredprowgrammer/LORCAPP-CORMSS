<?php
require_once __DIR__ . '/../config/config.php';

Security::requireLogin();
requirePermission('can_view_headcount');

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

// Build WHERE clause based on user role
$whereConditions = [];
$params = [];

if ($currentUser['role'] === 'local') {
    $whereConditions[] = 'h.local_code = ?';
    $params[] = $currentUser['local_code'];
} elseif ($currentUser['role'] === 'district') {
    $whereConditions[] = 'h.district_code = ?';
    $params[] = $currentUser['district_code'];
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Get headcount data
try {
    $stmt = $db->prepare("
        SELECT 
            h.*,
            d.district_name,
            lc.local_name
        FROM headcount h
        JOIN districts d ON h.district_code = d.district_code
        JOIN local_congregations lc ON h.local_code = lc.local_code
        $whereClause
        ORDER BY d.district_name, lc.local_name
    ");
    $stmt->execute($params);
    $headcounts = $stmt->fetchAll();
    
    // Get totals by district
    $stmt = $db->prepare("
        SELECT 
            d.district_code,
            d.district_name,
            SUM(h.total_count) as total
        FROM headcount h
        JOIN districts d ON h.district_code = d.district_code
        $whereClause
        GROUP BY d.district_code, d.district_name
        ORDER BY total DESC
    ");
    $stmt->execute($params);
    $districtTotals = $stmt->fetchAll();
    
    // Get overall total
    $stmt = $db->prepare("SELECT SUM(total_count) as grand_total FROM headcount h $whereClause");
    $stmt->execute($params);
    $grandTotal = $stmt->fetch()['grand_total'] ?? 0;
    
} catch (Exception $e) {
    error_log("Headcount report error: " . $e->getMessage());
    $headcounts = [];
    $districtTotals = [];
    $grandTotal = 0;
}

$pageTitle = 'Headcount Report';
ob_start();
?>

<div class="space-y-4">
    <!-- Header -->
    <div class="flex items-center justify-between p-4 bg-white rounded-lg shadow-sm border border-gray-200">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Headcount Report</h1>
            <p class="text-sm text-gray-500">Real-time officer headcount across locations</p>
        </div>
        <div class="inline-flex items-center px-3 py-1.5 rounded-lg text-sm font-semibold bg-blue-600 text-white">
            <?php echo number_format($grandTotal); ?> Total
        </div>
    </div>
    
    <!-- District Totals -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
        <div class="flex items-center gap-2 mb-3">
            <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"></path>
            </svg>
            <h3 class="font-semibold text-gray-900">By District</h3>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
            <?php foreach ($districtTotals as $district): ?>
                <div class="p-4 bg-gray-50 rounded-lg hover:shadow transition-shadow border border-gray-100">
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-sm font-medium truncate flex-1 text-gray-900"><?php echo Security::escape($district['district_name']); ?></p>
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800"><?php echo number_format($district['total']); ?></span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-1.5">
                        <?php 
                        $percentage = $grandTotal > 0 ? ($district['total'] / $grandTotal * 100) : 0;
                        ?>
                        <div class="bg-blue-600 h-1.5 rounded-full transition-all" 
                             style="width: <?php echo $percentage; ?>%"></div>
                    </div>
                    <p class="text-xs text-gray-500 mt-1"><?php echo number_format($percentage, 1); ?>% of total</p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- Detailed Table -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
        <div class="flex items-center gap-2 mb-3">
            <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
            </svg>
            <h3 class="font-semibold text-gray-900">Detailed Breakdown</h3>
        </div>
        
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">District</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Local Congregation</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Count</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Updated</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($headcounts)): ?>
                        <tr>
                            <td colspan="4" class="text-center py-8">
                                <svg class="w-12 h-12 text-gray-300 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                                </svg>
                                <p class="text-sm text-gray-500">No data available</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($headcounts as $hc): ?>
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-4 py-3">
                                    <div class="text-sm font-medium text-gray-900"><?php echo Security::escape($hc['district_name']); ?></div>
                                    <div class="text-xs text-gray-500"><?php echo Security::escape($hc['district_code']); ?></div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="text-sm font-medium text-gray-900"><?php echo Security::escape($hc['local_name']); ?></div>
                                    <div class="text-xs text-gray-500"><?php echo Security::escape($hc['local_code']); ?></div>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">
                                        <?php echo number_format($hc['total_count']); ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="text-xs text-gray-500"><?php echo formatDateTime($hc['last_updated']); ?></div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($headcounts)): ?>
                    <tfoot class="bg-gray-50">
                        <tr class="font-bold border-t-2 border-gray-300">
                            <td colspan="2" class="px-4 py-3 text-sm text-gray-900">TOTAL</td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">
                                    <?php echo number_format($grandTotal); ?>
                                </span>
                            </td>
                            <td class="px-4 py-3"></td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
