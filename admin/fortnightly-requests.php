<?php

include('../config/db.php');
include('../config/auth.php');

require_role('admin');

include('../includes/header.php');
include('../includes/sidebar-admin.php');


/* =========================================================
   FORTNIGHTLY REQUESTS
   Show all fortnightly callbacks/requests
========================================================= */

$requests = $conn->query("
    SELECT
        c.id,
        c.asset_id,
        c.technician_id,
        c.callback_number,
        c.issue,
        c.type,
        c.description,
        c.status,
        c.created_at,
        c.work_started_at,
        c.approved_at,

        a.asset_name,
        a.asset_type,
        a.location,

        owner.name AS owner_name,

        tech.name AS technician_name

    FROM callbacks c

    LEFT JOIN assets a
        ON a.id = c.asset_id

    LEFT JOIN users owner
        ON owner.id = a.owner_id

    LEFT JOIN users tech
        ON tech.id = c.technician_id

    WHERE c.type = 'fortnightly'

    ORDER BY c.created_at DESC
");


/* =========================================================
   COUNTS
========================================================= */

$totalRequests = 0;
$pendingRequests = 0;
$inProgressRequests = 0;
$approvedRequests = 0;
$closedRequests = 0;

$countResult = $conn->query("
    SELECT
        COUNT(*) AS total,
        SUM(status IN ('pending','assigned')) AS pending,
        SUM(status = 'in_progress') AS in_progress,
        SUM(status = 'approved') AS approved,
        SUM(status = 'closed') AS closed

    FROM callbacks

    WHERE type = 'fortnightly'
");

if ($countResult) {

    $counts = $countResult->fetch_assoc();

    $totalRequests =
        (int)($counts['total'] ?? 0);

    $pendingRequests =
        (int)($counts['pending'] ?? 0);

    $inProgressRequests =
        (int)($counts['in_progress'] ?? 0);

    $approvedRequests =
        (int)($counts['approved'] ?? 0);

    $closedRequests =
        (int)($counts['closed'] ?? 0);
}

?>


<style>

.fortnightly-card {
    border-radius: 14px;
    height: 100%;
}

.request-table th {
    white-space: nowrap;
    vertical-align: middle;
}

.request-table td {
    vertical-align: middle;
}

.status-badge {
    min-width: 110px;
    display: inline-block;
    text-align: center;
}

.issue-text {
    max-width: 300px;
    white-space: normal;
}

</style>


<div class="content">


<!-- =========================================================
     PAGE HEADER
========================================================= -->

<div class="d-flex justify-content-between align-items-center mb-4">

    <div>

        <h3 class="mb-1">
            Fortnightly Requests
        </h3>

        <small class="text-muted">
            All fortnightly requests submitted by owners and assigned to technicians
        </small>

    </div>


    <div>

        <a
            href="dashboard.php"
            class="btn btn-secondary">

            ← Dashboard

        </a>

    </div>

</div>



<!-- =========================================================
     SUMMARY CARDS
========================================================= -->

<div class="row g-3 mb-4">


    <!-- TOTAL -->

    <div class="col-md-3">

        <div class="card shadow-sm fortnightly-card p-3 text-center">

            <div style="font-size:30px;">
                🗓️
            </div>

            <h3 class="mb-1">
                <?= $totalRequests ?>
            </h3>

            <small class="text-muted">
                Total Fortnightly Requests
            </small>

        </div>

    </div>


    <!-- PENDING -->

    <div class="col-md-3">

        <div class="card shadow-sm fortnightly-card p-3 text-center">

            <div style="font-size:30px;">
                ⏳
            </div>

            <h3 class="text-warning mb-1">
                <?= $pendingRequests ?>
            </h3>

            <small class="text-muted">
                Pending
            </small>

        </div>

    </div>


    <!-- IN PROGRESS -->

    <div class="col-md-3">

        <div class="card shadow-sm fortnightly-card p-3 text-center">

            <div style="font-size:30px;">
                🛠️
            </div>

            <h3 class="text-primary mb-1">
                <?= $inProgressRequests ?>
            </h3>

            <small class="text-muted">
                In Progress
            </small>

        </div>

    </div>


    <!-- APPROVED -->

    <div class="col-md-3">

        <div class="card shadow-sm fortnightly-card p-3 text-center">

            <div style="font-size:30px;">
                ✅
            </div>

            <h3 class="text-success mb-1">
                <?= $approvedRequests ?>
            </h3>

            <small class="text-muted">
                Approved
            </small>

        </div>

    </div>

</div>



<!-- =========================================================
     REQUEST TABLE
========================================================= -->

<div class="card shadow-sm">

    <div class="card-header bg-dark text-white">

        <div class="d-flex justify-content-between align-items-center">

            <strong>
                All Fortnightly Requests
            </strong>

            <span class="badge bg-primary">
                <?= $totalRequests ?> Requests
            </span>

        </div>

    </div>


    <div class="card-body p-0">

        <div class="table-responsive">

            <table class="table table-hover mb-0 request-table">


                <thead class="table-light">

                    <tr>

                        <th>
                            #
                        </th>

                        <th>
                            Callback No.
                        </th>

                        <th>
                            Owner
                        </th>

                        <th>
                            Lift / Asset
                        </th>

                        <th>
                            Location
                        </th>

                        <th>
                            Technician
                        </th>

                        <th>
                            Issue
                        </th>

                        <th>
                            Date
                        </th>

                        <th>
                            Status
                        </th>

                    </tr>

                </thead>


                <tbody>


                <?php if ($requests && $requests->num_rows > 0): ?>


                    <?php

                    $counter = 1;

                    while ($row = $requests->fetch_assoc()):

                        $status =
                            strtolower(
                                trim(
                                    $row['status'] ?? ''
                                )
                            );


                        /*
                         * Status badge
                         */

                        switch ($status) {

                            case 'approved':

                                $badgeClass =
                                    'bg-success';

                                $statusText =
                                    'Approved';

                                break;


                            case 'in_progress':

                                $badgeClass =
                                    'bg-primary';

                                $statusText =
                                    'In Progress';

                                break;


                            case 'assigned':

                                $badgeClass =
                                    'bg-info text-dark';

                                $statusText =
                                    'Assigned';

                                break;


                            case 'pending':

                                $badgeClass =
                                    'bg-warning text-dark';

                                $statusText =
                                    'Pending';

                                break;


                            case 'closed':

                                $badgeClass =
                                    'bg-secondary';

                                $statusText =
                                    'Closed';

                                break;


                            case 'rejected':

                                $badgeClass =
                                    'bg-danger';

                                $statusText =
                                    'Rejected';

                                break;


                            default:

                                $badgeClass =
                                    'bg-dark';

                                $statusText =
                                    ucfirst(
                                        str_replace(
                                            '_',
                                            ' ',
                                            $status
                                        )
                                    );

                                break;

                        }

                    ?>


                    <tr>


                        <!-- NUMBER -->

                        <td>

                            <?= $counter++ ?>

                        </td>


                        <!-- CALLBACK -->

                        <td>

                            <strong>

                                <?= htmlspecialchars(
                                    $row['callback_number']
                                    ?? '—'
                                ) ?>

                            </strong>

                        </td>


                        <!-- OWNER -->

                        <td>

                            <?= htmlspecialchars(
                                $row['owner_name']
                                ?? '—'
                            ) ?>

                        </td>


                        <!-- ASSET -->

                        <td>

                            <strong>

                                <?= htmlspecialchars(
                                    $row['asset_name']
                                    ?? '—'
                                ) ?>

                            </strong>

                            <?php if (
                                !empty(
                                    $row['asset_type']
                                )
                            ): ?>

                                <br>

                                <small class="text-muted">

                                    <?= htmlspecialchars(
                                        $row['asset_type']
                                    ) ?>

                                </small>

                            <?php endif; ?>

                        </td>


                        <!-- LOCATION -->

                        <td>

                            <?= htmlspecialchars(
                                $row['location']
                                ?? '—'
                            ) ?>

                        </td>


                        <!-- TECHNICIAN -->

                        <td>

                            <?php if (
                                !empty(
                                    $row['technician_name']
                                )
                            ): ?>

                                <?= htmlspecialchars(
                                    $row['technician_name']
                                ) ?>

                            <?php else: ?>

                                <span class="text-muted">
                                    Not Assigned
                                </span>

                            <?php endif; ?>

                        </td>


                        <!-- ISSUE -->

                        <td>

                            <div class="issue-text">

                                <?= htmlspecialchars(
                                    $row['issue']
                                    ?? '—'
                                ) ?>

                            </div>

                            <?php if (
                                !empty(
                                    $row['description']
                                )
                            ): ?>

                                <small class="text-muted">

                                    <?= htmlspecialchars(
                                        $row['description']
                                    ) ?>

                                </small>

                            <?php endif; ?>

                        </td>


                        <!-- DATE -->

                        <td>

                            <?php

                            if (
                                !empty(
                                    $row['created_at']
                                )
                            ) {

                                echo date(
                                    'd M Y',
                                    strtotime(
                                        $row['created_at']
                                    )
                                );

                            } else {

                                echo '—';

                            }

                            ?>

                        </td>


                        <!-- STATUS -->

                        <td>

                            <span
                                class="badge status-badge <?= $badgeClass ?>">

                                <?= htmlspecialchars(
                                    $statusText
                                ) ?>

                            </span>

                        </td>


                    </tr>


                    <?php endwhile; ?>


                <?php else: ?>


                    <tr>

                        <td
                            colspan="9"
                            class="text-center py-5">

                            <div
                                style="font-size:45px;">

                                🗓️

                            </div>

                            <h5 class="mt-3">
                                No Fortnightly Requests
                            </h5>

                            <p class="text-muted mb-0">

                                There are currently no fortnightly
                                requests in the system.

                            </p>

                        </td>

                    </tr>


                <?php endif; ?>


                </tbody>

            </table>

        </div>

    </div>

</div>


</div>


<?php include('../includes/footer.php'); ?>