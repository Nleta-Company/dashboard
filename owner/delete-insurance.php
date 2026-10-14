<?php
include('../config/db.php');
include('../config/auth.php');
require_once '../config/security.php';

$currentUserRole = $_SESSION['role'] ?? '';
if ($currentUserRole !== 'admin' && $currentUserRole !== 'owner') {
    die("Unauthorized access.");
}

$asset_id = (int)($_GET['asset_id'] ?? 0);

if ($asset_id > 0) {
    // Archives active insurance for the asset
    $stmt = $conn->prepare("
        UPDATE insurance 
        SET is_active = 0 
        WHERE asset_id = ? AND is_active = 1
    ");
    $stmt->bind_param("i", $asset_id);

    if ($stmt->execute()) {
        $_SESSION['success'] = "Insurance entry cleared successfully.";
    } else {
        $_SESSION['error'] = "Failed to clear insurance record.";
    }
}

header("Location: noc-registration.php?asset_id=" . $asset_id);
exit;
?>