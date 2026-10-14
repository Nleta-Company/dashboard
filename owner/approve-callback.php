<?php
include('../config/db.php');
include('../config/auth.php');

require_role('owner');

$id       = (int)($_GET['id'] ?? 0);
$action   = $_GET['action'] ?? '';
$owner_id = (int)$_SESSION['uid'];

if (!$id || !in_array($action, ['approve', 'reject'])) {
    header("Location: callbacks.php");
    exit;
}

// Allow fortnightly callbacks (asset_id=0) and regular callbacks owned by this owner
$stmt = $conn->prepare("
    SELECT c.id, c.asset_id, c.status
    FROM callbacks c
    LEFT JOIN assets a ON a.id = c.asset_id
    WHERE c.id = ?
      AND a.owner_id = ?
");
$stmt->bind_param("ii", $id, $owner_id);
$stmt->execute();
$cb = $stmt->get_result()->fetch_assoc();

if (!$cb || $cb['status'] !== 'closed') {
    header("Location: callbacks.php");
    exit;
}

if ($action === 'approve') {
    $upd = $conn->prepare("UPDATE callbacks SET status='approved', approved_at=NOW(), work_closed_at=IFNULL(work_closed_at,NOW()) WHERE id=?");
    $upd->bind_param("i", $id);
    $upd->execute();

    // Only update asset status if asset exists
    if ($cb['asset_id'] > 0) {
        $act = $conn->prepare("UPDATE assets SET status='Active' WHERE id=?");
        $act->bind_param("i", $cb['asset_id']);
        $act->execute();
    }

    $_SESSION['success'] = "Callback approved successfully. Task is complete.";
} else {
    $upd = $conn->prepare("UPDATE callbacks SET status='in_progress' WHERE id=?");
    $upd->bind_param("i", $id);
    $upd->execute();

    $_SESSION['success'] = "Callback rejected and sent back to technician.";
}

header("Location: callbacks.php");
exit;
