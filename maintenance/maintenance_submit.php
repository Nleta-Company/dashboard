<?php

include('../config/db.php');
include('../config/auth.php');
require_once('../config/security.php');

require_role('technician');

$tech_id = (int)$_SESSION['uid'];

$job_id = (int)(
    $_POST['job_id']
    ?? $_GET['job_id']
    ?? 0
);


/* =========================================================
   AUTO FIND JOB
   ---------------------------------------------------------
   Always find the technician's latest maintenance job.
   Do NOT require technician_response = accepted just to
   open the checklist page.
========================================================= */

if (!$job_id) {

    $auto = $conn->prepare("
        SELECT id
        FROM maintenance_jobs
        WHERE technician_id = ?
          AND LOWER(TRIM(COALESCE(job_type, ''))) NOT IN ('fortnightly', 'saf_inspection')
          AND status IN ('assigned', 'in_progress')
        ORDER BY created_at DESC, id DESC
        LIMIT 1
    ");

    if ($auto) {

        $auto->bind_param(
            "i",
            $tech_id
        );

        $auto->execute();

        $row =
            $auto->get_result()->fetch_assoc();

        $job_id = $row
            ? (int)$row['id']
            : 0;
    }
}


/* =========================================================
   GET JOB
========================================================= */

$job = null;

if ($job_id > 0) {

    $sel = $conn->prepare("
        SELECT
            mj.id,
            mj.asset_id,
            mj.status,
            mj.technician_response,
            mj.checklist,
            mj.remarks,
            mj.file,
            mj.job_type,

            a.asset_name,
            a.location

        FROM maintenance_jobs mj

        INNER JOIN assets a
            ON a.id = mj.asset_id

        WHERE mj.id = ?
          AND mj.technician_id = ?

        LIMIT 1
    ");

    if ($sel) {

        $sel->bind_param(
            "ii",
            $job_id,
            $tech_id
        );

        $sel->execute();

        $job =
            $sel->get_result()->fetch_assoc();
    }
}



/* =========================================================
   AMC JOB VALIDATION
   ---------------------------------------------------------
   This page is only for normal AMC maintenance jobs.
   Technician can submit ONLY after:
     1. request accepted
     2. Start Work clicked
     3. job status = in_progress
========================================================= */
if ($job) {

    $jobType = strtolower(
        trim((string)($job['job_type'] ?? ''))
    );

    if (in_array($jobType, ['fortnightly', 'saf_inspection'], true)) {
        $_SESSION['error'] = 'This page is only for AMC maintenance jobs.';
        header("Location: assigned_lifts.php");
        exit;
    }
}

/* =========================================================
   HANDLE POST
========================================================= */


if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['do_submit'])
) {

    /* =====================================================
       JOB MUST EXIST FOR SUBMISSION
    ===================================================== */

    if (!$job) {

        $_SESSION['error'] =
            'No maintenance job is available for checklist submission.';

        header("Location: maintenance_submit.php");

        exit;
    }

    if (
        $job['status'] !== 'in_progress'
        ||
        $job['technician_response'] !== 'accepted'
    ) {

        $_SESSION['error'] =
            'Please accept the maintenance request and start work before submitting the checklist.';

        header(
            "Location: maintenance_submit.php?job_id={$job_id}"
        );

        exit;
    }


    /* =====================================================
       CSRF
    ===================================================== */

    if (
        !validateCSRFToken(
            $_POST['csrf_token'] ?? ''
        )
    ) {

        $_SESSION['error'] =
            'Invalid CSRF token.';

        header(
            "Location: maintenance_submit.php?job_id={$job_id}"
        );

        exit;
    }


    /* =====================================================
       REMARKS
    ===================================================== */

    $remarks = trim(
        $_POST['remarks'] ?? ''
    );


    /* =====================================================
       CHECKLIST
    ===================================================== */

    $checklistData =
        $_POST['checklist'] ?? [];

    if (!is_array($checklistData)) {
        $checklistData = [];
    }

    $checklist =
        json_encode(
            $checklistData,
            JSON_UNESCAPED_UNICODE
        );


    /* =====================================================
       REMARK VALIDATION
    ===================================================== */

    if ($remarks === '') {

        $_SESSION['error'] =
            'Remarks are required.';

        header(
            "Location: maintenance_submit.php?job_id={$job_id}"
        );

        exit;
    }


    /* =====================================================
       CHECKLIST VALIDATION
    ===================================================== */

    if (empty($checklistData)) {

        $_SESSION['error'] =
            'Please select at least one checklist item.';

        header(
            "Location: maintenance_submit.php?job_id={$job_id}"
        );

        exit;
    }


    /* =====================================================
       FILE
    ===================================================== */

    $fileName =
        $job['file'] ?? '';


    /* =====================================================
       FILE UPLOAD
    ===================================================== */

    if (
        isset($_FILES['file']) &&
        !empty($_FILES['file']['name'])
    ) {

        /* -------------------------------------------------
           Upload error
        ------------------------------------------------- */

        if (
            $_FILES['file']['error']
            !== UPLOAD_ERR_OK
        ) {

            $_SESSION['error'] =
                'Unable to upload file.';

            header(
                "Location: maintenance_submit.php?job_id={$job_id}"
            );

            exit;
        }


        /* -------------------------------------------------
           Extension
        ------------------------------------------------- */

        $ext =
            strtolower(
                pathinfo(
                    $_FILES['file']['name'],
                    PATHINFO_EXTENSION
                )
            );


        /* -------------------------------------------------
           MIME
        ------------------------------------------------- */

        $mime =
            mime_content_type(
                $_FILES['file']['tmp_name']
            );


        /* -------------------------------------------------
           Allowed extensions
        ------------------------------------------------- */

        $allowedExt = [

            'pdf',
            'jpg',
            'jpeg',
            'png',
            'doc',
            'docx'

        ];


        /* -------------------------------------------------
           Allowed MIME
        ------------------------------------------------- */

        $allowedMime = [

            'application/pdf',

            'image/jpeg',

            'image/png',

            'application/msword',

            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'

        ];


        if (
            !in_array(
                $ext,
                $allowedExt,
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
                'Invalid file type.';

            header(
                "Location: maintenance_submit.php?job_id={$job_id}"
            );

            exit;
        }


        /* -------------------------------------------------
           5 MB
        ------------------------------------------------- */

        if (
            $_FILES['file']['size']
            > 5242880
        ) {

            $_SESSION['error'] =
                'File too large. Max 5MB.';

            header(
                "Location: maintenance_submit.php?job_id={$job_id}"
            );

            exit;
        }


        /* -------------------------------------------------
           Upload directory
        ------------------------------------------------- */

        $dir =
            __DIR__ .
            "/../uploads/";


        if (!is_dir($dir)) {

            mkdir(
                $dir,
                0755,
                true
            );
        }


        /* -------------------------------------------------
           Generate unique filename
        ------------------------------------------------- */

        $fileName =
            bin2hex(
                random_bytes(8)
            )
            . '_'
            . time()
            . '.'
            . $ext;


        /* -------------------------------------------------
           Move file
        ------------------------------------------------- */

        if (
            !move_uploaded_file(
                $_FILES['file']['tmp_name'],
                $dir . $fileName
            )
        ) {

            $_SESSION['error'] =
                'Unable to upload file.';

            header(
                "Location: maintenance_submit.php?job_id={$job_id}"
            );

            exit;
        }
    }


    /* =====================================================
       SUBMIT CHECKLIST
       -----------------------------------------------------
       No technician_response condition here.
       The checklist can be submitted from this form.
    ===================================================== */

    $upd = $conn->prepare("
        UPDATE maintenance_jobs

        SET
            checklist = ?,
            remarks = ?,
            file = ?,
            status = 'pending_owner'

        WHERE id = ?
          AND technician_id = ?
          AND status = 'in_progress'
          AND technician_response = 'accepted'
    ");


    if (!$upd) {

        $_SESSION['error'] =
            'Unable to prepare maintenance submission.';

        header(
            "Location: maintenance_submit.php?job_id={$job_id}"
        );

        exit;
    }


    $upd->bind_param(
        "sssii",
        $checklist,
        $remarks,
        $fileName,
        $job_id,
        $tech_id
    );


    if ($upd->execute()) {

        $_SESSION['success'] =
            'Checklist submitted successfully. Waiting for owner approval.';

        header("Location: dashboard.php");

        exit;

    } else {

        $_SESSION['error'] =
            'Unable to submit the maintenance checklist.';

        header(
            "Location: maintenance_submit.php?job_id={$job_id}"
        );

        exit;
    }
}


/* =========================================================
   CHECKLIST ITEMS
========================================================= */

$checklistItems = [

    'Smooth operation – No jerks, vibration, or abnormal noise',

    'Accurate floor leveling – Cabin level matches landing floor',

    'Door safety sensor working – Door reopens when obstructed',

    'Smooth door opening & closing – No sudden jerks or slamming',

    'Alarm bell functional – Audible inside the lift',

    'Emergency light working – Operates during power failure',

    'Intercom / emergency communication working',

    'All floor buttons responsive – Cabin & landing panels',

    'Correct floor display & direction indicator',

    'Overload indication working',

    'No oil / grease leakage in cabin or on landings',

    'Cabin lighting & ventilation fan working',

    'Trial ride completed with technician (up & down)',

    'Maintenance logbook updated & signed'

];


/* =========================================================
   SELECTED CHECKLIST
========================================================= */

$selectedChecklist = [];

if (
    $job &&
    !empty($job['checklist'])
) {

    $decoded =
        json_decode(
            $job['checklist'],
            true
        );

    if (is_array($decoded)) {

        $selectedChecklist =
            $decoded;
    }
}


/* =========================================================
   HEADER / SIDEBAR
========================================================= */

include('../includes/header.php');
include('../includes/sidebar-tech.php');

?>


<div class="content">


<h3 class="mb-3">

    <?php if (
        $job &&
        $job['status'] === 'rejected'
    ): ?>

        Re-Submit Maintenance Checklist

    <?php else: ?>

        Maintenance Checklist

    <?php endif; ?>

</h3>


<?php if (!empty($_SESSION['success'])): ?>

<div class="alert alert-success alert-dismissible fade show">

    <?= htmlspecialchars(
        $_SESSION['success']
    ); ?>

    <?php unset(
        $_SESSION['success']
    ); ?>

    <button
        type="button"
        class="btn-close"
        data-bs-dismiss="alert">
    </button>

</div>

<?php endif; ?>


<?php if (!empty($_SESSION['error'])): ?>

<div class="alert alert-danger alert-dismissible fade show">

    <?= htmlspecialchars(
        $_SESSION['error']
    ); ?>

    <?php unset(
        $_SESSION['error']
    ); ?>

    <button
        type="button"
        class="btn-close"
        data-bs-dismiss="alert">
    </button>

</div>

<?php endif; ?>


<?php if (!$job): ?>


    <!-- =====================================================
         NO JOB
    ====================================================== -->

    <div
        class="card p-4 shadow"
        style="max-width:900px"
    >

        <div class="alert alert-info mb-0">

            No maintenance job is currently assigned to you.

            <br>

            You can open this page anytime, and the checklist
            will be available automatically when a maintenance
            job is assigned.

        </div>

    </div>


<?php else: ?>


    <!-- =====================================================
         CHECKLIST FORM
    ====================================================== -->

    <div
        class="card p-4 shadow"
        style="max-width:900px"
    >


        <h6 class="mb-3">

            Lift:

            <strong>

                <?= htmlspecialchars(
                    $job['asset_name']
                ) ?>

            </strong>

            <br>

            Location:

            <?= htmlspecialchars(
                $job['location']
            ) ?>

        </h6>


        <form
            method="POST"
            action="maintenance_submit.php"
            enctype="multipart/form-data"
        >


            <?= csrfField() ?>


            <input
                type="hidden"
                name="job_id"
                value="<?= $job_id ?>"
            >


            <!-- =================================================
                 CHECKLIST
            ================================================== -->

            <label class="fw-bold">

                Checklist

                <button
                    type="button"
                    class="btn btn-sm btn-outline-primary ms-2"
                    onclick="selectAll()"
                >

                    Select All

                </button>

            </label>


            <?php foreach (
                $checklistItems
                as $item
            ): ?>


                <div class="form-check">


                    <input
                        class="form-check-input chk-item"
                        type="checkbox"
                        name="checklist[]"
                        value="<?= htmlspecialchars(
                            $item
                        ) ?>"

                        <?= in_array(
                            $item,
                            $selectedChecklist,
                            true
                        )
                            ? 'checked'
                            : '' ?>
                    >


                    <label class="form-check-label">

                        <?= htmlspecialchars(
                            $item
                        ) ?>

                    </label>


                </div>


            <?php endforeach; ?>


            <!-- =================================================
                 SELECT ALL
            ================================================== -->

            <script>

            function selectAll() {

                document
                    .querySelectorAll('.chk-item')
                    .forEach(function(cb) {

                        cb.checked = true;

                    });

            }

            </script>


            <!-- =================================================
                 REMARKS
            ================================================== -->

            <textarea
                name="remarks"
                class="form-control mt-3"
                rows="3"
                placeholder="Remarks"
                required
            ><?= htmlspecialchars(
                $job['remarks'] ?? ''
            ) ?></textarea>


            <!-- =================================================
                 FILE
            ================================================== -->

            <input
                type="file"
                name="file"
                class="form-control mt-2"
                accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
            >


            <!-- =================================================
                 SUBMIT
            ================================================== -->

            <button
                type="submit"
                name="do_submit"
                value="1"
                class="btn btn-success w-100 mt-4"
            >

                <?=
                    $job['status'] === 'rejected'
                    ? 'Re-Submit for Owner Approval'
                    : 'Submit for Owner Approval'
                ?>

            </button>


        </form>


    </div>


<?php endif; ?>


</div>


<?php include('../includes/footer.php'); ?>