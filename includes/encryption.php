<?php
/**
 * Encryption Class for Officer Names
 */

class Encryption {
    
    /**
     * Get encryption key for district
     * Uses Infisical for secure key management, falls back to database
     */
    private static function getDistrictKey($districtCode) {
        // Check if Infisical integration is available
        if (class_exists('InfisicalKeyManager')) {
            return InfisicalKeyManager::getDistrictKey($districtCode);
        }
        
        // Fallback to database
        $db = Database::getInstance()->getConnection();
        
        $stmt = $db->prepare("SELECT encryption_key FROM districts WHERE district_code = ?");
        $stmt->execute([$districtCode]);
        $result = $stmt->fetch();
        
        if ($result && !empty($result['encryption_key'])) {
            return $result['encryption_key'];
        }
        
        // Generate new key if not exists
        return self::generateDistrictKey($districtCode);
    }
    
    /**
     * Generate and store encryption key for district
     */
    private static function generateDistrictKey($districtCode) {
        $key = self::generateSecureKey();
        
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("UPDATE districts SET encryption_key = ? WHERE district_code = ?");
        $stmt->execute([$key, $districtCode]);
        
        return $key;
    }
    
    /**
     * Generate secure encryption key
     */
    private static function generateSecureKey() {
        return base64_encode(random_bytes(32));
    }
    
    /**
     * Encrypt data using AES-256-GCM (authenticated encryption)
     */
    public static function encrypt($data, $districtCode) {
        if (empty($data)) {
            return '';
        }
        
        $key = self::getDistrictKey($districtCode);
        $nonce = random_bytes(12); // 12 bytes for GCM
        $tag = '';
        
        $encrypted = openssl_encrypt(
            $data,
            'aes-256-gcm', // Use GCM instead of CBC
            base64_decode($key),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            16 // 16-byte authentication tag
        );
        
        if ($encrypted === false) {
            throw new Exception('Encryption failed');
        }
        
        // Combine nonce + tag + encrypted data
        // Format: nonce(12) + tag(16) + ciphertext
        return base64_encode($nonce . $tag . $encrypted);
    }
    
    /**
     * Decrypt data (supports both GCM and legacy CBC, with automatic archived key fallback)
     */
    public static function decrypt($encryptedData, $districtCode) {
        if (empty($encryptedData)) {
            return '';
        }
        
        $key = self::getDistrictKey($districtCode);
        $data = base64_decode($encryptedData);
        
        if ($data === false) {
            return '';
        }
        
        // Try decryption with current key
        $decrypted = self::tryDecryptWithKey($data, $key);
        
        if ($decrypted !== '') {
            return $decrypted;
        }
        
        // If current key fails, try archived keys (for backward compatibility after rotation)
        if (class_exists('InfisicalKeyManager')) {
            try {
                $secrets = InfisicalKeyManager::listSecrets('/encryption-keys/archive');
                
                foreach ($secrets as $secret) {
                    // Look for archived keys matching this district
                    if (strpos($secret['secretKey'], "DISTRICT_KEY_{$districtCode}_") === 0) {
                        try {
                            $archivedKey = InfisicalKeyManager::getSecret($secret['secretKey'], '/encryption-keys/archive');
                            $decrypted = self::tryDecryptWithKey($data, $archivedKey);
                            
                            if ($decrypted !== '') {
                                // Successfully decrypted with archived key
                                return $decrypted;
                            }
                        } catch (Exception $e) {
                            // Continue to next archived key
                            continue;
                        }
                    }
                }
            } catch (Exception $e) {
                // No archived keys or error accessing them
            }
        }
        
        return '';
    }
    
    /**
     * Try to decrypt data with a specific key (supports both GCM and CBC)
     */
    private static function tryDecryptWithKey($data, $key) {
        $dataLength = strlen($data);
        
        // Try GCM decryption first (new format: nonce(12) + tag(16) + ciphertext)
        if ($dataLength >= 28) {
            $nonce = substr($data, 0, 12);
            $tag = substr($data, 12, 16);
            $ciphertext = substr($data, 28);
            
            $decrypted = openssl_decrypt(
                $ciphertext,
                'aes-256-gcm',
                base64_decode($key),
                OPENSSL_RAW_DATA,
                $nonce,
                $tag
            );
            
            if ($decrypted !== false) {
                return $decrypted;
            }
        }
        
        // Fallback to CBC decryption for legacy data
        if ($dataLength > 16) {
            $ivLength = 16;
            $iv = substr($data, 0, $ivLength);
            $encrypted = substr($data, $ivLength);
            
            $decrypted = openssl_decrypt(
                $encrypted,
                'aes-256-cbc',
                base64_decode($key),
                OPENSSL_RAW_DATA,
                $iv
            );
            
            if ($decrypted !== false) {
                return $decrypted;
            }
        }
        
        return '';
    }
    
    /**
     * Encrypt officer name
     */
    public static function encryptOfficerName($lastName, $firstName, $middleInitial, $districtCode) {
        return [
            'last_name_encrypted' => self::encrypt($lastName, $districtCode),
            'first_name_encrypted' => self::encrypt($firstName, $districtCode),
            'middle_initial_encrypted' => self::encrypt($middleInitial, $districtCode)
        ];
    }
    
    /**
     * Decrypt officer name
     */
    public static function decryptOfficerName($encryptedLastName, $encryptedFirstName, $encryptedMiddleInitial, $districtCode) {
        return [
            'last_name' => self::decrypt($encryptedLastName, $districtCode),
            'first_name' => self::decrypt($encryptedFirstName, $districtCode),
            'middle_initial' => self::decrypt($encryptedMiddleInitial, $districtCode)
        ];
    }
    
    /**
     * Get full name (decrypted)
     */
    public static function getFullName($encryptedLastName, $encryptedFirstName, $encryptedMiddleInitial, $districtCode) {
        $decrypted = self::decryptOfficerName($encryptedLastName, $encryptedFirstName, $encryptedMiddleInitial, $districtCode);
        
        $fullName = $decrypted['first_name'];
        if (!empty($decrypted['middle_initial'])) {
            $fullName .= ' ' . $decrypted['middle_initial'] . '.';
        }
        $fullName .= ' ' . $decrypted['last_name'];
        
        return $fullName;
    }
}
