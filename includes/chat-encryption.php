<?php
/**
 * Chat Encryption Helper
 * Fast encryption/decryption for chat messages using AES-256-GCM
 */

class ChatEncryption {
    
    /**
     * Encrypt a chat message
     * Uses AES-256-GCM for authenticated encryption (fast and secure)
     * 
     * @param string $message Plain text message
     * @param int $conversationId Conversation ID for key derivation
     * @return array ['encrypted' => string, 'key_hash' => string]
     */
    public static function encryptMessage($message, $conversationId) {
        // Derive encryption key from conversation ID and system secret
        $key = self::deriveKey($conversationId);
        
        // Generate random IV (96 bits for GCM)
        $iv = random_bytes(12);
        
        // Encrypt using AES-256-GCM (fastest authenticated encryption)
        $ciphertext = openssl_encrypt(
            $message,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        
        if ($ciphertext === false) {
            throw new Exception('Encryption failed');
        }
        
        // Combine IV + ciphertext + tag for storage
        $encrypted = base64_encode($iv . $ciphertext . $tag);
        
        // Generate key hash for verification
        $keyHash = hash('sha256', $key);
        
        return [
            'encrypted' => $encrypted,
            'key_hash' => $keyHash
        ];
    }
    
    /**
     * Decrypt a chat message
     * 
     * @param string $encryptedData Base64 encoded encrypted data
     * @param int $conversationId Conversation ID for key derivation
     * @return string Decrypted message
     */
    public static function decryptMessage($encryptedData, $conversationId) {
        try {
            // Derive decryption key
            $key = self::deriveKey($conversationId);
            
            // Decode base64
            $data = base64_decode($encryptedData);
            
            if ($data === false || strlen($data) < 29) { // 12 (IV) + 16 (tag) + 1 (min data)
                throw new Exception('Invalid encrypted data format');
            }
            
            // Extract IV (first 12 bytes)
            $iv = substr($data, 0, 12);
            
            // Extract tag (last 16 bytes)
            $tag = substr($data, -16);
            
            // Extract ciphertext (middle portion)
            $ciphertext = substr($data, 12, -16);
            
            // Decrypt using AES-256-GCM
            $plaintext = openssl_decrypt(
                $ciphertext,
                'aes-256-gcm',
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );
            
            if ($plaintext === false) {
                throw new Exception('Decryption failed - data may be corrupted');
            }
            
            return $plaintext;
            
        } catch (Exception $e) {
            error_log("Chat decryption error: " . $e->getMessage());
            return '[Message could not be decrypted]';
        }
    }
    
    /**
     * Derive encryption key from conversation ID
     * Uses HKDF for key derivation (fast and secure)
     * 
     * @param int $conversationId
     * @return string Binary key (32 bytes)
     */
    private static function deriveKey($conversationId) {
        // Get system master key - try Infisical first, then environment/config
        try {
            require_once __DIR__ . '/infisical.php';
            $masterKey = InfisicalKeyManager::getSecret('CHAT_MASTER_KEY');
        } catch (Exception $e) {
            // Fallback to environment or constant
            $masterKey = getenv('CHAT_MASTER_KEY');
            if (empty($masterKey) && defined('CHAT_MASTER_KEY')) {
                $masterKey = CHAT_MASTER_KEY;
            }
        }
        
        if (empty($masterKey)) {
            throw new Exception('CHAT_MASTER_KEY not available');
        }
        
        // Use HKDF to derive conversation-specific key
        $info = 'chat_conversation_' . $conversationId;
        $salt = hash('sha256', 'chat_salt_' . $conversationId, true);
        
        // Derive 32-byte key using HKDF
        $key = hash_hkdf('sha256', $masterKey, 32, $info, $salt);
        
        return $key;
    }
    
    /**
     * Encrypt file name for attachments
     */
    public static function encryptFileName($filename, $conversationId) {
        $result = self::encryptMessage($filename, $conversationId);
        return $result['encrypted'];
    }
    
    /**
     * Decrypt file name from attachments
     */
    public static function decryptFileName($encryptedName, $conversationId) {
        return self::decryptMessage($encryptedName, $conversationId);
    }
    
    /**
     * Generate key hash for verification
     */
    public static function generateKeyHash($conversationId) {
        $key = self::deriveKey($conversationId);
        return hash('sha256', $key);
    }
    
    /**
     * Verify key hash matches
     */
    public static function verifyKeyHash($keyHash, $conversationId) {
        return hash_equals($keyHash, self::generateKeyHash($conversationId));
    }
}
