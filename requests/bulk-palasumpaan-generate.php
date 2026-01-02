<?php
/**
 * Bulk Palasumpaan Generator - Backend
 * Generates multiple oath certificates and merges them into one PDF
 */

ob_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpWord\TemplateProcessor;
use Dompdf\Dompdf;
use Dompdf\Options;

Security::requireLogin();

$db = Database::getInstance()->getConnection();
$user = getCurrentUser();

// Get POST data
$requestIds = json_decode($_POST['request_ids'] ?? '[]', true);
$oathDate = $_POST['oath_date'] ?? null;
$oathLokal = $_POST['oath_lokal'] ?? '';
$oathDistrito = $_POST['oath_distrito'] ?? '';

if (empty($requestIds) || !$oathDate) {
    http_response_code(400);
    die('Invalid parameters');
}

// Check permissions
$canManage = in_array($user['role'], ['admin', 'district', 'local']);
if (!$canManage) {
    http_response_code(403);
    die('Access denied');
}

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

// Format oath date
$oathDateObj = new DateTime($oathDate);
$day = $oathDateObj->format('d');
$month = formatMonthTagalog($oathDateObj->format('F'));
$year = $oathDateObj->format('Y');

// Load template path
$templatePath = __DIR__ . '/../PALASUMPAAN_template.docx';
if (!file_exists($templatePath)) {
    http_response_code(500);
    die('Template file not found');
}

try {
    ob_end_clean();
    ob_start();
    
    // Array to store all PDF contents
    $allPdfContent = '';
    
    // Prepare Dompdf options
    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'Arial');
    
    foreach ($requestIds as $requestId) {
        // Fetch request details
        $stmt = $db->prepare("
            SELECT 
                r.*,
                lc.local_name,
                d.district_name,
                o.officer_uuid,
                o.last_name_encrypted as existing_last_name,
                o.first_name_encrypted as existing_first_name,
                o.middle_initial_encrypted as existing_middle_initial,
                o.district_code as existing_district_code
            FROM officer_requests r
            LEFT JOIN local_congregations lc ON r.local_code = lc.local_code
            LEFT JOIN districts d ON r.district_code = d.district_code
            LEFT JOIN officers o ON r.existing_officer_uuid = o.officer_uuid
            WHERE r.request_id = ? AND r.status IN ('ready_to_oath', 'oath_taken')
        ");
        
        $stmt->execute([$requestId]);
        $request = $stmt->fetch();
        
        if (!$request) {
            continue; // Skip if request not found
        }
        
        // Check permissions for this specific request
        if ($user['role'] === 'local' && $request['local_code'] !== $user['local_code']) {
            continue;
        } elseif ($user['role'] === 'district' && $request['district_code'] !== $user['district_code']) {
            continue;
        }
        
        // Decrypt officer name - prefer existing officer data if available
        if (!empty($request['existing_officer_uuid']) && !empty($request['existing_last_name'])) {
            // Use existing officer's encrypted data with their district code
            $districtCode = $request['existing_district_code'] ?? $request['district_code'];
            $nameData = Encryption::decryptOfficerName(
                $request['existing_last_name'],
                $request['existing_first_name'],
                $request['existing_middle_initial'],
                $districtCode
            );
        } elseif (!empty($request['last_name_encrypted'])) {
            // Use request's encrypted data
            $nameData = Encryption::decryptOfficerName(
                $request['last_name_encrypted'],
                $request['first_name_encrypted'],
                $request['middle_initial_encrypted'],
                $request['district_code']
            );
        } else {
            error_log("No name data for request {$requestId}");
            continue;
        }
        
        $fullName = trim(($nameData['first_name'] ?? '') . ' ' . 
                         (($nameData['middle_initial'] ?? '') ? $nameData['middle_initial'] . '. ' : '') . 
                         ($nameData['last_name'] ?? ''));
        
        $duty = $request['requested_duty'] ?: $request['requested_department'];
        
        // Create template processor
        $templateProcessor = new TemplateProcessor($templatePath);
        
        // Replace placeholders
        $templateProcessor->setValue('FULLNAME', strtoupper($fullName));
        $templateProcessor->setValue('LOCAL_NAME', strtoupper($request['local_name']));
        $templateProcessor->setValue('DISTRICT_NAME', 'PAMPANGA EAST');
        $templateProcessor->setValue('DUTY', strtoupper($duty));
        $templateProcessor->setValue('DAY', $day);
        $templateProcessor->setValue('MONTH', $month);
        $templateProcessor->setValue('YEAR', $year);
        $templateProcessor->setValue('OATH_LOKAL', ($oathLokal && $oathLokal !== '[TO BE FILLED]') ? strtoupper($oathLokal) : '____________________');
        $templateProcessor->setValue('OATH_DISTRITO', ($oathDistrito && $oathDistrito !== '[TO BE FILLED]') ? strtoupper($oathDistrito) : '__________________________');
        
        // Save to temp file
        $tempDocx = tempnam(sys_get_temp_dir(), 'palasumpaan_') . '.docx';
        $templateProcessor->saveAs($tempDocx);
        
        // Convert DOCX to HTML (simple conversion for Dompdf)
        // Note: This is a simplified approach. For better results, you might want to use
        // a more sophisticated DOCX to HTML converter or use PHPWord's HTML writer
        
        // Read DOCX and convert to PDF using Stirling PDF
        $docxContent = file_get_contents($tempDocx);
        unlink($tempDocx);
        
        // Create temporary file for upload
        $tempDocxForUpload = tempnam(sys_get_temp_dir(), 'palasumpaan_upload_') . '.docx';
        file_put_contents($tempDocxForUpload, $docxContent);
        
        // Use Stirling PDF API to convert DOCX to PDF
        $stirlingPdfUrl = 'https://pdf.adminforge.de/api/v1/convert/file/pdf';
        
        $cfile = new CURLFile($tempDocxForUpload, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'document.docx');
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $stirlingPdfUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, ['fileInput' => $cfile]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        
        $pdfContent = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        unlink($tempDocxForUpload);
        
        if ($httpCode === 200 && $pdfContent && strlen($pdfContent) > 0) {
            // Append PDF content
            $allPdfContent .= $pdfContent;
        }
    }
    
    // Now merge all PDFs into one using Stirling PDF merge API
    if (!empty($allPdfContent)) {
        // Since we have individual PDFs as strings, we need to merge them
        // For now, we'll use the first approach of using Stirling's merge endpoint
        
        // Create temp files for each PDF
        $tempPdfFiles = [];
        $pdfCount = count($requestIds);
        
        // We need to split the concatenated content back into individual PDFs
        // Since we concatenated raw PDFs, this won't work. Let me fix this approach.
        
        // Better approach: Store each PDF separately and merge them
        $pdfContents = [];
        
        foreach ($requestIds as $index => $requestId) {
            // Re-fetch and generate each PDF properly
            $stmt = $db->prepare("
                SELECT 
                    r.*,
                    lc.local_name,
                    d.district_name,
                    o.last_name_encrypted as existing_last_name,
                    o.first_name_encrypted as existing_first_name,
                    o.middle_initial_encrypted as existing_middle_initial,
                    o.district_code as existing_district_code
                FROM officer_requests r
                LEFT JOIN local_congregations lc ON r.local_code = lc.local_code
                LEFT JOIN districts d ON r.district_code = d.district_code
                LEFT JOIN officers o ON r.existing_officer_uuid = o.officer_uuid
                WHERE r.request_id = ? AND r.status IN ('ready_to_oath', 'oath_taken')
            ");
            
            $stmt->execute([$requestId]);
            $request = $stmt->fetch();
            
            if (!$request) continue;
            
            // Check permissions
            if ($user['role'] === 'local' && $request['local_code'] !== $user['local_code']) continue;
            if ($user['role'] === 'district' && $request['district_code'] !== $user['district_code']) continue;
            
            // Decrypt officer name - prefer existing officer data if available
            if (!empty($request['existing_officer_uuid']) && !empty($request['existing_last_name'])) {
                // Use existing officer's encrypted data with their district code
                $districtCode = $request['existing_district_code'] ?? $request['district_code'];
                $nameData = Encryption::decryptOfficerName(
                    $request['existing_last_name'],
                    $request['existing_first_name'],
                    $request['existing_middle_initial'],
                    $districtCode
                );
            } elseif (!empty($request['last_name_encrypted'])) {
                // Use request's encrypted data
                $nameData = Encryption::decryptOfficerName(
                    $request['last_name_encrypted'],
                    $request['first_name_encrypted'],
                    $request['middle_initial_encrypted'],
                    $request['district_code']
                );
            } else {
                error_log("No name data for request {$requestId} in merge loop");
                continue;
            }
            
            $fullName = trim(($nameData['first_name'] ?? '') . ' ' . 
                             (($nameData['middle_initial'] ?? '') ? $nameData['middle_initial'] . '. ' : '') . 
                             ($nameData['last_name'] ?? ''));
            
            $duty = $request['requested_duty'] ?: $request['requested_department'];
            
            $templateProcessor = new TemplateProcessor($templatePath);
            $templateProcessor->setValue('FULLNAME', strtoupper($fullName));
            $templateProcessor->setValue('LOCAL_NAME', strtoupper($request['local_name']));
            $templateProcessor->setValue('DISTRICT_NAME', 'PAMPANGA EAST');
            $templateProcessor->setValue('DUTY', strtoupper($duty));
            $templateProcessor->setValue('DAY', $day);
            $templateProcessor->setValue('MONTH', $month);
            $templateProcessor->setValue('YEAR', $year);
            $templateProcessor->setValue('OATH_LOKAL', strtoupper($oathLokal) ?: '____________________');
            $templateProcessor->setValue('OATH_DISTRITO', strtoupper($oathDistrito) ?: '__________________________');
            
            $tempDocx = tempnam(sys_get_temp_dir(), 'palasumpaan_') . '.docx';
            $templateProcessor->saveAs($tempDocx);
            $docxContent = file_get_contents($tempDocx);
            unlink($tempDocx);
            
            $tempDocxForUpload = tempnam(sys_get_temp_dir(), 'palasumpaan_') . '.docx';
            file_put_contents($tempDocxForUpload, $docxContent);
            
            $cfile = new CURLFile($tempDocxForUpload, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'document.docx');
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, 'https://pdf.adminforge.de/api/v1/convert/file/pdf');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, ['fileInput' => $cfile]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            
            $pdfContent = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            unlink($tempDocxForUpload);
            
            if ($httpCode === 200 && $pdfContent) {
                $pdfContents[] = $pdfContent;
            }
        }
        
        // Merge PDFs using Stirling PDF
        if (count($pdfContents) > 0) {
            if (count($pdfContents) === 1) {
                // Only one PDF, output directly
                $finalPdf = $pdfContents[0];
            } else {
                // Merge multiple PDFs
                $tempFiles = [];
                $postFields = [];
                
                foreach ($pdfContents as $index => $content) {
                    $tempFile = tempnam(sys_get_temp_dir(), 'pdf_') . '.pdf';
                    file_put_contents($tempFile, $content);
                    $tempFiles[] = $tempFile;
                    $postFields["fileInput[$index]"] = new CURLFile($tempFile, 'application/pdf', "cert_$index.pdf");
                }
                
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, 'https://pdf.adminforge.de/api/v1/general/merge-pdfs');
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 120);
                
                $finalPdf = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                // Clean up temp files
                foreach ($tempFiles as $tempFile) {
                    unlink($tempFile);
                }
                
                if ($httpCode !== 200 || !$finalPdf) {
                    throw new Exception('Failed to merge PDFs');
                }
            }
            
            // Clear all output buffers
            while (ob_get_level()) {
                ob_end_clean();
            }
            
            // Output merged PDF
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="Bulk_Palasumpaan_' . $oathDate . '.pdf"');
            header('Content-Length: ' . strlen($finalPdf));
            header('Cache-Control: private, max-age=0, must-revalidate');
            
            echo $finalPdf;
            exit;
        }
    }
    
    throw new Exception('No valid certificates generated');
    
} catch (Exception $e) {
    error_log("Bulk Palasumpaan error: " . $e->getMessage());
    http_response_code(500);
    die('Error generating certificates: ' . $e->getMessage());
}
