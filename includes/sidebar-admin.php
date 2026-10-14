<?php
/* =========================================================
   NLETA ADMIN SIDEBAR
   ---------------------------------------------------------
   ACTIVE PAGE + ACTION NOTIFICATIONS

   NOTIFICATIONS ONLY FOR:
   1. Service Requests
   2. Checklist Reviews
   3. Payment Settings
   4. PSGA Agreements
   5. SAF Contracts

   ACTION-BASED COUNTS ONLY

   Service Requests:
       status = 'Submitted'

   Checklist Reviews:
       report_requests.status = 'admin_pending'

   Payment Settings:
       payment_transactions.Status = 'Submitted'

   PSGA Agreements:
       contracts.Status = 'Pending Admin Review'
       OR
       contracts.Status = 'Pending Admin Confirmation'

   SAF Contracts:
       saf_contracts.status = 'Pending Admin Review'

   WORKFLOW:
       Advance Payment Verified
            ↓
       PSGA Agreement
            ↓
       PSGA Confirmed
            ↓
       SAF Contracts

   IMPORTANT:
   - Do NOT count total records.
   - Do NOT show badges on other sections.
   - Badge disappears when Admin completes the action.
========================================================= */


/* =========================================================
   CURRENT PAGE
========================================================= */

$currentPage = basename($_SERVER['PHP_SELF']);


/* =========================================================
   ACTIVE SIDEBAR HELPER
========================================================= */

function adminSidebarActive(string $page): string
{
    global $currentPage;

    return ($currentPage === $page)
        ? 'active'
        : '';
}


/* =========================================================
   DEFAULT NOTIFICATION COUNTS
========================================================= */

$serviceRequestNotification  = 0;
$checklistReviewNotification = 0;
$paymentNotification         = 0;
$psgaAgreementNotification   = 0;
$safContractNotification     = 0;


/* =========================================================
   DATABASE CHECK
========================================================= */

$sidebarDbAvailable =
    isset($conn) &&
    $conn instanceof mysqli;


/* =========================================================
   1. SERVICE REQUEST NOTIFICATION
   ---------------------------------------------------------
   ONLY requests waiting for Admin action.

   Submitted
       ↓
   Admin Accept / Reject
       ↓
   No longer notification
========================================================= */

if ($sidebarDbAvailable) {

    $tableCheck = $conn->query("
        SHOW TABLES LIKE 'service_requests'
    ");

    if (
        $tableCheck &&
        $tableCheck->num_rows > 0
    ) {

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM service_requests
            WHERE status = 'Submitted'
        ");

        if ($stmt) {

            if ($stmt->execute()) {

                $row =
                    $stmt
                    ->get_result()
                    ->fetch_assoc();

                $serviceRequestNotification =
                    (int)($row['total'] ?? 0);
            }

            $stmt->close();
        }
    }
}


/* =========================================================
   2. CHECKLIST REVIEW NOTIFICATION
   ---------------------------------------------------------
   ACTUAL ADMIN ACTION STATE:

   report_requests.status = 'admin_pending'

   Technician sends report request
          ↓
   Admin sees notification
          ↓
   Admin Approves Report
          ↓
   status = admin_approved
          ↓
   Badge disappears
========================================================= */

if ($sidebarDbAvailable) {

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
            WHERE status = 'admin_pending'
        ");

        if ($stmt) {

            if ($stmt->execute()) {

                $row =
                    $stmt
                    ->get_result()
                    ->fetch_assoc();

                $checklistReviewNotification =
                    (int)($row['total'] ?? 0);
            }

            $stmt->close();
        }
    }
}


/* =========================================================
   3. PAYMENT NOTIFICATION
   ---------------------------------------------------------
   ACTUAL ADMIN ACTION STATE:

   payment_transactions.Status = 'Submitted'

   Owner submits payment
          ↓
   Admin sees notification
          ↓
   Verify / Reject
          ↓
   Submitted disappears
========================================================= */

if ($sidebarDbAvailable) {

    $tableCheck = $conn->query("
        SHOW TABLES LIKE 'payment_transactions'
    ");

    if (
        $tableCheck &&
        $tableCheck->num_rows > 0
    ) {

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM payment_transactions
            WHERE Status = 'Submitted'
        ");

        if ($stmt) {

            if ($stmt->execute()) {

                $row =
                    $stmt
                    ->get_result()
                    ->fetch_assoc();

                $paymentNotification =
                    (int)($row['total'] ?? 0);
            }

            $stmt->close();
        }
    }
}


/* =========================================================
   4. PSGA AGREEMENT NOTIFICATION
   ---------------------------------------------------------
   PSGA generation is a MANUAL Admin action.

   The PSGA page shows an Offer as "Ready to Generate" when:
       1. Advance payment transaction is Verified
       2. The verified transaction belongs to the Advance stage
       3. The FSO/Offer has NOT already been mapped to a PSGA

   IMPORTANT:
   - At this stage there may be NO row yet in `contracts`.
   - Therefore `contracts.Status` cannot be used to detect
     the "Ready to Generate" notification.
   - Once Admin generates the PSGA, the same item is removed
     from this Ready-to-Generate badge by `psga_fso_map`.
   - Existing PSGA workflow remains unchanged.
========================================================= */

if ($sidebarDbAvailable) {

    /*
     * Use the SAME eligibility logic as admin/psga-agreements.php.
     * This makes the sidebar badge match the actual
     * "Ready to Generate" table exactly.
     */
    $requiredTables = [
        'nleta_offer_master',
        'service_requests',
        'payment_transactions',
        'payment_schedule',
        'psga_fso_map'
    ];

    $allRequiredTablesAvailable = true;

    foreach ($requiredTables as $requiredTable) {

        $tableCheck = $conn->query(
            "SHOW TABLES LIKE '" .
            $conn->real_escape_string($requiredTable) .
            "'"
        );

        if (
            !$tableCheck ||
            $tableCheck->num_rows === 0
        ) {
            $allRequiredTablesAvailable = false;

            if ($tableCheck) {
                $tableCheck->free();
            }

            break;
        }

        $tableCheck->free();
    }

    if ($allRequiredTablesAvailable) {

        /*
         * Count distinct Offers, not transactions.
         *
         * This is intentionally based on:
         *   payment_transactions.Status = 'Verified'
         *   payment_schedule.Payment_Stage = 'Advance'
         *   NOT EXISTS psga_fso_map
         *
         * This is the exact state shown by the PSGA page
         * as "Ready to Generate".
         */
        $stmt = $conn->prepare("
            SELECT COUNT(DISTINCT o.id) AS total
            FROM nleta_offer_master o

            INNER JOIN service_requests sr
                ON sr.id = o.request_id

            INNER JOIN payment_transactions pt
                ON pt.Offer_ID = o.id

            INNER JOIN payment_schedule ps
                ON ps.Schedule_ID = pt.Schedule_ID
               AND ps.Payment_Stage = 'Advance'

            WHERE pt.Status = 'Verified'

              AND NOT EXISTS (
                  SELECT 1
                  FROM psga_fso_map pf0
                  WHERE pf0.offer_id = o.id
              )
        ");

        if ($stmt) {

            if ($stmt->execute()) {

                $row =
                    $stmt
                    ->get_result()
                    ->fetch_assoc();

                $psgaAgreementNotification =
                    (int)($row['total'] ?? 0);
            }

            $stmt->close();
        }
    }
}


/* =========================================================
   5. SAF CONTRACT NOTIFICATION
   ---------------------------------------------------------
   SAF ADMIN ACTIONS:

   A) Confirmed PSGA + one or more assets without SAF
          ↓
      SAF is available for MANUAL GENERATION
          ↓
      SHOW BADGE

   B) SAF generated
          ↓
      saf_contracts.status = 'Pending Admin Review'
          ↓
      SHOW BADGE

   C) Admin forwards SAF to Owner
          ↓
      status = 'Pending Owner Acceptance'
          ↓
      This Admin badge for that SAF disappears

   IMPORTANT:
   - The badge must also work BEFORE any saf_contracts row
     exists, because SAF generation is manual.
   - Generation eligibility is the SAME logic used by
     admin/saf-contracts.php:
         contracts.Status = 'Confirmed'
         matching FSO exists
         selected assets exist
         SAF is still missing for at least one asset.
   - Count one notification per actionable PSGA / SAF record.
========================================================= */

if ($sidebarDbAvailable) {

    /*
     * ---------------------------------------------------------
     * A. CONFIRMED PSGA WAITING FOR SAF GENERATION
     * ---------------------------------------------------------
     */
    $generationTables = [
        'contracts',
        'nleta_offer_master',
        'service_requests',
        'service_request_assets',
        'saf_contracts'
    ];

    $generationTablesAvailable = true;

    foreach ($generationTables as $requiredTable) {

        $safeTable =
            $conn->real_escape_string($requiredTable);

        $tableCheck =
            $conn->query(
                "SHOW TABLES LIKE '{$safeTable}'"
            );

        if (
            !$tableCheck ||
            $tableCheck->num_rows === 0
        ) {

            $generationTablesAvailable = false;

            if ($tableCheck) {
                $tableCheck->free();
            }

            break;
        }

        $tableCheck->free();
    }


    $safGenerationNotification = 0;


    if ($generationTablesAvailable) {

        /*
         * This is intentionally the same eligibility logic as
         * confirmedPsgaRows on the SAF page.
         */
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM (
                SELECT
                    c.PSGA_ID,
                    c.PSGA_No,
                    o.id AS offer_id

                FROM contracts c

                INNER JOIN nleta_offer_master o
                    ON o.psga_no = c.PSGA_No

                LEFT JOIN service_request_assets sra
                    ON sra.request_id = o.request_id

                LEFT JOIN saf_contracts s
                    ON s.offer_id = o.id
                   AND s.asset_id = sra.asset_id

                WHERE
                    c.Status = 'Confirmed'

                GROUP BY
                    c.PSGA_ID,
                    c.PSGA_No,
                    o.id

                HAVING
                    COUNT(DISTINCT sra.asset_id)
                    >
                    COUNT(DISTINCT s.asset_id)
            ) AS ready_for_saf
        ");

        if ($stmt) {

            if ($stmt->execute()) {

                $row =
                    $stmt
                    ->get_result()
                    ->fetch_assoc();

                $safGenerationNotification =
                    (int)(
                        $row['total']
                        ??
                        0
                    );
            }

            $stmt->close();
        }
    }


    /*
     * ---------------------------------------------------------
     * B. SAF ALREADY GENERATED BUT WAITING FOR ADMIN REVIEW
     * ---------------------------------------------------------
     */
    $safReviewNotification = 0;

    $tableCheck = $conn->query("
        SHOW TABLES LIKE 'saf_contracts'
    ");

    if (
        $tableCheck &&
        $tableCheck->num_rows > 0
    ) {

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM saf_contracts
            WHERE status = 'Pending Admin Review'
        ");

        if ($stmt) {

            if ($stmt->execute()) {

                $row =
                    $stmt
                    ->get_result()
                    ->fetch_assoc();

                $safReviewNotification =
                    (int)(
                        $row['total']
                        ??
                        0
                    );
            }

            $stmt->close();
        }
    }


    /*
     * Final SAF badge:
     *
     * Ready for Generation + Pending Admin Review
     *
     * These are separate actionable records, so both are
     * intentionally visible through the same SAF sidebar badge.
     */
    $safContractNotification =
        $safGenerationNotification
        +
        $safReviewNotification;
}


/* =========================================================
   NOTIFICATION BADGE HELPER
========================================================= */

function adminNotificationBadge(
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
   ADMIN SIDEBAR
   ---------------------------------------------------------
   IMPORTANT:
   Sidebar scrolls independently.
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
            rgba(255, 255, 255, .25) transparent;
    }


    /* =========================================================
   SIDEBAR SCROLLBAR
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
            rgba(255, 255, 255, .25);

        border-radius:
            10px;
    }


    .sidebar::-webkit-scrollbar-thumb:hover {

        background:
            rgba(255, 255, 255, .40);
    }


    /* =========================================================
   NORMALIZE SIDEBAR ITEMS
   ---------------------------------------------------------
   Prevent spacing changes when active.
========================================================= */

    .sidebar>a,
    .sidebar>li.nav-item {

        display:
            block;

        width:
            100%;

        height:
            50px;

        min-height:
            50px;

        margin:
            0;

        padding:
            0;

        box-sizing:
            border-box;

        list-style:
            none;

        flex-shrink:
            0;
    }


    /* =========================================================
   ALL SIDEBAR LINKS
========================================================= */

    .sidebar>a,
    .sidebar>li.nav-item>a {

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
   LINK TEXT
========================================================= */

    .sidebar>a span,
    .sidebar>li.nav-item>a span {

        overflow:
            hidden;

        text-overflow:
            ellipsis;

        white-space:
            nowrap;
    }


    /* =========================================================
   ACTIVE SECTION
   ---------------------------------------------------------
   IMPORTANT:
   NO border.
   NO padding change.
   NO margin change.

   Inset shadow does not change layout size.
========================================================= */

    .sidebar>a.active,
    .sidebar>li.nav-item>a.active {

        background:
            rgba(0, 0, 0, .28) !important;

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

    .sidebar>a.active i,
    .sidebar>li.nav-item>a.active i {

        color:
            #ffffff !important;
    }


    /* =========================================================
   HOVER
========================================================= */

    .sidebar>a:hover,
    .sidebar>li.nav-item>a:hover {

        background:
            rgba(0, 0, 0, .14);
    }


    /* =========================================================
   ACTIVE HOVER
========================================================= */

    .sidebar>a.active:hover,
    .sidebar>li.nav-item>a.active:hover {

        background:
            rgba(0, 0, 0, .32) !important;
    }


    /* =========================================================
   ICON
========================================================= */

    .sidebar>a i,
    .sidebar>li.nav-item>a i {

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
   NOTIFICATION BADGE
   ---------------------------------------------------------
   Red circle at right side.
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
            0 1px 4px rgba(0, 0, 0, .20);
    }


    /* =========================================================
   MOBILE
========================================================= */

    @media(max-width:768px) {

        .sidebar {

            height:
                calc(100vh - 55px);

            max-height:
                calc(100vh - 55px);
        }


        .sidebar>a,
        .sidebar>li.nav-item {

            height:
                48px;

            min-height:
                48px;
        }


        .sidebar>a,
        .sidebar>li.nav-item>a {

            height:
                48px;

            min-height:
                48px;
        }
    }


    /* =========================================================
   SMALL HEIGHT SCREEN
========================================================= */

    @media(max-height:700px) {

        .sidebar>a,
        .sidebar>li.nav-item {

            height:
                46px;

            min-height:
                46px;
        }


        .sidebar>a,
        .sidebar>li.nav-item>a {

            height:
                46px;

            min-height:
                46px;
        }
    }
</style>


<div class="sidebar">


    <!-- =====================================================
         DASHBOARD
         NO NOTIFICATION
    ====================================================== -->

    <a
        href="dashboard.php"
        class="<?= adminSidebarActive('dashboard.php') ?>">

        <i class="bi bi-speedometer2"></i>

        <span>
            Dashboard
        </span>

    </a>


    <!-- =====================================================
         PSGA AGREEMENTS
         NOTIFICATION
         -----------------------------------------------------
         Separate agreement stage BEFORE SAF.

         Advance Payment Verified
                ↓
         PSGA Agreement
                ↓
         PSGA Confirmed
                ↓
         SAF Contracts
    ====================================================== -->

    <a
        href="psga-agreements.php"
        class="<?= adminSidebarActive('psga-agreements.php') ?>">

        <i class="bi bi-file-earmark-text"></i>

        <span>
            PSGA Agreements
        </span>

        <?= adminNotificationBadge(
            $psgaAgreementNotification,
            'PSGA Agreement(s) waiting for Admin action'
        ) ?>

    </a>


    <!-- =====================================================
         SAF CONTRACTS
         NOTIFICATION
         -----------------------------------------------------
         SAF becomes available after PSGA is confirmed.
    ====================================================== -->

    <a
        href="saf-contracts.php"
        class="<?= adminSidebarActive('saf-contracts.php') ?>">

        <i class="bi bi-file-earmark-check"></i>

        <span>
            SAF
        </span>

        <?= adminNotificationBadge(
            $safContractNotification,
            'SAF generation / review action(s) pending'
        ) ?>

    </a>


    <!-- =====================================================
         SERVICE REQUESTS
         NOTIFICATION
    ====================================================== -->

    <a
        href="service-requests.php"
        class="<?= adminSidebarActive('service-requests.php') ?>">

        <i class="bi bi-clipboard-check"></i>

        <span>
            Service Requests
        </span>

        <?= adminNotificationBadge(
            $serviceRequestNotification,
            'Service Request(s) waiting for Admin action'
        ) ?>

    </a>


    <!-- =====================================================
         CHECKLIST REVIEWS
         NOTIFICATION
    ====================================================== -->

    <a
        href="checklist-reviews.php"
        class="<?= adminSidebarActive('checklist-reviews.php') ?>">

        <i class="bi bi-clipboard2-check"></i>

        <span>
            Checklist Reviews
        </span>

        <?= adminNotificationBadge(
            $checklistReviewNotification,
            'Report request(s) waiting for Admin approval'
        ) ?>

    </a>



    <!-- =====================================================
         PAYMENT SETTINGS
         NOTIFICATION
    ====================================================== -->

    <a
        href="payment-settings.php"
        class="<?= adminSidebarActive('payment-settings.php') ?>">

        <i class="bi bi-credit-card-2-front"></i>

        <span>
            Payment Settings
        </span>

        <?= adminNotificationBadge(
            $paymentNotification,
            'Payment confirmation(s) waiting for Admin action'
        ) ?>

    </a>


    <!-- =====================================================
         USER MANAGEMENT
         NO NOTIFICATION
    ====================================================== -->

    <a
        href="users.php"
        class="<?= adminSidebarActive('users.php') ?>">

        <i class="bi bi-people"></i>

        <span>
            User Management
        </span>

    </a>



    <!-- =====================================================
         MAINTENANCE
         NO NOTIFICATION
    ====================================================== -->

    <a
        href="maintenance.php"
        class="<?= adminSidebarActive('maintenance.php') ?>">

        <i class="bi bi-tools"></i>

        <span>
            Maintenance
        </span>

    </a>


    <!-- =====================================================
         CALLBACKS
         NO NOTIFICATION
    ====================================================== -->

    <a
        href="callbacks.php"
        class="<?= adminSidebarActive('callbacks.php') ?>">

        <i class="bi bi-exclamation-circle"></i>

        <span>
            Callbacks
        </span>

    </a>


    <!-- =====================================================
         NOC REGISTRATION
         NO NOTIFICATION
    ====================================================== -->

    <a
        href="noc-registration.php"
        class="<?= adminSidebarActive('noc-registration.php') ?>">

        <i class="bi bi-file-earmark-text"></i>

        <span>
            NOC Registration
        </span>

    </a>


    <!-- =====================================================
         NOC LIST
         NO NOTIFICATION
    ====================================================== -->

    <a
        href="noc-list.php"
        class="<?= adminSidebarActive('noc-list.php') ?>">

        <i class="bi bi-list-check"></i>

        <span>
            NOC List
        </span>

    </a>


    <!-- =====================================================
         ASSETS / LIFTS
         NO NOTIFICATION
    ====================================================== -->

    <a
        href="assets.php"
        class="<?= adminSidebarActive('assets.php') ?>">

        <i class="bi bi-building"></i>

        <span>
            Assets/Lifts
        </span>

    </a>


    <!-- =====================================================
         CLIENT MASTER
         NO NOTIFICATION
    ====================================================== -->

    <a
        href="client_master.php"
        class="<?= adminSidebarActive('client_master.php') ?>">

        <i class="bi bi-person-vcard"></i>

        <span>
            Client Master
        </span>

    </a>


    <!-- =====================================================
         OWNER SERVICE ACCESS
         NO NOTIFICATION
    ====================================================== -->

    <a
        href="owner-service-access.php"
        class="<?= adminSidebarActive('owner-service-access.php') ?>">

        <i class="bi bi-shield-lock"></i>

        <span>
            Owner Service Access
        </span>

    </a>


    <!-- =====================================================
         MONTHLY REPORTS
         NO NOTIFICATION
    ====================================================== -->

    <a
        href="monthly_reports.php"
        class="<?= adminSidebarActive('monthly_reports.php') ?>">

        <i class="bi bi-bar-chart-line"></i>

        <span>
            Monthly Reports
        </span>

    </a>

</div>