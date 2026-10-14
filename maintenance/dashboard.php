<?php

include('../config/db.php');
include('../config/auth.php');
require_once('../config/security.php');

require_role('technician');

$tech_id = (int)$_SESSION['uid'];


/* =========================================================
   TIMEZONE
========================================================= */

$IST = new DateTimeZone('Asia/Kolkata');
$UTC = new DateTimeZone('UTC');

/*
 * Keep PHP's default timezone in IST so any new DateTime()
 * created without an explicit timezone uses India Standard Time.
 */
date_default_timezone_set('Asia/Kolkata');


/* =========================================================
   DATE SETTINGS
========================================================= */

$todayIST = new DateTime('now', $IST);


/* =========================================================
   DATE / TIME DISPLAY
========================================================= */

/*
 * Database DATETIME/TIMESTAMP values are stored in UTC.
 * Convert them to IST only when displaying them.
 */
function technicianDashboardDateTime($dateTime)
{
    global $IST, $UTC;

    if (empty($dateTime)) {
        return '—';
    }

    try {

        $dt = new DateTime(
            $dateTime,
            $UTC
        );

        $dt->setTimezone($IST);

        return
            '<div class="dashboard-date">'
            . htmlspecialchars(
                $dt->format('d M Y')
            )
            . '</div>'
            .
            '<div class="dashboard-time">'
            . htmlspecialchars(
                $dt->format('h:i A')
            )
            . ' IST'
            . '</div>';

    } catch (Exception $e) {

        return htmlspecialchars(
            $dateTime
        );
    }
}


/* =========================================================
   MAINTENANCE DATE DISPLAY
========================================================= */

/*
 * maintenance_date is a DATE field (Y-m-d). It has no time
 * component, so no artificial 12:00 AM time is displayed.
 * If a datetime is supplied, it is converted from UTC to IST.
 */
function technicianDashboardMaintenanceDate($dateTime)
{
    global $IST, $UTC;

    if (empty($dateTime)) {
        return null;
    }

    try {

        $value = trim((string)$dateTime);

        /*
         * DATE only: keep the calendar date exactly as stored.
         */
        if (
            preg_match(
                '/^\\d{4}-\\d{2}-\\d{2}$/',
                $value
            )
        ) {
            return new DateTime(
                $value . ' 00:00:00',
                $IST
            );
        }

        /*
         * DATETIME/TIMESTAMP: convert UTC -> IST.
         */
        $dt = new DateTime(
            $value,
            $UTC
        );

        $dt->setTimezone($IST);

        return $dt;

    } catch (Exception $e) {

        return null;
    }
}


/* =========================================================
   MAINTENANCE
========================================================= */

$maintenanceRows = [];
$addedAssets = [];
$activeMaintenanceCount = 0;


/* =========================================================
   GET ACTIVE MAINTENANCE REPORTS
========================================================= */

$assignedStmt = $conn->prepare("
    SELECT
        mj.id AS job_id,
        mj.asset_id,
        mj.status AS job_status,
        mj.created_at,

        a.asset_name,
        a.location,
        a.maintenance_interval,

        (
            SELECT MAX(mr.maintenance_date)
            FROM maintenance_records mr
            WHERE mr.asset_id = a.id
              AND LOWER(TRIM(COALESCE(mr.status, ''))) = 'approved'
        ) AS last_maintenance

    FROM maintenance_jobs mj

    INNER JOIN assets a
        ON a.id = mj.asset_id

    WHERE mj.technician_id = ?

      AND mj.status IN (
          'assigned',
          'in_progress'
      )

      AND IFNULL(
          mj.job_type,
          ''
      ) != 'fortnightly'

    ORDER BY
        mj.created_at DESC
");


if (!$assignedStmt) {

    die("Maintenance query error: "
        . htmlspecialchars(
            $conn->error
        ));
}


$assignedStmt->bind_param(
    "i",
    $tech_id
);

$assignedStmt->execute();

$assignedResult =
    $assignedStmt->get_result();


while (
    $row = $assignedResult->fetch_assoc()
) {

    $activeMaintenanceCount++;

    $assetId =
        (int)$row['asset_id'];


    /*
     * Prevent duplicate assets.
     */

    if (
        isset(
            $addedAssets[$assetId]
        )
    ) {

        continue;
    }


    $lastMaint = null;
    $nextDue = null;


    /* =====================================================
       LAST MAINTENANCE
    ====================================================== */

    if (
        !empty($row['last_maintenance'])
    ) {

        try {

            /*
             * Convert maintenance date/time correctly:
             * - DATE values remain the same calendar date.
             * - DATETIME/TIMESTAMP values are converted UTC -> IST.
             */
            $lastMaint = technicianDashboardMaintenanceDate(
                $row['last_maintenance']
            );


            $interval =
                (int)$row['maintenance_interval'];


            if (
                $interval > 0
            ) {

                $nextDue =
                    clone $lastMaint;

                $nextDue->modify(
                    "+{$interval} days"
                );
            }
        } catch (
            Exception $e
        ) {

            $lastMaint = null;
            $nextDue = null;
        }
    }


    $addedAssets[$assetId] = true;


    $maintenanceRows[] = [

        'type' =>
        'maintenance',

        'job_id' =>
        (int)$row['job_id'],

        'asset_id' =>
        $assetId,

        'asset_name' =>
        $row['asset_name'],

        'location' =>
        $row['location'],

        'last_maintenance' =>
        $lastMaint,

        'next_due' =>
        $nextDue,

        'job_status' =>
        $row['job_status']

    ];
}


/* =========================================================
   SORT BY NEXT DUE
========================================================= */

usort(
    $maintenanceRows,
    function (
        $a,
        $b
    ) {

        if (
            !$a['next_due'] &&
            !$b['next_due']
        ) {

            return 0;
        }


        if (
            !$a['next_due']
        ) {

            return 1;
        }


        if (
            !$b['next_due']
        ) {

            return -1;
        }


        return
            $a['next_due']->getTimestamp()
            -
            $b['next_due']->getTimestamp();
    }
);


$hasActiveMaintenance =
    ($activeMaintenanceCount > 0);


/* =========================================================
   MAINTENANCE SUMMARY COUNTS
========================================================= */


/* =========================================================
   WAITING OWNER APPROVAL
========================================================= */

$c2 = $conn->prepare("
    SELECT COUNT(*) AS t
    FROM maintenance_jobs
    WHERE technician_id = ?
      AND status = 'pending_owner'
");


if (!$c2) {

    die("Pending approval query error: "
        . htmlspecialchars(
            $conn->error
        ));
}


$c2->bind_param(
    "i",
    $tech_id
);

$c2->execute();


$pendingOwner =
    (int)$c2
        ->get_result()
        ->fetch_assoc()['t'];


/* =========================================================
   APPROVED
========================================================= */

$c3 = $conn->prepare("
    SELECT COUNT(*) AS t
    FROM maintenance_jobs
    WHERE technician_id = ?
      AND status = 'approved'
");


if (!$c3) {

    die("Approved query error: "
        . htmlspecialchars(
            $conn->error
        ));
}


$c3->bind_param(
    "i",
    $tech_id
);

$c3->execute();


$approved =
    (int)$c3
        ->get_result()
        ->fetch_assoc()['t'];


/* =========================================================
   REJECTED
========================================================= */

$c4 = $conn->prepare("
    SELECT COUNT(*) AS t
    FROM maintenance_jobs
    WHERE technician_id = ?
      AND status = 'rejected'
");


if (!$c4) {

    die("Rejected query error: "
        . htmlspecialchars(
            $conn->error
        ));
}


$c4->bind_param(
    "i",
    $tech_id
);

$c4->execute();


$rejected =
    (int)$c4
        ->get_result()
        ->fetch_assoc()['t'];


/* =========================================================
   CALLBACK SUMMARY COUNTS
========================================================= */


/*
   CALLBACK WAITING APPROVAL

   status = closed
   means technician has submitted the report
   and callback is waiting for approval.
*/

$cbWaitingStmt = $conn->prepare("
    SELECT COUNT(*) AS t
    FROM callbacks
    WHERE technician_id = ?
      AND status = 'closed'
");


if (!$cbWaitingStmt) {

    die("Callback waiting approval query error: "
        . htmlspecialchars(
            $conn->error
        ));
}


$cbWaitingStmt->bind_param(
    "i",
    $tech_id
);

$cbWaitingStmt->execute();


$callbackWaitingApproval =
    (int)$cbWaitingStmt
        ->get_result()
        ->fetch_assoc()['t'];


/* =========================================================
   CALLBACK APPROVED
========================================================= */

$cbApprovedStmt = $conn->prepare("
    SELECT COUNT(*) AS t
    FROM callbacks
    WHERE technician_id = ?
      AND status = 'approved'
");


if (!$cbApprovedStmt) {

    die("Callback approved query error: "
        . htmlspecialchars(
            $conn->error
        ));
}


$cbApprovedStmt->bind_param(
    "i",
    $tech_id
);

$cbApprovedStmt->execute();


$callbackApproved =
    (int)$cbApprovedStmt
        ->get_result()
        ->fetch_assoc()['t'];


/* =========================================================
   CALLBACK REJECTED
========================================================= */

$cbRejectedStmt = $conn->prepare("
    SELECT COUNT(*) AS t
    FROM callbacks
    WHERE technician_id = ?
      AND technician_response = 'rejected'
");


if (!$cbRejectedStmt) {

    die("Callback rejected query error: "
        . htmlspecialchars(
            $conn->error
        ));
}


$cbRejectedStmt->bind_param(
    "i",
    $tech_id
);

$cbRejectedStmt->execute();


$callbackRejected =
    (int)$cbRejectedStmt
        ->get_result()
        ->fetch_assoc()['t'];


/* =========================================================
   CALLBACKS WAITING FOR TECHNICIAN
========================================================= */

$pendingCallbackStmt = $conn->prepare("
    SELECT
        c.id,
        c.callback_number,
        c.issue,
        c.status,
        c.created_at,
        c.technician_response,

        COALESCE(
            a.asset_name,
            'General Check'
        ) AS asset_name,

        a.location,

        IF(
            c.type = 'fortnightly',
            1,
            0
        ) AS is_fortnightly

    FROM callbacks c

    LEFT JOIN assets a
        ON a.id = c.asset_id

    WHERE c.technician_id = ?

      AND c.status = 'open'

      AND (
          c.technician_response IS NULL
          OR c.technician_response = ''
      )

    ORDER BY
        c.created_at DESC
");


if (!$pendingCallbackStmt) {

    die("Pending callback query error: "
        . htmlspecialchars(
            $conn->error
        ));
}


$pendingCallbackStmt->bind_param(
    "i",
    $tech_id
);

$pendingCallbackStmt->execute();


$pendingCallbacks =
    $pendingCallbackStmt->get_result();


$pendingCallbackCount =
    $pendingCallbacks->num_rows;


/* =========================================================
   OPEN / IN-PROGRESS CALLBACKS
========================================================= */

$ocStmt = $conn->prepare("
    SELECT

        c.id,

        c.callback_number,

        c.issue,

        c.status,

        c.created_at,

        c.technician_response,

        COALESCE(
            a.asset_name,
            'General Check'
        ) AS asset_name,

        a.location,

        IF(
            c.type = 'fortnightly',
            1,
            0
        ) AS is_fortnightly

    FROM callbacks c

    LEFT JOIN assets a
        ON a.id = c.asset_id

    WHERE c.technician_id = ?

      AND c.technician_response = 'accepted'

      AND c.status IN (
          'open',
          'in_progress'
      )

    ORDER BY
        is_fortnightly DESC,
        c.created_at DESC
");


if (!$ocStmt) {

    die("Open callback query error: "
        . htmlspecialchars(
            $conn->error
        ));
}


$ocStmt->bind_param(
    "i",
    $tech_id
);

$ocStmt->execute();


$openCallbacks =
    $ocStmt->get_result();


$openCallbackCount =
    $openCallbacks->num_rows;


/* =========================================================
   FORTNIGHTLY COUNT
========================================================= */

$fqCount = $conn->prepare("
    SELECT COUNT(*) AS t

    FROM callbacks

    WHERE technician_id = ?

      AND status IN (
          'open',
          'in_progress'
      )

      AND type = 'fortnightly'

      AND technician_response = 'accepted'
");


if (!$fqCount) {

    die("Fortnightly query error: "
        . htmlspecialchars(
            $conn->error
        ));
}


$fqCount->bind_param(
    "i",
    $tech_id
);

$fqCount->execute();


$fortnightlyCount =
    (int)$fqCount
        ->get_result()
        ->fetch_assoc()['t'];




/* =========================================================
   HEADER
========================================================= */

include('../includes/header.php');
include('../includes/sidebar-tech.php');

?>


<style>
    /* =========================================================
   DASHBOARD DATE / TIME
========================================================= */

    .dashboard-date {

        font-weight: 600;

        white-space: nowrap;

    }


    .dashboard-time {

        color: #6c757d;

        font-size: 13px;

        margin-top: 2px;

        white-space: nowrap;

    }
</style>


<div class="content">


    <h3 class="mb-4">
        Technician/AMC Dashboard
    </h3>


    <!-- =====================================================
         SUCCESS
    ====================================================== -->

    <?php if (!empty($_SESSION['success'])): ?>

        <div class="alert alert-success alert-dismissible fade show">

            <?= htmlspecialchars(
                $_SESSION['success']
            ); ?>

            <?php
            unset($_SESSION['success']);
            ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert">
            </button>

        </div>

    <?php endif; ?>


    <!-- =====================================================
         ERROR
    ====================================================== -->

    <?php if (!empty($_SESSION['error'])): ?>

        <div class="alert alert-danger alert-dismissible fade show">

            <?= htmlspecialchars(
                $_SESSION['error']
            ); ?>

            <?php
            unset($_SESSION['error']);
            ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert">
            </button>

        </div>

    <?php endif; ?>


    <!-- =====================================================
         NEW CALLBACK REQUEST
    ====================================================== -->

    <?php if ($pendingCallbackCount > 0): ?>


        <div class="alert alert-warning shadow-sm">

            ⚠️

            <strong>
                <?= $pendingCallbackCount ?>
            </strong>

            callback request(s) are waiting for your response.

        </div>


        <h5 class="mb-3">
            🔔 Callback Requests
        </h5>


        <div class="table-responsive">

            <table class="table table-hover bg-white shadow mb-4">

                <thead class="table-dark">

                    <tr>

                        <th>Callback #</th>

                        <th>Lift</th>

                        <th>Location</th>

                        <th>Issue</th>

                        <th>Received</th>

                        <th>Action</th>

                    </tr>

                </thead>


                <tbody>


                    <?php
                    while (
                        $cb =
                        $pendingCallbacks->fetch_assoc()
                    ):
                    ?>


                        <tr>


                            <!-- CALLBACK NUMBER -->

                            <td>

                                <strong>

                                    <?= htmlspecialchars(
                                        $cb['callback_number']
                                    ) ?>

                                </strong>

                            </td>


                            <!-- LIFT -->

                            <td>

                                <?= htmlspecialchars(
                                    $cb['asset_name']
                                ) ?>

                            </td>


                            <!-- LOCATION -->

                            <td>

                                <?= htmlspecialchars(
                                    $cb['location'] ?? '—'
                                ) ?>

                            </td>


                            <!-- ISSUE -->

                            <td>

                                <?= htmlspecialchars(
                                    $cb['issue']
                                ) ?>

                            </td>


                            <!-- RECEIVED -->

                            <td>

                                <?= technicianDashboardDateTime(
                                    $cb['created_at']
                                ) ?>

                            </td>


                            <!-- ACTION -->

                            <td>

                                <div class="d-flex gap-2">


                                    <!-- ACCEPT -->

                                    <form
                                        method="POST"
                                        action="callback-response.php"
                                        style="display:inline;">

                                        <?= csrfField() ?>


                                        <input
                                            type="hidden"
                                            name="callback_id"
                                            value="<?= (int)$cb['id'] ?>">


                                        <input
                                            type="hidden"
                                            name="response"
                                            value="accepted">


                                        <button
                                            type="submit"
                                            class="btn btn-success btn-sm"
                                            onclick="return confirm('Accept this callback request?');">

                                            ✓ Accept

                                        </button>

                                    </form>


                                    <!-- REJECT BUTTON -->

                                    <button
                                        type="button"
                                        class="btn btn-danger btn-sm"
                                        data-bs-toggle="modal"
                                        data-bs-target="#rejectModal<?= (int)$cb['id'] ?>">

                                        ✕ Reject

                                    </button>


                                </div>

                            </td>


                        </tr>


                        <!-- =====================================================
                             REJECT MODAL
                        ====================================================== -->

                        <div
                            class="modal fade"
                            id="rejectModal<?= (int)$cb['id'] ?>"
                            tabindex="-1"
                            aria-hidden="true">


                            <div class="modal-dialog">


                                <div class="modal-content">


                                    <form
                                        method="POST"
                                        action="callback-response.php">


                                        <?= csrfField() ?>


                                        <input
                                            type="hidden"
                                            name="callback_id"
                                            value="<?= (int)$cb['id'] ?>">


                                        <input
                                            type="hidden"
                                            name="response"
                                            value="rejected">


                                        <div class="modal-header">


                                            <h5 class="modal-title">

                                                Reject Callback

                                            </h5>


                                            <button
                                                type="button"
                                                class="btn-close"
                                                data-bs-dismiss="modal">
                                            </button>


                                        </div>


                                        <div class="modal-body">


                                            <p>

                                                Callback:

                                                <strong>

                                                    <?= htmlspecialchars(
                                                        $cb['callback_number']
                                                    ) ?>

                                                </strong>

                                            </p>


                                            <label
                                                class="form-label fw-bold">

                                                Reason for rejection

                                            </label>


                                            <select
                                                name="reject_reason"
                                                class="form-select"
                                                required>


                                                <option value="">

                                                    -- Select Reason --

                                                </option>


                                                <option value="Already assigned to another job">

                                                    Already assigned to another job

                                                </option>


                                                <option value="Not available at this time">

                                                    Not available at this time

                                                </option>


                                                <option value="Location too far">

                                                    Location too far

                                                </option>


                                                <option value="Emergency work assigned">

                                                    Emergency work assigned

                                                </option>


                                                <option value="Leave / unavailable">

                                                    Leave / unavailable

                                                </option>


                                                <option value="Insufficient time to attend">

                                                    Insufficient time to attend

                                                </option>


                                                <option value="Issue outside my responsibility">

                                                    Issue outside my responsibility

                                                </option>


                                                <option value="Other">

                                                    Other

                                                </option>


                                            </select>


                                        </div>


                                        <div class="modal-footer">


                                            <button
                                                type="button"
                                                class="btn btn-secondary"
                                                data-bs-dismiss="modal">

                                                Cancel

                                            </button>


                                            <button
                                                type="submit"
                                                class="btn btn-danger">

                                                Reject Callback

                                            </button>


                                        </div>


                                    </form>


                                </div>

                            </div>

                        </div>


                    <?php endwhile; ?>


                </tbody>

            </table>

        </div>


    <?php else: ?>


        <div class="alert alert-success shadow-sm mb-4">

            ✅ No open callback requests assigned to you.

        </div>


    <?php endif; ?>


    <!-- =====================================================
         FORTNIGHTLY ALERT
    ====================================================== -->

    <?php if ($fortnightlyCount > 0): ?>

        <div class="alert alert-info shadow-sm">

            🗓️

            <strong>
                <?= $fortnightlyCount ?>
            </strong>

            fortnightly check issue(s) assigned to you!

        </div>

    <?php endif; ?>



    <!-- =====================================================
         MAINTENANCE
    ====================================================== -->

    <?php if ($hasActiveMaintenance): ?>


        <h5 class="mb-3">

            🛠️ Maintenance Due
            (Overdue or Due Soon)

        </h5>


        <div class="table-responsive">

            <table class="table table-hover bg-white shadow mb-4">


                <thead class="table-dark">

                    <tr>

                        <th>Assets Name</th>

                        <th>Location</th>

                        <th>Last Maintenance</th>

                        <th>Next Due</th>

                        <th>Status</th>

                        <th>Action</th>

                    </tr>

                </thead>


                <tbody>


                    <?php
                    foreach (
                        $maintenanceRows
                        as $lift
                    ):
                    ?>


                        <?php

                        $lastMaint =
                            $lift['last_maintenance'];

                        $nextDue =
                            $lift['next_due'];

                        ?>


                        <tr>


                            <!-- LIFT -->

                            <td>

                                <strong>

                                    <?= htmlspecialchars(
                                        $lift['asset_name']
                                    ) ?>

                                </strong>

                            </td>


                            <!-- LOCATION -->

                            <td>

                                <?= htmlspecialchars(
                                    $lift['location'] ?? '—'
                                ) ?>

                            </td>


                            <!-- LAST MAINTENANCE -->

                            <td>

                                <?php if ($lastMaint): ?>


                                    <?php

                                    $lastMaintDisplay =
                                        clone $lastMaint;

                                    $lastMaintDisplay
                                        ->setTimezone(
                                            $IST
                                        );

                                    ?>


                                    <div class="dashboard-date">

                                        <?= htmlspecialchars(
                                            $lastMaintDisplay
                                                ->format(
                                                    'd M Y'
                                                )
                                        ) ?>

                                    </div>


                                <?php else: ?>


                                    <span class="text-muted">

                                        No Previous Record

                                    </span>


                                <?php endif; ?>

                            </td>


                            <!-- NEXT DUE -->

                            <td>

                                <?php if ($nextDue): ?>


                                    <?php

                                    $nextDueDisplay =
                                        clone $nextDue;

                                    $nextDueDisplay
                                        ->setTimezone(
                                            $IST
                                        );

                                    ?>


                                    <div class="dashboard-date">

                                        <?= htmlspecialchars(
                                            $nextDueDisplay
                                                ->format(
                                                    'd M Y'
                                                )
                                        ) ?>

                                    </div>


                                <?php else: ?>


                                    <span class="text-muted">

                                        No Due Date

                                    </span>


                                <?php endif; ?>

                            </td>


                            <!-- STATUS -->

                            <td>


                                <?php if ($nextDue): ?>


                                    <?php

                                    /*
                                     * Current date is IST.
                                     */
                                    $todayForDue =
                                        new DateTime(
                                            'now',
                                            $IST
                                        );


                                    /*
                                     * Next due is already calculated in IST.
                                     */
                                    $nextDueCheck =
                                        clone $nextDue;

                                    $nextDueCheck
                                        ->setTimezone(
                                            $IST
                                        );


                                    /*
                                     * Compare dates only.
                                     */
                                    $todayDate =
                                        new DateTime(
                                            $todayForDue
                                                ->format(
                                                    'Y-m-d'
                                                ),
                                            $IST
                                        );


                                    $dueDate =
                                        new DateTime(
                                            $nextDueCheck
                                                ->format(
                                                    'Y-m-d'
                                                ),
                                            $IST
                                        );


                                    $daysUntilDue =
                                        (int)$todayDate
                                            ->diff(
                                                $dueDate
                                            )
                                            ->format(
                                                '%r%a'
                                            );

                                    ?>


                                    <?php if (
                                        $daysUntilDue < 0
                                    ): ?>


                                        <span
                                            class="badge bg-danger">

                                            Overdue

                                        </span>


                                    <?php elseif (
                                        $daysUntilDue === 0
                                    ): ?>


                                        <span
                                            class="badge bg-danger">

                                            Due Today

                                        </span>


                                    <?php elseif (
                                        $daysUntilDue <= 7
                                    ): ?>


                                        <span
                                            class="badge bg-warning text-dark">

                                            Due Soon -
                                            <?= $daysUntilDue ?>
                                            days

                                        </span>


                                    <?php else: ?>


                                        <span
                                            class="badge bg-info text-dark">

                                            Due in
                                            <?= $daysUntilDue ?>
                                            days

                                        </span>


                                    <?php endif; ?>


                                <?php else: ?>


                                    <span
                                        class="badge bg-secondary">

                                        No Due Date

                                    </span>


                                <?php endif; ?>


                            </td>


                            <!-- ACTION -->

                            <td>

                                <a
                                    href="assigned_lifts.php"
                                    class="btn btn-sm btn-primary">

                                    View Details

                                </a>

                            </td>


                        </tr>


                    <?php endforeach; ?>


                </tbody>

            </table>

        </div>


    <?php else: ?>


        <div class="alert alert-success shadow-sm mb-4">

            ✅ No open report maintenance assigned to you.

        </div>


    <?php endif; ?>


    <!-- =====================================================
         MAINTENANCE SUMMARY CARDS
    ====================================================== -->

    <h5 class="mb-3">

        🛠️ Maintenance Reports

    </h5>


    <div class="row g-4 mb-4">


        <!-- WAITING APPROVAL -->

        <div class="col-md-4">


            <div class="card shadow p-4 text-center">


                <h2>

                    <?= $pendingOwner ?>

                </h2>


                <p class="text-warning fw-bold mb-0">

                    Waiting Approval

                </p>


            </div>

        </div>


        <!-- APPROVED -->

        <div class="col-md-4">


            <div class="card shadow p-4 text-center">


                <h2>

                    <?= $approved ?>

                </h2>


                <p class="text-success fw-bold mb-0">

                    Approved

                </p>


            </div>

        </div>


        <!-- REJECTED -->

        <div class="col-md-4">


            <div class="card shadow p-4 text-center">


                <h2>

                    <?= $rejected ?>

                </h2>


                <p class="text-danger fw-bold mb-0">

                    Rejected

                </p>


            </div>

        </div>


    </div>


    <!-- =====================================================
         CALLBACK SUMMARY CARDS
    ====================================================== -->

    <h5 class="mb-3">

        📞 Callback Reports

    </h5>


    <div class="row g-4 mb-4">


        <!-- WAITING APPROVAL -->

        <div class="col-md-4">


            <div class="card shadow p-4 text-center">


                <h2>

                    <?= $callbackWaitingApproval ?>

                </h2>


                <p class="text-warning fw-bold mb-0">

                    Waiting Approval

                </p>


            </div>

        </div>


        <!-- APPROVED -->

        <div class="col-md-4">


            <div class="card shadow p-4 text-center">


                <h2>

                    <?= $callbackApproved ?>

                </h2>


                <p class="text-success fw-bold mb-0">

                    Approved

                </p>


            </div>

        </div>


        <!-- REJECTED -->

        <div class="col-md-4">


            <div class="card shadow p-4 text-center">


                <h2>

                    <?= $callbackRejected ?>

                </h2>


                <p class="text-danger fw-bold mb-0">

                    Rejected

                </p>


            </div>

        </div>


    </div>


    <!-- =====================================================
         ACCEPTED / IN PROGRESS CALLBACKS
    ====================================================== -->

    <?php if ($openCallbackCount > 0): ?>


        <h5 class="mb-3">

            🔔 Accepted Callbacks

        </h5>


        <div class="table-responsive">


            <table class="table table-hover bg-white shadow mb-4">


                <thead class="table-dark">


                    <tr>

                        <th>Callback #</th>

                        <th>Lift</th>

                        <th>Issue</th>

                        <th>Type</th>

                        <th>Status</th>

                        <th>Received</th>

                        <th>Action</th>

                    </tr>


                </thead>


                <tbody>


                    <?php
                    while (
                        $cb =
                        $openCallbacks->fetch_assoc()
                    ):
                    ?>


                        <tr>


                            <!-- CALLBACK -->

                            <td>

                                <strong>

                                    <?= htmlspecialchars(
                                        $cb['callback_number']
                                    ) ?>

                                </strong>

                            </td>


                            <!-- LIFT -->

                            <td>

                                <?= htmlspecialchars(
                                    $cb['asset_name']
                                ) ?>

                            </td>


                            <!-- ISSUE -->

                            <td>

                                <?= htmlspecialchars(
                                    $cb['issue']
                                ) ?>

                            </td>


                            <!-- TYPE -->

                            <td>


                                <?php if (
                                    $cb['is_fortnightly']
                                ): ?>


                                    <span
                                        class="badge bg-info text-dark">

                                        Fortnightly

                                    </span>


                                <?php else: ?>


                                    <span
                                        class="badge bg-secondary">

                                        Callback

                                    </span>


                                <?php endif; ?>


                            </td>


                            <!-- STATUS -->

                            <td>


                                <span
                                    class="badge bg-warning text-dark">

                                    In Progress

                                </span>


                            </td>


                            <!-- RECEIVED -->

                            <td>

                                <?= technicianDashboardDateTime(
                                    $cb['created_at']
                                ) ?>

                            </td>


                            <!-- ACTION -->

                            <td>

                                <a
                                    href="submit-report.php?id=<?= (int)$cb['id'] ?>"
                                    class="btn btn-sm btn-primary">

                                    Submit Report

                                </a>

                            </td>


                        </tr>


                    <?php endwhile; ?>


                </tbody>


            </table>

        </div>


    <?php endif; ?>


    <!-- =====================================================
         ACTION CARDS
    ====================================================== -->

    <div class="row g-4">


        <div class="col-md-4">


            <div class="card p-4 shadow text-center">


                <h5>

                    Assigned Assets

                </h5>


                <p>

                    Owners with reported issues

                </p>


                <a
                    href="assigned_lifts.php"
                    class="btn btn-danger">

                    View Assignments

                </a>


            </div>

        </div>


        <div class="col-md-4">


            <div class="card p-4 shadow text-center">


                <h5>

                    Submit Maintenance

                </h5>


                <p>

                    Checklist & work report

                </p>


                <a
                    href="maintenance_submit.php"
                    class="btn btn-success">

                    Submit Work

                </a>


            </div>

        </div>


        <div class="col-md-4">


            <div class="card p-4 shadow text-center">


                <h5>

                    Callbacks

                </h5>


                <p>

                    Open / In-progress callbacks

                </p>


                <a
                    href="callbacks.php"
                    class="btn btn-warning">

                    View Callbacks

                </a>


            </div>

        </div>


    </div>


    <!-- =====================================================
         NOTE
    ====================================================== -->

    <div class="alert alert-info mt-4">

        <strong>Note:</strong>

        Accept the callback before submitting the work report.

    </div>


</div>


<?php include('../includes/footer.php'); ?>