<?php
/* =========================================================
   NLETA INSPECTOR — SAF CHECKLIST ROUTER
   ---------------------------------------------------------
   PURPOSE:
   Start Inspection opens the correct checklist according to
   the service actually linked to the assigned SAF/job.

   ROUTING:
       PAT -> pat-checklist.php
       SAT -> sat-checklist.php
       SCI -> sci-checklist.php

   IMPORTANT:
   - Existing PAT/SAT/SCI checklist files are NOT changed.
   - Existing checklist save/recheck/evidence functionality
     remains in those respective files.
   - service_catalog is used as a fallback when
     saf_contracts.service_code is empty.
   - Inspector access is still verified against the actual
     inspector assignment.
   - assignment_id and job_id are preserved in the redirect.
   - All timestamps/logic use IST.
========================================================= */

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

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
   INSPECTOR / REQUEST
========================================================= */

$inspectorId = (int)(
    $_SESSION['uid']
    ?? $_SESSION['user_id']
    ?? 0
);

$jobId = (int)(
    $_GET['job_id']
    ?? $_POST['job_id']
    ?? 0
);

$assignmentId = (int)(
    $_GET['assignment_id']
    ?? $_POST['assignment_id']
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
   LOAD THE ACTUAL ASSIGNED SAF JOB
   ---------------------------------------------------------
   Inspector assignment is the access-control authority.
========================================================= */

$sql = "
    SELECT
        mj.id AS job_id,
        mj.saf_id,
        mj.service_id AS job_service_id,
        mj.asset_id,
        mj.owner_id,
        mj.status AS job_status,
        mj.job_type,

        ia.id AS assignment_id,
        ia.inspector_id,
        ia.service_id AS assignment_service_id,
        ia.status AS assignment_status,

        s.psga_no,
        s.saf_no,
        s.service_id AS saf_service_id,
        s.service_code,
        s.service_name,
        s.status AS saf_status,

        a.asset_type,
        a.display_asset_id,
        a.asset_name,
        a.location

    FROM inspector_assignments ia

    INNER JOIN maintenance_jobs mj
        ON mj.id = ia.job_id

    INNER JOIN saf_contracts s
        ON s.id = mj.saf_id

    LEFT JOIN assets a
        ON a.id = mj.asset_id

    WHERE
        ia.job_id = ?
        AND ia.inspector_id = ?
        AND mj.job_type = 'saf_inspection'

    LIMIT 1
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die(
        '<div style="margin:30px;padding:20px;border:1px solid #dc3545;'
        . 'border-radius:10px;background:#fff5f5;font-family:Arial">'
        . '<h3 style="color:#b02a37">SAF Checklist Router Error</h3>'
        . '<strong>Prepare failed:</strong> '
        . h($conn->error)
        . '</div>'
    );
}

$stmt->bind_param(
    'ii',
    $jobId,
    $inspectorId
);

if (!$stmt->execute()) {
    die(
        '<div style="margin:30px;padding:20px;border:1px solid #dc3545;'
        . 'border-radius:10px;background:#fff5f5;font-family:Arial">'
        . '<h3 style="color:#b02a37">SAF Checklist Router Error</h3>'
        . '<strong>Execute failed:</strong> '
        . h($stmt->error)
        . '</div>'
    );
}

$job = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$job) {
    header('Location: inspections.php');
    exit;
}


/* =========================================================
   VERIFY ASSIGNMENT ID WHEN ONE WAS PROVIDED
========================================================= */

$realAssignmentId = (int)(
    $job['assignment_id']
    ?? 0
);

if (
    $assignmentId > 0
    && $realAssignmentId > 0
    && $assignmentId !== $realAssignmentId
) {
    die('Invalid Inspector assignment.');
}

$assignmentId = $realAssignmentId;


/* =========================================================
   SAF ACCEPTANCE
========================================================= */

$safStatus = strtolower(
    trim(
        (string)(
            $job['saf_status']
            ?? ''
        )
    )
);

if (
    $safStatus !== ''
    && $safStatus !== 'accepted'
) {
    die(
        'The SAF must be accepted by the Owner before inspection.'
    );
}


/* =========================================================
   JOB STATUS
========================================================= */

$jobStatus = strtolower(
    trim(
        (string)(
            $job['job_status']
            ?? ''
        )
    )
);

if (
    !in_array(
        $jobStatus,
        [
            'assigned',
            'in_progress',
            'pending_owner',
            'approved',
            'closed',
            'completed',
            'submitted'
        ],
        true
    )
) {
    die(
        'This inspection is not available for checklist work. Current status: '
        . h($job['job_status'] ?? '')
    );
}


/* =========================================================
   FINAL SUBMISSION LOCK
   ---------------------------------------------------------
   "completed" is NOT treated as a permanent checklist lock.
   The Inspector must be able to View/Continue a checklist
   until the actual final submission workflow is completed.

   The authoritative lock is inspector_assignments.status =
   submitted. Once submitted, the checklist is view-only and
   its save handler must reject further edits.
========================================================= */

$assignmentStatus = strtolower(
    trim((string)($job['assignment_status'] ?? ''))
);

$isFinalSubmitted = in_array(
    $assignmentStatus,
    ['submitted', 'final_submitted'],
    true
);

/*
 * A maintenance job may contain "completed" because of an
 * older status-sync process. That must NOT prevent opening
 * the checklist. The actual assignment submission state above
 * is what determines whether editing is locked.
 */


/* =========================================================
   SERVICE RESOLUTION
   ---------------------------------------------------------
   IMPORTANT FIX:
   The previous router depended only on
   saf_contracts.service_code.

   If that value is empty, resolve it safely from:
       saf_contracts.service_id
       OR maintenance_jobs.service_id
       -> service_catalog.id
========================================================= */

$serviceCode = strtoupper(
    trim(
        (string)(
            $job['service_code']
            ?? ''
        )
    )
);

$serviceName = trim(
    (string)(
        $job['service_name']
        ?? ''
    )
);

$serviceId = (int)(
    $job['assignment_service_id']
    ?? 0
);

if ($serviceId <= 0) {
    $serviceId = (int)(
        $job['saf_service_id']
        ?? 0
    );
}

if ($serviceId <= 0) {
    $serviceId = (int)(
        $job['job_service_id']
        ?? 0
    );
}


/* =========================================================
   FALLBACK TO SERVICE CATALOG
========================================================= */

if (
    $serviceCode === ''
    && $serviceId > 0
) {

    $serviceStmt = $conn->prepare("
        SELECT
            id,
            service_code,
            service_name
        FROM service_catalog
        WHERE
            id = ?
            AND is_active = 1
        LIMIT 1
    ");

    if ($serviceStmt) {

        $serviceStmt->bind_param(
            'i',
            $serviceId
        );

        if ($serviceStmt->execute()) {

            $service = $serviceStmt
                ->get_result()
                ->fetch_assoc();

            if ($service) {
                $serviceCode = strtoupper(
                    trim(
                        (string)(
                            $service['service_code']
                            ?? ''
                        )
                    )
                );

                if ($serviceName === '') {
                    $serviceName = trim(
                        (string)(
                            $service['service_name']
                            ?? ''
                        )
                    );
                }
            }
        }

        $serviceStmt->close();
    }
}


/* =========================================================
   SECOND FALLBACK:
   Resolve from maintenance_jobs.service_id
========================================================= */

if (
    $serviceCode === ''
    && $job['job_service_id'] !== null
) {

    $jobServiceId = (int)(
        $job['job_service_id']
        ?? 0
    );

    if ($jobServiceId > 0) {

        $serviceStmt = $conn->prepare("
            SELECT
                id,
                service_code,
                service_name
            FROM service_catalog
            WHERE
                id = ?
                AND is_active = 1
            LIMIT 1
        ");

        if ($serviceStmt) {

            $serviceStmt->bind_param(
                'i',
                $jobServiceId
            );

            if ($serviceStmt->execute()) {

                $service = $serviceStmt
                    ->get_result()
                    ->fetch_assoc();

                if ($service) {

                    $serviceCode = strtoupper(
                        trim(
                            (string)(
                                $service['service_code']
                                ?? ''
                            )
                        )
                    );

                    if ($serviceName === '') {
                        $serviceName = trim(
                            (string)(
                                $service['service_name']
                                ?? ''
                            )
                        );
                    }
                }
            }

            $serviceStmt->close();
        }
    }
}


/* =========================================================
   NORMALIZE KNOWN SERVICE CODE VARIANTS
========================================================= */

$serviceCode = strtoupper(
    trim($serviceCode)
);


/* =========================================================
   ASSET TYPE RESOLUTION
   ---------------------------------------------------------
   Checklist routing MUST use both:
       1. Asset Type
       2. Assigned Service

   This prevents:
       Escalator + PAT -> Lift PAT checklist

   and keeps existing Lift routing untouched.
========================================================= */

$assetType = strtolower(
    trim(
        (string)(
            $job['asset_type']
            ?? ''
        )
    )
);

$isEscalator =
    str_contains($assetType, 'escalator')
    || str_contains($assetType, 'travellator');

$isLift =
    str_contains($assetType, 'lift')
    || str_contains($assetType, 'elevator');


/* =========================================================
   SUPPORTED CHECKLIST ROUTING
========================================================= */

$checklistPage = '';

if ($isEscalator) {

    switch ($serviceCode) {

        case 'PAT':
            $checklistPage =
                'escalator-pat-checklist.php';
            break;

        case 'SAT':
            $checklistPage =
                'escalator-sat-checklist.php';
            break;

        case 'SCI':
            $checklistPage =
                'escalator-sci-checklist.php';
            break;

        default:
            $checklistPage = '';
            break;
    }

} elseif ($isLift) {

    /*
     * EXISTING LIFT ROUTING — PRESERVED
     */
    switch ($serviceCode) {

        case 'PAT':
            $checklistPage =
                'pat-checklist.php';
            break;

        case 'SAT':
            $checklistPage =
                'sat-checklist.php';
            break;

        case 'SCI':
            $checklistPage =
                'sci-checklist.php';
            break;

        default:
            $checklistPage = '';
            break;
    }

} else {

    /*
     * Unknown / unsupported asset type.
     * Never silently send it to a Lift checklist.
     */
    $checklistPage = '';
}


/* =========================================================
   CONFIGURATION ERROR
========================================================= */

if ($checklistPage === '') {

    $shownAssetType =
        $assetType !== ''
            ? $job['asset_type']
            : 'EMPTY';

    $shownCode =
        $serviceCode !== ''
            ? $serviceCode
            : 'EMPTY';

    die(
        '<div style="max-width:900px;margin:50px auto;padding:30px;'
        . 'border:1px solid #dc3545;border-radius:12px;'
        . 'background:#fff5f5;font-family:Arial,sans-serif;'
        . 'color:#842029">'
        . '<h2 style="margin-top:0">Checklist Configuration Error</h2>'

        . '<p style="font-size:17px">'
        . 'The correct checklist could not be determined from '
        . '<strong>Asset Type + Service</strong>.'
        . '</p>'

        . '<div style="background:#fff;padding:15px;border-radius:8px;'
        . 'border:1px solid #f0c2c7;margin:15px 0">'
        . '<strong>Asset Type:</strong> '
        . h($shownAssetType)
        . '<br>'
        . '<strong>Service:</strong> '
        . h($shownCode)
        . '<br>'
        . '<strong>Service ID:</strong> '
        . h($serviceId)
        . '<br>'
        . '<strong>SAF No.:</strong> '
        . h($job['saf_no'] ?? '—')
        . '<br>'
        . '<strong>Job ID:</strong> '
        . h($jobId)
        . '<br>'
        . '<strong>Assignment ID:</strong> '
        . h($assignmentId)
        . '</div>'

        . '<p>'
        . 'Supported routing:'
        . '<br>Lift + PAT → pat-checklist.php'
        . '<br>Lift + SAT → sat-checklist.php'
        . '<br>Lift + SCI → sci-checklist.php'
        . '<br>Escalator + PAT → escalator-pat-checklist.php'
        . '<br>Escalator + SAT → escalator-sat-checklist.php'
        . '<br>Escalator + SCI → escalator-sci-checklist.php'
        . '</p>'

        . '<a href="inspections.php">← Back to Inspections</a>'
        . '</div>'
    );

    exit;
}


/* =========================================================
   FINAL ROUTING REDIRECT
   ---------------------------------------------------------
   Preserve both assignment_id and job_id.
========================================================= */

$query = http_build_query([
    'assignment_id' => $assignmentId,
    'job_id'        => $jobId,
    'service_id'    => $serviceId
]);

header(
    'Location: ' .
    $checklistPage .
    '?' .
    $query
);

exit;
?>
