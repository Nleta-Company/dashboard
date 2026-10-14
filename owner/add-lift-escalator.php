<?php
/* =========================================================
   NLETA
   OWNER — ADD LIFT / ESCALATOR
   PHASE 4 — ASSET REGISTER

   COMPLETE FIXED VERSION

   FEATURES
   ---------------------------------------------------------
   ✓ Add Lift / Escalator
   ✓ Existing Asset Details Copy
   ✓ Asset Name is NOT copied
   ✓ Copied details remain editable
   ✓ Rated Load Capacity auto-calculates
   ✓ Rated Load Capacity remains manually editable
   ✓ No Landing Matrix Preview
   ✓ Floor Service Pattern
   ✓ Every Nth Floor support
   ✓ Specific Floors support
   ✓ Hidden conditional fields are disabled
   ✓ No "floor_interval is not focusable" error
   ✓ MySQL transaction
   ✓ Owner-only existing asset loading
   ✓ IST / Asia-Kolkata
========================================================= */

include('../config/db.php');
include('../config/auth.php');
require_once '../config/security.php';

require_role('owner');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

date_default_timezone_set('Asia/Kolkata');


/* =========================================================
   OWNER ID
========================================================= */

$owner_id = (int)(
    $_SESSION['uid']
    ?? $_SESSION['user_id']
    ?? 0
);

if ($owner_id <= 0) {
    header('Location: ../login.php');
    exit;
}


/* =========================================================
   HELPER
========================================================= */

function h($value)
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}


/* =========================================================
   OWNER NAME
========================================================= */

$ownerName = '';

$ownerStmt = $conn->prepare("
    SELECT name
    FROM users
    WHERE id = ?
      AND role = 'owner'
    LIMIT 1
");

if ($ownerStmt) {

    $ownerStmt->bind_param(
        'i',
        $owner_id
    );

    if ($ownerStmt->execute()) {

        $ownerRow =
            $ownerStmt
            ->get_result()
            ->fetch_assoc();

        if ($ownerRow) {

            $ownerName =
                trim(
                    (string)(
                        $ownerRow['name'] ?? ''
                    )
                );
        }
    }

    $ownerStmt->close();
}


if ($ownerName === '') {
    $ownerName = 'Owner';
}


/* =========================================================
   CSRF
========================================================= */

if (
    empty(
        $_SESSION['csrf_owner_add_asset']
    )
) {

    $_SESSION['csrf_owner_add_asset'] =
        bin2hex(
            random_bytes(32)
        );
}

$csrfToken =
    $_SESSION['csrf_owner_add_asset'];


/* =========================================================
   STATE CODES
========================================================= */

$stateCodes = [

    'Andhra Pradesh' => 'AP',
    'Arunachal Pradesh' => 'AR',
    'Assam' => 'AS',
    'Bihar' => 'BR',
    'Chhattisgarh' => 'CG',
    'Goa' => 'GA',
    'Gujarat' => 'GJ',
    'Haryana' => 'HR',
    'Himachal Pradesh' => 'HP',
    'Jharkhand' => 'JH',
    'Karnataka' => 'KA',
    'Kerala' => 'KL',
    'Madhya Pradesh' => 'MP',
    'Maharashtra' => 'MH',
    'Manipur' => 'MN',
    'Meghalaya' => 'ML',
    'Mizoram' => 'MZ',
    'Nagaland' => 'NL',
    'Odisha' => 'OD',
    'Punjab' => 'PB',
    'Rajasthan' => 'RJ',
    'Sikkim' => 'SK',
    'Tamil Nadu' => 'TN',
    'Telangana' => 'TG',
    'Tripura' => 'TR',
    'Uttar Pradesh' => 'UP',
    'Uttarakhand' => 'UK',
    'West Bengal' => 'WB',

    'Andaman and Nicobar Islands' => 'AN',
    'Chandigarh' => 'CH',
    'Dadra and Nagar Haveli and Daman and Diu' => 'DN',
    'Delhi' => 'DL',
    'Jammu and Kashmir' => 'JK',
    'Ladakh' => 'LA',
    'Lakshadweep' => 'LD',
    'Puducherry' => 'PY'
];


/* =========================================================
   OPTIONS
========================================================= */

$liftTypes = [

    'Passenger Lift',
    'Goods Lift',
    'Passenger Cum Goods Lift',
    'Hospital Lift',
    'Service Lift',
    'Other'
];


$passengerBasedLiftTypes = [

    'Passenger Lift',
    'Passenger Cum Goods Lift',
    'Hospital Lift'
];


$escalatorTypes = [

    'Travelator',
    'Commercial Escalator',
    'Heavy Duty Escalator',
    'Other'
];


$machineTypes = [

    'Machine Room / MR',
    'Machine Room Less / MRL',
    'Hydraulic',
    'Other'
];


$floorServiceTypes = [

    'All Floors',
    'Odd Floors',
    'Even Floors',
    'Every Nth Floor',
    'Specific Floors'
];


$allowedAssetTypes = [
    'Lift',
    'Escalator'
];


$currentYear =
    (int)date('Y');

$minInstallationYear =
    1950;


/* =========================================================
   EXISTING ASSETS
========================================================= */

$existingAssets = [];

$existingAssetStmt = $conn->prepare("
    SELECT
        id,
        asset_name,
        display_asset_id,
        asset_type,
        location,
        state
    FROM assets
    WHERE owner_id = ?
    ORDER BY id DESC
");

if ($existingAssetStmt) {

    $existingAssetStmt->bind_param(
        'i',
        $owner_id
    );

    if ($existingAssetStmt->execute()) {

        $result =
            $existingAssetStmt
            ->get_result();

        while (
            $row =
            $result->fetch_assoc()
        ) {

            $existingAssets[] =
                $row;
        }
    }

    $existingAssetStmt->close();
}


/* =========================================================
   AJAX — LOAD EXISTING ASSET
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'GET'
    &&
    isset($_GET['action'])
    &&
    $_GET['action'] === 'get_existing_asset'
) {

    header(
        'Content-Type: application/json; charset=utf-8'
    );


    $requestedAssetId =
        (int)(
            $_GET['asset_id'] ?? 0
        );


    if ($requestedAssetId <= 0) {

        echo json_encode([
            'success' => false,
            'message' => 'Invalid asset selected.'
        ]);

        exit;
    }


    /*
     * SECURITY:
     * Only load assets belonging to this owner.
     */
    $assetStmt = $conn->prepare("
        SELECT
            id,
            asset_name,
            location,
            state,
            asset_type,
            category
        FROM assets
        WHERE id = ?
          AND owner_id = ?
        LIMIT 1
    ");


    if (!$assetStmt) {

        echo json_encode([
            'success' => false,
            'message' => 'Unable to prepare asset lookup.'
        ]);

        exit;
    }


    $assetStmt->bind_param(
        'ii',
        $requestedAssetId,
        $owner_id
    );


    if (!$assetStmt->execute()) {

        $assetStmt->close();

        echo json_encode([
            'success' => false,
            'message' => 'Unable to load selected asset.'
        ]);

        exit;
    }


    $asset =
        $assetStmt
        ->get_result()
        ->fetch_assoc();


    $assetStmt->close();


    if (!$asset) {

        echo json_encode([
            'success' => false,
            'message' => 'Selected asset was not found.'
        ]);

        exit;
    }


    /*
     * Technical details.
     */
    $detailStmt = $conn->prepare("
        SELECT *
        FROM asset_details
        WHERE asset_id = ?
        LIMIT 1
    ");


    if (!$detailStmt) {

        echo json_encode([
            'success' => false,
            'message' => 'Unable to prepare asset details lookup.'
        ]);

        exit;
    }


    $detailStmt->bind_param(
        'i',
        $requestedAssetId
    );


    if (!$detailStmt->execute()) {

        $detailStmt->close();

        echo json_encode([
            'success' => false,
            'message' => 'Unable to load technical details.'
        ]);

        exit;
    }


    $details =
        $detailStmt
        ->get_result()
        ->fetch_assoc();


    $detailStmt->close();


    if (!$details) {
        $details = [];
    }


    /*
     * Installation year.
     */
    $installationYear = '';

    if (
        !empty(
            $details['installation_date']
            ?? ''
        )
    ) {

        $timestamp =
            strtotime(
                (string)$details['installation_date']
            );

        if ($timestamp !== false) {

            $installationYear =
                date(
                    'Y',
                    $timestamp
                );
        }
    }


    echo json_encode([

        'success' => true,

        'asset' => [

            /*
             * Returned only for the message.
             * JavaScript will NOT copy this into
             * the new Asset Name field.
             */
            'asset_name' =>
                (string)(
                    $asset['asset_name']
                    ?? ''
                ),

            'location' =>
                (string)(
                    $asset['location']
                    ?? ''
                ),

            'state' =>
                (string)(
                    $asset['state']
                    ?? ''
                ),

            'asset_type' =>
                (string)(
                    $asset['asset_type']
                    ?? ''
                ),

            'lift_type' =>
                (string)(
                    $details['lift_type']
                    ?? ''
                ),

            'passenger_capacity' =>
                $details['passenger_capacity']
                ?? '',

            'load_capacity' =>
                $details['load_capacity']
                ?? '',

            'machine_type' =>
                (string)(
                    $details['machine_type']
                    ?? ''
                ),

            'number_of_floors' =>
                $details['number_of_floors']
                ?? '',

            'floor_service_type' =>
                (string)(
                    $details['floor_service_type']
                    ?? ''
                ),

            'served_floors' =>
                (string)(
                    $details['served_floors']
                    ?? ''
                ),

            'floor_interval' =>
                $details['floor_interval']
                ?? '',

            'escalator_type' =>
                (string)(
                    $details['escalator_type']
                    ?? ''
                ),

            'escalator_rise' =>
                $details['escalator_rise']
                ?? '',

            'installation_year' =>
                $installationYear,

            'modification_year' =>
                $details['modification_year']
                ?? '',

            'consignee_name' =>
                (string)(
                    $details['consignee_name']
                    ?? ''
                )
        ]
    ]);

    exit;
}


/* =========================================================
   FORM VALUES
========================================================= */

$assetName =
    trim(
        (string)(
            $_POST['asset_name'] ?? ''
        )
    );


$location =
    trim(
        (string)(
            $_POST['location'] ?? ''
        )
    );


$state =
    trim(
        (string)(
            $_POST['state'] ?? ''
        )
    );


$assetType =
    trim(
        (string)(
            $_POST['asset_type'] ?? ''
        )
    );


$liftType =
    trim(
        (string)(
            $_POST['lift_type'] ?? ''
        )
    );


$passengerCapacity =
    trim(
        (string)(
            $_POST['passenger_capacity'] ?? ''
        )
    );


$loadCapacity =
    trim(
        (string)(
            $_POST['load_capacity'] ?? ''
        )
    );


$machineType =
    trim(
        (string)(
            $_POST['machine_type'] ?? ''
        )
    );


$escalatorType =
    trim(
        (string)(
            $_POST['escalator_type'] ?? ''
        )
    );


$escalatorRise =
    trim(
        (string)(
            $_POST['escalator_rise'] ?? ''
        )
    );


$numberOfFloors =
    trim(
        (string)(
            $_POST['number_of_floors'] ?? ''
        )
    );


$floorServiceType =
    trim(
        (string)(
            $_POST['floor_service_type'] ?? ''
        )
    );


$servedFloors =
    trim(
        (string)(
            $_POST['served_floors'] ?? ''
        )
    );


$floorInterval =
    trim(
        (string)(
            $_POST['floor_interval'] ?? ''
        )
    );


$installationYear =
    trim(
        (string)(
            $_POST['installation_year'] ?? ''
        )
    );


$modificationYear =
    trim(
        (string)(
            $_POST['modification_year'] ?? ''
        )
    );


$consigneeName =
    trim(
        (string)(
            $_POST['consignee_name']
            ?? $ownerName
            ?? ''
        )
    );


$error = '';


/* =========================================================
   FLOOR BUILDER
========================================================= */

function buildServedFloors(
    int $totalFloors,
    string $serviceType,
    string $specificFloors = '',
    int $interval = 0
): array {

    if ($totalFloors <= 0) {
        return [];
    }


    $floors = [];


    /*
     * Number of floors includes Ground.
     *
     * Example:
     * 10 floors = G, 1 ... 9
     */
    $maxNumberedFloor =
        max(
            0,
            $totalFloors - 1
        );


    switch ($serviceType) {


        case 'All Floors':

            $floors[] = 'G';


            for (
                $i = 1;
                $i <= $maxNumberedFloor;
                $i++
            ) {

                $floors[] =
                    (string)$i;
            }

            break;


        case 'Odd Floors':

            $floors[] = 'G';


            for (
                $i = 1;
                $i <= $maxNumberedFloor;
                $i += 2
            ) {

                $floors[] =
                    (string)$i;
            }

            break;


        case 'Even Floors':

            $floors[] = 'G';


            for (
                $i = 2;
                $i <= $maxNumberedFloor;
                $i += 2
            ) {

                $floors[] =
                    (string)$i;
            }

            break;


        case 'Every Nth Floor':

            if ($interval <= 0) {
                return [];
            }


            $floors[] = 'G';


            for (
                $i = $interval;
                $i <= $maxNumberedFloor;
                $i += $interval
            ) {

                $floors[] =
                    (string)$i;
            }

            break;


        case 'Specific Floors':

            $parts =
                preg_split(
                    '/[,;\s]+/',
                    $specificFloors
                );


            foreach (
                $parts as $part
            ) {

                $part =
                    trim($part);


                if ($part === '') {
                    continue;
                }


                if (
                    strtoupper($part)
                    === 'GROUND'
                ) {

                    $part = 'G';
                }


                if (
                    strtoupper($part)
                    === 'G'
                ) {

                    $floors[] = 'G';

                    continue;
                }


                if (
                    ctype_digit($part)
                ) {

                    $floorNumber =
                        (int)$part;


                    if (
                        $floorNumber >= 1
                        &&
                        $floorNumber <=
                        $maxNumberedFloor
                    ) {

                        $floors[] =
                            (string)$floorNumber;
                    }
                }
            }

            break;
    }


    return array_values(
        array_unique(
            $floors
        )
    );
}


/* =========================================================
   POST
========================================================= */

if (
    $_SERVER['REQUEST_METHOD']
    === 'POST'
) {


    /* =====================================================
       CSRF
    ===================================================== */

    $postedToken =
        (string)(
            $_POST['csrf_token'] ?? ''
        );


    if (
        function_exists(
            'validateCSRFToken'
        )
    ) {

        if (
            !validateCSRFToken(
                $postedToken
            )
        ) {

            $error =
                'Invalid request. Please try again.';
        }

    } else {

        if (
            !$postedToken
            ||
            !hash_equals(
                $csrfToken,
                $postedToken
            )
        ) {

            $error =
                'Security validation failed. Please try again.';
        }
    }


    /* =====================================================
       BASIC
    ===================================================== */

    if (
        $error === ''
        &&
        $assetName === ''
    ) {

        $error =
            'Asset Name is required.';
    }


    if (
        $error === ''
        &&
        strlen($assetName) > 150
    ) {

        $error =
            'Asset Name is too long.';
    }


    if (
        $error === ''
        &&
        $location === ''
    ) {

        $error =
            'Location is required.';
    }


    if (
        $error === ''
        &&
        strlen($location) > 255
    ) {

        $error =
            'Location is too long.';
    }


    if (
        $error === ''
        &&
        !isset(
            $stateCodes[$state]
        )
    ) {

        $error =
            'Please select a valid State.';
    }


    if (
        $error === ''
        &&
        !in_array(
            $assetType,
            $allowedAssetTypes,
            true
        )
    ) {

        $error =
            'Please select Lift or Escalator.';
    }


    /* =====================================================
       LIFT
    ===================================================== */

    if (
        $error === ''
        &&
        $assetType === 'Lift'
    ) {


        if (
            !in_array(
                $liftType,
                $liftTypes,
                true
            )
        ) {

            $error =
                'Please select a valid Lift Type.';
        }


        if (
            $error === ''
            &&
            (
                $numberOfFloors === ''
                ||
                !ctype_digit(
                    $numberOfFloors
                )
                ||
                (int)$numberOfFloors < 1
                ||
                (int)$numberOfFloors > 200
            )
        ) {

            $error =
                'Number of Floors / Levels Served must be between 1 and 200.';
        }


        if (
            $error === ''
            &&
            !in_array(
                $floorServiceType,
                $floorServiceTypes,
                true
            )
        ) {

            $error =
                'Please select Floor Service Pattern.';
        }


        /* -----------------------------------------------
           NTH FLOOR
        ------------------------------------------------ */

        if (
            $error === ''
            &&
            $floorServiceType ===
                'Every Nth Floor'
            &&
            (
                $floorInterval === ''
                ||
                !ctype_digit(
                    $floorInterval
                )
                ||
                (int)$floorInterval < 1
                ||
                (int)$floorInterval > 200
            )
        ) {

            $error =
                'Please enter a valid Every Nth Floor interval.';
        }


        /* -----------------------------------------------
           SPECIFIC FLOORS
        ------------------------------------------------ */

        if (
            $error === ''
            &&
            $floorServiceType ===
                'Specific Floors'
            &&
            $servedFloors === ''
        ) {

            $error =
                'Please enter the specific floors served.';
        }


        /* -----------------------------------------------
           PASSENGER
        ------------------------------------------------ */

        if (
            $error === ''
            &&
            in_array(
                $liftType,
                $passengerBasedLiftTypes,
                true
            )
            &&
            (
                $passengerCapacity === ''
                ||
                !ctype_digit(
                    $passengerCapacity
                )
                ||
                (int)$passengerCapacity < 1
                ||
                (int)$passengerCapacity > 100
            )
        ) {

            $error =
                'Passenger Capacity must be between 1 and 100.';
        }


        /*
         * Auto calculate only if empty.
         *
         * Manual value is preserved.
         */
        if (
            $error === ''
            &&
            $loadCapacity === ''
            &&
            in_array(
                $liftType,
                $passengerBasedLiftTypes,
                true
            )
            &&
            $passengerCapacity !== ''
            &&
            ctype_digit(
                $passengerCapacity
            )
        ) {

            $loadCapacity =
                (string)(
                    (int)$passengerCapacity
                    * 68
                );
        }


        /* -----------------------------------------------
           LOAD
        ------------------------------------------------ */

        if (
            $error === ''
            &&
            (
                $loadCapacity === ''
                ||
                !is_numeric(
                    $loadCapacity
                )
                ||
                (float)$loadCapacity <= 0
                ||
                (float)$loadCapacity > 10000
            )
        ) {

            $error =
                'Please enter a valid Rated Load Capacity in kg.';
        }


        /* -----------------------------------------------
           MACHINE
        ------------------------------------------------ */

        if (
            $error === ''
            &&
            !in_array(
                $machineType,
                $machineTypes,
                true
            )
        ) {

            $error =
                'Please select a valid Machine Type.';
        }
    }


    /* =====================================================
       ESCALATOR
    ===================================================== */

    if (
        $error === ''
        &&
        $assetType === 'Escalator'
    ) {


        if (
            !in_array(
                $escalatorType,
                $escalatorTypes,
                true
            )
        ) {

            $error =
                'Please select a valid Escalator Type.';
        }


        if (
            $error === ''
            &&
            (
                $escalatorRise === ''
                ||
                !is_numeric(
                    $escalatorRise
                )
                ||
                (float)$escalatorRise <= 0
                ||
                (float)$escalatorRise > 50
            )
        ) {

            $error =
                'Escalator Rise must be between 0.1 and 50 metres.';
        }
    }


    /* =====================================================
       INSTALLATION
    ===================================================== */

    if (
        $error === ''
        &&
        (
            $installationYear === ''
            ||
            !ctype_digit(
                $installationYear
            )
            ||
            (int)$installationYear <
                $minInstallationYear
            ||
            (int)$installationYear >
                $currentYear
        )
    ) {

        $error =
            'Please enter a valid Installation Year.';
    }


    /* =====================================================
       MODIFICATION
    ===================================================== */

    if (
        $error === ''
        &&
        $modificationYear !== ''
        &&
        (
            !ctype_digit(
                $modificationYear
            )
            ||
            (int)$modificationYear <
                (int)$installationYear
            ||
            (int)$modificationYear >
                $currentYear
        )
    ) {

        $error =
            'Modification Year must be between Installation Year and current year.';
    }


    /* =====================================================
       CONSIGNEE
    ===================================================== */

    if (
        $error === ''
        &&
        $consigneeName === ''
    ) {

        $consigneeName =
            $ownerName;
    }


    /* =====================================================
       SERVED FLOORS
    ===================================================== */

    $generatedServedFloors = [];


    if (
        $error === ''
        &&
        $assetType === 'Lift'
    ) {

        $generatedServedFloors =
            buildServedFloors(
                (int)$numberOfFloors,
                $floorServiceType,
                $servedFloors,
                (int)$floorInterval
            );


        if (
            empty(
                $generatedServedFloors
            )
        ) {

            $error =
                'Unable to generate the served floor list.';
        }
    }


    /* =====================================================
       CREATE
    ===================================================== */

    if ($error === '') {


        /* ================================================
           OWNER CODE
        ================================================= */

        $ownerClean =
            preg_replace(
                '/[^A-Za-z0-9\s]/',
                ' ',
                $ownerName
            );


        $ownerClean =
            preg_replace(
                '/\s+/',
                ' ',
                trim(
                    (string)$ownerClean
                )
            );


        $words =
            $ownerClean !== ''
            ? preg_split(
                '/\s+/',
                $ownerClean
            )
            : [];


        $ownerCode = '';


        foreach (
            $words as $word
        ) {

            if (
                strtoupper($word)
                === 'RWA'
            ) {

                continue;
            }


            $cleanWord =
                preg_replace(
                    '/[^A-Za-z0-9]/',
                    '',
                    $word
                );


            if (
                $cleanWord !== ''
            ) {

                $ownerCode .=
                    strtoupper(
                        substr(
                            $cleanWord,
                            0,
                            1
                        )
                    );
            }


            if (
                strlen(
                    $ownerCode
                ) >= 4
            ) {

                break;
            }
        }


        if (
            $ownerCode === ''
        ) {

            $ownerCode =
                'AST';
        }


        $stateCode =
            $stateCodes[$state]
            ?? 'NA';


        $category =
            $assetType === 'Escalator'
            ? 'Escalator'
            : 'Lift';


        /* ================================================
           TRANSACTION
        ================================================= */

        $conn->begin_transaction();


        try {


            /* =============================================
               INSERT ASSET
            ============================================== */

            $stmt =
                $conn->prepare("
                    INSERT INTO assets
                    (
                        asset_name,
                        location,
                        state,
                        asset_type,
                        category,
                        owner_id,
                        status,
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
                        'Active',
                        NOW()
                    )
                ");


            if (!$stmt) {

                throw new Exception(
                    'Unable to prepare asset creation: '
                    . $conn->error
                );
            }


            $stmt->bind_param(
                'sssssi',
                $assetName,
                $location,
                $state,
                $assetType,
                $category,
                $owner_id
            );


            if (
                !$stmt->execute()
            ) {

                $message =
                    $stmt->error;

                $stmt->close();


                throw new Exception(
                    'Unable to create asset: '
                    . $message
                );
            }


            $assetId =
                (int)$conn->insert_id;


            $stmt->close();


            if (
                $assetId <= 0
            ) {

                throw new Exception(
                    'Invalid Asset ID generated.'
                );
            }


            /* =============================================
               DISPLAY ASSET ID
            ============================================== */

            $displayAssetId =
                $ownerCode
                . '-'
                . $stateCode
                . '-'
                . str_pad(
                    (string)$assetId,
                    3,
                    '0',
                    STR_PAD_LEFT
                );


            $update =
                $conn->prepare("
                    UPDATE assets
                    SET display_asset_id = ?
                    WHERE id = ?
                      AND owner_id = ?
                ");


            if (!$update) {

                throw new Exception(
                    'Unable to prepare Asset ID update: '
                    . $conn->error
                );
            }


            $update->bind_param(
                'sii',
                $displayAssetId,
                $assetId,
                $owner_id
            );


            if (
                !$update->execute()
            ) {

                $message =
                    $update->error;

                $update->close();


                throw new Exception(
                    'Unable to save Asset ID: '
                    . $message
                );
            }


            $update->close();


            /* =============================================
               CHECK ASSET DETAILS COLUMNS
            ============================================== */

            $columnResult =
                $conn->query(
                    "SHOW COLUMNS FROM asset_details"
                );


            if (!$columnResult) {

                throw new Exception(
                    'Unable to inspect asset_details: '
                    . $conn->error
                );
            }


            $existingColumns = [];


            while (
                $column =
                $columnResult->fetch_assoc()
            ) {

                $existingColumns[] =
                    (string)$column['Field'];
            }


            $detailValues = [];


            /* =============================================
               ASSET ID
            ============================================== */

            if (
                in_array(
                    'asset_id',
                    $existingColumns,
                    true
                )
            ) {

                $detailValues['asset_id'] =
                    $assetId;
            }


            /* =============================================
               INSTALLATION
            ============================================== */

            if (
                in_array(
                    'installation_date',
                    $existingColumns,
                    true
                )
            ) {

                $detailValues['installation_date'] =
                    $installationYear
                    . '-01-01';
            }


            /* =============================================
               FLOOR COUNT
            ============================================== */

            if (
                in_array(
                    'number_of_floors',
                    $existingColumns,
                    true
                )
            ) {

                $detailValues['number_of_floors'] =
                    $assetType === 'Lift'
                    ? (int)$numberOfFloors
                    : null;
            }


            /* =============================================
               FLOOR SERVICE TYPE
            ============================================== */

            if (
                in_array(
                    'floor_service_type',
                    $existingColumns,
                    true
                )
            ) {

                $detailValues['floor_service_type'] =
                    $assetType === 'Lift'
                    ? $floorServiceType
                    : null;
            }


            /* =============================================
               SERVED FLOORS
            ============================================== */

            if (
                in_array(
                    'served_floors',
                    $existingColumns,
                    true
                )
            ) {

                $detailValues['served_floors'] =
                    $assetType === 'Lift'
                    ? implode(
                        ',',
                        $generatedServedFloors
                    )
                    : null;
            }


            /* =============================================
               FLOOR INTERVAL
            ============================================== */

            if (
                in_array(
                    'floor_interval',
                    $existingColumns,
                    true
                )
            ) {

                $detailValues['floor_interval'] =
                    (
                        $assetType === 'Lift'
                        &&
                        $floorServiceType ===
                            'Every Nth Floor'
                    )
                    ? (int)$floorInterval
                    : null;
            }


            /* =============================================
               LIFT TYPE
            ============================================== */

            if (
                in_array(
                    'lift_type',
                    $existingColumns,
                    true
                )
            ) {

                $detailValues['lift_type'] =
                    $assetType === 'Lift'
                    ? $liftType
                    : null;
            }


            /* =============================================
               PASSENGER CAPACITY
            ============================================== */

            if (
                in_array(
                    'passenger_capacity',
                    $existingColumns,
                    true
                )
            ) {

                $detailValues['passenger_capacity'] =
                    $assetType === 'Lift'
                    &&
                    in_array(
                        $liftType,
                        $passengerBasedLiftTypes,
                        true
                    )
                    ? (int)$passengerCapacity
                    : null;
            }


            /* =============================================
               RATED LOAD
            ============================================== */

            if (
                in_array(
                    'load_capacity',
                    $existingColumns,
                    true
                )
            ) {

                /*
                 * Save exactly what the owner entered.
                 */
                $detailValues['load_capacity'] =
                    $assetType === 'Lift'
                    ? (float)$loadCapacity
                    : null;
            }


            /* =============================================
               MACHINE
            ============================================== */

            if (
                in_array(
                    'machine_type',
                    $existingColumns,
                    true
                )
            ) {

                $detailValues['machine_type'] =
                    $assetType === 'Lift'
                    ? $machineType
                    : null;
            }


            /* =============================================
               ESCALATOR TYPE
            ============================================== */

            if (
                in_array(
                    'escalator_type',
                    $existingColumns,
                    true
                )
            ) {

                $detailValues['escalator_type'] =
                    $assetType === 'Escalator'
                    ? $escalatorType
                    : null;
            }


            /* =============================================
               ESCALATOR RISE
            ============================================== */

            if (
                in_array(
                    'escalator_rise',
                    $existingColumns,
                    true
                )
            ) {

                $detailValues['escalator_rise'] =
                    $assetType === 'Escalator'
                    ? (float)$escalatorRise
                    : null;
            }


            /* =============================================
               MODIFICATION YEAR
            ============================================== */

            if (
                in_array(
                    'modification_year',
                    $existingColumns,
                    true
                )
            ) {

                $detailValues['modification_year'] =
                    $modificationYear !== ''
                    ? (int)$modificationYear
                    : null;
            }


            /* =============================================
               CONSIGNEE
            ============================================== */

            if (
                in_array(
                    'consignee_name',
                    $existingColumns,
                    true
                )
            ) {

                $detailValues['consignee_name'] =
                    $consigneeName;
            }


            if (
                !isset(
                    $detailValues['asset_id']
                )
            ) {

                throw new Exception(
                    'asset_details.asset_id column is required.'
                );
            }


            /* =============================================
               DYNAMIC INSERT
            ============================================== */

            $detailColumns =
                array_keys(
                    $detailValues
                );


            $placeholders =
                array_fill(
                    0,
                    count($detailColumns),
                    '?'
                );


            $sql =
                "INSERT INTO asset_details ("
                .
                implode(
                    ', ',
                    array_map(
                        function ($column) {

                            return '`'
                                . $column
                                . '`';

                        },
                        $detailColumns
                    )
                )
                .
                ")
                VALUES ("
                .
                implode(
                    ', ',
                    $placeholders
                )
                .
                ")";


            $detailsStmt =
                $conn->prepare($sql);


            if (!$detailsStmt) {

                throw new Exception(
                    'Unable to prepare Asset Details: '
                    . $conn->error
                );
            }


            $types = '';
            $bindValues = [];


            foreach (
                $detailColumns as $column
            ) {

                $value =
                    $detailValues[$column];


                if (
                    $column === 'asset_id'
                    ||
                    $column === 'number_of_floors'
                    ||
                    $column === 'floor_interval'
                    ||
                    $column === 'passenger_capacity'
                    ||
                    $column === 'modification_year'
                ) {

                    $types .= 'i';


                    $bindValues[] =
                        $value === null
                        ? 0
                        : (int)$value;


                } elseif (
                    $column === 'load_capacity'
                    ||
                    $column === 'escalator_rise'
                ) {

                    $types .= 'd';


                    $bindValues[] =
                        $value === null
                        ? 0
                        : (float)$value;


                } else {

                    $types .= 's';


                    $bindValues[] =
                        $value === null
                        ? null
                        : (string)$value;
                }
            }


            $bindReferences = [];

            $bindReferences[] =
                $types;


            foreach (
                $bindValues as $key => $value
            ) {

                $bindReferences[] =
                    &$bindValues[$key];
            }


            call_user_func_array(
                [
                    $detailsStmt,
                    'bind_param'
                ],
                $bindReferences
            );


            if (
                !$detailsStmt->execute()
            ) {

                $message =
                    $detailsStmt->error;

                $detailsStmt->close();


                throw new Exception(
                    'Unable to save Asset Details: '
                    . $message
                );
            }


            $detailsStmt->close();


            /* =============================================
               COMMIT
            ============================================== */

            $conn->commit();


            $_SESSION[
                'phase4_new_asset_id'
            ] =
                $assetId;


            $_SESSION[
                'phase4_new_asset_display_id'
            ] =
                $displayAssetId;


            $_SESSION['success'] =
                $assetType
                . ' added successfully. Asset ID: '
                . $displayAssetId;


            header(
                'Location: service-lifts.php'
            );

            exit;


        } catch (
            Throwable $e
        ) {

            $conn->rollback();

            $error =
                $e->getMessage();
        }
    }
}


/* =========================================================
   PAGE
========================================================= */

include('../includes/header.php');
include('../includes/sidebar-owner.php');

?>

<style>

/* =========================================================
   PAGE
========================================================= */

.owner-add-asset {

    max-width: 1100px;

    margin: 0 auto;

    padding:
        24px
        0
        40px;

}


/* =========================================================
   CARDS
========================================================= */

.asset-header,
.asset-card {

    background: #fff;

    border:
        1px solid
        #e1e5ea;

    border-radius:
        16px;

    box-shadow:
        0 4px 18px
        rgba(
            0,
            0,
            0,
            .06
        );

}


.asset-header {

    padding:
        24px;

    margin-bottom:
        18px;

}


.asset-header h3 {

    font-weight:
        700;

}


.asset-card {

    padding:
        26px;

}


/* =========================================================
   SECTION
========================================================= */

.section-title {

    margin-top:
        26px;

    margin-bottom:
        14px;

    padding-bottom:
        9px;

    border-bottom:
        1px solid
        #dee2e6;

    font-size:
        17px;

    font-weight:
        700;

}


.required {

    color:
        #dc3545;

}


/* =========================================================
   ASSET TYPE
========================================================= */

.asset-type-card {

    display:
        block;

    border:
        1px solid
        #d9dee5;

    border-radius:
        14px;

    padding:
        22px;

    cursor:
        pointer;

    transition:
        .2s;

    height:
        100%;

}


.asset-type-card:hover {

    border-color:
        #0d6efd;

    background:
        #f8fbff;

}


.asset-type-card.selected {

    border-color:
        #0d6efd;

    background:
        #eef5ff;

    box-shadow:
        0 0 0 2px
        rgba(
            13,
            110,
            253,
            .08
        );

}


.asset-type-card input {

    display:
        none;

}


.asset-type-icon {

    font-size:
        36px;

    margin-bottom:
        8px;

}


/* =========================================================
   TECHNICAL SECTIONS
========================================================= */

.asset-specific-section {

    background:
        #f8fbff;

    border:
        1px solid
        #e1ebf5;

    border-radius:
        13px;

    padding:
        20px;

}


/* =========================================================
   LOAD
========================================================= */

.auto-load-box {

    background:
        #eaf4ff;

    border:
        1px solid
        #b9dcff;

    border-radius:
        10px;

    padding:
        10px
        13px;

    margin-top:
        7px;

    color:
        #174a7c;

}


/* =========================================================
   COPY
========================================================= */

.copy-asset-box {

    background:
        #f7f9fc;

    border:
        1px solid
        #dce3ec;

    border-radius:
        13px;

    padding:
        20px;

    margin-bottom:
        24px;

}


.copy-icon {

    width:
        44px;

    height:
        44px;

    border-radius:
        10px;

    background:
        #eaf2ff;

    color:
        #0d6efd;

    display:
        flex;

    align-items:
        center;

    justify-content:
        center;

    font-size:
        21px;

    flex-shrink:
        0;

}


.copy-status {

    display:
        none;

    border-radius:
        9px;

    padding:
        10px
        13px;

    margin-top:
        12px;

    font-size:
        13px;

}


.copy-status.success {

    display:
        block;

    background:
        #eaf8ef;

    border:
        1px solid
        #bce3ca;

    color:
        #176b37;

}


.copy-status.error {

    display:
        block;

    background:
        #fff0f0;

    border:
        1px solid
        #f1bcbc;

    color:
        #9c1c1c;

}


.copy-status.loading {

    display:
        block;

    background:
        #eef5ff;

    border:
        1px solid
        #c9dcff;

    color:
        #174a7c;

}


/* =========================================================
   HELP
========================================================= */

.form-help {

    color:
        #6c757d;

    font-size:
        12px;

}


/* =========================================================
   BUTTON
========================================================= */

#saveBtn {

    cursor:
        pointer;

}


/* =========================================================
   MOBILE
========================================================= */

@media(max-width: 768px) {

    .owner-add-asset {

        padding:
            10px;

    }


    .asset-header,
    .asset-card {

        padding:
            18px;

        border-radius:
            12px;

    }


    .copy-asset-box {

        padding:
            16px;

    }

}

</style>


<div class="content">

<div class="owner-add-asset">


<!-- =====================================================
     HEADER
====================================================== -->

<div class="asset-header">

    <div
        class="
            d-flex
            justify-content-between
            align-items-start
            gap-3
            flex-wrap
        "
    >

        <div>

            <h3 class="mb-1">

                <i
                    class="
                        bi
                        bi-plus-circle
                        me-2
                    "
                ></i>

                Add Lift / Escalator

            </h3>


            <p class="text-muted mb-0">

                Register your Lift or Escalator with NLETA.

            </p>

        </div>


        <a
            href="service-lifts.php"
            class="btn btn-outline-secondary"
        >

            <i class="bi bi-arrow-left me-1"></i>

            Back

        </a>

    </div>

</div>


<!-- =====================================================
     ERROR
====================================================== -->

<?php if ($error !== ''): ?>

<div
    class="
        alert
        alert-danger
        alert-dismissible
        fade
        show
    "
>

    <i
        class="
            bi
            bi-exclamation-triangle-fill
            me-2
        "
    ></i>

    <?= h($error) ?>


    <button
        type="button"
        class="btn-close"
        data-bs-dismiss="alert"
    ></button>

</div>

<?php endif; ?>


<!-- =====================================================
     FORM CARD
====================================================== -->

<div class="asset-card">


<form
    method="POST"
    id="addAssetForm"
    autocomplete="off"
>


<?php

if (
    function_exists('csrfField')
) {

    echo csrfField();

} else {

?>

<input
    type="hidden"
    name="csrf_token"
    value="<?= h($csrfToken) ?>"
>

<?php

}

?>


<!-- =====================================================
     EXISTING ASSET
====================================================== -->

<div class="copy-asset-box">

    <div class="d-flex align-items-start gap-3">

        <div class="copy-icon">

            <i class="bi bi-copy"></i>

        </div>


        <div>

            <div class="fw-bold fs-5">

                Use Existing Asset Details

            </div>


            <div class="form-help mt-1">

                Select one of your existing assets to
                automatically fill its details into this
                new asset.

            </div>

        </div>

    </div>


    <div class="row g-3 mt-1">


        <div class="col-md-9">

            <label
                class="form-label fw-semibold"
                for="existingAssetSelect"
            >

                Select Existing Asset

            </label>


            <select
                id="existingAssetSelect"
                class="form-select"
            >

                <option value="">

                    -- Select Existing Asset --

                </option>


                <?php foreach (
                    $existingAssets as $existingAsset
                ): ?>

                <option
                    value="<?= (int)$existingAsset['id'] ?>"
                >

                    <?= h(
                        $existingAsset['display_asset_id']
                        ?: (
                            'Asset #'
                            .
                            $existingAsset['id']
                        )
                    ) ?>

                    -
                    <?= h(
                        $existingAsset['asset_name']
                    ) ?>

                    -
                    <?= h(
                        $existingAsset['asset_type']
                    ) ?>

                    -
                    <?= h(
                        $existingAsset['location']
                    ) ?>

                </option>

                <?php endforeach; ?>

            </select>


            <div class="form-help mt-1">

                Only assets belonging to your account
                are available.

            </div>

        </div>


        <div class="col-md-3 d-flex align-items-end">

            <button
                type="button"
                id="loadExistingAssetBtn"
                class="btn btn-outline-primary w-100"
            >

                <i
                    class="
                        bi
                        bi-arrow-down-circle
                        me-1
                    "
                ></i>

                Load Details

            </button>

        </div>

    </div>


    <div
        id="copyStatus"
        class="copy-status"
    ></div>

</div>


<!-- =====================================================
     OWNER
====================================================== -->

<div class="section-title">

    <i class="bi bi-person-badge me-2"></i>

    Owner Information

</div>


<div class="mb-3">

    <label
        class="form-label fw-semibold"
        for="consigneeName"
    >

        Owner / Consignee Name

        <span class="required">*</span>

    </label>


    <input
        type="text"
        name="consignee_name"
        id="consigneeName"
        class="form-control"
        maxlength="150"
        value="<?= h(
            $consigneeName !== ''
            ? $consigneeName
            : $ownerName
        ) ?>"
        placeholder="Owner / Consignee Name"
        required
    >

</div>


<!-- =====================================================
     ASSET TYPE
====================================================== -->

<div class="section-title">

    <i class="bi bi-buildings me-2"></i>

    1. Select Asset Type

</div>


<div class="row g-3">


    <div class="col-md-6">

        <label
            class="
                asset-type-card
                <?= $assetType === 'Lift'
                    ? 'selected'
                    : '' ?>
            "
            id="liftCard"
        >

            <input
                type="radio"
                name="asset_type"
                value="Lift"
                <?= $assetType === 'Lift'
                    ? 'checked'
                    : '' ?>
                required
            >


            <div class="asset-type-icon">

                🛗

            </div>


            <strong>

                Lift

            </strong>


            <div class="form-help mt-1">

                Passenger, Goods, Passenger Cum Goods,
                Hospital, Service and other lifts.

            </div>

        </label>

    </div>


    <div class="col-md-6">

        <label
            class="
                asset-type-card
                <?= $assetType === 'Escalator'
                    ? 'selected'
                    : '' ?>
            "
            id="escalatorCard"
        >

            <input
                type="radio"
                name="asset_type"
                value="Escalator"
                <?= $assetType === 'Escalator'
                    ? 'checked'
                    : '' ?>
                required
            >


            <div class="asset-type-icon">

                ↗️

            </div>


            <strong>

                Escalator

            </strong>


            <div class="form-help mt-1">

                Travelator, Commercial, Heavy Duty
                and Other.

            </div>

        </label>

    </div>

</div>


<!-- =====================================================
     BASIC
====================================================== -->

<div class="section-title">

    <i class="bi bi-building me-2"></i>

    2. Basic Asset Information

</div>


<div class="mb-3">

    <label
        class="form-label fw-semibold"
        for="assetName"
    >

        Asset Name

        <span class="required">*</span>

    </label>


    <input
        type="text"
        name="asset_name"
        id="assetName"
        class="form-control"
        maxlength="150"
        value="<?= h($assetName) ?>"
        placeholder="Example: Lift A"
        required
    >


    <div class="form-help mt-1">

        Enter the name for the new asset.
        Existing Asset Name is never copied.

    </div>

</div>


<div class="mb-3">

    <label
        class="form-label fw-semibold"
        for="location"
    >

        Location

        <span class="required">*</span>

    </label>


    <input
        type="text"
        name="location"
        id="location"
        class="form-control"
        maxlength="255"
        value="<?= h($location) ?>"
        placeholder="Example: Tower A, Main Entrance"
        required
    >

</div>


<div class="mb-3">

    <label
        class="form-label fw-semibold"
        for="state"
    >

        State

        <span class="required">*</span>

    </label>


    <select
        name="state"
        id="state"
        class="form-select"
        required
    >

        <option value="">

            Select State

        </option>


        <?php foreach (
            $stateCodes as $stateName => $stateCode
        ): ?>

        <option
            value="<?= h($stateName) ?>"
            <?= $state === $stateName
                ? 'selected'
                : '' ?>
        >

            <?= h($stateName) ?>

        </option>

        <?php endforeach; ?>

    </select>

</div>


<!-- =====================================================
     LIFT
====================================================== -->

<div
    id="liftDetails"
    class="asset-specific-section"
>


    <div class="section-title mt-0">

        <i class="bi bi-building-check me-2"></i>

        Lift Information

    </div>


    <div class="row g-3">


        <!-- LIFT TYPE -->

        <div class="col-md-6">

            <label
                class="form-label fw-semibold"
                for="liftType"
            >

                Lift Type

                <span class="required">*</span>

            </label>


            <select
                name="lift_type"
                id="liftType"
                class="form-select"
            >

                <option value="">

                    Select Lift Type

                </option>


                <?php foreach (
                    $liftTypes as $type
                ): ?>

                <option
                    value="<?= h($type) ?>"
                    <?= $liftType === $type
                        ? 'selected'
                        : '' ?>
                >

                    <?= h($type) ?>

                </option>

                <?php endforeach; ?>

            </select>

        </div>


        <!-- FLOORS -->

        <div class="col-md-6">

            <label
                class="form-label fw-semibold"
                for="numberOfFloors"
            >

                Number of Floors / Levels Served

                <span class="required">*</span>

            </label>


            <input
                type="number"
                name="number_of_floors"
                id="numberOfFloors"
                class="form-control"
                min="1"
                max="200"
                value="<?= h($numberOfFloors) ?>"
                placeholder="Example: 10"
            >

        </div>


        <!-- FLOOR PATTERN -->

        <div class="col-md-6">

            <label
                class="form-label fw-semibold"
                for="floorServiceType"
            >

                Floor Service Pattern

                <span class="required">*</span>

            </label>


            <select
                name="floor_service_type"
                id="floorServiceType"
                class="form-select"
            >

                <option value="">

                    Select Floor Pattern

                </option>


                <?php foreach (
                    $floorServiceTypes as $pattern
                ): ?>

                <option
                    value="<?= h($pattern) ?>"
                    <?= $floorServiceType === $pattern
                        ? 'selected'
                        : '' ?>
                >

                    <?= h($pattern) ?>

                </option>

                <?php endforeach; ?>

            </select>

        </div>


        <!-- EVERY NTH FLOOR -->

        <div
            class="col-md-6"
            id="intervalColumn"
            style="display:none;"
        >

            <label
                class="form-label fw-semibold"
                for="floorInterval"
            >

                Every Nth Floor

                <span class="required">*</span>

            </label>


            <input
                type="number"
                name="floor_interval"
                id="floorInterval"
                class="form-control"
                min="1"
                max="200"
                value="<?= h($floorInterval) ?>"
                placeholder="Example: 2"
                disabled
            >


            <div class="form-help mt-1">

                Example: 2 means every 2nd floor.

            </div>

        </div>


        <!-- SPECIFIC FLOORS -->

        <div
            class="col-md-12"
            id="specificFloorsColumn"
            style="display:none;"
        >

            <label
                class="form-label fw-semibold"
                for="servedFloors"
            >

                Specific Floors Served

                <span class="required">*</span>

            </label>


            <input
                type="text"
                name="served_floors"
                id="servedFloors"
                class="form-control"
                maxlength="500"
                value="<?= h($servedFloors) ?>"
                placeholder="Example: G, 2, 4, 7, 10"
                disabled
            >


            <div class="form-help mt-1">

                Separate floors with commas.
                G or Ground can be used for Ground Floor.

            </div>

        </div>


        <!-- PASSENGER -->

        <div
            class="col-md-6"
            id="passengerColumn"
        >

            <label
                class="form-label fw-semibold"
                for="passengerCapacity"
            >

                Passenger Capacity

                <span class="required">*</span>

            </label>


            <input
                type="number"
                name="passenger_capacity"
                id="passengerCapacity"
                class="form-control"
                min="1"
                max="100"
                value="<?= h($passengerCapacity) ?>"
                placeholder="Example: 13"
            >

        </div>


        <!-- LOAD -->

        <div class="col-md-6">

            <label
                class="form-label fw-semibold"
                for="loadCapacity"
            >

                Rated Load Capacity

                <span class="required">*</span>

            </label>


            <div class="input-group">

                <input
                    type="number"
                    name="load_capacity"
                    id="loadCapacity"
                    class="form-control"
                    min="1"
                    max="10000"
                    step="1"
                    value="<?= h($loadCapacity) ?>"
                    placeholder="Auto / Enter kg"
                >


                <span class="input-group-text">

                    kg

                </span>

            </div>


            <div
                id="autoLoadMessage"
                class="auto-load-box"
                style="display:none;"
            ></div>

        </div>


        <!-- MACHINE -->

        <div class="col-md-6">

            <label
                class="form-label fw-semibold"
                for="machineType"
            >

                Machine Type

                <span class="required">*</span>

            </label>


            <select
                name="machine_type"
                id="machineType"
                class="form-select"
            >

                <option value="">

                    Select Machine Type

                </option>


                <?php foreach (
                    $machineTypes as $machine
                ): ?>

                <option
                    value="<?= h($machine) ?>"
                    <?= $machineType === $machine
                        ? 'selected'
                        : '' ?>
                >

                    <?= h($machine) ?>

                </option>

                <?php endforeach; ?>

            </select>

        </div>


    </div>

</div>


<!-- =====================================================
     ESCALATOR
====================================================== -->

<div
    id="escalatorDetails"
    class="asset-specific-section"
    style="display:none;"
>


    <div class="section-title mt-0">

        <i
            class="
                bi
                bi-arrow-up-right-square
                me-2
            "
        ></i>

        Escalator Information

    </div>


    <div class="row g-3">


        <div class="col-md-6">

            <label
                class="form-label fw-semibold"
                for="escalatorType"
            >

                Escalator Type

                <span class="required">*</span>

            </label>


            <select
                name="escalator_type"
                id="escalatorType"
                class="form-select"
            >

                <option value="">

                    Select Escalator Type

                </option>


                <?php foreach (
                    $escalatorTypes as $type
                ): ?>

                <option
                    value="<?= h($type) ?>"
                    <?= $escalatorType === $type
                        ? 'selected'
                        : '' ?>
                >

                    <?= h($type) ?>

                </option>

                <?php endforeach; ?>

            </select>

        </div>


        <div class="col-md-6">

            <label
                class="form-label fw-semibold"
                for="escalatorRise"
            >

                Escalator Rise

                <span class="required">*</span>

            </label>


            <div class="input-group">

                <input
                    type="number"
                    name="escalator_rise"
                    id="escalatorRise"
                    class="form-control"
                    min="0.1"
                    max="50"
                    step="0.01"
                    value="<?= h($escalatorRise) ?>"
                    placeholder="Example: 4.50"
                >


                <span class="input-group-text">

                    metres

                </span>

            </div>

        </div>

    </div>


    <div class="alert alert-info mt-3 mb-0">

        <i class="bi bi-info-circle me-1"></i>

        Floor-service selection is not used for escalators.

    </div>

</div>


<!-- =====================================================
     INSTALLATION
====================================================== -->

<div class="section-title">

    <i class="bi bi-calendar3 me-2"></i>

    3. Installation Information

</div>


<div class="row g-3">


    <div class="col-md-6">

        <label
            class="form-label fw-semibold"
            for="installationYear"
        >

            Installation Year

            <span class="required">*</span>

        </label>


        <input
            type="number"
            name="installation_year"
            id="installationYear"
            class="form-control"
            min="<?= $minInstallationYear ?>"
            max="<?= $currentYear ?>"
            value="<?= h($installationYear) ?>"
            required
        >

    </div>


    <div class="col-md-6">

        <label
            class="form-label fw-semibold"
            for="modificationYear"
        >

            Modification Year

        </label>


        <input
            type="number"
            name="modification_year"
            id="modificationYear"
            class="form-control"
            min="<?= $minInstallationYear ?>"
            max="<?= $currentYear ?>"
            value="<?= h($modificationYear) ?>"
        >

    </div>

</div>


<!-- =====================================================
     ACTIONS
====================================================== -->

<div
    class="
        d-flex
        justify-content-between
        align-items-center
        gap-2
        mt-4
        flex-wrap
    "
>


    <a
        href="service-lifts.php"
        class="btn btn-outline-secondary"
    >

        <i class="bi bi-arrow-left me-1"></i>

        Cancel

    </a>


    <button
        type="submit"
        id="saveBtn"
        class="btn btn-primary px-4"
    >

        <i class="bi bi-save me-1"></i>

        Save Asset

    </button>


</div>


</form>

</div>

</div>

</div>


<?php include('../includes/footer.php'); ?>


<script>

document.addEventListener(
    'DOMContentLoaded',
    function () {


        /* =================================================
           ELEMENTS
        ================================================= */

        const form =
            document.getElementById(
                'addAssetForm'
            );


        const saveBtn =
            document.getElementById(
                'saveBtn'
            );


        const assetRadios =
            document.querySelectorAll(
                'input[name="asset_type"]'
            );


        const liftCard =
            document.getElementById(
                'liftCard'
            );


        const escalatorCard =
            document.getElementById(
                'escalatorCard'
            );


        const liftDetails =
            document.getElementById(
                'liftDetails'
            );


        const escalatorDetails =
            document.getElementById(
                'escalatorDetails'
            );


        const liftType =
            document.getElementById(
                'liftType'
            );


        const passengerCapacity =
            document.getElementById(
                'passengerCapacity'
            );


        const loadCapacity =
            document.getElementById(
                'loadCapacity'
            );


        const machineType =
            document.getElementById(
                'machineType'
            );


        const numberOfFloors =
            document.getElementById(
                'numberOfFloors'
            );


        const floorServiceType =
            document.getElementById(
                'floorServiceType'
            );


        const floorInterval =
            document.getElementById(
                'floorInterval'
            );


        const intervalColumn =
            document.getElementById(
                'intervalColumn'
            );


        const servedFloors =
            document.getElementById(
                'servedFloors'
            );


        const specificFloorsColumn =
            document.getElementById(
                'specificFloorsColumn'
            );


        const passengerColumn =
            document.getElementById(
                'passengerColumn'
            );


        const autoLoadMessage =
            document.getElementById(
                'autoLoadMessage'
            );


        const escalatorType =
            document.getElementById(
                'escalatorType'
            );


        const escalatorRise =
            document.getElementById(
                'escalatorRise'
            );


        const existingAssetSelect =
            document.getElementById(
                'existingAssetSelect'
            );


        const loadExistingAssetBtn =
            document.getElementById(
                'loadExistingAssetBtn'
            );


        const copyStatus =
            document.getElementById(
                'copyStatus'
            );


        const assetName =
            document.getElementById(
                'assetName'
            );


        const location =
            document.getElementById(
                'location'
            );


        const state =
            document.getElementById(
                'state'
            );


        const consigneeName =
            document.getElementById(
                'consigneeName'
            );


        const installationYear =
            document.getElementById(
                'installationYear'
            );


        const modificationYear =
            document.getElementById(
                'modificationYear'
            );


        /* =================================================
           CONSTANTS
        ================================================= */

        const passengerBasedLiftTypes = [

            'Passenger Lift',
            'Passenger Cum Goods Lift',
            'Hospital Lift'

        ];


        let submitting =
            false;


        let existingAssetLoaded =
            false;


        /* =================================================
           SAFE HTML
        ================================================= */

        function escapeHtml(value) {

            return String(
                value ?? ''
            )
            .replace(
                /&/g,
                '&amp;'
            )
            .replace(
                /</g,
                '&lt;'
            )
            .replace(
                />/g,
                '&gt;'
            )
            .replace(
                /"/g,
                '&quot;'
            )
            .replace(
                /'/g,
                '&#039;'
            );

        }


        /* =================================================
           AUTO LOAD
        ================================================= */

        function calculateLoad() {

            const persons =
                parseInt(
                    passengerCapacity.value,
                    10
                );


            if (
                Number.isFinite(persons)
                &&
                persons > 0
            ) {

                loadCapacity.value =
                    persons * 68;

            }

        }


        /* =================================================
           LIFT TYPE
        ================================================= */

        function updateLiftType(
            preserveLoad = false
        ) {

            const selected =
                liftType.value;


            if (
                passengerBasedLiftTypes.includes(
                    selected
                )
            ) {

                passengerColumn.style.display =
                    'block';


                passengerCapacity.disabled =
                    false;


                passengerCapacity.required =
                    true;


                loadCapacity.disabled =
                    false;


                /*
                 * IMPORTANT:
                 *
                 * NOT readonly.
                 *
                 * User can manually edit it.
                 */
                loadCapacity.readOnly =
                    false;


                loadCapacity.required =
                    true;


                autoLoadMessage.style.display =
                    'block';


                autoLoadMessage.innerHTML =

                    '<small>' +
                    '<i class="bi bi-calculator me-1"></i>' +
                    'Automatic calculation: ' +
                    '<strong>1 passenger = 68 kg</strong>. ' +
                    'You can manually edit the Rated Load Capacity.' +
                    '</small>';


                /*
                 * Do not overwrite an existing copied
                 * value.
                 */
                if (
                    !preserveLoad
                    &&
                    loadCapacity.value === ''
                ) {

                    calculateLoad();

                }

            } else {

                passengerColumn.style.display =
                    'block';


                passengerCapacity.disabled =
                    true;


                passengerCapacity.required =
                    false;


                passengerCapacity.value =
                    '';


                loadCapacity.disabled =
                    false;


                loadCapacity.readOnly =
                    false;


                loadCapacity.required =
                    true;


                autoLoadMessage.style.display =
                    'block';


                autoLoadMessage.innerHTML =

                    '<small>' +
                    '<i class="bi bi-info-circle me-1"></i>' +
                    'Enter the rated load capacity from the equipment specification.' +
                    '</small>';

            }

        }


        /* =================================================
           FLOOR SERVICE
           -------------------------------------------------
           THIS IS THE MAIN FIX
        ================================================= */

        function updateFloorService() {

            const pattern =
                floorServiceType.value;


            const isNthFloor =
                pattern === 'Every Nth Floor';


            const isSpecificFloors =
                pattern === 'Specific Floors';


            /* =============================================
               EVERY NTH FLOOR
            ============================================== */

            if (
                isNthFloor
            ) {

                intervalColumn.style.display =
                    'block';


                /*
                 * Visible -> enabled -> required
                 */
                floorInterval.disabled =
                    false;


                floorInterval.required =
                    true;

            } else {

                intervalColumn.style.display =
                    'none';


                /*
                 * HIDDEN -> DISABLED -> NOT REQUIRED
                 *
                 * This prevents:
                 *
                 * "An invalid form control with name
                 * 'floor_interval' is not focusable."
                 */
                floorInterval.disabled =
                    true;


                floorInterval.required =
                    false;


                floorInterval.value =
                    '';

            }


            /* =============================================
               SPECIFIC FLOORS
            ============================================== */

            if (
                isSpecificFloors
            ) {

                specificFloorsColumn.style.display =
                    'block';


                /*
                 * Visible -> enabled -> required
                 */
                servedFloors.disabled =
                    false;


                servedFloors.required =
                    true;

            } else {

                specificFloorsColumn.style.display =
                    'none';


                /*
                 * HIDDEN -> DISABLED -> NOT REQUIRED
                 */
                servedFloors.disabled =
                    true;


                servedFloors.required =
                    false;


                servedFloors.value =
                    '';

            }

        }


        /* =================================================
           ENABLE / DISABLE SECTION
        ================================================= */

        function setSectionEnabled(
            section,
            enabled
        ) {

            if (!section) {
                return;
            }


            section
                .querySelectorAll(
                    'input, select, textarea'
                )
                .forEach(
                    function (element) {

                        element.disabled =
                            !enabled;


                        /*
                         * Do not automatically change
                         * conditional required values here.
                         */
                    }
                );

        }


        /* =================================================
           ASSET TYPE
        ================================================= */

        function updateAssetType() {

            const selected =
                document.querySelector(
                    'input[name="asset_type"]:checked'
                );


            const type =
                selected
                ? selected.value
                : '';


            /* =============================================
               LIFT
            ============================================== */

            if (
                type === 'Lift'
            ) {

                liftDetails.style.display =
                    'block';


                escalatorDetails.style.display =
                    'none';


                liftCard.classList.add(
                    'selected'
                );


                escalatorCard.classList.remove(
                    'selected'
                );


                /*
                 * Enable Lift section.
                 */
                setSectionEnabled(
                    liftDetails,
                    true
                );


                /*
                 * Disable Escalator section.
                 */
                setSectionEnabled(
                    escalatorDetails,
                    false
                );


                /*
                 * All Escalator fields are not required.
                 */
                escalatorType.required =
                    false;


                escalatorRise.required =
                    false;


                updateLiftType(
                    existingAssetLoaded
                );


                /*
                 * IMPORTANT:
                 * Re-run conditional floor state AFTER
                 * enabling the Lift section.
                 */
                updateFloorService();


            }


            /* =============================================
               ESCALATOR
            ============================================== */

            else if (
                type === 'Escalator'
            ) {

                liftDetails.style.display =
                    'none';


                escalatorDetails.style.display =
                    'block';


                liftCard.classList.remove(
                    'selected'
                );


                escalatorCard.classList.add(
                    'selected'
                );


                /*
                 * Disable ALL Lift fields.
                 *
                 * This automatically removes required
                 * validation from hidden Lift controls.
                 */
                liftDetails
                    .querySelectorAll(
                        'input, select, textarea'
                    )
                    .forEach(
                        function (element) {

                            element.disabled =
                                true;


                            element.required =
                                false;

                        }
                    );


                /*
                 * Explicitly disable conditional
                 * Lift controls.
                 */
                floorInterval.disabled =
                    true;


                floorInterval.required =
                    false;


                servedFloors.disabled =
                    true;


                servedFloors.required =
                    false;


                /*
                 * Enable Escalator fields.
                 */
                setSectionEnabled(
                    escalatorDetails,
                    true
                );


                escalatorType.required =
                    true;


                escalatorRise.required =
                    true;

            }


            /* =============================================
               NOTHING SELECTED
            ============================================== */

            else {

                liftDetails.style.display =
                    'none';


                escalatorDetails.style.display =
                    'none';


                liftCard.classList.remove(
                    'selected'
                );


                escalatorCard.classList.remove(
                    'selected'
                );


                /*
                 * Disable everything.
                 */
                liftDetails
                    .querySelectorAll(
                        'input, select, textarea'
                    )
                    .forEach(
                        function (element) {

                            element.disabled =
                                true;


                            element.required =
                                false;

                        }
                    );


                escalatorDetails
                    .querySelectorAll(
                        'input, select, textarea'
                    )
                    .forEach(
                        function (element) {

                            element.disabled =
                                true;


                            element.required =
                                false;

                        }
                    );

            }

        }


        /* =================================================
           LOAD EXISTING ASSET
        ================================================= */

        loadExistingAssetBtn.addEventListener(
            'click',
            async function () {

                const assetId =
                    existingAssetSelect.value;


                if (!assetId) {

                    copyStatus.className =
                        'copy-status error';


                    copyStatus.innerHTML =

                        '<i class="bi bi-exclamation-circle me-1"></i>' +
                        'Please select an existing asset first.';


                    return;
                }


                loadExistingAssetBtn.disabled =
                    true;


                copyStatus.className =
                    'copy-status loading';


                copyStatus.innerHTML =

                    '<i class="bi bi-hourglass-split me-1"></i>' +
                    'Loading asset details...';


                loadExistingAssetBtn.innerHTML =

                    '<span class="spinner-border ' +
                    'spinner-border-sm me-1">' +
                    '</span>' +
                    'Loading...';


                try {

                    const url =
                        window.location.pathname
                        +
                        '?action=get_existing_asset'
                        +
                        '&asset_id='
                        +
                        encodeURIComponent(
                            assetId
                        );


                    const response =
                        await fetch(
                            url,
                            {
                                method:
                                    'GET',

                                credentials:
                                    'same-origin',

                                headers: {
                                    'Accept':
                                        'application/json'
                                }
                            }
                        );


                    if (
                        !response.ok
                    ) {

                        throw new Error(
                            'Server returned HTTP '
                            +
                            response.status
                        );
                    }


                    const data =
                        await response.json();


                    if (
                        !data.success
                    ) {

                        throw new Error(
                            data.message
                            ||
                            'Unable to load asset.'
                        );
                    }


                    const asset =
                        data.asset;


                    /*
                     * IMPORTANT:
                     *
                     * DO NOT COPY asset.asset_name.
                     *
                     * New Asset Name stays blank/current.
                     */
                    location.value =
                        asset.location
                        || '';


                    state.value =
                        asset.state
                        || '';


                    if (
                        asset.consignee_name
                    ) {

                        consigneeName.value =
                            asset.consignee_name;

                    }


                    /* =====================================
                       ASSET TYPE
                    ====================================== */

                    if (
                        asset.asset_type ===
                            'Lift'
                        ||
                        asset.asset_type ===
                            'Escalator'
                    ) {

                        const radio =
                            document.querySelector(
                                'input[name="asset_type"][value="' +
                                asset.asset_type +
                                '"]'
                            );


                        if (radio) {

                            radio.checked =
                                true;
                        }
                    }


                    /*
                     * Mark loaded BEFORE updateAssetType
                     * so the existing load capacity is
                     * preserved.
                     */
                    existingAssetLoaded =
                        true;


                    updateAssetType();


                    /* =====================================
                       LIFT
                    ====================================== */

                    if (
                        asset.asset_type ===
                            'Lift'
                    ) {

                        liftType.value =
                            asset.lift_type
                            || '';


                        numberOfFloors.value =
                            asset.number_of_floors
                            ?? '';


                        floorServiceType.value =
                            asset.floor_service_type
                            || '';


                        servedFloors.value =
                            asset.served_floors
                            || '';


                        floorInterval.value =
                            asset.floor_interval
                            ?? '';


                        passengerCapacity.value =
                            asset.passenger_capacity
                            ?? '';


                        /*
                         * Preserve exact existing load.
                         */
                        loadCapacity.value =
                            asset.load_capacity
                            ?? '';


                        machineType.value =
                            asset.machine_type
                            || '';


                        updateLiftType(
                            true
                        );


                        /*
                         * IMPORTANT:
                         * This enables/disables floor_interval
                         * correctly based on the copied pattern.
                         */
                        updateFloorService();

                    }


                    /* =====================================
                       ESCALATOR
                    ====================================== */

                    if (
                        asset.asset_type ===
                            'Escalator'
                    ) {

                        escalatorType.value =
                            asset.escalator_type
                            || '';


                        escalatorRise.value =
                            asset.escalator_rise
                            ?? '';

                    }


                    /* =====================================
                       INSTALLATION
                    ====================================== */

                    installationYear.value =
                        asset.installation_year
                        || '';


                    modificationYear.value =
                        asset.modification_year
                        ?? '';


                    /* =====================================
                       SUCCESS
                    ====================================== */

                    const selectedOption =
                        existingAssetSelect
                        .options[
                            existingAssetSelect
                            .selectedIndex
                        ];


                    const selectedText =
                        selectedOption
                        ? selectedOption.text
                        : 'Existing Asset';


                    copyStatus.className =
                        'copy-status success';


                    copyStatus.innerHTML =

                        '<i class="bi bi-check-circle-fill me-1"></i>' +
                        '<strong>Details copied successfully.</strong> ' +
                        escapeHtml(
                            selectedText
                        ) +
                        '. Enter the new Asset Name above. ' +
                        'You can edit any copied field before saving.';


                    /*
                     * Focus new Asset Name.
                     */
                    assetName.focus();


                    assetName.scrollIntoView({
                        behavior:
                            'smooth',

                        block:
                            'center'
                    });


                } catch (
                    error
                ) {

                    console.error(
                        'Existing asset load error:',
                        error
                    );


                    copyStatus.className =
                        'copy-status error';


                    copyStatus.innerHTML =

                        '<i class="bi bi-exclamation-triangle-fill me-1"></i>' +
                        escapeHtml(
                            error.message
                            ||
                            'Unable to load asset.'
                        );

                } finally {

                    loadExistingAssetBtn.disabled =
                        false;


                    loadExistingAssetBtn.innerHTML =

                        '<i class="bi bi-arrow-down-circle me-1"></i>' +
                        'Load Details';

                }

            }
        );


        /* =================================================
           EVENTS
        ================================================= */

        assetRadios.forEach(
            function (radio) {

                radio.addEventListener(
                    'change',
                    function () {

                        existingAssetLoaded =
                            false;


                        updateAssetType();

                    }
                );

            }
        );


        liftType.addEventListener(
            'change',
            function () {

                existingAssetLoaded =
                    false;


                /*
                 * If the user changes lift type,
                 * calculate load only when appropriate.
                 */
                updateLiftType(
                    false
                );

            }
        );


        passengerCapacity.addEventListener(
            'input',
            function () {

                /*
                 * When passenger capacity changes,
                 * recalculate the load.
                 *
                 * User can then manually edit it.
                 */
                if (
                    passengerBasedLiftTypes.includes(
                        liftType.value
                    )
                ) {

                    calculateLoad();

                }

            }
        );


        floorServiceType.addEventListener(
            'change',
            function () {

                updateFloorService();

            }
        );


        /* =================================================
           FORM SUBMIT
           -------------------------------------------------
           IMPORTANT:
           We manually validate conditional controls
           BEFORE disabling the button.
        ================================================= */

        form.addEventListener(
            'submit',
            function (event) {

                if (submitting) {

                    event.preventDefault();

                    return;
                }


                /*
                 * FIRST:
                 * Make sure conditional fields are in
                 * their correct enabled/required state.
                 */
                updateAssetType();


                /*
                 * Browser validation.
                 *
                 * Hidden conditional fields are now
                 * disabled, so they cannot produce:
                 *
                 * "not focusable"
                 */
                if (
                    !form.checkValidity()
                ) {

                    event.preventDefault();


                    form.reportValidity();


                    return;
                }


                /*
                 * Extra safety.
                 */
                const selected =
                    document.querySelector(
                        'input[name="asset_type"]:checked'
                    );


                if (!selected) {

                    event.preventDefault();


                    alert(
                        'Please select Lift or Escalator.'
                    );


                    return;
                }


                /* =========================================
                   LIFT
                ========================================== */

                if (
                    selected.value ===
                        'Lift'
                ) {


                    if (
                        !liftType.value
                    ) {

                        event.preventDefault();

                        liftType.focus();

                        alert(
                            'Please select Lift Type.'
                        );

                        return;
                    }


                    if (
                        !numberOfFloors.value
                    ) {

                        event.preventDefault();

                        numberOfFloors.focus();

                        alert(
                            'Please enter Number of Floors / Levels Served.'
                        );

                        return;
                    }


                    if (
                        !floorServiceType.value
                    ) {

                        event.preventDefault();

                        floorServiceType.focus();

                        alert(
                            'Please select Floor Service Pattern.'
                        );

                        return;
                    }


                    if (
                        floorServiceType.value ===
                            'Every Nth Floor'
                        &&
                        !floorInterval.value
                    ) {

                        event.preventDefault();

                        floorInterval.focus();

                        alert(
                            'Please enter Every Nth Floor interval.'
                        );

                        return;
                    }


                    if (
                        floorServiceType.value ===
                            'Specific Floors'
                        &&
                        !servedFloors.value
                    ) {

                        event.preventDefault();

                        servedFloors.focus();

                        alert(
                            'Please enter Specific Floors Served.'
                        );

                        return;
                    }


                    if (
                        passengerBasedLiftTypes.includes(
                            liftType.value
                        )
                        &&
                        !passengerCapacity.value
                    ) {

                        event.preventDefault();

                        passengerCapacity.focus();

                        alert(
                            'Please enter Passenger Capacity.'
                        );

                        return;
                    }


                    if (
                        !loadCapacity.value
                    ) {

                        event.preventDefault();

                        loadCapacity.focus();

                        alert(
                            'Please enter Rated Load Capacity.'
                        );

                        return;
                    }


                    if (
                        !machineType.value
                    ) {

                        event.preventDefault();

                        machineType.focus();

                        alert(
                            'Please select Machine Type.'
                        );

                        return;
                    }

                }


                /* =========================================
                   ESCALATOR
                ========================================== */

                if (
                    selected.value ===
                        'Escalator'
                ) {


                    if (
                        !escalatorType.value
                    ) {

                        event.preventDefault();

                        escalatorType.focus();

                        alert(
                            'Please select Escalator Type.'
                        );

                        return;
                    }


                    if (
                        !escalatorRise.value
                    ) {

                        event.preventDefault();

                        escalatorRise.focus();

                        alert(
                            'Please enter Escalator Rise.'
                        );

                        return;
                    }

                }


                /* =========================================
                   INSTALLATION
                ========================================== */

                if (
                    !installationYear.value
                ) {

                    event.preventDefault();

                    installationYear.focus();

                    alert(
                        'Please enter Installation Year.'
                    );

                    return;
                }


                /* =========================================
                   FINAL SUBMIT
                ========================================== */

                submitting =
                    true;


                /*
                 * Only now disable button.
                 */
                saveBtn.disabled =
                    true;


                saveBtn.innerHTML =

                    '<span class="spinner-border ' +
                    'spinner-border-sm me-1">' +
                    '</span>' +
                    'Saving Asset...';

                /*
                 * IMPORTANT:
                 *
                 * Do NOT call calculateLoad() here.
                 *
                 * If user manually changed:
                 *
                 * 884 -> 900
                 *
                 * 900 must be saved.
                 */

            }
        );


        /* =================================================
           INITIAL STATE
        ================================================= */

        updateAssetType();


        /*
         * Run once more after all event handlers are
         * registered.
         */
        updateFloorService();

    }
);

</script>