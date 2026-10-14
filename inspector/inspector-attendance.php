<?php
/* =========================================================
   NLETA INSPECTOR — SITE ATTENDANCE / OWNER OTP
========================================================= */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once '../config/db.php';
require_once '../config/auth.php';
require_once '../config/security.php';

require_role('inspector');

date_default_timezone_set('Asia/Kolkata');

$inspectorId  = (int)($_SESSION['uid'] ?? $_SESSION['user_id'] ?? 0);
$assignmentId = (int)($_GET['assignment_id'] ?? $_POST['assignment_id'] ?? 0);
$jobId        = (int)($_GET['job_id'] ?? $_POST['job_id'] ?? 0);

if ($inspectorId <= 0) {
    header('Location: ../login.php');
    exit;
}

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/* =========================================================
   STANDARD PROJECT CSRF TOKEN
========================================================= */
if (function_exists('generateCSRFToken')) {
    $csrfToken = generateCSRFToken();
} else {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    $csrfToken = $_SESSION['csrf_token'];
}

/* =========================================================
   ATTENDANCE TABLE
========================================================= */
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
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_inspector_attendance_assignment (assignment_id),
    KEY idx_inspector_attendance_job (job_id),
    KEY idx_inspector_attendance_inspector (inspector_id),
    KEY idx_inspector_attendance_owner (owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
";

if (!$conn->query($attendanceTableSql)) {
    die('Unable to prepare attendance verification: ' . h($conn->error));
}

/* =========================================================
   LOAD ASSIGNMENT
========================================================= */
$assignment = null;

$st = $conn->prepare("
    SELECT
        ia.id AS assignment_id,
        ia.job_id,
        ia.inspector_id,
        ia.asset_id AS assigned_asset_id,
        ia.owner_id AS assigned_owner_id,
        ia.status AS assignment_status,

        mj.asset_id AS job_asset_id,
        mj.owner_id AS job_owner_id,
        mj.job_type,
        mj.status AS job_status,

        a.display_asset_id,
        a.asset_name,
        a.location,
        a.asset_type,

        u.name AS owner_name,
        u.email AS owner_email,

        s.saf_no,
        s.psga_no,
        s.service_code,
        s.service_name

    FROM inspector_assignments ia

    INNER JOIN maintenance_jobs mj
        ON mj.id = ia.job_id

    LEFT JOIN assets a
        ON a.id = mj.asset_id

    LEFT JOIN users u
        ON u.id = mj.owner_id

    LEFT JOIN saf_contracts s
        ON s.id = mj.saf_id

    WHERE
        ia.id = ?
        AND ia.job_id = ?
        AND ia.inspector_id = ?
        AND mj.job_type = 'saf_inspection'

    LIMIT 1
");

if (!$st) {
    die('Unable to prepare attendance request: ' . h($conn->error));
}

$st->bind_param('iii', $assignmentId, $jobId, $inspectorId);
$st->execute();
$assignment = $st->get_result()->fetch_assoc();
$st->close();

if (!$assignment) {
    header('Location: inspections.php');
    exit;
}

$ownerId = (int)($assignment['job_owner_id'] ?: $assignment['assigned_owner_id']);
$assetId = (int)($assignment['job_asset_id'] ?: $assignment['assigned_asset_id']);

$success = '';
$error = '';

/* =========================================================
   LOAD CURRENT ATTENDANCE
========================================================= */
$attendance = null;

$get = $conn->prepare("
    SELECT *
    FROM inspector_attendance
    WHERE assignment_id = ?
      AND job_id = ?
      AND inspector_id = ?
      AND owner_id = ?
    LIMIT 1
");

if ($get) {
    $get->bind_param('iiii', $assignmentId, $jobId, $inspectorId, $ownerId);
    $get->execute();
    $attendance = $get->get_result()->fetch_assoc();
    $get->close();
}

/* =========================================================
   SEND OTP
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_attendance_otp'])) {

    $postedCsrf = $_POST['csrf_token'] ?? '';

    if (function_exists('validateCSRFToken')) {
        $csrfValid = validateCSRFToken($postedCsrf);
    } else {
        $csrfValid = !empty($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $postedCsrf);
    }

    if (!$csrfValid) {

        $error = 'Invalid security token. Please refresh and try again.';

    } elseif ($ownerId <= 0) {

        $error = 'Owner information is not available.';

    } elseif (!filter_var((string)$assignment['owner_email'], FILTER_VALIDATE_EMAIL)) {

        $error = 'The Owner does not have a valid email address.';

    } elseif (!in_array(
        strtolower(trim((string)$assignment['assignment_status'])),
        ['assigned', 'in_progress'],
        true
    )) {

        $error = 'This inspection assignment is not available for attendance verification.';

    } else {

        /* Already verified */
        $checkVerified = $conn->prepare("
            SELECT verified_at, otp_sent_at
            FROM inspector_attendance
            WHERE assignment_id = ?
              AND job_id = ?
              AND inspector_id = ?
              AND owner_id = ?
            LIMIT 1
        ");

        $verifiedAt = null;
        $lastSent = null;

        if ($checkVerified) {
            $checkVerified->bind_param(
                'iiii',
                $assignmentId,
                $jobId,
                $inspectorId,
                $ownerId
            );
            $checkVerified->execute();
            $vrow = $checkVerified->get_result()->fetch_assoc();

            $verifiedAt = $vrow['verified_at'] ?? null;
            $lastSent   = $vrow['otp_sent_at'] ?? null;

            $checkVerified->close();
        }

        if (!empty($verifiedAt)) {

            $success = 'Owner attendance was already verified. Start Inspection is unlocked.';

        } else {

            /* 60-second resend protection */
            if ($lastSent) {

                $lastTs = strtotime($lastSent);

                if ($lastTs !== false && (time() - $lastTs) < 60) {

                    $remaining = 60 - (time() - $lastTs);

                    $error =
                        'Please wait ' . $remaining .
                        ' seconds before requesting another OTP.';
                }
            }

            if ($error === '') {

                $otp = (string)random_int(100000, 999999);

                /*
                 * Use the existing NLETA OTP mailer.
                 * The mailer now supports inspector_attendance.
                 */
                /*
                 * Use the project's GENERAL NLETA mailer.
                 *
                 * The supplied nleta-mailer.php defines:
                 *   nletaCreateMailer()
                 *
                 * OTP emails are kept separate in config/otp-mailer.php,
                 * but Inspector Attendance uses the same working SMTP
                 * account as the general NLETA notifications.
                 */
                $mailerFile = __DIR__ . '/../config/nleta-mailer.php';

                if (!file_exists($mailerFile)) {

                    $error =
                        'NLETA general mailer configuration file was not found.';

                } else {

                    require_once $mailerFile;

                    if (!function_exists('nletaCreateMailer')) {

                        $error =
                            'NLETA general mailer function is not available.';

                    } else {

                        $sent = false;

                        try {

                            $mail = nletaCreateMailer();

                            $mail->addAddress(
                                (string)$assignment['owner_email'],
                                (string)($assignment['owner_name'] ?: 'Owner')
                            );

                            $ownerName = h(
                                $assignment['owner_name'] ?: 'Owner'
                            );

                            $assetDisplay = h(
                                $assignment['display_asset_id']
                                ?: ($assignment['asset_name'] ?: 'Assigned Asset')
                            );

                            $assetName = h(
                                $assignment['asset_name']
                                ?: 'Assigned Asset'
                            );

                            $location = h(
                                $assignment['location']
                                ?: 'Assigned location'
                            );

                            $safNo = h(
                                $assignment['saf_no'] ?: '—'
                            );

                            $psgaNo = h(
                                $assignment['psga_no'] ?: '—'
                            );

                            $safeOtp = h($otp);

                            $mail->Subject =
                                'NLETA - Inspector Site Attendance OTP';

                            $mail->isHTML(true);

                            $mail->Body = '
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>NLETA Inspector Attendance OTP</title>
</head>

<body style="margin:0;padding:0;background:#f4f6f9;font-family:Arial,Helvetica,sans-serif;">

<table width="100%" cellpadding="0" cellspacing="0" border="0"
style="background:#f4f6f9;padding:30px 10px;">
<tr>
<td align="center">

<table width="600" cellpadding="0" cellspacing="0" border="0"
style="max-width:600px;width:100%;background:#ffffff;border:1px solid #e1e5ea;border-radius:10px;overflow:hidden;">

<tr>
<td align="center"
style="background:#1f2933;padding:25px 20px;color:#ffffff;">

<div style="font-size:24px;font-weight:bold;letter-spacing:1px;">NLETA</div>

<div style="margin-top:8px;font-size:14px;line-height:20px;">
NATIONAL LIFT ESCALATOR<br>
TESTING AGENCY
</div>

<div style="margin-top:5px;font-size:12px;color:#d1d5db;">
Maintenance Management System
</div>

</td>
</tr>

<tr>
<td style="padding:35px 30px;color:#222222;">

<h2 style="margin:0 0 18px;color:#1f2933;">
Inspector Site Attendance Verification
</h2>

<p style="font-size:15px;line-height:24px;">
Dear <strong>'.$ownerName.'</strong>,
</p>

<p style="font-size:15px;line-height:24px;">
An NLETA Inspector has reached the assigned location and is requesting
confirmation of site attendance.
</p>

<table width="100%" cellpadding="0" cellspacing="0" border="0"
style="border-collapse:collapse;margin:20px 0;">

<tr>
<td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">
Asset
</td>
<td style="padding:10px;border:1px solid #e5e7eb;">
'.$assetDisplay.' — '.$assetName.'
</td>
</tr>

<tr>
<td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">
Location
</td>
<td style="padding:10px;border:1px solid #e5e7eb;">
'.$location.'
</td>
</tr>

<tr>
<td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">
SAF No.
</td>
<td style="padding:10px;border:1px solid #e5e7eb;">
'.$safNo.'
</td>
</tr>

<tr>
<td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">
PSGA No.
</td>
<td style="padding:10px;border:1px solid #e5e7eb;">
'.$psgaNo.'
</td>
</tr>

</table>

<div style="text-align:center;margin:28px 0;">

<div style="font-size:13px;color:#6c757d;">
Attendance Verification OTP
</div>

<div style="display:inline-block;margin-top:10px;padding:15px 28px;
border:2px dashed #0d6efd;border-radius:8px;font-size:30px;
letter-spacing:7px;font-weight:bold;color:#0d6efd;">
'.$safeOtp.'
</div>

</div>

<p style="font-size:13px;line-height:21px;color:#555;">
This OTP is valid for <strong>10 minutes</strong>.
Please provide it only to the assigned NLETA Inspector if the Inspector
is physically present at the assigned site.
</p>

<p style="font-size:13px;line-height:21px;color:#dc3545;">
Do not share this OTP with anyone else.
</p>

</td>
</tr>

<tr>
<td align="center"
style="background:#f4f6f9;padding:18px;color:#777;font-size:12px;line-height:18px;">

This is an automated email from the NLETA Maintenance Management System.

<br>

© '.date('Y').' NLETA. All Rights Reserved.

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
                                . ($assignment['owner_name'] ?: 'Owner')
                                . ",\n\n"
                                . "An NLETA Inspector has reached the assigned site.\n"
                                . "Your attendance verification OTP is: "
                                . $otp
                                . "\n\n"
                                . "Asset: "
                                . ($assignment['display_asset_id']
                                    ?: ($assignment['asset_name'] ?: 'Assigned Asset'))
                                . "\n"
                                . "Location: "
                                . ($assignment['location'] ?: 'Assigned location')
                                . "\n"
                                . "SAF No.: "
                                . ($assignment['saf_no'] ?: '—')
                                . "\n"
                                . "PSGA No.: "
                                . ($assignment['psga_no'] ?: '—')
                                . "\n\n"
                                . "Valid for 10 minutes. "
                                . "Provide it only to the assigned NLETA Inspector.";

                            $mail->send();

                            $sent = true;

                        } catch (\Throwable $mailException) {

                            error_log(
                                'NLETA Inspector Attendance Mail Error: '
                                . $mailException->getMessage()
                            );

                            $error =
                                'Attendance OTP could not be sent. '
                                . 'Mailer error: '
                                . $mailException->getMessage();

                            $sent = false;
                        }

                        if (!$sent) {

                            $error =
                                'Attendance OTP could not be sent. ' .
                                'Please check the NLETA email configuration and try again.';

                        } else {

                            $otpHash = password_hash($otp, PASSWORD_DEFAULT);

                            if ($otpHash === false) {

                                $error = 'Unable to secure attendance OTP.';

                            } else {

                                $expiresAt = date(
                                    'Y-m-d H:i:s',
                                    time() + 600
                                );

                                $sentAt = date('Y-m-d H:i:s');

                                $upsert = $conn->prepare("
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
                                    (?, ?, ?, ?, ?, ?, 0, ?, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)

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

                                if (!$upsert) {

                                    $error =
                                        'Attendance OTP was emailed, but could not be saved: '
                                        . $conn->error;

                                } else {

                                    $upsert->bind_param(
                                        'iiiisss',
                                        $assignmentId,
                                        $jobId,
                                        $inspectorId,
                                        $ownerId,
                                        $otpHash,
                                        $expiresAt,
                                        $sentAt
                                    );

                                    if (!$upsert->execute()) {

                                        $error =
                                            'Attendance OTP was emailed, but could not be saved: '
                                            . $upsert->error;

                                    } else {

                                        $success =
                                            'Attendance OTP has been sent to the registered Owner email. '
                                            . 'Ask the Owner to provide the OTP.';
                                    }

                                    $upsert->close();
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

/* =========================================================
   VERIFY OTP
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_attendance_otp'])) {

    $postedCsrf = $_POST['csrf_token'] ?? '';

    if (function_exists('validateCSRFToken')) {
        $csrfValid = validateCSRFToken($postedCsrf);
    } else {
        $csrfValid = !empty($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $postedCsrf);
    }

    $enteredOtp = preg_replace(
        '/[^0-9]/',
        '',
        (string)($_POST['otp'] ?? '')
    );

    if (!$csrfValid) {

        $error = 'Invalid security token. Please refresh and try again.';

    } elseif (!preg_match('/^[0-9]{6}$/', $enteredOtp)) {

        $error = 'Please enter the 6-digit OTP.';

    } else {

        $rowStmt = $conn->prepare("
            SELECT *
            FROM inspector_attendance
            WHERE assignment_id = ?
              AND job_id = ?
              AND inspector_id = ?
              AND owner_id = ?
            LIMIT 1
        ");

        $row = null;

        if ($rowStmt) {

            $rowStmt->bind_param(
                'iiii',
                $assignmentId,
                $jobId,
                $inspectorId,
                $ownerId
            );

            $rowStmt->execute();
            $row = $rowStmt->get_result()->fetch_assoc();
            $rowStmt->close();
        }

        if (!$row || empty($row['otp_hash'])) {

            $error =
                'No active attendance OTP was found. Please request a new OTP.';

        } elseif (!empty($row['verified_at'])) {

            $success =
                'Attendance is already verified. Start Inspection is unlocked.';

        } elseif (
            !empty($row['otp_expires_at'])
            && strtotime($row['otp_expires_at']) < time()
        ) {

            $error =
                'This attendance OTP has expired. Please request a new OTP.';

        } elseif ((int)$row['otp_attempts'] >= 5) {

            $error =
                'Too many incorrect attempts. Please request a new OTP.';

        } elseif (!password_verify(
            $enteredOtp,
            (string)$row['otp_hash']
        )) {

            $attemptStmt = $conn->prepare("
                UPDATE inspector_attendance
                SET
                    otp_attempts = otp_attempts + 1,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
                LIMIT 1
            ");

            if ($attemptStmt) {

                $rid = (int)$row['id'];

                $attemptStmt->bind_param('i', $rid);
                $attemptStmt->execute();
                $attemptStmt->close();
            }

            $error =
                'Invalid OTP. Please check with the Owner and try again.';

        } else {

            $verifyStmt = $conn->prepare("
                UPDATE inspector_attendance
                SET
                    verified_at = CURRENT_TIMESTAMP,
                    otp_hash = NULL,
                    otp_expires_at = NULL,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
                  AND assignment_id = ?
                  AND job_id = ?
                  AND inspector_id = ?
                  AND owner_id = ?
                  AND verified_at IS NULL
                LIMIT 1
            ");

            if (!$verifyStmt) {

                $error =
                    'Unable to verify attendance: ' .
                    $conn->error;

            } else {

                $attendanceId = (int)$row['id'];

                $verifyStmt->bind_param(
                    'iiiii',
                    $attendanceId,
                    $assignmentId,
                    $jobId,
                    $inspectorId,
                    $ownerId
                );

                if (
                    $verifyStmt->execute()
                    && $verifyStmt->affected_rows > 0
                ) {

                    $success =
                        'Owner attendance verified successfully. ' .
                        'Start Inspection is now unlocked.';

                } else {

                    $error =
                        'Attendance could not be verified. Please try again.';
                }

                $verifyStmt->close();
            }
        }
    }
}

/* =========================================================
   REFRESH CURRENT STATE
========================================================= */
$currentVerifiedAt = null;
$currentOtpSentAt = null;

$refresh = $conn->prepare("
    SELECT verified_at, otp_sent_at
    FROM inspector_attendance
    WHERE assignment_id = ?
      AND job_id = ?
      AND inspector_id = ?
      AND owner_id = ?
    LIMIT 1
");

if ($refresh) {

    $refresh->bind_param(
        'iiii',
        $assignmentId,
        $jobId,
        $inspectorId,
        $ownerId
    );

    $refresh->execute();

    $rr = $refresh->get_result()->fetch_assoc();

    if ($rr) {
        $currentVerifiedAt = $rr['verified_at'] ?? null;
        $currentOtpSentAt  = $rr['otp_sent_at'] ?? null;
    }

    $refresh->close();
}

include('../includes/header.php');
include('../includes/sidebar-inspector.php');
?>

<style>
.attendance-page{padding:24px;min-height:calc(100vh - 70px);background:#f4f6f9}
.attendance-card{max-width:850px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:14px;box-shadow:0 3px 14px rgba(0,0,0,.06);padding:26px}
.attendance-title{font-size:23px;font-weight:700}
.info-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin:22px 0}
.info-box{background:#f8f9fa;border:1px solid #e9ecef;border-radius:10px;padding:13px}
.info-label{font-size:11px;color:#6c757d;text-transform:uppercase;margin-bottom:4px}
.info-value{font-weight:600;word-break:break-word}
.otp-box{background:#f8fbff;border:1px solid #cfe2ff;border-radius:12px;padding:20px;margin-top:20px}
.otp-input{font-size:25px;letter-spacing:8px;text-align:center;font-weight:700;max-width:300px}
.verified-box{background:#d1e7dd;border:1px solid #a3cfbb;color:#0f5132;border-radius:12px;padding:18px}
@media(max-width:650px){.attendance-page{padding:12px}.attendance-card{padding:18px}.info-grid{grid-template-columns:1fr}}
</style>

<div class="content">
<div class="attendance-page">
<div class="attendance-card">

<div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
<div>
<div class="attendance-title"><i class="bi bi-person-check me-2"></i>Site Attendance Verification</div>
<div class="text-muted small mt-1">Owner OTP confirmation is required before the inspection can start.</div>
</div>
<a href="inspections.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>

<?php if ($success !== ''): ?>
<div class="alert alert-success mt-4"><?=h($success)?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
<div class="alert alert-danger mt-4"><?=h($error)?></div>
<?php endif; ?>

<div class="info-grid">
<div class="info-box"><div class="info-label">Asset</div><div class="info-value"><?=h($assignment['display_asset_id'] ?: '—')?> — <?=h($assignment['asset_name'] ?: '—')?></div></div>
<div class="info-box"><div class="info-label">Location</div><div class="info-value"><?=h($assignment['location'] ?: '—')?></div></div>
<div class="info-box"><div class="info-label">Owner</div><div class="info-value"><?=h($assignment['owner_name'] ?: '—')?></div></div>
<div class="info-box"><div class="info-label">Owner Email</div><div class="info-value"><?=h($assignment['owner_email'] ?: '—')?></div></div>
<div class="info-box"><div class="info-label">SAF No.</div><div class="info-value"><?=h($assignment['saf_no'] ?: '—')?></div></div>
<div class="info-box"><div class="info-label">PSGA No.</div><div class="info-value"><?=h($assignment['psga_no'] ?: '—')?></div></div>
</div>

<?php if (!empty($currentVerifiedAt)): ?>

<div class="verified-box">
<div class="fw-bold"><i class="bi bi-check-circle-fill me-2"></i>Owner Attendance Verified</div>
<div class="small mt-1">Verified on <?=h(date('d-m-Y h:i A', strtotime($currentVerifiedAt)))?> IST.</div>
</div>

<div class="mt-4">
<a href="start-inspection.php?assignment_id=<?=$assignmentId?>&job_id=<?=$jobId?>" class="btn btn-primary btn-lg">
<i class="bi bi-play-circle me-1"></i>Start Inspection
</a>
</div>

<?php else: ?>

<div class="otp-box">

<div class="fw-bold fs-5 mb-2">Step 1 — Send OTP to Owner</div>
<p class="text-muted small">The OTP will be sent to the registered Owner email. The Inspector should request the OTP only after reaching the assigned site.</p>

<form method="POST" class="mb-4">
<input type="hidden" name="csrf_token" value="<?=h($csrfToken)?>">
<input type="hidden" name="assignment_id" value="<?=$assignmentId?>">
<input type="hidden" name="job_id" value="<?=$jobId?>">
<button type="submit" name="send_attendance_otp" class="btn btn-outline-primary">
<i class="bi bi-envelope-check me-1"></i>Send Attendance OTP to Owner
</button>
</form>

<div class="border-top pt-4">
<div class="fw-bold fs-5 mb-2">Step 2 — Verify Owner OTP</div>
<p class="text-muted small">Enter the 6-digit OTP provided by the Owner.</p>

<form method="POST">
<input type="hidden" name="csrf_token" value="<?=h($csrfToken)?>">
<input type="hidden" name="assignment_id" value="<?=$assignmentId?>">
<input type="hidden" name="job_id" value="<?=$jobId?>">
<div class="mb-3">
<input type="text" name="otp" class="form-control otp-input" maxlength="6" inputmode="numeric" autocomplete="one-time-code" placeholder="000000" required>
</div>
<button type="submit" name="verify_attendance_otp" class="btn btn-primary">
<i class="bi bi-shield-check me-1"></i>Verify OTP
</button>
</form>
</div>

</div>

<?php endif; ?>

<div class="mt-4 p-3 bg-light border rounded">
<div class="fw-bold small"><i class="bi bi-lock-fill me-1"></i>Inspection Lock</div>
<div class="small text-muted mt-1">
Start Inspection remains locked until this assignment's Owner OTP is successfully verified.
Directly opening the Start Inspection URL must also be blocked until attendance is verified.
</div>
</div>

</div>
</div>
</div>

<?php include('../includes/footer.php'); ?>
