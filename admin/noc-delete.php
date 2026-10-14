<?php
include('../config/db.php');
include('../config/auth.php');

require_role('admin');

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    header("Location: noc-list.php");
    exit;
}

$stmt = $conn->prepare("SELECT id FROM noc_registration WHERE id=?");
$stmt->bind_param("i", $id);
$stmt->execute();
if ($stmt->get_result()->num_rows === 0) {
    header("Location: noc-list.php");
    exit;
}

$del = $conn->prepare("DELETE FROM noc_registration WHERE id=?");
$del->bind_param("i", $id);
$del->execute();

header("Location: noc-list.php?deleted=1");
exit;
