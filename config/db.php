<?php
require_once __DIR__ . '/timezone.php';
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', 3600);
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_path', '/');
    session_start();
}

// Use local DB when running locally, InfinityFree when hosted
$host = $_SERVER['HTTP_HOST'] ?? '';
$isLocal = in_array($host, ['localhost', '127.0.0.1', 'localhost:8000']) 
    || preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/', $host)
    || php_sapi_name() === 'cli' || empty($host);

if ($isLocal) {
    $conn = new mysqli('localhost', 'root', '', 'nleta');
} else {
    $conn = new mysqli('sql303.infinityfree.com', 'if0_41489314', 'Nelta2134', 'if0_41489314_maintenance');
}

if ($conn->connect_error) {
    die("Database connection failed. Please try again later.");
}
$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '+00:00'");


function format_dt($dt) {
    if (!$dt) return '';
    $d = new DateTime($dt, new DateTimeZone('UTC'));
    $d->setTimezone(new DateTimeZone('Asia/Kolkata'));
    return $d->format('d M Y');
}


?>
