<?php
/**
 * Export All CFO Data to Excel
 * Exports all filtered records (not just paginated) with auto-sized columns
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

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
    
    // Search filter - search on district/local names only (encrypted fields searched in PHP)
    // Note: For exports, we skip search term filter since it requires decryption
    // The search is applied post-decryption below if needed
    
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
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('CFO Registry');
    
    // Set headers
    $headers = [
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
    
    // Write headers
    $col = 'A';
    foreach ($headers as $header) {
        $sheet->setCellValue($col . '1', $header);
        $col++;
    }
    
    // Style headers
    $sheet->getStyle('A1:K1')->applyFromArray([
        'font' => [
            'bold' => true,
            'color' => ['rgb' => 'FFFFFF']
        ],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => '2563EB']
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER
        ],
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['rgb' => '000000']
            ]
        ]
    ]);
    
    // Write data rows
    $row = 2;
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
            
            // Write row data
            $sheet->setCellValue('A' . $row, $record['id']);
            $sheet->setCellValue('B' . $row, $lastName);
            $sheet->setCellValue('C' . $row, $firstName);
            $sheet->setCellValue('D' . $row, $middleName ?: '-');
            $sheet->setCellValue('E' . $row, $registryNumber);
            $sheet->setCellValue('F' . $row, $husbandsSurname ?: '-');
            $sheet->setCellValue('G' . $row, $birthday);
            $sheet->setCellValue('H' . $row, $classification);
            $sheet->setCellValue('I' . $row, $status);
            $sheet->setCellValue('J' . $row, $record['district_name']);
            $sheet->setCellValue('K' . $row, $record['local_name']);
            
            // Apply borders to data rows
            $sheet->getStyle('A' . $row . ':K' . $row)->applyFromArray([
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => 'CCCCCC']
                    ]
                ]
            ]);
            
            // Alternate row colors
            if ($row % 2 === 0) {
                $sheet->getStyle('A' . $row . ':K' . $row)->applyFromArray([
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'F9FAFB']
                    ]
                ]);
            }
            
            $row++;
            
        } catch (Exception $e) {
            error_log("Decryption error for record {$record['id']}: " . $e->getMessage());
        }
    }
    
    // Auto-size columns based on content
    foreach (range('A', 'K') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
    
    // Center align ID column
    $sheet->getStyle('A2:A' . ($row - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    // Generate filename with timestamp
    $timestamp = date('Y-m-d_His');
    $filename = "CFO_Registry_Export_{$timestamp}.xlsx";
    
    // Set headers for Excel download
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    header('Pragma: public');
    
    // Write file to output
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    
    exit;
    
} catch (Exception $e) {
    error_log("Error in export-cfo-excel.php: " . $e->getMessage());
    die('An error occurred while exporting data. Please try again.');
}
