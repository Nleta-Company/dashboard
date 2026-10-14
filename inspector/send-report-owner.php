<?php
/* =========================================================
   NLETA INSPECTOR — SEND GENERATED REPORT TO OWNER
   ---------------------------------------------------------
   Workflow:

   Owner requests final report
        ↓
   Inspector sends request to Admin
        ↓
   Admin approves
        ↓
   Inspector generates PAT-R01
        ↓
   Inspector sends generated report to Owner
        ↓
   Owner can View Report

   IMPORTANT:
   - Only the assigned Inspector can send the report.
   - Inspector authorization uses inspector_assignments.
   - maintenance_jobs.inspector_id is NOT used.
   - The PDF itself is NOT changed.
   - report_path is the generated PDF path.
   - Status changes:
         generated → sent_to_owner
   - sent_at is recorded when the column exists.
   - owner_notified_at is recorded when the column exists.
   - Timezone: Asia/Kolkata / IST.
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
   INSPECTOR
========================================================= */

$inspectorId = (int)(
    $_SESSION['uid']
    ?? $_SESSION['user_id']
    ?? 0
);

if ($inspectorId <= 0) {

    $_SESSION['error'] =
        'Inspector session not found.';

    header('Location: report_requests.php');
    exit;
}


/* =========================================================
   REQUEST ID
========================================================= */

$requestId = (int)(
    $_GET['request_id']
    ?? $_POST['request_id']
    ?? 0
);

if ($requestId <= 0) {

    $_SESSION['error'] =
        'Invalid report request.';

    header('Location: report_requests.php');
    exit;
}


/* =========================================================
   LOAD REPORT REQUEST
   ---------------------------------------------------------
   Inspector must:
   1. Own the report request through rr.inspector_id.
   2. Be assigned to the same inspection through
      inspector_assignments.
   3. The job must be saf_inspection.
========================================================= */

$sql = "
    SELECT
        rr.id AS request_id,
        rr.job_id,
        rr.owner_id,
        rr.inspector_id,
        rr.status,
        rr.report_path,
        rr.requested_at,
        rr.owner_message,

        mj.status AS job_status,
        mj.job_type,
        mj.recheck_status,
        mj.recheck_date,

        owner.name AS owner_name,
        owner.email AS owner_email,

        a.asset_name,
        a.display_asset_id,
        a.location,

        s.psga_no,
        s.saf_no,
        s.service_name

    FROM report_requests rr

    INNER JOIN maintenance_jobs mj
        ON mj.id = rr.job_id
       AND mj.job_type = 'saf_inspection'

    INNER JOIN inspector_assignments ia
        ON ia.job_id = rr.job_id
       AND ia.inspector_id = rr.inspector_id

    LEFT JOIN users owner
        ON owner.id = rr.owner_id

    LEFT JOIN assets a
        ON a.id = mj.asset_id

    LEFT JOIN saf_contracts s
        ON s.id = mj.saf_id

    WHERE
        rr.id = ?
        AND rr.inspector_id = ?
        AND ia.inspector_id = ?

    LIMIT 1
";

$stmt = $conn->prepare($sql);

if (!$stmt) {

    $_SESSION['error'] =
        'Database error while loading report request: '
        . $conn->error;

    header('Location: report_requests.php');
    exit;
}

$stmt->bind_param(
    'iii',
    $requestId,
    $inspectorId,
    $inspectorId
);

if (!$stmt->execute()) {

    $error = $stmt->error;
    $stmt->close();

    $_SESSION['error'] =
        'Unable to load report request: ' . $error;

    header('Location: report_requests.php');
    exit;
}

$request = $stmt
    ->get_result()
    ->fetch_assoc();

$stmt->close();


/* =========================================================
   VALIDATE REQUEST
========================================================= */

if (!$request) {

    $_SESSION['error'] =
        'Report request not found or it is not assigned to you.';

    header('Location: report_requests.php');
    exit;
}


/* =========================================================
   STATUS
========================================================= */

$status = strtolower(
    trim(
        (string)(
            $request['status']
            ?? ''
        )
    )
);


/*
 * Sending is allowed only after the PDF has been generated.
 *
 * generated:
 *      First send.
 *
 * sent / sent_to_owner:
 *      Allow sending again if required.
 *
 * completed:
 *      Kept for compatibility with older records.
 */
$allowedStatuses = [
    'generated',
    'sent',
    'sent_to_owner',
    'completed'
];

if (!in_array($status, $allowedStatuses, true)) {

    $_SESSION['error'] =
        'The report must be generated before it can be sent to the Owner. Current status: '
        . ($status !== '' ? $status : 'unknown');

    header('Location: report_requests.php');
    exit;
}


/* =========================================================
   REPORT PATH
========================================================= */

$reportPath = trim(
    (string)(
        $request['report_path']
        ?? ''
    )
);

if ($reportPath === '') {

    $_SESSION['error'] =
        'Generated report path is empty. Please generate the PAT-R01 report first.';

    header('Location: report_requests.php');
    exit;
}


/* =========================================================
   VERIFY PHYSICAL PDF
   ---------------------------------------------------------
   report_path should normally be:
       reports/generated/FILENAME.pdf

   Only the reports/generated directory is accepted for
   relative paths.
========================================================= */

$reportDirectory = realpath(
    __DIR__
    . '/../reports/generated'
);

if ($reportDirectory === false) {

    $_SESSION['error'] =
        'The reports/generated directory does not exist.';

    header('Location: report_requests.php');
    exit;
}

$cleanPath = str_replace(
    '\\',
    '/',
    trim($reportPath)
);

$cleanPath = ltrim(
    $cleanPath,
    '/'
);


/* ---------------------------------------------------------
   Determine filename only.
   This prevents arbitrary filesystem access.
--------------------------------------------------------- */

$fileName = basename($cleanPath);

if (
    $fileName === ''
    ||
    $fileName === '.'
    ||
    $fileName === '..'
    ||
    strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) !== 'pdf'
) {

    $_SESSION['error'] =
        'The generated report file path is invalid.';

    header('Location: report_requests.php');
    exit;
}


/* ---------------------------------------------------------
   Prefer reports/generated/<filename>.
--------------------------------------------------------- */

$candidate = $reportDirectory
    . DIRECTORY_SEPARATOR
    . $fileName;


/* ---------------------------------------------------------
   Verify candidate remains inside report directory.
--------------------------------------------------------- */

$candidateReal = false;

if (is_file($candidate)) {

    $candidateReal = realpath($candidate);
}

if ($candidateReal === false) {

    $_SESSION['error'] =
        'The generated PDF could not be found on the server. Please generate the report again.';

    header('Location: report_requests.php');
    exit;
}

$normalizedDirectory = rtrim(
    $reportDirectory,
    DIRECTORY_SEPARATOR
) . DIRECTORY_SEPARATOR;

if (
    strpos(
        $candidateReal,
        $normalizedDirectory
    ) !== 0
) {

    $_SESSION['error'] =
        'Invalid report file location.';

    header('Location: report_requests.php');
    exit;
}


/* ---------------------------------------------------------
   Verify readable and non-empty.
--------------------------------------------------------- */

if (!is_readable($candidateReal)) {

    $_SESSION['error'] =
        'The generated PDF cannot be read by the server.';

    header('Location: report_requests.php');
    exit;
}

$fileSize = filesize($candidateReal);

if (
    $fileSize === false
    ||
    $fileSize <= 0
) {

    $_SESSION['error'] =
        'The generated PDF is empty. Please regenerate the report.';

    header('Location: report_requests.php');
    exit;
}


/* =========================================================
   CHECK AVAILABLE COLUMNS
   ---------------------------------------------------------
   Keeps this file compatible with the current table while
   using optional notification/timestamp fields when present.
========================================================= */

$columns = [];

$columnResult = $conn->query(
    'SHOW COLUMNS FROM report_requests'
);

if ($columnResult) {

    while (
        $column = $columnResult->fetch_assoc()
    ) {

        $columns[] =
            $column['Field'];
    }
}


/* =========================================================
   REQUIRED COLUMNS
========================================================= */

$requiredColumns = [
    'id',
    'inspector_id',
    'status',
    'report_path'
];

foreach ($requiredColumns as $requiredColumn) {

    if (!in_array(
        $requiredColumn,
        $columns,
        true
    )) {

        $_SESSION['error'] =
            'The report_requests table is missing required column: '
            . $requiredColumn;

        header('Location: report_requests.php');
        exit;
    }
}


/* =========================================================
   BUILD UPDATE
========================================================= */

$setParts = [
    "status = 'sent_to_owner'"
];

$types = '';
$values = [];


/* ---------------------------------------------------------
   sent_at
--------------------------------------------------------- */

if (
    in_array(
        'sent_at',
        $columns,
        true
    )
) {

    $setParts[] =
        'sent_at = NOW()';
}


/* ---------------------------------------------------------
   owner_notified_at
--------------------------------------------------------- */

if (
    in_array(
        'owner_notified_at',
        $columns,
        true
    )
) {

    $setParts[] =
        'owner_notified_at = NOW()';
}


/* ---------------------------------------------------------
   updated_at
--------------------------------------------------------- */

if (
    in_array(
        'updated_at',
        $columns,
        true
    )
) {

    $setParts[] =
        'updated_at = NOW()';
}


/* ---------------------------------------------------------
   report_path
   ---------------------------------------------------------
   Keep the verified relative path so Owner's view-report.php
   can open the same generated PDF.
--------------------------------------------------------- */

$setParts[] =
    'report_path = ?';

$types .= 's';

$relativeReportPath =
    'reports/generated/' . $fileName;

$values[] =
    $relativeReportPath;


/* =========================================================
   UPDATE REQUEST
========================================================= */

$updateSql = "
    UPDATE report_requests
    SET
        " . implode(
            ', ',
            $setParts
        ) . "
    WHERE
        id = ?
        AND inspector_id = ?
    LIMIT 1
";

$types .= 'ii';

$values[] =
    $requestId;

$values[] =
    $inspectorId;


$update = $conn->prepare(
    $updateSql
);

if (!$update) {

    $_SESSION['error'] =
        'Unable to prepare Owner report delivery: '
        . $conn->error;

    header('Location: report_requests.php');
    exit;
}


/* =========================================================
   DYNAMIC BIND
========================================================= */

$bindParams = [];

$bindParams[] =
    $types;

foreach (
    $values
    as $key => $value
) {

    $bindParams[] =
        &$values[$key];
}

call_user_func_array(
    [
        $update,
        'bind_param'
    ],
    $bindParams
);


/* =========================================================
   EXECUTE
========================================================= */

if (!$update->execute()) {

    $error =
        $update->error;

    $update->close();

    $_SESSION['error'] =
        'Unable to send report to Owner: '
        . $error;

    header('Location: report_requests.php');
    exit;
}

$update->close();


/* =========================================================
   OPTIONAL EMAIL NOTIFICATION
   ---------------------------------------------------------
   The database workflow is completed above.

   If your project already has an email helper, it can be
   connected here without changing report generation.
   We intentionally do not call mail() blindly because local
   XAMPP usually has no SMTP configuration.

   The Owner receives the report through the Owner dashboard
   using report_path.
========================================================= */


/* =========================================================
   SUCCESS
========================================================= */

$_SESSION['success'] =
    'PAT-R01 report sent to Owner successfully.';


/* =========================================================
   RETURN
========================================================= */

header(
    'Location: report_requests.php'
);

exit;
?>