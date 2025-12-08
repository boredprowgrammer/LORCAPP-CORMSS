<?php
/**
 * JavaScript Verification Endpoint
 * Sets session flag when JavaScript is enabled
 */

session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['js_enabled'])) {
    $_SESSION['js_verified'] = true;
    $_SESSION['js_verify_time'] = time();
    
    http_response_code(200);
    echo json_encode(['success' => true]);
} else {
    http_response_code(400);
    echo json_encode(['success' => false]);
}
?>
