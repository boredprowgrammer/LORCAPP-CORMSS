<?php
/**
 * LORCAPP
 * Authentication & Session Management
 */

// Prevent direct access
if (count(get_included_files()) == 1) exit("Direct access forbidden");

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/encryption.php';

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['admin_id']) && isset($_SESSION['admin_username']);
}

// Require authentication
function requireAuth() {
    // Check geo-blocking first
    require_once __DIR__ . '/security.php';
    if (!checkGeoBlock()) {
        showGeoBlockPage();
    }
    
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit();
    }
}

// Login function
function login($username, $password) {
    try {
        $conn = getDbConnection();
        
        $username = sanitize($username);
        
        $query = "SELECT id, username, password, full_name FROM admin_users WHERE username = ?";
        $stmt = $conn->prepare($query);
        
        if (!$stmt) {
            return false;
        }
        
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            if (password_verify($password, $user['password'])) {
                // Set session variables
                $_SESSION['admin_id'] = $user['id'];
                $_SESSION['admin_username'] = $user['username'];
                // Decrypt full_name if stored encrypted, fallback to raw value
                $decrypted_name = null;
                if (function_exists('decryptValue')) {
                    try {
                        $decrypted_name = decryptValue($user['full_name']);
                    } catch (Exception $e) {
                        error_log('Decryption error in auth::login(): ' . $e->getMessage());
                        $decrypted_name = null;
                    }
                }
                $_SESSION['admin_name'] = ($decrypted_name !== null && $decrypted_name !== false) ? $decrypted_name : $user['full_name'];
                $_SESSION['admin_logged_in'] = true;
                
                // Update last login
                $updateQuery = "UPDATE admin_users SET last_login = NOW() WHERE id = ?";
                $updateStmt = $conn->prepare($updateQuery);
                
                if ($updateStmt) {
                    $updateStmt->bind_param("i", $user['id']);
                    $updateStmt->execute();
                }
                
                return true;
            }
        }
        
        return false;
    } catch (Exception $e) {
        error_log("ERROR [auth.php]: Exception in login(): " . $e->getMessage());
        return false;
    }
}

// Logout function
function logout() {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit();
}

// Get current admin info
function getCurrentAdmin() {
    if (!isLoggedIn()) {
        return null;
    }
    
    return [
        'id' => $_SESSION['admin_id'],
        'username' => $_SESSION['admin_username'],
        'name' => $_SESSION['admin_name'] ?? 'Administrator'
    ];
}
?>
