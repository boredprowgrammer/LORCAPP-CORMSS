<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/announcements.php';

Security::requireLogin();

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

// Get statistics based on user role
$stats = [
    'total_officers' => 0,
    'active_officers' => 0,
    'transfers_this_week' => 0,
    'total_departments' => 0
];

try {
    // Build query based on role
    $whereClause = '';
    $params = [];
    
    if ($currentUser['role'] === 'local') {
        $whereClause = 'WHERE o.local_code = ?';
        $params[] = $currentUser['local_code'];
    } elseif ($currentUser['role'] === 'district') {
        $whereClause = 'WHERE o.district_code = ?';
        $params[] = $currentUser['district_code'];
    }
    
    // Total officers
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM officers o $whereClause");
    $stmt->execute($params);
    $stats['total_officers'] = $stmt->fetch()['count'];
    
    // Active officers
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM officers o $whereClause " . 
                         ($whereClause ? 'AND' : 'WHERE') . " o.is_active = 1");
    $stmt->execute($params);
    $stats['active_officers'] = $stmt->fetch()['count'];
    
    // Transfers this week
    $weekInfo = getWeekDateRange();
    $transferParams = [$weekInfo['week'], $weekInfo['year']];
    $transferWhere = '';
    
    if ($currentUser['role'] === 'local') {
        $transferWhere = 'WHERE (t.from_local_code = ? OR t.to_local_code = ?) AND';
        array_unshift($transferParams, $currentUser['local_code'], $currentUser['local_code']);
    } elseif ($currentUser['role'] === 'district') {
        $transferWhere = 'WHERE (t.from_district_code = ? OR t.to_district_code = ?) AND';
        array_unshift($transferParams, $currentUser['district_code'], $currentUser['district_code']);
    } else {
        $transferWhere = 'WHERE';
    }
    
    $stmt = $db->prepare("
        SELECT COUNT(*) as count 
        FROM transfers t 
        $transferWhere t.week_number = ? AND t.year = ?
    ");
    $stmt->execute($transferParams);
    $stats['transfers_this_week'] = $stmt->fetch()['count'];
    
    // Active departments
    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT od.department) as count 
        FROM officer_departments od
        JOIN officers o ON od.officer_id = o.officer_id
        $whereClause
        AND od.is_active = 1
    ");
    $stmt->execute($params);
    $stats['total_departments'] = $stmt->fetch()['count'];
    
    // Inactive officers
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM officers o $whereClause " . 
                         ($whereClause ? 'AND' : 'WHERE') . " o.is_active = 0");
    $stmt->execute($params);
    $stats['inactive_officers'] = $stmt->fetch()['count'];
    
    // Removals this month
    $stmt = $db->prepare("
        SELECT COUNT(*) as count 
        FROM officer_removals or1
        JOIN officers o ON or1.officer_id = o.officer_id
        $whereClause
        " . ($whereClause ? 'AND' : 'WHERE') . "
        MONTH(or1.removal_date) = MONTH(CURRENT_DATE()) 
        AND YEAR(or1.removal_date) = YEAR(CURRENT_DATE())
    ");
    $stmt->execute($params);
    $stats['removals_this_month'] = $stmt->fetch()['count'];
    
    // New officers this month (CODE A)
    $stmt = $db->prepare("
        SELECT COUNT(*) as count 
        FROM officers o
        $whereClause
        " . ($whereClause ? 'AND' : 'WHERE') . "
        o.record_code = 'A'
        AND MONTH(o.created_at) = MONTH(CURRENT_DATE()) 
        AND YEAR(o.created_at) = YEAR(CURRENT_DATE())
    ");
    $stmt->execute($params);
    $stats['new_officers_this_month'] = $stmt->fetch()['count'];
    
    // Top departments
    $topDepartments = [];
    $stmt = $db->prepare("
        SELECT od.department, COUNT(*) as count
        FROM officer_departments od
        JOIN officers o ON od.officer_id = o.officer_id
        $whereClause
        AND od.is_active = 1
        GROUP BY od.department
        ORDER BY count DESC
        LIMIT 5
    ");
    $stmt->execute($params);
    $topDepartments = $stmt->fetchAll();
    
    // Get headcount
    $headcountData = [];
    if ($currentUser['role'] === 'admin') {
        $stmt = $db->query("
            SELECT d.district_name, SUM(h.total_count) as count
            FROM headcount h
            JOIN districts d ON h.district_code = d.district_code
            GROUP BY d.district_code, d.district_name
            ORDER BY count DESC
            LIMIT 5
        ");
        $headcountData = $stmt->fetchAll();
    } elseif ($currentUser['role'] === 'district') {
        $stmt = $db->prepare("
            SELECT lc.local_name, h.total_count as count
            FROM headcount h
            JOIN local_congregations lc ON h.local_code = lc.local_code
            WHERE h.district_code = ?
            ORDER BY count DESC
        ");
        $stmt->execute([$currentUser['district_code']]);
        $headcountData = $stmt->fetchAll();
    } else {
        $stmt = $db->prepare("
            SELECT lc.local_name, h.total_count as count
            FROM headcount h
            JOIN local_congregations lc ON h.local_code = lc.local_code
            WHERE h.local_code = ?
        ");
        $stmt->execute([$currentUser['local_code']]);
        $headcountData = $stmt->fetchAll();
    }
    
    // Recent activities
    $activityWhere = $whereClause ? str_replace('o.', 'al.', $whereClause) : '';
    $stmt = $db->prepare("
        SELECT al.*, u.full_name 
        FROM audit_log al
        LEFT JOIN users u ON al.user_id = u.user_id
        ORDER BY al.created_at DESC
        LIMIT 10
    ");
    $stmt->execute();
    $recentActivities = $stmt->fetchAll();
    
    // Fetch recent messages for mini inbox (only for local and district users)
    $recentMessages = [];
    $unreadMessageCount = 0;
    $allowedChatRoles = ['local', 'district'];
    
    if (in_array($currentUser['role'], $allowedChatRoles)) {
        // Get conversations with recent activity
        $stmt = $db->prepare("
            SELECT 
                c.conversation_id,
                c.last_message_at as sent_at,
                cp.last_read_at,
                (SELECT u2.full_name 
                 FROM chat_participants cp2 
                 JOIN users u2 ON cp2.user_id = u2.user_id 
                 WHERE cp2.conversation_id = c.conversation_id 
                 AND cp2.user_id != :user_id1
                 LIMIT 1) as display_name,
                (SELECT u2.role 
                 FROM chat_participants cp2 
                 JOIN users u2 ON cp2.user_id = u2.user_id 
                 WHERE cp2.conversation_id = c.conversation_id 
                 AND cp2.user_id != :user_id2
                 LIMIT 1) as sender_role,
                (SELECT cm.sender_id 
                 FROM chat_messages cm 
                 WHERE cm.conversation_id = c.conversation_id 
                 ORDER BY cm.sent_at DESC LIMIT 1) as sender_id,
                (SELECT COUNT(*) 
                 FROM chat_messages cm2 
                 WHERE cm2.conversation_id = c.conversation_id 
                 AND cm2.sent_at > COALESCE(cp.last_read_at, '1970-01-01')
                 AND cm2.sender_id != :user_id3) as unread_count
            FROM chat_conversations c
            INNER JOIN chat_participants cp ON c.conversation_id = cp.conversation_id AND cp.user_id = :user_id4
            WHERE c.last_message_at IS NOT NULL
            ORDER BY c.last_message_at DESC
            LIMIT 5
        ");
        $stmt->execute([
            'user_id1' => $currentUser['user_id'], 
            'user_id2' => $currentUser['user_id'], 
            'user_id3' => $currentUser['user_id'], 
            'user_id4' => $currentUser['user_id']
        ]);
        $recentMessages = $stmt->fetchAll();
        
        // Get total unread count across all conversations
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(
                (SELECT COUNT(*) 
                 FROM chat_messages cm 
                 WHERE cm.conversation_id = c.conversation_id 
                 AND cm.sent_at > COALESCE(cp.last_read_at, '1970-01-01')
                 AND cm.sender_id != :user_id1)
            ), 0) as count
            FROM chat_conversations c
            INNER JOIN chat_participants cp ON c.conversation_id = cp.conversation_id AND cp.user_id = :user_id2
        ");
        $stmt->execute([
            'user_id1' => $currentUser['user_id'],
            'user_id2' => $currentUser['user_id']
        ]);
        $unreadMessageCount = (int)$stmt->fetch()['count'];
    }

} catch (Exception $e) {
    error_log("Dashboard error: " . $e->getMessage());
}

$pageTitle = 'Dashboard';
ob_start();
?>

<div class="space-y-6">
    <!-- Welcome Header -->
    <div class="bg-white dark:bg-gray-800 dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-4 sm:p-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 sm:gap-0">
            <div>
                <h1 class="text-xl sm:text-2xl font-semibold text-gray-900 dark:text-gray-100"><?php echo Security::escape($currentUser['full_name']); ?></h1>
                <p class="text-xs sm:text-sm text-gray-500 mt-1"><?php echo formatDate(date('Y-m-d'), 'l, F d, Y'); ?> • Week <?php echo getCurrentWeekNumber(); ?></p>
            </div>
            <div class="flex items-center gap-2 sm:gap-3">
                <?php 
                $userAnnouncements = getUserAnnouncements($currentUser['user_id']);
                if (!empty($userAnnouncements)): 
                ?>
                <button onclick="openAnnouncementsModal()" class="inline-flex items-center px-3 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition-colors">
                    <svg class="w-4 h-4 sm:mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path>
                    </svg>
                    <span class="hidden sm:inline">Announcements</span>
                    <span class="ml-1 sm:ml-2 inline-flex items-center justify-center px-2 py-0.5 text-xs font-bold leading-none text-white bg-red-600 rounded-full"><?php echo count($userAnnouncements); ?></span>
                </button>
                <?php endif; ?>
                <div class="px-3 sm:px-4 py-2 bg-blue-100 text-blue-700 rounded-lg text-xs sm:text-sm font-medium">
                    <?php echo strtoupper($currentUser['role']); ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Minimalist Announcements Modal -->
    <?php if (!empty($userAnnouncements)): ?>
    <div id="announcementsModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
        <div class="flex items-center justify-center min-h-screen px-4">
            <!-- Simple Backdrop -->
            <div class="fixed inset-0 bg-black/50 transition-opacity" onclick="closeAnnouncementsModal()"></div>
            
            <!-- Modal Container -->
            <div class="relative bg-white dark:bg-gray-800 rounded-lg shadow-xl w-full max-w-4xl max-h-[85vh] flex flex-col">
                
                <!-- Simple Header -->
                <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Announcements (<?php echo count($userAnnouncements); ?>)</h3>
                    <button onclick="closeAnnouncementsModal()" class="text-gray-400 hover:text-gray-600 dark:text-gray-400 p-1">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                
                <!-- Announcements List -->
                <div id="announcementsContainer" class="flex-1 overflow-y-auto p-4">
                    <?php foreach ($userAnnouncements as $announcement): ?>
                        <?php
                        // Set defaults for missing keys
                        $announcement['type'] = $announcement['type'] ?? 'info';
                        $announcement['priority'] = $announcement['priority'] ?? 'medium';
                        $announcement['target_role'] = $announcement['target_role'] ?? 'all';
                        $announcement['start_date'] = $announcement['start_date'] ?? null;
                        
                        // Bootstrap-style color mapping
                        $typeColors = [
                            'info' => 'border-l-4 border-blue-500 bg-blue-50',
                            'success' => 'border-l-4 border-green-500 bg-green-50',
                            'warning' => 'border-l-4 border-yellow-500 bg-yellow-50',
                            'error' => 'border-l-4 border-red-500 bg-red-50'
                        ];
                        
                        $priorityBadges = [
                            'low' => 'bg-gray-100 text-gray-800',
                            'medium' => 'bg-blue-100 text-blue-800',
                            'high' => 'bg-orange-100 text-orange-800',
                            'urgent' => 'bg-red-100 text-red-800'
                        ];
                        
                        $colorClass = $typeColors[$announcement['type']] ?? $typeColors['info'];
                        $badgeClass = $priorityBadges[$announcement['priority']] ?? $priorityBadges['medium'];
                        ?>
                        
                        <div class="announcement-item mb-3" data-announcement-id="<?php echo $announcement['announcement_id']; ?>">
                            <div class="<?php echo $colorClass; ?> rounded p-4 relative">
                                <!-- Close button -->
                                <button onclick="dismissAnnouncement(<?php echo $announcement['announcement_id']; ?>)" 
                                        class="absolute top-3 right-3 text-gray-400 hover:text-gray-600 dark:text-gray-400">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                    </svg>
                                </button>
                                
                                <!-- Content -->
                                <div class="pr-6">
                                    <!-- Title with badge -->
                                    <div class="flex items-center gap-2 mb-2">
                                        <h4 class="font-semibold text-gray-900 dark:text-gray-100"><?php echo Security::escape($announcement['title']); ?></h4>
                                        <span class="inline-block px-2 py-0.5 text-xs font-medium rounded <?php echo $badgeClass; ?>">
                                            <?php echo strtoupper($announcement['priority']); ?>
                                        </span>
                                    </div>
                                    
                                    <!-- Message -->
                                    <p class="text-sm text-gray-700 dark:text-gray-300 mb-2 whitespace-pre-wrap"><?php echo nl2br(Security::escape($announcement['message'])); ?></p>
                                    
                                    <!-- Metadata -->
                                    <div class="flex items-center gap-3 text-xs text-gray-500">
                                        <?php if (!empty($announcement['start_date'])): ?>
                                        <span class="flex items-center gap-1">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                            </svg>
                                            <?php echo date('M d, Y', strtotime($announcement['start_date'])); ?>
                                        </span>
                                        <?php endif; ?>
                                        
                                        <?php if ($announcement['target_role'] !== 'all'): ?>
                                        <span class="flex items-center gap-1">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                                            </svg>
                                            <?php echo ucfirst($announcement['target_role']); ?>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Simple Footer -->
                <div class="flex items-center justify-end gap-2 px-6 py-3 border-t border-gray-200 dark:border-gray-700 bg-gray-50">
                    <button onclick="closeAnnouncementsModal()" 
                            class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-800 border border-gray-300 rounded hover:bg-gray-50">
                        Close
                    </button>
                    <button onclick="dismissAllAnnouncements()" 
                            class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded hover:bg-blue-700">
                        Dismiss All
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <script>
    function openAnnouncementsModal() {
        const modal = document.getElementById('announcementsModal');
        if (modal) {
            modal.classList.remove('hidden');
            modal.style.display = 'block';
        }
    }
    
    function closeAnnouncementsModal() {
        const modal = document.getElementById('announcementsModal');
        if (modal) {
            modal.classList.add('hidden');
            modal.style.display = 'none';
        }
    }
    
    function dismissAnnouncement(announcementId) {
        fetch('<?php echo BASE_URL; ?>/api/dismiss-announcement.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ announcement_id: announcementId })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Remove announcement with animation
                const element = document.querySelector(`[data-announcement-id="${announcementId}"]`);
                if (element) {
                    element.style.transition = 'opacity 0.3s, transform 0.3s';
                    element.style.opacity = '0';
                    element.style.transform = 'translateX(100%)';
                    setTimeout(() => {
                        element.remove();
                        // Check if any announcements left
                        const container = document.getElementById('announcementsContainer');
                        if (container && container.querySelectorAll('.announcement-item').length === 0) {
                            closeAnnouncementsModal();
                            // Remove button
                            const btn = document.querySelector('[onclick="openAnnouncementsModal()"]');
                            if (btn) btn.remove();
                        } else {
                            // Update count badge
                            updateAnnouncementCount();
                        }
                    }, 300);
                }
                toast.success('Announcement dismissed');
            } else {
                toast.error(data.message || 'Failed to dismiss announcement');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            toast.error('Failed to dismiss announcement');
        });
    }
    
    function dismissAllAnnouncements() {
        const announcements = document.querySelectorAll('.announcement-item');
        const announcementIds = Array.from(announcements).map(el => el.dataset.announcementId);
        
        Promise.all(
            announcementIds.map(id => 
                fetch('<?php echo BASE_URL; ?>/api/dismiss-announcement.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ announcement_id: parseInt(id) })
                })
            )
        ).then(() => {
            closeAnnouncementsModal();
            // Remove button
            const btn = document.querySelector('[onclick="openAnnouncementsModal()"]');
            if (btn) btn.remove();
            toast.success('All announcements dismissed');
        }).catch(error => {
            console.error('Error:', error);
            toast.error('Failed to dismiss all announcements');
        });
    }
    
    function updateAnnouncementCount() {
        const container = document.getElementById('announcementsContainer');
        const count = container ? container.querySelectorAll('.announcement-item').length : 0;
        const badges = document.querySelectorAll('[onclick="openAnnouncementsModal()"] span.bg-red-600, [onclick="openAnnouncementsModal()"] span.bg-blue-600');
        badges.forEach(badge => {
            badge.textContent = count;
        });
        
        if (count === 0) {
            const btn = document.querySelector('[onclick="openAnnouncementsModal()"]');
            if (btn) btn.remove();
        }
    }
    
    // Auto-show modal once per login session
    <?php if (!isset($_SESSION['announcements_shown'])): ?>
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(function() {
            openAnnouncementsModal();
            // Mark as shown in this session
            fetch('<?php echo BASE_URL; ?>/api/mark-announcements-shown.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' }
            });
        }, 500);
    });
    <?php endif; ?>
    </script>
    <?php endif; ?>
    
    <!-- Key Metrics Grid - 6 Cards -->
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 sm:gap-4">
        <!-- Total Officers -->
        <div class="bg-white dark:bg-gray-800 dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-3 sm:p-4 hover:shadow-md transition-shadow">
            <div class="flex flex-col sm:flex-row items-start sm:items-center sm:space-x-3 space-y-2 sm:space-y-0">
                <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-lg bg-blue-100 flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 sm:w-6 sm:h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                    </svg>
                </div>
                <div class="min-w-0">
                    <p class="text-xl sm:text-2xl font-bold text-gray-900 dark:text-gray-100 truncate"><?php echo number_format($stats['total_officers']); ?></p>
                    <p class="text-xs text-gray-500 truncate">Total</p>
                </div>
            </div>
        </div>
        
        <!-- Active Officers -->
        <div class="bg-white dark:bg-gray-800 dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-3 sm:p-4 hover:shadow-md transition-shadow">
            <div class="flex flex-col sm:flex-row items-start sm:items-center sm:space-x-3 space-y-2 sm:space-y-0">
                <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-lg bg-green-100 flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 sm:w-6 sm:h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
                <div class="min-w-0">
                    <p class="text-xl sm:text-2xl font-bold text-green-600 truncate"><?php echo number_format($stats['active_officers']); ?></p>
                    <p class="text-xs text-gray-500 truncate">Active</p>
                </div>
            </div>
        </div>
        
        <!-- Inactive Officers -->
        <div class="bg-white dark:bg-gray-800 dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-3 sm:p-4 hover:shadow-md transition-shadow">
            <div class="flex flex-col sm:flex-row items-start sm:items-center sm:space-x-3 space-y-2 sm:space-y-0">
                <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-lg bg-red-100 flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 sm:w-6 sm:h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
                <div class="min-w-0">
                    <p class="text-xl sm:text-2xl font-bold text-red-600 truncate"><?php echo number_format($stats['inactive_officers']); ?></p>
                    <p class="text-xs text-gray-500 truncate">Inactive</p>
                </div>
            </div>
        </div>
        
        <!-- Transfers This Week -->
        <div class="bg-white dark:bg-gray-800 dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-3 sm:p-4 hover:shadow-md transition-shadow">
            <div class="flex flex-col sm:flex-row items-start sm:items-center sm:space-x-3 space-y-2 sm:space-y-0">
                <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-lg bg-cyan-100 flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 sm:w-6 sm:h-6 text-cyan-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"></path>
                    </svg>
                </div>
                <div class="min-w-0">
                    <p class="text-xl sm:text-2xl font-bold text-cyan-600 truncate"><?php echo number_format($stats['transfers_this_week']); ?></p>
                    <p class="text-xs text-gray-500 truncate">Transfers/Wk</p>
                </div>
            </div>
        </div>
        
        <!-- New Officers This Month -->
        <div class="bg-white dark:bg-gray-800 dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-3 sm:p-4 hover:shadow-md transition-shadow">
            <div class="flex flex-col sm:flex-row items-start sm:items-center sm:space-x-3 space-y-2 sm:space-y-0">
                <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-lg bg-purple-100 flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 sm:w-6 sm:h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path>
                    </svg>
                </div>
                <div class="min-w-0">
                    <p class="text-xl sm:text-2xl font-bold text-purple-600 truncate"><?php echo number_format($stats['new_officers_this_month']); ?></p>
                    <p class="text-xs text-gray-500 truncate">New/Month</p>
                </div>
            </div>
        </div>
        
        <!-- Removals This Month -->
        <div class="bg-white dark:bg-gray-800 dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-3 sm:p-4 hover:shadow-md transition-shadow">
            <div class="flex flex-col sm:flex-row items-start sm:items-center sm:space-x-3 space-y-2 sm:space-y-0">
                <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-lg bg-yellow-100 flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 sm:w-6 sm:h-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7a4 4 0 11-8 0 4 4 0 018 0zM9 14a6 6 0 00-6 6v1h12v-1a6 6 0 00-6-6zM21 12h-6"></path>
                    </svg>
                </div>
                <div class="min-w-0">
                    <p class="text-xl sm:text-2xl font-bold text-yellow-600 truncate"><?php echo number_format($stats['removals_this_month']); ?></p>
                    <p class="text-xs text-gray-500 truncate">Removals/Mo</p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Main Content Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-<?php echo in_array($currentUser['role'], ['local', 'district']) ? '4' : '3'; ?> gap-4 sm:gap-6">
        <!-- Top Departments -->
        <div class="bg-white dark:bg-gray-800 dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-5">
            <div class="flex items-center space-x-2 mb-4">
                <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                </svg>
                <h3 class="font-semibold text-gray-900 dark:text-gray-100">Top Departments</h3>
            </div>
            <div class="space-y-2">
                <?php foreach ($topDepartments as $dept): ?>
                    <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors">
                        <span class="text-sm text-gray-700 dark:text-gray-300 truncate flex-1"><?php echo Security::escape($dept['department']); ?></span>
                        <span class="ml-3 px-2.5 py-1 bg-blue-100 text-blue-700 text-xs font-medium rounded-full"><?php echo $dept['count']; ?></span>
                    </div>
                <?php endforeach; ?>
                
                <?php if (empty($topDepartments)): ?>
                    <div class="text-center py-8">
                        <svg class="w-12 h-12 text-gray-300 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                        </svg>
                        <p class="text-sm text-gray-500">No data available</p>
                    </div>
                <?php endif; ?>
            </div>
            <a href="<?php echo BASE_URL; ?>/reports/departments.php" class="mt-4 flex items-center justify-center w-full px-4 py-2 text-sm font-medium text-blue-600 hover:text-blue-700 hover:bg-blue-50 rounded-lg transition-colors">
                View All 
                <svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                </svg>
            </a>
        </div>
        
        <!-- Messages Mini Inbox -->
        <?php if (in_array($currentUser['role'], ['local', 'district'])): ?>
        <div class="bg-white dark:bg-gray-800 dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-4 sm:p-5">
            <div class="flex items-center justify-between mb-3 sm:mb-4">
                <div class="flex items-center space-x-2">
                    <svg class="w-4 h-4 sm:w-5 sm:h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
                    </svg>
                    <h3 class="text-sm sm:text-base font-semibold text-gray-900 dark:text-gray-100">Messages</h3>
                    <?php if ($unreadMessageCount > 0): ?>
                        <span class="inline-flex items-center justify-center px-2 py-0.5 text-xs font-bold text-white bg-indigo-600 rounded-full"><?php echo $unreadMessageCount; ?></span>
                    <?php endif; ?>
                </div>
                <a href="<?php echo BASE_URL; ?>/chat.php" class="text-xs text-indigo-600 hover:text-indigo-700 font-medium">View All</a>
            </div>
            
            <div class="space-y-2 max-h-72 overflow-y-auto">
                <?php if (!empty($recentMessages)): ?>
                    <?php foreach ($recentMessages as $msg): ?>
                        <?php
                        $isUnread = $msg['unread_count'] > 0;
                        $isOwnMessage = $msg['sender_id'] == $currentUser['user_id'];
                        $initials = '';
                        if ($msg['display_name']) {
                            $parts = explode(' ', trim($msg['display_name']));
                            $initials = count($parts) >= 2 
                                ? strtoupper($parts[0][0] . $parts[count($parts)-1][0])
                                : strtoupper(substr($msg['display_name'], 0, 2));
                        }
                        $avatarColors = ['bg-red-500', 'bg-green-500', 'bg-blue-500', 'bg-purple-500', 'bg-pink-500', 'bg-indigo-500', 'bg-teal-500', 'bg-orange-500'];
                        $avatarColor = $avatarColors[$msg['conversation_id'] % count($avatarColors)];
                        ?>
                        <a href="<?php echo BASE_URL; ?>/chat.php?conversation=<?php echo $msg['conversation_id']; ?>" 
                           class="flex items-center p-2 sm:p-3 rounded-lg hover:bg-gray-50 transition-colors group <?php echo $isUnread ? 'bg-indigo-50' : 'bg-gray-50'; ?>">
                            <div class="relative flex-shrink-0">
                                <div class="w-9 h-9 sm:w-10 sm:h-10 rounded-full <?php echo $avatarColor; ?> flex items-center justify-center text-white text-xs sm:text-sm font-medium">
                                    <?php echo $initials; ?>
                                </div>
                                <?php if ($isUnread): ?>
                                    <span class="absolute -top-0.5 -right-0.5 w-2.5 h-2.5 sm:w-3 sm:h-3 bg-indigo-600 rounded-full border-2 border-white"></span>
                                <?php endif; ?>
                            </div>
                            <div class="ml-2 sm:ml-3 flex-1 min-w-0">
                                <div class="flex items-center justify-between">
                                    <p class="text-xs sm:text-sm font-medium text-gray-900 dark:text-gray-100 truncate <?php echo $isUnread ? 'font-semibold' : ''; ?>">
                                        <?php echo Security::escape($msg['display_name'] ?? 'Unknown'); ?>
                                    </p>
                                    <span class="text-xs text-gray-400 ml-2 flex-shrink-0">
                                        <?php 
                                        $msgTime = strtotime($msg['sent_at']);
                                        $now = time();
                                        $diff = $now - $msgTime;
                                        if ($diff < 60) echo 'now';
                                        elseif ($diff < 3600) echo floor($diff/60) . 'm';
                                        elseif ($diff < 86400) echo floor($diff/3600) . 'h';
                                        elseif ($diff < 604800) echo floor($diff/86400) . 'd';
                                        else echo date('M j', $msgTime);
                                        ?>
                                    </span>
                                </div>
                                <p class="text-xs text-gray-500 truncate mt-0.5 <?php echo $isUnread ? 'font-medium text-gray-700 dark:text-gray-300' : ''; ?>">
                                    <?php if ($isOwnMessage): ?>
                                        <span class="text-gray-400">You sent a message</span>
                                    <?php else: ?>
                                        <span class="text-gray-500">Tap to view message</span>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <?php if ($msg['unread_count'] > 0): ?>
                                <span class="ml-2 inline-flex items-center justify-center w-4 h-4 sm:w-5 sm:h-5 text-xs font-bold text-white bg-indigo-600 rounded-full flex-shrink-0">
                                    <?php echo $msg['unread_count'] > 9 ? '9+' : $msg['unread_count']; ?>
                                </span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center py-6 sm:py-8">
                        <div class="w-14 h-14 sm:w-16 sm:h-16 mx-auto mb-2 sm:mb-3 rounded-full bg-indigo-50 flex items-center justify-center">
                            <svg class="w-7 h-7 sm:w-8 sm:h-8 text-indigo-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
                            </svg>
                        </div>
                        <p class="text-xs sm:text-sm text-gray-500 mb-1">No messages yet</p>
                        <p class="text-xs text-gray-400">Start a conversation</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <a href="<?php echo BASE_URL; ?>/chat.php" class="mt-3 sm:mt-4 flex items-center justify-center w-full px-4 py-2 sm:py-2.5 text-xs sm:text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg transition-colors shadow-sm">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
                </svg>
                Open Messages
            </a>
        </div>
        <?php endif; ?>
        
        <!-- Headcount Overview -->
        <div class="bg-white dark:bg-gray-800 dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-5">
            <div class="flex items-center space-x-2 mb-4">
                <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                </svg>
                <h3 class="font-semibold text-gray-900 dark:text-gray-100">Headcount Overview</h3>
            </div>
            <div class="space-y-3">
                <?php foreach ($headcountData as $data): ?>
                    <div>
                        <div class="flex justify-between text-xs mb-1.5">
                            <span class="text-gray-700 dark:text-gray-300 truncate flex-1"><?php echo Security::escape($data['district_name'] ?? $data['local_name']); ?></span>
                            <span class="font-semibold text-gray-900 dark:text-gray-100 ml-2"><?php echo number_format($data['count']); ?></span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2 overflow-hidden">
                            <div class="bg-green-500 h-2 rounded-full transition-all duration-300" 
                                 style="width: <?php echo ($data['count'] / max(1, max(array_column($headcountData, 'count')))) * 100; ?>%"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <?php if (empty($headcountData)): ?>
                    <div class="text-center py-8">
                        <svg class="w-12 h-12 text-gray-300 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                        </svg>
                        <p class="text-sm text-gray-500">No data available</p>
                    </div>
                <?php endif; ?>
            </div>
            <a href="<?php echo BASE_URL; ?>/reports/headcount.php" class="mt-4 flex items-center justify-center w-full px-4 py-2 text-sm font-medium text-green-600 hover:text-green-700 hover:bg-green-50 rounded-lg transition-colors">
                Full Report 
                <svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                </svg>
            </a>
        </div>
        
        <!-- Recent Activities -->
        <div class="bg-white dark:bg-gray-800 dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-5">
            <div class="flex items-center space-x-2 mb-4">
                <svg class="w-5 h-5 text-cyan-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <h3 class="font-semibold text-gray-900 dark:text-gray-100">Recent Activity</h3>
            </div>
            <div class="space-y-2 max-h-80 overflow-y-auto">
                <?php foreach ($recentActivities as $activity): ?>
                    <div class="flex items-start space-x-3 p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors">
                        <div class="w-8 h-8 rounded-full bg-blue-100 flex items-center justify-center flex-shrink-0">
                            <svg class="w-4 h-4 text-blue-600" fill="currentColor" viewBox="0 0 20 20">
                                <?php if (strpos($activity['action'], 'login') !== false): ?>
                                    <path fill-rule="evenodd" d="M3 3a1 1 0 011 1v12a1 1 0 11-2 0V4a1 1 0 011-1zm7.707 3.293a1 1 0 010 1.414L9.414 9H17a1 1 0 110 2H9.414l1.293 1.293a1 1 0 01-1.414 1.414l-3-3a1 1 0 010-1.414l3-3a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                <?php elseif (strpos($activity['action'], 'add') !== false): ?>
                                    <path fill-rule="evenodd" d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" clip-rule="evenodd"></path>
                                <?php elseif (strpos($activity['action'], 'update') !== false || strpos($activity['action'], 'edit') !== false): ?>
                                    <path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"></path>
                                <?php elseif (strpos($activity['action'], 'delete') !== false || strpos($activity['action'], 'remove') !== false): ?>
                                    <path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                                <?php else: ?>
                                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                                <?php endif; ?>
                            </svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate"><?php echo Security::escape($activity['full_name'] ?? 'System'); ?></p>
                            <p class="text-xs text-gray-600 dark:text-gray-400 truncate"><?php echo Security::escape($activity['action']); ?></p>
                            <p class="text-xs text-gray-400 mt-0.5"><?php echo formatDateTime($activity['created_at']); ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <?php if (empty($recentActivities)): ?>
                    <div class="text-center py-8">
                        <svg class="w-12 h-12 text-gray-300 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <p class="text-sm text-gray-500">No recent activity</p>
                    </div>
                <?php endif; ?>
            </div>
            <?php if ($currentUser['role'] === 'admin'): ?>
                <a href="<?php echo BASE_URL; ?>/admin/audit.php" class="mt-4 flex items-center justify-center w-full px-4 py-2 text-sm font-medium text-cyan-600 hover:text-cyan-700 hover:bg-cyan-50 rounded-lg transition-colors">
                    View Audit Log 
                    <svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </a>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Quick Actions -->
    <div class="bg-white dark:bg-gray-800 dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-5">
        <div class="flex items-center space-x-2 mb-4">
            <svg class="w-5 h-5 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
            </svg>
            <h3 class="font-semibold text-gray-900 dark:text-gray-100">Quick Actions</h3>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
            <a href="<?php echo BASE_URL; ?>/officers/add.php" class="flex items-center justify-center px-4 py-3 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors shadow-sm">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path>
                </svg>
                <span class="text-sm font-medium">Add Officer</span>
            </a>
            <a href="<?php echo BASE_URL; ?>/officers/list.php" class="flex items-center justify-center px-4 py-3 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"></path>
                </svg>
                <span class="text-sm font-medium">View Officers</span>
            </a>
            <a href="<?php echo BASE_URL; ?>/transfers/transfer-in.php" class="flex items-center justify-center px-4 py-3 bg-green-500 text-white rounded-lg hover:bg-green-600 transition-colors shadow-sm">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4"></path>
                </svg>
                <span class="text-sm font-medium">Transfer In</span>
            </a>
            <a href="<?php echo BASE_URL; ?>/transfers/transfer-out.php" class="flex items-center justify-center px-4 py-3 bg-yellow-500 text-white rounded-lg hover:bg-yellow-600 transition-colors shadow-sm">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16v-12m0 0l-4 4m4-4l4 4"></path>
                </svg>
                <span class="text-sm font-medium">Transfer Out</span>
            </a>
            <a href="<?php echo BASE_URL; ?>/officers/remove.php" class="flex items-center justify-center px-4 py-3 bg-red-500 text-white rounded-lg hover:bg-red-600 transition-colors shadow-sm">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7a4 4 0 11-8 0 4 4 0 018 0zM9 14a6 6 0 00-6 6v1h12v-1a6 6 0 00-6-6zM21 12h-6"></path>
                </svg>
                <span class="text-sm font-medium">Remove Officer</span>
            </a>
            <a href="<?php echo BASE_URL; ?>/calendar.php" class="flex items-center justify-center px-4 py-3 bg-purple-500 text-white rounded-lg hover:bg-purple-600 transition-colors shadow-sm">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                </svg>
                <span class="text-sm font-medium">Calendar</span>
            </a>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/includes/layout.php';
?>
