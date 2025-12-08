<?php
/**
 * Download Call-Up Slip as PDF directly
 * Uses TCPDF (no installation needed, we'll use inline code)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

Security::requireLogin();

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

// Get slip ID
$slipId = intval($_GET['slip_id'] ?? 0);

if (!$slipId) {
    die('Invalid call-up slip ID.');
}

// Fetch call-up slip details
$stmt = $db->prepare("
    SELECT 
        c.*,
        o.officer_uuid,
        o.last_name_encrypted,
        o.first_name_encrypted,
        o.middle_initial_encrypted,
        o.district_code as officer_district,
        d.district_name,
        l.local_name,
        u.full_name as prepared_by_name
    FROM call_up_slips c
    JOIN officers o ON c.officer_id = o.officer_id
    LEFT JOIN districts d ON c.district_code = d.district_code
    LEFT JOIN local_congregations l ON c.local_code = l.local_code
    LEFT JOIN users u ON c.prepared_by = u.user_id
    WHERE c.slip_id = ?
");

$stmt->execute([$slipId]);
$slip = $stmt->fetch();

if (!$slip) {
    die('Call-up slip not found.');
}

// Check access rights
if ($currentUser['role'] === 'local' && $slip['local_code'] !== $currentUser['local_code']) {
    die('Access denied.');
} elseif ($currentUser['role'] === 'district' && $slip['district_code'] !== $currentUser['district_code']) {
    die('Access denied.');
}

// Decrypt officer name
$decrypted = Encryption::decryptOfficerName(
    $slip['last_name_encrypted'],
    $slip['first_name_encrypted'],
    $slip['middle_initial_encrypted'],
    $slip['officer_district']
);

$officerFullName = trim(
    $decrypted['first_name'] . ' ' . 
    ($decrypted['middle_initial'] ? $decrypted['middle_initial'] . '. ' : '') . 
    $decrypted['last_name']
);

// Format dates (Filipino month names)
$filipinoMonths = [
    1 => 'ENERO', 2 => 'PEBRERO', 3 => 'MARSO', 4 => 'ABRIL',
    5 => 'MAYO', 6 => 'HUNYO', 7 => 'HULYO', 8 => 'AGOSTO',
    9 => 'SETYEMBRE', 10 => 'OKTUBRE', 11 => 'NOBYEMBRE', 12 => 'DISYEMBRE'
];

$issueDateFil = strtoupper($filipinoMonths[(int)date('n', strtotime($slip['issue_date']))]) . ' ' . 
                date('d, Y', strtotime($slip['issue_date']));

$deadlineDateFil = strtoupper($filipinoMonths[(int)date('n', strtotime($slip['deadline_date']))]) . ' ' . 
                   date('d, Y', strtotime($slip['deadline_date']));

// Use destinado from database or default
$destinadoName = $slip['destinado'] ?? 'DESTINADO NG LOKAL';

// Load the HTML template
$templatePath = __DIR__ . '/../Call-UpForm_Template.html';
if (!file_exists($templatePath)) {
    die('Template file not found: ' . $templatePath);
}

$html = file_get_contents($templatePath);

// Prepare the reason text
$reasonText = strtoupper(htmlspecialchars($slip['reason'], ENT_QUOTES, 'UTF-8'));

// Replace all template values with actual data
$html = str_replace('DISTRITO NG PAMPANGA EAST', 
                    'DISTRITO NG ' . strtoupper(htmlspecialchars($slip['district_name'], ENT_QUOTES, 'UTF-8')), 
                    $html);

$html = str_replace('LOKAL NG STO. TOMAS', 
                    'LOKAL NG ' . strtoupper(htmlspecialchars($slip['local_name'], ENT_QUOTES, 'UTF-8')), 
                    $html);

$html = str_replace('PANGALAN: <span class="s2">VICTOR G. VIGAN</span>', 
                    'PANGALAN: <span class="s2">' . strtoupper(htmlspecialchars($officerFullName, ENT_QUOTES, 'UTF-8')) . '</span>', 
                    $html);

$html = str_replace('KAGAWARAN/KAPISANAN: <span class="s2">BUKLOD</span>', 
                    'KAGAWARAN/KAPISANAN: <span class="s2">' . strtoupper(htmlspecialchars($slip['department'], ENT_QUOTES, 'UTF-8')) . '</span>', 
                    $html);

$html = str_replace('CALL-UP FILE #: BUK-2025-001', 
                    'CALL-UP FILE #: ' . htmlspecialchars($slip['file_number'], ENT_QUOTES, 'UTF-8'), 
                    $html);

$html = str_replace('PETSA: <span class="s2">AGOSTO 06, 2025</span>', 
                    'PETSA: <span class="s2">' . htmlspecialchars($issueDateFil, ENT_QUOTES, 'UTF-8') . '</span>', 
                    $html);

// Replace the reason section
$html = str_replace('HINDI PO PAGSUMITE NG <span class="s3">R7-02 </span>NOONG AGOSTO 03, 2025', 
                    $reasonText, 
                    $html);

$html = str_replace('AGOSTO 09, 2025', 
                    htmlspecialchars($deadlineDateFil, ENT_QUOTES, 'UTF-8'), 
                    $html);

$html = str_replace('NAGHANDA: <b>JAN ANDREI P. FERNANDO</b>', 
                    'NAGHANDA: <b>' . strtoupper(htmlspecialchars($slip['prepared_by_name'], ENT_QUOTES, 'UTF-8')) . '</b>', 
                    $html);

$html = str_replace('AIVAN JADE G. CADIGAL', 
                    strtoupper(htmlspecialchars($destinadoName, ENT_QUOTES, 'UTF-8')), 
                    $html);

// Output HTML with jsPDF generation script
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Generating PDF...</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <style>
        body {
            margin: 0;
            padding: 20px;
            background: #f0f0f0;
            font-family: Arial, sans-serif;
        }
        #loading {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #3498db;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        #content {
            display: none;
            background: white;
            padding: 20mm;
            max-width: 210mm;
            margin: 0 auto;
        }
    </style>
</head>
<body>
    <div id="loading">
        <div class="spinner"></div>
        <h3>Generating PDF...</h3>
        <p>Please wait while we prepare your document.</p>
    </div>
    
    <div id="content">
        <?php echo $html; ?>
    </div>

    <script>
        window.onload = function() {
            const content = document.getElementById('content');
            content.style.display = 'block';
            
            // Wait a moment for fonts and rendering
            setTimeout(function() {
                html2canvas(content, {
                    scale: 2,
                    useCORS: true,
                    logging: false,
                    backgroundColor: '#ffffff'
                }).then(function(canvas) {
                    const { jsPDF } = window.jspdf;
                    const pdf = new jsPDF({
                        orientation: 'portrait',
                        unit: 'mm',
                        format: 'a4'
                    });
                    
                    const imgData = canvas.toDataURL('image/jpeg', 1.0);
                    const imgWidth = 210;
                    const pageHeight = 297;
                    const imgHeight = (canvas.height * imgWidth) / canvas.width;
                    
                    pdf.addImage(imgData, 'JPEG', 0, 0, imgWidth, imgHeight);
                    
                    // Open PDF in browser
                    const pdfBlob = pdf.output('blob');
                    const pdfUrl = URL.createObjectURL(pdfBlob);
                    window.location.href = pdfUrl;
                    
                    // Close the generation window after a delay
                    setTimeout(function() {
                        window.close();
                    }, 1000);
                });
            }, 500);
        };
    </script>
</body>
</html>
<?php
exit;
?>
