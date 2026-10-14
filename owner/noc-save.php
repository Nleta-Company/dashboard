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

$owner_id = (int)($_SESSION['uid'] ?? 0);


/* =========================================================
   ONLY POST REQUEST
========================================================= */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    header("Location: noc-registration.php");
    exit;
}


/* =========================================================
   CSRF VALIDATION
========================================================= */

if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {

    $_SESSION['error'] =
        "Invalid request. Please try again.";

    header("Location: noc-registration.php");
    exit;
}


/* =========================================================
   FORM VALUES
========================================================= */

$asset_id = (int)($_POST['asset_id'] ?? 0);

$noc_no = trim(
    $_POST['noc_no'] ?? ''
);

$noc_date = trim(
    $_POST['noc_date'] ?? ''
);

$noc_expiry_date = trim(
    $_POST['noc_expiry_date'] ?? ''
);

$registration_no = trim(
    $_POST['registration_no'] ?? ''
);

$registration_date = trim(
    $_POST['registration_date'] ?? ''
);

$safety_awareness_date = trim(
    $_POST['safety_awareness_date'] ?? ''
);

$mock_drill_date = trim(
    $_POST['mock_drill_date'] ?? ''
);


/* =========================================================
   INSURANCE VALUES
========================================================= */

$insurance_no = trim(
    $_POST['insurance_no'] ?? ''
);

$insurance_start_date = trim(
    $_POST['insurance_start_date'] ?? ''
);

$insurance_expiry_date = trim(
    $_POST['insurance_expiry_date'] ?? ''
);


/* =========================================================
   BASIC VALIDATION
========================================================= */

if ($asset_id <= 0) {

    $_SESSION['error'] =
        "Please select an asset.";

    header("Location: noc-registration.php");
    exit;
}


if ($noc_no === '') {

    $_SESSION['error'] =
        "Please enter NOC number.";

    header(
        "Location: noc-registration.php?asset_id=" .
        $asset_id
    );

    exit;
}


if ($noc_date === '') {

    $_SESSION['error'] =
        "Please enter NOC date.";

    header(
        "Location: noc-registration.php?asset_id=" .
        $asset_id
    );

    exit;
}


if ($registration_no === '') {

    $_SESSION['error'] =
        "Please enter registration number.";

    header(
        "Location: noc-registration.php?asset_id=" .
        $asset_id
    );

    exit;
}


if ($registration_date === '') {

    $_SESSION['error'] =
        "Please enter registration date.";

    header(
        "Location: noc-registration.php?asset_id=" .
        $asset_id
    );

    exit;
}


if ($safety_awareness_date === '') {

    $_SESSION['error'] =
        "Please enter safety awareness date.";

    header(
        "Location: noc-registration.php?asset_id=" .
        $asset_id
    );

    exit;
}


if ($mock_drill_date === '') {

    $_SESSION['error'] =
        "Please enter mock drill date.";

    header(
        "Location: noc-registration.php?asset_id=" .
        $asset_id
    );

    exit;
}


/* =========================================================
   INSURANCE VALIDATION
========================================================= */

if ($insurance_no === '') {

    $_SESSION['error'] =
        "Please enter insurance number.";

    header(
        "Location: noc-registration.php?asset_id=" .
        $asset_id
    );

    exit;
}


if ($insurance_start_date === '') {

    $_SESSION['error'] =
        "Please enter insurance starting date.";

    header(
        "Location: noc-registration.php?asset_id=" .
        $asset_id
    );

    exit;
}


/* =========================================================
   CALCULATE NOC EXPIRY SERVER-SIDE
   NOC DATE + 3 YEARS
========================================================= */

$nocDateObject = DateTime::createFromFormat(
    'Y-m-d',
    $noc_date
);

if (
    !$nocDateObject ||
    $nocDateObject->format('Y-m-d') !== $noc_date
) {

    $_SESSION['error'] =
        "Invalid NOC date.";

    header(
        "Location: noc-registration.php?asset_id=" .
        $asset_id
    );

    exit;
}

$nocDateObject->modify('+3 years');

$noc_expiry_date =
    $nocDateObject->format('Y-m-d');


/* =========================================================
   CALCULATE INSURANCE EXPIRY SERVER-SIDE
   START DATE + 1 YEAR
========================================================= */

$insuranceStartObject =
    DateTime::createFromFormat(
        'Y-m-d',
        $insurance_start_date
    );

if (
    !$insuranceStartObject ||
    $insuranceStartObject->format('Y-m-d') !==
    $insurance_start_date
) {

    $_SESSION['error'] =
        "Invalid insurance starting date.";

    header(
        "Location: noc-registration.php?asset_id=" .
        $asset_id
    );

    exit;
}

$insuranceStartObject->modify('+1 year');

$insurance_expiry_date =
    $insuranceStartObject->format('Y-m-d');


/* =========================================================
   VALIDATE REGISTRATION DATE
========================================================= */

$registrationDateObject =
    DateTime::createFromFormat(
        'Y-m-d',
        $registration_date
    );

if (
    !$registrationDateObject ||
    $registrationDateObject->format('Y-m-d') !==
    $registration_date
) {

    $_SESSION['error'] =
        "Invalid registration date.";

    header(
        "Location: noc-registration.php?asset_id=" .
        $asset_id
    );

    exit;
}


/* =========================================================
   VALIDATE SAFETY AWARENESS DATE
========================================================= */

$safetyDateObject =
    DateTime::createFromFormat(
        'Y-m-d',
        $safety_awareness_date
    );

if (
    !$safetyDateObject ||
    $safetyDateObject->format('Y-m-d') !==
    $safety_awareness_date
) {

    $_SESSION['error'] =
        "Invalid safety awareness date.";

    header(
        "Location: noc-registration.php?asset_id=" .
        $asset_id
    );

    exit;
}


/* =========================================================
   VALIDATE MOCK DRILL DATE
========================================================= */

$mockDateObject =
    DateTime::createFromFormat(
        'Y-m-d',
        $mock_drill_date
    );

if (
    !$mockDateObject ||
    $mockDateObject->format('Y-m-d') !==
    $mock_drill_date
) {

    $_SESSION['error'] =
        "Invalid mock drill date.";

    header(
        "Location: noc-registration.php?asset_id=" .
        $asset_id
    );

    exit;
}


/* =========================================================
   CHECK ASSET BELONGS TO OWNER
========================================================= */

$assetCheck = $conn->prepare("
    SELECT
        id,
        asset_name
    FROM assets
    WHERE id = ?
      AND owner_id = ?
    LIMIT 1
");

if (!$assetCheck) {

    $_SESSION['error'] =
        "Database error while checking asset: " .
        $conn->error;

    header("Location: noc-registration.php");
    exit;
}

$assetCheck->bind_param(
    "ii",
    $asset_id,
    $owner_id
);

$assetCheck->execute();

$assetResult =
    $assetCheck->get_result();

if ($assetResult->num_rows === 0) {

    $_SESSION['error'] =
        "Invalid asset selected.";

    header("Location: noc-registration.php");
    exit;
}


/* =========================================================
   BEGIN TRANSACTION
========================================================= */

$conn->begin_transaction();


/* =========================================================
   CHECK EXISTING NOC
   TABLE = noc_registration
========================================================= */

$nocCheck = $conn->prepare("
    SELECT id
    FROM noc_registration
    WHERE asset_id = ?
    LIMIT 1
");

if (!$nocCheck) {

    $conn->rollback();

    $_SESSION['error'] =
        "Database error while checking NOC: " .
        $conn->error;

    header(
        "Location: noc-registration.php?asset_id=" .
        $asset_id
    );

    exit;
}

$nocCheck->bind_param(
    "i",
    $asset_id
);

$nocCheck->execute();

$nocResult =
    $nocCheck->get_result();


/* =========================================================
   UPDATE EXISTING NOC
========================================================= */

if ($nocResult->num_rows > 0) {

    $nocRow =
        $nocResult->fetch_assoc();

    $noc_id =
        (int)$nocRow['id'];


    $nocUpdate = $conn->prepare("
        UPDATE noc_registration
        SET
            owner_id = ?,
            noc_no = ?,
            noc_date = ?,
            noc_expiry_date = ?,
            registration_no = ?,
            registration_date = ?,
            safety_awareness_date = ?,
            mock_drill_date = ?
        WHERE id = ?
    ");

    if (!$nocUpdate) {

        $conn->rollback();

        $_SESSION['error'] =
            "Database error while updating NOC: " .
            $conn->error;

        header(
            "Location: noc-registration.php?asset_id=" .
            $asset_id
        );

        exit;
    }


    $nocUpdate->bind_param(
        "isssssssi",
        $owner_id,
        $noc_no,
        $noc_date,
        $noc_expiry_date,
        $registration_no,
        $registration_date,
        $safety_awareness_date,
        $mock_drill_date,
        $noc_id
    );


    if (!$nocUpdate->execute()) {

        $conn->rollback();

        $_SESSION['error'] =
            "Unable to update NOC: " .
            $nocUpdate->error;

        header(
            "Location: noc-registration.php?asset_id=" .
            $asset_id
        );

        exit;
    }
}


/* =========================================================
   INSERT NEW NOC
========================================================= */

else {

    $nocInsert = $conn->prepare("
        INSERT INTO noc_registration
        (
            asset_id,
            owner_id,
            noc_no,
            noc_date,
            noc_expiry_date,
            registration_no,
            registration_date,
            safety_awareness_date,
            mock_drill_date
        )
        VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$nocInsert) {

        $conn->rollback();

        $_SESSION['error'] =
            "Database error while adding NOC: " .
            $conn->error;

        header(
            "Location: noc-registration.php?asset_id=" .
            $asset_id
        );

        exit;
    }


    $nocInsert->bind_param(
        "iisssssss",
        $asset_id,
        $owner_id,
        $noc_no,
        $noc_date,
        $noc_expiry_date,
        $registration_no,
        $registration_date,
        $safety_awareness_date,
        $mock_drill_date
    );


    if (!$nocInsert->execute()) {

        $conn->rollback();

        $_SESSION['error'] =
            "Unable to save NOC: " .
            $nocInsert->error;

        header(
            "Location: noc-registration.php?asset_id=" .
            $asset_id
        );

        exit;
    }
}


/* =========================================================
   INSURANCE FILE CHECK
========================================================= */

$hasNewInsuranceFile = (
    isset($_FILES['insurance_file']) &&
    $_FILES['insurance_file']['error'] !==
    UPLOAD_ERR_NO_FILE
);


/* =========================================================
   GET EXISTING INSURANCE
========================================================= */

$insuranceCheck = $conn->prepare("
    SELECT
        id,
        report_file
    FROM insurance
    WHERE asset_id = ?
    LIMIT 1
");

if (!$insuranceCheck) {

    $conn->rollback();

    $_SESSION['error'] =
        "Database error while checking insurance: " .
        $conn->error;

    header(
        "Location: noc-registration.php?asset_id=" .
        $asset_id
    );

    exit;
}

$insuranceCheck->bind_param(
    "i",
    $asset_id
);

$insuranceCheck->execute();

$insuranceResult =
    $insuranceCheck->get_result();


$existingInsurance = null;

if ($insuranceResult->num_rows > 0) {

    $existingInsurance =
        $insuranceResult->fetch_assoc();
}


/* =========================================================
   REQUIRE FILE FOR FIRST INSURANCE ENTRY
========================================================= */

if (
    !$existingInsurance &&
    !$hasNewInsuranceFile
) {

    $conn->rollback();

    $_SESSION['error'] =
        "Please upload the insurance report.";

    header(
        "Location: noc-registration.php?asset_id=" .
        $asset_id
    );

    exit;
}


/* =========================================================
   FILE VARIABLES
========================================================= */

$newFileName = null;

$destination = null;

$oldFile = null;


/* =========================================================
   PROCESS NEW INSURANCE FILE
========================================================= */

if ($hasNewInsuranceFile) {

    $uploadedFile =
        $_FILES['insurance_file'];


    /* -----------------------------------------------------
       UPLOAD ERROR
    ----------------------------------------------------- */

    if (
        $uploadedFile['error'] !==
        UPLOAD_ERR_OK
    ) {

        $conn->rollback();

        $_SESSION['error'] =
            "Insurance file upload failed.";

        header(
            "Location: noc-registration.php?asset_id=" .
            $asset_id
        );

        exit;
    }


    $originalName =
        $uploadedFile['name'];

    $tmpName =
        $uploadedFile['tmp_name'];

    $fileSize =
        (int)$uploadedFile['size'];


    /* -----------------------------------------------------
       EXTENSION
    ----------------------------------------------------- */

    $extension =
        strtolower(
            pathinfo(
                $originalName,
                PATHINFO_EXTENSION
            )
        );


    /* -----------------------------------------------------
       ALLOWED EXTENSIONS
    ----------------------------------------------------- */

    $allowedExtensions = [
        'pdf',
        'jpg',
        'jpeg',
        'png'
    ];

    if (
        !in_array(
            $extension,
            $allowedExtensions,
            true
        )
    ) {

        $conn->rollback();

        $_SESSION['error'] =
            "Invalid file type. Only PDF, JPG, JPEG and PNG files are allowed.";

        header(
            "Location: noc-registration.php?asset_id=" .
            $asset_id
        );

        exit;
    }


    /* -----------------------------------------------------
       MAX SIZE = 5 MB
    ----------------------------------------------------- */

    if ($fileSize > 5242880) {

        $conn->rollback();

        $_SESSION['error'] =
            "File is too large. Maximum allowed size is 5 MB.";

        header(
            "Location: noc-registration.php?asset_id=" .
            $asset_id
        );

        exit;
    }


    /* -----------------------------------------------------
       REAL UPLOAD CHECK
    ----------------------------------------------------- */

    if (!is_uploaded_file($tmpName)) {

        $conn->rollback();

        $_SESSION['error'] =
            "Invalid insurance file upload.";

        header(
            "Location: noc-registration.php?asset_id=" .
            $asset_id
        );

        exit;
    }


    /* -----------------------------------------------------
       MIME TYPE
    ----------------------------------------------------- */

    $allowedMimeTypes = [
        'pdf'  => 'application/pdf',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png'
    ];

    $mimeType = '';


    if (function_exists('finfo_open')) {

        $finfo =
            finfo_open(
                FILEINFO_MIME_TYPE
            );

        if ($finfo) {

            $mimeType =
                finfo_file(
                    $finfo,
                    $tmpName
                );

            finfo_close($finfo);
        }
    }


    if (
        !empty($mimeType) &&
        isset($allowedMimeTypes[$extension]) &&
        $mimeType !==
        $allowedMimeTypes[$extension]
    ) {

        $conn->rollback();

        $_SESSION['error'] =
            "The uploaded insurance file type does not match its extension.";

        header(
            "Location: noc-registration.php?asset_id=" .
            $asset_id
        );

        exit;
    }


    /* -----------------------------------------------------
       UPLOAD DIRECTORY
    ----------------------------------------------------- */

    $uploadDir =
        __DIR__ .
        '/../uploads/insurance/';


    if (!is_dir($uploadDir)) {

        if (
            !mkdir(
                $uploadDir,
                0755,
                true
            )
        ) {

            $conn->rollback();

            $_SESSION['error'] =
                "Unable to create insurance upload folder.";

            header(
                "Location: noc-registration.php?asset_id=" .
                $asset_id
            );

            exit;
        }
    }


    /* -----------------------------------------------------
       UNIQUE FILE NAME
    ----------------------------------------------------- */

    $newFileName =
        'insurance_' .
        $owner_id .
        '_' .
        $asset_id .
        '_' .
        time() .
        '_' .
        bin2hex(
            random_bytes(4)
        ) .
        '.' .
        $extension;


    $destination =
        $uploadDir .
        $newFileName;


    /* -----------------------------------------------------
       MOVE FILE
    ----------------------------------------------------- */

    if (
        !move_uploaded_file(
            $tmpName,
            $destination
        )
    ) {

        $conn->rollback();

        $_SESSION['error'] =
            "Unable to save the insurance report.";

        header(
            "Location: noc-registration.php?asset_id=" .
            $asset_id
        );

        exit;
    }


    /* -----------------------------------------------------
       REMEMBER OLD FILE
    ----------------------------------------------------- */

    if ($existingInsurance) {

        $oldFile =
            $existingInsurance['report_file'] ?? '';
    }
}


/* =========================================================
   SAVE INSURANCE
========================================================= */


/* =========================================================
   UPDATE EXISTING INSURANCE
========================================================= */

if ($existingInsurance) {

    $insurance_id =
        (int)$existingInsurance['id'];


    /* -----------------------------------------------------
       UPDATE WITHOUT NEW FILE
    ----------------------------------------------------- */

    if ($newFileName === null) {

        $insuranceUpdate =
            $conn->prepare("
                UPDATE insurance
                SET
                    insurance_no = ?,
                    start_date = ?,
                    expiry_date = ?
                WHERE id = ?
            ");

        if (!$insuranceUpdate) {

            $conn->rollback();

            $_SESSION['error'] =
                "Database error while updating insurance: " .
                $conn->error;

            header(
                "Location: noc-registration.php?asset_id=" .
                $asset_id
            );

            exit;
        }


        $insuranceUpdate->bind_param(
            "sssi",
            $insurance_no,
            $insurance_start_date,
            $insurance_expiry_date,
            $insurance_id
        );


        if (!$insuranceUpdate->execute()) {

            $conn->rollback();

            $_SESSION['error'] =
                "Unable to update insurance: " .
                $insuranceUpdate->error;

            header(
                "Location: noc-registration.php?asset_id=" .
                $asset_id
            );

            exit;
        }
    }


    /* -----------------------------------------------------
       UPDATE WITH NEW FILE
    ----------------------------------------------------- */

    else {

        $insuranceUpdate =
            $conn->prepare("
                UPDATE insurance
                SET
                    insurance_no = ?,
                    start_date = ?,
                    expiry_date = ?,
                    report_file = ?
                WHERE id = ?
            ");

        if (!$insuranceUpdate) {

            if (
                $destination &&
                file_exists($destination)
            ) {
                unlink($destination);
            }

            $conn->rollback();

            $_SESSION['error'] =
                "Database error while updating insurance: " .
                $conn->error;

            header(
                "Location: noc-registration.php?asset_id=" .
                $asset_id
            );

            exit;
        }


        $insuranceUpdate->bind_param(
            "ssssi",
            $insurance_no,
            $insurance_start_date,
            $insurance_expiry_date,
            $newFileName,
            $insurance_id
        );


        if (!$insuranceUpdate->execute()) {

            if (
                $destination &&
                file_exists($destination)
            ) {
                unlink($destination);
            }

            $conn->rollback();

            $_SESSION['error'] =
                "Unable to update insurance: " .
                $insuranceUpdate->error;

            header(
                "Location: noc-registration.php?asset_id=" .
                $asset_id
            );

            exit;
        }
    }
}


/* =========================================================
   INSERT NEW INSURANCE
========================================================= */

else {

    $insuranceInsert =
        $conn->prepare("
            INSERT INTO insurance
            (
                asset_id,
                insurance_no,
                start_date,
                expiry_date,
                report_file
            )
            VALUES
            (?, ?, ?, ?, ?)
        ");

    if (!$insuranceInsert) {

        if (
            $destination &&
            file_exists($destination)
        ) {
            unlink($destination);
        }

        $conn->rollback();

        $_SESSION['error'] =
            "Database error while adding insurance: " .
            $conn->error;

        header(
            "Location: noc-registration.php?asset_id=" .
            $asset_id
        );

        exit;
    }


    $insuranceInsert->bind_param(
        "issss",
        $asset_id,
        $insurance_no,
        $insurance_start_date,
        $insurance_expiry_date,
        $newFileName
    );


    if (!$insuranceInsert->execute()) {

        if (
            $destination &&
            file_exists($destination)
        ) {
            unlink($destination);
        }

        $conn->rollback();

        $_SESSION['error'] =
            "Unable to save insurance: " .
            $insuranceInsert->error;

        header(
            "Location: noc-registration.php?asset_id=" .
            $asset_id
        );

        exit;
    }
}


/* =========================================================
   COMMIT TRANSACTION
========================================================= */

if (!$conn->commit()) {

    if (
        $destination &&
        file_exists($destination)
    ) {
        unlink($destination);
    }

    $_SESSION['error'] =
        "Unable to complete the transaction.";

    header(
        "Location: noc-registration.php?asset_id=" .
        $asset_id
    );

    exit;
}


/* =========================================================
   DELETE OLD INSURANCE FILE
   ONLY AFTER SUCCESSFUL COMMIT
========================================================= */

if (
    $newFileName !== null &&
    !empty($oldFile)
) {

    $uploadDir =
        __DIR__ .
        '/../uploads/insurance/';

    $oldFileName =
        basename($oldFile);

    $oldPath =
        $uploadDir .
        $oldFileName;


    if (
        file_exists($oldPath) &&
        $oldFileName !== $newFileName
    ) {

        unlink($oldPath);
    }
}


/* =========================================================
   SUCCESS MESSAGE
========================================================= */

if ($existingInsurance) {

    $_SESSION['success'] =
        "NOC and Insurance details updated successfully.";

} else {

    $_SESSION['success'] =
        "NOC and Insurance details saved successfully.";
}


/* =========================================================
   REDIRECT
========================================================= */

header(
    "Location: noc-registration.php?asset_id=" .
    $asset_id
);

exit;