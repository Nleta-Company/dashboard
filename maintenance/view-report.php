<?php
/* =========================================================
   NLETA — VIEW GENERATED REPORT
   ---------------------------------------------------------
   FIX:
   If report_path is NULL/empty in database but the PDF
   already exists in /reports/generated/, automatically
   find the PDF and repair report_path.

   Supported:
   - Admin
   - Technician
   - Owner

   TIMEZONE:
   Asia/Kolkata / IST
========================================================= */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

date_default_timezone_set('Asia/Kolkata');


/* =========================================================
   ROLE CHECK
========================================================= */

if (
    !isset($_SESSION['uid']) ||
    !isset($_SESSION['role'])
) {
    header('Location: ../login.php');
    exit;
}


$userId = (int)$_SESSION['uid'];

$userRole = strtolower(
    trim(
        (string)$_SESSION['role']
    )
);


$allowedRoles = [
    'admin',
    'technician',
    'owner'
];


if (!in_array($userRole, $allowedRoles, true)) {

    http_response_code(403);

    exit('Access denied.');
}


/* =========================================================
   HELPER
========================================================= */

function h($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}


/* =========================================================
   REQUEST ID
========================================================= */

$requestId = (int)(
    $_GET['request_id']
    ?? 0
);


if ($requestId <= 0) {

    http_response_code(400);

    exit('Invalid report request ID.');
}


/* =========================================================
   LOAD REPORT REQUEST
========================================================= */

$report = null;


/* ---------------------------------------------------------
   ADMIN
--------------------------------------------------------- */

if ($userRole === 'admin') {

    $stmt = $conn->prepare("
        SELECT
            id,
            job_id,
            owner_id,
            technician_id,
            status,
            report_path,
            requested_at

        FROM report_requests

        WHERE id = ?

        LIMIT 1
    ");

}


/* ---------------------------------------------------------
   TECHNICIAN
--------------------------------------------------------- */

elseif ($userRole === 'technician') {

    $stmt = $conn->prepare("
        SELECT
            id,
            job_id,
            owner_id,
            technician_id,
            status,
            report_path,
            requested_at

        FROM report_requests

        WHERE
            id = ?
            AND technician_id = ?

        LIMIT 1
    ");

}


/* ---------------------------------------------------------
   OWNER
--------------------------------------------------------- */

else {

    $stmt = $conn->prepare("
        SELECT
            id,
            job_id,
            owner_id,
            technician_id,
            status,
            report_path,
            requested_at

        FROM report_requests

        WHERE
            id = ?
            AND owner_id = ?

        LIMIT 1
    ");
}


if (!$stmt) {

    http_response_code(500);

    exit(
        'Database error: '
        .
        h($conn->error)
    );
}


/* =========================================================
   BIND PARAMETERS
========================================================= */

if ($userRole === 'admin') {

    $stmt->bind_param(
        'i',
        $requestId
    );

} else {

    $stmt->bind_param(
        'ii',
        $requestId,
        $userId
    );
}


/* =========================================================
   EXECUTE
========================================================= */

if (!$stmt->execute()) {

    $error = $stmt->error;

    $stmt->close();

    http_response_code(500);

    exit(
        'Unable to load report: '
        .
        h($error)
    );
}


$result = $stmt->get_result();

$report = $result->fetch_assoc();

$stmt->close();


/* =========================================================
   REPORT NOT FOUND
========================================================= */

if (!$report) {

    http_response_code(404);

    exit(
        'Report request not found or you do not have permission to view it.'
    );
}


/* =========================================================
   REPORT STATUS
========================================================= */

$status = strtolower(
    trim(
        (string)(
            $report['status']
            ?? ''
        )
    )
);


/* =========================================================
   REPORT PATH FROM DATABASE
========================================================= */

$databasePath = trim(
    (string)(
        $report['report_path']
        ?? ''
    )
);


/* =========================================================
   REPORT DIRECTORY
========================================================= */

$reportDirectory = realpath(
    __DIR__
    . '/../reports/generated'
);


if ($reportDirectory === false) {

    http_response_code(500);

    exit(
        'The reports/generated directory does not exist.'
    );
}


/* =========================================================
   FIND REPORT FILE
   ---------------------------------------------------------
   FIRST:
   Use report_path from database.

   SECOND:
   If report_path is empty, automatically search for a
   PDF containing the request ID.

   Example:
   NLETA_Report_TS-MP-103_1_20260831_....pdf

   Here "1" is the request ID.
========================================================= */

$realFile = false;


/* =========================================================
   OPTION 1 — DATABASE PATH EXISTS
========================================================= */

if ($databasePath !== '') {

    $fileName = basename(
        str_replace(
            '\\',
            '/',
            $databasePath
        )
    );


    if (
        $fileName !== ''
        &&
        $fileName !== '.'
        &&
        $fileName !== '..'
    ) {

        $candidate =
            $reportDirectory
            .
            DIRECTORY_SEPARATOR
            .
            $fileName;


        if (
            is_file($candidate)
        ) {

            $candidateReal =
                realpath($candidate);


            if (
                $candidateReal !== false
            ) {

                $normalizedDirectory =
                    rtrim(
                        $reportDirectory,
                        DIRECTORY_SEPARATOR
                    )
                    .
                    DIRECTORY_SEPARATOR;


                if (
                    strpos(
                        $candidateReal,
                        $normalizedDirectory
                    ) === 0
                ) {

                    $realFile =
                        $candidateReal;
                }
            }
        }
    }
}


/* =========================================================
   OPTION 2 — AUTOMATIC SEARCH
   ---------------------------------------------------------
   Database report_path is empty OR file was moved.

   Search for:
       *_REQUESTID_*.pdf

   Example:
       *_1_*.pdf
========================================================= */

if ($realFile === false) {

    $pattern =
        $reportDirectory
        .
        DIRECTORY_SEPARATOR
        .
        '*_'
        .
        $requestId
        .
        '_*.pdf';


    $matches =
        glob($pattern);


    if (
        is_array($matches)
        &&
        !empty($matches)
    ) {

        /*
         * Sort newest first.
         */
        usort(
            $matches,
            function (
                $a,
                $b
            ) {

                return filemtime($b)
                    <=>
                    filemtime($a);
            }
        );


        foreach (
            $matches
            as $match
        ) {

            if (
                !is_file($match)
            ) {
                continue;
            }


            $candidateReal =
                realpath($match);


            if (
                $candidateReal === false
            ) {
                continue;
            }


            $normalizedDirectory =
                rtrim(
                    $reportDirectory,
                    DIRECTORY_SEPARATOR
                )
                .
                DIRECTORY_SEPARATOR;


            if (
                strpos(
                    $candidateReal,
                    $normalizedDirectory
                ) !== 0
            ) {

                continue;
            }


            $realFile =
                $candidateReal;


            break;
        }
    }
}


/* =========================================================
   REPORT FILE NOT FOUND
========================================================= */

if ($realFile === false) {

    http_response_code(404);

    echo '<!DOCTYPE html>

    <html>

    <head>

        <meta charset="UTF-8">

        <title>Report Not Found</title>

        <style>

            body {
                font-family: Arial, sans-serif;
                background: #f4f6f9;
                padding: 40px;
                color: #172033;
            }

            .box {
                max-width: 700px;
                margin: 50px auto;
                background: #fff;
                padding: 30px;
                border-radius: 12px;
                box-shadow:
                    0 4px 20px
                    rgba(0,0,0,.08);
            }

            h2 {
                margin-top: 0;
                color: #dc3545;
            }

            .info {
                background: #f8f9fa;
                padding: 15px;
                border-radius: 8px;
                margin-top: 15px;
            }

        </style>

    </head>

    <body>

        <div class="box">

            <h2>
                Report File Not Found
            </h2>

            <p>
                The report request exists, but the generated PDF
                could not be found.
            </p>

            <div class="info">

                <strong>
                    Request ID:
                </strong>

                '
                .
                h($requestId)
                .
                '

                <br><br>

                <strong>
                    Database Path:
                </strong>

                '
                .
                h(
                    $databasePath
                    ?: 'Empty'
                )
                .
                '

                <br><br>

                <strong>
                    Expected Folder:
                </strong>

                '
                .
                h($reportDirectory)
                .
                '

            </div>

        </div>

    </body>

    </html>';

    exit;
}


/* =========================================================
   VERIFY FILE
========================================================= */

if (
    !is_file($realFile)
) {

    http_response_code(404);

    exit(
        'Report file does not exist.'
    );
}


if (
    !is_readable($realFile)
) {

    http_response_code(403);

    exit(
        'Report file cannot be read.'
    );
}


$fileSize =
    filesize($realFile);


if (
    $fileSize === false
    ||
    $fileSize <= 0
) {

    http_response_code(500);

    exit(
        'The report PDF is empty.'
    );
}


/* =========================================================
   REPAIR DATABASE report_path
   ---------------------------------------------------------
   This is the important fix.

   If database had NULL but file was found, save:

       reports/generated/FILENAME.pdf
========================================================= */

$actualFileName =
    basename($realFile);


$relativePath =
    'reports/generated/'
    .
    $actualFileName;


/* =========================================================
   SAVE PATH IF:
   - Database path is empty
   - OR saved path is different
========================================================= */

if (
    $databasePath !== $relativePath
) {

    $updateStmt =
        $conn->prepare("
            UPDATE report_requests

            SET
                report_path = ?,
                status = 'generated'

            WHERE
                id = ?

            LIMIT 1
        ");


    if ($updateStmt) {

        $updateStmt->bind_param(
            'si',
            $relativePath,
            $requestId
        );


        $updateStmt->execute();

        $updateStmt->close();
    }
}


/* =========================================================
   CLEAR OUTPUT BUFFER
========================================================= */

while (
    ob_get_level() > 0
) {

    ob_end_clean();
}


/* =========================================================
   PDF HEADERS
========================================================= */

header(
    'Content-Type: application/pdf'
);


header(
    'Content-Disposition: inline; filename="'
    .
    $actualFileName
    .
    '"'
);


header(
    'Content-Length: '
    .
    $fileSize
);


header(
    'Accept-Ranges: bytes'
);


header(
    'Cache-Control: private, max-age=0, must-revalidate'
);


header(
    'Pragma: public'
);


/* =========================================================
   SEND PDF
========================================================= */

readfile(
    $realFile
);

exit;