<?php

/* =========================================================
   NLETA MAINTENANCE MANAGEMENT SYSTEM

   PHASE 5 — OFFER GENERATOR

   FLOW

   PHASE 4
       ↓
   ACCEPTED SERVICE REQUEST
       ↓
   OFFER GENERATOR
       ↓
   DAILY PSGA NUMBER
       ↓
   FSO NUMBER
       ↓
   PRICING
       ↓
   GST
       ↓
   PAYMENT TERMS
       ↓
   FSO-01
       ↓
   PRINT / SAVE PDF

========================================================= */

include('../config/db.php');
include('../config/auth.php');
require_once('../config/security.php');
require_once('../config/owner_access.php');
require_once('../config/nleta-mailer.php');

require_role('admin');

date_default_timezone_set('Asia/Kolkata');

mysqli_report(MYSQLI_REPORT_OFF);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}


/* =========================================================
   TRAVEL / LODGING / LOCAL CONVEYANCE COLUMN
   ---------------------------------------------------------
   Stored per FSO + Asset. Existing records default to
   Not Applicable (0), so no existing offer is changed.
========================================================= */
function ensureOfferTravelColumn(mysqli $conn): void
{
    $check = $conn->query("
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'nleta_offer_assets'
          AND COLUMN_NAME = 'travel_lodging_applicable'
        LIMIT 1
    ");

    if ($check && $check->num_rows > 0) {
        $check->free();
        return;
    }

    if ($check) {
        $check->free();
    }

    $alter = $conn->query("
        ALTER TABLE nleta_offer_assets
        ADD COLUMN travel_lodging_applicable TINYINT(1) NOT NULL DEFAULT 0
        AFTER asset_id
    ");

    if (!$alter) {
        throw new Exception(
            'Unable to prepare Travel / Lodging / Local Conveyance setting: ' .
            $conn->error
        );
    }
}

try {
    ensureOfferTravelColumn($conn);
} catch (Throwable $e) {
    die(h($e->getMessage()));
}

/* =========================================================
   TRAVEL / LODGING / LOCAL CONVEYANCE AGREED AMOUNT
   ---------------------------------------------------------
   Stored at FSO level. This amount is payable separately and
   is NOT included in service pricing, GST or Grand Total.
========================================================= */
function ensureOfferTravelAmountColumn(mysqli $conn): void
{
    $check = $conn->query("
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'nleta_offer_master'
          AND COLUMN_NAME = 'travel_lodging_amount'
        LIMIT 1
    ");

    if ($check && $check->num_rows > 0) {
        $check->free();
        return;
    }

    if ($check) {
        $check->free();
    }

    $alter = $conn->query("
        ALTER TABLE nleta_offer_master
        ADD COLUMN travel_lodging_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00
        AFTER payment_final
    ");

    if (!$alter) {
        throw new Exception(
            'Unable to prepare Travel / Lodging / Local Conveyance agreed amount: ' .
            $conn->error
        );
    }
}

try {
    ensureOfferTravelAmountColumn($conn);
} catch (Throwable $e) {
    die(h($e->getMessage()));
}

/* =========================================================
   TRAVEL / LODGING MANAGEMENT MODE
   ---------------------------------------------------------
   owner = Managed by the Owner
   nleta = Admin/NLETA enters the mutually agreed amount
========================================================= */
function ensureOfferTravelManagedByColumn(mysqli $conn): void
{
    $check = $conn->query("
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'nleta_offer_master'
          AND COLUMN_NAME = 'travel_lodging_managed_by'
        LIMIT 1
    ");

    if ($check && $check->num_rows > 0) {
        $check->free();
        return;
    }

    if ($check) {
        $check->free();
    }

    $alter = $conn->query("
        ALTER TABLE nleta_offer_master
        ADD COLUMN travel_lodging_managed_by VARCHAR(20) NOT NULL DEFAULT 'owner'
        AFTER travel_lodging_amount
    ");

    if (!$alter) {
        throw new Exception(
            'Unable to prepare Travel / Lodging management mode: ' .
            $conn->error
        );
    }
}

try {
    ensureOfferTravelManagedByColumn($conn);
} catch (Throwable $e) {
    die(h($e->getMessage()));
}


/* =========================================================
   HELPERS
========================================================= */

function h($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}


function getRequestServices(mysqli $conn, int $requestId, int $fallbackServiceId = 0): array
{
    $services = [];

    $tableCheck = $conn->query("SHOW TABLES LIKE 'service_request_services'");

    if ($tableCheck && $tableCheck->num_rows > 0) {
        $stmt = $conn->prepare("
            SELECT
                sc.id,
                sc.service_code,
                sc.service_name,
                sc.description AS service_description
            FROM service_request_services srs
            INNER JOIN service_catalog sc
                ON sc.id = srs.service_id
            WHERE srs.request_id = ?
            ORDER BY srs.id ASC
        ");

        if ($stmt) {
            $stmt->bind_param('i', $requestId);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $services[] = $row;
                }
            }
            $stmt->close();
        }
    }

    if (empty($services) && $fallbackServiceId > 0) {
        $stmt = $conn->prepare("
            SELECT
                id,
                service_code,
                service_name,
                description AS service_description
            FROM service_catalog
            WHERE id = ?
            LIMIT 1
        ");

        if ($stmt) {
            $stmt->bind_param('i', $fallbackServiceId);
            if ($stmt->execute()) {
                $row = $stmt->get_result()->fetch_assoc();
                if ($row) {
                    $services[] = $row;
                }
            }
            $stmt->close();
        }
    }

    return $services;
}

function serviceNamesText(array $services): string
{
    $names = [];
    foreach ($services as $service) {
        $name = trim((string)($service['service_name'] ?? ''));
        if ($name !== '') {
            $names[] = $name;
        }
    }
    return implode(', ', $names);
}

function serviceNamesHtml(array $services): string
{
    $html = '<ol style="margin:0;padding-left:20px;">';
    foreach ($services as $service) {
        $code = trim((string)($service['service_code'] ?? ''));
        $name = trim((string)($service['service_name'] ?? ''));
        $label = $name;
        if ($code !== '') {
            $label .= ' (' . $code . ')';
        }
        $html .= '<li>' . h($label) . '</li>';
    }
    $html .= '</ol>';
    return $html;
}

function money($value): string
{
    return number_format(
        (float)$value,
        2,
        '.',
        ','
    );
}


function moneyPlain($value): string
{
    return number_format(
        (float)$value,
        2,
        '.',
        ','
    );
}


/* =========================================================
   REQUEST ID
========================================================= */

$requestId =
    (int)(
        $_GET['request_id']
        ??
        $_GET['id']
        ??
        0
    );

/*
 * Regenerate means:
 * - Recalculate the current Offer using the latest asset/pricing data.
 * - Keep the same PSGA number.
 * - Keep the same FSO number.
 * - Replace the existing Offer Items / Offer Assets.
 * - Do NOT create a second FSO for the same Service Request.
 * - Regeneration is allowed only while the Offer is not Sent.
 */
$isRegenerate =
    (
        $_SERVER['REQUEST_METHOD'] === 'POST'
        &&
        (
            $_POST['action'] ?? ''
        ) === 'regenerate_offer'
    );

if ($requestId <= 0) {

    header(
        'Location: service-requests.php'
    );

    exit;
}


/* =========================================================
   CSRF
========================================================= */

if (
    empty($_SESSION['csrf_offer_generator'])
) {

    $_SESSION['csrf_offer_generator'] =
        bin2hex(
            random_bytes(32)
        );
}

$csrfToken =
    $_SESSION['csrf_offer_generator'];


/* =========================================================
   CURRENT IST
========================================================= */

$timezone =
    new DateTimeZone(
        'Asia/Kolkata'
    );

$now =
    new DateTime(
        'now',
        $timezone
    );


/* =========================================================
   FINANCIAL YEAR
   APRIL TO MARCH
========================================================= */

$currentYear =
    (int)$now->format('Y');

$currentMonth =
    (int)$now->format('n');

if ($currentMonth >= 4) {

    $fyStart =
        $currentYear;

    $fyEnd =
        $currentYear + 1;
} else {

    $fyStart =
        $currentYear - 1;

    $fyEnd =
        $currentYear;
}

$financialYear =
    substr(
        (string)$fyStart,
        -2
    )
    .
    '-'
    .
    substr(
        (string)$fyEnd,
        -2
    );


/* =========================================================
   HELPER — CLIENT SHORT CODE
========================================================= */

function generateClientShortCode(
    $organization
): string {

    $organization =
        trim(
            (string)$organization
        );

    $organization =
        preg_replace(
            '/\s+/',
            ' ',
            $organization
        );

    $organization =
        preg_replace(
            '/[&\/(),.]+/',
            ' ',
            $organization
        );

    $words =
        preg_split(
            '/[\s\-]+/',
            $organization,
            -1,
            PREG_SPLIT_NO_EMPTY
        );

    $code = '';

    foreach (
        $words
        as $word
    ) {

        $word =
            trim(
                (string)$word
            );

        if (
            $word === ''
        ) {
            continue;
        }

        $code .=
            strtoupper(
                substr(
                    $word,
                    0,
                    1
                )
            );
    }

    return $code;
}


/* =========================================================
   HELPER — A, B, C ... Z, AA, AB...
========================================================= */

function numberToLetters(
    $number
): string {

    $letters = '';

    $number =
        (int)$number;

    while (
        $number > 0
    ) {

        $number--;

        $letters =
            chr(
                65 +
                    (
                        $number % 26
                    )
            )
            .
            $letters;

        $number =
            intdiv(
                $number,
                26
            );
    }

    return $letters;
}


/* =========================================================
   GENERATE DAILY PSGA
========================================================= */

function generateDailyPSGA(
    mysqli $conn,
    DateTime $now
): string {

    $date =
        $now->format(
            'Y-m-d'
        );

    $datePart =
        $now->format(
            'dmY'
        );


    /*
     * Lock the sequence row for this date.
     */

    $select =
        $conn->prepare("
            SELECT
                sequence_number
            FROM nleta_psga_daily_sequence
            WHERE sequence_date = ?
            FOR UPDATE
        ");

    if (!$select) {

        throw new Exception(
            'Unable to prepare PSGA sequence.'
        );
    }


    $select->bind_param(
        's',
        $date
    );

    if (
        !$select->execute()
    ) {

        $error =
            $select->error;

        $select->close();

        throw new Exception(
            'Unable to read PSGA sequence: ' .
                $error
        );
    }


    $result =
        $select->get_result();

    $row =
        $result->fetch_assoc();

    $select->close();


    if ($row) {

        $nextNumber =
            ((int)$row['sequence_number'])
            + 1;


        $update =
            $conn->prepare("
                UPDATE
                    nleta_psga_daily_sequence
                SET
                    sequence_number = ?,
                    updated_at = ?
                WHERE
                    sequence_date = ?
            ");


        if (!$update) {

            throw new Exception(
                'Unable to update PSGA sequence.'
            );
        }


        $updatedAt =
            $now->format(
                'Y-m-d H:i:s'
            );


        $update->bind_param(
            'iss',
            $nextNumber,
            $updatedAt,
            $date
        );


        if (
            !$update->execute()
        ) {

            $error =
                $update->error;

            $update->close();

            throw new Exception(
                'Unable to update PSGA sequence: ' .
                    $error
            );
        }


        $update->close();
    } else {

        $nextNumber = 1;


        $insert =
            $conn->prepare("
                INSERT INTO
                    nleta_psga_daily_sequence
                (
                    sequence_date,
                    sequence_number,
                    updated_at
                )
                VALUES
                (
                    ?,
                    ?,
                    ?
                )
            ");


        if (!$insert) {

            throw new Exception(
                'Unable to create PSGA sequence.'
            );
        }


        $updatedAt =
            $now->format(
                'Y-m-d H:i:s'
            );


        $insert->bind_param(
            'sis',
            $date,
            $nextNumber,
            $updatedAt
        );


        if (
            !$insert->execute()
        ) {

            $error =
                $insert->error;

            $insert->close();

            throw new Exception(
                'Unable to create PSGA sequence: ' .
                    $error
            );
        }


        $insert->close();
    }


    return
        numberToLetters(
            $nextNumber
        )
        .
        $datePart;
}


/* =========================================================
   LOAD SERVICE REQUEST
========================================================= */

$request = null;


$stmt =
    $conn->prepare("
        SELECT

            sr.id,
            sr.request_no,
            sr.owner_id,
            sr.service_id,
            sr.requirements,
            sr.status,
            sr.submitted_at,

            sc.service_code,
            sc.service_name,
            sc.description AS service_description,

            u.name AS owner_name,
            u.email AS owner_email,
            u.phone AS owner_phone,
            u.organization_name AS organization_name,
            u.organization_address AS organization_address

        FROM service_requests sr

        INNER JOIN service_catalog sc
            ON sc.id = sr.service_id

        LEFT JOIN users u
            ON u.id = sr.owner_id

        WHERE sr.id = ?

        LIMIT 1
    ");


if (!$stmt) {

    die('Unable to load service request: ' .
        h($conn->error));
}


$stmt->bind_param(
    'i',
    $requestId
);


if (
    !$stmt->execute()
) {

    die('Unable to execute service request query: ' .
        h($stmt->error));
}


$request =
    $stmt
    ->get_result()
    ->fetch_assoc();


$stmt->close();


if (!$request) {

    die('Service Request not found.');
}


/* =========================================================
   LOAD ALL SELECTED SERVICES
   ---------------------------------------------------------
   Multi-service requests use service_request_services.
   Legacy single-service requests fall back to sr.service_id.
========================================================= */

$requestServices =
    getRequestServices(
        $conn,
        $requestId,
        (int)($request['service_id'] ?? 0)
    );

if (empty($requestServices)) {
    die('No service is attached to this Service Request.');
}


/* =========================================================
   ONLY ACCEPTED / OFFER GENERATED
========================================================= */

$requestStatus =
    trim(
        (string)(
            $request['status']
            ?? ''
        )
    );


if (
    !in_array(
        $requestStatus,
        [
            'Accepted',
            'Offer Generated'
        ],
        true
    )
) {

    die('Offer Generator is available only for an Accepted Service Request.');
}


/* =========================================================
   LOAD SELECTED ASSETS
========================================================= */

$requestAssets = [];


$assetStmt =
    $conn->prepare("
        SELECT

            a.id,
            a.display_asset_id,
            a.asset_name,
            a.asset_type,
            a.category,
            a.location,
            a.state,
            a.status,
            a.owner_id

        FROM service_request_assets sra

        INNER JOIN assets a
            ON a.id = sra.asset_id

        WHERE sra.request_id = ?

        ORDER BY
            a.display_asset_id ASC,
            a.id ASC
    ");


if (!$assetStmt) {

    die('Unable to load selected assets: ' .
        h($conn->error));
}


$assetStmt->bind_param(
    'i',
    $requestId
);


if (
    !$assetStmt->execute()
) {

    die('Unable to execute selected assets query: ' .
        h($assetStmt->error));
}


$assetResult =
    $assetStmt->get_result();


while (
    $row =
    $assetResult->fetch_assoc()
) {

    $requestAssets[] =
        $row;
}


$assetStmt->close();


if (
    empty($requestAssets)
) {

    die('No assets are attached to this Service Request.');
}


/* =========================================================
   RESOLVE CLIENT FOR OFFER
========================================================= */

$client = null;

$clientId = 0;


$ownerId =
    (int)(
        $request['owner_id']
        ??
        0
    );


if (
    $ownerId <= 0
) {

    die('Owner information was not found for this Service Request.');
}


/* =========================================================
   OWNER DETAILS
========================================================= */

$ownerName =
    trim(
        (string)(
            $request['owner_name']
            ??
            ''
        )
    );


$ownerEmail =
    trim(
        (string)(
            $request['owner_email']
            ??
            ''
        )
    );


$ownerPhone =
    trim(
        (string)(
            $request['owner_phone']
            ??
            ''
        )
    );


/* =========================================================
   PERMANENT ASSOCIATION / ORGANIZATION DETAILS
   ---------------------------------------------------------
   These details are taken from the Owner profile and are
   used as the permanent Association identity in documents.
========================================================= */

$organizationName =
    trim(
        (string)(
            $request['organization_name']
            ??
            ''
        )
    );

$organizationAddress =
    trim(
        (string)(
            $request['organization_address']
            ??
            ''
        )
    );


/* =========================================================
   FIND CLIENT MASTER
========================================================= */

$clientLookupQueries = [];


if (
    $ownerEmail !== ''
) {

    $clientLookupQueries[] = [

        'sql' => "
            SELECT
                Client_ID,
                Organization,
                Authorized_Person,
                Mobile,
                Email,
                GST,
                Billing_Address,
                Status
            FROM client_master
            WHERE Email = ?
            LIMIT 1
        ",

        'type' => 's',

        'value' => $ownerEmail
    ];
}


if (
    $ownerPhone !== ''
) {

    $clientLookupQueries[] = [

        'sql' => "
            SELECT
                Client_ID,
                Organization,
                Authorized_Person,
                Mobile,
                Email,
                GST,
                Billing_Address,
                Status
            FROM client_master
            WHERE Mobile = ?
            LIMIT 1
        ",

        'type' => 's',

        'value' => $ownerPhone
    ];
}


if (
    $ownerName !== ''
) {

    $clientLookupQueries[] = [

        'sql' => "
            SELECT
                Client_ID,
                Organization,
                Authorized_Person,
                Mobile,
                Email,
                GST,
                Billing_Address,
                Status
            FROM client_master
            WHERE Organization = ?
            LIMIT 1
        ",

        'type' => 's',

        'value' => $ownerName
    ];
}


foreach (
    $clientLookupQueries
    as $lookup
) {

    if (
        $client
    ) {
        break;
    }


    $lookupStmt =
        $conn->prepare(
            $lookup['sql']
        );


    if (!$lookupStmt) {
        continue;
    }


    $lookupValue =
        (string)(
            $lookup['value']
            ?? ''
        );


    $lookupType =
        (string)(
            $lookup['type']
            ?? 's'
        );


    $lookupStmt->bind_param(
        $lookupType,
        $lookupValue
    );


    if (
        $lookupStmt->execute()
    ) {

        $foundClient =
            $lookupStmt
            ->get_result()
            ->fetch_assoc();


        if ($foundClient) {

            $client =
                $foundClient;

            $clientId =
                (int)(
                    $foundClient['Client_ID']
                    ??
                    0
                );
        }
    }


    $lookupStmt->close();
}


/* =========================================================
   CREATE CLIENT MASTER AUTOMATICALLY
========================================================= */

if (
    !$client
    &&
    $ownerName !== ''
) {

    $organization =
        $organizationName !== ''
        ? $organizationName
        : $ownerName;

    $authorizedPerson =
        $ownerName;

    $mobile =
        $ownerPhone;

    $email =
        $ownerEmail;

    $gst = '';

    $billingAddress =
        $organizationAddress;

    $clientStatus =
        'Active';


    $createClientStmt =
        $conn->prepare("
            INSERT INTO client_master
            (
                Organization,
                Authorized_Person,
                Mobile,
                Email,
                GST,
                Billing_Address,
                Status
            )
            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?
            )
        ");


    if (
        $createClientStmt
    ) {

        $createClientStmt->bind_param(
            'sssssss',
            $organization,
            $authorizedPerson,
            $mobile,
            $email,
            $gst,
            $billingAddress,
            $clientStatus
        );


        if (
            $createClientStmt->execute()
        ) {

            $clientId =
                (int)
                $createClientStmt->insert_id;
        }


        $createClientStmt->close();
    }


    if (
        $clientId > 0
    ) {

        $loadCreatedClientStmt =
            $conn->prepare("
                SELECT
                    Client_ID,
                    Organization,
                    Authorized_Person,
                    Mobile,
                    Email,
                    GST,
                    Billing_Address,
                    Status
                FROM client_master
                WHERE Client_ID = ?
                LIMIT 1
            ");


        if (
            $loadCreatedClientStmt
        ) {

            $loadCreatedClientStmt->bind_param(
                'i',
                $clientId
            );


            $loadCreatedClientStmt->execute();


            $client =
                $loadCreatedClientStmt
                ->get_result()
                ->fetch_assoc();


            $loadCreatedClientStmt->close();
        }
    }
}


/* =========================================================
   FINAL CLIENT VALIDATION
========================================================= */

if (
    !$client
    ||
    $clientId <= 0
) {

    die('Unable to create or find the Client Master profile for this Owner.');
}


/* =========================================================
   SYNC CLIENT
========================================================= */

$syncClientStmt =
    $conn->prepare("
        UPDATE client_master

        SET
            Organization = ?,
            Authorized_Person = ?,
            Mobile = ?,
            Email = ?,
            Billing_Address = ?

        WHERE Client_ID = ?

        LIMIT 1
    ");


if (
    $syncClientStmt
) {

    $syncOrganization =
        $organizationName !== ''
        ? $organizationName
        : (string)($client['Organization'] ?? $ownerName);

    $syncBillingAddress =
        $organizationAddress !== ''
        ? $organizationAddress
        : (string)($client['Billing_Address'] ?? '');

    $syncClientStmt->bind_param(
        'sssssi',
        $syncOrganization,
        $ownerName,
        $ownerPhone,
        $ownerEmail,
        $syncBillingAddress,
        $clientId
    );


    $syncClientStmt->execute();

    $syncClientStmt->close();


    $reloadClientStmt =
        $conn->prepare("
            SELECT
                Client_ID,
                Organization,
                Authorized_Person,
                Mobile,
                Email,
                GST,
                Billing_Address,
                Status
            FROM client_master
            WHERE Client_ID = ?
            LIMIT 1
        ");


    if (
        $reloadClientStmt
    ) {

        $reloadClientStmt->bind_param(
            'i',
            $clientId
        );


        $reloadClientStmt->execute();


        $reloadedClient =
            $reloadClientStmt
            ->get_result()
            ->fetch_assoc();


        if (
            $reloadedClient
        ) {

            $client =
                $reloadedClient;
        }


        $reloadClientStmt->close();
    }
}


/* =========================================================
   CLIENT SHORT CODE
========================================================= */

$clientShortCode =
    generateClientShortCode(
        $client['Organization']
            ??
            ''
    );


if (
    $clientShortCode === ''
) {

    die('Unable to generate Client Short Code from Organization name.');
}


/* =========================================================
   EXISTING OFFER
========================================================= */

$existingOffer = null;


$existingStmt =
    $conn->prepare("
        SELECT *
        FROM nleta_offer_master
        WHERE request_id = ?
        LIMIT 1
    ");


if (
    $existingStmt
) {

    $existingStmt->bind_param(
        'i',
        $requestId
    );


    $existingStmt->execute();


    $existingOffer =
        $existingStmt
        ->get_result()
        ->fetch_assoc();


    $existingStmt->close();
}


/* =========================================================
   PERMANENT OWNER PSGA + NEW FSO PER SERVICE
   ---------------------------------------------------------
   BUSINESS RULE:
   - PSGA is permanent for the Owner/Client.
   - Every new Service Request gets a NEW FSO.
   - FSO sequence is 01, 02, 03 ... for that Owner.
   - Existing FSO records are NEVER reused or overwritten.
   - Every FSO remains linked to the same permanent PSGA.
   - SAF/payment history remains separate per FSO/service.
========================================================= */

$serviceRequestPSGANo = '';

$permanentOwnerPSGANo = '';

$permanentOwnerFSO = '';

$permanentOwnerOfferId = 0;

$nextFsoSequence = 1;


/*
 * First read PSGA already attached to the current request.
 */
$serviceRequestPSGAStmt =
    $conn->prepare("
        SELECT
            psga_no
        FROM service_requests
        WHERE id = ?
        LIMIT 1
    ");

if ($serviceRequestPSGAStmt) {

    $serviceRequestPSGAStmt->bind_param(
        'i',
        $requestId
    );

    $serviceRequestPSGAStmt->execute();

    $serviceRequestPSGARow =
        $serviceRequestPSGAStmt
        ->get_result()
        ->fetch_assoc();

    $serviceRequestPSGANo =
        trim(
            (string)(
                $serviceRequestPSGARow['psga_no']
                ?? ''
            )
        );

    $serviceRequestPSGAStmt->close();
}


/*
 * Find the Owner's permanent PSGA.
 *
 * IMPORTANT:
 * We do NOT reuse the Owner's old FSO.
 * We only reuse the permanent PSGA number.
 */
$ownerPermanentStmt =
    $conn->prepare("
        SELECT
            o.id AS offer_id,
            o.psga_no,
            o.offer_no
        FROM nleta_offer_master o
        INNER JOIN service_requests sr
            ON sr.id = o.request_id
        WHERE
            sr.owner_id = ?
            AND TRIM(COALESCE(o.psga_no, '')) <> ''
        ORDER BY
            o.id ASC
        LIMIT 1
    ");

if ($ownerPermanentStmt) {

    $ownerPermanentStmt->bind_param(
        'i',
        $ownerId
    );

    $ownerPermanentStmt->execute();

    $ownerPermanentRow =
        $ownerPermanentStmt
        ->get_result()
        ->fetch_assoc();

    $ownerPermanentStmt->close();

    if ($ownerPermanentRow) {

        $permanentOwnerOfferId =
            (int)(
                $ownerPermanentRow['offer_id']
                ?? 0
            );

        $permanentOwnerPSGANo =
            trim(
                (string)(
                    $ownerPermanentRow['psga_no']
                    ?? ''
                )
            );
    }
}


/*
 * Current request PSGA wins if it already exists.
 * Otherwise use the Owner's permanent PSGA.
 */
if ($serviceRequestPSGANo === '') {

    $serviceRequestPSGANo =
        $permanentOwnerPSGANo;
}


/*
 * If an older Offer already exists for this exact request,
 * preserve its values for display.
 *
 * Normally generation is blocked when an Offer exists.
 */
if ($existingOffer) {

    if (
        trim(
            (string)(
                $existingOffer['psga_no']
                ?? ''
            )
        ) !== ''
    ) {

        $serviceRequestPSGANo =
            trim(
                (string)(
                    $existingOffer['psga_no']
                )
            );
    }

    if (
        trim(
            (string)(
                $existingOffer['offer_no']
                ?? ''
            )
        ) !== ''
    ) {

        $permanentOwnerFSO =
            trim(
                (string)(
                    $existingOffer['offer_no']
                )
            );
    }
}


/*
 * Backfill the current Service Request with the permanent
 * Owner PSGA when it is not already stored.
 */
if ($serviceRequestPSGANo !== '') {

    $backfillPSGAStmt =
        $conn->prepare("
            UPDATE service_requests
            SET psga_no = ?
            WHERE id = ?
              AND (
                    psga_no IS NULL
                    OR TRIM(psga_no) = ''
                  )
            LIMIT 1
        ");

    if ($backfillPSGAStmt) {

        $backfillPSGAStmt->bind_param(
            'si',
            $serviceRequestPSGANo,
            $requestId
        );

        $backfillPSGAStmt->execute();

        $backfillPSGAStmt->close();
    }
}


/*
 * Calculate the next FSO sequence for this Owner.
 *
 * Examples:
 *   ...-01
 *   ...-02
 *   ...-03
 *
 * Old FSO numbers without -01/-02 are preserved as history
 * but are not treated as a reusable FSO.
 */
$ownerFsoStmt =
    $conn->prepare("
        SELECT
            o.offer_no
        FROM nleta_offer_master o
        INNER JOIN service_requests sr
            ON sr.id = o.request_id
        WHERE sr.owner_id = ?
        ORDER BY o.id ASC
    ");

if ($ownerFsoStmt) {

    $ownerFsoStmt->bind_param(
        'i',
        $ownerId
    );

    $ownerFsoStmt->execute();

    $ownerFsoResult =
        $ownerFsoStmt->get_result();

    $maxFsoSequence = 0;

    while (
        $ownerFsoRow =
        $ownerFsoResult->fetch_assoc()
    ) {

        $oldOfferNo =
            trim(
                (string)(
                    $ownerFsoRow['offer_no']
                    ?? ''
                )
            );

        if ($oldOfferNo === '') {
            continue;
        }

        /*
         * Read only the final two-digit sequence.
         * This prevents the date/PSGA digits from being
         * incorrectly treated as the FSO sequence.
         */
        if (
            preg_match(
                '/-(\d{2})$/',
                $oldOfferNo,
                $matches
            )
        ) {

            $sequence =
                (int)$matches[1];

            if ($sequence > $maxFsoSequence) {
                $maxFsoSequence = $sequence;
            }
        }
    }

    $ownerFsoStmt->close();

    $nextFsoSequence =
        max(
            1,
            $maxFsoSequence + 1
        );
}


/*
 * Next FSO preview.
 *
 * The actual number is recalculated inside the Generate
 * transaction as well.
 */
if ($serviceRequestPSGANo !== '') {

    $previewFsoPSGA =
        $serviceRequestPSGANo;

} else {

    /*
     * PSGA is generated only when the first FSO is actually
     * generated, so use a preview placeholder here.
     */
    $previewFsoPSGA =
        'NEW-PSGA';
}

$previewFsoNo =
    'FSO-'
    .
    $financialYear
    .
    '/NLETA/'
    .
    $clientShortCode
    .
    '-'
    .
    $previewFsoPSGA
    .
    '-'
    .
    str_pad(
        (string)$nextFsoSequence,
        2,
        '0',
        STR_PAD_LEFT
    );

/* =========================================================
   ANNEXURE-B — DGS PRICING
   ---------------------------------------------------------
   Society Platform Fee      = ₹7,500 / Society / Year
   1–5 assets                = ₹2,500 / Asset / Year
   6–10                      = ₹2,350 / Asset / Year
   11–20                     = ₹2,200 / Asset / Year
   21–30                     = ₹2,050 / Asset / Year
   31–40                     = ₹1,950 / Asset / Year
   41–50                     = ₹1,900 / Asset / Year

   Software implementation:
   Lift + Escalator + Travellator = participating assets.
========================================================= */

function getDgsPerAssetRate(int $assetCount): float
{
    if ($assetCount >= 1 && $assetCount <= 5) return 2500.00;
    if ($assetCount >= 6 && $assetCount <= 10) return 2350.00;
    if ($assetCount >= 11 && $assetCount <= 20) return 2200.00;
    if ($assetCount >= 21 && $assetCount <= 30) return 2050.00;
    if ($assetCount >= 31 && $assetCount <= 40) return 1950.00;
    if ($assetCount >= 41 && $assetCount <= 50) return 1900.00;
    return 0.00;
}

function getDgsAssetCount(array $assets): int
{
    return count($assets);
}


/* =========================================================
   OFFER ITEMS
========================================================= */

$offerItems = [];

$subtotal = 0.00;

$generationError = '';
$successMessage = '';

$generatedNow = false;

$sendOfferSuccess = '';


/* =========================================================
   LIFT PRICING CATEGORY
========================================================= */

function getLiftPricingCategory(
    $floors
): string {

    $floors =
        (int)$floors;


    if (
        $floors <= 0
    ) {

        return '';
    }


    if (
        $floors <= 7
    ) {

        return 'Lift up to 7 Floors';
    }


    if (
        $floors <= 15
    ) {

        return 'Lift 8–15 Floors';
    }


    if (
        $floors <= 23
    ) {

        return 'Lift 16–23 Floors';
    }


    if (
        $floors <= 31
    ) {

        return 'Lift 24–31 Floors';
    }


    return '';
}


/* =========================================================
   ESCALATOR PRICING CATEGORY — BASED ON RISE
   ---------------------------------------------------------
   ANNEXURE-A STANDARD REFERENCE:

   Rise <= 4.5 m
       → Commercial Escalator up to 4.5 m Rise

   Rise > 4.5 m and <= 7.0 m
       → Heavy Duty Escalator up to 7.0 m Rise

   Rise > 7.0 m
       → Not covered by standard Annexure-A pricing
========================================================= */

function getEscalatorPricingCategory(
    $rise
): string {

    if (
        $rise === ''
        ||
        !is_numeric($rise)
    ) {

        return '';
    }


    $rise =
        (float)$rise;


    if (
        $rise <= 0
    ) {

        return '';
    }


    if (
        $rise <= 4.5
    ) {

        return 'Commercial Escalator up to 4.5 m Rise';
    }


    if (
        $rise <= 7.0
    ) {

        return 'Heavy Duty Escalator up to 7.0 m Rise';
    }


    return '';
}



/* =========================================================
   LOAD ASSET TECHNICAL DETAILS
========================================================= */

foreach (
    $requestAssets
    as $key => $asset
) {

    $assetId =
        (int)(
            $asset['id']
            ??
            0
        );


    $detail = [];


    $detailStmt =
        $conn->prepare("
            SELECT

                number_of_floors,
                rated_speed,
                capacity,
                escalator_type,
                escalator_rise,
                technical_remarks

            FROM asset_details

            WHERE asset_id = ?

            LIMIT 1
        ");


    if (
        $detailStmt
    ) {

        $detailStmt->bind_param(
            'i',
            $assetId
        );


        $detailStmt->execute();


        $detail =
            $detailStmt
            ->get_result()
            ->fetch_assoc()
            ??
            [];


        $detailStmt->close();
    }


    $requestAssets[$key]['number_of_floors'] =
        $detail['number_of_floors']
        ??
        '';


    $requestAssets[$key]['rated_speed'] =
        $detail['rated_speed']
        ??
        '';


    $requestAssets[$key]['capacity'] =
        $detail['capacity']
        ??
        '';


    $requestAssets[$key]['escalator_type'] =
        $detail['escalator_type']
        ??
        '';


    $requestAssets[$key]['escalator_rise'] =
        $detail['escalator_rise']
        ??
        '';


    $requestAssets[$key]['technical_remarks'] =
        $detail['technical_remarks']
        ??
        '';
}


/* =========================================================
   EXISTING FSO ASSET TRAVEL SETTINGS
   ---------------------------------------------------------
   Existing saved values are loaded so regeneration/editing
   preserves the Admin's per-asset selection.
========================================================= */
$existingTravelSettings = [];

if ($existingOffer && !empty($existingOffer['id'])) {
    $travelStmt = $conn->prepare("
        SELECT asset_id, travel_lodging_applicable
        FROM nleta_offer_assets
        WHERE offer_id = ?
        ORDER BY id ASC
    ");

    if ($travelStmt) {
        $travelOfferId = (int)$existingOffer['id'];
        $travelStmt->bind_param('i', $travelOfferId);
        if ($travelStmt->execute()) {
            $travelResult = $travelStmt->get_result();
            while ($travelRow = $travelResult->fetch_assoc()) {
                $existingTravelSettings[(int)$travelRow['asset_id']] =
                    (int)($travelRow['travel_lodging_applicable'] ?? 0);
            }
        }
        $travelStmt->close();
    }
}


/* =========================================================
   SAVED TRAVEL / LODGING AGREED AMOUNT
========================================================= */
$existingTravelLodgingAmount = 0.00;

if ($existingOffer && array_key_exists('travel_lodging_amount', $existingOffer)) {
    $existingTravelLodgingAmount = max(
        0.00,
        (float)$existingOffer['travel_lodging_amount']
    );
}

$existingTravelLodgingManagedBy = 'owner';

if (
    $existingOffer
    && array_key_exists('travel_lodging_managed_by', $existingOffer)
    && in_array((string)$existingOffer['travel_lodging_managed_by'], ['owner', 'nleta'], true)
) {
    $existingTravelLodgingManagedBy = (string)$existingOffer['travel_lodging_managed_by'];
}

/* Preserve the meaning of offers created before the management-mode field existed. */
if (
    $existingTravelLodgingManagedBy === 'owner'
    && $existingTravelLodgingAmount > 0
) {
    $existingTravelLodgingManagedBy = 'nleta';
}

/* =========================================================
   SAVE TRAVEL SETTINGS FOR AN EXISTING UNSENT FSO
========================================================= */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'save_travel_settings'
) {
    $postedToken = (string)($_POST['csrf_token'] ?? '');
    $settings = $_POST['travel_lodging_applicable'] ?? [];
    if (!is_array($settings)) {
        $settings = [];
    }

    $postedTravelManagedBy = (string)($_POST['travel_lodging_managed_by'] ?? $existingTravelLodgingManagedBy);
    if (!in_array($postedTravelManagedBy, ['owner', 'nleta'], true)) {
        $postedTravelManagedBy = $existingTravelLodgingManagedBy;
    }

    $postedTravelAmount = isset($_POST['travel_lodging_amount'])
        ? (float)$_POST['travel_lodging_amount']
        : $existingTravelLodgingAmount;

    if ($postedTravelManagedBy === 'owner') {
        $postedTravelAmount = 0.00;
    }

    if ($postedTravelAmount < 0) {
        $postedTravelAmount = 0.00;
    }

    $postedTravelAmount = round($postedTravelAmount, 2);

    if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
        $generationError = 'Security validation failed. Please refresh the page and try again.';
    } elseif (!$existingOffer) {
        $generationError = 'Generate the FSO first before saving travel applicability.';
    } else {
        $offerStatusForTravel = trim((string)($existingOffer['status'] ?? ''));

        if (!in_array($offerStatusForTravel, ['Generated', 'Offer Generated'], true)) {
            $generationError = 'Travel, lodging and local conveyance settings can only be changed before the FSO is sent to the Owner.';
        } else {
            try {
                $conn->begin_transaction();

                $updateTravel = $conn->prepare("
                    UPDATE nleta_offer_assets
                    SET travel_lodging_applicable = ?
                    WHERE offer_id = ? AND asset_id = ?
                    LIMIT 1
                ");

                if (!$updateTravel) {
                    throw new Exception('Unable to prepare travel applicability update: ' . $conn->error);
                }

                foreach ($requestAssets as $assetRow) {
                    $assetId = (int)($assetRow['id'] ?? 0);
                    if ($assetId <= 0) continue;

                    $value = isset($settings[$assetId]) ? (int)$settings[$assetId] : 0;
                    $value = $value === 1 ? 1 : 0;

                    $updateTravel->bind_param(
                        'iii',
                        $value,
                        $existingOffer['id'],
                        $assetId
                    );
                    $updateTravel->execute();
                }

                $updateTravel->close();

                $updateTravelAmount = $conn->prepare("
                    UPDATE nleta_offer_master
                    SET travel_lodging_amount = ?, travel_lodging_managed_by = ?, updated_at = ?
                    WHERE id = ?
                    LIMIT 1
                ");

                if (!$updateTravelAmount) {
                    throw new Exception(
                        'Unable to prepare agreed travel amount update: ' . $conn->error
                    );
                }

                $travelAmountUpdatedAt = $now->format('Y-m-d H:i:s');
                $travelAmountOfferId = (int)$existingOffer['id'];

                $updateTravelAmount->bind_param(
                    'dssi',
                    $postedTravelAmount,
                    $postedTravelManagedBy,
                    $travelAmountUpdatedAt,
                    $travelAmountOfferId
                );

                if (!$updateTravelAmount->execute()) {
                    $error = $updateTravelAmount->error;
                    $updateTravelAmount->close();
                    throw new Exception(
                        'Unable to save agreed travel amount: ' . $error
                    );
                }

                $updateTravelAmount->close();

                $existingTravelLodgingAmount = $postedTravelAmount;
                $existingTravelLodgingManagedBy = $postedTravelManagedBy;
                $existingOffer['travel_lodging_amount'] = $postedTravelAmount;
                $existingOffer['travel_lodging_managed_by'] = $postedTravelManagedBy;

                $conn->commit();

                $successMessage = 'Travel, lodging/local conveyance applicability and agreed amount saved successfully.';

                /* Reload saved settings immediately. */
                $existingTravelSettings = [];
                $reloadTravel = $conn->prepare("
                    SELECT asset_id, travel_lodging_applicable
                    FROM nleta_offer_assets
                    WHERE offer_id = ?
                ");
                if ($reloadTravel) {
                    $reloadOfferId = (int)$existingOffer['id'];
                    $reloadTravel->bind_param('i', $reloadOfferId);
                    $reloadTravel->execute();
                    $reloadResult = $reloadTravel->get_result();
                    while ($reloadRow = $reloadResult->fetch_assoc()) {
                        $existingTravelSettings[(int)$reloadRow['asset_id']] =
                            (int)($reloadRow['travel_lodging_applicable'] ?? 0);
                    }
                    $reloadTravel->close();
                }
            } catch (Throwable $e) {
                $conn->rollback();
                $generationError = $e->getMessage();
            }
        }
    }
}


/* =========================================================
   SEND OFFER TO OWNER
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    (
        $_POST['action']
        ??
        ''
    ) === 'send_offer_to_owner'
) {

    $postedToken =
        $_POST['csrf_token']
        ??
        '';


    if (
        $postedToken === ''
        ||
        !hash_equals(
            $csrfToken,
            $postedToken
        )
    ) {

        $generationError =
            'Security validation failed. Please try again.';
    } else {

        $offerIdToSend =
            (int)(
                $_POST['offer_id']
                ??
                0
            );


        if (
            $offerIdToSend <= 0
        ) {

            $generationError =
                'Invalid Offer ID.';
        } else {

            /*
             * Load the Offer together with the Owner details.
             * These values are used for both access activation
             * and the email sent to the Owner.
             */

            $offerOwnerStmt =
                $conn->prepare("
                    SELECT

                        o.id AS offer_id,
                        o.status AS offer_status,
                        o.offer_no,
                        o.psga_no,
                        o.grand_total,
                        o.offer_date,
                        o.validity_date,

                        sr.owner_id,
                        sr.request_no,

                        u.name AS owner_name,
                        u.email AS owner_email

                    FROM nleta_offer_master o

                    INNER JOIN service_requests sr
                        ON sr.id = o.request_id

                    LEFT JOIN users u
                        ON u.id = sr.owner_id

                    WHERE o.id = ?
                      AND o.request_id = ?

                    LIMIT 1
                ");


            $offerOwnerRow = null;


            if (
                $offerOwnerStmt
            ) {

                $offerOwnerStmt->bind_param(
                    'ii',
                    $offerIdToSend,
                    $requestId
                );


                $offerOwnerStmt->execute();


                $offerOwnerRow =
                    $offerOwnerStmt
                    ->get_result()
                    ->fetch_assoc();


                $offerOwnerStmt->close();
            }


            if (
                !$offerOwnerRow
            ) {

                $generationError =
                    'Offer not found for this Service Request.';
            } else {

                $ownerId =
                    (int)(
                        $offerOwnerRow['owner_id']
                        ??
                        0
                    );


                if (
                    $ownerId <= 0
                ) {

                    $generationError =
                        'Unable to identify the Owner for this Offer.';
                } else {

                    $currentOfferStatus =
                        trim(
                            (string)(
                                $offerOwnerRow['offer_status']
                                ??
                                ''
                            )
                        );


                    /*
                     * Only Generated / Offer Generated offers can
                     * be sent for the first time.
                     */

                    if (
                        !in_array(
                            $currentOfferStatus,
                            [
                                'Generated',
                                'Offer Generated'
                            ],
                            true
                        )
                    ) {

                        if (
                            $currentOfferStatus === 'Sent'
                        ) {

                            /*
                             * Already sent:
                             * make sure access remains enabled.
                             */

                            $adminId =
                                (int)(
                                    $_SESSION['uid']
                                    ??
                                    $_SESSION['user_id']
                                    ??
                                    0
                                );


                            $accessEnabled =
                                enableOwnerService(
                                    $ownerId,
                                    'offers',
                                    $adminId
                                );


                            if (
                                $accessEnabled
                            ) {

                                $sendOfferSuccess =
                                    'Offer has already been sent to the Owner. Owner Offers access is enabled.';
                            } else {

                                $sendOfferSuccess =
                                    'Offer has already been sent to the Owner, but Owner Offers access could not be updated.';
                            }

                        } else {

                            $generationError =
                                'Offer cannot be sent because its current status is: '
                                .
                                (
                                    $currentOfferStatus !== ''
                                    ? $currentOfferStatus
                                    : 'Unknown'
                                )
                                .
                                '.';
                        }

                    } else {

                        /*
                         * Change status first.
                         * Email is sent only after the Offer is
                         * successfully marked as Sent.
                         */

                        $sendStmt =
                            $conn->prepare("
                                UPDATE nleta_offer_master

                                SET
                                    status = 'Sent',
                                    updated_at = ?

                                WHERE id = ?

                                  AND request_id = ?

                                  AND status IN (
                                      'Generated',
                                      'Offer Generated'
                                  )

                                LIMIT 1
                            ");


                        if (
                            !$sendStmt
                        ) {

                            $generationError =
                                'Unable to prepare Send to Owner action: '
                                .
                                $conn->error;

                        } else {

                            $sentAt =
                                $now->format(
                                    'Y-m-d H:i:s'
                                );


                            $sendStmt->bind_param(
                                'sii',
                                $sentAt,
                                $offerIdToSend,
                                $requestId
                            );


                            $sendSuccess =
                                $sendStmt->execute();


                            $rowsChanged =
                                $sendStmt->affected_rows;


                            $sendError =
                                $sendStmt->error;


                            $sendStmt->close();


                            if (
                                $sendSuccess
                                &&
                                $rowsChanged > 0
                            ) {

                                $adminId =
                                    (int)(
                                        $_SESSION['uid']
                                        ??
                                        $_SESSION['user_id']
                                        ??
                                        0
                                    );


                                /*
                                 * Enable the Owner's Offers service.
                                 */

                                $accessEnabled =
                                    enableOwnerService(
                                        $ownerId,
                                        'offers',
                                        $adminId
                                    );


                                /*
                                 * Count assets attached to the Offer.
                                 */

                                $offerAssetCount = 0;


                                $offerAssetCountStmt =
                                    $conn->prepare("
                                        SELECT COUNT(*) AS total
                                        FROM nleta_offer_assets
                                        WHERE offer_id = ?
                                    ");


                                if (
                                    $offerAssetCountStmt
                                ) {

                                    $offerAssetCountStmt->bind_param(
                                        'i',
                                        $offerIdToSend
                                    );


                                    $offerAssetCountStmt->execute();


                                    $offerAssetCountRow =
                                        $offerAssetCountStmt
                                        ->get_result()
                                        ->fetch_assoc();


                                    $offerAssetCount =
                                        (int)(
                                            $offerAssetCountRow['total']
                                            ??
                                            0
                                        );


                                    $offerAssetCountStmt->close();
                                }


                                /*
                                 * Send the Offer email only now.
                                 */

                                $offerEmailSent =
                                    sendNletaOfferSentEmail(
                                        trim(
                                            (string)(
                                                $offerOwnerRow['owner_email']
                                                ??
                                                ''
                                            )
                                        ),
                                        trim(
                                            (string)(
                                                $offerOwnerRow['owner_name']
                                                ??
                                                ''
                                            )
                                        ),
                                        trim(
                                            (string)(
                                                $offerOwnerRow['request_no']
                                                ??
                                                ''
                                            )
                                        ),
                                        trim(
                                            (string)(
                                                $offerOwnerRow['offer_no']
                                                ??
                                                ''
                                            )
                                        ),
                                        trim(
                                            (string)(
                                                $offerOwnerRow['psga_no']
                                                ??
                                                ''
                                            )
                                        ),
                                        serviceNamesText($requestServices),
                                        $offerAssetCount,
                                        (float)(
                                            $offerOwnerRow['grand_total']
                                            ??
                                            0
                                        ),
                                        trim(
                                            (string)(
                                                $offerOwnerRow['offer_date']
                                                ??
                                                ''
                                            )
                                        ),
                                        trim(
                                            (string)(
                                                $offerOwnerRow['validity_date']
                                                ??
                                                ''
                                            )
                                        )
                                    );


                                if (
                                    $accessEnabled
                                    &&
                                    $offerEmailSent
                                ) {

                                    $sendOfferSuccess =
                                        'Offer sent to the Owner successfully. Owner Offers access is enabled and the Offer email has been sent.';

                                } elseif (
                                    $accessEnabled
                                    &&
                                    !$offerEmailSent
                                ) {

                                    $sendOfferSuccess =
                                        'Offer status changed to Sent and Owner Offers access is enabled, but the email could not be sent. Check the Owner email address and mailer configuration.';

                                } elseif (
                                    !$accessEnabled
                                    &&
                                    $offerEmailSent
                                ) {

                                    $sendOfferSuccess =
                                        'Offer email was sent, but Owner Offers access could not be enabled automatically.';

                                } else {

                                    $sendOfferSuccess =
                                        'Offer status changed to Sent, but Owner Offers access and email delivery both require attention.';
                                }

                            } else {

                                $generationError =
                                    'Unable to send the Offer to the Owner.'
                                    .
                                    (
                                        $sendError !== ''
                                        ? ' ' . $sendError
                                        : ''
                                    );
                            }
                        }
                    }
                }
            }
        }
    }
}


/* =========================================================
   GENERATE OFFER
========================================================= */
/* =========================================================
   GENERATE OFFER
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    in_array(
        (
            $_POST['action']
            ??
            ''
        ),
        [
            'generate_offer',
            'regenerate_offer'
        ],
        true
    )
) {

    $isRegenerate =
        (
            (
                $_POST['action']
                ??
                ''
            ) === 'regenerate_offer'
        );

    $postedToken =
        $_POST['csrf_token']
        ??
        '';


    if (
        $postedToken === ''
        ||
        !hash_equals(
            $csrfToken,
            $postedToken
        )
    ) {

        $generationError =
            'Security validation failed. Please try again.';
    } elseif (
        $existingOffer
        &&
        !$isRegenerate
    ) {

        $generationError =
            'An Offer already exists for this Service Request. Use "Regenerate Offer" if you want to recalculate it.';
    } elseif (
        $isRegenerate
        &&
        $existingOffer
        &&
        !in_array(
            trim(
                (string)(
                    $existingOffer['status']
                    ?? ''
                )
            ),
            [
                'Generated',
                'Offer Generated'
            ],
            true
        )
    ) {

        $generationError =
            'This Offer cannot be regenerated after it has been sent or moved to a later status.';
    } else {

        /* -------------------------------------------------
           Per-asset travel applicability submitted by Admin.
           1 = Applicable, 0 = Not Applicable.
           During regeneration, if a value is not posted, keep
           the previously saved value.
        ------------------------------------------------- */
        $postedTravelSettings = $_POST['travel_lodging_applicable'] ?? [];
        if (!is_array($postedTravelSettings)) {
            $postedTravelSettings = [];
        }

        $travelLodgingManagedBy = (string)($_POST['travel_lodging_managed_by'] ?? $existingTravelLodgingManagedBy);
        if (!in_array($travelLodgingManagedBy, ['owner', 'nleta'], true)) {
            $travelLodgingManagedBy = $existingTravelLodgingManagedBy;
        }

        $travelLodgingAmount = isset($_POST['travel_lodging_amount'])
            ? (float)$_POST['travel_lodging_amount']
            : $existingTravelLodgingAmount;

        if ($travelLodgingManagedBy === 'owner') {
            $travelLodgingAmount = 0.00;
        }

        if ($travelLodgingAmount < 0) {
            $travelLodgingAmount = 0.00;
        }

        $travelLodgingAmount = round($travelLodgingAmount, 2);

        $travelSettingsForOffer = [];
        foreach ($requestAssets as $assetRow) {
            $assetIdForTravel = (int)($assetRow['id'] ?? 0);
            if ($assetIdForTravel <= 0) {
                continue;
            }

            if (array_key_exists((string)$assetIdForTravel, $postedTravelSettings)) {
                $travelSettingsForOffer[$assetIdForTravel] =
                    ((int)$postedTravelSettings[$assetIdForTravel] === 1) ? 1 : 0;
            } elseif (isset($existingTravelSettings[$assetIdForTravel])) {
                $travelSettingsForOffer[$assetIdForTravel] =
                    ((int)$existingTravelSettings[$assetIdForTravel] === 1) ? 1 : 0;
            } else {
                $travelSettingsForOffer[$assetIdForTravel] = 0;
            }
        }

        try {

            $conn->begin_transaction();


            /* =================================================
               PSGA + NEW FSO PER SERVICE
               -------------------------------------------------
               PSGA:
                   Permanent for the Owner/Client.

               FSO:
                   NEW for every Service Request.

               Numbering:
                   -01, -02, -03, ...

               Existing FSO records are never reused.
            ================================================== */

            $psgaNo =
                trim(
                    $serviceRequestPSGANo
                );


            /*
             * REGENERATE:
             * Keep the exact same PSGA and FSO identity.
             */
            if (
                $isRegenerate
                &&
                $existingOffer
            ) {

                $psgaNo =
                    trim(
                        (string)(
                            $existingOffer['psga_no']
                            ?? ''
                        )
                    );

                $permanentOwnerFSO =
                    trim(
                        (string)(
                            $existingOffer['offer_no']
                            ?? ''
                        )
                    );

                if ($psgaNo === '') {
                    throw new Exception(
                        'Existing Offer has no PSGA number. Regeneration stopped safely.'
                    );
                }

            /*
             * Reuse the permanent Owner PSGA if it already
             * exists. Only the first-ever service generates
             * the PSGA number.
             */
            } elseif (
                $psgaNo === ''
                &&
                $permanentOwnerPSGANo !== ''
            ) {

                $psgaNo =
                    $permanentOwnerPSGANo;
            }


            if (
                $psgaNo === ''
            ) {

                $psgaNo =
                    generateDailyPSGA(
                        $conn,
                        $now
                    );

                $savePSGAStmt =
                    $conn->prepare("
                        UPDATE service_requests
                        SET psga_no = ?
                        WHERE id = ?
                          AND (
                                psga_no IS NULL
                                OR TRIM(psga_no) = ''
                              )
                        LIMIT 1
                    ");

                if (!$savePSGAStmt) {

                    throw new Exception(
                        'Unable to prepare permanent PSGA save: ' .
                        $conn->error
                    );
                }

                $savePSGAStmt->bind_param(
                    'si',
                    $psgaNo,
                    $requestId
                );

                if (!$savePSGAStmt->execute()) {

                    $stmtError =
                        $savePSGAStmt->error;

                    $savePSGAStmt->close();

                    throw new Exception(
                        'Unable to save permanent PSGA: ' .
                        $stmtError
                    );
                }

                $savePSGAStmt->close();

                $permanentOwnerPSGANo =
                    $psgaNo;

            } else {

                /*
                 * Store the existing permanent PSGA against
                 * this new Service Request.
                 */
                $savePSGAStmt =
                    $conn->prepare("
                        UPDATE service_requests
                        SET psga_no = ?
                        WHERE id = ?
                        LIMIT 1
                    ");

                if ($savePSGAStmt) {

                    $savePSGAStmt->bind_param(
                        'si',
                        $psgaNo,
                        $requestId
                    );

                    $savePSGAStmt->execute();

                    $savePSGAStmt->close();
                }
            }


            /* =================================================
               OFFER DATE
            ================================================== */

            $offerDate =
                $now->format(
                    'Y-m-d'
                );


            $validityDateObject =
                clone $now;


            $validityDateObject->modify(
                '+30 days'
            );


            $validityDate =
                $validityDateObject
                ->format(
                    'Y-m-d'
                );


            /* =================================================
               NEW FSO NUMBER FOR THIS SERVICE
               -------------------------------------------------
               Every new Service Request gets a new FSO.

               Example:
               FSO-26-27/NLETA/TS-B05092026-01
               FSO-26-27/NLETA/TS-B05092026-02
               FSO-26-27/NLETA/TS-B05092026-03
            ================================================== */

            /*
             * During regeneration the existing FSO number is
             * deliberately preserved. A new FSO sequence is
             * calculated only for a brand-new Service Request.
             */
            if ($isRegenerate && $existingOffer) {

                $offerNo =
                    trim(
                        (string)(
                            $existingOffer['offer_no']
                            ?? ''
                        )
                    );

                if ($offerNo === '') {
                    throw new Exception(
                        'Existing Offer has no FSO number. Regeneration stopped safely.'
                    );
                }

            } else {

                $maxFsoSequence = 0;


                /*
                 * Lock existing Owner Offer rows while calculating
                 * the next sequence. The transaction prevents two
                 * normal generation requests from using the same
                 * sequence at the same time.
                 */
                $ownerFsoStmt =
                    $conn->prepare("
                    SELECT
                        o.offer_no
                    FROM nleta_offer_master o
                    INNER JOIN service_requests sr
                        ON sr.id = o.request_id
                    WHERE sr.owner_id = ?
                    FOR UPDATE
                ");

            if (!$ownerFsoStmt) {

                throw new Exception(
                    'Unable to prepare FSO sequence query: ' .
                    $conn->error
                );
            }


            $ownerFsoStmt->bind_param(
                'i',
                $ownerId
            );


            if (!$ownerFsoStmt->execute()) {

                $ownerFsoError =
                    $ownerFsoStmt->error;

                $ownerFsoStmt->close();

                throw new Exception(
                    'Unable to read existing FSO sequence: ' .
                    $ownerFsoError
                );
            }


            $ownerFsoResult =
                $ownerFsoStmt->get_result();


            while (
                $ownerFsoRow =
                $ownerFsoResult->fetch_assoc()
            ) {

                $oldOfferNo =
                    trim(
                        (string)(
                            $ownerFsoRow['offer_no']
                            ?? ''
                        )
                    );


                if (
                    $oldOfferNo === ''
                ) {
                    continue;
                }


                /*
                 * Only the final two digits are the FSO
                 * service sequence.
                 */
                if (
                    preg_match(
                        '/-(\d{2})$/',
                        $oldOfferNo,
                        $matches
                    )
                ) {

                    $sequence =
                        (int)$matches[1];


                    if (
                        $sequence > $maxFsoSequence
                    ) {

                        $maxFsoSequence =
                            $sequence;
                    }
                }
            }


            $ownerFsoStmt->close();


            $fsoSequence =
                max(
                    1,
                    $maxFsoSequence + 1
                );


            /*
             * Keep the sequence two digits:
             * 01, 02, 03 ... 09, 10, 11 ...
             */
                $fsoSequenceText =
                    str_pad(
                        (string)$fsoSequence,
                        2,
                        '0',
                        STR_PAD_LEFT
                    );


                $offerNo =
                    'FSO-'
                    .
                    $financialYear
                    .
                    '/NLETA/'
                    .
                    $clientShortCode
                    .
                    '-'
                    .
                    $psgaNo
                    .
                    '-'
                    .
                    $fsoSequenceText;
            }


            /*
             * IMPORTANT:
             * This variable is the FSO for THIS Service Request.
             * During regeneration it remains exactly unchanged.
             */
            $permanentOwnerFSO =
                $offerNo;


            /* =================================================
               MULTI-SERVICE PRICING
               -------------------------------------------------
               Every selected service is priced independently.
               Technical services (PAT/SAT/SCI) are priced against
               each selected asset. Annexure-B services are priced
               once per selected service, according to the existing
               business rules.
            ================================================== */

            foreach ($requestServices as $selectedService) {

                $serviceCode =
                    strtoupper(
                        trim(
                            (string)($selectedService['service_code'] ?? '')
                        )
                    );

                $serviceName =
                    trim(
                        (string)($selectedService['service_name'] ?? '')
                    );

                if ($serviceCode === '') {
                    throw new Exception(
                        'A selected service is missing its Service Code.'
                    );
                }

                /* =================================================
                   TECHNICAL SERVICES
                ================================================== */

                if (
                    in_array(
                        $serviceCode,
                        ['PAT', 'SAT', 'SCI'],
                        true
                    )
                ) {

                    foreach ($requestAssets as $asset) {

                        $escalatorRise = '';

                        $assetType =
                            strtolower(
                                trim(
                                    (string)($asset['asset_type'] ?? '')
                                )
                            );

                        $pricingCategory = '';

                        /* =================================================
                           LIFT
                        ================================================== */

                        if ($assetType === 'lift') {

                            $pricingCategory =
                                getLiftPricingCategory(
                                    $asset['number_of_floors'] ?? ''
                                );

                            if ($pricingCategory === '') {
                                throw new Exception(
                                    'Number of floors is missing or invalid for '
                                    . ($asset['display_asset_id'] ?? $asset['asset_name'])
                                    . '. Please complete the Permanent Lift Profile first.'
                                );
                            }

                        /* =================================================
                           ESCALATOR
                        ================================================== */

                        } elseif ($assetType === 'escalator') {

                            $escalatorRise =
                                trim(
                                    (string)($asset['escalator_rise'] ?? '')
                                );

                            if (
                                !is_numeric($escalatorRise)
                                || (float)$escalatorRise <= 0
                            ) {
                                throw new Exception(
                                    'Escalator Rise is missing or invalid for '
                                    . ($asset['display_asset_id'] ?? $asset['asset_name'])
                                    . '. Please enter the Escalator Rise in metres in the Permanent Asset Profile.'
                                );
                            }

                            $escalatorRiseValue = (float)$escalatorRise;

                            $pricingCategory =
                                getEscalatorPricingCategory(
                                    $escalatorRiseValue
                                );

                            if ($pricingCategory === '') {
                                throw new Exception(
                                    'Escalator Rise of '
                                    . number_format($escalatorRiseValue, 2)
                                    . ' m is above the standard 7.0 m pricing limit. A customized fee is required.'
                                );
                            }

                        } else {

                            throw new Exception(
                                'Unsupported asset type for technical pricing: '
                                . ($asset['asset_type'] ?? 'Unknown')
                            );
                        }

                        /* =================================================
                           PRICING MASTER
                        ================================================== */

                        $priceStmt =
                            $conn->prepare("
                                SELECT
                                    service_name,
                                    rate,
                                    pricing_unit,
                                    annexure,
                                    coverage_description
                                FROM nleta_pricing_master
                                WHERE service_code = ?
                                  AND asset_type = ?
                                  AND asset_category = ?
                                  AND annexure = 'A'
                                  AND is_active = 1
                                LIMIT 1
                            ");

                        if (!$priceStmt) {
                            throw new Exception(
                                'Unable to load pricing master.'
                            );
                        }

                        $pricingAssetType = ucfirst($assetType);

                        $priceStmt->bind_param(
                            'sss',
                            $serviceCode,
                            $pricingAssetType,
                            $pricingCategory
                        );

                        $priceStmt->execute();

                        $price =
                            $priceStmt
                            ->get_result()
                            ->fetch_assoc();

                        $priceStmt->close();

                        /* =================================================
                           PRICING FALLBACK
                        ================================================== */

                        if (!$price) {

                            $fallbackPriceStmt =
                                $conn->prepare("
                                    SELECT
                                        service_name,
                                        rate,
                                        pricing_unit,
                                        annexure,
                                        coverage_description,
                                        asset_category
                                    FROM nleta_pricing_master
                                    WHERE service_code = ?
                                      AND asset_type = ?
                                      AND annexure = 'A'
                                      AND is_active = 1
                                    ORDER BY id ASC
                                ");

                            if (!$fallbackPriceStmt) {
                                throw new Exception(
                                    'Unable to load pricing master fallback.'
                                );
                            }

                            $fallbackPriceStmt->bind_param(
                                'ss',
                                $serviceCode,
                                $pricingAssetType
                            );

                            $fallbackPriceStmt->execute();

                            $fallbackResult =
                                $fallbackPriceStmt
                                ->get_result();

                            $pricingRows = [];

                            while (
                                $pricingRow =
                                $fallbackResult->fetch_assoc()
                            ) {
                                $pricingRows[] = $pricingRow;
                            }

                            $fallbackPriceStmt->close();

                            if (count($pricingRows) === 1) {

                                $price = $pricingRows[0];

                                $pricingCategory =
                                    $price['asset_category']
                                    ?? $pricingCategory;

                            } elseif (count($pricingRows) > 1) {

                                $rawCategory =
                                    strtolower(
                                        trim(
                                            (string)($asset['category'] ?? '')
                                        )
                                    );

                                foreach ($pricingRows as $pricingRow) {

                                    $masterCategory =
                                        strtolower(
                                            trim(
                                                (string)($pricingRow['asset_category'] ?? '')
                                            )
                                        );

                                    if (
                                        $masterCategory !== ''
                                        && $rawCategory !== ''
                                        && (
                                            stripos($rawCategory, $masterCategory) !== false
                                            || stripos($masterCategory, $rawCategory) !== false
                                        )
                                    ) {
                                        $price = $pricingRow;
                                        $pricingCategory =
                                            $pricingRow['asset_category'];
                                        break;
                                    }
                                }
                            }
                        }

                        if (!$price) {
                            throw new Exception(
                                'No pricing found in Pricing Master for '
                                . $serviceName
                                . ' / '
                                . $pricingAssetType
                                . ' / '
                                . (
                                    $pricingCategory !== ''
                                    ? $pricingCategory
                                    : 'Asset category not configured'
                                )
                                . '. Please configure the correct asset category in nleta_pricing_master.'
                            );
                        }

                        $quantity = 1;

                        $rate =
                            (float)($price['rate'] ?? 0);

                        $amount =
                            $rate * $quantity;

                        $subtotal += $amount;

                        /* =================================================
                           SERVICE DESCRIPTION
                        ================================================== */

                        $assetCapacity =
                            trim(
                                (string)($asset['capacity'] ?? '')
                            );

                        $assetFloors =
                            (int)($asset['number_of_floors'] ?? 0);

                        if ($assetType === 'lift') {

                            $technicalText = '';

                            if ($assetCapacity !== '') {
                                $technicalText .= $assetCapacity;
                            }

                            if ($assetFloors > 0) {

                                if ($technicalText !== '') {
                                    $technicalText .= ', ';
                                }

                                $technicalText .=
                                    $assetFloors . ' Floors';
                            }

                            if ($technicalText === '') {
                                $technicalText = $pricingCategory;
                            }

                            $displayServiceName =
                                $serviceName
                                . ' for '
                                . $technicalText
                                . ' Lift';

                        } elseif ($assetType === 'escalator') {

                            $displayServiceName =
                                $serviceName
                                . ' for '
                                . $pricingCategory
                                . ' ('
                                . number_format(
                                    (float)$escalatorRise,
                                    2
                                )
                                . ' m Rise)';

                        } else {

                            $displayServiceName =
                                $serviceName;
                        }

                        $offerItems[] = [
                            'service_code' =>
                                $serviceCode,

                            'service_name' =>
                                $displayServiceName,

                            'quantity' =>
                                $quantity,

                            'rate' =>
                                $rate,

                            'amount' =>
                                $amount,

                            'annexure' =>
                                $price['annexure'] ?? '',

                            'pricing_basis' =>
                                $price['pricing_unit'] ?? '',

                            'scope' =>
                                $pricingCategory,

                            'asset_id' =>
                                (int)($asset['id'] ?? 0),

                            'asset_display_id' =>
                                $asset['display_asset_id'] ?? ''
                        ];
                    }

                } else {

                    /* =================================================
                       ANNEXURE B
                       -------------------------------------------------
                       DGS is special because Annexure-B contains:
                       1) Society Platform Fee
                       2) Per participating Asset / Year fee
                    ================================================== */

                    if (
                        $serviceCode === 'DG'
                        || $serviceCode === 'DIGITAL_GOVERNANCE'
                    ) {

                        $assetCount =
                            getDgsAssetCount($requestAssets);

                        if ($assetCount <= 0) {
                            throw new Exception(
                                'Digital Governance Support requires at least one participating asset.'
                            );
                        }

                        $dgsPerAssetRate =
                            getDgsPerAssetRate($assetCount);

                        if ($dgsPerAssetRate <= 0) {
                            throw new Exception(
                                'DGS standard pricing is available only up to 50 participating assets. '
                                . $assetCount
                                . ' assets require a separate commercial quotation as per Annexure-B Rev.1.'
                            );
                        }

                        /* Society Platform Fee — ₹7,500 / Society / Year */
                        $societyFee = 7500.00;

                        $offerItems[] = [
                            'service_code' => $serviceCode,
                            'service_name' => 'Digital Governance System (DGS) — Society Platform Fee',
                            'quantity' => 1,
                            'rate' => $societyFee,
                            'amount' => $societyFee,
                            'annexure' => 'B',
                            'pricing_basis' => 'Per Society / Year',
                            'scope' => 'Society Dashboard, Digital Administration, QR Platform, Engineering Database, Management Login',
                            'asset_id' => null,
                            'asset_display_id' => null
                        ];

                        $subtotal += $societyFee;

                        /* Per participating Asset / Year */
                        $dgsAssetAmount =
                            $dgsPerAssetRate * $assetCount;

                        $offerItems[] = [
                            'service_code' => $serviceCode,
                            'service_name' => 'Digital Governance System (DGS) — Per Asset Governance Fee',
                            'quantity' => $assetCount,
                            'rate' => $dgsPerAssetRate,
                            'amount' => $dgsAssetAmount,
                            'annexure' => 'B',
                            'pricing_basis' => 'Per Asset / Year',
                            'scope' => 'Participating Assets: Lift + Escalator + Travellator',
                            'asset_id' => null,
                            'asset_display_id' => null
                        ];

                        $subtotal += $dgsAssetAmount;

                    } else {

                        $pricingCode = $serviceCode;
                        $quantity = 1;

                        if ($serviceCode === 'UAP') {
                            $quantity = 1;

                        } elseif (
                            $serviceCode === 'MOCK'
                            || $serviceCode === 'QRT'
                        ) {
                            $pricingCode = 'MOCK';
                            $quantity = 1;

                        } elseif ($serviceCode === 'IEGS') {
                            throw new Exception(
                                'Integrated Engineering Governance Support is As Per Scope. A final commercial amount must be entered before generating this Offer.'
                            );
                        }

                        $priceStmt =
                            $conn->prepare("
                                SELECT
                                    service_name,
                                    rate,
                                    pricing_unit,
                                    annexure,
                                    coverage_description
                                FROM nleta_pricing_master
                                WHERE service_code = ?
                                  AND is_active = 1
                                  AND annexure = 'B'
                                ORDER BY id ASC
                                LIMIT 1
                            ");

                        if (!$priceStmt) {
                            throw new Exception(
                                'Unable to load Annexure B pricing.'
                            );
                        }

                        $priceStmt->bind_param(
                            's',
                            $pricingCode
                        );

                        $priceStmt->execute();

                        $price =
                            $priceStmt
                            ->get_result()
                            ->fetch_assoc();

                        $priceStmt->close();

                        if (!$price) {
                            throw new Exception(
                                'No Annexure-B pricing found for service: '
                                . $serviceName
                            );
                        }

                        $rate =
                            (float)($price['rate'] ?? 0);

                        $amount =
                            $rate * $quantity;

                        $subtotal += $amount;

                        $offerItems[] = [
                            'service_code' => $serviceCode,
                            'service_name' => $price['service_name'] ?? $serviceName,
                            'quantity' => $quantity,
                            'rate' => $rate,
                            'amount' => $amount,
                            'annexure' => $price['annexure'] ?? 'B',
                            'pricing_basis' => $price['pricing_unit'] ?? '',
                            'scope' => $price['coverage_description'] ?? '',
                            'asset_id' => null,
                            'asset_display_id' => null
                        ];
                    }
                }
            }

            /* =================================================
               VALIDATE TOTAL
            ================================================== */

            if (
                $subtotal <= 0
            ) {

                throw new Exception(
                    'Offer subtotal cannot be zero.'
                );
            }


            /* =================================================
               GST
            ================================================== */

            $gstRate =
                18.00;


            $gstAmount =
                round(
                    $subtotal
                        *
                        $gstRate
                        /
                        100,
                    2
                );


            $grandTotal =
                round(
                    $subtotal
                        +
                        $gstAmount,
                    2
                );


            /* =================================================
               PAYMENT
            ================================================== */

            $paymentAdvance =
                round(
                    $grandTotal
                        *
                        0.50,
                    2
                );


            $paymentServiceActivation =
                round(
                    $grandTotal
                        *
                        0.25,
                    2
                );


            $paymentFinal =
                round(
                    $grandTotal
                        -
                        $paymentAdvance
                        -
                        $paymentServiceActivation,
                    2
                );


            /* =================================================
               CREATED BY
            ================================================== */

            $createdBy =
                (int)(
                    $_SESSION['uid']
                    ??
                    $_SESSION['user_id']
                    ??
                    0
                );


            $createdAt =
                $now->format(
                    'Y-m-d H:i:s'
                );


            /* =================================================
               INSERT / REGENERATE OFFER MASTER
            ================================================== */

            if ($isRegenerate && $existingOffer) {

                $offerId =
                    (int)$existingOffer['id'];

                $updateOfferStmt =
                    $conn->prepare("
                        UPDATE nleta_offer_master
                        SET
                            client_id = ?,
                            psga_no = ?,
                            offer_no = ?,
                            financial_year = ?,
                            client_short_code = ?,
                            offer_date = ?,
                            validity_date = ?,
                            subtotal = ?,
                            gst_rate = ?,
                            gst_amount = ?,
                            grand_total = ?,
                            payment_advance = ?,
                            payment_service_activation = ?,
                            payment_final = ?,
                            travel_lodging_amount = ?,
                            travel_lodging_managed_by = ?,
                            status = 'Generated',
                            updated_at = ?
                        WHERE id = ?
                          AND request_id = ?
                          AND status IN ('Generated','Offer Generated')
                        LIMIT 1
                    ");

                if (!$updateOfferStmt) {
                    throw new Exception(
                        'Unable to prepare Offer regeneration update: ' .
                        $conn->error
                    );
                }

                $updateOfferStmt->bind_param(
                    'issssssddddddddssii',
                    $clientId,
                    $psgaNo,
                    $offerNo,
                    $financialYear,
                    $clientShortCode,
                    $offerDate,
                    $validityDate,
                    $subtotal,
                    $gstRate,
                    $gstAmount,
                    $grandTotal,
                    $paymentAdvance,
                    $paymentServiceActivation,
                    $paymentFinal,
                    $travelLodgingAmount,
                    $travelLodgingManagedBy,
                    $createdAt,
                    $offerId,
                    $requestId
                );

                if (!$updateOfferStmt->execute()) {
                    $error = $updateOfferStmt->error;
                    $updateOfferStmt->close();
                    throw new Exception(
                        'Unable to regenerate Offer: ' . $error
                    );
                }

                if ($updateOfferStmt->affected_rows < 0) {
                    $updateOfferStmt->close();
                    throw new Exception(
                        'Offer regeneration did not complete.'
                    );
                }

                $updateOfferStmt->close();

                /*
                 * Replace only the generated child records.
                 * The Offer/FSO identity remains unchanged.
                 */
                $deleteItemsStmt =
                    $conn->prepare("
                        DELETE FROM nleta_offer_items
                        WHERE offer_id = ?
                    ");

                if (!$deleteItemsStmt) {
                    throw new Exception(
                        'Unable to prepare Offer Item regeneration cleanup.'
                    );
                }

                $deleteItemsStmt->bind_param(
                    'i',
                    $offerId
                );

                if (!$deleteItemsStmt->execute()) {
                    $error = $deleteItemsStmt->error;
                    $deleteItemsStmt->close();
                    throw new Exception(
                        'Unable to refresh Offer Items: ' . $error
                    );
                }

                $deleteItemsStmt->close();

                $deleteAssetsStmt =
                    $conn->prepare("
                        DELETE FROM nleta_offer_assets
                        WHERE offer_id = ?
                    ");

                if (!$deleteAssetsStmt) {
                    throw new Exception(
                        'Unable to prepare Offer Asset regeneration cleanup.'
                    );
                }

                $deleteAssetsStmt->bind_param(
                    'i',
                    $offerId
                );

                if (!$deleteAssetsStmt->execute()) {
                    $error = $deleteAssetsStmt->error;
                    $deleteAssetsStmt->close();
                    throw new Exception(
                        'Unable to refresh Offer Assets: ' . $error
                    );
                }

                $deleteAssetsStmt->close();

            } else {

                $offerStmt =
                    $conn->prepare("
                        INSERT INTO nleta_offer_master
                        (
                            request_id,
                            client_id,
                            psga_no,
                            offer_no,
                            financial_year,
                            client_short_code,
                            offer_date,
                            validity_date,
                            subtotal,
                            gst_rate,
                            gst_amount,
                            grand_total,
                            payment_advance,
                            payment_service_activation,
                            payment_final,
                            travel_lodging_amount,
                            travel_lodging_managed_by,
                            status,
                            created_by,
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
                            ?,
                            ?,
                            'Generated',
                            ?,
                            ?,
                            ?
                        )
                    ");

                if (!$offerStmt) {
                    throw new Exception(
                        'Unable to prepare Offer Master insert: ' .
                        $conn->error
                    );
                }

                $offerStmt->bind_param(
                    'iissssssddddddddsiss',
                    $requestId,
                    $clientId,
                    $psgaNo,
                    $offerNo,
                    $financialYear,
                    $clientShortCode,
                    $offerDate,
                    $validityDate,
                    $subtotal,
                    $gstRate,
                    $gstAmount,
                    $grandTotal,
                    $paymentAdvance,
                    $paymentServiceActivation,
                    $paymentFinal,
                    $travelLodgingAmount,
                    $travelLodgingManagedBy,
                    $createdBy,
                    $createdAt,
                    $createdAt
                );

                if (!$offerStmt->execute()) {
                    $error = $offerStmt->error;
                    $offerStmt->close();
                    throw new Exception(
                        'Unable to create Offer: ' . $error
                    );
                }

                $offerId =
                    (int)$offerStmt->insert_id;

                $offerStmt->close();
            }

            /* =================================================
               INSERT OFFER ITEMS
            ================================================== */

            $itemStmt =
                $conn->prepare("
                    INSERT INTO nleta_offer_items
                    (
                        offer_id,
                        service_code,
                        service_name,
                        quantity,
                        rate,
                        amount,
                        annexure,
                        pricing_basis,
                        scope,
                        created_at
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
                        ?
                    )
                ");


            if (
                !$itemStmt
            ) {

                throw new Exception(
                    'Unable to prepare Offer Item insert.'
                );
            }


            foreach (
                $offerItems
                as $item
            ) {

                $itemOfferId =
                    $offerId;

                $itemServiceCode =
                    (string)(
                        $item['service_code']
                        ??
                        ''
                    );

                $itemServiceName =
                    (string)(
                        $item['service_name']
                        ??
                        ''
                    );

                $itemQuantity =
                    (float)(
                        $item['quantity']
                        ??
                        0
                    );

                $itemRate =
                    (float)(
                        $item['rate']
                        ??
                        0
                    );

                $itemAmount =
                    (float)(
                        $item['amount']
                        ??
                        0
                    );

                $itemAnnexure =
                    (string)(
                        $item['annexure']
                        ??
                        ''
                    );

                $itemBasis =
                    (string)(
                        $item['pricing_basis']
                        ??
                        ''
                    );

                $itemScope =
                    (string)(
                        $item['scope']
                        ??
                        ''
                    );


                $itemStmt->bind_param(
                    'issdddssss',
                    $itemOfferId,
                    $itemServiceCode,
                    $itemServiceName,
                    $itemQuantity,
                    $itemRate,
                    $itemAmount,
                    $itemAnnexure,
                    $itemBasis,
                    $itemScope,
                    $createdAt
                );


                if (
                    !$itemStmt->execute()
                ) {

                    $error =
                        $itemStmt->error;

                    $itemStmt->close();

                    throw new Exception(
                        'Unable to save Offer Item: '
                            .
                            $error
                    );
                }
            }


            $itemStmt->close();


            /* =================================================
               INSERT OFFER ASSETS
            ================================================== */

            $offerAssetStmt =
                $conn->prepare("
                    INSERT INTO nleta_offer_assets
                    (
                        offer_id,
                        asset_id,
                        travel_lodging_applicable,
                        created_at
                    )
                    VALUES
                    (
                        ?,
                        ?,
                        ?,
                        ?
                    )
                ");


            if (
                !$offerAssetStmt
            ) {

                throw new Exception(
                    'Unable to prepare Offer Asset insert.'
                );
            }


            foreach (
                $requestAssets
                as $asset
            ) {

                $assetId =
                    (int)(
                        $asset['id']
                        ??
                        0
                    );


                $travelApplicable =
                    (int)($travelSettingsForOffer[$assetId] ?? 0);

                $offerAssetStmt->bind_param(
                    'iiis',
                    $offerId,
                    $assetId,
                    $travelApplicable,
                    $createdAt
                );


                if (
                    !$offerAssetStmt->execute()
                ) {

                    $error =
                        $offerAssetStmt->error;

                    $offerAssetStmt->close();

                    throw new Exception(
                        'Unable to save Offer Asset: '
                            .
                            $error
                    );
                }
            }


            $offerAssetStmt->close();


            /* =================================================
               UPDATE SERVICE REQUEST
            ================================================== */

            $updateRequest =
                $conn->prepare("
                    UPDATE service_requests

                    SET status =
                        'Offer Generated'

                    WHERE id = ?

                      AND status = 'Accepted'

                    LIMIT 1
                ");


            if (
                !$updateRequest
            ) {

                throw new Exception(
                    'Unable to update Service Request.'
                );
            }


            $updateRequest->bind_param(
                'i',
                $requestId
            );


            if (
                !$updateRequest->execute()
            ) {

                $error =
                    $updateRequest->error;

                $updateRequest->close();

                throw new Exception(
                    'Unable to update Service Request: '
                        .
                        $error
                );
            }


            $updateRequest->close();


            /* =================================================
               COMMIT
            ================================================== */

            $conn->commit();


            $generatedNow = true;


            /*
             * IMPORTANT:
             * Generating/regenerating the FSO does NOT send it
             * to the Owner.
             *
             * The Admin must explicitly click:
             * "Send to Owner".
             */
            $sendOfferSuccess =
                $isRegenerate
                ?
                'Offer regenerated successfully using the latest asset/pricing details. The same FSO number has been preserved. Review it and click "Send to Owner" when ready.'
                :
                'Offer generated successfully. Review the generated FSO and click "Send to Owner" when it is ready.';


            /* =================================================
               RELOAD OFFER
            ================================================== */

            $existingStmt =
                $conn->prepare("
                    SELECT *
                    FROM nleta_offer_master
                    WHERE id = ?
                    LIMIT 1
                ");


            if (
                $existingStmt
            ) {

                $existingStmt->bind_param(
                    'i',
                    $offerId
                );


                $existingStmt->execute();


                $existingOffer =
                    $existingStmt
                    ->get_result()
                    ->fetch_assoc();


                $existingStmt->close();
            }
        } catch (
            Throwable $e
        ) {

            @$conn->rollback();

            $generationError =
                $e->getMessage();
        }
    }
}


/* =========================================================
   LOAD EXISTING OFFER ITEMS
========================================================= */

$existingItems = [];


if (
    $existingOffer
) {

    $itemStmt =
        $conn->prepare("
            SELECT *
            FROM nleta_offer_items
            WHERE offer_id = ?
            ORDER BY id ASC
        ");


    if (
        $itemStmt
    ) {

        $existingOfferId =
            (int)$existingOffer['id'];


        $itemStmt->bind_param(
            'i',
            $existingOfferId
        );


        $itemStmt->execute();


        $itemResult =
            $itemStmt
            ->get_result();


        while (
            $row =
            $itemResult->fetch_assoc()
        ) {

            $existingItems[] =
                $row;
        }


        $itemStmt->close();
    }
}


/* =========================================================
   OFFER DISPLAY VALUES
========================================================= */

$displayOfferNo =
    $existingOffer['offer_no']
    ??
    (
        'FSO-'
        .
        $financialYear
        .
        '/NLETA/'
        .
        $clientShortCode
        .
        '-AUTO'
    );


$displayPSGANo =
    $existingOffer['psga_no']
    ??
    $serviceRequestPSGANo
    ??
    $permanentOwnerPSGANo
    ??
    'Generated on Offer Creation';


$displayOfferDate =
    $existingOffer['offer_date']
    ??
    $now->format(
        'Y-m-d'
    );


$displayValidityDate =
    $existingOffer['validity_date']
    ??
    (
        clone $now
    )
    ->modify(
        '+30 days'
    )
    ->format(
        'Y-m-d'
    );


$displaySubtotal =
    $existingOffer
    ? (float)(
        $existingOffer['subtotal']
        ??
        0
    )
    : 0;


$displayGST =
    $existingOffer
    ? (float)(
        $existingOffer['gst_amount']
        ??
        0
    )
    : 0;


$displayGrandTotal =
    $existingOffer
    ? (float)(
        $existingOffer['grand_total']
        ??
        0
    )
    : 0;


$displayAdvance =
    $existingOffer
    ? (float)(
        $existingOffer['payment_advance']
        ??
        0
    )
    : 0;


$displayServiceActivation =
    $existingOffer
    ? (float)(
        $existingOffer['payment_service_activation']
        ??
        0
    )
    : 0;


$displayFinal =
    $existingOffer
    ? (float)(
        $existingOffer['payment_final']
        ??
        0
    )
    : 0;

$displayTravelLodgingAmount =
    $existingOffer
    ? max(
        0.00,
        (float)(
            $existingOffer['travel_lodging_amount']
            ??
            0
        )
    )
    : 0.00;


/* =========================================================
   SERVICE DISPLAY
========================================================= */

$serviceCode =
    strtoupper(
        trim(
            (string)(
                $requestServices[0]['service_code']
                ?? $request['service_code']
                ?? ''
            )
        )
    );


$serviceName =
    serviceNamesText($requestServices);


/* =========================================================
   SELECTED SERVICES FOR FSO TEMPLATE
========================================================= */
$GLOBALS['nleta_offer_request_services'] = $requestServices;


/* =========================================================
   DELIVERABLES
========================================================= */

$deliverables = [

    'Applicable SAF (Service Activation Form)',

    'Technical Inspection Checklist',

    'Technical Inspection Report',

    'Certificate (Where Applicable)',

    'Digital NCR & Verification Record',

    'Verified Digital Record'

];


/* =========================================================
   PAGE
========================================================= */

include('../includes/header.php');

include('../includes/sidebar-admin.php');

?>

<style>
    /* =========================================================
   SCREEN
========================================================= */

    .offer-generator-page {

        width: calc(100% - 250px);

        margin-left: 250px;

        margin-top: 65px;

        padding: 24px;

        background: #f4f6f9;

        min-height:
            calc(100vh - 65px);
    }


    .offer-topbar {

        background: #ffffff;

        border-radius: 12px;

        border: 1px solid #e3e6ea;

        padding: 18px 20px;

        margin-bottom: 20px;

        display: flex;

        justify-content:
            space-between;

        align-items:
            center;

        gap: 15px;

        flex-wrap: wrap;
    }


    .offer-topbar h2 {

        margin: 0;

        font-size: 22px;

        font-weight: 700;
    }


    .offer-topbar p {

        margin: 4px 0 0;

        color: #6c757d;

        font-size: 14px;
    }


    .offer-actions {

        display: flex;

        gap: 8px;

        flex-wrap: wrap;
    }


    .offer-card {

        background: #ffffff;

        border-radius: 12px;

        border: 1px solid #e1e5ea;

        padding: 20px;

        margin-bottom: 18px;
    }


    .offer-section-title {

        font-size: 16px;

        font-weight: 700;

        margin-bottom: 15px;

        color: #111827;
    }


    .info-grid {

        display: grid;

        grid-template-columns:
            repeat(2, minmax(0, 1fr));

        gap: 12px;
    }


    .info-item {

        border: 1px solid #e5e7eb;

        border-radius: 8px;

        padding: 12px;

        background: #fafafa;
    }


    .info-label {

        color: #6b7280;

        font-size: 11px;

        text-transform: uppercase;

        font-weight: 700;

        margin-bottom: 4px;
    }


    .info-value {

        font-size: 14px;

        font-weight: 600;

        overflow-wrap: anywhere;

        word-break: break-word;
    }


    .asset-table-wrap {

        overflow-x: auto;
    }


    .asset-table {

        width: 100%;

        border-collapse:
            collapse;
    }


    .asset-table th {

        background: #111827;

        color: #ffffff;

        padding: 9px;

        font-size: 12px;

        text-align: left;
    }


    .asset-table td {

        border-bottom:
            1px solid #e5e7eb;

        padding: 9px;

        font-size: 12px;
    }


    .offer-error {

        background: #fee2e2;

        color: #991b1b;

        border: 1px solid #fecaca;

        border-radius: 8px;

        padding: 13px 15px;

        margin-bottom: 18px;
    }


    .offer-success {

        background: #dcfce7;

        color: #166534;

        border: 1px solid #bbf7d0;

        border-radius: 8px;

        padding: 13px 15px;

        margin-bottom: 18px;
    }


    /* =========================================================
   FSO PAPER
========================================================= */

    /*
     * FSO PAPER CONTAINER
     *
     * IMPORTANT:
     * The paper wrapper must NOT be one A4 page containing both
     * pages. Each .fso-page below is its own complete A4 sheet.
     */
    .fso-paper {

        width: 100%;

        margin: 0;

        padding: 0;

        box-sizing: border-box;

        color: #111111;

        font-family:
            Arial,
            Helvetica,
            sans-serif;
    }


    /*
     * EACH FSO PAGE = ONE COMPLETE A4 SHEET
     */
    .fso-page {

        position: relative;

        width: 210mm;

        height: 297mm;

        min-height: 297mm;

        margin: 0 auto 25px;

        padding: 10mm 12mm 14mm;

        box-sizing: border-box;

        background: #ffffff;

        box-shadow:
            0 3px 18px rgba(0, 0, 0, .15);

        overflow: hidden;

        break-after: page;

        page-break-after: always;
    }


    .fso-page:last-child {

        break-after: auto;

        page-break-after: auto;
    }


    .fso-page.page-break {

        page-break-before: auto;
    }


    .fso-header {

        border-bottom:
            2px solid #111111;

        padding-bottom: 5px;

        margin-bottom: 5px;
    }


    .fso-header-top {

        display: flex;

        align-items: center;

        gap: 12px;
    }


    .fso-logo {

        width: 62px;

        height: 62px;

        object-fit: contain;
    }


    .fso-agency {

        flex: 1;

        text-align: center;
    }


    .fso-agency-name {

        color: #e91e63;

        font-size: 21px;

        font-weight: 700;
    }


    .fso-tagline {

        font-size: 12px;

        font-weight: 700;

        margin-top: 2px;
    }


    .fso-approval {

        color: #c00000;

        font-size: 11px;

        font-weight: 700;

        margin-top: 2px;
    }


    .fso-subtitle {

        font-size: 10px;

        margin-top: 2px;
    }


    .fso-contact {

        font-size: 9px;

        text-align: center;

        margin-top: 4px;

        line-height: 1.35;
    }


    .fso-offer-number {

        font-size: 10px;

        font-weight: 700;

        margin-top: 4px;
    }


    .fso-title {

        text-align: center;

        font-size: 14px;

        font-weight: 700;

        text-decoration:
            underline;

        margin: 5px 0 5px;
    }


    .fso-meta {

        font-size: 11px;

        line-height: 1.5;
    }


    .fso-client-table,
    .fso-service-table {

        width: 100%;

        border-collapse:
            collapse;
    }


    .fso-client-table {

        margin-top: 4px;
    }


    .fso-client-table td {

        border:
            1px solid #222222;

        padding:
            3px 5px;

        font-size: 10px;
    }


    .fso-client-table td:first-child {

        width: 32%;

        font-weight: 700;
    }


    .fso-note {

        font-size: 10px;

        margin:
            10px 0;
    }


    .fso-heading,
    .fso-client-table,
    .fso-service-table,
    .fso-commercial,
    .fso-deliverables {

        break-inside: avoid;

        page-break-inside: avoid;
    }


    .fso-heading {

        font-size: 11px;

        font-weight: 700;

        text-decoration:
            underline;

        margin:
            8px 0 4px;
    }


    .fso-service-table th {

        border:
            1px solid #222222;

        background: #f5f5f5;

        padding: 4px;

        font-size: 9.5px;

        text-align: center;
    }


    .fso-service-table td {

        border:
            1px solid #222222;

        padding: 3px 4px;

        font-size: 9.5px;
    }


    .fso-service-table th:nth-child(1) {

        width: 40%;
    }


    .fso-service-table th:nth-child(2) {

        width: 27%;
    }


    .fso-service-table th:nth-child(3) {

        width: 8%;
    }


    .fso-service-table th:nth-child(4) {

        width: 12%;
    }


    .fso-service-table th:nth-child(5) {

        width: 13%;
    }


    .fso-service-table td:first-child {

        text-align: left;
    }


    .fso-service-table td:nth-child(2) {

        text-align: left;

        word-break: break-word;
    }


    .fso-service-table td:nth-child(n+3) {

        text-align: center;
    }


    .fso-total {

        margin-top: 5px;

        font-size: 9.5px;

        line-height: 1.7;
    }


    .fso-commercial {

        font-size: 10px;

        line-height: 1.5;
    }


    .fso-commercial ul {

        margin:
            3px 0 5px 18px;

        padding: 0;
    }


    .fso-commercial li {

        margin-bottom: 2px;
    }


    .fso-deliverables {

        font-size: 10px;

        line-height: 1.5;
    }


    .fso-deliverables ul {

        margin:
            3px 0 0 18px;

        padding: 0;
    }


    .fso-deliverables li {

        margin-bottom: 1px;
    }


    .fso-exclusions {

        font-size: 10px;

        line-height: 1.45;
    }


    .fso-exclusions ul {

        margin:
            3px 0 4px 18px;

        padding: 0;
    }


    .fso-remarks-lines {

        margin-top: 4px;
    }


    .fso-remarks-line {

        border-bottom:
            1px solid #777;

        height: 17px;
    }


    .fso-acceptance {

        font-size: 10px;

        margin-top: 5px;

        font-weight: 700;

        text-transform:
            uppercase;
    }


    .fso-signatures {

        display: grid;

        grid-template-columns:
            1fr 1fr;

        gap: 30px;

        margin-top: 7px;
    }


    .fso-signature-box {

        font-size: 10px;

        line-height: 1.8;
    }


    .fso-bank {

        margin-top: 8px;

        font-size: 10px;
    }


    .fso-bank-table {

        border-collapse:
            collapse;
    }


    .fso-bank-table td {

        padding:
            1px 8px 1px 0;

        vertical-align: top;
    }


    .fso-footer {

        position: absolute;

        bottom: 8mm;

        left: 0;

        right: 0;

        border-top:
            2px solid #111111;

        padding-top: 3px;

        font-size: 10px;

        text-align: center;
    }


    .fso-footer-web {

        margin-bottom: 2px;
    }


    .fso-footer-address {

        background: #111111;

        color: #ffffff;

        padding: 3px;

        font-weight: 700;

        font-size: 10px;
    }


    .travel-setting-card {
        margin-top: 18px;
        padding: 18px;
        border: 1px solid #dbe3ea;
        border-radius: 12px;
        background: #ffffff;
    }

    .travel-setting-title {
        font-size: 15px;
        font-weight: 700;
        margin-bottom: 5px;
    }

    .travel-setting-help {
        font-size: 12px;
        color: #6c757d;
        margin-bottom: 12px;
        line-height: 1.5;
    }

    .travel-setting-table th {
        background: #f3f6f9;
        font-size: 12px;
        white-space: nowrap;
    }

    .travel-setting-table td {
        font-size: 12px;
    }

    /* =========================================================
   MOBILE
========================================================= */

    @media (max-width: 900px) {

        .offer-generator-page {

            width: 100%;

            margin-left: 0;

            margin-top: 0;

            padding: 12px;
        }


        .fso-paper {

            width: 100%;

            min-height: auto;

            padding: 0;

            overflow-x: auto;
        }


        .fso-page {

            width: 210mm;

            height: 297mm;

            min-height: 297mm;

            margin: 0 auto 20px;
        }


        .info-grid {

            grid-template-columns:
                1fr;
        }
    }


    /* =========================================================
   PRINT
========================================================= */

    @media print {

        @page {

            size: A4;

            margin: 0;
        }


        html,
        body {

            margin: 0 !important;

            padding: 0 !important;

            background: #ffffff !important;
        }


        body * {

            visibility: hidden;
        }


        .fso-paper,
        .fso-paper * {

            visibility: visible;
        }


        .fso-paper {

            position: static;

            width: 100%;

            min-height: 0;

            margin: 0;

            padding: 0;

            box-shadow: none;
        }


        .fso-page {

            position: relative;

            width: 210mm;

            height: 297mm;

            min-height: 297mm;

            margin: 0 !important;

            padding: 9mm 12mm 13mm;

            box-sizing: border-box;

            box-shadow: none;

            overflow: hidden;

            break-after: page;

            page-break-after: always;
        }


        .fso-page:last-child {

            break-after: auto;

            page-break-after: auto;
        }


        .fso-page.page-break {

            page-break-before: always;
        }


        .screen-only {

            display: none !important;
        }
    }
</style>


<div class="offer-generator-page">


    <!-- =================================================
         SCREEN TOP BAR
    ================================================== -->

    <div class="offer-topbar screen-only">

        <div>

            <h2>
                Offer Generator
            </h2>

            <p>
                NLETA Financial & Service Offer
            </p>

        </div>


        <div class="offer-actions">

            <a
                href="service-requests.php?id=<?= (int)$requestId ?>"
                class="btn btn-outline-secondary">

                <i
                    class="bi bi-arrow-left"></i>

                Back

            </a>


            <?php if ($existingOffer): ?>

                <?php

                $offerStatus =
                    trim(
                        (string)(
                            $existingOffer['status']
                            ??
                            ''
                        )
                    );

                ?>


                <?php if (
                    in_array(
                        $offerStatus,
                        [
                            'Generated',
                            'Offer Generated'
                        ],
                        true
                    )
                ): ?>

                    <form
                        method="POST"
                        style="display:inline;"
                        onsubmit="
                            return confirm(
                                'Send this Offer to the Owner?'
                            );
                        ">

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= h(
                                        $csrfToken
                                    ) ?>">


                        <input
                            type="hidden"
                            name="action"
                            value="send_offer_to_owner">


                        <input
                            type="hidden"
                            name="offer_id"
                            value="<?= (int)$existingOffer['id'] ?>">


                        <button
                            type="submit"
                            class="btn btn-success">

                            <i
                                class="bi bi-send-fill me-1"></i>

                            Send to Owner

                        </button>

                    </form>


                <?php elseif (
                    $offerStatus === 'Sent'
                ): ?>

                    <span
                        class="btn btn-success disabled">

                        <i
                            class="bi bi-check-circle-fill me-1"></i>

                        Sent to Owner

                    </span>

                <?php endif; ?>


                <?php if (
                    in_array(
                        $offerStatus,
                        [
                            'Generated',
                            'Offer Generated'
                        ],
                        true
                    )
                ): ?>

                    <form
                        method="POST"
                        style="display:inline;"
                        onsubmit="
                            return confirm(
                                'Regenerate this Offer using the latest asset and pricing data? The same FSO number will be preserved.'
                            );
                        ">

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= h($csrfToken) ?>">

                        <input
                            type="hidden"
                            name="action"
                            value="regenerate_offer">

                        <button
                            type="submit"
                            class="btn btn-warning">

                            <i
                                class="bi bi-arrow-clockwise me-1"></i>

                            Regenerate Offer

                        </button>

                    </form>

                <?php endif; ?>


                <button
                    type="button"
                    class="btn btn-primary"
                    onclick="window.print();">

                    <i
                        class="bi bi-printer me-1"></i>

                    Print / Save PDF

                </button>

            <?php endif; ?>

        </div>

    </div>


    <!-- =================================================
         ERRORS
    ================================================== -->

    <?php if ($successMessage !== ''): ?>

        <div class="offer-success screen-only">
            <i class="bi bi-check-circle-fill me-2"></i>
            <?= h($successMessage) ?>
        </div>

    <?php endif; ?>


    <?php if (
        $generationError !== ''
    ): ?>

        <div
            class="offer-error screen-only">

            <i
                class="bi bi-exclamation-triangle-fill me-2"></i>

            <?= h(
                $generationError
            ) ?>

        </div>

    <?php endif; ?>


    <?php if (
        $generatedNow
    ): ?>

        <div
            class="offer-success screen-only">

            <strong>
                Offer generated successfully.
            </strong>

            FSO:

            <strong>
                <?= h(
                    $existingOffer['offer_no']
                ) ?>
            </strong>

            &nbsp; | &nbsp;

            PSGA:

            <strong>
                <?= h(
                    $existingOffer['psga_no']
                ) ?>
            </strong>

        </div>

    <?php endif; ?>


    <?php if (
        $sendOfferSuccess !== ''
    ): ?>

        <div
            class="offer-success screen-only">

            <i
                class="bi bi-send-check-fill me-2"></i>

            <?= h(
                $sendOfferSuccess
            ) ?>

        </div>

    <?php endif; ?>


    <?php if ($existingOffer && in_array(trim((string)($existingOffer['status'] ?? '')), ['Generated', 'Offer Generated'], true)): ?>

        <div class="offer-card screen-only">
            <div class="offer-section-title">
                <i class="bi bi-airplane me-2"></i>
                Travel, Lodging &amp; Local Conveyance — Asset-wise Applicability
            </div>

            <div class="small text-muted mb-3">
                Update applicability before sending this FSO to the Owner.
            </div>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <input type="hidden" name="action" value="save_travel_settings">

                <div class="table-responsive">
                    <table class="table table-bordered align-middle travel-setting-table mb-3">
                        <thead>
                            <tr>
                                <th>Asset</th>
                                <th>Location</th>
                                <th style="width:230px;">Applicability</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($requestAssets as $travelAsset): ?>
                                <?php $travelAssetId = (int)($travelAsset['id'] ?? 0); ?>
                                <tr>
                                    <td>
                                        <strong><?= h($travelAsset['display_asset_id'] ?? '') ?></strong>
                                        <div class="small text-muted"><?= h($travelAsset['asset_name'] ?? '') ?></div>
                                    </td>
                                    <td><?= h($travelAsset['location'] ?? '—') ?></td>
                                    <td>
                                        <select name="travel_lodging_applicable[<?= $travelAssetId ?>]" class="form-select" required>
                                            <option value="0" <?= (($existingTravelSettings[$travelAssetId] ?? 0) === 0) ? 'selected' : '' ?>>Not Applicable</option>
                                            <option value="1" <?= (($existingTravelSettings[$travelAssetId] ?? 0) === 1) ? 'selected' : '' ?>>Applicable</option>
                                        </select>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">
                            Travel / Lodging / Local Conveyance Management
                        </label>

                        <div class="d-flex gap-4 flex-wrap mt-2">
                            <label class="form-check">
                                <input
                                    class="form-check-input travel-managed-by"
                                    type="radio"
                                    name="travel_lodging_managed_by"
                                    value="owner"
                                    <?= $existingTravelLodgingManagedBy === 'owner' ? 'checked' : '' ?>>
                                <span class="form-check-label">Managed by Owner</span>
                            </label>

                            <label class="form-check">
                                <input
                                    class="form-check-input travel-managed-by"
                                    type="radio"
                                    name="travel_lodging_managed_by"
                                    value="nleta"
                                    <?= $existingTravelLodgingManagedBy === 'nleta' ? 'checked' : '' ?>>
                                <span class="form-check-label">Amount Managed by NLETA</span>
                            </label>
                        </div>

                        <div class="travel-amount-wrap mt-3"
                             style="<?= $existingTravelLodgingManagedBy === 'nleta' ? '' : 'display:none;' ?>">
                            <label class="form-label fw-bold">
                                Agreed Travel / Lodging / Local Conveyance Amount (₹)
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input
                                    type="number"
                                    name="travel_lodging_amount"
                                    class="form-control travel-lodging-amount"
                                    min="0"
                                    step="0.01"
                                    value="<?= h(number_format($existingTravelLodgingAmount, 2, '.', '')) ?>"
                                    placeholder="Enter agreed amount"
                                    <?= $existingTravelLodgingManagedBy === 'nleta' ? '' : 'disabled' ?>>
                            </div>
                            <div class="form-text">
                                Mutually agreed amount as discussed over phone. Payable separately and not included in service pricing, GST or Grand Total.
                            </div>
                        </div>

                        <div class="form-text mt-2 travel-owner-note"
                             style="<?= $existingTravelLodgingManagedBy === 'owner' ? '' : 'display:none;' ?>">
                            The FSO will state: “The mutually agreed amount, as discussed over phone, shall be managed by the Owner.”
                        </div>
                    </div>
                </div>

                <script>
                (function () {
                    const form = document.currentScript.closest('form');
                    if (!form) return;

                    form.querySelectorAll('.travel-managed-by').forEach(function (radio) {
                        radio.addEventListener('change', function () {
                            const amountWrap = form.querySelector('.travel-amount-wrap');
                            const amountInput = form.querySelector('.travel-lodging-amount');
                            const ownerNote = form.querySelector('.travel-owner-note');

                            if (this.value === 'nleta') {
                                if (amountWrap) amountWrap.style.display = '';
                                if (amountInput) amountInput.disabled = false;
                                if (ownerNote) ownerNote.style.display = 'none';
                            } else {
                                if (amountWrap) amountWrap.style.display = 'none';
                                if (amountInput) {
                                    amountInput.disabled = true;
                                    amountInput.value = '0.00';
                                }
                                if (ownerNote) ownerNote.style.display = '';
                            }
                        });
                    });
                })();
                </script>

                <button type="submit" class="btn btn-outline-primary">
                    <i class="bi bi-save me-1"></i> Save Applicability
                </button>
            </form>
        </div>

    <?php endif; ?>


    <!-- =================================================
         GENERATE OFFER CARD
    ================================================== -->

    <?php if (
        !$existingOffer
        &&
        $generationError === ''
    ): ?>

        <div
            class="offer-card screen-only">

            <div
                class="offer-section-title">

                Ready to Generate <?= h($previewFsoNo) ?>

            </div>


            <div class="info-grid">


                <div class="info-item">

                    <div class="info-label">
                        Client
                    </div>

                    <div class="info-value">

                        <?= h(
                            $client['Organization']
                                ??
                                ''
                        ) ?>

                    </div>

                </div>


                <div class="info-item">

                    <div class="info-label">
                        Association / Organization Name
                    </div>

                    <div class="info-value">

                        <?= h(
                            $organizationName !== ''
                            ? $organizationName
                            : ($client['Organization'] ?? '')
                        ) ?>

                    </div>

                </div>


                <div class="info-item">

                    <div class="info-label">
                        Association / Organization Address
                    </div>

                    <div class="info-value">

                        <?= nl2br(
                            h(
                                $organizationAddress !== ''
                                ? $organizationAddress
                                : ($client['Billing_Address'] ?? '')
                            )
                        ) ?>

                    </div>

                </div>


                <div class="info-item">

                    <div class="info-label">
                        Client Short Code
                    </div>

                    <div class="info-value">

                        <?= h(
                            $clientShortCode
                        ) ?>

                    </div>

                </div>


                <div class="info-item">

                    <div class="info-label">
                        Financial Year
                    </div>

                    <div class="info-value">

                        FSO-<?= h(
                                $financialYear
                            ) ?>

                    </div>

                </div>


                <div class="info-item">

                    <div class="info-label">
                        Selected Services
                    </div>

                    <div class="info-value">

                        <?= serviceNamesHtml($requestServices) ?>

                    </div>

                </div>

                <div class="info-item">

                    <div class="info-label">
                        Contract Identity
                    </div>

                    <div class="info-value">

                        <?php if (
                            $permanentOwnerFSO !== ''
                            &&
                            $permanentOwnerPSGANo !== ''
                        ): ?>

                            Existing Permanent FSO / PSGA will be reused

                        <?php else: ?>

                            New Permanent FSO / PSGA will be created

                        <?php endif; ?>

                    </div>

                </div>


            </div>


            <form
                method="POST"
                onsubmit="
                    const b=this.querySelector('button[type=submit]');
                    if(b){
                        b.disabled=true;
                        b.innerHTML='Generating...';
                    }
                ">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= h(
                                $csrfToken
                            ) ?>">


                <input
                    type="hidden"
                    name="action"
                    value="generate_offer">


                <div class="travel-setting-card">

                    <div class="travel-setting-title">
                        <i class="bi bi-airplane me-2"></i>
                        Travel, Lodging &amp; Local Conveyance — Asset-wise Applicability
                    </div>

                    <div class="travel-setting-help">
                        Select <strong>Applicable</strong> only for the assets / service locations
                        where Travel, Lodging &amp; Local Conveyance will be payable.
                    </div>

                    <div class="table-responsive">
                        <table class="table table-bordered align-middle travel-setting-table mb-0">
                            <thead>
                                <tr>
                                    <th>Asset</th>
                                    <th>Location</th>
                                    <th style="width:230px;">Applicability</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($requestAssets as $travelAsset): ?>
                                    <?php $travelAssetId = (int)($travelAsset['id'] ?? 0); ?>
                                    <tr>
                                        <td>
                                            <strong><?= h($travelAsset['display_asset_id'] ?? '') ?></strong>
                                            <div class="small text-muted"><?= h($travelAsset['asset_name'] ?? '') ?></div>
                                        </td>
                                        <td><?= h($travelAsset['location'] ?? '—') ?></td>
                                        <td>
                                            <select
                                                name="travel_lodging_applicable[<?= $travelAssetId ?>]"
                                                class="form-select"
                                                required>
                                                <option value="0" <?= (($existingTravelSettings[$travelAssetId] ?? 0) === 0) ? 'selected' : '' ?>>Not Applicable</option>
                                                <option value="1" <?= (($existingTravelSettings[$travelAssetId] ?? 0) === 1) ? 'selected' : '' ?>>Applicable</option>
                                            </select>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="row g-3 mt-2>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">
                            Travel / Lodging / Local Conveyance Management
                        </label>

                        <div class="d-flex gap-4 flex-wrap mt-2">
                            <label class="form-check">
                                <input
                                    class="form-check-input travel-managed-by"
                                    type="radio"
                                    name="travel_lodging_managed_by"
                                    value="owner"
                                    <?= $existingTravelLodgingManagedBy === 'owner' ? 'checked' : '' ?>>
                                <span class="form-check-label">Managed by Owner</span>
                            </label>

                            <label class="form-check">
                                <input
                                    class="form-check-input travel-managed-by"
                                    type="radio"
                                    name="travel_lodging_managed_by"
                                    value="nleta"
                                    <?= $existingTravelLodgingManagedBy === 'nleta' ? 'checked' : '' ?>>
                                <span class="form-check-label">Amount Managed by NLETA</span>
                            </label>
                        </div>

                        <div class="travel-amount-wrap mt-3"
                             style="<?= $existingTravelLodgingManagedBy === 'nleta' ? '' : 'display:none;' ?>">
                            <label class="form-label fw-bold">
                                Agreed Travel / Lodging / Local Conveyance Amount (₹)
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input
                                    type="number"
                                    name="travel_lodging_amount"
                                    class="form-control travel-lodging-amount"
                                    min="0"
                                    step="0.01"
                                    value="<?= h(number_format($existingTravelLodgingAmount, 2, '.', '')) ?>"
                                    placeholder="Enter agreed amount"
                                    <?= $existingTravelLodgingManagedBy === 'nleta' ? '' : 'disabled' ?>>
                            </div>
                            <div class="form-text">
                                Mutually agreed amount as discussed over phone. Payable separately and not included in service pricing, GST or Grand Total.
                            </div>
                        </div>

                        <div class="form-text mt-2 travel-owner-note"
                             style="<?= $existingTravelLodgingManagedBy === 'owner' ? '' : 'display:none;' ?>">
                            The FSO will state: “The mutually agreed amount, as discussed over phone, shall be managed by the Owner.”
                        </div>
                    </div>
                </div>

                <script>
                (function () {
                    const form = document.currentScript.closest('form');
                    if (!form) return;

                    form.querySelectorAll('.travel-managed-by').forEach(function (radio) {
                        radio.addEventListener('change', function () {
                            const amountWrap = form.querySelector('.travel-amount-wrap');
                            const amountInput = form.querySelector('.travel-lodging-amount');
                            const ownerNote = form.querySelector('.travel-owner-note');

                            if (this.value === 'nleta') {
                                if (amountWrap) amountWrap.style.display = '';
                                if (amountInput) amountInput.disabled = false;
                                if (ownerNote) ownerNote.style.display = 'none';
                            } else {
                                if (amountWrap) amountWrap.style.display = 'none';
                                if (amountInput) {
                                    amountInput.disabled = true;
                                    amountInput.value = '0.00';
                                }
                                if (ownerNote) ownerNote.style.display = '';
                            }
                        });
                    });
                })();
                </script>

                </div>


                <button
                    type="submit"
                    class="btn btn-success">

                    <i
                        class="bi bi-file-earmark-check me-1"></i>

                    Generate <?= h($previewFsoNo) ?>

                </button>

            </form>

        </div>

    <?php endif; ?>


    <!-- =================================================
         FSO PAPER — SHARED RENDERER
    ================================================== -->

    <?php
        /*
         * FSO DISPLAY CLEANUP
         * ---------------------------------------------------------
         * Commercial Reference section has been removed from the
         * customer-facing FSO.
         *
         * Linked Documents line has also been removed.
         *
         * The word "Annexure" is not displayed anywhere in the
         * generated FSO. Existing database/pricing logic remains
         * unchanged; this is presentation-only cleanup.
         */
        require_once('saf-templates/fso-generator.php');

        if ($existingOffer) {

            ob_start();

            renderNletaFso(
                $existingOffer,
                is_array($client ?? null) ? $client : [],
                is_array($existingItems ?? null) ? $existingItems : [],
                is_array($requestAssets ?? null) ? $requestAssets : [],
                $conn
            );

            $fsoRenderedHtml = ob_get_clean();

            /*
             * Remove the Linked Documents block completely.
             */
            $fsoRenderedHtml = preg_replace(
                '/<div\\s+class=["\']fso-linked-docs["\'][^>]*>.*?<\\/div>/is',
                '',
                $fsoRenderedHtml
            );

            /*
             * Remove Point 5 — Commercial Reference completely.
             * Point 6 remains untouched.
             */
            $fsoRenderedHtml = preg_replace(
                '/<div\\s+class=["\']fso-heading["\']>\\s*5\\.\\s*COMMERCIAL REFERENCE\\s*<\\/div>.*?(?=<!--\\s*POINT 6|<div\\s+class=["\']fso-heading\\s+fso-point6-heading["\'])/is',
                '',
                $fsoRenderedHtml
            );

            /*
             * CUSTOMER-FACING FSO CLEANUP
             * -----------------------------------------------------
             * Do not display any reference labels / short forms
             * such as:
             *   Annexure
             *   T / T-01 / T-02 / T-03
             *   A / A-01 / A-02
             *   (Rev.1)
             *
             * This changes presentation only. Database, pricing,
             * PSGA, FSO and workflow logic remain unchanged.
             */

            // Remove Linked Documents / reference labels if present.
            $fsoRenderedHtml = preg_replace(
                '/<div\\s+class=["\']fso-linked-docs["\'][^>]*>.*?<\\/div>/is',
                '',
                $fsoRenderedHtml
            );

            // Remove revision labels such as (Rev.1), Rev.1, Revision 1.
            $fsoRenderedHtml = preg_replace(
                '/\\s*\\(?\\s*Rev(?:ision)?\\.?\\s*\\d+(?:\\.\\d+)?\\s*\\)?/i',
                '',
                $fsoRenderedHtml
            );

            // Remove Annexure / reference short-form text from visible HTML.
            $fsoRenderedHtml = preg_replace(
                '/\\bAnnexure\\s+[A-Z](?:[-\\s]?\\d+)?\\b/iu',
                '',
                $fsoRenderedHtml
            );

            // Remove standalone A / T reference labels when they appear
            // as document-reference labels (not normal words).
            $fsoRenderedHtml = preg_replace(
                '/(?<![A-Za-z0-9])(?:T|A)(?:[-\\s]?\\d+)?(?=\\s*(?:\\||,|<|$))/i',
                '',
                $fsoRenderedHtml
            );

            // Remove any now-empty separators left by the reference cleanup.
            $fsoRenderedHtml = preg_replace(
                '/\\s*\\|\\s*(?=<|$)/',
                '',
                $fsoRenderedHtml
            );

            echo $fsoRenderedHtml;
        }
    ?>



</div>


<?php include('../includes/footer.php'); ?>