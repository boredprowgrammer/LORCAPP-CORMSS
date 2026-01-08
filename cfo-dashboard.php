<?php
/**
 * CFO Dashboard
 * Dashboard for Local CFO users - restricted to CFO registry access only
 */

require_once __DIR__ . '/config/config.php';

Security::requireLogin();

// Restrict access to local_cfo role only
$currentUser = getCurrentUser();
if ($currentUser['role'] !== 'local_cfo') {
    $_SESSION['error'] = 'Access denied. This page is only for CFO coordinators.';
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$db = Database::getInstance()->getConnection();

$pageTitle = 'CFO Dashboard';
ob_start();

// Get CFO statistics for the user's local
$stats = [
    'total' => 0,
    'buklod' => 0,
    'kadiwa' => 0,
    'binhi' => 0,
    'active' => 0,
    'transferred' => 0,
    'new_this_month' => 0,
    'inactive' => 0
];

try {
    $localCode = $currentUser['local_code'];
    
    // Total CFO members
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM tarheta_control WHERE local_code = ?");
    $stmt->execute([$localCode]);
    $stats['total'] = $stmt->fetch()['total'];
    
    // By classification
    $stmt = $db->prepare("
        SELECT 
            cfo_classification,
            COUNT(*) as count 
        FROM tarheta_control 
        WHERE local_code = ?
        GROUP BY cfo_classification
    ");
    $stmt->execute([$localCode]);
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
        WHERE local_code = ?
        GROUP BY cfo_status
    ");
    $stmt->execute([$localCode]);
    while ($row = $stmt->fetch()) {
        if ($row['cfo_status'] === 'active') {
            $stats['active'] = $row['count'];
        } elseif ($row['cfo_status'] === 'transferred-out') {
            $stats['transferred'] = $row['count'];
        }
    }
    
    // New members this month
    $stmt = $db->prepare("
        SELECT COUNT(*) as count 
        FROM tarheta_control 
        WHERE local_code = ? 
        AND DATE_FORMAT(created_at, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')
    ");
    $stmt->execute([$localCode]);
    $stats['new_this_month'] = $stmt->fetch()['count'];
    
    // Get local info
    $stmt = $db->prepare("
        SELECT lc.local_name, d.district_name 
        FROM local_congregations lc
        LEFT JOIN districts d ON lc.district_code = d.district_code
        WHERE lc.local_code = ?
    ");
    $stmt->execute([$localCode]);
    $localInfo = $stmt->fetch();
    
} catch (Exception $e) {
    error_log("Error loading CFO dashboard stats: " . $e->getMessage());
}
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="bg-gradient-to-r from-purple-600 to-pink-600 rounded-lg shadow-lg p-6 text-white">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-3xl font-bold mb-2">CFO Dashboard</h1>
                <p class="text-purple-100 flex items-center">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                    </svg>
                    <?php echo Security::escape($localInfo['local_name'] ?? 'Local Congregation'); ?> - 
                    <?php echo Security::escape($localInfo['district_name'] ?? 'District'); ?>
                </p>
            </div>
            <div class="hidden md:block">
                <svg class="w-20 h-20 text-white opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                </svg>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <a href="cfo-registry.php" class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 hover:shadow-md transition-all duration-200 hover:border-blue-400 group">
            <div class="flex items-center">
                <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center group-hover:bg-blue-200 transition-colors">
                    <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                </div>
                <div class="ml-4">
                    <h3 class="text-lg font-semibold text-gray-900 group-hover:text-blue-600">CFO Registry</h3>
                    <p class="text-sm text-gray-500">Manage CFO members</p>
                </div>
                <div class="ml-auto">
                    <svg class="w-6 h-6 text-gray-400 group-hover:text-blue-600 transform group-hover:translate-x-1 transition-all" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </div>
            </div>
        </a>

        <a href="cfo-add.php" class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 hover:shadow-md transition-all duration-200 hover:border-green-400 group">
            <div class="flex items-center">
                <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center group-hover:bg-green-200 transition-colors">
                    <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                    </svg>
                </div>
                <div class="ml-4">
                    <h3 class="text-lg font-semibold text-gray-900 group-hover:text-green-600">Add CFO Member</h3>
                    <p class="text-sm text-gray-500">Register new member</p>
                </div>
                <div class="ml-auto">
                    <svg class="w-6 h-6 text-gray-400 group-hover:text-green-600 transform group-hover:translate-x-1 transition-all" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </div>
            </div>
        </a>
    </div>

    <!-- Statistics Overview -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-lg shadow-lg p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-blue-100 font-medium">Total Members</p>
                    <p class="text-4xl font-bold mt-2"><?php echo number_format($stats['total']); ?></p>
                    <p class="text-xs text-blue-100 mt-2">All CFO members</p>
                </div>
                <div class="w-16 h-16 bg-white bg-opacity-20 rounded-full flex items-center justify-center">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                    </svg>
                </div>
            </div>
        </div>

        <div class="bg-gradient-to-br from-purple-500 to-purple-600 rounded-lg shadow-lg p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-purple-100 font-medium">Buklod<p>
                    <p class="text-4xl font-bold mt-2"><?php echo number_format($stats['buklod']); ?></p>
                    <p class="text-xs text-purple-100 mt-2">Married Couples</p>
                </div>
                <div class="w-16 h-16 bg-white bg-opacity-20 rounded-full f/lex items-center justify-center">
                    <i class="fa-solid fa-rings-wedding text-3xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-lg shadow-lg p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-green-100 font-medium">Kadiwa</p>
                    <p class="text-4xl font-bold mt-2"><?php echo number_format($stats['kadiwa']); ?></p>
                    <p class="text-xs text-green-100 mt-2">Youth Members</p>
                </div>
                <div class="w-16 h-16 bg-white bg-opacity-20 rounded-full flex items-center justify-center">
                    <i class="fa-solid fa-user-group text-3xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-gradient-to-br from-orange-500 to-orange-600 rounded-lg shadow-lg p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-orange-100 font-medium">Binhi</p>
                    <p class="text-4xl font-bold mt-2"><?php echo number_format($stats['binhi']); ?></p>
                    <p class="text-xs text-orange-100 mt-2">Children</p>
                </div>
                <div class="w-16 h-16 bg-white bg-opacity-20 rounded-full flex items-center justify-center">
                    <i class="fa-solid fa-seedling text-3xl"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Status Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-900">Active Members</h3>
                <div class="w-10 h-10 bg-green-100 rounded-full flex items-center justify-center">
                    <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
            </div>
            <p class="text-3xl font-bold text-gray-900"><?php echo number_format($stats['active']); ?></p>
            <p class="text-sm text-gray-500 mt-2">Currently active members</p>
        </div>

        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-900">Transferred Out</h3>
                <div class="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center">
                    <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
                    </svg>
                </div>
            </div>
            <p class="text-3xl font-bold text-gray-900"><?php echo number_format($stats['transferred']); ?></p>
            <p class="text-sm text-gray-500 mt-2">Transferred to other locals</p>
        </div>

        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-900">New This Month</h3>
                <div class="w-10 h-10 bg-purple-100 rounded-full flex items-center justify-center">
                    <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                    </svg>
                </div>
            </div>
            <p class="text-3xl font-bold text-gray-900"><?php echo number_format($stats['new_this_month']); ?></p>
            <p class="text-sm text-gray-500 mt-2">Registered this month</p>
        </div>
    </div>

    <!-- Welcome Message -->
    <div class="bg-gradient-to-r from-indigo-50 to-purple-50 border-l-4 border-indigo-500 p-6 rounded-r-lg">
        <div class="flex items-start">
            <svg class="w-6 h-6 text-indigo-500 mr-3 mt-1 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
            </svg>
            <div>
                <h3 class="text-lg font-semibold text-indigo-900 mb-2">Welcome, CFO Coordinator!</h3>
                <p class="text-indigo-800 mb-2">You have access to the CFO Registry management system. Here you can:</p>
                <ul class="list-disc list-inside text-sm text-indigo-700 space-y-1">
                    <li>View and manage CFO members (Buklod, Kadiwa, Binhi)</li>
                    <li>Add new CFO members to your local congregation</li>
                    <li>Update member classification and status</li>
                    <li>Export CFO data to Excel</li>
                    <li>Track member statistics and reports</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Recent Activity -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h2 class="text-xl font-bold text-gray-900 mb-4 flex items-center">
            <svg class="w-5 h-5 mr-2 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            Recent Activity
        </h2>
        <?php
        try {
            $stmt = $db->prepare("
                SELECT 
                    CONCAT(first_name, ' ', last_name) as name,
                    cfo_classification,
                    created_at
                FROM tarheta_control
                WHERE local_code = ?
                ORDER BY created_at DESC
                LIMIT 5
            ");
            $stmt->execute([$localCode]);
            $recentMembers = $stmt->fetchAll();
            
            if (count($recentMembers) > 0): ?>
                <div class="space-y-3">
                    <?php foreach ($recentMembers as $member): ?>
                        <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors">
                            <div class="flex items-center">
                                <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-purple-500 rounded-full flex items-center justify-center text-white font-bold">
                                    <?php echo strtoupper(substr($member['name'], 0, 1)); ?>
                                </div>
                                <div class="ml-3">
                                    <p class="font-semibold text-gray-900"><?php echo Security::escape($member['name']); ?></p>
                                    <p class="text-sm text-gray-500"><?php echo Security::escape($member['cfo_classification'] ?? 'Unclassified'); ?></p>
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="text-xs text-gray-500"><?php echo date('M d, Y', strtotime($member['created_at'])); ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-8 text-gray-500">
                    <svg class="w-16 h-16 mx-auto text-gray-300 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                    </svg>
                    <p>No recent activity</p>
                </div>
            <?php endif;
        } catch (Exception $e) {
            error_log("Error loading recent activity: " . $e->getMessage());
        }
        ?>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/includes/layout.php';
?>
