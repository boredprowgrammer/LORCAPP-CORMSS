<?php
/**
 * Approve CFO Access Request with PDF Upload
 */

require_once __DIR__ . '/../config/config.php';

Security::requireLogin();

$currentUser = getCurrentUser();

// Only admin and local accounts can approve requests
if ($currentUser['role'] !== 'admin' && $currentUser['role'] !== 'local') {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied. Only admin and local accounts can approve requests.']);
    exit;
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

// Check if this is a rejection request (JSON body)
$jsonInput = file_get_contents('php://input');
if ($jsonInput && strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $data = json_decode($jsonInput, true);
    
    if (isset($data['action']) && $data['action'] === 'reject') {
        try {
            $requestId = intval($data['request_id'] ?? 0);
            $rejectionReason = Security::sanitizeInput($data['rejection_reason'] ?? '');
            
            if (!$requestId) {
                throw new Exception('Invalid request ID');
            }
            
            if (empty($rejectionReason)) {
                throw new Exception('Rejection reason is required');
            }
            
            // Verify request exists and is pending
            $stmt = $db->prepare("
                SELECT * FROM cfo_access_requests 
                WHERE id = ? AND status = 'pending' AND deleted_at IS NULL
            ");
            $stmt->execute([$requestId]);
            $request = $stmt->fetch();
            
            if (!$request) {
                throw new Exception('Request not found or already processed');
            }
            
            // Admin can reject any request, local can only reject from their congregation
            if ($currentUser['role'] !== 'admin' && $request['requester_local_code'] !== $currentUser['local_code']) {
                throw new Exception('You can only reject requests from your local congregation');
            }
            
            // Update request status to rejected
            $stmt = $db->prepare("
                UPDATE cfo_access_requests 
                SET status = 'rejected',
                    approver_user_id = ?,
                    approval_date = NOW(),
                    approval_notes = ?
                WHERE id = ?
            ");
            $stmt->execute([$currentUser['user_id'], $rejectionReason, $requestId]);
            
            echo json_encode(['success' => true, 'message' => 'Request rejected']);
            exit;
            
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        }
    }
}

// Handle approval with PDF upload
try {
    $requestId = intval($_POST['request_id'] ?? 0);
    $approvalNotes = Security::sanitizeInput($_POST['approval_notes'] ?? '');
    $seniorUserId = null;
    
    // If admin is approving, they must specify which senior account approves
    if ($currentUser['role'] === 'admin') {
        $seniorUserId = intval($_POST['senior_user_id'] ?? 0);
        if (!$seniorUserId) {
            throw new Exception('Senior approver must be selected');
        }
        
        // Verify the senior user exists and is a local account
        $stmt = $db->prepare("SELECT * FROM users WHERE user_id = ? AND role = 'local' AND is_active = 1");
        $stmt->execute([$seniorUserId]);
        $seniorUser = $stmt->fetch();
        
        if (!$seniorUser) {
            throw new Exception('Invalid senior approver selected');
        }
    }
    
    if (!$requestId) {
        throw new Exception('Invalid request ID');
    }
    
    // Verify request exists and is pending
    $stmt = $db->prepare("
        SELECT * FROM cfo_access_requests 
        WHERE id = ? AND status = 'pending' AND deleted_at IS NULL
    ");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();
    
    if (!$request) {
        throw new Exception('Request not found or already processed');
    }
    
    // Verify the request is for appropriate local
    if ($currentUser['role'] === 'local') {
        // Local accounts can only approve from their congregation
        if ($request['requester_local_code'] !== $currentUser['local_code']) {
            throw new Exception('You can only approve requests from your local congregation');
        }
        $approverUserId = $currentUser['user_id'];
    } else {
        // Admin is assigning to a senior user
        if ($request['requester_local_code'] !== $seniorUser['local_code']) {
            throw new Exception('Selected senior must be from the same local as the requester');
        }
        $approverUserId = $seniorUserId;
    }
    
    // Handle PDF upload
    if (!isset($_FILES['pdf_file']) || $_FILES['pdf_file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('PDF file is required');
    }
    
    $file = $_FILES['pdf_file'];
    
    // Validate file type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if ($mimeType !== 'application/pdf') {
        throw new Exception('Only PDF files are allowed');
    }
    
    // Validate file size (max 10MB)
    if ($file['size'] > 10 * 1024 * 1024) {
        throw new Exception('PDF file size must not exceed 10MB');
    }
    
    // Apply watermark using Stirling PDF API
    $watermarkedPdfContent = applyWatermarkToPdf($file['tmp_name']);
    
    // If watermarking fails, use original PDF as fallback
    if (!$watermarkedPdfContent) {
        error_log("Watermarking failed for request ID: $requestId, using original PDF");
        $watermarkedPdfContent = file_get_contents($file['tmp_name']);
    }
    
    // Store PDF in database
    $stmt = $db->prepare("
        UPDATE cfo_access_requests 
        SET status = 'approved',
            approver_user_id = ?,
            approval_date = NOW(),
            approval_notes = ?,
            pdf_file = ?,
            pdf_filename = ?,
            pdf_mime_type = ?,
            pdf_size = ?
        WHERE id = ?
    ");
    
    $stmt->execute([
        $approverUserId,
        $approvalNotes,
        $watermarkedPdfContent,
        $file['name'],
        $mimeType,
        strlen($watermarkedPdfContent),
        $requestId
    ]);
    
    // Log the action
    secureLog("CFO access request approved", [
        'request_id' => $requestId,
        'approver_id' => $approverUserId,
        'assigned_by' => $currentUser['user_id'],
        'cfo_type' => $request['cfo_type']
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Access request approved and PDF uploaded successfully'
    ]);
    
} catch (Exception $e) {
    error_log("Error in approve-cfo-access.php: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * Apply watermark to PDF using Stirling PDF API
 */
function applyWatermarkToPdf($pdfFilePath) {
    try {
        $apiUrl = 'https://pdf.adminforge.de/api/v1/security/add-watermark';
        
        // Read the PDF file
        $pdfContent = file_get_contents($pdfFilePath);
        
        // Prepare multipart form data
        $boundary = '----WebKitFormBoundary' . uniqid();
        $eol = "\r\n";
        
        $body = '';
        
        // Add PDF file
        $body .= '--' . $boundary . $eol;
        $body .= 'Content-Disposition: form-data; name="fileInput"; filename="document.pdf"' . $eol;
        $body .= 'Content-Type: application/pdf' . $eol . $eol;
        $body .= $pdfContent . $eol;
        
        // Add watermark parameters
        $params = [
            'watermarkType' => 'text',
            'watermarkText' => 'CONFIDENTIAL',
            'fontSize' => '12',
            'rotation' => '45',
            'opacity' => '0.5',
            'widthSpacer' => '85',
            'heightSpacer' => '85',
            'alphabet' => 'roman',
            'convertPDFToImage' => 'false',
            'customColor' => '#d3d3d3'

        ];
        
        foreach ($params as $key => $value) {
            $body .= '--' . $boundary . $eol;
            $body .= 'Content-Disposition: form-data; name="' . $key . '"' . $eol . $eol;
            $body .= $value . $eol;
        }
        
        $body .= '--' . $boundary . '--' . $eol;
        
        // Make API request
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: multipart/form-data; boundary=' . $boundary,
            'Content-Length: ' . strlen($body)
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120); // Increased timeout to 120 seconds
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        error_log("Calling Stirling PDF API: $apiUrl");
        error_log("Request body size: " . strlen($body) . " bytes");
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        
        error_log("Stirling PDF API response: HTTP $httpCode");
        if ($curlError) {
            error_log("CURL error ($curlErrno): $curlError");
        }
        if ($response) {
            error_log("Response size: " . strlen($response) . " bytes");
            if ($httpCode !== 200) {
                error_log("Error response body: " . substr($response, 0, 1000)); // Log first 1000 chars
            }
        } else {
            error_log("No response received from Stirling PDF API");
        }
        
        curl_close($ch);
        
        if ($httpCode === 200 && $response) {
            return $response;
        }
        
        error_log("Stirling PDF API error: HTTP $httpCode");
        return false;
        
    } catch (Exception $e) {
        error_log("Watermark error: " . $e->getMessage());
        return false;
    }
}
