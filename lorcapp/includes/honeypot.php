<?php
/**
 * LORCAPP - Honeypot System
 * Invisible fields and links to trap bots
 */

class Honeypot {
    
    /**
     * Generate honeypot HTML fields
     * These are invisible to humans but will be filled by bots
     */
    public static function generateFields() {
        $timestamp = time();
        $token = self::generateToken();
        
        return <<<HTML
        
        <!-- Honeypot Fields (Hidden from humans, visible to bots) -->
        <div style="position:absolute;left:-9999px;top:-9999px;" aria-hidden="true" tabindex="-1">
            <!-- Time-based honeypot -->
            <input type="hidden" name="timestamp" value="{$timestamp}">
            <input type="hidden" name="hp_token" value="{$token}">
            
            <!-- Field honeypots (bots will fill these) -->
            <input type="text" name="website" placeholder="Your website" autocomplete="off">
            <input type="text" name="company" placeholder="Company" autocomplete="off">
            <input type="email" name="secondary_email" placeholder="Secondary Email" autocomplete="off">
            <input type="tel" name="office_phone" placeholder="Office Phone" autocomplete="off">
            
            <!-- Checkbox honeypot -->
            <label>
                <input type="checkbox" name="subscribe_newsletter" value="1">
                Subscribe to newsletter
            </label>
            
            <!-- Select honeypot -->
            <select name="country_code">
                <option value="">Select Country</option>
                <option value="US">United States</option>
                <option value="UK">United Kingdom</option>
            </select>
        </div>
        
        <!-- Honeypot link (bots will click, humans won't see) -->
        <a href="/admin/secret-admin-login.php" style="position:absolute;left:-9999px;top:-9999px;" aria-hidden="true" tabindex="-1">Admin Login</a>
        
HTML;
    }
    
    /**
     * Validate honeypot submission
     * Returns true if submission is from a human, false if bot detected
     */
    public static function validate() {
        // Check if honeypot fields are filled (bot behavior)
        $honeypotFields = ['website', 'company', 'secondary_email', 'office_phone', 'country_code'];
        
        foreach ($honeypotFields as $field) {
            if (!empty($_POST[$field])) {
                self::logBot('Honeypot field filled: ' . $field);
                return false; // Bot detected
            }
        }
        
        // Check newsletter checkbox (should be unchecked)
        if (!empty($_POST['subscribe_newsletter'])) {
            self::logBot('Honeypot checkbox checked');
            return false;
        }
        
        // Check submission time (too fast = bot)
        if (!empty($_POST['timestamp'])) {
            $submitTime = time();
            $formLoadTime = (int)$_POST['timestamp'];
            $timeDiff = $submitTime - $formLoadTime;
            
            // Human should take at least 3 seconds to fill a form
            if ($timeDiff < 3) {
                self::logBot('Form submitted too fast: ' . $timeDiff . 's');
                return false;
            }
            
            // Form should not be older than 1 hour
            if ($timeDiff > 3600) {
                self::logBot('Form expired: ' . $timeDiff . 's old');
                return false;
            }
        }
        
        // Validate honeypot token
        if (!empty($_POST['hp_token'])) {
            if (!self::validateToken($_POST['hp_token'])) {
                self::logBot('Invalid honeypot token');
                return false;
            }
        }
        
        return true; // Passed all checks
    }
    
    /**
     * Generate secure token for honeypot validation
     */
    private static function generateToken() {
        $secret = 'LORCAPP_HP_SECRET_' . date('Ymd');
        return hash_hmac('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown', $secret);
    }
    
    /**
     * Validate honeypot token
     */
    private static function validateToken($token) {
        $expected = self::generateToken();
        return hash_equals($expected, $token);
    }
    
    /**
     * Log bot detection
     */
    private static function logBot($reason) {
        $logFile = __DIR__ . '/../logs/honeypot_detections.log';
        
        // Ensure logs directory exists
        if (!file_exists(dirname($logFile))) {
            mkdir(dirname($logFile), 0755, true);
        }
        
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'reason' => $reason,
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
            'referer' => $_SERVER['HTTP_REFERER'] ?? ''
        ];
        
        file_put_contents(
            $logFile,
            json_encode($logEntry) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }
    
    /**
     * Generate invisible honeypot link
     * Place this in your HTML to trap crawlers
     */
    public static function generateTrapLink() {
        return <<<HTML
        <a href="/trap.php" style="position:absolute;left:-9999px;top:-9999px;opacity:0;" aria-hidden="true" tabindex="-1">Hidden Link</a>
HTML;
    }
    
    /**
     * Check if IP is blocked from previous bot detection
     */
    public static function isBlocked() {
        $blockFile = __DIR__ . '/../logs/blocked_ips.json';
        
        if (!file_exists($blockFile)) {
            return false;
        }
        
        $blockedIPs = json_decode(file_get_contents($blockFile), true) ?? [];
        $clientIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        if (isset($blockedIPs[$clientIP])) {
            $blockTime = $blockedIPs[$clientIP]['blocked_at'];
            $blockDuration = 3600; // 1 hour
            
            if (time() - $blockTime < $blockDuration) {
                return true; // Still blocked
            } else {
                // Block expired, remove from list
                unset($blockedIPs[$clientIP]);
                file_put_contents($blockFile, json_encode($blockedIPs));
                return false;
            }
        }
        
        return false;
    }
    
    /**
     * Block IP address
     */
    public static function blockIP($reason = 'Bot detected') {
        $blockFile = __DIR__ . '/../logs/blocked_ips.json';
        
        // Ensure logs directory exists
        if (!file_exists(dirname($blockFile))) {
            mkdir(dirname($blockFile), 0755, true);
        }
        
        $blockedIPs = [];
        if (file_exists($blockFile)) {
            $blockedIPs = json_decode(file_get_contents($blockFile), true) ?? [];
        }
        
        $clientIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $blockedIPs[$clientIP] = [
            'blocked_at' => time(),
            'reason' => $reason,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ];
        
        file_put_contents($blockFile, json_encode($blockedIPs, JSON_PRETTY_PRINT));
        
        // Return 403 Forbidden
        http_response_code(403);
        header('Location: /error.php?code=403');
        exit();
    }
}
?>
