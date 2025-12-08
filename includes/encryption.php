<?php
/**
 * Encryption Class for Officer Names
 */

class Encryption {
    
    /**
     * Get encryption key for district
     */
    private static function getDistrictKey($districtCode) {
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
     * Encrypt data
     */
    public static function encrypt($data, $districtCode) {
        if (empty($data)) {
            return '';
        }
        
        $key = self::getDistrictKey($districtCode);
        $iv = random_bytes(openssl_cipher_iv_length(ENCRYPTION_METHOD));
        
        $encrypted = openssl_encrypt(
            $data,
            ENCRYPTION_METHOD,
            base64_decode($key),
            OPENSSL_RAW_DATA,
            $iv
        );
        
        // Combine IV and encrypted data
        return base64_encode($iv . $encrypted);
    }
    
    /**
     * Decrypt data
     */
    public static function decrypt($encryptedData, $districtCode) {
        if (empty($encryptedData)) {
            return '';
        }
        
        $key = self::getDistrictKey($districtCode);
        $data = base64_decode($encryptedData);
        
        $ivLength = openssl_cipher_iv_length(ENCRYPTION_METHOD);
        $iv = substr($data, 0, $ivLength);
        $encrypted = substr($data, $ivLength);
        
        $decrypted = openssl_decrypt(
            $encrypted,
            ENCRYPTION_METHOD,
            base64_decode($key),
            OPENSSL_RAW_DATA,
            $iv
        );
        
        return $decrypted;
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
