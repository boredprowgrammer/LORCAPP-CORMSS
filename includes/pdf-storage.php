<?php
/**
 * PDF Storage Manager
 * Handles encrypted storage and retrieval of PDF files
 */

class PDFStorage {
    private $db;
    private $encryptionMethod = 'AES-256-CBC';
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Store PDF with encryption
     * 
     * @param string $pdfContent The PDF binary content
     * @param string $fileName Original filename
     * @param string $referenceType Type of document (call_up_slip, palasumpaan, etc.)
     * @param int|null $referenceId Database ID of the related record
     * @param string|null $referenceUuid UUID of the related record
     * @param int $createdBy User ID who created the PDF
     * @return int|false PDF ID on success, false on failure
     */
    public function storePDF($pdfContent, $fileName, $referenceType, $referenceId, $referenceUuid, $createdBy) {
        try {
            // Generate encryption key from application secret
            $encryptionKey = $this->getEncryptionKey();
            
            // Generate random IV
            $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($this->encryptionMethod));
            
            // Encrypt PDF content
            $encryptedPdf = openssl_encrypt($pdfContent, $this->encryptionMethod, $encryptionKey, 0, $iv);
            
            if ($encryptedPdf === false) {
                throw new Exception('PDF encryption failed');
            }
            
            // Calculate checksum for integrity
            $checksum = hash('sha256', $pdfContent);
            
            // Store in database
            $stmt = $this->db->prepare("
                INSERT INTO pdf_files (
                    reference_type, reference_id, reference_uuid, file_name, 
                    file_size, mime_type, encrypted_pdf, encryption_iv, 
                    encryption_method, checksum, created_by
                ) VALUES (?, ?, ?, ?, ?, 'application/pdf', ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $referenceType,
                $referenceId,
                $referenceUuid,
                $fileName,
                strlen($pdfContent),
                $encryptedPdf,
                base64_encode($iv),
                $this->encryptionMethod,
                $checksum,
                $createdBy
            ]);
            
            return $this->db->lastInsertId();
            
        } catch (Exception $e) {
            error_log("PDF Storage Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Retrieve and decrypt PDF
     * 
     * @param int $pdfId PDF file ID
     * @param int $userId User requesting the PDF (for access control)
     * @return array|false Array with 'content', 'filename', 'mime_type' on success, false on failure
     */
    public function retrievePDF($pdfId, $userId) {
        try {
            // Get PDF record
            $stmt = $this->db->prepare("
                SELECT pdf_id, encrypted_pdf, encryption_iv, encryption_method, 
                       file_name, mime_type, checksum, reference_type, reference_id
                FROM pdf_files 
                WHERE pdf_id = ?
            ");
            $stmt->execute([$pdfId]);
            $pdf = $stmt->fetch();
            
            if (!$pdf) {
                throw new Exception('PDF not found');
            }
            
            // TODO: Add permission check based on reference_type and user role
            
            // Decrypt PDF
            $encryptionKey = $this->getEncryptionKey();
            $iv = base64_decode($pdf['encryption_iv']);
            
            $decryptedPdf = openssl_decrypt(
                $pdf['encrypted_pdf'], 
                $pdf['encryption_method'], 
                $encryptionKey, 
                0, 
                $iv
            );
            
            if ($decryptedPdf === false) {
                throw new Exception('PDF decryption failed');
            }
            
            // Verify integrity
            $checksum = hash('sha256', $decryptedPdf);
            if ($checksum !== $pdf['checksum']) {
                error_log("PDF integrity check failed for PDF ID: $pdfId");
                throw new Exception('PDF integrity verification failed');
            }
            
            // Update access tracking
            $this->updateAccessTracking($pdfId);
            
            return [
                'content' => $decryptedPdf,
                'filename' => $pdf['file_name'],
                'mime_type' => $pdf['mime_type']
            ];
            
        } catch (Exception $e) {
            error_log("PDF Retrieval Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Retrieve PDF by reference
     * 
     * @param string $referenceType Type of document
     * @param string $referenceUuid UUID of the related record
     * @param int $userId User requesting the PDF
     * @return array|false PDF data on success, false on failure
     */
    public function retrievePDFByReference($referenceType, $referenceUuid, $userId) {
        try {
            $stmt = $this->db->prepare("
                SELECT pdf_id 
                FROM pdf_files 
                WHERE reference_type = ? AND reference_uuid = ?
                ORDER BY created_at DESC
                LIMIT 1
            ");
            $stmt->execute([$referenceType, $referenceUuid]);
            $result = $stmt->fetch();
            
            if (!$result) {
                return false;
            }
            
            return $this->retrievePDF($result['pdf_id'], $userId);
            
        } catch (Exception $e) {
            error_log("PDF Retrieval by Reference Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if PDF exists for reference
     * 
     * @param string $referenceType Type of document
     * @param string $referenceUuid UUID of the related record
     * @return int|false PDF ID if exists, false otherwise
     */
    public function pdfExists($referenceType, $referenceUuid) {
        try {
            $stmt = $this->db->prepare("
                SELECT pdf_id 
                FROM pdf_files 
                WHERE reference_type = ? AND reference_uuid = ?
                ORDER BY created_at DESC
                LIMIT 1
            ");
            $stmt->execute([$referenceType, $referenceUuid]);
            $result = $stmt->fetch();
            
            return $result ? (int)$result['pdf_id'] : false;
            
        } catch (Exception $e) {
            error_log("PDF Exists Check Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Delete PDF
     * 
     * @param int $pdfId PDF file ID
     * @return bool Success status
     */
    public function deletePDF($pdfId) {
        try {
            $stmt = $this->db->prepare("DELETE FROM pdf_files WHERE pdf_id = ?");
            return $stmt->execute([$pdfId]);
        } catch (Exception $e) {
            error_log("PDF Deletion Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update access tracking
     * 
     * @param int $pdfId PDF file ID
     */
    private function updateAccessTracking($pdfId) {
        try {
            $stmt = $this->db->prepare("
                UPDATE pdf_files 
                SET last_accessed = NOW(), access_count = access_count + 1
                WHERE pdf_id = ?
            ");
            $stmt->execute([$pdfId]);
        } catch (Exception $e) {
            error_log("Access tracking update error: " . $e->getMessage());
        }
    }
    
    /**
     * Get encryption key from application configuration
     * 
     * @return string Encryption key
     */
    private function getEncryptionKey() {
        // Use the application's MASTER_KEY constant (loaded from Infisical or .env)
        if (!defined('MASTER_KEY')) {
            throw new Exception('Encryption key not configured');
        }
        
        $key = MASTER_KEY;
        
        if (empty($key)) {
            throw new Exception('Encryption key not configured');
        }
        
        // Ensure key is proper length for AES-256
        return hash('sha256', $key, true);
    }
    
    /**
     * Get PDF metadata
     * 
     * @param int $pdfId PDF file ID
     * @return array|false PDF metadata on success, false on failure
     */
    public function getPDFMetadata($pdfId) {
        try {
            $stmt = $this->db->prepare("
                SELECT pdf_id, reference_type, reference_id, reference_uuid, 
                       file_name, file_size, mime_type, created_at, 
                       last_accessed, access_count, created_by
                FROM pdf_files 
                WHERE pdf_id = ?
            ");
            $stmt->execute([$pdfId]);
            return $stmt->fetch();
        } catch (Exception $e) {
            error_log("PDF Metadata Error: " . $e->getMessage());
            return false;
        }
    }
}
