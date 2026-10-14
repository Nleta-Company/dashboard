<?php

include('../config/db.php');
include('../config/auth.php');

require_role('admin');

include('../includes/header.php');
include('../includes/sidebar-admin.php');


/* =========================================================
   STATUS FILTER
========================================================= */

$filterStatus = trim(
    $_GET['status'] ?? ''
);


$allowedStatuses = [
    'assigned',
    'in_progress',
    'pending_owner',
    'approved',
    'rejected',
    'closed'
];


if (
    !in_array(
        $filterStatus,
        $allowedStatuses,
        true
    )
) {

    $filterStatus = '';
}


/* =========================================================
   PAGE TITLE
========================================================= */

$pageTitle =
    'Maintenance Jobs (Admin)';


if ($filterStatus === 'assigned') {

    $pageTitle =
        'Assigned Maintenance Jobs';
}


if ($filterStatus === 'in_progress') {

    $pageTitle =
        'In Progress Maintenance Jobs';
}


if ($filterStatus === 'pending_owner') {

    $pageTitle =
        'Pending Owner Approval';
}


if ($filterStatus === 'approved') {

    $pageTitle =
        'Approved Maintenance Jobs';
}


if ($filterStatus === 'rejected') {

    $pageTitle =
        'Rejected Maintenance Jobs';
}


if ($filterStatus === 'closed') {

    $pageTitle =
        'Closed Maintenance Jobs';
}


/* =========================================================
   FETCH JOBS
========================================================= */

if ($filterStatus !== '') {

    $stmt = $conn->prepare("
        SELECT
            m.id,
            m.status,
            m.created_at,
            m.job_type,

            a.id AS asset_id,
            a.asset_name,

            u.name AS technician_name

        FROM maintenance_jobs m

        INNER JOIN assets a
            ON a.id = m.asset_id

        LEFT JOIN users u
            ON u.id = m.technician_id

        WHERE m.status = ?

          AND IFNULL(
              m.job_type,
              ''
          ) != 'fortnightly'

        ORDER BY m.created_at DESC
    ");


    $stmt->bind_param(
        "s",
        $filterStatus
    );


    $stmt->execute();

    $q =
        $stmt->get_result();

}

else {

    $q = $conn->query("
        SELECT
            m.id,
            m.status,
            m.created_at,
            m.job_type,

            a.id AS asset_id,
            a.asset_name,

            u.name AS technician_name

        FROM maintenance_jobs m

        INNER JOIN assets a
            ON a.id = m.asset_id

        LEFT JOIN users u
            ON u.id = m.technician_id

        WHERE IFNULL(
            m.job_type,
            ''
        ) != 'fortnightly'

        ORDER BY m.created_at DESC
    ");
}

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
    $filterStatus !== ''
): ?>

<small class="text-muted">

Showing only:

<strong>

<?= htmlspecialchars(
    ucwords(
        str_replace(
            '_',
            ' ',
            $filterStatus
        )
    )
) ?>

</strong>

</small>

<?php endif; ?>

</div>


<?php if (
    $filterStatus !== ''
): ?>

<a
  href="maintenance-jobs.php"
  class="btn btn-secondary">

View All Jobs

</a>

<?php endif; ?>


</div>


<!-- =====================================================
     SUMMARY FILTER BUTTONS
====================================================== -->

<div class="row g-3 mb-4">


<!-- ALL -->

<div class="col-md-2">

<a
  href="maintenance-jobs.php"
  class="text-decoration-none">

<div class="card shadow-sm p-3 text-center">

<strong class="fs-4">

<?= (int)$conn->query("
    SELECT COUNT(*)
    FROM maintenance_jobs
    WHERE IFNULL(
        job_type,
        ''
    ) != 'fortnightly'
")->fetch_row()[0] ?>

</strong>

<small class="text-muted">
All
</small>

</div>

</a>

</div>


<!-- ASSIGNED -->

<div class="col-md-2">

<a
  href="maintenance-jobs.php?status=assigned"
  class="text-decoration-none">

<div class="card shadow-sm p-3 text-center">

<strong class="fs-4 text-secondary">

<?= (int)$conn->query("
    SELECT COUNT(*)
    FROM maintenance_jobs
    WHERE status='assigned'
      AND IFNULL(
          job_type,
          ''
      ) != 'fortnightly'
")->fetch_row()[0] ?>

</strong>

<small class="text-muted">
Assigned
</small>

</div>

</a>

</div>


<!-- IN PROGRESS -->

<div class="col-md-2">

<a
  href="maintenance-jobs.php?status=in_progress"
  class="text-decoration-none">

<div class="card shadow-sm p-3 text-center">

<strong class="fs-4 text-warning">

<?= (int)$conn->query("
    SELECT COUNT(*)
    FROM maintenance_jobs
    WHERE status='in_progress'
      AND IFNULL(
          job_type,
          ''
      ) != 'fortnightly'
")->fetch_row()[0] ?>

</strong>

<small class="text-muted">
In Progress
</small>

</div>

</a>

</div>


<!-- PENDING OWNER -->

<div class="col-md-2">

<a
  href="maintenance-jobs.php?status=pending_owner"
  class="text-decoration-none">

<div class="card shadow-sm p-3 text-center">

<strong class="fs-4 text-info">

<?= (int)$conn->query("
    SELECT COUNT(*)
    FROM maintenance_jobs
    WHERE status='pending_owner'
      AND IFNULL(
          job_type,
          ''
      ) != 'fortnightly'
")->fetch_row()[0] ?>

</strong>

<small class="text-muted">
Pending Owner
</small>

</div>

</a>

</div>


<!-- APPROVED -->

<div class="col-md-2">

<a
  href="maintenance-jobs.php?status=approved"
  class="text-decoration-none">

<div class="card shadow-sm p-3 text-center">

<strong class="fs-4 text-success">

<?= (int)$conn->query("
    SELECT COUNT(*)
    FROM maintenance_jobs
    WHERE status='approved'
      AND IFNULL(
          job_type,
          ''
      ) != 'fortnightly'
")->fetch_row()[0] ?>

</strong>

<small class="text-muted">
Approved
</small>

</div>

</a>

</div>


<!-- REJECTED -->

<div class="col-md-2">

<a
  href="maintenance-jobs.php?status=rejected"
  class="text-decoration-none">

<div class="card shadow-sm p-3 text-center">

<strong class="fs-4 text-danger">

<?= (int)$conn->query("
    SELECT COUNT(*)
    FROM maintenance_jobs
    WHERE status='rejected'
      AND IFNULL(
          job_type,
          ''
      ) != 'fortnightly'
")->fetch_row()[0] ?>

</strong>

<small class="text-muted">
Rejected
</small>

</div>

</a>

</div>


</div>


<!-- =====================================================
     TABLE
====================================================== -->

<div class="card shadow">

<div class="card-body p-0">

<div class="table-responsive">

<table class="table table-hover mb-0">

<thead class="table-dark">

<tr>

<th>
Lift
</th>

<th>
Technician
</th>

<th>
Status
</th>

<th>
Created
</th>

<th>
Action
</th>

</tr>

</thead>


<tbody>


<?php if (
    $q->num_rows === 0
): ?>

<tr>

<td
  colspan="5"
  class="text-center text-muted py-5">

No maintenance jobs found

<?php if (
    $filterStatus !== ''
): ?>

for

<strong>

<?= htmlspecialchars(
    ucwords(
        str_replace(
            '_',
            ' ',
            $filterStatus
        )
    )
) ?>

</strong>

<?php endif; ?>

</td>

</tr>

<?php endif; ?>


<?php while (
    $r = $q->fetch_assoc()
): ?>


<?php

$statusBadge = match (
    $r['status']
) {

    'assigned'
        => 'secondary',

    'in_progress'
        => 'warning',

    'pending_owner'
        => 'info',

    'approved'
        => 'success',

    'rejected'
        => 'danger',

    'closed'
        => 'dark',

    default
        => 'secondary'
};


$statusText =
    ucwords(
        str_replace(
            '_',
            ' ',
            $r['status']
        )
    );

?>


<tr>


<td>

<a
  href="asset-details.php?id=<?= (int)$r['asset_id'] ?>"
  class="text-decoration-none">

<strong>

<?= htmlspecialchars(
    $r['asset_name']
) ?>

</strong>

</a>

</td>


<td>

<?= htmlspecialchars(
    $r['technician_name']
    ?? '—'
) ?>

</td>


<td>

<span
  class="badge bg-<?= $statusBadge ?>">

<?= htmlspecialchars(
    $statusText
) ?>

</span>

</td>


<td>

<?= format_dt(
    $r['created_at']
) ?>

</td>


<td>

<a
  href="maintenance-reassign.php?job=<?= (int)$r['id'] ?>"
  class="btn btn-sm btn-warning">

Reassign

</a>

</td>


</tr>


<?php endwhile; ?>


</tbody>

</table>

</div>

</div>

</div>

</div>


<?php include('../includes/footer.php'); ?>