<?php
/**
 * Behavior Tracking Endpoint
 * Receives and analyzes user interaction data
 */

session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    http_response_code(400);
    exit();
}

// Analyze behavior
$suspiciousScore = 0;

// Check if this is a suspicious behavior report
if (isset($data['suspicious']) && $data['suspicious']) {
    $suspiciousScore += 50;
}

// Check mouse movements (humans move mouse)
if (isset($data['mouseMovements'])) {
    if ($data['mouseMovements'] === 0) {
        $suspiciousScore += 30;
    }
}

// Check clicks (humans click)
if (isset($data['clicks'])) {
    if ($data['clicks'] === 0) {
        $suspiciousScore += 20;
    }
}

// Check duration (too short = bot)
if (isset($data['duration'])) {
    if ($data['duration'] < 1000) { // Less than 1 second
        $suspiciousScore += 25;
    }
}

// Log behavior
$logFile = __DIR__ . '/../logs/behavior_tracking.log';
if (!file_exists(dirname($logFile))) {
    mkdir(dirname($logFile), 0755, true);
}

file_put_contents(
    $logFile,
    json_encode([
        'timestamp' => date('Y-m-d H:i:s'),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'suspicious_score' => $suspiciousScore,
        'data' => $data
    ], JSON_PRETTY_PRINT) . "\n---\n",
    FILE_APPEND | LOCK_EX
);

// If highly suspicious, flag the session
if ($suspiciousScore >= 50) {
    $_SESSION['suspicious_behavior'] = true;
    $_SESSION['behavior_score'] = $suspiciousScore;
}

http_response_code(200);
echo json_encode(['success' => true, 'score' => $suspiciousScore]);
?>
