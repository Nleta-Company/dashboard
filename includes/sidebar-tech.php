<?php
/*
|--------------------------------------------------------------------------
| NLETA TECHNICIAN SIDEBAR
|--------------------------------------------------------------------------
|
| Features:
| - Active page highlight
| - Independent sidebar scrolling
| - Notification on Assigned Assets
| - Notification on Maintenance Checklist
| - Notification on Callbacks
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
   TECHNICIAN ID
========================================================= */

$technicianId = (int)(
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
   NOTIFICATION COUNTS
========================================================= */

$assignedAssetsNotification = 0;
$maintenanceChecklistNotification = 0;
$callbackNotification = 0;


/* =========================================================
   DATABASE AVAILABLE?
========================================================= */

$sidebarDbAvailable =
    isset($conn) &&
    $conn instanceof mysqli;


/* =========================================================
   1. ASSIGNED ASSETS
   ---------------------------------------------------------
   New maintenance job assigned to Technician.

   assigned
      ↓
   Technician opens / starts work
      ↓
   notification disappears
========================================================= */

if (
    $sidebarDbAvailable &&
    $technicianId > 0
) {

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total

        FROM maintenance_jobs

        WHERE technician_id = ?

          AND status = 'assigned'
    ");

    if ($stmt) {

        $stmt->bind_param(
            'i',
            $technicianId
        );

        if ($stmt->execute()) {

            $row =
                $stmt
                ->get_result()
                ->fetch_assoc();

            $assignedAssetsNotification =
                (int)(
                    $row['total']
                    ?? 0
                );
        }

        $stmt->close();
    }
}


/* =========================================================
   2. MAINTENANCE CHECKLIST
   ---------------------------------------------------------
   Maintenance work already started.

   in_progress
      ↓
   Technician needs to complete / submit checklist
========================================================= */

if (
    $sidebarDbAvailable &&
    $technicianId > 0
) {

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total

        FROM maintenance_jobs

        WHERE technician_id = ?

          AND status = 'in_progress'
    ");

    if ($stmt) {

        $stmt->bind_param(
            'i',
            $technicianId
        );

        if ($stmt->execute()) {

            $row =
                $stmt
                ->get_result()
                ->fetch_assoc();

            $maintenanceChecklistNotification =
                (int)(
                    $row['total']
                    ?? 0
                );
        }

        $stmt->close();
    }
}


/* =========================================================
   3. CALLBACKS
   ---------------------------------------------------------
   Callback assigned to Technician and waiting for
   Technician response.

   status = open

   technician_response:
      NULL
      OR blank

   Once Technician accepts/rejects it, notification
   disappears.
========================================================= */

if (
    $sidebarDbAvailable &&
    $technicianId > 0
) {

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total

        FROM callbacks

        WHERE technician_id = ?

          AND status = 'open'

          AND (
                technician_response IS NULL
                OR technician_response = ''
          )
    ");

    if ($stmt) {

        $stmt->bind_param(
            'i',
            $technicianId
        );

        if ($stmt->execute()) {

            $row =
                $stmt
                ->get_result()
                ->fetch_assoc();

            $callbackNotification =
                (int)(
                    $row['total']
                    ?? 0
                );
        }

        $stmt->close();
    }
}


/* =========================================================
   NOTIFICATION BADGE HELPER
========================================================= */

function technicianNotificationBadge(
    int $count,
    string $title
): string {

    if ($count <= 0) {
        return '';
    }

    return '
        <span
            class="technician-notification-badge"
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


/* =========================================================
   ACTIVE PAGE HELPER
========================================================= */

function technicianSidebarActive(
    string $page
): string {

    global $currentPage;

    return (
        $currentPage === $page
    )
        ? 'active'
        : '';
}

?>


<style>

/* =========================================================
   TECHNICIAN SIDEBAR
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

.technician-notification-badge {

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
        0 1px 4px
        rgba(0,0,0,.20);
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
     TECHNICIAN SIDEBAR
======================================================= -->

<div class="sidebar">


    <!-- =====================================================
         DASHBOARD
         NO NOTIFICATION
    ====================================================== -->

    <a
        href="dashboard.php"
        class="<?= technicianSidebarActive('dashboard.php') ?>"
    >

        <i class="bi bi-speedometer2"></i>

        <span>
            Dashboard
        </span>

    </a>


    <!-- =====================================================
         ASSIGNED ASSETS
         NOTIFICATION
    ====================================================== -->

    <a
        href="assigned_lifts.php"
        class="<?= technicianSidebarActive('assigned_lifts.php') ?>"
    >

        <i class="bi bi-building-exclamation"></i>

        <span>
            Assigned Assets
        </span>

        <?= technicianNotificationBadge(
            $assignedAssetsNotification,
            'Asset(s) assigned to you'
        ) ?>

    </a>


    <!-- =====================================================
         MAINTENANCE CHECKLIST
         NOTIFICATION
    ====================================================== -->

    <a
        href="maintenance_submit.php"
        class="<?= technicianSidebarActive('maintenance_submit.php') ?>"
    >

        <i class="bi bi-check2-square"></i>

        <span>
            Maintenance Checklist
        </span>

        <?= technicianNotificationBadge(
            $maintenanceChecklistNotification,
            'Maintenance checklist(s) requiring your action'
        ) ?>

    </a>


    <!-- =====================================================
         CREATE CALLBACK
         NO NOTIFICATION
    ====================================================== -->

    <a
        href="create-callback.php"
        class="<?= technicianSidebarActive('create-callback.php') ?>"
    >

        <i class="bi bi-plus-circle"></i>

        <span>
            Create Callback
        </span>

    </a>


    <!-- =====================================================
         CALLBACKS
         NOTIFICATION
    ====================================================== -->

    <a
        href="callbacks.php"
        class="<?= technicianSidebarActive('callbacks.php') ?>"
    >

        <i class="bi bi-exclamation-triangle"></i>

        <span>
            Callbacks
        </span>

        <?= technicianNotificationBadge(
            $callbackNotification,
            'Callback(s) waiting for your response'
        ) ?>

    </a>


    <!-- =====================================================
         HISTORY
         NO NOTIFICATION
    ====================================================== -->

    <a
        href="history.php"
        class="<?= technicianSidebarActive('history.php') ?>"
    >

        <i class="bi bi-clock-history"></i>

        <span>
            History
        </span>

    </a>


</div>