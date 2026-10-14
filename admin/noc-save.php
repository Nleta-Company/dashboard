<?php

/* =========================================================
   ERROR DISPLAY - TEMPORARY
========================================================= */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


/* =========================================================
   INCLUDES & AUTH
========================================================= */

include('../config/db.php');
include('../config/auth.php');
require_once '../config/security.php';

$currentUserRole = $_SESSION['role'] ?? '';
if ($currentUserRole !== 'admin' && $currentUserRole !== 'owner') {
    die("Unauthorized access.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // CSRF Check
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header("Location: noc-registration.php");
        exit;
    }

    $owner_id = ($currentUserRole === 'owner') ? (int)($_SESSION['uid'] ?? 0) : (int)($_POST['owner_id'] ?? 0);
    $asset_id = (int)($_POST['asset_id'] ?? 0);

    // Form Inputs
    $noc_no            = trim($_POST['noc_no'] ?? '');
    $noc_date          = $_POST['noc_date'] ?? '';
    $registration_no   = trim($_POST['registration_no'] ?? '');
    $registration_date = $_POST['registration_date'] ?? '';
    $safety_date       = $_POST['safety_awareness_date'] ?? '';
    $mock_drill_date   = $_POST['mock_drill_date'] ?? '';
    $insurance_no      = trim($_POST['insurance_no'] ?? '');
    $insurance_start   = $_POST['insurance_start_date'] ?? '';

    if (!$owner_id || !$asset_id || !$noc_no || !$noc_date || !$registration_no || !$registration_date || !$safety_date || !$mock_drill_date || !$insurance_no || !$insurance_start) {
        $_SESSION['error'] = "All required fields must be filled.";
        $redirectErr = ($currentUserRole === 'admin') ? "noc-registration.php?owner_id={$owner_id}&asset_id={$asset_id}" : "noc-registration.php";
        header("Location: " . $redirectErr);
        exit;
    }

    // Calculated Expiries & Due Dates
    $noc_expiry_date  = date('Y-m-d', strtotime($noc_date . ' +3 years'));
    $next_safety_date = date('Y-m-d', strtotime($safety_date . ' +6 months'));
    $next_mock_date   = date('Y-m-d', strtotime($mock_drill_date . ' +1 year'));
    $ins_expiry_date  = date('Y-m-d', strtotime($insurance_start . ' +1 year'));

    // Upload File Handling
    $uploadedFileName = null;
    if (isset($_FILES['insurance_file']) && $_FILES['insurance_file']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath   = $_FILES['insurance_file']['tmp_name'];
        $fileName      = $_FILES['insurance_file']['name'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if (in_array($fileExtension, ['pdf', 'jpg', 'jpeg', 'png']) && $_FILES['insurance_file']['size'] <= 5 * 1024 * 1024) {
            $uploadFileDir = '../uploads/insurance/';
            if (!is_dir($uploadFileDir)) {
                mkdir($uploadFileDir, 0755, true);
            }
            $newFileName = 'ins_' . $asset_id . '_' . time() . '.' . $fileExtension;
            if (move_uploaded_file($fileTmpPath, $uploadFileDir . $newFileName)) {
                $uploadedFileName = $newFileName;
            }
        }
    }

    $conn->begin_transaction();
    try {
        // A. UPSERT NOC Details
        $nocStmt = $conn->prepare("
            INSERT INTO noc_registration (
                owner_id, asset_id, noc_no, noc_date, noc_expiry_date, 
                registration_no, registration_date, safety_awareness_date, 
                next_safety_date, mock_drill_date, next_mock_drill_date
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                noc_no = VALUES(noc_no),
                noc_date = VALUES(noc_date),
                noc_expiry_date = VALUES(noc_expiry_date),
                registration_no = VALUES(registration_no),
                registration_date = VALUES(registration_date),
                safety_awareness_date = VALUES(safety_awareness_date),
                next_safety_date = VALUES(next_safety_date),
                mock_drill_date = VALUES(mock_drill_date),
                next_mock_drill_date = VALUES(next_mock_drill_date)
        ");
        $nocStmt->bind_param("iisssssssss", $owner_id, $asset_id, $noc_no, $noc_date, $noc_expiry_date, $registration_no, $registration_date, $safety_date, $next_safety_date, $mock_drill_date, $next_mock_date);
        $nocStmt->execute();

        // B. Fetch Previous Insurance File if no new upload
        if ($uploadedFileName === null) {
            $prevFileStmt = $conn->prepare("SELECT report_file FROM insurance WHERE asset_id = ? AND report_file IS NOT NULL ORDER BY id DESC LIMIT 1");
            $prevFileStmt->bind_param("i", $asset_id);
            $prevFileStmt->execute();
            $prevRow          = $prevFileStmt->get_result()->fetch_assoc();
            $uploadedFileName = $prevRow['report_file'] ?? null;
        }

        // C. Archive Old Insurance (Set is_active = 0)
        $archiveStmt = $conn->prepare("UPDATE insurance SET is_active = 0 WHERE asset_id = ?");
        $archiveStmt->bind_param("i", $asset_id);
        $archiveStmt->execute();

        // D. Insert New Active Insurance (is_active = 1)
        $insStmt = $conn->prepare("
            INSERT INTO insurance (asset_id, insurance_no, start_date, expiry_date, report_file, is_active)
            VALUES (?, ?, ?, ?, ?, 1)
        ");
        $insStmt->bind_param("issss", $asset_id, $insurance_no, $insurance_start, $ins_expiry_date, $uploadedFileName);
        $insStmt->execute();

        $conn->commit();
        $_SESSION['success'] = "NOC & Insurance details saved successfully.";

        $redirectPage = ($currentUserRole === 'admin') ? "noc-list.php" : "noc-registration.php";
        header("Location: " . $redirectPage);
        exit;

    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = "Transaction error: " . $e->getMessage();
        
        $redirectPage = ($currentUserRole === 'admin') ? "noc-registration.php?owner_id={$owner_id}&asset_id={$asset_id}" : "noc-registration.php";
        header("Location: " . $redirectPage);
        exit;
    }
}
?>