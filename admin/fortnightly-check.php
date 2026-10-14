<?php
include('../config/db.php');
include('../config/auth.php');
require_once '../config/security.php';

require_role('admin');

$admin_id = (int)$_SESSION['uid'];

$items = [
    "Abnormal sound or jerk during lift movement",
    "Doors not closing properly",
    "Lift stopping unexpectedly",
    "Buttons not responding sometimes",
    "Lift moving slowly",
    "Floor level mismatch",
    "Cabin light flickering",
    "Display not working properly",
    "Lift shaking slightly while moving",
    "Lift getting stuck between floors",
    "Unusual smell near the lift",
    "Lift not responding to calls on some floors",
    "Doors opening and closing repeatedly",
    "Lift stopping longer than usual at floors",
    "Sudden jerks during start or stop",
    "Door sensor not working properly",
    "Others"
];


/* =========================================================
   FETCH FIRST ASSET WITH ITS TECHNICIAN
========================================================= */

$assetRow = $conn->query("
    SELECT
        a.id,
        u.assigned_technician_id
    FROM assets a
    LEFT JOIN users u
        ON u.id = a.owner_id
    ORDER BY a.id
    LIMIT 1
")->fetch_assoc();

$firstAsset     = (int)($assetRow['id'] ?? 0);
$firstAssetTech = (int)($assetRow['assigned_technician_id'] ?? 0);


/* =========================================================
   CHECK 15-DAY COOLDOWN
========================================================= */

$cooldownMsg = null;

$lastCheckRow = $conn->query("
    SELECT check_date
    FROM fortnightly_checks
    WHERE owner_id = $admin_id
    ORDER BY check_date DESC
    LIMIT 1
")->fetch_assoc();

if ($lastCheckRow) {

    $lastDate = new DateTime($lastCheckRow['check_date']);

    $nextAllowed = clone $lastDate;
    $nextAllowed->modify('+15 days');

    $today = new DateTime(date('Y-m-d'));

    if ($today < $nextAllowed) {

        $cooldownMsg =
            "You can submit the next fortnightly check on <strong>" .
            $nextAllowed->format('d M Y') .
            "</strong>. (Last submitted: " .
            $lastDate->format('d M Y') .
            ")";
    }
}


/* =========================================================
   SAVE FORTNIGHTLY CHECK
========================================================= */

if (isset($_POST['save'])) {

    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {

        $_SESSION['fc_error'] =
            'Invalid request. Please try again.';

        header("Location: fortnightly-check.php");
        exit;
    }

    $asset_id   = $firstAsset;
    $check_date = $_POST['check_date'] ?? date('Y-m-d');
    $remarks    = trim($_POST['remarks'] ?? '');

    $results    = [];
    $complaints = [];


    /* =====================================================
       CHECKLIST
    ===================================================== */

    foreach ($items as $i => $item) {

        $val = $_POST["status_$i"] ?? 'not_ok';

        $val = in_array(
            $val,
            ['ok', 'not_ok'],
            true
        )
        ? $val
        : 'not_ok';

        $results[$item] = $val;

        if ($val === 'not_ok') {
            $complaints[] = $item;
        }
    }


    $checklist_json = json_encode(
        $results,
        JSON_UNESCAPED_UNICODE
    );


    /* =====================================================
       SAVE FORTNIGHTLY CHECK
    ===================================================== */

    $stmt = $conn->prepare("
        INSERT INTO fortnightly_checks
        (
            asset_id,
            owner_id,
            check_date,
            checklist,
            remarks
        )
        VALUES
        (
            ?,
            ?,
            ?,
            ?,
            ?
        )
    ");

    $stmt->bind_param(
        "iisss",
        $asset_id,
        $admin_id,
        $check_date,
        $checklist_json,
        $remarks
    );


    if ($stmt->execute()) {


        /* =================================================
           CREATE CALLBACKS FOR NOT OK ITEMS
        ================================================= */

        if (
            $asset_id > 0 &&
            count($complaints) > 0
        ) {

            $tech_id = $firstAssetTech;


            if ($tech_id <= 0) {

                $_SESSION['fc_error'] =
                    "No technician assigned to this lift. Please assign one via admin.";

                header("Location: fortnightly-check.php");
                exit;
            }


            require_once '../config/callback_helper.php';


            foreach ($complaints as $issue) {

                $cb_no = generateCallbackNumber($conn);

                $desc =
                    "Auto-generated from Admin Fortnightly Check on " .
                    $check_date;


                /*
                 * KEEPING YOUR OLD BEHAVIOUR:
                 *
                 * New fortnightly complaints start as
                 * in_progress.
                 *
                 * Their later status can become:
                 * pending
                 * assigned
                 * in_progress
                 * approved
                 * rejected
                 * done
                 */

                $ins = $conn->prepare("
                    INSERT INTO callbacks
                    (
                        asset_id,
                        technician_id,
                        callback_number,
                        issue,
                        type,
                        description,
                        status,
                        work_started_at
                    )
                    VALUES
                    (
                        ?,
                        ?,
                        ?,
                        ?,
                        'fortnightly',
                        ?,
                        'in_progress',
                        NOW()
                    )
                ");

                $ins->bind_param(
                    "iisss",
                    $asset_id,
                    $tech_id,
                    $cb_no,
                    $issue,
                    $desc
                );

                $ins->execute();
            }


            /* =============================================
               UPDATE ASSET STATUS
            ============================================= */

            $upd = $conn->prepare("
                UPDATE assets
                SET status = 'Under Maintenance'
                WHERE id = ?
            ");

            $upd->bind_param(
                "i",
                $asset_id
            );

            $upd->execute();


            $_SESSION['fc_success'] =
                "Fortnightly check submitted. " .
                count($complaints) .
                " complaint(s) sent to technician.";

        } else {

            $_SESSION['fc_success'] =
                "Fortnightly check submitted successfully!";
        }


    } else {

        $_SESSION['fc_error'] =
            "Error: " . $stmt->error;
    }


    header("Location: fortnightly-check.php");
    exit;
}


/* =========================================================
   FETCH ALL FORTNIGHTLY CALLBACKS
   SHOW ALL STATUSES
========================================================= */

$fortnightlyCallbacks = [];

$callbackQuery = $conn->query("
    SELECT
        c.id,
        c.callback_number,
        c.issue,
        c.description,
        c.status,
        c.created_at,
        c.work_started_at,
        c.technician_responded_at,
        c.technician_response,
        c.approved_at,
        c.completed_at,

        a.id AS asset_id,

        tech.name AS technician_name

    FROM callbacks c

    LEFT JOIN assets a
        ON a.id = c.asset_id

    LEFT JOIN users tech
        ON tech.id = c.technician_id

    WHERE c.type = 'fortnightly'

    ORDER BY c.id DESC
");


if ($callbackQuery) {

    while ($row = $callbackQuery->fetch_assoc()) {

        $fortnightlyCallbacks[] = $row;
    }
}


/* =========================================================
   STATUS COUNTS FOR FORTNIGHTLY SECTION ONLY
========================================================= */

$fortnightlyStatusCounts = [
    'pending'     => 0,
    'assigned'    => 0,
    'in_progress' => 0,
    'approved'    => 0,
    'rejected'    => 0,
    'done'        => 0
];


$countQuery = $conn->query("
    SELECT
        LOWER(status) AS status,
        COUNT(*) AS total

    FROM callbacks

    WHERE type = 'fortnightly'

    GROUP BY LOWER(status)
");


if ($countQuery) {

    while ($row = $countQuery->fetch_assoc()) {

        $status = strtolower(
            trim($row['status'])
        );

        if (
            isset(
                $fortnightlyStatusCounts[$status]
            )
        ) {

            $fortnightlyStatusCounts[$status] =
                (int)$row['total'];
        }
    }
}


include('../includes/header.php');
include('../includes/sidebar-admin.php');
?>


<div class="content">

<h3 class="mb-4">
    Fortnightly (15 Days) Operational Check by Admin
</h3>


<!-- =====================================================
     SUCCESS MESSAGE
===================================================== -->

<?php if (!empty($_SESSION['fc_success'])): ?>

<div class="alert alert-success alert-dismissible fade show"
     id="fc-alert">

    <?= htmlspecialchars($_SESSION['fc_success']) ?>

    <button
        type="button"
        class="btn-close"
        onclick="this.closest('.alert').remove()">
    </button>

</div>

<?php unset($_SESSION['fc_success']); ?>

<?php endif; ?>


<!-- =====================================================
     ERROR MESSAGE
===================================================== -->

<?php if (!empty($_SESSION['fc_error'])): ?>

<div class="alert alert-danger alert-dismissible fade show"
     id="fc-alert-err">

    <?= htmlspecialchars($_SESSION['fc_error']) ?>

    <button
        type="button"
        class="btn-close"
        onclick="this.closest('.alert').remove()">
    </button>

</div>

<?php unset($_SESSION['fc_error']); ?>

<?php endif; ?>


<!-- =====================================================
     FORTNIGHTLY STATUS SUMMARY
===================================================== -->

<div class="row g-3 mb-4">

    <div class="col-md-2">
        <div class="card shadow-sm border-warning">
            <div class="card-body">
                <small class="text-muted">
                    Pending
                </small>

                <h3 class="mb-0">
                    <?= $fortnightlyStatusCounts['pending'] ?>
                </h3>
            </div>
        </div>
    </div>


    <div class="col-md-2">
        <div class="card shadow-sm border-info">
            <div class="card-body">
                <small class="text-muted">
                    Assigned
                </small>

                <h3 class="mb-0">
                    <?= $fortnightlyStatusCounts['assigned'] ?>
                </h3>
            </div>
        </div>
    </div>


    <div class="col-md-2">
        <div class="card shadow-sm border-primary">
            <div class="card-body">
                <small class="text-muted">
                    In Progress
                </small>

                <h3 class="mb-0">
                    <?= $fortnightlyStatusCounts['in_progress'] ?>
                </h3>
            </div>
        </div>
    </div>


    <div class="col-md-2">
        <div class="card shadow-sm border-success">
            <div class="card-body">
                <small class="text-muted">
                    Approved
                </small>

                <h3 class="mb-0">
                    <?= $fortnightlyStatusCounts['approved'] ?>
                </h3>
            </div>
        </div>
    </div>


    <div class="col-md-2">
        <div class="card shadow-sm border-danger">
            <div class="card-body">
                <small class="text-muted">
                    Rejected
                </small>

                <h3 class="mb-0">
                    <?= $fortnightlyStatusCounts['rejected'] ?>
                </h3>
            </div>
        </div>
    </div>


    <div class="col-md-2">
        <div class="card shadow-sm border-secondary">
            <div class="card-body">
                <small class="text-muted">
                    Done
                </small>

                <h3 class="mb-0">
                    <?= $fortnightlyStatusCounts['done'] ?>
                </h3>
            </div>
        </div>
    </div>

</div>


<!-- =====================================================
     SUBMIT NEW CHECK
===================================================== -->

<?php if ($cooldownMsg): ?>

<div class="alert alert-warning shadow-sm">

    🕐 <?= $cooldownMsg ?>

</div>

<?php else: ?>


<div class="card shadow p-4 mb-4"
     style="max-width:800px;">

<form method="POST">

    <?= csrfField() ?>


    <div class="mb-3">

        <label class="form-label fw-semibold">
            Check Date
        </label>

        <input
            type="date"
            name="check_date"
            class="form-control"
            value="<?= date('Y-m-d') ?>"
            required>

    </div>


    <input
        type="hidden"
        name="asset_id"
        value="<?= $firstAsset ?>">


    <!-- CHECKLIST HEADER -->

    <div class="d-flex align-items-center justify-content-between mb-2">

        <p class="fw-semibold mb-0">
            Checklist Items
        </p>

        <div class="d-flex gap-2">

            <button
                type="button"
                class="btn btn-success btn-sm"
                onclick="setAll('ok')">

                ✅ All OK

            </button>


            <button
                type="button"
                class="btn btn-danger btn-sm"
                onclick="setAll('not_ok')">

                ❌ All Not OK

            </button>

        </div>

    </div>


    <!-- CHECKLIST ITEMS -->

    <div class="mb-3">

        <?php foreach ($items as $i => $item): ?>

        <div
            class="d-flex align-items-center justify-content-between p-3 mb-2 rounded border"
            id="row_<?= $i ?>"
            style="background:#f8f9fa;">

            <span class="flex-grow-1 me-3">

                <?= $i + 1 ?>.
                <?= htmlspecialchars($item) ?>

            </span>


            <div class="d-flex gap-2">

                <button
                    type="button"
                    id="btn_ok_<?= $i ?>"
                    onclick="setStatus(<?= $i ?>, 'ok')"
                    class="btn btn-sm btn-outline-success">

                    ✅ OK

                </button>


                <button
                    type="button"
                    id="btn_notok_<?= $i ?>"
                    onclick="setStatus(<?= $i ?>, 'not_ok')"
                    class="btn btn-sm btn-outline-danger">

                    ❌ Not OK

                </button>


                <input
                    type="hidden"
                    name="status_<?= $i ?>"
                    id="status_<?= $i ?>"
                    value="">

            </div>

        </div>

        <?php endforeach; ?>

    </div>


    <!-- NOT OK SUMMARY -->

    <div
        id="notOkSummary"
        class="alert alert-warning d-none mb-3">

        <strong>
            ⚠ Issues found:
        </strong>

        <ul
            id="notOkList"
            class="mb-0 mt-1">
        </ul>

        <small class="text-muted">

            These will be auto-converted to complaints
            for the technician.

        </small>

    </div>


    <!-- REMARKS -->

    <div class="mb-3">

        <label class="form-label fw-semibold">
            Remarks (optional)
        </label>

        <textarea
            name="remarks"
            class="form-control"
            rows="3"
            placeholder="Any additional observations..."></textarea>

    </div>


    <button
        name="save"
        class="btn btn-primary w-100">

        Submit Fortnightly Check

    </button>

</form>

</div>


<?php endif; ?>


<!-- =====================================================
     FORTNIGHTLY REQUESTS / COMPLAINTS
     ALL STATUS ARE SHOWN HERE
===================================================== -->

<div class="card shadow-sm mt-4">

    <div class="card-header">

        <h5 class="mb-0">
            Fortnightly Requests
        </h5>

    </div>


    <div class="card-body p-0">

        <?php if (count($fortnightlyCallbacks) > 0): ?>

        <div class="table-responsive">

            <table class="table table-hover mb-0">

                <thead>

                    <tr>

                        <th>#</th>

                        <th>Callback No.</th>

                        <th>Issue</th>

                        <th>Technician</th>

                        <th>Status</th>

                        <th>Created</th>

                    </tr>

                </thead>


                <tbody>

                <?php foreach (
                    $fortnightlyCallbacks
                    as $index => $callback
                ): ?>

                <?php

                    $status =
                        strtolower(
                            trim(
                                $callback['status'] ?? ''
                            )
                        );


                    switch ($status) {

                        case 'pending':
                            $badgeClass =
                                'bg-warning text-dark';
                            break;

                        case 'assigned':
                            $badgeClass =
                                'bg-info text-dark';
                            break;

                        case 'in_progress':
                            $badgeClass =
                                'bg-primary';
                            break;

                        case 'approved':
                            $badgeClass =
                                'bg-success';
                            break;

                        case 'rejected':
                            $badgeClass =
                                'bg-danger';
                            break;

                        case 'done':
                            $badgeClass =
                                'bg-secondary';
                            break;

                        default:
                            $badgeClass =
                                'bg-dark';
                            break;
                    }

                ?>

                <tr>

                    <td>
                        <?= $index + 1 ?>
                    </td>


                    <td>
                        <strong>
                            <?= htmlspecialchars(
                                $callback['callback_number']
                                ?? '-'
                            ) ?>
                        </strong>
                    </td>


                    <td>

                        <?= htmlspecialchars(
                            $callback['issue']
                            ?? '-'
                        ) ?>

                    </td>


                    <td>

                        <?= htmlspecialchars(
                            $callback['technician_name']
                            ?? 'Not Assigned'
                        ) ?>

                    </td>


                    <td>

                        <span
                            class="badge <?= $badgeClass ?>">

                            <?= htmlspecialchars(
                                ucwords(
                                    str_replace(
                                        '_',
                                        ' ',
                                        $status
                                    )
                                )
                            ) ?>

                        </span>

                    </td>


                    <td>

                        <?= !empty(
                            $callback['created_at']
                        )
                        ? date(
                            'd M Y, h:i A',
                            strtotime(
                                $callback['created_at']
                            )
                        )
                        : '-'
                        ?>

                    </td>

                </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

        </div>

        <?php else: ?>

        <div class="p-4 text-center text-muted">

            No fortnightly requests found.

        </div>

        <?php endif; ?>

    </div>

</div>


</div>


<?php include('../includes/footer.php'); ?>


<script>

const totalItems =
    <?= count($items) ?>;

const itemNames =
    <?= json_encode(
        array_values($items),
        JSON_UNESCAPED_UNICODE
    ) ?>;


function setStatus(i, val) {

    document.getElementById(
        'status_' + i
    ).value = val;


    const row =
        document.getElementById(
            'row_' + i
        );


    const btnOk =
        document.getElementById(
            'btn_ok_' + i
        );


    const btnNotOk =
        document.getElementById(
            'btn_notok_' + i
        );


    if (val === 'ok') {

        row.style.background =
            '#d4edda';

        row.style.borderColor =
            '#28a745';

        btnOk.className =
            'btn btn-sm btn-success';

        btnNotOk.className =
            'btn btn-sm btn-outline-danger';

    } else {

        row.style.background =
            '#f8d7da';

        row.style.borderColor =
            '#dc3545';

        btnOk.className =
            'btn btn-sm btn-outline-success';

        btnNotOk.className =
            'btn btn-sm btn-danger';
    }


    updateSummary();
}


function setAll(val) {

    for (
        let i = 0;
        i < totalItems;
        i++
    ) {

        setStatus(i, val);
    }
}


function updateSummary() {

    const list =
        document.getElementById(
            'notOkList'
        );


    const summary =
        document.getElementById(
            'notOkSummary'
        );


    list.innerHTML = '';


    let count = 0;


    for (
        let i = 0;
        i < totalItems;
        i++
    ) {

        if (
            document.getElementById(
                'status_' + i
            ).value === 'not_ok'
        ) {

            list.innerHTML +=
                `<li>${itemNames[i]}</li>`;

            count++;
        }
    }


    summary.classList.toggle(
        'd-none',
        count === 0
    );
}


const form =
    document.querySelector('form');


if (form) {

    form.addEventListener(
        'submit',
        function(e) {

            for (
                let i = 0;
                i < totalItems;
                i++
            ) {

                if (
                    !document.getElementById(
                        'status_' + i
                    ).value
                ) {

                    e.preventDefault();

                    alert(
                        'Please select OK or Not OK for all checklist items.'
                    );

                    return;
                }
            }

        }
    );
}

</script>