<?php
/**
 * Palasumpaan Generator
 * Generates oath certificates for officers
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/security.php';

Security::requireLogin();

$db = Database::getInstance()->getConnection();
$user = getCurrentUser();

// Get request ID from query string
$requestId = $_GET['request_id'] ?? null;

if (!$requestId) {
    header('Location: requests/list.php');
    exit;
}

// Check if this is preview mode
$isPreview = isset($_GET['preview']) && $_GET['preview'] == '1';

// Fetch request details
$stmt = $db->prepare("
    SELECT 
        r.*,
        lc.local_name,
        d.district_name,
        o.officer_uuid,
        o.last_name_encrypted as officer_last_name_encrypted,
        o.first_name_encrypted as officer_first_name_encrypted,
        o.middle_initial_encrypted as officer_middle_initial_encrypted,
        o.district_code as officer_district_code
    FROM officer_requests r
    LEFT JOIN local_congregations lc ON r.local_code = lc.local_code
    LEFT JOIN districts d ON r.district_code = d.district_code
    LEFT JOIN officers o ON r.officer_id = o.officer_id
    WHERE r.request_id = ? AND r.status IN ('ready_to_oath', 'oath_taken')
");

$stmt->execute([$requestId]);
$request = $stmt->fetch();

if (!$request) {
    die("Request not found or not ready for oath certificate generation.");
}

// Check permissions
if ($user['role'] === 'local' && $request['local_code'] !== $user['local_code']) {
    die("Access denied.");
} elseif ($user['role'] === 'district' && $request['district_code'] !== $user['district_code']) {
    die("Access denied.");
}

// Decrypt officer name - always use request fields
$nameData = Encryption::decryptOfficerName(
    $request['last_name_encrypted'],
    $request['first_name_encrypted'],
    $request['middle_initial_encrypted'],
    $request['district_code']
);

$fullName = trim(($nameData['first_name'] ?? '') . ' ' . 
                 (($nameData['middle_initial'] ?? '') ? $nameData['middle_initial'] . '. ' : '') . 
                 ($nameData['last_name'] ?? ''));

$duty = $request['requested_duty'] ?: $request['requested_department'];

// Get oath date and location from URL parameters (from modal) or use defaults for preview
if ($isPreview) {
    // Preview mode: use actual oath date and show placeholder for location
    $oathDateStr = $request['oath_actual_date'];
    $oathLokal = '[TO BE FILLED]';
    $oathDistrito = '[TO BE FILLED]';
} else {
    // Generate mode: use parameters from modal
    $oathDateStr = $_GET['oath_date'] ?? $request['oath_actual_date'];
    $oathLokal = $_GET['oath_lokal'] ?? '';
    $oathDistrito = $_GET['oath_distrito'] ?? '';
}

// Format oath date
$oathDate = new DateTime($oathDateStr);
$day = $oathDate->format('d');
$month = formatMonthTagalog($oathDate->format('F'));
$year = $oathDate->format('Y');

function formatMonthTagalog($month) {
    $months = [
        'January' => 'ENERO',
        'February' => 'PEBRERO',
        'March' => 'MARSO',
        'April' => 'ABRIL',
        'May' => 'MAYO',
        'June' => 'HUNYO',
        'July' => 'HULYO',
        'August' => 'AGOSTO',
        'September' => 'SETYEMBRE',
        'October' => 'OKTUBRE',
        'November' => 'NOBYEMBRE',
        'December' => 'DISYEMBRE'
    ];
    return $months[$month] ?? $month;
}

?>
<!doctype html>
<html lang="null">
<head>
    <meta charset="UTF-8">
    <title>Palasumpaan - <?= htmlspecialchars($fullName) ?></title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <style>
        body {
            background-color: #444;
            padding: 0 10px;
            margin: 0;
            min-width: fit-content;
        }
        <?php if (!$isPreview): ?>
        body {
            background: white;
        }
        body:not(#loading-overlay) .page-container {
            visibility: hidden;
            position: absolute;
        }
        <?php endif; ?>
        #loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: white;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 10000;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
        }
        .spinner {
            width: 64px;
            height: 64px;
            border: 5px solid #e5e7eb;
            border-top: 5px solid #16a34a;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .loading-content {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        .loading-text {
            font-size: 22px;
            font-weight: 600;
            color: #1f2937;
            margin-top: 24px;
            letter-spacing: -0.01em;
        }
        .loading-subtext {
            font-size: 15px;
            color: #6b7280;
            margin-top: 10px;
            font-weight: 400;
        }
        .page-container {
            margin: 10px auto;
            width: fit-content;
        }
        .page {
            overflow: hidden;
            position: relative;
            background-color: white;
        }
        .t {
            transform-origin: bottom left;
            z-index: 2;
            position: absolute;
            white-space: pre;
            overflow: visible;
            line-height: 1.5;
        }
        .text-container {
            white-space: pre;
        }
        .s0{font-size:20px;font-family:Tahoma,sans-serif;color:#000;}
        .s1{font-size:20px;font-family:Tahoma,sans-serif;font-weight:bold;color:#000;}
        .s2{font-size:21px;font-family:Tahoma,sans-serif;color:#000;}
        .s3{font-size:18px;font-family:sans-serif;color:#000;font-weight:bold;text-decoration:underline;z-index:10;position:relative;}
        .s4{font-size:18px;font-family:sans-serif;color:#000;}
        u {
            text-decoration: underline !important;
            text-decoration-thickness: 2px !important;
            text-underline-offset: 2px !important;
        }
        b {
            font-weight: bold !important;
        }
        u b, b u {
            text-decoration: underline !important;
            font-weight: bold !important;
        }
        
        .no-print {
            position: fixed;
            top: 10px;
            right: 10px;
            z-index: 1000;
        }
        .no-print button {
            background: #3b82f6;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            margin-left: 5px;
        }
        .no-print button:hover {
            background: #2563eb;
        }
        .no-print .back-btn {
            background: #6b7280;
        }
        .no-print .back-btn:hover {
            background: #4b5563;
        }
        .no-print .pdf-btn {
            background: #10b981;
        }
        .no-print .pdf-btn:hover {
            background: #059669;
        }
        
        @media print {
            body {
                background-color: white;
                padding: 0;
            }
            .page-container {
                margin: 0;
            }
            .no-print {
                display: none;
            }
        }
    </style>
    <script>
        async function generatePDF(download = false) {
            const { jsPDF } = window.jspdf;
            const certificate = document.querySelector('.page');
            
            // Show loading message
            const btn = event.target;
            const originalText = btn.innerHTML;
            btn.innerHTML = '⏳ Generating PDF...';
            btn.disabled = true;
            
            try {
                // Capture the certificate as canvas
                const canvas = await html2canvas(certificate, {
                    scale: 2,
                    useCORS: true,
                    logging: false,
                    backgroundColor: '#ffffff',
                    width: certificate.offsetWidth,
                    height: certificate.offsetHeight,
                    onclone: function(clonedDoc) {
                        // Ensure underlines and bold are visible in cloned document
                        clonedDoc.querySelectorAll('u').forEach(el => {
                            el.style.textDecoration = 'underline';
                            el.style.textDecorationThickness = '2px';
                            el.style.textUnderlineOffset = '2px';
                        });
                        clonedDoc.querySelectorAll('b').forEach(el => {
                            el.style.fontWeight = 'bold';
                        });
                    }
                });
                
                // Calculate page dimensions based on certificate aspect ratio
                const pageWidth = 935;
                const pageHeight = 1430;
                const pdfWidth = 210; // A4 width in mm
                const pdfHeight = (pageHeight / pageWidth) * pdfWidth;
                
                // Create PDF with custom size to fit entire certificate
                const pdf = new jsPDF({
                    orientation: 'portrait',
                    unit: 'mm',
                    format: [pdfWidth, pdfHeight]
                });
                
                const imgData = canvas.toDataURL('image/png');
                pdf.addImage(imgData, 'PNG', 0, 0, pdfWidth, pdfHeight);
                
                if (download) {
                    // Download the PDF
                    pdf.save('Palasumpaan_<?= htmlspecialchars($fullName) ?>_<?= date('Y-m-d') ?>.pdf');
                } else {
                    // Open PDF in new tab for preview
                    const pdfBlob = pdf.output('blob');
                    const pdfUrl = URL.createObjectURL(pdfBlob);
                    window.open(pdfUrl, '_blank');
                }
                
            } catch (error) {
                console.error('Error generating PDF:', error);
                alert('Error generating PDF. Please try print instead.');
            } finally {
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        }
        
        // Auto-generate PDF preview on page load (not in preview mode)
        window.addEventListener('DOMContentLoaded', function() {
            <?php if (!$isPreview): ?>
                // Auto-open PDF preview and close current tab
                setTimeout(function() {
                    generatePDFAuto();
                }, 1500);
            <?php endif; ?>
        });
        
        async function generatePDFAuto() {
            try {
                const { jsPDF } = window.jspdf;
                const certificate = document.querySelector('.page');
                
                if (!certificate) {
                    throw new Error('Certificate element not found');
                }
                
                // Make elements visible temporarily for canvas capture
                document.body.style.display = 'block';
                const pageContainer = document.querySelector('.page-container');
                if (pageContainer) {
                    pageContainer.style.visibility = 'visible';
                }
                certificate.style.visibility = 'visible';
                
                // Wait a bit for rendering
                await new Promise(resolve => setTimeout(resolve, 100));
                
                // Capture the certificate as canvas
                const canvas = await html2canvas(certificate, {
                    scale: 2,
                    useCORS: true,
                    logging: true,
                    backgroundColor: '#ffffff',
                    width: 935,
                    height: 1430,
                    onclone: function(clonedDoc) {
                        clonedDoc.querySelectorAll('u').forEach(el => {
                            el.style.textDecoration = 'underline';
                            el.style.textDecorationThickness = '2px';
                            el.style.textUnderlineOffset = '2px';
                        });
                        clonedDoc.querySelectorAll('b').forEach(el => {
                            el.style.fontWeight = 'bold';
                        });
                    }
                });
                
                // Calculate page dimensions
                const pageWidth = 935;
                const pageHeight = 1430;
                const pdfWidth = 210;
                const pdfHeight = (pageHeight / pageWidth) * pdfWidth;
                
                // Create PDF
                const pdf = new jsPDF({
                    orientation: 'portrait',
                    unit: 'mm',
                    format: [pdfWidth, pdfHeight]
                });
                
                const imgData = canvas.toDataURL('image/png');
                pdf.addImage(imgData, 'PNG', 0, 0, pdfWidth, pdfHeight);
                
                // Replace current page with PDF
                const pdfBlob = pdf.output('blob');
                const pdfUrl = URL.createObjectURL(pdfBlob);
                window.location.href = pdfUrl;
                
            } catch (error) {
                console.error('Error generating PDF:', error);
                alert('Error generating PDF: ' + error.message + '. Redirecting back...');
                window.location.href = 'requests/view.php?id=<?= $requestId ?>';
            }
        }
    </script>
</head>
<body>

<?php if (!$isPreview): ?>
<div id="loading-overlay">
    <div class="loading-content">
        <div class="spinner"></div>
        <div class="loading-text">Generating PDF Certificate</div>
        <div class="loading-subtext">Please wait, this will only take a moment...</div>
    </div>
</div>
<?php endif; ?>

<div class="no-print">
    <?php if ($isPreview): ?>
        <button class="back-btn" onclick="window.location.href='requests/view.php?id=<?= $requestId ?>'">← Back to Request</button>
        <span style="color: #fff; background: #f59e0b; padding: 10px 15px; border-radius: 5px; margin-left: 5px;">
            📋 PREVIEW MODE - Locations not filled
        </span>
    <?php else: ?>
        <button class="pdf-btn" onclick="generatePDF(true)">� Download PDF</button>
        <button onclick="generatePDF(false)">📄 Preview PDF</button>
        <button onclick="window.print()">🖨️ Print Certificate</button>
        <button class="back-btn" onclick="window.location.href='requests/view.php?id=<?= $requestId ?>'">← Back</button>
    <?php endif; ?>
</div>

<div class="page-container">
<section class="page" style="width: 935px; height: 1430px;" aria-label="Page 1">
<div id="pg1Overlay" style="width:100%; height:100%; position:absolute; z-index:1; background-color:rgba(0,0,0,0); -webkit-user-select: none;"></div>
<div id="pg1" style="-webkit-user-select: none;"><img id="pdf1" style="width:935px; height:1430px;" src="data:image/svg+xml,%3Csvg viewBox='0 0 935 1430' version='1.1' xmlns='http://www.w3.org/2000/svg'%3E%0A%3Cdefs%3E%0A%3CclipPath id='c0'%3E%3Cpath d='M-.2 1430.3V-.2H935.3V1430.3Z'/%3E%3C/clipPath%3E%0A%3CclipPath id='c1'%3E%3Cpath d='M472.8 218.4v-3H629.3v3Z'/%3E%3C/clipPath%3E%0A%3Cstyle%3E%0A.g0%7Bfill:%23FFF%3B%7D%0A.g1%7Bfill:%23000%3Bfill-opacity:0%3B%7D%0A%3C/style%3E%0A%3C/defs%3E%0A%3Cpath clip-path='url(%23c0)' d='M0 0H935V1430H0V0Z' class='g0'/%3E%0A%3Cimage clip-path='url(%23c1)' preserveAspectRatio='none' x='473' y='216' width='156' height='2' href='data:image/png%3Bbase64%2CiVBORw0KGgoAAAANSUhEUgAAAJwAAAACCAMAAACaCkVMAAAADFBMVEUAAAAAAAAAAAAAAAA16TeWAAAABHRSTlMApP%2BlWHo5MwAAABZJREFUeNpjYEAFjEwDCJgZ8INB5TgANWcB%2BSi2StIAAAAASUVORK5CYII='/%3E%0A%3Cpath d='M473.2 217.6H629.3v-1.8H473.2v1.8Z' class='g1'/%3E%0A%3C/svg%3E"/></div>
<div class="text-container">
<span class="t s0" style="left:123px;bottom:401px;letter-spacing:-0.04px;">___________________ </span>
<span class="t s0" style="left:167px;bottom:377px;letter-spacing:-0.04px;">Purok - Grupo </span>
<span class="t s0" style="left:55px;bottom:272px;letter-spacing:-0.08px;">_____________________________ </span>
<span class="t s0" style="left:167px;bottom:248px;letter-spacing:-0.08px;">Nagpasumpa </span>
<span class="t s1" style="left:232px;bottom:1330px;letter-spacing:-0.1px;">PANUNUMPA SA PAGTANGGAP NG TUNGKULIN </span>
<span class="t s0" style="left:55px;bottom:1244px;letter-spacing:0.07px;word-spacing:2.67px;">Akong si <u><b><?= strtoupper(htmlspecialchars($fullName)) ?></b></u>, kaanib sa Iglesia Ni Cristo sa lokal ng </span>
<span class="t s0" style="left:55px;bottom:1208px;letter-spacing:0.07px;word-spacing:7.71px;"><u><b><?= strtoupper(htmlspecialchars($request['local_name'])) ?></b></u>, Distrito ng </span><span class="t s1" style="left:464px;bottom:1208px;letter-spacing:0.05px;word-spacing:7.71px;">Pampanga East </span><span class="t s0" style="left:641px;bottom:1208px;letter-spacing:0.06px;word-spacing:7.71px;">ay nagpapahayag na aking </span>
<span class="t s0" style="left:55px;bottom:1173px;letter-spacing:0.07px;word-spacing:0.78px;">tinatanggap ang tungkuling <u><b><?= strtoupper(htmlspecialchars($duty)) ?></b></u>, udyok ng pananampalataya at malinis </span>
<span class="t s0" style="left:55px;bottom:1137px;letter-spacing:0.07px;word-spacing:0.27px;">na budhi na walang ibang layunin kundi ang pag-ibig sa Diyos, sa Panginoong Jesucristo, at sa </span>
<span class="t s0" style="left:55px;bottom:1102px;letter-spacing:0.08px;">Kaniyang banal na Iglesia. </span>
<span class="t s0" style="left:55px;bottom:1048px;letter-spacing:-0.03px;">Akin ding ipinahahayag na tutuparin ko nang buong puso at pagmamalasakit ang lahat ng </span>
<span class="t s0" style="left:55px;bottom:1013px;letter-spacing:0.23px;">gampaning nasasaklaw ng aking tungkulin, dahil ako ay lubos na sumasampalataya na ang </span>
<span class="t s0" style="left:55px;bottom:977px;letter-spacing:-0.07px;">pagpapabaya sa mga ito ay katumbas na rin ng pagtalikod sa tungkulin. </span>
<span class="t s0" style="left:55px;bottom:925px;letter-spacing:0.43px;">Ipinangangako ko rin na lubos kong susundin at ipatutupad ang mga aral ng Diyos na </span>
<span class="t s0" style="left:55px;bottom:889px;letter-spacing:0.02px;">nakasulat sa Bibilia at ang lahat ng tuntuning pinaiiral sa loob ng Iglesia, sa pamamagitan ng </span>
<span class="t s0" style="left:55px;bottom:855px;letter-spacing:0.41px;">lubos at buong kababaang–loob na nagpapasakop sa Pamamahala ng Iglesia Ni Cristo sa </span>
<span class="t s0" style="left:55px;bottom:819px;letter-spacing:-0.07px;">mga huling araw. </span>
<span class="t s0" style="left:55px;bottom:766px;letter-spacing:0.19px;">Nauunawaan ko na may kaukulang parusa ang alinmang paglabag sa mga aral ng Diyos </span>
<span class="t s0" style="left:55px;bottom:730px;letter-spacing:0.45px;">at mga tuntuning itinuturo at ipinatutupad sa loob ng Iglesia Ni Cristo. Kaya kung ako ay </span>
<span class="t s0" style="left:55px;bottom:695px;letter-spacing:-0.07px;">masusumpungan sa paglabag ay buong puso kong tatanggapin ang anumang parusa o </span>
<span class="t s0" style="left:55px;bottom:659px;letter-spacing:-0.06px;">pagdidisiplinang ipapataw sa akin ng Pamamahala ng Iglesia Ni Cristo. </span>
<span class="t s0" style="left:86px;bottom:607px;letter-spacing:-0.07px;">Tulungan nawa ako ng Diyos. </span>
<span class="t s0" style="left:121px;bottom:504px;letter-spacing:-0.08px;"><u><b><?= strtoupper(htmlspecialchars($fullName)) ?></b></u></span>
<span class="t s2" style="left:178px;bottom:485px;letter-spacing:-0.04px;">Nanumpa </span>
<span class="t s2" style="left:54px;bottom:83px;letter-spacing:-0.04px;">Sinumpaan ngayong ika - <u><b><?= $day ?></b></u> ng <u><b><?= $month ?></b></u> taong <u><b><?= $year ?></b></u> sa </span>
<span class="t s2" style="left:54px;bottom:56px;letter-spacing:-0.03px;">Lokal ng <u><b><?php if ($oathLokal && $oathLokal !== '[TO BE FILLED]'): ?><?= strtoupper(htmlspecialchars($oathLokal)) ?><?php else: ?>____________________<?php endif; ?></b></u> , Distrito Eklesiastiko ng <u><b><?php if ($oathDistrito && $oathDistrito !== '[TO BE FILLED]'): ?><?= strtoupper(htmlspecialchars($oathDistrito)) ?><?php else: ?>__________________________<?php endif; ?></b></u> </span>
</div>

</div>

</div>

</body>
</html>
