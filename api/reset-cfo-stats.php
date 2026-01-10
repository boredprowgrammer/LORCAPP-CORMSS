<?php
require_once __DIR__ . '/../config/config.php';

Security::requireLogin();
requirePermission('can_view_reports');

$currentUser = getCurrentUser();

// Only local accounts can reset
if ($currentUser['role'] !== 'local') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Only local accounts can reset CFO statistics']);
    exit;
}

header('Content-Type: application/json');

try {
    $db = Database::getInstance()->getConnection();
    
    $input = json_decode(file_get_contents('php://input'), true);
    $classification = Security::sanitizeInput($input['classification'] ?? 'all');
    $period = Security::sanitizeInput($input['period'] ?? 'both');
    
    // Validate classification
    $validClassifications = ['Buklod', 'Kadiwa', 'Binhi', 'all'];
    if (!in_array($classification, $validClassifications)) {
        throw new Exception('Invalid classification');
    }
    
    // Validate period
    $validPeriods = ['week', 'month', 'both'];
    if (!in_array($period, $validPeriods)) {
        throw new Exception('Invalid period');
    }
    
    $resetTime = date('Y-m-d H:i:s');
    $localCode = $currentUser['local_code'];
    $userId = $currentUser['user_id'];
    
    // Insert reset records
    if ($period === 'both') {
        $stmt = $db->prepare("
            INSERT INTO cfo_report_resets (local_code, classification, period, reset_at, reset_by)
            VALUES (?, ?, 'week', ?, ?), (?, ?, 'month', ?, ?)
        ");
        $stmt->execute([
            $localCode, $classification, $resetTime, $userId,
            $localCode, $classification, $resetTime, $userId
        ]);
    } else {
        $stmt = $db->prepare("
            INSERT INTO cfo_report_resets (local_code, classification, period, reset_at, reset_by)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$localCode, $classification, $period, $resetTime, $userId]);
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Statistics reset successfully',
        'reset_at' => $resetTime
    ]);
    
} catch (Exception $e) {
    error_log("CFO reset error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to reset statistics: ' . $e->getMessage()
    ]);
}
