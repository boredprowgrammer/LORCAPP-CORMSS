<?php
/**
 * List Overseers Contacts API
 */
// Suppress warnings and errors from output
error_reporting(E_ERROR | E_PARSE);
ob_start();

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/encryption.php';

// Clean any output buffer before sending JSON
ob_clean();
header('Content-Type: application/json');

Security::requireLogin();

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

try {
    // Build query based on user role
    $whereClause = '';
    $params = [];
    
    if ($currentUser['role'] === 'local') {
        $whereClause = 'WHERE oc.local_code = ?';
        $params[] = $currentUser['local_code'];
    } elseif ($currentUser['role'] === 'district') {
        $whereClause = 'WHERE oc.district_code = ?';
        $params[] = $currentUser['district_code'];
    }
    
    // Get all contacts
    $stmt = $db->prepare("
        SELECT 
            oc.contact_id,
            oc.contact_type,
            oc.district_code,
            oc.local_code,
            oc.purok_grupo,
            oc.purok,
            oc.katiwala_officer_ids,
            oc.katiwala_manual_name,
            oc.ii_katiwala_officer_ids,
            oc.ii_katiwala_manual_name,
            oc.kalihim_officer_ids,
            oc.kalihim_manual_name,
            oc.katiwala_contact,
            oc.katiwala_telegram,
            oc.ii_katiwala_contact,
            oc.ii_katiwala_telegram,
            oc.kalihim_contact,
            oc.kalihim_telegram,
            oc.is_active,
            oc.created_at,
            oc.updated_at,
            l.local_name,
            d.district_name
        FROM overseers_contacts oc
        LEFT JOIN local_congregations l ON oc.local_code = l.local_code
        LEFT JOIN districts d ON oc.district_code = d.district_code
        $whereClause
        ORDER BY oc.contact_type, oc.local_code, oc.purok_grupo, oc.purok
    ");
    
    $stmt->execute($params);
    $contacts = $stmt->fetchAll();
    
    // Decrypt officer names and get names from officer registry
    foreach ($contacts as &$contact) {
        // Prioritize manual names over looked-up names
        $contact['katiwala_names'] = $contact['katiwala_manual_name'] ?: getOfficerNames($contact['katiwala_officer_ids'], $contact['district_code'], $db);
        $contact['ii_katiwala_names'] = $contact['ii_katiwala_manual_name'] ?: getOfficerNames($contact['ii_katiwala_officer_ids'], $contact['district_code'], $db);
        $contact['kalihim_names'] = $contact['kalihim_manual_name'] ?: getOfficerNames($contact['kalihim_officer_ids'], $contact['district_code'], $db);
        
        // Remove encrypted IDs and manual names from response
        unset($contact['katiwala_officer_ids']);
        unset($contact['ii_katiwala_officer_ids']);
        unset($contact['kalihim_officer_ids']);
        unset($contact['katiwala_manual_name']);
        unset($contact['ii_katiwala_manual_name']);
        unset($contact['kalihim_manual_name']);
    }
    
    echo json_encode([
        'success' => true,
        'contacts' => $contacts
    ]);
    
} catch (Exception $e) {
    error_log("Error loading overseers contacts: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to load contacts'
    ]);
}

/**
 * Get officer names from encrypted JSON array of officer IDs
 */
function getOfficerNames($encryptedJson, $districtCode, $db) {
    if (empty($encryptedJson)) {
        return '';
    }
    
    try {
        // Decrypt the JSON array
        $decrypted = Encryption::decrypt($encryptedJson, $districtCode);
        $officerIds = json_decode($decrypted, true);
        
        if (empty($officerIds) || !is_array($officerIds)) {
            return '';
        }
        
        // Get officer names
        $placeholders = implode(',', array_fill(0, count($officerIds), '?'));
        $stmt = $db->prepare("
            SELECT full_name_encrypted 
            FROM officers 
            WHERE officer_id IN ($placeholders)
        ");
        $stmt->execute($officerIds);
        $officers = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Decrypt names
        $names = [];
        foreach ($officers as $encryptedName) {
            $name = Encryption::decrypt($encryptedName, $districtCode);
            if (!empty($name)) {
                $names[] = $name;
            }
        }
        
        return implode(', ', $names);
        
    } catch (Exception $e) {
        error_log("Error decrypting officer names: " . $e->getMessage());
        return '';
    }
}
