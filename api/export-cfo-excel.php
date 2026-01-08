<?php
/**
 * Export All CFO Data to Excel
 * Exports all filtered records (not just paginated)
 */

require_once __DIR__ . '/../config/config.php';

Security::requireLogin();
requirePermission('can_view_reports');

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

try {
    // Get filter parameters
    $searchValue = Security::sanitizeInput($_GET['search'] ?? '');
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
    
    // Get all records (no pagination)
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
            t.cfo_status,
            t.district_code,
            t.local_code,
            d.district_name,
            lc.local_name
        FROM tarheta_control t
        LEFT JOIN districts d ON t.district_code = d.district_code
        LEFT JOIN local_congregations lc ON t.local_code = lc.local_code
        $whereClause
        ORDER BY t.id DESC
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $records = $stmt->fetchAll();
    
    // Prepare data for Excel
    $excelData = [];
    $excelData[] = [
        'ID',
        'Last Name',
        'First Name',
        'Middle Name',
        'Registry Number',
        'Husband\'s Surname',
        'Birthday',
        'CFO Classification',
        'Status',
        'District',
        'Local Congregation'
    ];
    
    foreach ($records as $record) {
        try {
            // Decrypt data
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
            
            // Format classification
            $classification = $record['cfo_classification'] ?: 'Unclassified';
            
            // Format status
            $status = ($record['cfo_status'] === 'transferred-out') ? 'Transferred Out' : 'Active';
            
            $excelData[] = [
                $record['id'],
                $lastName,
                $firstName,
                $middleName ?: '-',
                $registryNumber,
                $husbandsSurname ?: '-',
                $birthday,
                $classification,
                $status,
                $record['district_name'],
                $record['local_name']
            ];
            
        } catch (Exception $e) {
            error_log("Decryption error for record {$record['id']}: " . $e->getMessage());
        }
    }
    
    // Generate filename with timestamp
    $timestamp = date('Y-m-d_His');
    $filename = "CFO_Registry_Export_{$timestamp}.csv";
    
    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Output CSV
    $output = fopen('php://output', 'w');
    
    // Add BOM for proper Excel UTF-8 encoding
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    foreach ($excelData as $row) {
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit;
    
} catch (Exception $e) {
    error_log("Error in export-cfo-excel.php: " . $e->getMessage());
    die('An error occurred while exporting data. Please try again.');
}
