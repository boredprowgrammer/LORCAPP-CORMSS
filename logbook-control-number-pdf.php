<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

Security::requireLogin();
requirePermission('can_view_officers');

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

// Build WHERE clause based on user role
$whereConditions = [];
$params = [];

if ($currentUser['role'] === 'local') {
    $whereConditions[] = 'o.local_code = ?';
    $params[] = $currentUser['local_code'];
} elseif ($currentUser['role'] === 'district') {
    $whereConditions[] = 'o.district_code = ?';
    $params[] = $currentUser['district_code'];
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Get officers with control numbers
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
            o.purok,
            o.grupo,
            o.control_number,
            o.registry_number_encrypted,
            o.is_active,
            d.district_name,
            lc.local_name,
            GROUP_CONCAT(DISTINCT od.department SEPARATOR ', ') as departments
        FROM officers o
        LEFT JOIN districts d ON o.district_code = d.district_code
        LEFT JOIN local_congregations lc ON o.local_code = lc.local_code
        LEFT JOIN officer_departments od ON o.officer_id = od.officer_id AND od.is_active = 1
        $whereClause
        GROUP BY o.officer_id
        ORDER BY 
            CASE 
                WHEN o.control_number IS NULL OR o.control_number = '' THEN 1
                ELSE 0
            END,
            CAST(o.control_number AS UNSIGNED) ASC,
            o.control_number ASC
    ");
    
    $stmt->execute($params);
    $allOfficers = $stmt->fetchAll();
    
    // Decrypt names and prepare data
    $officers = [];
    $missingControlNumbers = [];
    
    foreach ($allOfficers as $officer) {
        $decrypted = Encryption::decryptOfficerName(
            $officer['last_name_encrypted'],
            $officer['first_name_encrypted'],
            $officer['middle_initial_encrypted'],
            $officer['district_code']
        );
        
        $officer['last_name'] = $decrypted['last_name'];
        $officer['first_name'] = $decrypted['first_name'];
        $officer['middle_initial'] = $decrypted['middle_initial'];
        
        // Decrypt registry number if available
        if (!empty($officer['registry_number_encrypted'])) {
            try {
                $officer['registry_number'] = Encryption::decrypt(
                    $officer['registry_number_encrypted'],
                    $officer['district_code']
                );
            } catch (Exception $e) {
                $officer['registry_number'] = '';
            }
        } else {
            $officer['registry_number'] = '';
        }
        
        $officers[] = $officer;
        
        if (empty($officer['control_number'])) {
            $missingControlNumbers[] = $officer;
        }
    }
    
} catch (Exception $e) {
    error_log("Logbook control number PDF error: " . $e->getMessage());
    die("Error generating PDF");
}

// Generate HTML for PDF
$html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        @page {
            margin: 0.8cm;
            size: portrait;
        }
        
        body {
            font-family: Arial, sans-serif;
            font-size: 8pt;
            line-height: 1.2;
            color: #000;
        }
        
        .header {
            text-align: center;
            margin-bottom: 10px;
            padding-bottom: 8px;
            border-bottom: 2px solid #333;
        }
        
        .header h1 {
            margin: 0;
            font-size: 14pt;
            font-weight: bold;
            color: #1e40af;
        }
        
        .header p {
            margin: 3px 0;
            font-size: 8pt;
            color: #666;
        }
        
        .stats {
            display: table;
            width: 100%;
            margin-bottom: 8px;
            border: 1px solid #ddd;
            background: #f9f9f9;
        }
        
        .stats-row {
            display: table-row;
        }
        
        .stats-cell {
            display: table-cell;
            padding: 5px;
            text-align: center;
            border-right: 1px solid #ddd;
            width: 33.33%;
        }
        
        .stats-cell:last-child {
            border-right: none;
        }
        
        .stats-label {
            font-size: 7pt;
            color: #666;
            font-weight: normal;
            display: block;
            margin-bottom: 2px;
        }
        
        .stats-value {
            font-size: 11pt;
            font-weight: bold;
            color: #333;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 5px;
        }
        
        th {
            background-color: #2563eb;
            color: white;
            font-weight: bold;
            padding: 5px 4px;
            text-align: center;
            font-size: 8pt;
            border: 1px solid #1e40af;
        }
        
        td {
            padding: 4px;
            text-align: center;
            border: 1px solid #ddd;
            font-size: 8pt;
        }
        
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        
        .control-num {
            font-weight: bold;
            font-family: "Courier New", monospace;
            background: #eff6ff;
            padding: 2px 6px;
            border-radius: 3px;
        }
        
        .control-num-missing {
            color: #dc2626;
            font-weight: bold;
            font-size: 7pt;
        }
        
        .status-active {
            color: #059669;
            font-weight: bold;
        }
        
        .status-inactive {
            color: #dc2626;
            font-weight: bold;
        }
        
        .footer {
            position: fixed;
            bottom: 0;
            width: 100%;
            text-align: center;
            font-size: 7pt;
            color: #999;
            padding-top: 10px;
            border-top: 1px solid #ddd;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>LOGBOOK CONTROL NUMBER REGISTRY</h1>
        <p><strong>Church Officers Registry and Management System</strong></p>
        <p>Generated on: ' . date('F d, Y g:i A') . '</p>
    </div>
    
    <div class="stats">
        <div class="stats-row">
            <div class="stats-cell">
                <span class="stats-label">Total Officers</span>
                <span class="stats-value">' . number_format(count($officers)) . '</span>
            </div>
            <div class="stats-cell">
                <span class="stats-label">With Control Number</span>
                <span class="stats-value" style="color: #059669;">' . number_format(count($officers) - count($missingControlNumbers)) . '</span>
            </div>
            <div class="stats-cell">
                <span class="stats-label">Missing Control Number</span>
                <span class="stats-value" style="color: #dc2626;">' . number_format(count($missingControlNumbers)) . '</span>
            </div>
        </div>
    </div>
    
    <table>
        <thead>
            <tr>
                <th style="width: 25%;">Control #</th>
                <th style="width: 75%;">Officer Name</th>
            </tr>
        </thead>
        <tbody>';

foreach ($officers as $officer) {
    $fullName = htmlspecialchars($officer['last_name'] . ', ' . $officer['first_name']);
    if (!empty($officer['middle_initial'])) {
        $fullName .= ' ' . htmlspecialchars($officer['middle_initial']) . '.';
    }
    
    $controlNumber = !empty($officer['control_number']) 
        ? '<span class="control-num">' . htmlspecialchars($officer['control_number']) . '</span>'
        : '<span class="control-num-missing">NOT SET</span>';
    
    $registryNumber = !empty($officer['registry_number']) 
        ? htmlspecialchars($officer['registry_number']) 
        : '-';
    
    $location = htmlspecialchars($officer['local_name']) . '<br><small style="color: #666;">' . htmlspecialchars($officer['district_name']) . '</small>';
    
    $purokGrupo = 'P' . htmlspecialchars($officer['purok'] ?: '-') . ' / G' . htmlspecialchars($officer['grupo'] ?: '-');
    
    $departments = !empty($officer['departments']) 
        ? htmlspecialchars($officer['departments'])
        : '<em style="color: #999;">No departments</em>';
    
    $status = $officer['is_active'] 
        ? '<span class="status-active">ACTIVE</span>'
        : '<span class="status-inactive">INACTIVE</span>';
    
    $html .= '<tr>
        <td>' . $controlNumber . '</td>
        <td>' . $fullName . '</td>
    </tr>';
}

$html .= '</tbody>
    </table>
    
    <div class="footer">
        <p>Confidential Document - Church Officers Registry and Management System | Generated by: ' . htmlspecialchars($currentUser['username']) . '</p>
    </div>
</body>
</html>';

// Configure Dompdf
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'Arial');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();

// Output PDF to browser (inline display)
$dompdf->stream('Logbook_Control_Number_' . date('Y-m-d') . '.pdf', ['Attachment' => false]);
