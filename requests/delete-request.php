<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';


Security::requireLogin();

$user = getCurrentUser();
$allowedRoles = ['admin', 'district', 'local'];
if (!in_array($user['role'], $allowedRoles)) {
    $_SESSION['error'] = "You don't have permission to delete requests.";
    header('Location: list.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: list.php');
    exit;
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!Security::validateCSRFToken($csrfToken)) {
    $_SESSION['error'] = 'Invalid security token.';
    header('Location: list.php');
    exit;
}

$requestId = $_POST['request_id'] ?? '';
if (empty($requestId) || !ctype_digit($requestId)) {
    $_SESSION['error'] = 'Invalid request ID.';
    header('Location: list.php');
    exit;
}

$db = Database::getInstance()->getConnection();

try {
    $stmt = $db->prepare('DELETE FROM officer_requests WHERE request_id = ?');
    $stmt->execute([$requestId]);
    $_SESSION['success'] = 'Request deleted successfully.';
} catch (Exception $e) {
    error_log('Delete request error: ' . $e->getMessage());
    $_SESSION['error'] = 'Failed to delete request.';
}

header('Location: list.php');
exit;
