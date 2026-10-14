<?php
/**
 * Production Security Configuration
 * Include this file at the top of config/db.php for production deployment
 */

// Prevent direct access
if (!defined('SECURITY_INIT')) {
    define('SECURITY_INIT', true);
}

// Production Error Handling
if (getenv('APP_ENV') === 'production') {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(E_ALL);
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/../logs/php_errors.log');
} else {
    // Development
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
}

// Security Headers (if not set in .htaccess)
if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    
    // Content Security Policy
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:;");
    
    // HSTS (only if using HTTPS)
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// Secure PHP Settings
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Strict');

// If using HTTPS, enable secure cookies
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', 1);
}

// Disable expose_php
ini_set('expose_php', 0);

// File Upload Security
ini_set('file_uploads', 1);
ini_set('upload_max_filesize', '5M');
ini_set('post_max_size', '6M');
ini_set('max_file_uploads', 5);

// Create logs directory if it doesn't exist
$logsDir = __DIR__ . '/../logs';
if (!is_dir($logsDir)) {
    mkdir($logsDir, 0755, true);
}

// Custom error handler for production
if (getenv('APP_ENV') === 'production') {
    set_error_handler(function($errno, $errstr, $errfile, $errline) {
        error_log("Error [$errno]: $errstr in $errfile on line $errline");
        
        // Don't show errors to users
        if (!(error_reporting() & $errno)) {
            return false;
        }
        
        // For critical errors, show generic message
        if ($errno === E_ERROR || $errno === E_CORE_ERROR || $errno === E_COMPILE_ERROR) {
            die('An error occurred. Please contact support.');
        }
        
        return true;
    });
    
    set_exception_handler(function($exception) {
        error_log("Uncaught Exception: " . $exception->getMessage() . " in " . $exception->getFile() . " on line " . $exception->getLine());
        die('An error occurred. Please contact support.');
    });
}

// Validate environment variables
function validateEnvironment() {
    $required = ['DB_HOST', 'DB_USER', 'DB_PASS', 'DB_NAME'];
    $missing = [];
    
    foreach ($required as $var) {
        if (!getenv($var)) {
            $missing[] = $var;
        }
    }
    
    if (!empty($missing) && getenv('APP_ENV') === 'production') {
        error_log('Missing required environment variables: ' . implode(', ', $missing));
        die('Configuration error. Please contact administrator.');
    }
}

validateEnvironment();
?>
