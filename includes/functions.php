<?php
/**
 * Common Functions
 */

/**
 * Secure logging function - redacts sensitive data
 */
function secureLog($message, $context = [], $level = 'INFO') {
    // List of sensitive keys to redact
    $sensitiveKeys = [
        'password', 'passwd', 'pwd',
        'token', 'csrf', 'api_key', 'secret',
        'ssn', 'social_security',
        'credit_card', 'card_number', 'cvv',
        'pin', 'encryption_key', 'master_key',
        'private_key', 'auth_token'
    ];
    
    // Redact sensitive data from context
    if (is_array($context)) {
        array_walk_recursive($context, function(&$value, $key) use ($sensitiveKeys) {
            foreach ($sensitiveKeys as $sensitiveKey) {
                if (stripos($key, $sensitiveKey) !== false) {
                    $value = '[REDACTED]';
                    break;
                }
            }
        });
    }
    
    $contextStr = !empty($context) ? ' | Context: ' . json_encode($context) : '';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] [{$level}] {$message}{$contextStr}";
    
    error_log($logMessage);
}

/**
 * Redirect helper
 */
function redirect($url) {
    header("Location: " . $url);
    exit();
}

/**
 * Get current week number (Monday-Sunday basis)
 */
function getCurrentWeekNumber() {
    // ISO week date: Monday as first day of week
    return date('W');
}

/**
 * Get week date range
 */
function getWeekDateRange($weekNumber = null, $year = null) {
    $weekNumber = $weekNumber ?: date('W');
    $year = $year ?: date('Y');
    
    $dto = new DateTime();
    $dto->setISODate($year, $weekNumber);
    $monday = $dto->format('Y-m-d');
    
    $dto->modify('+6 days');
    $sunday = $dto->format('Y-m-d');
    
    return [
        'start' => $monday,
        'end' => $sunday,
        'week' => $weekNumber,
        'year' => $year
    ];
}

/**
 * Format date for display
 */
function formatDate($date, $format = 'F d, Y') {
    if (empty($date)) return '';
    return date($format, strtotime($date));
}

/**
 * Format datetime for display
 */
function formatDateTime($datetime, $format = 'F d, Y h:i A') {
    if (empty($datetime)) return '';
    return date($format, strtotime($datetime));
}

/**
 * Get department list
 */
function getDepartments() {
    return [
        'Pamunuan',
        'KNTSTSP',
        'Katiwala ng dako (GWS)',
        'Katiwala ng Purok',
        'II Katiwala ng Purok',
        'Katiwala ng Grupo',
        'II Katiwala ng Grupo',
        'Kalihim ng Grupo',
        'Diakono',
        'Diakonesa',
        'Lupon sa Pagpapatibay',
        'Ilaw Ng Kaligtasan',
        'Mang-aawit',
        'Organista',
        'Pananalapi',
        'Kalihiman',
        'Buklod',
        'KADIWA',
        'Binhi',
        'PNK',
        'Guro',
        'SCAN',
        'TSV',
        'CBI'
    ];
}

/**
 * Get removal codes
 */
function getRemovalCodes() {
    return [
        'A' => 'Namatay (Deceased)',
        'B' => 'Lumipat sa ibang lokal (Transfer Out)',
        'C' => 'Inalis sa karapatan - suspendido (Suspended)',
        'D' => 'Lipat Kapisanan (Transfer Kapisanan)'
    ];
}

/**
 * Get local congregations by district
 */
function getLocalsByDistrict($districtCode) {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("
        SELECT local_code, local_name 
        FROM local_congregations 
        WHERE district_code = ? 
        ORDER BY local_name
    ");
    $stmt->execute([$districtCode]);
    return $stmt->fetchAll();
}

/**
 * Get record codes
 */
function getRecordCodes() {
    return [
        'A' => 'New Record (No existing record)',
        'D' => 'Existing Record (Has previous record)'
    ];
}

/**
 * Flash message helper
 */
function setFlashMessage($type, $message) {
    $_SESSION['flash_message'] = [
        'type' => $type, // success, error, warning, info
        'message' => $message
    ];
}

/**
 * Get and clear flash message
 */
function getFlashMessage() {
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']);
        return $message;
    }
    return null;
}

/**
 * Get user info
 */
function getCurrentUser() {
    if (!Security::isLoggedIn()) {
        return null;
    }
    
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("
        SELECT u.*, d.district_name, lc.local_name 
        FROM users u
        LEFT JOIN districts d ON u.district_code = d.district_code
        LEFT JOIN local_congregations lc ON u.local_code = lc.local_code
        WHERE u.user_id = ?
    ");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
}

/**
 * Check if user has access to district
 */
function hasDistrictAccess($districtCode) {
    $user = getCurrentUser();
    if (!$user) return false;
    
    // Admin has access to all
    if ($user['role'] === 'admin') return true;
    
    // Check if district matches
    return $user['district_code'] === $districtCode;
}

/**
 * Check if user has access to local congregation
 */
function hasLocalAccess($localCode) {
    $user = getCurrentUser();
    if (!$user) return false;
    
    // Admin and district users have access
    if ($user['role'] === 'admin' || $user['role'] === 'district') return true;
    
    // Check if local matches
    return $user['local_code'] === $localCode;
}

/**
 * Paginate results
 */
function paginate($total, $currentPage = 1, $perPage = RECORDS_PER_PAGE) {
    $totalPages = ceil($total / $perPage);
    $currentPage = max(1, min($currentPage, $totalPages));
    $offset = ($currentPage - 1) * $perPage;
    
    return [
        'total' => $total,
        'per_page' => $perPage,
        'current_page' => $currentPage,
        'total_pages' => $totalPages,
        'offset' => $offset,
        'has_prev' => $currentPage > 1,
        'has_next' => $currentPage < $totalPages
    ];
}

/**
 * Generate pagination HTML
 */
function renderPagination($pagination, $baseUrl) {
    if ($pagination['total_pages'] <= 1) return '';
    
    $html = '<nav><ul class="pagination">';
    
    // Previous
    if ($pagination['has_prev']) {
        $prevPage = $pagination['current_page'] - 1;
        $html .= "<li class='page-item'><a class='page-link' href='{$baseUrl}?page={$prevPage}'>Previous</a></li>";
    }
    
    // Page numbers
    for ($i = 1; $i <= $pagination['total_pages']; $i++) {
        $active = $i === $pagination['current_page'] ? 'active' : '';
        $html .= "<li class='page-item {$active}'><a class='page-link' href='{$baseUrl}?page={$i}'>{$i}</a></li>";
    }
    
    // Next
    if ($pagination['has_next']) {
        $nextPage = $pagination['current_page'] + 1;
        $html .= "<li class='page-item'><a class='page-link' href='{$baseUrl}?page={$nextPage}'>Next</a></li>";
    }
    
    $html .= '</ul></nav>';
    return $html;
}

/**
 * API Response helper
 */
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

/**
 * Obfuscate name for privacy display
 * Example: "DELA CRUZ, JUAN A." -> "D**A CR*Z, J*** A."
 */
function obfuscateName($fullName) {
    if (empty($fullName)) return '';
    
    // Split by comma to separate last name from first name
    $parts = explode(',', $fullName, 2);
    if (count($parts) !== 2) {
        // If no comma, treat as single name
        return obfuscateWord($fullName);
    }
    
    $lastName = trim($parts[0]);
    $firstName = trim($parts[1]);
    
    // Obfuscate each part
    $obfuscatedLast = obfuscateWord($lastName);
    $obfuscatedFirst = obfuscateWord($firstName);
    
    return $obfuscatedLast . ', ' . $obfuscatedFirst;
}

/**
 * Obfuscate a single word or phrase
 */
function obfuscateWord($word) {
    if (empty($word)) return '';
    
    // Split by spaces to handle multi-word names
    $words = explode(' ', $word);
    $obfuscatedWords = [];
    
    foreach ($words as $w) {
        $obfuscatedWords[] = obfuscateSingleWord($w);
    }
    
    return implode(' ', $obfuscatedWords);
}

/**
 * Obfuscate a single word
 */
function obfuscateSingleWord($word) {
    $len = strlen($word);
    
    if ($len <= 2) {
        // For very short words, show first letter only or keep as is
        return $len === 1 ? $word : $word[0] . '*';
    } elseif ($len === 3) {
        // For 3-letter words, show first and last
        return $word[0] . '*' . $word[$len-1];
    } else {
        // For longer words, show first, asterisks for middle, and last
        $first = $word[0];
        $last = $word[$len-1];
        $asterisks = str_repeat('*', $len - 2);
        return $first . $asterisks . $last;
    }
}
