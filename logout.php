<?php
require_once __DIR__ . '/config/db.php';
// Ensure session is started even if db.php doesn't start it for some reason
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION = [];
// Expire session cookie(s) and destroy server session
if (!empty($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 86400, '/');
}
session_destroy();
// Prevent any output before redirect
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header("Location: login.php");
exit;
?>
