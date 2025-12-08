<?php
/**
 * ALTCHA Challenge Endpoint
 * Generates challenges for ALTCHA widget
 */

// Simple rate limiting
session_start();
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rate_limit_key = 'altcha_' . md5($ip);

if (!isset($_SESSION[$rate_limit_key])) {
    $_SESSION[$rate_limit_key] = ['count' => 0, 'reset' => time() + 60];
}

// Reset counter every minute
if (time() > $_SESSION[$rate_limit_key]['reset']) {
    $_SESSION[$rate_limit_key] = ['count' => 0, 'reset' => time() + 60];
}

// Allow max 20 requests per minute
if ($_SESSION[$rate_limit_key]['count'] >= 20) {
    http_response_code(429);
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Too many requests. Please try again later.']));
}

$_SESSION[$rate_limit_key]['count']++;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Only accept GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET, OPTIONS');
    die(json_encode(['error' => 'Method not allowed. Use GET.']));
}

// ALTCHA configuration
// Use a secure random key - should be in .env file
$hmacKey = getenv('ALTCHA_HMAC_KEY');
if (!$hmacKey) {
    error_log('CRITICAL: ALTCHA_HMAC_KEY not configured');
    http_response_code(500);
    die(json_encode(['error' => 'Server configuration error']));
}

$algorithm = 'SHA-256';
$maxNumber = 50000; // Difficulty level

/**
 * Generate ALTCHA challenge
 */
function generateChallenge($maxNumber, $hmacKey, $algorithm) {
    // Generate random salt
    $salt = bin2hex(random_bytes(12));
    
    // Generate cryptographically secure random secret number
    $secretNumber = random_int(0, $maxNumber);
    
    // Create the challenge hash (what needs to be solved)
    $challenge = hash('sha256', $salt . $secretNumber);
    
    // Create payload for signature (without signature field)
    $payloadForSigning = sprintf(
        '%s?challenge=%s&maxnumber=%d&salt=%s',
        $algorithm,
        $challenge,
        $maxNumber,
        $salt
    );
    
    // Sign the payload
    $signature = hash_hmac('sha256', $payloadForSigning, $hmacKey);
    
    return [
        'algorithm' => $algorithm,
        'challenge' => $challenge,
        'maxnumber' => $maxNumber,
        'salt' => $salt,
        'signature' => $signature
    ];
}

try {
    $challenge = generateChallenge($maxNumber, $hmacKey, $algorithm);
    
    echo json_encode($challenge);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to generate challenge',
        'message' => $e->getMessage()
    ]);
}
