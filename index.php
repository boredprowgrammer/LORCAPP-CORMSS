<?php
// Redirect index to login or launchpad
require_once __DIR__ . '/config/config.php';

if (Security::isLoggedIn()) {
    redirect(BASE_URL . '/launchpad.php');
} else {
    redirect(BASE_URL . '/login.php');
}
?>
