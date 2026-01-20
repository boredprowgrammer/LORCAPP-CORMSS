<?php
/**
 * Search PNK Registry API
 * Returns PNK records matching search criteria
 */

require_once __DIR__ . '/../config/config.php';

Security::requireLogin();

header('Content-Type: application/json');

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

// Get search parameters
$nameQuery = Security::sanitizeInput($_GET['name'] ?? '');
$registryQuery = Security::sanitizeInput($_GET['registry'] ?? '');
$statusQuery = Security::sanitizeInput($_GET['status'] ?? '');

// Build WHERE clause based on user role
$whereConditions = ['p.deleted_at IS NULL'];
$params = [];

// Role-based filtering
if ($currentUser['role'] === 'local' || $currentUser['role'] === 'local_cfo') {
    $whereConditions[] = 'p.local_code = ?';
    $params[] = $currentUser['local_code'];
} elseif ($currentUser['role'] === 'district') {
    $whereConditions[] = 'p.district_code = ?';
    $params[] = $currentUser['district_code'];
}

// Status filter - maps to attendance_status or baptism_status
if (!empty($statusQuery)) {
    if ($statusQuery === 'active') {
        $whereConditions[] = 'p.attendance_status = ?';
        $params[] = 'active';
    } elseif ($statusQuery === 'inactive') {
        $whereConditions[] = 'p.attendance_status = ?';
        $params[] = 'inactive';
    } elseif ($statusQuery === 'transferred-out') {
        $whereConditions[] = 'p.attendance_status = ?';
        $params[] = 'transferred-out';
    } elseif ($statusQuery === 'baptized') {
        $whereConditions[] = 'p.baptism_status = ?';
        $params[] = 'baptized';
    }
}

$whereClause = 'WHERE ' . implode(' AND ', $whereConditions);

try {
    // Fetch records - limit to prevent performance issues
    $stmt = $db->prepare("
        SELECT 
            p.id,
            p.registry_number,
            p.first_name_encrypted,
            p.middle_name_encrypted,
            p.last_name_encrypted,
            p.birthday_encrypted,
            p.sex,
            p.pnk_category,
            p.baptism_status,
            p.attendance_status,
            p.registration_date,
            p.dako_encrypted,
            p.district_code,
            p.local_code,
            lc.local_name,
            d.district_name
        FROM pnk_registry p
        LEFT JOIN local_congregations lc ON p.local_code = lc.local_code
        LEFT JOIN districts d ON p.district_code = d.district_code
        $whereClause
        ORDER BY p.registration_date DESC
        LIMIT 500
    ");
    
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $results = [];
    $matchCount = 0;
    $maxResults = 100;
    
    foreach ($records as $record) {
        if ($matchCount >= $maxResults) {
            break;
        }
        
        // Decrypt name for search matching
        try {
            $firstName = Encryption::decrypt($record['first_name_encrypted'], $record['district_code']);
            $middleName = $record['middle_name_encrypted'] ? Encryption::decrypt($record['middle_name_encrypted'], $record['district_code']) : '';
            $lastName = Encryption::decrypt($record['last_name_encrypted'], $record['district_code']);
            $birthday = Encryption::decrypt($record['birthday_encrypted'], $record['district_code']);
            $dako = $record['dako_encrypted'] ? Encryption::decrypt($record['dako_encrypted'], $record['district_code']) : '';
            
            $fullName = trim($firstName . ' ' . ($middleName ? $middleName . ' ' : '') . $lastName);
            
            // Apply name filter if provided
            if (!empty($nameQuery)) {
                $queryLower = mb_strtolower($nameQuery, 'UTF-8');
                $fullNameLower = mb_strtolower($fullName, 'UTF-8');
                
                if (stripos($fullNameLower, $queryLower) === false) {
                    continue; // Skip if name doesn't match
                }
            }
            
            // Apply registry number filter if provided
            if (!empty($registryQuery)) {
                if (stripos($record['registry_number'], $registryQuery) === false) {
                    continue; // Skip if registry number doesn't match
                }
            }
            
            // Calculate age from birthday
            $age = '';
            if (!empty($birthday)) {
                try {
                    $birthDate = new DateTime($birthday);
                    $now = new DateTime();
                    $age = $now->diff($birthDate)->y;
                } catch (Exception $e) {
                    $age = '';
                }
            }
            
            $results[] = [
                'id' => $record['id'],
                'registry_number' => $record['registry_number'],
                'full_name' => $fullName,
                'first_name' => $firstName,
                'middle_name' => $middleName,
                'last_name' => $lastName,
                'sex' => $record['sex'],
                'age' => $age,
                'pnk_category' => $record['pnk_category'],
                'baptism_status' => $record['baptism_status'],
                'attendance_status' => $record['attendance_status'],
                'registration_date' => $record['registration_date'],
                'dako' => $dako,
                'local_name' => $record['local_name'] ?? '',
                'district_name' => $record['district_name'] ?? ''
            ];
            
            $matchCount++;
            
        } catch (Exception $e) {
            // Skip records that fail to decrypt
            error_log("Failed to decrypt PNK record {$record['id']}: " . $e->getMessage());
            continue;
        }
    }
    
    echo json_encode([
        'success' => true,
        'records' => $results,
        'total' => count($results)
    ]);
    
} catch (Exception $e) {
    error_log("Search PNK error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Failed to search PNK records']);
}
