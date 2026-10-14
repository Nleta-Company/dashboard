<?php
/* =========================================================
   NLETA INSPECTOR — SUBMIT ASSIGNED CHECKLISTS
   ---------------------------------------------------------
   MULTI-INSPECTOR / MULTI-ASSET WORKFLOW

   Example:
       Asset A -> Inspector 1
       Asset B -> Inspector 2
       Asset C -> Inspector 3

   Each Inspector can submit ONLY their own assigned asset(s).
   Other Inspectors' assignments are never changed.

   SAF moves to pending_owner ONLY when every SAF inspection
   job has a completed checklist AND its assignment is submitted.
========================================================= */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

if (file_exists(__DIR__ . '/../config/security.php')) {
    require_once __DIR__ . '/../config/security.php';
}

require_role('inspector');
date_default_timezone_set('Asia/Kolkata');

function failBack(string $message, int $safId = 0, bool $openOtp = false): void
{
    $_SESSION['error'] = $message;
    $url = 'inspections.php';
    $params = [];
    if ($safId > 0) $params['saf_id'] = $safId;
    if ($openOtp) $params['submission_otp'] = '1';
    if ($params) $url .= '?' . http_build_query($params);
    header('Location: ' . $url);
    exit;
}

function successBack(string $message): void
{
    $_SESSION['success'] = $message;
    header('Location: inspections.php');
    exit;
}

function validChecklist(array $checklist): bool
{
    $finalStatus = strtoupper(trim((string)($checklist['final_status'] ?? '')));
    return in_array($finalStatus, ['PASS', 'CONDITIONAL PASS', 'FAIL'], true);
}

$inspectorId = (int)($_SESSION['uid'] ?? $_SESSION['user_id'] ?? 0);

if ($inspectorId <= 0) {
    header('Location: dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: inspections.php');
    exit;
}

/* =========================================================
   CSRF
========================================================= */

if (function_exists('validateCSRFToken')) {
    if (!validateCSRFToken((string)($_POST['csrf_token'] ?? ''))) {
        failBack('Invalid security token. Please refresh and try again.');
    }
} elseif (!empty($_SESSION['csrf_token'])) {
    $token = (string)($_POST['csrf_token'] ?? '');
    if ($token === '' || !hash_equals((string)$_SESSION['csrf_token'], $token)) {
        failBack('Invalid security token. Please refresh and try again.');
    }
}

$safId = (int)($_POST['saf_id'] ?? 0);
$contextJobId = (int)($_POST['job_id'] ?? 0);
$contextAssignmentId = (int)($_POST['assignment_id'] ?? 0);
$contextAssetId = (int)($_POST['selected_asset_id'] ?? 0);

if ($safId <= 0) {
    failBack('Invalid SAF selected.');
}

/* =========================================================
   OWNER OTP FOR FINAL CHECKLIST SUBMISSION
   ---------------------------------------------------------
   The Owner receives an OTP when the Inspector is ready to
   submit completed checklist(s). The checklist is NOT moved
   to Owner review until this OTP is verified.

   One OTP is maintained per SAF + Inspector because one SAF
   can contain multiple assets assigned to different Inspectors.
========================================================= */

$conn->query("CREATE TABLE IF NOT EXISTS inspector_submission_otp (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    saf_id INT NOT NULL,
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
    UNIQUE KEY uq_submission_otp_saf_inspector (saf_id, inspector_id),
    KEY idx_submission_otp_owner (owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ---------------------------------------------------------
   STEP A — REQUEST OWNER OTP
--------------------------------------------------------- */
if (isset($_POST['request_submission_otp'])) {
    try {
        $check = $conn->prepare("
            SELECT sc.owner_id, sc.saf_no, sc.status, u.name AS owner_name, u.email AS owner_email
            FROM saf_contracts sc
            LEFT JOIN users u ON u.id = sc.owner_id
            WHERE sc.id = ? LIMIT 1
        ");
        if (!$check) throw new RuntimeException('Unable to verify SAF Owner: ' . $conn->error);
        $check->bind_param('i', $safId);
        $check->execute();
        $safOtp = $check->get_result()->fetch_assoc();
        $check->close();

        if (!$safOtp) throw new RuntimeException('SAF was not found.');
        if (strcasecmp(trim((string)$safOtp['status']), 'Accepted') !== 0) throw new RuntimeException('This SAF has not been accepted by the Owner.');
        if (!filter_var((string)$safOtp['owner_email'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException('The registered Owner email address is invalid or unavailable.');

        /* Verify that this Inspector really has completed work ready to submit. */
        $readyStmt = $conn->prepare("
            SELECT mj.id, mj.asset_id, mj.checklist, mj.status AS job_status, ia.status AS assignment_status
            FROM maintenance_jobs mj
            INNER JOIN inspector_assignments ia ON ia.job_id = mj.id AND ia.inspector_id = ?
            WHERE mj.saf_id = ? AND mj.job_type = 'saf_inspection'
            ORDER BY mj.id DESC
        ");
        if (!$readyStmt) throw new RuntimeException('Unable to verify your assigned checklist(s): ' . $conn->error);
        $readyStmt->bind_param('ii', $inspectorId, $safId);
        $readyStmt->execute();
        $readyResult = $readyStmt->get_result();
        $readyCount = 0;
        while ($rr = $readyResult->fetch_assoc()) {
            $jobStatus = strtolower(trim((string)($rr['job_status'] ?? '')));
            $assignmentStatus = strtolower(trim((string)($rr['assignment_status'] ?? '')));
            if ($assignmentStatus === 'submitted' || in_array($jobStatus, ['submitted','pending_owner'], true)) continue;
            $decoded = json_decode((string)($rr['checklist'] ?? ''), true);
            $final = is_array($decoded) ? strtoupper(trim((string)($decoded['final_status'] ?? ''))) : '';
            if (in_array($final, ['PASS','CONDITIONAL PASS','FAIL'], true)) $readyCount++;
        }
        $readyStmt->close();
        if ($readyCount <= 0) throw new RuntimeException('No completed checklist is ready for submission.');

        /* 60-second resend protection. */
        $existing = null;
        $readOtp = $conn->prepare("SELECT otp_sent_at, verified_at FROM inspector_submission_otp WHERE saf_id = ? AND inspector_id = ? LIMIT 1");
        if ($readOtp) {
            $readOtp->bind_param('ii', $safId, $inspectorId);
            $readOtp->execute();
            $existing = $readOtp->get_result()->fetch_assoc();
            $readOtp->close();
        }
        if ($existing && !empty($existing['otp_sent_at']) && (time() - strtotime((string)$existing['otp_sent_at'])) < 60) {
            $remaining = 60 - (time() - strtotime((string)$existing['otp_sent_at']));
            failBack('Please wait ' . max(1, $remaining) . ' seconds before requesting another submission OTP.', $safId, true);
        }

        $otp = (string)random_int(100000, 999999);
        $hash = password_hash($otp, PASSWORD_DEFAULT);
        if ($hash === false) throw new RuntimeException('Unable to secure submission OTP.');

        $mailerFile = __DIR__ . '/../config/nleta-mailer.php';
        if (!file_exists($mailerFile)) throw new RuntimeException('NLETA mailer configuration was not found.');
        require_once $mailerFile;
        if (!function_exists('nletaCreateMailer')) throw new RuntimeException('NLETA mailer function is not available.');
        $mail = nletaCreateMailer();
        $mail->addAddress((string)$safOtp['owner_email'], (string)($safOtp['owner_name'] ?: 'Owner'));
        $mail->isHTML(true);
        $mail->Subject = 'NLETA - Inspector Checklist Submission OTP';
        $safeOwner = htmlspecialchars((string)($safOtp['owner_name'] ?: 'Owner'), ENT_QUOTES, 'UTF-8');
        $safeSaf = htmlspecialchars((string)($safOtp['saf_no'] ?: '—'), ENT_QUOTES, 'UTF-8');
        $safeOtp = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');
        $mail->Body = '<!DOCTYPE html><html><body style="margin:0;background:#f4f6f9;font-family:Arial,sans-serif;padding:30px"><div style="max-width:600px;margin:auto;background:#fff;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden"><div style="background:#1f2933;color:#fff;text-align:center;padding:22px"><div style="font-size:24px;font-weight:700">NLETA</div><div style="font-size:12px;margin-top:5px">NATIONAL LIFT ESCALATOR TESTING AGENCY</div></div><div style="padding:28px"><h2 style="color:#1f2933">Inspector Checklist Submission Verification</h2><p>Dear <strong>'.$safeOwner.'</strong>,</p><p>An assigned NLETA Inspector has completed the checklist and is requesting Owner authorization to submit the checklist for Owner review.</p><p><strong>SAF No.:</strong> '.$safeSaf.'</p><div style="text-align:center;margin:28px 0"><div style="font-size:13px;color:#666">Submission OTP</div><div style="display:inline-block;margin-top:10px;padding:15px 28px;border:2px dashed #0d6efd;border-radius:8px;font-size:30px;letter-spacing:7px;font-weight:700;color:#0d6efd">'.$safeOtp.'</div></div><p style="font-size:13px;color:#555">This OTP is valid for <strong>10 minutes</strong>.</p><p style="font-size:13px;color:#b42318"><strong>Do not share this OTP unless you authorize submission of the Inspector checklist.</strong></p></div><div style="background:#f4f6f9;padding:16px;text-align:center;color:#777;font-size:12px">Automated message from NLETA Maintenance Management System.</div></div></body></html>';
        $mail->AltBody = "NLETA Inspector Checklist Submission Verification\n\nSAF No.: {$safOtp['saf_no']}\nSubmission OTP: {$otp}\n\nValid for 10 minutes.";
        $mail->send();

        $expires = date('Y-m-d H:i:s', time() + 600);
        $sentAt = date('Y-m-d H:i:s');
        $saveOtp = $conn->prepare("
            INSERT INTO inspector_submission_otp (saf_id, inspector_id, owner_id, otp_hash, otp_expires_at, otp_attempts, otp_sent_at, verified_at)
            VALUES (?, ?, ?, ?, ?, 0, ?, NULL)
            ON DUPLICATE KEY UPDATE owner_id=VALUES(owner_id), otp_hash=VALUES(otp_hash), otp_expires_at=VALUES(otp_expires_at), otp_attempts=0, otp_sent_at=VALUES(otp_sent_at), verified_at=NULL, updated_at=CURRENT_TIMESTAMP
        ");
        if (!$saveOtp) throw new RuntimeException('OTP email was sent, but the OTP could not be saved: ' . $conn->error);
        $ownerOtpId = (int)$safOtp['owner_id'];
        $saveOtp->bind_param('iiisss', $safId, $inspectorId, $ownerOtpId, $hash, $expires, $sentAt);
        if (!$saveOtp->execute()) throw new RuntimeException('OTP email was sent, but the OTP could not be saved: ' . $saveOtp->error);
        $saveOtp->close();

        $_SESSION['success'] = 'Submission OTP has been sent to the registered Owner email. Enter the OTP to submit your completed checklist(s).';
        header('Location: inspections.php?saf_id=' . $safId . '&submission_otp=1');
        exit;
    } catch (Throwable $e) {
        error_log('NLETA Checklist Submission OTP Error: ' . $e->getMessage());
        failBack($e->getMessage(), $safId, true);
    }
}

/* ---------------------------------------------------------
   STEP B — VERIFY OWNER OTP
--------------------------------------------------------- */
if (isset($_POST['verify_submission_otp'])) {
    $enteredOtp = preg_replace('/[^0-9]/', '', (string)($_POST['submission_otp_code'] ?? ''));
    if (!preg_match('/^[0-9]{6}$/', $enteredOtp)) failBack('Please enter the 6-digit Owner OTP.', $safId, true);

    $otpStmt = $conn->prepare("SELECT id, otp_hash, otp_expires_at, otp_attempts, verified_at FROM inspector_submission_otp WHERE saf_id = ? AND inspector_id = ? LIMIT 1");
    if (!$otpStmt) failBack('Unable to read submission OTP: ' . $conn->error, $safId, true);
    $otpStmt->bind_param('ii', $safId, $inspectorId);
    $otpStmt->execute();
    $otpRow = $otpStmt->get_result()->fetch_assoc();
    $otpStmt->close();

    if (!$otpRow || empty($otpRow['otp_hash'])) failBack('No active submission OTP was found. Please request a new OTP.', $safId, true);
    if (!empty($otpRow['verified_at'])) {
        /* already verified; continue into the normal submission transaction */
    } elseif (!empty($otpRow['otp_expires_at']) && strtotime((string)$otpRow['otp_expires_at']) < time()) {
        failBack('This submission OTP has expired. Please request a new OTP.', $safId, true);
    } elseif ((int)$otpRow['otp_attempts'] >= 5) {
        failBack('Too many incorrect attempts. Please request a new OTP.', $safId, true);
    } elseif (!password_verify($enteredOtp, (string)$otpRow['otp_hash'])) {
        $inc = $conn->prepare("UPDATE inspector_submission_otp SET otp_attempts = otp_attempts + 1, updated_at = CURRENT_TIMESTAMP WHERE id = ? LIMIT 1");
        if ($inc) { $oid=(int)$otpRow['id']; $inc->bind_param('i',$oid); $inc->execute(); $inc->close(); }
        failBack('Invalid Owner OTP. Please check with the Owner and try again.', $safId, true);
    } else {
        $verify = $conn->prepare("UPDATE inspector_submission_otp SET verified_at=CURRENT_TIMESTAMP, otp_hash=NULL, otp_expires_at=NULL, updated_at=CURRENT_TIMESTAMP WHERE id=? AND saf_id=? AND inspector_id=? AND verified_at IS NULL LIMIT 1");
        if (!$verify) failBack('Unable to verify submission OTP: ' . $conn->error, $safId, true);
        $oid=(int)$otpRow['id']; $verify->bind_param('iii',$oid,$safId,$inspectorId);
        if (!$verify->execute() || $verify->affected_rows <= 0) failBack('Submission OTP could not be verified. Please try again.', $safId, true);
        $verify->close();
    }
}

$transactionStarted = false;

try {
    $conn->begin_transaction();
    $transactionStarted = true;

    /* =====================================================
       1. VERIFY SAF
    ====================================================== */

    $stmt = $conn->prepare("
        SELECT
            id,
            request_id,
            owner_id,
            saf_no,
            status
        FROM saf_contracts
        WHERE id = ?
        LIMIT 1
        FOR UPDATE
    ");

    if (!$stmt) {
        throw new RuntimeException('Unable to prepare SAF verification: ' . $conn->error);
    }

    $stmt->bind_param('i', $safId);
    if (!$stmt->execute()) {
        throw new RuntimeException('Unable to verify SAF: ' . $stmt->error);
    }

    $saf = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$saf) {
        throw new RuntimeException('SAF was not found.');
    }

    if (strcasecmp(trim((string)$saf['status']), 'Accepted') !== 0) {
        throw new RuntimeException('This SAF has not been accepted by the Owner.');
    }

    /* =====================================================
       2. LOAD ONLY CURRENT INSPECTOR'S ACTIVE ASSIGNMENTS
    ====================================================== */

    $stmt = $conn->prepare("
        SELECT
            mj.id AS job_id,
            mj.asset_id,
            mj.checklist,
            mj.status AS job_status,
            ia.id AS assignment_id,
            ia.inspector_id,
            ia.status AS assignment_status
        FROM maintenance_jobs mj
        INNER JOIN inspector_assignments ia
            ON ia.job_id = mj.id
           AND ia.inspector_id = ?
        WHERE mj.saf_id = ?
          AND mj.job_type = 'saf_inspection'
        ORDER BY mj.asset_id ASC, mj.id DESC
        FOR UPDATE
    ");

    if (!$stmt) {
        throw new RuntimeException('Unable to load your Inspector assignments: ' . $conn->error);
    }

    $stmt->bind_param('ii', $inspectorId, $safId);
    if (!$stmt->execute()) {
        throw new RuntimeException('Unable to load your Inspector assignments: ' . $stmt->error);
    }

    $result = $stmt->get_result();
    $myJobs = [];

    while ($row = $result->fetch_assoc()) {
        $assetId = (int)$row['asset_id'];
        if ($assetId <= 0) {
            continue;
        }

        /* newest job wins if duplicate legacy rows exist */
        if (!isset($myJobs[$assetId])) {
            $myJobs[$assetId] = $row;
        }
    }

    $stmt->close();

    if (!$myJobs) {
        throw new RuntimeException('No inspection asset is assigned to your Inspector account for this SAF.');
    }

    /* =====================================================
       3. VALIDATE CONTEXT IF PROVIDED
          Prevent tampering with hidden fields.
    ====================================================== */

    if ($contextJobId > 0 || $contextAssignmentId > 0 || $contextAssetId > 0) {
        $contextValid = false;

        foreach ($myJobs as $row) {
            if (
                $contextJobId > 0 && (int)$row['job_id'] === $contextJobId
                && $contextAssignmentId > 0 && (int)$row['assignment_id'] === $contextAssignmentId
                && $contextAssetId > 0 && (int)$row['asset_id'] === $contextAssetId
            ) {
                $contextValid = true;
                break;
            }
        }

        if (!$contextValid) {
            throw new RuntimeException('The selected inspection asset does not belong to your Inspector assignment.');
        }
    }

    /* =====================================================
       4. FIND COMPLETED ASSETS BELONGING TO THIS INSPECTOR
          Already submitted assets are skipped.
    ====================================================== */

    $assetsToSubmit = [];

    foreach ($myJobs as $assetId => $row) {
        $jobStatus = strtolower(trim((string)($row['job_status'] ?? '')));
        $assignmentStatus = strtolower(trim((string)($row['assignment_status'] ?? '')));

        /* Do not resubmit an already submitted assignment. */
        if (
            $assignmentStatus === 'submitted'
            || in_array($jobStatus, ['submitted', 'pending_owner'], true)
        ) {
            continue;
        }

        $checklist = [];

        if (!empty($row['checklist'])) {
            $decoded = json_decode((string)$row['checklist'], true);
            if (is_array($decoded)) {
                $checklist = $decoded;
            }
        }

        if (!validChecklist($checklist)) {
            continue;
        }

        $assetsToSubmit[$assetId] = $row;
    }

    if (!$assetsToSubmit) {
        throw new RuntimeException(
            'No completed checklist is ready for submission. Complete your assigned asset checklist first.'
        );
    }

    /* =====================================================
       5. SUBMIT ONLY CURRENT INSPECTOR'S COMPLETED ASSETS
    ====================================================== */

    $submittedCount = 0;

    foreach ($assetsToSubmit as $assetId => $row) {
        $jobId = (int)$row['job_id'];
        $assignmentId = (int)$row['assignment_id'];

        /* Job belongs to this exact Inspector assignment. */
        $updateJob = $conn->prepare("
            UPDATE maintenance_jobs
            SET
                status = 'submitted',
                updated_at = NOW()
            WHERE id = ?
              AND saf_id = ?
              AND asset_id = ?
              AND job_type = 'saf_inspection'
        ");

        if (!$updateJob) {
            throw new RuntimeException('Unable to prepare asset submission: ' . $conn->error);
        }

        $updateJob->bind_param('iii', $jobId, $safId, $assetId);

        if (!$updateJob->execute()) {
            throw new RuntimeException('Unable to submit asset checklist: ' . $updateJob->error);
        }

        $updateJob->close();

        $updateAssignment = $conn->prepare("
            UPDATE inspector_assignments
            SET
                status = 'submitted',
                submitted_at = NOW()
            WHERE id = ?
              AND job_id = ?
              AND asset_id = ?
              AND inspector_id = ?
        ");

        if (!$updateAssignment) {
            throw new RuntimeException('Unable to prepare Inspector submission: ' . $conn->error);
        }

        $updateAssignment->bind_param(
            'iiii',
            $assignmentId,
            $jobId,
            $assetId,
            $inspectorId
        );

        if (!$updateAssignment->execute()) {
            throw new RuntimeException('Unable to submit Inspector assignment: ' . $updateAssignment->error);
        }

        $updateAssignment->close();
        $submittedCount++;
    }

    /* =====================================================
       6. CHECK WHOLE SAF
          IMPORTANT: NO inspector_id FILTER HERE.
          Every asset/Inspector assignment is checked.
    ====================================================== */

    $allJobsStmt = $conn->prepare("
        SELECT
            mj.id AS job_id,
            mj.asset_id,
            mj.checklist,
            mj.status AS job_status,
            ia.id AS assignment_id,
            ia.inspector_id,
            ia.status AS assignment_status
        FROM maintenance_jobs mj
        INNER JOIN inspector_assignments ia
            ON ia.job_id = mj.id
        WHERE mj.saf_id = ?
          AND mj.job_type = 'saf_inspection'
        ORDER BY mj.asset_id ASC, mj.id DESC
        FOR UPDATE
    ");

    if (!$allJobsStmt) {
        throw new RuntimeException('Unable to verify complete SAF status: ' . $conn->error);
    }

    $allJobsStmt->bind_param('i', $safId);

    if (!$allJobsStmt->execute()) {
        throw new RuntimeException('Unable to verify complete SAF status: ' . $allJobsStmt->error);
    }

    $allResult = $allJobsStmt->get_result();
    $allJobs = [];

    while ($row = $allResult->fetch_assoc()) {
        $assetId = (int)$row['asset_id'];
        if ($assetId <= 0) continue;

        /* newest row wins for duplicate legacy jobs */
        if (!isset($allJobs[$assetId])) {
            $allJobs[$assetId] = $row;
        }
    }

    $allJobsStmt->close();

    if (!$allJobs) {
        throw new RuntimeException('No SAF inspection jobs exist for this SAF.');
    }

    $notReady = [];

    foreach ($allJobs as $assetId => $row) {
        $checklist = [];

        if (!empty($row['checklist'])) {
            $decoded = json_decode((string)$row['checklist'], true);
            if (is_array($decoded)) {
                $checklist = $decoded;
            }
        }

        $complete = validChecklist($checklist);
        $assignmentStatus = strtolower(trim((string)($row['assignment_status'] ?? '')));
        $jobStatus = strtolower(trim((string)($row['job_status'] ?? '')));

        if (!$complete) {
            $notReady[] = "Asset ID {$assetId}: checklist incomplete.";
            continue;
        }

        if (
            $assignmentStatus !== 'submitted'
            && !in_array($jobStatus, ['pending_owner'], true)
        ) {
            $notReady[] = "Asset ID {$assetId}: assigned Inspector has not submitted it yet.";
        }
    }

    /* =====================================================
       7. ONLY WHEN EVERY ASSET IS READY -> OWNER
    ====================================================== */

    if (!$notReady) {
        $finalUpdate = $conn->prepare("
            UPDATE maintenance_jobs
            SET
                status = 'pending_owner',
                updated_at = NOW()
            WHERE saf_id = ?
              AND job_type = 'saf_inspection'
        ");

        if (!$finalUpdate) {
            throw new RuntimeException('Unable to prepare final SAF submission: ' . $conn->error);
        }

        $finalUpdate->bind_param('i', $safId);

        if (!$finalUpdate->execute()) {
            throw new RuntimeException('Unable to move complete SAF to Owner: ' . $finalUpdate->error);
        }

        $finalUpdate->close();

        $conn->commit();
        $transactionStarted = false;

        $_SESSION['success'] =
            'All asset checklists for SAF ' .
            $saf['saf_no'] .
            ' have been submitted. Complete SAF is now ready for Owner review.';

        header('Location: inspections.php?saf_id=' . $safId);
        exit;
    }

    /* =====================================================
       8. CURRENT INSPECTOR SUBMITTED, BUT OTHER ASSETS REMAIN
    ====================================================== */

    $conn->commit();
    $transactionStarted = false;

    $_SESSION['success'] =
        $submittedCount .
        ' assigned asset checklist(s) submitted successfully. ' .
        'The SAF will be sent to the Owner after all assigned Inspectors submit their assets.';

    header('Location: inspections.php?saf_id=' . $safId);
    exit;

} catch (Throwable $e) {
    if ($transactionStarted) {
        $conn->rollback();
    }

    error_log('NLETA Asset-wise SAF Submission Error: ' . $e->getMessage());
    failBack($e->getMessage());
}
?>
