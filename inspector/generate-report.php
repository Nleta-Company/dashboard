<?php
/*
|--------------------------------------------------------------------------
| NLETA INSPECTOR — GENERATE REPORT
|--------------------------------------------------------------------------
| Compatibility entry point for existing links:
| generate-report.php?request_id=ID
|
| Final payment rule:
| The report generator is unlocked only after the Final payment
| is VERIFIED by Admin.
|
| Actual PAT/SAT/SCI generator:
| generator.php
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

require_role('inspector');

date_default_timezone_set('Asia/Kolkata');

$requestId = (int)(
    $_GET['request_id']
    ?? $_POST['request_id']
    ?? 0
);

if ($requestId <= 0) {
    header('Location: report_requests.php');
    exit;
}

$inspectorId = (int)(
    $_SESSION['uid']
    ?? $_SESSION['user_id']
    ?? 0
);

if ($inspectorId <= 0) {
    header('Location: dashboard.php');
    exit;
}

/* ---------------------------------------------------------
   SERVER-SIDE FINAL PAYMENT GATE
--------------------------------------------------------- */
$stmt = $conn->prepare("
    SELECT
        rr.id AS request_id,
        s.psga_no
    FROM report_requests rr
    INNER JOIN maintenance_jobs mj
        ON mj.id = rr.job_id
    INNER JOIN saf_contracts s
        ON s.id = mj.saf_id
    WHERE
        rr.id = ?
        AND rr.inspector_id = ?
        AND mj.job_type = 'saf_inspection'
    LIMIT 1
");

if (!$stmt) {
    $_SESSION['error'] = 'Unable to verify Final payment before report generation.';
    header('Location: report_requests.php');
    exit;
}

$stmt->bind_param('ii', $requestId, $inspectorId);

if (!$stmt->execute()) {
    $stmt->close();
    $_SESSION['error'] = 'Unable to verify Final payment before report generation.';
    header('Location: report_requests.php');
    exit;
}

$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    $_SESSION['error'] = 'Report request not found or it is not assigned to you.';
    header('Location: report_requests.php');
    exit;
}

$psgaNo = trim((string)($row['psga_no'] ?? ''));
$finalPaymentVerified = false;

if ($psgaNo !== '') {
    $paymentStmt = $conn->prepare("
        SELECT 1
        FROM nleta_offer_master o
        INNER JOIN payment_schedule ps
            ON ps.Offer_ID = o.id
        LEFT JOIN payment_transactions pt
            ON pt.Schedule_ID = ps.Schedule_ID
        WHERE
            TRIM(COALESCE(o.psga_no, '')) = ?
            AND ps.Payment_Stage = 'Final'
            AND (
                LOWER(TRIM(COALESCE(ps.Status, ''))) = 'verified'
                OR LOWER(TRIM(COALESCE(pt.Status, ''))) = 'verified'
            )
        LIMIT 1
    ");

    if ($paymentStmt) {
        $paymentStmt->bind_param('s', $psgaNo);
        if ($paymentStmt->execute()) {
            $finalPaymentVerified = (bool)$paymentStmt->get_result()->fetch_row();
        }
        $paymentStmt->close();
    }
}

if (!$finalPaymentVerified) {
    $_SESSION['error'] = 'Final payment must be verified by Admin before the inspection report can be generated.';
    header('Location: report_requests.php');
    exit;
}

header('Location: generator.php?request_id=' . $requestId);
exit;
?>
