<?php
/**
 * Store PDF to Encrypted Database
 * Receives PDF from client, encrypts it, and stores in database
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/pdf-storage.php';

header('Content-Type: application/json');

Security::requireLogin();

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['csrf_token']) || !Security::validateCSRFToken($input['csrf_token'])) {
        throw new Exception('Invalid security token');
    }
    
    $pdfBase64 = $input['pdf_data'] ?? '';
    $slipId = isset($input['slip_id']) ? (int)$input['slip_id'] : 0;
    $fileNumber = $input['file_number'] ?? 'callup-slip';
    
    if (empty($pdfBase64)) {
        throw new Exception('No PDF data provided');
    }
    
    if ($slipId === 0) {
        throw new Exception('Invalid slip ID');
    }
    
    // Verify access to this call-up slip
    $stmt = $db->prepare("
        SELECT slip_id, file_number, local_code, district_code 
        FROM call_up_slips 
        WHERE slip_id = ?
    ");
    $stmt->execute([$slipId]);
    $slip = $stmt->fetch();
    
    if (!$slip) {
        throw new Exception('Call-up slip not found');
    }
    
    // Check access permissions
    if (($currentUser['role'] === 'local' || $currentUser['role'] === 'local_limited') && $slip['local_code'] !== $currentUser['local_code']) {
        throw new Exception('Access denied');
    } elseif ($currentUser['role'] === 'district' && $slip['district_code'] !== $currentUser['district_code']) {
        throw new Exception('Access denied');
    }
    
    // Decode base64 PDF data
    // Remove data URI prefix if present (data:application/pdf;base64,)
    if (strpos($pdfBase64, 'data:') === 0) {
        $pdfBase64 = substr($pdfBase64, strpos($pdfBase64, ',') + 1);
    }
    
    $pdfContent = base64_decode($pdfBase64);
    
    if ($pdfContent === false || empty($pdfContent)) {
        throw new Exception('Invalid PDF data');
    }
    
    // Verify it's actually a PDF
    if (substr($pdfContent, 0, 4) !== '%PDF') {
        throw new Exception('Invalid PDF format');
    }
    
    // Check if PDF already exists for this slip
    $pdfStorage = new PDFStorage();
    $existingPdfId = $pdfStorage->pdfExists('call_up_slip', (string)$slipId);
    
    if ($existingPdfId) {
        // Delete old PDF
        $pdfStorage->deletePDF($existingPdfId);
    }
    
    // Store PDF
    $fileName = 'CallUp_' . $fileNumber . '_' . date('Ymd') . '.pdf';
    $pdfId = $pdfStorage->storePDF(
        $pdfContent,
        $fileName,
        'call_up_slip',
        $slipId,
        (string)$slipId,
        $currentUser['user_id']
    );
    
    if (!$pdfId) {
        throw new Exception('Failed to store PDF');
    }
    
    // Update call_up_slips table with PDF reference
    $stmt = $db->prepare("
        UPDATE call_up_slips 
        SET pdf_file_id = ?
        WHERE slip_id = ?
    ");
    $stmt->execute([$pdfId, $slipId]);
    
    // Log success
    error_log("PDF stored successfully: ID=$pdfId, SlipID=$slipId, Size=" . strlen($pdfContent) . " bytes");
    
    echo json_encode([
        'success' => true,
        'pdf_id' => $pdfId,
        'message' => 'PDF stored successfully'
    ]);
    
} catch (Exception $e) {
    error_log("Store PDF Error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
