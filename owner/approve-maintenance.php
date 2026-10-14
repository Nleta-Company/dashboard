<?php

include('../config/db.php');
include('../config/auth.php');
require_once('../config/security.php');

require_role('owner');

date_default_timezone_set('Asia/Kolkata');

$id       = (int)($_GET['id'] ?? 0);
$owner_id = (int)($_SESSION['uid'] ?? 0);

if ($id <= 0 || $owner_id <= 0) {
    header("Location: approvals.php");
    exit;
}


/* =========================================================
   LOAD AMC JOB
========================================================= */

$stmt = $conn->prepare("
    SELECT
        mj.*,
        a.asset_name,
        a.id AS asset_id,
        a.maintenance_interval
    FROM maintenance_jobs mj
    INNER JOIN assets a
        ON a.id = mj.asset_id
    WHERE
        mj.id = ?
        AND a.owner_id = ?
        AND LOWER(TRIM(COALESCE(mj.job_type, '')))
            NOT IN ('fortnightly', 'saf_inspection')
    LIMIT 1
");

$stmt->bind_param(
    "ii",
    $id,
    $owner_id
);

$stmt->execute();

$job =
    $stmt
    ->get_result()
    ->fetch_assoc();


if (!$job) {

    $_SESSION['error'] =
        "Maintenance job not found.";

    header("Location: approvals.php");
    exit;
}


/* =========================================================
   APPROVE
========================================================= */

if (isset($_POST['approve'])) {

    if (!validateCSRFToken(
        $_POST['csrf_token'] ?? ''
    )) {

        $_SESSION['error'] =
            'Invalid request. Please try again.';

        header(
            "Location: approve-maintenance.php?id={$id}"
        );

        exit;
    }


    /*
     * Only pending_owner can be approved.
     */
    if ($job['status'] !== 'pending_owner') {

        $_SESSION['error'] =
            'This maintenance job is no longer pending owner approval.';

        header("Location: approvals.php");
        exit;
    }


    $remark =
        trim(
            $_POST['remark'] ?? ''
        );

    $file = '';


    /* =====================================================
       FILE UPLOAD
    ===================================================== */

    if (!empty($_FILES['file']['name'])) {

        $allowed = [
            'pdf',
            'jpg',
            'jpeg',
            'png'
        ];

        $allowedMime = [
            'application/pdf',
            'image/jpeg',
            'image/png'
        ];

        $ext = strtolower(
            pathinfo(
                $_FILES['file']['name'],
                PATHINFO_EXTENSION
            )
        );

        $mime = mime_content_type(
            $_FILES['file']['tmp_name']
        );

        if (
            !in_array(
                $ext,
                $allowed,
                true
            )
            ||
            !in_array(
                $mime,
                $allowedMime,
                true
            )
        ) {

            $_SESSION['error'] =
                "Invalid file type. Allowed: pdf, jpg, jpeg, png.";

            header(
                "Location: approve-maintenance.php?id={$id}"
            );

            exit;
        }


        if (
            (int)$_FILES['file']['size']
            > 5242880
        ) {

            $_SESSION['error'] =
                "File too large. Maximum 5MB.";

            header(
                "Location: approve-maintenance.php?id={$id}"
            );

            exit;
        }


        $file =
            bin2hex(
                random_bytes(8)
            )
            . '_'
            . time()
            . '.'
            . $ext;


        $uploadDir =
            __DIR__ . '/../uploads/';


        if (!is_dir($uploadDir)) {

            mkdir(
                $uploadDir,
                0755,
                true
            );
        }


        if (!move_uploaded_file(
            $_FILES['file']['tmp_name'],
            $uploadDir . $file
        )) {

            $_SESSION['error'] =
                "File upload failed.";

            header(
                "Location: approve-maintenance.php?id={$id}"
            );

            exit;
        }
    }


    /* =====================================================
       TRANSACTION
    ===================================================== */

    $conn->begin_transaction();


    try {

        /* =================================================
           LOCK JOB
        ================================================= */

        $lock = $conn->prepare("
            SELECT
                id,
                asset_id,
                status
            FROM maintenance_jobs
            WHERE id = ?
              AND technician_id = ?
            FOR UPDATE
        ");

        $lock->bind_param(
            "ii",
            $id,
            $job['technician_id']
        );

        $lock->execute();

        $lockedJob =
            $lock
            ->get_result()
            ->fetch_assoc();

        $lock->close();


        if (
            !$lockedJob ||
            $lockedJob['status'] !== 'pending_owner'
        ) {

            throw new Exception(
                'Maintenance job is no longer pending.'
            );
        }


        /* =================================================
           1. APPROVE JOB
        ================================================= */

        $updJob = $conn->prepare("
            UPDATE maintenance_jobs
            SET
                status = 'approved',
                owner_remark = ?
            WHERE id = ?
              AND status = 'pending_owner'
        ");

        $updJob->bind_param(
            "si",
            $remark,
            $id
        );

        if (
            !$updJob->execute() ||
            $updJob->affected_rows !== 1
        ) {

            throw new Exception(
                'Unable to approve maintenance job.'
            );
        }

        $updJob->close();


        /* =================================================
           2. TODAY = NEW MAINTENANCE DATE
        ================================================= */

        $maintenanceDate =
            date('Y-m-d');


        /* =================================================
           3. MAINTENANCE RECORD
        ================================================= */

        $chk = $conn->prepare("
            SELECT id
            FROM maintenance_records
            WHERE asset_id = ?
              AND maintenance_date = ?
            LIMIT 1
        ");

        $chk->bind_param(
            "is",
            $job['asset_id'],
            $maintenanceDate
        );

        $chk->execute();

        $rec =
            $chk
            ->get_result()
            ->fetch_assoc();

        $chk->close();


        if ($rec) {

            $updRec = $conn->prepare("
                UPDATE maintenance_records
                SET
                    remarks = ?,
                    status = 'approved'
                WHERE id = ?
            ");

            $updRec->bind_param(
                "si",
                $remark,
                $rec['id']
            );

            if (!$updRec->execute()) {
                throw new Exception(
                    'Unable to update maintenance record.'
                );
            }

            $updRec->close();

        } else {

            $insRec = $conn->prepare("
                INSERT INTO maintenance_records
                (
                    asset_id,
                    maintenance_date,
                    remarks,
                    status
                )
                VALUES
                (
                    ?,
                    ?,
                    ?,
                    'approved'
                )
            ");

            $insRec->bind_param(
                "iss",
                $job['asset_id'],
                $maintenanceDate,
                $remark
            );

            if (!$insRec->execute()) {
                throw new Exception(
                    'Unable to create maintenance record.'
                );
            }

            $insRec->close();
        }


        /* =================================================
           4. RESET MAINTENANCE CYCLE
        ================================================= */

        $updAsset = $conn->prepare("
            UPDATE assets
            SET
                maintenance_start_date = ?,
                status = 'Active',
                updated_at = CURDATE()
            WHERE id = ?
              AND owner_id = ?
        ");

        $updAsset->bind_param(
            "sii",
            $maintenanceDate,
            $job['asset_id'],
            $owner_id
        );

        if (!$updAsset->execute()) {

            throw new Exception(
                'Unable to reset maintenance cycle.'
            );
        }

        $updAsset->close();


        /* =================================================
           COMMIT
        ================================================= */

        $conn->commit();


        $_SESSION['success'] =
            "Maintenance approved successfully. "
            . "Today is now the new maintenance date.";

        header("Location: approvals.php");
        exit;


    } catch (Throwable $e) {

        $conn->rollback();


        if (
            $file !== '' &&
            is_file(
                __DIR__ .
                '/../uploads/' .
                $file
            )
        ) {

            @unlink(
                __DIR__ .
                '/../uploads/' .
                $file
            );
        }


        error_log(
            'Maintenance approval error: ' .
            $e->getMessage()
        );


        $_SESSION['error'] =
            "Maintenance approval failed. Please try again.";

        header(
            "Location: approve-maintenance.php?id={$id}"
        );

        exit;
    }
}


/* =========================================================
   REJECT
========================================================= */

if (isset($_POST['reject'])) {

    if (!validateCSRFToken(
        $_POST['csrf_token'] ?? ''
    )) {

        $_SESSION['error'] =
            'Invalid request. Please try again.';

        header(
            "Location: approve-maintenance.php?id={$id}"
        );

        exit;
    }


    if ($job['status'] !== 'pending_owner') {

        $_SESSION['error'] =
            'This maintenance job is no longer pending approval.';

        header("Location: approvals.php");
        exit;
    }


    $remark =
        trim(
            $_POST['remark'] ?? ''
        );


    $conn->begin_transaction();


    try {

        /*
         * Keep technician response = accepted.
         *
         * This allows start_job.php to reopen the rejected
         * maintenance job for technician rework.
         */

        $stmt = $conn->prepare("
            UPDATE maintenance_jobs
            SET
                status = 'rejected',
                owner_remark = ?
            WHERE
                id = ?
                AND status = 'pending_owner'
        ");

        $stmt->bind_param(
            "si",
            $remark,
            $id
        );

        if (
            !$stmt->execute() ||
            $stmt->affected_rows !== 1
        ) {

            throw new Exception(
                'Unable to reject maintenance.'
            );
        }

        $stmt->close();


        /*
         * Do NOT change maintenance_start_date here.
         *
         * The old approved maintenance date remains the
         * current cycle date until the technician completes
         * rework and owner approves again.
         */

        $conn->commit();


        $_SESSION['success'] =
            "Maintenance rejected and sent back for rework.";

        header("Location: approvals.php");
        exit;


    } catch (Throwable $e) {

        $conn->rollback();

        error_log(
            'Maintenance rejection error: ' .
            $e->getMessage()
        );

        $_SESSION['error'] =
            "Maintenance rejection failed. Please try again.";

        header(
            "Location: approve-maintenance.php?id={$id}"
        );

        exit;
    }
}


/* =========================================================
   PAGE
========================================================= */

include('../includes/header.php');
include('../includes/sidebar-owner.php');

?>

<div class="content">

    <h3 class="mb-3">
        <?= htmlspecialchars(
            $job['asset_name'],
            ENT_QUOTES,
            'UTF-8'
        ) ?>
    </h3>


    <?php if (!empty($_SESSION['error'])): ?>

        <div class="alert alert-danger">

            <?= htmlspecialchars(
                $_SESSION['error']
            ) ?>

            <?php unset(
                $_SESSION['error']
            ); ?>

        </div>

    <?php endif; ?>


    <div
        class="card p-4 shadow"
        style="max-width:600px"
    >

        <form
            method="POST"
            enctype="multipart/form-data"
        >

            <?= csrfField() ?>


            <textarea
                name="remark"
                class="form-control mb-3"
                placeholder="Owner remarks"
                required
            ></textarea>


            <input
                type="file"
                name="file"
                class="form-control mb-3"
                accept=".pdf,.jpg,.jpeg,.png"
            >


            <button
                type="submit"
                name="approve"
                class="btn btn-success w-100 mb-2"
            >
                Approve Maintenance
            </button>


            <button
                type="submit"
                name="reject"
                class="btn btn-danger w-100"
            >
                Reject & Send for Rework
            </button>

        </form>

    </div>

</div>

<?php include('../includes/footer.php'); ?>