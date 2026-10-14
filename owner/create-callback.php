<?php

include('../config/db.php');
include('../config/auth.php');
require_once '../config/security.php';

require_role('owner');

require_once __DIR__ . '/../config/owner_access.php';
requireOwnerAccess('callback');

$owner_id = (int)$_SESSION['uid'];


/* =========================================================
   CREATE CALLBACK
========================================================= */

if (isset($_POST['submit'])) {

    /* ================= CSRF ================= */

    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {

        $_SESSION['error'] = 'Invalid request. Please try again.';

        header("Location: create-callback.php");
        exit;
    }


    /* ================= INPUT ================= */

    $asset_id = (int)($_POST['asset_id'] ?? 0);
    $issue    = trim($_POST['issue'] ?? '');


    /* ================= VALIDATION ================= */

    if (!$asset_id) {

        $_SESSION['error'] = "Please select a lift.";

        header("Location: create-callback.php");
        exit;
    }


    if (!$issue) {

        $_SESSION['error'] = "Please select an issue.";

        header("Location: create-callback.php");
        exit;
    }


    /* =====================================================
       VERIFY THAT THIS LIFT BELONGS TO THIS OWNER
    ===================================================== */

    $verify = $conn->prepare("
        SELECT
            id,
            asset_name,
            location
        FROM assets
        WHERE id = ?
          AND owner_id = ?
        LIMIT 1
    ");

    $verify->bind_param(
        "ii",
        $asset_id,
        $owner_id
    );

    $verify->execute();

    $asset = $verify->get_result()->fetch_assoc();


    if (!$asset) {

        $_SESSION['error'] = "Invalid lift selection.";

        header("Location: create-callback.php");
        exit;
    }


    /* =====================================================
       GET TECHNICIAN ASSIGNED TO THIS OWNER
    ===================================================== */

    $ownerStmt = $conn->prepare("
        SELECT assigned_technician_id
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    $ownerStmt->bind_param(
        "i",
        $owner_id
    );

    $ownerStmt->execute();

    $ownerData = $ownerStmt
        ->get_result()
        ->fetch_assoc();


    if (
        !$ownerData ||
        empty($ownerData['assigned_technician_id'])
    ) {

        $_SESSION['error'] =
            "No technician is assigned to your account. Please contact admin.";

        header("Location: create-callback.php");
        exit;
    }


    $tech_id = (int)$ownerData['assigned_technician_id'];


    /* =====================================================
       VERIFY TECHNICIAN EXISTS
    ===================================================== */

    $techCheck = $conn->prepare("
        SELECT
            id,
            name,
            email
        FROM users
        WHERE id = ?
          AND role = 'technician'
        LIMIT 1
    ");

    $techCheck->bind_param(
        "i",
        $tech_id
    );

    $techCheck->execute();

    $tech = $techCheck
        ->get_result()
        ->fetch_assoc();


    if (!$tech) {

        $_SESSION['error'] =
            "Assigned technician was not found. Please contact admin.";

        header("Location: create-callback.php");
        exit;
    }


    /* =====================================================
       GENERATE UNIQUE CALLBACK NUMBER

       Format:
       NLETA01DDMMYYYY
       NLETA02DDMMYYYY
       NLETA03DDMMYYYY
       etc.

       This checks the database first so an existing
       callback number is never reused.
    ===================================================== */

    $today = date('dmY');

    $prefix = "NLETA";


    /*
     * Get all callback numbers for today's date.
     */
    $numberStmt = $conn->prepare("
        SELECT callback_number
        FROM callbacks
        WHERE callback_number LIKE ?
        ORDER BY id DESC
    ");

    $likePattern = $prefix . '%' . $today;

    $numberStmt->bind_param(
        "s",
        $likePattern
    );

    $numberStmt->execute();

    $numberResult = $numberStmt->get_result();


    /*
     * Find the highest existing sequence number.
     */
    $nextNumber = 1;

    while ($numberRow = $numberResult->fetch_assoc()) {

        $existingNumber = $numberRow['callback_number'];

        /*
         * Example:
         * NLETA0312082026
         *
         * Remove:
         * NLETA
         * 12082026
         *
         * Remaining:
         * 03
         */

        if (
            strpos($existingNumber, $prefix) === 0 &&
            substr($existingNumber, -8) === $today
        ) {

            $sequencePart = substr(
                $existingNumber,
                strlen($prefix),
                -8
            );

            if (ctype_digit($sequencePart)) {

                $sequence = (int)$sequencePart;

                if ($sequence >= $nextNumber) {

                    $nextNumber = $sequence + 1;
                }
            }
        }
    }


    /*
     * Generate callback number.
     *
     * Example:
     * NLETA0112082026
     * NLETA0212082026
     * NLETA0312082026
     * NLETA0412082026
     */
    $cb_no =
        $prefix .
        str_pad($nextNumber, 2, '0', STR_PAD_LEFT) .
        $today;


    /*
     * Final safety check.
     *
     * If the number somehow already exists,
     * keep increasing until a free number is found.
     */
    while (true) {

        $duplicateCheck = $conn->prepare("
            SELECT id
            FROM callbacks
            WHERE callback_number = ?
            LIMIT 1
        ");

        $duplicateCheck->bind_param(
            "s",
            $cb_no
        );

        $duplicateCheck->execute();

        $duplicate = $duplicateCheck
            ->get_result()
            ->fetch_assoc();


        if (!$duplicate) {
            break;
        }


        $nextNumber++;

        $cb_no =
            $prefix .
            str_pad($nextNumber, 2, '0', STR_PAD_LEFT) .
            $today;
    }


    /* =====================================================
       CREATE CALLBACK
       
       status = open
       technician_response = NULL
       technician_reject_reason = NULL
       technician_responded_at = NULL
       created_by = owner
    ===================================================== */

    $stmt = $conn->prepare("
        INSERT INTO callbacks
        (
            asset_id,
            technician_id,
            technician_response,
            technician_reject_reason,
            technician_responded_at,
            callback_number,
            issue,
            status,
            created_at,
            created_by
        )
        VALUES
        (
            ?,
            ?,
            NULL,
            NULL,
            NULL,
            ?,
            ?,
            'open',
            NOW(),
            'owner'
        )
    ");


    $stmt->bind_param(
        "iiss",
        $asset_id,
        $tech_id,
        $cb_no,
        $issue
    );


    if (!$stmt->execute()) {

        /*
         * If MySQL still reports a duplicate,
         * show a normal error instead of a PHP fatal error.
         */
        if ($conn->errno == 1062) {

            $_SESSION['error'] =
                "Callback number already exists. Please submit again.";

        } else {

            $_SESSION['error'] =
                "Failed to create callback. Please try again.";
        }

        header("Location: create-callback.php");
        exit;
    }


    /* =====================================================
       SET LIFT STATUS
       
       Keep the lift under maintenance while callback
       is active.
    ===================================================== */

    $upd = $conn->prepare("
        UPDATE assets
        SET status = 'Under Maintenance'
        WHERE id = ?
          AND owner_id = ?
    ");

    $upd->bind_param(
        "ii",
        $asset_id,
        $owner_id
    );

    $upd->execute();


    /* =====================================================
       EMAIL TECHNICIAN
    ===================================================== */

    require_once '../config/email.php';


    if (!empty($tech['email'])) {

        $msg =
            "NLETA Callback Request\n\n" .
            "Reference: " . $cb_no . "\n" .
            "Lift: " . $asset['asset_name'] . "\n" .
            "Location: " . $asset['location'] . "\n" .
            "Issue: " . $issue . "\n\n" .
            "A callback request has been assigned to you.\n" .
            "Please login to the technician dashboard and ACCEPT or REJECT the request.";


        sendEmail(
            $tech['email'],
            $tech['name'],
            'NLETA Callback Request - ' . $cb_no,
            $msg
        );
    }


    /* =====================================================
       SUCCESS
    ===================================================== */

    $_SESSION['success'] =
        "Callback request created successfully. Reference: " .
        $cb_no .
        ". Waiting for technician acceptance.";


    header("Location: create-callback.php");
    exit;
}



/* =========================================================
   GET OWNER'S LIFTS
========================================================= */

$lifts = [];


$q = $conn->prepare("
    SELECT
        id,
        asset_name,
        location
    FROM assets
    WHERE owner_id = ?
    ORDER BY asset_name
");


$q->bind_param(
    "i",
    $owner_id
);


$q->execute();


$res = $q->get_result();


while ($a = $res->fetch_assoc()) {

    $lifts[] = $a;
}



/* =========================================================
   ISSUE LIST
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



/* =========================================================
   HEADER
========================================================= */

include('../includes/header.php');
include('../includes/sidebar-owner.php');

?>


<div class="content">

    <h3 class="mb-4">
        Create Callback
    </h3>


    <!-- =====================================================
         SUCCESS
    ===================================================== -->

    <?php if (!empty($_SESSION['success'])): ?>

        <div class="alert alert-success alert-dismissible fade show">

            <?= htmlspecialchars($_SESSION['success']) ?>

            <?php unset($_SESSION['success']); ?>

            <button
                type="button"
                class="btn-close"
                onclick="this.closest('.alert').remove()">
            </button>

        </div>

    <?php endif; ?>


    <!-- =====================================================
         ERROR
    ===================================================== -->

    <?php if (!empty($_SESSION['error'])): ?>

        <div class="alert alert-danger alert-dismissible fade show">

            <?= htmlspecialchars($_SESSION['error']) ?>

            <?php unset($_SESSION['error']); ?>

            <button
                type="button"
                class="btn-close"
                onclick="this.closest('.alert').remove()">
            </button>

        </div>

    <?php endif; ?>


    <!-- =====================================================
         FORM
    ===================================================== -->

    <form
        method="POST"
        class="card p-4 shadow"
        style="max-width:600px;">

        <?= csrfField() ?>


        <!-- ================= LIFT ================= -->

        <?php if (!empty($lifts)): ?>

            <div class="mb-3">

                <label class="form-label">

                    Select Lift

                    <span class="text-danger">*</span>

                </label>


                <select
                    name="asset_id"
                    class="form-select"
                    required>

                    <option value="">
                        -- Select Lift --
                    </option>


                    <?php foreach ($lifts as $l): ?>

                        <option value="<?= (int)$l['id'] ?>">

                            <?= htmlspecialchars($l['asset_name']) ?>

                            -

                            <?= htmlspecialchars($l['location']) ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

        <?php else: ?>

            <div class="alert alert-warning">

                No lifts found.

                Please add a lift first.

            </div>

        <?php endif; ?>


        <!-- ================= ISSUE ================= -->

        <div class="mb-3">

            <label class="form-label">

                Issue

                <span class="text-danger">*</span>

            </label>


            <select
                name="issue"
                class="form-select"
                required>

                <option value="">
                    -- Select Issue --
                </option>


                <?php foreach ($issues as $iss): ?>

                    <option
                        value="<?= htmlspecialchars($iss) ?>">

                        <?= htmlspecialchars($iss) ?>

                    </option>

                <?php endforeach; ?>

            </select>

        </div>


        <!-- ================= SUBMIT ================= -->

        <?php if (!empty($lifts)): ?>

            <button
                type="submit"
                name="submit"
                class="btn btn-primary w-100">

                Submit Callback

            </button>

        <?php endif; ?>

    </form>

</div>


<?php include('../includes/footer.php'); ?>