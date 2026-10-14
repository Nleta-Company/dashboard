<?php
/* =========================================================
   NLETA OWNER — REQUEST INSPECTION REPORT FOR INSPECTOR
   ---------------------------------------------------------
   Owner requests the final report from the assigned
   inspector.

   Workflow:

   Owner
      ↓
   Request Report
      ↓
   Inspector
      ↓
   Generate / Send Report
      ↓
   Owner
========================================================= */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/security.php';

require_role('owner');

date_default_timezone_set('Asia/Kolkata');
const NLETA_TIMEZONE = 'Asia/Kolkata';


/* =========================================================
   OWNER
========================================================= */

$ownerId = (int)(
    $_SESSION['uid']
    ?? $_SESSION['user_id']
    ?? 0
);


/* =========================================================
   JOB
========================================================= */

$jobId = (int)(
    $_POST['job_id']
    ?? $_GET['job_id']
    ?? 0
);


if ($ownerId <= 0) {

    header(
        'Location: ../login.php'
    );

    exit;
}


if ($jobId <= 0) {

    $_SESSION['error'] =
        'Invalid checklist request.';

    header(
        'Location: approvals.php'
    );

    exit;
}


/* =========================================================
   POST ONLY
========================================================= */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    header(
        'Location: view-checklist.php?job_id='
        . $jobId
    );

    exit;
}


/* =========================================================
   CSRF
========================================================= */

if (
    !validateCSRFToken(
        $_POST['csrf_token']
        ?? ''
    )
) {

    $_SESSION['error'] =
        'Invalid request. Please try again.';

    header(
        'Location: view-checklist.php?job_id='
        . $jobId
    );

    exit;
}


/* =========================================================
   GET JOB
   ---------------------------------------------------------
   Make sure:
   - Job belongs to Owner
   - It is SAF inspection
   - Inspector is assigned
========================================================= */

$stmt = $conn->prepare("
    SELECT
        mj.id,
        mj.owner_id,
        ia.inspector_id,
        mj.status,
        mj.job_type,
        a.asset_name

    FROM maintenance_jobs mj

    INNER JOIN inspector_assignments ia
        ON ia.job_id = mj.id

    INNER JOIN assets a
        ON a.id = mj.asset_id

    WHERE
        mj.id = ?
        AND mj.owner_id = ?
        AND mj.job_type = 'saf_inspection'
        AND ia.inspector_id IS NOT NULL
        AND ia.status IN (
            'assigned',
            'in_progress',
            'submitted',
            'completed',
            'approved'
        )

    ORDER BY ia.id DESC

    LIMIT 1
");


if (!$stmt) {

    $_SESSION['error'] =
        'Unable to process report request.';

    header(
        'Location: view-checklist.php?job_id='
        . $jobId
    );

    exit;
}


$stmt->bind_param(
    'ii',
    $jobId,
    $ownerId
);


$stmt->execute();


$job =
    $stmt
    ->get_result()
    ->fetch_assoc();


$stmt->close();


/* =========================================================
   JOB NOT FOUND
========================================================= */

if (!$job) {

    $_SESSION['error'] =
        'Checklist not found or access denied.';

    header(
        'Location: approvals.php'
    );

    exit;
}


/* =========================================================
   FINAL PAYMENT VERIFICATION
   ---------------------------------------------------------
   IMPORTANT:
   Owner Approval is NOT a prerequisite for requesting the
   Inspection Report.

   The Request Report button is available when the Final
   payment is Paid / Verified by Admin.

   This server-side check intentionally mirrors the Owner
   page unlock rule and accepts the actual payment state
   stored by the payment module.
========================================================= */

$psgaNo = trim((string)($job['psga_no'] ?? ''));

if ($psgaNo === '') {
    $psgaStmt = $conn->prepare("
        SELECT s.psga_no
        FROM maintenance_jobs mj
        INNER JOIN saf_contracts s ON s.id = mj.saf_id
        WHERE
            mj.id = ?
            AND mj.owner_id = ?
            AND mj.job_type = 'saf_inspection'
        LIMIT 1
    ");

    if ($psgaStmt) {
        $psgaStmt->bind_param('ii', $jobId, $ownerId);
        $psgaStmt->execute();
        $psgaRow = $psgaStmt->get_result()->fetch_assoc();
        $psgaStmt->close();

        $psgaNo = trim((string)($psgaRow['psga_no'] ?? ''));
    }
}

if ($psgaNo === '') {
    $_SESSION['error'] =
        'Final payment cannot be verified because the PSGA number is not available for this inspection.';

    header(
        'Location: view-checklist.php?job_id=' . $jobId
    );
    exit;
}

/*
 * =========================================================
 * FINAL PAYMENT VERIFICATION
 * =========================================================
 *
 * Accepted states:
 *
 *   1. payment_schedule final stage = Paid / Verified
 *   OR
 *   2. latest final transaction = Verified
 *   OR
 *   3. payments overall status = Paid / Completed / Verified
 *
 * Payment_Stage must still represent the Final payment.
 *
 * We do NOT require Owner Approval here.
 * =========================================================
 */

$finalPaymentPaid = false;


/* =========================================================
   A. PSGA -> OFFER -> PAYMENT SCHEDULE
========================================================= */

$finalPaymentStmt = $conn->prepare("
    SELECT
        1

    FROM nleta_offer_master o

    INNER JOIN payment_schedule ps
        ON ps.Offer_ID = o.id

    LEFT JOIN payment_transactions pt
        ON pt.Schedule_ID = ps.Schedule_ID
        AND pt.Transaction_ID = (
            SELECT
                MAX(pt2.Transaction_ID)
            FROM payment_transactions pt2
            WHERE
                pt2.Schedule_ID = ps.Schedule_ID
        )

    LEFT JOIN payments p
        ON p.Offer_ID = o.id

    WHERE
        TRIM(
            COALESCE(
                o.psga_no,
                ''
            )
        ) = ?

        /* Final payment stage */
        AND REPLACE(
                REPLACE(
                    REPLACE(
                        LOWER(
                            TRIM(
                                COALESCE(
                                    ps.Payment_Stage,
                                    ''
                                )
                            )
                        ),
                        ' ',
                        ''
                    ),
                    '_',
                    ''
                ),
                '-',
                ''
            ) IN (
                'final',
                'finalpayment'
            )

        /* Admin-verified payment */
        AND (
            LOWER(
                TRIM(
                    COALESCE(
                        ps.Status,
                        ''
                    )
                )
            ) IN (
                'paid',
                'verified'
            )

            OR

            LOWER(
                TRIM(
                    COALESCE(
                        pt.Status,
                        ''
                    )
                )
            ) = 'verified'

            OR

            LOWER(
                TRIM(
                    COALESCE(
                        p.Payment_Status,
                        ''
                    )
                )
            ) IN (
                'paid',
                'completed',
                'verified'
            )
        )

    LIMIT 1
");


if (!$finalPaymentStmt) {

    $_SESSION['error'] =
        'Unable to verify Final payment: '
        . $conn->error;

    header(
        'Location: view-checklist.php?job_id='
        . $jobId
    );

    exit;
}


$finalPaymentStmt->bind_param(
    's',
    $psgaNo
);


if (!$finalPaymentStmt->execute()) {

    $_SESSION['error'] =
        'Unable to verify Final payment: '
        . $finalPaymentStmt->error;

    $finalPaymentStmt->close();

    header(
        'Location: view-checklist.php?job_id='
        . $jobId
    );

    exit;
}


$finalPaymentFound =
    $finalPaymentStmt
    ->get_result()
    ->fetch_row();


$finalPaymentStmt->close();


$finalPaymentPaid =
    !empty($finalPaymentFound);


/* =========================================================
   B. JOB -> SAF -> OFFER FALLBACK
   ---------------------------------------------------------
   This handles installations where the payment schedule
   relation is correctly tied to the SAF PSGA but the direct
   PSGA lookup above does not find the row.
========================================================= */

if (!$finalPaymentPaid) {

    $jobPaymentStmt = $conn->prepare("
        SELECT
            1

        FROM maintenance_jobs mj

        INNER JOIN saf_contracts s
            ON s.id = mj.saf_id

        INNER JOIN nleta_offer_master o
            ON TRIM(
                COALESCE(
                    o.psga_no,
                    ''
                )
            )
            =
            TRIM(
                COALESCE(
                    s.psga_no,
                    ''
                )
            )

        INNER JOIN payment_schedule ps
            ON ps.Offer_ID = o.id

        LEFT JOIN payment_transactions pt
            ON pt.Schedule_ID = ps.Schedule_ID
            AND pt.Transaction_ID = (
                SELECT
                    MAX(pt2.Transaction_ID)
                FROM payment_transactions pt2
                WHERE
                    pt2.Schedule_ID = ps.Schedule_ID
            )

        LEFT JOIN payments p
            ON p.Offer_ID = o.id

        WHERE
            mj.id = ?
            AND mj.owner_id = ?

            AND REPLACE(
                    REPLACE(
                        REPLACE(
                            LOWER(
                                TRIM(
                                    COALESCE(
                                        ps.Payment_Stage,
                                        ''
                                    )
                                )
                            ),
                            ' ',
                            ''
                        ),
                        '_',
                        ''
                    ),
                    '-',
                    ''
                ) IN (
                    'final',
                    'finalpayment'
                )

            AND (
                LOWER(
                    TRIM(
                        COALESCE(
                            ps.Status,
                            ''
                        )
                    )
                ) IN (
                    'paid',
                    'verified'
                )

                OR

                LOWER(
                    TRIM(
                        COALESCE(
                            pt.Status,
                            ''
                        )
                    )
                ) = 'verified'

                OR

                LOWER(
                    TRIM(
                        COALESCE(
                            p.Payment_Status,
                            ''
                        )
                    )
                ) IN (
                    'paid',
                    'completed',
                    'verified'
                )
            )

        LIMIT 1
    ");


    if ($jobPaymentStmt) {

        $jobPaymentStmt->bind_param(
            'ii',
            $jobId,
            $ownerId
        );


        if ($jobPaymentStmt->execute()) {

            $jobPaymentFound =
                $jobPaymentStmt
                ->get_result()
                ->fetch_row();


            $finalPaymentPaid =
                !empty($jobPaymentFound);
        }


        $jobPaymentStmt->close();
    }
}


/* =========================================================
   FINAL PAYMENT BLOCK
========================================================= */

if (!$finalPaymentPaid) {

    $_SESSION['error'] =
        'Inspection Report request is locked until the Final payment is paid and verified by Admin.';

    header(
        'Location: view-checklist.php?job_id='
        . $jobId
    );

    exit;
}


/* =========================================================
   INSPECTOR REQUIRED
========================================================= */

$inspectorId =
    (int)(
        $job['inspector_id']
        ??
        0
    );

if ($inspectorId <= 0) {

    $_SESSION['error'] =
        'No Inspector is assigned to this inspection yet.';

    header(
        'Location: view-checklist.php?job_id='
        . $jobId
    );

    exit;
}


/* =========================================================
   CHECK EXISTING ACTIVE REQUEST
   ---------------------------------------------------------
   Do not create duplicate report requests.
========================================================= */


$check = $conn->prepare("
    SELECT
        id,
        status
    FROM report_requests
    WHERE
        job_id = ?
        AND owner_id = ?
        AND status IN (
            'requested',
            'in_progress',
            'sent',
            'completed'
        )
    ORDER BY id DESC
    LIMIT 1
");


if ($check) {

    $check->bind_param(
        'ii',
        $jobId,
        $ownerId
    );

    $check->execute();

    $existing =
        $check
        ->get_result()
        ->fetch_assoc();

    $check->close();


    if ($existing) {

        $_SESSION['success'] =
            'Report has already been requested for this inspection.';

        header(
            'Location: view-checklist.php?job_id='
            . $jobId
        );

        exit;
    }
}


/* =========================================================
   OWNER MESSAGE
========================================================= */

$message =
    trim(
        $_POST['message']
        ?? ''
    );


if ($message === '') {

    $message =
        'Please prepare and send the inspection report.';
}


/* =========================================================
   INSERT REPORT REQUEST
========================================================= */

$insert = $conn->prepare("
    INSERT INTO report_requests
    (
        job_id,
        owner_id,
        inspector_id,
        status,
        owner_message,
        requested_at
    )
    VALUES
    (
        ?,
        ?,
        ?,
        'requested',
        ?,
        NOW()
    )
");


if (!$insert) {

    $_SESSION['error'] =
        'Unable to create report request: '
        . $conn->error;

    header(
        'Location: view-checklist.php?job_id='
        . $jobId
    );

    exit;
}


$insert->bind_param(
    'iiis',
    $jobId,
    $ownerId,
    $inspectorId,
    $message
);


if (!$insert->execute()) {

    $_SESSION['error'] =
        'Unable to send report request.';

    $insert->close();

    header(
        'Location: view-checklist.php?job_id='
        . $jobId
    );

    exit;
}


$insert->close();


/* =========================================================
   TECHNICIAN NOTIFICATION
   ---------------------------------------------------------
   Uses notifications table only if it exists.
   This keeps the existing system safe.
========================================================= */

$tableCheck = $conn->query("
    SHOW TABLES LIKE 'notifications'
");


if (
    $tableCheck &&
    $tableCheck->num_rows > 0
) {

    /*
     * Check available columns so we don't break the
     * existing notification system.
     */

    $columns = [];

    $columnResult =
        $conn->query("
            SHOW COLUMNS
            FROM notifications
        ");


    if ($columnResult) {

        while (
            $column =
            $columnResult->fetch_assoc()
        ) {

            $columns[] =
                $column['Field'];
        }
    }


    /*
     * Common notification structure:
     *
     * user_id
     * message
     * link
     * is_read
     *
     * Only insert when the required columns exist.
     */

    if (
        in_array(
            'user_id',
            $columns,
            true
        )
        &&
        in_array(
            'message',
            $columns,
            true
        )
    ) {

        $notificationMessage =
            'Owner requested the inspection report for '
            . ($job['asset_name'] ?? 'the assigned lift')
            . '.';


        if (
            in_array(
                'link',
                $columns,
                true
            )
            &&
            in_array(
                'is_read',
                $columns,
                true
            )
        ) {

            $notification = $conn->prepare("
                INSERT INTO notifications
                (
                    user_id,
                    message,
                    link,
                    is_read
                )
                VALUES
                (
                    ?,
                    ?,
                    ?,
                    0
                )
            ");


            if ($notification) {

                $link =
                    '../inspector/report-requests.php';


                $notification->bind_param(
                    'iss',
                    $inspectorId,
                    $notificationMessage,
                    $link
                );


                $notification->execute();

                $notification->close();
            }

        } else {

            $notification = $conn->prepare("
                INSERT INTO notifications
                (
                    user_id,
                    message
                )
                VALUES
                (
                    ?,
                    ?
                )
            ");


            if ($notification) {

                $notification->bind_param(
                    'is',
                    $inspectorId,
                    $notificationMessage
                );


                $notification->execute();

                $notification->close();
            }
        }
    }
}


/* =========================================================
   SUCCESS
========================================================= */

$_SESSION['success'] =
    'Inspection Report request sent to the assigned Inspector successfully.';


header(
    'Location: view-checklist.php?job_id='
    . $jobId
);

exit;