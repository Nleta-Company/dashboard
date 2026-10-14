<?php
/* =========================================================
   NLETA INSPECTOR — SEND REPORT FOR ADMIN APPROVAL
   ---------------------------------------------------------
   Workflow:

   Owner requests report
          ↓
   Inspector clicks "Send for Admin Approval"
          ↓
   report_requests.status = admin_pending
          ↓
   Admin approves
          ↓
   Inspector generates report

   IMPORTANT:
   - Inspector only.
   - Uses inspector_id, NOT technician_id.
   - Only the assigned Inspector can update the request.
   - Original checklist data is NOT changed.
   - Timezone: Asia/Kolkata / IST
========================================================= */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

require_role('inspector');

date_default_timezone_set('Asia/Kolkata');


/* =========================================================
   HELPER
========================================================= */

function h($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}


/* =========================================================
   INSPECTOR ID
========================================================= */

$inspectorId = (int)(
    $_SESSION['uid']
    ??
    $_SESSION['user_id']
    ??
    0
);

if ($inspectorId <= 0) {

    header(
        'Location: report_requests.php'
    );

    exit;
}


/* =========================================================
   REQUEST ID
========================================================= */

$requestId = (int)(
    $_GET['request_id']
    ??
    $_POST['request_id']
    ??
    0
);

if ($requestId <= 0) {

    $_SESSION['error'] =
        'Invalid report request.';

    header(
        'Location: report_requests.php'
    );

    exit;
}


/* =========================================================
   LOAD REQUEST
   ---------------------------------------------------------
   Authorization is based on BOTH:
   1. report_requests.inspector_id
   2. inspector_assignments.inspector_id
========================================================= */

$stmt = $conn->prepare("
    SELECT

        rr.id,
        rr.job_id,
        rr.owner_id,
        rr.inspector_id,
        rr.status,
        rr.owner_message,
        rr.requested_at,

        mj.status AS job_status,
        mj.job_type,
        mj.recheck_status,
        mj.recheck_date,

        a.asset_name,
        a.display_asset_id,
        a.location,

        owner.name AS owner_name,

        s.psga_no,
        s.saf_no,
        s.service_name

    FROM report_requests rr

    LEFT JOIN maintenance_jobs mj
        ON mj.id = rr.job_id

    LEFT JOIN assets a
        ON a.id = mj.asset_id

    LEFT JOIN users owner
        ON owner.id = rr.owner_id

    LEFT JOIN saf_contracts s
        ON s.id = mj.saf_id

    WHERE
        rr.id = ?
        AND rr.inspector_id = ?
        AND EXISTS (
            SELECT 1
            FROM inspector_assignments ia
            WHERE ia.job_id = rr.job_id
              AND ia.inspector_id = rr.inspector_id
        )

    LIMIT 1
");

if (!$stmt) {

    $_SESSION['error'] =
        'Unable to verify report request: '
        . $conn->error;

    header(
        'Location: report_requests.php'
    );

    exit;
}

$stmt->bind_param(
    'ii',
    $requestId,
    $inspectorId
);

if (!$stmt->execute()) {

    $stmtError =
        $stmt->error;

    $stmt->close();

    $_SESSION['error'] =
        'Unable to load report request: '
        . $stmtError;

    header(
        'Location: report_requests.php'
    );

    exit;
}

$request = $stmt
    ->get_result()
    ->fetch_assoc();

$stmt->close();


/* =========================================================
   NOT FOUND / NOT AUTHORIZED
========================================================= */

if (!$request) {

    $_SESSION['error'] =
        'Report request not found or it is not assigned to you.';

    header(
        'Location: report_requests.php'
    );

    exit;
}


/* =========================================================
   CURRENT STATUS
========================================================= */

$currentStatus = strtolower(
    trim(
        (string)(
            $request['status']
            ??
            ''
        )
    )
);


/* =========================================================
   ALREADY SENT FOR APPROVAL
========================================================= */

if (
    $currentStatus === 'admin_pending'
    ||
    $currentStatus === 'approval_pending'
) {

    $_SESSION['success'] =
        'This report is already waiting for Admin approval.';

    header(
        'Location: report_requests.php'
    );

    exit;
}


/* =========================================================
   ALREADY APPROVED
========================================================= */

if (
    $currentStatus === 'admin_approved'
    ||
    $currentStatus === 'approved'
) {

    $_SESSION['success'] =
        'Admin has already approved this report request.';

    header(
        'Location: report_requests.php'
    );

    exit;
}


/* =========================================================
   ALREADY GENERATED
========================================================= */

if ($currentStatus === 'generated') {

    $_SESSION['success'] =
        'The report has already been generated.';

    header(
        'Location: report_requests.php'
    );

    exit;
}


/* =========================================================
   VALID SOURCE STATUS
========================================================= */

$allowedSourceStatuses = [
    'requested',
    'report_requested'
];

if (
    !in_array(
        $currentStatus,
        $allowedSourceStatuses,
        true
    )
) {

    $_SESSION['error'] =
        'This report request cannot be sent for Admin approval from its current status: '
        .
        (
            $currentStatus !== ''
            ?
            $currentStatus
            :
            'unknown'
        )
        .
        '.';

    header(
        'Location: report_requests.php'
    );

    exit;
}


/* =========================================================
   JOB CHECK
========================================================= */

$jobId = (int)(
    $request['job_id']
    ??
    0
);

if ($jobId <= 0) {

    $_SESSION['error'] =
        'The report request is missing its inspection job.';

    header(
        'Location: report_requests.php'
    );

    exit;
}


/* =========================================================
   UPDATE
   ---------------------------------------------------------
   requested / report_requested
                  ↓
              admin_pending

   The checklist itself is untouched.
========================================================= */

$updateStmt = $conn->prepare("
    UPDATE report_requests

    SET
        status = 'admin_pending'

    WHERE
        id = ?
        AND inspector_id = ?
        AND status IN (
            'requested',
            'report_requested'
        )

    LIMIT 1
");

if (!$updateStmt) {

    $_SESSION['error'] =
        'Unable to prepare Admin approval request: '
        . $conn->error;

    header(
        'Location: report_requests.php'
    );

    exit;
}

$updateStmt->bind_param(
    'ii',
    $requestId,
    $inspectorId
);

$executed =
    $updateStmt->execute();

$affected =
    $updateStmt->affected_rows;

$updateError =
    $updateStmt->error;

$updateStmt->close();


/* =========================================================
   RESULT
========================================================= */

if (
    $executed
    &&
    $affected > 0
) {

    $_SESSION['success'] =
        'Report approval request sent to Admin successfully.';

} else {

    if ($updateError !== '') {

        $_SESSION['error'] =
            'Unable to send report approval request: '
            . $updateError;

    } else {

        $_SESSION['error'] =
            'Unable to send report approval request. '
            .
            'The request may already have been processed.';
    }
}


/* =========================================================
   RETURN
========================================================= */

header(
    'Location: report_requests.php'
);

exit;
?>
