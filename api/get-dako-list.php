<?php
/**
 * Get Dako List API
 * Returns list of Dako entries for a specific local congregation
 */

require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

Security::requireLogin();

$currentUser = getCurrentUser();

// Use provided parameters or fall back to current user's local/district
$localCode = Security::sanitizeInput($_GET['local_code'] ?? $currentUser['local_code'] ?? '');
$districtCode = Security::sanitizeInput($_GET['district_code'] ?? $currentUser['district_code'] ?? '');

if (empty($localCode) || empty($districtCode)) {
    echo json_encode(['success' => false, 'error' => 'Local code and district code are required']);
    exit;
}

$db = Database::getInstance()->getConnection();

try {
    $stmt = $db->prepare("
        SELECT id, dako_name as name, description, leader_name, is_active
        FROM pnk_dako
        WHERE district_code = ? AND local_code = ? AND is_active = TRUE
        ORDER BY dako_name ASC
    ");
    $stmt->execute([$districtCode, $localCode]);
    $dakos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'dakos' => $dakos
    ]);
    
} catch (Exception $e) {
    error_log("Get Dako list error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Failed to load Dako list']);
}
