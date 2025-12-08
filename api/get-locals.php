<?php
require_once __DIR__ . '/../config/config.php';

Security::requireLogin();

header('Content-Type: application/json');

$districtCode = Security::sanitizeInput($_GET['district'] ?? '');

if (empty($districtCode)) {
    echo json_encode([]);
    exit;
}

$currentUser = getCurrentUser();

// Check access
if (!hasDistrictAccess($districtCode)) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

try {
    $locals = getLocalsByDistrict($districtCode);
    echo json_encode($locals);
} catch (Exception $e) {
    error_log("Get locals API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
?>
