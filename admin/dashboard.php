<?php

include('../config/db.php');
include('../config/auth.php');

require_role('admin');

include('../includes/header.php');
include('../includes/sidebar-admin.php');


/* =========================================================
   BASIC COUNTS
========================================================= */

$assets = 0;

$result = $conn->query("
    SELECT COUNT(*) AS t
    FROM assets
");

if ($result) {
    $assets = (int)$result->fetch_assoc()['t'];
}


$totalCallbacks = 0;

$result = $conn->query("
    SELECT COUNT(*) AS t
    FROM callbacks
");

if ($result) {
    $totalCallbacks = (int)$result->fetch_assoc()['t'];
}


$inProgressCallbacks = 0;

$result = $conn->query("
    SELECT COUNT(*) AS t
    FROM callbacks
    WHERE status = 'in_progress'
");

if ($result) {
    $inProgressCallbacks =
        (int)$result->fetch_assoc()['t'];
}


$pendingApprovalCallbacks = 0;

$result = $conn->query("
    SELECT COUNT(*) AS t
    FROM callbacks
    WHERE status = 'closed'
");

if ($result) {
    $pendingApprovalCallbacks =
        (int)$result->fetch_assoc()['t'];
}


$approvedCallbacks = 0;

$result = $conn->query("
    SELECT COUNT(*) AS t
    FROM callbacks
    WHERE status = 'approved'
");

if ($result) {
    $approvedCallbacks =
        (int)$result->fetch_assoc()['t'];
}


/* =========================================================
   INSURANCE
========================================================= */

$insuranceExpired = 0;

$result = $conn->query("
    SELECT COUNT(*) AS t
    FROM insurance
    WHERE expiry_date < CURDATE()
");

if ($result) {
    $insuranceExpired =
        (int)$result->fetch_assoc()['t'];
}


$insuranceDue = 0;

$result = $conn->query("
    SELECT COUNT(*) AS t
    FROM insurance
    WHERE expiry_date BETWEEN CURDATE()
    AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
");

if ($result) {
    $insuranceDue =
        (int)$result->fetch_assoc()['t'];
}


$insuranceActive = 0;

$result = $conn->query("
    SELECT COUNT(*) AS t
    FROM insurance
    WHERE expiry_date > DATE_ADD(CURDATE(), INTERVAL 30 DAY)
");

if ($result) {
    $insuranceActive =
        (int)$result->fetch_assoc()['t'];
}


/* =========================================================
   NOC ALERTS
========================================================= */

$nocExpired = 0;

$result = $conn->query("
    SELECT COUNT(*) AS t
    FROM noc_registration
    WHERE noc_expiry_date < CURDATE()
");

if ($result) {
    $nocExpired =
        (int)$result->fetch_assoc()['t'];
}


$nocDue = 0;

$result = $conn->query("
    SELECT COUNT(*) AS t
    FROM noc_registration
    WHERE noc_expiry_date
    BETWEEN CURDATE()
    AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
");

if ($result) {
    $nocDue =
        (int)$result->fetch_assoc()['t'];
}


$nocSafetyDue = 0;

$result = $conn->query("
    SELECT COUNT(*) AS t
    FROM noc_registration
    WHERE next_safety_date <= DATE_ADD(CURDATE(), INTERVAL 15 DAY)
");

if ($result) {
    $nocSafetyDue =
        (int)$result->fetch_assoc()['t'];
}


$nocMockDue = 0;

$result = $conn->query("
    SELECT COUNT(*) AS t
    FROM noc_registration
    WHERE next_mock_drill_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
");

if ($result) {
    $nocMockDue =
        (int)$result->fetch_assoc()['t'];
}


/* =========================================================
   MAINTENANCE OVERDUE
========================================================= */

$maintenanceOverdue = 0;

$result = $conn->query("
    SELECT COUNT(*) AS t
    FROM assets a
    JOIN (
        SELECT
            asset_id,
            MAX(maintenance_date) AS last_date
        FROM maintenance_records
        GROUP BY asset_id
    ) m
        ON m.asset_id = a.id
    WHERE DATE_ADD(
        m.last_date,
        INTERVAL a.maintenance_interval DAY
    ) < CURDATE()
");

if ($result) {
    $maintenanceOverdue =
        (int)$result->fetch_assoc()['t'];
}


$pendingOwner = 0;

$result = $conn->query("
    SELECT COUNT(*) AS t
    FROM maintenance_jobs
    WHERE status = 'pending_owner'
");

if ($result) {
    $pendingOwner =
        (int)$result->fetch_assoc()['t'];
}


$underMaintenance = 0;

$result = $conn->query("
    SELECT COUNT(*) AS t
    FROM assets
    WHERE status = 'Under Maintenance'
");

if ($result) {
    $underMaintenance =
        (int)$result->fetch_assoc()['t'];
}


$outOfService = 0;

$result = $conn->query("
    SELECT COUNT(*) AS t
    FROM assets
    WHERE status = 'Out of Service'
");

if ($result) {
    $outOfService =
        (int)$result->fetch_assoc()['t'];
}


/* =========================================================
   PHASE 4 — AUTOMATIC SERVICE REQUEST STATUS SYNC
   ---------------------------------------------------------
   A Service Request is completed automatically when its latest
   Offer has a fully Paid payment (Balance_Amount <= 0.01).

   The payment module already updates payments.Payment_Status
   to Paid after the final payment is verified. This dashboard
   sync changes the Service Request to Completed and the list
   below also hides Paid requests even if an older database
   cannot store the new status value.
========================================================= */

$serviceRequestCompletionSyncError = '';

$completionSyncSql = "
    UPDATE service_requests sr

    INNER JOIN nleta_offer_master o
        ON o.request_id = sr.id

    INNER JOIN payments p
        ON p.Offer_ID = o.id

    SET
        sr.status = 'Completed'

    WHERE
        sr.status <> 'Completed'

        AND LOWER(
            TRIM(
                COALESCE(
                    p.Payment_Status,
                    ''
                )
            )
        ) = 'paid'

        AND COALESCE(
            p.Balance_Amount,
            0
        ) <= 0.01
";

if (!$conn->query($completionSyncSql)) {

    /*
     * Do not stop the Dashboard if the database status column
     * is from an older version that does not allow Completed.
     * The SELECT logic below still hides fully-paid requests.
     */
    $serviceRequestCompletionSyncError =
        $conn->error;
}


/* =========================================================
   PHASE 4 — SERVICE REQUEST COUNTS
========================================================= */



$serviceRequestTotal = 0;

$serviceRequestSubmitted = 0;

$serviceRequestOffer = 0;

$serviceRequestAccepted = 0;


/* =========================================================
   ACTIVE REQUEST TOTAL
   ---------------------------------------------------------
   Fully paid requests are excluded automatically.
========================================================= */

$result = $conn->query("
    SELECT COUNT(*) AS t
    FROM service_requests sr

    WHERE
        sr.status <> 'Completed'

        AND NOT EXISTS (

            SELECT 1

            FROM nleta_offer_master o

            INNER JOIN payments p
                ON p.Offer_ID = o.id

            WHERE
                o.request_id = sr.id

                AND LOWER(
                    TRIM(
                        COALESCE(
                            p.Payment_Status,
                            ''
                        )
                    )
                ) = 'paid'

                AND COALESCE(
                    p.Balance_Amount,
                    0
                ) <= 0.01
        )
");

if ($result) {
    $serviceRequestTotal =
        (int)$result->fetch_assoc()['t'];
}


/* =========================================================
   SUBMITTED
========================================================= */

$result = $conn->query("
    SELECT COUNT(*) AS t
    FROM service_requests sr

    WHERE
        sr.status = 'Submitted'

        AND NOT EXISTS (

            SELECT 1

            FROM nleta_offer_master o

            INNER JOIN payments p
                ON p.Offer_ID = o.id

            WHERE
                o.request_id = sr.id

                AND LOWER(
                    TRIM(
                        COALESCE(
                            p.Payment_Status,
                            ''
                        )
                    )
                ) = 'paid'

                AND COALESCE(
                    p.Balance_Amount,
                    0
                ) <= 0.01
        )
");

if ($result) {
    $serviceRequestSubmitted =
        (int)$result->fetch_assoc()['t'];
}


/* =========================================================
   OFFER GENERATED
========================================================= */

$result = $conn->query("
    SELECT COUNT(*) AS t
    FROM service_requests sr

    WHERE
        sr.status = 'Offer Generated'

        AND NOT EXISTS (

            SELECT 1

            FROM nleta_offer_master o

            INNER JOIN payments p
                ON p.Offer_ID = o.id

            WHERE
                o.request_id = sr.id

                AND LOWER(
                    TRIM(
                        COALESCE(
                            p.Payment_Status,
                            ''
                        )
                    )
                ) = 'paid'

                AND COALESCE(
                    p.Balance_Amount,
                    0
                ) <= 0.01
        )
");

if ($result) {
    $serviceRequestOffer =
        (int)$result->fetch_assoc()['t'];
}


/* =========================================================
   ACCEPTED
========================================================= */

$result = $conn->query("
    SELECT COUNT(*) AS t
    FROM service_requests sr

    WHERE
        sr.status = 'Accepted'

        AND NOT EXISTS (

            SELECT 1

            FROM nleta_offer_master o

            INNER JOIN payments p
                ON p.Offer_ID = o.id

            WHERE
                o.request_id = sr.id

                AND LOWER(
                    TRIM(
                        COALESCE(
                            p.Payment_Status,
                            ''
                        )
                    )
                ) = 'paid'

                AND COALESCE(
                    p.Balance_Amount,
                    0
                ) <= 0.01
        )
");

if ($result) {
    $serviceRequestAccepted =
        (int)$result->fetch_assoc()['t'];
}


/* =========================================================
   AMC DONUT
========================================================= */

$amc = [
    'ok'         => 0,
    'due'        => 0,
    'dueSoon'    => 0,
    'overdue'    => 0,
    'inProgress' => 0
];


$today = strtotime(
    date('Y-m-d')
);


/* =========================================================
   GET EVERY ASSET
========================================================= */

$q = $conn->query("
    SELECT

        a.id AS asset_id,
        a.asset_name,
        a.maintenance_interval,

        mr.maintenance_date

    FROM assets a

    LEFT JOIN maintenance_records mr
        ON mr.id = (

            SELECT mr2.id

            FROM maintenance_records mr2

            WHERE mr2.asset_id = a.id

            ORDER BY mr2.id DESC

            LIMIT 1
        )

    ORDER BY a.asset_name ASC
");


if ($q) {

    while ($r = $q->fetch_assoc()) {

        $assetId =
            (int)$r['asset_id'];

        $interval =
            (int)$r['maintenance_interval'];


        if ($interval <= 0) {
            $interval = 30;
        }


        $lastMaintenance =
            $r['maintenance_date'] ?? null;


        /* =================================================
           CHECK REAL IN-PROGRESS JOB
        ================================================= */

        $jobCheck = $conn->prepare("
            SELECT id

            FROM maintenance_jobs

            WHERE asset_id = ?

              AND status = 'in_progress'

            ORDER BY id DESC

            LIMIT 1
        ");


        if ($jobCheck) {

            $jobCheck->bind_param(
                "i",
                $assetId
            );

            $jobCheck->execute();

            $jobResult =
                $jobCheck->get_result();

            $inProgress =
                ($jobResult->num_rows > 0);

            $jobCheck->close();

        } else {

            $inProgress = false;

        }


        /* =================================================
           IN PROGRESS
        ================================================= */

        if ($inProgress) {

            $amc['inProgress']++;

            continue;
        }


        /* =================================================
           NO PREVIOUS RECORD = DUE
        ================================================= */

        if (empty($lastMaintenance)) {

            $amc['due']++;

            continue;
        }


        $lastDate =
            strtotime(
                $lastMaintenance
            );


        if (!$lastDate) {

            $amc['due']++;

            continue;
        }


        $nextDueTimestamp =
            strtotime(
                "+{$interval} days",
                $lastDate
            );


        $daysUntilDue =
            floor(
                (
                    $nextDueTimestamp -
                    $today
                ) / 86400
            );


        if ($daysUntilDue < 0) {

            $amc['overdue']++;

        }

        elseif ($daysUntilDue === 0) {

            $amc['due']++;

        }

        elseif ($daysUntilDue <= 5) {

            $amc['dueSoon']++;

        }

        else {

            $amc['ok']++;

        }

    }

}


/* =========================================================
   MONTHLY MAINTENANCE CHART
========================================================= */

$monthMap =
    array_fill(
        1,
        12,
        0
    );


$q = $conn->query("
    SELECT

        MONTH(maintenance_date) AS m,
        COUNT(*) AS c

    FROM maintenance_records

    WHERE YEAR(maintenance_date) =
        YEAR(CURDATE())

    GROUP BY MONTH(maintenance_date)
");


if ($q) {

    while ($r = $q->fetch_assoc()) {

        $monthMap[
            (int)$r['m']
        ] =
            (int)$r['c'];

    }

}


$monthlyMaintenance =
    array_values(
        $monthMap
    );


/* =========================================================
   DAILY CALLBACK CREATED - LAST 30 DAYS
========================================================= */

$days = [];

$data = [];


for ($i = 29; $i >= 0; $i--) {

    $date =
        date(
            'Y-m-d',
            strtotime("-$i days")
        );


    $days[] =
        date(
            'd M',
            strtotime($date)
        );


    $data[$date] = 0;
}


$q = $conn->query("
    SELECT

        DATE(created_at) d,
        COUNT(*) c

    FROM callbacks

    WHERE created_at >= DATE_SUB(
        CURDATE(),
        INTERVAL 30 DAY
    )

    GROUP BY DATE(created_at)
");


if ($q) {

    while ($r = $q->fetch_assoc()) {

        if (
            isset(
                $data[$r['d']]
            )
        ) {

            $data[$r['d']] =
                (int)$r['c'];

        }

    }

}


$dailyCreatedCallbacks =
    array_values(
        $data
    );


/* =========================================================
   ISSUE WISE CREATED
========================================================= */

$issueTotals = [

    'Electrical' => 0,
    'Mechanical' => 0,
    'Door'       => 0,
    'Safety'     => 0,
    'Other'      => 0

];


$q = $conn->query("
    SELECT

        issue,
        COUNT(*) c

    FROM callbacks

    WHERE YEAR(created_at) =
        YEAR(CURDATE())

    GROUP BY issue
");


if ($q) {

    while ($r = $q->fetch_assoc()) {

        if (
            isset(
                $issueTotals[
                    $r['issue']
                ]
            )
        ) {

            $issueTotals[
                $r['issue']
            ] =
                (int)$r['c'];

        }

    }

}


/* =========================================================
   MONTHLY REPORTS
========================================================= */

$currentYear =
    (int)date('Y');


$monthlyReports = [];


$monthNames = [

    1  => 'January',
    2  => 'February',
    3  => 'March',
    4  => 'April',
    5  => 'May',
    6  => 'June',
    7  => 'July',
    8  => 'August',
    9  => 'September',
    10 => 'October',
    11 => 'November',
    12 => 'December'

];


for ($m = 1; $m <= 12; $m++) {

    $monthlyReports[$m] = [

        'maintenance' => 0,
        'callbacks'   => 0,
        'approved'    => 0,
        'pending'     => 0,
        'rejected'    => 0

    ];
}


/* =========================================================
   MONTHLY MAINTENANCE
========================================================= */

$q = $conn->query("
    SELECT

        MONTH(maintenance_date) AS month_no,
        COUNT(*) AS total

    FROM maintenance_records

    WHERE YEAR(maintenance_date) =
        $currentYear

    GROUP BY MONTH(maintenance_date)
");


if ($q) {

    while ($r = $q->fetch_assoc()) {

        $monthNo =
            (int)$r['month_no'];

        if (
            isset(
                $monthlyReports[$monthNo]
            )
        ) {

            $monthlyReports[
                $monthNo
            ]['maintenance'] =
                (int)$r['total'];

        }

    }

}


/* =========================================================
   MONTHLY CALLBACKS CREATED
========================================================= */

$q = $conn->query("
    SELECT

        MONTH(created_at) AS month_no,
        COUNT(*) AS total

    FROM callbacks

    WHERE YEAR(created_at) =
        $currentYear

    GROUP BY MONTH(created_at)
");


if ($q) {

    while ($r = $q->fetch_assoc()) {

        $monthNo =
            (int)$r['month_no'];

        if (
            isset(
                $monthlyReports[$monthNo]
            )
        ) {

            $monthlyReports[
                $monthNo
            ]['callbacks'] =
                (int)$r['total'];

        }

    }

}


/* =========================================================
   MONTHLY CALLBACKS APPROVED
========================================================= */

$q = $conn->query("
    SELECT

        MONTH(approved_at) AS month_no,
        COUNT(*) AS total

    FROM callbacks

    WHERE status = 'approved'

      AND approved_at IS NOT NULL

      AND YEAR(approved_at) =
          $currentYear

    GROUP BY MONTH(approved_at)
");


if ($q) {

    while ($r = $q->fetch_assoc()) {

        $monthNo =
            (int)$r['month_no'];

        if (
            isset(
                $monthlyReports[$monthNo]
            )
        ) {

            $monthlyReports[
                $monthNo
            ]['approved'] =
                (int)$r['total'];

        }

    }

}


/* =========================================================
   MONTHLY PENDING
========================================================= */

$q = $conn->query("
    SELECT

        MONTH(created_at) AS month_no,
        COUNT(*) AS total

    FROM callbacks

    WHERE status = 'closed'

      AND YEAR(created_at) =
          $currentYear

    GROUP BY MONTH(created_at)
");


if ($q) {

    while ($r = $q->fetch_assoc()) {

        $monthNo =
            (int)$r['month_no'];

        if (
            isset(
                $monthlyReports[$monthNo]
            )
        ) {

            $monthlyReports[
                $monthNo
            ]['pending'] =
                (int)$r['total'];

        }

    }

}


/* =========================================================
   MONTHLY REJECTED
========================================================= */

$q = $conn->query("
    SELECT

        MONTH(technician_responded_at)
            AS month_no,

        COUNT(*) AS total

    FROM callbacks

    WHERE technician_response = 'rejected'

      AND technician_responded_at IS NOT NULL

      AND YEAR(technician_responded_at) =
          $currentYear

    GROUP BY MONTH(technician_responded_at)
");


if ($q) {

    while ($r = $q->fetch_assoc()) {

        $monthNo =
            (int)$r['month_no'];

        if (
            isset(
                $monthlyReports[$monthNo]
            )
        ) {

            $monthlyReports[
                $monthNo
            ]['rejected'] =
                (int)$r['total'];

        }

    }

}


/* =========================================================
   FORTNIGHTLY PENDING
========================================================= */

$fortnightlyPending = 0;

$result = $conn->query("
    SELECT COUNT(*) AS t

    FROM callbacks

    WHERE type = 'fortnightly'

      AND status IN (
          'pending',
          'assigned',
          'in_progress'
      )
");

if ($result) {

    $fortnightlyPending =
        (int)$result->fetch_assoc()['t'];
}


/* =========================================================
   CALLBACK SOLVED TODAY
========================================================= */

$callbackSolved = 0;

$result = $conn->query("
    SELECT COUNT(*) AS t

    FROM callbacks

    WHERE status = 'approved'

      AND DATE(approved_at) = CURDATE()
");

if ($result) {

    $callbackSolved =
        (int)$result->fetch_assoc()['t'];
}


/* =========================================================
   OVERALL STATUS
========================================================= */

$allOk = !(
    $insuranceExpired ||
    $insuranceDue ||
    $nocExpired ||
    $nocDue ||
    $nocSafetyDue ||
    $nocMockDue ||
    $maintenanceOverdue ||
    $pendingOwner ||
    $underMaintenance ||
    $outOfService ||
    ($fortnightlyPending > 0)
);


/* =========================================================
   AMC TOTAL
========================================================= */

$amcTotal =
    $amc['ok'] +
    $amc['due'] +
    $amc['dueSoon'] +
    $amc['overdue'] +
    $amc['inProgress'];


/* =========================================================
   PHASE 4 — LATEST SERVICE REQUESTS
========================================================= */

$latestServiceRequests = false;


/*
 * IMPORTANT:
 *
 * We use LEFT JOIN for users because the existing
 * user table structure may contain an owner record
 * even if the display-name field is empty.
 *
 * Owner name is resolved using users.name.
 */

$serviceRequestSql = "

    SELECT

        sr.id,
        sr.request_no,
        sr.owner_id,
        sr.service_id,
        sr.requirements,
        sr.status,
        sr.submitted_at,

        sc.service_code,
        sc.service_name,

        u.name AS owner_name,

        /*
         * Selected Assets(s) is now a NUMBER ONLY.
         * Completed/fully-paid requests are excluded.
         */
        COUNT(
            DISTINCT
            CASE

                WHEN NOT (
                    LOWER(
                        TRIM(
                            COALESCE(
                                p.Payment_Status,
                                ''
                            )
                        )
                    ) = 'paid'

                    AND COALESCE(
                        p.Balance_Amount,
                        0
                    ) <= 0.01
                )

                THEN sra.asset_id

            END
        ) AS lift_count,

        p.Payment_Status,
        p.Balance_Amount,

        o.status AS offer_status

    FROM service_requests sr

    INNER JOIN service_catalog sc
        ON sc.id = sr.service_id

    LEFT JOIN users u
        ON u.id = sr.owner_id

    LEFT JOIN service_request_assets sra
        ON sra.request_id = sr.id

    /*
     * Latest Offer for this Service Request.
     */
    LEFT JOIN (

        SELECT
            o1.*

        FROM nleta_offer_master o1

        INNER JOIN (

            SELECT
                request_id,
                MAX(id) AS max_id

            FROM nleta_offer_master

            GROUP BY request_id

        ) latest_o

            ON latest_o.max_id = o1.id

    ) o

        ON o.request_id = sr.id

    /*
     * Payment belonging to the latest Offer.
     */
    LEFT JOIN payments p
        ON p.Offer_ID = o.id

    /*
     * Completed requests disappear automatically:
     *
     * 1. status already synchronized to Completed, OR
     * 2. latest payment is Paid with zero balance.
     */
    WHERE

        sr.status <> 'Completed'

        AND NOT (

            LOWER(
                TRIM(
                    COALESCE(
                        p.Payment_Status,
                        ''
                    )
                )
            ) = 'paid'

            AND COALESCE(
                p.Balance_Amount,
                0
            ) <= 0.01

        )

    GROUP BY

        sr.id,
        sr.request_no,
        sr.owner_id,
        sr.service_id,
        sr.requirements,
        sr.status,
        sr.submitted_at,

        sc.service_code,
        sc.service_name,

        u.name,

        p.Payment_Status,
        p.Balance_Amount,

        o.status

    ORDER BY
        sr.id DESC

    LIMIT 10

";


$latestServiceRequests =
    $conn->query(
        $serviceRequestSql
    );

?>

<style>

/* =========================================================
   GENERAL
========================================================= */

.card {
    border-radius: 14px;
    height: 100%;
}

.icon-lg {
    font-size: 42px;
}

.icon-sm {
    font-size: 26px;
}

.chart-card {
    padding: 18px !important;
}

.chart-card canvas {
    max-height: 210px !important;
}

.table th {
    background: #f8f9fa;
}

.pagination {
    justify-content: center;
}

.monthly-report-card {
    border-radius: 14px;
    overflow: hidden;
}

.monthly-report-table th {
    background: #212529 !important;
    color: white;
    vertical-align: middle;
}

.monthly-report-table td {
    vertical-align: middle;
}

.month-current {
    background-color: #eaf4ff !important;
    font-weight: 600;
}

.report-number {
    font-weight: 600;
}


/* =========================================================
   CLICKABLE DASHBOARD
========================================================= */

.dashboard-link {
    text-decoration: none;
    color: inherit;
    display: block;
    height: 100%;
}

.dashboard-link:hover {
    color: inherit;
}

.dashboard-link .card {
    cursor: pointer;
    transition:
        transform .15s ease,
        box-shadow .15s ease;
}

.dashboard-link .card:hover {
    transform: translateY(-2px);
    box-shadow:
        0 .5rem 1rem rgba(0,0,0,.15) !important;
}


.chart-link {
    text-decoration: none;
    color: inherit;
    display: block;
    height: 100%;
}

.chart-link:hover {
    color: inherit;
}

.chart-link .card {
    cursor: pointer;
    transition:
        transform .15s ease,
        box-shadow .15s ease;
}

.chart-link .card:hover {
    transform: translateY(-2px);
    box-shadow:
        0 .5rem 1rem rgba(0,0,0,.15) !important;
}


.chart-card {
    padding: 18px !important;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
}

.chart-card h6 {
    text-align: center;
    margin-bottom: 15px !important;
    width: 100%;
}

.chart-card canvas {
    max-height: 210px !important;
    display: block;
}

.all-lifts-card {
    padding-top: 60px !important;
}


/* =========================================================
   PHASE 4 SERVICE REQUESTS
========================================================= */

.service-request-section {
    border-radius: 14px;
    overflow: hidden;
}


.service-request-header {
    background: #0d6efd;
    color: #fff;
}


.service-request-summary {
    border: 1px solid #e9ecef;
    border-radius: 12px;
    padding: 18px;
    height: 100%;
    background: #fff;
}


.service-request-summary-icon {
    font-size: 28px;
    margin-bottom: 6px;
}


.service-request-summary-number {
    font-size: 28px;
    font-weight: 700;
}


.service-request-table th {
    white-space: nowrap;
    background: #f8f9fa;
}


.service-request-table td {
    vertical-align: middle;
}


.service-request-id {
    font-weight: 700;
    color: #0d6efd;
}


.service-code-badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 5px;
    background: #eaf2ff;
    color: #0d6efd;
    font-size: 11px;
    font-weight: 700;
}


.service-request-date {
    white-space: nowrap;
    font-size: 12px;
}


.service-request-empty {
    padding: 50px 20px;
    text-align: center;
    color: #6c757d;
}


.service-request-empty-icon {
    font-size: 48px;
    color: #adb5bd;
}


.phase4-badge {
    font-size: 11px;
}


/* =========================================================
   MOBILE
========================================================= */

@media (max-width: 768px) {

    .service-request-table {
        min-width: 850px;
    }

}

</style>


<div class="content">


<!-- =====================================================
     ROW 1
===================================================== -->

<div class="row g-4 mb-4">


    <!-- ALL LIFTS -->

    <div class="col-md-3">

        <a
            href="assets.php"
            class="dashboard-link"
        >

            <div class="card shadow p-4 text-center all-lifts-card">

                <div class="icon-lg">

                    <?= $allOk
                        ? '✅'
                        : '⚠️' ?>

                </div>

                <h5 class="mt-2">

                    All Lifts

                </h5>

                <strong
                    class="<?= $allOk
                        ? 'text-success'
                        : 'text-danger' ?>"
                >

                    <?= $allOk
                        ? 'All Lifts Active'
                        : 'Attention Required' ?>

                </strong>

            </div>

        </a>

    </div>


    <!-- AMC -->

    <div class="col-md-3">

        <a
            href="maintenance.php"
            class="chart-link"
        >

            <div class="card shadow chart-card">

                <h6 class="text-center mb-2">

                    AMC Status

                </h6>

                <canvas id="amcChart"></canvas>

            </div>

        </a>

    </div>


    <!-- CALLBACK -->

    <div class="col-md-3">

        <a
            href="callbacks.php"
            class="chart-link"
        >

            <div class="card shadow chart-card">

                <h6 class="text-center mb-2">

                    Callback Breakdown

                    <small class="text-muted">

                        (Last 30 Days)

                    </small>

                </h6>

                <canvas id="callbackCreatedChart"></canvas>

            </div>

        </a>

    </div>


    <!-- MONTHLY MAINTENANCE -->

    <div class="col-md-3">

        <a
            href="maintenance.php"
            class="chart-link"
        >

            <div class="card shadow chart-card">

                <h6 class="text-center mb-2">

                    Monthly Maintenance
                    (<?= date('Y') ?>)

                </h6>

                <canvas id="maintenanceChart"></canvas>

            </div>

        </a>

    </div>

</div>


<!-- =====================================================
     ROW 2
===================================================== -->

<div class="row g-4 mb-4">


    <!-- TOTAL ASSETS -->

    <div class="col-md-3">

        <a
            href="assets.php"
            class="dashboard-link"
        >

            <div class="card shadow p-4 text-center">

                <div class="icon-sm">
                    🏢
                </div>

                <h3>
                    <?= $assets ?>
                </h3>

                <small>
                    Total Assets
                </small>

            </div>

        </a>

    </div>


    <!-- TOTAL CALLBACKS -->

    <div class="col-md-3">

        <a
            href="callbacks.php"
            class="dashboard-link"
        >

            <div class="card shadow p-4 text-center">

                <div class="icon-sm">
                    📞
                </div>

                <h3>
                    <?= $totalCallbacks ?>
                </h3>

                <small>
                    Total Callbacks
                </small>

            </div>

        </a>

    </div>


    <!-- APPROVED -->

    <div class="col-md-3">

        <a
            href="callbacks.php?status=approved"
            class="dashboard-link"
        >

            <div class="card shadow p-4 text-center">

                <div class="icon-sm">
                    ✅
                </div>

                <h3>
                    <?= $approvedCallbacks ?>
                </h3>

                <small>
                    Approved / Completed
                </small>

            </div>

        </a>

    </div>


    <!-- FORTNIGHTLY -->

    <div class="col-md-3">

        <a
            href="fortnightly-requests.php"
            class="dashboard-link"
        >

            <div class="card shadow p-4 text-center">

                <div class="icon-sm">
                    🗓️
                </div>

                <h3>
                    <?= $fortnightlyPending ?>
                </h3>

                <small>
                    Fortnightly Pending
                </small>

            </div>

        </a>

    </div>

</div>


<!-- =====================================================
     ROW 3
===================================================== -->

<div class="row g-4 mb-5">


    <!-- UNDER MAINTENANCE -->

    <div class="col-md-4">

        <a
            href="assets.php?status=Under%20Maintenance"
            class="dashboard-link"
        >

            <div class="card shadow p-4 text-center">

                <div class="icon-sm">
                    🛠️
                </div>

                <h3>
                    <?= $underMaintenance ?>
                </h3>

                <small>
                    Under Maintenance
                </small>

            </div>

        </a>

    </div>


    <!-- OUT OF SERVICE -->

    <div class="col-md-4">

        <a
            href="assets.php?status=Out%20of%20Service"
            class="dashboard-link"
        >

            <div class="card shadow p-4 text-center">

                <div class="icon-sm">
                    ⛔
                </div>

                <h3>
                    <?= $outOfService ?>
                </h3>

                <small>
                    Out of Service
                </small>

            </div>

        </a>

    </div>


    <!-- PENDING OWNER -->

    <div class="col-md-4">

        <a
            href="maintenance-jobs.php?status=pending_owner"
            class="dashboard-link"
        >

            <div class="card shadow p-4 text-center">

                <div class="icon-sm">
                    ⏳
                </div>

                <h3>
                    <?= $pendingOwner ?>
                </h3>

                <small>
                    Pending Owner Approvals
                </small>

            </div>

        </a>

    </div>

</div>


<!-- =====================================================
     PHASE 4 — SERVICE REQUESTS
===================================================== -->

<div class="card shadow service-request-section mb-5">


    <!-- =================================================
         HEADER
    ================================================== -->

    <div class="card-header service-request-header p-3">

        <div
            class="d-flex
                   justify-content-between
                   align-items-center
                   flex-wrap
                   gap-2"
        >

            <div>

                <h5 class="mb-1">

                    <i class="bi bi-clipboard-check me-2"></i>

                    Service Requests

                </h5>

                <small>

                    Owner-submitted requests waiting
                    for Admin processing

                    <br>

                    <span
                        class="opacity-75"
                        style="font-size:11px;"
                    >

                        Completed requests are removed automatically
                        after full payment verification.

                    </span>

                </small>

            </div>


            <div
                class="d-flex
                       align-items-center
                       gap-2"
            >

                <?php if (
                    $serviceRequestSubmitted > 0
                ): ?>

                    <span class="badge bg-warning text-dark">

                        <?= $serviceRequestSubmitted ?>

                        New

                    </span>

                <?php endif; ?>


                <a
                    href="service-requests.php"
                    class="btn btn-light btn-sm"
                >

                    <i class="bi bi-list-ul me-1"></i>

                    View All

                </a>

            </div>

        </div>

    </div>


    <!-- =================================================
         BODY
    ================================================== -->

    <div class="card-body">


        <!-- =================================================
             SUMMARY CARDS
        ================================================== -->

        <div class="row g-3 mb-4">


            <!-- TOTAL -->

            <div class="col-md-3">

                <a
                    href="service-requests.php"
                    class="text-decoration-none text-dark"
                >

                    <div class="service-request-summary text-center">

                        <div
                            class="service-request-summary-icon
                                   text-primary"
                        >

                            <i class="bi bi-files"></i>

                        </div>


                        <div
                            class="service-request-summary-number"
                        >

                            <?= $serviceRequestTotal ?>

                        </div>


                        <small class="text-muted">

                            Total Requests

                        </small>

                    </div>

                </a>

            </div>


            <!-- SUBMITTED -->

            <div class="col-md-3">

                <a
                    href="service-requests.php?status=Submitted"
                    class="text-decoration-none text-dark"
                >

                    <div class="service-request-summary text-center">

                        <div
                            class="service-request-summary-icon
                                   text-warning"
                        >

                            <i class="bi bi-hourglass-split"></i>

                        </div>


                        <div
                            class="service-request-summary-number"
                        >

                            <?= $serviceRequestSubmitted ?>

                        </div>


                        <small class="text-muted">

                            Waiting for Admin

                        </small>

                    </div>

                </a>

            </div>


            <!-- OFFER -->

            <div class="col-md-3">

                <a
                    href="service-requests.php?status=Offer%20Generated"
                    class="text-decoration-none text-dark"
                >

                    <div class="service-request-summary text-center">

                        <div
                            class="service-request-summary-icon
                                   text-info"
                        >

                            <i class="bi bi-file-earmark-text"></i>

                        </div>


                        <div
                            class="service-request-summary-number"
                        >

                            <?= $serviceRequestOffer ?>

                        </div>


                        <small class="text-muted">

                            Offer Generated

                        </small>

                    </div>

                </a>

            </div>


            <!-- ACCEPTED -->

            <div class="col-md-3">

                <a
                    href="service-requests.php?status=Accepted"
                    class="text-decoration-none text-dark"
                >

                    <div class="service-request-summary text-center">

                        <div
                            class="service-request-summary-icon
                                   text-success"
                        >

                            <i class="bi bi-check-circle"></i>

                        </div>


                        <div
                            class="service-request-summary-number"
                        >

                            <?= $serviceRequestAccepted ?>

                        </div>


                        <small class="text-muted">

                            Accepted

                        </small>

                    </div>

                </a>

            </div>

        </div>


        <!-- =================================================
             LATEST REQUESTS
        ================================================== -->

        <?php if (
            $latestServiceRequests
            &&
            $latestServiceRequests->num_rows > 0
        ): ?>


            <div class="table-responsive">

                <table
                    class="table
                           table-hover
                           table-bordered
                           service-request-table
                           mb-0"
                >

                    <thead>

                        <tr>

                            <th>
                                Request ID
                            </th>

                            <th>
                                Owner
                            </th>

                            <th>
                                Service
                            </th>

                            <th>
                                Selected Assets(s)
                            </th>

                            <th>
                                Request Date
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


                    <?php while (
                        $request =
                        $latestServiceRequests
                        ->fetch_assoc()
                    ): ?>


                        <tr>


                            <!-- REQUEST ID -->

                            <td>

                                <span
                                    class="service-request-id"
                                >

                                    <?= htmlspecialchars(
                                        $request[
                                            'request_no'
                                        ]
                                    ) ?>

                                </span>

                            </td>


                            <!-- OWNER -->

                            <td>

                                <?= htmlspecialchars(
                                    $request[
                                        'owner_name'
                                    ]
                                    ??
                                    'Unknown Owner'
                                ) ?>

                            </td>


                            <!-- SERVICE -->

                            <td>

                                <span
                                    class="service-code-badge"
                                >

                                    <?= htmlspecialchars(
                                        $request[
                                            'service_code'
                                        ]
                                    ) ?>

                                </span>


                                <div
                                    class="small
                                           text-muted
                                           mt-1"
                                >

                                    <?= htmlspecialchars(
                                        $request[
                                            'service_name'
                                        ]
                                    ) ?>

                                </div>

                            </td>


                            <!-- SELECTED ASSETS(S) -->

                            <td class="text-center">

                                <span
                                    class="badge
                                           bg-secondary
                                           phase4-badge"
                                    style="
                                        min-width:36px;
                                        font-size:13px;
                                    "
                                >

                                    <?= (int)$request[
                                        'lift_count'
                                    ] ?>

                                </span>

                            </td>


                            <!-- DATE -->

                            <td>

                                <?php

                                $requestDate =
                                    '';


                                if (
                                    !empty(
                                        $request[
                                            'submitted_at'
                                        ]
                                    )
                                ) {

                                    try {

                                        $dt =
                                            new DateTime(
                                                $request[
                                                    'submitted_at'
                                                ],
                                                new DateTimeZone(
                                                    'Asia/Kolkata'
                                                )
                                            );


                                        $dt->setTimezone(
                                            new DateTimeZone(
                                                'Asia/Kolkata'
                                            )
                                        );


                                        $requestDate =
                                            $dt->format(
                                                'd-m-Y h:i A'
                                            );

                                    } catch (
                                        Exception $e
                                    ) {

                                        $requestDate =
                                            $request[
                                                'submitted_at'
                                            ];
                                    }

                                }

                                ?>


                                <div
                                    class="service-request-date"
                                >

                                    <?= htmlspecialchars(
                                        $requestDate
                                    ) ?>

                                </div>


                                <small
                                    class="text-muted"
                                >

                                </small>

                            </td>


                            <!-- STATUS -->

                            <td>

                                <?php

                                $status =
                                    trim(
                                        (string)
                                        $request[
                                            'status'
                                        ]
                                    );


                                $statusClass =
                                    'bg-secondary';


                                if (
                                    $status ===
                                    'Submitted'
                                ) {

                                    $statusClass =
                                        'bg-warning text-dark';

                                }

                                elseif (
                                    $status ===
                                    'Offer Generated'
                                ) {

                                    $statusClass =
                                        'bg-info text-dark';

                                }

                                elseif (
                                    $status ===
                                    'Accepted'
                                ) {

                                    $statusClass =
                                        'bg-success';

                                }

                                elseif (
                                    $status ===
                                    'Rejected'
                                ) {

                                    $statusClass =
                                        'bg-danger';

                                }

                                elseif (
                                    $status ===
                                    'Cancelled'
                                ) {

                                    $statusClass =
                                        'bg-secondary';

                                }

                                ?>


                                <span
                                    class="badge
                                           <?= $statusClass ?>"
                                >

                                    <?= htmlspecialchars(
                                        $status
                                    ) ?>

                                </span>

                            </td>


                            <!-- ACTION -->

                            <td>

                                <a
                                    href="service-requests.php?id=<?= (int)$request['id'] ?>"
                                    class="btn
                                           btn-sm
                                           btn-primary"
                                >

                                    <i
                                        class="bi bi-eye me-1"
                                    ></i>

                                    View

                                </a>

                            </td>


                        </tr>


                    <?php endwhile; ?>


                    </tbody>

                </table>

            </div>


        <?php else: ?>


            <div class="service-request-empty">


                <div
                    class="service-request-empty-icon"
                >

                    <i class="bi bi-inbox"></i>

                </div>


                <h5 class="mt-3">

                    No Service Requests

                </h5>


                <p class="mb-0">

                    Owner-submitted service
                    requests will appear here.

                </p>


            </div>


        <?php endif; ?>


    </div>

</div>


<!-- =====================================================
     MONTHLY REPORTS
===================================================== -->

<div class="card shadow monthly-report-card mb-5">


    <div class="card-header bg-dark text-white p-3">


        <div
            class="d-flex
                   justify-content-between
                   align-items-center
                   flex-wrap
                   gap-2"
        >

            <div>

                <h5 class="mb-1">

                    📊 Monthly Reports

                </h5>

                <small class="text-light">

                    Monthly activity summary for
                    <?= $currentYear ?>

                </small>

            </div>


            <div>

                <span class="badge bg-primary">

                    <?= $currentYear ?>

                </span>

            </div>

        </div>

    </div>


    <div class="card-body p-0">


        <div class="table-responsive">


            <table
                class="table
                       table-hover
                       table-bordered
                       mb-0
                       monthly-report-table"
            >

                <thead>

                    <tr>

                        <th>
                            Month
                        </th>

                        <th class="text-center">
                            🛠️ Maintenance Reports
                        </th>

                        <th class="text-center">
                            📞 Callbacks Created
                        </th>

                        <th class="text-center">
                            ✅ Approved
                        </th>

                        <th class="text-center">
                            ⏳ Pending
                        </th>

                        <th class="text-center">
                            ❌ Rejected
                        </th>

                        <th class="text-center">
                            Total Activity
                        </th>

                    </tr>

                </thead>


                <tbody>


                <?php

                $currentMonth =
                    (int)date('n');


                for (
                    $m = 1;
                    $m <= 12;
                    $m++
                ):

                    $report =
                        $monthlyReports[$m];


                    $totalActivity =
                        $report['maintenance']
                        +
                        $report['callbacks'];


                    $rowClass =
                        ($m === $currentMonth)
                        ? 'month-current'
                        : '';

                ?>

                    <tr
                        class="<?= $rowClass ?>"
                    >

                        <td>

                            <strong>

                                <?= $monthNames[$m] ?>

                            </strong>


                            <?php if (
                                $m ===
                                $currentMonth
                            ): ?>

                                <span
                                    class="badge
                                           bg-primary
                                           ms-2"
                                >

                                    Current

                                </span>

                            <?php endif; ?>

                        </td>


                        <td class="text-center">

                            <span
                                class="report-number"
                            >

                                <?= $report[
                                    'maintenance'
                                ] ?>

                            </span>

                        </td>


                        <td class="text-center">

                            <span
                                class="report-number"
                            >

                                <?= $report[
                                    'callbacks'
                                ] ?>

                            </span>

                        </td>


                        <td class="text-center">

                            <?php if (
                                $report[
                                    'approved'
                                ] > 0
                            ): ?>

                                <span
                                    class="badge
                                           bg-success"
                                >

                                    <?= $report[
                                        'approved'
                                    ] ?>

                                </span>

                            <?php else: ?>

                                <span
                                    class="text-muted"
                                >

                                    0

                                </span>

                            <?php endif; ?>

                        </td>


                        <td class="text-center">

                            <?php if (
                                $report[
                                    'pending'
                                ] > 0
                            ): ?>

                                <span
                                    class="badge
                                           bg-warning
                                           text-dark"
                                >

                                    <?= $report[
                                        'pending'
                                    ] ?>

                                </span>

                            <?php else: ?>

                                <span
                                    class="text-muted"
                                >

                                    0

                                </span>

                            <?php endif; ?>

                        </td>


                        <td class="text-center">

                            <?php if (
                                $report[
                                    'rejected'
                                ] > 0
                            ): ?>

                                <span
                                    class="badge
                                           bg-danger"
                                >

                                    <?= $report[
                                        'rejected'
                                    ] ?>

                                </span>

                            <?php else: ?>

                                <span
                                    class="text-muted"
                                >

                                    0

                                </span>

                            <?php endif; ?>

                        </td>


                        <td class="text-center">

                            <strong>

                                <?= $totalActivity ?>

                            </strong>

                        </td>

                    </tr>

                <?php endfor; ?>


                </tbody>


                <tfoot>


                <?php

                $yearMaintenance = 0;
                $yearCallbacks   = 0;
                $yearApproved    = 0;
                $yearPending     = 0;
                $yearRejected    = 0;


                foreach (
                    $monthlyReports
                    as $report
                ) {

                    $yearMaintenance +=
                        $report['maintenance'];

                    $yearCallbacks +=
                        $report['callbacks'];

                    $yearApproved +=
                        $report['approved'];

                    $yearPending +=
                        $report['pending'];

                    $yearRejected +=
                        $report['rejected'];
                }


                $yearActivity =
                    $yearMaintenance
                    +
                    $yearCallbacks;

                ?>


                    <tr class="table-light">


                        <td>

                            <strong>

                                YEAR TOTAL

                            </strong>

                        </td>


                        <td class="text-center">

                            <strong>

                                <?= $yearMaintenance ?>

                            </strong>

                        </td>


                        <td class="text-center">

                            <strong>

                                <?= $yearCallbacks ?>

                            </strong>

                        </td>


                        <td class="text-center">

                            <strong
                                class="text-success"
                            >

                                <?= $yearApproved ?>

                            </strong>

                        </td>


                        <td class="text-center">

                            <strong
                                class="text-warning"
                            >

                                <?= $yearPending ?>

                            </strong>

                        </td>


                        <td class="text-center">

                            <strong
                                class="text-danger"
                            >

                                <?= $yearRejected ?>

                            </strong>

                        </td>


                        <td class="text-center">

                            <strong>

                                <?= $yearActivity ?>

                            </strong>

                        </td>


                    </tr>


                </tfoot>

            </table>

        </div>

    </div>

</div>


</div>


<script>

/* =========================================================
   CHARTS
========================================================= */

document.addEventListener(
    'DOMContentLoaded',
    function () {


        /* =================================================
           AMC DONUT
        ================================================= */

        const amcOk =
            <?= (int)$amc['ok'] ?>;


        const amcDue =
            <?= (int)$amc['due'] ?>;


        const amcDueSoon =
            <?= (int)$amc['dueSoon'] ?>;


        const amcOverdue =
            <?= (int)$amc['overdue'] ?>;


        const amcInProgress =
            <?= (int)$amc['inProgress'] ?>;


        const totalAssets =
            <?= (int)$assets ?>;


        const amcSummaryPlugin = {

            id: 'amcSummary',


            afterDraw(chart) {

                const {
                    ctx,
                    width,
                    height
                } = chart;


                ctx.save();


                ctx.textAlign =
                    'left';


                const centerX =
                    width / 2;


                const startY =
                    height - 75;


                const leftX =
                    centerX - 105;


                const rightX =
                    centerX + 10;


                ctx.font =
                    '11px Arial';


                /* LEFT */

                ctx.fillStyle =
                    '#198754';


                ctx.fillText(
                    `● OK: ${amcOk}`,
                    leftX,
                    startY
                );


                ctx.fillStyle =
                    '#0d6efd';


                ctx.fillText(
                    `● Due: ${amcDue}`,
                    leftX,
                    startY + 18
                );


                ctx.fillStyle =
                    '#fd7e14';


                ctx.fillText(
                    `● Due Soon: ${amcDueSoon}`,
                    leftX,
                    startY + 36
                );


                /* RIGHT */

                ctx.fillStyle =
                    '#dc3545';


                ctx.fillText(
                    `● Overdue: ${amcOverdue}`,
                    rightX,
                    startY
                );


                ctx.fillStyle =
                    '#ffc107';


                ctx.fillText(
                    `● In Progress: ${amcInProgress}`,
                    rightX,
                    startY + 18
                );


                /* TOTAL */

                ctx.textAlign =
                    'center';


                ctx.font =
                    'bold 11px Arial';


                ctx.fillStyle =
                    '#6c757d';


                ctx.fillText(
                    `Total Assets: ${totalAssets}`,
                    centerX,
                    height - 20
                );


                ctx.restore();

            }

        };


        new Chart(

            document.getElementById(
                'amcChart'
            ),

            {

                type: 'doughnut',


                data: {

                    labels: [

                        'AMC OK',
                        'Due',
                        'Due Soon',
                        'Overdue',
                        'In Progress'

                    ],


                    datasets: [{

                        data: [

                            amcOk,
                            amcDue,
                            amcDueSoon,
                            amcOverdue,
                            amcInProgress

                        ],


                        backgroundColor: [

                            '#198754',
                            '#0d6efd',
                            '#fd7e14',
                            '#dc3545',
                            '#ffc107'

                        ],


                        borderWidth: 2

                    }]

                },


                options: {

                    responsive: true,

                    maintainAspectRatio: true,

                    cutout: '65%',


                    layout: {

                        padding: {

                            bottom: 95

                        }

                    },


                    plugins: {

                        legend: {

                            display: false

                        },


                        tooltip: {

                            callbacks: {

                                label:
                                    function(context) {

                                    const value =
                                        context.raw || 0;


                                    const total =
                                        context.dataset
                                        .data
                                        .reduce(
                                            (a, b) =>
                                                a + b,
                                            0
                                        );


                                    const percentage =
                                        total > 0
                                            ? (
                                                (value / total)
                                                * 100
                                            ).toFixed(1)
                                            : 0;


                                    return (
                                        context.label +
                                        ': ' +
                                        value +
                                        ' (' +
                                        percentage +
                                        '%)'
                                    );

                                }

                            }

                        }

                    }

                },


                plugins: [

                    amcSummaryPlugin

                ]

            }

        );


        /* =================================================
           MONTHLY MAINTENANCE
        ================================================= */

        new Chart(

            document.getElementById(
                'maintenanceChart'
            ),

            {

                type: 'bar',


                data: {

                    labels: [

                        'Jan',
                        'Feb',
                        'Mar',
                        'Apr',
                        'May',
                        'Jun',
                        'Jul',
                        'Aug',
                        'Sep',
                        'Oct',
                        'Nov',
                        'Dec'

                    ],


                    datasets: [{

                        data:
                            <?= json_encode(
                                $monthlyMaintenance
                            ) ?>,


                        borderRadius: 6,


                        backgroundColor:
                            '#198754'

                    }]

                },


                options: {

                    plugins: {

                        legend: {

                            display: false

                        }

                    },


                    scales: {

                        y: {

                            beginAtZero: true,

                            ticks: {

                                stepSize: 1

                            }

                        }

                    }

                }

            }

        );


        /* =================================================
           DAILY CALLBACK
        ================================================= */

        new Chart(

            document.getElementById(
                'callbackCreatedChart'
            ),

            {

                type: 'bar',


                data: {

                    labels:
                        <?= json_encode(
                            $days
                        ) ?>,


                    datasets: [{

                        data:
                            <?= json_encode(
                                $dailyCreatedCallbacks
                            ) ?>,


                        backgroundColor:
                            '#0d6efd',


                        borderRadius: 6

                    }]

                },


                options: {

                    plugins: {

                        legend: {

                            display: false

                        }

                    },


                    scales: {

                        y: {

                            beginAtZero: true

                        }

                    }

                }

            }

        );

    }
);

</script>


<?php include('../includes/footer.php'); ?>