<?php
include('../config/db.php');
include('../config/auth.php');
require_once '../config/security.php';

require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header("Location: noc-list.php"); exit;
    }
    
    $id                = (int)$_POST['id'];
    $owner_id          = (int)$_POST['owner_id'];
    $asset_id          = (int)$_POST['asset_id'];
    $noc_no            = trim($_POST['noc_no']);
    $noc_date          = $_POST['noc_date'];
    $registration_no   = trim($_POST['registration_no']);
    $registration_date = $_POST['registration_date'];
    $safety_date       = $_POST['safety_awareness_date'];
    $mock_date         = $_POST['mock_drill_date'];

    // Validate dates
    foreach ([$noc_date, $registration_date, $safety_date, $mock_date] as $d) {
        if (!DateTime::createFromFormat('Y-m-d', $d)) {
            $_SESSION['error'] = 'Invalid date format.';
            header("Location: noc-list.php"); exit;
        }
    }

    $noc_expiry   = date('Y-m-d', strtotime($noc_date . ' +3 years'));
    $next_safety  = date('Y-m-d', strtotime($safety_date . ' +6 months'));
    $next_mock    = date('Y-m-d', strtotime($mock_date . ' +1 year'));

    $stmt = $conn->prepare("UPDATE noc_registration SET owner_id=?, asset_id=?, noc_no=?, noc_date=?, noc_expiry_date=?, registration_no=?, registration_date=?, safety_awareness_date=?, next_safety_date=?, mock_drill_date=?, next_mock_drill_date=? WHERE id=?");
    $stmt->bind_param("iisssssssssi", $owner_id, $asset_id, $noc_no, $noc_date, $noc_expiry, $registration_no, $registration_date, $safety_date, $next_safety, $mock_date, $next_mock, $id);
    $stmt->execute();

    header("Location: noc-list.php?updated=1"); exit;
}
