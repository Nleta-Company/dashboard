<?php

include('../config/db.php');
include('../config/auth.php');

require_role('technician');

$tech_id = (int)($_SESSION['uid'] ?? 0);
$job_id  = (int)($_GET['id'] ?? 0);

if ($job_id <= 0) {
    $_SESSION['error'] = "Invalid maintenance job.";
    header("Location: assigned_lifts.php");
    exit;
}

/* =========================================================
   VALIDATE APPROVED AMC JOB
========================================================= */

$job_q = $conn->prepare("
    SELECT
        mj.id,
        mj.asset_id,
        mj.status,
        mj.job_type,
        mj.technician_response
    FROM maintenance_jobs mj
    WHERE mj.id = ?
      AND mj.technician_id = ?
      AND mj.status = 'approved'
      AND LOWER(TRIM(COALESCE(mj.job_type, ''))) NOT IN ('fortnightly', 'saf_inspection')
    LIMIT 1
");

$job_q->bind_param("ii", $job_id, $tech_id);
$job_q->execute();

$job = $job_q->get_result()->fetch_assoc();

if (!$job) {
    $_SESSION['error'] = "Job not found or not approved by owner yet.";
    header("Location: assigned_lifts.php");
    exit;
}

/* =========================================================
   TRANSACTION
========================================================= */

$conn->begin_transaction();

try {

    /* =====================================================
       CLOSE MAINTENANCE JOB
    ===================================================== */

    $upd1 = $conn->prepare("
        UPDATE maintenance_jobs
        SET status = 'closed'
        WHERE id = ?
          AND technician_id = ?
          AND status = 'approved'
    ");

    $upd1->bind_param("ii", $job_id, $tech_id);

    if (!$upd1->execute()) {
        throw new Exception("Unable to close maintenance job.");
    }

    if ($upd1->affected_rows !== 1) {
        throw new Exception("Maintenance job could not be closed.");
    }

    /* =====================================================
       SET ASSET ACTIVE
    ===================================================== */

    $asset_id = (int)$job['asset_id'];

    $upd2 = $conn->prepare("
        UPDATE assets
        SET status = 'Active'
        WHERE id = ?
    ");

    $upd2->bind_param("i", $asset_id);

    if (!$upd2->execute()) {
        throw new Exception("Unable to update asset status.");
    }

    /* =====================================================
       COMMIT
    ===================================================== */

    $conn->commit();

    $_SESSION['success'] =
        "Maintenance job closed successfully. Asset is now Active.";

} catch (Throwable $e) {

    $conn->rollback();

    $_SESSION['error'] =
        "Unable to close maintenance job. Please try again.";
}

header("Location: assigned_lifts.php");
exit;