<?php
/**
 * R5's Transactions - Logsheet PDF
 * Black and white minimalistic design with light grey table shading
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

Security::requireLogin();
requirePermission('can_view_reports');

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

// Get filter parameters
$filterPeriod = $_GET['period'] ?? 'month';
$filterMonth = $_GET['month'] ?? date('m');
$filterYear = $_GET['year'] ?? date('Y');
$filterDistrict = $_GET['district'] ?? '';
$filterLocal = $_GET['local'] ?? '';
$filterType = $_GET['type'] ?? '';

// Calculate date range
switch ($filterPeriod) {
    case 'week':
        $startDate = date('Y-m-d', strtotime('monday this week'));
        $endDate = date('Y-m-d', strtotime('sunday this week'));
        $periodLabel = 'Week: ' . date('M d', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate));
        break;
    case 'year':
        $startDate = $filterYear . '-01-01';
        $endDate = $filterYear . '-12-31';
        $periodLabel = 'Year: ' . $filterYear;
        break;
    case 'month':
    default:
        $startDate = $filterYear . '-' . str_pad($filterMonth, 2, '0', STR_PAD_LEFT) . '-01';
        $endDate = date('Y-m-t', strtotime($startDate));
        $periodLabel = 'Month: ' . date('F Y', strtotime($startDate));
        break;
}

// Build WHERE clause
$whereConditions = [];
$params = [];

if ($currentUser['role'] === 'local') {
    $whereConditions[] = 'o.local_code = ?';
    $params[] = $currentUser['local_code'];
} elseif ($currentUser['role'] === 'district') {
    $whereConditions[] = 'o.district_code = ?';
    $params[] = $currentUser['district_code'];
}

if (!empty($filterDistrict) && $currentUser['role'] === 'admin') {
    $whereConditions[] = 'o.district_code = ?';
    $params[] = $filterDistrict;
}

if (!empty($filterLocal)) {
    $whereConditions[] = 'o.local_code = ?';
    $params[] = $filterLocal;
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Query transactions
try {
    $stmt = $db->prepare("
        SELECT 
            o.officer_id,
            o.officer_uuid,
            o.last_name_encrypted,
            o.first_name_encrypted,
            o.middle_initial_encrypted,
            o.district_code,
            o.local_code,
            o.control_number,
            o.registry_number_encrypted,
            d.district_name,
            lc.local_name,
            GROUP_CONCAT(DISTINCT CONCAT(od.department, IF(od.duty IS NOT NULL AND od.duty != '', CONCAT(' (', od.duty, ')'), '')) SEPARATOR ', ') as departments,
            MAX(od.oath_date) as oath_date,
            MAX(t.transfer_date) as transfer_date,
            MAX(t.transfer_type) as transfer_type,
            MAX(t.from_district_code) as from_district_code,
            MAX(t.from_local_code) as from_local_code,
            MAX(t.to_district_code) as to_district_code,
            MAX(t.to_local_code) as to_local_code,
            MAX(r.removal_date) as removal_date,
            MAX(r.removal_code) as removal_code,
            MAX(r.reason) as removal_reason,
            CASE
                WHEN MAX(od.oath_date) >= ? AND MAX(od.oath_date) <= ? THEN 'OATH'
                WHEN MAX(t.transfer_type) = 'in' AND MAX(t.transfer_date) >= ? AND MAX(t.transfer_date) <= ? THEN 'TRANSFER-IN'
                WHEN MAX(t.transfer_type) = 'out' AND MAX(t.transfer_date) >= ? AND MAX(t.transfer_date) <= ? THEN 'TRANSFER-OUT'
                WHEN MAX(r.removal_date) >= ? AND MAX(r.removal_date) <= ? THEN 'REMOVAL'
                ELSE NULL
            END as transaction_type,
            CASE
                WHEN MAX(od.oath_date) >= ? AND MAX(od.oath_date) <= ? THEN MAX(od.oath_date)
                WHEN MAX(t.transfer_type) = 'in' AND MAX(t.transfer_date) >= ? AND MAX(t.transfer_date) <= ? THEN MAX(t.transfer_date)
                WHEN MAX(t.transfer_type) = 'out' AND MAX(t.transfer_date) >= ? AND MAX(t.transfer_date) <= ? THEN MAX(t.transfer_date)
                WHEN MAX(r.removal_date) >= ? AND MAX(r.removal_date) <= ? THEN MAX(r.removal_date)
                ELSE NULL
            END as transaction_date
        FROM officers o
        LEFT JOIN districts d ON o.district_code = d.district_code
        LEFT JOIN local_congregations lc ON o.local_code = lc.local_code
        LEFT JOIN officer_departments od ON o.officer_id = od.officer_id
        LEFT JOIN transfers t ON o.officer_id = t.officer_id
        LEFT JOIN officer_removals r ON o.officer_id = r.officer_id
        $whereClause
        GROUP BY o.officer_uuid
        HAVING transaction_type IS NOT NULL
        ORDER BY transaction_date DESC, o.last_name_encrypted
    ");
    
    $dateParams = array_merge(
        [$startDate, $endDate, $startDate, $endDate, $startDate, $endDate, $startDate, $endDate],
        [$startDate, $endDate, $startDate, $endDate, $startDate, $endDate, $startDate, $endDate],
        $params
    );
    
    $stmt->execute($dateParams);
    $transactions = $stmt->fetchAll();
    
} catch (Exception $e) {
    error_log("R5 Logsheet error: " . $e->getMessage());
    $transactions = [];
}

// Process transactions
$processedTransactions = [];
foreach ($transactions as $t) {
    if (!empty($filterType) && $t['transaction_type'] !== $filterType) {
        continue;
    }
    
    $decrypted = Encryption::decryptOfficerName(
        $t['last_name_encrypted'],
        $t['first_name_encrypted'],
        $t['middle_initial_encrypted'],
        $t['district_code']
    );
    
    $t['last_name'] = $decrypted['last_name'];
    $t['first_name'] = $decrypted['first_name'];
    $t['middle_initial'] = $decrypted['middle_initial'];
    
    if (!empty($t['registry_number_encrypted'])) {
        try {
            $t['registry_number'] = Encryption::decrypt($t['registry_number_encrypted'], $t['district_code']);
        } catch (Exception $e) {
            $t['registry_number'] = '';
        }
    } else {
        $t['registry_number'] = '';
    }
    
    $processedTransactions[] = $t;
}

// Generate HTML for PDF
$html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        @page {
            margin: 0.5cm;
            size: portrait;
        }
        
        body {
            font-family: "Courier New", Courier, monospace;
            font-size: 8pt;
            line-height: 1.3;
            color: #000;
            margin: 0;
            padding: 0;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }
        
        th {
            background-color: #333;
            color: #fff;
            font-weight: bold;
            padding: 6px 4px;
            text-align: left;
            font-size: 8pt;
            border: 1px solid #000;
        }
        
        td {
            padding: 4px;
            text-align: left;
            border: 1px solid #999;
            font-size: 8pt;
        }
        
        tr:nth-child(even) {
            background-color: #f0f0f0;
        }
        
        tr:nth-child(odd) {
            background-color: #fff;
        }
        
        .text-center {
            text-align: center;
        }
        
        .font-bold {
            font-weight: bold;
        }
    </style>
</head>
<body>
    <table>
        <thead>
            <tr>
                <th style="width: 10%;">Date</th>
                <th style="width: 12%;">Type</th>
                <th style="width: 10%;">Control #</th>
                <th style="width: 13%;">Registry #</th>
                <th style="width: 25%;">Name</th>
                <th style="width: 30%;">Department(s) & Duties</th>
            </tr>
        </thead>
        <tbody>';

if (empty($processedTransactions)) {
    $html .= '<tr><td colspan="6" class="text-center" style="padding: 20px;">No transactions found for the selected period</td></tr>';
} else {
    foreach ($processedTransactions as $t) {
        $fullName = htmlspecialchars($t['last_name'] . ', ' . $t['first_name']);
        if (!empty($t['middle_initial'])) {
            $fullName .= ' ' . htmlspecialchars($t['middle_initial']) . '.';
        }
        
        $controlNumber = !empty($t['control_number']) ? htmlspecialchars($t['control_number']) : '-';
        $registryNumber = !empty($t['registry_number']) ? htmlspecialchars($t['registry_number']) : '-';
        $departments = !empty($t['departments']) ? htmlspecialchars($t['departments']) : '-';
        $district = htmlspecialchars($t['district_name'] ?? '-');
        $local = htmlspecialchars($t['local_name'] ?? '-');
        
        $date = !empty($t['transaction_date']) ? date('Y-m-d', strtotime($t['transaction_date'])) : '-';
        $type = htmlspecialchars($t['transaction_type']);
        
        $html .= "<tr>
            <td class=\"text-center\">{$date}</td>
            <td class=\"font-bold\">{$type}</td>
            <td class=\"text-center\">{$controlNumber}</td>
            <td class=\"text-center\" style=\"white-space: nowrap;\">{$registryNumber}</td>
            <td style=\"white-space: nowrap;\">{$fullName}</td>
            <td>{$departments}</td>
        </tr>";
    }
}

$html .= '</tbody>
    </table>
</body>
</html>';

// Configure Dompdf
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'Courier');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Output PDF to browser (auto-open, not download)
$dompdf->stream('R5_Transactions_Logsheet_' . date('Y-m-d') . '.pdf', ['Attachment' => false]);
