<?php

include('../config/db.php');
include('../config/auth.php');

require_role('technician');

$tech_id = (int)($_SESSION['uid'] ?? 0);

$job_id = isset($_GET['id'])
    ? (int)$_GET['id']
    : 0;

if ($job_id <= 0) {
    $_SESSION['error'] = "Invalid maintenance job.";
    header("Location: assigned_lifts.php");
    exit;
}

/* =========================================================
   VALIDATE JOB
========================================================= */

$job_q = $conn->prepare("
    SELECT
        id,
        asset_id,
        status,
        technician_response,
        job_type
    FROM maintenance_jobs
    WHERE id = ?
      AND technician_id = ?
      AND LOWER(TRIM(COALESCE(job_type, ''))) NOT IN ('fortnightly', 'saf_inspection')
    LIMIT 1
");

$job_q->bind_param(
    "ii",
    $job_id,
    $tech_id
);

$job_q->execute();

$job = $job_q->get_result()->fetch_assoc();

if (!$job) {

    $_SESSION['error'] =
        "Maintenance job not found.";

    header("Location: assigned_lifts.php");
    exit;
}

/* =========================================================
   MUST ACCEPT BEFORE STARTING
========================================================= */

if (strtolower(trim((string)$job['technician_response'])) !== 'accepted') {

    $_SESSION['error'] =
        "You must accept the maintenance request before starting work.";

    header("Location: assigned_lifts.php");
    exit;
}

/* =========================================================
   ALLOWED STATES
=========================================================

   assigned
       → first time start

   rejected
       → owner rejected previous submission
       → technician can rework

   in_progress
       → already started
========================================================= */

$current_status = strtolower(trim((string)$job['status']));

/* =========================================================
   ALREADY IN PROGRESS
========================================================= */

if ($current_status === 'in_progress') {

    $_SESSION['success'] =
        "Maintenance work is already in progress.";

    header(
        "Location: maintenance_submit.php?job_id={$job_id}"
    );

    exit;
}

/* =========================================================
   ONLY ASSIGNED / REJECTED CAN START
========================================================= */

if (!in_array($current_status, ['assigned', 'rejected'], true)) {

    $_SESSION['error'] =
        "This job cannot be started in its current status.";

    header("Location: assigned_lifts.php");
    exit;
}

/* =========================================================
   TRANSACTION
========================================================= */

$conn->begin_transaction();

try {

    /* =====================================================
       START / RESTART WORK
    ===================================================== */

    $upd1 = $conn->prepare("
        UPDATE maintenance_jobs
        SET status = 'in_progress'
        WHERE id = ?
          AND technician_id = ?
          AND technician_response = 'accepted'
          AND LOWER(TRIM(COALESCE(job_type, '')))
              NOT IN ('fortnightly', 'saf_inspection')
          AND status IN ('assigned', 'rejected')
    ");

    $upd1->bind_param(
        "ii",
        $job_id,
        $tech_id
    );

    if (!$upd1->execute()) {
        throw new Exception("Unable to start maintenance job.");
    }

    if ($upd1->affected_rows !== 1) {
        throw new Exception("Maintenance job could not be started.");
    }

    /* =====================================================
       ASSET → UNDER MAINTENANCE
    ===================================================== */

    $asset_id = (int)$job['asset_id'];

    $upd2 = $conn->prepare("
        UPDATE assets
        SET status = 'Under Maintenance'
        WHERE id = ?
    ");

    $upd2->bind_param(
        "i",
        $asset_id
    );

    if (!$upd2->execute()) {
        throw new Exception("Unable to update asset status.");
    }

    /* =====================================================
       COMMIT
    ===================================================== */

    $conn->commit();

    $_SESSION['success'] =
        "Work started. Please complete and submit the maintenance checklist.";

    header(
        "Location: maintenance_submit.php?job_id={$job_id}"
    );

    exit;

} catch (Throwable $e) {

    $conn->rollback();

    $_SESSION['error'] =
        "Unable to start maintenance work. Please try again.";

    header("Location: assigned_lifts.php");
    exit;
}