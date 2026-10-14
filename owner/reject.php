<?php

include('../config/db.php');
include('../config/auth.php');
require_once('../config/security.php');

require_role('owner');

$job_id   = (int)($_GET['job_id'] ?? 0);
$owner_id = (int)$_SESSION['uid'];


if ($job_id <= 0) {

    header("Location: approvals.php");
    exit;
}


/* =========================================================
   GET JOB
========================================================= */

$stmt = $conn->prepare("
    SELECT
        mj.id,
        mj.asset_id,
        mj.technician_id,
        mj.status,
        a.asset_name,
        a.owner_id

    FROM maintenance_jobs mj

    INNER JOIN assets a
        ON a.id = mj.asset_id

    WHERE mj.id = ?
      AND a.owner_id = ?

    LIMIT 1
");

$stmt->bind_param(
    "ii",
    $job_id,
    $owner_id
);

$stmt->execute();

$job = $stmt->get_result()->fetch_assoc();


if (!$job) {

    $_SESSION['error'] =
        'Maintenance request not found.';

    header("Location: approvals.php");
    exit;
}


/* =========================================================
   ONLY PENDING OWNER
========================================================= */

if ($job['status'] !== 'pending_owner') {

    $_SESSION['error'] =
        'This maintenance request has already been processed.';

    header("Location: approvals.php");
    exit;
}


/* =========================================================
   REJECT
========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {

        $_SESSION['error'] =
            'Invalid request. Please try again.';

        header("Location: reject.php?job_id=" . $job_id);
        exit;
    }


    $remark = trim(
        $_POST['remark'] ?? ''
    );


    if ($remark === '') {

        $_SESSION['error'] =
            'Please enter the rejection reason.';

        header("Location: reject.php?job_id=" . $job_id);
        exit;
    }


    /* =====================================================
       UPDATE JOB AS REJECTED
    ===================================================== */

    $upd = $conn->prepare("
        UPDATE maintenance_jobs

        SET
            status = 'rejected',
            owner_remark = ?

        WHERE id = ?
          AND status = 'pending_owner'
    ");

    $upd->bind_param(
        "si",
        $remark,
        $job_id
    );


    if (
        !$upd->execute() ||
        $upd->affected_rows <= 0
    ) {

        $_SESSION['error'] =
            'Unable to reject maintenance.';

        header("Location: approvals.php");
        exit;
    }


    /* =====================================================
       ASSET OUT OF SERVICE
    ===================================================== */

    $assetUpd = $conn->prepare("
        UPDATE assets

        SET status = 'Out of Service'

        WHERE id = ?
    ");

    $assetUpd->bind_param(
        "i",
        $job['asset_id']
    );

    $assetUpd->execute();


    /*
     * IMPORTANT:
     *
     * Do NOT insert into maintenance_records here.
     *
     * Rejected work is kept in maintenance_jobs
     * and will appear in Technician History as REJECTED.
     */


    $_SESSION['success'] =
        'Maintenance rejected successfully.';

    header("Location: approvals.php");
    exit;
}


/* =========================================================
   HEADER
========================================================= */

include('../includes/header.php');
include('../includes/sidebar-owner.php');

?>

<div class="content">

    <h3 class="mb-4">
        Reject Maintenance
    </h3>


    <?php if (!empty($_SESSION['error'])): ?>

        <div class="alert alert-danger alert-dismissible fade show">

            <?= htmlspecialchars($_SESSION['error']); ?>

            <?php unset($_SESSION['error']); ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert">
            </button>

        </div>

    <?php endif; ?>


    <div
        class="card shadow p-4"
        style="max-width: 650px;"
    >

        <h5 class="mb-3">

            <?= htmlspecialchars(
                $job['asset_name']
            ) ?>

        </h5>


        <form method="POST">

            <?= csrfField() ?>


            <div class="mb-3">

                <label class="form-label fw-bold">

                    Reason for Rejection

                </label>

                <textarea
                    name="remark"
                    class="form-control"
                    rows="5"
                    required
                    placeholder="Enter reason for rejecting this maintenance..."
                ></textarea>

            </div>


            <button
                type="submit"
                class="btn btn-danger w-100"
                onclick="return confirm('Reject this maintenance?')"
            >

                ✕ Reject Maintenance

            </button>


            <a
                href="approvals.php"
                class="btn btn-secondary w-100 mt-2"
            >

                Cancel

            </a>

        </form>

    </div>

</div>


<?php include('../includes/footer.php'); ?>