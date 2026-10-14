<?php
// CSRF Token Generation and Validation
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generateCSRFToken()) . '">';
}

// Secure File Upload Validation
function validateFileUpload($file, $allowedTypes = ['pdf','jpg','jpeg','png'], $maxSize = 7242880) {
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['success' => false, 'error' => 'Invalid file upload'];
    }
    
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedTypes)) {
        return ['success' => false, 'error' => 'Invalid file type. Allowed: ' . implode(', ', $allowedTypes)];
    }
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    $allowedMimes = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    ];
    
    if (!isset($allowedMimes[$ext]) || $mimeType !== $allowedMimes[$ext]) {
        return ['success' => false, 'error' => 'File type mismatch'];
    }
    
    if ($file['size'] > $maxSize) {
        return ['success' => false, 'error' => 'File too large. Max ' . ($maxSize/1048576) . 'MB'];
    }
    
    return ['success' => true];
}

// Sanitize filename to prevent path traversal
function sanitizeFilename($filename) {
    $filename = basename($filename);
    $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
    return $filename;
}

// Generate secure random filename
function generateSecureFilename($extension) {
    return bin2hex(random_bytes(16)) . '_' . time() . '.' . $extension;
}

// Rate limiting for login attempts
function checkRateLimit($identifier, $maxAttempts = 5, $timeWindow = 900) {
    $key = 'rate_limit_' . md5($identifier);
    
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = ['count' => 0, 'first_attempt' => time()];
    }
    
    $data = $_SESSION[$key];
    
    if (time() - $data['first_attempt'] > $timeWindow) {
        $_SESSION[$key] = ['count' => 1, 'first_attempt' => time()];
        return true;
    }
    
    if ($data['count'] >= $maxAttempts) {
        return false;
    }
    
    $_SESSION[$key]['count']++;
    return true;
}

// Secure redirect
function secureRedirect($url) {
    $url = filter_var($url, FILTER_SANITIZE_URL);
    if (strpos($url, 'http') === 0) {
        die('External redirects not allowed');
    }
    header("Location: " . $url);
    exit;
}

// Input sanitization
function sanitizeInput($input, $type = 'string') {
    $input = trim($input);
    
    switch ($type) {
        case 'int':
            return filter_var($input, FILTER_VALIDATE_INT) !== false ? (int)$input : 0;
        case 'email':
            return filter_var($input, FILTER_VALIDATE_EMAIL) ?: '';
        case 'string':
        default:
            return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
    }
}

// Password strength validation
function validatePasswordStrength($password) {
    if (strlen($password) < 8) {
        return ['valid' => false, 'error' => 'Password must be at least 8 characters'];
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return ['valid' => false, 'error' => 'Password must contain at least one uppercase letter'];
    }
    if (!preg_match('/[a-z]/', $password)) {
        return ['valid' => false, 'error' => 'Password must contain at least one lowercase letter'];
    }
    if (!preg_match('/[0-9]/', $password)) {
        return ['valid' => false, 'error' => 'Password must contain at least one number'];
    }
    return ['valid' => true];
}
?>
