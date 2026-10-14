<?php
/* =========================================================
   NLETA INSPECTOR — REPORT REQUESTS
   ---------------------------------------------------------
   Workflow:

   Owner requests report
          ↓
   Inspector sends Admin approval request
          ↓
   Admin approves
          ↓
   Inspector generates report
          ↓
   Report Generated
          ↓
   Inspector can VIEW / REGENERATE
          ↓
   Inspector can SEND REPORT TO OWNER
          ↓
   Owner can VIEW REPORT

   TIMEZONE:
   Asia/Kolkata / IST

   UI:
   - Report table columns automatically size to content.
   - No fixed 1280px table width.
   - Action column wraps buttons naturally.
   - Long owner/status/date values do not create excessive
     empty space.
========================================================= */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

require_role('inspector');

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
   INSPECTOR ID
========================================================= */

$inspectorId = (int)(
    $_SESSION['uid']
    ??
    $_SESSION['user_id']
    ??
    0
);

if ($inspectorId <= 0) {

    header(
        'Location: dashboard.php'
    );

    exit;
}


/* =========================================================
   IST FORMAT
========================================================= */

function formatIST($value): string
{
    if (empty($value)) {
        return '—';
    }

    try {

        $date = new DateTime(
            $value,
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
   SUCCESS / ERROR
========================================================= */

$success =
    $_SESSION['success']
    ??
    '';

$error =
    $_SESSION['error']
    ??
    '';

unset(
    $_SESSION['success'],
    $_SESSION['error']
);


/* =========================================================
   LOAD REPORT REQUESTS
========================================================= */

$rows = [];


$sql = "

    SELECT

        rr.id AS request_id,

        rr.job_id,

        rr.owner_id,

        rr.inspector_id,

        rr.status,

        rr.report_path,

        rr.requested_at,

        rr.sent_at,

        rr.owner_message,

        mj.status AS job_status,

        mj.job_type,


        a.asset_name,

        a.display_asset_id,

        a.location,

        owner.name AS owner_name,

        s.psga_no,

        s.saf_no,

        s.service_code,
        s.service_name,

        EXISTS (
            SELECT 1
            FROM nleta_offer_master o2
            INNER JOIN payment_schedule ps2
                ON ps2.Offer_ID = o2.id
            LEFT JOIN payment_transactions pt2
                ON pt2.Schedule_ID = ps2.Schedule_ID
            WHERE
                TRIM(COALESCE(o2.psga_no, '')) = TRIM(COALESCE(s.psga_no, ''))
                AND ps2.Payment_Stage = 'Final'
                AND (
                    LOWER(TRIM(COALESCE(ps2.Status, ''))) = 'verified'
                    OR LOWER(TRIM(COALESCE(pt2.Status, ''))) = 'verified'
                )
            LIMIT 1
        ) AS final_payment_verified

    FROM report_requests rr

    LEFT JOIN maintenance_jobs mj
        ON mj.id = rr.job_id

    LEFT JOIN assets a
        ON a.id = mj.asset_id

    LEFT JOIN users owner
        ON owner.id = rr.owner_id

    LEFT JOIN saf_contracts s
        ON s.id = mj.saf_id

    WHERE

        rr.inspector_id = ?

        AND EXISTS (

            SELECT 1

            FROM inspector_assignments ia

            WHERE ia.job_id = rr.job_id

              AND ia.inspector_id = rr.inspector_id

        )

    ORDER BY

        rr.requested_at DESC,

        rr.id DESC

";


$stmt =
    $conn->prepare($sql);


if (!$stmt) {

    die(
        'Unable to load report requests: '
        .
        h($conn->error)
    );
}


$stmt->bind_param(
    'i',
    $inspectorId
);


if (!$stmt->execute()) {

    $stmtError =
        $stmt->error;

    $stmt->close();

    die(
        'Unable to load report requests: '
        .
        h($stmtError)
    );
}


$result =
    $stmt->get_result();


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

include('../includes/sidebar-inspector.php');

?>

<style>

/* =========================================================
   PAGE
========================================================= */

.report-page {

    padding:
        28px;

    background:
        #f4f6f9;

    min-height:
        calc(100vh - 70px);
}


/* =========================================================
   CARD
========================================================= */

.report-card {

    width:
        100%;

    max-width:
        100%;

    background:
        #fff;

    border:
        1px solid #e4e7eb;

    border-radius:
        15px;

    box-shadow:
        0 5px 20px
        rgba(0,0,0,.06);

    overflow:
        hidden;
}


/* =========================================================
   HEADER
========================================================= */

.report-header {

    padding:
        24px
        28px;

    border-bottom:
        1px solid #e8ebef;
}


.report-header h2 {

    margin:
        0;

    color:
        #13233f;

    font-weight:
        700;
}


.report-header p {

    margin:
        6px
        0
        0;

    color:
        #68778d;
}


/* =========================================================
   TABLE WRAPPER
   ---------------------------------------------------------
   Horizontal scrolling is used only when the actual
   content cannot physically fit in the available viewport.
========================================================= */

.table-wrap {

    width:
        100%;

    max-width:
        100%;

    overflow-x:
        auto;

    overflow-y:
        visible;

    -webkit-overflow-scrolling:
        touch;
}


/* =========================================================
   AUTO-SIZE TABLE
========================================================= */

.report-table {

    width:
        100%;

    max-width:
        100%;

    border-collapse:
        collapse;

    table-layout:
        auto;

    margin:
        0;

    empty-cells:
        show;
}


/* =========================================================
   HEADER CELLS
========================================================= */

.report-table th {

    background:
        #f6f8fb;

    color:
        #10213d;

    padding:
        14px
        12px;

    font-size:
        13px;

    font-weight:
        700;

    border-bottom:
        1px solid #dee2e6;

    vertical-align:
        middle;

    white-space:
        nowrap;

    width:
        auto;
}


/* =========================================================
   DATA CELLS
========================================================= */

.report-table td {

    padding:
        15px
        12px;

    font-size:
        13px;

    border-bottom:
        1px solid #edf0f4;

    vertical-align:
        middle;

    width:
        auto;

    white-space:
        normal;

    overflow-wrap:
        anywhere;

    word-break:
        normal;
}


.report-table tbody tr:hover {

    background:
        #fafcff;
}


/* =========================================================
   CONTENT-SPECIFIC AUTO SIZING
========================================================= */

.report-table th:nth-child(1),
.report-table td:nth-child(1) {
    min-width: 105px;
}

.report-table th:nth-child(2),
.report-table td:nth-child(2) {
    min-width: 105px;
}

.report-table th:nth-child(3),
.report-table td:nth-child(3),
.report-table th:nth-child(4),
.report-table td:nth-child(4) {
    white-space: nowrap;
    min-width: 75px;
}

.report-table th:nth-child(5),
.report-table td:nth-child(5) {
    min-width: 105px;
}

.report-table th:nth-child(6),
.report-table td:nth-child(6) {
    min-width: 145px;
}

.report-table th:nth-child(7),
.report-table td:nth-child(7) {
    min-width: 150px;
}

.report-table th:nth-child(8),
.report-table td:nth-child(8) {
    min-width: 240px;
}

/* =========================================================
   LIFT
========================================================= */

.asset {

    font-weight:
        700;

    color:
        #172033;

    white-space:
        nowrap;
}


.location {

    font-size:
        12px;

    color:
        #6c757d;

    margin-top:
        3px;

    white-space:
        normal;
}


/* =========================================================
   STATUS
========================================================= */

.status {

    display:
        inline-flex;

    align-items:
        center;

    justify-content:
        center;

    gap:
        5px;

    padding:
        7px
        11px;

    border-radius:
        20px;

    font-size:
        11px;

    font-weight:
        700;

    white-space:
        nowrap;

    max-width:
        100%;
}


.status-requested {

    background:
        #fff1c2;

    color:
        #795500;
}


.status-pending {

    background:
        #fff1c2;

    color:
        #795500;
}


.status-approved {

    background:
        #d9f4e5;

    color:
        #08743d;
}


.status-generated {

    background:
        #dfe8ff;

    color:
        #214ca0;
}


.status-sent {

    background:
        #d9f4e5;

    color:
        #08743d;
}


.status-rejected {

    background:
        #fde2e2;

    color:
        #a40000;
}


/* =========================================================
   ACTION AREA
   ---------------------------------------------------------
   Buttons remain intact but wrap inside the Action cell.
========================================================= */

.report-table td:last-child {

    vertical-align:
        middle;

    white-space:
        normal;

    min-width:
        240px;
}


.action-btn {

    margin:
        2px;

    border-radius:
        7px;

    font-weight:
        600;

    white-space:
        nowrap;

    display:
        inline-flex;

    align-items:
        center;

    justify-content:
        center;

    vertical-align:
        middle;
}


/* =========================================================
   EMPTY
========================================================= */

.empty {

    padding:
        70px
        20px;

    text-align:
        center;

    color:
        #6c757d;
}


.empty i {

    display:
        block;

    font-size:
        45px;

    margin-bottom:
        12px;

    color:
        #198754;
}


/* =========================================================
   ALERT
========================================================= */

.alert {

    border-radius:
        10px;
}


/* =========================================================
   SENT TIME
========================================================= */

.sent-time {

    display:
        block;

    margin-top:
        5px;

    color:
        #6c757d;

    font-size:
        10px;

    white-space:
        normal;

    overflow-wrap:
        anywhere;
}


/* =========================================================
   MEDIUM SCREEN
========================================================= */

@media (max-width: 1400px) {

    .report-page {

        padding:
            20px;
    }

    .report-table th,
    .report-table td {

        padding:
            12px
            9px;
    }

    .report-table th:nth-child(8),
    .report-table td:nth-child(8) {

        min-width:
            210px;
    }
}


/* =========================================================
   SMALL SCREEN
========================================================= */

@media (max-width: 900px) {

    .report-page {

        padding:
            12px;
    }

    .report-header {

        padding:
            18px;
        }

    .report-table {

        min-width:
            950px;
    }

    .report-table th:nth-child(8),
    .report-table td:nth-child(8) {

        min-width:
            200px;
    }
}


/* =========================================================
   VERY SMALL SCREEN
========================================================= */

@media (max-width: 600px) {

    .report-page {

        padding:
            8px;
    }

    .report-card {

        border-radius:
            10px;
    }

    .report-header h2 {

        font-size:
            20px;
    }

    .report-table {

        min-width:
            900px;
    }
}

</style>


<div class="content">

<div class="report-page">


    <!-- =================================================
         SUCCESS MESSAGE
    ================================================== -->

    <?php if ($success !== ''): ?>

        <div
            class="alert alert-success alert-dismissible fade show"
        >

            <i
                class="bi bi-check-circle me-2"
            ></i>

            <?= h($success) ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>


    <!-- =================================================
         ERROR MESSAGE
    ================================================== -->

    <?php if ($error !== ''): ?>

        <div
            class="alert alert-danger alert-dismissible fade show"
        >

            <i
                class="bi bi-exclamation-triangle me-2"
            ></i>

            <?= h($error) ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>


    <!-- =================================================
         REPORT CARD
    ================================================== -->

    <div class="report-card">


        <!-- =================================================
             HEADER
        ================================================== -->

        <div class="report-header">

            <h2>

                <i
                    class="bi bi-file-earmark-pdf me-2"
                ></i>

                Report Requests

            </h2>

            <p>
                Owner report requests assigned to you.
                Send the request for Admin approval, then generate and send the final report to the Owner.
            </p>

        </div>


        <!-- =================================================
             TABLE
        ================================================== -->

        <div class="table-wrap">


        <?php if (empty($rows)): ?>


            <div class="empty">

                <i
                    class="bi bi-file-earmark-check"
                ></i>

                <strong>
                    No Report Requests
                </strong>

                <div class="mt-2">

                    There are currently no report
                    requests assigned to you.

                </div>

            </div>


        <?php else: ?>


        <table class="report-table">


            <thead>

            <tr>

                <th>
                    Lift
                </th>

                <th>
                    Owner
                </th>

                <th>
                    PSGA
                </th>

                <th>
                    SAF
                </th>

                <th>
                    Service
                </th>

                <th>
                    Requested
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


            <?php foreach ($rows as $row): ?>


                <?php

                $status =
                    strtolower(
                        trim(
                            (string)(
                                $row['status']
                                ??
                                ''
                            )
                        )
                    );

                $finalPaymentVerified = !empty(
                    $row['final_payment_verified']
                );


                if (
                    $status ===
                    'requested'
                ) {

                    $statusLabel =
                        'Report Requested';

                    $statusClass =
                        'status-requested';

                } elseif (
                    $status ===
                    'report_requested'
                ) {

                    $statusLabel =
                        'Report Requested';

                    $statusClass =
                        'status-requested';

                } elseif (
                    $status ===
                    'admin_pending'
                ) {

                    $statusLabel =
                        'Waiting for Admin Approval';

                    $statusClass =
                        'status-pending';

                } elseif (
                    $status ===
                    'approval_pending'
                ) {

                    $statusLabel =
                        'Waiting for Admin Approval';

                    $statusClass =
                        'status-pending';

                } elseif (
                    $status ===
                    'admin_approved'
                ) {

                    $statusLabel =
                        'Admin Approved';

                    $statusClass =
                        'status-approved';

                } elseif (
                    $status ===
                    'approved'
                ) {

                    $statusLabel =
                        'Admin Approved';

                    $statusClass =
                        'status-approved';

                } elseif (
                    $status ===
                    'generated'
                ) {

                    $statusLabel =
                        'Report Generated';

                    $statusClass =
                        'status-generated';

                } elseif (
                    $status ===
                    'sent'
                    ||
                    $status ===
                    'sent_to_owner'
                ) {

                    $statusLabel =
                        'Report Sent to Owner';

                    $statusClass =
                        'status-sent';

                } elseif (
                    $status ===
                    'rejected'
                ) {

                    $statusLabel =
                        'Rejected';

                    $statusClass =
                        'status-rejected';

                } else {

                    $statusLabel =
                        $status !== ''
                        ?
                        ucwords(
                            str_replace(
                                '_',
                                ' ',
                                $status
                            )
                        )
                        :
                        'Unknown';

                    $statusClass =
                        'status-pending';
                }

                ?>


                <tr>


                    <!-- =================================================
                         LIFT
                    ================================================== -->

                    <td>

                        <div class="asset">

                            <?= h(
                                $row['display_asset_id']
                                ?:
                                $row['asset_name']
                                ?:
                                '—'
                            ) ?>

                        </div>

                        <div class="location">

                            <?= h(
                                $row['location']
                                ?:
                                '—'
                            ) ?>

                        </div>

                    </td>


                    <!-- =================================================
                         OWNER
                    ================================================== -->

                    <td>

                        <div>
                            <?= h(
                                $row['owner_name']
                                ?:
                                '—'
                            ) ?>
                        </div>

                        <?php if (!empty($row['owner_message'])): ?>

                            <div
                                class="location"
                                title="<?= h($row['owner_message']) ?>"
                            >
                                <i class="bi bi-chat-left-text me-1"></i>
                                <?= h($row['owner_message']) ?>
                            </div>

                        <?php endif; ?>

                    </td>


                    <!-- =================================================
                         PSGA
                    ================================================== -->

                    <td>

                        <?= h(
                            $row['psga_no']
                            ?:
                            '—'
                        ) ?>

                    </td>


                    <!-- =================================================
                         SAF
                    ================================================== -->

                    <td>

                        <?= h(
                            $row['saf_no']
                            ?:
                            '—'
                        ) ?>

                    </td>


                    <!-- =================================================
                         SERVICE
                    ================================================== -->

                    <td>

                        <div style="font-weight:700;color:#214ca0;">
                            <?= h(
                                $row['service_code']
                                ?:
                                '—'
                            ) ?>
                        </div>

                        <?php if (!empty($row['service_name'])): ?>
                            <div class="location">
                                <?= h($row['service_name']) ?>
                            </div>
                        <?php endif; ?>

                    </td>


                    <!-- =================================================
                         REQUESTED
                    ================================================== -->

                    <td>

                        <?= h(
                            formatIST(
                                $row['requested_at']
                                ??
                                null
                            )
                        ) ?>


                        <?php if (
                            !empty(
                                $row['sent_at']
                            )
                        ): ?>

                            <span
                                class="sent-time"
                            >

                                Sent:

                                <?= h(
                                    formatIST(
                                        $row['sent_at']
                                    )
                                ) ?>

                            </span>

                        <?php endif; ?>

                    </td>


                    <!-- =================================================
                         STATUS
                    ================================================== -->

                    <td>

                        <span
                            class="status <?= h($statusClass) ?>"
                        >


                            <?php if (
                                $status ===
                                'generated'
                            ): ?>

                                <i
                                    class="bi bi-file-earmark-pdf"
                                ></i>

                            <?php elseif (
                                $status ===
                                'sent'
                                ||
                                $status ===
                                'sent_to_owner'
                            ): ?>

                                <i
                                    class="bi bi-send-check"
                                ></i>

                            <?php elseif (
                                $status ===
                                'admin_pending'
                                ||
                                $status ===
                                'approval_pending'
                            ): ?>

                                <i
                                    class="bi bi-clock"
                                ></i>

                            <?php elseif (
                                $status ===
                                'admin_approved'
                                ||
                                $status ===
                                'approved'
                            ): ?>

                                <i
                                    class="bi bi-check-circle"
                                ></i>

                            <?php else: ?>

                                <i
                                    class="bi bi-file-earmark"
                                ></i>

                            <?php endif; ?>


                            <?= h(
                                $statusLabel
                            ) ?>


                        </span>

                    </td>


                    <!-- =================================================
                         ACTION
                    ================================================== -->

                    <td>


                        <?php if (!$finalPaymentVerified): ?>

                            <span class="text-warning fw-semibold">

                                <i class="bi bi-lock-fill me-1"></i>

                                Final Payment Not Verified

                            </span>

                            <div class="small text-muted mt-1">
                                Report generation/send remains locked until Admin verifies the Final payment.
                            </div>


                        <?php elseif (
                            $status ===
                            'requested'
                            ||
                            $status ===
                            'report_requested'
                        ): ?>


                            <a
                                href="send-report-approval.php?request_id=<?= (int)$row['request_id'] ?>"
                                class="btn btn-warning btn-sm action-btn"
                            >

                                <i
                                    class="bi bi-send me-1"
                                ></i>

                                Send for Admin Approval

                            </a>


                        <?php elseif (
                            $status ===
                            'admin_pending'
                            ||
                            $status ===
                            'approval_pending'
                        ): ?>


                            <span
                                class="text-muted"
                            >

                                <i
                                    class="bi bi-hourglass-split me-1"
                                ></i>

                                Waiting for Admin

                            </span>


                        <?php elseif (
                            $status ===
                            'admin_approved'
                            ||
                            $status ===
                            'approved'
                        ): ?>


                            <a
                                href="generate-report.php?request_id=<?= (int)$row['request_id'] ?>"
                                class="btn btn-success btn-sm action-btn"
                            >

                                <i
                                    class="bi bi-file-earmark-pdf me-1"
                                ></i>

                                Generate Report

                            </a>


                        <?php elseif (
                            $status ===
                            'generated'
                        ): ?>


                            <a
                                href="view-report.php?request_id=<?= (int)$row['request_id'] ?>"
                                target="_blank"
                                class="btn btn-primary btn-sm action-btn"
                            >

                                <i
                                    class="bi bi-eye me-1"
                                ></i>

                                View Report

                            </a>


                            <a
                                href="generate-report.php?request_id=<?= (int)$row['request_id'] ?>"
                                class="btn btn-warning btn-sm action-btn"
                                onclick="return confirm('Regenerate this report using the latest checklist data? The existing PDF will be replaced.');"
                            >

                                <i
                                    class="bi bi-arrow-clockwise me-1"
                                ></i>

                                Regenerate Report

                            </a>


                            <a
                                href="send-report-owner.php?request_id=<?= (int)$row['request_id'] ?>"
                                class="btn btn-success btn-sm action-btn"
                                onclick="return confirm('Send this generated report to the Owner?');"
                            >

                                <i
                                    class="bi bi-send-check me-1"
                                ></i>

                                Send Report to Owner

                            </a>


                        <?php elseif (
                            $status ===
                            'sent'
                            ||
                            $status ===
                            'sent_to_owner'
                        ): ?>


                            <a
                                href="view-report.php?request_id=<?= (int)$row['request_id'] ?>"
                                target="_blank"
                                class="btn btn-primary btn-sm action-btn"
                            >

                                <i
                                    class="bi bi-eye me-1"
                                ></i>

                                View Report

                            </a>


                            <a
                                href="generate-report.php?request_id=<?= (int)$row['request_id'] ?>"
                                class="btn btn-warning btn-sm action-btn"
                                onclick="return confirm('Regenerate this report using the latest checklist data? The existing PDF will be replaced. The Owner will receive the updated report after you send it again.');"
                            >

                                <i
                                    class="bi bi-arrow-clockwise me-1"
                                ></i>

                                Regenerate Report

                            </a>


                            <a
                                href="send-report-owner.php?request_id=<?= (int)$row['request_id'] ?>"
                                class="btn btn-success btn-sm action-btn"
                                onclick="return confirm('Send the current generated report to the Owner again?');"
                            >

                                <i
                                    class="bi bi-send-check me-1"
                                ></i>

                                Send Again to Owner

                            </a>


                        <?php elseif (
                            $status ===
                            'rejected'
                        ): ?>


                            <span
                                class="text-danger"
                            >

                                Report request rejected.

                            </span>


                        <?php else: ?>


                            <span
                                class="text-muted"
                            >

                                —

                            </span>


                        <?php endif; ?>


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