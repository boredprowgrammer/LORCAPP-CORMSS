<?php
/**
 * Print R2-03 Form - TALAAN NG KAANIB NG SAMBAHAYAN SA GRUPO
 * Family registry for a specific Grupo/Purok
 */

require_once __DIR__ . '/../config/config.php';

Security::requireLogin();

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

// Check access
if (!in_array($currentUser['role'], ['admin', 'district', 'local', 'local_cfo'])) {
    header('Location: ' . BASE_URL . '/launchpad.php');
    exit;
}

// Get filter parameters
$purok = $_GET['purok'] ?? '';
$grupo = $_GET['grupo'] ?? '';
$localCode = $_GET['local'] ?? '';
$districtCode = $_GET['district'] ?? '';
$familyId = $_GET['family_id'] ?? '';

// Build query to get families
$where = ["f.deleted_at IS NULL", "f.status = 'active'"];
$params = [];

// If family_id is specified, filter by that specific family
if ($familyId) {
    $where[] = "f.id = ?";
    $params[] = $familyId;
}

if ($purok) {
    $where[] = "f.purok = ?";
    $params[] = $purok;
}
if ($grupo) {
    $where[] = "f.grupo = ?";
    $params[] = $grupo;
}
if ($localCode) {
    $where[] = "f.local_code = ?";
    $params[] = $localCode;
}
if ($districtCode) {
    $where[] = "f.district_code = ?";
    $params[] = $districtCode;
}

$whereClause = implode(' AND ', $where);

// Get families with their members
$sql = "
    SELECT f.id, f.family_code, f.purok, f.grupo, f.pangulo_id, f.district_code, f.local_code,
           lc.local_name, d.district_name
    FROM families f
    LEFT JOIN local_congregations lc ON f.local_code = lc.local_code
    LEFT JOIN districts d ON f.district_code = d.district_code
    WHERE $whereClause
    ORDER BY f.purok, f.grupo, f.family_code
";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$families = $stmt->fetchAll();

// Process each family - collect members per family
$familiesWithMembers = [];

foreach ($families as $family) {
    // Get family members
    $stmtMembers = $db->prepare("
        SELECT fm.*, 
               tc.last_name_encrypted as t_last, tc.first_name_encrypted as t_first, 
               tc.middle_name_encrypted as t_middle, tc.registry_number_encrypted as t_registry,
               tc.cfo_classification as t_cfo, tc.district_code as t_district,
               tc.birthday_encrypted as t_birthday
        FROM family_members fm
        LEFT JOIN tarheta_control tc ON fm.tarheta_id = tc.id
        WHERE fm.family_id = ? AND fm.is_active = 1
        ORDER BY 
            CASE fm.relasyon 
                WHEN 'Pangulo' THEN 1 
                WHEN 'Asawa' THEN 2 
                WHEN 'Anak' THEN 3 
                WHEN 'Apo' THEN 4
                ELSE 5 
            END,
            fm.created_at ASC
    ");
    $stmtMembers->execute([$family['id']]);
    $members = $stmtMembers->fetchAll();
    
    $familyMembers = [];
    foreach ($members as $member) {
        $name = 'Unknown';
        $registry = '';
        $baptismDate = '';
        $birthDate = '';
        
        if ($member['tarheta_id'] && $member['t_first']) {
            $first = Encryption::decrypt($member['t_first'], $member['t_district']);
            $last = Encryption::decrypt($member['t_last'], $member['t_district']);
            $middle = $member['t_middle'] ? Encryption::decrypt($member['t_middle'], $member['t_district']) : '';
            $name = trim(strtoupper("$last, $first $middle"));
            $registry = $member['t_registry'] ? Encryption::decrypt($member['t_registry'], $member['t_district']) : '';
            // Get birthday from tarheta_control
            if ($member['t_birthday']) {
                $birthDate = Encryption::decrypt($member['t_birthday'], $member['t_district']);
            }
            // baptism_date will be implemented soon
            $baptismDate = '';
        } elseif ($member['first_name_encrypted']) {
            $first = Encryption::decrypt($member['first_name_encrypted'], $family['district_code']);
            $last = Encryption::decrypt($member['last_name_encrypted'], $family['district_code']);
            $name = trim(strtoupper("$last, $first"));
            // Get birthday from family_members if available
            if (!empty($member['birthday_encrypted'])) {
                $birthDate = Encryption::decrypt($member['birthday_encrypted'], $family['district_code']);
            }
        }
        
        $familyMembers[] = [
            'name' => $name,
            'registry' => $registry,
            'relasyon' => $member['relasyon'],
            'kapisanan' => $member['kapisanan'] ?? '',
            'baptism_date' => $baptismDate,
            'birth_date' => $birthDate,
            'member_type' => $member['member_type']
        ];
    }
    
    $familiesWithMembers[] = [
        'family_code' => $family['family_code'],
        'purok' => $family['purok'],
        'grupo' => $family['grupo'],
        'local_name' => $family['local_name'],
        'members' => $familyMembers
    ];
}

// Get Katiwala, II Katiwala, Kalihim if available
$katiwala = '';
$iiKatiwala = '';
$kalihim = '';

$pageTitle = "R2-03 Talaan ng Kaanib ng Sambahayan";
?>
<!DOCTYPE html>
<html lang="fil">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        @page {
            size: A4 landscape;
            margin: 8mm 10mm;
        }
        
        body {
            font-family: Verdana, sans-serif;
            font-size: 8pt;
            padding: 8px 12px;
            background: white;
            margin: 0;
        }
        
        .no-print {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
            display: flex;
            gap: 10px;
        }
        
        .no-print button {
            padding: 10px 20px;
            font-size: 14px;
            cursor: pointer;
            border: none;
            border-radius: 5px;
            font-weight: bold;
        }
        
        .print-btn {
            background: #4CAF50;
            color: white;
        }
        
        .close-btn {
            background: #666;
            color: white;
        }
        
        .container {
            width: 100%;
        }
        
        /* Header Section */
        .header-row {
            display: flex;
            align-items: flex-end;
            margin-bottom: 8px;
        }
        
        .header-left {
            text-align: left;
        }
        
        .form-label-text {
            font-size: 9pt;
            color: black;
            display: block;
        }
        
        .form-number {
            font-family: Cambria, serif;
            font-size: 28pt;
            font-weight: bold;
            color: black;
            line-height: 1;
            margin-top: -2px;
        }
        
        .header-center {
            flex: 1;
            text-align: center;
            padding-bottom: 8px;
        }
        
        .main-title {
            font-family: Verdana, sans-serif;
            font-size: 16pt;
            font-weight: bold;
            color: black;
            letter-spacing: 0.5px;
        }
        
        /* Officer Info Section */
        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
        }
        
        .officer-table {
            border-collapse: collapse;
        }
        
        .officer-table td {
            border: 1pt solid black;
            padding: 6px 10px;
            min-height: 42px;
            height: 42px;
            font-size: 9pt;
            vertical-align: top;
        }
        
        .officer-table td.officer-cell {
            min-width: 230px;
            width: 230px;
        }
        
        .location-wrapper {
            margin-left: auto;
            border-top: 1pt solid black;
            padding-top: 0;
        }
        
        .location-table {
            border-collapse: collapse;
        }
        
        .location-table td {
            border: 1pt solid black;
            border-top: none;
            padding: 4px 12px;
            font-size: 9pt;
            font-weight: bold;
            text-align: center;
            min-width: 70px;
            vertical-align: top;
        }
        
        .location-table .label-row td {
            border-bottom: 1pt solid black;
            padding: 3px 12px;
            height: 20px;
        }
        
        .location-table .data-row td {
            border-top: none;
            height: 25px;
            font-weight: normal;
        }
        
        /* Main Table */
        .main-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 6.5pt;
            table-layout: fixed;
        }
        
        .main-table th,
        .main-table td {
            border: 0.5pt solid black;
            padding: 1px 1px;
            text-align: center;
            vertical-align: middle;
        }
        
        .main-table thead th {
            background-color: white;
            font-weight: normal;
            font-size: 6.5pt;
        }
        
        .main-table thead tr:first-child th {
            font-size: 7pt;
        }
        
        /* Specific column widths - narrower for A4 */
        .col-samb { width: 20px; font-size: 5pt !important; }
        .col-pangalan { width: 150px; text-align: left !important; padding-left: 3px !important; }
        .col-id-digit { width: 6px !important; min-width: 6px !important; max-width: 6px !important; font-size: 5pt !important; padding: 0 !important; }
        .col-cs { width: 22px; }
        .col-petsa-bautismo { width: 50px; font-size: 4.5pt !important; white-space: nowrap; overflow: hidden; }
        .col-petsa-kapanganakan { width: 60px; font-size: 4.5pt !important; white-space: nowrap; overflow: hidden; }
        .col-relasyon { width: 45px; font-size: 5pt !important; }
        .col-kap { width: 20px; }
        .col-tirahan { width: 110px; text-align: left !important; padding-left: 3px !important; }
        .col-code { width: 25px; }
        .col-pansin { width: 65px; text-align: left !important; padding-left: 3px !important; }
        
        /* Data rows */
        .main-table tbody td {
            height: 14px;
            font-size: 6.5pt;
        }
        
        .main-table tbody td.text-left {
            text-align: left !important;
            padding-left: 4px !important;
        }
        
        /* Header row styling */
        .main-table thead th {
            padding: 2px 1px;
            line-height: 1.1;
        }
        
        .header-petsa {
            font-size: 7pt !important;
        }
        
        .sub-header {
            font-size: 6pt !important;
        }
        
        /* Footer */
        .footer {
            text-align: left;
            font-size: 7pt;
            font-style: italic;
            margin-top: 8px;
            padding-left: 2px;
        }
        
        /* Page break for each family */
        .page-break {
            page-break-after: always;
            break-after: page;
        }
        
        .family-page {
            page-break-inside: avoid;
        }
        
        @media print {
            .no-print { display: none !important; }
            body {
                padding: 5px 10px;
            }
            .main-table tbody td {
                height: 12px;
            }
            .page-break {
                page-break-after: always;
                break-after: page;
            }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button class="print-btn" onclick="window.print()">🖨️ Print</button>
        <button class="close-btn" onclick="window.close()">✕ Close</button>
    </div>

    <?php 
    $familyIndex = 0;
    $totalFamilies = count($familiesWithMembers);
    
    foreach ($familiesWithMembers as $family): 
        $familyIndex++;
        $isLastFamily = ($familyIndex === $totalFamilies);
    ?>
    <div class="container family-page <?php echo !$isLastFamily ? 'page-break' : ''; ?>">
        <!-- Header -->
        <div class="header-row">
            <div class="header-left">
                <span class="form-label-text">Records Form</span>
                <span class="form-number">R2-03</span>
            </div>
            <div class="header-center">
                <p class="main-title">TALAAN NG KAANIB NG SAMBAHAYAN SA GRUPO</p>
            </div>
        </div>
        
        <!-- Officer and Location Section -->
        <div class="info-row">
            <table class="officer-table">
                <tr>
                    <td class="officer-cell">Katiwala: <?php echo htmlspecialchars($katiwala); ?></td>
                    <td class="officer-cell">II Katiwala: <?php echo htmlspecialchars($iiKatiwala); ?></td>
                    <td class="officer-cell">Kalihim: <?php echo htmlspecialchars($kalihim); ?></td>
                </tr>
            </table>
            <div class="location-wrapper">
                <table class="location-table">
                    <tr class="label-row">
                        <td>PUROK</td>
                        <td>GRUPO</td>
                    </tr>
                    <tr class="data-row">
                        <td><?php echo htmlspecialchars($family['purok'] ?: ''); ?></td>
                        <td><?php echo htmlspecialchars($family['grupo'] ?: ''); ?></td>
                    </tr>
                </table>
            </div>
        </div>
        
        <!-- Main Data Table -->
        <table class="main-table">
            <thead>
                <tr>
                    <th rowspan="2" class="col-samb">Samb<br>Big</th>
                    <th rowspan="2" class="col-pangalan">BUONG PANGALAN</th>
                    <th colspan="13">ID NUMBER</th>
                    <th rowspan="2" class="col-cs">CS</th>
                    <th colspan="2" class="header-petsa">Petsa</th>
                    <th rowspan="2" class="col-relasyon">Relasyon sa<br>Pangulo</th>
                    <th rowspan="2" class="col-kap">Kap</th>
                    <th rowspan="2" class="col-tirahan">Tirahan</th>
                    <th rowspan="2" class="col-code">Code</th>
                    <th rowspan="2" class="col-pansin">Pansin</th>
                </tr>
                <tr>
                    <!-- ID Number sub-columns (13 characters) -->
                    <th class="col-id-digit"></th>
                    <th class="col-id-digit"></th>
                    <th class="col-id-digit"></th>
                    <th class="col-id-digit"></th>
                    <th class="col-id-digit"></th>
                    <th class="col-id-digit"></th>
                    <th class="col-id-digit"></th>
                    <th class="col-id-digit"></th>
                    <th class="col-id-digit"></th>
                    <th class="col-id-digit"></th>
                    <th class="col-id-digit"></th>
                    <th class="col-id-digit"></th>
                    <th class="col-id-digit"></th>
                    <!-- Petsa sub-columns -->
                    <th class="col-petsa-bautismo sub-header">Bautismo</th>
                    <th class="col-petsa-kapanganakan sub-header">Kapanganakan</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $rowCount = 0;
                $maxRows = 35;
                
                foreach ($family['members'] as $member): 
                    if ($rowCount >= $maxRows) break;
                    $rowCount++;
                    
                    // Split registry number into individual characters for ID columns (remove spaces)
                    $registryClean = str_replace(' ', '', $member['registry']);
                    $registryChars = str_split($registryClean);
                    $registryChars = array_pad($registryChars, 13, '');
                    // Take only first 13 characters
                    $registryChars = array_slice($registryChars, 0, 13);
                    
                    // Format baptism date
                    $baptismFormatted = '';
                    if ($member['baptism_date']) {
                        $dt = new DateTime($member['baptism_date']);
                        $baptismFormatted = $dt->format('m/d/y');
                    }
                    
                    // Format birth date (kapanganakan)
                    $birthFormatted = '';
                    if (!empty($member['birth_date'])) {
                        try {
                            $dt = new DateTime($member['birth_date']);
                            $birthFormatted = $dt->format('m/d/y');
                        } catch (Exception $e) {
                            // If date format is invalid, use as-is
                            $birthFormatted = $member['birth_date'];
                        }
                    }
                    
                    // Kapisanan abbreviation
                    $kapAbbrev = match($member['kapisanan']) {
                        'Buklod' => 'BUK',
                        'Kadiwa' => 'KAD',
                        'Binhi' => 'BIN',
                        'PNK' => 'PNK',
                        'HDB' => 'HDB',
                        default => ''
                    };
                    
                    // Civil Status based on kapisanan
                    $civilStatus = match($member['kapisanan']) {
                        'Buklod' => 'K',
                        'Kadiwa', 'Binhi' => 'S',
                        default => ''
                    };
                ?>
                <tr>
                    <td><?php echo ($member['member_type'] === 'pangulo') ? '●' : ''; ?></td>
                    <td class="text-left"><?php echo htmlspecialchars($member['name']); ?></td>
                    <?php for ($i = 0; $i < 13; $i++): ?>
                    <td><?php echo htmlspecialchars($registryChars[$i] ?? ''); ?></td>
                    <?php endfor; ?>
                    <td><?php echo $civilStatus; ?></td>
                    <td><?php echo htmlspecialchars($baptismFormatted); ?></td>
                    <td><?php echo htmlspecialchars($birthFormatted); ?></td>
                    <td><?php echo htmlspecialchars($member['relasyon']); ?></td>
                    <td><?php echo htmlspecialchars($kapAbbrev); ?></td>
                    <td class="text-left"><?php echo htmlspecialchars($family['local_name'] ?? ''); ?></td>
                    <td></td>
                    <td class="text-left"></td>
                </tr>
                <?php endforeach; ?>
                
                <?php 
                // Fill remaining rows with empty cells (23 columns total: 1+1+13+1+2+1+1+1+1+1)
                for ($i = $rowCount; $i < $maxRows; $i++): 
                ?>
                <tr>
                    <td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>
                </tr>
                <?php endfor; ?>
            </tbody>
        </table>
        
        <!-- Footer -->
        <p class="footer">Revised Sept 2009</p>
    </div>
    <?php endforeach; ?>
    
    <?php if (empty($familiesWithMembers)): ?>
    <div class="container">
        <p style="text-align: center; padding: 50px; font-size: 14pt;">No families found matching the criteria.</p>
    </div>
    <?php endif; ?>
</body>
</html>
