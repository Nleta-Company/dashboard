<?php
include('../config/db.php');
include('../config/auth.php');
require_once '../config/security.php';

// Verify session role
$currentUserRole = $_SESSION['role'] ?? '';
if ($currentUserRole !== 'admin' && $currentUserRole !== 'owner') {
    die("Unauthorized access.");
}

$asset_id = (int)($_GET['asset_id'] ?? 0);
$owner_id = (int)($_GET['owner_id'] ?? 0);

if ($asset_id > 0) {
    // Sets active insurance to archived (0) so it hides across all panels
    $stmt = $conn->prepare("
        UPDATE insurance 
        SET is_active = 0 
        WHERE asset_id = ? AND is_active = 1
    ");
    $stmt->bind_param("i", $asset_id);

    if ($stmt->execute()) {
        $_SESSION['success'] = "Active insurance cleared successfully. Historical data remains intact.";
    } else {
        $_SESSION['error'] = "Failed to clear insurance record.";
    }
}

$redirectUrl = "noc-registration.php?asset_id=" . $asset_id;
if ($owner_id > 0 && $currentUserRole === 'admin') {
    $redirectUrl .= "&owner_id=" . $owner_id;
}

header("Location: " . $redirectUrl);
exit;
?>