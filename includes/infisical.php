<?php
/**
 * Infisical Key Management Integration
 * Securely fetch encryption keys from Infisical Secret Manager
 */

class InfisicalKeyManager {
    
    private static $cache = [];
    private static $cacheExpiry = 3600; // Cache keys for 1 hour
    
    /**
     * Get Infisical configuration from environment
     */
    private static function getInfisicalConfig() {
        return [
            'host' => getenv('INFISICAL_HOST') ?: 'https://app.infisical.com',
            'client_id' => getenv('INFISICAL_CLIENT_ID'),
            'client_secret' => getenv('INFISICAL_CLIENT_SECRET'),
            'project_id' => getenv('INFISICAL_PROJECT_ID'),
            'environment' => getenv('INFISICAL_ENVIRONMENT') ?: 'production'
        ];
    }
    
    /**
     * Get access token from Infisical
     */
    private static function getAccessToken() {
        $cacheKey = 'infisical_token';
        
        // Check cache
        if (isset(self::$cache[$cacheKey]) && 
            self::$cache[$cacheKey]['expiry'] > time()) {
            return self::$cache[$cacheKey]['token'];
        }
        
        $config = self::getInfisicalConfig();
        
        if (empty($config['client_id']) || empty($config['client_secret'])) {
            throw new Exception('Infisical credentials not configured');
        }
        
        $ch = curl_init($config['host'] . '/api/v1/auth/universal-auth/login');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'clientId' => $config['client_id'],
                'clientSecret' => $config['client_secret']
            ]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json'
            ],
            CURLOPT_TIMEOUT => 10
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception('Failed to authenticate with Infisical');
        }
        
        $data = json_decode($response, true);
        
        if (!isset($data['accessToken'])) {
            throw new Exception('Invalid Infisical response');
        }
        
        // Cache token
        self::$cache[$cacheKey] = [
            'token' => $data['accessToken'],
            'expiry' => time() + 7200 // Token valid for 2 hours
        ];
        
        return $data['accessToken'];
    }
    
    /**
     * Get secret from Infisical
     */
    public static function getSecret($secretName, $path = '/') {
        $cacheKey = "secret_{$secretName}_{$path}";
        
        // Check cache
        if (isset(self::$cache[$cacheKey]) && 
            self::$cache[$cacheKey]['expiry'] > time()) {
            return self::$cache[$cacheKey]['value'];
        }
        
        $config = self::getInfisicalConfig();
        $token = self::getAccessToken();
        
        $url = $config['host'] . '/api/v3/secrets/raw/' . urlencode($secretName) . 
               '?workspaceId=' . $config['project_id'] .
               '&environment=' . $config['environment'] .
               '&secretPath=' . urlencode($path);
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json'
            ],
            CURLOPT_TIMEOUT => 10
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception("Failed to fetch secret: {$secretName}");
        }
        
        $data = json_decode($response, true);
        
        if (!isset($data['secret']['secretValue'])) {
            throw new Exception("Secret not found: {$secretName}");
        }
        
        $secretValue = $data['secret']['secretValue'];
        
        // Cache secret
        self::$cache[$cacheKey] = [
            'value' => $secretValue,
            'expiry' => time() + self::$cacheExpiry
        ];
        
        return $secretValue;
    }
    
    /**
     * Get district encryption key from Infisical
     * Falls back to database if Infisical is not configured
     */
    public static function getDistrictKey($districtCode) {
        // Check if Infisical is configured
        $config = self::getInfisicalConfig();
        
        if (empty($config['client_id']) || empty($config['client_secret'])) {
            // Fallback to database
            return self::getDistrictKeyFromDatabase($districtCode);
        }
        
        try {
            // Try to get from Infisical
            $secretName = "DISTRICT_KEY_{$districtCode}";
            return self::getSecret($secretName, '/encryption-keys');
        } catch (Exception $e) {
            // Log error and fallback to database
            if (APP_ENV !== 'production') {
                error_log("Infisical fetch failed: " . $e->getMessage());
            }
            return self::getDistrictKeyFromDatabase($districtCode);
        }
    }
    
    /**
     * Fallback: Get district key from database
     */
    private static function getDistrictKeyFromDatabase($districtCode) {
        $db = Database::getInstance()->getConnection();
        
        $stmt = $db->prepare("SELECT encryption_key FROM districts WHERE district_code = ?");
        $stmt->execute([$districtCode]);
        $result = $stmt->fetch();
        
        if ($result && !empty($result['encryption_key'])) {
            return $result['encryption_key'];
        }
        
        // Generate new key if not exists
        return self::generateAndStoreDistrictKey($districtCode);
    }
    
    /**
     * Generate and store new district key
     */
    private static function generateAndStoreDistrictKey($districtCode) {
        $key = base64_encode(random_bytes(32));
        
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("UPDATE districts SET encryption_key = ? WHERE district_code = ?");
        $stmt->execute([$key, $districtCode]);
        
        return $key;
    }
    
    /**
     * Store district key in Infisical (admin function)
     */
    public static function storeDistrictKeyInInfisical($districtCode, $key) {
        $config = self::getInfisicalConfig();
        $token = self::getAccessToken();
        
        $secretName = "DISTRICT_KEY_{$districtCode}";
        
        $url = $config['host'] . '/api/v3/secrets/raw/' . urlencode($secretName);
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode([
                'workspaceId' => $config['project_id'],
                'environment' => $config['environment'],
                'secretPath' => '/encryption-keys',
                'secretValue' => $key
            ]),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json'
            ],
            CURLOPT_TIMEOUT => 10
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 && $httpCode !== 201) {
            throw new Exception("Failed to store secret in Infisical");
        }
        
        return true;
    }
    
    /**
     * Clear cache (useful for testing)
     */
    public static function clearCache() {
        self::$cache = [];
    }
    
    /**
     * Test authentication (returns true if successful)
     */
    public static function authenticate() {
        try {
            $token = self::getAccessToken();
            return !empty($token);
        } catch (Exception $e) {
            throw $e;
        }
    }
    
    /**
     * List all secrets in a path
     */
    public static function listSecrets($path = '/') {
        $config = self::getInfisicalConfig();
        $token = self::getAccessToken();
        
        $url = $config['host'] . '/api/v3/secrets/raw' .
               '?workspaceId=' . $config['project_id'] .
               '&environment=' . $config['environment'] .
               '&secretPath=' . urlencode($path);
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json'
            ],
            CURLOPT_TIMEOUT => 10
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception("Failed to list secrets in path: {$path}");
        }
        
        $data = json_decode($response, true);
        
        if (!isset($data['secrets'])) {
            return [];
        }
        
        return $data['secrets'];
    }
    
    /**
     * Store a secret in Infisical
     */
    public static function storeSecret($secretName, $secretValue, $path = '/') {
        $config = self::getInfisicalConfig();
        $token = self::getAccessToken();
        
        $url = $config['host'] . '/api/v3/secrets/raw/' . urlencode($secretName);
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode([
                'workspaceId' => $config['project_id'],
                'environment' => $config['environment'],
                'secretPath' => $path,
                'secretValue' => $secretValue,
                'type' => 'shared'
            ]),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json'
            ],
            CURLOPT_TIMEOUT => 10
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($httpCode !== 200 && $httpCode !== 201) {
            $errorData = json_decode($response, true);
            $errorMsg = isset($errorData['message']) ? $errorData['message'] : 'Unknown error';
            throw new Exception("Failed to store secret: {$errorMsg} (HTTP {$httpCode})");
        }
        
        // Clear cache for this secret
        $cacheKey = "secret_{$secretName}_{$path}";
        unset(self::$cache[$cacheKey]);
        
        return true;
    }
}
