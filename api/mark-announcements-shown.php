<?php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

Security::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Mark announcements as shown in this login session
$_SESSION['announcements_shown'] = true;

echo json_encode([
    'success' => true,
    'message' => 'Announcements marked as shown'
]);
?>
