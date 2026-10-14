<?php
include('../config/db.php');
include('../config/auth.php');

require_role('admin');

include('../includes/header.php');
include('../includes/sidebar-admin.php');


/* =========================================================
   FETCH ALL FORTNIGHTLY REQUESTS
   These are the same requests sent to technicians
========================================================= */

$fortnightly = $conn->query("
    SELECT
        c.id,
        c.callback_number,
        c.issue,
        c.description,
        c.status,
        c.created_at,
        c.work_started_at,
        c.work_completed_at,

        a.asset_name,
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

    ORDER BY c.id DESC
");


/* =========================================================
   COUNTS
========================================================= */

$totalFortnightly = 0;
$pendingFortnightly = 0;
$inProgressFortnightly = 0;
$completedFortnightly = 0;

$countResult = $conn->query("
    SELECT
        COUNT(*) AS total,

        SUM(
            CASE
                WHEN status IN ('open', 'pending')
                THEN 1
                ELSE 0
            END
        ) AS pending,

        SUM(
            CASE
                WHEN status = 'in_progress'
                THEN 1
                ELSE 0
            END
        ) AS in_progress,

        SUM(
            CASE
                WHEN status IN ('closed', 'completed')
                THEN 1
                ELSE 0
            END
        ) AS completed

    FROM callbacks

    WHERE type = 'fortnightly'
");

if ($countResult) {

    $counts = $countResult->fetch_assoc();

    $totalFortnightly =
        (int)($counts['total'] ?? 0);

    $pendingFortnightly =
        (int)($counts['pending'] ?? 0);

    $inProgressFortnightly =
        (int)($counts['in_progress'] ?? 0);

    $completedFortnightly =
        (int)($counts['completed'] ?? 0);
}

?>

<div class="content">


<!-- =========================================================
     PAGE HEADER
========================================================= -->

<div class="d-flex justify-content-between align-items-center mb-4">

    <div>

        <h3>
            Fortnightly Requests
        </h3>

        <small class="text-muted">
            All Fortnightly requests received from Owners
        </small>

    </div>


    <a
        href="dashboard.php"
        class="btn btn-secondary">

        ← Back to Dashboard

    </a>

</div>


<!-- =========================================================
     SUMMARY CARDS
========================================================= -->

<div class="row g-3 mb-4">


    <!-- TOTAL -->

    <div class="col-md-3">

        <div class="card shadow-sm p-3 text-center">

            <strong class="fs-4">

                <?= $totalFortnightly ?>

            </strong>

            <small class="text-muted">

                Total Fortnightly

            </small>

        </div>

    </div>


    <!-- PENDING -->

    <div class="col-md-3">

        <div class="card shadow-sm p-3 text-center">

            <strong class="fs-4 text-warning">

                <?= $pendingFortnightly ?>

            </strong>

            <small class="text-muted">

                Pending

            </small>

        </div>

    </div>


    <!-- IN PROGRESS -->

    <div class="col-md-3">

        <div class="card shadow-sm p-3 text-center">

            <strong class="fs-4 text-primary">

                <?= $inProgressFortnightly ?>

            </strong>

            <small class="text-muted">

                In Progress

            </small>

        </div>

    </div>


    <!-- COMPLETED -->

    <div class="col-md-3">

        <div class="card shadow-sm p-3 text-center">

            <strong class="fs-4 text-success">

                <?= $completedFortnightly ?>

            </strong>

            <small class="text-muted">

                Completed

            </small>

        </div>

    </div>

</div>


<!-- =========================================================
     FORTNIGHTLY REQUEST TABLE
========================================================= -->

<div class="card shadow-sm">

    <div class="card-body">

        <h5 class="mb-3">
            All Fortnightly Requests
        </h5>


        <div class="table-responsive">

            <table class="table table-hover bg-white">

                <thead class="table-light">

                    <tr>

                        <th>#</th>

                        <th>Callback No.</th>

                        <th>Asset / Lift</th>

                        <th>Owner</th>

                        <th>Technician</th>

                        <th>Issue</th>

                        <th>Date</th>

                        <th>Status</th>

                    </tr>

                </thead>


                <tbody>

                <?php

                if (!$fortnightly ||
                    $fortnightly->num_rows == 0
                ):

                ?>

                    <tr>

                        <td
                            colspan="8"
                            class="text-center py-4">

                            No Fortnightly requests found.

                        </td>

                    </tr>

                <?php

                else:

                    $number = 1;

                    while (
                        $r = $fortnightly->fetch_assoc()
                    ):

                        /* STATUS BADGE */

                        $status =
                            strtolower(
                                trim(
                                    $r['status'] ?? ''
                                )
                            );


                        if (
                            $status === 'closed' ||
                            $status === 'completed'
                        ) {

                            $badge =
                                'success';

                            $statusText =
                                ucfirst(
                                    str_replace(
                                        '_',
                                        ' ',
                                        $status
                                    )
                                );

                        } elseif (
                            $status === 'in_progress'
                        ) {

                            $badge =
                                'primary';

                            $statusText =
                                'In Progress';

                        } elseif (
                            $status === 'open' ||
                            $status === 'pending'
                        ) {

                            $badge =
                                'warning';

                            $statusText =
                                ucfirst(
                                    $status
                                );

                        } else {

                            $badge =
                                'secondary';

                            $statusText =
                                $r['status'] ?? '—';

                        }


                        /* DATE */

                        $requestDate = '—';

                        if (
                            !empty(
                                $r['created_at']
                            )
                        ) {

                            $requestDate =
                                date(
                                    'd M Y',
                                    strtotime(
                                        $r['created_at']
                                    )
                                );

                        }

                ?>

                    <tr>

                        <!-- NUMBER -->

                        <td>

                            <?= $number++ ?>

                        </td>


                        <!-- CALLBACK -->

                        <td>

                            <strong>

                                <?= htmlspecialchars(
                                    $r['callback_number']
                                    ?? '—'
                                ) ?>

                            </strong>

                        </td>


                        <!-- ASSET -->

                        <td>

                            <strong>

                                <?= htmlspecialchars(
                                    $r['asset_name']
                                    ?? '—'
                                ) ?>

                            </strong>

                            <?php if (
                                !empty(
                                    $r['location']
                                )
                            ): ?>

                                <br>

                                <small
                                    class="text-muted">

                                    <?= htmlspecialchars(
                                        $r['location']
                                    ) ?>

                                </small>

                            <?php endif; ?>

                        </td>


                        <!-- OWNER -->

                        <td>

                            <?= htmlspecialchars(
                                $r['owner_name']
                                ?? '—'
                            ) ?>

                        </td>


                        <!-- TECHNICIAN -->

                        <td>

                            <?= htmlspecialchars(
                                $r['technician_name']
                                ?? 'Not Assigned'
                            ) ?>

                        </td>


                        <!-- ISSUE -->

                        <td>

                            <?= htmlspecialchars(
                                $r['issue']
                                ?? '—'
                            ) ?>

                        </td>


                        <!-- DATE -->

                        <td>

                            <?= $requestDate ?>

                        </td>


                        <!-- STATUS -->

                        <td>

                            <span
                                class="badge bg-<?= $badge ?>">

                                <?= htmlspecialchars(
                                    $statusText
                                ) ?>

                            </span>

                        </td>

                    </tr>

                <?php

                    endwhile;

                endif;

                ?>

                </tbody>

            </table>

        </div>

    </div>

</div>


</div>


<?php include('../includes/footer.php'); ?>