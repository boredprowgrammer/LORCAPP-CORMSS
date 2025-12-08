<?php
require_once __DIR__ . '/../config/config.php';

Security::requireLogin();
Security::requireRole('admin');

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

// Get filter parameters
$filterAction = Security::sanitizeInput($_GET['action'] ?? '');
$filterUser = intval($_GET['user_id'] ?? 0);
$currentPage = max(1, intval($_GET['page'] ?? 1));

// Build WHERE clause
$whereConditions = [];
$params = [];

if (!empty($filterAction)) {
    $whereConditions[] = 'al.action LIKE ?';
    $params[] = "%$filterAction%";
}

if ($filterUser > 0) {
    $whereConditions[] = 'al.user_id = ?';
    $params[] = $filterUser;
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Get total count
try {
    $countStmt = $db->prepare("SELECT COUNT(*) as total FROM audit_log al $whereClause");
    $countStmt->execute($params);
    $totalRecords = $countStmt->fetch()['total'];
    
    $pagination = paginate($totalRecords, $currentPage, 50); // 50 per page for audit logs
    
    // Get audit logs
    $stmt = $db->prepare("
        SELECT 
            al.*,
            u.username,
            u.full_name
        FROM audit_log al
        LEFT JOIN users u ON al.user_id = u.user_id
        $whereClause
        ORDER BY al.created_at DESC
        LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}
    ");
    
    $stmt->execute($params);
    $logs = $stmt->fetchAll();
    
    // Get users for filter
    $stmt = $db->query("SELECT user_id, username, full_name FROM users ORDER BY full_name");
    $users = $stmt->fetchAll();
    
} catch (Exception $e) {
    error_log("Audit log error: " . $e->getMessage());
    $logs = [];
    $users = [];
}

$pageTitle = 'Audit Log';
ob_start();
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h2 class="text-3xl font-bold">Audit Log</h2>
            <p class="text-sm opacity-70">System activity tracking and monitoring</p>
        </div>
    </div>
    
    <!-- Filters -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <form method="GET" action="" class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Action</label>
                <input 
                    type="text" 
                    name="action" 
                    placeholder="Search action..." 
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                    value="<?php echo Security::escape($filterAction); ?>"
                >
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">User</label>
                <select name="user_id" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors">
                    <option value="0">All Users</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?php echo $user['user_id']; ?>"
                            <?php echo $filterUser == $user['user_id'] ? 'selected' : ''; ?>>
                            <?php echo Security::escape($user['full_name'] . ' (@' . $user['username'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">&nbsp;</label>
                <div class="flex gap-2">
                    <button type="submit" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition-colors flex-1">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                        Filter
                    </button>
                    <a href="<?php echo BASE_URL; ?>/admin/audit.php" class="inline-flex items-center p-2 border border-gray-300 rounded-lg text-gray-600 hover:bg-gray-50 transition-colors">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </a>
                </div>
            </div>
        </form>
    </div>
    
    <!-- Stats -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <div class="text-sm text-gray-500 mb-1">Total Logs</div>
            <div class="text-3xl font-bold text-blue-600"><?php echo number_format($totalRecords); ?></div>
            <div class="text-xs text-gray-400 mt-1">All recorded activities</div>
        </div>
        
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <div class="text-sm text-gray-500 mb-1">Current Page</div>
            <div class="text-3xl font-bold text-purple-600"><?php echo $pagination['current_page']; ?> / <?php echo $pagination['total_pages']; ?></div>
            <div class="text-xs text-gray-400 mt-1">Viewing <?php echo $pagination['per_page']; ?> per page</div>
        </div>
    </div>
    
    <!-- Audit Log Table -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Timestamp</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Action</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Details</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">IP Address</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-8">
                                <svg class="w-12 h-12 text-gray-300 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                                </svg>
                                <p class="text-sm text-gray-500">No audit logs found</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-700">#<?php echo $log['log_id']; ?></span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="text-xs text-gray-900">
                                        <?php echo formatDateTime($log['created_at'], 'M d, Y'); ?><br>
                                        <span class="text-gray-500"><?php echo date('H:i:s', strtotime($log['created_at'])); ?></span>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <?php if ($log['username']): ?>
                                        <div class="flex items-center gap-2">
                                            <div class="w-6 h-6 bg-blue-600 text-white rounded-full flex items-center justify-center">
                                                <span class="text-xs font-semibold"><?php echo strtoupper(substr($log['full_name'], 0, 1)); ?></span>
                                            </div>
                                            <div>
                                                <div class="text-xs font-semibold text-gray-900"><?php echo Security::escape($log['full_name']); ?></div>
                                                <div class="text-xs text-gray-500">@<?php echo Security::escape($log['username']); ?></div>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-xs text-gray-400">System</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?php 
                                            echo strpos($log['action'], 'login') !== false ? 'bg-green-100 text-green-800' : 
                                                (strpos($log['action'], 'add') !== false || strpos($log['action'], 'create') !== false ? 'bg-green-100 text-green-800' : 
                                                (strpos($log['action'], 'delete') !== false || strpos($log['action'], 'remove') !== false ? 'bg-red-100 text-red-800' : 
                                                (strpos($log['action'], 'update') !== false || strpos($log['action'], 'edit') !== false ? 'bg-yellow-100 text-yellow-800' : 'bg-blue-100 text-blue-800'))); 
                                        ?>">
                                            <?php echo Security::escape($log['action']); ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="text-xs text-gray-900">
                                            <?php if ($log['table_name']): ?>
                                                <span class="text-gray-500">Table:</span> <span class="font-mono"><?php echo Security::escape($log['table_name']); ?></span><br>
                                            <?php endif; ?>
                                            <?php if ($log['record_id']): ?>
                                                <span class="text-gray-500">Record ID:</span> <?php echo Security::escape($log['record_id']); ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="text-xs font-mono text-gray-700"><?php echo Security::escape($log['ip_address']); ?></div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if ($pagination['total_pages'] > 1): ?>
                <div class="flex justify-center p-4 border-t border-gray-200">
                    <div class="inline-flex items-center space-x-1">
                        <?php if ($pagination['has_prev']): ?>
                            <a href="?page=<?php echo $pagination['current_page'] - 1; ?><?php echo $filterAction ? '&action=' . urlencode($filterAction) : ''; ?><?php echo $filterUser ? '&user_id=' . $filterUser : ''; ?>" 
                               class="inline-flex items-center px-3 py-1.5 border border-gray-300 rounded-lg text-sm text-gray-700 bg-white hover:bg-gray-50 transition-colors">«</a>
                        <?php endif; ?>
                        
                        <?php 
                        $start = max(1, $pagination['current_page'] - 2);
                        $end = min($pagination['total_pages'], $pagination['current_page'] + 2);
                        for ($i = $start; $i <= $end; $i++): 
                        ?>
                            <a href="?page=<?php echo $i; ?><?php echo $filterAction ? '&action=' . urlencode($filterAction) : ''; ?><?php echo $filterUser ? '&user_id=' . $filterUser : ''; ?>" 
                               class="inline-flex items-center px-3 py-1.5 border rounded-lg text-sm transition-colors <?php echo $i === $pagination['current_page'] ? 'bg-blue-600 border-blue-600 text-white' : 'border-gray-300 text-gray-700 bg-white hover:bg-gray-50'; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($pagination['has_next']): ?>
                            <a href="?page=<?php echo $pagination['current_page'] + 1; ?><?php echo $filterAction ? '&action=' . urlencode($filterAction) : ''; ?><?php echo $filterUser ? '&user_id=' . $filterUser : ''; ?>" 
                               class="inline-flex items-center px-3 py-1.5 border border-gray-300 rounded-lg text-sm text-gray-700 bg-white hover:bg-gray-50 transition-colors">»</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
