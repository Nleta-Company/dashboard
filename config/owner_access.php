<?php
/**
 * ============================================================
 * NLETA OWNER SERVICE ACCESS CONTROL
 * ============================================================
 *
 * Existing Owner access system is preserved.
 *
 * DGS / DIGITAL GOVERNANCE SUPPORT:
 * ------------------------------------------------------------
 * When an Owner has an ACTIVE DGS SAF + active asset access,
 * DGS acts as a master unlock for the existing Owner sections.
 *
 * IMPORTANT:
 * - No new sidebar item is created here.
 * - Existing sidebar links/pages remain unchanged.
 * - Existing owner_service_access remains the normal access
 *   controller for Owners who do NOT have active DGS.
 * - PAT / SAT / SCI / Inspector / My Lifts functionality is
 *   not changed here.
 *
 * Requirements:
 * - $conn must already exist.
 * - $_SESSION['uid'] must contain logged-in Owner ID.
 * - config/dgs_access.php must be available for DGS checks.
 *
 * ============================================================
 */


/* ============================================================
   PREVENT DIRECT ACCESS
============================================================ */

if (!defined('NLETA_OWNER_ACCESS')) {
    define('NLETA_OWNER_ACCESS', true);
}


/* ============================================================
   LOAD DGS ACCESS HELPER
   ------------------------------------------------------------
   This is intentionally loaded here as well as from the
   Owner sidebar. This is important because protected Owner
   pages call requireOwnerAccess() directly.
============================================================ */

$dgsAccessFile = __DIR__ . '/dgs_access.php';

if (
    is_file($dgsAccessFile) &&
    !function_exists('ownerHasActiveDgs')
) {
    require_once $dgsAccessFile;
}


/* ============================================================
   CHECK OWNER LOGIN
============================================================ */

if (!function_exists('getCurrentOwnerId')) {
    function getCurrentOwnerId(): int
    {
        return (int)(
            $_SESSION['uid']
            ??
            $_SESSION['user_id']
            ??
            0
        );
    }
}


/* ============================================================
   AVAILABLE OWNER SERVICES
============================================================ */

if (!function_exists('getOwnerServiceList')) {
    function getOwnerServiceList(): array
    {
        return [

            'offers' => [
                'name' => 'Offers',
                'page' => 'offers.php',
                'icon' => 'bi-file-earmark-text'
            ],

            'assets' => [
                'name' => 'My Lifts',
                'page' => 'assets.php',
                'icon' => 'bi-building'
            ],

            'issues' => [
                'name' => 'Reported Issues',
                'page' => 'issues.php',
                'icon' => 'bi-exclamation-triangle'
            ],

            'approvals' => [
                'name' => 'Checklist Approvals',
                'page' => 'approvals.php',
                'icon' => 'bi-check2-circle'
            ],

            'noc' => [
                'name' => 'NOC Details',
                'page' => 'noc-registration.php',
                'icon' => 'bi-patch-check'
            ],

            'noc_history' => [
                'name' => 'NOC History',
                'page' => 'noc-list.php',
                'icon' => 'bi-journal-text'
            ],

            'callback' => [
                'name' => 'Create Callback',
                'page' => 'create-callback.php',
                'icon' => 'bi-plus-circle'
            ],

            'fortnightly' => [
                'name' => 'Fortnightly Check',
                'page' => 'fortnightly-check.php',
                'icon' => 'bi-clipboard2-check'
            ],

            'callback_approval' => [
                'name' => 'Callback for Approval',
                'page' => 'callbacks.php',
                'icon' => 'bi-clipboard-check'
            ],

            'maintenance_history' => [
                'name' => 'Maintenance History',
                'page' => 'maintenance-history.php',
                'icon' => 'bi-tools'
            ]

        ];
    }
}


/* ============================================================
   DGS MASTER ACCESS CHECK
   ------------------------------------------------------------
   Returns TRUE only when the Owner has:
   - Owner Accepted DGS SAF
   - Admin activated DGS
   - At least one active dgs_asset_access row
============================================================ */

if (!function_exists('ownerDgsMasterAccess')) {
    function ownerDgsMasterAccess(?int $ownerId = null): bool
    {
        global $conn;

        if ($ownerId === null) {
            $ownerId = getCurrentOwnerId();
        }

        $ownerId = (int)$ownerId;

        if ($ownerId <= 0) {
            return false;
        }

        if (
            !isset($conn) ||
            !($conn instanceof mysqli)
        ) {
            return false;
        }

        if (
            function_exists('ownerHasActiveDgs')
        ) {
            return ownerHasActiveDgs(
                $conn,
                $ownerId
            );
        }

        /*
         * Safety fallback if dgs_access.php could not be loaded.
         */
        $stmt = $conn->prepare("
            SELECT 1
            FROM dgs_asset_access daa
            INNER JOIN saf_contracts s
                ON s.id = daa.saf_id
            WHERE daa.owner_id = ?
              AND daa.is_active = 1
              AND s.owner_id = daa.owner_id
              AND s.status = 'Accepted'
              AND s.dgs_activation_status = 'Active'
              AND (
                    UPPER(
                        TRIM(
                            COALESCE(
                                s.service_code,
                                ''
                            )
                        )
                    ) IN (
                        'DG',
                        'DGS',
                        'DIGITAL_GOVERNANCE'
                    )
                    OR UPPER(
                        COALESCE(
                            s.service_name,
                            ''
                        )
                    ) LIKE '%DIGITAL GOVERNANCE%'
                    OR UPPER(
                        COALESCE(
                            s.service_name,
                            ''
                        )
                    ) LIKE '%GOVERNANCE SUPPORT%'
              )
            LIMIT 1
        ");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param(
            'i',
            $ownerId
        );

        $stmt->execute();

        $result = $stmt->get_result();

        $row = $result
            ? $result->fetch_assoc()
            : null;

        $stmt->close();

        return (bool)$row;
    }
}


/* ============================================================
   CHECK SINGLE OWNER SERVICE ACCESS
============================================================ */

if (!function_exists('ownerCanAccess')) {
    function ownerCanAccess(
        string $serviceCode,
        ?int $ownerId = null
    ): bool {

        global $conn;

        if ($ownerId === null) {
            $ownerId = getCurrentOwnerId();
        }

        $ownerId = (int)$ownerId;

        if ($ownerId <= 0) {
            return false;
        }

        $serviceCode =
            trim($serviceCode);

        if ($serviceCode === '') {
            return false;
        }

        /*
         * Only allow known existing Owner service codes.
         */
        $services =
            getOwnerServiceList();

        if (
            !array_key_exists(
                $serviceCode,
                $services
            )
        ) {
            return false;
        }

        /*
         * ====================================================
         * DGS MASTER UNLOCK
         * ====================================================
         *
         * If Admin has activated DGS for this Owner, allow
         * access to the EXISTING Owner sections.
         *
         * No page URL or page functionality is changed.
         */
        if (
            ownerDgsMasterAccess(
                $ownerId
            )
        ) {
            return true;
        }

        /*
         * ====================================================
         * EXISTING ACCESS LOGIC
         * ====================================================
         *
         * For all normal Owners, keep the existing
         * owner_service_access behavior exactly as before.
         */
        if (
            !isset($conn) ||
            !($conn instanceof mysqli)
        ) {
            return false;
        }

        $stmt =
            $conn->prepare("
                SELECT is_enabled
                FROM owner_service_access
                WHERE owner_id = ?
                  AND service_code = ?
                LIMIT 1
            ");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param(
            "is",
            $ownerId,
            $serviceCode
        );

        $stmt->execute();

        $result =
            $stmt->get_result();

        $row =
            $result
                ? $result->fetch_assoc()
                : null;

        $stmt->close();

        return (
            !empty($row)
            &&
            (int)$row['is_enabled'] === 1
        );
    }
}


/* ============================================================
   GET ALL OWNER ACCESS
============================================================ */

if (!function_exists('getOwnerAccess')) {
    function getOwnerAccess(
        ?int $ownerId = null
    ): array {

        global $conn;

        if ($ownerId === null) {
            $ownerId =
                getCurrentOwnerId();
        }

        $ownerId =
            (int)$ownerId;

        $services =
            getOwnerServiceList();

        /*
         * Default everything to locked.
         */
        $access = [];

        foreach (
            $services as $code => $service
        ) {
            $access[$code] = false;
        }

        if ($ownerId <= 0) {
            return $access;
        }

        /*
         * ====================================================
         * DGS MASTER UNLOCK
         * ====================================================
         *
         * Populate the EXISTING access array as unlocked.
         * This means current sidebar code and current Owner
         * pages can continue using the same access mechanism.
         */
        if (
            ownerDgsMasterAccess(
                $ownerId
            )
        ) {

            foreach (
                $services as $code => $service
            ) {
                $access[$code] = true;
            }

            return $access;
        }

        /*
         * ====================================================
         * EXISTING DATABASE ACCESS
         * ====================================================
         */
        if (
            !isset($conn) ||
            !($conn instanceof mysqli)
        ) {
            return $access;
        }

        $stmt =
            $conn->prepare("
                SELECT
                    service_code,
                    is_enabled
                FROM owner_service_access
                WHERE owner_id = ?
            ");

        if (!$stmt) {
            return $access;
        }

        $stmt->bind_param(
            "i",
            $ownerId
        );

        $stmt->execute();

        $result =
            $stmt->get_result();

        while (
            $row =
            $result->fetch_assoc()
        ) {

            $code =
                trim(
                    (string)(
                        $row['service_code']
                        ??
                        ''
                    )
                );

            if (
                array_key_exists(
                    $code,
                    $access
                )
            ) {

                $access[$code] =
                    (
                        (int)(
                            $row['is_enabled']
                            ??
                            0
                        ) === 1
                    );
            }
        }

        $stmt->close();

        return $access;
    }
}


/* ============================================================
   REQUIRE OWNER SERVICE ACCESS
============================================================ */

if (!function_exists('requireOwnerAccess')) {
    function requireOwnerAccess(
        string $serviceCode,
        string $redirect = 'dashboard.php'
    ): void {

        /*
         * Make sure logged-in user is Owner.
         */
        if (
            empty($_SESSION['uid'])
            &&
            empty($_SESSION['user_id'])
        ) {

            header(
                "Location: " .
                (
                    function_exists('_base_url')
                    ? _base_url()
                    : '../'
                ) .
                "login.php"
            );

            exit;
        }

        if (
            empty($_SESSION['role'])
            ||
            $_SESSION['role'] !== 'owner'
        ) {

            header(
                "Location: " .
                (
                    function_exists('_base_url')
                    ? _base_url()
                    : '../'
                ) .
                "login.php"
            );

            exit;
        }

        /*
         * ====================================================
         * DGS MASTER UNLOCK
         * ====================================================
         *
         * ownerCanAccess() already performs this check.
         * Therefore existing protected pages do not need
         * individual modifications.
         */
        if (
            ownerCanAccess(
                $serviceCode
            )
        ) {
            return;
        }

        /*
         * Existing redirect behavior preserved.
         */
        header(
            "Location: " .
            $redirect
        );

        exit;
    }
}


/* ============================================================
   ENABLE OWNER SERVICE
============================================================ */

if (!function_exists('enableOwnerService')) {
    function enableOwnerService(
        int $ownerId,
        string $serviceCode,
        ?int $adminId = null
    ): bool {

        global $conn;

        $services =
            getOwnerServiceList();

        if (
            $ownerId <= 0
            ||
            !array_key_exists(
                $serviceCode,
                $services
            )
        ) {
            return false;
        }

        if (
            !isset($conn) ||
            !($conn instanceof mysqli)
        ) {
            return false;
        }

        $stmt =
            $conn->prepare("
                INSERT INTO owner_service_access
                (
                    owner_id,
                    service_code,
                    is_enabled,
                    enabled_by,
                    enabled_at
                )
                VALUES
                (
                    ?,
                    ?,
                    1,
                    ?,
                    NOW()
                )
                ON DUPLICATE KEY UPDATE
                    is_enabled = 1,
                    enabled_by = VALUES(enabled_by),
                    enabled_at = NOW()
            ");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param(
            "isi",
            $ownerId,
            $serviceCode,
            $adminId
        );

        $success =
            $stmt->execute();

        $stmt->close();

        return $success;
    }
}


/* ============================================================
   DISABLE OWNER SERVICE
============================================================ */

if (!function_exists('disableOwnerService')) {
    function disableOwnerService(
        int $ownerId,
        string $serviceCode
    ): bool {

        global $conn;

        $services =
            getOwnerServiceList();

        if (
            $ownerId <= 0
            ||
            !array_key_exists(
                $serviceCode,
                $services
            )
        ) {
            return false;
        }

        if (
            !isset($conn) ||
            !($conn instanceof mysqli)
        ) {
            return false;
        }

        $stmt =
            $conn->prepare("
                INSERT INTO owner_service_access
                (
                    owner_id,
                    service_code,
                    is_enabled,
                    enabled_by,
                    enabled_at
                )
                VALUES
                (
                    ?,
                    ?,
                    0,
                    NULL,
                    NULL
                )
                ON DUPLICATE KEY UPDATE
                    is_enabled = 0,
                    enabled_by = NULL,
                    enabled_at = NULL
            ");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param(
            "is",
            $ownerId,
            $serviceCode
        );

        $success =
            $stmt->execute();

        $stmt->close();

        return $success;
    }
}


/* ============================================================
   INITIALIZE OWNER ACCESS
============================================================ */

if (!function_exists('initializeOwnerAccess')) {
    function initializeOwnerAccess(
        int $ownerId
    ): bool {

        global $conn;

        if ($ownerId <= 0) {
            return false;
        }

        if (
            !isset($conn) ||
            !($conn instanceof mysqli)
        ) {
            return false;
        }

        $services =
            getOwnerServiceList();

        $stmt =
            $conn->prepare("
                INSERT IGNORE INTO owner_service_access
                (
                    owner_id,
                    service_code,
                    is_enabled
                )
                VALUES
                (
                    ?,
                    ?,
                    0
                )
            ");

        if (!$stmt) {
            return false;
        }

        foreach (
            $services as $code => $service
        ) {

            $stmt->bind_param(
                "is",
                $ownerId,
                $code
            );

            $stmt->execute();
        }

        $stmt->close();

        return true;
    }
}
