<?php
// Only start session if not already started by db.php
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', 3600);
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_path', '/');
    session_start();
}

require_once __DIR__ . '/role_guard.php';
?>
