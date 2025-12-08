<?php
// Redirect index to login or dashboard
require_once __DIR__ . '/config/config.php';

if (Security::isLoggedIn()) {
    redirect(BASE_URL . '/dashboard.php');
} else {
    redirect(BASE_URL . '/login.php');
}
?>
