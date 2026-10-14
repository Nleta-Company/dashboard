<?php
/* =========================================================
   NLETA INSPECTOR — START INSPECTION
   ---------------------------------------------------------
   NEW CONTROL:
   Owner site-attendance OTP must be verified before an
   Inspector can start the inspection.

   Existing workflow preserved:
   - Inspector role
   - inspector_assignments ownership
   - SAF acceptance check
   - assigned -> in_progress
   - redirect to saf-checklist.php
   - Asia/Kolkata
========================================================= */

include('../config/db.php');
include('../config/auth.php');
require_once '../config/security.php';

require_role('inspector');

date_default_timezone_set('Asia/Kolkata');

$inspectorId = (int)(
    $_SESSION['uid']
    ?? $_SESSION['user_id']
    ?? 0
);

$jobId = (int)(
    $_GET['job_id']
    ?? 0
);

if ($inspectorId <= 0) {
    header('Location: dashboard.php');
    exit;
}

if ($jobId <= 0) {
    header('Location: inspections.php');
    exit;
}

/* =========================================================
   ENSURE ATTENDANCE TABLE EXISTS
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
    $_SESSION['error'] =
        'Unable to prepare attendance verification: ' .
        $conn->error;

    header('Location: inspections.php');
    exit;
}

$conn->begin_transaction();

try {

    $stmt = $conn->prepare("
        SELECT
            mj.id,
            mj.saf_id,
            mj.service_id,
            mj.asset_id,
            mj.owner_id,
            mj.status AS job_status,
            mj.job_type,

            ia.id AS assignment_id,
            ia.inspector_id,
            ia.status AS assignment_status,

            s.saf_no,
            s.status AS saf_status,
            s.service_name,
            s.service_code,

            a.asset_name,
            a.display_asset_id,
            a.location,

            u.name AS owner_name,
            u.email AS owner_email

        FROM maintenance_jobs mj

        INNER JOIN inspector_assignments ia
            ON ia.job_id = mj.id

        INNER JOIN saf_contracts s
            ON s.id = mj.saf_id

        LEFT JOIN assets a
            ON a.id = mj.asset_id

        LEFT JOIN users u
            ON u.id = mj.owner_id

        WHERE
            mj.id = ?
            AND ia.inspector_id = ?
            AND mj.job_type = 'saf_inspection'

        LIMIT 1
    ");

    if (!$stmt) {
        throw new Exception(
            'Unable to prepare inspection query: ' .
            $conn->error
        );
    }

    $stmt->bind_param(
        'ii',
        $jobId,
        $inspectorId
    );

    if (!$stmt->execute()) {
        throw new Exception(
            'Unable to load inspection: ' .
            $stmt->error
        );
    }

    $job = $stmt
        ->get_result()
        ->fetch_assoc();

    $stmt->close();

    if (!$job) {
        throw new Exception(
            'Inspection assignment not found or it is not assigned to you.'
        );
    }

    if (
        $job['saf_status'] !== 'Accepted'
    ) {
        throw new Exception(
            'This SAF has not been accepted by the Owner.'
        );
    }

    $assignmentStatus =
        (string)($job['assignment_status'] ?? '');

    $jobStatus =
        (string)($job['job_status'] ?? '');

    if (
        $assignmentStatus !== 'assigned'
        &&
        $assignmentStatus !== 'in_progress'
    ) {
        throw new Exception(
            'This inspection cannot be started because its assignment status is ' .
            $assignmentStatus . '.'
        );
    }

    if (
        $jobStatus !== 'assigned'
        &&
        $jobStatus !== 'in_progress'
    ) {
        throw new Exception(
            'This inspection cannot be started because its job status is ' .
            $jobStatus . '.'
        );
    }

    /* =====================================================
       OWNER ATTENDANCE OTP CHECK
       -----------------------------------------------------
       The check is tied to the exact assignment/job,
       Inspector and Owner. No global unlock.
    ===================================================== */

    $attendanceStmt = $conn->prepare("
        SELECT
            verified_at
        FROM inspector_attendance
        WHERE
            assignment_id = ?
            AND job_id = ?
            AND inspector_id = ?
            AND owner_id = ?
        LIMIT 1
    ");

    if (!$attendanceStmt) {
        throw new Exception(
            'Unable to verify site attendance: ' .
            $conn->error
        );
    }

    $assignmentId =
        (int)$job['assignment_id'];

    $ownerId =
        (int)$job['owner_id'];

    $attendanceStmt->bind_param(
        'iiii',
        $assignmentId,
        $jobId,
        $inspectorId,
        $ownerId
    );

    if (!$attendanceStmt->execute()) {
        throw new Exception(
            'Unable to check site attendance: ' .
            $attendanceStmt->error
        );
    }

    $attendanceRow =
        $attendanceStmt
            ->get_result()
            ->fetch_assoc();

    $attendanceStmt->close();

    if (
        !$attendanceRow
        ||
        empty($attendanceRow['verified_at'])
    ) {

        throw new Exception(
            'Owner site attendance has not been verified by OTP. ' .
            'Please complete Attendance Verification before starting the inspection.'
        );
    }

    /* =====================================================
       START INSPECTION
    ===================================================== */

    if (
        $assignmentStatus === 'assigned'
        ||
        $jobStatus === 'assigned'
    ) {

        $updateJob = $conn->prepare("
            UPDATE maintenance_jobs
            SET
                status = 'in_progress',
                updated_at = NOW()
            WHERE
                id = ?
                AND job_type = 'saf_inspection'
                AND status = 'assigned'
                AND EXISTS (
                    SELECT 1
                    FROM inspector_assignments ia2
                    WHERE
                        ia2.job_id = maintenance_jobs.id
                        AND ia2.inspector_id = ?
                )
        ");

        if (!$updateJob) {
            throw new Exception(
                'Unable to prepare inspection start: ' .
                $conn->error
            );
        }

        $updateJob->bind_param(
            'ii',
            $jobId,
            $inspectorId
        );

        if (!$updateJob->execute()) {
            throw new Exception(
                'Unable to start inspection: ' .
                $updateJob->error
            );
        }

        $updateJob->close();

        $updateAssignment = $conn->prepare("
            UPDATE inspector_assignments
            SET
                status = 'in_progress',
                started_at = COALESCE(started_at, NOW())
            WHERE
                id = ?
                AND inspector_id = ?
                AND status = 'assigned'
        ");

        if (!$updateAssignment) {
            throw new Exception(
                'Unable to prepare Inspector assignment update: ' .
                $conn->error
            );
        }

        $updateAssignment->bind_param(
            'ii',
            $assignmentId,
            $inspectorId
        );

        if (!$updateAssignment->execute()) {
            throw new Exception(
                'Unable to start Inspector assignment: ' .
                $updateAssignment->error
            );
        }

        $updateAssignment->close();
    }

    /* =====================================================
       FINAL VERIFICATION
    ===================================================== */

    $verify = $conn->prepare("
        SELECT
            mj.id,
            mj.status AS job_status,
            ia.status AS assignment_status,
            ia.inspector_id
        FROM maintenance_jobs mj
        INNER JOIN inspector_assignments ia
            ON ia.job_id = mj.id
        WHERE
            mj.id = ?
            AND ia.inspector_id = ?
            AND mj.job_type = 'saf_inspection'
        LIMIT 1
    ");

    if (!$verify) {
        throw new Exception(
            'Unable to verify inspection start: ' .
            $conn->error
        );
    }

    $verify->bind_param(
        'ii',
        $jobId,
        $inspectorId
    );

    $verify->execute();

    $verified =
        $verify
            ->get_result()
            ->fetch_assoc();

    $verify->close();

    if (!$verified) {
        throw new Exception(
            'Unable to verify Inspector assignment.'
        );
    }

    if (
        $verified['job_status'] !== 'in_progress'
        ||
        $verified['assignment_status'] !== 'in_progress'
    ) {
        throw new Exception(
            'Inspection could not be started correctly.'
        );
    }

    $conn->commit();

    header(
        'Location: saf-checklist.php?job_id=' .
        $jobId
    );
    exit;

} catch (Throwable $e) {

    $conn->rollback();

    $_SESSION['error'] =
        $e->getMessage();

    header(
        'Location: inspections.php'
    );
    exit;
}
?>
