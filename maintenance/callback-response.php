<?php

include('../config/db.php');
include('../config/auth.php');
require_once('../config/security.php');

require_role('technician');

$tech_id = (int)$_SESSION['uid'];


/* =========================================================
   ONLY POST REQUESTS
========================================================= */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: callbacks.php");
    exit;
}


/* =========================================================
   CSRF
========================================================= */

if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    $_SESSION['error'] = 'Invalid request. Please try again.';
    header("Location: callbacks.php");
    exit;
}


/* =========================================================
   GET DATA
========================================================= */

$callback_id = (int)($_POST['callback_id'] ?? 0);
$response    = trim($_POST['response'] ?? '');
$reject_reason = trim($_POST['reject_reason'] ?? '');


/* =========================================================
   VALIDATE RESPONSE
========================================================= */

if (!$callback_id) {
    $_SESSION['error'] = 'Invalid callback.';
    header("Location: callbacks.php");
    exit;
}

if (!in_array($response, ['accepted', 'rejected'], true)) {
    $_SESSION['error'] = 'Invalid technician response.';
    header("Location: callbacks.php");
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
   VALIDATE REJECTION
========================================================= */

if ($response === 'rejected') {

    if ($reject_reason === '') {
        $_SESSION['error'] = 'Please select a rejection reason.';
        header("Location: callbacks.php");
        exit;
    }

    if (!in_array($reject_reason, $allowedReasons, true)) {
        $_SESSION['error'] = 'Invalid rejection reason.';
        header("Location: callbacks.php");
        exit;
    }
}


/* =========================================================
   GET CALLBACK
========================================================= */

$stmt = $conn->prepare("
    SELECT
        id,
        callback_number,
        technician_id,
        status,
        technician_response
    FROM callbacks
    WHERE id = ?
      AND technician_id = ?
    LIMIT 1
");

$stmt->bind_param(
    "ii",
    $callback_id,
    $tech_id
);

$stmt->execute();

$callback = $stmt->get_result()->fetch_assoc();


if (!$callback) {
    $_SESSION['error'] = 'Callback not found or not assigned to you.';
    header("Location: callbacks.php");
    exit;
}


/* =========================================================
   PREVENT DOUBLE RESPONSE
========================================================= */

if (!empty($callback['technician_response'])) {

    $_SESSION['error'] =
        'You have already responded to this callback.';

    header("Location: callbacks.php");
    exit;
}


/* =========================================================
   ACCEPT CALLBACK
========================================================= */

if ($response === 'accepted') {

    $upd = $conn->prepare("
        UPDATE callbacks
        SET
            technician_response = 'accepted',
            technician_reject_reason = NULL,
            technician_responded_at = NOW(),
            status = 'in_progress',
            work_started_at = NOW()
        WHERE id = ?
          AND technician_id = ?
          AND technician_response IS NULL
    ");

    $upd->bind_param(
        "ii",
        $callback_id,
        $tech_id
    );


    if ($upd->execute() && $upd->affected_rows > 0) {

        $_SESSION['success'] =
            'Callback accepted successfully. You can now submit the work report.';

    } else {

        $_SESSION['error'] =
            'Unable to accept the callback. Please try again.';
    }

    header("Location: callbacks.php");
    exit;
}


/* =========================================================
   REJECT CALLBACK
========================================================= */

if ($response === 'rejected') {

    $upd = $conn->prepare("
        UPDATE callbacks
        SET
            technician_response = 'rejected',
            technician_reject_reason = ?,
            technician_responded_at = NOW()
        WHERE id = ?
          AND technician_id = ?
          AND technician_response IS NULL
    ");

    $upd->bind_param(
        "sii",
        $reject_reason,
        $callback_id,
        $tech_id
    );


    if ($upd->execute() && $upd->affected_rows > 0) {

        $_SESSION['success'] =
            'Callback rejected successfully.';

    } else {

        $_SESSION['error'] =
            'Unable to reject the callback. Please try again.';
    }

    header("Location: callbacks.php");
    exit;
}


header("Location: callbacks.php");
exit;