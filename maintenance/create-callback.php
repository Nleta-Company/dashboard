<?php

include('../config/db.php');
include('../config/auth.php');
require_once('../config/security.php');

require_role('technician');

$tech_id = (int)$_SESSION['uid'];

/* =========================================================
   CREATE CALLBACK
========================================================= */

if (isset($_POST['submit'])) {

    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {

        $_SESSION['error'] =
            'Invalid request. Please try again.';

        header("Location: create-callback.php");
        exit;
    }

    $asset_id = (int)($_POST['asset_id'] ?? 0);

    $issue = trim(
        $_POST['issue'] ?? ''
    );

    if (!$asset_id || !$issue) {

        $_SESSION['error'] =
            "All fields are required";

        header("Location: create-callback.php");
        exit;
    }

    /* =====================================================
       VERIFY TECHNICIAN IS ASSIGNED TO LIFT
    ===================================================== */

    $chk = $conn->prepare("
        SELECT
            a.asset_name

        FROM maintenance_jobs mj

        JOIN assets a
            ON a.id = mj.asset_id

        WHERE mj.asset_id = ?

          AND mj.technician_id = ?

          AND mj.status IN (
              'assigned',
              'in_progress'
          )

        LIMIT 1
    ");

    $chk->bind_param(
        "ii",
        $asset_id,
        $tech_id
    );

    $chk->execute();

    $asset = $chk
        ->get_result()
        ->fetch_assoc();

    if (!$asset) {

        $_SESSION['error'] =
            "Invalid or unauthorized lift";

        header("Location: create-callback.php");
        exit;
    }

    /* =====================================================
       CALLBACK NUMBER
    ===================================================== */

    $callback_number =
        'CB-' .
        time() .
        '-' .
        rand(100, 999);

    /* =====================================================
       IMPORTANT
       
       CALLBACK STARTS AS OPEN.
       
       TECHNICIAN MUST ACCEPT IT.
    ===================================================== */

    $stmt = $conn->prepare("
        INSERT INTO callbacks
        (
            asset_id,
            callback_number,
            issue,
            status,
            technician_id,
            created_at
        )

        VALUES
        (
            ?,
            ?,
            ?,
            'open',
            ?,
            NOW()
        )
    ");

    $stmt->bind_param(
        "issi",
        $asset_id,
        $callback_number,
        $issue,
        $tech_id
    );

    if (!$stmt->execute()) {

        $_SESSION['error'] =
            "Failed to create callback. Please try again.";

        header("Location: create-callback.php");
        exit;
    }

    /* =====================================================
       DO NOT CHANGE ASSET STATUS TO UNDER MAINTENANCE YET.
       
       TECHNICIAN HAS NOT ACCEPTED THE CALLBACK.
    ===================================================== */

    $_SESSION['success'] =
        "Callback Created | ID: $callback_number";

    header("Location: callbacks.php");
    exit;
}

/* =========================================================
   FETCH LIFTS
========================================================= */

$a = $conn->prepare("
    SELECT DISTINCT
        a.id,
        a.asset_name

    FROM maintenance_jobs mj

    JOIN assets a
        ON a.id = mj.asset_id

    WHERE mj.technician_id = ?

      AND mj.status IN (
          'assigned',
          'in_progress'
      )

    ORDER BY a.asset_name
");

$a->bind_param(
    "i",
    $tech_id
);

$a->execute();

$lifts = $a
    ->get_result()
    ->fetch_all(
        MYSQLI_ASSOC
    );

/* =========================================================
   ISSUES
========================================================= */

$issues = [

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

include('../includes/header.php');
include('../includes/sidebar-tech.php');

?>

<style>

.custom-select-wrapper {
    position: relative;
    width: 100%;
}

.custom-select-box {

    border: 1px solid #ced4da;
    border-radius: 6px;

    padding: 8px 36px 8px 12px;

    background: #fff;

    cursor: pointer;

    user-select: none;

    font-size: 15px;

    color: #333;

    position: relative;
}

.custom-select-box::after {

    content: "\25BC";

    position: absolute;

    right: 12px;

    top: 50%;

    transform: translateY(-50%);

    font-size: 11px;

    color: #666;

    pointer-events: none;
}

.custom-select-dropdown {

    display: none;

    position: absolute;

    top: calc(100% + 4px);

    left: 0;

    right: 0;

    background: #fff;

    border: 1px solid #ced4da;

    border-radius: 6px;

    z-index: 99999;

    max-height: 220px;

    overflow-y: auto;

    box-shadow:
        0 4px 16px
        rgba(0,0,0,0.12);
}

.custom-select-dropdown.open {
    display: block;
}

.custom-select-option {

    padding: 9px 14px;

    cursor: pointer;

    font-size: 14px;

    color: #333;
}

.custom-select-option:hover {
    background: #f0f4ff;
}

.custom-select-option.selected {

    background: #e7f0ff;

    font-weight: 600;
}

</style>

<div class="content">

    <h3 class="mb-4">
        Create Callback
    </h3>

    <?php if (!empty($_SESSION['error'])): ?>

        <div class="alert alert-danger alert-dismissible fade show">

            <?= htmlspecialchars(
                $_SESSION['error']
            ); ?>

            <?php unset($_SESSION['error']); ?>

            <button
                type="button"
                class="btn-close"
                onclick="this.closest('.alert').remove()">
            </button>

        </div>

    <?php endif; ?>

    <?php if (!empty($_SESSION['success'])): ?>

        <div class="alert alert-success alert-dismissible fade show">

            <?= htmlspecialchars(
                $_SESSION['success']
            ); ?>

            <?php unset($_SESSION['success']); ?>

            <button
                type="button"
                class="btn-close"
                onclick="this.closest('.alert').remove()">
            </button>

        </div>

    <?php endif; ?>

    <div
        class="card shadow p-4"
        style="max-width:700px">

        <form method="POST">

            <?= csrfField() ?>

            <!-- LIFT -->

            <div class="mb-4">

                <label class="form-label">
                    Select Lift
                </label>

                <input
                    type="hidden"
                    name="asset_id"
                    id="asset_id_val">

                <div class="custom-select-wrapper">

                    <div
                        class="custom-select-box"
                        id="liftBox">

                        -- Select Lift --

                    </div>

                    <div
                        class="custom-select-dropdown"
                        id="liftDropdown">

                        <?php if (empty($lifts)): ?>

                            <div class="custom-select-option">

                                No assigned lifts

                            </div>

                        <?php else: ?>

                            <?php foreach ($lifts as $l): ?>

                                <div
                                    class="custom-select-option"
                                    data-value="<?= (int)$l['id'] ?>">

                                    <?= htmlspecialchars(
                                        $l['asset_name']
                                    ) ?>

                                </div>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </div>

                </div>

            </div>

            <!-- ISSUE -->

            <div class="mb-4">

                <label class="form-label">
                    Issue Type
                </label>

                <input
                    type="hidden"
                    name="issue"
                    id="issue_val">

                <div class="custom-select-wrapper">

                    <div
                        class="custom-select-box"
                        id="issueBox">

                        -- Select Issue --

                    </div>

                    <div
                        class="custom-select-dropdown"
                        id="issueDropdown">

                        <?php foreach ($issues as $iss): ?>

                            <div
                                class="custom-select-option"
                                data-value="<?= htmlspecialchars($iss) ?>">

                                <?= htmlspecialchars($iss) ?>

                            </div>

                        <?php endforeach; ?>

                    </div>

                </div>

            </div>

            <button
                name="submit"
                class="btn btn-danger w-100">

                Create Callback

            </button>

        </form>

    </div>

</div>

<?php include('../includes/footer.php'); ?>

<script>

function initCustomSelect(
    boxId,
    dropdownId,
    hiddenId
) {

    const box =
        document.getElementById(boxId);

    const dropdown =
        document.getElementById(dropdownId);

    const hidden =
        document.getElementById(hiddenId);

    box.addEventListener(
        'click',
        function(e) {

            e.stopPropagation();

            document
                .querySelectorAll(
                    '.custom-select-dropdown.open'
                )
                .forEach(function(d) {

                    if (d !== dropdown) {

                        d.classList.remove(
                            'open'
                        );

                    }

                });

            dropdown.classList.toggle(
                'open'
            );
        }
    );

    dropdown
        .querySelectorAll(
            '.custom-select-option'
        )
        .forEach(function(opt) {

            opt.addEventListener(
                'click',
                function() {

                    const val =
                        this.getAttribute(
                            'data-value'
                        );

                    if (!val) {
                        return;
                    }

                    hidden.value = val;

                    box.textContent =
                        this.textContent.trim();

                    dropdown
                        .querySelectorAll(
                            '.custom-select-option'
                        )
                        .forEach(
                            function(o) {

                                o.classList.remove(
                                    'selected'
                                );

                            }
                        );

                    this.classList.add(
                        'selected'
                    );

                    dropdown.classList.remove(
                        'open'
                    );
                }
            );

        });
}

initCustomSelect(
    'liftBox',
    'liftDropdown',
    'asset_id_val'
);

initCustomSelect(
    'issueBox',
    'issueDropdown',
    'issue_val'
);

document.addEventListener(
    'click',
    function() {

        document
            .querySelectorAll(
                '.custom-select-dropdown.open'
            )
            .forEach(function(d) {

                d.classList.remove(
                    'open'
                );

            });

    }
);

</script>