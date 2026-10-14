<?php
/* =========================================================
   NLETA — AUTOMATIC PAT-R01 REPORT GENERATOR
   ---------------------------------------------------------
   Generates:
       PAT-R01 — Partial Acceptance Test REPORT

   Source:
       maintenance_jobs
       saf_contracts
       assets
       users
       maintenance_jobs.checklist JSON

   Report structure follows:
       ARCHIVE 11 | REV.1
       NLETA/PAT-R01/2026 (Rev.1)

   Generates a PDF automatically.

   IMPORTANT:
   Install Dompdf in the project:
       composer require dompdf/dompdf

   Expected file:
       ../vendor/autoload.php
========================================================= */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/security.php';


/* =========================================================
   DOMPDF
========================================================= */

$autoloadCandidates = [

    __DIR__ . '/../vendor/autoload.php',

    __DIR__ . '/../../vendor/autoload.php'

];

$autoloadLoaded = false;

foreach ($autoloadCandidates as $autoload) {

    if (file_exists($autoload)) {

        require_once $autoload;

        $autoloadLoaded = true;

        break;
    }
}


if (!$autoloadLoaded) {

    die(
        '<div style="
            font-family:Arial;
            padding:30px;
            background:#f8d7da;
            color:#842029;
        ">
            <h3>Dompdf is not installed</h3>

            <p>
                Run this command in your project folder:
            </p>

            <pre>
composer require dompdf/dompdf
            </pre>
        </div>'
    );
}


use Dompdf\Dompdf;

use Dompdf\Options;


/* =========================================================
   AUTHORIZATION
   ---------------------------------------------------------
   This generator is intended to run after Admin approval.
========================================================= */

require_role('admin');


date_default_timezone_set(
    'Asia/Kolkata'
);


$adminId = (int)(
    $_SESSION['uid']
    ??
    $_SESSION['user_id']
    ??
    0
);


/* =========================================================
   JOB ID
========================================================= */

$jobId = (int)(
    $_GET['job_id']
    ??
    $_POST['job_id']
    ??
    0
);


if ($jobId <= 0) {

    die(
        'Invalid inspection job ID.'
    );
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


function safeText($value): string
{
    return trim(
        preg_replace(
            '/\s+/',
            ' ',
            (string)$value
        )
    );
}


function formatDateValue($value): string
{
    if (
        empty($value)
    ) {

        return '—';
    }

    $timestamp =
        strtotime(
            (string)$value
        );

    if (
        $timestamp === false
    ) {

        return h(
            $value
        );
    }

    return date(
        'd / m / Y',
        $timestamp
    );
}


/* =========================================================
   LOAD COMPLETE JOB
========================================================= */

$stmt = $conn->prepare("
    SELECT
        mj.id AS job_id,

        mj.saf_id,
        mj.service_id,
        mj.asset_id,
        mj.owner_id,
        mj.technician_id,

        mj.status AS job_status,

        mj.created_at,
        mj.updated_at,

        mj.checklist,

        mj.recheck_date,
        mj.recheck_status,

        mj.admin_rating,
        mj.admin_review_remark,
        mj.admin_review_status,
        mj.admin_reviewed_at,

        mj.certificate_status,
        mj.certificate_no,
        mj.certificate_generated_at,

        s.psga_no,
        s.saf_no,
        s.service_code,
        s.service_name,
        s.proposed_inspection_date,
        s.owner_accepted_at,
        s.status AS saf_status,

        owner.name AS owner_name,
        owner.email AS owner_email,

        tech.name AS technician_name,
        tech.email AS technician_email,

        a.asset_name,
        a.display_asset_id,
        a.location,
        a.asset_type,
        a.category

    FROM maintenance_jobs mj

    INNER JOIN saf_contracts s
        ON s.id = mj.saf_id

    LEFT JOIN users owner
        ON owner.id = mj.owner_id

    LEFT JOIN users tech
        ON tech.id = mj.technician_id

    LEFT JOIN assets a
        ON a.id = mj.asset_id

    WHERE
        mj.id = ?
        AND mj.job_type = 'saf_inspection'

    LIMIT 1
");


if (!$stmt) {

    die(
        'Unable to prepare report query: '
        . h(
            $conn->error
        )
    );
}


$stmt->bind_param(
    'i',
    $jobId
);


$stmt->execute();


$job =
    $stmt
    ->get_result()
    ->fetch_assoc();


$stmt->close();


if (!$job) {

    die(
        'Inspection record not found.'
    );
}


/* =========================================================
   ADMIN REVIEW CHECK
========================================================= */

$adminReviewStatus =
    strtolower(
        trim(
            (string)(
                $job[
                    'admin_review_status'
                ]
                ??
                ''
            )
        )
    );


if (
    $adminReviewStatus
    !==
    'approved'
) {

    die(
        '<div style="
            font-family:Arial;
            padding:30px;
            color:#842029;
            background:#f8d7da;
        ">
            <h3>Report Not Ready</h3>

            <p>
                PAT-R01 can only be generated after
                Admin final review is approved.
            </p>
        </div>'
    );
}


/* =========================================================
   DECODE CHECKLIST
========================================================= */

$checklist = [];


if (
    !empty(
        $job[
            'checklist'
        ]
    )
) {

    $decoded =
        json_decode(
            $job[
                'checklist'
            ],
            true
        );


    if (
        is_array(
            $decoded
        )
    ) {

        $checklist =
            $decoded;
    }
}


/* =========================================================
   ORIGINAL RESULTS
========================================================= */

$results =
    is_array(
        $checklist[
            'results'
        ]
        ??
        null
    )
        ?
        $checklist[
            'results'
        ]
        :
        [];


/* =========================================================
   SECTION REMARKS
========================================================= */

$sectionRemarks =
    is_array(
        $checklist[
            'section_remarks'
        ]
        ??
        null
    )
        ?
        $checklist[
            'section_remarks'
        ]
        :
        [];


/* =========================================================
   RECHECK RESULTS
========================================================= */

$recheckResults =
    is_array(
        $checklist[
            'recheck_results'
        ]
        ??
        null
    )
        ?
        $checklist[
            'recheck_results'
        ]
        :
        [];


/* =========================================================
   FINAL STATUS
========================================================= */

$finalStatus =
    strtoupper(
        trim(
            (string)(
                $checklist[
                    'final_status'
                ]
                ??
                ''
            )
        )
    );


/* =========================================================
   OWNER APPROVAL
========================================================= */

$ownerApproval =
    is_array(
        $checklist[
            'owner_approval'
        ]
        ??
        null
    )
        ?
        $checklist[
            'owner_approval'
        ]
        :
        [];


$ownerApprovalStatus =
    (string)(
        $ownerApproval[
            'status'
        ]
        ??
        ''
    );


$ownerApprovalReason =
    (string)(
        $ownerApproval[
            'reason'
        ]
        ??
        ''
    );


/* =========================================================
   COUNTS
========================================================= */

$totalCount =
    count(
        $results
    );

$okCount = 0;

$ncCount = 0;

$naCount = 0;

$peCount = 0;


foreach (
    $results as $item
) {

    if (
        !is_array(
            $item
        )
    ) {

        continue;
    }


    $result =
        strtoupper(
            trim(
                (string)(
                    $item[
                        'result'
                    ]
                    ??
                    ''
                )
            )
        );


    switch (
        $result
    ) {

        case 'OK':
            $okCount++;
            break;

        case 'NC':
            $ncCount++;
            break;

        case 'NA':
            $naCount++;
            break;

        case 'PE':
            $peCount++;
            break;
    }
}


/* =========================================================
   RECHECK COUNTS
========================================================= */

$recheckOkCount = 0;

$recheckNcCount = 0;

$recheckCompletedCount = 0;


foreach (
    $recheckResults
    as $ref => $item
) {

    if (
        !is_array(
            $item
        )
    ) {

        continue;
    }


    $originalItem =
        $results[
            $ref
        ]
        ??
        null;


    /*
     * Only original NC checkpoints
     * are valid recheck items.
     */

    if (
        !is_array(
            $originalItem
        )
    ) {

        continue;
    }


    $originalResult =
        strtoupper(
            trim(
                (string)(
                    $originalItem[
                        'result'
                    ]
                    ??
                    ''
                )
            )
        );


    if (
        $originalResult
        !==
        'NC'
    ) {

        continue;
    }


    $rr =
        strtoupper(
            trim(
                (string)(
                    $item[
                        'result'
                    ]
                    ??
                    ''
                )
            )
        );


    if (
        $rr
        ===
        'OK'
    ) {

        $recheckOkCount++;

        $recheckCompletedCount++;

    } elseif (
        $rr
        ===
        'NC'
    ) {

        $recheckNcCount++;

        $recheckCompletedCount++;
    }
}


/* =========================================================
   COMPLIANCE OUTCOME
   ---------------------------------------------------------
   Based on final / recheck state.
========================================================= */

$complianceOutcome =
    'Non-Compliant';


if (
    $recheckStatus =
        strtolower(
            trim(
                (string)(
                    $job[
                        'recheck_status'
                    ]
                    ??
                    ''
                )
            )
        )
) {

    if (
        $recheckStatus
        ===
        'completed'
    ) {

        if (
            $recheckNcCount
            >
            0
        ) {

            $complianceOutcome =
                'Non-Compliant';

        } else {

            /*
             * Original NCs have all passed
             * recheck.
             */

            $complianceOutcome =
                'Compliant';
        }

    } elseif (
        $recheckStatus
        ===
        'scheduled'
    ) {

        $complianceOutcome =
            'Compliant with Observations';
    }
}


/*
 * If no recheck is required and the original
 * checklist contains no NC, mark Compliant.
 */

if (
    (
        $recheckStatus
        ===
        ''
    )
    ||
    (
        $recheckStatus
        ===
        'not_required'
    )
) {

    if (
        $ncCount
        ===
        0
    ) {

        $complianceOutcome =
            'Compliant';

    } else {

        $complianceOutcome =
            'Non-Compliant';
    }
}


/*
 * Final checklist status can override where appropriate.
 */

if (
    stripos(
        $finalStatus,
        'CRITICAL'
    )
    !==
    false
) {

    $complianceOutcome =
        'Critical Safety Risk';
}


/* =========================================================
   ENGINEERING FINDINGS
========================================================= */

$engineeringFindings = [];


foreach (
    $results as $ref => $item
) {

    if (
        !is_array(
            $item
        )
    ) {

        continue;
    }


    $result =
        strtoupper(
            trim(
                (string)(
                    $item[
                        'result'
                    ]
                    ??
                    ''
                )
            )
        );


    if (
        $result
        !==
        'NC'
    ) {

        continue;
    }


    $remark =
        trim(
            (string)(
                $item[
                    'remark'
                ]
                ??
                $item[
                    'remarks'
                ]
                ??
                ''
            )
        );


    if (
        $remark
        ===
        ''
    ) {

        $remark =
            'Non-compliance identified during inspection.';
    }


    /*
     * Determine risk.
     */

    $risk =
        'Medium';


    $remarkUpper =
        strtoupper(
            $remark
        );


    if (
        strpos(
            $remarkUpper,
            'CRITICAL'
        )
        !==
        false
        ||
        strpos(
            $remarkUpper,
            'DANGER'
        )
        !==
        false
        ||
        strpos(
            $remarkUpper,
            'IMMEDIATE'
        )
        !==
        false
    ) {

        $risk =
            'Critical';

    } elseif (
        strpos(
            $remarkUpper,
            'HIGH'
        )
        !==
        false
    ) {

        $risk =
            'High';
    }


    /*
     * Find recheck result.
     */

    $recheckResultForFinding =
        'Not Rechecked';


    $recheckRemarkForFinding =
        '';


    if (
        isset(
            $recheckResults[
                $ref
            ]
        )
        &&
        is_array(
            $recheckResults[
                $ref
            ]
        )
    ) {

        $recheckResultForFinding =
            strtoupper(
                trim(
                    (string)(
                        $recheckResults[
                            $ref
                        ][
                            'result'
                        ]
                        ??
                        ''
                    )
                )
            );


        $recheckRemarkForFinding =
            trim(
                (string)(
                    $recheckResults[
                        $ref
                    ][
                        'remark'
                    ]
                    ??
                    ''
                )
            );
    }


    /*
     * Recommendation
     */

    $recommendation =
        'Rectification required.';


    if (
        $recheckResultForFinding
        ===
        'OK'
    ) {

        $recommendation =
            'Rectification completed and verified during recheck.';

    } elseif (
        $recheckResultForFinding
        ===
        'NC'
    ) {

        $recommendation =
            'Rectification remains outstanding.';
    }


    $engineeringFindings[] = [

        'ref' =>
            $ref,

        'finding' =>
            $remark,

        'risk' =>
            $risk,

        'recheck' =>
            $recheckResultForFinding,

        'recheck_remark' =>
            $recheckRemarkForFinding,

        'recommendation' =>
            $recommendation

    ];
}


/* =========================================================
   REPORT NUMBER
   ---------------------------------------------------------
   Auto generated only after Admin approval.
========================================================= */

$year =
    date(
        'Y'
    );


$reportPrefix =
    'NLETA/PAT-R01/'
    .
    $year
    .
    '/';


/*
 * Use existing certificate/report number if one
 * already exists. Otherwise generate a number.
 */

$reportNo =
    trim(
        (string)(
            $job[
                'certificate_no'
            ]
            ??
            ''
        )
    );


if (
    $reportNo
    ===
    ''
) {

    /*
     * Use database job ID as a deterministic sequence
     * for this local system.
     */

    $reportNo =
        $reportPrefix
        .
        str_pad(
            (string)$jobId,
            5,
            '0',
            STR_PAD_LEFT
        );
}


/* =========================================================
   SAVE GENERATED REPORT NUMBER / STATUS
   ---------------------------------------------------------
   Do this before sending output.
========================================================= */

$saveReport =
    $conn->prepare("
        UPDATE maintenance_jobs

        SET
            certificate_status = 'generated',
            certificate_no = ?,
            certificate_generated_at = NOW()

        WHERE id = ?
          AND job_type = 'saf_inspection'
    ");


if (
    $saveReport
) {

    $saveReport->bind_param(
        'si',
        $reportNo,
        $jobId
    );


    $saveReport->execute();


    $saveReport->close();
}


/* =========================================================
   REPORT VALUES
========================================================= */

$archiveNo =
    '11';


$revision =
    'Rev.1';


$documentCode =
    'PAT-R01';


$documentId =
    'NLETA/PAT-R01/2026 (Rev.1)';


$classification =
    'Customer Report';


$previousArchive =
    'Archive 10 — CHK-PAT';


$nextArchive =
    'Archive 12 — PAT-C01';


$clientName =
    safeText(
        $job[
            'owner_name'
        ]
        ??
        ''
    );


$site =
    safeText(
        $job[
            'location'
        ]
        ??
        ''
    );


$liftId =
    safeText(
        $job[
            'display_asset_id'
        ]
        ?:
        $job[
            'asset_name'
        ]
        ?:
        ''
    );


$inspectionId =
    'SAF-'
    .
    $jobId;


$inspectionDate =
    formatDateValue(
        $job[
            'proposed_inspection_date'
        ]
        ??
        $job[
            'created_at'
        ]
        ??
        ''
    );


$inspector =
    safeText(
        $job[
            'technician_name'
        ]
        ??
        ''
    );


$amcAgency =
    'NLETA';


$unitsInspected =
    '1';


/* =========================================================
   OPTION CHECKMARKS
========================================================= */

$compliantChecked =
    $complianceOutcome
    ===
    'Compliant';


$observationsChecked =
    $complianceOutcome
    ===
    'Compliant with Observations';


$nonCompliantChecked =
    $complianceOutcome
    ===
    'Non-Compliant';


$criticalChecked =
    $complianceOutcome
    ===
    'Critical Safety Risk';


$check =
    '<span class="checkbox">☐</span>';


$checked =
    '<span class="checkbox checked">☑</span>';


/* =========================================================
   ENGINEERING FINDINGS HTML
========================================================= */

$findingsHtml = '';


if (
    empty(
        $engineeringFindings
    )
) {

    $findingsHtml = '
        <tr>
            <td colspan="6" class="center">
                No engineering findings / NC observations recorded.
            </td>
        </tr>
    ';

} else {

    foreach (
        $engineeringFindings
        as $finding
    ) {

        $recheckText =
            $finding[
                'recheck'
            ];


        if (
            $recheckText
            ===
            'OK'
        ) {

            $recheckText =
                'OK';

        } elseif (
            $recheckText
            ===
            'NC'
        ) {

            $recheckText =
                'NC';
        }


        $findingsHtml .= '
            <tr>

                <td>
                    <strong>'
                    .
                    h(
                        $finding[
                            'ref'
                        ]
                    )
                    .
                    '</strong>
                </td>


                <td>'
                    .
                    nl2br(
                        h(
                            $finding[
                                'finding'
                            ]
                        )
                    )
                    .
                    '</td>


                <td class="center">
                    '
                    .
                    h(
                        $finding[
                            'risk'
                        ]
                    )
                    .
                '</td>


                <td class="center">
                    '
                    .
                    h(
                        $recheckText
                    )
                    .
                '</td>


                <td>'
                    .
                    (
                        trim(
                            $finding[
                                'recheck_remark'
                            ]
                        )
                        !==
                        ''
                            ?
                            nl2br(
                                h(
                                    $finding[
                                        'recheck_remark'
                                    ]
                                )
                            )
                            :
                            '—'
                    )
                    .
                '</td>


                <td>'
                    .
                    h(
                        $finding[
                            'recommendation'
                        ]
                    )
                    .
                '</td>

            </tr>
        ';
    }
}


/* =========================================================
   REPORT HTML
========================================================= */

$html = '<!DOCTYPE html>

<html>

<head>

<meta charset="UTF-8">

<title>
'
.
h(
    $documentCode
)
.
'
</title>


<style>

@page {

    size: A4;

    margin:
        18mm
        14mm
        18mm
        14mm;
}


body {

    font-family:
        DejaVu Sans,
        Arial,
        sans-serif;

    font-size:
        10px;

    color:
        #202428;

    line-height:
        1.4;
}


.header {

    border-bottom:
        2px solid #202428;

    padding-bottom:
        10px;

    margin-bottom:
        14px;
}


.agency {

    font-size:
        16px;

    font-weight:
        700;

    text-align:
        center;
}


.archive {

    text-align:
        center;

    font-size:
        10px;

    margin-top:
        3px;
}


.report-title {

    font-size:
        18px;

    font-weight:
        700;

    text-align:
        center;

    margin:
        8px
        0
        3px;
}


.document-id {

    text-align:
        center;

    font-size:
        9px;

    color:
        #555;
}


.section-title {

    background:
        #202428;

    color:
        #ffffff;

    padding:
        7px
        9px;

    font-weight:
        700;

    font-size:
        11px;

    margin-top:
        13px;

}


table {

    width:
        100%;

    border-collapse:
        collapse;

}


th,
td {

    border:
        1px solid #cfd5db;

    padding:
        6px
        7px;

    vertical-align:
        top;
}


th {

    background:
        #f1f3f5;

    font-weight:
        700;
}


.center {

    text-align:
        center;
}


.document-table td:first-child {

    width:
        34%;

    font-weight:
        700;
}


.summary-table td:first-child {

    width:
        24%;

    font-weight:
        700;
}


.findings-table {

    font-size:
        8.5px;
}


.findings-table th {

    font-size:
        8.5px;
}


.outcome {

    margin-top:
        4px;
}


.checkbox {

    font-size:
        12px;

    margin-right:
        4px;
}


.checked {

    font-weight:
        700;
}


.declaration {

    border:
        1px solid #cfd5db;

    padding:
        10px;

    margin-top:
        10px;

    background:
        #fafafa;
}


.footer {

    margin-top:
        15px;

    padding-top:
        7px;

    border-top:
        1px solid #cfd5db;

    font-size:
        8px;

    color:
        #666;

    text-align:
        center;
}


.rating {

    font-size:
        15px;

    letter-spacing:
        2px;

    color:
        #b87900;
}


.meta {

    color:
        #555;

    font-size:
        9px;
}

</style>

</head>


<body>


<div class="header">

    <div class="agency">
        National Lift Escalator Testing Agency (NLETA)
    </div>


    <div class="archive">

        ARCHIVE 11 | REV.1

    </div>


    <div class="report-title">

        PAT-R01 — Partial Acceptance &amp; TEST REPORT

    </div>


    <div class="document-id">

        Document ID:
        '
        .
        h(
            $documentId
        )
        .
        '

    </div>

</div>


<div class="section-title">

    Document Control

</div>


<table class="document-table">

<tr>

    <td>
        Archive No.
    </td>

    <td>
        '
        .
        h(
            $archiveNo
        )
        .
        '
    </td>

</tr>


<tr>

    <td>
        Document Code
    </td>

    <td>
        '
        .
        h(
            $documentCode
        )
        .
        '
    </td>

</tr>


<tr>

    <td>
        Revision
    </td>

    <td>
        '
        .
        h(
            $revision
        )
        .
        '
    </td>

</tr>


<tr>

    <td>
        Classification
    </td>

    <td>
        '
        .
        h(
            $classification
        )
        .
        '
    </td>

</tr>


<tr>

    <td>
        Previous Archive
    </td>

    <td>
        '
        .
        h(
            $previousArchive
        )
        .
        '
    </td>

</tr>


<tr>

    <td>
        Next Archive
    </td>

    <td>
        '
        .
        h(
            $nextArchive
        )
        .
        '
    </td>

</tr>


<tr>

    <td>
        Report Purpose
    </td>

    <td>

        PAT-R01 is the official engineering report issued
        after completion of Partial Acceptance &amp; Test.
        It summarizes inspection scope, technical findings,
        risk classification, recommendations, and the overall
        compliance outcome.

    </td>

</tr>

</table>


<div class="section-title">

    Inspection Summary

</div>


<table class="summary-table">

<tr>

    <td>
        Client
    </td>

    <td>
        '
        .
        h(
            $clientName
        )
        .
        '
    </td>

</tr>


<tr>

    <td>
        Site
    </td>

    <td>
        '
        .
        h(
            $site
        )
        .
        '
    </td>

</tr>


<tr>

    <td>
        Inspection ID
    </td>

    <td>
        '
        .
        h(
            $inspectionId
        )
        .
        '
    </td>

</tr>


<tr>

    <td>
        Lift ID
    </td>

    <td>
        '
        .
        h(
            $liftId
        )
        .
        '
    </td>

</tr>


<tr>

    <td>
        Inspection Date
    </td>

    <td>
        '
        .
        h(
            $inspectionDate
        )
        .
        '
    </td>

</tr>


<tr>

    <td>
        Inspector
    </td>

    <td>
        '
        .
        h(
            $inspector
        )
        .
        '
    </td>

</tr>


<tr>

    <td>
        AMC Agency
    </td>

    <td>
        '
        .
        h(
            $amcAgency
        )
        .
        '
    </td>

</tr>


<tr>

    <td>
        Units Inspected
    </td>

    <td>
        '
        .
        h(
            $unitsInspected
        )
        .
        '
    </td>

</tr>

</table>


<div class="section-title">

    Engineering Findings

</div>


<table class="findings-table">

<thead>

<tr>

    <th>
        Item
    </th>

    <th>
        Risk / Finding
    </th>

    <th>
        Risk Status
    </th>

    <th>
        Recheck
    </th>

    <th>
        Recheck Remarks
    </th>

    <th>
        Recommendation
    </th>

</tr>

</thead>


<tbody>

'
.
$findingsHtml
.
'

</tbody>

</table>


<div class="section-title">

    Compliance Outcome

</div>


<table>

<tr>

    <th>
        Result Selection
    </th>

    <th>
        Checklist Summary
    </th>

</tr>


<tr>

    <td>

        <div class="outcome">

            '
            .
            (
                $compliantChecked
                    ?
                    $checked
                    :
                    $check
            )
            .
            ' Compliant

        </div>


        <div class="outcome">

            '
            .
            (
                $observationsChecked
                    ?
                    $checked
                    :
                    $check
            )
            .
            ' Compliant with Observations

        </div>


        <div class="outcome">

            '
            .
            (
                $nonCompliantChecked
                    ?
                    $checked
                    :
                    $check
            )
            .
            ' Non-Compliant

        </div>


        <div class="outcome">

            '
            .
            (
                $criticalChecked
                    ?
                    $checked
                    :
                    $check
            )
            .
            ' Critical Safety Risk

        </div>

    </td>


    <td>

        <strong>
            Original Checklist
        </strong>

        <br>

        Total:
        '
        .
        (int)$totalCount
        .
        '

        &nbsp; | &nbsp;

        OK:
        '
        .
        (int)$okCount
        .
        '

        &nbsp; | &nbsp;

        NC:
        '
        .
        (int)$ncCount
        .
        '

        &nbsp; | &nbsp;

        NA:
        '
        .
        (int)$naCount
        .
        '

        &nbsp; | &nbsp;

        PE:
        '
        .
        (int)$peCount
        .
        '

        <br><br>


        <strong>
            Recheck
        </strong>

        <br>

        Status:
        '
        .
        h(
            ucfirst(
                $recheckStatus
                ?:
                'Not Required'
            )
        )
        .
        '

        <br>

        Rechecked:
        '
        .
        (int)$recheckCompletedCount
        .
        '
        /
        '
        .
        (int)$ncCount
        .
        '

        <br>

        Recheck OK:
        '
        .
        (int)$recheckOkCount
        .
        '

        &nbsp; | &nbsp;

        Recheck NC:
        '
        .
        (int)$recheckNcCount
        .
        '

        <br><br>


        <strong>
            Overall Outcome:
        </strong>

        '
        .
        h(
            $complianceOutcome
        )
        .
        '

        <br><br>


        <strong>
            Admin Rating:
        </strong>

        <span class="rating">
            '
            .
            str_repeat(
                '★',
                max(
                    0,
                    min(
                        5,
                        (int)(
                            $job[
                                'admin_rating'
                            ]
                            ??
                            0
                        )
                    )
                )
            )
            .
            str_repeat(
                '☆',
                5 -
                max(
                    0,
                    min(
                        5,
                        (int)(
                            $job[
                                'admin_rating'
                            ]
                            ??
                            0
                        )
                    )
                )
            )
            .
        '</span>

        <br>


        <span class="meta">

            '
            .
            (
                !empty(
                    $job[
                        'admin_review_remark'
                    ]
                )
                    ?
                    nl2br(
                        h(
                            $job[
                                'admin_review_remark'
                            ]
                        )
                    )
                    :
                    ''
            )
            .
        '</span>

    </td>

</tr>

</table>


<div class="section-title">

    Inspector Declaration

</div>


<div class="declaration">

    I certify that this report is based on an independent
    engineering inspection supported by documented technical
    evidence.

    <br><br>

    <strong>
        Inspector:
    </strong>

    '
    .
    h(
        $inspector
    )
    .
    '

    <br>

    <strong>
        Inspection Date:
    </strong>

    '
    .
    h(
        $inspectionDate
    )
    .
    '

    <br>

    <strong>
        Report No.:
    </strong>

    '
    .
    h(
        $reportNo
    )
    .
    '

</div>


<div class="footer">

    END OF ARCHIVE 11 — PAT-R01 (Rev.1)

    <br>

    Next Archive: 12 — PAT-C01

</div>


</body>

</html>';


/* =========================================================
   DOMPDF CONFIG
========================================================= */

$options =
    new Options();

$options->set(
    'isHtml5ParserEnabled',
    true
);

$options->set(
    'isRemoteEnabled',
    true
);

$options->set(
    'defaultFont',
    'DejaVu Sans'
);


$dompdf =
    new Dompdf(
        $options
    );


/* =========================================================
   LOAD HTML
========================================================= */

$dompdf->loadHtml(
    $html,
    'UTF-8'
);


/* =========================================================
   PAPER
========================================================= */

$dompdf->setPaper(
    'A4',
    'portrait'
);


/* =========================================================
   RENDER
========================================================= */

$dompdf->render();


/* =========================================================
   SAVE PDF
========================================================= */

$reportDir =
    __DIR__
    . '/generated/';


if (
    !is_dir(
        $reportDir
    )
) {

    if (
        !mkdir(
            $reportDir,
            0755,
            true
        )
    ) {

        die(
            'Unable to create report directory.'
        );
    }
}


/* =========================================================
   FILE NAME
========================================================= */

$fileSafeLift =
    preg_replace(
        '/[^A-Za-z0-9_-]+/',
        '_',
        $liftId
    );


$fileSafeReport =
    preg_replace(
        '/[^A-Za-z0-9_-]+/',
        '_',
        str_replace(
            '/',
            '-',
            $reportNo
        )
    );


$fileName =
    'PAT-R01_'
    .
    $fileSafeLift
    .
    '_'
    .
    $fileSafeReport
    .
    '.pdf';


$filePath =
    $reportDir
    .
    $fileName;


/* =========================================================
   WRITE PDF
========================================================= */

$pdfOutput =
    $dompdf->output();


if (
    file_put_contents(
        $filePath,
        $pdfOutput
    )
    ===
    false
) {

    die(
        'Unable to save generated PAT-R01 PDF.'
    );
}


/* =========================================================
   SAVE REPORT PATH
========================================================= */

$relativeReportPath =
    'reports/generated/'
    .
    $fileName;


/*
 * We reuse certificate_no for the report number above.
 * If you later add a dedicated report_file column,
 * move this value there.
 */

if (
    isset(
        $conn
    )
    &&
    $conn instanceof mysqli
) {

    /*
     * Try to save generated file path in certificate_no
     * only if it is not already being used for another
     * purpose is NOT recommended.
     *
     * Therefore we leave certificate_no as report number.
     *
     * The actual path is returned to the caller.
     */
}


/* =========================================================
   OUTPUT
   ---------------------------------------------------------
   Send PDF directly to browser.
========================================================= */

$dompdf->stream(
    $fileName,
    [
        'Attachment' => true
    ]
);

exit;