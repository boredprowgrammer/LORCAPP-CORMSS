<?php
/**
 * Palasumpaan Generator
 * Generates oath certificates for officers using PHPWord and converts to PDF
 * This is a standalone script that should not include any layout files
 */

// Prevent any output buffering issues
ob_start();

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpWord\TemplateProcessor;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\IOFactory;

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

// Load the template
$templatePath = __DIR__ . '/PALASUMPAAN_template.docx';

if (!file_exists($templatePath)) {
    die("Template file not found: PALASUMPAAN_template.docx");
}

try {
    // For non-preview mode, show a minimal loading message
    if (!$isPreview) {
        // Start output buffering for the entire response
        ob_end_clean();
        ob_start();
    }
    
    // Create template processor
    $templateProcessor = new TemplateProcessor($templatePath);
    
    // Replace placeholders in the template
    // Template should have these placeholders: ${FULLNAME}, ${LOCAL_NAME}, ${DISTRICT_NAME}, ${DUTY}, ${DAY}, ${MONTH}, ${YEAR}, ${OATH_LOKAL}, ${OATH_DISTRITO}
    $templateProcessor->setValue('FULLNAME', strtoupper($fullName));
    $templateProcessor->setValue('LOCAL_NAME', strtoupper($request['local_name']));
    $templateProcessor->setValue('DISTRICT_NAME', 'PAMPANGA EAST');
    $templateProcessor->setValue('DUTY', strtoupper($duty));
    $templateProcessor->setValue('DAY', $day);
    $templateProcessor->setValue('MONTH', $month);
    $templateProcessor->setValue('YEAR', $year);
    $templateProcessor->setValue('OATH_LOKAL', ($oathLokal && $oathLokal !== '[TO BE FILLED]') ? strtoupper($oathLokal) : '____________________');
    $templateProcessor->setValue('OATH_DISTRITO', ($oathDistrito && $oathDistrito !== '[TO BE FILLED]') ? strtoupper($oathDistrito) : '__________________________');
    
    // For preview mode, generate DOCX
    if ($isPreview) {
        $fileName = 'Palasumpaan_' . str_replace(' ', '_', $fullName) . '_PREVIEW.docx';
        
        // Set headers for download
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Cache-Control: max-age=0');
        
        // Save to output
        $templateProcessor->saveAs('php://output');
        exit;
    }
    
    // For generate mode, convert to PDF using Stirling PDF
    // Save the processed template to memory buffer
    $tempDocx = tempnam(sys_get_temp_dir(), 'palasumpaan_') . '.docx';
    $templateProcessor->saveAs($tempDocx);
    
    // Read the DOCX content into memory
    $docxContent = file_get_contents($tempDocx);
    unlink($tempDocx); // Clean up temp file immediately
    
    // Store DOCX in database
    $stmtStore = $db->prepare("
        INSERT INTO palasumpaan_temp_docs (request_id, docx_content, created_at) 
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE docx_content = VALUES(docx_content), created_at = NOW()
    ");
    $stmtStore->execute([$requestId, $docxContent]);
    
    // Create a temporary file from the blob for cURL upload
    $tempDocxForUpload = tempnam(sys_get_temp_dir(), 'palasumpaan_upload_') . '.docx';
    file_put_contents($tempDocxForUpload, $docxContent);
    
    // Use Stirling PDF API to convert DOCX to PDF
    $stirlingPdfUrl = 'https://pdf.adminforge.de/api/v1/convert/file/pdf';
    
    // Prepare the file for upload
    $cfile = new CURLFile($tempDocxForUpload, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'document.docx');
    
    // Initialize cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $stirlingPdfUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, ['fileInput' => $cfile]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    
    // Execute request
    $pdfContent = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    // Clean up temp upload file
    unlink($tempDocxForUpload);
    
    // Check if conversion was successful
    if ($httpCode === 200 && $pdfContent && strlen($pdfContent) > 0) {
        // Store PDF in database
        $stmtStorePdf = $db->prepare("
            UPDATE palasumpaan_temp_docs 
            SET pdf_content = ?, converted_at = NOW() 
            WHERE request_id = ?
        ");
        $stmtStorePdf->execute([$pdfContent, $requestId]);
        
        // Clear ALL output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Output the PDF with proper headers
        $fileName = 'Palasumpaan_' . str_replace(' ', '_', $fullName) . '_' . date('Y-m-d') . '.pdf';
        
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $fileName . '"');
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . strlen($pdfContent));
        
        echo $pdfContent;
        exit;
    } else {
        // Conversion failed
        $errorMsg = "Failed to convert DOCX to PDF. HTTP Code: $httpCode";
        if ($curlError) {
            $errorMsg .= ", cURL Error: $curlError";
        }
        error_log("Stirling PDF conversion error: " . $errorMsg);
        throw new Exception($errorMsg);
    }
    
} catch (Exception $e) {
    error_log("Error generating Palasumpaan: " . $e->getMessage());
    die("Error generating certificate: " . $e->getMessage());
}
