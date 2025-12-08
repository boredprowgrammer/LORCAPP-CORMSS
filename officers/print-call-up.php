<?php
/**
 * Print Call-Up Slip
 * Uses the exact template format from Call-UpForm_Template.html
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
$reasonText = strtoupper(Security::escape($slip['reason']));

// Replace all template values with actual data
$html = str_replace('DISTRITO NG PAMPANGA EAST', 
                    'DISTRITO NG ' . strtoupper(Security::escape($slip['district_name'])), 
                    $html);

$html = str_replace('LOKAL NG STO. TOMAS', 
                    'LOKAL NG ' . strtoupper(Security::escape($slip['local_name'])), 
                    $html);

$html = str_replace('PANGALAN: <span class="s2">VICTOR G. VIGAN</span>', 
                    'PANGALAN: <span class="s2">' . strtoupper(Security::escape($officerFullName)) . '</span>', 
                    $html);

$html = str_replace('KAGAWARAN/KAPISANAN: <span class="s2">BUKLOD</span>', 
                    'KAGAWARAN/KAPISANAN: <span class="s2">' . strtoupper(Security::escape($slip['department'])) . '</span>', 
                    $html);

$html = str_replace('CALL-UP FILE #: BUK-2025-001', 
                    'CALL-UP FILE #: ' . Security::escape($slip['file_number']), 
                    $html);

$html = str_replace('PETSA: <span class="s2">AGOSTO 06, 2025</span>', 
                    'PETSA: <span class="s2">' . Security::escape($issueDateFil) . '</span>', 
                    $html);

// Replace the reason section
$html = str_replace('HINDI PO PAGSUMITE NG <span class="s3">R7-02 </span>NOONG AGOSTO 03, 2025', 
                    $reasonText, 
                    $html);

$html = str_replace('AGOSTO 09, 2025', 
                    Security::escape($deadlineDateFil), 
                    $html);

$html = str_replace('NAGHANDA: <b>JAN ANDREI P. FERNANDO</b>', 
                    'NAGHANDA: <b>' . strtoupper(Security::escape($slip['prepared_by_name'])) . '</b>', 
                    $html);

$html = str_replace('AIVAN JADE G. CADIGAL', 
                    strtoupper(Security::escape($destinadoName)), 
                    $html);

// Add print and PDF buttons before the body content
$printButton = '<div style="text-align:center; padding:20px; background:#f5f5f5; margin:0; page-break-after:avoid;" class="no-print">
    <button onclick="window.print()" style="padding:12px 30px; font-size:16px; background:#4CAF50; color:white; border:none; border-radius:5px; cursor:pointer; margin-right:10px;">🖨️ Print Call-Up Slip</button>
    <button onclick="downloadPDF()" style="padding:12px 30px; font-size:16px; background:#2196F3; color:white; border:none; border-radius:5px; cursor:pointer; margin-right:10px;">📄 Download PDF</button>
    <button onclick="window.close()" style="padding:12px 30px; font-size:16px; background:#666; color:white; border:none; border-radius:5px; cursor:pointer;">✕ Close</button>
</div>';

$printStyles = '<style>@media print { .no-print { display:none !important; } body { margin: 0; padding: 20mm; } }</style>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
function downloadPDF() {
    const element = document.querySelector("table");
    const opt = {
        margin: [20, 10, 20, 10],
        filename: "call-up-slip-' . Security::escape($slip['file_number']) . '.pdf",
        image: { type: "jpeg", quality: 0.98 },
        html2canvas: { scale: 2, useCORS: true },
        jsPDF: { unit: "mm", format: "a4", orientation: "portrait" }
    };
    
    // Create a wrapper with the header
    const wrapper = document.createElement("div");
    wrapper.innerHTML = `
        <h1 style="text-align:center; font-family:Arial; font-size:12pt; font-weight:bold; margin-bottom:5px;">IGLESIA NI CRISTO</h1>
        <p style="text-align:center; font-family:Arial; font-size:12pt; margin:2px 0;">DISTRITO NG ' . strtoupper(Security::escape($slip['district_name'])) . '</p>
        <p style="text-align:center; font-family:Arial; font-size:12pt; margin:2px 0 15px 0;">LOKAL NG ' . strtoupper(Security::escape($slip['local_name'])) . '</p>
    `;
    wrapper.appendChild(element.cloneNode(true));
    
    html2pdf().set(opt).from(wrapper).save();
}
</script>';

$html = str_replace('</head>', $printStyles . '</head>', $html);
$html = str_replace('<body>', '<body>' . $printButton, $html);

// Output the modified HTML
echo $html;
exit;
?>
