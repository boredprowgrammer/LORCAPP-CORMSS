<?php
require_once __DIR__ . '/../config/config.php';

Security::requireLogin();
requirePermission('can_manage_announcements');

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

$error = '';
$success = '';

// Handle announcement creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!Security::validateCSRFToken($csrfToken)) {
        $error = 'Invalid security token.';
    } else {
        $title = Security::sanitizeInput($_POST['title'] ?? '');
        $message = Security::sanitizeInput($_POST['message'] ?? '');
        $type = Security::sanitizeInput($_POST['announcement_type'] ?? 'info');
        $priority = Security::sanitizeInput($_POST['priority'] ?? 'medium');
        $targetRole = Security::sanitizeInput($_POST['target_role'] ?? 'all');
        $targetDistrict = Security::sanitizeInput($_POST['target_district_code'] ?? '');
        $targetLocal = Security::sanitizeInput($_POST['target_local_code'] ?? '');
        $isPinned = isset($_POST['is_pinned']) ? 1 : 0;
        $startDate = $_POST['start_date'] ?? null;
        $endDate = $_POST['end_date'] ?? null;
        
        if (empty($title) || empty($message)) {
            $error = 'Title and message are required.';
        } else {
            try {
                $stmt = $db->prepare("
                    INSERT INTO announcements 
                    (title, message, announcement_type, priority, is_pinned, target_role, 
                     target_district_code, target_local_code, start_date, end_date, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $title,
                    $message,
                    $type,
                    $priority,
                    $isPinned,
                    $targetRole,
                    $targetDistrict ?: null,
                    $targetLocal ?: null,
                    $startDate ?: null,
                    $endDate ?: null,
                    $currentUser['user_id']
                ]);
                
                $success = 'Announcement created successfully!';
                
            } catch (Exception $e) {
                error_log("Create announcement error: " . $e->getMessage());
                $error = 'An error occurred while creating the announcement.';
            }
        }
    }
}

// Handle announcement update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!Security::validateCSRFToken($csrfToken)) {
        $error = 'Invalid security token.';
    } else {
        $announcementId = intval($_POST['announcement_id'] ?? 0);
        $title = Security::sanitizeInput($_POST['title'] ?? '');
        $message = Security::sanitizeInput($_POST['message'] ?? '');
        $type = Security::sanitizeInput($_POST['announcement_type'] ?? 'info');
        $priority = Security::sanitizeInput($_POST['priority'] ?? 'medium');
        $targetRole = Security::sanitizeInput($_POST['target_role'] ?? 'all');
        $targetDistrict = Security::sanitizeInput($_POST['target_district_code'] ?? '');
        $targetLocal = Security::sanitizeInput($_POST['target_local_code'] ?? '');
        $isPinned = isset($_POST['is_pinned']) ? 1 : 0;
        $startDate = $_POST['start_date'] ?? null;
        $endDate = $_POST['end_date'] ?? null;
        
        if (empty($title) || empty($message)) {
            $error = 'Title and message are required.';
        } else {
            try {
                $stmt = $db->prepare("
                    UPDATE announcements 
                    SET title = ?, message = ?, announcement_type = ?, priority = ?, 
                        is_pinned = ?, target_role = ?, target_district_code = ?, 
                        target_local_code = ?, start_date = ?, end_date = ?, updated_by = ?
                    WHERE announcement_id = ?
                ");
                
                $stmt->execute([
                    $title,
                    $message,
                    $type,
                    $priority,
                    $isPinned,
                    $targetRole,
                    $targetDistrict ?: null,
                    $targetLocal ?: null,
                    $startDate ?: null,
                    $endDate ?: null,
                    $currentUser['user_id'],
                    $announcementId
                ]);
                
                $success = 'Announcement updated successfully!';
                
            } catch (Exception $e) {
                error_log("Update announcement error: " . $e->getMessage());
                $error = 'An error occurred while updating the announcement.';
            }
        }
    }
}

// Handle toggle active status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle') {
    $announcementId = intval($_POST['announcement_id'] ?? 0);
    $isActive = intval($_POST['is_active'] ?? 0);
    
    try {
        $stmt = $db->prepare("UPDATE announcements SET is_active = ?, updated_by = ? WHERE announcement_id = ?");
        $stmt->execute([!$isActive, $currentUser['user_id'], $announcementId]);
        $success = 'Announcement status updated successfully!';
    } catch (Exception $e) {
        error_log("Toggle announcement error: " . $e->getMessage());
        $error = 'An error occurred while updating announcement status.';
    }
}

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $announcementId = intval($_POST['announcement_id'] ?? 0);
    
    try {
        $stmt = $db->prepare("DELETE FROM announcements WHERE announcement_id = ?");
        $stmt->execute([$announcementId]);
        $success = 'Announcement deleted successfully!';
    } catch (Exception $e) {
        error_log("Delete announcement error: " . $e->getMessage());
        $error = 'An error occurred while deleting the announcement.';
    }
}

// Get all announcements with creator info
try {
    $stmt = $db->query("
        SELECT a.*, 
               u.full_name as creator_name,
               u2.full_name as updater_name,
               d.district_name,
               lc.local_name
        FROM announcements a
        LEFT JOIN users u ON a.created_by = u.user_id
        LEFT JOIN users u2 ON a.updated_by = u2.user_id
        LEFT JOIN districts d ON a.target_district_code = d.district_code
        LEFT JOIN local_congregations lc ON a.target_local_code = lc.local_code
        ORDER BY a.is_pinned DESC, a.priority DESC, a.created_at DESC
    ");
    $announcements = $stmt->fetchAll();
    
    // Get districts for dropdown
    $stmt = $db->query("SELECT * FROM districts ORDER BY district_name");
    $districts = $stmt->fetchAll();
    
} catch (Exception $e) {
    error_log("Load announcements error: " . $e->getMessage());
    $announcements = [];
    $districts = [];
}

$pageTitle = 'Manage Announcements';
ob_start();
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h2 class="text-3xl font-bold text-gray-900">Announcement Management</h2>
            <p class="text-sm text-gray-500">Create and manage system-wide announcements</p>
        </div>
        <button onclick="openCreateModal()" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition-colors">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path>
            </svg>
            Create Announcement
        </button>
    </div>
    
    <?php if (!empty($error)): ?>
        <div class="bg-red-50 border border-red-200 rounded-lg p-4">
            <div class="flex items-center">
                <svg class="w-5 h-5 text-red-600 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                </svg>
                <span class="text-sm font-medium text-red-800"><?php echo Security::escape($error); ?></span>
            </div>
        </div>
    <?php endif; ?>
    
    <?php if (!empty($success)): ?>
        <div class="bg-green-50 border border-green-200 rounded-lg p-4">
            <div class="flex items-center">
                <svg class="w-5 h-5 text-green-600 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                </svg>
                <span class="text-sm font-medium text-green-800"><?php echo Security::escape($success); ?></span>
            </div>
        </div>
    <?php endif; ?>
    
    <!-- Announcements List -->
    <div class="space-y-4">
        <?php if (empty($announcements)): ?>
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-12 text-center">
                <svg class="w-16 h-16 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path>
                </svg>
                <p class="text-gray-500 text-lg font-medium">No announcements yet</p>
                <p class="text-gray-400 text-sm mt-1">Create your first announcement to get started</p>
            </div>
        <?php else: ?>
            <?php foreach ($announcements as $announcement): ?>
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 hover:shadow-md transition-shadow">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex-1">
                            <div class="flex items-center gap-2 mb-2">
                                <?php if ($announcement['is_pinned']): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800">
                                        <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                            <path d="M11 3a1 1 0 10-2 0v1a1 1 0 102 0V3zM15.657 5.757a1 1 0 00-1.414-1.414l-.707.707a1 1 0 001.414 1.414l.707-.707zM18 10a1 1 0 01-1 1h-1a1 1 0 110-2h1a1 1 0 011 1zM5.05 6.464A1 1 0 106.464 5.05l-.707-.707a1 1 0 00-1.414 1.414l.707.707zM5 10a1 1 0 01-1 1H3a1 1 0 110-2h1a1 1 0 011 1zM8 16v-1h4v1a2 2 0 11-4 0zM12 14c.015-.34.208-.646.477-.859a4 4 0 10-4.954 0c.27.213.462.519.476.859h4.002z"></path>
                                        </svg>
                                        Pinned
                                    </span>
                                <?php endif; ?>
                                
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?php 
                                    echo $announcement['priority'] === 'urgent' ? 'bg-red-100 text-red-800' : 
                                        ($announcement['priority'] === 'high' ? 'bg-orange-100 text-orange-800' : 
                                        ($announcement['priority'] === 'medium' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-800')); 
                                ?>">
                                    <?php echo ucfirst($announcement['priority']); ?>
                                </span>
                                
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?php 
                                    echo $announcement['announcement_type'] === 'error' ? 'bg-red-100 text-red-800' : 
                                        ($announcement['announcement_type'] === 'warning' ? 'bg-yellow-100 text-yellow-800' : 
                                        ($announcement['announcement_type'] === 'success' ? 'bg-green-100 text-green-800' : 'bg-blue-100 text-blue-800')); 
                                ?>">
                                    <?php echo ucfirst($announcement['announcement_type']); ?>
                                </span>
                                
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?php echo $announcement['is_active'] ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'; ?>">
                                    <?php echo $announcement['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </div>
                            
                            <h3 class="text-lg font-bold text-gray-900 mb-2"><?php echo Security::escape($announcement['title']); ?></h3>
                            <p class="text-gray-700 mb-3"><?php echo nl2br(Security::escape($announcement['message'])); ?></p>
                            
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-xs text-gray-600">
                                <div>
                                    <span class="font-medium">Target:</span> 
                                    <?php 
                                        if ($announcement['target_local_code']) {
                                            echo Security::escape($announcement['local_name']);
                                        } elseif ($announcement['target_district_code']) {
                                            echo Security::escape($announcement['district_name']);
                                        } else {
                                            echo ucfirst($announcement['target_role']);
                                        }
                                    ?>
                                </div>
                                <div>
                                    <span class="font-medium">Created:</span> <?php echo formatDateTime($announcement['created_at']); ?>
                                </div>
                                <?php if ($announcement['start_date']): ?>
                                <div>
                                    <span class="font-medium">Start:</span> <?php echo formatDateTime($announcement['start_date']); ?>
                                </div>
                                <?php endif; ?>
                                <?php if ($announcement['end_date']): ?>
                                <div>
                                    <span class="font-medium">End:</span> <?php echo formatDateTime($announcement['end_date']); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="flex items-center gap-2">
                            <button onclick="editAnnouncement(<?php echo htmlspecialchars(json_encode($announcement)); ?>)" class="p-2 text-blue-600 hover:text-blue-900 hover:bg-blue-50 rounded transition-colors" title="Edit">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                </svg>
                            </button>
                            
                            <form method="POST" action="" class="inline">
                                <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="announcement_id" value="<?php echo $announcement['announcement_id']; ?>">
                                <input type="hidden" name="is_active" value="<?php echo $announcement['is_active']; ?>">
                                <button type="submit" class="p-2 text-gray-600 hover:text-gray-900 hover:bg-gray-100 rounded transition-colors" title="<?php echo $announcement['is_active'] ? 'Deactivate' : 'Activate'; ?>">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <?php if ($announcement['is_active']): ?>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"></path>
                                        <?php else: ?>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                        <?php endif; ?>
                                    </svg>
                                </button>
                            </form>
                            
                            <form method="POST" action="" class="inline" onsubmit="return confirm('Are you sure you want to delete this announcement?');">
                                <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="announcement_id" value="<?php echo $announcement['announcement_id']; ?>">
                                <button type="submit" class="p-2 text-red-600 hover:text-red-900 hover:bg-red-50 rounded transition-colors" title="Delete">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                    </svg>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Create/Edit Announcement Modal -->
<div id="announcementModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="flex items-center justify-center min-h-screen px-4 py-6">
        <div onclick="closeAnnouncementModal()" class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity"></div>
        <div onclick="event.stopPropagation()" class="relative bg-white rounded-lg shadow-xl w-full max-w-3xl p-6 transform transition-all max-h-[90vh] overflow-y-auto">
            
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-bold text-gray-900" id="modalTitle">Create New Announcement</h3>
                <button onclick="closeAnnouncementModal()" class="text-gray-400 hover:text-gray-500">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
        
        <form method="POST" action="" id="announcementForm" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo Security::generateCSRFToken(); ?>">
            <input type="hidden" name="action" id="formAction" value="create">
            <input type="hidden" name="announcement_id" id="announcementId" value="">
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Title *</label>
                <input type="text" name="title" id="announcementTitle" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors" required>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Message *</label>
                <textarea name="message" id="announcementMessage" rows="4" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors" required></textarea>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Type *</label>
                    <select name="announcement_type" id="announcementType" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors" required>
                        <option value="info">Info</option>
                        <option value="success">Success</option>
                        <option value="warning">Warning</option>
                        <option value="error">Error</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Priority *</label>
                    <select name="priority" id="announcementPriority" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors" required>
                        <option value="low">Low</option>
                        <option value="medium" selected>Medium</option>
                        <option value="high">High</option>
                        <option value="urgent">Urgent</option>
                    </select>
                </div>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Target Role *</label>
                    <select name="target_role" id="targetRole" onchange="toggleDistrictLocal()" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors" required>
                        <option value="all">All Users</option>
                        <option value="admin">Admins Only</option>
                        <option value="district">District Users</option>
                        <option value="local">Local Users</option>
                    </select>
                </div>
                
                <div id="districtField" style="display: none;">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Specific District</label>
                    <select name="target_district_code" id="targetDistrictCode" onchange="toggleLocalField()" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors">
                        <option value="">All Districts</option>
                        <?php foreach ($districts as $district): ?>
                            <option value="<?php echo Security::escape($district['district_code']); ?>"><?php echo Security::escape($district['district_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div id="localField" style="display: none;">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Specific Local</label>
                    <input type="text" name="target_local_code" id="targetLocalCode" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors" placeholder="Leave blank for all">
                </div>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Start Date (Optional)</label>
                    <input type="datetime-local" name="start_date" id="startDate" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">End Date (Optional)</label>
                    <input type="datetime-local" name="end_date" id="endDate" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors">
                </div>
            </div>
            
            <div>
                <label class="inline-flex items-center">
                    <input type="checkbox" name="is_pinned" id="isPinned" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                    <span class="ml-2 text-sm text-gray-700">Pin this announcement (appears first)</span>
                </label>
            </div>
            
            <div class="flex items-center justify-end space-x-3 pt-4 border-t">
                <button type="button" onclick="closeAnnouncementModal()" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-lg text-gray-700 bg-white hover:bg-gray-50 transition-colors">Cancel</button>
                <button type="submit" id="submitBtn" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition-colors">
                    Create Announcement
                </button>
            </div>
        </form>
        </div>
    </div>
</div>

<script>
function openCreateModal() {
    // Reset form
    document.getElementById('announcementForm').reset();
    document.getElementById('formAction').value = 'create';
    document.getElementById('announcementId').value = '';
    document.getElementById('modalTitle').textContent = 'Create New Announcement';
    document.getElementById('submitBtn').textContent = 'Create Announcement';
    
    // Show modal
    const modal = document.getElementById('announcementModal');
    modal.classList.remove('hidden');
    modal.style.display = 'block';
    
    // Reset visibility
    toggleDistrictLocal();
}

function closeAnnouncementModal() {
    const modal = document.getElementById('announcementModal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
}

function toggleDistrictLocal() {
    const targetRole = document.getElementById('targetRole').value;
    const districtField = document.getElementById('districtField');
    const localField = document.getElementById('localField');
    
    if (targetRole === 'district' || targetRole === 'local') {
        districtField.style.display = 'block';
    } else {
        districtField.style.display = 'none';
        localField.style.display = 'none';
        document.getElementById('targetDistrictCode').value = '';
        document.getElementById('targetLocalCode').value = '';
    }
    
    toggleLocalField();
}

function toggleLocalField() {
    const targetRole = document.getElementById('targetRole').value;
    const targetDistrict = document.getElementById('targetDistrictCode').value;
    const localField = document.getElementById('localField');
    
    if (targetRole === 'local' && targetDistrict) {
        localField.style.display = 'block';
    } else {
        localField.style.display = 'none';
        document.getElementById('targetLocalCode').value = '';
    }
}

function editAnnouncement(announcement) {
    // Populate form fields
    document.getElementById('formAction').value = 'update';
    document.getElementById('announcementId').value = announcement.announcement_id;
    document.getElementById('announcementTitle').value = announcement.title;
    document.getElementById('announcementMessage').value = announcement.message;
    document.getElementById('announcementType').value = announcement.announcement_type;
    document.getElementById('announcementPriority').value = announcement.priority;
    document.getElementById('targetRole').value = announcement.target_role;
    document.getElementById('targetDistrictCode').value = announcement.target_district_code || '';
    document.getElementById('targetLocalCode').value = announcement.target_local_code || '';
    document.getElementById('isPinned').checked = announcement.is_pinned == 1;
    
    // Format dates for datetime-local input
    if (announcement.start_date) {
        const startDate = new Date(announcement.start_date);
        document.getElementById('startDate').value = startDate.toISOString().slice(0, 16);
    } else {
        document.getElementById('startDate').value = '';
    }
    if (announcement.end_date) {
        const endDate = new Date(announcement.end_date);
        document.getElementById('endDate').value = endDate.toISOString().slice(0, 16);
    } else {
        document.getElementById('endDate').value = '';
    }
    
    // Update modal title and button
    document.getElementById('modalTitle').textContent = 'Edit Announcement';
    document.getElementById('submitBtn').textContent = 'Update Announcement';
    
    // Show appropriate fields
    toggleDistrictLocal();
    
    // Open modal
    const modal = document.getElementById('announcementModal');
    modal.classList.remove('hidden');
    modal.style.display = 'block';
}

// Close modal on Escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeAnnouncementModal();
    }
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
