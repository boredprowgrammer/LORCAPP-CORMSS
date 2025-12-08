<?php
/**
 * Officer Removal Requests Management
 * Three-stage workflow: Deliberated by Local → Requested → Approved by District
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

Security::requireLogin();

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

// Get removal requests based on user role
$query = "
    SELECT 
        r.*,
        o.officer_uuid,
        o.last_name_encrypted,
        o.first_name_encrypted,
        o.middle_initial_encrypted,
        o.district_code,
        o.local_code,
        d.district_name,
        l.local_name,
        od.department,
        od.duty,
        u1.full_name as deliberated_by_name,
        u2.full_name as requested_by_name,
        u3.full_name as approved_by_name
    FROM officer_removals r
    JOIN officers o ON r.officer_id = o.officer_id
    LEFT JOIN officer_departments od ON r.department_id = od.id
    LEFT JOIN districts d ON o.district_code = d.district_code
    LEFT JOIN local_congregations l ON o.local_code = l.local_code
    LEFT JOIN users u1 ON r.deliberated_by = u1.user_id
    LEFT JOIN users u2 ON r.requested_by = u2.user_id
    LEFT JOIN users u3 ON r.approved_by = u3.user_id
    WHERE r.status NOT IN ('approved_by_district', 'completed', 'staged')
";

$params = [];

if ($currentUser['role'] === 'local') {
    $query .= " AND o.local_code = ?";
    $params[] = $currentUser['local_code'];
} elseif ($currentUser['role'] === 'district') {
    $query .= " AND o.district_code = ?";
    $params[] = $currentUser['district_code'];
}

$query .= " ORDER BY r.created_at DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$requests = $stmt->fetchAll();

$pageTitle = 'Officer Removal Requests';
ob_start();
?>

<div class="container mx-auto px-4 py-6">
    <div class="mb-6">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-3xl font-bold text-gray-900">Officer Removal Requests</h2>
                <p class="text-gray-600 mt-1">Manage the three-stage removal approval process</p>
            </div>
            <?php if ($currentUser['role'] === 'local'): ?>
            <a href="remove.php" class="inline-flex items-center px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                </svg>
                New Removal Request
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Workflow Info -->
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
        <div class="flex items-start">
            <svg class="w-5 h-5 text-blue-600 mr-3 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
            </svg>
            <div class="text-sm text-blue-800">
                <p class="font-semibold mb-1">Three-Stage Workflow:</p>
                <ol class="list-decimal list-inside space-y-1 ml-2">
                    <li><strong>Deliberated by Local:</strong> Local admin creates removal request</li>
                    <li><strong>Requested:</strong> Local/Admin sends request to district for review</li>
                    <li><strong>Approved by District:</strong> District admin approves and officer is deactivated</li>
                </ol>
            </div>
        </div>
    </div>

    <!-- Requests Table -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Officer</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Location</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Code</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php if (empty($requests)): ?>
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-gray-500">
                            <p>No removal requests found</p>
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($requests as $request): 
                            $lastName = Encryption::decrypt($request['last_name_encrypted'], $request['district_code']);
                            $firstName = Encryption::decrypt($request['first_name_encrypted'], $request['district_code']);
                            $middleInitial = $request['middle_initial_encrypted'] ? Encryption::decrypt($request['middle_initial_encrypted'], $request['district_code']) : '';
                            $fullName = "$lastName, $firstName" . ($middleInitial ? " $middleInitial." : "");
                            
                            $statusConfig = [
                                'staged' => ['color' => 'gray', 'label' => 'Staged'],
                                'deliberated_by_local' => ['color' => 'yellow', 'label' => 'Deliberated by Local'],
                                'requested' => ['color' => 'blue', 'label' => 'Requested to District'],
                                'approved_by_district' => ['color' => 'green', 'label' => 'Approved'],
                                'completed' => ['color' => 'green', 'label' => 'Completed']
                            ];
                            $statusInfo = $statusConfig[$request['status']] ?? ['color' => 'gray', 'label' => ucfirst($request['status'])];
                        ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <div class="font-mono font-semibold text-gray-900 uppercase"><?php echo Security::escape($fullName); ?></div>
                                <?php if (!empty($request['department_id']) && !empty($request['department'])): ?>
                                    <div class="text-xs text-blue-600 mt-1">
                                        <svg class="w-3 h-3 inline mr-1" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                                        </svg>
                                        Department: <?php echo Security::escape($request['department']); ?>
                                        <?php if (!empty($request['duty'])): ?>
                                            (<?php echo Security::escape($request['duty']); ?>)
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3">
                                <div class="text-sm text-gray-900"><?php echo Security::escape($request['local_name']); ?></div>
                                <div class="text-xs text-gray-500"><?php echo Security::escape($request['district_name']); ?></div>
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-red-100 text-red-800">
                                    CODE <?php echo Security::escape($request['removal_code']); ?>
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-<?php echo $statusInfo['color']; ?>-100 text-<?php echo $statusInfo['color']; ?>-800">
                                    <?php echo $statusInfo['label']; ?>
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="text-sm text-gray-900"><?php echo date('M j, Y', strtotime($request['removal_date'])); ?></div>
                                <div class="text-xs text-gray-500"><?php echo date('g:i A', strtotime($request['created_at'])); ?></div>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="view.php?id=<?php echo $request['officer_uuid']; ?>" 
                                       class="inline-flex items-center px-3 py-1 bg-blue-50 text-blue-700 rounded-lg hover:bg-blue-100 text-sm font-medium">
                                        View Officer
                                    </a>
                                    
                                    <?php 
                                    $canManage = $currentUser['role'] === 'admin' || 
                                                 ($currentUser['role'] === 'district' && $currentUser['district_code'] === $request['district_code']) ||
                                                 ($currentUser['role'] === 'local' && $currentUser['local_code'] === $request['local_code']);
                                    ?>
                                    
                                    <?php if ($request['status'] === 'deliberated_by_local' && $canManage): ?>
                                    <form method="POST" action="remove.php" class="inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                                        <input type="hidden" name="action" value="request_to_district">
                                        <input type="hidden" name="removal_id" value="<?php echo $request['removal_id']; ?>">
                                        <button type="submit" class="inline-flex items-center px-3 py-1 bg-green-50 text-green-700 rounded-lg hover:bg-green-100 text-sm font-medium">
                                            Request to District
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    
                                    <?php if ($request['status'] === 'requested' && $canManage): ?>
                                    <form method="POST" action="remove.php" class="inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                                        <input type="hidden" name="action" value="approve_removal">
                                        <input type="hidden" name="removal_id" value="<?php echo $request['removal_id']; ?>">
                                        <button type="submit" onclick="return confirm('Approve this removal? This will deactivate the officer/department and update headcount.');" 
                                                class="inline-flex items-center px-3 py-1 bg-red-50 text-red-700 rounded-lg hover:bg-red-100 text-sm font-medium">
                                            Approve Removal
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    
                                    <?php if ($canManage): ?>
                                    <form method="POST" action="remove.php" class="inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                                        <input type="hidden" name="action" value="delete_removal">
                                        <input type="hidden" name="removal_id" value="<?php echo $request['removal_id']; ?>">
                                        <button type="submit" onclick="return confirm('Are you sure you want to delete this removal request? This action cannot be undone.');" 
                                                class="inline-flex items-center px-3 py-1 bg-gray-50 text-gray-700 rounded-lg hover:bg-gray-100 text-sm font-medium">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                            </svg>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
