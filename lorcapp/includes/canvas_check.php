<?php
/**
 * Canvas Fingerprint Check Endpoint
 * Detects suspicious canvas fingerprints
 */

session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit();
}

$canvasHash = $_POST['canvas_hash'] ?? '';

if (empty($canvasHash)) {
    http_response_code(400);
    exit();
}

// Store canvas hash in session
$_SESSION['canvas_hash'] = $canvasHash;

// Log for analysis
$logFile = __DIR__ . '/../logs/canvas_fingerprints.log';
if (!file_exists(dirname($logFile))) {
    mkdir(dirname($logFile), 0755, true);
}

file_put_contents(
    $logFile,
    json_encode([
        'timestamp' => date('Y-m-d H:i:s'),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'canvas_hash' => $canvasHash
    ], JSON_PRETTY_PRINT) . "\n---\n",
    FILE_APPEND | LOCK_EX
);

http_response_code(200);
echo json_encode(['success' => true]);
?>
