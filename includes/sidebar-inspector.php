<?php
/*
|--------------------------------------------------------------------------
| NLETA INSPECTOR SIDEBAR
|--------------------------------------------------------------------------
|
| Features:
| - Inspector role protection
| - Active page highlight
| - Independent sidebar scrolling
| - Notification badge on Inspections
| - Notification badge on Report Requests
|
|--------------------------------------------------------------------------
*/


/* =========================================================
   SESSION
========================================================= */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


/* =========================================================
   INSPECTOR ROLE CHECK
========================================================= */

if (
    empty($_SESSION['uid']) ||
    empty($_SESSION['role']) ||
    $_SESSION['role'] !== 'inspector'
) {

    header(
        "Location: ../login.php"
    );

    exit;
}


/* =========================================================
   INSPECTOR ID
========================================================= */

$inspectorId = (int)(
    $_SESSION['uid']
    ?? $_SESSION['user_id']
    ?? 0
);


/* =========================================================
   CURRENT PAGE
========================================================= */

$currentPage = basename(
    $_SERVER['PHP_SELF']
);


/* =========================================================
   ACTIVE PAGE HELPER
========================================================= */

function inspectorSidebarActive(
    string $page
): string {

    global $currentPage;

    return (
        $currentPage === $page
    )
        ? 'active'
        : '';
}


/* =========================================================
   NOTIFICATION COUNTS
========================================================= */

$inspectionNotification    = 0;
$reportRequestNotification = 0;


/* =========================================================
   DATABASE AVAILABLE?
========================================================= */

$sidebarDbAvailable =
    isset($conn) &&
    $conn instanceof mysqli;


/* =========================================================
   1. INSPECTIONS NOTIFICATION
   ---------------------------------------------------------
   Only Inspector's own assignments.

   assigned
       ↓
   Inspector opens / starts inspection
       ↓
   notification disappears

   IMPORTANT:
   Do NOT count all inspection jobs.
========================================================= */

if (
    $sidebarDbAvailable &&
    $inspectorId > 0
) {

    $tableCheck = $conn->query("
        SHOW TABLES LIKE 'inspector_assignments'
    ");

    if (
        $tableCheck &&
        $tableCheck->num_rows > 0
    ) {

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM inspector_assignments
            WHERE
                inspector_id = ?
                AND status = 'assigned'
        ");

        if ($stmt) {

            $stmt->bind_param(
                'i',
                $inspectorId
            );

            if ($stmt->execute()) {

                $row =
                    $stmt
                        ->get_result()
                        ->fetch_assoc();

                $inspectionNotification =
                    (int)(
                        $row['total']
                        ?? 0
                    );
            }

            $stmt->close();
        }
    }
}


/* =========================================================
   2. REPORT REQUEST NOTIFICATION
   ---------------------------------------------------------
   Only requests belonging to this Inspector.

   requested
       ↓
   Inspector sends to Admin
       ↓
   admin_pending
       ↓
   notification disappears

   Existing workflow:
   requested → admin_pending
========================================================= */

if (
    $sidebarDbAvailable &&
    $inspectorId > 0
) {

    $tableCheck = $conn->query("
        SHOW TABLES LIKE 'report_requests'
    ");

    if (
        $tableCheck &&
        $tableCheck->num_rows > 0
    ) {

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM report_requests
            WHERE
                inspector_id = ?
                AND status = 'requested'
        ");

        if ($stmt) {

            $stmt->bind_param(
                'i',
                $inspectorId
            );

            if ($stmt->execute()) {

                $row =
                    $stmt
                        ->get_result()
                        ->fetch_assoc();

                $reportRequestNotification =
                    (int)(
                        $row['total']
                        ?? 0
                    );
            }

            $stmt->close();
        }
    }
}


/* =========================================================
   NOTIFICATION BADGE HELPER
========================================================= */

function inspectorNotificationBadge(
    int $count,
    string $title
): string {

    if ($count <= 0) {
        return '';
    }

    return '
        <span
            class="sidebar-notification-badge"
            title="' .
                htmlspecialchars(
                    $title,
                    ENT_QUOTES,
                    'UTF-8'
                ) .
        '"
        >' .
            $count .
        '</span>
    ';
}

?>


<style>

/* =========================================================
   INSPECTOR SIDEBAR
========================================================= */

.sidebar {

    height:
        calc(100vh - 70px);

    max-height:
        calc(100vh - 70px);

    overflow-y:
        auto;

    overflow-x:
        hidden;

    box-sizing:
        border-box;

    scrollbar-gutter:
        stable;

    scroll-behavior:
        smooth;

    scrollbar-width:
        thin;

    scrollbar-color:
        rgba(255,255,255,.25)
        transparent;
}


/* =========================================================
   SCROLLBAR
========================================================= */

.sidebar::-webkit-scrollbar {

    width:
        6px;
}


.sidebar::-webkit-scrollbar-track {

    background:
        transparent;
}


.sidebar::-webkit-scrollbar-thumb {

    background:
        rgba(255,255,255,.25);

    border-radius:
        10px;
}


.sidebar::-webkit-scrollbar-thumb:hover {

    background:
        rgba(255,255,255,.40);
}


/* =========================================================
   SIDEBAR LINKS
========================================================= */

.sidebar > a {

    position:
        relative;

    display:
        flex;

    align-items:
        center;

    gap:
        10px;

    width:
        100%;

    height:
        50px;

    min-height:
        50px;

    margin:
        0;

    padding:
        0 14px;

    box-sizing:
        border-box;

    flex-shrink:
        0;

    text-decoration:
        none;

    transition:
        background-color .18s ease,
        color .18s ease;
}


/* =========================================================
   ICON
========================================================= */

.sidebar > a i {

    width:
        20px;

    min-width:
        20px;

    text-align:
        center;

    flex-shrink:
        0;
}


/* =========================================================
   TEXT
========================================================= */

.sidebar > a span {

    overflow:
        hidden;

    text-overflow:
        ellipsis;

    white-space:
        nowrap;
}


/* =========================================================
   ACTIVE PAGE
   ---------------------------------------------------------
   Does NOT change padding / width / height.
========================================================= */

.sidebar > a.active {

    background:
        rgba(0,0,0,.28) !important;

    color:
        #ffffff !important;

    font-weight:
        600;

    box-shadow:
        inset 4px 0 0 #ffffff;
}


/* =========================================================
   ACTIVE ICON
========================================================= */

.sidebar > a.active i {

    color:
        #ffffff !important;
}


/* =========================================================
   HOVER
========================================================= */

.sidebar > a:hover {

    background:
        rgba(0,0,0,.14);
}


/* =========================================================
   ACTIVE HOVER
========================================================= */

.sidebar > a.active:hover {

    background:
        rgba(0,0,0,.32) !important;
}


/* =========================================================
   NOTIFICATION BADGE
========================================================= */

.sidebar-notification-badge {

    margin-left:
        auto;

    min-width:
        22px;

    height:
        22px;

    padding:
        0 6px;

    display:
        inline-flex;

    align-items:
        center;

    justify-content:
        center;

    border-radius:
        50%;

    background:
        #dc3545;

    color:
        #ffffff;

    font-size:
        11px;

    font-weight:
        700;

    line-height:
        1;

    flex-shrink:
        0;

    box-shadow:
        0 1px 4px rgba(0,0,0,.20);
}


/* =========================================================
   MOBILE
========================================================= */

@media (max-width:768px) {

    .sidebar {

        height:
            calc(100vh - 55px);

        max-height:
            calc(100vh - 55px);
    }


    .sidebar > a {

        height:
            48px;

        min-height:
            48px;
    }
}


/* =========================================================
   SMALL HEIGHT SCREEN
========================================================= */

@media (max-height:700px) {

    .sidebar > a {

        height:
            46px;

        min-height:
            46px;
    }
}

</style>


<!-- =======================================================
     INSPECTOR SIDEBAR
======================================================= -->

<div class="sidebar">


    <!-- =====================================================
         DASHBOARD
         NO NOTIFICATION
    ====================================================== -->

    <a
        href="dashboard.php"
        class="<?= inspectorSidebarActive('dashboard.php') ?>"
    >

        <i class="bi bi-speedometer2"></i>

        <span>
            Dashboard
        </span>

    </a>


    <!-- =====================================================
         INSPECTIONS
         NOTIFICATION
    ====================================================== -->

    <a
        href="inspections.php"
        class="<?= inspectorSidebarActive('inspections.php') ?>"
    >

        <i class="bi bi-clipboard2-check"></i>

        <span>
            Inspections
        </span>

        <?= inspectorNotificationBadge(
            $inspectionNotification,
            'Inspection(s) assigned to you'
        ) ?>

    </a>


    <!-- =====================================================
         REPORT REQUESTS
         NOTIFICATION
    ====================================================== -->

    <a
        href="report_requests.php"
        class="<?= inspectorSidebarActive('report_requests.php') ?>"
    >

        <i class="bi bi-file-earmark-pdf"></i>

        <span>
            Report Requests
        </span>

        <?= inspectorNotificationBadge(
            $reportRequestNotification,
            'Report request(s) waiting for your action'
        ) ?>

    </a>


    <!-- =====================================================
         HISTORY
         NO NOTIFICATION
    ====================================================== -->

    <a
        href="history.php"
        class="<?= inspectorSidebarActive('history.php') ?>"
    >

        <i class="bi bi-clock-history"></i>

        <span>
            History
        </span>

    </a>


</div>