<?php
include('../config/db.php');
include('../config/auth.php');

require_role('admin');

include('../includes/header.php');
include('../includes/sidebar-admin.php');


/* =========================================================
   FILTERS
========================================================= */

$filterStatus = trim($_GET['status'] ?? '');

$month = isset($_GET['month'])
    ? (int)$_GET['month']
    : 0;

$year = isset($_GET['year'])
    ? (int)$_GET['year']
    : 0;


$allowedStatuses = [
    'open',
    'in_progress',
    'closed',
    'approved',
    'rejected'
];


/*
   Rejected is stored in technician_response,
   not callbacks.status.
*/

if (
    $filterStatus !== '' &&
    !in_array($filterStatus, $allowedStatuses, true)
) {

    $filterStatus = '';
}


/* =========================================================
   PAGE TITLE
========================================================= */

$pageTitle = 'Callback Monitoring';

if ($filterStatus === 'approved') {
    $pageTitle = 'Approved Callbacks';
}

elseif ($filterStatus === 'closed') {
    $pageTitle = 'Pending Approval Callbacks';
}

elseif ($filterStatus === 'in_progress') {
    $pageTitle = 'Callbacks In Progress';
}

elseif ($filterStatus === 'open') {
    $pageTitle = 'Open Callbacks';
}

elseif ($filterStatus === 'rejected') {
    $pageTitle = 'Rejected Callbacks';
}


/* =========================================================
   BUILD QUERY
========================================================= */

$sql = "
    SELECT
        c.id,
        c.callback_number,
        c.issue,
        c.status,
        c.technician_response,
        c.work_started_at,
        c.work_closed_at,
        c.work_report,
        c.work_report_file,
        c.created_at,

        COALESCE(
            a.asset_name,
            'General'
        ) AS asset_name,

        u.name AS technician_name

    FROM callbacks c

    LEFT JOIN assets a
        ON a.id = c.asset_id

    LEFT JOIN users u
        ON u.id = c.technician_id

    WHERE 1=1
";


$params = [];
$types = "";


/* =========================================================
   STATUS FILTER
========================================================= */

if ($filterStatus === 'rejected') {

    $sql .= "
        AND c.technician_response = 'rejected'
    ";

}

elseif ($filterStatus !== '') {

    $sql .= "
        AND c.status = ?
    ";

    $params[] = $filterStatus;
    $types .= "s";
}


/* =========================================================
   MONTH FILTER
========================================================= */

if (
    $month >= 1 &&
    $month <= 12 &&
    $year >= 2000
) {

    /*
       Approved uses approved_at.
       Rejected uses technician_responded_at.
       Others use created_at.
    */

    if ($filterStatus === 'approved') {

        $sql .= "
            AND MONTH(c.approved_at) = ?
            AND YEAR(c.approved_at) = ?
        ";

    }

    elseif ($filterStatus === 'rejected') {

        $sql .= "
            AND MONTH(c.technician_responded_at) = ?
            AND YEAR(c.technician_responded_at) = ?
        ";

    }

    else {

        $sql .= "
            AND MONTH(c.created_at) = ?
            AND YEAR(c.created_at) = ?
        ";
    }

    $params[] = $month;
    $params[] = $year;

    $types .= "ii";
}


/* =========================================================
   ORDER
========================================================= */

$sql .= "
    ORDER BY c.created_at DESC
";


$stmt = $conn->prepare($sql);


if (!empty($params)) {

    $stmt->bind_param(
        $types,
        ...$params
    );
}


$stmt->execute();

$q = $stmt->get_result();

?>

<div class="content">


<div class="d-flex justify-content-between align-items-center mb-4">

    <div>

        <h3 class="mb-1">

            <?= htmlspecialchars(
                $pageTitle
            ) ?>

        </h3>

        <?php if (
            $month >= 1 &&
            $month <= 12 &&
            $year >= 2000
        ): ?>

            <small class="text-muted">

                Showing
                <?= date(
                    'F',
                    mktime(
                        0,
                        0,
                        0,
                        $month,
                        1
                    )
                ) ?>

                <?= $year ?>

            </small>

        <?php endif; ?>

    </div>


    <?php if (
        $filterStatus !== '' ||
        $month > 0
    ): ?>

        <a
          href="callbacks.php"
          class="btn btn-secondary">

            View All Callbacks

        </a>

    <?php endif; ?>

</div>


<!-- =========================================================
     SUMMARY
========================================================= -->

<div class="row g-3 mb-4">


<div class="col-md-2">

<a
  href="callbacks.php"
  class="text-decoration-none">

<div class="card shadow-sm p-3 text-center">

<strong class="fs-4">

<?= (int)$conn->query("
    SELECT COUNT(*)
    FROM callbacks
")->fetch_row()[0] ?>

</strong>

<small class="text-muted">
All
</small>

</div>

</a>

</div>


<div class="col-md-2">

<a
  href="callbacks.php?status=open"
  class="text-decoration-none">

<div class="card shadow-sm p-3 text-center">

<strong class="fs-4 text-secondary">

<?= (int)$conn->query("
    SELECT COUNT(*)
    FROM callbacks
    WHERE status='open'
")->fetch_row()[0] ?>

</strong>

<small class="text-muted">
Open
</small>

</div>

</a>

</div>


<div class="col-md-2">

<a
  href="callbacks.php?status=in_progress"
  class="text-decoration-none">

<div class="card shadow-sm p-3 text-center">

<strong class="fs-4 text-warning">

<?= (int)$conn->query("
    SELECT COUNT(*)
    FROM callbacks
    WHERE status='in_progress'
")->fetch_row()[0] ?>

</strong>

<small class="text-muted">
In Progress
</small>

</div>

</a>

</div>


<div class="col-md-2">

<a
  href="callbacks.php?status=closed"
  class="text-decoration-none">

<div class="card shadow-sm p-3 text-center">

<strong class="fs-4 text-warning">

<?= (int)$conn->query("
    SELECT COUNT(*)
    FROM callbacks
    WHERE status='closed'
")->fetch_row()[0] ?>

</strong>

<small class="text-muted">
Pending
</small>

</div>

</a>

</div>


<div class="col-md-2">

<a
  href="callbacks.php?status=approved"
  class="text-decoration-none">

<div class="card shadow-sm p-3 text-center">

<strong class="fs-4 text-success">

<?= (int)$conn->query("
    SELECT COUNT(*)
    FROM callbacks
    WHERE status='approved'
")->fetch_row()[0] ?>

</strong>

<small class="text-muted">
Approved
</small>

</div>

</a>

</div>


<div class="col-md-2">

<a
  href="callbacks.php?status=rejected"
  class="text-decoration-none">

<div class="card shadow-sm p-3 text-center">

<strong class="fs-4 text-danger">

<?= (int)$conn->query("
    SELECT COUNT(*)
    FROM callbacks
    WHERE technician_response='rejected'
")->fetch_row()[0] ?>

</strong>

<small class="text-muted">
Rejected
</small>

</div>

</a>

</div>


</div>


<!-- =========================================================
     TABLE
========================================================= -->

<table class="table table-hover bg-white shadow">

<thead class="table-dark">

<tr>

  <th>Callback #</th>
  <th>Lift</th>
  <th>Issue</th>
  <th>Technician</th>
  <th>Status</th>
  <th>Started</th>
  <th>Completed</th>
  <th>Report</th>

</tr>

</thead>


<tbody>


<?php if ($q->num_rows == 0): ?>

<tr>

<td
  colspan="8"
  class="text-center text-muted py-5">

No callbacks found.

</td>

</tr>

<?php endif; ?>


<?php while (
    $r = $q->fetch_assoc()
): ?>


<?php

/* =========================================================
   STATUS BADGE
========================================================= */

if (
    $r['technician_response'] === 'rejected'
) {

    $badge =
        "<span class='badge bg-danger'>
            Rejected
        </span>";

}

else {

    $badge = match (
        $r['status']
    ) {

        'open'
            => "<span class='badge bg-secondary'>
                    Open
               </span>",

        'in_progress'
            => "<span class='badge bg-warning text-dark'>
                    In Progress
               </span>",

        'closed'
            => "<span class='badge bg-secondary'>
                    Pending Approval
               </span>",

        'approved'
            => "<span class='badge bg-success'>
                    Approved
               </span>",

        default
            => "<span class='badge bg-secondary'>
                    Unknown
               </span>"
    };
}


$started =
    $r['work_started_at']
    ? format_dt(
        $r['work_started_at']
      )
    : '—';


$closed =
    $r['work_closed_at']
    ? format_dt(
        $r['work_closed_at']
      )
    : '—';


$reportText =
    !empty($r['work_report'])
    ? nl2br(
        htmlspecialchars(
            $r['work_report']
        )
      )
    : '<span class="text-muted">
          Not submitted
       </span>';


$reportFile = '';

if (!empty($r['work_report_file'])) {

    $safeFile =
        basename(
            $r['work_report_file']
        );

    $filePath =
        __DIR__
        . '/../uploads/callback_reports/'
        . $safeFile;

    if (file_exists($filePath)) {

        $reportFile =
            "<br>
            <a
              href='../uploads/callback_reports/"
              . htmlspecialchars($safeFile)
              . "'
              target='_blank'
              class='btn btn-sm btn-outline-primary mt-1'>

              View File

            </a>";

    }

    else {

        $reportFile =
            "<br>
            <span
              class='badge bg-warning text-dark mt-1'>

              File Not Found

            </span>";
    }
}

?>


<tr>


<td>

<strong>

<?= htmlspecialchars(
    $r['callback_number']
) ?>

</strong>

</td>


<td>

<?= htmlspecialchars(
    $r['asset_name']
) ?>

</td>


<td>

<?= htmlspecialchars(
    $r['issue']
) ?>

</td>


<td>

<?= htmlspecialchars(
    $r['technician_name']
    ?? '—'
) ?>

</td>


<td>

<?= $badge ?>

</td>


<td>

<?= $started ?>

</td>


<td>

<?= $closed ?>

</td>


<td style="max-width:320px">

<?= $reportText ?>

<?= $reportFile ?>

</td>


</tr>


<?php endwhile; ?>


</tbody>

</table>

</div>

<?php include('../includes/footer.php'); ?>