<?php
/**
 * R5's Transactions
 * Track newly oath officers, transfers, and removals
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/ui-components.php';

Security::requireLogin();
requirePermission('can_view_reports');

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

// Get filter parameters
$filterPeriod = Security::sanitizeInput($_GET['period'] ?? 'month');
$filterMonth = Security::sanitizeInput($_GET['month'] ?? date('m'));
$filterYear = Security::sanitizeInput($_GET['year'] ?? date('Y'));
$filterDistrict = Security::sanitizeInput($_GET['district'] ?? '');
$filterLocal = Security::sanitizeInput($_GET['local'] ?? '');
$searchQuery = Security::sanitizeInput($_GET['search'] ?? '');
$activeTab = Security::sanitizeInput($_GET['tab'] ?? 'reports');

// Build WHERE clause based on user role
$roleConditions = [];
$roleParams = [];

if ($currentUser['role'] === 'district') {
    $roleConditions[] = 'o.district_code = ?';
    $roleParams[] = $currentUser['district_code'];
    $filterDistrict = $currentUser['district_code'];
} elseif ($currentUser['role'] === 'local') {
    $roleConditions[] = 'o.local_code = ?';
    $roleParams[] = $currentUser['local_code'];
}

// Get districts for filter
$districts = [];
try {
    if ($currentUser['role'] === 'admin') {
        $stmt = $db->query("SELECT district_code, district_name FROM districts ORDER BY district_name");
        $districts = $stmt->fetchAll();
    } elseif ($currentUser['role'] === 'district') {
        $stmt = $db->prepare("SELECT district_code, district_name FROM districts WHERE district_code = ?");
        $stmt->execute([$currentUser['district_code']]);
        $districts = $stmt->fetchAll();
    }
} catch (Exception $e) {
    error_log("Load districts error: " . $e->getMessage());
}

// Get locals for filter
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

// Date range based on period
$startDate = '';
$endDate = date('Y-m-d');

if ($filterPeriod === 'week') {
    $startDate = date('Y-m-d', strtotime('monday this week'));
} elseif ($filterPeriod === 'month') {
    // Use selected month and year
    $startDate = $filterYear . '-' . str_pad($filterMonth, 2, '0', STR_PAD_LEFT) . '-01';
    $endDate = date('Y-m-t', strtotime($startDate));
} elseif ($filterPeriod === 'year') {
    $startDate = $filterYear . '-01-01';
    $endDate = $filterYear . '-12-31';
}

$pageTitle = "R5's Transactions";
ob_start();
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 p-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100">R5's Transactions</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Track newly oath officers, transfers, and removals</p>
            </div>
            <div class="flex items-center gap-3">
                <a href="r5-transactions-logsheet.php?period=<?php echo urlencode($filterPeriod); ?>&month=<?php echo urlencode($filterMonth); ?>&year=<?php echo urlencode($filterYear); ?>&district=<?php echo urlencode($filterDistrict); ?>&local=<?php echo urlencode($filterLocal); ?>" 
                   target="_blank"
                   class="inline-flex items-center gap-2 px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded-lg transition-colors duration-200">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>
                    </svg>
                    Print Logsheet
                </a>
                <svg class="w-8 h-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                </svg>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 p-6">
        <form method="GET" action="" class="space-y-4">
            <input type="hidden" name="tab" value="<?php echo Security::escape($activeTab); ?>">
            
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <!-- Period Filter -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Period</label>
                    <select name="period" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100" onchange="this.form.submit()">
                        <option value="week" <?php echo $filterPeriod === 'week' ? 'selected' : ''; ?>>This Week</option>
                        <option value="month" <?php echo $filterPeriod === 'month' ? 'selected' : ''; ?>>By Month</option>
                        <option value="year" <?php echo $filterPeriod === 'year' ? 'selected' : ''; ?>>By Year</option>
                    </select>
                </div>

                <!-- Month Filter (shown when period is month) -->
                <?php if ($filterPeriod === 'month'): ?>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Month</label>
                    <select name="month" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100" onchange="this.form.submit()">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo str_pad($m, 2, '0', STR_PAD_LEFT); ?>" <?php echo $filterMonth == str_pad($m, 2, '0', STR_PAD_LEFT) ? 'selected' : ''; ?>>
                                <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <?php endif; ?>

                <!-- Year Filter (shown when period is month or year) -->
                <?php if ($filterPeriod === 'month' || $filterPeriod === 'year'): ?>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Year</label>
                    <select name="year" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100" onchange="this.form.submit()">
                        <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $filterYear == $y ? 'selected' : ''; ?>>
                                <?php echo $y; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <?php endif; ?>

                <?php if ($currentUser['role'] === 'admin'): ?>
                <!-- District Filter -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">District</label>
                    <select name="district" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100" onchange="this.form.submit()">
                        <option value="">All Districts</option>
                        <?php foreach ($districts as $district): ?>
                            <option value="<?php echo Security::escape($district['district_code']); ?>" <?php echo $filterDistrict === $district['district_code'] ? 'selected' : ''; ?>>
                                <?php echo Security::escape($district['district_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <?php if ($currentUser['role'] !== 'local' && !empty($filterDistrict)): ?>
                <!-- Local Filter -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Local</label>
                    <select name="local" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100" onchange="this.form.submit()">
                        <option value="">All Locals</option>
                        <?php foreach ($locals as $local): ?>
                            <option value="<?php echo Security::escape($local['local_code']); ?>" <?php echo $filterLocal === $local['local_code'] ? 'selected' : ''; ?>>
                                <?php echo Security::escape($local['local_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Tabs -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200">
        <div class="border-b border-gray-200 dark:border-gray-700">
            <nav class="flex -mb-px">
                <a href="?tab=reports&period=<?php echo urlencode($filterPeriod); ?>&district=<?php echo urlencode($filterDistrict); ?>&local=<?php echo urlencode($filterLocal); ?>" 
                   class="px-6 py-4 text-sm font-medium <?php echo $activeTab === 'reports' ? 'border-b-2 border-blue-500 text-blue-600' : 'text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>">
                    <svg class="w-5 h-5 inline-block mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                    </svg>
                    Reports
                </a>
                <a href="?tab=transactions&period=<?php echo urlencode($filterPeriod); ?>&district=<?php echo urlencode($filterDistrict); ?>&local=<?php echo urlencode($filterLocal); ?>" 
                   class="px-6 py-4 text-sm font-medium <?php echo $activeTab === 'transactions' ? 'border-b-2 border-blue-500 text-blue-600' : 'text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>">
                    <svg class="w-5 h-5 inline-block mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                    Transactions Search
                </a>
            </nav>
        </div>

        <div class="p-6">
            <?php if ($activeTab === 'reports'): ?>
                <?php include __DIR__ . '/r5-transactions-reports.php'; ?>
            <?php else: ?>
                <?php include __DIR__ . '/r5-transactions-search.php'; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
// Render the reusable officer details modal
renderOfficerDetailsModal();

$content = ob_get_clean();

// Add the JavaScript file for the officer modal
$extraScripts = '<script src="' . BASE_URL . '/assets/js/officer-details-modal.js"></script>';

include __DIR__ . '/../includes/layout.php';
?>
