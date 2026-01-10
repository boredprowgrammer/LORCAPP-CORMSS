<?php
// Prevent any output before JSON
ob_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/encryption.php';

// Clear any buffered output
ob_end_clean();

header('Content-Type: application/json');

// Check if user is authenticated
if (!Security::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $currentUser = getCurrentUser();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to get current user: ' . $e->getMessage()]);
    exit;
}

// Check if user has permission to track users
if ($currentUser['role'] !== 'admin' && !$currentUser['can_track_users']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Insufficient permissions']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    
    // Get all user locations updated within the last 3 hours
    // Active: last 10 minutes, Recent: 10 minutes to 3 hours
    $stmt = $db->prepare("
        SELECT 
            ul.id,
            ul.user_id,
            ul.latitude,
            ul.longitude,
            ul.ip_address,
            ul.accuracy,
            ul.device_info,
            ul.address,
            ul.city,
            ul.country,
            ul.location_source,
            ul.last_updated,
            u.username,
            u.full_name,
            u.role,
            u.local_code,
            lc.local_name,
            CASE 
                WHEN ul.last_updated >= DATE_SUB(NOW(), INTERVAL 10 MINUTE) THEN 'active'
                ELSE 'recent'
            END as status
        FROM user_locations ul
        INNER JOIN users u ON ul.user_id = u.user_id
        LEFT JOIN local_congregations lc ON u.local_code = lc.local_code
        WHERE ul.last_updated >= DATE_SUB(NOW(), INTERVAL 3 HOUR)
        ORDER BY ul.last_updated DESC
    ");
    $stmt->execute();
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Process user names and device info
    foreach ($locations as &$location) {
        // Split full_name into first and last name
        $nameParts = explode(' ', $location['full_name'], 2);
        $location['first_name'] = $nameParts[0] ?? '';
        $location['last_name'] = $nameParts[1] ?? '';
        unset($location['full_name']);
        
        // Parse device info
        if ($location['device_info']) {
            $location['device_info'] = json_decode($location['device_info'], true);
        }
        
        // Calculate how long ago the location was updated
        $lastUpdated = new DateTime($location['last_updated']);
        $now = new DateTime();
        $interval = $now->diff($lastUpdated);
        
        if ($interval->h > 0) {
            $location['time_ago'] = $interval->h . ' hour' . ($interval->h > 1 ? 's' : '') . ' ago';
        } elseif ($interval->i == 0) {
            $location['time_ago'] = 'Just now';
        } elseif ($interval->i == 1) {
            $location['time_ago'] = '1 minute ago';
        } else {
            $location['time_ago'] = $interval->i . ' minutes ago';
        }
    }
    
    // Separate active and recent users
    $activeLocations = array_filter($locations, fn($loc) => $loc['status'] === 'active');
    $recentLocations = array_filter($locations, fn($loc) => $loc['status'] === 'recent');
    
    echo json_encode([
        'success' => true,
        'locations' => $locations,
        'active_locations' => array_values($activeLocations),
        'recent_locations' => array_values($recentLocations),
        'count' => count($locations),
        'active_count' => count($activeLocations),
        'recent_count' => count($recentLocations)
    ]);
    
} catch (Exception $e) {
    error_log("Error fetching user locations: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to fetch locations: ' . $e->getMessage()
    ]);
}
