<?php

/* =========================================================
   ERROR REPORTING
   Keep enabled while debugging.
   Once everything works, you can disable these 3 lines.
========================================================= */

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);


/* =========================================================
   CONFIG
========================================================= */

include('../config/db.php');
include('../config/auth.php');
require_once '../config/security.php';

require_role('admin');


/* =========================================================
   ASSET ID
========================================================= */

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {

    $_SESSION['error'] = 'Invalid asset ID.';

    header("Location: assets.php");
    exit;
}


/* =========================================================
   GET ASSET
========================================================= */

$stmt = $conn->prepare("
    SELECT
        id,
        asset_name,
        location,
        asset_type,
        category,
        owner_id,
        maintenance_interval,
        maintenance_start_date
    FROM assets
    WHERE id = ?
    LIMIT 1
");

if (!$stmt) {

    die(
        "Asset query prepare error: " .
        $conn->error
    );
}

$stmt->bind_param("i", $id);

if (!$stmt->execute()) {

    die(
        "Asset query execute error: " .
        $stmt->error
    );
}

$result = $stmt->get_result();

$asset = $result->fetch_assoc();

$stmt->close();


/* =========================================================
   ASSET NOT FOUND
========================================================= */

if (!$asset) {

    $_SESSION['error'] =
        'Asset not found.';

    header("Location: assets.php");
    exit;
}


/* =========================================================
   MAINTENANCE START DATE
========================================================= */

$maintenance_start =
    $asset['maintenance_start_date'] ?? '';


/* =========================================================
   DELETE ASSET — PERMANENT HARD DELETE
   ---------------------------------------------------------
   Rules:
   - Owner/Technician USERS are never deleted.
   - Active maintenance/callback still blocks deletion.
   - If a Service Request contains ONLY this asset, the complete
     request workflow is deleted: Offer, Offer Assets, Payments,
     Payment Schedule, Payment Transactions, etc.
   - If a Service Request contains OTHER assets too, the request
     itself is preserved and only this asset is detached.
   - The actual Asset and all direct asset records are deleted.
   - Everything is done in one DB transaction.
========================================================= */

if (isset($_POST['delete'])) {

    /* =====================================================
       CSRF
    ====================================================== */

    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {

        $_SESSION['error'] =
            'Invalid request. Please try again.';

        header("Location: edit-asset.php?id=" . $id);
        exit;
    }


    /* =====================================================
       CHECK ACTIVE MAINTENANCE JOB
    ====================================================== */

    $stmt = $conn->prepare("
        SELECT id
        FROM maintenance_jobs
        WHERE asset_id = ?
          AND status IN (
              'assigned',
              'in_progress',
              'pending_owner'
          )
        LIMIT 1
    ");

    if (!$stmt) {
        die("Maintenance job query error: " . $conn->error);
    }

    $stmt->bind_param("i", $id);
    $stmt->execute();

    $jobCheck = $stmt->get_result()->num_rows;
    $stmt->close();


    /* =====================================================
       CHECK ACTIVE CALLBACK
    ====================================================== */

    $stmt = $conn->prepare("
        SELECT id
        FROM callbacks
        WHERE asset_id = ?
          AND status IN (
              'open',
              'in_progress'
          )
          AND (
              technician_response IS NULL
              OR technician_response <> 'rejected'
          )
        LIMIT 1
    ");

    if (!$stmt) {
        die("Callback query error: " . $conn->error);
    }

    $stmt->bind_param("i", $id);
    $stmt->execute();

    $callbackCheck = $stmt->get_result()->num_rows;
    $stmt->close();


    /* =====================================================
       BLOCK DELETE
    ====================================================== */

    if ($jobCheck > 0 || $callbackCheck > 0) {

        $_SESSION['error'] =
            "Cannot delete asset. Active job or callback exists.";

        header("Location: edit-asset.php?id=" . $id);
        exit;
    }


    /* =====================================================
       HELPER: CHECK TABLE/COLUMN
    ====================================================== */

    $tableColumnExists = static function (
        mysqli $db,
        string $table,
        string $column
    ): bool {

        $stmt = $db->prepare("
            SELECT COUNT(*) AS t
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param("ss", $table, $column);
        $stmt->execute();

        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return ((int)($row['t'] ?? 0)) > 0;
    };


    /* =====================================================
       HELPER: DELETE BY ONE INTEGER COLUMN IF IT EXISTS
    ====================================================== */

    $deleteByIntColumn = static function (
        mysqli $db,
        string $table,
        string $column,
        int $value
    ) use ($tableColumnExists): void {

        if (!$tableColumnExists($db, $table, $column)) {
            return;
        }

        $sql = "DELETE FROM `" .
            str_replace('`', '``', $table) .
            "` WHERE `" .
            str_replace('`', '``', $column) .
            "` = ?";

        $stmt = $db->prepare($sql);

        if (!$stmt) {
            throw new Exception(
                "Unable to prepare cleanup for {$table}."
            );
        }

        $stmt->bind_param("i", $value);

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();

            throw new Exception(
                "Unable to delete records from {$table}: {$error}"
            );
        }

        $stmt->close();
    };


    /* =====================================================
       HELPER: DELETE BY INTEGER COLUMN IN A LIST
    ====================================================== */

    $deleteByIntList = static function (
        mysqli $db,
        string $table,
        string $column,
        array $ids
    ) use ($tableColumnExists): void {

        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn($v) => $v > 0
        )));

        if (empty($ids)) {
            return;
        }

        if (!$tableColumnExists($db, $table, $column)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));

        $sql = "DELETE FROM `" .
            str_replace('`', '``', $table) .
            "` WHERE `" .
            str_replace('`', '``', $column) .
            "` IN ({$placeholders})";

        $stmt = $db->prepare($sql);

        if (!$stmt) {
            throw new Exception(
                "Unable to prepare list cleanup for {$table}."
            );
        }

        $stmt->bind_param($types, ...$ids);

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();

            throw new Exception(
                "Unable to delete records from {$table}: {$error}"
            );
        }

        $stmt->close();
    };


    /* =====================================================
       START TRANSACTION
    ====================================================== */

    $conn->begin_transaction();

    try {

        /* =================================================
           1. FIND SERVICE REQUESTS USING THIS ASSET
        ================================================== */

        $requestIds = [];

        if ($tableColumnExists(
            $conn,
            'service_request_assets',
            'asset_id'
        )) {

            $requestStmt = $conn->prepare("
                SELECT DISTINCT request_id
                FROM service_request_assets
                WHERE asset_id = ?
            ");

            if (!$requestStmt) {
                throw new Exception(
                    'Unable to find Service Requests linked to this asset.'
                );
            }

            $requestStmt->bind_param("i", $id);
            $requestStmt->execute();

            $requestResult = $requestStmt->get_result();

            while ($row = $requestResult->fetch_assoc()) {

                $requestId = (int)($row['request_id'] ?? 0);

                if ($requestId > 0) {
                    $requestIds[] = $requestId;
                }
            }

            $requestStmt->close();
        }

        $requestIds = array_values(array_unique($requestIds));


        /* =================================================
           2. PROCESS EACH SERVICE REQUEST

           IMPORTANT:
           If the request contains other assets, do NOT delete
           the whole request. That would destroy another asset's
           valid offer/payment history.
        ================================================== */

        foreach ($requestIds as $requestId) {

            $assetCount = 0;

            $countStmt = $conn->prepare("
                SELECT COUNT(*) AS total
                FROM service_request_assets
                WHERE request_id = ?
            ");

            if (!$countStmt) {
                throw new Exception(
                    'Unable to determine assets in Service Request #' .
                    $requestId . '.'
                );
            }

            $countStmt->bind_param("i", $requestId);
            $countStmt->execute();

            $countRow = $countStmt
                ->get_result()
                ->fetch_assoc();

            $assetCount = (int)($countRow['total'] ?? 0);
            $countStmt->close();


            /* =============================================
               CASE A — THIS IS THE ONLY ASSET

               Delete the complete Service Request workflow.
            ============================================== */

            if ($assetCount <= 1) {

                /* -----------------------------------------
                   Get Offer IDs for this request
                ----------------------------------------- */

                $offerIds = [];

                if ($tableColumnExists(
                    $conn,
                    'nleta_offer_master',
                    'request_id'
                )) {

                    $offerStmt = $conn->prepare("
                        SELECT id
                        FROM nleta_offer_master
                        WHERE request_id = ?
                    ");

                    if (!$offerStmt) {
                        throw new Exception(
                            'Unable to find Offers for Service Request #' .
                            $requestId . '.'
                        );
                    }

                    $offerStmt->bind_param("i", $requestId);
                    $offerStmt->execute();

                    $offerResult = $offerStmt->get_result();

                    while ($offerRow = $offerResult->fetch_assoc()) {

                        $offerId = (int)($offerRow['id'] ?? 0);

                        if ($offerId > 0) {
                            $offerIds[] = $offerId;
                        }
                    }

                    $offerStmt->close();
                }

                $offerIds = array_values(array_unique($offerIds));


                /* -----------------------------------------
                   Get Payment IDs from Offers
                ----------------------------------------- */

                $paymentIds = [];

                if (
                    !empty($offerIds)
                    &&
                    $tableColumnExists(
                        $conn,
                        'payments',
                        'Offer_ID'
                    )
                ) {

                    $placeholders = implode(
                        ',',
                        array_fill(0, count($offerIds), '?')
                    );

                    $types = str_repeat('i', count($offerIds));

                    $paymentStmt = $conn->prepare("
                        SELECT Payment_ID
                        FROM payments
                        WHERE Offer_ID IN ({$placeholders})
                    ");

                    if ($paymentStmt) {

                        $paymentStmt->bind_param(
                            $types,
                            ...$offerIds
                        );

                        $paymentStmt->execute();

                        $paymentResult = $paymentStmt->get_result();

                        while ($paymentRow = $paymentResult->fetch_assoc()) {

                            $paymentId = (int)(
                                $paymentRow['Payment_ID'] ?? 0
                            );

                            if ($paymentId > 0) {
                                $paymentIds[] = $paymentId;
                            }
                        }

                        $paymentStmt->close();
                    }
                }

                $paymentIds = array_values(array_unique($paymentIds));


                /* -----------------------------------------
                   Get Payment Schedule IDs
                ----------------------------------------- */

                $scheduleIds = [];

                if (
                    !empty($paymentIds)
                    &&
                    $tableColumnExists(
                        $conn,
                        'payment_schedule',
                        'Payment_ID'
                    )
                    &&
                    $tableColumnExists(
                        $conn,
                        'payment_schedule',
                        'Schedule_ID'
                    )
                ) {

                    $placeholders = implode(
                        ',',
                        array_fill(0, count($paymentIds), '?')
                    );

                    $types = str_repeat('i', count($paymentIds));

                    $scheduleStmt = $conn->prepare("
                        SELECT Schedule_ID
                        FROM payment_schedule
                        WHERE Payment_ID IN ({$placeholders})
                    ");

                    if ($scheduleStmt) {

                        $scheduleStmt->bind_param(
                            $types,
                            ...$paymentIds
                        );

                        $scheduleStmt->execute();

                        $scheduleResult = $scheduleStmt->get_result();

                        while ($scheduleRow = $scheduleResult->fetch_assoc()) {

                            $scheduleId = (int)(
                                $scheduleRow['Schedule_ID'] ?? 0
                            );

                            if ($scheduleId > 0) {
                                $scheduleIds[] = $scheduleId;
                            }
                        }

                        $scheduleStmt->close();
                    }
                }

                $scheduleIds = array_values(array_unique($scheduleIds));


                /* -----------------------------------------
                   Delete Payment Transactions FIRST
                ----------------------------------------- */

                $deleteByIntList(
                    $conn,
                    'payment_transactions',
                    'Schedule_ID',
                    $scheduleIds
                );

                $deleteByIntList(
                    $conn,
                    'payment_transactions',
                    'Payment_ID',
                    $paymentIds
                );

                $deleteByIntList(
                    $conn,
                    'payment_transactions',
                    'Offer_ID',
                    $offerIds
                );


                /* -----------------------------------------
                   Delete Payment Schedule
                ----------------------------------------- */

                $deleteByIntList(
                    $conn,
                    'payment_schedule',
                    'Schedule_ID',
                    $scheduleIds
                );

                $deleteByIntList(
                    $conn,
                    'payment_schedule',
                    'Payment_ID',
                    $paymentIds
                );


                /* -----------------------------------------
                   Delete Payments
                ----------------------------------------- */

                $deleteByIntList(
                    $conn,
                    'payments',
                    'Payment_ID',
                    $paymentIds
                );

                $deleteByIntList(
                    $conn,
                    'payments',
                    'Offer_ID',
                    $offerIds
                );


                /* -----------------------------------------
                   Delete Offer Child Records
                ----------------------------------------- */

                $deleteByIntList(
                    $conn,
                    'nleta_offer_assets',
                    'offer_id',
                    $offerIds
                );

                $deleteByIntList(
                    $conn,
                    'nleta_offer_items',
                    'offer_id',
                    $offerIds
                );


                /* -----------------------------------------
                   Delete Offers
                ----------------------------------------- */

                $deleteByIntList(
                    $conn,
                    'nleta_offer_master',
                    'id',
                    $offerIds
                );


                /* -----------------------------------------
                   Delete Service Request Asset Links
                ----------------------------------------- */

                $deleteByIntColumn(
                    $conn,
                    'service_request_assets',
                    'request_id',
                    $requestId
                );


                /* -----------------------------------------
                   Delete Service Request itself
                ----------------------------------------- */

                if ($tableColumnExists(
                    $conn,
                    'service_requests',
                    'id'
                )) {

                    $deleteRequest = $conn->prepare("
                        DELETE FROM service_requests
                        WHERE id = ?
                        LIMIT 1
                    ");

                    if (!$deleteRequest) {
                        throw new Exception(
                            'Unable to prepare Service Request deletion.'
                        );
                    }

                    $deleteRequest->bind_param("i", $requestId);

                    if (!$deleteRequest->execute()) {

                        $error = $deleteRequest->error;
                        $deleteRequest->close();

                        throw new Exception(
                            'Unable to delete Service Request #' .
                            $requestId . ': ' . $error
                        );
                    }

                    $deleteRequest->close();
                }


            /* =============================================
               CASE B — REQUEST HAS OTHER ASSETS

               Preserve the request/offer/payment workflow.
               Remove only this asset from the request and
               its offer-asset mapping.
            ============================================== */

            } else {

                /* Remove this asset from the Service Request. */
                $deleteLink = $conn->prepare("
                    DELETE FROM service_request_assets
                    WHERE request_id = ?
                      AND asset_id = ?
                ");

                if (!$deleteLink) {
                    throw new Exception(
                        'Unable to detach asset from Service Request #' .
                        $requestId . '.'
                    );
                }

                $deleteLink->bind_param(
                    "ii",
                    $requestId,
                    $id
                );

                if (!$deleteLink->execute()) {
                    $error = $deleteLink->error;
                    $deleteLink->close();

                    throw new Exception(
                        'Unable to detach asset from Service Request #' .
                        $requestId . ': ' . $error
                    );
                }

                $deleteLink->close();
            }
        }


        /* =================================================
           3. REMOVE ASSET FROM ANY REMAINING OFFER-ASSET MAP

           This is especially important for old/orphaned Offers.
        ================================================== */

        $deleteByIntColumn(
            $conn,
            'nleta_offer_assets',
            'asset_id',
            $id
        );


        /* =================================================
           4. DELETE DIRECT ASSET RECORDS
        ================================================== */

        $directAssetTables = [
            'asset_details',
            'maintenance_records',
            'maintenance_jobs',
            'insurance',
            'fortnightly_checks',
            'callbacks',
            'asset_master',
            'noc_registration',
            'evidence',
            'nc_register',
            'contracts',
            'offers'
        ];

        foreach ($directAssetTables as $table) {

            $deleteByIntColumn(
                $conn,
                $table,
                'asset_id',
                $id
            );
        }


        /* =================================================
           5. DELETE ACTUAL ASSET
        ================================================== */

        $deleteAsset = $conn->prepare("
            DELETE FROM assets
            WHERE id = ?
            LIMIT 1
        ");

        if (!$deleteAsset) {
            throw new Exception(
                'Unable to prepare Asset deletion: ' .
                $conn->error
            );
        }

        $deleteAsset->bind_param("i", $id);

        if (!$deleteAsset->execute()) {

            $error = $deleteAsset->error;
            $deleteAsset->close();

            throw new Exception(
                'Unable to delete Asset: ' . $error
            );
        }

        if ($deleteAsset->affected_rows <= 0) {
            $deleteAsset->close();

            throw new Exception(
                'Asset could not be deleted.'
            );
        }

        $deleteAsset->close();


        /* =================================================
           6. COMMIT
        ================================================== */

        $conn->commit();

        $_SESSION['success'] =
            'Asset and all applicable related records were permanently deleted.';

        header("Location: assets.php");
        exit;


    } catch (Throwable $e) {

        $conn->rollback();

        $_SESSION['error'] =
            'Unable to permanently delete asset: ' .
            $e->getMessage();

        header(
            "Location: edit-asset.php?id=" . $id
        );

        exit;
    }
}


/* =========================================================
   UPDATE ASSET
========================================================= */

if (isset($_POST['update'])) {


    /* =====================================================
       CSRF
    ===================================================== */

    if (!validateCSRFToken(
        $_POST['csrf_token'] ?? ''
    )) {

        $_SESSION['error'] =
            'Invalid request. Please try again.';

        header(
            "Location: edit-asset.php?id=" . $id
        );

        exit;
    }


    /* =====================================================
       GET FORM DATA
    ===================================================== */

    $name =
        trim($_POST['asset_name'] ?? '');

    $location =
        trim($_POST['location'] ?? '');

    $type =
        trim($_POST['asset_type'] ?? '');

    $category =
        trim($_POST['category'] ?? '');

    $owner_id =
        (int)($_POST['owner_id'] ?? 0);

    $start =
        trim(
            $_POST['maintenance_start_date']
            ?? ''
        );


    /* =====================================================
       ALLOWED ASSET TYPES
    ===================================================== */

    $allowed_types = [
        'Lift',
        'Escalator',
        'Travelator'
    ];


    /* =====================================================
       ALLOWED CATEGORIES
    ===================================================== */

    $allowed_categories = [
        'I',
        'II',
        'III'
    ];


    /* =====================================================
       MAINTENANCE INTERVAL
    ===================================================== */

    $intervalMap = [
        'I'   => 30,
        'II'  => 15,
        'III' => 90
    ];


    /* =====================================================
       VALIDATE DATE
    ===================================================== */

    $validDate = false;

    if ($start !== '') {

        $dateObject =
            DateTime::createFromFormat(
                'Y-m-d',
                $start
            );

        $validDate =
            $dateObject &&
            $dateObject->format('Y-m-d') === $start;
    }


    /* =====================================================
       VALIDATE FORM
    ===================================================== */

    if (
        $name !== '' &&
        $validDate &&
        in_array(
            $type,
            $allowed_types,
            true
        ) &&
        in_array(
            $category,
            $allowed_categories,
            true
        )
    ) {


        $interval =
            $intervalMap[$category];


        /* =================================================
           UPDATE ASSET
        ================================================= */

        /*
           owner_id uses 0 when no owner is selected.
           NULLIF converts 0 to SQL NULL.
        */

        $stmt = $conn->prepare("
            UPDATE assets
            SET
                asset_name = ?,
                location = ?,
                asset_type = ?,
                category = ?,
                owner_id = NULLIF(?, 0),
                maintenance_interval = ?,
                maintenance_start_date = ?
            WHERE id = ?
        ");

        if (!$stmt) {

            $_SESSION['error'] =
                "Asset update query error: " .
                $conn->error;

            header(
                "Location: edit-asset.php?id=" . $id
            );

            exit;
        }


        /* =================================================
           CORRECT BIND TYPES

           s = asset_name
           s = location
           s = asset_type
           s = category
           i = owner_id
           i = maintenance_interval
           s = maintenance_start_date
           i = asset id

           Correct string:
           ssssiisi
        ================================================= */

        $stmt->bind_param(
            "ssssiisi",
            $name,
            $location,
            $type,
            $category,
            $owner_id,
            $interval,
            $start,
            $id
        );


        if (!$stmt->execute()) {

            $_SESSION['error'] =
                "Unable to update asset: " .
                $stmt->error;

            $stmt->close();

            header(
                "Location: edit-asset.php?id=" . $id
            );

            exit;
        }

        $stmt->close();


        /* =================================================
           CHECK EXISTING MAINTENANCE RECORD
        ================================================= */

        $chk = $conn->prepare("
            SELECT id
            FROM maintenance_records
            WHERE asset_id = ?
            ORDER BY maintenance_date DESC
            LIMIT 1
        ");

        if (!$chk) {

            $_SESSION['error'] =
                "Maintenance record query error: " .
                $conn->error;

            header(
                "Location: edit-asset.php?id=" . $id
            );

            exit;
        }

        $chk->bind_param(
            "i",
            $id
        );

        if (!$chk->execute()) {

            $_SESSION['error'] =
                "Maintenance record check error: " .
                $chk->error;

            $chk->close();

            header(
                "Location: edit-asset.php?id=" . $id
            );

            exit;
        }

        $rec =
            $chk->get_result()->fetch_assoc();

        $chk->close();


        /* =================================================
           UPDATE EXISTING MAINTENANCE RECORD
        ================================================= */

        if ($rec) {

            $record_id =
                (int)$rec['id'];

            $upd = $conn->prepare("
                UPDATE maintenance_records
                SET maintenance_date = ?
                WHERE id = ?
            ");

            if (!$upd) {

                $_SESSION['error'] =
                    "Maintenance update query error: " .
                    $conn->error;

                header(
                    "Location: edit-asset.php?id=" . $id
                );

                exit;
            }

            $upd->bind_param(
                "si",
                $start,
                $record_id
            );

            if (!$upd->execute()) {

                $_SESSION['error'] =
                    "Unable to update maintenance record: " .
                    $upd->error;

                $upd->close();

                header(
                    "Location: edit-asset.php?id=" . $id
                );

                exit;
            }

            $upd->close();


        } else {


            /* =================================================
               CREATE FIRST MAINTENANCE RECORD
            ================================================= */

            $ins = $conn->prepare("
                INSERT INTO maintenance_records
                (
                    asset_id,
                    maintenance_date,
                    status
                )
                VALUES
                (
                    ?,
                    ?,
                    'completed'
                )
            ");

            if (!$ins) {

                $_SESSION['error'] =
                    "Maintenance insert query error: " .
                    $conn->error;

                header(
                    "Location: edit-asset.php?id=" . $id
                );

                exit;
            }

            $ins->bind_param(
                "is",
                $id,
                $start
            );

            if (!$ins->execute()) {

                $_SESSION['error'] =
                    "Unable to create maintenance record: " .
                    $ins->error;

                $ins->close();

                header(
                    "Location: edit-asset.php?id=" . $id
                );

                exit;
            }

            $ins->close();
        }


        /* =================================================
           UPDATE SUCCESS
        ================================================= */

        $_SESSION['success'] =
            "Asset updated successfully.";

        header("Location: assets.php");
        exit;
    }


    /* =====================================================
       INVALID UPDATE
    ===================================================== */

    $_SESSION['error'] =
        "Please enter valid asset information.";

    header(
        "Location: edit-asset.php?id=" . $id
    );

    exit;
}


/* =========================================================
   GET OWNERS
========================================================= */

$owners = $conn->query("
    SELECT
        id,
        name
    FROM users
    WHERE role = 'owner'
    ORDER BY name ASC
");

if (!$owners) {

    die(
        "Owner query error: " .
        $conn->error
    );
}


/* =========================================================
   HEADER
========================================================= */

include('../includes/header.php');
include('../includes/sidebar-admin.php');

?>


<div class="content">

    <h3 class="mb-4">
        Edit Asset
    </h3>


    <!-- =====================================================
         SUCCESS MESSAGE
    ====================================================== -->

    <?php if (!empty($_SESSION['success'])): ?>

        <div class="alert alert-success alert-dismissible fade show">

            <?= htmlspecialchars(
                $_SESSION['success']
            ); ?>

            <?php unset(
                $_SESSION['success']
            ); ?>

            <button
                type="button"
                class="btn-close"
                onclick="this.closest('.alert').remove()">
            </button>

        </div>

    <?php endif; ?>


    <!-- =====================================================
         ERROR MESSAGE
    ====================================================== -->

    <?php if (!empty($_SESSION['error'])): ?>

        <div class="alert alert-danger alert-dismissible fade show">

            <?= htmlspecialchars(
                $_SESSION['error']
            ); ?>

            <?php unset(
                $_SESSION['error']
            ); ?>

            <button
                type="button"
                class="btn-close"
                onclick="this.closest('.alert').remove()">
            </button>

        </div>

    <?php endif; ?>


    <!-- =====================================================
         EDIT FORM
    ====================================================== -->

    <div
        class="card p-4 shadow"
        style="max-width:600px">

        <form method="POST">

            <?= csrfField() ?>


            <!-- =================================================
                 ASSET NAME
            ================================================== -->

            <div class="mb-3">

                <label class="form-label">
                    Asset Name
                </label>

                <input
                    type="text"
                    name="asset_name"
                    class="form-control"
                    value="<?= htmlspecialchars(
                        $asset['asset_name'] ?? ''
                    ) ?>"
                    required>

            </div>


            <!-- =================================================
                 LOCATION
            ================================================== -->

            <div class="mb-3">

                <label class="form-label">
                    Location
                </label>

                <input
                    type="text"
                    name="location"
                    class="form-control"
                    value="<?= htmlspecialchars(
                        $asset['location'] ?? ''
                    ) ?>">

            </div>


            <!-- =================================================
                 ASSET TYPE
            ================================================== -->

            <div class="mb-3">

                <label class="form-label">
                    Asset Type
                </label>

                <select
                    name="asset_type"
                    class="form-select"
                    required>

                    <option value="">
                        Select Type
                    </option>

                    <option
                        value="Lift"
                        <?= ($asset['asset_type'] ?? '') === 'Lift'
                            ? 'selected'
                            : '' ?>>

                        Lift

                    </option>

                    <option
                        value="Escalator"
                        <?= ($asset['asset_type'] ?? '') === 'Escalator'
                            ? 'selected'
                            : '' ?>>

                        Escalator

                    </option>

                    <option
                        value="Travelator"
                        <?= ($asset['asset_type'] ?? '') === 'Travelator'
                            ? 'selected'
                            : '' ?>>

                        Travelator

                    </option>

                </select>

            </div>


            <!-- =================================================
                 CATEGORY
            ================================================== -->

            <div class="mb-3">

                <label class="form-label">
                    Maintenance Category
                </label>

                <select
                    name="category"
                    class="form-select"
                    required>

                    <option
                        value="I"
                        <?= ($asset['category'] ?? '') === 'I'
                            ? 'selected'
                            : '' ?>>

                        Monthly

                    </option>

                    <option
                        value="II"
                        <?= ($asset['category'] ?? '') === 'II'
                            ? 'selected'
                            : '' ?>>

                        15 Days

                    </option>

                    <option
                        value="III"
                        <?= ($asset['category'] ?? '') === 'III'
                            ? 'selected'
                            : '' ?>>

                        3 Months

                    </option>

                </select>

            </div>


            <!-- =================================================
                 MAINTENANCE START DATE
            ================================================== -->

            <div class="mb-3">

                <label class="form-label">
                    Maintenance Start Date
                </label>

                <input
                    type="date"
                    name="maintenance_start_date"
                    class="form-control"
                    value="<?= htmlspecialchars(
                        $maintenance_start
                    ) ?>"
                    required>

            </div>


            <!-- =================================================
                 OWNER
            ================================================== -->

            <div class="mb-3">

                <label class="form-label">
                    Assign Owner (RWA)
                </label>

                <select
                    name="owner_id"
                    class="form-select">

                    <option value="">
                        -- No Owner --
                    </option>

                    <?php while (
                        $o = $owners->fetch_assoc()
                    ): ?>

                        <option
                            value="<?= (int)$o['id'] ?>"
                            <?= (
                                (int)($asset['owner_id'] ?? 0)
                                ===
                                (int)$o['id']
                            )
                                ? 'selected'
                                : '' ?>>

                            <?= htmlspecialchars(
                                $o['name']
                            ) ?>

                        </option>

                    <?php endwhile; ?>

                </select>

            </div>


            <!-- =================================================
                 UPDATE
            ================================================== -->

            <button
                type="submit"
                name="update"
                value="1"
                class="btn btn-success w-100">

                Update Asset

            </button>


            <!-- =================================================
                 DELETE
            ================================================== -->

            <button
                type="submit"
                name="delete"
                value="1"
                class="btn btn-outline-danger w-100 mt-2"
                onclick="return confirm('Delete this asset permanently?')">

                Delete Asset

            </button>


            <!-- =================================================
                 BACK
            ================================================== -->

            <a
                href="assets.php"
                class="btn btn-secondary w-100 mt-2">

                ← Back

            </a>

        </form>

    </div>

</div>


<?php

include('../includes/footer.php');

?>