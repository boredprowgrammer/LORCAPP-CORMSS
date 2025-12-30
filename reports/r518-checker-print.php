<?php
/**
 * R5-18 Checker Print View
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
$filterDepartment = Security::sanitizeInput($_GET['department'] ?? '');
$filterRequirement = Security::sanitizeInput($_GET['requirement'] ?? '');

// Get officers (same query as main page)
$officers = [];
$statistics = [
    'total' => 0,
    'verified' => 0,
    'complete' => 0,
    'incomplete' => 0,
    'pending' => 0
];

try {
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
    }
    
    if (!empty($filterLocal)) {
        $whereConditions[] = 'o.local_code = ?';
        $params[] = $filterLocal;
    }
    
    if (!empty($filterDepartment)) {
        $whereConditions[] = 'EXISTS (SELECT 1 FROM officer_departments od2 WHERE od2.officer_id = o.officer_id AND od2.department = ? AND od2.is_active = 1)';
        $params[] = $filterDepartment;
    }
    
    if ($filterRequirement === 'missing_r518') {
        $whereConditions[] = '(o.r518_submitted IS NULL OR o.r518_submitted = 0)';
    } elseif ($filterRequirement === 'missing_picture') {
        $whereConditions[] = '(o.r518_picture_attached IS NULL OR o.r518_picture_attached = 0)';
    } elseif ($filterRequirement === 'missing_signatories') {
        $whereConditions[] = '(o.r518_signatories_complete IS NULL OR o.r518_signatories_complete = 0)';
    } elseif ($filterRequirement === 'missing_verify') {
        $whereConditions[] = '(o.r518_data_verify IS NULL OR o.r518_data_verify = 0)';
    }
    
    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    $stmt = $db->prepare("
        SELECT 
            o.officer_id,
            o.officer_uuid,
            o.first_name_encrypted,
            o.last_name_encrypted,
            o.middle_initial_encrypted,
            o.district_code,
            o.control_number_encrypted,
            o.registry_number_encrypted,
            o.r518_submitted,
            o.r518_picture_attached,
            o.r518_signatories_complete,
            o.r518_data_verify,
            o.r518_completion_status,
            d.district_name,
            lc.local_name
        FROM officers o
        LEFT JOIN districts d ON o.district_code = d.district_code
        LEFT JOIN local_congregations lc ON o.local_code = lc.local_code
        $whereClause
        ORDER BY o.r518_completion_status DESC, d.district_name, lc.local_name, o.last_name_encrypted
    ");
    
    $stmt->execute($params);
    $results = $stmt->fetchAll();
    
    $rowNumber = 1;
    foreach ($results as $officer) {
        $r518Status = $officer['r518_completion_status'];
        
        $controlNumber = null;
        if (!empty($officer['control_number_encrypted'])) {
            try {
                $controlNumber = Encryption::decrypt($officer['control_number_encrypted'], $officer['district_code']);
            } catch (Exception $e) {}
        }
        
        $registryNumber = null;
        if (!empty($officer['registry_number_encrypted'])) {
            try {
                $registryNumber = Encryption::decrypt($officer['registry_number_encrypted'], $officer['district_code']);
            } catch (Exception $e) {}
        }
        
        $statistics['total']++;
        if ($r518Status === 'verified') $statistics['verified']++;
        elseif ($r518Status === 'complete') $statistics['complete']++;
        elseif ($r518Status === 'incomplete') $statistics['incomplete']++;
        else $statistics['pending']++;
        
        $officers[] = [
            'row_number' => $rowNumber++,
            'full_name' => Encryption::getFullName(
                $officer['last_name_encrypted'],
                $officer['first_name_encrypted'],
                $officer['middle_initial_encrypted'],
                $officer['district_code']
            ),
            'district_name' => $officer['district_name'],
            'local_name' => $officer['local_name'],
            'control_number' => $controlNumber,
            'registry_number' => $registryNumber,
            'has_r518' => $officer['r518_submitted'] == 1,
            'has_picture' => $officer['r518_picture_attached'] == 1,
            'has_signatories' => $officer['r518_signatories_complete'] == 1,
            'has_data_verify' => $officer['r518_data_verify'] == 1,
            'r518_status' => $r518Status
        ];
    }
} catch (Exception $e) {
    error_log("Load officers error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>R5-18 Checker Report - <?php echo date('F d, Y'); ?></title>
    <link rel="stylesheet" href="https://site-assets.fontawesome.com/releases/v6.5.1/css/all.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding: 30px;
            background: white;
            color: #000;
        }
        .column-selector {
            background: #f0f9ff;
            border: 2px solid #2563eb;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 25px;
        }
        .column-selector h3 {
            font-size: 16px;
            color: #1e40af;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .column-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 10px;
        }
        .column-option {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }
        .column-option input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        .column-option label {
            cursor: pointer;
            font-size: 14px;
            user-select: none;
        }
        .print-button {
            background: #2563eb;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 15px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .print-button:hover {
            background: #1e40af;
        }
        .search-container {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #bfdbfe;
        }
        .search-input {
            width: 100%;
            padding: 10px 40px 10px 12px;
            border: 2px solid #93c5fd;
            border-radius: 6px;
            font-size: 14px;
            transition: all 0.2s;
        }
        .search-input:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        .search-wrapper {
            position: relative;
        }
        .search-icon {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #6b7280;
            pointer-events: none;
        }
        .search-results {
            margin-top: 8px;
            font-size: 13px;
            color: #6b7280;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 3px solid #2563eb;
            padding-bottom: 15px;
        }
        .header h1 {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 8px;
            color: #1e40af;
        }
        .header p {
            font-size: 14px;
            color: #4b5563;
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-bottom: 25px;
        }
        .info-card {
            background: #f3f4f6;
            padding: 12px;
            border-radius: 8px;
            border-left: 4px solid #2563eb;
        }
        .info-card .label {
            font-size: 11px;
            color: #6b7280;
            text-transform: uppercase;
            font-weight: 600;
            margin-bottom: 4px;
        }
        .info-card .value {
            font-size: 18px;
            font-weight: bold;
            color: #111827;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
            margin-top: 20px;
        }
        thead {
            background: #1e40af;
            color: white;
        }
        thead th {
            padding: 10px 8px;
            text-align: left;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 10px;
            letter-spacing: 0.5px;
        }
        tbody tr {
            border-bottom: 1px solid #e5e7eb;
        }
        tbody tr:nth-child(even) {
            background-color: #f9fafb;
        }
        tbody td {
            padding: 8px;
            color: #374151;
        }
        .text-center { text-align: center; }
        .icon-yes {
            color: #059669;
            font-size: 14px;
        }
        .icon-no {
            color: #9ca3af;
            font-size: 14px;
        }
        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .status-verified { background: #dbeafe; color: #1e40af; }
        .status-complete { background: #d1fae5; color: #065f46; }
        .status-incomplete { background: #fef3c7; color: #92400e; }
        .status-pending { background: #f3f4f6; color: #374151; }
        .footer {
            margin-top: 30px;
            padding-top: 15px;
            border-top: 2px solid #e5e7eb;
            text-align: center;
            font-size: 11px;
            color: #6b7280;
        }
        @media print {
            body { padding: 15px; }
            .header { margin-bottom: 20px; }
            table { font-size: 9px; }
            thead th { padding: 6px 4px; }
            tbody td { padding: 5px 4px; }
            .column-selector { display: none; }
        }
    </style>
</head>
<body>
    <div class="column-selector">
        <h3><i class="fa-solid fa-table-columns"></i> Select Columns to Print</h3>
        <div class="column-options">
            <div class="column-option">
                <input type="checkbox" id="col-number" checked>
                <label for="col-number">#</label>
            </div>
            <div class="column-option">
                <input type="checkbox" id="col-name" checked>
                <label for="col-name">Officer Name</label>
            </div>
            <div class="column-option">
                <input type="checkbox" id="col-local" checked>
                <label for="col-local">Local Congregation</label>
            </div>
            <div class="column-option">
                <input type="checkbox" id="col-control" checked>
                <label for="col-control">Control #</label>
            </div>
            <div class="column-option">
                <input type="checkbox" id="col-registry" checked>
                <label for="col-registry">Registry #</label>
            </div>
            <div class="column-option">
                <input type="checkbox" id="col-r518" checked>
                <label for="col-r518">R5-18</label>
            </div>
            <div class="column-option">
                <input type="checkbox" id="col-picture" checked>
                <label for="col-picture">Picture</label>
            </div>
            <div class="column-option">
                <input type="checkbox" id="col-signatories" checked>
                <label for="col-signatories">Signatories</label>
            </div>
            <div class="column-option">
                <input type="checkbox" id="col-verified" checked>
                <label for="col-verified">Verified</label>
            </div>
            <div class="column-option">
                <input type="checkbox" id="col-status" checked>
                <label for="col-status">Status</label>
            </div>
        </div>
        <div class="search-container">
            <div class="search-wrapper">
                <input type="text" id="searchInput" class="search-input" placeholder="Search by name, local, district, control #, or registry #...">
                <i class="fa-solid fa-search search-icon"></i>
            </div>
            <div id="searchResults" class="search-results"></div>
        </div>
        <button class="print-button" onclick="window.print()">
            <i class="fa-solid fa-print"></i> Print Report
        </button>
    </div>
    
    <div class="header">
        <h1>📋 R5-18 CHECKER REPORT</h1>
        <p>Verify R5-18 Form Completeness</p>
    </div>
    
    <div class="info-grid">
        <div class="info-card">
            <div class="label">Total Officers</div>
            <div class="value"><?php echo number_format($statistics['total']); ?></div>
        </div>
        <div class="info-card" style="border-left-color: #2563eb;">
            <div class="label">Verified</div>
            <div class="value" style="color: #2563eb;"><?php echo number_format($statistics['verified']); ?></div>
        </div>
        <div class="info-card" style="border-left-color: #059669;">
            <div class="label">Complete</div>
            <div class="value" style="color: #059669;"><?php echo number_format($statistics['complete']); ?></div>
        </div>
        <div class="info-card" style="border-left-color: #d97706;">
            <div class="label">Pending/Incomplete</div>
            <div class="value" style="color: #d97706;"><?php echo number_format($statistics['incomplete'] + $statistics['pending']); ?></div>
        </div>
    </div>
    
    <table>
        <thead>
            <tr>
                <th class="col-number">#</th>
                <th class="col-name">Officer Name</th>
                <th class="col-local">Local Congregation</th>
                <th class="col-control">Control #</th>
                <th class="col-registry">Registry #</th>
                <th class="text-center col-r518">R5-18</th>
                <th class="text-center col-picture">Picture</th>
                <th class="text-center col-signatories">Signatories</th>
                <th class="text-center col-verified">Verified</th>
                <th class="text-center col-status">Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($officers as $officer): ?>
            <tr>
                <td class="col-number"><?php echo $officer['row_number']; ?></td>
                <td class="col-name"><?php echo Security::escape($officer['full_name']); ?></td>
                <td class="col-local">
                    <?php echo Security::escape($officer['local_name']); ?><br>
                    <small style="color: #6b7280;"><?php echo Security::escape($officer['district_name']); ?></small>
                </td>
                <td class="col-control"><?php echo $officer['control_number'] ? Security::escape($officer['control_number']) : '—'; ?></td>
                <td class="col-registry"><?php echo $officer['registry_number'] ? Security::escape($officer['registry_number']) : '—'; ?></td>
                <td class="text-center col-r518">
                    <i class="<?php echo $officer['has_r518'] ? 'fa-solid icon-yes' : 'fa-regular icon-no'; ?> fa-thumbs-up"></i>
                </td>
                <td class="text-center col-picture">
                    <i class="<?php echo $officer['has_picture'] ? 'fa-solid icon-yes' : 'fa-regular icon-no'; ?> fa-thumbs-up"></i>
                </td>
                <td class="text-center col-signatories">
                    <i class="<?php echo $officer['has_signatories'] ? 'fa-solid icon-yes' : 'fa-regular icon-no'; ?> fa-thumbs-up"></i>
                </td>
                <td class="text-center col-verified">
                    <i class="<?php echo $officer['has_data_verify'] ? 'fa-solid icon-yes' : 'fa-regular icon-no'; ?> fa-thumbs-up"></i>
                </td>
                <td class="text-center col-status">
                    <?php
                    $statusClasses = [
                        'verified' => 'status-verified',
                        'complete' => 'status-complete',
                        'incomplete' => 'status-incomplete',
                        'pending' => 'status-pending'
                    ];
                    $statusLabels = [
                        'verified' => '✓ Verified',
                        'complete' => '✓ Complete',
                        'incomplete' => '⚠ Incomplete',
                        'pending' => '○ Pending'
                    ];
                    $statusClass = $statusClasses[$officer['r518_status']] ?? 'status-pending';
                    $statusLabel = $statusLabels[$officer['r518_status']] ?? 'Pending';
                    ?>
                    <span class="status-badge <?php echo $statusClass; ?>"><?php echo $statusLabel; ?></span>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <div class="footer">
        <p><strong>Generated:</strong> <?php echo date('F d, Y'); ?> | 
        <strong>Generated By:</strong> <?php echo Security::escape($currentUser['username'] ?? $currentUser['email'] ?? 'Unknown'); ?> | 
        <strong>Total Records:</strong> <?php echo number_format(count($officers)); ?></p>
    </div>
    
    <script>
        // Column visibility toggle
        document.querySelectorAll('.column-option input[type="checkbox"]').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const columnClass = this.id; // e.g., 'col-number'
                const elements = document.querySelectorAll('.' + columnClass);
                
                elements.forEach(element => {
                    if (this.checked) {
                        element.style.display = '';
                    } else {
                        element.style.display = 'none';
                    }
                });
            });
        });
        
        // Instant search functionality
        const searchInput = document.getElementById('searchInput');
        const searchResults = document.getElementById('searchResults');
        const tableRows = document.querySelectorAll('tbody tr');
        const totalRecords = tableRows.length;
        
        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase().trim();
            let visibleCount = 0;
            
            tableRows.forEach(row => {
                const text = row.textContent.toLowerCase();
                
                if (searchTerm === '' || text.includes(searchTerm)) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            // Update search results message
            if (searchTerm === '') {
                searchResults.textContent = '';
            } else {
                searchResults.textContent = `Showing ${visibleCount} of ${totalRecords} records`;
            }
        });
        
        // Clear search on print
        window.addEventListener('beforeprint', function() {
            if (searchInput.value) {
                const confirmClear = confirm('Clear search filter before printing to show all records?');
                if (confirmClear) {
                    searchInput.value = '';
                    searchInput.dispatchEvent(new Event('input'));
                }
            }
        });
    </script>
</body>
</html>
