<?php
/* =========================================================
   NLETA ADMIN — CHECKLIST REVIEWS + REPORT APPROVAL
   ---------------------------------------------------------

   SECTION 1:
   REPORT APPROVAL REQUESTS

   Workflow:

   Owner requests report
          ↓
   Technician receives request
          ↓
   Technician sends to Admin
          ↓
   ADMIN APPROVES REPORT REQUEST
          ↓
   Technician can Generate Report
          ↓
   Report sent to Owner


   SECTION 2:
   CHECKLIST REVIEWS

   Technician submits checklist
          ↓
   Owner approves
          ↓
          ↓
   Admin can view complete checklist


   REMOVED FROM CHECKLIST REVIEW:
   - Rating
   - Admin rating
   - Admin remarks
   - Checklist Approve
   - Checklist Reject
   - Return for review

   REPORT APPROVAL:
   - Only Approve Report Request
========================================================= */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';


require_role('admin');


/* =========================================================
   TIMEZONE
========================================================= */

date_default_timezone_set('Asia/Kolkata');


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
   UTC DATABASE TIME → IST
========================================================= */

function formatIST($datetime): string
{
    if (empty($datetime)) {
        return '—';
    }

    try {

        $date = new DateTime(
            $datetime,
            new DateTimeZone('UTC')
        );

        $date->setTimezone(
            new DateTimeZone('Asia/Kolkata')
        );

        return $date->format(
            'd / m / Y h:i A'
        ) . ' IST';

    } catch (Throwable $e) {

        return '—';
    }
}


/* =========================================================
   ADMIN ID
========================================================= */

$adminId = (int)(
    $_SESSION['uid']
    ?? $_SESSION['user_id']
    ?? 0
);


/* =========================================================
   APPROVE REPORT REQUEST
   ---------------------------------------------------------
   admin_pending
          ↓
   admin_approved
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    isset(
        $_POST['approve_report_request']
    )
) {

    $requestId =
        (int)(
            $_POST['request_id']
            ?? 0
        );


    if (
        $requestId <= 0
    ) {

        $_SESSION['error'] =
            'Invalid report approval request.';

        header(
            'Location: checklist-reviews.php'
        );

        exit;
    }


    /* =====================================================
       VERIFY REQUEST
    ===================================================== */

    $checkStmt = $conn->prepare("
        SELECT
            id,
            status
        FROM report_requests
        WHERE id = ?
        LIMIT 1
    ");


    if (!$checkStmt) {

        $_SESSION['error'] =
            'Unable to verify report request: '
            . $conn->error;

        header(
            'Location: checklist-reviews.php'
        );

        exit;
    }


    $checkStmt->bind_param(
        'i',
        $requestId
    );


    $checkStmt->execute();


    $requestRow =
        $checkStmt
        ->get_result()
        ->fetch_assoc();


    $checkStmt->close();


    if (!$requestRow) {

        $_SESSION['error'] =
            'Report approval request not found.';

        header(
            'Location: checklist-reviews.php'
        );

        exit;
    }


    /* =====================================================
       ONLY ADMIN PENDING REQUEST CAN BE APPROVED
    ===================================================== */

    if (
        $requestRow['status']
        !==
        'admin_pending'
    ) {

        $_SESSION['error'] =
            'This report request is no longer waiting for Admin approval.';

        header(
            'Location: checklist-reviews.php'
        );

        exit;
    }


    /* =====================================================
       APPROVE
    ===================================================== */

    $approveStmt = $conn->prepare("
        UPDATE report_requests

        SET
            status = 'admin_approved'

        WHERE
            id = ?
            AND status = 'admin_pending'

        LIMIT 1
    ");


    if (!$approveStmt) {

        $_SESSION['error'] =
            'Unable to prepare report approval: '
            . $conn->error;

        header(
            'Location: checklist-reviews.php'
        );

        exit;
    }


    $approveStmt->bind_param(
        'i',
        $requestId
    );


    if (
        $approveStmt->execute()
        &&
        $approveStmt->affected_rows > 0
    ) {

        $_SESSION['success'] =
            'Report request approved successfully. Technician can now generate the report.';

    } else {

        $_SESSION['error'] =
            'Unable to approve the report request.';
    }


    $approveStmt->close();


    header(
        'Location: checklist-reviews.php'
    );

    exit;
}


/* =========================================================
   LOAD REPORT APPROVAL REQUESTS
   ---------------------------------------------------------
   Only technician requests waiting for Admin.
========================================================= */

$reportRequests = [];


$reportStmt = $conn->prepare("
    SELECT

        rr.id AS request_id,

        rr.job_id,

        rr.owner_id,

        rr.technician_id,

        rr.status,

        rr.owner_message,

        rr.requested_at,

        rr.report_path,

        owner.name AS owner_name,

        owner.email AS owner_email,

        tech.name AS technician_name,

        tech.email AS technician_email,

        mj.asset_id,

        mj.saf_id,

        a.asset_name,

        a.display_asset_id,

        a.location,

        s.psga_no,

        s.saf_no,

        s.service_name

    FROM report_requests rr

    INNER JOIN maintenance_jobs mj
        ON mj.id = rr.job_id

    LEFT JOIN users owner
        ON owner.id = rr.owner_id

    LEFT JOIN users tech
        ON tech.id = rr.technician_id

    LEFT JOIN assets a
        ON a.id = mj.asset_id

    LEFT JOIN saf_contracts s
        ON s.id = mj.saf_id

    WHERE

        rr.status = 'admin_pending'

    ORDER BY

        rr.requested_at DESC
");


if (!$reportStmt) {

    die(
        'Unable to load report approval requests: '
        . h(
            $conn->error
        )
    );
}


if (
    !$reportStmt->execute()
) {

    die(
        'Unable to execute report approval query: '
        . h(
            $reportStmt->error
        )
    );
}


$reportResult =
    $reportStmt
    ->get_result();


while (
    $row =
    $reportResult->fetch_assoc()
) {

    $reportRequests[] =
        $row;
}


$reportStmt->close();


/* =========================================================
   LOAD CHECKLIST REVIEWS
========================================================= */

$rows = [];


$stmt = $conn->prepare("
    SELECT

        mj.id AS job_id,

        mj.status,
        mj.created_at,
        mj.updated_at,


        mj.certificate_status,
        mj.certificate_no,
        mj.certificate_generated_at,

        s.psga_no,
        s.saf_no,
        s.service_name,

        owner.name AS owner_name,

        tech.name AS technician_name,

        a.asset_name,
        a.display_asset_id,
        a.location

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

        mj.job_type = 'saf_inspection'

    ORDER BY

        mj.created_at DESC
");


if (!$stmt) {

    die(
        'Unable to load checklist reviews: '
        . h(
            $conn->error
        )
    );
}


if (
    !$stmt->execute()
) {

    die(
        'Unable to execute checklist review query: '
        . h(
            $stmt->error
        )
    );
}


$result =
    $stmt
    ->get_result();


while (
    $row =
    $result->fetch_assoc()
) {

    $rows[] =
        $row;
}


$stmt->close();


/* =========================================================
   HEADER
========================================================= */

include('../includes/header.php');

include('../includes/sidebar-admin.php');

?>


<style>

/* =========================================================
   PAGE
========================================================= */

.checklist-review-page {

    padding:
        24px;

    background:
        #f4f6f9;

    min-height:
        calc(100vh - 70px);
}


/* =========================================================
   CARD
========================================================= */

.review-list-card {

    background:
        #fff;

    border:
        1px solid #e4e7eb;

    border-radius:
        14px;

    box-shadow:
        0 4px 18px rgba(0,0,0,.06);

    overflow:
        hidden;

    margin-bottom:
        20px;
}


/* =========================================================
   HEADER
========================================================= */

.review-list-header {

    padding:
        20px
        22px;

    border-bottom:
        1px solid #e9ecef;
}


.review-list-header h3 {

    margin:
        0;

    font-weight:
        700;

    color:
        #172033;
}


.review-list-header p {

    margin:
        5px
        0
        0;

    color:
        #6c757d;

    font-size:
        14px;
}


/* =========================================================
   REQUEST HEADER
========================================================= */

.report-request-header {

    padding:
        18px
        22px;

    background:
        #fff8e6;

    border-bottom:
        1px solid #f1dfab;
}


.report-request-header h4 {

    margin:
        0;

    color:
        #6b4b00;

    font-weight:
        700;
}


.report-request-header p {

    margin:
        4px
        0
        0;

    color:
        #856404;

    font-size:
        13px;
}


/* =========================================================
   TABLE WRAP
========================================================= */

.review-table-wrap {

    overflow-x:
        auto;
}


/* =========================================================
   TABLE
========================================================= */

.review-table {

    width:
        100%;

    border-collapse:
        collapse;

    min-width:
        1100px;
}


.review-table th {

    background:
        #f6f8fb;

    color:
        #26364d;

    padding:
        13px
        12px;

    border-bottom:
        1px solid #dee2e6;

    font-size:
        13px;

    font-weight:
        700;

    white-space:
        nowrap;
}


.review-table td {

    padding:
        13px
        12px;

    border-bottom:
        1px solid #edf0f4;

    font-size:
        13px;

    vertical-align:
        middle;
}


.review-table tbody tr:hover {

    background:
        #f9fbfd;
}


/* =========================================================
   ASSET
========================================================= */

.asset-name {

    font-weight:
        700;

    color:
        #172033;
}


.meta {

    color:
        #6c757d;

    font-size:
        12px;
}


/* =========================================================
   BADGES
========================================================= */

.status-badge {

    display:
        inline-block;

    padding:
        6px
        10px;

    border-radius:
        16px;

    font-size:
        11px;

    font-weight:
        700;

    white-space:
        nowrap;
}


.status-pending {

    background:
        #fff1c2;

    color:
        #7a5200;
}


.status-ready {

    background:
        #d9f4e5;

    color:
        #0c6b35;
}


.status-generated {

    background:
        #e7f1ff;

    color:
        #0d6efd;
}


/* =========================================================
   ACTION
========================================================= */

.action-btn {

    border-radius:
        7px;

    font-weight:
        600;

    white-space:
        nowrap;
}


.report-request-header .action-btn {

    min-width:
        78px;
}


/* =========================================================
   EMPTY
========================================================= */

.empty-state {

    padding:
        50px
        20px;

    text-align:
        center;

    color:
        #6c757d;
}


.empty-state i {

    font-size:
        42px;

    display:
        block;

    margin-bottom:
        12px;

    color:
        #198754;
}


/* =========================================================
   REQUEST MESSAGE
========================================================= */

.request-message {

    max-width:
        280px;

    color:
        #495057;

    white-space:
        normal;
}


/* =========================================================
   COUNT
========================================================= */

.pending-count {

    display:
        inline-flex;

    align-items:
        center;

    justify-content:
        center;

    min-width:
        25px;

    height:
        25px;

    padding:
        0
        7px;

    margin-left:
        8px;

    border-radius:
        20px;

    background:
        #dc3545;

    color:
        #fff;

    font-size:
        12px;

    font-weight:
        700;
}


@media(max-width: 900px) {

    .checklist-review-page {

        padding:
            14px;
    }

}

</style>


<div class="content">

<div class="checklist-review-page">


    <!-- =====================================================
         ALERTS
    ====================================================== -->

    <?php if (
        !empty(
            $_SESSION['success']
        )
    ): ?>


        <div
            class="
                alert
                alert-success
                alert-dismissible
                fade
                show
            "
        >

            <i
                class="
                    bi
                    bi-check-circle
                    me-2
                "
            ></i>

            <?= h(
                $_SESSION['success']
            ) ?>


            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>


        <?php

        unset(
            $_SESSION['success']
        );

        ?>


    <?php endif; ?>


    <?php if (
        !empty(
            $_SESSION['error']
        )
    ): ?>


        <div
            class="
                alert
                alert-danger
                alert-dismissible
                fade
                show
            "
        >

            <i
                class="
                    bi
                    bi-exclamation-triangle
                    me-2
                "
            ></i>

            <?= h(
                $_SESSION['error']
            ) ?>


            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>


        <?php

        unset(
            $_SESSION['error']
        );

        ?>


    <?php endif; ?>


    <!-- =====================================================
         REPORT APPROVAL REQUESTS
    ====================================================== -->

    <div
        class="review-list-card"
    >


        <div
            class="report-request-header"
        >


            <h4>

                <i
                    class="
                        bi
                        bi-file-earmark-check
                        me-2
                    "
                ></i>

                Report Approval Requests


                <?php if (
                    count(
                        $reportRequests
                    ) > 0
                ): ?>

                    <span
                        class="pending-count"
                    >

                        <?= count(
                            $reportRequests
                        ) ?>

                    </span>

                <?php endif; ?>


            </h4>


            <p>

                Technician report requests waiting
                for Admin approval before report generation.

            </p>


        </div>


        <div
            class="review-table-wrap"
        >


            <?php if (
                empty(
                    $reportRequests
                )
            ): ?>


                <div
                    class="empty-state"
                >


                    <i
                        class="
                            bi
                            bi-check-circle
                        "
                    ></i>


                    <strong>

                        No Report Approval Requests

                    </strong>


                    <div
                        class="mt-1"
                    >

                        There are currently no technician
                        report requests waiting for approval.

                    </div>


                </div>


            <?php else: ?>


                <table
                    class="review-table"
                >


                    <thead>

                    <tr>

                        <th>
                            Lift
                        </th>

                        <th>
                            Owner
                        </th>

                        <th>
                            Technician
                        </th>

                        <th>
                            PSGA
                        </th>

                        <th>
                            SAF
                        </th>

                        <th>
                            Requested
                        </th>

                        <th>
                            Message
                        </th>

                        <th>
                            Status
                        </th>

                        <th>
                            Action
                        </th>

                    </tr>

                    </thead>


                    <tbody>


                    <?php foreach (
                        $reportRequests
                        as $request
                    ): ?>


                        <tr>


                            <!-- =================================
                                 LIFT
                            ================================== -->

                            <td>


                                <div
                                    class="asset-name"
                                >

                                    <?= h(
                                        $request[
                                            'display_asset_id'
                                        ]
                                        ?:
                                        $request[
                                            'asset_name'
                                        ]
                                        ?:
                                        '—'
                                    ) ?>

                                </div>


                                <?php if (
                                    !empty(
                                        $request[
                                            'location'
                                        ]
                                    )
                                ): ?>


                                    <div
                                        class="meta"
                                    >

                                        <?= h(
                                            $request[
                                                'location'
                                            ]
                                        ) ?>

                                    </div>


                                <?php endif; ?>


                            </td>


                            <!-- =================================
                                 OWNER
                            ================================== -->

                            <td>

                                <?= h(
                                    $request[
                                        'owner_name'
                                    ]
                                    ?:
                                    '—'
                                ) ?>

                            </td>


                            <!-- =================================
                                 TECHNICIAN
                            ================================== -->

                            <td>

                                <?= h(
                                    $request[
                                        'technician_name'
                                    ]
                                    ?:
                                    '—'
                                ) ?>

                            </td>


                            <!-- =================================
                                 PSGA
                            ================================== -->

                            <td>

                                <?= h(
                                    $request[
                                        'psga_no'
                                    ]
                                    ?:
                                    '—'
                                ) ?>

                            </td>


                            <!-- =================================
                                 SAF
                            ================================== -->

                            <td>

                                <?= h(
                                    $request[
                                        'saf_no'
                                    ]
                                    ?:
                                    '—'
                                ) ?>

                            </td>


                            <!-- =================================
                                 REQUESTED
                            ================================== -->

                            <td>

                                <?= h(
                                    formatIST(
                                        $request[
                                            'requested_at'
                                        ]
                                        ??
                                        null
                                    )
                                ) ?>

                            </td>


                            <!-- =================================
                                 MESSAGE
                            ================================== -->

                            <td>


                                <?php if (
                                    !empty(
                                        trim(
                                            (string)(
                                                $request[
                                                    'owner_message'
                                                ]
                                                ??
                                                ''
                                            )
                                        )
                                    )
                                ): ?>


                                    <div
                                        class="request-message"
                                    >

                                        <?= nl2br(
                                            h(
                                                $request[
                                                    'owner_message'
                                                ]
                                            )
                                        ) ?>

                                    </div>


                                <?php else: ?>


                                    <span
                                        class="meta"
                                    >

                                        No message

                                    </span>


                                <?php endif; ?>


                            </td>


                            <!-- =================================
                                 STATUS
                            ================================== -->

                            <td>


                                <span
                                    class="
                                        status-badge
                                        status-pending
                                    "
                                >

                                    <i
                                        class="
                                            bi
                                            bi-hourglass-split
                                            me-1
                                        "
                                    ></i>

                                    Waiting Approval

                                </span>


                            </td>


                            <!-- =================================
                                 ACTION
                            ================================== -->

                            <td>

                                <div
                                    class="d-flex flex-wrap gap-2"
                                >

                                    <!-- =================================
                                         VIEW / CHECK BEFORE APPROVAL
                                         ---------------------------------
                                         Report is normally generated only
                                         AFTER Admin approval. Therefore,
                                         this View opens the complete
                                         checklist/report basis first.
                                    ================================== -->

                                    <a
                                        href="
                                            checklist-review.php?job_id=<?= (int)$request['job_id'] ?>
                                        "
                                        class="
                                            btn
                                            btn-primary
                                            btn-sm
                                            action-btn
                                        "
                                        target="_blank"
                                        rel="noopener"
                                    >

                                        <i
                                            class="
                                                bi
                                                bi-eye
                                                me-1
                                            "
                                        ></i>

                                        View

                                    </a>


                                    <?php
                                    $approvalReportPath =
                                        trim(
                                            (string)(
                                                $request['report_path']
                                                ??
                                                ''
                                            )
                                        );
                                    ?>

                                    <?php if ($approvalReportPath !== ''): ?>

                                        <a
                                            href="
                                                view-report.php?request_id=<?= (int)$request['request_id'] ?>
                                            "
                                            class="
                                                btn
                                                btn-outline-primary
                                                btn-sm
                                                action-btn
                                            "
                                            target="_blank"
                                            rel="noopener"
                                        >

                                            <i
                                                class="
                                                    bi
                                                    bi-file-earmark-pdf
                                                    me-1
                                                "
                                            ></i>

                                            View Report

                                        </a>

                                    <?php endif; ?>


                                    <form
                                        method="POST"
                                        class="m-0"
                                        onsubmit="
                                            return confirm(
                                                'Approve this report request? The technician will be allowed to generate the report.'
                                            );
                                        "
                                    >

                                        <input
                                            type="hidden"
                                            name="request_id"
                                            value="<?= (int)$request['request_id'] ?>"
                                        >

                                        <button
                                            type="submit"
                                            name="approve_report_request"
                                            class="
                                                btn
                                                btn-success
                                                btn-sm
                                                action-btn
                                            "
                                        >

                                            <i
                                                class="
                                                    bi
                                                    bi-check-circle
                                                    me-1
                                                "
                                            ></i>

                                            Approve Report

                                        </button>

                                    </form>

                                </div>

                            </td>


                        </tr>


                    <?php endforeach; ?>


                    </tbody>


                </table>


            <?php endif; ?>


        </div>


    </div>


    <!-- =====================================================
         CHECKLIST REVIEWS
    ====================================================== -->

    <div
        class="review-list-card"
    >


        <div
            class="review-list-header"
        >


            <h3>

                <i
                    class="
                        bi
                        bi-clipboard2-check
                        me-2
                    "
                ></i>

                Checklist Reviews

            </h3>


            <p>

                All submitted SAF / PAT inspection
                checklists are available for Admin review.

            </p>


        </div>


        <div
            class="review-table-wrap"
        >


            <?php if (
                empty(
                    $rows
                )
            ): ?>


                <div
                    class="empty-state"
                >


                    <i
                        class="
                            bi
                            bi-check-circle
                        "
                    ></i>


                    <strong>

                        No Checklist Reviews Available

                    </strong>


                    <div
                        class="mt-1"
                    >

                        There are currently no SAF
                        inspection checklists available to view.

                    </div>


                </div>


            <?php else: ?>


                <table
                    class="review-table"
                >


                    <thead>

                    <tr>

                        <th>
                            Lift
                        </th>

                        <th>
                            Owner
                        </th>

                        <th>
                            Technician
                        </th>

                        <th>
                            PSGA
                        </th>

                        <th>
                            SAF
                        </th>

                        <th>
                            Inspection Date
                        </th>


                        <th>
                            Status
                        </th>

                        <th>
                            Certificate
                        </th>

                        <th>
                            Action
                        </th>

                    </tr>


                    </thead>


                    <tbody>


                    <?php foreach (
                        $rows
                        as $row
                    ): ?>


                        <?php

                        /* =================================
                           INSPECTION DATE
                        ================================= */

                        $inspectionDate =
                            '—';


                        if (
                            !empty(
                                $row[
                                    'created_at'
                                ]
                            )
                        ) {

                            try {

                                $inspectionDateObj =
                                    new DateTime(
                                        $row[
                                            'created_at'
                                        ],
                                        new DateTimeZone(
                                            'UTC'
                                        )
                                    );


                                $inspectionDateObj
                                    ->setTimezone(
                                        new DateTimeZone(
                                            'Asia/Kolkata'
                                        )
                                    );


                                $inspectionDate =
                                    $inspectionDateObj
                                    ->format(
                                        'd / m / Y'
                                    );

                            } catch (
                                Throwable $e
                            ) {

                                $inspectionDate =
                                    '—';
                            }
                        }


                        /* =================================
                           CERTIFICATE STATUS
                        ================================= */

                        $certificateStatus =
                            strtolower(
                                trim(
                                    (string)(
                                        $row[
                                            'certificate_status'
                                        ]
                                        ??
                                        ''
                                    )
                                )
                            );

                        ?>


                        <tr>


                            <!-- =================================
                                 LIFT
                            ================================== -->

                            <td>


                                <div
                                    class="asset-name"
                                >

                                    <?= h(
                                        $row[
                                            'display_asset_id'
                                        ]
                                        ?:
                                        $row[
                                            'asset_name'
                                        ]
                                        ?:
                                        '—'
                                    ) ?>

                                </div>


                                <?php if (
                                    !empty(
                                        $row[
                                            'location'
                                        ]
                                    )
                                ): ?>


                                    <div
                                        class="meta"
                                    >

                                        <?= h(
                                            $row[
                                                'location'
                                            ]
                                        ) ?>

                                    </div>


                                <?php endif; ?>


                            </td>


                            <!-- =================================
                                 OWNER
                            ================================== -->

                            <td>

                                <?= h(
                                    $row[
                                        'owner_name'
                                    ]
                                    ?:
                                    '—'
                                ) ?>

                            </td>


                            <!-- =================================
                                 TECHNICIAN
                            ================================== -->

                            <td>

                                <?= h(
                                    $row[
                                        'technician_name'
                                    ]
                                    ?:
                                    '—'
                                ) ?>

                            </td>


                            <!-- =================================
                                 PSGA
                            ================================== -->

                            <td>

                                <?= h(
                                    $row[
                                        'psga_no'
                                    ]
                                    ?:
                                    '—'
                                ) ?>

                            </td>


                            <!-- =================================
                                 SAF
                            ================================== -->

                            <td>

                                <?= h(
                                    $row[
                                        'saf_no'
                                    ]
                                    ?:
                                    '—'
                                ) ?>

                            </td>


                            <!-- =================================
                                 INSPECTION DATE
                            ================================== -->

                            <td>

                                <?= h(
                                    $inspectionDate
                                ) ?>

                            </td>


                            <!-- =================================
                                 STATUS
                            ================================== -->

                            <td>


                                <span
                                    class="
                                        status-badge
                                        status-ready
                                    "
                                >

                                    Ready to View

                                </span>


                            </td>


                            <!-- =================================
                                 CERTIFICATE
                            ================================== -->

                            <td>


                                <?php if (
                                    $certificateStatus
                                    ===
                                    'generated'
                                ): ?>


                                    <span
                                        class="
                                            status-badge
                                            status-generated
                                        "
                                    >

                                        Generated

                                    </span>


                                    <?php if (
                                        !empty(
                                            $row[
                                                'certificate_no'
                                            ]
                                        )
                                    ): ?>


                                        <div
                                            class="meta mt-1"
                                        >

                                            <?= h(
                                                $row[
                                                    'certificate_no'
                                                ]
                                            ) ?>

                                        </div>


                                    <?php endif; ?>


                                <?php else: ?>


                                    <span
                                        class="meta"
                                    >

                                        Not Generated

                                    </span>


                                <?php endif; ?>


                            </td>


                            <!-- =================================
                                 ACTION
                            ================================== -->

                            <td>


                                <a
                                    href="
                                        checklist-review.php?job_id=<?= (int)$row['job_id'] ?>
                                    "
                                    class="
                                        btn
                                        btn-primary
                                        btn-sm
                                        action-btn
                                    "
                                >

                                    <i
                                        class="
                                            bi
                                            bi-eye
                                            me-1
                                        "
                                    ></i>

                                    View Checklist

                                </a>


                            </td>


                        </tr>


                    <?php endforeach; ?>


                    </tbody>


                </table>


            <?php endif; ?>


        </div>


    </div>


</div>

</div>


<?php

include('../includes/footer.php');

?>