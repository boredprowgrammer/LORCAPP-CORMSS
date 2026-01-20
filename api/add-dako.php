<?php
/**
 * Add Dako API
 * Adds a new Dako (chapter/group) entry
 */

require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

Security::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$districtCode = Security::sanitizeInput($input['district_code'] ?? '');
$localCode = Security::sanitizeInput($input['local_code'] ?? '');
$dakoName = Security::sanitizeInput($input['dako_name'] ?? '');
$description = Security::sanitizeInput($input['description'] ?? '');
$leaderName = Security::sanitizeInput($input['leader_name'] ?? '');

if (empty($districtCode) || empty($localCode) || empty($dakoName)) {
    echo json_encode(['success' => false, 'error' => 'District, local, and Dako name are required']);
    exit;
}

$currentUser = getCurrentUser();

// Check permission
if ($currentUser['role'] !== 'admin' && $currentUser['role'] !== 'local') {
    echo json_encode(['success' => false, 'error' => 'You do not have permission to add Dako']);
    exit;
}

$db = Database::getInstance()->getConnection();

try {
    // Check if Dako already exists
    $stmt = $db->prepare("
        SELECT id FROM pnk_dako 
        WHERE district_code = ? AND local_code = ? AND dako_name = ?
    ");
    $stmt->execute([$districtCode, $localCode, $dakoName]);
    
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'A Dako with this name already exists']);
        exit;
    }
    
    // Insert new Dako
    $stmt = $db->prepare("
        INSERT INTO pnk_dako (district_code, local_code, dako_name, description, leader_name, created_by)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $districtCode, 
        $localCode, 
        $dakoName, 
        $description ?: null, 
        $leaderName ?: null, 
        $currentUser['user_id']
    ]);
    
    $dakoId = $db->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'message' => 'Dako added successfully',
        'dako_id' => $dakoId
    ]);
    
} catch (Exception $e) {
    error_log("Add Dako error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Failed to add Dako']);
}
