<?php
/**
 * LORCAPP - Anti-Scraping Protection Initialization
 * Include this file at the top of pages you want to protect
 * 
 * Usage:
 * require_once 'includes/anti_scraping_init.php';
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load required components
require_once __DIR__ . '/honeypot.php';
require_once __DIR__ . '/rate_limiter.php';

/**
 * STEP 1: Check if IP is already blocked
 */
if (Honeypot::isBlocked()) {
    http_response_code(403);
    header('Location: /error.php?code=403');
    exit();
}

/**
 * STEP 2: Rate limiting check
 * Uncomment to activate (will block excessive requests)
 */
// RateLimiter::check();

/**
 * STEP 3: Bot detection via User-Agent
 */
function detectBotUserAgent() {
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    $botPatterns = [
        // AI Scrapers
        'GPTBot', 'ChatGPT', 'Claude', 'CCBot', 'anthropic', 'cohere',
        // Search Engine Bots
        'Googlebot', 'Bingbot', 'Slurp', 'DuckDuckBot', 'Baiduspider', 'YandexBot',
        // SEO Crawlers
        'AhrefsBot', 'SemrushBot', 'MJ12bot', 'DotBot', 'Rogerbot', 'BLEXBot', 'PetalBot',
        // Headless Browsers
        'HeadlessChrome', 'PhantomJS', 'Selenium', 'Puppeteer', 'Playwright',
        // Scrapers
        'bot', 'crawler', 'spider', 'scraper', 'scraping',
        // Programming Languages/Tools
        'python', 'curl', 'wget', 'scrapy', 'requests', 'beautifulsoup', 'mechanize',
        'perl', 'ruby', 'java', 'go-http', 'node-fetch'
    ];
    
    foreach ($botPatterns as $pattern) {
        if (stripos($userAgent, $pattern) !== false) {
            return [
                'is_bot' => true,
                'pattern' => $pattern,
                'user_agent' => $userAgent
            ];
        }
    }
    
    // Check for empty user agent
    if (empty($userAgent)) {
        return [
            'is_bot' => true,
            'pattern' => 'empty',
            'user_agent' => ''
        ];
    }
    
    // Check for very long user agent (often scrapers)
    if (strlen($userAgent) > 500) {
        return [
            'is_bot' => true,
            'pattern' => 'too_long',
            'user_agent' => $userAgent
        ];
    }
    
    return ['is_bot' => false];
}

$botCheck = detectBotUserAgent();
if ($botCheck['is_bot']) {
    // Log the bot attempt
    $logFile = __DIR__ . '/../logs/bot_detections.log';
    if (!file_exists(dirname($logFile))) {
        mkdir(dirname($logFile), 0755, true);
    }
    
    file_put_contents(
        $logFile,
        json_encode([
            'timestamp' => date('Y-m-d H:i:s'),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'pattern' => $botCheck['pattern'],
            'user_agent' => $botCheck['user_agent'],
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
            'referer' => $_SERVER['HTTP_REFERER'] ?? ''
        ], JSON_PRETTY_PRINT) . "\n---\n",
        FILE_APPEND | LOCK_EX
    );
    
    // Block the bot
    Honeypot::blockIP('Bot detected: ' . $botCheck['pattern']);
}

/**
 * STEP 4: Check for missing standard headers (bots often forget these)
 */
function checkStandardHeaders() {
    $issues = [];
    
    // Most real browsers send Accept header
    if (empty($_SERVER['HTTP_ACCEPT'])) {
        $issues[] = 'missing_accept';
    }
    
    // Most real browsers send Accept-Language
    if (empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        $issues[] = 'missing_accept_language';
    }
    
    // Most real browsers send Accept-Encoding
    if (empty($_SERVER['HTTP_ACCEPT_ENCODING'])) {
        $issues[] = 'missing_accept_encoding';
    }
    
    return $issues;
}

$headerIssues = checkStandardHeaders();
if (count($headerIssues) >= 2) {
    // Multiple missing headers = likely bot
    Honeypot::blockIP('Missing standard headers: ' . implode(', ', $headerIssues));
}

/**
 * STEP 5: Validate honeypot on form submissions
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Honeypot::validate()) {
        // Honeypot triggered - this is a bot
        Honeypot::blockIP('Honeypot validation failed');
    }
}

/**
 * STEP 6: Check request frequency (session-based)
 */
function checkRequestFrequency() {
    if (!isset($_SESSION['request_timestamps'])) {
        $_SESSION['request_timestamps'] = [];
    }
    
    $now = time();
    $_SESSION['request_timestamps'][] = $now;
    
    // Keep only requests from last 10 seconds
    $_SESSION['request_timestamps'] = array_filter(
        $_SESSION['request_timestamps'],
        function($timestamp) use ($now) {
            return ($now - $timestamp) <= 10;
        }
    );
    
    // If more than 10 requests in 10 seconds, likely a bot
    if (count($_SESSION['request_timestamps']) > 10) {
        return true;
    }
    
    return false;
}

if (checkRequestFrequency()) {
    Honeypot::blockIP('Too many requests in short time');
}

/**
 * STEP 7: JavaScript challenge tracking
 * Set a session variable when JS loads successfully
 */
if (!isset($_SESSION['js_verified'])) {
    // First request - JS hasn't verified yet
    $_SESSION['js_challenge_time'] = time();
} else {
    // JS has been verified
    unset($_SESSION['js_challenge_time']);
}

/**
 * STEP 8: Detect direct POST without visiting form (bots often do this)
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_SESSION['form_loaded'])) {
    // POST without loading the form first = bot
    Honeypot::blockIP('Direct POST without form load');
}

/**
 * STEP 9: Mark that a page was loaded (for tracking)
 */
$_SESSION['form_loaded'] = true;

/**
 * STEP 10: Set security headers
 */
header('X-Robots-Tag: noindex, nofollow, noarchive');

/**
 * Helper function to inject honeypot into forms
 */
function getHoneypotHTML() {
    return Honeypot::generateFields();
}

/**
 * Helper function to verify current request is legitimate
 */
function verifyLegitimateRequest() {
    // Check if JS verification is set
    if (!isset($_SESSION['js_verified'])) {
        // No JS verification = might be bot
        return false;
    }
    
    // Check referer on POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_SERVER['HTTP_REFERER'])) {
        return false;
    }
    
    return true;
}

/**
 * Anti-scraping successfully initialized
 * All checks passed - user is likely legitimate
 */
?>
