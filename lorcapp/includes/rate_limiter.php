<?php
/**
 * LORCAPP - Rate Limiting & Anti-Scraping Middleware
 * Server-side protection against scraping and DDoS
 */

// Prevent direct access
if (count(get_included_files()) == 1) exit("Direct access forbidden");

class RateLimiter {
    private $rateFile;
    private $maxRequests;
    private $timeWindow;
    private $blockDuration;
    
    public function __construct() {
        $this->rateFile = __DIR__ . '/../logs/rate_limit.json';
        $this->maxRequests = 30;        // Max 30 requests
        $this->timeWindow = 60;         // Per 60 seconds
        $this->blockDuration = 3600;    // Block for 1 hour
        
        // Ensure logs directory exists
        if (!file_exists(dirname($this->rateFile))) {
            mkdir(dirname($this->rateFile), 0755, true);
        }
    }
    
    /**
     * Get client identifier (IP + User Agent fingerprint)
     */
    private function getClientId() {
        $ip = $this->getClientIP();
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        return md5($ip . $ua);
    }
    
    /**
     * Get real client IP (behind proxies/CDN)
     */
    private function getClientIP() {
        $headers = [
            'HTTP_CF_CONNECTING_IP',      // Cloudflare
            'HTTP_X_REAL_IP',             // Nginx proxy
            'HTTP_X_FORWARDED_FOR',       // Standard proxy
            'HTTP_CLIENT_IP',             // Shared internet
            'REMOTE_ADDR'                 // Direct connection
        ];
        
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                // Handle comma-separated IPs (X-Forwarded-For)
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                // Validate IP
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        
        return '0.0.0.0';
    }
    
    /**
     * Load rate limit data
     */
    private function loadData() {
        if (!file_exists($this->rateFile)) {
            return [];
        }
        
        $json = file_get_contents($this->rateFile);
        return json_decode($json, true) ?? [];
    }
    
    /**
     * Save rate limit data
     */
    private function saveData($data) {
        file_put_contents($this->rateFile, json_encode($data), LOCK_EX);
    }
    
    /**
     * Clean old entries
     */
    private function cleanOldEntries(&$data) {
        $now = time();
        foreach ($data as $clientId => $info) {
            // Remove expired blocks
            if (isset($info['blocked_until']) && $info['blocked_until'] < $now) {
                unset($data[$clientId]);
                continue;
            }
            
            // Remove old requests outside time window
            if (isset($info['requests'])) {
                $info['requests'] = array_filter($info['requests'], function($timestamp) use ($now) {
                    return ($now - $timestamp) < $this->timeWindow;
                });
                
                if (empty($info['requests']) && !isset($info['blocked_until'])) {
                    unset($data[$clientId]);
                } else {
                    $data[$clientId] = $info;
                }
            }
        }
    }
    
    /**
     * Check if client is blocked
     */
    public function isBlocked() {
        $clientId = $this->getClientId();
        $data = $this->loadData();
        
        if (isset($data[$clientId]['blocked_until'])) {
            if (time() < $data[$clientId]['blocked_until']) {
                return [
                    'blocked' => true,
                    'reason' => $data[$clientId]['reason'] ?? 'Rate limit exceeded',
                    'until' => $data[$clientId]['blocked_until'],
                    'requests' => $data[$clientId]['total_requests'] ?? 0
                ];
            }
        }
        
        return ['blocked' => false];
    }
    
    /**
     * Record request and check rate limit
     */
    public function checkRateLimit() {
        $clientId = $this->getClientId();
        $data = $this->loadData();
        $now = time();
        
        // Clean old entries
        $this->cleanOldEntries($data);
        
        // Initialize client data if not exists
        if (!isset($data[$clientId])) {
            $data[$clientId] = [
                'requests' => [],
                'first_seen' => $now,
                'ip' => $this->getClientIP(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ];
        }
        
        // Check if blocked
        if (isset($data[$clientId]['blocked_until']) && $data[$clientId]['blocked_until'] > $now) {
            $this->saveData($data);
            return [
                'allowed' => false,
                'reason' => 'blocked',
                'blocked_until' => $data[$clientId]['blocked_until']
            ];
        }
        
        // Add current request
        $data[$clientId]['requests'][] = $now;
        $data[$clientId]['last_seen'] = $now;
        
        // Count requests in current window
        $recentRequests = array_filter($data[$clientId]['requests'], function($timestamp) use ($now) {
            return ($now - $timestamp) < $this->timeWindow;
        });
        
        $requestCount = count($recentRequests);
        
        // Check if rate limit exceeded
        if ($requestCount > $this->maxRequests) {
            // Block the client
            $data[$clientId]['blocked_until'] = $now + $this->blockDuration;
            $data[$clientId]['total_requests'] = $requestCount;
            $data[$clientId]['reason'] = 'Rate limit exceeded';
            $data[$clientId]['blocked_at'] = date('Y-m-d H:i:s');
            
            $this->saveData($data);
            
            // Log the block
            $this->logBlock($clientId, $requestCount);
            
            return [
                'allowed' => false,
                'reason' => 'rate_limit_exceeded',
                'requests' => $requestCount,
                'limit' => $this->maxRequests,
                'window' => $this->timeWindow,
                'blocked_until' => $data[$clientId]['blocked_until']
            ];
        }
        
        $this->saveData($data);
        
        return [
            'allowed' => true,
            'requests' => $requestCount,
            'limit' => $this->maxRequests,
            'remaining' => $this->maxRequests - $requestCount
        ];
    }
    
    /**
     * Log blocked clients
     */
    private function logBlock($clientId, $requestCount) {
        $logFile = __DIR__ . '/../logs/blocked_clients.log';
        $logEntry = sprintf(
            "[%s] Blocked: %s | IP: %s | Requests: %d | UA: %s\n",
            date('Y-m-d H:i:s'),
            $clientId,
            $this->getClientIP(),
            $requestCount,
            substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 100)
        );
        
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Check for suspicious patterns (scraper detection)
     */
    public function detectScraper() {
        $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
        
        // Known scraper patterns
        $scraperPatterns = [
            'bot', 'crawler', 'spider', 'scraper', 'curl', 'wget', 'python',
            'scrapy', 'mechanize', 'beautifulsoup', 'selenium', 'puppeteer',
            'headless', 'phantom', 'httrack', 'harvest', 'extract', 'parser',
            'gptbot', 'chatgpt', 'ccbot', 'anthropic', 'claude', 'google-extended',
            'ahrefsbot', 'semrush', 'mj12bot', 'dotbot', 'petalbot', 'bingbot'
        ];
        
        foreach ($scraperPatterns as $pattern) {
            if (strpos($ua, $pattern) !== false) {
                return [
                    'is_scraper' => true,
                    'pattern' => $pattern,
                    'user_agent' => $ua
                ];
            }
        }
        
        // Check for missing or suspicious user agent
        if (empty($ua) || strlen($ua) < 10 || strlen($ua) > 500) {
            return [
                'is_scraper' => true,
                'pattern' => 'suspicious_user_agent',
                'user_agent' => $ua
            ];
        }
        
        // Check for missing Accept header (common in bots)
        if (empty($_SERVER['HTTP_ACCEPT'])) {
            return [
                'is_scraper' => true,
                'pattern' => 'missing_accept_header',
                'user_agent' => $ua
            ];
        }
        
        return ['is_scraper' => false];
    }
    
    /**
     * Block client permanently
     */
    public function blockClient($reason = 'Scraper detected') {
        $clientId = $this->getClientId();
        $data = $this->loadData();
        $now = time();
        
        $data[$clientId] = [
            'blocked_until' => $now + (86400 * 365), // 1 year
            'reason' => $reason,
            'blocked_at' => date('Y-m-d H:i:s'),
            'ip' => $this->getClientIP(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ];
        
        $this->saveData($data);
        $this->logBlock($clientId, 0);
    }
    
    /**
     * Send rate limit response
     */
    public function sendRateLimitResponse($info) {
        http_response_code(429); // Too Many Requests
        header('Content-Type: application/json');
        header('Retry-After: ' . ($info['blocked_until'] - time()));
        
        echo json_encode([
            'error' => 'Rate limit exceeded',
            'message' => 'Too many requests. Please try again later.',
            'retry_after' => $info['blocked_until'] - time(),
            'blocked_until' => date('Y-m-d H:i:s', $info['blocked_until'])
        ]);
        
        exit;
    }
    
    /**
     * Send blocked response
     */
    public function sendBlockedResponse($reason = 'Suspicious activity detected') {
        http_response_code(403); // Forbidden
        header('Content-Type: text/html; charset=utf-8');
        
        echo '<!DOCTYPE html>
<html>
<head>
    <title>Access Denied</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f5f5f5; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .container { background: white; padding: 40px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); text-align: center; max-width: 500px; }
        h1 { color: #e53e3e; margin: 0 0 20px 0; }
        p { color: #4a5568; line-height: 1.6; }
        .code { background: #f7fafc; padding: 10px; border-radius: 4px; font-family: monospace; margin: 20px 0; }
    </style>
</head>
<body>
    <div class="container">
        <h1>⛔ Access Denied</h1>
        <p><strong>Reason:</strong> ' . htmlspecialchars($reason) . '</p>
        <p>Your request has been blocked due to suspicious activity.</p>
        <div class="code">Error Code: 403 FORBIDDEN</div>
        <p>If you believe this is an error, please contact the administrator.</p>
    </div>
</body>
</html>';
        
        exit;
    }
}

// Usage example (uncomment to use in your pages):
/*
require_once __DIR__ . '/includes/rate_limiter.php';

$limiter = new RateLimiter();

// Check for scrapers
$scraperCheck = $limiter->detectScraper();
if ($scraperCheck['is_scraper']) {
    $limiter->blockClient('Scraper detected: ' . $scraperCheck['pattern']);
    $limiter->sendBlockedResponse('Automated access detected');
}

// Check rate limit
$rateCheck = $limiter->checkRateLimit();
if (!$rateCheck['allowed']) {
    $limiter->sendRateLimitResponse($rateCheck);
}
*/
