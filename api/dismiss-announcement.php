<?php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

Security::requireLogin();

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$announcementId = intval($data['announcement_id'] ?? 0);

if (!$announcementId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid announcement ID']);
    exit;
}

try {
    // Check if announcement exists
    $stmt = $db->prepare("SELECT announcement_id FROM announcements WHERE announcement_id = ?");
    $stmt->execute([$announcementId]);
    
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Announcement not found']);
        exit;
    }
    
    // Dismiss announcement for this user
    $stmt = $db->prepare("
        INSERT INTO announcement_dismissals (announcement_id, user_id) 
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE dismissed_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([$announcementId, $currentUser['user_id']]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Announcement dismissed'
    ]);
    
} catch (Exception $e) {
    error_log("Dismiss announcement API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while dismissing the announcement'
    ]);
}
?>
