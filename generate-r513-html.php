<?php
/**
 * Generate R5-13 (Form 513) - Certificate of Seminar Attendance
 * HTML-based version for better PDF control
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

Security::requireLogin();

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

// Check if this is preview mode (GET) or generation mode (POST)
$isPreview = isset($_GET['preview']) && $_GET['preview'] == '1';
$requestMethod = $_SERVER['REQUEST_METHOD'];

// For POST requests (actual generation), expect JSON and validate CSRF
if ($requestMethod === 'POST') {
    header('Content-Type: application/json');
    
    try {
        // Get request data
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['csrf_token']) || !Security::validateCSRFToken($input['csrf_token'])) {
            throw new Exception('Invalid security token');
        }
        
        $requestId = isset($input['request_id']) ? (int)$input['request_id'] : 0;
    } catch (Exception $e) {
        http_response_code(400);
        error_log("R5-13 Generation Error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
        exit;
    }
} else {
    // GET request for preview - no CSRF validation needed
    $requestId = isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0;
}

if ($requestId === 0) {
    if ($requestMethod === 'POST') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid request ID']);
    } else {
        header('Location: requests/list.php');
    }
    exit;
}

try {
    // Get officer request with district and local info
    $stmt = $db->prepare("
        SELECT ore.*,
               d.district_name, d.district_code,
               l.local_name, l.local_code,
               u.full_name as requested_by_name,
               u.username as requested_by_username
        FROM officer_requests ore
        JOIN districts d ON ore.district_code = d.district_code
        JOIN local_congregations l ON ore.local_code = l.local_code
        JOIN users u ON ore.requested_by = u.user_id
        WHERE ore.request_id = ?
    ");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();
    
    if (!$request) {
        throw new Exception('Request not found');
    }
    
    // Check access permissions
    if (($currentUser['role'] === 'local' || $currentUser['role'] === 'local_limited') && 
        $request['local_code'] !== $currentUser['local_code']) {
        throw new Exception('Access denied');
    } elseif ($currentUser['role'] === 'district' && $request['district_code'] !== $currentUser['district_code']) {
        throw new Exception('Access denied');
    }
    
    // Check if seminar is completed
    if ($request['status'] !== 'seminar_completed' && $request['status'] !== 'requested_to_oath' && 
        $request['status'] !== 'ready_to_oath' && $request['status'] !== 'oath_taken') {
        throw new Exception('Seminar must be completed before generating R5-13 certificate');
    }
    
    // Check if all required seminar days are completed
    $requiredDays = $request['seminar_days_required'] ?? 0;
    $completedDays = $request['seminar_days_completed'] ?? 0;
    
    if ($requiredDays > 0 && $completedDays < $requiredDays) {
        throw new Exception("Seminar not yet complete: $completedDays/$requiredDays days attended");
    }
    
    // Decrypt officer name
    $encryption = new Encryption();
    $districtKey = InfisicalKeyManager::getDistrictKey($request['district_code']);
    
    $lastName = !empty($request['last_name_encrypted']) ? 
        $encryption->decrypt($request['last_name_encrypted'], $districtKey) : '';
    $firstName = !empty($request['first_name_encrypted']) ? 
        $encryption->decrypt($request['first_name_encrypted'], $districtKey) : '';
    $middleInitial = !empty($request['middle_initial_encrypted']) ? 
        $encryption->decrypt($request['middle_initial_encrypted'], $districtKey) : '';
    
    $fullName = trim("$firstName " . ($middleInitial ? "$middleInitial " : "") . $lastName);
    
    // Parse seminar dates
    $seminarDates = json_decode($request['seminar_dates'] ?? '[]', true) ?: [];
    
    // Current date for certificate
    $now = new DateTime();
    
    // Load the official 513 HTML form
    $templatePath = __DIR__ . '/R5-13(Seminar_Form)/513.pdf-1.html';
    if (!file_exists($templatePath)) {
        throw new Exception('Official 513 form template not found');
    }
    
    $html = file_get_contents($templatePath);
    
    // Prepare data
    $districtName = htmlspecialchars($request['district_name'] ?? '');
    $localName = htmlspecialchars($request['local_name'] ?? '');
    $districtCode = htmlspecialchars($request['district_code'] ?? '');
    $localCode = htmlspecialchars($request['local_code'] ?? '');
    $fullNameEsc = htmlspecialchars($fullName);
    
    // Get date components (Buwan, Araw, Taon)
    $buwan = $now->format('F'); // Full month name
    $araw = $now->format('d'); // Day with leading zero
    $taon = $now->format('Y'); // Four digit year
    
    // Determine seminar class and row configuration
    $requestClass = $request['request_class'] ?? '33_lessons';
    $isEightLessons = ($requestClass === '8_lessons'); // 8 lessons
    $is33Lessons = ($requestClass === '33_lessons'); // 33 lessons (8 main + PAKSA sections)
    
    // Directly modify HTML by injecting divs into the template matching the exact format
    $injectionsAfterBody = '';
    
    // Inject Distrito name (after Distrito label at 31.35pt,71.9pt)
    $injectionsAfterBody .= "\t\t<div style=\"position:absolute; left:70pt; top:71.9pt;\"><span style=\"position:absolute; white-space:pre; font-size:9pt; font-family: 'IDYPKQ+Verdana'; color:#000000; left:0pt\">{$districtName}</span></div>\n";
    
    // Inject Lokal name (after Lokal label at 31.35pt,90.15pt)
    $injectionsAfterBody .= "\t\t<div style=\"position:absolute; left:70pt; top:90.15pt;\"><span style=\"position:absolute; white-space:pre; font-size:9pt; font-family: 'IDYPKQ+Verdana'; color:#000000; left:0pt\">{$localName}</span></div>\n";
    
    // Inject DCODE (after DCODE label at 285.55pt,71.9pt) - use district_code not district_name
    $injectionsAfterBody .= "\t\t<div style=\"position:absolute; left:330pt; top:71.9pt;\"><span style=\"position:absolute; white-space:pre; font-size:9pt; font-family: 'IDYPKQ+Verdana'; color:#000000; left:0pt\">{$districtCode}</span></div>\n";
    
    // Inject LCODE (after LCODE label at 286.5pt,90.15pt) - use local_code not local_name
    $injectionsAfterBody .= "\t\t<div style=\"position:absolute; left:330pt; top:90.15pt;\"><span style=\"position:absolute; white-space:pre; font-size:9pt; font-family: 'IDYPKQ+Verdana'; color:#000000; left:0pt\">{$localCode}</span></div>\n";
    
    // Inject Buwan, Araw, Taon (date fields at top right)
    // Labels at: 411.75pt top:71.9pt with "Buwan" at +0pt, "Araw" at +59.4pt, "Taon" at +123.65pt
    // Underlines are at y=104.9pt:
    //   Buwan: 392pt to 448.65pt (center ~420pt)
    //   Araw: 448.75pt to 512.4pt (center ~480pt)
    //   Taon: 512.55pt to 576.3pt (center ~544pt)
    // Place text at y=85.3pt to align with existing field format
    $injectionsAfterBody .= "\t\t<div style=\"position:absolute; left:420pt; top:85.3pt;\"><span style=\"position:absolute; white-space:pre; font-size:9pt; font-family: 'Calibri'; color:#000000; left:0pt\">{$buwan}</span></div>\n";
    $injectionsAfterBody .= "\t\t<div style=\"position:absolute; left:480pt; top:85.3pt;\"><span style=\"position:absolute; white-space:pre; font-size:9pt; font-family: 'Calibri'; color:#000000; left:0pt\">{$araw}</span></div>\n";
    $injectionsAfterBody .= "\t\t<div style=\"position:absolute; left:544pt; top:85.3pt;\"><span style=\"position:absolute; white-space:pre; font-size:9pt; font-family: 'Calibri'; color:#000000; left:0pt\">{$taon}</span></div>\n";
    
    // Inject seminar dates into respective rows based on class type
    if ($isEightLessons) {
        // 8 lessons: only rows 1-8
        $rowTops = [168.3, 193.5, 214.4, 230.9, 247.4, 264, 280.55, 297.1];
        
        for ($i = 0; $i < 8 && $i < count($seminarDates); $i++) {
            $date = $seminarDates[$i] ?? null;
            if ($date && isset($date['date'])) {
                $dt = new DateTime($date['date']);
                $dateStr = htmlspecialchars($dt->format('M d, Y'));
                $topPos = $rowTops[$i];
                
                // Add date in Petsa ng Seminar column (420pt left)
                $injectionsAfterBody .= "\t\t<div style=\"position:absolute; left:420pt; top:{$topPos}pt;\"><span style=\"position:absolute; white-space:pre; font-size:9pt; font-family: 'IDYPKQ+Verdana'; color:#000000; left:0pt\">{$dateStr}</span></div>\n";
            }
        }
    } else {
        // 33 lessons: 8 main rows + PAKSA sections
        // Main 8 rows
        $mainRows = [168.3, 193.5, 214.4, 230.9, 247.4, 264, 280.55, 297.1];
        
        // PAKSA 1 rows (5 rows starting around 348pt based on "P A K S A" vertical text)
        $paksa1Rows = [348, 359, 370, 381, 392];
        
        // PAKSA 2 rows (5 rows starting around 471pt)
        $paksa2Rows = [471, 482, 493, 504, 515];
        
        // PAKSA 3 rows (5 rows starting around 588pt)
        $paksa3Rows = [588, 599, 610, 621, 632];
        
        // GAWAIN rows (5 rows starting around 697pt)
        $gawainRows = [697, 708, 719, 730, 741];
        
        $allRows = array_merge($mainRows, $paksa1Rows, $paksa2Rows, $paksa3Rows, $gawainRows);
        
        for ($i = 0; $i < count($allRows) && $i < count($seminarDates); $i++) {
            $date = $seminarDates[$i] ?? null;
            if ($date && isset($date['date'])) {
                $dt = new DateTime($date['date']);
                $dateStr = htmlspecialchars($dt->format('M d, Y'));
                $topPos = $allRows[$i];
                
                // Add date in Petsa ng Seminar column (420pt left)
                $injectionsAfterBody .= "\t\t<div style=\"position:absolute; left:420pt; top:{$topPos}pt;\"><span style=\"position:absolute; white-space:pre; font-size:9pt; font-family: 'IDYPKQ+Verdana'; color:#000000; left:0pt\">{$dateStr}</span></div>\n";
            }
        }
    }
    
    // Find the position right after the opening body tag and the first div
    $html = preg_replace(
        '/(<body>\s*<div[^>]*>)/s',
        '$1' . "\n" . $injectionsAfterBody,
        $html,
        1
    );
    
    // Add print button for preview mode
    if ($isPreview) {
        $printButton = '<style>.print-btn{position:fixed;top:20px;right:20px;padding:10px 20px;background:#4CAF50;color:white;border:none;border-radius:5px;cursor:pointer;z-index:9999;font-family:Arial,sans-serif;}@media print{.print-btn{display:none;}}</style><button class="print-btn" onclick="window.print()">🖨️ Print</button>';
        $html = str_replace('</head>', $printButton . '</head>', $html);
    }
    
    // For preview mode, just output the HTML directly
    if ($isPreview) {
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }
    
    // For generation mode, use Dompdf to create PDF
    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');
    
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    
    $pdfContent = $dompdf->output();
    
    // Preview mode: display PDF in browser
    if ($isPreview) {
        $fileName = 'R5-13_' . str_replace(' ', '_', $fullName) . '_PREVIEW.pdf';
        
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $fileName . '"');
        header('Content-Length: ' . strlen($pdfContent));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        
        echo $pdfContent;
        exit;
    }
    
    // Generation mode: Store PDF in database
    require_once __DIR__ . '/includes/pdf-storage.php';
    $pdfStorage = new PDFStorage();
    
    $fileName = 'R5-13_' . $request['local_code'] . '_' . $requestId . '_' . date('Ymd') . '.pdf';
    $pdfId = $pdfStorage->storePDF(
        $pdfContent,
        $fileName,
        'other',
        $requestId,
        (string)$requestId,
        $currentUser['user_id']
    );
    
    if (!$pdfId) {
        throw new Exception('Failed to store PDF');
    }
    
    // Update officer_requests table
    $stmt = $db->prepare("
        UPDATE officer_requests 
        SET r513_generated_at = NOW(),
            r513_pdf_file_id = ?
        WHERE request_id = ?
    ");
    $stmt->execute([$pdfId, $requestId]);
    
    echo json_encode([
        'success' => true,
        'message' => 'R5-13 certificate generated successfully',
        'pdf_id' => $pdfId,
        'file_name' => $fileName
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    error_log("R5-13 Generation Error: " . $e->getMessage());
    
    if ($requestMethod === 'POST') {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    } else {
        echo "Error: " . htmlspecialchars($e->getMessage());
    }
}

function generateR513FormHTML($districtName, $localName, $officerName, $seminarDates, $dateToday, $requestClass, $requiredDays) {
    // Determine how many rows based on class
    $maxRows = ($requestClass === '33_lessons') ? 30 : 8;
    
    // Generate table rows for seminar dates
    $rows = '';
    for ($i = 0; $i < $maxRows; $i++) {
        $date = $seminarDates[$i] ?? null;
        $dateStr = '';
        if ($date && isset($date['date'])) {
            $dt = new DateTime($date['date']);
            $dateStr = $dt->format('M d, Y');
        }
        
        $rows .= '<tr>
            <td>' . ($i + 1) . '</td>
            <td>' . $dateStr . '</td>
            <td></td>
            <td></td>
        </tr>';
    }
    
    return <<<HTML
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="UTF-8">
    <title>R5-13 Records Form</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/dejavu-sans/index.css">
    <style>
        @page {
            size: A4 portrait;
            margin: 10mm;
        }
        @media print {
            body { margin: 0; }
            .no-print { display: none; }
        }
        body, * {
            font-family: "DejaVu Sans", sans-serif !important;
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            padding: 10mm;
        }
        .container {
            width: 100%;
            max-width: 210mm;
            border: 1pt solid black;
            padding: 10pt;
            margin: 0 auto;
            background: white;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8pt;
        }
        td {
            border: 1pt solid black;
            padding: 3pt 5pt;
            vertical-align: middle;
        }
        .header-row td {
            text-align: center;
            font-weight: bold;
            font-size: 9pt;
        }
        .header-cell {
            text-align: center;
            font-weight: bold;
            background-color: #f0f0f0;
        }
        .no-col { width: 8%; text-align: center; }
        .date-col { width: 30%; }
        .topic-col { width: 40%; }
        .remarks-col { width: 22%; }
        .signature-row td {
            text-align: center;
            padding: 20pt 5pt 5pt 5pt;
        }
        .signature-line {
            display: inline-block;
            min-width: 150pt;
            border-top: 1pt solid black;
            padding-top: 3pt;
            margin-top: 15pt;
        }
        .print-button {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 10px 20px;
            background: #4CAF50;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            z-index: 1000;
        }
        .print-button:hover {
            background: #45a049;
        }
    </style>
    <script>
        function printForm() {
            window.print();
        }
    </script>
</head>
<body>
    <button onclick="printForm()" class="print-button no-print">🖨️ Print Form</button>
    
    <div class="container">
        <!-- Header -->
        <table style="margin-bottom: 5pt;">
            <tr class="header-row">
                <td colspan="2" style="width: 30%;">DISTRICT</td>
                <td colspan="2">{$districtName}</td>
            </tr>
            <tr class="header-row">
                <td colspan="2">LOCAL CONGREGATION</td>
                <td colspan="2">{$localName}</td>
            </tr>
        </table>
        
        <!-- Main Table Header -->
        <table>
            <tr>
                <td class="header-cell no-col">No.</td>
                <td class="header-cell date-col">DATE</td>
                <td class="header-cell topic-col">TOPIC/SUBJECT</td>
                <td class="header-cell remarks-col">REMARKS</td>
            </tr>
            {$rows}
        </table>
        
        <!-- Signature Section -->
        <table style="margin-top: 10pt; border: none;">
            <tr class="signature-row">
                <td style="border: none; width: 50%;">
                    <div class="signature-line">District Minister</div>
                </td>
                <td style="border: none; width: 50%;">
                    <div class="signature-line">Date: {$dateToday}</div>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
HTML;
}

