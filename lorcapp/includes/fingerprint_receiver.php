<?php
/**
 * LORCAPP - Browser Fingerprint Receiver
 * Receives and analyzes browser fingerprints for bot detection
 */

// Prevent direct access
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

// Get fingerprint data
$rawData = file_get_contents('php://input');
$data = json_decode($rawData, true);

// Also check for FormData submission (from sendBeacon)
if (!$data && isset($_POST['fingerprint'])) {
    $data = [
        'fingerprint' => json_decode($_POST['fingerprint'], true),
        'bot_score' => isset($_POST['bot_score']) ? (int)$_POST['bot_score'] : 0
    ];
}

if (!$data || !isset($data['fingerprint'])) {
    http_response_code(400);
    exit('Invalid data');
}

$fingerprint = $data['fingerprint'];
$botScore = isset($data['bot_score']) ? (int)$data['bot_score'] : 0;

// Enhance with server-side checks
$serverChecks = analyzeServerSide($fingerprint);
$finalScore = max($botScore, $serverChecks['score']);

// Log fingerprint
logFingerprint($fingerprint, $finalScore, $serverChecks);

// If bot score is high, add to watchlist
if ($finalScore >= 50) {
    addToWatchlist($fingerprint, $finalScore);
}

// If very high score, block immediately
if ($finalScore >= 80) {
    blockIP($fingerprint);
}

http_response_code(200);
echo json_encode([
    'success' => true,
    'bot_score' => $finalScore,
    'flagged' => $finalScore >= 50
]);

/**
 * Server-side analysis
 */
function analyzeServerSide($fingerprint) {
    $score = 0;
    $reasons = [];
    
    // Check User-Agent consistency
    $serverUA = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $clientUA = $fingerprint['browser']['userAgent'] ?? '';
    
    if ($serverUA !== $clientUA) {
        $score += 25;
        $reasons[] = 'User-Agent mismatch';
    }
    
    // Check for bot patterns in UA
    $botPatterns = [
        'bot', 'crawler', 'spider', 'scraper', 'headless',
        'phantom', 'selenium', 'puppeteer', 'playwright',
        'curl', 'wget', 'python', 'scrapy', 'requests',
        'GPTBot', 'ChatGPT', 'Claude', 'CCBot'
    ];
    
    foreach ($botPatterns as $pattern) {
        if (stripos($serverUA, $pattern) !== false) {
            $score += 30;
            $reasons[] = 'Bot pattern in UA: ' . $pattern;
            break;
        }
    }
    
    // Check for missing Accept headers (bots often forget these)
    if (empty($_SERVER['HTTP_ACCEPT'])) {
        $score += 15;
        $reasons[] = 'Missing Accept header';
    }
    
    // Check Accept-Language
    if (empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        $score += 15;
        $reasons[] = 'Missing Accept-Language';
    }
    
    // Check for suspicious Accept-Encoding
    $acceptEncoding = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
    if (empty($acceptEncoding)) {
        $score += 10;
        $reasons[] = 'Missing Accept-Encoding';
    }
    
    // Check Connection header
    $connection = $_SERVER['HTTP_CONNECTION'] ?? '';
    if (empty($connection) || strtolower($connection) === 'close') {
        $score += 5;
        $reasons[] = 'Suspicious Connection header';
    }
    
    // Check for missing Referer on form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_SERVER['HTTP_REFERER'])) {
        $score += 10;
        $reasons[] = 'POST without Referer';
    }
    
    // Check DNT header inconsistency
    $serverDNT = $_SERVER['HTTP_DNT'] ?? null;
    $clientDNT = $fingerprint['browser']['doNotTrack'] ?? null;
    
    if ($serverDNT !== $clientDNT) {
        $score += 5;
        $reasons[] = 'DNT header mismatch';
    }
    
    // Check for webdriver flag
    if (isset($fingerprint['features']['webdriver']) && $fingerprint['features']['webdriver']) {
        $score += 30;
        $reasons[] = 'WebDriver detected';
    }
    
    // Check for phantom
    if (isset($fingerprint['features']['phantom']) && $fingerprint['features']['phantom']) {
        $score += 30;
        $reasons[] = 'PhantomJS detected';
    }
    
    // Check for selenium
    if (isset($fingerprint['features']['selenium']) && $fingerprint['features']['selenium']) {
        $score += 30;
        $reasons[] = 'Selenium detected';
    }
    
    // Check plugin count (headless = 0 plugins)
    $pluginCount = count($fingerprint['browser']['plugins'] ?? []);
    if ($pluginCount === 0) {
        $score += 20;
        $reasons[] = 'No browser plugins';
    }
    
    // Check language
    if (empty($fingerprint['browser']['language'])) {
        $score += 15;
        $reasons[] = 'No browser language';
    }
    
    return [
        'score' => min($score, 100),
        'reasons' => $reasons
    ];
}

/**
 * Log fingerprint to file
 */
function logFingerprint($fingerprint, $botScore, $serverChecks) {
    $logFile = __DIR__ . '/../logs/fingerprints.log';
    
    // Ensure logs directory exists
    if (!file_exists(dirname($logFile))) {
        mkdir(dirname($logFile), 0755, true);
    }
    
    $logEntry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'bot_score' => $botScore,
        'server_checks' => $serverChecks,
        'fingerprint' => $fingerprint,
        'headers' => [
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'accept' => $_SERVER['HTTP_ACCEPT'] ?? '',
            'accept_language' => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
            'accept_encoding' => $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '',
            'connection' => $_SERVER['HTTP_CONNECTION'] ?? '',
            'referer' => $_SERVER['HTTP_REFERER'] ?? ''
        ]
    ];
    
    file_put_contents(
        $logFile,
        json_encode($logEntry, JSON_PRETTY_PRINT) . "\n---\n",
        FILE_APPEND | LOCK_EX
    );
}

/**
 * Add to watchlist
 */
function addToWatchlist($fingerprint, $score) {
    $watchFile = __DIR__ . '/../logs/watchlist.json';
    
    // Ensure logs directory exists
    if (!file_exists(dirname($watchFile))) {
        mkdir(dirname($watchFile), 0755, true);
    }
    
    $watchlist = [];
    if (file_exists($watchFile)) {
        $watchlist = json_decode(file_get_contents($watchFile), true) ?? [];
    }
    
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    $key = md5($ip . $ua);
    
    $watchlist[$key] = [
        'ip' => $ip,
        'user_agent' => $ua,
        'bot_score' => $score,
        'first_seen' => $watchlist[$key]['first_seen'] ?? date('Y-m-d H:i:s'),
        'last_seen' => date('Y-m-d H:i:s'),
        'hit_count' => ($watchlist[$key]['hit_count'] ?? 0) + 1
    ];
    
    file_put_contents($watchFile, json_encode($watchlist, JSON_PRETTY_PRINT));
}

/**
 * Block IP
 */
function blockIP($fingerprint) {
    $blockFile = __DIR__ . '/../logs/blocked_ips.json';
    
    // Ensure logs directory exists
    if (!file_exists(dirname($blockFile))) {
        mkdir(dirname($blockFile), 0755, true);
    }
    
    $blockedIPs = [];
    if (file_exists($blockFile)) {
        $blockedIPs = json_decode(file_get_contents($blockFile), true) ?? [];
    }
    
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $blockedIPs[$ip] = [
        'blocked_at' => time(),
        'reason' => 'High bot score from fingerprint analysis',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'fingerprint_hash' => md5(json_encode($fingerprint))
    ];
    
    file_put_contents($blockFile, json_encode($blockedIPs, JSON_PRETTY_PRINT));
}
?>
