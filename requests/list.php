<?php
/**
 * Officer Requests List - Revamped UI
 * Modern standalone interface for managing officer requests
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/permissions.php';

Security::requireLogin();

$db = Database::getInstance()->getConnection();
$user = getCurrentUser();

// Check permissions
$canManage = in_array($user['role'], ['admin', 'district', 'local']);

// Get filter parameters
$statusFilter = $_GET['status'] ?? 'all';
$searchQuery = $_GET['search'] ?? '';

// Build query
$query = "SELECT 
    r.request_id,
    r.last_name_encrypted,
    r.first_name_encrypted,
    r.middle_initial_encrypted,
    r.record_code,
    r.existing_officer_uuid,
    r.district_code,
    r.local_code,
    r.requested_department,
    r.requested_duty,
    r.status,
    r.requested_at,
    r.seminar_date,
    r.oath_scheduled_date,
    r.is_imported,
    r.lorcapp_id,
    d.district_name,
    l.local_name,
    u.full_name as requested_by_name,
    o.last_name_encrypted as existing_last_name,
    o.first_name_encrypted as existing_first_name,
    o.middle_initial_encrypted as existing_middle_initial,
    o.district_code as existing_district_code
FROM officer_requests r
LEFT JOIN districts d ON r.district_code = d.district_code
LEFT JOIN local_congregations l ON r.local_code = l.local_code
LEFT JOIN users u ON r.requested_by = u.user_id
LEFT JOIN officers o ON r.existing_officer_uuid = o.officer_uuid
WHERE 1=1";

$params = [];

// Role-based filtering
if ($user['role'] === 'district') {
    $query .= " AND r.district_code = ?";
    $params[] = $user['district_code'];
} elseif ($user['role'] === 'local') {
    $query .= " AND r.local_code = ?";
    $params[] = $user['local_code'];
}

// Status filter
if ($statusFilter === 'all') {
    $query .= " AND r.status != 'oath_taken'";
} elseif ($statusFilter !== 'all') {
    $query .= " AND r.status = ?";
    $params[] = $statusFilter;
}

// Search filter
if (!empty($searchQuery)) {
    $query .= " AND (r.requested_department LIKE ? OR d.district_name LIKE ? OR l.local_name LIKE ?";
    $searchParam = "%$searchQuery%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    
    $words = preg_split('/\s+/', strtolower($searchQuery));
    foreach ($words as $word) {
        if (strlen($word) < 2) continue;
        $query .= " OR LOWER(AES_DECRYPT(r.last_name_encrypted, UNHEX(SHA2(r.district_code, 256)))) LIKE ?";
        $query .= " OR LOWER(AES_DECRYPT(r.first_name_encrypted, UNHEX(SHA2(r.district_code, 256)))) LIKE ?";
        $params[] = "%$word%";
        $params[] = "%$word%";
        $query .= " OR LOWER(AES_DECRYPT(o.last_name_encrypted, UNHEX(SHA2(o.district_code, 256)))) LIKE ?";
        $query .= " OR LOWER(AES_DECRYPT(o.first_name_encrypted, UNHEX(SHA2(o.district_code, 256)))) LIKE ?";
        $params[] = "%$word%";
        $params[] = "%$word%";
    }
    $query .= ")";
}

$query .= " ORDER BY r.requested_at DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get status counts
$countsQuery = "SELECT status, COUNT(*) as count FROM officer_requests WHERE 1=1";
$countsParams = [];

if ($user['role'] === 'district') {
    $countsQuery .= " AND district_code = ?";
    $countsParams[] = $user['district_code'];
} elseif ($user['role'] === 'local') {
    $countsQuery .= " AND local_code = ?";
    $countsParams[] = $user['local_code'];
}

$countsQuery .= " GROUP BY status";
$countsStmt = $db->prepare($countsQuery);
$countsStmt->execute($countsParams);
$statusCounts = $countsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Status configuration
$statusConfig = [
    'pending' => ['label' => 'Pending', 'color' => 'yellow', 'bg' => 'bg-yellow-100 dark:bg-yellow-900/30', 'text' => 'text-yellow-700 dark:text-yellow-400'],
    'requested_to_seminar' => ['label' => 'For Seminar', 'color' => 'blue', 'bg' => 'bg-blue-100 dark:bg-blue-900/30', 'text' => 'text-blue-700 dark:text-blue-400'],
    'in_seminar' => ['label' => 'In Seminar', 'color' => 'indigo', 'bg' => 'bg-indigo-100 dark:bg-indigo-900/30', 'text' => 'text-indigo-700 dark:text-indigo-400'],
    'seminar_completed' => ['label' => 'Seminar Done', 'color' => 'teal', 'bg' => 'bg-teal-100 dark:bg-teal-900/30', 'text' => 'text-teal-700 dark:text-teal-400'],
    'requested_to_oath' => ['label' => 'For Oath', 'color' => 'purple', 'bg' => 'bg-purple-100 dark:bg-purple-900/30', 'text' => 'text-purple-700 dark:text-purple-400'],
    'ready_to_oath' => ['label' => 'Ready', 'color' => 'pink', 'bg' => 'bg-pink-100 dark:bg-pink-900/30', 'text' => 'text-pink-700 dark:text-pink-400'],
    'oath_taken' => ['label' => 'Completed', 'color' => 'green', 'bg' => 'bg-green-100 dark:bg-green-900/30', 'text' => 'text-green-700 dark:text-green-400'],
    'rejected' => ['label' => 'Rejected', 'color' => 'red', 'bg' => 'bg-red-100 dark:bg-red-900/30', 'text' => 'text-red-700 dark:text-red-400'],
    'cancelled' => ['label' => 'Cancelled', 'color' => 'gray', 'bg' => 'bg-gray-100 dark:bg-gray-700', 'text' => 'text-gray-700 dark:text-gray-400']
];

$pageTitle = 'Officer Requests';
?>
<!DOCTYPE html>
<html lang="en" x-data="{ darkMode: localStorage.getItem('darkMode') === 'true', showFilters: false }" :class="{ 'dark': darkMode }">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - Church Officers Registry</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'sans-serif'] }
                }
            }
        }
    </script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        .request-card { transition: all 0.2s ease; }
        .request-card:hover { transform: translateY(-2px); }
        .status-pill { font-size: 0.7rem; letter-spacing: 0.025em; }
        .scrollbar-thin::-webkit-scrollbar { width: 6px; }
        .scrollbar-thin::-webkit-scrollbar-track { background: transparent; }
        .scrollbar-thin::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.2); border-radius: 3px; }
        .dark .scrollbar-thin::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900 min-h-screen">

    <!-- Header -->
    <header class="bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 sticky top-0 z-40">
        <div class="max-w-7xl mx-auto px-4 sm:px-6">
            <div class="flex items-center justify-between h-14">
                <!-- Left: Navigation -->
                <div class="flex items-center gap-3">
                    <a href="<?php echo BASE_URL; ?>/launchpad.php" class="p-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl transition-colors" title="Launchpad">
                        <svg class="w-5 h-5 text-gray-600 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path>
                        </svg>
                    </a>
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 bg-gradient-to-br from-rose-500 to-pink-600 rounded-xl flex items-center justify-center shadow-lg">
                            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path>
                            </svg>
                        </div>
                        <div>
                            <h1 class="text-base font-bold text-gray-900 dark:text-white">Officer Requests</h1>
                            <p class="text-xs text-gray-500 dark:text-gray-400 hidden sm:block">Manage applications</p>
                        </div>
                    </div>
                </div>

                <!-- Right: Actions -->
                <div class="flex items-center gap-2">
                    <?php if ($user['role'] !== 'admin'): ?>
                    <a href="add.php" class="hidden sm:inline-flex items-center gap-2 px-4 py-2 bg-rose-600 hover:bg-rose-700 text-white text-sm font-medium rounded-xl transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                        </svg>
                        New Request
                    </a>
                    <?php endif; ?>
                    
                    <button @click="darkMode = !darkMode; localStorage.setItem('darkMode', darkMode)" class="p-2 rounded-xl hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                        <svg x-show="!darkMode" class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path>
                        </svg>
                        <svg x-show="darkMode" class="w-5 h-5 text-yellow-400" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M12 2.25a.75.75 0 01.75.75v2.25a.75.75 0 01-1.5 0V3a.75.75 0 01.75-.75zM7.5 12a4.5 4.5 0 119 0 4.5 4.5 0 01-9 0z"></path>
                        </svg>
                    </button>
                    
                    <div class="flex items-center gap-2 px-3 py-1.5 bg-gray-100 dark:bg-gray-700 rounded-xl">
                        <div class="w-7 h-7 rounded-full bg-gradient-to-br from-rose-500 to-pink-600 flex items-center justify-center text-white text-xs font-medium">
                            <?php echo strtoupper(substr($user['username'], 0, 2)); ?>
                        </div>
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300 hidden sm:block"><?php echo htmlspecialchars($user['username']); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 py-6">
        
        <!-- Flash Messages -->
        <?php if (!empty($_SESSION['success'])): ?>
        <div class="mb-6 bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 rounded-xl p-4 flex items-center gap-3">
            <svg class="w-5 h-5 text-green-600 dark:text-green-400 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
            </svg>
            <span class="text-sm font-medium text-green-800 dark:text-green-300"><?php echo Security::escape($_SESSION['success']); ?></span>
        </div>
        <?php unset($_SESSION['success']); endif; ?>
        
        <?php if (!empty($_SESSION['error'])): ?>
        <div class="mb-6 bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 rounded-xl p-4 flex items-center gap-3">
            <svg class="w-5 h-5 text-red-600 dark:text-red-400 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
            </svg>
            <span class="text-sm font-medium text-red-800 dark:text-red-300"><?php echo Security::escape($_SESSION['error']); ?></span>
        </div>
        <?php unset($_SESSION['error']); endif; ?>

        <!-- Status Pipeline -->
        <div class="mb-6 overflow-x-auto scrollbar-thin pb-2">
            <div class="flex gap-2 min-w-max">
                <a href="?status=all" class="flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-medium transition-all <?php echo $statusFilter === 'all' ? 'bg-gray-900 dark:bg-white text-white dark:text-gray-900' : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border border-gray-200 dark:border-gray-700 hover:border-gray-400'; ?>">
                    <span>All</span>
                    <span class="px-2 py-0.5 rounded-full text-xs <?php echo $statusFilter === 'all' ? 'bg-white/20' : 'bg-gray-100 dark:bg-gray-700'; ?>"><?php echo array_sum($statusCounts) - ($statusCounts['oath_taken'] ?? 0); ?></span>
                </a>
                <?php 
                $pipelineStatuses = ['pending', 'requested_to_seminar', 'in_seminar', 'seminar_completed', 'requested_to_oath', 'ready_to_oath', 'oath_taken'];
                foreach ($pipelineStatuses as $status): 
                    $count = $statusCounts[$status] ?? 0;
                    $config = $statusConfig[$status];
                    $isActive = $statusFilter === $status;
                ?>
                <a href="?status=<?php echo $status; ?>" class="flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-medium transition-all <?php echo $isActive ? $config['bg'] . ' ' . $config['text'] . ' ring-2 ring-' . $config['color'] . '-400' : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border border-gray-200 dark:border-gray-700 hover:border-gray-400'; ?>">
                    <span><?php echo $config['label']; ?></span>
                    <span class="px-2 py-0.5 rounded-full text-xs <?php echo $isActive ? 'bg-white/30 dark:bg-black/20' : 'bg-gray-100 dark:bg-gray-700'; ?>"><?php echo $count; ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Search & Actions Bar -->
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 mb-6">
            <form method="GET" class="flex flex-col sm:flex-row gap-4">
                <input type="hidden" name="status" value="<?php echo Security::escape($statusFilter); ?>">
                
                <div class="flex-1 relative">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                    <input type="text" name="search" value="<?php echo Security::escape($searchQuery); ?>" placeholder="Search by name, department, location..." class="w-full pl-10 pr-4 py-2.5 bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:ring-2 focus:ring-rose-500 focus:border-rose-500">
                </div>
                
                <div class="flex gap-2">
                    <button type="submit" class="px-5 py-2.5 bg-rose-600 hover:bg-rose-700 text-white text-sm font-medium rounded-xl transition-colors">
                        Search
                    </button>
                    <?php if (!empty($searchQuery)): ?>
                    <a href="?status=<?php echo Security::escape($statusFilter); ?>" class="px-5 py-2.5 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 text-sm font-medium rounded-xl hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors">
                        Clear
                    </a>
                    <?php endif; ?>
                </div>
            </form>
            
            <?php if ($canManage): ?>
            <div class="flex flex-wrap gap-2 mt-4 pt-4 border-t border-gray-100 dark:border-gray-700">
                <a href="import-from-lorcapp.php" class="inline-flex items-center gap-2 px-3 py-1.5 text-xs font-medium text-purple-700 dark:text-purple-400 bg-purple-50 dark:bg-purple-900/30 rounded-lg hover:bg-purple-100 dark:hover:bg-purple-900/50 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3V10"></path></svg>
                    Import LORCAPP
                </a>
                <a href="link-to-lorcapp.php" class="inline-flex items-center gap-2 px-3 py-1.5 text-xs font-medium text-indigo-700 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-900/30 rounded-lg hover:bg-indigo-100 dark:hover:bg-indigo-900/50 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path></svg>
                    Link LORCAPP
                </a>
                <a href="bulk-palasumpaan.php" class="inline-flex items-center gap-2 px-3 py-1.5 text-xs font-medium text-green-700 dark:text-green-400 bg-green-50 dark:bg-green-900/30 rounded-lg hover:bg-green-100 dark:hover:bg-green-900/50 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    Bulk Palasumpaan
                </a>
            </div>
            <?php endif; ?>
        </div>

        <!-- Requests Grid -->
        <?php if (empty($requests)): ?>
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-12 text-center">
            <div class="w-16 h-16 mx-auto mb-4 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center">
                <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                </svg>
            </div>
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-1">No requests found</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400">Try adjusting your search or filters</p>
        </div>
        <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
            <?php foreach ($requests as $request):
                // Decrypt name
                try {
                    if (!empty($request['existing_officer_uuid']) && !empty($request['existing_last_name'])) {
                        $districtCode = $request['existing_district_code'] ?? $request['district_code'];
                        $decrypted = Encryption::decryptOfficerName(
                            $request['existing_last_name'],
                            $request['existing_first_name'],
                            $request['existing_middle_initial'],
                            $districtCode
                        );
                    } elseif (!empty($request['last_name_encrypted'])) {
                        $decrypted = Encryption::decryptOfficerName(
                            $request['last_name_encrypted'],
                            $request['first_name_encrypted'],
                            $request['middle_initial_encrypted'],
                            $request['district_code']
                        );
                    } else {
                        throw new Exception("No name data");
                    }
                    
                    $lastName = $decrypted['last_name'] ?? '';
                    $firstName = $decrypted['first_name'] ?? '';
                    $middleInitial = $decrypted['middle_initial'] ?? '';
                    $fullName = empty($lastName) && empty($firstName) ? '[Name Unavailable]' : "$lastName, $firstName" . ($middleInitial ? " $middleInitial." : "");
                } catch (Exception $e) {
                    $fullName = '[Name Unavailable]';
                }
                
                $statusInfo = $statusConfig[$request['status']] ?? $statusConfig['pending'];
                $initials = strtoupper(substr($fullName, 0, 1));
            ?>
            <div class="request-card bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden hover:shadow-lg hover:border-rose-300 dark:hover:border-rose-600">
                <!-- Card Header -->
                <div class="p-4 border-b border-gray-100 dark:border-gray-700">
                    <div class="flex items-start gap-3">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-rose-500 to-pink-600 flex items-center justify-center text-white font-semibold text-lg flex-shrink-0">
                            <?php echo $initials; ?>
                        </div>
                        <div class="flex-1 min-w-0">
                            <h3 class="font-semibold text-gray-900 dark:text-white truncate"><?php echo Security::escape($fullName); ?></h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400 truncate"><?php echo Security::escape($request['local_name']); ?> • <?php echo Security::escape($request['district_name']); ?></p>
                        </div>
                        <span class="status-pill px-2 py-1 rounded-lg font-semibold uppercase <?php echo $statusInfo['bg'] . ' ' . $statusInfo['text']; ?>">
                            <?php echo $statusInfo['label']; ?>
                        </span>
                    </div>
                </div>
                
                <!-- Card Body -->
                <div class="p-4 space-y-3">
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                        </svg>
                        <span class="text-sm text-gray-700 dark:text-gray-300"><?php echo Security::escape($request['requested_department']); ?></span>
                        <?php if ($request['requested_duty']): ?>
                        <span class="text-xs text-gray-400">• <?php echo Security::escape($request['requested_duty']); ?></span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                        <span class="flex items-center gap-1">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                            </svg>
                            <?php echo date('M j, Y', strtotime($request['requested_at'])); ?>
                        </span>
                        <?php if ($request['seminar_date']): ?>
                        <span class="flex items-center gap-1 text-indigo-600 dark:text-indigo-400">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                            </svg>
                            Seminar: <?php echo date('M j', strtotime($request['seminar_date'])); ?>
                        </span>
                        <?php endif; ?>
                        <?php if ($request['oath_scheduled_date']): ?>
                        <span class="flex items-center gap-1 text-purple-600 dark:text-purple-400">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 11.5V14m0-2.5v-6a1.5 1.5 0 113 0m-3 6a1.5 1.5 0 00-3 0v2a7.5 7.5 0 0015 0v-5a1.5 1.5 0 00-3 0m-6-3V11m0-5.5v-1a1.5 1.5 0 013 0v1m0 0V11m0-5.5a1.5 1.5 0 013 0v3m0 0V11"></path>
                            </svg>
                            Oath: <?php echo date('M j', strtotime($request['oath_scheduled_date'])); ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Card Footer -->
                <div class="px-4 py-3 bg-gray-50 dark:bg-gray-800/50 border-t border-gray-100 dark:border-gray-700 flex items-center justify-between">
                    <span class="text-xs text-gray-500 dark:text-gray-400">by <?php echo Security::escape($request['requested_by_name'] ?? 'Unknown'); ?></span>
                    <div class="flex items-center gap-2">
                        <a href="view.php?id=<?php echo $request['request_id']; ?>" class="px-3 py-1.5 text-xs font-medium text-rose-700 dark:text-rose-400 bg-rose-50 dark:bg-rose-900/30 rounded-lg hover:bg-rose-100 dark:hover:bg-rose-900/50 transition-colors">
                            View Details
                        </a>
                        <?php if ($canManage && $request['status'] !== 'oath_taken'): ?>
                        <a href="view.php?id=<?php echo $request['request_id']; ?>#workflow" class="px-3 py-1.5 text-xs font-medium text-green-700 dark:text-green-400 bg-green-50 dark:bg-green-900/30 rounded-lg hover:bg-green-100 dark:hover:bg-green-900/50 transition-colors">
                            Update
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Results Count -->
        <div class="mt-6 text-center text-sm text-gray-500 dark:text-gray-400">
            Showing <?php echo count($requests); ?> request<?php echo count($requests) !== 1 ? 's' : ''; ?>
        </div>
        <?php endif; ?>

    </main>

    <!-- Mobile FAB -->
    <?php if ($user['role'] !== 'admin'): ?>
    <a href="add.php" class="sm:hidden fixed bottom-6 right-6 w-14 h-14 bg-rose-600 hover:bg-rose-700 text-white rounded-full shadow-lg flex items-center justify-center z-50 transition-colors">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
        </svg>
    </a>
    <?php endif; ?>

</body>
</html>

