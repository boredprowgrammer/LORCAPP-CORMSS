<?php
/**
 * Generate R5-13 (Form 513) - Certificate of Seminar Attendance
 * Similar to palasumpaan generator, uses PHPWord and Stirling PDF
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpWord\TemplateProcessor;

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
    
    // TODO: Load R5-13 template (needs to be created as DOCX)
    // For now, generate a simple text-based certificate
    $templatePath = __DIR__ . '/R5-13_template.docx';
    
    if (!file_exists($templatePath)) {
        throw new Exception('R5-13 template not found. Please create R5-13_template.docx');
    }
    
    // Load template
    $templateProcessor = new TemplateProcessor($templatePath);
    
    // Replace placeholders with blank if not set
    $templateProcessor->setValue('DISTRICT_NAME', $request['district_name'] ?? '');
    $templateProcessor->setValue('DISTRICT_CODE', $request['district_code'] ?? '');
    $templateProcessor->setValue('LOCAL_NAME', $request['local_name'] ?? '');
    $templateProcessor->setValue('LOCAL_CODE', $request['local_code'] ?? '');
    $templateProcessor->setValue('FULLNAME', $fullName ?? '');
    $templateProcessor->setValue('DUTY', $request['requested_duty'] ?? '');
    $templateProcessor->setValue('REQUEST_CLASS', $request['request_class'] ?? '');
    $templateProcessor->setValue('SEMINAR_DAYS', $requiredDays ?? '');
    
    // Current date for certificate
    $now = new DateTime();
    $templateProcessor->setValue('BUWAN', $now->format('F'));
    $templateProcessor->setValue('ARAW', $now->format('d'));
    $templateProcessor->setValue('TAON', $now->format('Y'));
    $templateProcessor->setValue('DATE_TODAY', $now->format('F d, Y'));
    
    // Add seminar dates - handle both 8 lessons and 33 lessons
    $maxDays = ($request['request_class'] === '33_lessons') ? 30 : 8;
    
    for ($i = 1; $i <= 30; $i++) {
        $date = $seminarDates[$i - 1] ?? null;
        if ($date && isset($date['date']) && $i <= $maxDays) {
            $dt = new DateTime($date['date']);
            $templateProcessor->setValue("SEMINAR{$i}_DATE", $dt->format('F d, Y'));
            $templateProcessor->setValue("SEMINAR{$i}_TOPIC", $date['topic'] ?? '');
            $templateProcessor->setValue("SEMINAR{$i}_NOTES", $date['notes'] ?? '');
        } else {
            // Set blank for unused or missing dates
            $templateProcessor->setValue("SEMINAR{$i}_DATE", '');
            $templateProcessor->setValue("SEMINAR{$i}_TOPIC", '');
            $templateProcessor->setValue("SEMINAR{$i}_NOTES", '');
        }
    }
    
    // Save DOCX to temporary file
    $tempDocxPath = sys_get_temp_dir() . '/r513_' . $requestId . '_' . time() . '.docx';
    $templateProcessor->saveAs($tempDocxPath);
    
    // Convert to PDF using Stirling PDF API
    $stirlingApiUrl = 'https://pdf.adminforge.de/api/v1/convert/file/pdf';
    
    $cFile = new CURLFile($tempDocxPath, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'r513.docx');
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $stirlingApiUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, ['fileInput' => $cFile]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    
    $pdfContent = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // Clean up temp DOCX
    @unlink($tempDocxPath);
    
    if ($httpCode !== 200 || empty($pdfContent)) {
        throw new Exception('PDF conversion failed');
    }
    
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
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
