<?php



/* =========================================================
   INCLUDES
========================================================= */

include('../config/db.php');
include('../config/auth.php');

require_role('owner');

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$owner_id = (int)($_SESSION['uid'] ?? 0);

$timezone = new DateTimeZone('Asia/Kolkata');

$today = new DateTime('today', $timezone);



/* =========================================================
   BASIC COUNTS
========================================================= */

/* Total lifts */

$s1 = $conn->prepare("
    SELECT COUNT(*) AS t
    FROM assets
    WHERE owner_id = ?
");

$s1->bind_param("i", $owner_id);
$s1->execute();

$assets = (int)$s1->get_result()->fetch_assoc()['t'];


/* Out of Service */

$s2 = $conn->prepare("
    SELECT COUNT(*) AS t
    FROM assets
    WHERE owner_id = ?
      AND status = 'Out of Service'
");

$s2->bind_param("i", $owner_id);
$s2->execute();

$outOfService = (int)$s2->get_result()->fetch_assoc()['t'];


/* =========================================================
   UNDER MAINTENANCE
   ONLY ACTIVE MAINTENANCE JOBS
========================================================= */

$s3 = $conn->prepare("
    SELECT COUNT(DISTINCT a.id) AS t

    FROM assets a

    INNER JOIN maintenance_jobs mj
        ON mj.asset_id = a.id

    WHERE a.owner_id = ?

      AND mj.status IN (
          'assigned',
          'in_progress',
          'pending_owner'
      )
");

$s3->bind_param("i", $owner_id);
$s3->execute();

$underMaintenance =
    (int)$s3->get_result()->fetch_assoc()['t'];


/* =========================================================
   JOB STATUS COUNTS
========================================================= */

$jobStatusStmt = $conn->prepare("
    SELECT
        mj.status,
        COUNT(*) AS c

    FROM maintenance_jobs mj

    INNER JOIN assets a
        ON a.id = mj.asset_id

    WHERE a.owner_id = ?

      AND mj.status IN (
          'assigned',
          'in_progress',
          'pending_owner'
      )

    GROUP BY mj.status
");

$jobStatusStmt->bind_param("i", $owner_id);
$jobStatusStmt->execute();

$jobStatuses = $jobStatusStmt->get_result();

$jobCounts = [
    'assigned'      => 0,
    'in_progress'   => 0,
    'pending_owner' => 0
];

while ($row = $jobStatuses->fetch_assoc()) {

    $status = $row['status'];

    if (isset($jobCounts[$status])) {
        $jobCounts[$status] = (int)$row['c'];
    }
}


/* =========================================================
   ACTIVE LIFTS
========================================================= */

$s4 = $conn->prepare("
    SELECT COUNT(*) AS t
    FROM assets
    WHERE owner_id = ?
      AND status = 'Active'
");

$s4->bind_param("i", $owner_id);
$s4->execute();

$activeLifts =
    (int)$s4->get_result()->fetch_assoc()['t'];


/* =========================================================
   PENDING OWNER APPROVALS
========================================================= */

$s5 = $conn->prepare("
    SELECT COUNT(*) AS t

    FROM maintenance_jobs mj

    INNER JOIN assets a
        ON a.id = mj.asset_id

    WHERE a.owner_id = ?

      AND mj.status = 'pending_owner'
");

$s5->bind_param("i", $owner_id);
$s5->execute();

$pendingApprovals =
    (int)$s5->get_result()->fetch_assoc()['t'];


/* =========================================================
   AMC STATUS
=========================================================

   IMPORTANT:

   AMC STATUS IS NOT BASED ON maintenance_jobs.

   AMC is calculated from:

   1. Last approved maintenance date
   2. maintenance_interval
   3. maintenance_start_date if available

   Result:

   AMC OK  = maintenance is not due
   AMC Due = maintenance date has passed

   Maintenance job status does NOT affect AMC chart.

========================================================= */

$amc = [
    'ok'      => 0,
    'due'     => 0,
    'pending' => 0
];

$maintenanceDueSoon = 0;


/* =========================================================
   GET ASSETS + MAINTENANCE INFORMATION
========================================================= */

$amcStmt = $conn->prepare("
    SELECT

        a.id,

        a.asset_name,

        a.status,

        a.maintenance_interval,

        a.maintenance_start_date,

        (
            SELECT MAX(mr.maintenance_date)

            FROM maintenance_records mr

            WHERE mr.asset_id = a.id

              AND mr.status = 'approved'

        ) AS last_maintenance

    FROM assets a

    WHERE a.owner_id = ?

    ORDER BY a.asset_name
");

$amcStmt->bind_param("i", $owner_id);
$amcStmt->execute();

$amcQ = $amcStmt->get_result();


while ($r = $amcQ->fetch_assoc()) {

    $interval = (int)$r['maintenance_interval'];

    $lastMaintenance = $r['last_maintenance'];
    $startDate       = $r['maintenance_start_date'];

    $dueDate = null;


    /* =====================================================
       DETERMINE MAINTENANCE BASE DATE
    ===================================================== */

    if (!empty($lastMaintenance)) {

        /*
         * Prefer last approved maintenance.
         */

        try {

            $baseDate = new DateTime(
                $lastMaintenance,
                $timezone
            );

            if ($interval > 0) {

                $dueDate = clone $baseDate;

                $dueDate->modify(
                    "+{$interval} days"
                );
            }

        } catch (Exception $e) {

            $dueDate = null;
        }

    } elseif (!empty($startDate)) {

        /*
         * If there is no maintenance record,
         * use maintenance_start_date.
         */

        try {

            $baseDate = new DateTime(
                $startDate,
                $timezone
            );

            if ($interval > 0) {

                $dueDate = clone $baseDate;

                $dueDate->modify(
                    "+{$interval} days"
                );
            }

        } catch (Exception $e) {

            $dueDate = null;
        }
    }


    /* =====================================================
       NO DUE DATE
    ===================================================== */

    if ($dueDate === null) {

        /*
         * No maintenance baseline means
         * we cannot mark the lift as overdue.

         * Keep it AMC OK.
         */

        $amc['ok']++;

        continue;
    }


    /* =====================================================
       CALCULATE DAYS UNTIL DUE
    ===================================================== */

    $daysUntilDue = (int)$today
        ->diff($dueDate)
        ->format('%r%a');


    /* =====================================================
       AMC DUE
    ===================================================== */

    if ($daysUntilDue < 0) {

        $amc['due']++;

        continue;
    }


    /* =====================================================
       DUE SOON
       0 TO 7 DAYS
    ===================================================== */

    if ($daysUntilDue <= 7) {

        $maintenanceDueSoon++;
    }


    /* =====================================================
       AMC OK
    ===================================================== */

    $amc['ok']++;
}


/* =========================================================
   AMC TOTAL
========================================================= */

$amcTotal =
    $amc['ok']
    + $amc['due']
    + $amc['pending'];


/* =========================================================
   CALLBACK CHART
   LAST 30 DAYS
========================================================= */

$days = [];
$data = [];

for ($i = 29; $i >= 0; $i--) {

    $d = date(
        'Y-m-d',
        strtotime("-{$i} days")
    );

    $days[] = date(
        'd M',
        strtotime($d)
    );

    $data[$d] = 0;
}


$cbStmt = $conn->prepare("
    SELECT

        DATE(c.created_at) AS d,

        COUNT(*) AS c

    FROM callbacks c

    INNER JOIN assets a
        ON a.id = c.asset_id

    WHERE a.owner_id = ?

      AND c.created_at >= DATE_SUB(
          CURDATE(),
          INTERVAL 30 DAY
      )

    GROUP BY DATE(c.created_at)

    ORDER BY DATE(c.created_at)
");

$cbStmt->bind_param("i", $owner_id);
$cbStmt->execute();

$cbRes = $cbStmt->get_result();


while ($r = $cbRes->fetch_assoc()) {

    if (isset($data[$r['d']])) {

        $data[$r['d']] =
            (int)$r['c'];
    }
}

$dailyCallbacks = array_values($data);


/* =========================================================
   OWNER'S ASSIGNED TECHNICIAN
========================================================= */

$techStmt = $conn->prepare("
    SELECT assigned_technician_id

    FROM users

    WHERE id = ?
");

$techStmt->bind_param("i", $owner_id);
$techStmt->execute();

$techRow =
    $techStmt->get_result()->fetch_assoc();

$tech_id =
    (int)($techRow['assigned_technician_id'] ?? 0);


/* =========================================================
   FORTNIGHTLY PENDING
========================================================= */

$fortnightlyPendingCallbacksStmt = $conn->prepare("
    SELECT MAX(fc.check_date) AS last_check

    FROM fortnightly_checks fc

    WHERE fc.owner_id = ?
");

$fortnightlyPendingCallbacksStmt->bind_param(
    "i",
    $owner_id
);

$fortnightlyPendingCallbacksStmt->execute();

$lastRow =
    $fortnightlyPendingCallbacksStmt
    ->get_result()
    ->fetch_assoc();

$lastCheck =
    $lastRow['last_check'] ?? null;


$fortnightlyPendingCallbacks = 0;


if (!$lastCheck) {

    $fortnightlyPendingCallbacks = 1;

} else {

    try {

        $lastDate = new DateTime(
            $lastCheck,
            $timezone
        );

        $todayDate = new DateTime(
            'today',
            $timezone
        );

        $diffDays =
            (int)$lastDate
            ->diff($todayDate)
            ->format('%a');


        if ($diffDays >= 15) {

            $fortnightlyPendingCallbacks = 1;
        }

    } catch (Exception $e) {

        $fortnightlyPendingCallbacks = 1;
    }
}


/* =========================================================
   FORTNIGHTLY APPROVED TODAY
========================================================= */

$fortnightlyDoneTodayStmt = $conn->prepare("
    SELECT COUNT(*) AS t

    FROM callbacks c

    INNER JOIN assets a
        ON a.id = c.asset_id

    WHERE a.owner_id = ?

      AND c.type = 'fortnightly'

      AND c.status = 'approved'

      AND DATE(c.approved_at) = CURDATE()
");

$fortnightlyDoneTodayStmt->bind_param(
    "i",
    $owner_id
);

$fortnightlyDoneTodayStmt->execute();

$fortnightlyDoneToday =
    (int)$fortnightlyDoneTodayStmt
    ->get_result()
    ->fetch_assoc()['t'];


/* =========================================================
   DISMISS FORTNIGHTLY ALERT
========================================================= */

if (isset($_GET['dismiss_fc_done'])) {

    $_SESSION[
        'fc_done_dismissed_' . $owner_id
    ] = true;

    header("Location: dashboard.php");

    exit;
}


/* =========================================================
   DISMISS CALLBACK ALERT
========================================================= */

if (isset($_GET['dismiss_cb_solved'])) {

    $_SESSION[
        'cb_solved_dismissed_' . $owner_id
    ] = true;

    header("Location: dashboard.php");

    exit;
}


/* =========================================================
   RESET DISMISS STATUS
========================================================= */

if ($fortnightlyPendingCallbacks == 0) {

    unset(
        $_SESSION[
            'fc_done_dismissed_' . $owner_id
        ]
    );
}


/* =========================================================
   CALLBACKS SOLVED TODAY
========================================================= */

$cbSolvedStmt = $conn->prepare("
    SELECT COUNT(*) AS t

    FROM callbacks c

    INNER JOIN assets a
        ON a.id = c.asset_id

    WHERE a.owner_id = ?

      AND c.status = 'approved'

      AND DATE(c.approved_at) = CURDATE()
");

$cbSolvedStmt->bind_param(
    "i",
    $owner_id
);

$cbSolvedStmt->execute();

$callbackSolved =
    (int)$cbSolvedStmt
    ->get_result()
    ->fetch_assoc()['t'];


if ($callbackSolved == 0) {

    unset(
        $_SESSION[
            'cb_solved_dismissed_' . $owner_id
        ]
    );
}


/* =========================================================
   OVERALL STATUS
========================================================= */

$allOk = !(
    $outOfService
    || $underMaintenance
    || ($fortnightlyPendingCallbacks > 0)
);

$hasActiveJobs =
    $underMaintenance > 0;


/* =========================================================
   SAF CONTRACTS — OWNER
   Auto-created after Advance Payment verification.
   Dashboard shows only SAFs pending Owner acceptance.
   Accepted SAFs remain available in the separate SAF Contract page.
========================================================= */

$safContracts = [];

$safStmt = $conn->prepare("
    SELECT
        sc.id,
        sc.psga_no,
        sc.saf_no,
        sc.service_code,
        sc.service_name,
        sc.saf_type,
        sc.activation_date,
        sc.proposed_inspection_date,
        sc.checklist_reference,
        sc.service_output,
        sc.applicable_fee,
        sc.status,
        sc.generated_at,
        sc.owner_accepted_at,
        a.asset_name,
        a.location
    FROM saf_contracts sc
    INNER JOIN assets a
        ON a.id = sc.asset_id
    WHERE sc.owner_id = ?
      AND sc.status = 'Pending Owner Acceptance'
    ORDER BY sc.generated_at DESC, sc.id DESC
");

if ($safStmt) {

    $safStmt->bind_param("i", $owner_id);

    if ($safStmt->execute()) {

        $safResult = $safStmt->get_result();

        while ($safRow = $safResult->fetch_assoc()) {
            $safContracts[] = $safRow;
        }
    }

    $safStmt->close();
}

$pendingSafCount = 0;

foreach ($safContracts as $safRow) {

    if (
        ($safRow['status'] ?? '')
        === 'Pending Owner Acceptance'
    ) {
        $pendingSafCount++;
    }
}

/* =========================================================
   ASSIGNED INSPECTOR DETAILS — OWNER
   Only the requested information is shown.
========================================================= */

$assignedInspectorDetails = [];

$inspectorStmt = $conn->prepare("
    SELECT
        a.asset_name,
        a.location,
        u.name AS inspector_name,
        u.phone AS inspector_phone,
        ia.assigned_at,
        sc.proposed_inspection_date,
        ia.status AS assignment_status,
        ia.started_at,
        ia.submitted_at,
        ia.completed_at
    FROM inspector_assignments ia
    INNER JOIN maintenance_jobs mj
        ON mj.id = ia.job_id
       AND mj.job_type = 'saf_inspection'
    INNER JOIN saf_contracts sc
        ON sc.id = mj.saf_id
    INNER JOIN assets a
        ON a.id = ia.asset_id
       AND a.owner_id = ?
    LEFT JOIN users u
        ON u.id = ia.inspector_id
    WHERE ia.owner_id = ?

      /*
       * OWNER DASHBOARD = ACTIVE / WORKING INSPECTIONS ONLY
       *
       * Keep the assignment visible while the Inspector is:
       *   - Assigned / Pending
       *   - Started / In Progress
       *
       * As soon as the Inspector submits the inspection,
       * it is removed from this dashboard section.
       *
       * Submitted / Pending Owner / Completed / Approved /
       * Closed / Rejected / Cancelled inspections are not shown.
       *
       * submitted_at is also checked independently so an inspection
       * cannot remain visible if the assignment status was not updated.
       */
      AND (
          LOWER(TRIM(COALESCE(ia.status, ''))) IN (
              'assigned',
              'pending',
              'started',
              'in_progress'
          )
      )

      AND (
          ia.submitted_at IS NULL
          OR ia.submitted_at = '0000-00-00 00:00:00'
      )

      AND (
          ia.completed_at IS NULL
          OR ia.completed_at = '0000-00-00 00:00:00'
      )

      AND LOWER(TRIM(COALESCE(mj.status, ''))) IN (
          'assigned',
          'in_progress',
          'started',
          'pending'
      )

    ORDER BY ia.assigned_at DESC, ia.id DESC
");

if ($inspectorStmt) {

    $inspectorStmt->bind_param(
        "ii",
        $owner_id,
        $owner_id
    );

    if ($inspectorStmt->execute()) {

        $inspectorResult = $inspectorStmt->get_result();

        while ($inspectorRow = $inspectorResult->fetch_assoc()) {
            $assignedInspectorDetails[] = $inspectorRow;
        }
    }

    $inspectorStmt->close();
}

/* =========================================================
   IST DATE/TIME FORMATTER
========================================================= */

function ownerDashboardIstDateTime($value, DateTimeZone $timezone) {

    if (empty($value)) {
        return '—';
    }

    try {

        $date = new DateTime(
            $value,
            new DateTimeZone('UTC')
        );

        $date->setTimezone($timezone);

        return $date->format('d-m-Y h:i A');

    } catch (Throwable $e) {

        return '—';
    }
}


/* =========================================================
   HEADER / SIDEBAR
========================================================= */

include('../includes/header.php');
include('../includes/sidebar-owner.php');

?>


<style>

.card {
    border-radius: 14px;
    height: 100%;
}

.icon-lg {
    font-size: 42px;
}

.icon-sm {
    font-size: 26px;
}

.chart-card {
    padding: 18px !important;
}

.chart-card canvas {
    max-height: 190px !important;
}

</style>


<div class="content">


<!-- =====================================================
     FORTNIGHTLY ALERT
===================================================== -->

<?php if ($fortnightlyPendingCallbacks > 0): ?>

<div class="alert alert-warning shadow-sm d-flex justify-content-between align-items-center alert-dismissible fade show">

    <div>

        🕐

        <strong>
            <?= (int)$fortnightlyPendingCallbacks ?>
        </strong>

        Fortnightly check pending —
        submit your fortnightly report.

    </div>

    <button
        type="button"
        class="btn-close"
        data-bs-dismiss="alert">
    </button>

</div>

<?php elseif (
    $fortnightlyDoneToday > 0
    &&
    empty(
        $_SESSION[
            'fc_done_dismissed_' . $owner_id
        ]
    )
): ?>

<div class="alert alert-success shadow-sm d-flex justify-content-between align-items-center alert-dismissible fade show">

    <div>

        ✅

        <strong>
            Fortnightly Check Approved
        </strong>

        — Technician has submitted and owner approved today.

    </div>

    <a
        href="dashboard.php?dismiss_fc_done=1"
        class="btn-close"
        aria-label="Close">
    </a>

</div>

<?php endif; ?>


<!-- =====================================================
     MAINTENANCE DUE SOON
===================================================== -->

<?php if ($maintenanceDueSoon > 0): ?>

<div class="alert alert-warning shadow-sm d-flex justify-content-between align-items-center alert-dismissible fade show">

    <div>

        🛠️

        <strong>
            <?= (int)$maintenanceDueSoon ?>
        </strong>

        Lift(s) maintenance due soon —
        schedule maintenance.

    </div>

    <button
        type="button"
        class="btn-close"
        data-bs-dismiss="alert">
    </button>

</div>

<?php endif; ?>


<!-- =====================================================
     CALLBACK SOLVED
===================================================== -->

<?php if (
    $callbackSolved > 0
    &&
    empty(
        $_SESSION[
            'cb_solved_dismissed_' . $owner_id
        ]
    )
): ?>

<div class="alert alert-success shadow-sm d-flex justify-content-between align-items-center alert-dismissible fade show">

    <div>

        ✅

        <strong>
            <?= (int)$callbackSolved ?>
        </strong>

        Callback(s) resolved and approved today.

    </div>

    <a
        href="dashboard.php?dismiss_cb_solved=1"
        class="btn-close"
        aria-label="Close">
    </a>

</div>

<?php endif; ?>


<!-- =====================================================
     MAINTENANCE APPROVAL
===================================================== -->

<?php if ($pendingApprovals > 0): ?>

<div class="alert alert-warning shadow-sm d-flex justify-content-between align-items-center alert-dismissible fade show">

    <div>

        📝

        <strong>
            <?= (int)$pendingApprovals ?>
        </strong>

        Maintenance Checklist Pending for Approval

    </div>

    <div class="d-flex gap-2">

        <a
            href="approvals.php"
            class="btn btn-sm btn-dark">
            Review Now
        </a>

        <button
            type="button"
            class="btn-close"
            data-bs-dismiss="alert">
        </button>

    </div>

</div>


<?php elseif (!$allOk): ?>


<div class="alert alert-danger shadow-sm alert-dismissible fade show">

    ⚠

    <strong>
        Attention Required:
    </strong>


    <?php if ($outOfService): ?>

        🔴

        <?= (int)$outOfService ?>

        Out of Service

    <?php endif; ?>


    <?php if ($underMaintenance): ?>

        <?php if ($outOfService): ?>
            |
        <?php endif; ?>

        🟠

        <?= (int)$underMaintenance ?>

        Job(s) In Progress


        <?php if ($jobCounts['pending_owner'] > 0): ?>

            <br>

            <small class="text-warning">

                (
                <?= (int)$jobCounts['pending_owner'] ?>

                awaiting your approval

                )

            </small>

        <?php endif; ?>


        <?php if ($jobCounts['in_progress'] > 0): ?>

            <br>

            <small class="text-info">

                (
                <?= (int)$jobCounts['in_progress'] ?>

                in progress by technician

                )

            </small>

        <?php endif; ?>

    <?php endif; ?>


    <button
        type="button"
        class="btn-close"
        data-bs-dismiss="alert">
    </button>

</div>


<?php else: ?>


<div class="alert alert-success shadow-sm alert-dismissible fade show">

    ✅

    <strong>
        All Lifts Active
    </strong>

    <button
        type="button"
        class="btn-close"
        data-bs-dismiss="alert">
    </button>

</div>

<?php endif; ?>


<!-- =====================================================
     SAF CONTRACTS
===================================================== -->

<?php if (!empty($safContracts)): ?>

<div class="card shadow-sm mb-4">

    <div class="card-body">

        <div class="d-flex justify-content-between align-items-center mb-3">

            <div>

                <h5 class="mb-1">

                    <i class="bi bi-file-earmark-text me-2"></i>

                    SAF - Service Activation Form

                </h5>

                <small class="text-muted">
                    Service Activation Forms pending your acceptance.
                </small>

            </div>

            <?php if ($pendingSafCount > 0): ?>

                <span class="badge bg-warning text-dark">
                    <?= (int)$pendingSafCount ?>
                    Pending Acceptance
                </span>

            <?php endif; ?>

        </div>


        <div class="row g-3">

            <?php foreach ($safContracts as $saf): ?>

                <?php

                $safStatus =
                    trim(
                        (string)(
                            $saf['status'] ?? ''
                        )
                    );

                $statusClass = 'bg-warning text-dark';

                ?>

                <div class="col-lg-6">

                    <div
                        class="border rounded-3 p-3 h-100"
                        style="background:#fafbfc;"
                    >

                        <div class="d-flex justify-content-between align-items-start gap-3">

                            <div>

                                <div class="fw-bold">

                                    <i class="bi bi-file-earmark-check me-1"></i>

                                    Service Activation Form

                                </div>

                                <small class="text-muted">

                                    <?= h(
                                        $saf['service_name']
                                        ?: $saf['saf_type']
                                        ?: 'PAT'
                                    ) ?>

                                </small>

                            </div>

                            <span
                                class="badge <?= h($statusClass) ?>"
                            >

                                Pending Owner Acceptance

                            </span>

                        </div>


                        <hr>


                        <div class="row g-2 small">

                            <div class="col-sm-6">

                                <div class="text-muted">
                                    PSGA / Contract No.
                                </div>

                                <strong>
                                    <?= h(
                                        $saf['psga_no']
                                    ) ?>
                                </strong>

                            </div>


                            <div class="col-sm-6">

                                <div class="text-muted">
                                    SAF No.
                                </div>

                                <strong>
                                    <?= h(
                                        $saf['saf_no']
                                    ) ?>
                                </strong>

                            </div>


                            <div class="col-sm-6 mt-2">

                                <div class="text-muted">
                                    Asset
                                </div>

                                <strong>
                                    <?= h(
                                        $saf['asset_name']
                                        ?: '—'
                                    ) ?>
                                </strong>

                                <?php if (
                                    !empty(
                                        $saf['location']
                                    )
                                ): ?>

                                    <div class="text-muted">
                                        <?= h(
                                            $saf['location']
                                        ) ?>
                                    </div>

                                <?php endif; ?>

                            </div>


                            <div class="col-sm-6 mt-2">

                                <div class="text-muted">
                                    Generated
                                </div>

                                <strong>

                                    <?php

                                    if (
                                        !empty(
                                            $saf['generated_at']
                                        )
                                    ) {

                                        try {

                                            $generatedDate =
                                                new DateTime(
                                                    $saf['generated_at'],
                                                    new DateTimeZone('UTC')
                                                );

                                            $generatedDate =
                                                $generatedDate->setTimezone(
                                                    $timezone
                                                );

                                            echo h(
                                                $generatedDate->format(
                                                    'd-m-Y h:i A'
                                                )
                                            );

                                        } catch (Throwable $e) {

                                            echo '—';
                                        }

                                    } else {

                                        echo '—';
                                    }

                                    ?>

                                </strong>

                            </div>

                        </div>


                        <div class="d-flex gap-2 mt-3">

                            <a
                                href="saf-view.php?id=<?= (int)$saf['id'] ?>"
                                class="btn btn-sm btn-primary"
                            >

                                <i class="bi bi-eye me-1"></i>

                                View SAF

                            </a>


                        </div>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    </div>

</div>

<?php endif; ?>


<!-- =====================================================
     ASSIGNED INSPECTOR DETAILS
===================================================== -->

<?php if (!empty($assignedInspectorDetails)): ?>

<div class="card shadow-sm mb-4">

    <div class="card-body">

        <div class="d-flex justify-content-between align-items-center mb-3">

            <div>
                <h5 class="mb-1">
                    <i class="bi bi-person-badge me-2"></i>
                    Assigned Inspector Details
                </h5>

                <small class="text-muted">
                    Active and in-progress inspection assignments only.
                    Submitted inspections are automatically removed from this section.
                    Technician maintenance completion status is not shown here.
                </small>
            </div>

            <span class="badge bg-primary">
                <?= (int)count($assignedInspectorDetails) ?>
                Active Assignment(s)
            </span>

        </div>

        <div class="table-responsive">

            <table class="table table-bordered table-hover align-middle mb-0">

                <thead class="table-light">
                    <tr>
                        <th>Asset Name</th>
                        <th>Location</th>
                        <th>Assigned Inspector Name</th>
                        <th>Inspector Mobile</th>
                        <th>Assignment Date</th>
                        <th>Inspection Date</th>
                        <th>Assignment Status</th>
                        <th>Started</th>
                        <th>Submitted</th>
                        <th>Completed</th>
                    </tr>
                </thead>

                <tbody>

                    <?php foreach ($assignedInspectorDetails as $inspector): ?>

                    <tr>

                        <td>
                            <strong>
                                <?= h($inspector['asset_name'] ?: '—') ?>
                            </strong>
                        </td>

                        <td>
                            <?= h($inspector['location'] ?: '—') ?>
                        </td>

                        <td>
                            <strong>
                                <?= h($inspector['inspector_name'] ?: '—') ?>
                            </strong>
                        </td>

                        <td>
                            <?= h($inspector['inspector_phone'] ?: '—') ?>
                        </td>

                        <td>
                            <?= h(
                                ownerDashboardIstDateTime(
                                    $inspector['assigned_at'] ?? null,
                                    $timezone
                                )
                            ) ?>
                        </td>

                        <td>
                            <?= h(
                                !empty($inspector['proposed_inspection_date'])
                                    ? date(
                                        'd-m-Y',
                                        strtotime(
                                            $inspector['proposed_inspection_date']
                                        )
                                    )
                                    : '—'
                            ) ?>
                        </td>

                        <td>
                            <?php
                            $assignmentStatus =
                                trim(
                                    (string)(
                                        $inspector['assignment_status'] ?? ''
                                    )
                                );

                            $assignmentBadge = 'bg-secondary';

                            if (
                                in_array(
                                    strtolower($assignmentStatus),
                                    ['assigned', 'pending'],
                                    true
                                )
                            ) {
                                $assignmentBadge = 'bg-warning text-dark';
                            } elseif (
                                in_array(
                                    strtolower($assignmentStatus),
                                    ['in_progress', 'started'],
                                    true
                                )
                            ) {
                                $assignmentBadge = 'bg-info text-dark';
                            } elseif (
                                in_array(
                                    strtolower($assignmentStatus),
                                    ['submitted', 'pending_owner'],
                                    true
                                )
                            ) {
                                $assignmentBadge = 'bg-primary';
                            } elseif (
                                in_array(
                                    strtolower($assignmentStatus),
                                    ['completed', 'closed', 'approved'],
                                    true
                                )
                            ) {
                                $assignmentBadge = 'bg-success';
                            }
                            ?>

                            <span class="badge <?= h($assignmentBadge) ?>">
                                <?= h($assignmentStatus ?: '—') ?>
                            </span>
                        </td>

                        <td>
                            <?= h(
                                ownerDashboardIstDateTime(
                                    $inspector['started_at'] ?? null,
                                    $timezone
                                )
                            ) ?>
                        </td>

                        <td>
                            <?= h(
                                ownerDashboardIstDateTime(
                                    $inspector['submitted_at'] ?? null,
                                    $timezone
                                )
                            ) ?>
                        </td>

                        <td>
                            <?= h(
                                ownerDashboardIstDateTime(
                                    $inspector['completed_at'] ?? null,
                                    $timezone
                                )
                            ) ?>
                        </td>

                    </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        </div>

        <div class="mt-2 small text-muted">
            <i class="bi bi-info-circle me-1"></i>
            This section shows only inspections that are currently assigned or in progress.
            Once an inspection is submitted, it is automatically removed from this dashboard.
        </div>

    </div>

</div>

<?php endif; ?>


<!-- =====================================================
     TOP CARDS
===================================================== -->

<div class="row g-4 mb-4">


    <!-- ALL LIFTS -->

    <div class="col-md-4">

        <div class="card shadow p-4 text-center">

            <div class="icon-lg">

                <?= $allOk
                    ? '✅'
                    : '⚠️'
                ?>

            </div>

            <h5 class="mt-2">
                All Lifts
            </h5>

            <strong
                class="<?= $allOk
                    ? 'text-success'
                    : 'text-danger'
                ?>">

                <?= $allOk
                    ? 'All Lifts Active'
                    : 'Issues Reported'
                ?>

            </strong>

        </div>

    </div>


    <!-- CALLBACK CHART -->

    <div class="col-md-4">

        <div class="card shadow chart-card">

            <h6 class="text-center mb-2">
                Callbacks (Last 30 Days)
            </h6>

            <canvas id="callbackChart"></canvas>

        </div>

    </div>


    <!-- AMC STATUS -->

    <div class="col-md-4">

        <div
            class="card shadow chart-card text-center"
            style="background:#f8f9fa;">

            <h6 class="mb-2">
                AMC Status
            </h6>

            <canvas id="amcDonut"></canvas>

        </div>

    </div>

</div>


<!-- =====================================================
     COUNTER CARDS
===================================================== -->

<div class="row g-4 mb-4">


    <!-- TOTAL LIFTS -->

    <div class="col-md-3">

        <div class="card shadow p-4 text-center">

            <div class="icon-sm">
                🏢
            </div>

            <h3>
                <?= (int)$assets ?>
            </h3>

            <small>
                Total Lifts
            </small>

        </div>

    </div>


    <!-- ACTIVE -->

    <div class="col-md-3">

        <div class="card shadow p-4 text-center">

            <div class="icon-sm">
                ✅
            </div>

            <h3>
                <?= (int)$activeLifts ?>
            </h3>

            <small>
                Active
            </small>

        </div>

    </div>


    <!-- UNDER MAINTENANCE -->

    <div class="col-md-3">

        <div class="card shadow p-4 text-center">

            <div class="icon-sm">
                🛠️
            </div>

            <h3>
                <?= (int)$underMaintenance ?>
            </h3>

            <small>
                Under Maintenance
            </small>

        </div>

    </div>


    <!-- OUT OF SERVICE -->

    <div class="col-md-3">

        <div class="card shadow p-4 text-center">

            <div class="icon-sm">
                ⛔
            </div>

            <h3>
                <?= (int)$outOfService ?>
            </h3>

            <small>
                Out of Service
            </small>

        </div>

    </div>


    <!-- FORTNIGHTLY -->

    <div class="col-md-3">

        <div class="card shadow p-4 text-center">

            <div class="icon-sm">
                🗓️
            </div>

            <h3>
                <?= (int)$fortnightlyPendingCallbacks ?>
            </h3>

            <small>
                Fortnightly Pending (Not OK)
            </small>

        </div>

    </div>


</div>


</div>


<?php include('../includes/footer.php'); ?>


<script>

/* =========================================================
   CALLBACK CHART
========================================================= */

new Chart(
    document.getElementById('callbackChart'),
    {

        type: 'bar',

        data: {

            labels:
                <?= json_encode($days) ?>,

            datasets: [

                {

                    data:
                        <?= json_encode($dailyCallbacks) ?>,

                    backgroundColor: '#0d6efd',

                    borderRadius: 6

                }

            ]

        },

        options: {

            plugins: {

                legend: {
                    display: false
                }

            },

            scales: {

                y: {

                    beginAtZero: true,

                    ticks: {
                        precision: 0
                    }

                }

            }

        }

    }
);


/* =========================================================
   AMC DATA
========================================================= */

const amcData = {

    ok:
        <?= (int)$amc['ok'] ?>,

    due:
        <?= (int)$amc['due'] ?>,

    pending:
        <?= (int)$amc['pending'] ?>

};


/* =========================================================
   AMC TOTAL
========================================================= */

const amcTotal =
    amcData.ok
    +
    amcData.due
    +
    amcData.pending;


/* =========================================================
   AMC DONUT
========================================================= */

const amcCanvas =
    document.getElementById('amcDonut');


if (amcCanvas) {

    new Chart(
        amcCanvas,
        {

            type: 'doughnut',

            data: {

                labels:
                    amcTotal > 0
                        ? [
                            'AMC OK',
                            'AMC Due',
                            'In Progress'
                        ]
                        : [
                            'No Data'
                        ],


                datasets: [

                    {

                        data:
                            amcTotal > 0
                                ? [
                                    amcData.ok,
                                    amcData.due,
                                    amcData.pending
                                ]
                                : [
                                    1
                                ],


                        backgroundColor:
                            amcTotal > 0
                                ? [
                                    '#28a745',
                                    '#dc3545',
                                    '#ffc107'
                                ]
                                : [
                                    '#e0e0e0'
                                ],


                        borderColor: '#ffffff',

                        borderWidth:
                            amcTotal > 0
                                ? 2
                                : 0

                    }

                ]

            },


            options: {

                responsive: true,

                maintainAspectRatio: true,

                cutout: '70%',


                layout: {

                    padding: {

                        bottom: 45

                    }

                },


                plugins: {

                    legend: {

                        display: false

                    },


                    tooltip: {

                        enabled: true

                    }

                }

            },


            plugins: [

                {

                    id: 'amcSummary',


                    afterDraw(chart) {

                        const ctx =
                            chart.ctx;

                        const width =
                            chart.width;

                        const height =
                            chart.height;


                        ctx.save();


                        ctx.textAlign =
                            'center';


                        ctx.font =
                            '12px Arial';


                        /*
                         * AMC OK
                         */

                        ctx.fillStyle =
                            '#28a745';

                        ctx.fillText(
                            `AMC OK (${amcData.ok})`,
                            width / 2 - 90,
                            height - 30
                        );


                        /*
                         * AMC DUE
                         */

                        ctx.fillStyle =
                            '#dc3545';

                        ctx.fillText(
                            `AMC Due (${amcData.due})`,
                            width / 2,
                            height - 30
                        );


                        /*
                         * IN PROGRESS
                         */

                        ctx.fillStyle =
                            '#ffc107';

                        ctx.fillText(
                            `In Progress (${amcData.pending})`,
                            width / 2 + 90,
                            height - 30
                        );


                        /*
                         * TOTAL
                         */

                        ctx.font =
                            'bold 13px Arial';

                        ctx.fillStyle =
                            '#6c757d';

                        ctx.fillText(
                            `Total: ${amcTotal}`,
                            width / 2,
                            height - 10
                        );


                        ctx.restore();

                    }

                }

            ]

        }

    );

}

</script>