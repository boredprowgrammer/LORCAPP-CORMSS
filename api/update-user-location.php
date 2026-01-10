<?php
// Prevent any output before JSON
ob_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/functions.php';

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
    
    // Ensure we have a valid user_id
    if (!isset($currentUser['user_id']) || empty($currentUser['user_id'])) {
        http_response_code(500);
        echo json_encode([
            'success' => false, 
            'error' => 'Invalid user session',
            'debug' => 'user_id not found in session'
        ]);
        exit;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to get current user: ' . $e->getMessage()]);
    exit;
}

try {
    // Get input data
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid input data');
    }
    
    $latitude = isset($input['latitude']) ? floatval($input['latitude']) : null;
    $longitude = isset($input['longitude']) ? floatval($input['longitude']) : null;
    $accuracy = isset($input['accuracy']) ? floatval($input['accuracy']) : null;
    $address = isset($input['address']) ? $input['address'] : null;
    $city = isset($input['city']) ? $input['city'] : null;
    $country = isset($input['country']) ? $input['country'] : null;
    $locationSource = isset($input['location_source']) ? $input['location_source'] : 'browser';
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    // Require GPS coordinates - reject if missing
    if ($latitude === null || $longitude === null) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'GPS coordinates required. Please enable location services.'
        ]);
        exit;
    }
    
    // Get device/browser info
    $deviceInfo = json_encode([
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'platform' => php_uname('s'),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
    $db = Database::getInstance()->getConnection();
    
    // Check if user already has a location record
    $stmt = $db->prepare("SELECT id FROM user_locations WHERE user_id = ?");
    $stmt->execute([$currentUser['user_id']]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        // Update existing record
        $stmt = $db->prepare("
            UPDATE user_locations 
            SET latitude = ?, 
                longitude = ?, 
                ip_address = ?, 
                accuracy = ?,
                device_info = ?,
                address = ?,
                city = ?,
                country = ?,
                location_source = ?,
                last_updated = NOW()
            WHERE user_id = ?
        ");
        $stmt->execute([
            $latitude, 
            $longitude, 
            $ipAddress, 
            $accuracy,
            $deviceInfo,
            $address,
            $city,
            $country,
            $locationSource,
            $currentUser['user_id']
        ]);
    } else {
        // Insert new record
        $stmt = $db->prepare("
            INSERT INTO user_locations 
            (user_id, latitude, longitude, ip_address, accuracy, device_info, address, city, country, location_source, last_updated) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $currentUser['user_id'], 
            $latitude, 
            $longitude, 
            $ipAddress, 
            $accuracy,
            $deviceInfo,
            $address,
            $city,
            $country,
            $locationSource
        ]);
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Location updated successfully',
        'last_updated' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    error_log("Error updating user location: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to update location: ' . $e->getMessage()
    ]);
}
