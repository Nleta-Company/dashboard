<?php
include('../config/db.php');
include('../config/auth.php');

require_role('admin');

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header("Location: noc-list.php"); exit; }

$stmt = $conn->prepare("SELECT noc_expiry_date, safety_awareness_date, mock_drill_date FROM noc_registration WHERE id=?");
$stmt->bind_param("i", $id);
$stmt->execute();
$noc = $stmt->get_result()->fetch_assoc();
if (!$noc) { header("Location: noc-list.php"); exit; }

$new_expiry      = date('Y-m-d', strtotime($noc['noc_expiry_date'] . ' +3 years'));
$new_next_safety = date('Y-m-d', strtotime($noc['safety_awareness_date'] . ' +6 months'));
$new_next_mock   = date('Y-m-d', strtotime($noc['mock_drill_date'] . ' +1 year'));

$upd = $conn->prepare("UPDATE noc_registration SET noc_expiry_date=?, next_safety_date=?, next_mock_drill_date=? WHERE id=?");
$upd->bind_param("sssi", $new_expiry, $new_next_safety, $new_next_mock, $id);
$upd->execute();

header("Location: noc-list.php?renewed=1"); exit;
