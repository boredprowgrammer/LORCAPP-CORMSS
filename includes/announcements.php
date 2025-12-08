<?php
// Get announcements relevant to current user
function getUserAnnouncements($userId = null) {
    if (!$userId) {
        $currentUser = getCurrentUser();
        $userId = $currentUser['user_id'];
    } else {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM users WHERE user_id = ?");
        $stmt->execute([$userId]);
        $currentUser = $stmt->fetch();
    }
    
    if (!$currentUser) {
        return [];
    }
    
    $db = Database::getInstance()->getConnection();
    $now = date('Y-m-d H:i:s');
    
    try {
        // Build query based on user role and location
        $query = "
            SELECT 
                a.*,
                d.district_name,
                lc.local_name
            FROM announcements a
            LEFT JOIN announcement_dismissals ad ON a.announcement_id = ad.announcement_id AND ad.user_id = ?
            LEFT JOIN districts d ON a.target_district_code = d.district_code
            LEFT JOIN local_congregations lc ON a.target_local_code = lc.local_code
            WHERE a.is_active = 1
            AND ad.id IS NULL
            AND (a.start_date IS NULL OR a.start_date <= ?)
            AND (a.end_date IS NULL OR a.end_date >= ?)
            AND (
                a.target_role = 'all'
                OR a.target_role = ?
                OR (a.target_district_code = ? AND a.target_local_code IS NULL)
                OR (a.target_local_code = ?)
            )
            ORDER BY a.is_pinned DESC, a.priority DESC, a.created_at DESC
            LIMIT 10
        ";
        
        $stmt = $db->prepare($query);
        $stmt->execute([
            $userId,
            $now,
            $now,
            $currentUser['role'],
            $currentUser['district_code'] ?? '',
            $currentUser['local_code'] ?? ''
        ]);
        
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Get user announcements error: " . $e->getMessage());
        return [];
    }
}

// Dismiss an announcement for a user
function dismissAnnouncement($announcementId, $userId) {
    $db = Database::getInstance()->getConnection();
    
    try {
        $stmt = $db->prepare("
            INSERT INTO announcement_dismissals (announcement_id, user_id) 
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE dismissed_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([$announcementId, $userId]);
        return true;
    } catch (Exception $e) {
        error_log("Dismiss announcement error: " . $e->getMessage());
        return false;
    }
}

// Render announcement HTML
function renderAnnouncement($announcement, $dismissible = true) {
    $typeColors = [
        'info' => 'bg-blue-50 border-blue-200 text-blue-800',
        'success' => 'bg-green-50 border-green-200 text-green-800',
        'warning' => 'bg-yellow-50 border-yellow-200 text-yellow-800',
        'error' => 'bg-red-50 border-red-200 text-red-800'
    ];
    
    $typeIcons = [
        'info' => '<path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>',
        'success' => '<path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>',
        'warning' => '<path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>',
        'error' => '<path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>'
    ];
    
    $type = $announcement['announcement_type'] ?? 'info';
    $colorClass = $typeColors[$type] ?? $typeColors['info'];
    $icon = $typeIcons[$type] ?? $typeIcons['info'];
    $isPinned = $announcement['is_pinned'] == 1;
    
    $html = '<div class="announcement-item ' . $colorClass . ' border rounded-lg p-4 mb-4 relative" data-announcement-id="' . $announcement['announcement_id'] . '">';
    $html .= '<div class="flex items-start">';
    $html .= '<div class="flex-shrink-0"><svg class="w-5 h-5 mr-3" fill="currentColor" viewBox="0 0 20 20">' . $icon . '</svg></div>';
    $html .= '<div class="flex-1">';
    
    if ($isPinned) {
        $html .= '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800 mb-2"><svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20"><path d="M11 3a1 1 0 10-2 0v1a1 1 0 102 0V3zM15.657 5.757a1 1 0 00-1.414-1.414l-.707.707a1 1 0 001.414 1.414l.707-.707zM18 10a1 1 0 01-1 1h-1a1 1 0 110-2h1a1 1 0 011 1zM5.05 6.464A1 1 0 106.464 5.05l-.707-.707a1 1 0 00-1.414 1.414l.707.707zM5 10a1 1 0 01-1 1H3a1 1 0 110-2h1a1 1 0 011 1zM8 16v-1h4v1a2 2 0 11-4 0zM12 14c.015-.34.208-.646.477-.859a4 4 0 10-4.954 0c.27.213.462.519.476.859h4.002z"></path></svg>Pinned</span>';
    }
    
    $html .= '<p class="font-semibold mb-1">' . Security::escape($announcement['title']) . '</p>';
    $html .= '<p class="text-sm">' . nl2br(Security::escape($announcement['message'])) . '</p>';
    $html .= '<p class="text-xs mt-2 opacity-75">' . formatDateTime($announcement['created_at']) . '</p>';
    $html .= '</div>';
    
    if ($dismissible) {
        $html .= '<button onclick="dismissAnnouncement(' . $announcement['announcement_id'] . ')" class="flex-shrink-0 ml-3 text-current hover:opacity-75 transition-opacity" title="Dismiss">';
        $html .= '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">';
        $html .= '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>';
        $html .= '</svg></button>';
    }
    
    $html .= '</div></div>';
    
    return $html;
}
?>
