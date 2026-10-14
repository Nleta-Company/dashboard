<?php

include('../config/db.php');
include('../config/auth.php');
require_once('../config/security.php');

require_role('technician');

$tech_id = (int)$_SESSION['uid'];


/* =========================================================
   REJECTION REASONS
========================================================= */

$rejectionReasons = [
    'Already assigned to another job',
    'Not available at this time',
    'Location too far',
    'Emergency work assigned',
    'Leave / unavailable',
    'Insufficient time to attend',
    'Issue outside my responsibility',
    'Other'
];


/* =========================================================
   SEARCH
========================================================= */

$searchError = '';
$searchResult = null;


if (isset($_POST['search_cb'])) {

    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {

        $searchError =
            'Invalid request. Please try again.';

    } else {

        $cb_number =
            trim($_POST['callback_number'] ?? '');


        if (empty($cb_number)) {

            $searchError =
                'Please enter a callback number.';

        } else {

            $s = $conn->prepare("
                SELECT
                    c.id,
                    c.callback_number,
                    c.issue,
                    c.status,
                    c.technician_response,
                    c.technician_reject_reason,

                    COALESCE(
                        a.asset_name,
                        'General Check'
                    ) AS asset_name

                FROM callbacks c

                LEFT JOIN assets a
                    ON a.id = c.asset_id

                WHERE c.callback_number = ?
                  AND c.technician_id = ?

                LIMIT 1
            ");

            $s->bind_param(
                "si",
                $cb_number,
                $tech_id
            );

            $s->execute();

            $searchResult =
                $s->get_result()->fetch_assoc();


            if (!$searchResult) {

                $searchError =
                    'Callback number not found or not assigned to you.';
            }
        }
    }
}


/* =========================================================
   GET ALL CALLBACKS
========================================================= */

$cq = $conn->prepare("
    SELECT

        c.id,
        c.callback_number,
        c.issue,
        c.status,
        c.created_at,

        c.technician_response,
        c.technician_reject_reason,
        c.technician_responded_at,

        COALESCE(
            a.asset_name,
            'General Check'
        ) AS asset_name

    FROM callbacks c

    LEFT JOIN assets a
        ON a.id = c.asset_id

    WHERE c.technician_id = ?

    ORDER BY c.created_at DESC
");

$cq->bind_param(
    "i",
    $tech_id
);

$cq->execute();

$q = $cq->get_result();


include('../includes/header.php');
include('../includes/sidebar-tech.php');

?>

<div class="content">

    <h3 class="mb-4">
        My Callbacks
    </h3>


    <!-- =====================================================
         SUCCESS
    ====================================================== -->

    <?php if (!empty($_SESSION['success'])): ?>

        <div class="alert alert-success alert-dismissible fade show">

            <?= htmlspecialchars($_SESSION['success']) ?>

            <?php unset($_SESSION['success']); ?>

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

            <?= htmlspecialchars($_SESSION['error']) ?>

            <?php unset($_SESSION['error']); ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert">
            </button>

        </div>

    <?php endif; ?>


    <!-- =====================================================
         SEARCH
    ====================================================== -->

    <div
        class="card shadow p-4 mb-4"
        style="max-width:600px">

        <h6 class="fw-bold mb-3">
            🔍 Search Callback
        </h6>


        <form method="POST" class="d-flex gap-2">

            <?= csrfField() ?>

            <input
                type="text"
                name="callback_number"
                class="form-control"
                placeholder="Enter callback number"
                value="<?= htmlspecialchars(
                    $_POST['callback_number'] ?? ''
                ) ?>"
                required>


            <button
                name="search_cb"
                class="btn btn-primary">

                Search

            </button>

        </form>


        <?php if ($searchError): ?>

            <div class="alert alert-danger mt-3 mb-0">

                <?= htmlspecialchars($searchError) ?>

            </div>

        <?php endif; ?>


        <?php if ($searchResult): ?>

            <div class="alert alert-info mt-3 mb-0">

                <strong>
                    <?= htmlspecialchars(
                        $searchResult['callback_number']
                    ) ?>
                </strong>

                <br>

                <small>

                    Lift:
                    <?= htmlspecialchars(
                        $searchResult['asset_name']
                    ) ?>

                    <br>

                    Issue:
                    <?= htmlspecialchars(
                        $searchResult['issue']
                    ) ?>

                </small>

            </div>

        <?php endif; ?>

    </div>


    <!-- =====================================================
         CALLBACK TABLE
    ====================================================== -->

    <div class="table-responsive">

        <table
            class="table table-hover bg-white shadow">

            <thead class="table-dark">

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
                        Technician Response
                    </th>

                    <th>
                        Status
                    </th>

                    <th>
                        Received
                    </th>

                    <th>
                        Action
                    </th>

                </tr>

            </thead>


            <tbody>


            <?php if ($q->num_rows === 0): ?>

                <tr>

                    <td
                        colspan="7"
                        class="text-center text-muted py-4">

                        No callbacks assigned to you.

                    </td>

                </tr>

            <?php endif; ?>


            <?php while ($r = $q->fetch_assoc()): ?>


                <tr>


                    <!-- CALLBACK NUMBER -->

                    <td>

                        <strong>

                            <?= htmlspecialchars(
                                $r['callback_number']
                            ) ?>

                        </strong>

                    </td>


                    <!-- LIFT -->

                    <td>

                        <?= htmlspecialchars(
                            $r['asset_name']
                        ) ?>

                    </td>


                    <!-- ISSUE -->

                    <td>

                        <?= htmlspecialchars(
                            $r['issue']
                        ) ?>

                    </td>


                    <!-- TECHNICIAN RESPONSE -->

                    <td>


                        <?php if (
                            $r['technician_response'] === null
                            || $r['technician_response'] === ''
                        ): ?>

                            <span
                                class="badge bg-warning text-dark">

                                Waiting for Response

                            </span>


                        <?php elseif (
                            $r['technician_response'] === 'accepted'
                        ): ?>

                            <span
                                class="badge bg-success">

                                Accepted ✓

                            </span>


                        <?php elseif (
                            $r['technician_response'] === 'rejected'
                        ): ?>

                            <span
                                class="badge bg-danger">

                                Rejected

                            </span>

                            <?php if (
                                !empty(
                                    $r['technician_reject_reason']
                                )
                            ): ?>

                                <br>

                                <small class="text-danger">

                                    <?= htmlspecialchars(
                                        $r['technician_reject_reason']
                                    ) ?>

                                </small>

                            <?php endif; ?>


                        <?php endif; ?>


                    </td>


                    <!-- STATUS -->

                    <td>


                        <?php

                        if (
                            $r['technician_response'] === null
                            || $r['technician_response'] === ''
                        ) {

                            echo '<span class="badge bg-warning text-dark">
                                    Waiting
                                  </span>';

                        } elseif (
                            $r['technician_response'] === 'accepted'
                            && $r['status'] === 'in_progress'
                        ) {

                            echo '<span class="badge bg-primary">
                                    In Progress
                                  </span>';

                        } elseif (
                            $r['technician_response'] === 'rejected'
                        ) {

                            echo '<span class="badge bg-danger">
                                    Rejected
                                  </span>';

                        } elseif (
                            $r['status'] === 'closed'
                        ) {

                            echo '<span class="badge bg-info text-dark">
                                    Pending Approval
                                  </span>';

                        } elseif (
                            $r['status'] === 'approved'
                        ) {

                            echo '<span class="badge bg-success">
                                    Approved ✓
                                  </span>';

                        } else {

                            echo '<span class="badge bg-secondary">
                                    ' .
                                    htmlspecialchars(
                                        ucfirst(
                                            str_replace(
                                                '_',
                                                ' ',
                                                $r['status']
                                            )
                                        )
                                    )
                                    .
                                    '</span>';
                        }

                        ?>

                    </td>


                    <!-- CREATED -->

                    <td>

                        <small>

                            <?= format_dt(
                                $r['created_at']
                            ) ?>

                        </small>

                    </td>


                    <!-- ACTION -->

                    <td>


                        <?php if (
                            empty(
                                $r['technician_response']
                            )
                        ): ?>


                            <!-- ACCEPT -->

                            <form
                                method="POST"
                                action="callback-response.php"
                                class="d-inline">

                                <?= csrfField() ?>

                                <input
                                    type="hidden"
                                    name="callback_id"
                                    value="<?= (int)$r['id'] ?>">

                                <input
                                    type="hidden"
                                    name="response"
                                    value="accepted">


                                <button
                                    type="submit"
                                    class="btn btn-sm btn-success"
                                    onclick="return confirm('Accept this callback?');">

                                    Accept

                                </button>

                            </form>


                            <!-- REJECT -->

                            <button
                                type="button"
                                class="btn btn-sm btn-danger"
                                data-bs-toggle="modal"
                                data-bs-target="#rejectModal<?= (int)$r['id'] ?>">

                                Reject

                            </button>


                            <!-- REJECT MODAL -->

                            <div
                                class="modal fade"
                                id="rejectModal<?= (int)$r['id'] ?>"
                                tabindex="-1">

                                <div
                                    class="modal-dialog">

                                    <div
                                        class="modal-content">


                                        <div
                                            class="modal-header">

                                            <h5
                                                class="modal-title">

                                                Reject Callback

                                            </h5>

                                            <button
                                                type="button"
                                                class="btn-close"
                                                data-bs-dismiss="modal">
                                            </button>

                                        </div>


                                        <form
                                            method="POST"
                                            action="callback-response.php">


                                            <div
                                                class="modal-body">

                                                <?= csrfField() ?>


                                                <input
                                                    type="hidden"
                                                    name="callback_id"
                                                    value="<?= (int)$r['id'] ?>">


                                                <input
                                                    type="hidden"
                                                    name="response"
                                                    value="rejected">


                                                <div class="mb-3">

                                                    <label
                                                        class="form-label fw-bold">

                                                        Reason for Rejection

                                                    </label>


                                                    <select
                                                        name="reject_reason"
                                                        class="form-select"
                                                        required>

                                                        <option
                                                            value="">

                                                            -- Select Reason --

                                                        </option>


                                                        <?php foreach (
                                                            $rejectionReasons
                                                            as $reason
                                                        ): ?>

                                                            <option
                                                                value="<?= htmlspecialchars($reason) ?>">

                                                                <?= htmlspecialchars($reason) ?>

                                                            </option>

                                                        <?php endforeach; ?>

                                                    </select>

                                                </div>


                                            </div>


                                            <div
                                                class="modal-footer">

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


                        <?php elseif (
                            $r['technician_response'] === 'accepted'
                            && $r['status'] === 'in_progress'
                        ): ?>


                            <a
                                href="submit-report.php?id=<?= (int)$r['id'] ?>"
                                class="btn btn-sm btn-primary">

                                Submit Report

                            </a>


                        <?php else: ?>

                            <span class="text-muted">
                                —
                            </span>

                        <?php endif; ?>


                    </td>


                </tr>


            <?php endwhile; ?>


            </tbody>

        </table>

    </div>

</div>


<?php include('../includes/footer.php'); ?>