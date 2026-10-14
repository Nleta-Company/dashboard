<?php

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

/**
 * ============================================================
 * NLETA OWNER SIDEBAR
 * ============================================================
 *
 * OWNER SERVICE ACCESS + ACTION NOTIFICATIONS
 *
 * Notifications:
 * 1. Offers
 * 2. SAF
 * 3. Checklist Approvals
 * 4. Fortnightly Check
 * 5. Callback for Approval
 *
 * DGS / DIGITAL GOVERNANCE SUPPORT:
 * ------------------------------------------------------------
 * DGS does NOT create a new sidebar item.
 *
 * Once DGS is activated by Admin and at least one active
 * DGS asset access row exists, the existing Owner sidebar
 * sections are unlocked for this Owner.
 *
 * Existing owner_service_access workflow is preserved when
 * DGS is not active.
 *
 * IMPORTANT:
 * - PAT / SAT / SCI workflow untouched.
 * - Inspector workflow untouched.
 * - My Lifts workflow untouched.
 * - Existing notifications preserved.
 *
 * ============================================================
 */


/* ============================================================
   START SESSION SAFELY
============================================================ */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}


/* ============================================================
   OWNER ACCESS CONTROLLER
============================================================ */

require_once __DIR__ . '/../config/owner_access.php';


/* ============================================================
   DGS ACCESS CONTROLLER
   ------------------------------------------------------------
   This file contains:
       ownerHasActiveDgs()
       getOwnerDgsAssetIds()
       getOwnerDgsAssets()
       etc.
============================================================ */

$dgsAccessFile =
    __DIR__ . '/../config/dgs_access.php';

if (is_file($dgsAccessFile)) {
    require_once $dgsAccessFile;
}


/* ============================================================
   CURRENT OWNER ID
============================================================ */

$uid = (int)($_SESSION['uid'] ?? 0);

if ($uid <= 0) {
    $uid = (int)($_SESSION['user_id'] ?? 0);
}


/* ============================================================
   OWNER ACCESS
============================================================ */

$ownerAccess = [];

if (
    $uid > 0 &&
    function_exists('getOwnerAccess')
) {

    $ownerAccess =
        getOwnerAccess($uid);
}


/* ============================================================
   KNOWN SERVICES
============================================================ */

$knownServices = [];

if (
    function_exists(
        'getOwnerServiceList'
    )
) {

    $knownServices =
        getOwnerServiceList();
}


/* ============================================================
   DIRECT DATABASE ACCESS
   ------------------------------------------------------------
   Database value is treated as final source of truth.
============================================================ */

$directOwnerAccess = [];

foreach (
    $knownServices
    as $serviceCode => $serviceInfo
) {

    $directOwnerAccess[
        $serviceCode
    ] = false;
}


if (
    $uid > 0 &&
    isset($conn) &&
    $conn instanceof mysqli
) {

    $accessStmt =
        $conn->prepare("
            SELECT
                service_code,
                is_enabled
            FROM owner_service_access
            WHERE owner_id = ?
        ");

    if ($accessStmt) {

        $accessStmt->bind_param(
            "i",
            $uid
        );

        if ($accessStmt->execute()) {

            $accessResult =
                $accessStmt->get_result();

            while (
                $accessRow =
                $accessResult->fetch_assoc()
            ) {

                $code =
                    trim(
                        (string)(
                            $accessRow[
                                'service_code'
                            ]
                            ??
                            ''
                        )
                    );

                if (
                    $code !== '' &&
                    array_key_exists(
                        $code,
                        $directOwnerAccess
                    )
                ) {

                    $directOwnerAccess[
                        $code
                    ] =
                        (
                            (int)(
                                $accessRow[
                                    'is_enabled'
                                ]
                                ??
                                0
                            ) === 1
                        );
                }
            }
        }

        $accessStmt->close();
    }
}


/* ============================================================
   COMBINE ACCESS
============================================================ */

foreach (
    $knownServices
    as $serviceCode => $serviceInfo
) {

    if (
        array_key_exists(
            $serviceCode,
            $directOwnerAccess
        )
    ) {

        $ownerAccess[
            $serviceCode
        ] =
            $directOwnerAccess[
                $serviceCode
            ];
    }
}


/* ============================================================
   HELPER
============================================================ */

if (
    !function_exists(
        'ownerSidebarUnlocked'
    )
) {

    function ownerSidebarUnlocked(
        string $serviceCode,
        array $ownerAccess
    ): bool {

        return (
            isset(
                $ownerAccess[
                    $serviceCode
                ]
            )
            &&
            $ownerAccess[
                $serviceCode
            ] === true
        );
    }
}


/* ============================================================
   CURRENT PAGE
============================================================ */

$current =
    basename(
        $_SERVER['PHP_SELF']
    );


/* ============================================================
   DGS ACTIVATION STATUS
   ------------------------------------------------------------
   IMPORTANT:
   DGS is NOT read from owner_service_access.
============================================================ */

$ownerHasActiveDgs = false;

$dgsAssetIds = [];

if (
    $uid > 0 &&
    isset($conn) &&
    $conn instanceof mysqli
) {

    /*
     * Preferred controller.
     */
    if (
        function_exists(
            'ownerHasActiveDgs'
        )
    ) {

        $ownerHasActiveDgs =
            ownerHasActiveDgs(
                $conn,
                $uid
            );
    }


    /*
     * Fallback direct query.
     *
     * This keeps the sidebar safe even if the helper file
     * has not yet been copied correctly.
     */
    if (!$ownerHasActiveDgs) {

        $dgsCheckStmt =
            $conn->prepare("
                SELECT
                    s.id

                FROM saf_contracts s

                INNER JOIN dgs_asset_access daa
                    ON daa.saf_id = s.id

                WHERE
                    s.owner_id = ?

                    AND s.status = 'Accepted'

                    AND s.dgs_activation_status = 'Active'

                    AND daa.is_active = 1

                    AND UPPER(
                        TRIM(
                            COALESCE(
                                s.service_code,
                                ''
                            )
                        )
                    ) IN (
                        'DGS',
                        'DG',
                        'DIGITAL_GOVERNANCE'
                    )

                LIMIT 1
            ");

        if ($dgsCheckStmt) {

            $dgsCheckStmt->bind_param(
                'i',
                $uid
            );

            if (
                $dgsCheckStmt->execute()
            ) {

                $dgsRow =
                    $dgsCheckStmt
                    ->get_result()
                    ->fetch_assoc();

                $ownerHasActiveDgs =
                    !empty($dgsRow);
            }

            $dgsCheckStmt->close();
        }
    }


    /*
     * Load active DGS asset IDs.
     */
    if (
        $ownerHasActiveDgs &&
        function_exists(
            'getOwnerDgsAssetIds'
        )
    ) {

        $dgsAssetIds =
            getOwnerDgsAssetIds(
                $conn,
                $uid
            );
    }
}


/*
 * Final safety:
 *
 * DGS is active only when there is at least one asset
 * with active DGS access.
 */
$ownerHasActiveDgs =
    $ownerHasActiveDgs &&
    !empty($dgsAssetIds);


/* ============================================================
   NOTIFICATION COUNTS
============================================================ */

$ownerOfferCount       = 0;
$pendingCount          = 0;
$pendingMaintenanceCount = 0;
$callbackApproveCount  = 0;
$fortnightlyBadge      = 0;
$ownerSafCount         = 0;
$ownerSafTotal         = 0;
$ownerPsgaCount        = 0;


/* ============================================================
   OFFERS SENT TO OWNER
============================================================ */

if (
    $uid > 0 &&
    isset($conn) &&
    $conn instanceof mysqli
) {

    $offerCountStmt =
        $conn->prepare("
            SELECT
                COUNT(DISTINCT o.id) AS total

            FROM nleta_offer_master o

            INNER JOIN service_requests sr
                ON sr.id = o.request_id

            WHERE
                sr.owner_id = ?

                AND o.status = 'Sent'
        ");

    if ($offerCountStmt) {

        $offerCountStmt->bind_param(
            "i",
            $uid
        );

        if (
            $offerCountStmt->execute()
        ) {

            $offerCountRow =
                $offerCountStmt
                ->get_result()
                ->fetch_assoc();

            $ownerOfferCount =
                (int)(
                    $offerCountRow[
                        'total'
                    ]
                    ??
                    0
                );
        }

        $offerCountStmt->close();
    }
}


/* ============================================================
   CHECKLIST APPROVALS
============================================================ */

if (
    $uid > 0 &&
    isset($conn) &&
    $conn instanceof mysqli
) {

    $approvalStmt =
        $conn->prepare("
            SELECT
                COUNT(*) AS total

            FROM maintenance_jobs mj

            LEFT JOIN assets a
                ON a.id = mj.asset_id

            WHERE
                LOWER(
                    TRIM(
                        COALESCE(
                            mj.status,
                            ''
                        )
                    )
                ) = 'pending_owner'

                /*
                 * Checklist Approvals is ONLY for SAF
                 * inspection/checklist workflow.
                 *
                 * Normal Technician / AMC maintenance
                 * checklists are handled separately under
                 * Maintenance Checklist.
                 */
                AND LOWER(
                    TRIM(
                        COALESCE(
                            mj.job_type,
                            ''
                        )
                    )
                ) = 'saf_inspection'

                AND (
                    mj.owner_id = ?
                    OR a.owner_id = ?
                )
        ");

    if ($approvalStmt) {

        $approvalStmt->bind_param(
            "ii",
            $uid,
            $uid
        );

        if (
            $approvalStmt->execute()
        ) {

            $approvalRow =
                $approvalStmt
                ->get_result()
                ->fetch_assoc();

            $pendingCount =
                (int)(
                    $approvalRow[
                        'total'
                    ]
                    ??
                    0
                );
        }

        $approvalStmt->close();
    }
}



/* ============================================================
   NORMAL MAINTENANCE CHECKLISTS
   ------------------------------------------------------------
   Separate from SAF / PAT / SAT / SCI checklist approvals.
============================================================ */

if (
    $uid > 0 &&
    isset($conn) &&
    $conn instanceof mysqli
) {

    $maintenanceChecklistStmt =
        $conn->prepare("
            SELECT COUNT(*) AS total
            FROM maintenance_jobs mj
            INNER JOIN assets a
                ON a.id = mj.asset_id
            WHERE
                a.owner_id = ?
                AND LOWER(TRIM(COALESCE(mj.status, ''))) = 'pending_owner'
                AND LOWER(TRIM(COALESCE(mj.job_type, ''))) <> 'saf_inspection'
        ");

    if ($maintenanceChecklistStmt) {

        $maintenanceChecklistStmt->bind_param("i", $uid);

        if ($maintenanceChecklistStmt->execute()) {

            $maintenanceChecklistRow =
                $maintenanceChecklistStmt
                ->get_result()
                ->fetch_assoc();

            $pendingMaintenanceCount =
                (int)($maintenanceChecklistRow['total'] ?? 0);
        }

        $maintenanceChecklistStmt->close();
    }
}


/* ============================================================
   CALLBACKS PENDING OWNER APPROVAL
============================================================ */


if (
    $uid > 0 &&
    isset($conn) &&
    $conn instanceof mysqli
) {

    $callbackStmt =
        $conn->prepare("
            SELECT
                COUNT(*) AS total

            FROM callbacks c

            LEFT JOIN assets a
                ON a.id = c.asset_id

            WHERE
                (
                    a.owner_id = ?

                    OR c.asset_id = 0
                )

                AND c.status = 'closed'
        ");

    if ($callbackStmt) {

        $callbackStmt->bind_param(
            "i",
            $uid
        );

        if (
            $callbackStmt->execute()
        ) {

            $callbackRow =
                $callbackStmt
                ->get_result()
                ->fetch_assoc();

            $callbackApproveCount =
                (int)(
                    $callbackRow[
                        'total'
                    ]
                    ??
                    0
                );
        }

        $callbackStmt->close();
    }
}


/* ============================================================
   FORTNIGHTLY CHECK
============================================================ */

if (
    $uid > 0 &&
    isset($conn) &&
    $conn instanceof mysqli
) {

    $fortnightlyStmt =
        $conn->prepare("
            SELECT
                COUNT(*) AS total

            FROM callbacks

            WHERE
                type = 'fortnightly'

                AND technician_id = (
                    SELECT
                        assigned_technician_id

                    FROM users

                    WHERE id = ?

                    LIMIT 1
                )

                AND status IN (
                    'open',
                    'in_progress'
                )
        ");

    if ($fortnightlyStmt) {

        $fortnightlyStmt->bind_param(
            "i",
            $uid
        );

        if (
            $fortnightlyStmt->execute()
        ) {

            $fortnightlyRow =
                $fortnightlyStmt
                ->get_result()
                ->fetch_assoc();

            $fortnightlyCount =
                (int)(
                    $fortnightlyRow[
                        'total'
                    ]
                    ??
                    0
                );

            if (
                $fortnightlyCount > 0
            ) {

                $fortnightlyBadge = 1;
            }
        }

        $fortnightlyStmt->close();
    }
}


/* ============================================================
   PSGA AGREEMENTS
   ------------------------------------------------------------
   OWNER ACTION STATE:

   Admin sends PSGA to Owner
          ↓
   contracts.Status = 'Pending Owner Confirmation'
          ↓
   Owner sees PSGA in Offers
          ↓
   Owner Accept / Reject
          ↓
   Badge disappears after Owner action

   IMPORTANT:
   - Count only this Owner's PSGA records.
   - Do not count Pending Admin Review.
   - Do not count Pending Admin Confirmation.
   - PSGA is stored permanently in contracts.
============================================================ */

if (
    $uid > 0 &&
    isset($conn) &&
    $conn instanceof mysqli
) {

    $psgaOwnerStmt =
        $conn->prepare("
            SELECT
                COUNT(*) AS total

            FROM contracts

            WHERE
                owner_id = ?

                AND Status = 'Pending Owner Confirmation'

                AND PSGA_No IS NOT NULL

                AND TRIM(PSGA_No) <> ''
        ");

    if ($psgaOwnerStmt) {

        $psgaOwnerStmt->bind_param(
            "i",
            $uid
        );

        if (
            $psgaOwnerStmt->execute()
        ) {

            $psgaOwnerRow =
                $psgaOwnerStmt
                ->get_result()
                ->fetch_assoc();

            $ownerPsgaCount =
                (int)(
                    $psgaOwnerRow[
                        'total'
                    ]
                    ??
                    0
                );
        }

        $psgaOwnerStmt->close();
    }
}


/* ============================================================
   SAF CONTRACTS
============================================================ */

if (
    $uid > 0 &&
    isset($conn) &&
    $conn instanceof mysqli
) {

    $safCountStmt =
        $conn->prepare("
            SELECT

                COUNT(*) AS total,

                SUM(
                    CASE
                        WHEN status =
                            'Pending Owner Acceptance'
                        THEN 1
                        ELSE 0
                    END
                ) AS pending_total

            FROM saf_contracts

            WHERE
                owner_id = ?

                AND status IN (
                    'Pending Owner Acceptance',
                    'Accepted'
                )
        ");

    if ($safCountStmt) {

        $safCountStmt->bind_param(
            "i",
            $uid
        );

        if (
            $safCountStmt->execute()
        ) {

            $safRow =
                $safCountStmt
                ->get_result()
                ->fetch_assoc();

            $ownerSafTotal =
                (int)(
                    $safRow[
                        'total'
                    ]
                    ??
                    0
                );

            $ownerSafCount =
                (int)(
                    $safRow[
                        'pending_total'
                    ]
                    ??
                    0
                );
        }

        $safCountStmt->close();
    }
}


/* ============================================================
   PERMANENT NOTIFICATION UNLOCK
============================================================ */

$permanentNotificationUnlock = [];


$unlockOwnerServicePermanently = function (
    string $serviceCode
) use (
    $conn,
    $uid,
    &$ownerAccess,
    &$permanentNotificationUnlock
): void {

    if (
        $uid <= 0 ||
        $serviceCode === ''
    ) {
        return;
    }


    if (
        !function_exists(
            'getOwnerServiceList'
        )
    ) {
        return;
    }


    $known =
        getOwnerServiceList();


    if (
        !array_key_exists(
            $serviceCode,
            $known
        )
    ) {
        return;
    }


    /*
     * Unlock immediately for this request.
     */
    $permanentNotificationUnlock[
        $serviceCode
    ] = true;


    $ownerAccess[
        $serviceCode
    ] = true;


    /*
     * Persist unlock.
     */
    if (
        !isset($conn) ||
        !($conn instanceof mysqli)
    ) {
        return;
    }


    $stmt =
        $conn->prepare("
            INSERT INTO owner_service_access
            (
                owner_id,
                service_code,
                is_enabled
            )

            VALUES
            (
                ?,
                ?,
                1
            )

            ON DUPLICATE KEY UPDATE
                is_enabled = 1
        ");


    if (!$stmt) {
        return;
    }


    $stmt->bind_param(
        "is",
        $uid,
        $serviceCode
    );


    @$stmt->execute();

    $stmt->close();
};


/* ============================================================
   AUTO UNLOCK FROM NOTIFICATIONS
============================================================ */


/* OFFERS */

if ($ownerOfferCount > 0) {

    $unlockOwnerServicePermanently(
        'offers'
    );
}


/* CHECKLIST APPROVALS */

if ($pendingCount > 0) {

    $unlockOwnerServicePermanently(
        'approvals'
    );
}


/* NORMAL MAINTENANCE CHECKLIST */

if ($pendingMaintenanceCount > 0) {

    $permanentNotificationUnlock['maintenance_checklist'] = true;
}


/* CALLBACK APPROVAL */

if ($callbackApproveCount > 0) {

    $unlockOwnerServicePermanently(
        'callback_approval'
    );
}


/* FORTNIGHTLY */

if ($fortnightlyBadge > 0) {

    $unlockOwnerServicePermanently(
        'fortnightly'
    );
}


/* SAF */

if ($ownerSafTotal > 0) {

    $unlockOwnerServicePermanently(
        'SAF_CONTRACT'
    );
}


/* ============================================================
   LOCKED MENU HELPER
============================================================ */

if (
    !function_exists(
        'lockedOwnerMenu'
    )
) {

    function lockedOwnerMenu(
        string $label,
        string $icon
    ): void {

        ?>

        <a
            href="javascript:void(0);"
            class="owner-locked-menu"
            onclick="ownerAccessLocked(); return false;"
            title="Access not enabled"
            aria-disabled="true"
        >

            <i
                class="bi <?= htmlspecialchars(
                    $icon,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>"
            ></i>

            <span class="owner-menu-label">
                <?= htmlspecialchars(
                    $label,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </span>

            <i
                class="bi bi-lock-fill owner-lock-icon"
                title="Locked"
            ></i>

        </a>

        <?php
    }
}


/* ============================================================
   CSS
============================================================ */

?>

<style>

/* ============================================================
   OWNER SIDEBAR
============================================================ */

.sidebar {

    height: calc(100vh - 70px);

    max-height: calc(100vh - 70px);

    overflow-y: auto;

    overflow-x: hidden;

    scrollbar-width: thin;

    box-sizing: border-box;
}


/* ============================================================
   SIDEBAR LINK NORMALIZATION
============================================================ */

.sidebar > a {

    width: 100%;

    min-height: 50px;

    height: 50px;

    box-sizing: border-box;

    display: flex;

    align-items: center;

    gap: 10px;

    padding: 0 16px;

    margin: 0;

    flex-shrink: 0;

    text-decoration: none;

    transition:
        background 0.15s ease,
        color 0.15s ease;

}


/* ============================================================
   ICON
============================================================ */

.sidebar > a > i:first-child {

    width: 20px;

    min-width: 20px;

    text-align: center;

    flex-shrink: 0;

}


/* ============================================================
   LABEL
============================================================ */

.sidebar .owner-menu-label {

    flex: 1;

    min-width: 0;

    overflow: hidden;

    text-overflow: ellipsis;

    white-space: nowrap;

}


/* ============================================================
   ACTIVE ITEM
============================================================ */

.sidebar > a.active {

    background: rgba(
        0,
        0,
        0,
        0.25
    ) !important;

    color: #ffffff !important;

    box-shadow:
        inset 4px 0 0 rgba(
            255,
            255,
            255,
            0.90
        );

}


.sidebar > a.active:hover {

    background: rgba(
        0,
        0,
        0,
        0.32
    ) !important;

}


/* ============================================================
   NOTIFICATION BADGE
============================================================ */

.sidebar .owner-notification-badge {

    min-width: 22px;

    height: 22px;

    padding: 2px 6px;

    margin-left: 5px;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    flex-shrink: 0;

    border-radius: 50%;

    background: #dc3545;

    color: #ffffff;

    font-size: 12px;

    font-weight: 700;

    line-height: 1;

}


/* ============================================================
   WARNING BADGE
============================================================ */

.sidebar .owner-warning-badge {

    min-width: 22px;

    height: 22px;

    padding: 2px 6px;

    margin-left: 5px;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    flex-shrink: 0;

    border-radius: 50%;

    background: #ffc107;

    color: #212529;

    font-size: 12px;

    font-weight: 700;

    line-height: 1;

}


/* ============================================================
   LOCKED ITEM
============================================================ */

.sidebar .owner-locked-menu {

    opacity: 0.55;

    cursor: not-allowed;

    position: relative;

}


.sidebar .owner-locked-menu:hover {

    background: rgba(
        255,
        255,
        255,
        0.06
    );

    color: #ffffff;

}


/* ============================================================
   LOCK ICON
============================================================ */

.sidebar .owner-lock-icon {

    margin-left: auto;

    font-size: 13px;

    opacity: 0.90;

    flex-shrink: 0;

}


/* ============================================================
   UNLOCK ICON
============================================================ */

.sidebar .owner-unlock-icon {

    margin-left: 5px;

    font-size: 11px;

    opacity: 0.45;

    flex-shrink: 0;

}


/* ============================================================
   DGS ACTIVE BADGE
============================================================ */

.sidebar .owner-dgs-active-badge {

    min-width: 22px;

    height: 22px;

    padding: 2px 6px;

    margin-left: 5px;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    flex-shrink: 0;

    border-radius: 50%;

    background: #198754;

    color: #ffffff;

    font-size: 11px;

    font-weight: 700;

    line-height: 1;

}


/* ============================================================
   SIDEBAR SCROLLBAR
============================================================ */

.sidebar::-webkit-scrollbar {

    width: 6px;

}


.sidebar::-webkit-scrollbar-thumb {

    background: rgba(
        255,
        255,
        255,
        0.25
    );

    border-radius: 10px;

}


.sidebar::-webkit-scrollbar-track {

    background: transparent;

}


/* ============================================================
   ACCESS TOAST
============================================================ */

.owner-access-toast {

    position: fixed;

    top: 85px;

    right: 25px;

    z-index: 99999;

    background: #212529;

    color: #ffffff;

    padding: 13px 18px;

    border-radius: 8px;

    box-shadow:
        0 5px 20px rgba(
            0,
            0,
            0,
            0.25
        );

    display: none;

    font-size: 14px;

}


/* ============================================================
   MOBILE
============================================================ */

@media (max-width: 768px) {

    .sidebar {

        height: calc(100vh - 55px);

        max-height: calc(100vh - 55px);

    }


    .sidebar > a {

        height: 48px;

        min-height: 48px;

    }

}


/* ============================================================
   SMALL HEIGHT SCREEN
============================================================ */

@media (max-height: 700px) {

    .sidebar > a {

        height: 46px;

        min-height: 46px;

    }

}

</style>


<div class="sidebar">


    <!-- =====================================================
         DASHBOARD
    ====================================================== -->

    <a
        href="dashboard.php"
        class="<?= $current === 'dashboard.php' ? 'active' : '' ?>"
    >

        <i class="bi bi-speedometer2"></i>

        <span class="owner-menu-label">
            Dashboard
        </span>

    </a>


    <!-- =====================================================
         SERVICE SELECTION
         ALWAYS AVAILABLE
    ====================================================== -->

    <a
        href="service-selection.php"
        class="<?= $current === 'service-selection.php' ? 'active' : '' ?>"
    >

        <i class="bi bi-clipboard-plus"></i>

        <span class="owner-menu-label">
            Service Selection
        </span>

    </a>


    <!-- =====================================================
         OFFERS
    ====================================================== -->

    <?php if (
        $ownerHasActiveDgs
        ||
        ownerSidebarUnlocked(
            'offers',
            $ownerAccess
        )
        ||
        $ownerPsgaCount > 0
        ||
        !empty(
            $permanentNotificationUnlock[
                'offers'
            ]
        )
    ): ?>

        <a
            href="offers.php"
            class="<?= $current === 'offers.php' ? 'active' : '' ?>"
        >

            <i class="bi bi-file-earmark-text"></i>

            <span class="owner-menu-label">
                Offers
            </span>

            <?php if (
                $ownerOfferCount > 0
            ): ?>

                <span
                    class="owner-notification-badge"
                    title="<?= $ownerOfferCount ?> offer(s) waiting for Owner action"
                >
                    <?= $ownerOfferCount ?>
                </span>

            <?php endif; ?>

            <?php if (
                $ownerPsgaCount > 0
            ): ?>

                <span
                    class="owner-notification-badge"
                    aria-label="<?= $ownerPsgaCount ?> PSGA agreement(s) waiting for Owner action"
                    title="<?= $ownerPsgaCount ?> PSGA agreement(s) waiting for Owner action"
                >
                    <?= (int)$ownerPsgaCount ?>
                </span>

            <?php endif; ?>

            <i
                class="bi bi-unlock-fill owner-unlock-icon"
                title="Access enabled"
            ></i>

        </a>

    <?php else: ?>

        <?php
        lockedOwnerMenu(
            'Offers',
            'bi-file-earmark-text'
        );
        ?>

    <?php endif; ?>


    <!-- =====================================================
         SAF CONTRACT
    ====================================================== -->

    <?php if (
        $ownerHasActiveDgs
        ||
        $ownerSafTotal > 0
    ): ?>

        <a
            href="./saf-list.php"
            class="<?= (
                $current === 'saf-list.php' ||
                $current === 'saf-view.php'
            ) ? 'active' : '' ?>"
        >

            <i class="bi bi-file-earmark-check"></i>

            <span class="owner-menu-label">
                Service Activation Forms (SAF)
            </span>

            <?php if (
                $ownerSafCount > 0
            ): ?>

                <span
                    class="owner-notification-badge"
                    title="<?= $ownerSafCount ?> SAF(s) waiting for Owner acceptance"
                >
                    <?= $ownerSafCount ?>
                </span>

            <?php endif; ?>

            <i
                class="bi bi-unlock-fill owner-unlock-icon"
                title="SAF available"
            ></i>

        </a>

    <?php else: ?>

        <a
            href="javascript:void(0);"
            class="owner-locked-menu"
            onclick="ownerSafNotAvailable(); return false;"
            title="No SAF available"
            aria-disabled="true"
        >

            <i class="bi bi-file-earmark-check"></i>

            <span class="owner-menu-label">
                SAF - Service Activation Form
            </span>

            <i
                class="bi bi-lock-fill owner-lock-icon"
                title="No SAF available"
            ></i>

        </a>

    <?php endif; ?>


    <!-- =====================================================
         MY LIFTS
         -----------------------------------------------------
         EXISTING LOGIC PRESERVED
    ====================================================== -->

    <?php if (
        $ownerHasActiveDgs
        ||
        ownerSidebarUnlocked(
            'assets',
            $ownerAccess
        )
    ): ?>

        <a
            href="assets.php"
            class="<?= (
                $current === 'assets.php'
            ) ? 'active' : '' ?>"
        >

            <i class="bi bi-building"></i>

            <span class="owner-menu-label">
                My Lifts
            </span>

            <i
                class="bi bi-unlock-fill owner-unlock-icon"
            ></i>

        </a>

    <?php else: ?>

        <?php
        lockedOwnerMenu(
            'My Lifts',
            'bi-building'
        );
        ?>

    <?php endif; ?>


    <!-- =====================================================
         REPORTED ISSUES
    ====================================================== -->

    <?php if (
        $ownerHasActiveDgs
        ||
        ownerSidebarUnlocked(
            'issues',
            $ownerAccess
        )
    ): ?>

        <a
            href="issues.php"
            class="<?= (
                $current === 'issues.php'
            ) ? 'active' : '' ?>"
        >

            <i class="bi bi-exclamation-triangle"></i>

            <span class="owner-menu-label">
                Reported Issues
            </span>

            <i
                class="bi bi-unlock-fill owner-unlock-icon"
            ></i>

        </a>

    <?php else: ?>

        <?php
        lockedOwnerMenu(
            'Reported Issues',
            'bi-exclamation-triangle'
        );
        ?>

    <?php endif; ?>


    <!-- =====================================================
         CHECKLIST APPROVALS
    ====================================================== -->

    <?php

    $checklistApprovalUnlocked =
        $ownerHasActiveDgs
        ||
        ownerSidebarUnlocked(
            'approvals',
            $ownerAccess
        )
        ||
        $pendingCount > 0
        ||
        !empty(
            $permanentNotificationUnlock[
                'approvals'
            ]
        );

    ?>


    <?php if (
        $checklistApprovalUnlocked
    ): ?>

        <a
            href="approvals.php"
            class="<?= (
                $current ===
                'approvals.php'
            ) ? 'active' : '' ?>"
        >

            <i class="bi bi-check2-circle"></i>

            <span class="owner-menu-label">
                Checklist Approvals
            </span>

            <?php if (
                $pendingCount > 0
            ): ?>

                <span
                    class="owner-notification-badge"
                    aria-label="<?= $pendingCount ?> checklist(s) waiting for Owner approval"
                    title="<?= $pendingCount ?> checklist(s) waiting for Owner approval"
                >
                    <?= (int)$pendingCount ?>
                </span>

            <?php endif; ?>

            <i
                class="bi bi-unlock-fill owner-unlock-icon"
            ></i>

        </a>

    <?php else: ?>

        <?php
        lockedOwnerMenu(
            'Checklist Approvals',
            'bi-check2-circle'
        );
        ?>

    <?php endif; ?>


    <!-- =====================================================
         NOC DETAILS
    ====================================================== -->

    <?php if (
        $ownerHasActiveDgs
        ||
        ownerSidebarUnlocked(
            'noc',
            $ownerAccess
        )
    ): ?>

        <a
            href="noc-registration.php"
            class="<?= (
                $current ===
                'noc-registration.php'
            ) ? 'active' : '' ?>"
        >

            <i class="bi bi-patch-check"></i>

            <span class="owner-menu-label">
                NOC Details
            </span>

            <i
                class="bi bi-unlock-fill owner-unlock-icon"
            ></i>

        </a>

    <?php else: ?>

        <?php
        lockedOwnerMenu(
            'NOC Details',
            'bi-patch-check'
        );
        ?>

    <?php endif; ?>


    <!-- =====================================================
         NOC HISTORY
    ====================================================== -->

    <?php if (
        $ownerHasActiveDgs
        ||
        ownerSidebarUnlocked(
            'noc_history',
            $ownerAccess
        )
    ): ?>

        <a
            href="noc-list.php"
            class="<?= (
                $current ===
                'noc-list.php'
            ) ? 'active' : '' ?>"
        >

            <i class="bi bi-journal-text"></i>

            <span class="owner-menu-label">
                NOC History
            </span>

            <i
                class="bi bi-unlock-fill owner-unlock-icon"
            ></i>

        </a>

    <?php else: ?>

        <?php
        lockedOwnerMenu(
            'NOC History',
            'bi-journal-text'
        );
        ?>

    <?php endif; ?>


    <!-- =====================================================
         CREATE CALLBACK
    ====================================================== -->

    <?php if (
        $ownerHasActiveDgs
        ||
        ownerSidebarUnlocked(
            'callback',
            $ownerAccess
        )
    ): ?>

        <a
            href="create-callback.php"
            class="<?= (
                $current ===
                'create-callback.php'
            ) ? 'active' : '' ?>"
        >

            <i class="bi bi-plus-circle"></i>

            <span class="owner-menu-label">
                Create Callback
            </span>

            <i
                class="bi bi-unlock-fill owner-unlock-icon"
            ></i>

        </a>

    <?php else: ?>

        <?php
        lockedOwnerMenu(
            'Create Callback',
            'bi-plus-circle'
        );
        ?>

    <?php endif; ?>


    <!-- =====================================================
         FORTNIGHTLY CHECK
    ====================================================== -->

    <?php if (
        $ownerHasActiveDgs
        ||
        ownerSidebarUnlocked(
            'fortnightly',
            $ownerAccess
        )
        ||
        !empty(
            $permanentNotificationUnlock[
                'fortnightly'
            ]
        )
    ): ?>

        <a
            href="fortnightly-check.php"
            class="<?= (
                $current ===
                'fortnightly-check.php'
            ) ? 'active' : '' ?>"
        >

            <i class="bi bi-clipboard2-check"></i>

            <span class="owner-menu-label">
                Fortnightly Check
            </span>

            <?php if (
                $fortnightlyBadge > 0
            ): ?>

                <span
                    class="owner-notification-badge"
                    title="Fortnightly Check requires action"
                >
                    !
                </span>

            <?php endif; ?>

            <i
                class="bi bi-unlock-fill owner-unlock-icon"
            ></i>

        </a>

    <?php else: ?>

        <?php
        lockedOwnerMenu(
            'Fortnightly Check',
            'bi-clipboard2-check'
        );
        ?>

    <?php endif; ?>


    <!-- =====================================================
         CALLBACK FOR APPROVAL
    ====================================================== -->

    <?php if (
        $ownerHasActiveDgs
        ||
        ownerSidebarUnlocked(
            'callback_approval',
            $ownerAccess
        )
        ||
        !empty(
            $permanentNotificationUnlock[
                'callback_approval'
            ]
        )
    ): ?>

        <a
            href="callbacks.php"
            class="<?= (
                $current ===
                'callbacks.php'
            ) ? 'active' : '' ?>"
        >

            <i class="bi bi-clipboard-check"></i>

            <span class="owner-menu-label">
                Callback for Approval
            </span>

            <?php if (
                $callbackApproveCount > 0
            ): ?>

                <span
                    class="owner-warning-badge"
                    title="<?= $callbackApproveCount ?> callback(s) waiting for approval"
                >
                    <?= $callbackApproveCount ?>
                </span>

            <?php endif; ?>

            <i
                class="bi bi-unlock-fill owner-unlock-icon"
            ></i>

        </a>

    <?php else: ?>

        <?php
        lockedOwnerMenu(
            'Callback for Approval',
            'bi-clipboard-check'
        );
        ?>

    <?php endif; ?>

    <!-- =====================================================
         MAINTENANCE CHECKLIST
         -----------------------------------------------------
         Separate Owner section for normal Technician/AMC
         maintenance checklists. SAF inspection checklists
         remain under Checklist Approvals.
    ====================================================== -->

    <?php

    $maintenanceChecklistUnlocked =
        $ownerHasActiveDgs
        ||
        !empty(
            $permanentNotificationUnlock[
                'maintenance_checklist'
            ]
        )
        ||
        ownerSidebarUnlocked(
            'maintenance_history',
            $ownerAccess
        );

    ?>

    <?php if ($maintenanceChecklistUnlocked): ?>

        <a
            href="maintenance-checklist.php"
            class="<?= (
                $current === 'maintenance-checklist.php'
            ) ? 'active' : '' ?>"
        >

            <i class="bi bi-clipboard2-check"></i>

            <span class="owner-menu-label">
                Maintenance Checklist
            </span>

            <?php if ($pendingMaintenanceCount > 0): ?>

                <span
                    class="owner-notification-badge"
                    title="<?= $pendingMaintenanceCount ?> maintenance checklist(s) waiting for Owner approval"
                >
                    <?= (int)$pendingMaintenanceCount ?>
                </span>

            <?php endif; ?>

            <i
                class="bi bi-unlock-fill owner-unlock-icon"
                title="Maintenance checklist available"
            ></i>

        </a>

    <?php else: ?>

        <?php
        lockedOwnerMenu(
            'Maintenance Checklist',
            'bi-clipboard2-check'
        );
        ?>

    <?php endif; ?>





    <!-- =====================================================
         MAINTENANCE HISTORY
    ====================================================== -->

    <?php if (
        $ownerHasActiveDgs
        ||
        ownerSidebarUnlocked(
            'maintenance_history',
            $ownerAccess
        )
    ): ?>

        <a
            href="maintenance-history.php"
            class="<?= (
                $current ===
                'maintenance-history.php'
            ) ? 'active' : '' ?>"
        >

            <i class="bi bi-tools"></i>

            <span class="owner-menu-label">
                Maintenance History
            </span>

            <i
                class="bi bi-unlock-fill owner-unlock-icon"
            ></i>

        </a>

    <?php else: ?>

        <?php
        lockedOwnerMenu(
            'Maintenance History',
            'bi-tools'
        );
        ?>

    <?php endif; ?>


</div>


<!-- ============================================================
     LOCKED ACCESS MESSAGE
============================================================ -->

<div
    id="ownerAccessToast"
    class="owner-access-toast"
>

    <i class="bi bi-lock-fill me-2"></i>

    This section is locked.
    Please contact Admin for access.

</div>


<script>

/* ============================================================
   LOCKED SECTION MESSAGE
============================================================ */

function ownerAccessLocked() {

    const toast =
        document.getElementById(
            'ownerAccessToast'
        );

    if (!toast) {
        return;
    }


    toast.innerHTML =
        '<i class="bi bi-lock-fill me-2"></i>' +
        'This section is locked. ' +
        'Please contact Admin for access.';


    toast.style.display =
        'block';


    clearTimeout(
        window.ownerAccessToastTimer
    );


    window.ownerAccessToastTimer =
        setTimeout(
            function () {

                toast.style.display =
                    'none';

            },
            3000
        );
}


/* ============================================================
   SAF NOT AVAILABLE
============================================================ */

function ownerSafNotAvailable() {

    const toast =
        document.getElementById(
            'ownerAccessToast'
        );

    if (!toast) {
        return;
    }


    toast.innerHTML =
        '<i class="bi bi-file-earmark-check me-2"></i>' +
        'No SAF is currently available for your account.';


    toast.style.display =
        'block';


    clearTimeout(
        window.ownerSafToastTimer
    );


    window.ownerSafToastTimer =
        setTimeout(
            function () {

                toast.style.display =
                    'none';


                toast.innerHTML =
                    '<i class="bi bi-lock-fill me-2"></i>' +
                    'This section is locked. ' +
                    'Please contact Admin for access.';

            },
            3000
        );
}


/* ============================================================
   DGS NOT ACTIVE
============================================================ */

function ownerDgsNotAvailable() {

    const toast =
        document.getElementById(
            'ownerAccessToast'
        );

    if (!toast) {
        return;
    }


    toast.innerHTML =
        '<i class="bi bi-shield-lock-fill me-2"></i>' +
        'Governance Services are not active yet. ' +
        'Please wait for Admin activation.';


    toast.style.display =
        'block';


    clearTimeout(
        window.ownerDgsToastTimer
    );


    window.ownerDgsToastTimer =
        setTimeout(
            function () {

                toast.style.display =
                    'none';


                toast.innerHTML =
                    '<i class="bi bi-lock-fill me-2"></i>' +
                    'This section is locked. ' +
                    'Please contact Admin for access.';

            },
            3500
        );
}

</script>