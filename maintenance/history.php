<?php

include('../config/db.php');
include('../config/auth.php');

require_role('technician');

$tech_id = (int)$_SESSION['uid'];

include('../includes/header.php');
include('../includes/sidebar-tech.php');


/* =========================================================
   TAB
========================================================= */

$tab = $_GET['tab'] ?? 'maintenance';

if (!in_array($tab, ['maintenance', 'callbacks'], true)) {
    $tab = 'maintenance';
}


/* =========================================================
   DATE + TIME DISPLAY
========================================================= */

function historyDateTime($dateTime)
{
    if (empty($dateTime)) {
        return '—';
    }

    try {

        /*
         * Database datetime is treated as UTC
         * and displayed in IST.
         */

        $dt = new DateTime(
            $dateTime,
            new DateTimeZone('UTC')
        );

        $dt->setTimezone(
            new DateTimeZone('Asia/Kolkata')
        );

        return
            '<div class="history-date">'
            . htmlspecialchars(
                $dt->format('d-m-Y')
            )
            . '</div>'
            .
            '<div class="history-time">'
            . htmlspecialchars(
                $dt->format('h:i A')
            )
            . '</div>';

    } catch (Exception $e) {

        return htmlspecialchars(
            $dateTime
        );
    }
}


/* =========================================================
   MAINTENANCE HISTORY
========================================================= */

$maintenanceStmt = $conn->prepare("
    SELECT
        mj.id,
        mj.asset_id,
        mj.checklist,
        mj.remarks,
        mj.file,
        mj.status,
        mj.created_at,

        a.asset_name,
        a.location

    FROM maintenance_jobs mj

    INNER JOIN assets a
        ON a.id = mj.asset_id

    WHERE mj.technician_id = ?

      AND IFNULL(
          mj.job_type,
          ''
      ) != 'fortnightly'

    ORDER BY
        mj.created_at DESC,
        mj.id DESC
");


if (!$maintenanceStmt) {

    die(
        "Maintenance history query error: " .
        htmlspecialchars($conn->error)
    );

}


$maintenanceStmt->bind_param(
    "i",
    $tech_id
);

$maintenanceStmt->execute();

$maintenanceResult =
    $maintenanceStmt->get_result();


/* =========================================================
   CALLBACK HISTORY
========================================================= */

$callbackStmt = $conn->prepare("
    SELECT

        c.id,
        c.callback_number,
        c.issue,
        c.type,
        c.status,
        c.description,

        c.technician_response,
        c.technician_reject_reason,
        c.technician_responded_at,

        c.work_report,
        c.work_report_file,

        c.created_at,
        c.work_started_at,
        c.work_closed_at,
        c.approved_at,

        COALESCE(
            a.asset_name,
            'General Check'
        ) AS asset_name,

        COALESCE(
            a.location,
            '—'
        ) AS location,

        COALESCE(
            u.name,
            '—'
        ) AS technician_name

    FROM callbacks c

    LEFT JOIN assets a
        ON a.id = c.asset_id

    LEFT JOIN users u
        ON u.id = c.technician_id

    WHERE c.technician_id = ?

      AND (
            c.technician_response IN (
                'accepted',
                'rejected'
            )

            OR c.work_report IS NOT NULL

            OR c.work_report_file IS NOT NULL

            OR c.status IN (
                'closed',
                'approved',
                'rejected'
            )
      )

    ORDER BY
        COALESCE(
            c.work_closed_at,
            c.technician_responded_at,
            c.created_at
        ) DESC,

        c.id DESC
");


if (!$callbackStmt) {

    die(
        "Callback history query error: " .
        htmlspecialchars($conn->error)
    );

}


$callbackStmt->bind_param(
    "i",
    $tech_id
);

$callbackStmt->execute();

$callbackResult =
    $callbackStmt->get_result();


/* =========================================================
   STATUS BADGES - MAINTENANCE
========================================================= */

function maintenanceStatusBadge($status)
{

    switch ($status) {

        case 'assigned':

            return '
                <span class="badge bg-warning text-dark">
                    Assigned
                </span>
            ';


        case 'in_progress':

            return '
                <span class="badge bg-primary">
                    In Progress
                </span>
            ';


        case 'pending_owner':

            return '
                <span class="badge bg-info text-dark">
                    Pending Approval
                </span>
            ';


        case 'approved':

            return '
                <span class="badge bg-success">
                    Approved
                </span>
            ';


        case 'rejected':

            return '
                <span class="badge bg-danger">
                    Rejected
                </span>
            ';


        case 'closed':

            return '
                <span class="badge bg-secondary">
                    Closed
                </span>
            ';


        default:

            return '
                <span class="badge bg-secondary">
                    ' .
                    htmlspecialchars(
                        ucfirst(
                            str_replace(
                                '_',
                                ' ',
                                $status
                            )
                        )
                    )
                    . '
                </span>
            ';
    }
}


/* =========================================================
   STATUS BADGES - CALLBACK
========================================================= */

function callbackStatusBadge($row)
{

    if (
        isset($row['technician_response']) &&
        $row['technician_response'] === 'rejected'
    ) {

        return '
            <span class="badge bg-danger">
                Rejected
            </span>
        ';
    }


    if (
        isset($row['status']) &&
        $row['status'] === 'approved'
    ) {

        return '
            <span class="badge bg-success">
                Approved
            </span>
        ';
    }


    if (
        isset($row['status']) &&
        $row['status'] === 'rejected'
    ) {

        return '
            <span class="badge bg-danger">
                Rejected
            </span>
        ';
    }


    if (
        isset($row['status']) &&
        $row['status'] === 'closed'
    ) {

        return '
            <span class="badge bg-info text-dark">
                Pending Approval
            </span>
        ';
    }


    if (
        isset($row['technician_response']) &&
        $row['technician_response'] === 'accepted'
    ) {

        return '
            <span class="badge bg-primary">
                In Progress
            </span>
        ';
    }


    return '
        <span class="badge bg-warning text-dark">
            Pending
        </span>
    ';
}

?>


<style>

/* =========================================================
   HISTORY PAGE
========================================================= */

.history-card {

    border-radius: 14px;

    overflow: hidden;

}


.history-table {

    margin-bottom: 0;

}


.history-table th {

    background: #212529;

    color: #fff;

    white-space: nowrap;

    vertical-align: middle;

}


.history-table td {

    vertical-align: middle;

}


.history-table tbody tr:hover {

    background-color: #f8f9fa;

}


.history-empty {

    padding: 40px !important;

    text-align: center;

    color: #6c757d;

}


.history-tabs {

    margin-bottom: 20px;

}


.history-tabs .nav-link {

    font-size: 18px;

    padding: 14px 24px;

}


.history-tabs .nav-link.active {

    font-weight: 600;

}


.status-badge {

    white-space: nowrap;

}


.checklist-item {

    margin-bottom: 4px;

}


.rejection-reason {

    display: block;

    margin-top: 5px;

    color: #dc3545;

    font-size: 14px;

}


.callback-number {

    font-weight: 600;

}


.callback-date {

    color: #6c757d;

    font-size: 13px;

    margin-top: 4px;

}


.work-report {

    max-width: 350px;

    white-space: normal;

    word-break: break-word;

}


/* =========================================================
   DATE / TIME
========================================================= */

.history-date {

    font-weight: 500;

    white-space: nowrap;

}


.history-time {

    color: #6c757d;

    font-size: 13px;

    margin-top: 2px;

    white-space: nowrap;

}

</style>


<div class="content">


    <!-- =====================================================
         PAGE TITLE
    ====================================================== -->

    <h2 class="mb-4">

        Maintenance History

    </h2>


    <!-- =====================================================
         TABS
    ====================================================== -->

    <ul class="nav nav-tabs history-tabs">


        <!-- MAINTENANCE -->

        <li class="nav-item">

            <a
                class="nav-link <?= $tab === 'maintenance' ? 'active' : '' ?>"
                href="history.php?tab=maintenance"
            >

                Checklist Approvals

            </a>

        </li>


        <!-- CALLBACKS -->

        <li class="nav-item">

            <a
                class="nav-link <?= $tab === 'callbacks' ? 'active' : '' ?>"
                href="history.php?tab=callbacks"
            >

                Callbacks

            </a>

        </li>

    </ul>


    <!-- =====================================================
         MAINTENANCE HISTORY
    ====================================================== -->

    <?php if ($tab === 'maintenance'): ?>


        <div class="card shadow history-card">

            <div class="table-responsive">

                <table class="table table-hover history-table">

                    <thead>

                        <tr>

                            <th>
                                Lift
                            </th>

                            <th>
                                Location
                            </th>

                            <th>
                                Status
                            </th>

                            <th>
                                Submitted
                            </th>

                            <th>
                                Checklist
                            </th>

                            <th>
                                Remarks
                            </th>

                            <th>
                                File
                            </th>

                        </tr>

                    </thead>


                    <tbody>


                    <?php if (
                        $maintenanceResult->num_rows === 0
                    ): ?>

                        <tr>

                            <td
                                colspan="7"
                                class="history-empty"
                            >

                                No maintenance history found.

                            </td>

                        </tr>

                    <?php endif; ?>


                    <?php while (
                        $r = $maintenanceResult->fetch_assoc()
                    ): ?>


                        <tr>


                            <!-- =================================================
                                 LIFT
                            ================================================== -->

                            <td>

                                <strong>

                                    <?= htmlspecialchars(
                                        $r['asset_name'] ?? '—'
                                    ) ?>

                                </strong>

                            </td>


                            <!-- =================================================
                                 LOCATION
                            ================================================== -->

                            <td>

                                <?= htmlspecialchars(
                                    $r['location'] ?? '—'
                                ) ?>

                            </td>


                            <!-- =================================================
                                 STATUS
                            ================================================== -->

                            <td>

                                <?= maintenanceStatusBadge(
                                    $r['status']
                                ) ?>

                            </td>


                            <!-- =================================================
                                 SUBMITTED DATE + TIME
                            ================================================== -->

                            <td>

                                <?= historyDateTime(
                                    $r['created_at']
                                ) ?>

                            </td>


                            <!-- =================================================
                                 CHECKLIST
                            ================================================== -->

                            <td>

                                <?php

                                $checklist =
                                    $r['checklist'] ?? '';

                                $list = [];

                                if (
                                    !empty($checklist)
                                ) {

                                    $decoded =
                                        json_decode(
                                            $checklist,
                                            true
                                        );

                                    if (
                                        is_array($decoded)
                                    ) {

                                        $list =
                                            $decoded;

                                    }

                                }

                                ?>


                                <?php if (
                                    !empty($list)
                                ): ?>


                                    <?php foreach (
                                        $list as $item
                                    ): ?>

                                        <div
                                            class="checklist-item"
                                        >

                                            <span
                                                class="text-primary"
                                            >

                                                ✔

                                            </span>

                                            <?= htmlspecialchars(
                                                (string)$item
                                            ) ?>

                                        </div>

                                    <?php endforeach; ?>


                                <?php else: ?>

                                    <span class="text-muted">

                                        No checklist

                                    </span>

                                <?php endif; ?>

                            </td>


                            <!-- =================================================
                                 REMARKS
                            ================================================== -->

                            <td>

                                <?php if (
                                    !empty(
                                        trim(
                                            $r['remarks'] ?? ''
                                        )
                                    )
                                ): ?>

                                    <?= nl2br(
                                        htmlspecialchars(
                                            $r['remarks']
                                        )
                                    ) ?>

                                <?php else: ?>

                                    <span class="text-muted">

                                        —

                                    </span>

                                <?php endif; ?>


                                <?php if (
                                    $r['status'] === 'rejected'
                                ): ?>

                                    <span
                                        class="rejection-reason"
                                    >

                                        Owner rejected
                                        maintenance.

                                    </span>

                                <?php endif; ?>

                            </td>


                            <!-- =================================================
                                 FILE
                            ================================================== -->

                            <td>

                                <?php if (
                                    !empty($r['file'])
                                ): ?>


                                    <?php

                                    $filePath =
                                        __DIR__ .
                                        '/../uploads/' .
                                        $r['file'];

                                    ?>


                                    <?php if (
                                        file_exists(
                                            $filePath
                                        )
                                    ): ?>


                                        <a
                                            href="../uploads/<?= htmlspecialchars(
                                                $r['file']
                                            ) ?>"
                                            target="_blank"
                                            class="btn btn-outline-primary btn-sm"
                                        >

                                            View

                                        </a>


                                    <?php else: ?>


                                        <span
                                            class="badge bg-warning text-dark"
                                        >

                                            File Not Found

                                        </span>


                                    <?php endif; ?>


                                <?php else: ?>


                                    <span class="text-muted">

                                        No file

                                    </span>


                                <?php endif; ?>

                            </td>


                        </tr>


                    <?php endwhile; ?>


                    </tbody>

                </table>

            </div>

        </div>


    <!-- =====================================================
         CALLBACK HISTORY
    ====================================================== -->

    <?php else: ?>


        <div class="card shadow history-card">

            <div class="table-responsive">

                <table class="table table-hover history-table">

                    <thead>

                        <tr>

                            <th>
                                Callback #
                            </th>

                            <th>
                                Lift
                            </th>

                            <th>
                                Issue
                            </th>

                            <th>
                                Technician
                            </th>

                            <th>
                                Status
                            </th>

                            <th>
                                Date / Time
                            </th>

                            <th>
                                Work Report
                            </th>

                        </tr>

                    </thead>


                    <tbody>


                    <?php if (
                        $callbackResult->num_rows === 0
                    ): ?>

                        <tr>

                            <td
                                colspan="7"
                                class="history-empty"
                            >

                                No callback history found.

                            </td>

                        </tr>

                    <?php endif; ?>


                    <?php while (
                        $r = $callbackResult->fetch_assoc()
                    ): ?>


                        <tr>


                            <!-- =================================================
                                 CALLBACK NUMBER
                            ================================================== -->

                            <td>

                                <strong
                                    class="callback-number"
                                >

                                    <?= htmlspecialchars(
                                        $r['callback_number'] ?? '—'
                                    ) ?>

                                </strong>


                                <?php if (
                                    !empty(
                                        $r['technician_responded_at']
                                    )
                                ): ?>

                                    <div
                                        class="callback-date"
                                    >

                                        Responded:

                                        <br>

                                        <?= historyDateTime(
                                            $r[
                                                'technician_responded_at'
                                            ]
                                        ) ?>

                                    </div>

                                <?php endif; ?>

                            </td>


                            <!-- =================================================
                                 LIFT
                            ================================================== -->

                            <td>

                                <?= htmlspecialchars(
                                    $r['asset_name'] ?? '—'
                                ) ?>

                            </td>


                            <!-- =================================================
                                 ISSUE
                            ================================================== -->

                            <td>

                                <?= htmlspecialchars(
                                    $r['issue'] ?? '—'
                                ) ?>

                            </td>


                            <!-- =================================================
                                 TECHNICIAN
                            ================================================== -->

                            <td>

                                <?= htmlspecialchars(
                                    $r['technician_name'] ?? '—'
                                ) ?>

                            </td>


                            <!-- =================================================
                                 STATUS
                            ================================================== -->

                            <td>

                                <?= callbackStatusBadge(
                                    $r
                                ) ?>


                                <!-- TECHNICIAN REJECTION -->

                                <?php if (
                                    $r['technician_response']
                                    === 'rejected'
                                    &&
                                    !empty(
                                        $r[
                                            'technician_reject_reason'
                                        ]
                                    )
                                ): ?>

                                    <span
                                        class="rejection-reason"
                                    >

                                        Reason:

                                        <?= htmlspecialchars(
                                            $r[
                                                'technician_reject_reason'
                                            ]
                                        ) ?>

                                    </span>

                                <?php endif; ?>


                                <!-- OWNER REJECTION -->

                                <?php if (
                                    $r['status']
                                    === 'rejected'
                                    &&
                                    !empty(
                                        $r[
                                            'technician_reject_reason'
                                        ]
                                    )
                                    &&
                                    $r[
                                        'technician_response'
                                    ] !== 'rejected'
                                ): ?>

                                    <span
                                        class="rejection-reason"
                                    >

                                        Reason:

                                        <?= htmlspecialchars(
                                            $r[
                                                'technician_reject_reason'
                                            ]
                                        ) ?>

                                    </span>

                                <?php endif; ?>

                            </td>


                            <!-- =================================================
                                 DATE + TIME
                            ================================================== -->

                            <td>

                                <?= historyDateTime(
                                    $r['created_at']
                                ) ?>

                            </td>


                            <!-- =================================================
                                 WORK REPORT
                            ================================================== -->

                            <td>

                                <div class="work-report">


                                    <?php if (
                                        !empty(
                                            trim(
                                                $r['work_report']
                                                ?? ''
                                            )
                                        )
                                    ): ?>

                                        <?= nl2br(
                                            htmlspecialchars(
                                                $r['work_report']
                                            )
                                        ) ?>

                                    <?php else: ?>

                                        <span class="text-muted">

                                            No report

                                        </span>

                                    <?php endif; ?>


                                    <?php if (
                                        !empty(
                                            $r[
                                                'work_report_file'
                                            ]
                                        )
                                    ): ?>

                                        <br>


                                        <?php

                                        $callbackFile =
                                            __DIR__ .
                                            '/../uploads/' .
                                            $r[
                                                'work_report_file'
                                            ];

                                        ?>


                                        <?php if (
                                            file_exists(
                                                $callbackFile
                                            )
                                        ): ?>

                                            <a
                                                href="../uploads/<?= htmlspecialchars(
                                                    $r[
                                                        'work_report_file'
                                                    ]
                                                ) ?>"
                                                target="_blank"
                                                class="btn btn-outline-primary btn-sm mt-2"
                                            >

                                                View File

                                            </a>

                                        <?php endif; ?>

                                    <?php endif; ?>

                                </div>

                            </td>


                        </tr>


                    <?php endwhile; ?>


                    </tbody>

                </table>

            </div>

        </div>


    <?php endif; ?>


</div>


<?php include('../includes/footer.php'); ?>