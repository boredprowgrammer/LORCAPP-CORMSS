<?php
/**
 * Challenge Verification Endpoint
 * Verifies proof-of-work computational challenge
 */

session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit();
}

$nonce = $_POST['nonce'] ?? '';
$hash = $_POST['hash'] ?? '';

if (empty($nonce) || empty($hash)) {
    http_response_code(400);
    exit();
}

// Verify the challenge
$difficulty = 2;
$prefix = str_repeat('0', $difficulty);
$valid = substr($hash, 0, $difficulty) === $prefix;

if ($valid) {
    $_SESSION['challenge_solved'] = true;
    $_SESSION['challenge_time'] = time();
    
    http_response_code(200);
    echo json_encode(['success' => true, 'valid' => true]);
} else {
    http_response_code(200);
    echo json_encode(['success' => true, 'valid' => false]);
}
?>
