<?php

/* =========================================================
   NLETA MAINTENANCE MANAGEMENT SYSTEM
   PHASE 4 — SERVICE SELECTION
   STEP 3 — ASSET DETAILS

   Permanent Lift / Asset Profile Details
========================================================= */

include('../config/db.php');
include('../config/auth.php');

require_role('owner');


/* =========================================================
   SESSION
========================================================= */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}


/* =========================================================
   OWNER
========================================================= */

$owner_id = (int)($_SESSION['uid'] ?? 0);

if ($owner_id <= 0) {
    header("Location: ../login.php");
    exit;
}


/* =========================================================
   TIMEZONE — IST
========================================================= */

$timezone = new DateTimeZone('Asia/Kolkata');

$now = new DateTime('now', $timezone);


/* =========================================================
   CSRF TOKEN
========================================================= */

if (empty($_SESSION['csrf_lift_details'])) {

    $_SESSION['csrf_lift_details'] =
        bin2hex(random_bytes(32));
}

$csrfToken =
    $_SESSION['csrf_lift_details'];


/* =========================================================
   PHASE 4 SERVICES — MULTIPLE SERVICES
========================================================= */

$service_ids = [];

if (
    isset($_SESSION['phase4_service_ids']) &&
    is_array($_SESSION['phase4_service_ids'])
) {
    foreach ($_SESSION['phase4_service_ids'] as $sid) {
        $sid = (int)$sid;

        if ($sid > 0) {
            $service_ids[] = $sid;
        }
    }
}

/* Backward compatibility with old single-service session. */
if (
    empty($service_ids) &&
    !empty($_SESSION['phase4_service_id'])
) {
    $oldServiceId = (int)$_SESSION['phase4_service_id'];

    if ($oldServiceId > 0) {
        $service_ids[] = $oldServiceId;
    }
}

$service_ids = array_values(array_unique($service_ids));

if (empty($service_ids)) {
    header("Location: service-selection.php");
    exit;
}


/* =========================================================
   LOAD ALL SELECTED SERVICES
========================================================= */

$servicePlaceholders = implode(
    ',',
    array_fill(0, count($service_ids), '?')
);

$serviceBindTypes = str_repeat(
    'i',
    count($service_ids)
);

$serviceStmt = $conn->prepare("
    SELECT
        id,
        service_code,
        service_name,
        description
    FROM service_catalog
    WHERE id IN ($servicePlaceholders)
      AND is_active = 1
    ORDER BY sort_order ASC, id ASC
");

if (!$serviceStmt) {
    die('Unable to load service information.');
}

$serviceValues = $service_ids;
$serviceRefs = [$serviceBindTypes];

foreach ($serviceValues as $key => $value) {
    $serviceRefs[] = &$serviceValues[$key];
}

call_user_func_array(
    [$serviceStmt, 'bind_param'],
    $serviceRefs
);

$serviceStmt->execute();

$serviceResult = $serviceStmt->get_result();

$services = [];

while ($row = $serviceResult->fetch_assoc()) {
    $services[] = $row;
}

$serviceStmt->close();

if (count($services) !== count($service_ids)) {

    unset(
        $_SESSION['phase4_service_ids'],
        $_SESSION['phase4_service_codes'],
        $_SESSION['phase4_service_names'],
        $_SESSION['phase4_service_id'],
        $_SESSION['phase4_service_code'],
        $_SESSION['phase4_service_name'],
        $_SESSION['phase4_asset_ids']
    );

    header("Location: service-selection.php");
    exit;
}

/*
 * The first service is retained as the primary/legacy
 * service for existing code paths. The complete selection
 * is available in $services.
 */
$service = $services[0];

$service_id = (int)$service['id'];

$service_code = strtoupper(
    trim((string)$service['service_code'])
);

/* =========================================================
   SELECTED ASSETS
========================================================= */

$selectedAssetIds =
    $_SESSION['phase4_asset_ids']
    ?? [];


if (!is_array($selectedAssetIds)) {

    $selectedAssetIds = [];
}


/* =========================================================
   CLEAN ASSET IDS
========================================================= */

$cleanAssetIds = [];

foreach (
    $selectedAssetIds
    as $assetId
) {

    $assetId = (int)$assetId;

    if ($assetId > 0) {

        $cleanAssetIds[] =
            $assetId;
    }
}


$selectedAssetIds =
    array_values(
        array_unique(
            $cleanAssetIds
        )
    );


if (empty($selectedAssetIds)) {

    header(
        "Location: service-lifts.php"
    );

    exit;
}


/* =========================================================
   LOAD SERVICE
========================================================= */

$serviceStmt = $conn->prepare("
    SELECT
        id,
        service_code,
        service_name,
        description
    FROM service_catalog
    WHERE id = ?
      AND is_active = 1
    LIMIT 1
");


if (!$serviceStmt) {

    die(
        'Unable to load service information.'
    );
}


$serviceStmt->bind_param(
    "i",
    $service_id
);

$serviceStmt->execute();


$service =
    $serviceStmt
    ->get_result()
    ->fetch_assoc();


$serviceStmt->close();


if (!$service) {

    unset(
        $_SESSION['phase4_service_ids'],
        $_SESSION['phase4_service_codes'],
        $_SESSION['phase4_service_names'],
        $_SESSION['phase4_service_id'],
        $_SESSION['phase4_service_code'],
        $_SESSION['phase4_service_name'],
        $_SESSION['phase4_asset_ids']
    );

    header(
        "Location: service-selection.php"
    );

    exit;
}


/* =========================================================
   LOAD SELECTED OWNER ASSETS
========================================================= */

$placeholders =
    implode(
        ',',
        array_fill(
            0,
            count($selectedAssetIds),
            '?'
        )
    );


$assetTypes =
    str_repeat(
        'i',
        count($selectedAssetIds)
    );


$assetSql = "

    SELECT

        id,
        display_asset_id,
        asset_name,
        asset_type,
        category,
        location,
        status

    FROM assets

    WHERE owner_id = ?

      AND id IN ($placeholders)

    ORDER BY
        display_asset_id ASC,
        id ASC

";


$assetStmt =
    $conn->prepare($assetSql);


if (!$assetStmt) {

    die(
        'Unable to load selected assets.'
    );
}


/* =========================================================
   BIND OWNER + ASSET IDS
========================================================= */

$bindTypes =
    'i' . $assetTypes;


$bindValues =
    array_merge(
        [$owner_id],
        $selectedAssetIds
    );


$bindParams = [];

$bindParams[] =
    $bindTypes;


foreach (
    $bindValues
    as $key => $value
) {

    $bindParams[] =
        &$bindValues[$key];
}


call_user_func_array(
    [
        $assetStmt,
        'bind_param'
    ],
    $bindParams
);


$assetStmt->execute();


$assetResult =
    $assetStmt->get_result();


$assets = [];


while (
    $row =
    $assetResult->fetch_assoc()
) {

    $assets[] =
        $row;
}


$assetStmt->close();


/* =========================================================
   SECURITY CHECK
========================================================= */

if (
    count($assets)
    !==
    count($selectedAssetIds)
) {

    unset(
        $_SESSION['phase4_asset_ids']
    );

    header(
        "Location: service-lifts.php"
    );

    exit;
}


/* =========================================================
   LOAD PERMANENT DETAILS
========================================================= */

$details = [];


$detailStmt = $conn->prepare("
    SELECT

        asset_id,
        manufacturer,
        model,
        serial_number,
        installation_date,
        capacity,
        number_of_floors,
        rated_speed,
        drive_type,
        controller_type,
        machine_type,
        escalator_type,
        escalator_rise,
        technical_remarks

    FROM asset_details

    WHERE asset_id = ?

    LIMIT 1
");


if (!$detailStmt) {

    die(
        'Unable to load permanent asset details.'
    );
}


foreach (
    $assets
    as $asset
) {

    $assetId =
        (int)$asset['id'];


    $detailStmt->bind_param(
        "i",
        $assetId
    );


    $detailStmt->execute();


    $existing =
        $detailStmt
        ->get_result()
        ->fetch_assoc();


    if ($existing) {

        $details[$assetId] =
            $existing;

    } else {

        $details[$assetId] = [

            'asset_id' =>
                $assetId,

            'manufacturer' =>
                '',

            'model' =>
                '',

            'serial_number' =>
                '',

            'installation_date' =>
                '',

            'capacity' =>
                '',

            'number_of_floors' =>
                '',

            'rated_speed' =>
                '',

            'drive_type' =>
                '',

            'controller_type' =>
                '',

            'machine_type' =>
                '',

            'escalator_type' =>
                '',

            'escalator_rise' =>
                '',

            'technical_remarks' =>
                ''
        ];
    }
}


$detailStmt->close();


/* =========================================================
   ERROR
========================================================= */

$error = '';


/* =========================================================
   SAVE DETAILS
========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {


    /* =====================================================
       CSRF
    ===================================================== */

    $postedToken =
        $_POST['csrf_token'] ?? '';


    if (
        empty($postedToken)
        ||
        !hash_equals(
            $csrfToken,
            $postedToken
        )
    ) {

        $error =
            'Security validation failed. Please try again.';

    } else {


        /* =================================================
           FORM ARRAYS
        ================================================= */

        $manufacturer =
            $_POST['manufacturer']
            ?? [];

        $model =
            $_POST['model']
            ?? [];

        $serialNumber =
            $_POST['serial_number']
            ?? [];

        $installationDate =
            $_POST['installation_date']
            ?? [];

        $capacity =
            $_POST['capacity']
            ?? [];

        $numberOfFloors =
            $_POST['number_of_floors']
            ?? [];

        $ratedSpeed =
            $_POST['rated_speed']
            ?? [];

        $driveType =
            $_POST['drive_type']
            ?? [];

        $controllerType =
            $_POST['controller_type']
            ?? [];

        $machineType =
            $_POST['machine_type']
            ?? [];

        $escalatorType =
            $_POST['escalator_type']
            ?? [];

        $escalatorRise =
            $_POST['escalator_rise']
            ?? [];

        $technicalRemarks =
            $_POST['technical_remarks']
            ?? [];


        /* =================================================
           VALIDATION
        ================================================= */

        foreach (
            $assets
            as $asset
        ) {

            $assetId =
                (int)$asset['id'];


            $manufacturerValue =
                trim(
                    (string)(
                        $manufacturer[$assetId]
                        ?? ''
                    )
                );


            $modelValue =
                trim(
                    (string)(
                        $model[$assetId]
                        ?? ''
                    )
                );


            $serialValue =
                trim(
                    (string)(
                        $serialNumber[$assetId]
                        ?? ''
                    )
                );


            $installationValue =
                trim(
                    (string)(
                        $installationDate[$assetId]
                        ?? ''
                    )
                );


            $capacityValue =
                trim(
                    (string)(
                        $capacity[$assetId]
                        ?? ''
                    )
                );


            $floorsValue =
                trim(
                    (string)(
                        $numberOfFloors[$assetId]
                        ?? ''
                    )
                );


            $speedValue =
                trim(
                    (string)(
                        $ratedSpeed[$assetId]
                        ?? ''
                    )
                );

            $assetTypeValue =
                strtolower(
                    trim(
                        (string)($asset['asset_type'] ?? '')
                    )
                );

            $escalatorTypeValue =
                trim(
                    (string)(
                        $escalatorType[$assetId]
                        ?? ''
                    )
                );

            $escalatorRiseValue =
                trim(
                    (string)(
                        $escalatorRise[$assetId]
                        ?? ''
                    )
                );


            /*
             * Common required fields.
             */
            if (
                $manufacturerValue === ''
                ||
                $modelValue === ''
                ||
                $serialValue === ''
                ||
                $installationValue === ''
                ||
                $speedValue === ''
            ) {

                $error =
                    'Please complete all mandatory technical details for ' .
                    $asset['display_asset_id'] .
                    '.';

                break;
            }


            if ($assetTypeValue === 'lift') {

                if (
                    $capacityValue === ''
                    ||
                    $floorsValue === ''
                ) {

                    $error =
                        'Please complete Capacity and Number of Floors for ' .
                        $asset['display_asset_id'] .
                        '.';

                    break;
                }

                if (
                    !ctype_digit($floorsValue)
                    ||
                    (int)$floorsValue < 1
                ) {

                    $error =
                        'Number of floors must be a valid positive number for ' .
                        $asset['display_asset_id'] .
                        '.';

                    break;
                }

            } elseif ($assetTypeValue === 'escalator') {

                if (
                    $escalatorTypeValue === ''
                    ||
                    $escalatorRiseValue === ''
                ) {

                    $error =
                        'Please select Escalator Type and enter Rise for ' .
                        $asset['display_asset_id'] .
                        '.';

                    break;
                }

                if (
                    !is_numeric($escalatorRiseValue)
                    ||
                    (float)$escalatorRiseValue <= 0
                ) {

                    $error =
                        'Escalator Rise must be a valid positive number for ' .
                        $asset['display_asset_id'] .
                        '.';

                    break;
                }

                $riseNumeric =
                    (float)$escalatorRiseValue;

                if (
                    $escalatorTypeValue ===
                    'Commercial Escalator up to 4.5 m Rise'
                    &&
                    $riseNumeric > 4.5
                ) {

                    $error =
                        'Commercial Escalator rise cannot exceed 4.5 m for ' .
                        $asset['display_asset_id'] .
                        '.';

                    break;
                }

                if (
                    $escalatorTypeValue ===
                    'Heavy Duty Escalator up to 7.0 m Rise'
                    &&
                    $riseNumeric > 7.0
                ) {

                    $error =
                        'Heavy Duty Escalator rise cannot exceed 7.0 m for ' .
                        $asset['display_asset_id'] .
                        '.';

                    break;
                }
            }


            /*
             * Installation date validation
             */

            $dateObject =
                DateTime::createFromFormat(
                    'Y-m-d',
                    $installationValue
                );


            if (
                !$dateObject
                ||
                $dateObject->format(
                    'Y-m-d'
                ) !==
                $installationValue
            ) {

                $error =
                    'Please enter a valid installation date for ' .
                    $asset['display_asset_id'] .
                    '.';

                break;
            }

        }


        /* =================================================
           SAVE IF VALID
        ================================================= */

        if (empty($error)) {


            $conn->begin_transaction();


            try {


                $saveStmt =
                    $conn->prepare("
                        INSERT INTO asset_details
                        (
                            asset_id,
                            manufacturer,
                            model,
                            serial_number,
                            installation_date,
                            capacity,
                            number_of_floors,
                            rated_speed,
                            drive_type,
                            controller_type,
                            machine_type,
                            escalator_type,
                            escalator_rise,
                            technical_remarks,
                            created_at,
                            updated_at
                        )
                        VALUES
                        (
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?
                        )

                        ON DUPLICATE KEY UPDATE

                            manufacturer =
                                VALUES(manufacturer),

                            model =
                                VALUES(model),

                            serial_number =
                                VALUES(serial_number),

                            installation_date =
                                VALUES(installation_date),

                            capacity =
                                VALUES(capacity),

                            number_of_floors =
                                VALUES(number_of_floors),

                            rated_speed =
                                VALUES(rated_speed),

                            drive_type =
                                VALUES(drive_type),

                            controller_type =
                                VALUES(controller_type),

                            machine_type =
                                VALUES(machine_type),

                            escalator_type =
                                VALUES(escalator_type),

                            escalator_rise =
                                VALUES(escalator_rise),

                            technical_remarks =
                                VALUES(technical_remarks),

                            updated_at =
                                VALUES(updated_at)
                    ");


                if (!$saveStmt) {

                    throw new Exception(
                        'Unable to prepare lift details.'
                    );
                }


                $currentTime =
                    $now->format(
                        'Y-m-d H:i:s'
                    );


                foreach (
                    $assets
                    as $asset
                ) {

                    $assetId =
                        (int)$asset['id'];


                    $manufacturerValue =
                        trim(
                            (string)(
                                $manufacturer[$assetId]
                                ?? ''
                            )
                        );


                    $modelValue =
                        trim(
                            (string)(
                                $model[$assetId]
                                ?? ''
                            )
                        );


                    $serialValue =
                        trim(
                            (string)(
                                $serialNumber[$assetId]
                                ?? ''
                            )
                        );


                    $installationValue =
                        trim(
                            (string)(
                                $installationDate[$assetId]
                                ?? ''
                            )
                        );


                    $capacityValue =
                        trim(
                            (string)(
                                $capacity[$assetId]
                                ?? ''
                            )
                        );


                    $floorsValue =
                        (int)(
                            $numberOfFloors[$assetId]
                            ?? 0
                        );


                    $speedValue =
                        trim(
                            (string)(
                                $ratedSpeed[$assetId]
                                ?? ''
                            )
                        );


                    $driveValue =
                        trim(
                            (string)(
                                $driveType[$assetId]
                                ?? ''
                            )
                        );


                    $controllerValue =
                        trim(
                            (string)(
                                $controllerType[$assetId]
                                ?? ''
                            )
                        );


                    $machineValue =
                        trim(
                            (string)(
                                $machineType[$assetId]
                                ?? ''
                            )
                        );

                    $escalatorTypeValue =
                        trim(
                            (string)(
                                $escalatorType[$assetId]
                                ?? ''
                            )
                        );

                    $escalatorRiseValue =
                        trim(
                            (string)(
                                $escalatorRise[$assetId]
                                ?? ''
                            )
                        );

                    $floorsDbValue =
                        $floorsValue === ''
                        ? 0
                        : (int)$floorsValue;


                    $remarksValue =
                        trim(
                            (string)(
                                $technicalRemarks[$assetId]
                                ?? ''
                            )
                        );


                    /*
                     * Correct bind types:
                     *
                     * i = asset_id
                     * s = all other fields
                     */

                    $saveStmt->bind_param(
                        "isssssisisssssss",
                        $assetId,
                        $manufacturerValue,
                        $modelValue,
                        $serialValue,
                        $installationValue,
                        $capacityValue,
                        $floorsDbValue,
                        $speedValue,
                        $driveValue,
                        $controllerValue,
                        $machineValue,
                        $escalatorTypeValue,
                        $escalatorRiseValue,
                        $remarksValue,
                        $currentTime,
                        $currentTime
                    );


                    if (
                        !$saveStmt->execute()
                    ) {

                        throw new Exception(
                            'Unable to save details for ' .
                            $asset['display_asset_id']
                        );
                    }

                }


                $saveStmt->close();


                $conn->commit();


                /*
                 * Continue to review page
                 */

                header(
                    "Location: service-request-review.php"
                );

                exit;


            } catch (Throwable $e) {

                $conn->rollback();


                $error =
                    'Unable to save lift details. Please try again.';
            }

        }

    }
}


/* =========================================================
   KEEP ENTERED DATA AFTER VALIDATION ERROR
========================================================= */

if (!empty($error)) {

    foreach (
        $assets
        as $asset
    ) {

        $assetId =
            (int)$asset['id'];


        $details[$assetId] = [

            'asset_id' =>
                $assetId,

            'manufacturer' =>
                trim(
                    (string)(
                        $_POST[
                            'manufacturer'
                        ][$assetId]
                        ?? ''
                    )
                ),

            'model' =>
                trim(
                    (string)(
                        $_POST[
                            'model'
                        ][$assetId]
                        ?? ''
                    )
                ),

            'serial_number' =>
                trim(
                    (string)(
                        $_POST[
                            'serial_number'
                        ][$assetId]
                        ?? ''
                    )
                ),

            'installation_date' =>
                trim(
                    (string)(
                        $_POST[
                            'installation_date'
                        ][$assetId]
                        ?? ''
                    )
                ),

            'capacity' =>
                trim(
                    (string)(
                        $_POST[
                            'capacity'
                        ][$assetId]
                        ?? ''
                    )
                ),

            'number_of_floors' =>
                trim(
                    (string)(
                        $_POST[
                            'number_of_floors'
                        ][$assetId]
                        ?? ''
                    )
                ),

            'rated_speed' =>
                trim(
                    (string)(
                        $_POST[
                            'rated_speed'
                        ][$assetId]
                        ?? ''
                    )
                ),

            'drive_type' =>
                trim(
                    (string)(
                        $_POST[
                            'drive_type'
                        ][$assetId]
                        ?? ''
                    )
                ),

            'controller_type' =>
                trim(
                    (string)(
                        $_POST[
                            'controller_type'
                        ][$assetId]
                        ?? ''
                    )
                ),

            'machine_type' =>
                trim(
                    (string)(
                        $_POST[
                            'machine_type'
                        ][$assetId]
                        ?? ''
                    )
                ),

            'escalator_type' =>
                trim(
                    (string)(
                        $_POST[
                            'escalator_type'
                        ][$assetId]
                        ?? ''
                    )
                ),

            'escalator_rise' =>
                trim(
                    (string)(
                        $_POST[
                            'escalator_rise'
                        ][$assetId]
                        ?? ''
                    )
                ),

            'technical_remarks' =>
                trim(
                    (string)(
                        $_POST[
                            'technical_remarks'
                        ][$assetId]
                        ?? ''
                    )
                )
        ];
    }
}


/* =========================================================
   HEADER / SIDEBAR
========================================================= */

include('../includes/header.php');

include('../includes/sidebar-owner.php');

?>


<style>

/* =========================================================
   MAIN
========================================================= */

.phase4-wrapper {

    max-width: 1200px;

    margin: 0 auto;

}


/* =========================================================
   HEADER
========================================================= */

.phase4-header {

    background: #ffffff;

    border-radius: 14px;

    padding: 24px;

    margin-bottom: 24px;

    box-shadow:
        0 2px 10px rgba(0,0,0,0.08);

}


.phase4-header h3 {

    margin: 0;

    font-weight: 600;

}


.phase4-header p {

    margin: 8px 0 0;

    color: #6c757d;

}


/* =========================================================
   DATE
========================================================= */

.date-box {

    text-align: right;

}


.date-box .date-label {

    color: #6c757d;

    font-size: 12px;

}


.date-box .date-value {

    font-weight: 600;

    margin-top: 3px;

}


/* =========================================================
   SERVICE BOX
========================================================= */

.service-box {

    margin-top: 20px;

    padding: 14px 16px;

    border-radius: 10px;

    background: #f4f8ff;

    border: 1px solid #d9e7ff;

}


.service-code {

    display: inline-block;

    margin-right: 8px;

    padding: 4px 8px;

    border-radius: 5px;

    background: #eaf2ff;

    color: #0d6efd;

    font-size: 11px;

    font-weight: 700;

}


/* =========================================================
   STEPS
========================================================= */

.phase4-steps {

    display: flex;

    align-items: center;

    gap: 10px;

    margin-top: 20px;

    flex-wrap: wrap;

}


.phase4-step {

    display: flex;

    align-items: center;

    gap: 8px;

    color: #6c757d;

    font-size: 14px;

}


.phase4-step-number {

    width: 32px;

    height: 32px;

    border-radius: 50%;

    display: flex;

    align-items: center;

    justify-content: center;

    background: #e9ecef;

    font-weight: 600;

}


.phase4-step.completed {

    color: #198754;

    font-weight: 600;

}


.phase4-step.completed
.phase4-step-number {

    background: #198754;

    color: #ffffff;

}


.phase4-step.active {

    color: #0d6efd;

    font-weight: 600;

}


.phase4-step.active
.phase4-step-number {

    background: #0d6efd;

    color: #ffffff;

}


.phase4-arrow {

    color: #adb5bd;

}


/* =========================================================
   ASSET CARD
========================================================= */

.asset-detail-card {

    background: #ffffff;

    border-radius: 14px;

    padding: 22px;

    margin-bottom: 20px;

    box-shadow:
        0 2px 10px rgba(0,0,0,0.08);

}


/* =========================================================
   ASSET HEADER
========================================================= */

.asset-header {

    display: flex;

    justify-content: space-between;

    align-items: center;

    gap: 15px;

    margin-bottom: 20px;

    flex-wrap: wrap;

}


.asset-title {

    display: flex;

    align-items: center;

    gap: 13px;

}


.asset-icon {

    width: 48px;

    height: 48px;

    border-radius: 11px;

    background: #eaf2ff;

    color: #0d6efd;

    display: flex;

    align-items: center;

    justify-content: center;

    font-size: 22px;

}


.asset-id {

    font-size: 18px;

    font-weight: 700;

}


.asset-name {

    color: #6c757d;

    font-size: 13px;

    margin-top: 3px;

}


/* =========================================================
   EXISTING ASSET INFO
========================================================= */

.existing-data {

    background: #f8f9fa;

    border-radius: 10px;

    padding: 13px 15px;

    margin-bottom: 20px;

    font-size: 12px;

    color: #6c757d;

    line-height: 1.7;

}


/* =========================================================
   FORM
========================================================= */

.form-grid {

    display: grid;

    grid-template-columns:
        repeat(2, minmax(0, 1fr));

    gap: 18px;

}


.form-group-full {

    grid-column: 1 / -1;

}


.form-label {

    display: block;

    font-size: 13px;

    font-weight: 600;

    margin-bottom: 6px;

}


.required {

    color: #dc3545;

}


.form-control {

    min-height: 42px;

}


textarea.form-control {

    min-height: 100px;

    resize: vertical;

}


/* =========================================================
   PERMANENT INFO
========================================================= */

.permanent-info {

    display: flex;

    align-items: flex-start;

    gap: 10px;

    margin-top: 20px;

    padding: 12px 14px;

    background: #eaf7ef;

    border: 1px solid #b7e4c7;

    border-radius: 9px;

    color: #146c43;

    font-size: 12px;

    line-height: 1.5;

}


.permanent-info i {

    margin-top: 2px;

}


/* =========================================================
   ACTION BAR
========================================================= */

.action-bar {

    background: #ffffff;

    border-radius: 14px;

    padding: 20px;

    margin-bottom: 30px;

    box-shadow:
        0 2px 10px rgba(0,0,0,0.08);

    display: flex;

    justify-content: space-between;

    align-items: center;

    gap: 15px;

}


.action-note {

    color: #6c757d;

    font-size: 13px;

}


.action-buttons {

    display: flex;

    gap: 10px;

}


/* =========================================================
   MOBILE
========================================================= */

@media (max-width: 768px) {

    .form-grid {

        grid-template-columns: 1fr;

    }


    .form-group-full {

        grid-column: auto;

    }


    .date-box {

        text-align: left;

    }


    .action-bar {

        flex-direction: column;

        align-items: stretch;

    }


    .action-buttons {

        width: 100%;

    }


    .action-buttons .btn {

        flex: 1;

    }

}

</style>


<div class="content">

<div class="phase4-wrapper">


<!-- =====================================================
     HEADER
===================================================== -->

<div class="phase4-header">


    <div
        class="d-flex justify-content-between
               align-items-start gap-3"
    >


        <div>

            <h3>

                <i class="bi bi-card-checklist me-2"></i>

                Asset Details

            </h3>

            <p>

                Enter the permanent technical details
                of your selected asset(s).

            </p>

        </div>


        <div class="date-box">

            <div class="date-label">

                Current Date & Time

            </div>

            <div class="date-value">

                <?= htmlspecialchars(
                    $now->format(
                        'd-m-Y h:i A'
                    )
                ) ?>

            </div>

            <small class="text-muted">

                IST

            </small>

        </div>


    </div>


    <!-- =================================================
         SERVICE
    ================================================== -->

    <div class="service-box">

        <strong>
            Selected Services
        </strong>

        <div class="mt-2 d-flex flex-wrap gap-2">

            <?php foreach ($services as $selectedService): ?>

                <span class="service-code">

                    <?= htmlspecialchars(
                        $selectedService['service_code']
                    ) ?>

                    —
                    <?= htmlspecialchars(
                        $selectedService['service_name']
                    ) ?>

                </span>

            <?php endforeach; ?>

        </div>

    </div>


    <!-- =================================================
         STEPS
    ================================================== -->

    <div class="phase4-steps">


        <div class="phase4-step completed">

            <span class="phase4-step-number">

                ✓

            </span>

            <span>

                Select Service

            </span>

        </div>


        <span class="phase4-arrow">

            →

        </span>


        <div class="phase4-step completed">

            <span class="phase4-step-number">

                ✓

            </span>

            <span>

                My Lifts

            </span>

        </div>


        <span class="phase4-arrow">

            →

        </span>


        <div class="phase4-step active">

            <span class="phase4-step-number">

                3

            </span>

            <span>

                Asset Details

            </span>

        </div>


        <span class="phase4-arrow">

            →

        </span>


        <div class="phase4-step">

            <span class="phase4-step-number">

                4

            </span>

            <span>

                Review & Submit

            </span>

        </div>


    </div>

</div>


<!-- =====================================================
     ERROR
===================================================== -->

<?php if (!empty($error)): ?>

<div class="alert alert-danger shadow-sm">

    <i class="bi bi-exclamation-triangle-fill me-2"></i>

    <?= htmlspecialchars($error) ?>

</div>

<?php endif; ?>


<!-- =====================================================
     FORM
===================================================== -->

<form
    method="POST"
    action="lift-details.php"
    id="liftDetailsForm"
>


    <input
        type="hidden"
        name="csrf_token"
        value="<?= htmlspecialchars(
            $csrfToken
        ) ?>"
    >


    <?php foreach (
        $assets
        as $asset
    ): ?>


        <?php

        $assetId =
            (int)$asset['id'];

        $detail =
            $details[$assetId];

        ?>


        <div class="asset-detail-card">


            <!-- =========================================
                 ASSET HEADER
            ========================================== -->

            <div class="asset-header">


                <div class="asset-title">


                    <div class="asset-icon">

                        <i class="bi bi-building"></i>

                    </div>


                    <div>

                        <div class="asset-id">

                            <?= htmlspecialchars(
                                $asset[
                                    'display_asset_id'
                                ]
                            ) ?>

                        </div>


                        <div class="asset-name">

                            <?= htmlspecialchars(
                                $asset[
                                    'asset_name'
                                ]
                            ) ?>

                        </div>

                    </div>


                </div>


                <span class="badge text-bg-primary">

                    Permanent Asset Profile

                </span>


            </div>


            <!-- =========================================
                 EXISTING ASSET INFORMATION
            ========================================== -->

            <div class="existing-data">

                <strong>

                    Existing Asset Information

                </strong>

                <br>

                <strong>Asset ID:</strong>

                <?= htmlspecialchars(
                    $asset[
                        'display_asset_id'
                    ]
                ) ?>

                &nbsp; • &nbsp;

                <strong>Type:</strong>

                <?= htmlspecialchars(
                    $asset[
                        'asset_type'
                    ]
                ) ?>

                &nbsp; • &nbsp;

                <strong>Category:</strong>

                <?= htmlspecialchars(
                    $asset[
                        'category'
                    ]
                ) ?>

                <br>

                <strong>Location:</strong>

                <?= htmlspecialchars(
                    $asset[
                        'location'
                    ]
                ) ?>

                &nbsp; • &nbsp;

                <strong>Status:</strong>

                <?= htmlspecialchars(
                    $asset[
                        'status'
                    ]
                ) ?>

            </div>


            <!-- =========================================
                 DETAILS
            ========================================== -->

            <div class="form-grid">


                <!-- Manufacturer -->

                <div>

                    <label class="form-label">

                        Manufacturer

                        <span class="required">*</span>

                    </label>

                    <input
                        type="text"
                        name="manufacturer[<?= $assetId ?>]"
                        class="form-control"
                        maxlength="150"
                        value="<?= htmlspecialchars(
                            $detail[
                                'manufacturer'
                            ]
                        ) ?>"
                        placeholder="Enter manufacturer"
                        required
                    >

                </div>


                <!-- Model -->

                <div>

                    <label class="form-label">

                        Model

                        <span class="required">*</span>

                    </label>

                    <input
                        type="text"
                        name="model[<?= $assetId ?>]"
                        class="form-control"
                        maxlength="150"
                        value="<?= htmlspecialchars(
                            $detail[
                                'model'
                            ]
                        ) ?>"
                        placeholder="Enter model"
                        required
                    >

                </div>


                <!-- Serial -->

                <div>

                    <label class="form-label">

                        Serial Number

                        <span class="required">*</span>

                    </label>

                    <input
                        type="text"
                        name="serial_number[<?= $assetId ?>]"
                        class="form-control"
                        maxlength="150"
                        value="<?= htmlspecialchars(
                            $detail[
                                'serial_number'
                            ]
                        ) ?>"
                        placeholder="Enter serial number"
                        required
                    >

                </div>


                <!-- Installation -->

                <div>

                    <label class="form-label">

                        Installation Date

                        <span class="required">*</span>

                    </label>

                    <input
                        type="date"
                        name="installation_date[<?= $assetId ?>]"
                        class="form-control"
                        value="<?= htmlspecialchars(
                            $detail[
                                'installation_date'
                            ]
                        ) ?>"
                        required
                    >

                </div>


                <!-- Escalator Pricing Type -->

                <?php if (strtolower($asset['asset_type'] ?? '') === 'escalator'): ?>

                <div>

                    <label class="form-label">
                        Escalator Type
                        <span class="required">*</span>
                    </label>

                    <select
                        name="escalator_type[<?= $assetId ?>]"
                        class="form-select"
                        required
                    >
                        <option value="">Select Escalator Type</option>
                        <option value="Commercial Escalator up to 4.5 m Rise" <?= ($detail['escalator_type'] ?? '') === 'Commercial Escalator up to 4.5 m Rise' ? 'selected' : '' ?>>Commercial Escalator up to 4.5 m Rise</option>
                        <option value="Heavy Duty Escalator up to 7.0 m Rise" <?= ($detail['escalator_type'] ?? '') === 'Heavy Duty Escalator up to 7.0 m Rise' ? 'selected' : '' ?>>Heavy Duty Escalator up to 7.0 m Rise</option>
                    </select>

                </div>

                <div>

                    <label class="form-label">
                        Escalator Rise (m)
                        <span class="required">*</span>
                    </label>

                    <input
                        type="number"
                        name="escalator_rise[<?= $assetId ?>]"
                        class="form-control"
                        min="0.01"
                        step="0.01"
                        value="<?= htmlspecialchars($detail['escalator_rise'] ?? '') ?>"
                        placeholder="Example: 4.5"
                        required
                    >

                </div>

                <?php endif; ?>


                <!-- Capacity -->

                <div class="<?= strtolower($asset['asset_type'] ?? '') === 'escalator' ? 'optional-technical-field' : '' ?>">

                    <label class="form-label">

                        Capacity

                        <span class="required">*</span>

                    </label>

                    <input
                        type="text"
                        name="capacity[<?= $assetId ?>]"
                        class="form-control"
                        maxlength="100"
                        value="<?= htmlspecialchars(
                            $detail[
                                'capacity'
                            ]
                        ) ?>"
                        placeholder="Example: 630 kg / 8 persons"
                        <?= strtolower($asset['asset_type'] ?? '') === 'lift' ? 'required' : '' ?>
                    >

                </div>


<?php if (strtolower($asset['asset_type'] ?? '') === 'lift'): ?>

                <!-- Floors -->

                <div>

                    <label class="form-label">

                        Number of Floors / Stops

                        <span class="required">*</span>

                    </label>

                    <input
                        type="number"
                        name="number_of_floors[<?= $assetId ?>]"
                        class="form-control"
                        min="1"
                        max="999"
                        value="<?= htmlspecialchars(
                            $detail[
                                'number_of_floors'
                            ]
                        ) ?>"
                        placeholder="Example: 8"
                        required
                    >

                </div>



<?php endif; ?>


                <!-- Speed -->

                <div>

                    <label class="form-label">

                        Rated Speed

                        <span class="required">*</span>

                    </label>

                    <input
                        type="text"
                        name="rated_speed[<?= $assetId ?>]"
                        class="form-control"
                        maxlength="100"
                        value="<?= htmlspecialchars(
                            $detail[
                                'rated_speed'
                            ]
                        ) ?>"
                        placeholder="Example: 1.0 m/s"
                        required
                    >

                </div>


                <!-- Drive -->

                <div>

                    <label class="form-label">

                        Drive Type

                    </label>

                    <input
                        type="text"
                        name="drive_type[<?= $assetId ?>]"
                        class="form-control"
                        maxlength="100"
                        value="<?= htmlspecialchars(
                            $detail[
                                'drive_type'
                            ]
                        ) ?>"
                        placeholder="Example: VVVF"
                    >

                </div>


                <!-- Controller -->

                <div>

                    <label class="form-label">

                        Controller Type

                    </label>

                    <input
                        type="text"
                        name="controller_type[<?= $assetId ?>]"
                        class="form-control"
                        maxlength="100"
                        value="<?= htmlspecialchars(
                            $detail[
                                'controller_type'
                            ]
                        ) ?>"
                        placeholder="Enter controller type"
                    >

                </div>


                <!-- Machine -->

                <div>

                    <label class="form-label">

                        Machine Type

                    </label>

                    <input
                        type="text"
                        name="machine_type[<?= $assetId ?>]"
                        class="form-control"
                        maxlength="100"
                        value="<?= htmlspecialchars(
                            $detail[
                                'machine_type'
                            ]
                        ) ?>"
                        placeholder="Example: Geared / Gearless"
                    >

                </div>


                <!-- Remarks -->

                <div class="form-group-full">

                    <label class="form-label">

                        Technical Remarks

                    </label>

                    <textarea
                        name="technical_remarks[<?= $assetId ?>]"
                        class="form-control"
                        rows="4"
                        maxlength="5000"
                        placeholder="Enter additional permanent technical information..."
                    ><?= htmlspecialchars(
                        $detail[
                            'technical_remarks'
                        ]
                    ) ?></textarea>

                </div>


            </div>


            <!-- =========================================
                 PERMANENT MESSAGE
            ========================================== -->

            <div class="permanent-info">

                <i class="bi bi-database-check"></i>

                <div>

                    <strong>

                        Permanent Asset Profile

                    </strong>

                    <br>

                    These details are saved against
                    <?= htmlspecialchars(
                        $asset[
                            'display_asset_id'
                        ]
                    ) ?>
                    and will be available for future
                    NLETA services, inspections,
                    reports and certificates.

                </div>

            </div>


        </div>


    <?php endforeach; ?>


    <!-- =================================================
         ACTION BAR
    ================================================== -->

    <div class="action-bar">


        <div class="action-note">

            <strong>

                <?= count($assets) ?>

                <?= count($assets) === 1
                    ? 'asset'
                    : 'assets'
                ?>

                selected.

            </strong>

            <br>

            Fields marked with
            <span class="text-danger">*</span>
            are mandatory.

        </div>


        <div class="action-buttons">


            <a
                href="service-lifts.php"
                class="btn btn-outline-secondary"
            >

                <i class="bi bi-arrow-left me-1"></i>

                Back

            </a>


            <button
                type="submit"
                class="btn btn-primary px-4"
                id="saveButton"
            >

                <i class="bi bi-save me-1"></i>

                Save & Continue

            </button>


        </div>


    </div>


</form>


</div>

</div>


<?php include('../includes/footer.php'); ?>


<script>

/* =========================================================
   LIFT DETAILS
========================================================= */

document.addEventListener(
    'DOMContentLoaded',
    function () {


        const form =
            document.getElementById(
                'liftDetailsForm'
            );


        const saveButton =
            document.getElementById(
                'saveButton'
            );


        if (form) {

            form.addEventListener(
                'submit',
                function (event) {


                    /*
                     * Browser validation handles required fields.
                     */

                    if (
                        !form.checkValidity()
                    ) {

                        event.preventDefault();

                        form.reportValidity();

                        return false;
                    }


                    if (saveButton) {

                        saveButton.disabled =
                            true;

                        saveButton.innerHTML =

                            '<span class="spinner-border spinner-border-sm me-1"></span>' +

                            'Saving...';

                    }

                }
            );

        }

    }
);

</script>