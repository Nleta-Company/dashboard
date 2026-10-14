# Quick Security Fix Implementation Guide

## ✅ What Has Been Fixed

1. **config/security.php** - Created with security helper functions
2. **login.php** - Added CSRF protection + rate limiting
3. **admin/add-user.php** - Added CSRF + password strength validation
4. **.htaccess** - Enhanced security headers, blocked debug files
5. **config/security_config.php** - Production security configuration
6. **SECURITY_AUDIT_REPORT.md** - Comprehensive security audit

---

## 🔴 Critical Fixes Needed (Do Now)

### 1. Add CSRF to All Forms

Add these two lines to EVERY form in your application:

**At the top of the PHP file:**
```php
require_once '../config/security.php';  // or correct path
```

**Inside the form POST handler:**
```php
if (isset($_POST['submit'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header("Location: current_page.php"); exit;
    }
    // ... rest of your code
}
```

**Inside the HTML form:**
```php
<form method="POST">
  <?= csrfField() ?>
  <!-- rest of form fields -->
</form>
```

**Files that need CSRF tokens:**
- admin/edit-user.php (2 forms)
- admin/add-asset.php
- admin/edit-asset.php
- admin/insurance.php
- admin/renew-insurance.php
- admin/noc-save.php
- admin/noc-edit.php
- admin/assign-technician.php
- owner/create-callback.php
- owner/approve-maintenance.php
- owner/insurance.php
- maintenance/maintenance_submit.php
- maintenance/submit-report.php

---

### 2. Delete or Secure Debug Files

**Option A: Delete (Recommended)**
```bash
rm debug.php
rm test-connection.php
rm cook.txt
rm cookies.txt
```

**Option B: Secure (if needed)**
Add at the top of debug.php:
```php
<?php
require_once 'config/db.php';
require_once 'config/auth.php';
require_role('admin');
// ... rest of debug code
```

---

### 3. Fix File Upload Validation

Replace file upload code in these files:

**owner/insurance.php** (lines 20-40):
```php
require_once '../config/security.php';

// Replace existing validation with:
$validation = validateFileUpload($_FILES['report_file'], ['pdf','jpg','jpeg','png'], 5242880);
if (!$validation['success']) {
    $_SESSION['error'] = $validation['error'];
    header("Location: insurance.php"); exit;
}

$ext = strtolower(pathinfo($_FILES['report_file']['name'], PATHINFO_EXTENSION));
$fileName = generateSecureFilename($ext);
```

**Apply same fix to:**
- maintenance/maintenance_submit.php
- owner/approve-maintenance.php
- maintenance/submit-report.php

---

### 4. Remove Hardcoded Database Password

**config/db.php** - Change lines 17-19:
```php
// OLD (INSECURE):
$db_pass = getenv('DB_PASS') ?: '1234';

// NEW (SECURE):
$db_pass = getenv('DB_PASS');
if (!$db_pass) {
    error_log('DB_PASS environment variable not set');
    die('Database configuration error. Contact administrator.');
}
```

**Create .env file** (DO NOT commit to git):
```env
DB_HOST=localhost
DB_USER=maintenance_user
DB_PASS=YourStrongPasswordHere123!
DB_NAME=maintenance_system
APP_ENV=production
FAST2SMS_API_KEY=your_api_key
ADMIN_PHONE=1234567890
```

**Add to .gitignore:**
```
.env
sessions/
logs/
uploads/
*.log
```

---

## 🟠 High Priority Fixes (This Week)

### 5. Fix OTP Comparison

**admin/verify-delete.php** - Line 15:
```php
// OLD:
if ($otp == $_SESSION['delete_otp']) {

// NEW:
if ($otp === (string)$_SESSION['delete_otp']) {
```

---

### 6. Add Password Validation to Edit User

**admin/edit-user.php** - Add after line 1:
```php
require_once '../config/security.php';
```

In the POST handler, add:
```php
if (!empty($_POST['password'])) {
    $pwCheck = validatePasswordStrength($_POST['password']);
    if (!$pwCheck['valid']) {
        $_SESSION['error'] = $pwCheck['error'];
        header("Location: edit-user.php?id=$id"); exit;
    }
    $pass = password_hash($_POST['password'], PASSWORD_BCRYPT, ['cost' => 12]);
    // ... rest of update code
}
```

---

### 7. Enable Production Security Config

**config/db.php** - Add at the very top (line 2):
```php
<?php
require_once __DIR__ . '/security_config.php';
// ... rest of db.php
```

---

### 8. Create Logs Directory

```bash
mkdir logs
chmod 755 logs
echo "Deny from all" > logs/.htaccess
```

---

## 🟡 Medium Priority (This Month)

### 9. Move Uploads Outside Web Root

**Current structure:**
```
dashboard_original/
├── uploads/          ← Publicly accessible
├── admin/
└── owner/
```

**Secure structure:**
```
/var/www/
├── uploads/          ← Outside web root
└── html/
    └── dashboard_original/
```

**Update all upload paths:**
```php
// OLD:
$uploadDir = __DIR__ . "/../uploads/";

// NEW:
$uploadDir = '/var/www/uploads/';
```

**Create download script** (download.php):
```php
<?php
require_once 'config/db.php';
require_once 'config/auth.php';

$file = basename($_GET['file'] ?? '');
$type = $_GET['type'] ?? '';

// Validate user has access to this file
// ... add your access control logic

$filePath = "/var/www/uploads/$type/$file";
if (file_exists($filePath)) {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    readfile($filePath);
    exit;
}
http_response_code(404);
```

---

### 10. Add Audit Logging

Create **config/audit_log.php**:
```php
<?php
function logAudit($action, $details = '') {
    global $conn;
    $user_id = $_SESSION['uid'] ?? 0;
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $stmt = $conn->prepare("INSERT INTO audit_log (user_id, action, details, ip_address) VALUES (?,?,?,?)");
    $stmt->bind_param("isss", $user_id, $action, $details, $ip);
    $stmt->execute();
}
```

**Add audit_log table:**
```sql
CREATE TABLE audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(100),
    details TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

**Use in critical actions:**
```php
logAudit('user_created', "Created user: $email");
logAudit('user_deleted', "Deleted user ID: $id");
logAudit('asset_deleted', "Deleted asset ID: $id");
```

---

## Testing Checklist

After implementing fixes, test:

- [ ] Login with wrong password 6 times (should block after 5)
- [ ] Try to submit form without CSRF token (should fail)
- [ ] Try to access debug.php (should be blocked)
- [ ] Try to upload .php file as image (should be rejected)
- [ ] Try to create user with weak password (should be rejected)
- [ ] Check that .env file is not accessible via browser
- [ ] Verify all forms still work correctly
- [ ] Test file downloads work after moving uploads

---

## Production Deployment Checklist

- [ ] Set APP_ENV=production in .env
- [ ] Remove all debug files
- [ ] Set strong database password
- [ ] Enable HTTPS and update session.cookie_secure
- [ ] Set proper file permissions (644 for files, 755 for directories)
- [ ] Disable directory listing
- [ ] Set up automated backups
- [ ] Configure firewall rules
- [ ] Set up SSL certificate
- [ ] Test all functionality in staging first

---

## Quick Command Reference

**Find all forms without CSRF:**
```bash
grep -r "<form" --include="*.php" | grep -v "csrfField"
```

**Find all file uploads:**
```bash
grep -r "move_uploaded_file" --include="*.php"
```

**Find all password_hash calls:**
```bash
grep -r "password_hash" --include="*.php"
```

---

## Support

If you need help implementing these fixes:
1. Check SECURITY_AUDIT_REPORT.md for detailed explanations
2. Review config/security.php for available helper functions
3. Test each fix in a development environment first

**Remember:** Security is not a one-time fix. Regular audits and updates are essential.
