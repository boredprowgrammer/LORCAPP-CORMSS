<?php
/**
 * LORCAPP
 * Encryption Helper Functions for ID Numbers
 */

// Prevent direct access
if (count(get_included_files()) == 1) exit("Direct access forbidden");

// Define OPENSSL_RAW_OUTPUT if not available (PHP < 5.4)
if (!defined('OPENSSL_RAW_OUTPUT')) {
    define('OPENSSL_RAW_OUTPUT', 1);
}

/**
 * Encrypt a value using AES-256-GCM (authenticated encryption)
 * Returns base64 encoded string with IV, tag, and encrypted data
 * Format: base64(nonce + tag + ciphertext)
 */
function encryptValue($value) {
    if (empty($value)) {
        return null;
    }
    
    // Get encryption key from environment - MUST be set, no fallback
    // Try multiple sources: getenv, $_ENV, $_SERVER
    $key = getenv('LORCAPP_ENCRYPTION_KEY') ?: ($_ENV['LORCAPP_ENCRYPTION_KEY'] ?? ($_SERVER['LORCAPP_ENCRYPTION_KEY'] ?? null));
    
    if (!$key || empty($key)) {
        error_log('CRITICAL SECURITY ERROR: ENCRYPTION_KEY environment variable not set');
        throw new Exception('Encryption key not configured. Please contact system administrator.');
    }
    $key = substr(hash('sha256', $key, true), 0, 32); // Ensure 32 bytes for AES-256
    
    // Generate a random nonce/IV (12 bytes for GCM)
    $nonce = openssl_random_pseudo_bytes(12);
    
    // Encrypt the value with GCM mode (provides authentication)
    $tag = '';
    $encrypted = openssl_encrypt($value, 'aes-256-gcm', $key, OPENSSL_RAW_OUTPUT, $nonce, $tag);
    
    if ($encrypted === false) {
        error_log('Encryption failed: ' . openssl_error_string());
        throw new Exception('Encryption failed');
    }
    
    // Combine nonce + tag + ciphertext and encode
    // Format: nonce(12) + tag(16) + ciphertext(variable)
    return base64_encode($nonce . $tag . $encrypted);
}

/**
 * Decrypt a value encrypted with encryptValue()
 * Accepts base64 encoded string with nonce, tag, and ciphertext
 * Format: base64(nonce + tag + ciphertext)
 * 
 * Also supports legacy CBC-encrypted data for backward compatibility
 */
function decryptValue($encryptedValue) {
    if (empty($encryptedValue)) {
        return null;
    }
    
    try {
        // Get encryption key (same as encryption) - MUST be set, no fallback
        // Try multiple sources: getenv, $_ENV, $_SERVER
    $key = getenv('LORCAPP_ENCRYPTION_KEY') ?: ($_ENV['LORCAPP_ENCRYPTION_KEY'] ?? ($_SERVER['LORCAPP_ENCRYPTION_KEY'] ?? null));
        
        if (!$key || empty($key)) {
            error_log('CRITICAL SECURITY ERROR: ENCRYPTION_KEY environment variable not set');
            throw new Exception('Encryption key not configured. Please contact system administrator.');
        }
        $key = substr(hash('sha256', $key, true), 0, 32);
        
        // Decode the base64 string
        $data = base64_decode($encryptedValue);
        
        if ($data === false) {
            error_log('Decryption failed: Invalid base64 encoding');
            return null;
        }
        
        $dataLength = strlen($data);
        
        // Try GCM decryption first (new format: nonce(12) + tag(16) + ciphertext)
        if ($dataLength >= 28) { // Minimum: 12 + 16 + some data
            $nonce = substr($data, 0, 12);
            $tag = substr($data, 12, 16);
            $ciphertext = substr($data, 28);
            
            // Attempt GCM decryption
            $decrypted = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_OUTPUT, $nonce, $tag);
            
            if ($decrypted !== false) {
                return $decrypted;
            }
        }
        
        // Fallback to CBC decryption for legacy data (IV(16) + ciphertext)
        if ($dataLength > 16) {
            $ivLength = 16; // AES-256-CBC uses 16-byte IV
            $iv = substr($data, 0, $ivLength);
            $encrypted = substr($data, $ivLength);
            
            // Attempt CBC decryption (legacy format)
            $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', $key, 0, $iv);
            
            if ($decrypted !== false) {
                // Successfully decrypted legacy CBC data
                return $decrypted;
            }
        }
        
        // Decryption failed
        return null;
        
    } catch (Exception $e) {
        error_log("Decryption failed: " . $e->getMessage());
        return null;
    }
}

/**
 * Decrypt name fields from a database record
 * Returns the record with decrypted name fields
 * @param array $record Database record array
 * @return array Record with decrypted names
 */
function decryptRecordNames($record) {
    if (!is_array($record)) {
        return $record;
    }
    
    $nameFields = ['given_name', 'mother_surname', 'father_surname', 'husband_surname'];
    
    foreach ($nameFields as $field) {
        if (isset($record[$field]) && !empty($record[$field])) {
            $decrypted = decryptValue($record[$field]);
            // Only replace if decryption succeeded
            if ($decrypted !== null && $decrypted !== false) {
                $record[$field] = $decrypted;
            } else {
                // If decryption fails, it might be unencrypted legacy data
                // Leave it as is but log for monitoring
                error_log("Warning: Could not decrypt {$field} for record. Might be legacy unencrypted data.");
            }
        }
    }
    
    return $record;
}

/**
 * Encrypt name fields for a record before saving
 * @param array $data Data array with name fields
 * @return array Data with encrypted names
 */
function encryptRecordNames($data) {
    if (!is_array($data)) {
        return $data;
    }
    
    $nameFields = ['given_name', 'mother_surname', 'father_surname', 'husband_surname'];
    
    foreach ($nameFields as $field) {
        if (isset($data[$field]) && !empty($data[$field])) {
            $data[$field] = encryptValue($data[$field]);
        }
    }
    
    return $data;
}
