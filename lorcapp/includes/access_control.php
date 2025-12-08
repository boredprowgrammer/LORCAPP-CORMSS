<?php
/**
 * Access Control - Prevent Direct Access to Sensitive Files
 * 
 * This file provides functions to prevent direct access to API endpoints,
 * functions, and sensitive files without relying on server configuration.
 * 
 * Usage: Add one line at the top of any sensitive PHP file:
 * if (count(get_included_files()) == 1) exit("Direct access forbidden");
 */

/**
 * Prevent direct access to the current file
 * Only allows access through proper inclusion/require
 * 
 * @param string $allowed_file Optional: The file that is allowed to include this one
 * @return void Dies with 403 if accessed directly
 */
function prevent_direct_access($allowed_file = null) {
    // Check if file is being accessed directly (not included)
    $current_file = basename($_SERVER['PHP_SELF']);
    $script_filename = basename($_SERVER['SCRIPT_FILENAME']);
    
    // If current file matches script filename, it's being accessed directly
    if ($current_file === $script_filename) {
        http_response_code(403);
        header('Content-Type: text/plain');
        die('403 Forbidden: Direct access to this file is not allowed');
    }
    
    // Additional check: if specific file is required
    if ($allowed_file !== null) {
        $including_file = basename(debug_backtrace()[0]['file'] ?? '');
        if ($including_file !== $allowed_file) {
            http_response_code(403);
            header('Content-Type: text/plain');
            die('403 Forbidden: This file can only be accessed from ' . htmlspecialchars($allowed_file));
        }
    }
}

/**
 * Require that a constant is defined (indicating proper initialization)
 * 
 * @param string $constant_name The constant that must be defined
 * @return void Dies with 403 if constant is not defined
 */
function require_constant($constant_name) {
    if (!defined($constant_name)) {
        http_response_code(403);
        header('Content-Type: text/plain');
        die('403 Forbidden: Access denied - Missing required constant');
    }
}

/**
 * Check if request is from an AJAX call
 * 
 * @return bool True if AJAX request, false otherwise
 */
function is_ajax_request() {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

/**
 * Require AJAX request - block direct browser access
 * 
 * @return void Dies with 403 if not AJAX
 */
function require_ajax() {
    if (!is_ajax_request()) {
        http_response_code(403);
        header('Content-Type: application/json');
        die(json_encode([
            'success' => false,
            'error' => 'This endpoint only accepts AJAX requests'
        ]));
    }
}

/**
 * Require POST request method
 * 
 * @return void Dies with 405 if not POST
 */
function require_post() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        header('Content-Type: application/json');
        die(json_encode([
            'success' => false,
            'error' => 'Method Not Allowed - POST required'
        ]));
    }
}

/**
 * Require GET request method
 * 
 * @return void Dies with 405 if not GET
 */
function require_get() {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        header('Allow: GET');
        header('Content-Type: application/json');
        die(json_encode([
            'success' => false,
            'error' => 'Method Not Allowed - GET required'
        ]));
    }
}

/**
 * Check if request is from localhost/local IP
 * 
 * @return bool True if local, false otherwise
 */
function is_local_request() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return in_array($ip, ['127.0.0.1', '::1', 'localhost']);
}

/**
 * Require local access only (localhost/127.0.0.1)
 * 
 * @return void Dies with 403 if not local
 */
function require_local_access() {
    if (!is_local_request()) {
        http_response_code(403);
        header('Content-Type: text/plain');
        die('403 Forbidden: This resource is only accessible from localhost');
    }
}

/**
 * Require authenticated session
 * Checks if user is logged in via session
 * 
 * @return void Dies with 401 if not authenticated
 */
function require_auth() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (!isset($_SESSION['admin_id']) || !isset($_SESSION['admin_logged_in'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        die(json_encode([
            'success' => false,
            'error' => 'Unauthorized - Authentication required',
            'redirect' => '/admin/login.php'
        ]));
    }
}

/**
 * Require admin authentication with specific role
 * 
 * @param string $required_role Optional role requirement
 * @return void Dies with 403 if not authorized
 */
function require_admin_auth($required_role = null) {
    require_auth();
    
    if ($required_role !== null && isset($_SESSION['admin_role'])) {
        if ($_SESSION['admin_role'] !== $required_role) {
            http_response_code(403);
            header('Content-Type: application/json');
            die(json_encode([
                'success' => false,
                'error' => 'Forbidden - Insufficient permissions'
            ]));
        }
    }
}

/**
 * Check if file is included (not accessed directly)
 * 
 * @return bool True if included, false if direct access
 */
function is_included() {
    $included_files = get_included_files();
    return count($included_files) > 1;
}

/**
 * Require file to be included (not accessed directly)
 * 
 * @return void Dies with 403 if accessed directly
 */
function require_included() {
    if (!is_included()) {
        http_response_code(403);
        header('Content-Type: text/plain');
        die('403 Forbidden: This file must be included, not accessed directly');
    }
}

/**
 * Block access to sensitive file types
 * 
 * @param array $blocked_extensions Array of file extensions to block
 * @return void Dies with 403 if accessing blocked file
 */
function block_file_extensions($blocked_extensions = []) {
    $default_blocked = ['php', 'sql', 'env', 'log', 'ini', 'conf', 'sh', 'bash'];
    $blocked = array_merge($default_blocked, $blocked_extensions);
    
    $request_uri = $_SERVER['REQUEST_URI'] ?? '';
    $extension = pathinfo(parse_url($request_uri, PHP_URL_PATH), PATHINFO_EXTENSION);
    
    if (in_array(strtolower($extension), $blocked)) {
        http_response_code(403);
        header('Content-Type: text/plain');
        die('403 Forbidden: Access to this file type is not allowed');
    }
}

/**
 * Validate referer to prevent CSRF-like attacks
 * 
 * @param string $expected_domain Optional expected domain
 * @return bool True if referer is valid
 */
function validate_referer($expected_domain = null) {
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    
    if (empty($referer)) {
        return false;
    }
    
    if ($expected_domain !== null) {
        $referer_host = parse_url($referer, PHP_URL_HOST);
        return $referer_host === $expected_domain;
    }
    
    // Check if referer is from same domain
    $server_host = $_SERVER['HTTP_HOST'] ?? '';
    $referer_host = parse_url($referer, PHP_URL_HOST);
    
    return $referer_host === $server_host;
}

/**
 * Require valid referer (same domain)
 * 
 * @return void Dies with 403 if referer is invalid
 */
function require_valid_referer() {
    if (!validate_referer()) {
        http_response_code(403);
        header('Content-Type: application/json');
        die(json_encode([
            'success' => false,
            'error' => 'Forbidden - Invalid or missing referer'
        ]));
    }
}

/**
 * Combined protection for API endpoints
 * Requires: AJAX, POST, Authentication, Valid Referer
 * 
 * @param bool $require_ajax Whether to require AJAX (default: true)
 * @param bool $require_post_method Whether to require POST (default: true)
 * @param bool $require_authentication Whether to require auth (default: true)
 * @param bool $require_valid_referer Whether to require valid referer (default: true)
 * @return void
 */
function protect_api_endpoint($require_ajax = true, $require_post_method = true, $require_authentication = true, $require_valid_referer = true) {
    if ($require_ajax) {
        require_ajax();
    }
    
    if ($require_post_method) {
        require_post();
    }
    
    if ($require_authentication) {
        require_auth();
    }
    
    if ($require_valid_referer) {
        require_valid_referer();
    }
}

/**
 * Combined protection for admin-only files
 * Prevents direct access and requires authentication
 * 
 * @return void
 */
function protect_admin_file() {
    prevent_direct_access();
    require_auth();
}

/**
 * Combined protection for include files (config, functions, etc.)
 * 
 * @return void
 */
function protect_include_file() {
    require_included();
}

/**
 * Log unauthorized access attempts
 * 
 * @param string $file The file that was accessed
 * @param string $reason Reason for blocking
 * @return void
 */
function log_unauthorized_access($file, $reason = 'Direct access attempt') {
    $log_file = __DIR__ . '/../logs/unauthorized_access.log';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    $timestamp = date('Y-m-d H:i:s');
    
    $log_entry = sprintf(
        "[%s] IP: %s | File: %s | Reason: %s | User-Agent: %s\n",
        $timestamp,
        $ip,
        $file,
        $reason,
        $user_agent
    );
    
    @file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

/**
 * Enhanced direct access prevention with logging
 * 
 * @param string $file_description Description of the file for logging
 * @return void
 */
function prevent_direct_access_with_log($file_description = '') {
    $current_file = basename($_SERVER['PHP_SELF']);
    $script_filename = basename($_SERVER['SCRIPT_FILENAME']);
    
    if ($current_file === $script_filename) {
        $file = $file_description ?: $current_file;
        log_unauthorized_access($file, 'Direct access blocked');
        
        http_response_code(403);
        header('Content-Type: text/plain');
        die('403 Forbidden: Direct access to this file is not allowed');
    }
}

// Export functions for easy access
return [
    'prevent_direct_access' => 'prevent_direct_access',
    'require_constant' => 'require_constant',
    'require_ajax' => 'require_ajax',
    'require_post' => 'require_post',
    'require_get' => 'require_get',
    'require_local_access' => 'require_local_access',
    'require_auth' => 'require_auth',
    'require_admin_auth' => 'require_admin_auth',
    'require_included' => 'require_included',
    'protect_api_endpoint' => 'protect_api_endpoint',
    'protect_admin_file' => 'protect_admin_file',
    'protect_include_file' => 'protect_include_file',
];
