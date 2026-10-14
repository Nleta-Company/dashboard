<?php
include('../config/db.php');
include('../config/auth.php');

require_role('technician');

$tech_id = (int)$_SESSION['uid'];

/* ===== CALLBACK NUMBER + ACTION ===== */
$callback_no = $_GET['callback'] ?? '';
$action      = $_GET['action'] ?? '';

if (!$callback_no || $action !== 'start') {
    header("Location: callbacks.php"); exit;
}

/* ================= GET CALLBACK ================= */
$cb_stmt = $conn->prepare("SELECT asset_id, status FROM callbacks WHERE callback_number=?");
$cb_stmt->bind_param("s", $callback_no);
$cb_stmt->execute();
$cb = $cb_stmt->get_result()->fetch_assoc();

if (!$cb) {
    header("Location: callbacks.php"); exit;
}

$asset_id = (int)$cb['asset_id'];

if ($cb['status'] === 'open') {
    $upd = $conn->prepare("UPDATE callbacks SET status='in_progress', work_started_at=NOW() WHERE callback_number=? AND technician_id=?");
    $upd->bind_param("si", $callback_no, $tech_id);
    $upd->execute();
}

header("Location: callbacks.php");
exit;
