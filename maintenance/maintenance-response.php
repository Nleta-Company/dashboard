<?php

include('../config/db.php');
include('../config/auth.php');
require_once('../config/security.php');

require_role('technician');

$tech_id = (int)($_SESSION['uid'] ?? 0);


/* =========================================================
   ONLY POST REQUESTS
========================================================= */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: assigned_lifts.php");
    exit;
}


/* =========================================================
   CSRF
========================================================= */

if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {

    $_SESSION['error'] =
        'Invalid request. Please try again.';

    header("Location: assigned_lifts.php");
    exit;
}


/* =========================================================
   GET DATA
========================================================= */

$job_id = (int)($_POST['job_id'] ?? 0);

$response = trim(
    $_POST['response'] ?? ''
);

$reject_reason = trim(
    $_POST['reject_reason'] ?? ''
);


/* =========================================================
   VALID JOB ID
========================================================= */

if ($job_id <= 0) {

    $_SESSION['error'] =
        'Invalid maintenance job.';

    header("Location: assigned_lifts.php");
    exit;
}


/* =========================================================
   VALID RESPONSE
========================================================= */

if (!in_array(
    $response,
    ['accepted', 'rejected'],
    true
)) {

    $_SESSION['error'] =
        'Invalid technician response.';

    header("Location: assigned_lifts.php");
    exit;
}


/* =========================================================
   REJECTION REASONS
========================================================= */

$allowedReasons = [

    'Already assigned to another job',
    'Not available at this time',
    'Location too far',
    'Emergency work assigned',
    'Leave / unavailable',
    'Insufficient time to attend',
    'Issue outside my responsibility',
    'Other'
];


/* =========================================================
   VALIDATE REJECTION REASON
========================================================= */

if ($response === 'rejected') {

    if ($reject_reason === '') {

        $_SESSION['error'] =
            'Please select a rejection reason.';

        header("Location: assigned_lifts.php");
        exit;
    }

    if (!in_array(
        $reject_reason,
        $allowedReasons,
        true
    )) {

        $_SESSION['error'] =
            'Invalid rejection reason.';

        header("Location: assigned_lifts.php");
        exit;
    }
}


/* =========================================================
   LOAD AMC JOB
========================================================= */

$stmt = $conn->prepare("
    SELECT
        id,
        technician_id,
        asset_id,
        status,
        technician_response,
        job_type
    FROM maintenance_jobs
    WHERE id = ?
      AND technician_id = ?
      AND LOWER(TRIM(COALESCE(job_type, '')))
          NOT IN ('fortnightly', 'saf_inspection')
    LIMIT 1
");

$stmt->bind_param(
    "ii",
    $job_id,
    $tech_id
);

$stmt->execute();

$job = $stmt
    ->get_result()
    ->fetch_assoc();


/* =========================================================
   JOB NOT FOUND
========================================================= */

if (!$job) {

    $_SESSION['error'] =
        'AMC maintenance job not found or not assigned to you.';

    header("Location: assigned_lifts.php");
    exit;
}


/* =========================================================
   ONLY ASSIGNED JOB CAN RECEIVE FIRST RESPONSE
========================================================= */

if ($job['status'] !== 'assigned') {

    $_SESSION['error'] =
        'This maintenance request is no longer available for response.';

    header("Location: assigned_lifts.php");
    exit;
}


/* =========================================================
   PREVENT DOUBLE RESPONSE
========================================================= */

if (
    !empty($job['technician_response']) &&
    $job['technician_response'] !== null
) {

    $_SESSION['error'] =
        'You have already responded to this maintenance request.';

    header("Location: assigned_lifts.php");
    exit;
}


/* =========================================================
   ACCEPT
========================================================= */

if ($response === 'accepted') {

    $upd = $conn->prepare("
        UPDATE maintenance_jobs
        SET
            technician_response = 'accepted',
            technician_reject_reason = NULL,
            technician_responded_at = NOW()
        WHERE id = ?
          AND technician_id = ?
          AND status = 'assigned'
          AND technician_response IS NULL
          AND LOWER(TRIM(COALESCE(job_type, '')))
              NOT IN ('fortnightly', 'saf_inspection')
    ");

    $upd->bind_param(
        "ii",
        $job_id,
        $tech_id
    );

    if (
        $upd->execute() &&
        $upd->affected_rows === 1
    ) {

        $_SESSION['success'] =
            'Maintenance request accepted. You can now start work.';

    } else {

        $_SESSION['error'] =
            'Unable to accept the maintenance request. Please try again.';
    }

    header("Location: assigned_lifts.php");
    exit;
}


/* =========================================================
   REJECT
========================================================= */

if ($response === 'rejected') {

    $upd = $conn->prepare("
        UPDATE maintenance_jobs
        SET
            technician_response = 'rejected',
            technician_reject_reason = ?,
            technician_responded_at = NOW(),
            status = 'rejected'
        WHERE id = ?
          AND technician_id = ?
          AND status = 'assigned'
          AND technician_response IS NULL
          AND LOWER(TRIM(COALESCE(job_type, '')))
              NOT IN ('fortnightly', 'saf_inspection')
    ");

    $upd->bind_param(
        "sii",
        $reject_reason,
        $job_id,
        $tech_id
    );

    if (
        $upd->execute() &&
        $upd->affected_rows === 1
    ) {

        $_SESSION['success'] =
            'Maintenance request rejected successfully.';

    } else {

        $_SESSION['error'] =
            'Unable to reject the maintenance request. Please try again.';
    }

    header("Location: assigned_lifts.php");
    exit;
}


header("Location: assigned_lifts.php");
exit;