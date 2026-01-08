<?php
/**
 * Get CFO Details for Editing
 */

require_once __DIR__ . '/../config/config.php';

Security::requireLogin();
requirePermission('can_view_reports');

header('Content-Type: application/json');

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

try {
    $id = intval($_GET['id'] ?? 0);
    
    if ($id <= 0) {
        throw new Exception('Invalid ID');
    }
    
    // Get record
    $stmt = $db->prepare("
        SELECT 
            t.*,
            d.district_name,
            lc.local_name
        FROM tarheta_control t
        LEFT JOIN districts d ON t.district_code = d.district_code
        LEFT JOIN local_congregations lc ON t.local_code = lc.local_code
        WHERE t.id = ?
    ");
    $stmt->execute([$id]);
    $record = $stmt->fetch();
    
    if (!$record) {
        throw new Exception('Record not found');
    }
    
    // Check access
    if ($currentUser['role'] === 'district' && $record['district_code'] !== $currentUser['district_code']) {
        throw new Exception('Access denied');
    }
    if ($currentUser['role'] === 'local' && $record['local_code'] !== $currentUser['local_code']) {
        throw new Exception('Access denied');
    }
    
    // Decrypt data
    $lastName = Encryption::decrypt($record['last_name_encrypted'], $record['district_code']);
    $firstName = Encryption::decrypt($record['first_name_encrypted'], $record['district_code']);
    $middleName = $record['middle_name_encrypted'] ? Encryption::decrypt($record['middle_name_encrypted'], $record['district_code']) : '';
    $husbandsSurname = $record['husbands_surname_encrypted'] ? Encryption::decrypt($record['husbands_surname_encrypted'], $record['district_code']) : '';
    $registryNumber = Encryption::decrypt($record['registry_number_encrypted'], $record['district_code']);
    $birthday = $record['birthday_encrypted'] ? Encryption::decrypt($record['birthday_encrypted'], $record['district_code']) : '';
    
    // Full name
    $fullName = trim($firstName . ' ' . $middleName . ' ' . $lastName);
    if ($husbandsSurname) {
        $fullName .= ' (' . $husbandsSurname . ')';
    }
    
    echo json_encode([
        'success' => true,
        'id' => $record['id'],
        'name' => $fullName,
        'first_name' => $firstName,
        'middle_name' => $middleName,
        'last_name' => $lastName,
        'husbands_surname' => $husbandsSurname,
        'registry_number' => $registryNumber,
        'birthday' => $birthday ? date('M d, Y', strtotime($birthday)) : '',
        'birthday_raw' => $birthday,
        'cfo_classification' => $record['cfo_classification'],
        'cfo_classification_auto' => $record['cfo_classification_auto'],
        'cfo_status' => $record['cfo_status'],
        'cfo_notes' => $record['cfo_notes'],
        'district_name' => $record['district_name'],
        'local_name' => $record['local_name']
    ]);
    
} catch (Exception $e) {
    error_log("Error in get-cfo-details.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
