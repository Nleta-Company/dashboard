<?php
/* =========================================================
   NLETA INSPECTOR — INSPECTIONS
   =========================================================
   Inspector assignment source:
       inspector_assignments

   The Inspector sees ONLY assignments belonging to the
   logged-in Inspector.

   Owner-selected service is read from the existing
   SAF/job. Inspector cannot change the service.

   Assignment chain:
       Owner Service
            ↓
       SAF / maintenance_jobs
            ↓
       Admin assigns Inspector
            ↓
       inspector_assignments
            ↓
       Inspector Inspections
========================================================= */

require_once '../config/db.php';
require_once '../config/auth.php';
require_once '../config/security.php';

require_role('inspector');

date_default_timezone_set('Asia/Kolkata');


/* =========================================================
   INSPECTOR ID
========================================================= */

$inspectorId = (int)(
    $_SESSION['uid']
    ?? $_SESSION['user_id']
    ?? 0
);

if ($inspectorId <= 0) {
    header('Location: ../login.php');
    exit;
}

/* =========================================================
   EXPLICIT ASSET SELECTION
   ---------------------------------------------------------
   Inspector must choose the Asset/Lift first.
   Nothing is auto-selected from the assignment list.
========================================================= */

$selectedAssetId = (int)(
    $_GET['asset_id']
    ?? $_POST['selected_asset_id']
    ?? 0
);

$selectedSafId = (int)(
    $_GET['saf_id']
    ?? $_POST['selected_saf_id']
    ?? 0
);


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
   CSRF
========================================================= */

$csrfToken = '';

if (function_exists('generateCSRFToken')) {
    $csrfToken = generateCSRFToken();
} else {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    $csrfToken = $_SESSION['csrf_token'];
}


/* =========================================================
   SITE ATTENDANCE OTP STATE
========================================================= */

$attendanceMessage = '';
$attendanceError = '';

$attendancePostAssignmentId =
    (int)($_POST['attendance_assignment_id'] ?? 0);

$attendancePostJobId =
    (int)($_POST['attendance_job_id'] ?? 0);


/* =========================================================
   VARIABLES
========================================================= */

$jobs = [];
$pageError = '';

$assignedCount = 0;
$inProgressCount = 0;
$submittedCount = 0;
$completedCount = 0;


/* =========================================================
   VERIFY ASSIGNMENT TABLE
========================================================= */

$tableCheck = $conn->query(
    "SHOW TABLES LIKE 'inspector_assignments'"
);

$assignmentTableAvailable =
    $tableCheck &&
    $tableCheck->num_rows > 0;


/* =========================================================
   OWNER CONTACT NUMBER
   ---------------------------------------------------------
   Read the contact number from whichever standard contact
   column exists in the users table. This keeps the Inspector
   page compatible with the existing database without changing
   the database structure.
========================================================= */

$ownerContactColumn = '';

$ownerContactCandidates = [
    'mobile',
    'phone',
    'contact_no',
    'contact_number',
    'mobile_no',
    'phone_no'
];

$usersColumnsResult = $conn->query("SHOW COLUMNS FROM users");

if ($usersColumnsResult) {
    $availableUserColumns = [];

    while ($userColumnRow = $usersColumnsResult->fetch_assoc()) {
        $availableUserColumns[] = (string)($userColumnRow['Field'] ?? '');
    }

    foreach ($ownerContactCandidates as $candidateColumn) {
        if (in_array($candidateColumn, $availableUserColumns, true)) {
            $ownerContactColumn = $candidateColumn;
            break;
        }
    }
}

$ownerContactSelectSql = $ownerContactColumn !== ''
    ? "owner_user.`" . $ownerContactColumn . "` AS owner_contact_no,"
    : "NULL AS owner_contact_no,";


/* =========================================================
   INSPECTOR SITE ATTENDANCE OTP TABLE
========================================================= */

$attendanceTableAvailable = false;

if ($assignmentTableAvailable) {

    $attendanceTableSql = "
        CREATE TABLE IF NOT EXISTS inspector_attendance (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            assignment_id INT NOT NULL,
            job_id INT NOT NULL,
            inspector_id INT NOT NULL,
            owner_id INT NOT NULL,
            otp_hash VARCHAR(255) NULL,
            otp_expires_at DATETIME NULL,
            otp_attempts INT NOT NULL DEFAULT 0,
            otp_sent_at DATETIME NULL,
            verified_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_inspector_attendance_assignment (assignment_id),
            KEY idx_inspector_attendance_job (job_id),
            KEY idx_inspector_attendance_inspector (inspector_id),
            KEY idx_inspector_attendance_owner (owner_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";

    if ($conn->query($attendanceTableSql)) {
        $attendanceTableAvailable = true;
    }
}


/* =========================================================
   INLINE OWNER OTP — SITE ATTENDANCE
   ---------------------------------------------------------
   OTP is handled directly on this inspections.php page.
   No separate inspector-attendance.php page is required.
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    (
        isset($_POST['send_attendance_otp'])
        ||
        isset($_POST['verify_attendance_otp'])
    )
) {

    $postedCsrf =
        (string)($_POST['csrf_token'] ?? '');

    $csrfValid = false;

    if (function_exists('validateCSRFToken')) {
        $csrfValid =
            validateCSRFToken($postedCsrf);
    } else {
        $csrfValid =
            !empty($_SESSION['csrf_token'])
            &&
            hash_equals(
                (string)$_SESSION['csrf_token'],
                $postedCsrf
            );
    }

    if (!$csrfValid) {

        $attendanceError =
            'Invalid security token. Please refresh and try again.';

    } else {

        $postAssignmentId =
            (int)($_POST['attendance_assignment_id'] ?? 0);

        $postJobId =
            (int)($_POST['attendance_job_id'] ?? 0);

        /*
         * Always re-check the assignment server-side.
         * Inspector ownership comes from inspector_assignments.
         */
        $attendanceTarget = null;

        if (
            $postAssignmentId > 0
            &&
            $postJobId > 0
        ) {

            $targetStmt = $conn->prepare("
                SELECT
                    ia.id AS assignment_id,
                    ia.job_id,
                    ia.inspector_id,
                    ia.owner_id AS assigned_owner_id,
                    ia.asset_id AS assigned_asset_id,
                    ia.status AS assignment_status,

                    mj.owner_id AS job_owner_id,
                    mj.asset_id AS job_asset_id,
                    mj.status AS job_status,
                    mj.job_type,

                    owner_user.name AS owner_name,
                    owner_user.email AS owner_email,
                    $ownerContactSelectSql

                    a.asset_name,
                    a.display_asset_id,
                    a.location,

                    s.saf_no,
                    s.psga_no

                FROM inspector_assignments ia

                INNER JOIN maintenance_jobs mj
                    ON mj.id = ia.job_id

                LEFT JOIN users owner_user
                    ON owner_user.id = mj.owner_id

                LEFT JOIN assets a
                    ON a.id = mj.asset_id

                LEFT JOIN saf_contracts s
                    ON s.id = mj.saf_id

                WHERE
                    ia.id = ?
                    AND ia.job_id = ?
                    AND ia.inspector_id = ?
                    AND mj.job_type = 'saf_inspection'

                LIMIT 1
            ");

            if ($targetStmt) {

                $targetStmt->bind_param(
                    'iii',
                    $postAssignmentId,
                    $postJobId,
                    $inspectorId
                );

                $targetStmt->execute();

                $attendanceTarget =
                    $targetStmt
                        ->get_result()
                        ->fetch_assoc();

                $targetStmt->close();
            }
        }

        if (!$attendanceTarget) {

            $attendanceError =
                'Invalid inspection assignment.';

        } else {

            $attendanceOwnerId =
                (int)(
                    $attendanceTarget['job_owner_id']
                    ?: $attendanceTarget['assigned_owner_id']
                );

            $attendanceAssetId =
                (int)(
                    $attendanceTarget['job_asset_id']
                    ?: $attendanceTarget['assigned_asset_id']
                );

            $attendanceAssignmentStatus =
                strtolower(
                    trim(
                        (string)$attendanceTarget[
                            'assignment_status'
                        ]
                    )
                );

            if (
                !in_array(
                    $attendanceAssignmentStatus,
                    [
                        'assigned',
                        'in_progress'
                    ],
                    true
                )
            ) {

                $attendanceError =
                    'Attendance verification is available only for an active inspection assignment.';

            } elseif (
                !filter_var(
                    (string)$attendanceTarget['owner_email'],
                    FILTER_VALIDATE_EMAIL
                )
            ) {

                $attendanceError =
                    'The registered Owner email address is invalid or unavailable.';

            } else {

                /*
                 * Read existing attendance row.
                 */
                $attendanceRow = null;

                $attendanceRead =
                    $conn->prepare("
                        SELECT
                            id,
                            otp_hash,
                            otp_expires_at,
                            otp_attempts,
                            otp_sent_at,
                            verified_at
                        FROM inspector_attendance
                        WHERE
                            assignment_id = ?
                            AND job_id = ?
                            AND inspector_id = ?
                            AND owner_id = ?
                        LIMIT 1
                    ");

                if ($attendanceRead) {

                    $attendanceRead->bind_param(
                        'iiii',
                        $postAssignmentId,
                        $postJobId,
                        $inspectorId,
                        $attendanceOwnerId
                    );

                    $attendanceRead->execute();

                    $attendanceRow =
                        $attendanceRead
                            ->get_result()
                            ->fetch_assoc();

                    $attendanceRead->close();
                }

                /* =================================================
                   SEND OTP
                ================================================= */

                if (isset($_POST['send_attendance_otp'])) {

                    if (
                        $attendanceRow
                        &&
                        !empty(
                            $attendanceRow['verified_at']
                        )
                    ) {

                        $attendanceMessage =
                            'Owner attendance is already verified. Start Inspection is unlocked.';

                    } else {

                        $canSend = true;

                        if (
                            $attendanceRow
                            &&
                            !empty(
                                $attendanceRow['otp_sent_at']
                            )
                        ) {

                            $lastSentTs =
                                strtotime(
                                    (string)$attendanceRow[
                                        'otp_sent_at'
                                    ]
                                );

                            if (
                                $lastSentTs !== false
                                &&
                                (time() - $lastSentTs) < 60
                            ) {

                                $remaining =
                                    60 -
                                    (
                                        time() -
                                        $lastSentTs
                                    );

                                $canSend = false;

                                $attendanceError =
                                    'Please wait '
                                    . $remaining
                                    . ' seconds before requesting another OTP.';
                            }
                        }

                        if ($canSend) {

                            try {

                                $otp =
                                    (string)random_int(
                                        100000,
                                        999999
                                    );

                                $otpHash =
                                    password_hash(
                                        $otp,
                                        PASSWORD_DEFAULT
                                    );

                                if (
                                    $otpHash === false
                                ) {

                                    throw new Exception(
                                        'Unable to secure attendance OTP.'
                                    );
                                }

                                /*
                                 * Existing NLETA general mailer.
                                 * It provides nletaCreateMailer().
                                 */
                                $mailerFile =
                                    __DIR__
                                    . '/../config/nleta-mailer.php';

                                if (
                                    !file_exists(
                                        $mailerFile
                                    )
                                ) {

                                    throw new Exception(
                                        'NLETA mailer configuration was not found.'
                                    );
                                }

                                require_once $mailerFile;

                                if (
                                    !function_exists(
                                        'nletaCreateMailer'
                                    )
                                ) {

                                    throw new Exception(
                                        'NLETA mailer function is not available.'
                                    );
                                }

                                $mail =
                                    nletaCreateMailer();

                                $mail->addAddress(
                                    (string)$attendanceTarget[
                                        'owner_email'
                                    ],
                                    (string)(
                                        $attendanceTarget[
                                            'owner_name'
                                        ]
                                        ?: 'Owner'
                                    )
                                );

                                $mail->Subject =
                                    'NLETA - Inspector Site Attendance OTP';

                                $ownerNameSafe =
                                    nletaSafe(
                                        $attendanceTarget[
                                            'owner_name'
                                        ]
                                        ?: 'Owner'
                                    );

                                $assetSafe =
                                    nletaSafe(
                                        $attendanceTarget[
                                            'display_asset_id'
                                        ]
                                        ?: (
                                            $attendanceTarget[
                                                'asset_name'
                                            ]
                                            ?: 'Assigned Asset'
                                        )
                                    );

                                $assetNameSafe =
                                    nletaSafe(
                                        $attendanceTarget[
                                            'asset_name'
                                        ]
                                        ?: 'Assigned Asset'
                                    );

                                $locationSafe =
                                    nletaSafe(
                                        $attendanceTarget[
                                            'location'
                                        ]
                                        ?: 'Assigned Site'
                                    );

                                $safSafe =
                                    nletaSafe(
                                        $attendanceTarget[
                                            'saf_no'
                                        ]
                                        ?: '—'
                                    );

                                $psgaSafe =
                                    nletaSafe(
                                        $attendanceTarget[
                                            'psga_no'
                                        ]
                                        ?: '—'
                                    );

                                $otpSafe =
                                    nletaSafe(
                                        $otp
                                    );

                                $mail->isHTML(true);

                                $mail->Body = '
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>NLETA Site Attendance OTP</title>
</head>

<body style="margin:0;padding:0;background:#f4f6f9;font-family:Arial,Helvetica,sans-serif;">

<table width="100%" cellpadding="0" cellspacing="0" border="0"
style="background:#f4f6f9;padding:30px 10px;">
<tr>
<td align="center">

<table width="600" cellpadding="0" cellspacing="0" border="0"
style="max-width:600px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden;border:1px solid #e5e7eb;">

<tr>
<td align="center"
style="background:#1f2933;padding:24px;color:#ffffff;">

<div style="font-size:24px;font-weight:bold;letter-spacing:1px;">
NLETA
</div>

<div style="margin-top:7px;font-size:13px;line-height:20px;">
NATIONAL LIFT ESCALATOR<br>
TESTING AGENCY
</div>

</td>
</tr>

<tr>
<td style="padding:30px;color:#222222;">

<h2 style="margin:0 0 18px;color:#1f2933;">
Inspector Site Attendance Verification
</h2>

<p style="font-size:15px;line-height:24px;">
Dear <strong>'
                                    . $ownerNameSafe
                                    . '</strong>,
</p>

<p style="font-size:14px;line-height:23px;">
An NLETA Inspector has reached the assigned site and is requesting Owner confirmation of site attendance.
</p>

<table width="100%" cellpadding="0" cellspacing="0" border="0"
style="border-collapse:collapse;margin:18px 0;">

<tr>
<td style="padding:9px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">
Asset
</td>
<td style="padding:9px;border:1px solid #e5e7eb;">
'
                                    . $assetSafe
                                    . ' — '
                                    . $assetNameSafe
                                    . '
</td>
</tr>

<tr>
<td style="padding:9px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">
Location
</td>
<td style="padding:9px;border:1px solid #e5e7eb;">
'
                                    . $locationSafe
                                    . '
</td>
</tr>

<tr>
<td style="padding:9px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">
SAF No.
</td>
<td style="padding:9px;border:1px solid #e5e7eb;">
'
                                    . $safSafe
                                    . '
</td>
</tr>

<tr>
<td style="padding:9px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">
PSGA No.
</td>
<td style="padding:9px;border:1px solid #e5e7eb;">
'
                                    . $psgaSafe
                                    . '
</td>
</tr>

</table>

<div style="text-align:center;margin:28px 0;">

<div style="font-size:13px;color:#6b7280;">
Attendance Verification OTP
</div>

<div style="display:inline-block;margin-top:10px;padding:16px 28px;border:2px dashed #0d6efd;border-radius:8px;font-size:31px;letter-spacing:8px;font-weight:bold;color:#0d6efd;">
'
                                    . $otpSafe
                                    . '
</div>

</div>

<p style="font-size:13px;line-height:21px;color:#555;">
This OTP is valid for <strong>10 minutes</strong>.
</p>

<p style="font-size:13px;line-height:21px;color:#dc3545;">
Provide this OTP only to the assigned NLETA Inspector if the Inspector is physically present at the assigned site.
</p>

</td>
</tr>

<tr>
<td align="center"
style="background:#f4f6f9;padding:18px;color:#777;font-size:12px;">
This is an automated message from the NLETA Maintenance Management System.
</td>
</tr>

</table>

</td>
</tr>
</table>

</body>
</html>';

                                $mail->AltBody =
                                    "NLETA Inspector Site Attendance Verification\n\n"
                                    . "Dear "
                                    . (
                                        $attendanceTarget[
                                            'owner_name'
                                        ]
                                        ?: 'Owner'
                                    )
                                    . ",\n\n"
                                    . "Attendance verification OTP: "
                                    . $otp
                                    . "\n\n"
                                    . "Asset: "
                                    . (
                                        $attendanceTarget[
                                            'display_asset_id'
                                        ]
                                        ?: (
                                            $attendanceTarget[
                                                'asset_name'
                                            ]
                                            ?: 'Assigned Asset'
                                        )
                                    )
                                    . "\n"
                                    . "Location: "
                                    . (
                                        $attendanceTarget[
                                            'location'
                                        ]
                                        ?: 'Assigned Site'
                                    )
                                    . "\n"
                                    . "SAF No.: "
                                    . (
                                        $attendanceTarget[
                                            'saf_no'
                                        ]
                                        ?: '—'
                                    )
                                    . "\n"
                                    . "PSGA No.: "
                                    . (
                                        $attendanceTarget[
                                            'psga_no'
                                        ]
                                        ?: '—'
                                    )
                                    . "\n\n"
                                    . "Valid for 10 minutes.";

                                $mail->send();

                                /*
                                 * Save the hash only after successful
                                 * email delivery.
                                 */
                                $newExpiresAt =
                                    date(
                                        'Y-m-d H:i:s',
                                        time() + 600
                                    );

                                $newSentAt =
                                    date(
                                        'Y-m-d H:i:s'
                                    );

                                $saveStmt =
                                    $conn->prepare("
                                        INSERT INTO inspector_attendance
                                        (
                                            assignment_id,
                                            job_id,
                                            inspector_id,
                                            owner_id,
                                            otp_hash,
                                            otp_expires_at,
                                            otp_attempts,
                                            otp_sent_at,
                                            verified_at,
                                            created_at,
                                            updated_at
                                        )
                                        VALUES
                                        (
                                            ?,
                                            ?,
                                            ?,
                                            ?,
                                            ?,
                                            ?,
                                            0,
                                            ?,
                                            NULL,
                                            CURRENT_TIMESTAMP,
                                            CURRENT_TIMESTAMP
                                        )

                                        ON DUPLICATE KEY UPDATE
                                            job_id = VALUES(job_id),
                                            inspector_id = VALUES(inspector_id),
                                            owner_id = VALUES(owner_id),
                                            otp_hash = VALUES(otp_hash),
                                            otp_expires_at = VALUES(otp_expires_at),
                                            otp_attempts = 0,
                                            otp_sent_at = VALUES(otp_sent_at),
                                            verified_at = NULL,
                                            updated_at = CURRENT_TIMESTAMP
                                    ");

                                if (!$saveStmt) {

                                    throw new Exception(
                                        'OTP email was sent, but attendance OTP could not be saved: '
                                        . $conn->error
                                    );
                                }

                                $saveStmt->bind_param(
                                    'iiiisss',
                                    $postAssignmentId,
                                    $postJobId,
                                    $inspectorId,
                                    $attendanceOwnerId,
                                    $otpHash,
                                    $newExpiresAt,
                                    $newSentAt
                                );

                                if (
                                    !$saveStmt->execute()
                                ) {

                                    $saveError =
                                        $saveStmt->error;

                                    $saveStmt->close();

                                    throw new Exception(
                                        'OTP email was sent, but attendance OTP could not be saved: '
                                        . $saveError
                                    );
                                }

                                $saveStmt->close();

                                $attendanceMessage =
                                    'Attendance OTP has been sent to the registered Owner email. Ask the Owner to provide the OTP.';

                            } catch (Throwable $e) {

                                error_log(
                                    'NLETA Inspector Attendance OTP Error: '
                                    . $e->getMessage()
                                );

                                $attendanceError =
                                    'Attendance OTP could not be sent. '
                                    . 'Please check the NLETA email configuration and try again.';

                            }
                        }
                    }
                }


                /* =================================================
                   VERIFY OTP
                ================================================= */

                if (
                    isset(
                        $_POST[
                            'verify_attendance_otp'
                        ]
                    )
                    &&
                    $attendanceError === ''
                ) {

                    $enteredOtp =
                        preg_replace(
                            '/[^0-9]/',
                            '',
                            (string)(
                                $_POST['attendance_otp']
                                ?? ''
                            )
                        );

                    /*
                     * Re-read immediately so the latest OTP is used.
                     */
                    $verifyRow = null;

                    $verifyRead =
                        $conn->prepare("
                            SELECT
                                id,
                                otp_hash,
                                otp_expires_at,
                                otp_attempts,
                                verified_at
                            FROM inspector_attendance
                            WHERE
                                assignment_id = ?
                                AND job_id = ?
                                AND inspector_id = ?
                                AND owner_id = ?
                            LIMIT 1
                        ");

                    if ($verifyRead) {

                        $verifyRead->bind_param(
                            'iiii',
                            $postAssignmentId,
                            $postJobId,
                            $inspectorId,
                            $attendanceOwnerId
                        );

                        $verifyRead->execute();

                        $verifyRow =
                            $verifyRead
                                ->get_result()
                                ->fetch_assoc();

                        $verifyRead->close();
                    }

                    if (
                        !preg_match(
                            '/^[0-9]{6}$/',
                            $enteredOtp
                        )
                    ) {

                        $attendanceError =
                            'Please enter the 6-digit OTP.';

                    } elseif (
                        !$verifyRow
                        ||
                        empty(
                            $verifyRow['otp_hash']
                        )
                    ) {

                        $attendanceError =
                            'No active attendance OTP was found. Please request a new OTP.';

                    } elseif (
                        !empty(
                            $verifyRow['verified_at']
                        )
                    ) {

                        $attendanceMessage =
                            'Owner attendance is already verified. Start Inspection is unlocked.';

                    } elseif (
                        !empty(
                            $verifyRow['otp_expires_at']
                        )
                        &&
                        strtotime(
                            $verifyRow['otp_expires_at']
                        ) < time()
                    ) {

                        $attendanceError =
                            'This attendance OTP has expired. Please request a new OTP.';

                    } elseif (
                        (int)$verifyRow[
                            'otp_attempts'
                        ] >= 5
                    ) {

                        $attendanceError =
                            'Too many incorrect attempts. Please request a new OTP.';

                    } elseif (
                        !password_verify(
                            $enteredOtp,
                            (string)$verifyRow[
                                'otp_hash'
                            ]
                        )
                    ) {

                        $attemptStmt =
                            $conn->prepare("
                                UPDATE inspector_attendance
                                SET
                                    otp_attempts =
                                        otp_attempts + 1,
                                    updated_at =
                                        CURRENT_TIMESTAMP
                                WHERE
                                    id = ?
                                LIMIT 1
                            ");

                        if ($attemptStmt) {

                            $attendanceId =
                                (int)$verifyRow['id'];

                            $attemptStmt->bind_param(
                                'i',
                                $attendanceId
                            );

                            $attemptStmt->execute();
                            $attemptStmt->close();
                        }

                        $attendanceError =
                            'Invalid OTP. Please check with the Owner and try again.';

                    } else {

                        $verifyStmt =
                            $conn->prepare("
                                UPDATE inspector_attendance
                                SET
                                    verified_at =
                                        CURRENT_TIMESTAMP,
                                    otp_hash = NULL,
                                    otp_expires_at = NULL,
                                    updated_at =
                                        CURRENT_TIMESTAMP
                                WHERE
                                    id = ?
                                    AND assignment_id = ?
                                    AND job_id = ?
                                    AND inspector_id = ?
                                    AND owner_id = ?
                                    AND verified_at IS NULL
                                LIMIT 1
                            ");

                        if (!$verifyStmt) {

                            $attendanceError =
                                'Unable to verify attendance: '
                                . $conn->error;

                        } else {

                            $attendanceId =
                                (int)$verifyRow['id'];

                            $verifyStmt->bind_param(
                                'iiiii',
                                $attendanceId,
                                $postAssignmentId,
                                $postJobId,
                                $inspectorId,
                                $attendanceOwnerId
                            );

                            if (
                                $verifyStmt->execute()
                                &&
                                $verifyStmt->affected_rows > 0
                            ) {

                                $attendanceMessage =
                                    'Owner attendance verified successfully. Start Inspection is now unlocked.';

                            } else {

                                $attendanceError =
                                    'Attendance could not be verified. Please try again.';
                            }

                            $verifyStmt->close();
                        }
                    }
                }
            }
        }
    }
}


/* =========================================================
   LOAD INSPECTOR ASSIGNMENTS
========================================================= */

if (!$assignmentTableAvailable) {

    $pageError =
        'Inspector assignment table is not available.';

} else {

    /*
     * IMPORTANT:
     *
     * Do NOT use maintenance_jobs.inspector_id.
     *
     * Your actual database uses the separate
     * inspector_assignments table.
     */

    $sql = "
        SELECT

            ia.id AS assignment_id,

            ia.job_id,

            ia.inspector_id,

            ia.asset_id AS assigned_asset_id,

            ia.owner_id AS assigned_owner_id,

            ia.service_id AS assigned_service_id,

            ia.status AS assignment_status,

            ia.assigned_by,

            ia.assigned_at,

            ia.started_at,

            ia.submitted_at,

            ia.completed_at,

            ia.remarks AS assignment_remarks,

            /* SITE ATTENDANCE OTP */

            iaa.verified_at AS attendance_verified_at,


            /* JOB */

            mj.saf_id,

            s.request_id AS job_request_id,

            mj.service_id AS job_service_id,

            mj.asset_id AS job_asset_id,

            mj.owner_id AS job_owner_id,

            mj.status AS job_status,

            /* PAT / SAT checklist JSON for asset completion detection */

            mj.checklist,

            mj.job_type,

            mj.created_at AS job_created_at,


            mj.owner_remark,


            /* SAF / OWNER SELECTED SERVICE */

            s.psga_no,

            s.saf_no,

            s.service_id AS saf_service_id,

            s.service_code,

            s.service_name,

            s.proposed_inspection_date,

            s.status AS saf_status,

            s.owner_accepted_at,


            /* OWNER */

            owner_user.name AS owner_name,

            owner_user.email AS owner_email,

            $ownerContactSelectSql


            /* ASSET */

            a.asset_name,

            a.display_asset_id,

            a.asset_type,

            a.location,

            a.status AS asset_status


        FROM inspector_assignments ia


        INNER JOIN maintenance_jobs mj

            ON mj.id = ia.job_id


        LEFT JOIN inspector_attendance iaa

            ON iaa.assignment_id = ia.id
            AND iaa.job_id = ia.job_id
            AND iaa.inspector_id = ia.inspector_id


        LEFT JOIN saf_contracts s

            ON s.id = mj.saf_id


        LEFT JOIN users owner_user

            ON owner_user.id = mj.owner_id


        LEFT JOIN assets a

            ON a.id = mj.asset_id


        WHERE
            ia.inspector_id = ?


        ORDER BY

            CASE

                WHEN ia.status = 'assigned'
                     AND mj.status = 'assigned'
                    THEN 1

                WHEN ia.status = 'in_progress'
                     OR mj.status = 'in_progress'
                    THEN 2

                WHEN ia.status = 'submitted'
                     OR mj.status = 'pending_owner'
                    THEN 3

                WHEN ia.status = 'approved'
                     OR mj.status = 'approved'
                    THEN 4

                WHEN ia.status = 'completed'
                     OR mj.status = 'closed'
                    THEN 5

                ELSE 6

            END,

            ia.assigned_at DESC,

            ia.id DESC
    ";


    $stmt = $conn->prepare($sql);


    if (!$stmt) {

        $pageError =
            'Unable to prepare Inspector assignment query: '
            . $conn->error;

    } else {

        $stmt->bind_param(
            'i',
            $inspectorId
        );


        if (!$stmt->execute()) {

            $pageError =
                'Unable to load Inspector assignments: '
                . $stmt->error;

        } else {

            $result =
                $stmt->get_result();


            while (
                $row = $result->fetch_assoc()
            ) {

                /*
                 * Assignment table is the authority for
                 * Inspector ownership.
                 *
                 * Job values are fallback only.
                 */

                if (
                    empty($row['asset_id'])
                    &&
                    !empty($row['assigned_asset_id'])
                ) {

                    $row['asset_id'] =
                        $row['assigned_asset_id'];

                } elseif (
                    empty($row['asset_id'])
                    &&
                    !empty($row['job_asset_id'])
                ) {

                    $row['asset_id'] =
                        $row['job_asset_id'];

                }


                if (
                    empty($row['owner_id'])
                    &&
                    !empty($row['assigned_owner_id'])
                ) {

                    $row['owner_id'] =
                        $row['assigned_owner_id'];

                } elseif (
                    empty($row['owner_id'])
                    &&
                    !empty($row['job_owner_id'])
                ) {

                    $row['owner_id'] =
                        $row['job_owner_id'];

                }


                if (
                    empty($row['service_id'])
                    &&
                    !empty($row['assigned_service_id'])
                ) {

                    $row['service_id'] =
                        $row['assigned_service_id'];

                } elseif (
                    empty($row['service_id'])
                    &&
                    !empty($row['job_service_id'])
                ) {

                    $row['service_id'] =
                        $row['job_service_id'];

                } elseif (
                    empty($row['service_id'])
                    &&
                    !empty($row['saf_service_id'])
                ) {

                    $row['service_id'] =
                        $row['saf_service_id'];

                }


                /* =================================================
                 * MULTI-ASSET SAF / SERVICE REQUEST
                 * -------------------------------------------------
                 * One SAF belongs to one Service Request.
                 * A Service Request can contain multiple assets.
                 *
                 * inspector_assignments.job_id points to ONE
                 * asset inspection instance, but the Inspector
                 * must be able to see the complete asset set
                 * belonging to the same SAF / request.
                 *
                 * Do NOT create new Asset IDs here.
                 * Read the existing Asset Master IDs.
                 * ================================================= */

                $row['all_asset_ids'] = [];
                $row['all_asset_names'] = [];
                $row['all_asset_locations'] = [];

                $requestIdForAssets =
                    (int)(
                        $row['job_request_id']
                        ?? 0
                    );

                if ($requestIdForAssets > 0) {

                    $assetListStmt = $conn->prepare("
                        SELECT
                            a.id,
                            a.display_asset_id,
                            a.asset_name,
                            a.location
                        FROM service_request_assets sra
                        INNER JOIN assets a
                            ON a.id = sra.asset_id
                        WHERE
                            sra.request_id = ?
                        ORDER BY
                            a.display_asset_id ASC,
                            a.id ASC
                    ");

                    if ($assetListStmt) {

                        $assetListStmt->bind_param(
                            'i',
                            $requestIdForAssets
                        );

                        if ($assetListStmt->execute()) {

                            $assetListResult =
                                $assetListStmt->get_result();

                            while (
                                $assetItem =
                                    $assetListResult->fetch_assoc()
                            ) {

                                $assetIdText =
                                    trim(
                                        (string)(
                                            $assetItem['display_asset_id']
                                            ?? ''
                                        )
                                    );

                                if ($assetIdText === '') {

                                    $assetIdText =
                                        (string)(
                                            $assetItem['id']
                                            ?? ''
                                        );
                                }

                                if (
                                    $assetIdText !== ''
                                    &&
                                    !in_array(
                                        $assetIdText,
                                        $row['all_asset_ids'],
                                        true
                                    )
                                ) {

                                    $row['all_asset_ids'][] =
                                        $assetIdText;
                                }

                                $assetNameText =
                                    trim(
                                        (string)(
                                            $assetItem['asset_name']
                                            ?? ''
                                        )
                                    );

                                if (
                                    $assetNameText !== ''
                                    &&
                                    !in_array(
                                        $assetNameText,
                                        $row['all_asset_names'],
                                        true
                                    )
                                ) {

                                    $row['all_asset_names'][] =
                                        $assetNameText;
                                }

                                $locationText =
                                    trim(
                                        (string)(
                                            $assetItem['location']
                                            ?? ''
                                        )
                                    );

                                if (
                                    $locationText !== ''
                                    &&
                                    !in_array(
                                        $locationText,
                                        $row['all_asset_locations'],
                                        true
                                    )
                                ) {

                                    $row['all_asset_locations'][] =
                                        $locationText;
                                }
                            }
                        }

                        $assetListStmt->close();
                    }
                }

                /*
                 * Legacy / safety fallback:
                 * If service_request_assets is not populated,
                 * retain the currently assigned asset.
                 */

                if (empty($row['all_asset_ids'])) {

                    $fallbackAssetId =
                        trim(
                            (string)(
                                $row['display_asset_id']
                                ?? ''
                            )
                        );

                    if ($fallbackAssetId === '') {

                        $fallbackAssetId =
                            trim(
                                (string)(
                                    $row['asset_id']
                                    ?? ''
                                )
                            );
                    }

                    if ($fallbackAssetId !== '') {

                        $row['all_asset_ids'][] =
                            $fallbackAssetId;
                    }
                }

                /*
                 * Existing maintenance job status is the
                 * primary workflow status.
                 *
                 * IMPORTANT:
                 * proposed_inspection_date is informational only.
                 * It does NOT lock Inspector assignment,
                 * Owner acceptance, attendance OTP, or
                 * Start Inspection.
                 */

                $row['display_status'] =
                    trim(
                        (string)(
                            $row['job_status']
                            ??
                            $row['assignment_status']
                            ??
                            'assigned'
                        )
                    );

                /*
                 * CHECKLIST COMPLETION STATUS
                 * ---------------------------
                 * The saved checklist final_status is authoritative only for
                 * the checklist_complete flag. It must NOT replace the actual
                 * maintenance_jobs / inspector_assignments workflow status,
                 * because pending_owner/submitted is the state that records
                 * successful Owner-OTP final submission.
                 *
                 * This does not alter any checkpoint, evidence, CSS, or
                 * Lift/Escalator checklist workflow.
                 */
                $rowChecklist = json_decode(
                    (string)($row['checklist'] ?? ''),
                    true
                );

                $rowFinalStatus = is_array($rowChecklist)
                    ? strtoupper(trim((string)($rowChecklist['final_status'] ?? '')))
                    : '';

                $rowJobStatusNormalized = strtolower(
                    trim((string)($row['job_status'] ?? ''))
                );

                $rowAssignmentStatusNormalized = strtolower(
                    trim((string)($row['assignment_status'] ?? ''))
                );

                $rowChecklistCompleted = in_array(
                    $rowFinalStatus,
                    ['PASS', 'CONDITIONAL PASS', 'FAIL'],
                    true
                );

                $rowAlreadyCompleted =
                    in_array(
                        $rowJobStatusNormalized,
                        ['completed', 'approved', 'closed'],
                        true
                    )
                    ||
                    in_array(
                        $rowAssignmentStatusNormalized,
                        ['completed', 'approved', 'closed'],
                        true
                    );

                /*
                 * IMPORTANT STATUS RULE
                 * ----------------------
                 * checklist.final_status means ONLY that the individual
                 * checklist has been completed. It does NOT mean that the
                 * Inspector has completed the final Owner-OTP submission.
                 *
                 * Previously this code converted every checklist with a
                 * final_status into job/assignment = completed. That caused
                 * the "Verify Owner OTP & Submit Checklist(s)" button to
                 * appear again even after the Owner OTP submission had
                 * already been verified, because the real submitted/pending_owner
                 * state was overwritten in memory.
                 *
                 * Keep the real DB workflow status untouched. Only mark the
                 * checklist itself as complete below.
                 */
                /*
                 * DISPLAY STATUS FIX
                 * -------------------
                 * If the Inspector has completed the checklist, the card
                 * must no longer display "In Progress".
                 *
                 * A checklist with final_status = PASS / CONDITIONAL PASS /
                 * FAIL is a completed inspection checklist. It can still be
                 * awaiting the final Owner-OTP submission, so this changes
                 * ONLY the display status. It does not change the database
                 * workflow status.
                 *
                 * Priority:
                 *   1. Real submitted/pending_owner state
                 *   2. Real completed/approved/closed state
                 *   3. Completed checklist
                 *   4. Existing workflow state
                 */
                if ($rowAlreadyCompleted) {
                    $row['display_status'] = 'completed';
                } elseif ($rowChecklistCompleted) {
                    $row['display_status'] = 'completed';
                }

                /*
                 * Final asset completion flag. This is calculated from the
                 * checklist final_status OR an actual completed/approved/closed
                 * workflow status. It does NOT overwrite the workflow status.
                 */
                $row['checklist_complete'] = (
                    $rowChecklistCompleted
                    ||
                    $rowAlreadyCompleted
                );

                $jobs[] = $row;
            }
        }


        $stmt->close();
    }
}

/* =========================================================
   APPLY EXPLICIT ASSET SELECTION
   ---------------------------------------------------------
   The selected Asset determines the active $job used by:
       - Owner Attendance OTP
       - Start Inspection
       - Continue Checklist
       - Complete SAF submission

   IMPORTANT:
   We do not auto-select the first assignment.
========================================================= */

$selectedJob = null;

if ($selectedAssetId > 0) {

    foreach ($jobs as $candidateJob) {

        $candidateAssetId = (int)(
            $candidateJob['job_asset_id']
            ?? $candidateJob['assigned_asset_id']
            ?? $candidateJob['asset_id']
            ?? 0
        );

        $candidateSafId = (int)(
            $candidateJob['saf_id']
            ?? 0
        );

        if (
            $candidateAssetId === $selectedAssetId
            &&
            (
                $selectedSafId <= 0
                ||
                $candidateSafId === $selectedSafId
            )
        ) {
            $selectedJob = $candidateJob;
            break;
        }
    }
}

/*
 * Active inspection context.
 * When nothing is selected, keep $job empty so the page cannot
 * accidentally operate on another Asset.
 */
$job = $selectedJob;

$activeJobSelected = ($selectedJob !== null);


/* =========================================================
   GROUP MULTI-ASSET SAFs FOR DISPLAY
   ---------------------------------------------------------
   IMPORTANT:
   - A checklist being "Completed" only means the checklist data
     is complete. It must remain visible until Owner OTP submission.
   - Once the Inspector has actually submitted the assignment to
     the Owner (assignment submitted/final_submitted OR the job is
     pending_owner/submitted), that Asset is removed from the
     active Inspections page.
   - History remains the place for already-submitted/completed work.
   - For a multi-asset SAF, only the submitted assets disappear;
     remaining active assets continue to be shown in the same SAF.
   - If every asset in a SAF is submitted, the entire SAF card
     disappears from Inspections.
========================================================= */
$displayJobs = [];
$displaySafSeen = [];

foreach ($jobs as $displayJob) {

    $displaySafId = (int)($displayJob['saf_id'] ?? 0);

    $displayServiceId = (int)(
        $displayJob['assigned_service_id']
        ?? $displayJob['job_service_id']
        ?? $displayJob['saf_service_id']
        ?? 0
    );

    if ($displaySafId <= 0 || $displayServiceId <= 0) {
        continue;
    }

    $displayJobStatus = strtolower(
        trim((string)($displayJob['job_status'] ?? ''))
    );

    $displayAssignmentStatus = strtolower(
        trim((string)($displayJob['assignment_status'] ?? ''))
    );

    $displayIsFinalSubmitted =
        (
            in_array(
                $displayAssignmentStatus,
                ['submitted', 'final_submitted'],
                true
            )
            ||
            in_array(
                $displayJobStatus,
                ['submitted', 'pending_owner'],
                true
            )
        );

    /*
     * Submitted/final-submitted assets are no longer active work.
     * They remain available through History.
     */
    if ($displayIsFinalSubmitted) {
        continue;
    }

    $displayGroupKey = $displaySafId . ':' . $displayServiceId;

    if (isset($displaySafSeen[$displayGroupKey])) {
        continue;
    }

    $displaySafSeen[$displayGroupKey] = true;
    $displayJobs[] = $displayJob;
}

/* =========================================================
   COUNTS
========================================================= */

foreach ($jobs as $job) {

    $status =
        strtolower(
            trim(
                (string)(
                    $job['display_status']
                    ?? 'assigned'
                )
            )
        );


    if ($status === 'assigned') {

        $assignedCount++;

    } elseif ($status === 'in_progress') {

        $inProgressCount++;

    } elseif (
        in_array(
            $status,
            [
                'submitted',
                'pending_owner'
            ],
            true
        )
    ) {

        $submittedCount++;

    } elseif (
        in_array(
            $status,
            [
                'approved',
                'closed',
                'completed'
            ],
            true
        )
    ) {

        $completedCount++;
    }
}


/* =========================================================
   HEADER
========================================================= */

include('../includes/header.php');


/* =========================================================
   SIDEBAR
========================================================= */

include('../includes/sidebar-inspector.php');


/* =========================================================
   VIEW-SCOPE DEFAULTS
   ---------------------------------------------------------
   Keep the template variables explicitly initialized in the
   same render scope. This also prevents editor/static-analysis
   false positives on variables used after the PHP/HTML boundary.
========================================================= */
$viewJobs = isset($jobs) && is_array($jobs) ? $jobs : [];
$viewPageError = isset($pageError) ? (string)$pageError : '';
$viewAssignedCount = isset($assignedCount) ? (int)$assignedCount : 0;
$viewInProgressCount = isset($inProgressCount) ? (int)$inProgressCount : 0;
$viewSubmittedCount = isset($submittedCount) ? (int)$submittedCount : 0;
$viewCompletedCount = isset($completedCount) ? (int)$completedCount : 0;
$viewDisplayJobs = isset($displayJobs) && is_array($displayJobs) ? $displayJobs : [];
$viewActiveJobSelected = !empty($activeJobSelected);
$viewSelectedJob = (isset($selectedJob) && is_array($selectedJob)) ? $selectedJob : null;
$viewAttendanceError = isset($attendanceError) ? (string)$attendanceError : '';
$viewAttendanceMessage = isset($attendanceMessage) ? (string)$attendanceMessage : '';
$viewAttendancePostAssignmentId = isset($attendancePostAssignmentId) ? (int)$attendancePostAssignmentId : 0;
$viewAttendancePostJobId = isset($attendancePostJobId) ? (int)$attendancePostJobId : 0;
$viewCsrfToken = isset($csrfToken) ? (string)$csrfToken : '';


?>



<style>

.inspector-page {

    padding: 24px;

    min-height:
        calc(100vh - 70px);

    background: #f4f6f9;

}


.inspector-page-header {

    background: #fff;

    border:
        1px solid #e5e7eb;

    border-radius: 12px;

    padding: 20px 22px;

    margin-bottom: 18px;

    box-shadow:
        0 2px 10px
        rgba(0,0,0,.05);

}


.inspector-page-header h3 {

    margin: 0;

    font-weight: 700;

}


.inspector-page-header p {

    margin:
        5px 0 0;

    color: #6c757d;

}


.stat-card {

    background: #fff;

    border:
        1px solid #e5e7eb;

    border-radius: 12px;

    padding: 18px;

    height: 100%;

    box-shadow:
        0 2px 10px
        rgba(0,0,0,.05);

}


.stat-label {

    color: #6c757d;

    font-size: 13px;

    font-weight: 600;

}


.stat-value {

    font-size: 28px;

    font-weight: 700;

    margin-top: 5px;

}


.inspection-card {

    background: #fff;

    border:
        1px solid #e5e7eb;

    border-radius: 12px;

    padding: 20px;

    margin-bottom: 16px;

    box-shadow:
        0 2px 10px
        rgba(0,0,0,.05);

}


.inspection-card-top {

    display: flex;

    justify-content: space-between;

    align-items: flex-start;

    gap: 15px;

    flex-wrap: wrap;

}


.service-title {

    font-size: 19px;

    font-weight: 700;

}


.service-code {

    display: inline-block;

    margin-top: 6px;

    padding: 4px 9px;

    border-radius: 6px;

    background: #eaf2ff;

    color: #0d6efd;

    border:
        1px solid #cfe2ff;

    font-size: 12px;

    font-weight: 700;

}


.status-badge {

    padding: 6px 11px;

    border-radius: 20px;

    font-size: 12px;

    font-weight: 700;

}


.status-assigned {

    background: #cfe2ff;

    color: #084298;

}


.status-progress {

    background: #fff3cd;

    color: #856404;

}


.status-submitted {

    background: #cff4fc;

    color: #055160;

}


.status-completed {

    background: #d1e7dd;

    color: #0f5132;

}


.status-rejected {

    background: #f8d7da;

    color: #842029;

}


.info-grid {

    display: grid;

    grid-template-columns:
        repeat(4, 1fr);

    gap: 12px;

    margin-top: 18px;

}


.info-box {

    background: #f8f9fa;

    border:
        1px solid #e9ecef;

    border-radius: 9px;

    padding: 12px;

}


.info-label {

    font-size: 11px;

    color: #6c757d;

    text-transform: uppercase;

    margin-bottom: 3px;

}


.info-value {

    font-weight: 600;

    word-break: break-word;

}


.multi-asset-box {

    margin-top: 12px;

    padding: 12px 14px;

    background: #f8f9fa;

    border:
        1px solid #e9ecef;

    border-radius: 9px;

}


.multi-asset-title {

    font-size: 11px;

    color: #6c757d;

    text-transform: uppercase;

    font-weight: 700;

    margin-bottom: 5px;

}


.multi-asset-ids {

    font-weight: 700;

    line-height: 1.6;

    word-break: break-word;

}


.multi-asset-note {

    margin-top: 3px;

    font-size: 11px;

    color: #6c757d;

}


.service-info {

    margin-top: 15px;

    padding: 12px 15px;

    background: #f8f9fa;

    border:
        1px solid #e9ecef;

    border-radius: 9px;

    font-size: 13px;

}


.empty-box {

    background: #fff;

    border:
        1px solid #e5e7eb;

    border-radius: 12px;

    padding: 60px 20px;

    text-align: center;

    box-shadow:
        0 2px 10px
        rgba(0,0,0,.05);

}


.empty-box i {

    font-size: 42px;

    color: #adb5bd;

}


.empty-box h5 {

    margin-top: 12px;

}


.empty-box p {

    color: #6c757d;

}
























@media(max-width: 1000px) {

    .info-grid {

        grid-template-columns:
            repeat(2, 1fr);

    }

}


@media(max-width: 768px) {

    .inspector-page {

        padding: 12px;

    }

}


@media(max-width: 550px) {

    .info-grid {

        grid-template-columns: 1fr;

    }

    .inspection-card {

        padding: 15px;

    }

}

</style>

<style>
.multi-asset-checklist-panel{margin-top:18px;border:2px solid #dbe8f7;border-radius:12px;background:#fff;overflow:hidden}.multi-asset-panel-header{padding:14px 16px;background:#f4f8ff;border-bottom:1px solid #dbe8f7;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}.multi-asset-panel-title{font-size:16px;font-weight:900;color:#17395c}.multi-asset-panel-help{font-size:12px;color:#5d6b7c;margin-top:3px}.multi-asset-progress{padding:7px 11px;border-radius:18px;background:#e7f1ff;color:#0d6efd;font-size:11px;font-weight:900}.asset-checklist-selector-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;padding:14px}.asset-checklist-tile{scroll-margin-top:110px;border:1px solid #dfe5ec;border-radius:11px;padding:12px;background:#fff;transition:.15s}.asset-checklist-tile:hover{border-color:#0d6efd;box-shadow:0 4px 14px rgba(13,110,253,.12)}.asset-checklist-tile.selected{border:2px solid #0d6efd;background:#f4f8ff;box-shadow:0 0 0 3px rgba(13,110,253,.08)}.asset-checklist-tile.completed{border-color:#b7dfc8;background:#f5fff8}.asset-select-card{display:block;text-decoration:none;color:inherit;cursor:pointer}.asset-select-card:hover{color:inherit}.asset-select-button{margin-top:11px;padding:10px 8px;text-align:center;border:2px solid #0d6efd;border-radius:8px;background:#fff;color:#0d6efd;font-size:12px;font-weight:900}.asset-checklist-tile.selected .asset-select-button{background:#0d6efd;color:#fff}.asset-tile-top{display:flex;justify-content:space-between;align-items:center;gap:8px}.asset-tile-id{font-size:17px;font-weight:900;color:#0d6efd}.asset-tile-status{font-size:10px;font-weight:900;padding:4px 7px;border-radius:14px}.asset-tile-status.complete{background:#d1e7dd;color:#0f5132}.asset-tile-status.progress{background:#fff3cd;color:#664d03}.asset-tile-status.submitted{background:#cff4fc;color:#055160}.asset-tile-status.assigned{background:#e2e3e5;color:#41464b}.asset-tile-name{font-size:13px;font-weight:800;color:#172033;margin-top:5px}.asset-tile-info{display:flex;gap:10px;flex-wrap:wrap;margin-top:6px;color:#6c757d;font-size:11px}@media(max-width:1000px){.asset-checklist-selector-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:600px){.asset-checklist-selector-grid{grid-template-columns:1fr}}
</style>


<style>
/* =========================================================
   NLETA INSPECTOR — PROFESSIONAL INSPECTIONS UI
   ========================================================= */
.inspector-page{
    padding:28px;
    min-height:calc(100vh - 70px);
    background:#f5f7fb;
}
.inspector-page-header{
    position:relative;
    background:linear-gradient(135deg,#ffffff 0%,#f8fbff 100%);
    border:1px solid #e2e8f0;
    border-radius:16px;
    padding:22px 24px;
    margin-bottom:20px;
    box-shadow:0 8px 24px rgba(15,23,42,.06);
    overflow:hidden;
}
.inspector-page-header:before{
    content:"";
    position:absolute;
    left:0;
    top:0;
    bottom:0;
    width:5px;
    background:#0d6efd;
}
.inspector-page-header h3{
    margin:0;
    color:#172033;
    font-size:23px;
    font-weight:800;
    letter-spacing:-.2px;
}
.inspector-page-header p{
    margin:6px 0 0;
    color:#64748b;
    font-size:13px;
}
.inspector-page-header .badge{
    border-radius:10px;
    font-size:12px;
    font-weight:700;
    background:#eaf2ff!important;
    color:#0d6efd!important;
    border:1px solid #cfe2ff;
}
.stat-card{
    position:relative;
    background:#fff;
    border:1px solid #e5eaf1;
    border-radius:14px;
    padding:18px 19px;
    min-height:104px;
    box-shadow:0 5px 18px rgba(15,23,42,.055);
    transition:transform .15s ease,box-shadow .15s ease;
}
.stat-card:hover{
    transform:translateY(-2px);
    box-shadow:0 9px 24px rgba(15,23,42,.08);
}
.stat-label{
    color:#64748b;
    font-size:11px;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:.55px;
}
.stat-value{
    font-size:29px;
    line-height:1.1;
    font-weight:800;
    margin-top:9px;
    color:#172033;
}
.inspection-card{
    background:#fff;
    border:1px solid #e1e7ef;
    border-radius:16px;
    padding:22px;
    margin-bottom:18px;
    box-shadow:0 7px 25px rgba(15,23,42,.055);
    overflow:hidden;
}
.inspection-card-top{
    padding-bottom:17px;
    border-bottom:1px solid #edf1f5;
}
.service-title{
    color:#172033;
    font-size:20px;
    line-height:1.35;
    font-weight:800;
}
.service-code{
    margin-top:8px;
    padding:5px 10px;
    border-radius:7px;
    background:#eff6ff;
    color:#0d6efd;
    border:1px solid #d6e7ff;
    font-size:11px;
    font-weight:800;
}
.status-badge{
    padding:7px 12px;
    border-radius:999px;
    font-size:11px;
    font-weight:800;
    white-space:nowrap;
}
.info-grid{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:12px;
    margin-top:17px;
}
.info-box{
    min-width:0;
    background:#f8fafc;
    border:1px solid #e7ecf2;
    border-radius:11px;
    padding:13px 14px;
}
.info-label{
    font-size:10px;
    color:#64748b;
    text-transform:uppercase;
    letter-spacing:.45px;
    margin-bottom:6px;
    font-weight:800;
}
.info-value{
    color:#172033;
    font-size:13px;
    font-weight:700;
    line-height:1.45;
    word-break:break-word;
}
.multi-asset-checklist-panel{
    margin-top:19px;
    border:1px solid #dfe7f1!important;
    border-radius:14px!important;
    background:#fff;
    box-shadow:0 5px 18px rgba(15,23,42,.04);
}
.multi-asset-panel-header{
    padding:16px 18px!important;
    background:#f8fbff!important;
    border-bottom:1px solid #e2eaf3!important;
}
.multi-asset-panel-title{
    font-size:16px!important;
    color:#172033!important;
    font-weight:800!important;
}
.multi-asset-panel-help{
    color:#64748b!important;
    line-height:1.45;
}
.multi-asset-progress{
    border:1px solid #cfe2ff;
    background:#eff6ff!important;
    color:#0d6efd!important;
}
.asset-checklist-selector-grid{
    grid-template-columns:repeat(3,minmax(0,1fr))!important;
    gap:14px!important;
    padding:16px!important;
}
.asset-checklist-tile{
    border:1px solid #e1e7ef!important;
    border-radius:12px!important;
    padding:14px!important;
    background:#fff!important;
    box-shadow:0 2px 7px rgba(15,23,42,.025);
}
.asset-checklist-tile:hover{
    border-color:#9fc5ff!important;
    box-shadow:0 7px 18px rgba(13,110,253,.09)!important;
}
.asset-checklist-tile.selected{
    border:2px solid #0d6efd!important;
    background:#f7fbff!important;
    box-shadow:0 0 0 3px rgba(13,110,253,.07)!important;
}
.asset-checklist-tile.completed{
    border-color:#b7dfc8!important;
    background:#f7fff9!important;
}
.asset-tile-id{
    font-size:16px!important;
    color:#0d6efd!important;
    font-weight:800!important;
}
.asset-tile-name{
    color:#172033!important;
    font-size:13px!important;
    font-weight:700!important;
}
.asset-tile-info{
    color:#64748b!important;
    line-height:1.5;
}
.asset-select-button{
    margin-top:12px!important;
    border:1px solid #0d6efd!important;
    border-radius:8px!important;
    padding:9px 8px!important;
    font-size:11px!important;
}
.asset-checklist-tile:not(.selected) .asset-select-button{
    background:#fff!important;
}
.inspection-card .btn{
    border-radius:8px;
    font-weight:700;
}
.service-info{
    background:#f8fafc!important;
    border:1px solid #e5eaf1!important;
    border-radius:11px!important;
}
.empty-box{
    border-radius:16px;
    padding:70px 20px;
    box-shadow:0 7px 25px rgba(15,23,42,.05);
}
/* Summary cards: 2 columns on tablet + mobile, 4 on desktop */
.inspector-summary-grid > [class*="col-"]{
    min-width:0;
}
@media(max-width:1199.98px){
    .inspector-summary-grid > [class*="col-"]{
        flex:0 0 50%;
        max-width:50%;
    }
}
@media(max-width:575.98px){
    .inspector-summary-grid{
        --bs-gutter-x:12px;
        --bs-gutter-y:12px;
    }
    .inspector-summary-grid > [class*="col-"]{
        flex:0 0 50%;
        max-width:50%;
    }
    .stat-card{
        min-height:104px;
        padding:16px 14px;
    }
}

@media(max-width:1200px){
    .info-grid{grid-template-columns:repeat(2,minmax(0,1fr));}
    .asset-checklist-selector-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important;}
}
@media(max-width:768px){
    .inspector-page{padding:14px;}
    .inspection-card{padding:16px;}
    /* Tablet + Mobile: 2 information cards per row */
    .info-grid{grid-template-columns:repeat(2,minmax(0,1fr));}
}
@media(max-width:600px){
    /* Mobile: keep asset cards in 2 columns as requested */
    .asset-checklist-selector-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important;}
    .info-grid{grid-template-columns:repeat(2,minmax(0,1fr));}
    .inspector-page-header{padding:18px;}
}
</style>


<style>
/* =========================================================
   FINAL RESPONSIVE FIX — ASSIGNED ASSETS / INSPECTION CARDS
   Desktop: existing 3-column layout
   Tablet + Mobile: 2-column layout
   If only one asset exists, use full available width
   ========================================================= */
.asset-checklist-selector-grid{
    width:100%;
    max-width:100%;
    box-sizing:border-box;
    align-items:stretch;
}
.asset-checklist-tile{
    width:100%;
    min-width:0;
    max-width:none;
    box-sizing:border-box;
}
.asset-select-card{
    width:100%;
    min-width:0;
    box-sizing:border-box;
}
.asset-tile-top{
    min-width:0;
}
.asset-tile-id{
    min-width:0;
    overflow-wrap:anywhere;
    word-break:break-word;
}
.asset-tile-name{
    overflow-wrap:anywhere;
    word-break:break-word;
}
.asset-tile-info{
    min-width:0;
}
.asset-select-button{
    width:100%;
    box-sizing:border-box;
    white-space:normal;
    line-height:1.25;
}

@media (max-width:1200px){
    .asset-checklist-selector-grid{
        grid-template-columns:repeat(2,minmax(0,1fr)) !important;
    }
}

@media (max-width:768px){
    .multi-asset-checklist-panel{
        width:100%;
        max-width:100%;
        box-sizing:border-box;
    }
    .multi-asset-panel-header{
        align-items:flex-start;
    }
    .multi-asset-panel-title{
        font-size:15px !important;
    }
    .multi-asset-panel-help{
        font-size:11px;
    }
    .asset-checklist-selector-grid{
        grid-template-columns:repeat(2,minmax(0,1fr)) !important;
        gap:10px !important;
        padding:10px !important;
    }
    .asset-checklist-tile{
        padding:11px !important;
    }
    .asset-tile-top{
        align-items:flex-start;
    }
    .asset-tile-id{
        font-size:14px !important;
    }
    .asset-tile-status{
        flex:0 0 auto;
        white-space:nowrap;
    }
    .asset-tile-info{
        gap:6px 8px;
        font-size:10px;
    }
    .asset-select-button{
        font-size:10px !important;
        padding:8px 6px !important;
    }

    /* One assigned asset should not leave half the section empty */
    .asset-checklist-selector-grid > .asset-checklist-tile:only-child{
        grid-column:1 / -1;
    }
}

@media (max-width:480px){
    .asset-checklist-selector-grid{
        gap:8px !important;
        padding:8px !important;
    }
    .asset-checklist-tile{
        padding:10px !important;
        border-radius:10px !important;
    }
    .asset-tile-id{
        font-size:13px !important;
    }
    .asset-tile-name{
        font-size:11px !important;
    }
    .asset-tile-info{
        font-size:9px;
    }
    .asset-tile-status{
        font-size:8px !important;
        padding:3px 5px !important;
    }
}
</style>

<div class="content">

<div class="inspector-page">


    <!-- =====================================================
         PAGE HEADER
    ====================================================== -->

    <div class="inspector-page-header">

        <div
            class="
                d-flex
                justify-content-between
                align-items-center
                gap-3
                flex-wrap
            "
        >

            <div>

                <h3>

                    <i
                        class="
                            bi
                            bi-clipboard2-check
                            me-2
                        "
                    ></i>

                    Inspections

                </h3>

                <p>

                    Inspections assigned to you by NLETA Admin.

                </p>

            </div>


            <span
                class="
                    badge
                    bg-primary
                    px-3
                    py-2
                "
            >

                <?= count($viewJobs) ?>

                Assignment<?= count($viewJobs) === 1 ? '' : 's' ?>

            </span>

        </div>

    </div>


    <!-- =====================================================
         ERROR
    ====================================================== -->

    <?php if ($viewPageError !== ''): ?>

        <div
            class="
                alert
                alert-danger
                shadow-sm
            "
        >

            <i
                class="
                    bi
                    bi-exclamation-triangle
                    me-2
                "
            ></i>

            <?= h($viewPageError) ?>

        </div>

    <?php endif; ?>


    <!-- =====================================================
         SUMMARY
    ====================================================== -->

    <div class="row g-3 mb-4 inspector-summary-grid">


        <div class="col-12 col-md-6 col-xl-3">

            <div class="stat-card">

                <div class="stat-label">

                    Assigned

                </div>

                <div
                    class="
                        stat-value
                        text-primary
                    "
                >

                    <?= $viewAssignedCount ?>

                </div>

            </div>

        </div>


        <div class="col-12 col-md-6 col-xl-3">

            <div class="stat-card">

                <div class="stat-label">

                    In Progress

                </div>

                <div
                    class="
                        stat-value
                        text-warning
                    "
                >

                    <?= $viewInProgressCount ?>

                </div>

            </div>

        </div>


        <div class="col-12 col-md-6 col-xl-3">

            <div class="stat-card">

                <div class="stat-label">

                    Submitted

                </div>

                <div
                    class="
                        stat-value
                        text-info
                    "
                >

                    <?= $viewSubmittedCount ?>

                </div>

            </div>

        </div>


        <div class="col-12 col-md-6 col-xl-3">

            <div class="stat-card">

                <div class="stat-label">

                    Completed

                </div>

                <div
                    class="
                        stat-value
                        text-success
                    "
                >

                    <?= $viewCompletedCount ?>

                </div>

            </div>

        </div>


    </div>


    <!-- =====================================================
         ASSIGNMENTS
    ====================================================== -->

    <?php if (empty($viewJobs)): ?>


        <div class="empty-box">

            <i
                class="
                    bi
                    bi-clipboard-x
                "
            ></i>

            <h5>

                No Inspection Assigned

            </h5>

            <p>

                Once Admin assigns an inspection to you,
                the Owner-selected service and asset
                details will appear here.

            </p>

        </div>


    <?php else: ?>


        <?php foreach ($viewDisplayJobs as $displayJob): ?>

            <?php
            /* Presentation row may be the first assignment, but all operational
             * actions below must use the explicitly selected Asset job. */
            
            /* Each SAF card always uses its own display row. The selected asset is
             * used only for actions/OTP inside that same SAF card. */
            $job = $displayJob;
            $cardSafId = (int)($displayJob['saf_id'] ?? 0);
            $cardServiceId = (int)(
                $displayJob['assigned_service_id']
                ?? $displayJob['job_service_id']
                ?? $displayJob['saf_service_id']
                ?? 0
            );
            $viewSelectedJobServiceId = (int)(
                $viewSelectedJob['assigned_service_id']
                ?? $viewSelectedJob['job_service_id']
                ?? $viewSelectedJob['saf_service_id']
                ?? 0
            );
            $cardIsActive = $viewActiveJobSelected
                && $viewSelectedJob !== null
                && (int)($viewSelectedJob['saf_id'] ?? 0) === $cardSafId
                && $viewSelectedJobServiceId === $cardServiceId
                && (int)($viewSelectedJob['job_asset_id'] ?? $viewSelectedJob['assigned_asset_id'] ?? 0) === (int)($displayJob['job_asset_id'] ?? $displayJob['assigned_asset_id'] ?? 0);

            ?>


            <?php

            $status =
                strtolower(
                    trim(
                        (string)(
                            $job['display_status']
                            ?? 'assigned'
                        )
                    )
                );

            /*
             * DISPLAY STATUS — WORKFLOW STATUS HAS PRIORITY
             * --------------------------------------------
             * checklist.final_status only means the checklist itself is
             * complete. It must NEVER hide the real submission state.
             *
             * Priority:
             *   submitted / pending_owner -> Submitted to Owner
             *   completed / approved / closed -> Completed
             *   otherwise -> actual current status
             *
             * This is important after Inspector submits the checklist:
             * the checklist may contain final_status = PASS/FAIL, but the
             * Owner-facing workflow is still pending_owner/submitted.
             */
            $displayChecklist = json_decode(
                (string)($job['checklist'] ?? ''),
                true
            );

            $displayFinalStatus = is_array($displayChecklist)
                ? strtoupper(trim((string)($displayChecklist['final_status'] ?? '')))
                : '';

            $displayJobStatusRaw = strtolower(
                trim((string)($job['job_status'] ?? ''))
            );

            $displayAssignmentStatusRaw = strtolower(
                trim((string)($job['assignment_status'] ?? ''))
            );

            $displayIsSubmittedToOwner =
                in_array(
                    $displayJobStatusRaw,
                    ['submitted', 'pending_owner'],
                    true
                )
                ||
                in_array(
                    $displayAssignmentStatusRaw,
                    ['submitted'],
                    true
                );

            $displayIsActuallyCompleted =
                in_array(
                    $displayJobStatusRaw,
                    ['completed', 'approved', 'closed'],
                    true
                )
                ||
                in_array(
                    $displayAssignmentStatusRaw,
                    ['completed', 'approved', 'closed'],
                    true
                );

            if ($displayIsSubmittedToOwner) {
                /*
                 * Submitted to Owner always has priority over checklist
                 * completion.
                 */
                $status = 'submitted';
            } elseif (
                $displayIsActuallyCompleted
                ||
                (
                    is_array($displayChecklist)
                    &&
                    in_array(
                        $displayFinalStatus,
                        ['PASS', 'CONDITIONAL PASS', 'FAIL'],
                        true
                    )
                )
            ) {
                /*
                 * FIX:
                 * A completed checklist must not continue to show
                 * "In Progress". The database workflow status is left
                 * untouched; this is presentation-only.
                 */
                $status = 'completed';
            }


            switch ($status) {

                case 'in_progress':

                    $statusClass =
                        'status-progress';

                    $statusText =
                        'In Progress';

                    break;


                case 'submitted':

                case 'pending_owner':

                    $statusClass =
                        'status-submitted';

                    $statusText =
                        'Submitted';

                    break;


                case 'approved':

                case 'closed':

                case 'completed':

                    $statusClass =
                        'status-completed';

                    $statusText =
                        'Completed';

                    break;


                case 'rejected':

                    $statusClass =
                        'status-rejected';

                    $statusText =
                        'Rejected';

                    break;


                default:

                    $statusClass =
                        'status-assigned';

                    $statusText =
                        'Assigned';

                    break;
            }


            $serviceCode =
                trim(
                    (string)(
                        $job['service_code']
                        ?? ''
                    )
                );


            $serviceName =
                trim(
                    (string)(
                        $job['service_name']
                        ?? ''
                    )
                );


            if ($serviceName === '') {

                $serviceName =
                    $serviceCode !== ''
                        ? $serviceCode
                        : 'Service Inspection';
            }


            $assetDisplay =
                trim(
                    (string)(
                        $job['display_asset_id']
                        ?? ''
                    )
                );


            if ($assetDisplay === '') {

                $assetDisplay =
                    trim(
                        (string)(
                            $job['asset_name']
                            ?? ''
                        )
                    );
            }


            if ($assetDisplay === '') {

                $assetDisplay = '—';
            }


            /*
             * ONLY show assets actually assigned to this logged-in
             * Inspector. We intentionally do not use the Owner's full
             * service_request_assets list here.
             *
             * One SAF can have many assets and each asset can be assigned
             * to a different Inspector. This card therefore shows only
             * the subset belonging to the current Inspector.
             */
            $allAssetIds = [];

            foreach ($viewJobs as $assignedJobForInspector) {

                if (
                    (int)($assignedJobForInspector['inspector_id'] ?? 0) !== $inspectorId
                    ||
                    (int)($assignedJobForInspector['saf_id'] ?? 0) !== (int)($job['saf_id'] ?? 0)
                ) {
                    continue;
                }

                $assignedAssetDisplay = trim(
                    (string)(
                        $assignedJobForInspector['display_asset_id']
                        ?? ''
                    )
                );

                if ($assignedAssetDisplay === '') {
                    $assignedAssetDisplay = trim(
                        (string)(
                            $assignedJobForInspector['asset_name']
                            ?? ''
                        )
                    );
                }

                if (
                    $assignedAssetDisplay !== ''
                    &&
                    !in_array(
                        $assignedAssetDisplay,
                        $allAssetIds,
                        true
                    )
                ) {
                    $allAssetIds[] = $assignedAssetDisplay;
                }
            }

            if (empty($allAssetIds)) {
                $allAssetIds = [$assetDisplay];
            }

            $allAssetIdsDisplay = implode(', ', $allAssetIds);
            $assetCount = count($allAssetIds);

            $inspectionDate = '—';


            if (
                !empty(
                    $job[
                        'proposed_inspection_date'
                    ]
                )
            ) {

                try {

                    $inspectionDate =
                        (
                            new DateTime(
                                $job[
                                    'proposed_inspection_date'
                                ],
                                new DateTimeZone(
                                    'Asia/Kolkata'
                                )
                            )
                        )->format(
                            'd / m / Y'
                        );

                } catch (Throwable $e) {

                    $inspectionDate = '—';
                }
            }


            $assignedDate = '—';


            if (
                !empty(
                    $job['assigned_at']
                )
            ) {

                try {

                    $assignedDate =
                        (
                            new DateTime(
                                $job['assigned_at'],
                                new DateTimeZone(
                                    'UTC'
                                )
                            )
                        )
                        ->setTimezone(
                            new DateTimeZone(
                                'Asia/Kolkata'
                            )
                        )
                        ->format(
                            'd / m / Y h:i A'
                        );

                } catch (Throwable $e) {

                    $assignedDate = '—';
                }
            }


            $jobId =
                (int)(
                    $job['job_id']
                    ?? 0
                );


            $assignmentId =
                (int)(
                    $job['assignment_id']
                    ?? 0
                );

            $attendanceVerified =
                !empty(
                    $job['attendance_verified_at']
                );

            ?>


            <div class="inspection-card">


                <!-- =================================================
                     TOP
                ================================================== -->

                <div class="inspection-card-top">


                    <div>

                        <div class="service-title">

                            <?= h(
                                $serviceName
                            ) ?>

                        </div>


                        <?php if (
                            $serviceCode !== ''
                        ): ?>

                            <span
                                class="service-code"
                            >

                                Service:
                                <?= h(
                                    $serviceCode
                                ) ?>

                            </span>

                        <?php endif; ?>


                        <div
                            class="
                                text-muted
                                small
                                mt-2
                            "
                        >

                            Assignment #<?= $assignmentId ?>

                            &nbsp; | &nbsp;

                            Job #<?= $jobId ?>

                        </div>

                    </div>


                    <span
                        class="
                            status-badge
                            <?= h(
                                $statusClass
                            ) ?>
                        "
                    >

                        <?= h(
                            $statusText
                        ) ?>

                    </span>


                </div>


                <!-- =================================================
                     INFORMATION
                ================================================== -->

                <div class="info-grid">


                    <div class="info-box">

                        <div class="info-label">

                            Owner

                        </div>

                        <div class="info-value">

                            <?= h(
                                $job['owner_name']
                                ?? '—'
                            ) ?>

                        </div>

                    </div>


                    <div class="info-box">

                        <div class="info-label">

                            Owner Contact No.

                        </div>

                        <div class="info-value">

                            <?php
                            $ownerContactDisplay = trim(
                                (string)(
                                    $job['owner_contact_no']
                                    ?? ''
                                )
                            );

                            if ($ownerContactDisplay === '') {
                                $ownerContactDisplay = '—';
                            }
                            ?>

                            <?php if ($ownerContactDisplay !== '—'): ?>

                                <a
                                    href="tel:<?= h($ownerContactDisplay) ?>"
                                    class="text-decoration-none"
                                >
                                    <i class="bi bi-telephone-fill me-1"></i>
                                    <?= h($ownerContactDisplay) ?>
                                </a>

                            <?php else: ?>

                                <?= h($ownerContactDisplay) ?>

                            <?php endif; ?>

                        </div>

                    </div>


                    <div class="info-box">

                        <div class="info-label">

                            Assets Assigned to You

                        </div>

                        <div class="info-value">

                            <?= h(
                                $allAssetIdsDisplay
                            ) ?>

                        </div>

                        <div
                            class="
                                small
                                text-muted
                                mt-1
                            "
                        >

                            <?= $assetCount ?>
                            Asset<?= $assetCount === 1 ? '' : 's' ?>
                            selected

                        </div>

                    </div>


                    <div class="info-box">

                        <div class="info-label">

                            Location

                        </div>

                        <div class="info-value">

                            <?= h(
                                $job['location']
                                ?? '—'
                            ) ?>

                        </div>

                    </div>


                    <div class="info-box">

                        <div class="info-label">

                            Proposed Inspection Date

                        </div>

                        <div class="info-value">

                            <?= h(
                                $inspectionDate
                            ) ?>

                        </div>

                    </div>


                </div>


                <!-- =================================================
                     SAF INFORMATION
                ================================================== -->

                <div
                    class="
                        mt-3
                        d-flex
                        gap-3
                        flex-wrap
                        small
                        text-muted
                    "
                >

                    <span>

                        <strong>SAF:</strong>

                        <?= h(
                            $job['saf_no']
                            ?? '—'
                        ) ?>

                    </span>


                    <span>

                        <strong>PSGA:</strong>

                        <?= h(
                            $job['psga_no']
                            ?? '—'
                        ) ?>

                    </span>


                    <span>

                        <strong>Assigned:</strong>

                        <?= h(
                            $assignedDate
                        ) ?>

                    </span>

                </div>


                <!-- =================================================
                     SITE ATTENDANCE — INLINE OWNER OTP
                     No separate attendance page is used.
                ================================================== -->

                <?php if ($cardIsActive && $status === 'assigned'): ?>

                    <div
                        class="attendance-inline mt-3 service-info"
                    >

                        <div
                            class="
                                d-flex
                                justify-content-between
                                align-items-start
                                gap-3
                                flex-wrap
                            "
                        >

                            <div>

                                <div
                                    class="fw-bold fs-5"
                                >

                                    <i
                                        class="
                                            bi
                                            bi-shield-check
                                            text-primary
                                            me-1
                                        "
                                    ></i>

                                    Site Attendance Verification

                                </div>

                                <div
                                    class="
                                        small
                                        text-muted
                                        mt-1
                                    "
                                >

                                    Owner OTP verification is required
                                    before Start Inspection is unlocked.

                                </div>

                            </div>

                            <?php if ($attendanceVerified): ?>

                                <span
                                    class="
                                        badge
                                        bg-success
                                        px-3
                                        py-2
                                    "
                                >

                                    <i
                                        class="
                                            bi
                                            bi-check-circle-fill
                                            me-1
                                        "
                                    ></i>

                                    Attendance Verified

                                </span>

                            <?php endif; ?>

                        </div>


                        <?php if ($viewAttendanceError !== ''): ?>

                            <?php
                            $postTargetMatchesThisJob =
                                $viewAttendancePostAssignmentId ===
                                    $assignmentId
                                &&
                                $viewAttendancePostJobId ===
                                    $jobId;
                            ?>

                            <?php if (
                                $postTargetMatchesThisJob
                            ): ?>

                                <div
                                    class="
                                        alert
                                        alert-danger
                                        mt-3
                                        mb-0
                                    "
                                >

                                    <?= h(
                                        $viewAttendanceError
                                    ) ?>

                                </div>

                            <?php endif; ?>

                        <?php endif; ?>


                        <?php if ($viewAttendanceMessage !== ''): ?>

                            <?php
                            $postTargetMatchesThisJob =
                                $viewAttendancePostAssignmentId ===
                                    $assignmentId
                                &&
                                $viewAttendancePostJobId ===
                                    $jobId;
                            ?>

                            <?php if (
                                $postTargetMatchesThisJob
                            ): ?>

                                <div
                                    class="
                                        alert
                                        alert-success
                                        mt-3
                                        mb-0
                                    "
                                >

                                    <?= h(
                                        $viewAttendanceMessage
                                    ) ?>

                                </div>

                            <?php endif; ?>

                        <?php endif; ?>


                        <?php if (
                            !$attendanceVerified
                        ): ?>

                            <div
                                class="
                                    mt-3
                                    d-flex
                                    gap-2
                                    flex-wrap
                                "
                            >

                                <form
                                    method="POST"
                                    class="m-0"
                                >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= h(
                                            $viewCsrfToken
                                        ) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="attendance_assignment_id"
                                        value="<?= $assignmentId ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="attendance_job_id"
                                        value="<?= $jobId ?>"
                                    >
                                    <input
                                        type="hidden"
                                        name="selected_asset_id"
                                        value="<?= (int)$selectedAssetId ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="selected_saf_id"
                                        value="<?= (int)($job['saf_id'] ?? 0) ?>"
                                    >

                                    <button
                                        type="submit"
                                        name="send_attendance_otp"
                                        class="
                                            btn
                                            btn-outline-primary
                                        "
                                    >

                                        <i
                                            class="
                                                bi
                                                bi-envelope-check
                                                me-1
                                            "
                                        ></i>

                                        Send OTP to Owner

                                    </button>

                                </form>

                            </div>


                            <div
                                class="
                                    mt-3
                                    pt-3
                                "
                                style="
                                    border-top:1px solid #e5e7eb;
                                "
                            >

                                <div
                                    class="
                                        fw-bold
                                        mb-1
                                    "
                                >

                                    Step 2 — Verify Owner OTP

                                </div>

                                <div
                                    class="
                                        small
                                        text-muted
                                        mb-2
                                    "
                                >

                                    Enter the 6-digit OTP provided by
                                    the Owner.

                                </div>

                                <form
                                    method="POST"
                                    class="
                                        d-flex
                                        gap-2
                                        flex-wrap
                                        align-items-center
                                    "
                                >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= h(
                                            $viewCsrfToken
                                        ) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="attendance_assignment_id"
                                        value="<?= $assignmentId ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="attendance_job_id"
                                        value="<?= $jobId ?>"
                                    >
                                    <input
                                        type="hidden"
                                        name="selected_asset_id"
                                        value="<?= (int)$selectedAssetId ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="selected_saf_id"
                                        value="<?= (int)($job['saf_id'] ?? 0) ?>"
                                    >

                                    <input
                                        type="text"
                                        name="attendance_otp"
                                        class="
                                            form-control
                                        "
                                        inputmode="numeric"
                                        autocomplete="one-time-code"
                                        maxlength="6"
                                        pattern="[0-9]{6}"
                                        placeholder="000000"
                                        required
                                    >

                                    <button
                                        type="submit"
                                        name="verify_attendance_otp"
                                        class="
                                            btn
                                            btn-primary
                                        "
                                    >

                                        <i
                                            class="
                                                bi
                                                bi-shield-check
                                                me-1
                                            "
                                        ></i>

                                        Verify OTP

                                    </button>

                                </form>

                            </div>

                        <?php else: ?>

                            <div
                                class="
                                    mt-3
                                    small
                                    text-success
                                "
                            >

                                <i
                                    class="
                                        bi
                                        bi-unlock-fill
                                        me-1
                                    "
                                ></i>

                                Owner attendance verified.
                                Start Inspection is unlocked.

                            </div>

                        <?php endif; ?>

                    </div>

                <?php endif; ?>


                <!-- =================================================
                     MULTI-ASSET CHECKLIST SELECTOR
                ================================================== -->
                <?php
                $assetInspectionRows = [];

/*
 * The asset selector must be visible BEFORE an Asset is selected.
 * Therefore its SAF/request context is taken from the selected job
 * when available, otherwise from the first assigned job only for
 * loading the card list. This does NOT select that Asset.
 */
$selectorSourceJob = $displayJob;

if (
    $viewSelectedJob !== null
    && (int)($viewSelectedJob['saf_id'] ?? 0) === $cardSafId
) {
    $selectorSourceJob = $viewSelectedJob;
}

$selectorRequestId = (int)($selectorSourceJob['job_request_id'] ?? 0);
$selectorSafId = (int)($selectorSourceJob['saf_id'] ?? 0);
$selectorServiceId = (int)(
    $selectorSourceJob['assigned_service_id']
    ?? $selectorSourceJob['job_service_id']
    ?? $selectorSourceJob['saf_service_id']
    ?? 0
);

if ($selectorRequestId > 0 && $selectorSafId > 0 && $selectorServiceId > 0) {
                    /*
                     * IMPORTANT SECURITY / VISIBILITY RULE:
                     * The Inspector must see ONLY active assets for which an
                     * inspector_assignments row exists for this logged-in
                     * Inspector. Assets already submitted to the Owner are
                     * excluded here and remain available in History. Do NOT load every asset from the Owner's
                     * Service Request and then filter only in the UI.
                     *
                     * INNER JOINs below make the restriction server-side.
                     */
                    $assetSelectorStmt = $conn->prepare("
                        SELECT DISTINCT
                            a.id AS asset_id,
                            a.display_asset_id,
                            a.asset_name,
                            a.location,
                            a.asset_type,
                            mj.id AS job_id,
                            mj.status AS job_status,
                            mj.checklist,
                            ia.id AS assignment_id,
                            ia.status AS assignment_status,
                            iaa.verified_at AS attendance_verified_at
                        FROM inspector_assignments ia
                        INNER JOIN maintenance_jobs mj
                            ON mj.id = ia.job_id
                            AND mj.saf_id = ?
                            AND mj.job_type = 'saf_inspection'
                        INNER JOIN assets a
                            ON a.id = mj.asset_id
                        LEFT JOIN inspector_attendance iaa
                            ON iaa.assignment_id = ia.id
                            AND iaa.job_id = mj.id
                            AND iaa.inspector_id = ia.inspector_id
                        WHERE
                            ia.inspector_id = ?
                            AND ia.service_id = ?
                            AND mj.owner_id = ?
                        ORDER BY a.display_asset_id ASC, a.id ASC
                    ");
                    if ($assetSelectorStmt) {
                        $selectorOwnerId = (int)($selectorSourceJob['job_owner_id'] ?? $selectorSourceJob['assigned_owner_id'] ?? 0);
                        $assetSelectorStmt->bind_param('iiii', $selectorSafId, $inspectorId, $selectorServiceId, $selectorOwnerId);
                        if ($assetSelectorStmt->execute()) {
                            $assetSelectorResult=$assetSelectorStmt->get_result();
                            while($assetSelectorRow=$assetSelectorResult->fetch_assoc()){

                                /*
                                 * FINAL SUBMISSION VISIBILITY RULE
                                 * ---------------------------------
                                 * Once this particular Asset has actually
                                 * been submitted to the Owner, remove it from
                                 * the active Inspections selector. It remains
                                 * available in History.
                                 *
                                 * Do NOT use checklist.final_status here.
                                 * "Completed" before Owner OTP is still active
                                 * work and must remain visible.
                                 */
                                $selectorStoredJobStatus = strtolower(
                                    trim((string)($assetSelectorRow['job_status'] ?? ''))
                                );

                                $selectorStoredAssignmentStatus = strtolower(
                                    trim((string)($assetSelectorRow['assignment_status'] ?? ''))
                                );

                                $selectorFinalSubmitted =
                                    (
                                        in_array(
                                            $selectorStoredAssignmentStatus,
                                            ['submitted', 'final_submitted'],
                                            true
                                        )
                                        ||
                                        in_array(
                                            $selectorStoredJobStatus,
                                            ['submitted', 'pending_owner'],
                                            true
                                        )
                                    );

                                if ($selectorFinalSubmitted) {
                                    continue;
                                }

                                /*
                                 * An asset is COMPLETE when its own checklist
                                 * has a valid final_status OR its own job/
                                 * assignment has already been marked completed.
                                 *
                                 * This makes the UI reliable even for records
                                 * saved before the status synchronization fix.
                                 */
                                $savedSelector=json_decode((string)($assetSelectorRow['checklist']??''),true);

                                $finalSelector=is_array($savedSelector)
                                    ?strtoupper(trim((string)($savedSelector['final_status']??'')))
                                    :'';

                                $selectorJobStatus=strtolower(
                                    trim((string)($assetSelectorRow['job_status']??''))
                                );

                                $selectorAssignmentStatus=strtolower(
                                    trim((string)($assetSelectorRow['assignment_status']??''))
                                );

                                    $assetSelectorRow['checklist_complete'] =
                                    in_array(
                                        $finalSelector,
                                        ['PASS','CONDITIONAL PASS','FAIL'],
                                        true
                                    )
                                    ||
                                    in_array(
                                        $selectorJobStatus,
                                        ['completed','approved','closed'],
                                        true
                                    )
                                    ||
                                    in_array(
                                        $selectorAssignmentStatus,
                                        ['completed','approved','closed'],
                                        true
                                    );

                                /*
                                 * IMPORTANT: checklist completion must NOT
                                 * overwrite the real job/assignment workflow
                                 * status. After Owner OTP submission, the job
                                 * may correctly be pending_owner/submitted.
                                 * Keeping that status is what prevents the
                                 * final submission button from appearing again.
                                 */

                                $assetInspectionRows[]=$assetSelectorRow;
                            }
                        }
                        $assetSelectorStmt->close();
                    }
                }
                /*
                 * IMPORTANT SECURITY RULE:
                 * Never fall back to $selectorSourceJob here.
                 * If no inspector_assignments row exists for this logged-in
                 * Inspector, the asset must not be displayed.
                 */
                $selectorCompleted=0;
                foreach($assetInspectionRows as $assetSelectorRow){
                    if(!empty($assetSelectorRow['checklist_complete'])){
                        $selectorCompleted++;
                    }
                }

                $selectorTotal = count($assetInspectionRows);

                /*
                 * COMPLETE SAF SUBMISSION
                 * ---------------------
                 * The button is available only when every selected
                 * asset has a completed checklist.
                 *
                 * The actual submission is NOT trusted to the UI.
                 * submit-complete-saf.php performs the authoritative
                 * server-side validation again.
                 */
                $allAssetsCompleted =
                    $selectorTotal > 0
                    && $selectorCompleted === $selectorTotal;

                /*
                 * An assignment being submitted is NOT the same as the
                 * whole SAF being submitted. In a multi-Inspector SAF,
                 * each Inspector submits only their own assigned assets.
                 */
                $allMyAssignmentsSubmitted =
                    $selectorTotal > 0;

                foreach ($assetInspectionRows as $submissionRow) {
                    $submissionJobStatus = strtolower(trim((string)($submissionRow['job_status'] ?? '')));
                    $submissionAssignmentStatus = strtolower(trim((string)($submissionRow['assignment_status'] ?? '')));

                    if (
                        !in_array($submissionJobStatus, ['submitted', 'pending_owner'], true)
                        &&
                        $submissionAssignmentStatus !== 'submitted'
                    ) {
                        $allMyAssignmentsSubmitted = false;
                        break;
                    }
                }

                /* Actual SAF status: pending_owner means all assigned
                   asset jobs have reached Owner-review stage. */
                $safActuallySubmitted = false;

                if ($selectorSafId > 0) {
                    $safStatusStmt = $conn->prepare("
                        SELECT COUNT(*) AS c
                        FROM maintenance_jobs
                        WHERE saf_id = ?
                          AND job_type = 'saf_inspection'
                          AND status = 'pending_owner'
                    ");

                    if ($safStatusStmt) {
                        $safStatusStmt->bind_param('i', $selectorSafId);
                        if ($safStatusStmt->execute()) {
                            $safStatusRow = $safStatusStmt->get_result()->fetch_assoc();
                            $pendingOwnerJobs = (int)($safStatusRow['c'] ?? 0);

                            $totalSafJobsStmt = $conn->prepare("
                                SELECT COUNT(*) AS c
                                FROM maintenance_jobs
                                WHERE saf_id = ?
                                  AND job_type = 'saf_inspection'
                            ");

                            if ($totalSafJobsStmt) {
                                $totalSafJobsStmt->bind_param('i', $selectorSafId);
                                if ($totalSafJobsStmt->execute()) {
                                    $totalSafJobsRow = $totalSafJobsStmt->get_result()->fetch_assoc();
                                    $totalSafJobs = (int)($totalSafJobsRow['c'] ?? 0);
                                    $safActuallySubmitted =
                                        $totalSafJobs > 0 &&
                                        $pendingOwnerJobs === $totalSafJobs;
                                }
                                $totalSafJobsStmt->close();
                            }
                        }
                        $safStatusStmt->close();
                    }
                }
                ?>

                <div class="multi-asset-checklist-panel">
                    <?php if (!$viewActiveJobSelected): ?>
                        <div class="alert alert-primary rounded-0 border-0 border-bottom mb-0">
                            <strong>
                                <i class="bi bi-hand-index-thumb me-1"></i>
                                Step 1 — Select one of your assigned assets/lifts.
                            </strong>
                            
                        </div>
                    <?php endif; ?>
                    <div class="multi-asset-panel-header">
                        <div>
                            <div class="multi-asset-panel-title"><i class="bi bi-layers me-1"></i>Your Assigned Assets / Lifts</div>
                            <div class="multi-asset-panel-help">Only assets assigned to your Inspector account are shown below. Select a lift to open its own inspection job and checklist.</div>
                        </div>
                        <div class="multi-asset-progress"><?= $selectorCompleted ?> / <?= count($assetInspectionRows) ?> Complete</div>
                    </div>
                    <div class="asset-checklist-selector-grid">
                        <?php foreach($assetInspectionRows as $assetSelectorRow): ?>
                            <?php
                            $selectorJobId=(int)($assetSelectorRow['job_id']??0);
                            $selectorAssignmentId=(int)($assetSelectorRow['assignment_id']??0);
                            $selectorAssetId=trim((string)($assetSelectorRow['display_asset_id']??''));
                            if($selectorAssetId==='')$selectorAssetId='Asset #'.(int)($assetSelectorRow['asset_id']??0);
                            $selectorStatus=strtolower(trim((string)($assetSelectorRow['job_status']??'assigned')));
                            $selectorAssignmentStatus=strtolower(trim((string)($assetSelectorRow['assignment_status']??'')));
                            $selectorAttendance=!empty($assetSelectorRow['attendance_verified_at']);
                            $selectorComplete=!empty($assetSelectorRow['checklist_complete']);
                            $selectorCurrent=(
                                $selectedAssetId > 0
                                && $selectedSafId > 0
                                && (int)($assetSelectorRow['asset_id'] ?? 0) === $selectedAssetId
                                && $selectorSafId === $selectedSafId
                                && $selectorServiceId === (int)($viewSelectedJob['assigned_service_id'] ?? $viewSelectedJob['job_service_id'] ?? $viewSelectedJob['saf_service_id'] ?? 0)
                            );
                            ?>
                            <div
                                id="asset-selector-<?= (int)($assetSelectorRow['asset_id'] ?? 0) ?>"
                                class="asset-checklist-tile <?= $selectorCurrent?'selected':'' ?> <?= $selectorComplete?'completed':'' ?>"
                            >
                                <a
                                    href="inspections.php?saf_id=<?= (int)$selectorSafId ?>&asset_id=<?= (int)($assetSelectorRow['asset_id']??0) ?>#asset-selector-<?= (int)($assetSelectorRow['asset_id'] ?? 0) ?>"
                                    class="asset-select-card"
                                >
                                    <div class="asset-tile-top">
                                        <span class="asset-tile-id"><?= h($selectorAssetId) ?></span>

                                        <?php if(in_array($selectorStatus,['submitted','pending_owner'],true) || $selectorAssignmentStatus === 'submitted'): ?>
                                            <span class="asset-tile-status submitted">
                                                <i class="bi bi-send-check me-1"></i>Submitted to Owner
                                            </span>
                                        <?php elseif($selectorComplete): ?>
                                            <span class="asset-tile-status complete">
                                                <i class="bi bi-check-circle me-1"></i>Completed
                                            </span>
                                        <?php elseif($selectorStatus==='in_progress'): ?>
                                            <span class="asset-tile-status progress">In Progress</span>
                                        <?php else: ?>
                                            <span class="asset-tile-status assigned">Assigned</span>
                                        <?php endif; ?>

                                    </div>

                                    <div class="asset-tile-name">
                                        <?= h($assetSelectorRow['asset_name']??'—') ?>
                                    </div>

                                    <div class="asset-tile-info">
                                        <span>
                                            <i class="bi bi-geo-alt me-1"></i>
                                            <?= h($assetSelectorRow['location']??'—') ?>
                                        </span>
                                        <span>
                                            <i class="bi bi-box me-1"></i>
                                            <?= h($assetSelectorRow['asset_type']??'Lift') ?>
                                        </span>
                                    </div>

                                    <div class="asset-select-button">
                                        <?php if ($selectorCurrent): ?>
                                            <i class="bi bi-check-circle-fill me-1"></i>
                                            SELECTED ASSET
                                        <?php else: ?>
                                            <i class="bi bi-hand-index-thumb me-1"></i>
                                            SELECT THIS ASSET FIRST
                                        <?php endif; ?>
                                    </div>
                                </a>

                                <?php if($selectorCurrent && $selectorJobId>0&&$selectorAssignmentId>0): ?>
                                    <?php if($selectorStatus==='assigned'&&$selectorAttendance): ?>
                                        <a href="start-inspection.php?assignment_id=<?= $selectorAssignmentId ?>&job_id=<?= $selectorJobId ?>&service_id=<?= (int)$selectorServiceId ?>" class="btn btn-sm btn-primary w-100 mt-2">
                                            <i class="bi bi-play-circle me-1"></i>Start Inspection
                                        </a>
                                    <?php elseif($selectorStatus==='assigned'): ?>
                                        <span class="btn btn-sm btn-outline-secondary disabled w-100 mt-2">
                                            <i class="bi bi-lock-fill me-1"></i>Attendance OTP Required
                                        </span>
                                    <?php elseif($selectorStatus==='in_progress'): ?>
                                        <a href="saf-checklist.php?assignment_id=<?= $selectorAssignmentId ?>&job_id=<?= $selectorJobId ?>&service_id=<?= (int)$selectorServiceId ?>" class="btn btn-sm btn-warning w-100 mt-2">
                                            <i class="bi bi-list-check me-1"></i>Open Checklist
                                        </a>
                                    <?php else: ?>
                                        <a href="saf-checklist.php?assignment_id=<?= $selectorAssignmentId ?>&job_id=<?= $selectorJobId ?>&service_id=<?= (int)$selectorServiceId ?>" class="btn btn-sm btn-outline-success w-100 mt-2">
                                            <i class="bi bi-eye me-1"></i>View Checklist
                                        </a>
                                    <?php endif; ?>
                                <?php elseif($selectorCurrent): ?>
                                    <span class="btn btn-sm btn-outline-danger disabled w-100 mt-2">
                                        Inspection Job Not Available
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- =================================================
                     COMPLETE SAF SUBMISSION TO OWNER
                     -------------------------------------------------
                     Final submission belongs on the Inspections page,
                     NOT on the individual asset checklist page.

                     One SAF can contain multiple selected assets.
                     Every asset checklist must be complete before
                     this button is shown.

                     Server-side validation is performed again by
                     submit-complete-saf.php.
                ================================================== -->

                <?php if ($cardIsActive && $safActuallySubmitted): ?>

                    <div class="mt-3 alert alert-info d-flex align-items-center gap-2 mb-0">
                        <i class="bi bi-send-check-fill"></i>
                        <div>
                            <strong>Complete SAF Submitted to Owner</strong>
                            <div class="small">All asset inspection jobs for this SAF have been submitted to Owner and are awaiting Owner review.</div>
                        </div>
                    </div>

                <?php elseif ($cardIsActive && $allMyAssignmentsSubmitted): ?>

                    <div class="mt-3 alert alert-success d-flex align-items-center gap-2 mb-0">
                        <i class="bi bi-check-circle-fill"></i>
                        <div>
                            <strong>Your Assigned Checklist(s) Submitted to Owner</strong>
                            <div class="small">Your assigned asset checklist(s) have been submitted. The complete SAF is sent to Owner review after all assigned Inspectors submit their assets.</div>
                        </div>
                    </div>

                <?php elseif ($cardIsActive && $allAssetsCompleted): ?>

                    <div class="mt-3 p-3" style="border:2px solid #b7dfc8;border-radius:10px;background:#f5fff8;">
                        <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap">
                            <div>
                                <div class="fw-bold text-success">
                                    <i class="bi bi-check-circle-fill me-1"></i>
                                    Your Assigned Asset Checklist(s) Complete
                                </div>
                                <div class="small text-muted mt-1">
                                    <?= $selectorCompleted ?> of <?= $selectorTotal ?> asset checklist(s) assigned to you are complete.
                                    Submit your completed checklist(s). The SAF will go to the Owner only after all assigned Inspectors submit their assets.
                                </div>
                            </div>

                            <form method="POST"
                                  action="submit-complete-saf.php"
                                  class="m-0"
                                  onsubmit="return confirm('Submit your completed assigned asset checklist(s)? Other Inspector assignments will not be changed.');">

                                <input type="hidden" name="csrf_token" value="<?= h($viewCsrfToken) ?>">
                                <input type="hidden" name="saf_id" value="<?= (int)$selectorSafId ?>">

                                <?php
                                $submissionContextJobId = 0;
                                $submissionContextAssignmentId = 0;
                                $submissionContextAssetId = 0;

                                foreach ($assetInspectionRows as $submissionContextRow) {
                                    $submissionContextJobStatus = strtolower(trim((string)($submissionContextRow['job_status'] ?? '')));
                                    $submissionContextAssignmentStatus = strtolower(trim((string)($submissionContextRow['assignment_status'] ?? '')));
                                    $submissionContextChecklistComplete = !empty($submissionContextRow['checklist_complete']);

                                    if (
                                        $submissionContextChecklistComplete
                                        &&
                                        !in_array($submissionContextJobStatus, ['submitted', 'pending_owner'], true)
                                        &&
                                        $submissionContextAssignmentStatus !== 'submitted'
                                    ) {
                                        $submissionContextJobId = (int)($submissionContextRow['job_id'] ?? 0);
                                        $submissionContextAssignmentId = (int)($submissionContextRow['assignment_id'] ?? 0);
                                        $submissionContextAssetId = (int)($submissionContextRow['asset_id'] ?? 0);
                                        break;
                                    }
                                }
                                ?>

                                <input type="hidden" name="job_id" value="<?= $submissionContextJobId ?>">
                                <input type="hidden" name="assignment_id" value="<?= $submissionContextAssignmentId ?>">
                                <input type="hidden" name="selected_asset_id" value="<?= $submissionContextAssetId ?>">

                                <button type="submit" name="request_submission_otp" value="1" class="btn btn-success px-4">
                                    <i class="bi bi-send-check me-1"></i>
                                    Verify Owner OTP & Submit Checklist(s)
                                </button>
                            </form>
                        </div>
                    </div>

                <?php elseif ($cardIsActive): ?>

                    <div class="mt-3 alert alert-warning d-flex align-items-center gap-2 mb-0">
                        <i class="bi bi-hourglass-split"></i>
                        <div>
                            <strong>Complete Your Assigned Asset Checklist(s) First</strong>
                            <div class="small">
                                <?= $selectorCompleted ?> of <?= $selectorTotal ?> assigned asset checklist(s) are complete.
                                The submit button appears after all assets assigned to you are completed.
                            </div>
                        </div>
                    </div>

                <?php endif; ?>


                <!-- =================================================
                     ACTION
                ================================================== -->

                <?php if ($cardIsActive): ?>

                    <?php if (
                        $status === 'assigned'
                        &&
                        $attendanceVerified
                    ): ?>

                        <?php
                        /*
                         * Start Inspection is unlocked only after
                         * Owner site-attendance OTP verification.
                         *
                         * There is intentionally NO check here
                         * against proposed_inspection_date.
                         */
                        ?>

                        <a
                            href="start-inspection.php?assignment_id=<?= $assignmentId ?>&job_id=<?= $jobId ?>&service_id=<?= (int)$cardServiceId ?>"
                            class="
                                btn
                                btn-primary
                            "
                        >

                            <i
                                class="
                                    bi
                                    bi-play-circle
                                    me-1
                                "
                            ></i>

                            Start Inspection

                        </a>


                    <?php elseif (
                        $status === 'assigned'
                    ): ?>

                        <span
                            class="
                                btn
                                btn-outline-secondary
                                disabled
                            "
                            aria-disabled="true"
                            title="Owner OTP site attendance verification is required first."
                        >

                            <i
                                class="
                                    bi
                                    bi-lock-fill
                                    me-1
                                "
                            ></i>

                            Start Inspection Locked

                        </span>


                    <?php elseif (
                        $status === 'in_progress'
                    ): ?>

                        <a
                            href="saf-checklist.php?assignment_id=<?= $assignmentId ?>&job_id=<?= $jobId ?>"
                            class="
                                btn
                                btn-warning
                            "
                        >

                            <i
                                class="
                                    bi
                                    bi-list-check
                                    me-1
                                "
                            ></i>

                            Continue Checklist

                        </a>


                    <?php elseif (
                        in_array(
                            $status,
                            [
                                'submitted',
                                'pending_owner'
                            ],
                            true
                        )
                    ): ?>

                        <span
                            class="
                                btn
                                btn-outline-info
                                disabled
                            "
                        >

                            <i
                                class="
                                    bi
                                    bi-hourglass-split
                                    me-1
                                "
                            ></i>

                            Awaiting Review

                        </span>


                    <?php elseif (
                        in_array(
                            $status,
                            [
                                'approved',
                                'closed',
                                'completed'
                            ],
                            true
                        )
                    ): ?>

                        <span
                            class="
                                btn
                                btn-outline-success
                                disabled
                            "
                        >

                            <i
                                class="
                                    bi
                                    bi-check-circle
                                    me-1
                                "
                            ></i>

                            Inspection Completed

                        </span>


                    <?php elseif (
                        $status === 'rejected'
                    ): ?>

                        <span
                            class="
                                btn
                                btn-outline-danger
                                disabled
                            "
                        >

                            <i
                                class="
                                    bi
                                    bi-x-circle
                                    me-1
                                "
                            ></i>

                            Inspection Rejected

                        </span>

                    <?php endif; ?>

                </div>

                <?php endif; ?>


            </div>


        <?php endforeach; ?>


    <?php endif; ?>


</div>

</div>


<!-- =========================================================
     OWNER OTP — FINAL CHECKLIST SUBMISSION
========================================================= -->
<?php
$openSubmissionOtp = isset($_GET['submission_otp']) && $_GET['submission_otp'] === '1';
$otpSafForModal = (int)($_GET['saf_id'] ?? 0);
$otpJobForModal = 0;
$otpAssignmentForModal = 0;
$otpAssetForModal = 0;
if ($otpSafForModal > 0) {
    foreach ($viewJobs as $otpJobRow) {
        if ((int)($otpJobRow['saf_id'] ?? 0) === $otpSafForModal) {
            $otpJobForModal = (int)($otpJobRow['job_id'] ?? 0);
            $otpAssignmentForModal = (int)($otpJobRow['assignment_id'] ?? 0);
            $otpAssetForModal = (int)($otpJobRow['job_asset_id'] ?? $otpJobRow['assigned_asset_id'] ?? 0);
            break;
        }
    }
}
?>

<?php if ($openSubmissionOtp && $otpSafForModal > 0): ?>
<div class="modal fade" id="ownerSubmissionOtpModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius:16px;overflow:hidden;">
            <div class="modal-header bg-primary text-white">
                <div>
                    <h5 class="modal-title mb-1"><i class="bi bi-shield-lock me-2"></i>Owner Authorization Required</h5>
                    <div class="small opacity-75">Checklist submission OTP verification</div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="alert alert-info">
                    <strong>OTP has been sent to the registered Owner email.</strong><br>
                    The completed Inspector checklist will be submitted to Owner review only after the correct OTP is verified.
                </div>
                <div class="text-center mb-3">
                    <div class="small text-muted">SAF</div>
                    <div class="fw-bold fs-5">#<?= (int)$otpSafForModal ?></div>
                </div>
                <form method="POST" action="submit-complete-saf.php">
                    <input type="hidden" name="csrf_token" value="<?= h($viewCsrfToken) ?>">
                    <input type="hidden" name="saf_id" value="<?= (int)$otpSafForModal ?>">
                    <input type="hidden" name="job_id" value="<?= (int)$otpJobForModal ?>">
                    <input type="hidden" name="assignment_id" value="<?= (int)$otpAssignmentForModal ?>">
                    <input type="hidden" name="selected_asset_id" value="<?= (int)$otpAssetForModal ?>">
                    <label class="form-label fw-bold">Enter 6-digit Owner OTP</label>
                    <input type="text" name="submission_otp_code" class="form-control form-control-lg text-center" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" placeholder="000000" required style="letter-spacing:8px;font-weight:800;font-size:24px;">
                    <button type="submit" name="verify_submission_otp" value="1" class="btn btn-success w-100 mt-3 py-2">
                        <i class="bi bi-check-circle me-1"></i> Verify OTP & Submit Checklist
                    </button>
                </form>
                <form method="POST" action="submit-complete-saf.php" class="mt-2">
                    <input type="hidden" name="csrf_token" value="<?= h($viewCsrfToken) ?>">
                    <input type="hidden" name="saf_id" value="<?= (int)$otpSafForModal ?>">
                    <input type="hidden" name="job_id" value="<?= (int)$otpJobForModal ?>">
                    <input type="hidden" name="assignment_id" value="<?= (int)$otpAssignmentForModal ?>">
                    <input type="hidden" name="selected_asset_id" value="<?= (int)$otpAssetForModal ?>">
                    <button type="submit" name="request_submission_otp" value="1" class="btn btn-outline-primary w-100">
                        <i class="bi bi-arrow-clockwise me-1"></i> Resend OTP to Owner
                    </button>
                </form>
                <div class="small text-muted text-center mt-3">OTP validity: 10 minutes. Maximum incorrect attempts: 5.</div>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function(){
    var modalEl=document.getElementById('ownerSubmissionOtpModal');
    if(modalEl && window.bootstrap){
        new bootstrap.Modal(modalEl).show();
    }
});
</script>
<?php endif; ?>

<!-- =========================================================
     ASSET SELECTION — PRESERVE SCROLL POSITION
     ---------------------------------------------------------
     Selecting another asset reloads this page with
     ?saf_id=...&asset_id=....

     The selected asset must NOT send the Inspector back
     to the top of the page. Save the current scroll position
     before navigation and restore it after the new page loads.
========================================================= -->
<script>
(function () {
    'use strict';

    var storageKey = 'nleta_inspections_asset_scroll_' + window.location.pathname;
    var restoreUntil = 1500;

    function getSafIdFromUrl() {
        try {
            return new URLSearchParams(window.location.search).get('saf_id') || '0';
        } catch (e) {
            return '0';
        }
    }

    function saveAssetSelectionScroll() {
        try {
            var safId = getSafIdFromUrl();

            sessionStorage.setItem(
                storageKey,
                JSON.stringify({
                    saf_id: safId,
                    scroll_y: Math.max(0, window.scrollY || window.pageYOffset || 0),
                    saved_at: Date.now()
                })
            );
        } catch (e) {
            // Scroll preservation is enhancement only.
        }
    }

    function restoreAssetSelectionScroll() {
        try {
            var raw = sessionStorage.getItem(storageKey);
            if (!raw) return;

            var saved = JSON.parse(raw);
            var currentSafId = getSafIdFromUrl();

            if (!saved || String(saved.saf_id) !== String(currentSafId)) {
                return;
            }

            var y = parseInt(saved.scroll_y, 10);
            if (!Number.isFinite(y) || y < 0) return;

            /*
             * Restore after the page has rendered. A second delayed
             * restore handles Bootstrap/fonts/layout settling.
             */
            var started = Date.now();
            function keepPosition() {
                window.scrollTo(0, y);
                if (Date.now() - started < restoreUntil) {
                    requestAnimationFrame(keepPosition);
                } else {
                    sessionStorage.removeItem(storageKey);
                }
            }
            requestAnimationFrame(keepPosition);
        } catch (e) {
            // Ignore storage/browser errors.
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        /*
         * Only asset-selection links are intercepted.
         * Checklist buttons, OTP forms, Start Inspection, etc.
         * continue to behave exactly as before.
         */
        document.querySelectorAll('a.asset-select-card').forEach(function (link) {
            link.addEventListener('click', function () {
                saveAssetSelectionScroll();
            }, { passive: true });
        });

        restoreAssetSelectionScroll();
    });

    window.addEventListener('pageshow', function () {
        restoreAssetSelectionScroll();
    });
})();
</script>

<?php

include('../includes/footer.php');

?>