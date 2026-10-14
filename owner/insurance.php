<?php

/* =========================================================
   ERROR DISPLAY - TEMPORARY
   Remove these 3 lines after everything works.
========================================================= */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


/* =========================================================
   INCLUDES
========================================================= */

include('../config/db.php');
include('../config/auth.php');
require_once '../config/security.php';

require_role('owner');

$owner_id = (int)$_SESSION['uid'];


/* =========================================================
   SUBMIT INSURANCE
========================================================= */

if (isset($_POST['submit_insurance'])) {

    /* -----------------------------------------------------
       CSRF
    ----------------------------------------------------- */

    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {

        $_SESSION['error'] = 'Invalid request. Please try again.';

        header("Location: insurance.php");
        exit;
    }


    /* -----------------------------------------------------
       ASSET
    ----------------------------------------------------- */

    $asset_id = (int)($_POST['asset_id'] ?? 0);

    if ($asset_id <= 0) {

        $_SESSION['error'] = "Please select a lift.";

        header("Location: insurance.php");
        exit;
    }


    /* -----------------------------------------------------
       CHECK FILE
    ----------------------------------------------------- */

    if (
        !isset($_FILES['report_file']) ||
        $_FILES['report_file']['error'] !== UPLOAD_ERR_OK
    ) {

        $_SESSION['error'] = "Please select an insurance report.";

        header("Location: insurance.php");
        exit;
    }


    /* -----------------------------------------------------
       CHECK ASSET BELONGS TO OWNER
    ----------------------------------------------------- */

    $chk = $conn->prepare("
        SELECT id
        FROM assets
        WHERE id = ?
          AND owner_id = ?
        LIMIT 1
    ");

    if (!$chk) {

        $_SESSION['error'] =
            "Database error while checking lift: " . $conn->error;

        header("Location: insurance.php");
        exit;
    }

    $chk->bind_param("ii", $asset_id, $owner_id);
    $chk->execute();

    $chkResult = $chk->get_result();

    if ($chkResult->num_rows === 0) {

        $_SESSION['error'] = "Invalid lift selected.";

        header("Location: insurance.php");
        exit;
    }


    /* =====================================================
       FILE INFORMATION
    ===================================================== */

    $uploadedFile = $_FILES['report_file'];

    $originalName = $uploadedFile['name'];
    $tmpName      = $uploadedFile['tmp_name'];
    $fileSize     = (int)$uploadedFile['size'];

    $extension = strtolower(
        pathinfo($originalName, PATHINFO_EXTENSION)
    );


    /* =====================================================
       ALLOWED FILE TYPES
    ===================================================== */

    $allowedExtensions = [
        'pdf',
        'jpg',
        'jpeg',
        'png'
    ];

    if (!in_array($extension, $allowedExtensions, true)) {

        $_SESSION['error'] =
            "Invalid file type. Only PDF, JPG, JPEG and PNG files are allowed.";

        header("Location: insurance.php");
        exit;
    }


    /* =====================================================
       MAX FILE SIZE = 5 MB
    ===================================================== */

    if ($fileSize > 5242880) {

        $_SESSION['error'] =
            "File is too large. Maximum allowed size is 5 MB.";

        header("Location: insurance.php");
        exit;
    }


    /* =====================================================
       CHECK REAL UPLOAD
    ===================================================== */

    if (!is_uploaded_file($tmpName)) {

        $_SESSION['error'] =
            "Invalid file upload.";

        header("Location: insurance.php");
        exit;
    }


    /* =====================================================
       MIME TYPE CHECK
    ===================================================== */

    $allowedMimeTypes = [
        'pdf'  => 'application/pdf',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png'
    ];

    $mimeType = '';

    if (function_exists('finfo_open')) {

        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo) {

            $mimeType = finfo_file($finfo, $tmpName);

            finfo_close($finfo);
        }
    }

    if (
        !empty($mimeType) &&
        isset($allowedMimeTypes[$extension]) &&
        $mimeType !== $allowedMimeTypes[$extension]
    ) {

        $_SESSION['error'] =
            "The uploaded file type does not match its extension.";

        header("Location: insurance.php");
        exit;
    }


    /* =====================================================
       UPLOAD DIRECTORY
       
       IMPORTANT:
       We are NOT using UPLOAD_PATH.
    ===================================================== */

    $uploadDir = __DIR__ . '/../uploads/insurance/';


    /* Create folder if it doesn't exist */

    if (!is_dir($uploadDir)) {

        if (!mkdir($uploadDir, 0755, true)) {

            $_SESSION['error'] =
                "Unable to create insurance upload folder.";

            header("Location: insurance.php");
            exit;
        }
    }


    /* =====================================================
       GENERATE UNIQUE FILE NAME
    ===================================================== */

    $fileName =
        'insurance_' .
        $owner_id .
        '_' .
        time() .
        '_' .
        bin2hex(random_bytes(4)) .
        '.' .
        $extension;

    $destination = $uploadDir . $fileName;


    /* =====================================================
       MOVE FILE
    ===================================================== */

    if (!move_uploaded_file($tmpName, $destination)) {

        $_SESSION['error'] =
            "Unable to save the insurance report.";

        header("Location: insurance.php");
        exit;
    }


    /* =====================================================
       CHECK EXISTING INSURANCE
    ===================================================== */

    $exists = $conn->prepare("
        SELECT id, report_file
        FROM insurance
        WHERE asset_id = ?
        LIMIT 1
    ");

    if (!$exists) {

        if (file_exists($destination)) {
            unlink($destination);
        }

        $_SESSION['error'] =
            "Database error while checking insurance: " .
            $conn->error;

        header("Location: insurance.php");
        exit;
    }

    $exists->bind_param("i", $asset_id);
    $exists->execute();

    $existingResult = $exists->get_result();


    /* =====================================================
       UPDATE EXISTING INSURANCE
    ===================================================== */

    if ($existingResult->num_rows > 0) {

        $existingRow = $existingResult->fetch_assoc();

        $insurance_id = (int)$existingRow['id'];

        $oldFile = $existingRow['report_file'] ?? '';


        /* -------------------------------------------------
           UPDATE DATABASE
        ------------------------------------------------- */

        $upd = $conn->prepare("
            UPDATE insurance
            SET
                expiry_date = CURDATE(),
                report_file = ?
            WHERE id = ?
        ");

        if (!$upd) {

            if (file_exists($destination)) {
                unlink($destination);
            }

            $_SESSION['error'] =
                "Database error while updating insurance: " .
                $conn->error;

            header("Location: insurance.php");
            exit;
        }

        $upd->bind_param(
            "si",
            $fileName,
            $insurance_id
        );


        if (!$upd->execute()) {

            if (file_exists($destination)) {
                unlink($destination);
            }

            $_SESSION['error'] =
                "Unable to update insurance: " .
                $upd->error;

            header("Location: insurance.php");
            exit;
        }


        /* -------------------------------------------------
           DELETE OLD FILE
        ------------------------------------------------- */

        if (!empty($oldFile)) {

            $oldFileName = basename($oldFile);

            $oldPath = $uploadDir . $oldFileName;

            if (
                file_exists($oldPath) &&
                $oldFileName !== $fileName
            ) {

                unlink($oldPath);
            }
        }


        $_SESSION['success'] =
            "Insurance report renewed successfully.";
    }


    /* =====================================================
       INSERT NEW INSURANCE
    ===================================================== */

    else {

        $ins = $conn->prepare("
            INSERT INTO insurance
                (asset_id, expiry_date, report_file)
            VALUES
                (?, CURDATE(), ?)
        ");

        if (!$ins) {

            if (file_exists($destination)) {
                unlink($destination);
            }

            $_SESSION['error'] =
                "Database error while adding insurance: " .
                $conn->error;

            header("Location: insurance.php");
            exit;
        }

        $ins->bind_param(
            "is",
            $asset_id,
            $fileName
        );


        if (!$ins->execute()) {

            if (file_exists($destination)) {
                unlink($destination);
            }

            $_SESSION['error'] =
                "Unable to add insurance: " .
                $ins->error;

            header("Location: insurance.php");
            exit;
        }


        $_SESSION['success'] =
            "Insurance report submitted successfully.";
    }


    /* =====================================================
       REDIRECT
    ===================================================== */

    header("Location: insurance.php");
    exit;
}


/* =========================================================
   GET OWNER LIFTS
========================================================= */

$lifts = $conn->prepare("
    SELECT
        id,
        asset_name
    FROM assets
    WHERE owner_id = ?
    ORDER BY asset_name
");

if (!$lifts) {

    die("Database error: " . htmlspecialchars($conn->error));
}

$lifts->bind_param("i", $owner_id);
$lifts->execute();

$liftsResult = $lifts->get_result();


/* =========================================================
   GET INSURANCE RECORDS
========================================================= */

$q = $conn->prepare("
    SELECT
        a.id AS asset_id,
        a.asset_name,
        i.expiry_date,
        i.report_file
    FROM assets a
    LEFT JOIN insurance i
        ON i.asset_id = a.id
    WHERE a.owner_id = ?
    ORDER BY a.asset_name
");

if (!$q) {

    die("Database error: " . htmlspecialchars($conn->error));
}

$q->bind_param("i", $owner_id);
$q->execute();

$insuranceList = $q->get_result();


/* =========================================================
   HEADER
========================================================= */

include('../includes/header.php');
include('../includes/sidebar-owner.php');

?>


<div class="content">

    <h3 class="mb-3">Insurance</h3>


    <!-- =====================================================
         SUCCESS MESSAGE
    ====================================================== -->

    <?php if (!empty($_SESSION['success'])): ?>

        <div class="alert alert-success alert-dismissible fade show">

            <?= htmlspecialchars($_SESSION['success']) ?>

            <button
                type="button"
                class="btn-close"
                onclick="this.closest('.alert').remove()">
            </button>

        </div>

        <?php unset($_SESSION['success']); ?>

    <?php endif; ?>


    <!-- =====================================================
         ERROR MESSAGE
    ====================================================== -->

    <?php if (!empty($_SESSION['error'])): ?>

        <div class="alert alert-danger alert-dismissible fade show">

            <?= htmlspecialchars($_SESSION['error']) ?>

            <button
                type="button"
                class="btn-close"
                onclick="this.closest('.alert').remove()">
            </button>

        </div>

        <?php unset($_SESSION['error']); ?>

    <?php endif; ?>


    <!-- =====================================================
         INSURANCE SUBMISSION FORM
    ====================================================== -->

    <div
        class="card shadow p-4 mb-4"
        style="max-width:600px;"
    >

        <form
            method="POST"
            enctype="multipart/form-data"
        >

            <?= csrfField() ?>


            <!-- SELECT LIFT -->

            <div class="mb-3">

                <label class="form-label fw-semibold">
                    Select Lift
                </label>

                <select
                    name="asset_id"
                    class="form-select"
                    required
                >

                    <option value="">
                        -- Select Lift --
                    </option>

                    <?php while ($l = $liftsResult->fetch_assoc()): ?>

                        <option value="<?= (int)$l['id'] ?>">

                            <?= htmlspecialchars($l['asset_name']) ?>

                        </option>

                    <?php endwhile; ?>

                </select>

            </div>


            <!-- UPLOAD REPORT -->

            <div class="mb-3">

                <label class="form-label fw-semibold">

                    Upload Insurance Report

                    <small class="text-muted">
                        (PDF/JPG/PNG, max 5MB)
                    </small>

                </label>

                <input
                    type="file"
                    name="report_file"
                    class="form-control"
                    accept=".pdf,.jpg,.jpeg,.png"
                    required
                >

            </div>


            <!-- SUBMIT -->

            <button
                type="submit"
                name="submit_insurance"
                class="btn btn-primary w-100"
            >

                Submit Insurance

            </button>

        </form>

    </div>


    <!-- =====================================================
         INSURANCE TABLE
    ====================================================== -->

    <div class="table-responsive">

        <table class="table table-bordered table-hover bg-white shadow">

            <thead class="table-dark">

                <tr>

                    <th>#</th>

                    <th>Lift</th>

                    <th>Insurance Status</th>

                    <th>Expiry Date</th>

                    <th>Report</th>

                </tr>

            </thead>


            <tbody>

            <?php

            $i = 1;

            if ($insuranceList->num_rows === 0):

            ?>

                <tr>

                    <td
                        colspan="5"
                        class="text-center text-muted"
                    >

                        No lifts found.

                    </td>

                </tr>

            <?php

            else:

                while ($r = $insuranceList->fetch_assoc()):


                    /* =================================================
                       STATUS
                    ================================================= */

                    if (empty($r['expiry_date'])) {

                        $status =
                            "<span class='badge bg-secondary'>
                                Not Submitted
                            </span>";

                    } else {

                        $expiryTimestamp =
                            strtotime($r['expiry_date']);

                        $todayTimestamp =
                            strtotime(date('Y-m-d'));

                        $daysLeft =
                            floor(
                                (
                                    $expiryTimestamp -
                                    $todayTimestamp
                                ) / 86400
                            );


                        if ($daysLeft < 0) {

                            $status =
                                "<span class='badge bg-danger'>
                                    Expired
                                </span>";

                        } elseif ($daysLeft <= 30) {

                            $status =
                                "<span class='badge bg-warning text-dark'>
                                    Due Soon
                                </span>";

                        } else {

                            $status =
                                "<span class='badge bg-success'>
                                    Active
                                </span>";
                        }
                    }


                    /* =================================================
                       REPORT
                    ================================================= */

                    $reportHtml = '—';


                    if (!empty($r['report_file'])) {

                        $safeFile =
                            basename($r['report_file']);

                        $filePath =
                            __DIR__ .
                            '/../uploads/insurance/' .
                            $safeFile;


                        if (file_exists($filePath)) {

                            $reportHtml =
                                "<a
                                    href='../uploads/insurance/" .
                                    rawurlencode($safeFile) .
                                    "'
                                    target='_blank'
                                    class='btn btn-sm btn-outline-primary'
                                >
                                    👁 View Report
                                </a>";

                        } else {

                            $reportHtml =
                                "<span class='badge bg-warning text-dark'>
                                    File Not Found
                                </span>";
                        }
                    }

            ?>

                    <tr>

                        <td>
                            <?= $i ?>
                        </td>

                        <td>
                            <?= htmlspecialchars($r['asset_name']) ?>
                        </td>

                        <td>
                            <?= $status ?>
                        </td>

                        <td>

                            <?php if (!empty($r['expiry_date'])): ?>

                                <?= htmlspecialchars($r['expiry_date']) ?>

                            <?php else: ?>

                                —

                            <?php endif; ?>

                        </td>

                        <td>
                            <?= $reportHtml ?>
                        </td>

                    </tr>

            <?php

                    $i++;

                endwhile;

            endif;

            ?>

            </tbody>

        </table>

    </div>

</div>


<?php include('../includes/footer.php'); ?>