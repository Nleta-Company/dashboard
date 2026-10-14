<?php
/* =========================================================
   NLETA OWNER — CHECKLIST APPROVAL ACTION
========================================================= */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/security.php';

require_role('owner');

date_default_timezone_set('Asia/Kolkata');

$ownerId = (int)($_SESSION['uid'] ?? $_SESSION['user_id'] ?? 0);

if ($ownerId <= 0) {
    header('Location: ../login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: approvals.php');
    exit;
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* =========================================================
   CSRF
========================================================= */

if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    die('Invalid request. Please refresh the page and try again.');
}

$jobId = (int)($_POST['job_id'] ?? 0);
$action = strtolower(trim((string)($_POST['action'] ?? '')));
$reason = trim((string)($_POST['reason'] ?? ''));

if ($jobId <= 0) {
    die('Invalid checklist ID.');
}

if (!in_array($action, ['approve', 'reject'], true)) {
    die('Invalid approval action.');
}

if ($action === 'reject' && $reason === '') {
    die('Please enter a rejection / return reason.');
}

if (mb_strlen($reason) > 2000) {
    die('Rejection reason cannot exceed 2000 characters.');
}

/* =========================================================
   LOAD OWNER'S PENDING JOB
========================================================= */

$stmt = $conn->prepare("
    SELECT
        mj.id,
        mj.owner_id,
        mj.technician_id,
        mj.saf_id,
        mj.status,
        mj.checklist,

        s.saf_no,
        s.psga_no

    FROM maintenance_jobs mj

    INNER JOIN saf_contracts s
        ON s.id = mj.saf_id

    WHERE
        mj.id = ?
        AND mj.owner_id = ?
        AND mj.job_type = 'saf_inspection'

    LIMIT 1
");

if (!$stmt) {
    die('Unable to prepare approval query: ' . h($conn->error));
}

$stmt->bind_param('ii', $jobId, $ownerId);
$stmt->execute();

$job = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$job) {
    die('Checklist not found or you do not have permission to approve it.');
}

if ($job['status'] !== 'pending_owner') {
    die(
        'This checklist is no longer waiting for Owner approval. ' .
        'Current status: ' . h($job['status'])
    );
}

/* =========================================================
   DECODE EXISTING CHECKLIST
========================================================= */

$checklist = [];

if (!empty($job['checklist'])) {

    $decoded =
        json_decode(
            $job['checklist'],
            true
        );

    if (is_array($decoded)) {
        $checklist = $decoded;
    }
}

/* =========================================================
   OWNER APPROVAL DATA
========================================================= */

$checklist['owner_approval'] = [

    'status' =>
        $action === 'approve'
            ? 'approved'
            : 'rejected',

    'owner_id' =>
        $ownerId,

    'action_at' =>
        date('Y-m-d H:i:s'),

    'reason' =>
        $action === 'reject'
            ? $reason
            : ''
];

$updatedChecklist =
    json_encode(
        $checklist,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
    );

if ($updatedChecklist === false) {
    die('Unable to prepare approval data.');
}

/* =========================================================
   STATUS
========================================================= */

/*
 * Approved:
 *     approved
 *
 * Rejected / Returned:
 *     in_progress
 *
 * This allows the technician to continue/rework the
 * inspection without requiring a new SAF assignment.
 */

$newStatus =
    $action === 'approve'
        ? 'approved'
        : 'in_progress';


/* =========================================================
   SAVE
========================================================= */

$update = $conn->prepare("
    UPDATE maintenance_jobs
    SET
        checklist = ?,
        status = ?,
        updated_at = NOW()
    WHERE
        id = ?
        AND owner_id = ?
        AND status = 'pending_owner'
        AND job_type = 'saf_inspection'
    LIMIT 1
");

if (!$update) {
    die('Unable to prepare approval update: ' . h($conn->error));
}

$update->bind_param(
    'ssii',
    $updatedChecklist,
    $newStatus,
    $jobId,
    $ownerId
);

if (!$update->execute()) {
    $error = $update->error;
    $update->close();

    die(
        'Unable to save Owner decision: ' .
        h($error)
    );
}

$affectedRows = $update->affected_rows;

$update->close();

if ($affectedRows < 1) {
    die('The checklist was not updated. It may already have been processed.');
}

/* =========================================================
   SUCCESS MESSAGE
========================================================= */

if ($action === 'approve') {

    $_SESSION['success'] =
        'Checklist approved successfully.';

} else {

    $_SESSION['success'] =
        'Checklist returned to the Technician.';

}

header(
    'Location: view-checklist.php?job_id=' .
    $jobId
);

exit;
?>