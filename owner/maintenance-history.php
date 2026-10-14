<?php

include('../config/db.php');
include('../config/auth.php');

require_role('owner');

require_once __DIR__ . '/../config/owner_access.php';
requireOwnerAccess('maintenance_history');

$owner_id = (int)$_SESSION['uid'];

$tab = $_GET['tab'] ?? 'checklist';


/* =========================================================
   STATUS BADGE
========================================================= */

function getStatusBadge($status)
{
    switch ($status) {

        case 'pending_owner':
            return '<span class="badge bg-warning text-dark">Pending</span>';

        case 'approved':
            return '<span class="badge bg-success">Approved</span>';

        case 'rejected':
            return '<span class="badge bg-danger">Rejected</span>';

        case 'closed':
            return '<span class="badge bg-info text-dark">Closed</span>';

        default:
            return '<span class="badge bg-secondary">'
                . htmlspecialchars($status)
                . '</span>';
    }
}


include('../includes/header.php');
include('../includes/sidebar-owner.php');

?>


<div class="content">
<style>
/* =========================================================
   RESPONSIVE MAINTENANCE HISTORY
========================================================= */

.history-page {
    width: 100%;
    max-width: 1400px;
    margin: 0 auto;
}

.history-table-wrap {
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

.history-table-wrap table {
    min-width: 760px;
    margin-bottom: 0;
}

.history-table-wrap th,
.history-table-wrap td {
    white-space: nowrap;
    vertical-align: middle;
}

.history-table-wrap td:last-child {
    white-space: normal;
    min-width: 180px;
}

@media (max-width: 992px) {

    .content {
        width: 100%;
    }

    .history-page {
        padding-left: 8px;
        padding-right: 8px;
    }

    .nav-tabs {
        overflow-x: auto;
        flex-wrap: nowrap;
        scrollbar-width: thin;
    }

    .nav-tabs .nav-item {
        flex: 0 0 auto;
    }

    .nav-tabs .nav-link {
        white-space: nowrap;
    }

}

@media (max-width: 768px) {

    .history-page h3 {
        font-size: 20px;
    }

    .history-table-wrap {
        border-radius: 8px;
    }

    .history-table-wrap table {
        min-width: 700px;
        font-size: 12px;
    }

    .history-table-wrap th,
    .history-table-wrap td {
        padding: 8px;
    }

    .history-table-wrap .btn {
        font-size: 11px;
        padding: 4px 8px;
    }

    .badge {
        font-size: 10px;
    }

}

@media (max-width: 576px) {

    .history-page {
        padding-left: 4px;
        padding-right: 4px;
    }

    .history-page h3 {
        font-size: 18px;
        margin-bottom: 14px !important;
    }

    .nav-tabs .nav-link {
        font-size: 12px;
        padding: 8px 10px;
    }

    .history-table-wrap table {
        min-width: 650px;
        font-size: 11px;
    }

    .history-table-wrap th,
    .history-table-wrap td {
        padding: 7px;
    }

}
</style>


<h3 class="mb-4">Maintenance History</h3>


<!-- =========================================================
     TABS
========================================================= -->

<ul class="nav nav-tabs mb-3">

    <li class="nav-item">

        <a
            class="nav-link <?= $tab === 'checklist' ? 'active' : '' ?>"
            href="maintenance-history.php?tab=checklist">

            Checklist Approvals

        </a>

    </li>


    <li class="nav-item">

        <a
            class="nav-link <?= $tab === 'callbacks' ? 'active' : '' ?>"
            href="maintenance-history.php?tab=callbacks">

            Callbacks

        </a>

    </li>

    <li class="nav-item">

        <a
            class="nav-link <?= $tab === 'service_selection' ? 'active' : '' ?>"
            href="maintenance-history.php?tab=service_selection">

            Service Selection History

        </a>

    </li>

</ul>



<?php if ($tab === 'checklist'): ?>


<!-- =========================================================
     CHECKLIST HISTORY
========================================================= -->

<div class="history-table-wrap">

<table class="table table-hover bg-white shadow">

<thead class="table-dark">

<tr>

    <th>Lift</th>

    <th>Status</th>

    <th>Date</th>

    <th>Remarks</th>

    <th>File</th>

</tr>

</thead>


<tbody>

<?php


$lq = $conn->prepare("
    SELECT
        mj.id,
        mj.checklist,
        mj.remarks,
        mj.file,
        mj.created_at,
        mj.status,
        a.asset_name

    FROM maintenance_jobs mj

    JOIN assets a
        ON a.id = mj.asset_id

    WHERE
        a.owner_id = ?

        AND mj.status IN (
            'approved',
            'rejected',
            'closed'
        )

    ORDER BY mj.created_at DESC
");

$lq->bind_param("i", $owner_id);

$lq->execute();

$list = $lq->get_result();


if ($list->num_rows === 0) {

    echo "
        <tr>
            <td colspan='5'
                class='text-center text-muted'>

                No approved, rejected, or closed
                checklist approvals found

            </td>
        </tr>
    ";

} else {

    while ($r = $list->fetch_assoc()) {

?>

<tr>

    <td>

        <strong>
            <?= htmlspecialchars($r['asset_name']) ?>
        </strong>

    </td>


    <td>

        <?= getStatusBadge($r['status']) ?>

    </td>


    <td>

        <?= format_dt($r['created_at']) ?>

    </td>


    <td>

        <?= nl2br(
            htmlspecialchars(
                $r['remarks'] ?? '—'
            )
        ) ?>

    </td>


    <td>

        <?php if (!empty($r['file'])): ?>

            <?php

            $filePath =
                __DIR__ .
                '/../uploads/' .
                $r['file'];

            ?>

            <?php if (file_exists($filePath)): ?>

                <a
                    href="../uploads/<?= htmlspecialchars($r['file']) ?>"
                    target="_blank"
                    class="btn btn-outline-primary btn-sm">

                    View

                </a>

            <?php else: ?>

                <span
                    class="badge bg-warning text-dark">

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

<?php

    }

}

?>

</tbody>

</table>

</div>



<?php elseif ($tab === 'service_selection'): ?>


<!-- =========================================================
     SERVICE SELECTION HISTORY
     SHOWS ALL SERVICE REQUEST HISTORY FOR THIS OWNER
========================================================= -->

<div class="history-table-wrap">

<table class="table table-hover bg-white shadow">

<thead class="table-dark">
<tr>
    <th>Request No.</th>
    <th>Service</th>
    <th>Assets</th>
    <th>Status</th>
    <th>Submitted Date</th>
    <th>Requirements / Remarks</th>
</tr>
</thead>

<tbody>

<?php

$ssStmt = $conn->prepare("
    SELECT
        sr.id,
        sr.request_no,
        sr.requirements,
        sr.status,
        sr.submitted_at,
        sc.service_code,
        sc.service_name,
        COUNT(DISTINCT sra.asset_id) AS asset_count
    FROM service_requests sr
    INNER JOIN service_catalog sc
        ON sc.id = sr.service_id
    LEFT JOIN service_request_assets sra
        ON sra.request_id = sr.id
    WHERE sr.owner_id = ?
    GROUP BY
        sr.id, sr.request_no, sr.requirements, sr.status,
        sr.submitted_at, sc.service_code, sc.service_name
    ORDER BY sr.submitted_at DESC, sr.id DESC
");

$ssStmt->bind_param("i", $owner_id);
$ssStmt->execute();
$ssList = $ssStmt->get_result();

if ($ssList->num_rows === 0) {

    echo "<tr><td colspan='6' class='text-center text-muted'>No service selection history found</td></tr>";

} else {

    while ($r = $ssList->fetch_assoc()) {

        $requestStatus = strtolower(trim((string)$r['status']));

        switch ($requestStatus) {
            case 'submitted':
                $statusBadge = '<span class="badge bg-warning text-dark">Submitted</span>';
                break;
            case 'accepted':
                $statusBadge = '<span class="badge bg-success">Accepted</span>';
                break;
            case 'rejected':
                $statusBadge = '<span class="badge bg-danger">Rejected</span>';
                break;
            case 'offer generated':
                $statusBadge = '<span class="badge bg-info text-dark">Offer Generated</span>';
                break;
            default:
                $statusBadge = '<span class="badge bg-secondary">' . htmlspecialchars($r['status']) . '</span>';
        }

?>
<tr>
    <td><strong><?= htmlspecialchars($r['request_no'] ?? '-') ?></strong></td>
    <td>
        <span class="badge bg-primary"><?= htmlspecialchars($r['service_code'] ?? '-') ?></span>
        <br>
        <small><?= htmlspecialchars($r['service_name'] ?? '-') ?></small>
    </td>
    <td><span class="badge bg-secondary"><?= (int)$r['asset_count'] ?></span></td>
    <td><?= $statusBadge ?></td>
    <td><?= format_dt($r['submitted_at']) ?></td>
    <td><?= nl2br(htmlspecialchars($r['requirements'] ?? '—')) ?></td>
</tr>
<?php
    }
}

$ssStmt->close();
?>

</tbody>
</table>

</div>


<?php else: ?>


<!-- =========================================================
     CALLBACK HISTORY
     SHOWS BOTH APPROVED AND REJECTED
========================================================= -->

<div class="history-table-wrap">

<table class="table table-hover bg-white shadow">

<thead class="table-dark">

<tr>

    <th>Callback #</th>

    <th>Lift</th>

    <th>Issue</th>

    <th>Technician</th>

    <th>Status</th>

    <th>Date</th>

    <th>Work Report</th>

</tr>

</thead>


<tbody>

<?php


/* =========================================================
   GET CALLBACK HISTORY

   IMPORTANT:
   We intentionally do NOT use:

       c.status = 'approved'

   because we want BOTH approved and rejected callbacks.
========================================================= */

$cbStmt = $conn->prepare("
    SELECT

        c.id,

        c.callback_number,

        c.issue,

        c.status,

        c.created_at,

        c.work_started_at,

        c.work_closed_at,

        c.approved_at,

        c.technician_response,

        c.technician_reject_reason,

        c.technician_responded_at,

        c.work_report,

        c.work_report_file,

        a.asset_name,

        u.name AS technician_name

    FROM callbacks c

    JOIN assets a
        ON a.id = c.asset_id

    LEFT JOIN users u
        ON u.id = c.technician_id

    WHERE
        a.owner_id = ?

    ORDER BY
        COALESCE(
            c.work_closed_at,
            c.technician_responded_at,
            c.created_at
        ) DESC
");


$cbStmt->bind_param("i", $owner_id);

$cbStmt->execute();

$cbList = $cbStmt->get_result();


if ($cbList->num_rows === 0) {

    echo "
        <tr>
            <td colspan='7'
                class='text-center text-muted'>

                No callback history found

            </td>
        </tr>
    ";

} else {


    while ($r = $cbList->fetch_assoc()) {


        /* =====================================================
           DETERMINE CALLBACK STATUS
        ===================================================== */

        $statusBadge = '';

        $statusText = '';


        /*
         * TECHNICIAN REJECTED
         */

        if ($r['technician_response'] === 'rejected') {

            $statusBadge = '
                <span class="badge bg-danger">
                    ❌ Rejected
                </span>
            ';

            $statusText = 'Technician rejected callback';


        /*
         * OWNER APPROVED
         */

        } elseif ($r['status'] === 'approved') {

            $statusBadge = '
                <span class="badge bg-success">
                    ✅ Approved
                </span>
            ';

            $statusText = 'Approved';


        /*
         * OWNER REJECTED AFTER WORK
         */

        } elseif (
            $r['status'] === 'rejected'
        ) {

            $statusBadge = '
                <span class="badge bg-danger">
                    ❌ Rejected
                </span>
            ';

            $statusText = 'Rejected';


        /*
         * CALLBACK CLOSED BUT NOT YET APPROVED
         */

        } elseif (
            $r['status'] === 'closed'
        ) {

            $statusBadge = '
                <span class="badge bg-info text-dark">
                    Pending Approval
                </span>
            ';

            $statusText = 'Pending Owner Approval';


        /*
         * TECHNICIAN ACCEPTED / WORKING
         */

        } elseif (
            $r['technician_response'] === 'accepted'
        ) {

            $statusBadge = '
                <span class="badge bg-primary">
                    🔧 In Progress
                </span>
            ';

            $statusText = 'Technician working';


        /*
         * WAITING FOR TECHNICIAN
         */

        } else {

            $statusBadge = '
                <span class="badge bg-warning text-dark">
                    ⏳ Waiting
                </span>
            ';

            $statusText = 'Waiting for technician';

        }

?>

<tr>


<!-- =====================================================
     CALLBACK NUMBER
===================================================== -->

<td>

    <strong>

        <?= htmlspecialchars(
            $r['callback_number'] ?? '-'
        ) ?>

    </strong>


    <?php if (!empty($r['technician_responded_at'])): ?>

        <br>

        <small class="text-muted">

            Responded:
            <?= format_dt(
                $r['technician_responded_at']
            ) ?>

        </small>

    <?php endif; ?>

</td>



<!-- =====================================================
     LIFT
===================================================== -->

<td>

    <?= htmlspecialchars(
        $r['asset_name'] ?? '—'
    ) ?>

</td>



<!-- =====================================================
     ISSUE
===================================================== -->

<td>

    <?= htmlspecialchars(
        $r['issue'] ?? '—'
    ) ?>

</td>



<!-- =====================================================
     TECHNICIAN
===================================================== -->

<td>

    <?= htmlspecialchars(
        $r['technician_name'] ?? '—'
    ) ?>

</td>



<!-- =====================================================
     STATUS
===================================================== -->

<td>

    <?= $statusBadge ?>


    <?php if (
        $r['technician_response'] === 'rejected'
        && !empty($r['technician_reject_reason'])
    ): ?>

        <br>

        <small class="text-danger">

            <strong>Reason:</strong>

            <?= htmlspecialchars(
                $r['technician_reject_reason']
            ) ?>

        </small>

    <?php endif; ?>

</td>



<!-- =====================================================
     DATE
===================================================== -->

<td>

    <?php

    $historyDate =
        $r['work_closed_at']
        ?: $r['approved_at']
        ?: $r['technician_responded_at']
        ?: $r['created_at'];

    ?>

    <?= format_dt($historyDate) ?>

</td>



<!-- =====================================================
     WORK REPORT
===================================================== -->

<td>

    <?php if (!empty($r['work_report'])): ?>

        <?= nl2br(
            htmlspecialchars(
                $r['work_report']
            )
        ) ?>

        <br>

    <?php endif; ?>


    <?php if (!empty($r['work_report_file'])): ?>

        <?php

        $filePath =
            __DIR__ .
            '/../uploads/callback_reports/' .
            $r['work_report_file'];

        ?>

        <?php if (file_exists($filePath)): ?>

            <a
                href="../uploads/callback_reports/<?= htmlspecialchars($r['work_report_file']) ?>"
                target="_blank"
                class="btn btn-outline-primary btn-sm">

                View Report

            </a>

        <?php else: ?>

            <span
                class="badge bg-warning text-dark">

                File Not Found

            </span>

        <?php endif; ?>


    <?php endif; ?>


    <?php if (
        empty($r['work_report'])
        && empty($r['work_report_file'])
    ): ?>

        <span class="text-muted">

            No report

        </span>

    <?php endif; ?>

</td>


</tr>

<?php

    }

}

?>

</tbody>

</table>

</div>


<?php endif; ?>


</div>

</div>


<?php include('../includes/footer.php'); ?>