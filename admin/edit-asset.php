<?php

/* =========================================================
   ERROR REPORTING
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
    die("Asset query prepare error: " . $conn->error);
}

$stmt->bind_param("i", $id);

if (!$stmt->execute()) {
    die("Asset query execute error: " . $stmt->error);
}

$asset = $stmt->get_result()->fetch_assoc();

$stmt->close();


/* =========================================================
   ASSET NOT FOUND
========================================================= */

if (!$asset) {
    $_SESSION['error'] = 'Asset not found.';
    header("Location: assets.php");
    exit;
}


$maintenance_start =
    $asset['maintenance_start_date'] ?? '';


/* =========================================================
   DELETE ASSET
========================================================= */

if (isset($_POST['delete'])) {

    /* =====================================================
       CSRF
    ===================================================== */

    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {

        $_SESSION['error'] =
            'Invalid request. Please try again.';

        header("Location: edit-asset.php?id=" . $id);
        exit;
    }


    /* =====================================================
       CHECK ACTIVE MAINTENANCE
    ===================================================== */

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

    $jobCheck =
        $stmt->get_result()->num_rows;

    $stmt->close();


    /* =====================================================
       CHECK ACTIVE CALLBACK
    ===================================================== */

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

    $callbackCheck =
        $stmt->get_result()->num_rows;

    $stmt->close();


    /* =====================================================
       BLOCK IF ACTIVE WORK EXISTS
    ===================================================== */

    if (
        $jobCheck > 0 ||
        $callbackCheck > 0
    ) {

        $_SESSION['error'] =
            'Cannot delete asset. Active maintenance job or callback exists.';

        header("Location: edit-asset.php?id=" . $id);
        exit;
    }


    /* =====================================================
       HELPER
       CHECK TABLE + COLUMN
    ===================================================== */

    $tableColumnExists = static function (
        mysqli $db,
        string $table,
        string $column
    ): bool {

        $stmt = $db->prepare("
            SELECT COUNT(*) AS total
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param(
            "ss",
            $table,
            $column
        );

        $stmt->execute();

        $row =
            $stmt
            ->get_result()
            ->fetch_assoc();

        $stmt->close();

        return ((int)($row['total'] ?? 0)) > 0;
    };


    /* =====================================================
       HELPER
       DELETE ONE INTEGER VALUE
    ===================================================== */

    $deleteOne = static function (
        mysqli $db,
        string $table,
        string $column,
        int $value
    ) use ($tableColumnExists): void {

        if ($value <= 0) {
            return;
        }

        if (!$tableColumnExists(
            $db,
            $table,
            $column
        )) {
            return;
        }

        $safeTable =
            str_replace('`', '``', $table);

        $safeColumn =
            str_replace('`', '``', $column);

        $sql = "
            DELETE FROM `{$safeTable}`
            WHERE `{$safeColumn}` = ?
        ";

        $stmt = $db->prepare($sql);

        if (!$stmt) {
            throw new Exception(
                "Unable to prepare deletion for {$table}."
            );
        }

        $stmt->bind_param(
            "i",
            $value
        );

        if (!$stmt->execute()) {

            $error =
                $stmt->error;

            $stmt->close();

            throw new Exception(
                "Unable to delete from {$table}: {$error}"
            );
        }

        $stmt->close();
    };


    /* =====================================================
       HELPER
       DELETE LIST OF IDS
    ===================================================== */

    $deleteList = static function (
        mysqli $db,
        string $table,
        string $column,
        array $ids
    ) use ($tableColumnExists): void {

        $ids = array_values(
            array_unique(
                array_filter(
                    array_map(
                        'intval',
                        $ids
                    ),
                    static fn($v) => $v > 0
                )
            )
        );

        if (empty($ids)) {
            return;
        }

        if (!$tableColumnExists(
            $db,
            $table,
            $column
        )) {
            return;
        }

        $placeholders =
            implode(
                ',',
                array_fill(
                    0,
                    count($ids),
                    '?'
                )
            );

        $types =
            str_repeat(
                'i',
                count($ids)
            );

        $safeTable =
            str_replace('`', '``', $table);

        $safeColumn =
            str_replace('`', '``', $column);

        $sql = "
            DELETE FROM `{$safeTable}`
            WHERE `{$safeColumn}`
            IN ({$placeholders})
        ";

        $stmt =
            $db->prepare($sql);

        if (!$stmt) {
            throw new Exception(
                "Unable to prepare {$table} cleanup."
            );
        }

        $stmt->bind_param(
            $types,
            ...$ids
        );

        if (!$stmt->execute()) {

            $error =
                $stmt->error;

            $stmt->close();

            throw new Exception(
                "Unable to delete from {$table}: {$error}"
            );
        }

        $stmt->close();
    };


    /* =====================================================
       TRANSACTION
    ===================================================== */

    $conn->begin_transaction();


    try {

        /* =================================================
           FIND REQUESTS USING THIS ASSET
        ================================================= */

        $requestIds = [];

        $requestStmt = $conn->prepare("
            SELECT DISTINCT request_id
            FROM service_request_assets
            WHERE asset_id = ?
        ");

        if (!$requestStmt) {
            throw new Exception(
                "Unable to find Service Requests: " .
                $conn->error
            );
        }

        $requestStmt->bind_param(
            "i",
            $id
        );

        $requestStmt->execute();

        $requestResult =
            $requestStmt->get_result();

        while (
            $row =
            $requestResult->fetch_assoc()
        ) {

            $requestId =
                (int)$row['request_id'];

            if ($requestId > 0) {
                $requestIds[] =
                    $requestId;
            }
        }

        $requestStmt->close();

        $requestIds =
            array_values(
                array_unique(
                    $requestIds
                )
            );


        /* =================================================
           PROCESS EACH REQUEST
        ================================================= */

        foreach (
            $requestIds
            as $requestId
        ) {

            /* =============================================
               COUNT ASSETS IN REQUEST
            ============================================== */

            $countStmt = $conn->prepare("
                SELECT COUNT(*) AS total
                FROM service_request_assets
                WHERE request_id = ?
            ");

            if (!$countStmt) {
                throw new Exception(
                    "Unable to count assets for Request #{$requestId}."
                );
            }

            $countStmt->bind_param(
                "i",
                $requestId
            );

            $countStmt->execute();

            $countRow =
                $countStmt
                ->get_result()
                ->fetch_assoc();

            $countStmt->close();

            $assetCount =
                (int)($countRow['total'] ?? 0);


            /* =============================================
               ONLY ASSET IN REQUEST
            ============================================== */

            if ($assetCount <= 1) {

                /* -----------------------------------------
                   GET OFFER IDS
                ----------------------------------------- */

                $offerIds = [];

                $offerStmt = $conn->prepare("
                    SELECT id
                    FROM nleta_offer_master
                    WHERE request_id = ?
                ");

                if (!$offerStmt) {
                    throw new Exception(
                        "Unable to find offers for Request #{$requestId}."
                    );
                }

                $offerStmt->bind_param(
                    "i",
                    $requestId
                );

                $offerStmt->execute();

                $offerResult =
                    $offerStmt->get_result();

                while (
                    $offer =
                    $offerResult->fetch_assoc()
                ) {

                    $offerId =
                        (int)$offer['id'];

                    if ($offerId > 0) {
                        $offerIds[] =
                            $offerId;
                    }
                }

                $offerStmt->close();


                $offerIds =
                    array_values(
                        array_unique(
                            $offerIds
                        )
                    );


                /* -----------------------------------------
                   GET PAYMENT IDS
                ----------------------------------------- */

                $paymentIds = [];

                if (!empty($offerIds)) {

                    $placeholders =
                        implode(
                            ',',
                            array_fill(
                                0,
                                count($offerIds),
                                '?'
                            )
                        );

                    $types =
                        str_repeat(
                            'i',
                            count($offerIds)
                        );

                    $paymentStmt =
                        $conn->prepare("
                            SELECT Payment_ID
                            FROM payments
                            WHERE Offer_ID
                            IN ({$placeholders})
                        ");

                    if ($paymentStmt) {

                        $paymentStmt->bind_param(
                            $types,
                            ...$offerIds
                        );

                        $paymentStmt->execute();

                        $paymentResult =
                            $paymentStmt->get_result();

                        while (
                            $payment =
                            $paymentResult->fetch_assoc()
                        ) {

                            $paymentId =
                                (int)$payment['Payment_ID'];

                            if ($paymentId > 0) {
                                $paymentIds[] =
                                    $paymentId;
                            }
                        }

                        $paymentStmt->close();
                    }
                }


                $paymentIds =
                    array_values(
                        array_unique(
                            $paymentIds
                        )
                    );


                /* -----------------------------------------
                   GET PAYMENT SCHEDULE IDS
                ----------------------------------------- */

                $scheduleIds = [];

                if (!empty($paymentIds)) {

                    $placeholders =
                        implode(
                            ',',
                            array_fill(
                                0,
                                count($paymentIds),
                                '?'
                            )
                        );

                    $types =
                        str_repeat(
                            'i',
                            count($paymentIds)
                        );

                    $scheduleStmt =
                        $conn->prepare("
                            SELECT Schedule_ID
                            FROM payment_schedule
                            WHERE Payment_ID
                            IN ({$placeholders})
                        ");

                    if ($scheduleStmt) {

                        $scheduleStmt->bind_param(
                            $types,
                            ...$paymentIds
                        );

                        $scheduleStmt->execute();

                        $scheduleResult =
                            $scheduleStmt->get_result();

                        while (
                            $schedule =
                            $scheduleResult->fetch_assoc()
                        ) {

                            $scheduleId =
                                (int)$schedule['Schedule_ID'];

                            if ($scheduleId > 0) {
                                $scheduleIds[] =
                                    $scheduleId;
                            }
                        }

                        $scheduleStmt->close();
                    }
                }


                /* -----------------------------------------
                   DELETE PAYMENT TRANSACTIONS
                ----------------------------------------- */

                $deleteList(
                    $conn,
                    'payment_transactions',
                    'Schedule_ID',
                    $scheduleIds
                );

                $deleteList(
                    $conn,
                    'payment_transactions',
                    'Payment_ID',
                    $paymentIds
                );

                $deleteList(
                    $conn,
                    'payment_transactions',
                    'Offer_ID',
                    $offerIds
                );


                /* -----------------------------------------
                   DELETE PAYMENT SCHEDULE
                ----------------------------------------- */

                $deleteList(
                    $conn,
                    'payment_schedule',
                    'Schedule_ID',
                    $scheduleIds
                );

                $deleteList(
                    $conn,
                    'payment_schedule',
                    'Payment_ID',
                    $paymentIds
                );


                /* -----------------------------------------
                   DELETE PAYMENTS
                ----------------------------------------- */

                $deleteList(
                    $conn,
                    'payments',
                    'Payment_ID',
                    $paymentIds
                );

                $deleteList(
                    $conn,
                    'payments',
                    'Offer_ID',
                    $offerIds
                );


                /* -----------------------------------------
                   DELETE OFFER CHILDREN
                ----------------------------------------- */

                $deleteList(
                    $conn,
                    'nleta_offer_assets',
                    'offer_id',
                    $offerIds
                );

                $deleteList(
                    $conn,
                    'nleta_offer_items',
                    'offer_id',
                    $offerIds
                );


                /* -----------------------------------------
                   DELETE OFFERS
                ----------------------------------------- */

                $deleteList(
                    $conn,
                    'nleta_offer_master',
                    'id',
                    $offerIds
                );


                /* -----------------------------------------
                   DELETE SERVICE REQUEST ASSET LINK
                ----------------------------------------- */

                $deleteOne(
                    $conn,
                    'service_request_assets',
                    'request_id',
                    $requestId
                );


                /* -----------------------------------------
                   DELETE SERVICE REQUEST
                ----------------------------------------- */

                $deleteOne(
                    $conn,
                    'service_requests',
                    'id',
                    $requestId
                );

            } else {

                /* =========================================
                   REQUEST HAS OTHER ASSETS

                   Keep Request / Offer / Payment.
                   Remove only this asset.
                ========================================== */

                $deleteLink =
                    $conn->prepare("
                        DELETE FROM service_request_assets
                        WHERE request_id = ?
                          AND asset_id = ?
                    ");

                if (!$deleteLink) {
                    throw new Exception(
                        "Unable to detach Asset #{$id}."
                    );
                }

                $deleteLink->bind_param(
                    "ii",
                    $requestId,
                    $id
                );

                if (!$deleteLink->execute()) {

                    $error =
                        $deleteLink->error;

                    $deleteLink->close();

                    throw new Exception(
                        "Unable to detach Asset #{$id}: {$error}"
                    );
                }

                $deleteLink->close();
            }
        }


        /* =================================================
           REMOVE ALL REMAINING OFFER-ASSET LINKS
        ================================================= */

        $deleteOne(
            $conn,
            'nleta_offer_assets',
            'asset_id',
            $id
        );


        /* =================================================
           DELETE ASSET-BASED RECORDS
        ================================================= */

        $assetTables = [

            'maintenance_records',
            'maintenance_jobs',
            'insurance',
            'fortnightly_checks',
            'callbacks',
            'asset_details',
            'asset_master',
            'noc_registration',
            'evidence',
            'nc_register',
            'contracts',
            'offers'

        ];


        foreach (
            $assetTables
            as $table
        ) {

            $deleteOne(
                $conn,
                $table,
                'asset_id',
                $id
            );
        }


        /* =================================================
           DELETE ACTUAL ASSET
        ================================================= */

        $deleteAsset =
            $conn->prepare("
                DELETE FROM assets
                WHERE id = ?
                LIMIT 1
            ");

        if (!$deleteAsset) {
            throw new Exception(
                "Unable to prepare Asset deletion: " .
                $conn->error
            );
        }

        $deleteAsset->bind_param(
            "i",
            $id
        );

        if (!$deleteAsset->execute()) {

            $error =
                $deleteAsset->error;

            $deleteAsset->close();

            throw new Exception(
                "Unable to delete Asset #{$id}: {$error}"
            );
        }

        if (
            $deleteAsset->affected_rows !== 1
        ) {

            $deleteAsset->close();

            throw new Exception(
                "Asset #{$id} was not deleted."
            );
        }

        $deleteAsset->close();


        /* =================================================
           VERIFY ASSET IS REALLY GONE
        ================================================= */

        $verify =
            $conn->prepare("
                SELECT id
                FROM assets
                WHERE id = ?
                LIMIT 1
            ");

        if (!$verify) {
            throw new Exception(
                "Unable to verify Asset deletion."
            );
        }

        $verify->bind_param(
            "i",
            $id
        );

        $verify->execute();

        $stillExists =
            $verify
            ->get_result()
            ->num_rows > 0;

        $verify->close();

        if ($stillExists) {

            throw new Exception(
                "Asset #{$id} still exists after deletion."
            );
        }


        /* =================================================
           COMMIT
        ================================================= */

        $conn->commit();

        $_SESSION['success'] =
            "Asset #{$id} and its applicable workflow records were permanently deleted.";

        header("Location: assets.php");
        exit;


    } catch (Throwable $e) {

        $conn->rollback();

        $_SESSION['error'] =
            "Asset deletion failed: " .
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


    $allowed_types = [
        'Lift',
        'Escalator',
        'Travelator'
    ];

    $allowed_categories = [
        'I',
        'II',
        'III'
    ];

    $intervalMap = [
        'I'   => 30,
        'II'  => 15,
        'III' => 90
    ];


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
           MAINTENANCE RECORD
        ================================================= */

        $chk = $conn->prepare("
            SELECT id
            FROM maintenance_records
            WHERE asset_id = ?
            ORDER BY maintenance_date DESC
            LIMIT 1
        ");

        if ($chk) {

            $chk->bind_param(
                "i",
                $id
            );

            $chk->execute();

            $rec =
                $chk
                ->get_result()
                ->fetch_assoc();

            $chk->close();


            if ($rec) {

                $record_id =
                    (int)$rec['id'];

                $upd =
                    $conn->prepare("
                        UPDATE maintenance_records
                        SET maintenance_date = ?
                        WHERE id = ?
                    ");

                if ($upd) {

                    $upd->bind_param(
                        "si",
                        $start,
                        $record_id
                    );

                    $upd->execute();

                    $upd->close();
                }

            } else {

                $ins =
                    $conn->prepare("
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

                if ($ins) {

                    $ins->bind_param(
                        "is",
                        $id,
                        $start
                    );

                    $ins->execute();

                    $ins->close();
                }
            }
        }


        $_SESSION['success'] =
            "Asset updated successfully.";

        header(
            "Location: assets.php"
        );

        exit;
    }


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

$owners =
    $conn->query("
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


    <?php if (!empty($_SESSION['success'])): ?>

        <div class="alert alert-success alert-dismissible fade show">

            <?= htmlspecialchars(
                $_SESSION['success']
            ) ?>

            <?php unset(
                $_SESSION['success']
            ) ?>

            <button
                type="button"
                class="btn-close"
                onclick="this.closest('.alert').remove()">
            </button>

        </div>

    <?php endif; ?>


    <?php if (!empty($_SESSION['error'])): ?>

        <div class="alert alert-danger alert-dismissible fade show">

            <?= htmlspecialchars(
                $_SESSION['error']
            ) ?>

            <?php unset(
                $_SESSION['error']
            ) ?>

            <button
                type="button"
                class="btn-close"
                onclick="this.closest('.alert').remove()">
            </button>

        </div>

    <?php endif; ?>


    <div
        class="card p-4 shadow"
        style="max-width:600px"
    >

        <form method="POST">

            <?= csrfField() ?>


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
                        $o =
                        $owners->fetch_assoc()
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


            <button
                type="submit"
                name="update"
                value="1"
                class="btn btn-success w-100">

                Update Asset

            </button>


            <button
                type="submit"
                name="delete"
                value="1"
                class="btn btn-outline-danger w-100 mt-2"
                onclick="return confirm(
                    'Delete this asset permanently? This will also delete its applicable Service Request, Offer and payment workflow.'
                )">

                Delete Asset

            </button>


            <a
                href="assets.php"
                class="btn btn-secondary w-100 mt-2">

                ← Back

            </a>

        </form>

    </div>

</div>

<?php include('../includes/footer.php'); ?>