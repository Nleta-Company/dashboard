<?php

include('../config/db.php');
include('../config/auth.php');

require_role('owner');

require_once __DIR__ . '/../config/owner_access.php';
requireOwnerAccess('issues');

include('../includes/header.php');
include('../includes/sidebar-owner.php');

$owner_id = (int)$_SESSION['uid'];


/* =========================================================
   GET OWNER'S ACTIVE MAINTENANCE ISSUES
========================================================= */

/*
 * Only show lifts which currently have an active maintenance job.
 *
 * active jobs:
 * assigned
 * in_progress
 * pending_owner
 * rejected
 *
 * closed / approved jobs are NOT treated as reported issues.
 */

$stmt = $conn->prepare("
    SELECT
        a.id AS asset_id,
        a.asset_name,
        a.location,
        a.status AS asset_status,

        mj.id AS job_id,
        mj.status AS job_status,
        mj.created_at AS reported_at

    FROM assets a

    INNER JOIN maintenance_jobs mj
        ON mj.asset_id = a.id

    WHERE a.owner_id = ?

      AND mj.id = (
          SELECT MAX(mj2.id)
          FROM maintenance_jobs mj2
          WHERE mj2.asset_id = a.id
      )

      AND mj.status IN (
          'assigned',
          'in_progress',
          'pending_owner',
          'rejected'
      )

    ORDER BY mj.created_at DESC
");


$stmt->bind_param(
    "i",
    $owner_id
);

$stmt->execute();

$q = $stmt->get_result();

?>

<div class="content">

<h3 class="mb-3">
    Reported Issues
</h3>


<?php if (!empty($_SESSION['success'])): ?>

<div class="alert alert-success alert-dismissible fade show">

    <?= htmlspecialchars($_SESSION['success']); ?>

    <?php unset($_SESSION['success']); ?>

    <button
        type="button"
        class="btn-close"
        onclick="this.closest('.alert').remove()">
    </button>

</div>

<?php endif; ?>


<?php if (!empty($_SESSION['error'])): ?>

<div class="alert alert-danger alert-dismissible fade show">

    <?= htmlspecialchars($_SESSION['error']); ?>

    <?php unset($_SESSION['error']); ?>

    <button
        type="button"
        class="btn-close"
        onclick="this.closest('.alert').remove()">
    </button>

</div>

<?php endif; ?>


<table class="table table-hover bg-white shadow mt-3">

<thead class="table-dark">

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
        Reported On
    </th>

</tr>

</thead>


<tbody>

<?php if ($q->num_rows === 0): ?>

<tr>

    <td
        colspan="4"
        class="text-center text-muted py-4">

        No reported issues found

    </td>

</tr>

<?php endif; ?>


<?php while ($r = $q->fetch_assoc()): ?>

<?php

/* =========================================================
   DISPLAY STATUS
========================================================= */

switch ($r['job_status']) {

    case 'assigned':

        $displayStatus = 'Under Maintenance';
        $badge = 'warning';

        break;


    case 'in_progress':

        $displayStatus = 'Under Maintenance';
        $badge = 'warning';

        break;


    case 'pending_owner':

        $displayStatus = 'Waiting for Approval';
        $badge = 'info';

        break;


    case 'rejected':

        $displayStatus = 'Maintenance Rejected';
        $badge = 'danger';

        break;


    default:

        $displayStatus = 'Under Maintenance';
        $badge = 'warning';

        break;
}


?>


<tr>

    <!-- LIFT -->

    <td>

        <?= htmlspecialchars(
            $r['asset_name']
        ) ?>

    </td>


    <!-- LOCATION -->

    <td>

        <?= htmlspecialchars(
            $r['location'] ?? '—'
        ) ?>

    </td>


    <!-- STATUS -->

    <td>

        <span class="badge bg-<?= $badge ?>">

            <?= htmlspecialchars(
                $displayStatus
            ) ?>

        </span>

    </td>


    <!-- REPORTED ON -->

    <td>

        <?php

        if (!empty($r['reported_at'])) {

            try {

                /*
                 * Database created_at is treated as UTC.
                 * Convert UTC to IST for display.
                 */

                $reportedDate = new DateTime(
                    $r['reported_at'],
                    new DateTimeZone('UTC')
                );

                $reportedDate->setTimezone(
                    new DateTimeZone('Asia/Kolkata')
                );


                /*
                 * Display date and time separately.
                 */

                $reportedDateText = $reportedDate->format('d M Y');

                $reportedTimeText = $reportedDate->format('h:i A');


                echo "
                    <small class='text-muted'>
                        {$reportedDateText}
                    </small>
                    <br>
                    <small class='text-muted'>
                        {$reportedTimeText}
                    </small>
                ";


            } catch (Exception $e) {

                echo '—';
            }

        } else {

            echo '—';
        }

        ?>

    </td>

</tr>


<?php endwhile; ?>

</tbody>

</table>

</div>


<?php include('../includes/footer.php'); ?>