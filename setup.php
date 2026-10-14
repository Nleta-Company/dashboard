<?php
/**
 * NLETA System Setup Page
 * Run this ONCE to create the database tables and admin user.
 * DELETE this file after setup is complete.
 */

$host = "localhost";
$user = "root";
$pass = "";       // Default XAMPP/WAMP has no password
$db   = "maintenance_system";

// Allow overriding via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = $_POST['host'] ?? $host;
    $user = $_POST['db_user'] ?? $user;
    $pass = $_POST['db_pass'] ?? $pass;
    $db   = $_POST['db_name'] ?? $db;
}

$messages = [];
$success  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['setup'])) {

    // Connect without DB first
    $conn = new mysqli($host, $user, $pass);

    if ($conn->connect_error) {
        $messages[] = ['type' => 'danger', 'text' => "❌ Connection failed: " . $conn->connect_error];
    } else {

        // Create database
        $conn->query("CREATE DATABASE IF NOT EXISTS `$db`");
        $conn->select_db($db);

        $sql = [
            "CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                email VARCHAR(100) UNIQUE NOT NULL,
                password VARCHAR(255) NOT NULL,
                role ENUM('admin','owner','technician','client') NOT NULL,
                assigned_technician_id INT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS assets (
                id INT AUTO_INCREMENT PRIMARY KEY,
                asset_name VARCHAR(150) NOT NULL,
                asset_type VARCHAR(50),
                category VARCHAR(10),
                location VARCHAR(200),
                owner_id INT NOT NULL,
                status ENUM('Active','Under Maintenance','Out of Service') DEFAULT 'Active',
                maintenance_interval INT DEFAULT 30,
                updated_at DATE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS maintenance_records (
                id INT AUTO_INCREMENT PRIMARY KEY,
                asset_id INT NOT NULL,
                maintenance_date DATE NOT NULL,
                remarks TEXT,
                status VARCHAR(50) DEFAULT 'completed',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS maintenance_jobs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                asset_id INT NOT NULL,
                technician_id INT,
                status ENUM('assigned','in_progress','pending_owner','approved','rejected','completed') DEFAULT 'assigned',
                checklist JSON,
                remarks TEXT,
                file VARCHAR(255),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS callbacks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                callback_number VARCHAR(50) UNIQUE,
                asset_id INT NOT NULL,
                technician_id INT,
                issue VARCHAR(100),
                description TEXT,
                status ENUM('open','in_progress','closed','approved') DEFAULT 'open',
                work_report TEXT,
                work_report_file VARCHAR(255),
                work_started_at DATETIME,
                work_closed_at DATETIME,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS insurance (
                id INT AUTO_INCREMENT PRIMARY KEY,
                asset_id INT NOT NULL,
                expiry_date DATE NOT NULL,
                report_file VARCHAR(255),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS noc_registration (
                id INT AUTO_INCREMENT PRIMARY KEY,
                asset_id INT,
                noc_number VARCHAR(100),
                noc_expiry_date DATE,
                next_safety_date DATE,
                next_mock_drill_date DATE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS fortnightly_checks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                asset_id INT,
                owner_id INT,
                checklist JSON,
                remarks TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS news (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255),
                content TEXT,
                image VARCHAR(255),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )"
        ];

        foreach ($sql as $query) {
            if (!$conn->query($query)) {
                $messages[] = ['type' => 'warning', 'text' => "⚠ Table error: " . $conn->error];
            }
        }

        // Create admin user
        $adminName  = trim($_POST['admin_name'] ?? 'Admin');
        $adminEmail = trim($_POST['admin_email'] ?? 'admin@nleta.org');
        $adminPass  = password_hash($_POST['admin_password'] ?? 'admin123', PASSWORD_DEFAULT);

        $check = $conn->query("SELECT id FROM users WHERE email='$adminEmail'");
        if ($check->num_rows === 0) {
            $conn->query("INSERT INTO users (name, email, password, role) VALUES ('$adminName', '$adminEmail', '$adminPass', 'admin')");
            $messages[] = ['type' => 'success', 'text' => "✅ Admin user created: $adminEmail"];
        } else {
            $messages[] = ['type' => 'info', 'text' => "ℹ Admin user already exists: $adminEmail"];
        }

        // Update db.php
        $dbConfig = "<?php\n\$conn = new mysqli(\"$host\", \"$user\", \"$pass\", \"$db\");\nif (\$conn->connect_error) {\n    die(\"Database Connection Failed\");\n}\nsession_start();\n?>\n";
        file_put_contents(__DIR__ . '/config/db.php', $dbConfig);

        $messages[] = ['type' => 'success', 'text' => "✅ Database tables created successfully"];
        $messages[] = ['type' => 'success', 'text' => "✅ config/db.php updated with your settings"];
        $success = true;

        $conn->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>NLETA System Setup</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="container" style="max-width:600px; margin-top:60px;">

  <div class="card shadow p-4">
    <h3 class="mb-1 text-center">⚙️ NLETA System Setup</h3>
    <p class="text-center text-muted mb-4">Run once to initialize the database</p>

    <?php foreach ($messages as $m): ?>
    <div class="alert alert-<?= $m['type'] ?>"><?= $m['text'] ?></div>
    <?php endforeach; ?>

    <?php if ($success): ?>
    <div class="alert alert-success text-center">
      <strong>🎉 Setup Complete!</strong><br>
      <a href="login.php" class="btn btn-success mt-2">Go to Login →</a>
    </div>
    <div class="alert alert-warning mt-2">
      ⚠ <strong>Security:</strong> Delete <code>setup.php</code> after setup.
    </div>
    <?php else: ?>

    <form method="POST">

      <h5 class="mb-3">Database Settings</h5>

      <div class="row g-3 mb-3">
        <div class="col-6">
          <label class="form-label">Host</label>
          <input name="host" class="form-control" value="localhost">
        </div>
        <div class="col-6">
          <label class="form-label">Database Name</label>
          <input name="db_name" class="form-control" value="maintenance_system">
        </div>
        <div class="col-6">
          <label class="form-label">MySQL Username</label>
          <input name="db_user" class="form-control" value="root">
        </div>
        <div class="col-6">
          <label class="form-label">MySQL Password</label>
          <input name="db_pass" type="password" class="form-control" placeholder="Leave blank if none">
        </div>
      </div>

      <hr>
      <h5 class="mb-3">Admin Account</h5>

      <div class="mb-3">
        <label class="form-label">Admin Name</label>
        <input name="admin_name" class="form-control" value="Admin" required>
      </div>
      <div class="mb-3">
        <label class="form-label">Admin Email</label>
        <input name="admin_email" type="email" class="form-control" value="admin@nleta.org" required>
      </div>
      <div class="mb-3">
        <label class="form-label">Admin Password</label>
        <input name="admin_password" type="password" class="form-control" placeholder="Set a strong password" required>
      </div>

      <button name="setup" class="btn btn-primary w-100">
        🚀 Run Setup
      </button>

    </form>
    <?php endif; ?>

  </div>

  <p class="text-center text-muted mt-3" style="font-size:13px">
    © <?= date('Y') ?> NLETA. All Rights Reserved.
  </p>

</div>

</body>
</html>
