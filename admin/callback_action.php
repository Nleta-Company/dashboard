<?php
include('../config/db.php');
include('../config/auth.php');

require_role('admin');

$callback = trim($_GET['callback'] ?? '');
$action   = $_GET['action'] ?? '';

if (!$callback || !in_array($action, ['approve','reopen'])) {
    header("Location: callbacks.php"); exit;
}

$stmt = $conn->prepare("SELECT status FROM callbacks WHERE callback_number=?");
$stmt->bind_param("s", $callback);
$stmt->execute();
$cb = $stmt->get_result()->fetch_assoc();
if (!$cb) { header("Location: callbacks.php"); exit; }

if ($action === 'approve' && $cb['status'] === 'closed') {
    $upd = $conn->prepare("UPDATE callbacks SET status='approved' WHERE callback_number=?");
    $upd->bind_param("s", $callback);
    $upd->execute();
    $_SESSION['success'] = "Callback approved successfully";

} elseif ($action === 'reopen' && in_array($cb['status'], ['closed','approved'])) {
    $upd = $conn->prepare("UPDATE callbacks SET status='in_progress', work_closed_at=NULL WHERE callback_number=?");
    $upd->bind_param("s", $callback);
    $upd->execute();
    $_SESSION['success'] = "Callback reopened and sent back to technician";
}

header("Location: callbacks.php"); exit;
