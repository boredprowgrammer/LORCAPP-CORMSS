<?php
/**
 * Verify Developer PIN
 * Secret endpoint to enable developer mode
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';

header('Content-Type: application/json');

// Verify CSRF token
$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!Security::validateCSRFToken($token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$pin = $input['pin'] ?? '';

// Get developer PIN from environment variable
$devPin = getenv('DEVELOPER_PIN') ?: null;

// If no PIN is set in environment, deny access
if (!$devPin) {
    error_log('DEVELOPER_PIN not set in environment variables');
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Developer mode not configured']);
    exit;
}

// Verify PIN
if ($pin === $devPin) {
    // Log successful developer mode activation
    error_log('Developer mode activated from IP: ' . $_SERVER['REMOTE_ADDR']);
    
    echo json_encode([
        'success' => true,
        'message' => 'Developer mode enabled'
    ]);
} else {
    // Log failed attempt
    error_log('Failed developer mode attempt from IP: ' . $_SERVER['REMOTE_ADDR']);
    
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid PIN'
    ]);
}
