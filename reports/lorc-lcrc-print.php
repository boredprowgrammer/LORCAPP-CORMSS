<?php
/**
 * LORC/LCRC Checker Report - Print View
 * Printer-optimized version without navigation
 */

require_once __DIR__ . '/../config/config.php';

Security::requireLogin();
requirePermission('can_view_reports');

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

// Get filter parameters from URL
$filterDistrict = Security::sanitizeInput($_GET['district'] ?? '');
$filterLocal = Security::sanitizeInput($_GET['local'] ?? '');
$filterStatus = Security::sanitizeInput($_GET['status'] ?? 'active');
$filterIssue = Security::sanitizeInput($_GET['issue'] ?? '');
$filterDepartment = Security::sanitizeInput($_GET['department'] ?? '');

// Customizable field display options
// Checkboxes only send value if checked, so we use isset() to check presence
$showControlNumber = isset($_GET['show_control']);
$showRegistryNumber = isset($_GET['show_registry']);
$showOathDate = isset($_GET['show_oath']);
$showDepartments = isset($_GET['show_departments']);

// If no display options are set at all, show all columns (default behavior)
if (!isset($_GET['show_control']) && !isset($_GET['show_registry']) && 
    !isset($_GET['show_oath']) && !isset($_GET['show_departments'])) {
    $showControlNumber = true;
    $showRegistryNumber = true;
    $showOathDate = true;
    $showDepartments = true;
}

// Get officers for report
$officers = [];
$reportInfo = [
    'district_name' => 'All Districts',
    'local_name' => 'All Locals',
    'generated_date' => date('F d, Y'),
    'generated_time' => date('h:i A'),
    'filter_status' => ucfirst($filterStatus),
    'filter_issue' => ''
];

$statistics = [
    'total' => 0,
    'complete' => 0,
    'missing_control' => 0,
    'missing_registry' => 0,
    'missing_both' => 0
];

try {
    // Build WHERE clause
    $whereConditions = [];
    $params = [];
    
    if ($filterStatus === 'active') {
        $whereConditions[] = 'o.is_active = 1';
    } elseif ($filterStatus === 'inactive') {
        $whereConditions[] = 'o.is_active = 0';
    }
    
    if ($currentUser['role'] === 'district') {
        $whereConditions[] = 'o.district_code = ?';
        $params[] = $currentUser['district_code'];
    } elseif ($currentUser['role'] === 'local') {
        $whereConditions[] = 'o.local_code = ?';
        $params[] = $currentUser['local_code'];
    }
    
    if (!empty($filterDistrict)) {
        $whereConditions[] = 'o.district_code = ?';
        $params[] = $filterDistrict;
        
        // Get district name
        $stmt = $db->prepare("SELECT district_name FROM districts WHERE district_code = ?");
        $stmt->execute([$filterDistrict]);
        $district = $stmt->fetch();
        if ($district) {
            $reportInfo['district_name'] = $district['district_name'];
        }
    }
    
    if (!empty($filterLocal)) {
        $whereConditions[] = 'o.local_code = ?';
        $params[] = $filterLocal;
        
        // Get local name
        $stmt = $db->prepare("SELECT local_name FROM local_congregations WHERE local_code = ?");
        $stmt->execute([$filterLocal]);
        $local = $stmt->fetch();
        if ($local) {
            $reportInfo['local_name'] = $local['local_name'];
        }
    }
    
    if (!empty($filterDepartment)) {
        $whereConditions[] = 'od.department = ?';
        $params[] = $filterDepartment;
    }
    
    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    // Get officers with registry information
    $stmt = $db->prepare("
        SELECT 
            o.*,
            d.district_name,
            lc.local_name,
            tc.registry_number_encrypted,
            GROUP_CONCAT(
                DISTINCT CONCAT(
                    od.department, 
                    IF(od.duty IS NOT NULL AND od.duty != '', CONCAT(' - ', od.duty), '')
                ) 
                ORDER BY od.department 
                SEPARATOR ', '
            ) as departments,
            GROUP_CONCAT(
                DISTINCT DATE_FORMAT(od.oath_date, '%m/%d/%Y')
                ORDER BY od.department
                SEPARATOR ', '
            ) as oath_dates
        FROM officers o
        LEFT JOIN districts d ON o.district_code = d.district_code
        LEFT JOIN local_congregations lc ON o.local_code = lc.local_code
        LEFT JOIN officer_departments od ON o.officer_id = od.officer_id AND od.is_active = 1
        LEFT JOIN tarheta_control tc ON o.tarheta_control_id = tc.id
        $whereClause
        GROUP BY o.officer_id
        ORDER BY lc.local_name, o.officer_id
    ");
    
    $stmt->execute($params);
    $allOfficers = $stmt->fetchAll();
    
    // Decrypt and analyze
    $rowNumber = 1;
    foreach ($allOfficers as $officer) {
        $decrypted = Encryption::decryptOfficerName(
            $officer['last_name_encrypted'],
            $officer['first_name_encrypted'],
            $officer['middle_initial_encrypted'],
            $officer['district_code']
        );
        
        // Decrypt registry number if available
        $registryNumber = null;
        if (!empty($officer['registry_number_encrypted'])) {
            try {
                $registryNumber = Encryption::decrypt($officer['registry_number_encrypted'], $officer['district_code']);
            } catch (Exception $e) {
                error_log("Failed to decrypt registry number for officer {$officer['officer_id']}: " . $e->getMessage());
            }
        }
        
        // Decrypt control number
        $controlNumber = null;
        if (!empty($officer['control_number_encrypted'])) {
            try {
                $controlNumber = Encryption::decrypt($officer['control_number_encrypted'], $officer['district_code']);
            } catch (Exception $e) {
                error_log("Failed to decrypt control number for officer {$officer['officer_id']}: " . $e->getMessage());
            }
        }
        
        // Check purok and grupo assignments
        $hasPurok = !empty($officer['purok']);
        $hasGrupo = !empty($officer['grupo']);
        
        // Determine issue status
        $hasControl = !empty($controlNumber);
        $hasRegistry = !empty($registryNumber);
        
        $issueType = null;
        if (!$hasControl && !$hasRegistry) {
            $issueType = 'missing_both';
            $statistics['missing_both']++;
        } elseif (!$hasControl) {
            $issueType = 'missing_control';
            $statistics['missing_control']++;
        } elseif (!$hasRegistry) {
            $issueType = 'missing_registry';
            $statistics['missing_registry']++;
        } else {
            $issueType = 'complete';
            $statistics['complete']++;
        }
        
        // Check for purok/grupo assignment issues
        if (!$hasPurok && !$hasGrupo) {
            $issueType = 'no_purok_grupo';
        } elseif (!$hasPurok) {
            $issueType = 'no_purok';
        } elseif (!$hasGrupo) {
            $issueType = 'no_grupo';
        }
        
        $statistics['total']++;
        
        // Apply issue filter
        if (!empty($filterIssue) && $filterIssue !== $issueType) {
            continue;
        }
        
        $officers[] = [
            'row_number' => $rowNumber++,
            'officer_id' => $officer['officer_id'],
            'officer_uuid' => $officer['officer_uuid'],
            'full_name' => trim($decrypted['last_name'] . ', ' . $decrypted['first_name'] . 
                              (!empty($decrypted['middle_initial']) ? ' ' . $decrypted['middle_initial'] . '.' : '')),
            'control_number' => $controlNumber,
            'registry_number' => $registryNumber,
            'oath_dates' => $officer['oath_dates'] ?? null,
            'departments' => $officer['departments'],
            'district_name' => $officer['district_name'],
            'local_name' => $officer['local_name'],
            'purok' => $officer['purok'] ?? null,
            'grupo' => $officer['grupo'] ?? null,
            'issue_type' => $issueType,
            'has_control' => $hasControl,
            'has_registry' => $hasRegistry,
            'has_purok' => $hasPurok,
            'has_grupo' => $hasGrupo
        ];
    }
    
    // Set filter issue description
    switch ($filterIssue) {
        case 'complete':
            $reportInfo['filter_issue'] = 'Complete Records Only';
            break;
        case 'missing_control':
            $reportInfo['filter_issue'] = 'Missing Control Number';
            break;
        case 'missing_registry':
            $reportInfo['filter_issue'] = 'Missing Registry Number';
            break;
        case 'missing_both':
            $reportInfo['filter_issue'] = 'Missing Both Numbers';
            break;
        case 'no_purok':
            $reportInfo['filter_issue'] = 'No Purok Assignment';
            break;
        case 'no_grupo':
            $reportInfo['filter_issue'] = 'No Grupo Assignment';
            break;
        case 'no_purok_grupo':
            $reportInfo['filter_issue'] = 'No Purok & Grupo';
            break;
        default:
            $reportInfo['filter_issue'] = 'All Records';
    }
    
} catch (Exception $e) {
    error_log("Load LORC/LCRC checker error: " . $e->getMessage());
    die('Error loading report data.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LORC/LCRC Checker Report - <?php echo Security::escape($reportInfo['district_name']); ?></title>
    <style>
        @page {
            /* Print in portrait orientation */
            size: auto portrait;
            margin: 0.5in;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 10pt;
            color: #000;
            background: #fff;
            padding: 10px;
        }
        
        /* Hide all decorative elements for plain print */
        .report-header,
        .report-info,
        .statistics-summary,
        .report-footer {
            display: none !important;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }
        
        thead {
            background-color: #000;
            color: white;
        }
        
        thead th {
            padding: 6px 4px;
            text-align: left;
            font-size: 8pt;
            font-weight: bold;
            border: 1px solid #000;
            text-transform: uppercase;
        }
        
        tbody tr {
            page-break-inside: avoid;
        }
        
        tbody td {
            padding: 4px 3px;
            border: 1px solid #ccc;
            font-size: 8pt;
            vertical-align: top;
        }
        
        tbody tr:nth-child(even) {
            background-color: #f9fafb;
        }
        
        /* Status-based row coloring */
        tbody tr.complete {
            background-color: #f0fdf4 !important;
        }
        
        tbody tr.missing-control {
            background-color: #fefce8 !important;
        }
        
        tbody tr.missing-registry {
            background-color: #fff7ed !important;
        }
        
        tbody tr.missing-both {
            background-color: #fef2f2 !important;
        }
        
        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 8pt;
            font-weight: bold;
            text-align: center;
            white-space: nowrap;
        }
        
        .status-badge.complete {
            background-color: #dcfce7;
            color: #166534;
        }
        
        .status-badge.missing-control {
            background-color: #fef3c7;
            color: #854d0e;
        }
        
        .status-badge.missing-registry {
            background-color: #ffedd5;
            color: #9a3412;
        }
        
        .status-badge.missing-both {
            background-color: #fee2e2;
            color: #991b1b;
        }
        
        .text-missing {
            color: #dc2626;
            font-weight: bold;
        }
        
        .no-print {
            margin: 20px;
            text-align: center;
        }
        
        .no-print button {
            padding: 12px 24px;
            background-color: #2563eb;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 12pt;
            cursor: pointer;
            margin: 0 5px;
        }
        
        .no-print button:hover {
            background-color: #1d4ed8;
        }
        
        .no-print button.secondary {
            background-color: #6b7280;
        }
        
        .no-print button.secondary:hover {
            background-color: #4b5563;
        }
        
        @media print {
            .no-print {
                display: none !important;
            }
            
            body {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            
            thead {
                display: table-header-group;
            }
            
            tbody tr {
                page-break-inside: avoid;
                page-break-after: auto;
            }
        }
        
        @media screen {
            body {
                padding: 20px;
                background: #e5e7eb;
            }
            
            .container {
                max-width: 1200px;
                margin: 0 auto;
                background: white;
                padding: 20px;
                box-shadow: 0 0 10px rgba(0,0,0,0.1);
            }
            
            /* Show elements on screen for preview */
            .report-header,
            .report-info,
            .statistics-summary,
            .report-footer {
                display: block !important;
            }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button onclick="window.print()">🖨️ Print Report</button>
        <button onclick="window.close()" class="secondary">✕ Close</button>
    </div>
    
    <div class="container">
        <!-- Report Header -->
        <div class="report-header">
            <h1>LORC/LCRC Checker Report</h1>
            <h2>Church Officers Registry Verification</h2>
        </div>
        
        <!-- Report Info -->
        <div class="report-info">
            <div class="report-info-left">
                <p><strong>District:</strong> <?php echo Security::escape($reportInfo['district_name']); ?></p>
                <p><strong>Local Congregation:</strong> <?php echo Security::escape($reportInfo['local_name']); ?></p>
                <p><strong>Status Filter:</strong> <?php echo Security::escape($reportInfo['filter_status']); ?></p>
                <p><strong>Issue Filter:</strong> <?php echo Security::escape($reportInfo['filter_issue']); ?></p>
            </div>
            <div class="report-info-right">
                <p><strong>Generated:</strong> <?php echo Security::escape($reportInfo['generated_date']); ?></p>
                <p><strong>Time:</strong> <?php echo Security::escape($reportInfo['generated_time']); ?></p>
                <p><strong>Total Records:</strong> <?php echo number_format(count($officers)); ?></p>
                <p><strong>Generated By:</strong> <?php echo Security::escape($currentUser['username']); ?></p>
            </div>
        </div>
        
        <!-- Statistics Summary -->
        <div class="statistics-summary">
            <div class="stat-item">
                <div class="stat-label">Total Officers</div>
                <div class="stat-value"><?php echo number_format($statistics['total']); ?></div>
            </div>
            <div class="stat-item">
                <div class="stat-label">Complete</div>
                <div class="stat-value complete"><?php echo number_format($statistics['complete']); ?></div>
            </div>
            <div class="stat-item">
                <div class="stat-label">No Control #</div>
                <div class="stat-value warning"><?php echo number_format($statistics['missing_control']); ?></div>
            </div>
            <div class="stat-item">
                <div class="stat-label">No Registry #</div>
                <div class="stat-value warning"><?php echo number_format($statistics['missing_registry']); ?></div>
            </div>
            <div class="stat-item">
                <div class="stat-label">Missing Both</div>
                <div class="stat-value danger"><?php echo number_format($statistics['missing_both']); ?></div>
            </div>
        </div>
        
        <!-- Report Table -->
        <?php if (!empty($officers)): ?>
        <table>
            <thead>
                <tr>
                    <th style="width: 40px;">#</th>
                    <th style="width: 200px;">Officer Name</th>
                    <?php if ($showControlNumber): ?>
                    <th style="width: 120px;">Control Number</th>
                    <?php endif; ?>
                    <?php if ($showRegistryNumber): ?>
                    <th style="width: 120px;">Registry Number</th>
                    <?php endif; ?>
                    <?php if ($showOathDate): ?>
                    <th style="width: 100px;">Oath Date</th>
                    <?php endif; ?>
                    <?php if ($showDepartments): ?>
                    <th>Tungkulin (Department)</th>
                    <?php endif; ?>
                    <th style="width: 120px; text-align: center;">LORC/LCRC Check</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($officers as $officer): 
                    $rowClass = '';
                    $statusBadgeClass = '';
                    $statusText = '';
                    
                    switch ($officer['issue_type']) {
                        case 'complete':
                            $rowClass = 'complete';
                            $statusBadgeClass = 'complete';
                            $statusText = '✓ Complete';
                            break;
                        case 'missing_control':
                            $rowClass = 'missing-control';
                            $statusBadgeClass = 'missing-control';
                            $statusText = '⚠ No Control #';
                            break;
                        case 'missing_registry':
                            $rowClass = 'missing-registry';
                            $statusBadgeClass = 'missing-registry';
                            $statusText = '⚠ No Registry #';
                            break;
                        case 'missing_both':
                            $rowClass = 'missing-both';
                            $statusBadgeClass = 'missing-both';
                            $statusText = '✗ Missing Both';
                            break;
                        case 'no_purok':
                            $rowClass = 'missing-control';
                            $statusBadgeClass = 'missing-control';
                            $statusText = '⚠ No Purok';
                            break;
                        case 'no_grupo':
                            $rowClass = 'missing-registry';
                            $statusBadgeClass = 'missing-registry';
                            $statusText = '⚠ No Grupo';
                            break;
                        case 'no_purok_grupo':
                            $rowClass = 'missing-both';
                            $statusBadgeClass = 'missing-both';
                            $statusText = '✗ No Purok & Grupo';
                            break;
                    }
                ?>
                <tr class="<?php echo $rowClass; ?>">
                    <td><?php echo $officer['row_number']; ?></td>
                    <td style="font-weight: bold;"><?php echo Security::escape($officer['full_name']); ?></td>
                    <?php if ($showControlNumber): ?>
                    <td class="<?php echo !$officer['has_control'] ? 'text-missing' : ''; ?>">
                        <?php echo $officer['control_number'] ? Security::escape($officer['control_number']) : '—'; ?>
                    </td>
                    <?php endif; ?>
                    <?php if ($showRegistryNumber): ?>
                    <td class="<?php echo !$officer['has_registry'] ? 'text-missing' : ''; ?>">
                        <?php echo $officer['registry_number'] ? Security::escape($officer['registry_number']) : '—'; ?>
                    </td>
                    <?php endif; ?>
                    <?php if ($showOathDate): ?>
                    <td><?php echo $officer['oath_dates'] ? Security::escape($officer['oath_dates']) : '—'; ?></td>
                    <?php endif; ?>
                    <?php if ($showDepartments): ?>
                    <td><?php echo $officer['departments'] ? Security::escape($officer['departments']) : '—'; ?></td>
                    <?php endif; ?>
                    <td style="text-align: center;">
                        <span class="status-badge <?php echo $statusBadgeClass; ?>">
                            <?php echo $statusText; ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p style="text-align: center; padding: 40px; color: #666;">No records found matching the selected filters.</p>
        <?php endif; ?>
        
        <!-- Report Footer -->
        <div class="report-footer">
            <p>This is a computer-generated report from CORegistry and CORTracker System</p>
            <p>Generated on <?php echo date('F d, Y \a\t h:i A'); ?> by <?php echo Security::escape($currentUser['username']); ?></p>
        </div>
    </div>
    
    <script>
        // Auto-print on load if requested
        if (window.location.search.includes('autoprint=1')) {
            window.onload = function() {
                window.print();
            };
        }
    </script>
</body>
</html>
