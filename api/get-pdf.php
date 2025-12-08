<?php
/**
 * Retrieve Stored PDF
 * Opens PDF from encrypted database storage
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/pdf-storage.php';

Security::requireLogin();

$currentUser = getCurrentUser();

// Get parameters
$pdfId = isset($_GET['pdf_id']) ? (int)$_GET['pdf_id'] : 0;
$referenceType = $_GET['type'] ?? '';
$referenceUuid = $_GET['uuid'] ?? '';
$token = $_GET['token'] ?? '';

// Validate CSRF token
if (!Security::validateCSRFToken($token)) {
    http_response_code(403);
    echo 'Invalid security token.';
    exit;
}

try {
    $pdfStorage = new PDFStorage();
    
    // Retrieve PDF either by ID or by reference
    if ($pdfId > 0) {
        $pdfData = $pdfStorage->retrievePDF($pdfId, $currentUser['user_id']);
    } elseif (!empty($referenceType) && !empty($referenceUuid)) {
        $pdfData = $pdfStorage->retrievePDFByReference($referenceType, $referenceUuid, $currentUser['user_id']);
    } else {
        throw new Exception('Invalid PDF request parameters');
    }
    
    if (!$pdfData) {
        throw new Exception('PDF not found or access denied');
    }
    
    // Extract clean filename without path
    $filename = basename($pdfData['filename']);
    
    // Set headers for PDF output
    header('Content-Type: ' . $pdfData['mime_type']);
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdfData['content']));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    
    // Output PDF content
    echo $pdfData['content'];
    exit;
    
} catch (Exception $e) {
    error_log("PDF Retrieval Error: " . $e->getMessage());
    http_response_code(404);
    echo 'PDF not found or access denied.';
    exit;
}
