<?php
/**
 * Get CFO Data for DataTables
 */

require_once __DIR__ . '/../config/config.php';

Security::requireLogin();
requirePermission('can_view_reports');

header('Content-Type: application/json');

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

try {
    // Get DataTables parameters
    $draw = intval($_GET['draw'] ?? 1);
    $start = intval($_GET['start'] ?? 0);
    $length = intval($_GET['length'] ?? 25);
    $searchValue = Security::sanitizeInput($_GET['search']['value'] ?? '');
    
    // Get filter parameters
    $filterClassification = Security::sanitizeInput($_GET['classification'] ?? '');
    $filterStatus = Security::sanitizeInput($_GET['status'] ?? '');
    $filterDistrict = Security::sanitizeInput($_GET['district'] ?? '');
    $filterLocal = Security::sanitizeInput($_GET['local'] ?? '');
    
    // Build WHERE conditions
    $whereConditions = [];
    $params = [];
    
    // Role-based filtering
    if ($currentUser['role'] === 'district') {
        $whereConditions[] = 't.district_code = ?';
        $params[] = $currentUser['district_code'];
    } elseif ($currentUser['role'] === 'local') {
        $whereConditions[] = 't.local_code = ?';
        $params[] = $currentUser['local_code'];
    }
    
    // Classification filter
    if (!empty($filterClassification)) {
        if ($filterClassification === 'null') {
            $whereConditions[] = 't.cfo_classification IS NULL';
        } else {
            $whereConditions[] = 't.cfo_classification = ?';
            $params[] = $filterClassification;
        }
    }
    
    // Status filter
    if (!empty($filterStatus)) {
        $whereConditions[] = 't.cfo_status = ?';
        $params[] = $filterStatus;
    }
    
    // District filter
    if (!empty($filterDistrict)) {
        $whereConditions[] = 't.district_code = ?';
        $params[] = $filterDistrict;
    }
    
    // Local filter
    if (!empty($filterLocal)) {
        $whereConditions[] = 't.local_code = ?';
        $params[] = $filterLocal;
    }
    
    // Search filter - use indexed search columns
    if (!empty($searchValue)) {
        $whereConditions[] = '(t.search_name LIKE ? OR t.search_registry LIKE ? OR d.district_name LIKE ? OR lc.local_name LIKE ?)';
        $searchParam = '%' . $searchValue . '%';
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }
    
    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    // Get total count (without search and filters)
    $stmtTotal = $db->prepare("SELECT COUNT(*) as total FROM tarheta_control t");
    $stmtTotal->execute([]);
    $totalRecords = $stmtTotal->fetch()['total'];
    
    // Get records with pagination
    $query = "
        SELECT 
            t.id,
            t.last_name_encrypted,
            t.first_name_encrypted,
            t.middle_name_encrypted,
            t.husbands_surname_encrypted,
            t.registry_number_encrypted,
            t.birthday_encrypted,
            t.cfo_classification,
            t.cfo_classification_auto,
            t.cfo_status,
            t.cfo_notes,
            t.district_code,
            t.local_code,
            d.district_name,
            lc.local_name
        FROM tarheta_control t
        LEFT JOIN districts d ON t.district_code = d.district_code
        LEFT JOIN local_congregations lc ON t.local_code = lc.local_code
        $whereClause
        ORDER BY t.id DESC
        LIMIT $start, $length
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $records = $stmt->fetchAll();
    
    // Get filtered count
    $stmtFiltered = $db->prepare("SELECT COUNT(*) as total FROM tarheta_control t LEFT JOIN districts d ON t.district_code = d.district_code LEFT JOIN local_congregations lc ON t.local_code = lc.local_code $whereClause");
    $stmtFiltered->execute($params);
    $filteredRecords = $stmtFiltered->fetch()['total'];
    
    // Decrypt and format records
    $data = [];
    foreach ($records as $record) {
        try {
            $lastName = Encryption::decrypt($record['last_name_encrypted'], $record['district_code']);
            $firstName = Encryption::decrypt($record['first_name_encrypted'], $record['district_code']);
            $middleName = $record['middle_name_encrypted'] ? Encryption::decrypt($record['middle_name_encrypted'], $record['district_code']) : '';
            $husbandsSurname = $record['husbands_surname_encrypted'] ? Encryption::decrypt($record['husbands_surname_encrypted'], $record['district_code']) : '';
            $registryNumber = Encryption::decrypt($record['registry_number_encrypted'], $record['district_code']);
            $birthday = $record['birthday_encrypted'] ? Encryption::decrypt($record['birthday_encrypted'], $record['district_code']) : '';
            
            // Format birthday
            if ($birthday) {
                try {
                    $birthdayDate = new DateTime($birthday);
                    $birthday = $birthdayDate->format('M d, Y');
                } catch (Exception $e) {
                    $birthday = '-';
                }
            } else {
                $birthday = '-';
            }
            
            // Full name
            $fullName = trim($firstName . ' ' . $middleName . ' ' . $lastName);
            if ($husbandsSurname) {
                $fullName .= ' (' . $husbandsSurname . ')';
            }
            
            $data[] = [
                'id' => $record['id'],
                'name' => $fullName,
                'last_name' => $lastName,
                'first_name' => $firstName,
                'middle_name' => $middleName ?: '-',
                'registry_number' => $registryNumber,
                'husbands_surname' => $husbandsSurname ?: '-',
                'birthday' => $birthday,
                'cfo_classification' => $record['cfo_classification'],
                'cfo_classification_auto' => $record['cfo_classification_auto'],
                'cfo_status' => $record['cfo_status'],
                'district_name' => $record['district_name'],
                'local_name' => $record['local_name']
            ];
        } catch (Exception $e) {
            error_log("Decryption error for record {$record['id']}: " . $e->getMessage());
        }
    }
    
    echo json_encode([
        'draw' => $draw,
        'recordsTotal' => $totalRecords,
        'recordsFiltered' => $filteredRecords,
        'data' => $data
    ]);
    
} catch (Exception $e) {
    error_log("Error in get-cfo-data.php: " . $e->getMessage());
    echo json_encode([
        'draw' => 1,
        'recordsTotal' => 0,
        'recordsFiltered' => 0,
        'data' => [],
        'error' => 'An error occurred while loading data'
    ]);
}
