<?php
/* =========================================================
   NLETA OWNER — CA DECISION + ONE-TIME RECHECK SCHEDULER
   ---------------------------------------------------------
   OWNER ACTIONS
     1) CA Approve
        - Records Owner approval.
        - Original CA remains CA.
        - No recheck is triggered.

     2) CA Reject
        - Records Owner rejection + reason.
        - Original CA remains CA.
        - Does NOT immediately schedule recheck if other CA
          checkpoints are still awaiting Owner decision.

     3) Schedule Recheck
        - Allowed only after EVERY CA checkpoint has a final
          Owner decision (Approve or Reject).
        - If there are no CA checkpoints, NC can be scheduled
          directly.
        - If at least one CA is rejected OR NC exists, the
          single recheck can be scheduled.
        - Recheck is one-time only.
        - Recheck date/status are stored in maintenance_jobs.
========================================================= */

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/security.php';

require_role('owner');

date_default_timezone_set('Asia/Kolkata');

function backToSaf(int $jobId): void
{
    header(
        'Location: saf-recheck.php?job_id=' . rawurlencode((string)$jobId),
        true,
        303
    );
    exit;
}

function failOwnerAction(string $message, int $jobId): void
{
    $_SESSION['owner_pat_error'] = $message;
    backToSaf($jobId);
}

function successOwnerAction(string $message, int $jobId): void
{
    $_SESSION['owner_pat_success'] = $message;
    backToSaf($jobId);
}

function validRecheckDate(string $date): bool
{
    if ($date === '') {
        return false;
    }

    $dt = DateTime::createFromFormat('Y-m-d', $date);
    return $dt instanceof DateTime
        && $dt->format('Y-m-d') === $date;
}

function checklistArray(string $json): array
{
    if ($json === '') {
        return [];
    }

    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

$ownerId = (int)(
    $_SESSION['uid']
    ?? $_SESSION['user_id']
    ?? 0
);

$jobId = (int)(
    $_POST['job_id']
    ?? 0
);

$action = strtolower(trim((string)(
    $_POST['owner_action']
    ?? ''
)));

if ($ownerId <= 0) {
    header('Location: ../login.php');
    exit;
}

if ($jobId <= 0) {
    failOwnerAction('Invalid inspection job.', 0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    backToSaf($jobId);
}

if (
    !validateCSRFToken(
        $_POST['csrf_token'] ?? ''
    )
) {
    failOwnerAction(
        'Invalid security token. Please refresh the page and try again.',
        $jobId
    );
}

$allowedActions = [
    'ca_approve',
    'ca_reject',
    'schedule_recheck'
];

if (!in_array($action, $allowedActions, true)) {
    failOwnerAction(
        'Invalid Owner action.',
        $jobId
    );
}

$caRef = trim((string)(
    $_POST['ca_ref']
    ?? ''
));

$caFloor = trim((string)(
    $_POST['ca_floor']
    ?? ''
));

$rejectReason = trim((string)(
    $_POST['ca_reject_reason']
    ?? ''
));

$requestedRecheckDate = trim((string)(
    $_POST['recheck_date']
    ?? ''
));

$recheckRemark = trim((string)(
    $_POST['recheck_remark']
    ?? ''
));

if (
    $action !== 'schedule_recheck'
    && $caRef === ''
) {
    failOwnerAction(
        'No Conditional Acceptance checkpoint was selected.',
        $jobId
    );
}

if (
    $action === 'ca_reject'
    && $rejectReason === ''
) {
    failOwnerAction(
        'Please enter a reason before rejecting this Conditional Acceptance.',
        $jobId
    );
}

try {

    $conn->begin_transaction();

    $stmt = $conn->prepare("
        SELECT
            mj.id,
            mj.owner_id,
            mj.saf_id,
            mj.asset_id,
            mj.status AS job_status,
            mj.checklist,
            mj.recheck_date,
            mj.recheck_status,

            s.service_code,
            s.service_name,
            s.status AS saf_status

        FROM maintenance_jobs mj

        INNER JOIN saf_contracts s
            ON s.id = mj.saf_id

        WHERE
            mj.id = ?
            AND mj.owner_id = ?
            AND mj.job_type = 'saf_inspection'

        LIMIT 1

        FOR UPDATE
    ");

    if (!$stmt) {
        throw new RuntimeException(
            'Unable to prepare Owner action verification: ' . $conn->error
        );
    }

    $stmt->bind_param('ii', $jobId, $ownerId);

    if (!$stmt->execute()) {
        throw new RuntimeException(
            'Unable to verify Owner action: ' . $stmt->error
        );
    }

    $job = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$job) {
        throw new RuntimeException(
            'Inspection job not found or it does not belong to your account.'
        );
    }

    if (
        strcasecmp(
            trim((string)($job['saf_status'] ?? '')),
            'Accepted'
        ) !== 0
    ) {
        throw new RuntimeException(
            'This SAF has not been accepted by the Owner.'
        );
    }

    $checklist = checklistArray(
        (string)($job['checklist'] ?? '')
    );

    /* =====================================================
       SCHEDULE RECHECK
    ====================================================== */

    if ($action === 'schedule_recheck') {

        $currentRecheckStatus = strtolower(trim((string)(
            $job['recheck_status'] ?? ''
        )));

        if (in_array(
            $currentRecheckStatus,
            ['scheduled', 'in_progress', 'completed'],
            true
        )) {
            throw new RuntimeException(
                'The one-time recheck is already '
                . $currentRecheckStatus
                . '. A second recheck cannot be scheduled.'
            );
        }

        if (!validRecheckDate($requestedRecheckDate)) {
            throw new RuntimeException(
                'Please select a valid recheck date in YYYY-MM-DD format.'
            );
        }

        /*
         * Collect every CA checkpoint from both fixed results
         * and floor-wise results.
         */
        $caItems = [];

        if (
            isset($checklist['results'])
            && is_array($checklist['results'])
        ) {
            foreach ($checklist['results'] as $ref => $item) {
                if (!is_array($item)) {
                    continue;
                }

                if (
                    strtoupper(trim((string)($item['result'] ?? '')))
                    !== 'CA'
                ) {
                    continue;
                }

                $caItems[] = [
                    'ref' => (string)$ref,
                    'floor' => '',
                    'item' => $item
                ];
            }
        }

        foreach (['floor_checklist', 'floor_results'] as $container) {
            if (
                !isset($checklist[$container])
                || !is_array($checklist[$container])
            ) {
                continue;
            }

            foreach ($checklist[$container] as $floor => $floorItems) {
                if (!is_array($floorItems)) {
                    continue;
                }

                foreach ($floorItems as $ref => $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    if (
                        strtoupper(trim((string)($item['result'] ?? '')))
                        !== 'CA'
                    ) {
                        continue;
                    }

                    $caItems[] = [
                        'ref' => (string)$ref,
                        'floor' => (string)$floor,
                        'item' => $item
                    ];
                }
            }
        }

        $pendingCa = 0;
        $approvedCa = 0;
        $rejectedCa = 0;

        foreach ($caItems as $ca) {
            $decision = (
                isset($ca['item']['owner_ca_decision'])
                && is_array($ca['item']['owner_ca_decision'])
            )
                ? $ca['item']['owner_ca_decision']
                : [];

            $decisionStatus = strtolower(trim((string)(
                $decision['status'] ?? ''
            )));

            if ($decisionStatus === 'approved') {
                $approvedCa++;
            } elseif ($decisionStatus === 'rejected') {
                $rejectedCa++;
            } else {
                $pendingCa++;
            }
        }

        /*
         * Count original NC checkpoints. NC always participates
         * in the one-time recheck when present.
         */
        $ncCount = 0;

        if (
            isset($checklist['results'])
            && is_array($checklist['results'])
        ) {
            foreach ($checklist['results'] as $item) {
                if (!is_array($item)) {
                    continue;
                }

                if (
                    strtoupper(trim((string)($item['result'] ?? '')))
                    === 'NC'
                ) {
                    $ncCount++;
                }
            }
        }

        foreach (['floor_checklist', 'floor_results'] as $container) {
            if (
                !isset($checklist[$container])
                || !is_array($checklist[$container])
            ) {
                continue;
            }

            foreach ($checklist[$container] as $floorItems) {
                if (!is_array($floorItems)) {
                    continue;
                }

                foreach ($floorItems as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    if (
                        strtoupper(trim((string)($item['result'] ?? '')))
                        === 'NC'
                    ) {
                        $ncCount++;
                    }
                }
            }
        }

        if ($pendingCa > 0) {
            throw new RuntimeException(
                $pendingCa
                . ' Conditional Acceptance checkpoint(s) still require Owner decision. '
                . 'Approve or Reject every CA checkpoint before scheduling the recheck.'
            );
        }

        if ($ncCount <= 0 && $rejectedCa <= 0) {
            throw new RuntimeException(
                'No NC checkpoint or Owner-rejected CA checkpoint requires a recheck.'
            );
        }

        $nowIst = date('Y-m-d H:i:s');

        $checklist['recheck_schedule'] = [
            'status' => 'scheduled',
            'scheduled_by_owner_id' => $ownerId,
            'scheduled_at_ist' => $nowIst,
            'timezone' => 'Asia/Kolkata',
            'recheck_date' => $requestedRecheckDate,
            'original_nc_count' => $ncCount,
            'owner_rejected_ca_count' => $rejectedCa,
            'owner_approved_ca_count' => $approvedCa,
            'remark' => $recheckRemark
        ];

        $newJson = json_encode(
            $checklist,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_PRETTY_PRINT
        );

        if ($newJson === false) {
            throw new RuntimeException(
                'Unable to encode recheck schedule data.'
            );
        }

        $update = $conn->prepare("
            UPDATE maintenance_jobs
            SET
                checklist = ?,
                recheck_date = ?,
                recheck_status = 'scheduled',
                updated_at = NOW()
            WHERE
                id = ?
                AND owner_id = ?
                AND job_type = 'saf_inspection'
            LIMIT 1
        ");

        if (!$update) {
            throw new RuntimeException(
                'Unable to prepare recheck schedule: ' . $conn->error
            );
        }

        $update->bind_param(
            'ssii',
            $newJson,
            $requestedRecheckDate,
            $jobId,
            $ownerId
        );

        if (!$update->execute()) {
            $err = $update->error;
            $update->close();

            throw new RuntimeException(
                'Unable to schedule recheck: ' . $err
            );
        }

        if ($update->affected_rows < 1) {
            $update->close();

            throw new RuntimeException(
                'Recheck could not be scheduled.'
            );
        }

        $update->close();

        $conn->commit();

        if ($rejectedCa > 0) {
            successOwnerAction(
                'Recheck scheduled successfully. '
                . $rejectedCa
                . ' Owner-rejected CA checkpoint(s)'
                . ($ncCount > 0 ? ' and ' . $ncCount . ' NC checkpoint(s)' : '')
                . ' will be handled in the single recheck.',
                $jobId
            );
        }

        successOwnerAction(
            'Recheck scheduled successfully for '
            . $ncCount
            . ' NC checkpoint(s).',
            $jobId
        );
    }

    /* =====================================================
       CA APPROVE / REJECT
    ====================================================== */

    $targetLocation = 'results';
    $targetFloor = '';
    $targetItem = null;

    /*
     * If floor is supplied, prefer the floor containers first.
     * This avoids accidentally matching a fixed checkpoint with
     * the same reference.
     */
    if ($caFloor !== '') {
        foreach (['floor_checklist', 'floor_results'] as $container) {
            if (
                isset(
                    $checklist[$container][$caFloor][$caRef]
                )
                &&
                is_array(
                    $checklist[$container][$caFloor][$caRef]
                )
            ) {
                $targetLocation = $container;
                $targetFloor = $caFloor;
                $targetItem =
                    $checklist[$container][$caFloor][$caRef];
                break;
            }
        }
    }

    if (!is_array($targetItem)) {
        if (
            isset($checklist['results'][$caRef])
            &&
            is_array($checklist['results'][$caRef])
        ) {
            $targetLocation = 'results';
            $targetItem = $checklist['results'][$caRef];
        }
    }

    if (!is_array($targetItem)) {
        throw new RuntimeException(
            'Conditional Acceptance checkpoint '
            . $caRef
            . ' was not found in the saved checklist.'
        );
    }

    $targetResult = strtoupper(trim((string)(
        $targetItem['result'] ?? ''
    )));

    if ($targetResult !== 'CA') {
        throw new RuntimeException(
            'Only a checkpoint currently marked CA can receive an Owner CA decision.'
        );
    }

    $existingDecision = (
        isset($targetItem['owner_ca_decision'])
        && is_array($targetItem['owner_ca_decision'])
    )
        ? $targetItem['owner_ca_decision']
        : [];

    $existingStatus = strtolower(trim((string)(
        $existingDecision['status'] ?? ''
    )));

    if (
        $existingStatus === 'approved'
        ||
        $existingStatus === 'rejected'
    ) {
        throw new RuntimeException(
            'This Conditional Acceptance already has a final Owner decision.'
        );
    }

    $decisionStatus =
        $action === 'ca_approve'
            ? 'approved'
            : 'rejected';

    $nowIst = date('Y-m-d H:i:s');

    $targetItem['owner_ca_decision'] = [
        'status' => $decisionStatus,
        'owner_id' => $ownerId,
        'decided_at_ist' => $nowIst,
        'timezone' => 'Asia/Kolkata',
        'reason' => $decisionStatus === 'rejected'
            ? $rejectReason
            : '',
        'decision_text' => $decisionStatus === 'approved'
            ? 'Owner Approved — Conditional Acceptance permitted.'
            : 'Owner Rejected — Conditional Acceptance requires recheck.',
        'recheck_required' => $decisionStatus === 'rejected'
    ];

    if ($targetLocation === 'results') {
        $checklist['results'][$caRef] = $targetItem;
    } else {
        $checklist[$targetLocation][$targetFloor][$caRef] = $targetItem;
    }

    $newJson = json_encode(
        $checklist,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRETTY_PRINT
    );

    if ($newJson === false) {
        throw new RuntimeException(
            'Unable to save Owner CA decision.'
        );
    }

    $update = $conn->prepare("
        UPDATE maintenance_jobs
        SET
            checklist = ?,
            updated_at = NOW()
        WHERE
            id = ?
            AND owner_id = ?
            AND job_type = 'saf_inspection'
        LIMIT 1
    ");

    if (!$update) {
        throw new RuntimeException(
            'Unable to prepare Owner CA decision save: ' . $conn->error
        );
    }

    $update->bind_param(
        'sii',
        $newJson,
        $jobId,
        $ownerId
    );

    if (!$update->execute()) {
        $err = $update->error;
        $update->close();

        throw new RuntimeException(
            'Unable to save Owner CA decision: ' . $err
        );
    }

    if ($update->affected_rows < 1) {
        $update->close();

        throw new RuntimeException(
            'Owner CA decision could not be saved.'
        );
    }

    $update->close();

    $conn->commit();

    if ($decisionStatus === 'approved') {
        successOwnerAction(
            'Conditional Acceptance approved by Owner. Recheck scheduling remains available only for NC or other rejected CA checkpoints.',
            $jobId
        );
    }

    /*
     * Rejected CA is recorded first. If other CA checkpoints are
     * still pending, the Recheck Schedule button remains locked.
     * Once all CA decisions are final, Owner can schedule the
     * single recheck.
     */
    successOwnerAction(
        'Conditional Acceptance rejected by Owner. '
        . 'The rejection has been recorded. '
        . 'Please finalize every remaining CA decision; after all CA decisions are final, the Recheck Schedule option will open.',
        $jobId
    );

} catch (Throwable $e) {

    try {
        $conn->rollback();
    } catch (Throwable $ignore) {
    }

    error_log(
        'NLETA Owner CA/Recheck Error: '
        . $e->getMessage()
    );

    failOwnerAction(
        $e->getMessage(),
        $jobId
    );
}
