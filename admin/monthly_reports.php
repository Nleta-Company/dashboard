<?php

include('../config/db.php');
include('../config/auth.php');

require_role('admin');


/* =========================================================
   MONTH / YEAR SELECTION
========================================================= */

$selectedMonth = isset($_GET['month'])
    ? (int)$_GET['month']
    : (int)date('m');

$selectedYear = isset($_GET['year'])
    ? (int)$_GET['year']
    : (int)date('Y');


if ($selectedMonth < 1 || $selectedMonth > 12) {
    $selectedMonth = (int)date('m');
}

if ($selectedYear < 2020 || $selectedYear > 2100) {
    $selectedYear = (int)date('Y');
}


/* =========================================================
   FILTER SELECTION
========================================================= */

$selectedTechnician = isset($_GET['technician_id'])
    ? (int)$_GET['technician_id']
    : 0;

$selectedOwner = isset($_GET['owner_id'])
    ? (int)$_GET['owner_id']
    : 0;

$selectedLocation = isset($_GET['location'])
    ? trim($_GET['location'])
    : '';


/*
 * Separate checklist filters
 *
 * maintenance_checklist:
 * all
 * submitted
 * not_submitted
 *
 * callback_checklist:
 * all
 * submitted
 * not_submitted
 */

$maintenanceChecklistFilter = isset($_GET['maintenance_checklist'])
    ? $_GET['maintenance_checklist']
    : 'all';

$callbackChecklistFilter = isset($_GET['callback_checklist'])
    ? $_GET['callback_checklist']
    : 'all';


$allowedChecklistFilters = [
    'all',
    'submitted',
    'not_submitted'
];


if (!in_array(
    $maintenanceChecklistFilter,
    $allowedChecklistFilters,
    true
)) {
    $maintenanceChecklistFilter = 'all';
}


if (!in_array(
    $callbackChecklistFilter,
    $allowedChecklistFilters,
    true
)) {
    $callbackChecklistFilter = 'all';
}


/* =========================================================
   MONTH START / END
========================================================= */

$monthStart = sprintf(
    '%04d-%02d-01',
    $selectedYear,
    $selectedMonth
);

$monthEnd = date(
    'Y-m-t',
    strtotime($monthStart)
);

$monthName = date(
    'F Y',
    strtotime($monthStart)
);


/* =========================================================
   LOAD TECHNICIANS
========================================================= */

$technicians = [];

$techQuery = $conn->query("
    SELECT id, name
    FROM users
    WHERE role = 'technician'
    ORDER BY name
");

if ($techQuery) {

    while ($row = $techQuery->fetch_assoc()) {

        $technicians[] = $row;

    }
}


/* =========================================================
   LOAD OWNERS
========================================================= */

$owners = [];

$ownerQuery = $conn->query("
    SELECT id, name
    FROM users
    WHERE role = 'owner'
    ORDER BY name
");

if ($ownerQuery) {

    while ($row = $ownerQuery->fetch_assoc()) {

        $owners[] = $row;

    }
}


/* =========================================================
   LOAD LOCATIONS
========================================================= */

$locationData = [];

$locationQuery = $conn->query("
    SELECT
        a.location,
        a.owner_id,
        u.assigned_technician_id

    FROM assets a

    LEFT JOIN users u
        ON u.id = a.owner_id

    WHERE a.location IS NOT NULL
      AND TRIM(a.location) <> ''

    GROUP BY
        a.location,
        a.owner_id,
        u.assigned_technician_id

    ORDER BY a.location
");

if ($locationQuery) {

    while ($row = $locationQuery->fetch_assoc()) {

        $locationData[] = [

            'location' => $row['location'],

            'owner_id' => (int)$row['owner_id'],

            'technician_id' =>
                (int)$row['assigned_technician_id']

        ];

    }
}


/* =========================================================
   BASIC MONTHLY TOTALS
========================================================= */


/* ---------------------------------------------------------
   Maintenance reports
--------------------------------------------------------- */

$totalMaintenance = 0;

$stmt = $conn->prepare("
    SELECT COUNT(*) AS total

    FROM maintenance_records mr

    INNER JOIN assets a
        ON a.id = mr.asset_id

    WHERE mr.maintenance_date BETWEEN ? AND ?
");

$stmt->bind_param(
    "ss",
    $monthStart,
    $monthEnd
);

$stmt->execute();

$totalMaintenance = (int)$stmt
    ->get_result()
    ->fetch_assoc()['total'];

$stmt->close();


/* ---------------------------------------------------------
   Maintenance jobs
--------------------------------------------------------- */

$totalJobs = 0;

$stmt = $conn->prepare("
    SELECT COUNT(*) AS total

    FROM maintenance_jobs mj

    WHERE DATE(mj.created_at) BETWEEN ? AND ?
");

$stmt->bind_param(
    "ss",
    $monthStart,
    $monthEnd
);

$stmt->execute();

$totalJobs = (int)$stmt
    ->get_result()
    ->fetch_assoc()['total'];

$stmt->close();


/* ---------------------------------------------------------
   Callbacks created
--------------------------------------------------------- */

$totalCallbacks = 0;

$stmt = $conn->prepare("
    SELECT COUNT(*) AS total

    FROM callbacks c

    WHERE DATE(c.created_at) BETWEEN ? AND ?
");

$stmt->bind_param(
    "ss",
    $monthStart,
    $monthEnd
);

$stmt->execute();

$totalCallbacks = (int)$stmt
    ->get_result()
    ->fetch_assoc()['total'];

$stmt->close();


/* ---------------------------------------------------------
   Approved callbacks
--------------------------------------------------------- */

$approvedCallbacks = 0;

$stmt = $conn->prepare("
    SELECT COUNT(*) AS total

    FROM callbacks c

    WHERE c.status = 'approved'

      AND DATE(c.approved_at)
          BETWEEN ? AND ?
");

$stmt->bind_param(
    "ss",
    $monthStart,
    $monthEnd
);

$stmt->execute();

$approvedCallbacks = (int)$stmt
    ->get_result()
    ->fetch_assoc()['total'];

$stmt->close();


/* =========================================================
   MAINTENANCE CHECKLIST SUMMARY
========================================================= */

$maintenanceChecklistSubmitted = 0;

$stmt = $conn->prepare("
    SELECT COUNT(*) AS total

    FROM maintenance_jobs mj

    WHERE DATE(mj.created_at)
          BETWEEN ? AND ?

      AND mj.checklist IS NOT NULL

      AND TRIM(mj.checklist) <> ''

      AND mj.checklist <> '[]'
");

$stmt->bind_param(
    "ss",
    $monthStart,
    $monthEnd
);

$stmt->execute();

$maintenanceChecklistSubmitted = (int)$stmt
    ->get_result()
    ->fetch_assoc()['total'];

$stmt->close();


/* =========================================================
   CALLBACK CHECKLIST SUMMARY
========================================================= */

$callbackChecklistSubmitted = 0;

$stmt = $conn->prepare("
    SELECT COUNT(*) AS total

    FROM callbacks c

    WHERE COALESCE(
        DATE(c.work_closed_at),
        DATE(c.created_at)
    ) BETWEEN ? AND ?

      AND c.checklist IS NOT NULL

      AND TRIM(c.checklist) <> ''

      AND c.checklist <> '[]'
");

$stmt->bind_param(
    "ss",
    $monthStart,
    $monthEnd
);

$stmt->execute();

$callbackChecklistSubmitted = (int)$stmt
    ->get_result()
    ->fetch_assoc()['total'];

$stmt->close();


/* =========================================================
   ASSET STATUS REPORT
========================================================= */

$assetStatus = [
    'ok' => 0,
    'due_soon' => 0,
    'due' => 0,
    'overdue' => 0
];


$assetTypeTotals = [
    'Lift' => 0,
    'Escalator' => 0,
    'Travelator' => 0
];


$assetTypeStatus = [

    'Lift' => [
        'ok' => 0,
        'due_soon' => 0,
        'due' => 0,
        'overdue' => 0
    ],

    'Escalator' => [
        'ok' => 0,
        'due_soon' => 0,
        'due' => 0,
        'overdue' => 0
    ],

    'Travelator' => [
        'ok' => 0,
        'due_soon' => 0,
        'due' => 0,
        'overdue' => 0
    ]

];


$assetQuery = $conn->query("
    SELECT

        a.id,
        a.asset_name,
        a.asset_type,
        a.status,
        a.maintenance_interval,

        (
            SELECT MAX(mr.maintenance_date)

            FROM maintenance_records mr

            WHERE mr.asset_id = a.id

        ) AS last_maintenance

    FROM assets a
");


$today = new DateTime(
    'today',
    new DateTimeZone('Asia/Kolkata')
);


while ($asset = $assetQuery->fetch_assoc()) {


    /* Owner filter */

    if ($selectedOwner > 0) {

        if (
            !isset($asset['id'])
        ) {
            continue;
        }

        $ownerCheck = $conn->prepare("
            SELECT owner_id

            FROM assets

            WHERE id = ?
        ");

        $ownerCheck->bind_param(
            "i",
            $asset['id']
        );

        $ownerCheck->execute();

        $ownerRow = $ownerCheck
            ->get_result()
            ->fetch_assoc();

        $ownerCheck->close();

        if (
            !$ownerRow ||
            (int)$ownerRow['owner_id']
                !== $selectedOwner
        ) {
            continue;
        }
    }


    /* Technician filter */

    if ($selectedTechnician > 0) {

        $techCheck = $conn->prepare("
            SELECT u.assigned_technician_id

            FROM assets a

            LEFT JOIN users u
                ON u.id = a.owner_id

            WHERE a.id = ?
        ");

        $techCheck->bind_param(
            "i",
            $asset['id']
        );

        $techCheck->execute();

        $techRow = $techCheck
            ->get_result()
            ->fetch_assoc();

        $techCheck->close();

        if (
            !$techRow ||
            (int)$techRow['assigned_technician_id']
                !== $selectedTechnician
        ) {
            continue;
        }
    }


    /* Location filter */

    if ($selectedLocation !== '') {

        $locationCheck = $conn->prepare("
            SELECT location

            FROM assets

            WHERE id = ?
        ");

        $locationCheck->bind_param(
            "i",
            $asset['id']
        );

        $locationCheck->execute();

        $locationRow = $locationCheck
            ->get_result()
            ->fetch_assoc();

        $locationCheck->close();

        if (
            !$locationRow ||
            trim($locationRow['location'])
                !== $selectedLocation
        ) {
            continue;
        }
    }


    $type = trim(
        (string)$asset['asset_type']
    );


    if (
        strcasecmp($type, 'lift') === 0
    ) {

        $type = 'Lift';

    } elseif (
        strcasecmp($type, 'escalator') === 0
    ) {

        $type = 'Escalator';

    } elseif (
        strcasecmp($type, 'travelator') === 0
    ) {

        $type = 'Travelator';

    } else {

        $type = 'Lift';

    }


    $assetTypeTotals[$type]++;


    $interval = (int)$asset['maintenance_interval'];


    if (
        empty($asset['last_maintenance']) ||
        $interval <= 0
    ) {

        $assetStatus['ok']++;

        $assetTypeStatus[$type]['ok']++;

        continue;
    }


    try {

        $lastMaintenance = new DateTime(
            $asset['last_maintenance'],
            new DateTimeZone('Asia/Kolkata')
        );

        $nextDue = clone $lastMaintenance;

        $nextDue->modify(
            '+' . $interval . ' days'
        );

    } catch (Exception $e) {

        $assetStatus['ok']++;

        $assetTypeStatus[$type]['ok']++;

        continue;
    }


    $daysUntilDue = (int)$today
        ->diff($nextDue)
        ->format('%r%a');


    if ($daysUntilDue < 0) {

        $assetStatus['overdue']++;

        $assetTypeStatus[$type]['overdue']++;

    } elseif ($daysUntilDue === 0) {

        $assetStatus['due']++;

        $assetTypeStatus[$type]['due']++;

    } elseif ($daysUntilDue <= 5) {

        $assetStatus['due_soon']++;

        $assetTypeStatus[$type]['due_soon']++;

    } else {

        $assetStatus['ok']++;

        $assetTypeStatus[$type]['ok']++;

    }
}


/* =========================================================
   TECHNICIAN REPORT
========================================================= */

$technicianReports = [];


$techSql = "

    SELECT

        u.id,
        u.name,

        COUNT(
            CASE
                WHEN DATE(mj.created_at)
                     BETWEEN ? AND ?
                THEN 1
            END
        ) AS total_jobs,

        COUNT(
            CASE
                WHEN DATE(mj.created_at)
                     BETWEEN ? AND ?
                 AND mj.status = 'approved'
                THEN 1
            END
        ) AS approved_jobs,

        COUNT(
            CASE
                WHEN DATE(mj.created_at)
                     BETWEEN ? AND ?
                 AND mj.status = 'pending_owner'
                THEN 1
            END
        ) AS pending_jobs,

        COUNT(
            CASE
                WHEN DATE(mj.created_at)
                     BETWEEN ? AND ?
                 AND mj.status = 'rejected'
                THEN 1
            END
        ) AS rejected_jobs,

        COUNT(
            CASE
                WHEN DATE(mj.created_at)
                     BETWEEN ? AND ?
                 AND mj.checklist IS NOT NULL
                 AND TRIM(mj.checklist) <> ''
                 AND mj.checklist <> '[]'
                THEN 1
            END
        ) AS checklist_submissions

    FROM users u

    LEFT JOIN maintenance_jobs mj
        ON mj.technician_id = u.id

    WHERE u.role = 'technician'
";


if ($selectedTechnician > 0) {

    $techSql .= "
        AND u.id = ?
    ";
}


$techSql .= "
    GROUP BY u.id, u.name
    ORDER BY u.name
";


$stmt = $conn->prepare($techSql);


if ($selectedTechnician > 0) {

    $stmt->bind_param(
        "ssssssssssi",
        $monthStart,
        $monthEnd,
        $monthStart,
        $monthEnd,
        $monthStart,
        $monthEnd,
        $monthStart,
        $monthEnd,
        $monthStart,
        $monthEnd,
        $selectedTechnician
    );

} else {

    $stmt->bind_param(
        "ssssssssss",
        $monthStart,
        $monthEnd,
        $monthStart,
        $monthEnd,
        $monthStart,
        $monthEnd,
        $monthStart,
        $monthEnd,
        $monthStart,
        $monthEnd
    );
}


$stmt->execute();

$result = $stmt->get_result();


while ($row = $result->fetch_assoc()) {


    if ($selectedLocation !== '') {

        $locCheck = $conn->prepare("
            SELECT COUNT(*) AS total

            FROM assets a

            INNER JOIN users o
                ON o.id = a.owner_id

            WHERE o.assigned_technician_id = ?

              AND TRIM(a.location) = ?
        ");

        $locCheck->bind_param(
            "is",
            $row['id'],
            $selectedLocation
        );

        $locCheck->execute();

        $locExists = (int)$locCheck
            ->get_result()
            ->fetch_assoc()['total'];

        $locCheck->close();

        if ($locExists === 0) {
            continue;
        }
    }


    if ($selectedOwner > 0) {

        $ownerCheck = $conn->prepare("
            SELECT COUNT(*) AS total

            FROM assets a

            INNER JOIN users o
                ON o.id = a.owner_id

            WHERE o.id = ?

              AND o.assigned_technician_id = ?
        ");

        $ownerCheck->bind_param(
            "ii",
            $selectedOwner,
            $row['id']
        );

        $ownerCheck->execute();

        $ownerExists = (int)$ownerCheck
            ->get_result()
            ->fetch_assoc()['total'];

        $ownerCheck->close();

        if ($ownerExists === 0) {
            continue;
        }
    }


    $technicianReports[] = $row;
}


$stmt->close();


/* =========================================================
   OWNER REPORT
========================================================= */

$ownerReports = [];


$ownerSql = "

    SELECT

        u.id,
        u.name,

        COUNT(DISTINCT a.id)
            AS total_assets,

        COUNT(
            DISTINCT CASE
                WHEN DATE(c.created_at)
                     BETWEEN ? AND ?
                THEN c.id
            END
        ) AS callbacks,

        COUNT(
            DISTINCT CASE
                WHEN c.status = 'approved'

                 AND DATE(c.approved_at)
                     BETWEEN ? AND ?

                THEN c.id
            END
        ) AS approved_callbacks

    FROM users u

    LEFT JOIN assets a
        ON a.owner_id = u.id

    LEFT JOIN callbacks c
        ON c.asset_id = a.id

    WHERE u.role = 'owner'
";


if ($selectedOwner > 0) {

    $ownerSql .= "
        AND u.id = ?
    ";
}


$ownerSql .= "
    GROUP BY u.id, u.name
    ORDER BY u.name
";


$stmt = $conn->prepare($ownerSql);


if ($selectedOwner > 0) {

    $stmt->bind_param(
        "ssssi",
        $monthStart,
        $monthEnd,
        $monthStart,
        $monthEnd,
        $selectedOwner
    );

} else {

    $stmt->bind_param(
        "ssss",
        $monthStart,
        $monthEnd,
        $monthStart,
        $monthEnd
    );
}


$stmt->execute();

$result = $stmt->get_result();


while ($row = $result->fetch_assoc()) {


    if ($selectedLocation !== '') {

        $locCheck = $conn->prepare("
            SELECT COUNT(*) AS total

            FROM assets

            WHERE owner_id = ?

              AND TRIM(location) = ?
        ");

        $locCheck->bind_param(
            "is",
            $row['id'],
            $selectedLocation
        );

        $locCheck->execute();

        $locExists = (int)$locCheck
            ->get_result()
            ->fetch_assoc()['total'];

        $locCheck->close();

        if ($locExists === 0) {
            continue;
        }
    }


    if ($selectedTechnician > 0) {

        $techCheck = $conn->prepare("
            SELECT COUNT(*) AS total

            FROM assets a

            INNER JOIN users o
                ON o.id = a.owner_id

            WHERE o.id = ?

              AND o.assigned_technician_id = ?
        ");

        $techCheck->bind_param(
            "ii",
            $row['id'],
            $selectedTechnician
        );

        $techCheck->execute();

        $techExists = (int)$techCheck
            ->get_result()
            ->fetch_assoc()['total'];

        $techCheck->close();

        if ($techExists === 0) {
            continue;
        }
    }


    $ownerReports[] = $row;
}


$stmt->close();


/* =========================================================
   MAINTENANCE CHECKLIST REPORTS
========================================================= */

$maintenanceChecklistReports = [];


$maintenanceChecklistSql = "

    SELECT

        mj.id,
        mj.created_at,
        mj.status,
        mj.checklist,
        mj.remarks,
        mj.file,

        a.asset_name,
        a.asset_type,
        a.location,

        owner.id AS owner_id,
        owner.name AS owner_name,

        tech.id AS technician_id,
        tech.name AS technician_name

    FROM maintenance_jobs mj

    INNER JOIN assets a
        ON a.id = mj.asset_id

    LEFT JOIN users owner
        ON owner.id = a.owner_id

    LEFT JOIN users tech
        ON tech.id = mj.technician_id

    WHERE DATE(mj.created_at)
          BETWEEN ? AND ?

      AND mj.checklist IS NOT NULL

      AND TRIM(mj.checklist) <> ''

      AND mj.checklist <> '[]'
";


if ($selectedTechnician > 0) {

    $maintenanceChecklistSql .= "
        AND mj.technician_id = ?
    ";
}


if ($selectedOwner > 0) {

    $maintenanceChecklistSql .= "
        AND a.owner_id = ?
    ";
}


if ($selectedLocation !== '') {

    $maintenanceChecklistSql .= "
        AND TRIM(a.location) = ?
    ";
}


$maintenanceChecklistSql .= "
    ORDER BY mj.created_at DESC
";


$stmt = $conn->prepare(
    $maintenanceChecklistSql
);


if (
    $selectedTechnician > 0 &&
    $selectedOwner > 0 &&
    $selectedLocation !== ''
) {

    $stmt->bind_param(
        "siis",
        $monthStart,
        $monthEnd,
        $selectedTechnician,
        $selectedOwner,
        $selectedLocation
    );

} elseif (
    $selectedTechnician > 0 &&
    $selectedOwner > 0
) {

    $stmt->bind_param(
        "ssii",
        $monthStart,
        $monthEnd,
        $selectedTechnician,
        $selectedOwner
    );

} elseif (
    $selectedTechnician > 0 &&
    $selectedLocation !== ''
) {

    $stmt->bind_param(
        "ssis",
        $monthStart,
        $monthEnd,
        $selectedTechnician,
        $selectedLocation
    );

} elseif (
    $selectedOwner > 0 &&
    $selectedLocation !== ''
) {

    $stmt->bind_param(
        "ssis",
        $monthStart,
        $monthEnd,
        $selectedOwner,
        $selectedLocation
    );

} elseif ($selectedTechnician > 0) {

    $stmt->bind_param(
        "ssi",
        $monthStart,
        $monthEnd,
        $selectedTechnician
    );

} elseif ($selectedOwner > 0) {

    $stmt->bind_param(
        "ssi",
        $monthStart,
        $monthEnd,
        $selectedOwner
    );

} elseif ($selectedLocation !== '') {

    $stmt->bind_param(
        "sss",
        $monthStart,
        $monthEnd,
        $selectedLocation
    );

} else {

    $stmt->bind_param(
        "ss",
        $monthStart,
        $monthEnd
    );
}


$stmt->execute();

$result = $stmt->get_result();


while ($row = $result->fetch_assoc()) {

    $maintenanceChecklistReports[] = $row;

}


$stmt->close();


/* =========================================================
   CALLBACK CHECKLIST REPORTS
========================================================= */

$callbackChecklistReports = [];


$callbackChecklistSql = "

    SELECT

        c.id,
        c.callback_number,
        c.issue,
        c.status,
        c.work_report,
        c.checklist,
        c.work_report_file,
        c.created_at,
        c.work_closed_at,
        c.approved_at,

        COALESCE(
            a.asset_name,
            'General Check'
        ) AS asset_name,

        a.asset_type,
        a.location,

        owner.id AS owner_id,
        owner.name AS owner_name,

        tech.id AS technician_id,
        tech.name AS technician_name

    FROM callbacks c

    LEFT JOIN assets a
        ON a.id = c.asset_id

    LEFT JOIN users owner
        ON owner.id = a.owner_id

    LEFT JOIN users tech
        ON tech.id = c.technician_id

    WHERE COALESCE(
        DATE(c.work_closed_at),
        DATE(c.created_at)
    ) BETWEEN ? AND ?

      AND c.checklist IS NOT NULL

      AND TRIM(c.checklist) <> ''

      AND c.checklist <> '[]'
";


if ($selectedTechnician > 0) {

    $callbackChecklistSql .= "
        AND c.technician_id = ?
    ";
}


if ($selectedOwner > 0) {

    $callbackChecklistSql .= "
        AND a.owner_id = ?
    ";
}


if ($selectedLocation !== '') {

    $callbackChecklistSql .= "
        AND TRIM(a.location) = ?
    ";
}


$callbackChecklistSql .= "
    ORDER BY
        COALESCE(
            c.work_closed_at,
            c.created_at
        ) DESC
";


$stmt = $conn->prepare(
    $callbackChecklistSql
);


if (
    $selectedTechnician > 0 &&
    $selectedOwner > 0 &&
    $selectedLocation !== ''
) {

    $stmt->bind_param(
        "siis",
        $monthStart,
        $monthEnd,
        $selectedTechnician,
        $selectedOwner,
        $selectedLocation
    );

} elseif (
    $selectedTechnician > 0 &&
    $selectedOwner > 0
) {

    $stmt->bind_param(
        "ssii",
        $monthStart,
        $monthEnd,
        $selectedTechnician,
        $selectedOwner
    );

} elseif (
    $selectedTechnician > 0 &&
    $selectedLocation !== ''
) {

    $stmt->bind_param(
        "ssis",
        $monthStart,
        $monthEnd,
        $selectedTechnician,
        $selectedLocation
    );

} elseif (
    $selectedOwner > 0 &&
    $selectedLocation !== ''
) {

    $stmt->bind_param(
        "ssis",
        $monthStart,
        $monthEnd,
        $selectedOwner,
        $selectedLocation
    );

} elseif ($selectedTechnician > 0) {

    $stmt->bind_param(
        "ssi",
        $monthStart,
        $monthEnd,
        $selectedTechnician
    );

} elseif ($selectedOwner > 0) {

    $stmt->bind_param(
        "ssi",
        $monthStart,
        $monthEnd,
        $selectedOwner
    );

} elseif ($selectedLocation !== '') {

    $stmt->bind_param(
        "sss",
        $monthStart,
        $monthEnd,
        $selectedLocation
    );

} else {

    $stmt->bind_param(
        "ss",
        $monthStart,
        $monthEnd
    );
}


$stmt->execute();

$result = $stmt->get_result();


while ($row = $result->fetch_assoc()) {

    $callbackChecklistReports[] = $row;

}


$stmt->close();


/* =========================================================
   APPLY SEPARATE CHECKLIST DROPDOWN FILTERS
========================================================= */

if ($maintenanceChecklistFilter === 'submitted') {

    /*
     * Already submitted records only.
     * No extra filtering required.
     */

} elseif ($maintenanceChecklistFilter === 'not_submitted') {

    /*
     * Show no checklist submissions.
     */

    $maintenanceChecklistReports = [];

}


if ($callbackChecklistFilter === 'submitted') {

    /*
     * Already submitted records only.
     */

} elseif ($callbackChecklistFilter === 'not_submitted') {

    /*
     * Show no checklist submissions.
     */

    $callbackChecklistReports = [];

}


/* =========================================================
   HEADER
========================================================= */

include('../includes/header.php');
include('../includes/sidebar-admin.php');

?>


<style>

.report-card {
    border-radius: 14px;
    height: 100%;
}

.report-number {
    font-size: 32px;
    font-weight: 700;
}

.report-title {
    font-size: 14px;
    color: #6c757d;
}

.section-title {
    font-weight: 600;
    margin-bottom: 15px;
}

.report-table {
    background: #fff;
}

.report-table th {
    background: #212529;
    color: #fff;
    white-space: nowrap;
}

.report-table td {
    vertical-align: middle;
}

.month-selector {
    background: #fff;
    border-radius: 14px;
    padding: 20px;
}

.status-card {
    border-radius: 14px;
    height: 100%;
}

.status-number {
    font-size: 30px;
    font-weight: 700;
}

.filter-card {
    background: #fff;
    border-radius: 14px;
    padding: 20px;
}

.checklist-box {
    max-height: 300px;
    overflow-y: auto;
    min-width: 300px;
}

.checklist-item {
    padding: 6px 8px;
    border-bottom: 1px solid #eee;
    font-size: 13px;
}

.checklist-item:last-child {
    border-bottom: none;
}

.report-details {
    min-width: 260px;
}

/* Callback table */

.callback-table th,
.callback-table td {
    vertical-align: middle;
    padding: 15px 20px;
}

/* # */
.callback-table th:nth-child(1),
.callback-table td:nth-child(1) {
    width: 50px;
    text-align: center;
    white-space: nowrap;
}


/* TECHNICIAN */
.callback-table th:nth-child(3),
.callback-table td:nth-child(3) {
    min-width: 140px;
}

/* OWNER */
.callback-table th:nth-child(4),
.callback-table td:nth-child(4) {
    min-width: 130px;
}

.callback-table th:nth-child(6),
.callback-table td:nth-child(6) {
    width: 1%;
    white-space: nowrap;
    vertical-align: middle;
}

.callback-table td:nth-child(6) strong {
    white-space: nowrap;
}

.callback-table td:nth-child(6) small {
    display: block;
    margin-top: 4px;
}

/* Maintenance table */

.maintenance-table th,
.maintenance-table td {
    vertical-align: middle;
    padding: 15px 20px;
}


/* # */
.maintenance-table th:nth-child(1),
.maintenance-table td:nth-child(1) {
    width: 50px;
    text-align: center;
    white-space: nowrap;
}


/* TECHNICIAN */
.maintenance-table th:nth-child(3),
.maintenance-table td:nth-child(3) {
    min-width: 140px;
}


/* OWNER */
.maintenance-table th:nth-child(4),
.maintenance-table td:nth-child(4) {
    min-width: 130px;
}


/* ASSET */
.maintenance-table th:nth-child(5),
.maintenance-table td:nth-child(5) {
    width: 1%;
    white-space: nowrap;
    vertical-align: middle;
}


.maintenance-table td:nth-child(5) strong {
    white-space: nowrap;
}


.maintenance-table td:nth-child(5) small {
    display: block;
    margin-top: 4px;
}

/* DATE - Callback + Maintenance */
.callback-table th:nth-child(2),
.callback-table td:nth-child(2),
.maintenance-table th:nth-child(2),
.maintenance-table td:nth-child(2) {
    width: 120px;
    min-width: 120px;
    white-space: nowrap;
    line-height: 1.5;
}
</style>


<div class="content">


<!-- =====================================================
     PAGE TITLE
===================================================== -->

<div class="d-flex justify-content-between align-items-center mb-4">

    <div>

        <h3 class="mb-1">
            Monthly Reports
        </h3>

        <p class="text-muted mb-0">
            Complete monthly report for Lift, Escalator and Travelator
        </p>

    </div>

</div>


<!-- =====================================================
     FILTERS
===================================================== -->

<div class="month-selector shadow-sm mb-4">

<form method="GET" class="row g-3 align-items-end">


<!-- MONTH -->

<div class="col-md-3">

<label class="form-label fw-bold">
    Select Month
</label>

<select name="month" class="form-select">

<?php for ($m = 1; $m <= 12; $m++): ?>

<option
    value="<?= $m ?>"
    <?= $m === $selectedMonth ? 'selected' : '' ?>
>

<?= date(
    'F',
    mktime(0, 0, 0, $m, 1)
) ?>

</option>

<?php endfor; ?>

</select>

</div>


<!-- YEAR -->

<div class="col-md-3">

<label class="form-label fw-bold">
    Select Year
</label>

<select name="year" class="form-select">

<?php

$currentYear = (int)date('Y');

for (
    $y = $currentYear - 5;
    $y <= $currentYear + 1;
    $y++
):

?>

<option
    value="<?= $y ?>"
    <?= $y === $selectedYear ? 'selected' : '' ?>
>

<?= $y ?>

</option>

<?php endfor; ?>

</select>

</div>


<!-- TECHNICIAN -->

<div class="col-md-3">

<label class="form-label fw-bold">
    Technician
</label>

<select
    name="technician_id"
    id="technicianFilter"
    class="form-select"
>

<option value="0">
    All Technicians
</option>

<?php foreach ($technicians as $tech): ?>

<option
    value="<?= (int)$tech['id'] ?>"
    <?= $selectedTechnician === (int)$tech['id']
        ? 'selected'
        : '' ?>
>

<?= htmlspecialchars($tech['name']) ?>

</option>

<?php endforeach; ?>

</select>

</div>


<!-- OWNER -->

<div class="col-md-3">

<label class="form-label fw-bold">
    Owner
</label>

<select
    name="owner_id"
    id="ownerFilter"
    class="form-select"
>

<option value="0">
    All Owners
</option>

<?php foreach ($owners as $owner): ?>

<option
    value="<?= (int)$owner['id'] ?>"
    <?= $selectedOwner === (int)$owner['id']
        ? 'selected'
        : '' ?>
>

<?= htmlspecialchars($owner['name']) ?>

</option>

<?php endforeach; ?>

</select>

</div>


<!-- LOCATION -->

<div class="col-md-4">

<label class="form-label fw-bold">
    Location
</label>

<select
    name="location"
    id="locationFilter"
    class="form-select"
>

<option value="">
    All Locations
</option>

</select>

</div>


<!-- =====================================================
     MAINTENANCE CHECKLIST DROPDOWN
===================================================== -->

<div class="col-md-4">

<label class="form-label fw-bold">
    🛠️ Maintenance Checklist
</label>

<select
    name="maintenance_checklist"
    class="form-select"
>

<option
    value="all"
    <?= $maintenanceChecklistFilter === 'all'
        ? 'selected'
        : '' ?>
>
    All Maintenance Checklists
</option>

<option
    value="submitted"
    <?= $maintenanceChecklistFilter === 'submitted'
        ? 'selected'
        : '' ?>
>
    Submitted Checklists Only
</option>

<option
    value="not_submitted"
    <?= $maintenanceChecklistFilter === 'not_submitted'
        ? 'selected'
        : '' ?>
>
    No Checklist Submitted
</option>

</select>


</div>


<!-- =====================================================
     CALLBACK CHECKLIST DROPDOWN
===================================================== -->

<div class="col-md-4">

<label class="form-label fw-bold">
    📞 Callback Checklist
</label>

<select
    name="callback_checklist"
    class="form-select"
>

<option
    value="all"
    <?= $callbackChecklistFilter === 'all'
        ? 'selected'
        : '' ?>
>
    All Callback Checklists
</option>

<option
    value="submitted"
    <?= $callbackChecklistFilter === 'submitted'
        ? 'selected'
        : '' ?>
>
    Submitted Checklists Only
</option>

<option
    value="not_submitted"
    <?= $callbackChecklistFilter === 'not_submitted'
        ? 'selected'
        : '' ?>
>
    No Checklist Submitted
</option>

</select>

</div>


<!-- BUTTON -->

<div class="col-md-12">

<button
    type="submit"
    class="btn btn-primary w-100"
>

📊 Generate Monthly Report

</button>

</div>


</form>

</div>


<!-- =====================================================
     REPORT PERIOD
===================================================== -->

<div class="alert alert-info shadow-sm">

📅

<strong>
    Report Period:
</strong>

<?= htmlspecialchars($monthName) ?>


<?php if ($selectedTechnician > 0): ?>

<br>

<strong>
    Technician:
</strong>

<?php

foreach ($technicians as $tech) {

    if (
        (int)$tech['id']
        === $selectedTechnician
    ) {

        echo htmlspecialchars(
            $tech['name']
        );

        break;
    }

}

?>

<?php endif; ?>


<?php if ($selectedOwner > 0): ?>

<br>

<strong>
    Owner:
</strong>

<?php

foreach ($owners as $owner) {

    if (
        (int)$owner['id']
        === $selectedOwner
    ) {

        echo htmlspecialchars(
            $owner['name']
        );

        break;
    }

}

?>

<?php endif; ?>


<?php if ($selectedLocation !== ''): ?>

<br>

<strong>
    Location:
</strong>

<?= htmlspecialchars($selectedLocation) ?>

<?php endif; ?>

</div>


<!-- =====================================================
     MONTHLY SUMMARY
===================================================== -->

<div class="row g-4 mb-5">


<div class="col-md-3">

<div class="card report-card shadow p-4 text-center">

<div class="fs-2">
    🛠️
</div>

<div class="report-number">
    <?= $totalMaintenance ?>
</div>

<div class="report-title">
    Maintenance Reports
</div>

</div>

</div>


<div class="col-md-3">

<div class="card report-card shadow p-4 text-center">

<div class="fs-2">
    📋
</div>

<div class="report-number">
    <?= $totalJobs ?>
</div>

<div class="report-title">
    Maintenance Jobs
</div>

</div>

</div>


<div class="col-md-3">

<div class="card report-card shadow p-4 text-center">

<div class="fs-2">
    📞
</div>

<div class="report-number">
    <?= $totalCallbacks ?>
</div>

<div class="report-title">
    Callbacks Created
</div>

</div>

</div>


<div class="col-md-3">

<div class="card report-card shadow p-4 text-center">

<div class="fs-2">
    ✅
</div>

<div class="report-number">
    <?= $approvedCallbacks ?>
</div>

<div class="report-title">
    Approved Callbacks
</div>

</div>

</div>


</div>


<!-- =====================================================
     CHECKLIST SUMMARY
===================================================== -->

<div class="row g-4 mb-5">


<div class="col-md-6">

<div class="card shadow p-4 text-center">

<div class="fs-2">
    🛠️
</div>

<div class="report-number">
    <?= $maintenanceChecklistSubmitted ?>
</div>

<div class="fw-bold">
    Maintenance Checklists Submitted
</div>

<small class="text-muted">
    Technician maintenance checklist submissions
</small>

</div>

</div>


<div class="col-md-6">

<div class="card shadow p-4 text-center">

<div class="fs-2">
    📞
</div>

<div class="report-number">
    <?= $callbackChecklistSubmitted ?>
</div>

<div class="fw-bold">
    Callback Checklists Submitted
</div>

<small class="text-muted">
    Technician callback checklist submissions
</small>

</div>

</div>


</div>


<!-- =====================================================
     MAINTENANCE STATUS
===================================================== -->

<div class="mb-5">

<h5 class="section-title">
    🛠️ Maintenance Status
</h5>


<div class="row g-4">


<div class="col-md-3">

<div class="card status-card shadow p-4 text-center">

<div class="fs-2">
    🟢
</div>

<div class="status-number text-success">
    <?= $assetStatus['ok'] ?>
</div>

<div class="fw-bold">
    OK
</div>

<small class="text-muted">
    More than 5 days remaining
</small>

</div>

</div>


<div class="col-md-3">

<div class="card status-card shadow p-4 text-center">

<div class="fs-2">
    🟡
</div>

<div class="status-number text-warning">
    <?= $assetStatus['due_soon'] ?>
</div>

<div class="fw-bold">
    Due Soon
</div>

<small class="text-muted">
    Due within 5 days
</small>

</div>

</div>


<div class="col-md-3">

<div class="card status-card shadow p-4 text-center">

<div class="fs-2">
    🟠
</div>

<div class="status-number text-warning">
    <?= $assetStatus['due'] ?>
</div>

<div class="fw-bold">
    Due Today
</div>

<small class="text-muted">
    Maintenance due today
</small>

</div>

</div>


<div class="col-md-3">

<div class="card status-card shadow p-4 text-center">

<div class="fs-2">
    🔴
</div>

<div class="status-number text-danger">
    <?= $assetStatus['overdue'] ?>
</div>

<div class="fw-bold">
    Overdue
</div>

<small class="text-muted">
    Maintenance date passed
</small>

</div>

</div>


</div>

</div>


<!-- =====================================================
     ASSET TYPE BREAKDOWN
===================================================== -->

<div class="mb-5">

<h5 class="section-title">
    🏢 Asset Type Breakdown
</h5>


<div class="table-responsive shadow-sm">

<table class="table table-hover report-table mb-0">

<thead>

<tr>

<th>Asset Type</th>
<th>Total</th>
<th>OK</th>
<th>Due Soon</th>
<th>Due Today</th>
<th>Overdue</th>

</tr>

</thead>


<tbody>


<tr>

<td>
<strong>🛗 Lift</strong>
</td>

<td>
<span class="badge bg-primary">
<?= $assetTypeTotals['Lift'] ?>
</span>
</td>

<td>
<span class="badge bg-success">
<?= $assetTypeStatus['Lift']['ok'] ?>
</span>
</td>

<td>
<span class="badge bg-warning text-dark">
<?= $assetTypeStatus['Lift']['due_soon'] ?>
</span>
</td>

<td>
<span class="badge bg-warning text-dark">
<?= $assetTypeStatus['Lift']['due'] ?>
</span>
</td>

<td>
<span class="badge bg-danger">
<?= $assetTypeStatus['Lift']['overdue'] ?>
</span>
</td>

</tr>


<tr>

<td>
<strong>↕️ Escalator</strong>
</td>

<td>
<span class="badge bg-primary">
<?= $assetTypeTotals['Escalator'] ?>
</span>
</td>

<td>
<span class="badge bg-success">
<?= $assetTypeStatus['Escalator']['ok'] ?>
</span>
</td>

<td>
<span class="badge bg-warning text-dark">
<?= $assetTypeStatus['Escalator']['due_soon'] ?>
</span>
</td>

<td>
<span class="badge bg-warning text-dark">
<?= $assetTypeStatus['Escalator']['due'] ?>
</span>
</td>

<td>
<span class="badge bg-danger">
<?= $assetTypeStatus['Escalator']['overdue'] ?>
</span>
</td>

</tr>


<tr>

<td>
<strong>➡️ Travelator</strong>
</td>

<td>
<span class="badge bg-primary">
<?= $assetTypeTotals['Travelator'] ?>
</span>
</td>

<td>
<span class="badge bg-success">
<?= $assetTypeStatus['Travelator']['ok'] ?>
</span>
</td>

<td>
<span class="badge bg-warning text-dark">
<?= $assetTypeStatus['Travelator']['due_soon'] ?>
</span>
</td>

<td>
<span class="badge bg-warning text-dark">
<?= $assetTypeStatus['Travelator']['due'] ?>
</span>
</td>

<td>
<span class="badge bg-danger">
<?= $assetTypeStatus['Travelator']['overdue'] ?>
</span>
</td>

</tr>


<tr class="table-light fw-bold">

<td>
TOTAL
</td>

<td>
<?= array_sum($assetTypeTotals) ?>
</td>

<td>
<?= $assetStatus['ok'] ?>
</td>

<td>
<?= $assetStatus['due_soon'] ?>
</td>

<td>
<?= $assetStatus['due'] ?>
</td>

<td>
<?= $assetStatus['overdue'] ?>
</td>

</tr>


</tbody>

</table>

</div>

</div>


<!-- =====================================================
     TECHNICIAN REPORT
===================================================== -->

<div class="mb-5">

<h5 class="section-title">
    👨‍🔧 Technician Monthly Report
</h5>


<div class="table-responsive shadow-sm">

<table class="table table-hover report-table mb-0">

<thead>

<tr>

<th>#</th>
<th>Technician</th>
<th>Total Jobs</th>
<th>Approved</th>
<th>Pending Approval</th>
<th>Rejected</th>
<th>Checklist Submitted</th>

</tr>

</thead>


<tbody>


<?php if (count($technicianReports) > 0): ?>

<?php

$techNo = 1;

foreach (
    $technicianReports
    as $tech
):

?>

<tr>

<td>
<?= $techNo++ ?>
</td>


<td>

<strong>
<?= htmlspecialchars(
    $tech['name']
) ?>
</strong>

</td>


<td>

<span class="badge bg-primary">
<?= (int)$tech['total_jobs'] ?>
</span>

</td>


<td>

<span class="badge bg-success">
<?= (int)$tech['approved_jobs'] ?>
</span>

</td>


<td>

<span class="badge bg-warning text-dark">
<?= (int)$tech['pending_jobs'] ?>
</span>

</td>


<td>

<span class="badge bg-danger">
<?= (int)$tech['rejected_jobs'] ?>
</span>

</td>


<td>

<span class="badge bg-info text-dark">
<?= (int)$tech['checklist_submissions'] ?>
</span>

</td>

</tr>

<?php endforeach; ?>


<?php else: ?>

<tr>

<td
    colspan="7"
    class="text-center text-muted py-4"
>

No technicians found for selected filters.

</td>

</tr>

<?php endif; ?>


</tbody>

</table>

</div>

</div>


<!-- =====================================================
     OWNER REPORT
===================================================== -->

<div class="mb-5">

<h5 class="section-title">
    👤 Owner Monthly Report
</h5>


<div class="table-responsive shadow-sm">

<table class="table table-hover report-table mb-0">

<thead>

<tr>

<th>#</th>
<th>Owner</th>
<th>Total Assets</th>
<th>Callbacks</th>
<th>Approved Callbacks</th>

</tr>

</thead>


<tbody>


<?php if (count($ownerReports) > 0): ?>

<?php

$ownerNo = 1;

foreach (
    $ownerReports
    as $owner
):

?>

<tr>

<td>
<?= $ownerNo++ ?>
</td>


<td>

<strong>
<?= htmlspecialchars(
    $owner['name']
) ?>
</strong>

</td>


<td>

<span class="badge bg-primary">
<?= (int)$owner['total_assets'] ?>
</span>

</td>


<td>

<span class="badge bg-warning text-dark">
<?= (int)$owner['callbacks'] ?>
</span>

</td>


<td>

<span class="badge bg-success">
<?= (int)$owner['approved_callbacks'] ?>
</span>

</td>

</tr>

<?php endforeach; ?>


<?php else: ?>

<tr>

<td
    colspan="5"
    class="text-center text-muted py-4"
>

No owners found for selected filters.

</td>

</tr>

<?php endif; ?>


</tbody>

</table>

</div>

</div>


<!-- =====================================================
     MAINTENANCE CHECKLIST REPORT
===================================================== -->

<div class="mb-5">

<div class="d-flex justify-content-between align-items-center mb-3">

<h5 class="section-title mb-0">
    🛠️ Maintenance Checklist Submissions
</h5>

<span class="badge bg-primary">
    <?= count($maintenanceChecklistReports) ?>
    Submission(s)
</span>

</div>


<div class="table-responsive shadow-sm">

<table class="table table-hover report-table maintenance-table">

<thead>

<tr>

<th>#</th>
<th>Date</th>
<th>Technician</th>
<th>Owner</th>
<th>Asset</th>
<th>Location</th>
<th>Status</th>
<th>Checklist</th>
<th>Remarks</th>
<th>File</th>

</tr>

</thead>


<tbody>


<?php if (
    count($maintenanceChecklistReports) > 0
): ?>


<?php

$maintenanceNo = 1;

foreach (
    $maintenanceChecklistReports
    as $report
):


$maintenanceChecklist =
    json_decode(
        $report['checklist'] ?? '[]',
        true
    );


if (
    !is_array($maintenanceChecklist)
) {
    $maintenanceChecklist = [];
}

?>


<tr>


<td>
<?= $maintenanceNo++ ?>
</td>


<td>

<?= date('d-m-Y', strtotime($report['created_at'])) ?><br>
<?= date('H:i', strtotime($report['created_at'])) ?>

</td>


<td>

<strong>
<?= htmlspecialchars(
    $report['technician_name']
    ?? 'N/A'
) ?>
</strong>

</td>


<td>

<?= htmlspecialchars(
    $report['owner_name']
    ?? 'N/A'
) ?>

</td>


<td>

<strong>
<?= htmlspecialchars(
    $report['asset_name']
) ?>
</strong>

<br>

<small class="text-muted">
<?= htmlspecialchars(
    $report['asset_type']
    ?? ''
) ?>
</small>

</td>


<td>

<?= htmlspecialchars(
    $report['location']
    ?? ''
) ?>

</td>


<td>

<?php

$status = $report['status'];

if ($status === 'approved'):

?>

<span class="badge bg-success">
Approved
</span>

<?php elseif (
    $status === 'pending_owner'
): ?>

<span class="badge bg-warning text-dark">
Pending Owner
</span>

<?php elseif (
    $status === 'rejected'
): ?>

<span class="badge bg-danger">
Rejected
</span>

<?php else: ?>

<span class="badge bg-secondary">
<?= htmlspecialchars(
    ucwords(
        str_replace(
            '_',
            ' ',
            $status
        )
    )
) ?>
</span>

<?php endif; ?>

</td>


<td>

<div class="checklist-box">

<?php if (
    count($maintenanceChecklist) > 0
): ?>

<?php foreach (
    $maintenanceChecklist
    as $item
):

?>

<div class="checklist-item">

✅

<?= htmlspecialchars(
    $item
) ?>

</div>

<?php endforeach; ?>

<?php else: ?>

<span class="text-muted">
No checklist items
</span>

<?php endif; ?>

</div>

</td>


<td>

<div class="report-details">

<?= nl2br(
    htmlspecialchars(
        $report['remarks']
        ?? ''
    )
) ?>

</div>

</td>


<td>

<?php if (
    !empty($report['file'])
): ?>

<a
    href="../uploads/<?= htmlspecialchars(
        $report['file']
    ) ?>"
    target="_blank"
    class="btn btn-sm btn-outline-primary"
>

View File

</a>

<?php else: ?>

<span class="text-muted">
No file
</span>

<?php endif; ?>

</td>


</tr>


<?php endforeach; ?>


<?php else: ?>

<tr>

<td
    colspan="10"
    class="text-center text-muted py-4"
>

No maintenance checklist submissions
found for <?= htmlspecialchars($monthName) ?>.

</td>

</tr>

<?php endif; ?>


</tbody>

</table>

</div>

</div>


<!-- =====================================================
     CALLBACK CHECKLIST REPORT
===================================================== -->

<div class="mb-5">

<div class="d-flex justify-content-between align-items-center mb-3">

<h5 class="section-title mb-0">
    📞 Callback Checklist Submissions
</h5>

<span class="badge bg-primary">
    <?= count($callbackChecklistReports) ?>
    Submission(s)
</span>

</div>


<div class="table-responsive shadow-sm">

<table class="table table-hover report-table callback-table">

<thead>

<tr>

<th>#</th>
<th>Date</th>
<th>Callback No.</th>
<th>Technician</th>
<th>Owner</th>
<th>Asset</th>
<th>Location</th>
<th>Issue</th>
<th>Status</th>
<th>Checklist</th>
<th>Work Report</th>
<th>File</th>

</tr>

</thead>


<tbody>


<?php if (
    count($callbackChecklistReports) > 0
): ?>


<?php

$callbackNo = 1;

foreach (
    $callbackChecklistReports
    as $report
):


$callbackChecklist =
    json_decode(
        $report['checklist'] ?? '[]',
        true
    );


if (
    !is_array($callbackChecklist)
) {
    $callbackChecklist = [];
}


$callbackDate =
    $report['work_closed_at']
    ?? $report['created_at'];

?>


<tr>


<td>
<?= $callbackNo++ ?>
</td>


<td>

<?= date('d-m-Y', strtotime($callbackDate)) ?><br>
<?= date('H:i', strtotime($callbackDate)) ?>

</td>


<td>

<strong>

<?= htmlspecialchars(
    $report['callback_number']
) ?>

</strong>

</td>


<td>

<strong>

<?= htmlspecialchars(
    $report['technician_name']
    ?? 'N/A'
) ?>

</strong>

</td>


<td>

<?= htmlspecialchars(
    $report['owner_name']
    ?? 'N/A'
) ?>

</td>


<td>

<strong>

<?= htmlspecialchars(
    $report['asset_name']
) ?>

</strong>

<br>

<small class="text-muted">

<?= htmlspecialchars(
    $report['asset_type']
    ?? ''
) ?>

</small>

</td>


<td>

<?= htmlspecialchars(
    $report['location']
    ?? ''
) ?>

</td>


<td>

<div class="report-details">

<?= nl2br(
    htmlspecialchars(
        $report['issue']
        ?? ''
    )
) ?>

</div>

</td>


<td>

<?php

$status = $report['status'];

if (
    $status === 'approved'
):

?>

<span class="badge bg-success">
Approved
</span>

<?php elseif (
    $status === 'closed'
): ?>

<span class="badge bg-warning text-dark">
Submitted
</span>

<?php elseif (
    $status === 'open'
): ?>

<span class="badge bg-primary">
Open
</span>

<?php elseif (
    $status === 'rejected'
): ?>

<span class="badge bg-danger">
Rejected
</span>

<?php else: ?>

<span class="badge bg-secondary">

<?= htmlspecialchars(
    ucwords(
        str_replace(
            '_',
            ' ',
            $status
        )
    )
) ?>

</span>

<?php endif; ?>

</td>


<td>

<div class="checklist-box">

<?php if (
    count($callbackChecklist) > 0
): ?>

<?php foreach (
    $callbackChecklist
    as $item
):

?>

<div class="checklist-item">

✅

<?= htmlspecialchars(
    $item
) ?>

</div>

<?php endforeach; ?>

<?php else: ?>

<span class="text-muted">
No checklist items
</span>

<?php endif; ?>

</div>

</td>


<td>

<div class="report-details">

<?= nl2br(
    htmlspecialchars(
        $report['work_report']
        ?? ''
    )
) ?>

</div>

</td>


<td>

<?php if (
    !empty(
        $report['work_report_file']
    )
): ?>

<a
    href="../uploads/callback_reports/<?= htmlspecialchars(
        $report['work_report_file']
    ) ?>"
    target="_blank"
    class="btn btn-sm btn-outline-primary"
>

View File

</a>

<?php else: ?>

<span class="text-muted">
No file
</span>

<?php endif; ?>

</td>


</tr>


<?php endforeach; ?>


<?php else: ?>

<tr>

<td
    colspan="12"
    class="text-center text-muted py-4"
>

No callback checklist submissions
found for <?= htmlspecialchars($monthName) ?>.

</td>

</tr>

<?php endif; ?>


</tbody>

</table>

</div>

</div>


<!-- =====================================================
     REPORT INFORMATION
===================================================== -->

<div class="alert alert-secondary shadow-sm mb-5">

<strong>
    Report Information:
</strong>

This report displays activity for

<strong>
    <?= htmlspecialchars($monthName) ?>
</strong>.

<br><br>

<strong>
Maintenance Checklist:
</strong>

Shows checklists submitted by technicians
for maintenance jobs.

<br>

<strong>
Callback Checklist:
</strong>

Shows checklists submitted by technicians
when completing callback work.

<br><br>

The Admin can use the separate dropdowns above
to filter the two checklist report sections.

</div>


</div>


<!-- =====================================================
     LOCATION FILTER JAVASCRIPT
===================================================== -->

<script>

const locationData =
    <?= json_encode(
        $locationData,
        JSON_HEX_TAG |
        JSON_HEX_APOS |
        JSON_HEX_AMP |
        JSON_HEX_QUOT
    ) ?>;


const technicianFilter =
    document.getElementById(
        'technicianFilter'
    );


const ownerFilter =
    document.getElementById(
        'ownerFilter'
    );


const locationFilter =
    document.getElementById(
        'locationFilter'
    );


const selectedLocation =
    <?= json_encode(
        $selectedLocation
    ) ?>;


/* =========================================================
   UPDATE LOCATION DROPDOWN
========================================================= */

function updateLocations() {

    const technicianId =
        parseInt(
            technicianFilter.value || 0
        );


    const ownerId =
        parseInt(
            ownerFilter.value || 0
        );


    locationFilter.innerHTML = '';


    const allOption =
        document.createElement(
            'option'
        );


    allOption.value = '';

    allOption.textContent =
        'All Locations';


    locationFilter.appendChild(
        allOption
    );


    const locations =
        new Set();


    locationData.forEach(
        function(item) {

            const itemTech =
                parseInt(
                    item.technician_id || 0
                );


            const itemOwner =
                parseInt(
                    item.owner_id || 0
                );


            if (
                technicianId > 0 &&
                itemTech !== technicianId
            ) {

                return;

            }


            if (
                ownerId > 0 &&
                itemOwner !== ownerId
            ) {

                return;

            }


            if (item.location) {

                locations.add(
                    item.location.trim()
                );

            }

        }
    );


    Array.from(locations)
        .sort(
            function(a, b) {

                return a.localeCompare(b);

            }
        )
        .forEach(
            function(location) {

                const option =
                    document.createElement(
                        'option'
                    );


                option.value =
                    location;


                option.textContent =
                    location;


                if (
                    location ===
                    selectedLocation
                ) {

                    option.selected =
                        true;

                }


                locationFilter.appendChild(
                    option
                );

            }
        );

}


/* =========================================================
   TECHNICIAN CHANGE
========================================================= */

technicianFilter.addEventListener(
    'change',
    function() {

        ownerFilter.value = '0';

        updateLocations();

    }
);


/* =========================================================
   OWNER CHANGE
========================================================= */

ownerFilter.addEventListener(
    'change',
    function() {

        technicianFilter.value = '0';

        updateLocations();

    }
);


/* =========================================================
   INITIAL LOAD
========================================================= */

updateLocations();

</script>


<?php

include('../includes/footer.php');

?>