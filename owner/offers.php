<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
/* =========================================================
   OWNER OFFERS
   ========================================================= */

include('../config/db.php');
include('../config/auth.php');
require_once __DIR__ . '/../config/security.php';

require_role('owner');

require_once __DIR__ . '/../config/owner_access.php';

requireOwnerAccess('offers');

$ownerId = (int)($_SESSION['uid'] ?? 0);

$timezone = new DateTimeZone('Asia/Kolkata');

date_default_timezone_set('Asia/Kolkata');

$currentOfferId =
    (int)($_GET['id'] ?? 0);

$errorMessage = '';

$offers = [];

$selectedOffer = null;

$selectedItems = [];

$selectedAssets = [];


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
   OWNER OFFER STATUS HELPER
========================================================= */

function getOwnerOfferStatus(
    $offerStatus,
    $paymentStatus = '',
    $paymentReceived = 0,
    $paymentBalance = null,
    $validityDate = null
) {
    $offerStatus = trim((string)$offerStatus);
    $paymentStatus = trim((string)$paymentStatus);
    $paymentStatusLower = strtolower($paymentStatus);

    $received = (float)$paymentReceived;
    $balance = ($paymentBalance === null || $paymentBalance === '')
        ? null
        : (float)$paymentBalance;

    if ($offerStatus === 'Rejected') {
        return ['label' => 'Offer Rejected', 'class' => 'bg-danger'];
    }

    $completedByStatus = in_array($paymentStatusLower, [
        'paid',
        'completed',
        'payment completed',
        'fully paid',
        'payment received'
    ], true);

    $completedByAmount =
        $balance !== null && $balance <= 0.009;

    if ($offerStatus === 'Accepted' && ($completedByStatus || $completedByAmount)) {
        return ['label' => 'Payment Completed', 'class' => 'bg-success'];
    }

    if ($offerStatus === 'Accepted' && in_array($paymentStatusLower, [
        'submitted',
        'verification pending',
        'pending verification',
        'under verification',
        'submitted / pending'
    ], true)) {
        return [
            'label' => 'Payment Verification Pending',
            'class' => 'bg-info text-dark'
        ];
    }

    $partialByStatus = in_array($paymentStatusLower, [
        'partial',
        'partially paid',
        'payment partial',
        'partially received'
    ], true);

    $partialByAmount =
        $received > 0 &&
        $balance !== null &&
        $balance > 0.009;

    if ($offerStatus === 'Accepted' && ($partialByStatus || $partialByAmount)) {
        return [
            'label' => 'Payment Partially Paid',
            'class' => 'bg-primary'
        ];
    }

    if ($offerStatus === 'Accepted') {
        return ['label' => 'Payment Pending', 'class' => 'bg-warning text-dark'];
    }

    if ($offerStatus === 'Sent' || strtolower($offerStatus) === 'sent to owner') {

        if (!empty($validityDate)) {
            try {
                $today = new DateTime('today', new DateTimeZone('Asia/Kolkata'));
                $validUntil = new DateTime($validityDate, new DateTimeZone('Asia/Kolkata'));

                if ($validUntil < $today) {
                    return ['label' => 'Review Offer', 'class' => 'bg-secondary'];
                }
            } catch (Exception $e) {
                // Keep Offer Received if validity date cannot be parsed.
            }
        }

        return ['label' => 'Approval Pending', 'class' => 'bg-warning text-dark'];
    }

    if (strtolower($offerStatus) === 'expired') {
        return ['label' => 'Offer Expired', 'class' => 'bg-secondary'];
    }

    if (strtolower($offerStatus) === 'cancelled') {
        return ['label' => 'Offer Cancelled', 'class' => 'bg-dark'];
    }

    return [
        'label' => $offerStatus !== '' ? $offerStatus : 'Status Pending',
        'class' => 'bg-secondary'
    ];
}

/* =========================================================
   PSGA OWNER APPROVAL — HANDLED INSIDE OFFERS
   ---------------------------------------------------------
   PSGA is NOT a Checklist Approval.
   When Admin sends a PSGA, it appears against the Owner's
   Offer here and follows:
       Pending Owner Confirmation
            -> Accept -> Pending Admin Confirmation
            -> Reject -> Pending Admin Review
========================================================= */

function ownerOfferColumnExists($conn, $table, $column): bool
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return ((int)($row['total'] ?? 0) > 0);
}

/* ---------------------------------------------------------
   PSGA ACCEPT
--------------------------------------------------------- */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['accept_psga'])
) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request. Please refresh the page.';
        header('Location: offers.php');
        exit;
    }

    $psgaId = (int)($_POST['psga_id'] ?? 0);

    if ($psgaId <= 0) {
        $_SESSION['error'] = 'Invalid PSGA selected.';
        header('Location: offers.php');
        exit;
    }

    $stmt = $conn->prepare("
        UPDATE contracts
        SET Status = 'Pending Admin Confirmation'
        WHERE PSGA_ID = ?
          AND Owner_ID = ?
          AND Status = 'Pending Owner Confirmation'
        LIMIT 1
    ");

    if (!$stmt) {
        $_SESSION['error'] =
            'Unable to prepare PSGA acceptance: ' . $conn->error;
    } else {
        $stmt->bind_param('ii', $psgaId, $ownerId);

        if ($stmt->execute() && $stmt->affected_rows === 1) {
            $_SESSION['success'] =
                'PSGA Agreement accepted successfully. It is now pending Admin confirmation.';
        } else {
            $_SESSION['error'] =
                'PSGA could not be accepted. It may already have been processed.';
        }

        $stmt->close();
    }

    header('Location: offers.php');
    exit;
}

/* ---------------------------------------------------------
   PSGA REJECT
--------------------------------------------------------- */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['reject_psga'])
) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request. Please refresh the page.';
        header('Location: offers.php');
        exit;
    }

    $psgaId = (int)($_POST['psga_id'] ?? 0);
    $rejectReason = trim((string)($_POST['psga_reject_reason'] ?? ''));

    if ($psgaId <= 0) {
        $_SESSION['error'] = 'Invalid PSGA selected.';
        header('Location: offers.php');
        exit;
    }

    if ($rejectReason === '') {
        $_SESSION['error'] =
            'Please enter a reason for rejecting the PSGA Agreement.';
        header('Location: offers.php');
        exit;
    }

    /*
     * Preserve the existing rejection-reason behavior where the
     * database already has a supported column. No schema change
     * is performed here.
     */
    $reasonColumn = null;

    $reasonCheck = $conn->query("
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'contracts'
          AND COLUMN_NAME IN (
              'Owner_Rejection_Reason',
              'Rejection_Reason',
              'Reject_Reason'
          )
        ORDER BY FIELD(
            COLUMN_NAME,
            'Owner_Rejection_Reason',
            'Rejection_Reason',
            'Reject_Reason'
        )
        LIMIT 1
    ");

    if ($reasonCheck) {
        $reasonRow = $reasonCheck->fetch_assoc();
        if ($reasonRow) {
            $reasonColumn = $reasonRow['COLUMN_NAME'];
        }
        $reasonCheck->free();
    }

    if ($reasonColumn !== null) {
        $sql = "
            UPDATE contracts
            SET
                Status = 'Pending Admin Review',
                `$reasonColumn` = ?
            WHERE PSGA_ID = ?
              AND Owner_ID = ?
              AND Status = 'Pending Owner Confirmation'
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            $_SESSION['error'] =
                'Unable to prepare PSGA rejection: ' . $conn->error;
        } else {
            $stmt->bind_param(
                'sii',
                $rejectReason,
                $psgaId,
                $ownerId
            );

            if ($stmt->execute() && $stmt->affected_rows === 1) {
                $_SESSION['success'] =
                    'PSGA Agreement rejected and sent back to Admin for review.';
            } else {
                $_SESSION['error'] =
                    'PSGA could not be rejected. It may already have been processed.';
            }

            $stmt->close();
        }
    } else {
        $stmt = $conn->prepare("
            UPDATE contracts
            SET Status = 'Pending Admin Review'
            WHERE PSGA_ID = ?
              AND Owner_ID = ?
              AND Status = 'Pending Owner Confirmation'
            LIMIT 1
        ");

        if (!$stmt) {
            $_SESSION['error'] =
                'Unable to prepare PSGA rejection: ' . $conn->error;
        } else {
            $stmt->bind_param('ii', $psgaId, $ownerId);

            if ($stmt->execute() && $stmt->affected_rows === 1) {
                $_SESSION['success'] =
                    'PSGA Agreement rejected and sent back to Admin for review.';
            } else {
                $_SESSION['error'] =
                    'PSGA could not be rejected. It may already have been processed.';
            }

            $stmt->close();
        }
    }

    header('Location: offers.php');
    exit;
}

/* =========================================================
   LOAD OWNER OFFERS
   ---------------------------------------------------------
   Owner mapping:
   owner
      -> assets.owner_id
      -> asset_master.asset_id
      -> asset_master.Client_ID
      -> nleta_offer_master.client_id
========================================================= */

$offerStmt = $conn->prepare("
    SELECT

        o.id,
        o.request_id,
        o.client_id,
        o.psga_no,
        o.offer_no,
        o.financial_year,
        o.client_short_code,
        o.offer_date,
        o.validity_date,
        o.subtotal,
        o.gst_rate,
        o.gst_amount,
        o.grand_total,
        o.payment_advance,
        o.payment_service_activation,
        o.payment_final,
        o.status,
        o.created_at,
        o.updated_at,

        COUNT(DISTINCT oa.asset_id) AS asset_count,

        COALESCE(
            p.Total_Amount,
            o.grand_total
        ) AS payment_total,

        COALESCE(
            p.Received_Amount,
            0
        ) AS payment_received,

        COALESCE(
            p.Balance_Amount,
            o.grand_total
        ) AS payment_balance,

        p.Payment_Status AS payment_status,

        c.PSGA_ID AS psga_id,
        c.Status AS psga_status,
        c.Contract_Date AS psga_contract_date

    FROM nleta_offer_master o

    /*
     * IMPORTANT:
     * Owner ownership is determined from the Service Request.
     *
     * service_requests.owner_id
     *          ↓
     * service_requests.id = nleta_offer_master.request_id
     *
     * Do NOT use o.owner_id because that column does not exist.
     */
    INNER JOIN service_requests sr
        ON sr.id = o.request_id
       AND sr.owner_id = ?

    /*
     * Offer must still have at least one live asset
     * belonging to this Owner.
     */
    INNER JOIN nleta_offer_assets oa
        ON oa.offer_id = o.id

    INNER JOIN assets a
        ON a.id = oa.asset_id
       AND a.owner_id = ?

    LEFT JOIN payments p
        ON p.Offer_ID = o.id

    LEFT JOIN contracts c
        ON c.PSGA_No = o.psga_no
       AND c.Owner_ID = ?

    WHERE o.status IN (
        'Sent',
        'Accepted',
        'Rejected'
    )

    GROUP BY
        o.id,
        o.request_id,
        o.client_id,
        o.psga_no,
        o.offer_no,
        o.financial_year,
        o.client_short_code,
        o.offer_date,
        o.validity_date,
        o.subtotal,
        o.gst_rate,
        o.gst_amount,
        o.grand_total,
        o.payment_advance,
        o.payment_service_activation,
        o.payment_final,
        o.status,
        o.created_at,
        o.updated_at,
        p.Total_Amount,
        p.Received_Amount,
        p.Balance_Amount,
        p.Payment_Status,
        c.PSGA_ID,
        c.Status,
        c.Contract_Date

    ORDER BY
        o.id DESC
");

if (!$offerStmt) {

    $errorMessage =
        'Unable to load Offers: '
        .
        $conn->error;

} else {

    $offerStmt->bind_param(
        "iii",
        $ownerId,
        $ownerId,
        $ownerId
    );

    $offerStmt->execute();

    $offerResult =
        $offerStmt->get_result();

    while (
        $row =
        $offerResult->fetch_assoc()
    ) {

        $offers[] = $row;
    }

    $offerStmt->close();
}


/* =========================================================
   LOAD REJECTED SERVICE REQUESTS
   ---------------------------------------------------------
   Admin rejection happens at SERVICE REQUEST level, before
   an Offer may exist in nleta_offer_master.

   Therefore rejected requests must be loaded separately so
   the Owner can see them in the Offers section.
========================================================= */

$existingOfferRequestIds = [];

foreach ($offers as $existingOffer) {
    $existingOfferRequestIds[(int)($existingOffer['request_id'] ?? 0)] = true;
}

$rejectedStmt = $conn->prepare("
    SELECT
        sr.id,
        sr.request_no,
        sr.service_id,
        sr.submitted_at,
        sr.status,

        COUNT(DISTINCT sra.asset_id) AS asset_count

    FROM service_requests sr

    LEFT JOIN service_request_assets sra
        ON sra.request_id = sr.id

    WHERE sr.owner_id = ?
      AND sr.status = 'Rejected'

    GROUP BY
        sr.id,
        sr.request_no,
        sr.service_id,
        sr.submitted_at,
        sr.status

    ORDER BY sr.id DESC
");

if ($rejectedStmt) {

    $rejectedStmt->bind_param("i", $ownerId);
    $rejectedStmt->execute();

    $rejectedResult = $rejectedStmt->get_result();

    while ($rejectedRequest = $rejectedResult->fetch_assoc()) {

        $rejectedRequestId = (int)$rejectedRequest['id'];

        /*
         * If an actual Offer already exists for this request,
         * do not create a duplicate row.
         */
        if (isset($existingOfferRequestIds[$rejectedRequestId])) {
            continue;
        }

        $offers[] = [
            'id'                       => 0,
            'request_id'               => $rejectedRequestId,
            'client_id'                => null,
            'psga_no'                  => '',
            'offer_no'                 => '',
            'financial_year'           => '',
            'client_short_code'        => '',
            'offer_date'               => $rejectedRequest['submitted_at'],
            'validity_date'            => null,
            'subtotal'                 => 0,
            'gst_rate'                 => 0,
            'gst_amount'               => 0,
            'grand_total'              => 0,
            'payment_advance'          => 0,
            'payment_service_activation' => 0,
            'payment_final'             => 0,
            'status'                   => 'Rejected',
            'created_at'               => $rejectedRequest['submitted_at'],
            'updated_at'               => $rejectedRequest['submitted_at'],
            'asset_count'              => (int)$rejectedRequest['asset_count'],
            'payment_total'            => 0,
            'payment_received'         => 0,
            'payment_balance'          => 0,
            'payment_status'            => null,
            'psga_id'                   => null,
            'psga_status'               => null,
            'psga_contract_date'        => null,

            /* Extra display-only fields */
            '_is_rejected_request'     => true,
            '_request_no'              => $rejectedRequest['request_no'],
            '_service_code'            => '',
            '_service_name'            => '',
            '_sort_date'               => $rejectedRequest['submitted_at']
        ];
    }

    $rejectedStmt->close();
}

/*
 * Keep newest records first.
 */
usort($offers, function ($a, $b) {

    $dateA = strtotime((string)($a['_sort_date'] ?? $a['created_at'] ?? ''));
    $dateB = strtotime((string)($b['_sort_date'] ?? $b['created_at'] ?? ''));

    return $dateB <=> $dateA;
});


/* =========================================================
   DOCUMENT REGISTER
   ---------------------------------------------------------
   Each document type gets its OWN ROW.
   This keeps the Offers section scalable when more document
   types are added in future (FSO, PSGA, SAF, NOC, Insurance,
   Certificates, etc.).
========================================================= */

$documentRows = [];
$seenPsgaIds = [];

/*
 * Current FSO / Offer documents.
 * Rejected service requests remain separate document rows.
 */
foreach ($offers as $offer) {

    if (!empty($offer['_is_rejected_request'])) {

        $documentRows[] = [
            'document_type' => 'Service Request',
            'document_icon' => 'bi-file-earmark-x',
            'document_no'   => (string)($offer['_request_no'] ?? '—'),
            'reference'     => '#' . (int)($offer['request_id'] ?? 0),
            'offer_id'      => 0,
            'psga_id'       => 0,
            'psga_status'   => '',
            'asset_count'   => (int)($offer['asset_count'] ?? 0),
            'document_date' => $offer['offer_date'] ?? null,
            'valid_until'   => null,
            'amount'        => null,
            'status'        => 'Rejected',
            'sort_date'     => strtotime((string)($offer['_sort_date'] ?? $offer['offer_date'] ?? '')) ?: 0,
            'is_rejected'   => true
        ];

        continue;
    }

    /*
     * FSO / Offer is one independent document row.
     */
    $documentRows[] = [
        'document_type' => 'FSO Offer',
        'document_icon' => 'bi-file-earmark-text',
        'document_no'   => (string)($offer['offer_no'] ?? '—'),
        'reference'     => '#' . (int)($offer['request_id'] ?? 0),
        'offer_id'      => (int)($offer['id'] ?? 0),
        'psga_id'       => 0,
        'psga_status'   => '',
        'asset_count'   => (int)($offer['asset_count'] ?? 0),
        'document_date' => $offer['offer_date'] ?? null,
        'valid_until'   => $offer['validity_date'] ?? null,
        'amount'        => (float)($offer['grand_total'] ?? 0),
        'status'        => (string)($offer['status'] ?? ''),
        'payment_status'=> (string)($offer['payment_status'] ?? ''),
        'payment_received' => (float)($offer['payment_received'] ?? 0),
        'payment_balance' => $offer['payment_balance'] ?? null,
        'sort_date'     => strtotime((string)($offer['offer_date'] ?? $offer['created_at'] ?? '')) ?: 0,
        'is_rejected'   => false
    ];
}

/*
 * PSGA is a separate permanent document.
 *
 * First collect PSGAs already linked to loaded Offers so their
 * approval state is available. Duplicate PSGA rows are prevented.
 */
foreach ($offers as $offer) {

    if (!empty($offer['_is_rejected_request'])) {
        continue;
    }

    $psgaId = (int)($offer['psga_id'] ?? 0);

    if ($psgaId <= 0 || isset($seenPsgaIds[$psgaId])) {
        continue;
    }

    $seenPsgaIds[$psgaId] = true;

    $documentRows[] = [
        'document_type' => 'PSGA Agreement',
        'document_icon' => 'bi-file-earmark-check',
        'document_no'   => (string)($offer['psga_no'] ?? '—'),
        'reference'     => (string)($offer['offer_no'] ?? '—'),
        'offer_id'      => (int)($offer['id'] ?? 0),
        'psga_id'       => $psgaId,
        'psga_status'   => (string)($offer['psga_status'] ?? ''),
        'asset_count'   => (int)($offer['asset_count'] ?? 0),
        'document_date' => $offer['psga_contract_date'] ?? null,
        'valid_until'   => null,
        'amount'        => null,
        'status'        => (string)($offer['psga_status'] ?? ''),
        'sort_date'     => strtotime((string)($offer['psga_contract_date'] ?? $offer['offer_date'] ?? '')) ?: 0,
        'is_rejected'   => false
    ];
}

/*
 * IMPORTANT:
 * Permanent PSGA must remain visible even when its FSO/Offer
 * is deleted. Therefore load Owner's PSGA directly from
 * contracts as a separate document type.
 */
$permanentPsgaStmt = $conn->prepare("
    SELECT
        PSGA_ID,
        PSGA_No,
        Contract_Date,
        Status
    FROM contracts
    WHERE Owner_ID = ?
    ORDER BY PSGA_ID ASC
");

if ($permanentPsgaStmt) {

    $permanentPsgaStmt->bind_param("i", $ownerId);
    $permanentPsgaStmt->execute();

    $permanentPsgaResult = $permanentPsgaStmt->get_result();

    while ($permanentPsga = $permanentPsgaResult->fetch_assoc()) {

        $psgaId = (int)($permanentPsga['PSGA_ID'] ?? 0);

        if ($psgaId <= 0 || isset($seenPsgaIds[$psgaId])) {
            continue;
        }

        $seenPsgaIds[$psgaId] = true;

        $documentRows[] = [
            'document_type' => 'PSGA Agreement',
            'document_icon' => 'bi-file-earmark-check',
            'document_no'   => (string)($permanentPsga['PSGA_No'] ?? '—'),
            'reference'     => 'Permanent Owner Agreement',
            'offer_id'      => 0,
            'psga_id'       => $psgaId,
            'psga_status'   => (string)($permanentPsga['Status'] ?? ''),
            'asset_count'   => 0,
            'document_date' => $permanentPsga['Contract_Date'] ?? null,
            'valid_until'   => null,
            'amount'        => null,
            'status'        => (string)($permanentPsga['Status'] ?? ''),
            'sort_date'     => strtotime((string)($permanentPsga['Contract_Date'] ?? '')) ?: 0,
            'is_rejected'   => false
        ];
    }

    $permanentPsgaStmt->close();
}

/*
 * Newest document first.
 */
usort($documentRows, function ($a, $b) {
    return ((int)($b['sort_date'] ?? 0)) <=> ((int)($a['sort_date'] ?? 0));
});


/* =========================================================
   LOAD SELECTED OFFER
========================================================= */

if (
    $currentOfferId > 0
) {

    $selectedStmt = $conn->prepare("
    SELECT
        o.*,
        c.PSGA_ID AS psga_id,
        c.Status AS psga_status,
        c.Contract_Date AS psga_contract_date

    FROM nleta_offer_master o

    INNER JOIN service_requests sr
        ON sr.id = o.request_id
       AND sr.owner_id = ?

    /*
     * The Offer must still be connected to a live asset
     * owned by the logged-in Owner.
     */
    INNER JOIN nleta_offer_assets oa
        ON oa.offer_id = o.id

    INNER JOIN assets a
        ON a.id = oa.asset_id
       AND a.owner_id = ?

    LEFT JOIN contracts c
        ON c.PSGA_No = o.psga_no
       AND c.Owner_ID = ?

    WHERE o.id = ?

      AND o.status IN (
          'Sent',
          'Accepted',
          'Rejected'
      )

    LIMIT 1
");

if (!$selectedStmt) {

        $errorMessage =
            'Unable to load Offer details: '
            .
            $conn->error;

    } else {

        $selectedStmt->bind_param(
            "iiii",
            $ownerId,
            $ownerId,
            $ownerId,
            $currentOfferId
        );

        $selectedStmt->execute();

        $selectedOffer =
            $selectedStmt
            ->get_result()
            ->fetch_assoc();

        $selectedStmt->close();

        /*
         * =========================================================
         * SELECTED OFFER PAYMENT STATUS
         * =========================================================
         *
         * Payment status is taken from the actual payments record.
         * Offer Master status remains unchanged.
         */
        if ($selectedOffer) {

            $selectedOffer['payment_total'] =
                (float)$selectedOffer['grand_total'];

            $selectedOffer['payment_received'] =
                0.00;

            $selectedOffer['payment_balance'] =
                (float)$selectedOffer['grand_total'];

            $selectedOffer['payment_status'] =
                null;

            $paymentStmt = $conn->prepare("
                SELECT
                    Total_Amount,
                    Received_Amount,
                    Balance_Amount,
                    Payment_Status
                FROM payments
                WHERE Offer_ID = ?
                ORDER BY Payment_ID DESC
                LIMIT 1
            ");

            if ($paymentStmt) {

                $paymentStmt->bind_param(
                    "i",
                    $currentOfferId
                );

                $paymentStmt->execute();

                $paymentRow =
                    $paymentStmt
                    ->get_result()
                    ->fetch_assoc();

                if ($paymentRow) {

                    $selectedOffer['payment_total'] =
                        (float)$paymentRow['Total_Amount'];

                    $selectedOffer['payment_received'] =
                        (float)$paymentRow['Received_Amount'];

                    $selectedOffer['payment_balance'] =
                        (float)$paymentRow['Balance_Amount'];

                    $selectedOffer['payment_status'] =
                        (string)$paymentRow['Payment_Status'];
                }

                $paymentStmt->close();
            }
        }


        if (!$selectedOffer) {

            $errorMessage =
                'Offer not found or this Offer is not assigned to you.';

        } else {


            /* =============================================
               OFFER ITEMS
            ============================================= */

            $itemStmt = $conn->prepare("
                SELECT *

                FROM nleta_offer_items

                WHERE offer_id = ?

                ORDER BY id ASC
            ");

            if ($itemStmt) {

                $itemStmt->bind_param(
                    "i",
                    $currentOfferId
                );

                $itemStmt->execute();

                $itemResult =
                    $itemStmt
                    ->get_result();

                while (
                    $item =
                    $itemResult->fetch_assoc()
                ) {

                    $selectedItems[] =
                        $item;
                }

                $itemStmt->close();
            }


            /* =============================================
               OFFER ASSETS
            ============================================= */

            $assetStmt = $conn->prepare("
                SELECT

                    a.id,

                    a.display_asset_id,

                    a.asset_name,

                    a.asset_type,

                    a.location,

                    a.state,

                    ad.capacity,

                    ad.number_of_floors,

                    ad.escalator_type,

                    ad.escalator_rise

                FROM nleta_offer_assets oa

                INNER JOIN assets a
                    ON a.id = oa.asset_id

                LEFT JOIN asset_details ad
                    ON ad.asset_id = a.id

                WHERE oa.offer_id = ?

                  AND a.owner_id = ?

                ORDER BY
                    a.display_asset_id ASC,
                    a.id ASC
            ");

            if ($assetStmt) {

                $assetStmt->bind_param(
                    "ii",
                    $currentOfferId,
                    $ownerId
                );

                $assetStmt->execute();

                $assetResult =
                    $assetStmt
                    ->get_result();

                while (
                    $asset =
                    $assetResult->fetch_assoc()
                ) {

                    $selectedAssets[] =
                        $asset;
                }

                $assetStmt->close();
            }
        }
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
   OWNER OFFERS PAGE
========================================================= */

.offer-page {
    width: 100%;
    max-width: 1400px;
    margin: 0 auto;
}

.offer-card {
    border: 0;
    border-radius: 14px;
    box-shadow: 0 4px 18px rgba(0,0,0,.08);
}

.offer-table-wrap {
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

.offer-table {
    min-width: 850px;
    margin-bottom: 0;
}

.offer-table th,
.offer-table td {
    vertical-align: middle;
    white-space: nowrap;
}

.offer-number {
    font-weight: 700;
}

.amount {
    font-weight: 700;
}

.offer-detail {
    border-radius: 14px;
    background: #fff;
    box-shadow: 0 4px 18px rgba(0,0,0,.08);
}

.offer-detail-header {
    border-bottom: 1px solid #dee2e6;
    padding-bottom: 15px;
    margin-bottom: 20px;
}

.detail-table {
    width: 100%;
}

.detail-table td {
    padding: 7px 0;
    vertical-align: top;
}

.detail-label {
    width: 180px;
    font-weight: 600;
}

.asset-box {
    border: 1px solid #dee2e6;
    border-radius: 10px;
    padding: 12px;
    height: 100%;
}

.offer-table td:last-child .btn{
    margin-bottom:4px;
}

@media (max-width: 768px) {

    .offer-page {
        padding-left: 8px;
        padding-right: 8px;
    }

    .offer-table {
        min-width: 760px;
        font-size: 12px;
    }

    .detail-label {
        width: 130px;
    }

}

/* =========================================================
   DOCUMENT REGISTER
========================================================= */

.document-type-title {
    font-weight: 700;
    color: #172033;
}

.document-number {
    font-weight: 700;
}

.document-row-psga {
    background: #fffdf7;
}

.document-row-psga:hover {
    background: #fffaf0 !important;
}

.document-row-rejected {
    background: #fff8f8;
}

.document-row-rejected:hover {
    background: #fff2f2 !important;
}

.offer-table th,
.offer-table td {
    vertical-align: middle;
}

.offer-table td:nth-child(1) {
    min-width: 155px;
}

.offer-table td:nth-child(2) {
    min-width: 185px;
}

.offer-table td:nth-child(3) {
    min-width: 155px;
}

@media (max-width: 991px) {

    .offer-table {
        min-width: 1050px;
    }

}

@media (max-width: 575px) {

    .offer-table {
        min-width: 1000px;
        font-size: 12px;
    }

    .offer-table th,
    .offer-table td {
        padding: 8px 7px;
    }

}

@media print {

    body * {
        visibility: hidden !important;
    }

    #printOffer,
    #printOffer * {
        visibility: visible !important;
    }

    #printOffer {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        box-shadow: none !important;
    }

    .no-print {
        display: none !important;
    }

}

</style>


<div class="content">

<div class="offer-page">


    <!-- =================================================
         PAGE HEADER
    ================================================== -->

    <div
        class="d-flex
               justify-content-between
               align-items-center
               flex-wrap
               gap-2
               mb-4"
    >

        <div>

            <h3 class="mb-1">

                <i
                    class="bi bi-file-earmark-text me-2"
                ></i>

                Offers

            </h3>

            <div class="text-muted">

                Offers received from NLETA

            </div>

        </div>

    </div>


    <?php if (!empty($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle-fill me-2"></i>
            <?= h($_SESSION['success']) ?>
            <?php unset($_SESSION['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?= h($_SESSION['error']) ?>
            <?php unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (
        $errorMessage !== ''
    ): ?>

        <div class="alert alert-danger">

            <?= h($errorMessage) ?>

        </div>

    <?php endif; ?>


    <?php if (
        $selectedOffer
    ): ?>


        <!-- =================================================
             OFFER DETAIL
        ================================================== -->

        <div
            id="printOffer"
            class="offer-detail p-4 mb-4"
        >

            <div
                class="offer-detail-header
                       d-flex
                       justify-content-between
                       align-items-start
                       flex-wrap
                       gap-3"
            >

                <div>

                    <h4 class="mb-1">

                        <?= h(
                            $selectedOffer['offer_no']
                        ) ?>

                    </h4>

                    <div class="text-muted">

                        FSO Offer

                    </div>

                </div>


                <div>

                    <?php
                    $detailStatus = getOwnerOfferStatus(
                        $selectedOffer['status'] ?? '',
                        $selectedOffer['payment_status'] ?? '',
                        $selectedOffer['payment_received'] ?? 0,
                        $selectedOffer['payment_balance'] ?? null,
                        $selectedOffer['validity_date'] ?? null
                    );

                    $detailDisplayStatus = $detailStatus['label'];
                    $detailStatusClass = $detailStatus['class'];
                    ?>

                    <span
                        class="badge <?= h($detailStatusClass) ?>"
                        style="font-size:13px;"
                    >

                        <?= h($detailDisplayStatus) ?>

                    </span>

                </div>

            </div>


            <?php
            $psgaStatusDetail = trim((string)($selectedOffer['psga_status'] ?? ''));
            ?>

            <?php if ($psgaStatusDetail !== ''): ?>
                <div class="alert alert-light border mb-4">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                        <div>
                            <div class="fw-bold">
                                <i class="bi bi-file-earmark-check me-1"></i>
                                PSGA Agreement
                            </div>
                            <div class="small text-muted mt-1">
                                PSGA No. <?= h($selectedOffer['psga_no'] ?? '—') ?>
                            </div>
                        </div>

                        <div>
                            <?php if ($psgaStatusDetail === 'Pending Owner Confirmation'): ?>
                                <span class="badge bg-warning text-dark">
                                    PSGA Approval Pending
                                </span>
                            <?php elseif ($psgaStatusDetail === 'Pending Admin Confirmation'): ?>
                                <span class="badge bg-info text-dark">
                                    Accepted — Pending Admin
                                </span>
                            <?php elseif ($psgaStatusDetail === 'Confirmed'): ?>
                                <span class="badge bg-success">
                                    PSGA Confirmed
                                </span>
                            <?php elseif ($psgaStatusDetail === 'Pending Admin Review'): ?>
                                <span class="badge bg-danger">
                                    Returned to Admin
                                </span>
                            <?php else: ?>
                                <span class="badge bg-secondary">
                                    <?= h($psgaStatusDetail) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($psgaStatusDetail === 'Pending Owner Confirmation'): ?>
                        <div class="mt-3 d-flex gap-2 flex-wrap no-print">
                            <?php if (!empty($selectedOffer['psga_id'])): ?>
                                <a
                                    href="psga-view.php?psga_id=<?= (int)$selectedOffer['psga_id'] ?>"
                                    class="btn btn-primary btn-sm"
                                >
                                    <i class="bi bi-eye me-1"></i>
                                    View PSGA
                                </a>

                                <form method="POST" class="d-inline"
                                      onsubmit="return confirm('Accept this PSGA Agreement?');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="psga_id" value="<?= (int)$selectedOffer['psga_id'] ?>">
                                    <button type="submit" name="accept_psga" class="btn btn-success btn-sm">
                                        <i class="bi bi-check2-circle me-1"></i>
                                        Accept PSGA
                                    </button>
                                </form>

                                <button
                                    type="button"
                                    class="btn btn-danger btn-sm"
                                    data-bs-toggle="modal"
                                    data-bs-target="#offerPsgaRejectModal<?= (int)$selectedOffer['psga_id'] ?>"
                                >
                                    <i class="bi bi-x-circle me-1"></i>
                                    Reject PSGA
                                </button>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($selectedOffer['psga_id'])): ?>
                            <div class="modal fade"
                                 id="offerPsgaRejectModal<?= (int)$selectedOffer['psga_id'] ?>"
                                 tabindex="-1"
                                 aria-hidden="true">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content">
                                        <form method="POST">
                                            <div class="modal-header">
                                                <h5 class="modal-title fw-bold text-danger">
                                                    Reject PSGA Agreement
                                                </h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="alert alert-warning small">
                                                    Please provide the reason for rejecting this PSGA.
                                                    It will be returned to Admin for review.
                                                </div>
                                                <?= csrfField() ?>
                                                <input type="hidden" name="psga_id" value="<?= (int)$selectedOffer['psga_id'] ?>">
                                                <label class="form-label fw-bold">Rejection Reason</label>
                                                <textarea
                                                    name="psga_reject_reason"
                                                    class="form-control"
                                                    rows="4"
                                                    required
                                                    placeholder="Enter rejection reason..."
                                                ></textarea>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                                    Cancel
                                                </button>
                                                <button type="submit" name="reject_psga" class="btn btn-danger">
                                                    <i class="bi bi-x-circle me-1"></i>
                                                    Reject PSGA
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php elseif ($psgaStatusDetail === 'Pending Admin Confirmation'): ?>
                        <div class="small text-info fw-semibold mt-2">
                            PSGA accepted. Waiting for Admin confirmation.
                        </div>
                    <?php elseif ($psgaStatusDetail === 'Confirmed'): ?>
                        <div class="small text-success fw-semibold mt-2">
                            PSGA confirmed. SAF workflow may proceed.
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>


            <!-- OFFER BASIC DETAILS -->

            <div class="row g-4 mb-4">

                <div class="col-md-6">

                    <table
                        class="detail-table"
                    >

                        <tr>

                            <td class="detail-label">

                                Offer No.

                            </td>

                            <td>

                                <?= h(
                                    $selectedOffer['offer_no']
                                ) ?>

                            </td>

                        </tr>


                        <tr>

                            <td class="detail-label">

                                PSGA No.

                            </td>

                            <td>

                                <?= h(
                                    $selectedOffer['psga_no']
                                ) ?>

                            </td>

                        </tr>


                        <tr>

                            <td class="detail-label">

                                Offer Date

                            </td>

                            <td>

                                <?php

                                try {

                                    echo (new DateTime(
                                        $selectedOffer['offer_date'],
                                        $timezone
                                    ))->format(
                                        'd-m-Y'
                                    );

                                } catch (
                                    Exception $e
                                ) {

                                    echo h(
                                        $selectedOffer['offer_date']
                                    );
                                }

                                ?>

                            </td>

                        </tr>


                        <tr>

                            <td class="detail-label">

                                Valid Until

                            </td>

                            <td>

                                <?= h(
                                    $selectedOffer['validity_date']
                                ) ?>

                            </td>

                        </tr>

                    </table>

                </div>


                <div class="col-md-6">

                    <table
                        class="detail-table"
                    >

                        <tr>

                            <td class="detail-label">

                                Subtotal

                            </td>

                            <td>

                                ₹<?= number_format(
                                    (float)$selectedOffer['subtotal'],
                                    2
                                ) ?>

                            </td>

                        </tr>


                        <tr>

                            <td class="detail-label">

                                GST

                            </td>

                            <td>

                                <?= number_format(
                                    (float)$selectedOffer['gst_rate'],
                                    2
                                ) ?>%

                                —

                                ₹<?= number_format(
                                    (float)$selectedOffer['gst_amount'],
                                    2
                                ) ?>

                            </td>

                        </tr>


                        <tr>

                            <td class="detail-label">

                                Grand Total

                            </td>

                            <td
                                class="amount text-primary"
                            >

                                ₹<?= number_format(
                                    (float)$selectedOffer['grand_total'],
                                    2
                                ) ?>

                            </td>

                        </tr>

                    </table>

                </div>

            </div>


            <!-- PAYMENT -->

            <div
                class="alert alert-light border"
            >

                <strong>
                    Payment Schedule
                </strong>

                <div class="row mt-2">

                    <div class="col-md-4">

                        <small class="text-muted">

                            Advance – 50%

                        </small>

                        <br>

                        <strong>

                            ₹<?= number_format(
                                (float)$selectedOffer['payment_advance'],
                                2
                            ) ?>

                        </strong>

                    </div>


                    <div class="col-md-4">

                        <small class="text-muted">

                            Service Activation – 25%

                        </small>

                        <br>

                        <strong>

                            ₹<?= number_format(
                                (float)$selectedOffer['payment_service_activation'],
                                2
                            ) ?>

                        </strong>

                    </div>


                    <div class="col-md-4">

                        <small class="text-muted">

                            Final – 25%

                        </small>

                        <br>

                        <strong>

                            ₹<?= number_format(
                                (float)$selectedOffer['payment_final'],
                                2
                            ) ?>

                        </strong>

                    </div>

                </div>

            </div>


            <!-- =================================================
                 PAYMENT STATUS
            ================================================== -->

            <?php if (
                ($selectedOffer['status'] ?? '') === 'Accepted'
                ||
                !empty($selectedOffer['payment_status'])
            ): ?>

                <div
                    class="alert alert-light border mb-4"
                >

                    <div class="fw-bold mb-2">
                        Payment Status
                    </div>

                    <div class="row g-3">

                        <div class="col-md-4">

                            <small class="text-muted">
                                Total Amount
                            </small>

                            <br>

                            <strong>
                                ₹<?= number_format(
                                    (float)$selectedOffer['payment_total'],
                                    2
                                ) ?>
                            </strong>

                        </div>

                        <div class="col-md-4">

                            <small class="text-muted">
                                Received
                            </small>

                            <br>

                            <strong class="text-success">
                                ₹<?= number_format(
                                    (float)$selectedOffer['payment_received'],
                                    2
                                ) ?>
                            </strong>

                        </div>

                        <div class="col-md-4">

                            <small class="text-muted">
                                Balance
                            </small>

                            <br>

                            <strong class="text-danger">
                                ₹<?= number_format(
                                    (float)$selectedOffer['payment_balance'],
                                    2
                                ) ?>
                            </strong>

                        </div>

                    </div>

                </div>

            <?php endif; ?>


            <!-- ASSETS -->

            <h5 class="mt-4 mb-3">

                Selected Assets

            </h5>


            <?php if (
                empty($selectedAssets)
            ): ?>

                <div class="alert alert-warning">

                    No assets found for this Offer.

                </div>

            <?php else: ?>

                <div class="row g-3">

                    <?php foreach (
                        $selectedAssets
                        as $asset
                    ): ?>

                        <div
                            class="col-md-6
                                   col-lg-4"
                        >

                            <div
                                class="asset-box"
                            >

                                <div
                                    class="fw-bold"
                                >

                                    <?= h(
                                        $asset['display_asset_id']
                                    ) ?>

                                </div>

                                <div>

                                    <?= h(
                                        $asset['asset_name']
                                    ) ?>

                                </div>

                                <small
                                    class="text-muted"
                                >

                                    <?= h(
                                        $asset['asset_type']
                                    ) ?>

                                    <?php if (
                                        !empty(
                                            $asset['location']
                                        )
                                    ): ?>

                                        —
                                        <?= h(
                                            $asset['location']
                                        ) ?>

                                    <?php endif; ?>

                                </small>


                                <?php if (
                                    strtolower(
                                        (string)$asset['asset_type']
                                    )
                                    ===
                                    'lift'
                                ): ?>

                                    <?php if (
                                        !empty(
                                            $asset['capacity']
                                        )
                                    ): ?>

                                        <br>

                                        <small>

                                            Capacity:
                                            <?= h(
                                                $asset['capacity']
                                            ) ?>

                                        </small>

                                    <?php endif; ?>


                                    <?php if (
                                        !empty(
                                            $asset['number_of_floors']
                                        )
                                    ): ?>

                                        <br>

                                        <small>

                                            Floors:
                                            <?= h(
                                                $asset['number_of_floors']
                                            ) ?>

                                        </small>

                                    <?php endif; ?>

                                <?php else: ?>

                                    <?php if (
                                        !empty(
                                            $asset['escalator_type']
                                        )
                                    ): ?>

                                        <br>

                                        <small>

                                            <?= h(
                                                $asset['escalator_type']
                                            ) ?>

                                        </small>

                                    <?php endif; ?>


                                    <?php if (
                                        $asset['escalator_rise']
                                        !== null
                                        &&
                                        $asset['escalator_rise']
                                        !== ''
                                    ): ?>

                                        <br>

                                        <small>

                                            Rise:
                                            <?= h(
                                                $asset['escalator_rise']
                                            ) ?>
                                            m

                                        </small>

                                    <?php endif; ?>

                                <?php endif; ?>

                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>


            <!-- SERVICE ITEMS -->

            <h5 class="mt-4 mb-3">

                Offer Services

            </h5>


            <div class="offer-table-wrap">

                <table
                    class="table
                           table-bordered
                           align-middle"
                >

                    <thead
                        class="table-light"
                    >

                        <tr>

                            <th>
                                #
                            </th>

                            <th>
                                Service
                            </th>

                            <th>
                                Qty
                            </th>

                            <th>
                                Rate
                            </th>

                            <th>
                                Amount
                            </th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php if (
                            empty($selectedItems)
                        ): ?>

                            <tr>

                                <td
                                    colspan="5"
                                    class="text-center"
                                >

                                    No service items found.

                                </td>

                            </tr>

                        <?php else: ?>

                            <?php foreach (
                                $selectedItems
                                as $index => $item
                            ): ?>

                                <tr>

                                    <td>

                                        <?= $index + 1 ?>

                                    </td>

                                    <td>

                                        <strong>

                                            <?= h(
                                                $item['service_code']
                                            ) ?>

                                        </strong>

                                        <br>

                                        <?= h(
                                            $item['service_name']
                                        ) ?>

                                    </td>

                                    <td>

                                        <?= h(
                                            $item['quantity']
                                        ) ?>

                                    </td>

                                    <td>

                                        ₹<?= number_format(
                                            (float)$item['rate'],
                                            2
                                        ) ?>

                                    </td>

                                    <td>

                                        ₹<?= number_format(
                                            (float)$item['amount'],
                                            2
                                        ) ?>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>


            <!-- TOTAL -->

            <div
                class="row
                       justify-content-end
                       mt-3"
            >

                <div class="col-md-5">

                    <table
                        class="table
                               table-sm"
                    >

                        <tr>

                            <td>
                                Subtotal
                            </td>

                            <td class="text-end">

                                ₹<?= number_format(
                                    (float)$selectedOffer['subtotal'],
                                    2
                                ) ?>

                            </td>

                        </tr>


                        <tr>

                            <td>

                                GST
                                (<?= number_format(
                                    (float)$selectedOffer['gst_rate'],
                                    2
                                ) ?>%)

                            </td>

                            <td class="text-end">

                                ₹<?= number_format(
                                    (float)$selectedOffer['gst_amount'],
                                    2
                                ) ?>

                            </td>

                        </tr>


                        <tr
                            class="table-primary
                                   fw-bold"
                        >

                            <td>

                                Grand Total

                            </td>

                            <td class="text-end">

                                ₹<?= number_format(
                                    (float)$selectedOffer['grand_total'],
                                    2
                                ) ?>

                            </td>

                        </tr>

                    </table>

                </div>

            </div>

        </div>


        <!-- ACTIONS -->

        <div
            class="d-flex
                   gap-2
                   flex-wrap
                   mb-4
                   no-print"
        >

            <button
                type="button"
                class="btn btn-primary"
                onclick="window.print();"
            >

                <i
                    class="bi bi-printer me-1"
                ></i>

                Print / Save PDF

            </button>


            <a
                href="offers.php"
                class="btn btn-outline-secondary"
            >

                <i
                    class="bi bi-arrow-left me-1"
                ></i>

                Back to Offers

            </a>

        </div>


    <?php else: ?>


        <!-- =================================================
             OFFER LIST

             OWNER MAPPING:
             service_requests.owner_id
                     ↓
             service_requests.id
                     ↓
             nleta_offer_master.request_id

             This ensures every offer belonging to the logged-in
             Owner is displayed, even if asset ownership data was
             changed later.
        ================================================== -->

        <?php if (
            empty($offers)
        ): ?>

            <div class="card offer-card p-5 text-center">

                <div
                    class="mb-3"
                    style="font-size:48px;"
                >

                    📄

                </div>

                <h5>

                    No Offers Received

                </h5>

                <p class="text-muted mb-0">

                    Offers sent by NLETA will appear here.

                </p>

            </div>

        <?php else: ?>

            <div
                class="card
                       offer-card
                       p-3"
            >

                <div class="offer-table-wrap">

                    <table
                        class="table
                               offer-table
                               table-hover"
                    >

                        <thead class="table-light">

                            <tr>

                                <th>
                                    Document Type
                                </th>

                                <th>
                                    Action
                                </th>

                                <th>
                                    Document No.
                                </th>

                                <th>
                                    Reference
                                </th>

                                <th>
                                    Assets
                                </th>

                                <th>
                                    Date
                                </th>

                                <th>
                                    Valid Until
                                </th>

                                <th>
                                    Amount
                                </th>

                                <th>
                                    Status
                                </th>

                            </tr>

                        </thead>

                        <tbody>

                            <?php if (empty($documentRows)): ?>

                                <tr>

                                    <td
                                        colspan="9"
                                        class="text-center text-muted py-5"
                                    >

                                        <div style="font-size:42px;" class="mb-2">
                                            📄
                                        </div>

                                        <strong>
                                            No Documents Received
                                        </strong>

                                        <div class="small mt-1">
                                            Documents sent by NLETA will appear here.
                                        </div>

                                    </td>

                                </tr>

                            <?php else: ?>

                                <?php foreach ($documentRows as $document): ?>

                                    <?php
                                    $documentType = (string)($document['document_type'] ?? '');
                                    $documentStatus = trim((string)($document['status'] ?? ''));

                                    $documentStatusClass = 'bg-secondary';
                                    $documentStatusLabel = $documentStatus !== ''
                                        ? $documentStatus
                                        : 'Status Pending';

                                    /*
                                     * FSO status
                                     */
                                    if ($documentType === 'FSO Offer') {

                                        $offerDocStatus = getOwnerOfferStatus(
                                            $document['status'] ?? '',
                                            $document['payment_status'] ?? '',
                                            $document['payment_received'] ?? 0,
                                            $document['payment_balance'] ?? null,
                                            $document['valid_until'] ?? null
                                        );

                                        $documentStatusLabel = $offerDocStatus['label'];
                                        $documentStatusClass = $offerDocStatus['class'];
                                    }

                                    /*
                                     * PSGA status
                                     */
                                    elseif ($documentType === 'PSGA Agreement') {

                                        if ($documentStatus === 'Pending Owner Confirmation') {
                                            $documentStatusLabel = 'PSGA Approval Pending';
                                            $documentStatusClass = 'bg-warning text-dark';
                                        } elseif ($documentStatus === 'Pending Admin Confirmation') {
                                            $documentStatusLabel = 'Accepted — Pending Admin';
                                            $documentStatusClass = 'bg-info text-dark';
                                        } elseif ($documentStatus === 'Confirmed') {
                                            $documentStatusLabel = 'PSGA Confirmed';
                                            $documentStatusClass = 'bg-success';
                                        } elseif ($documentStatus === 'Pending Admin Review') {
                                            $documentStatusLabel = 'Returned to Admin';
                                            $documentStatusClass = 'bg-danger';
                                        }
                                    }

                                    /*
                                     * Rejected Service Request
                                     */
                                    elseif ($documentType === 'Service Request') {
                                        $documentStatusLabel = 'Service Request Rejected';
                                        $documentStatusClass = 'bg-danger';
                                    }

                                    $dateText = '—';

                                    if (!empty($document['document_date'])) {
                                        try {
                                            $dateText = (new DateTime(
                                                (string)$document['document_date'],
                                                $timezone
                                            ))->format('d-m-Y');
                                        } catch (Throwable $e) {
                                            $dateText = (string)$document['document_date'];
                                        }
                                    }

                                    $validUntilText = '—';

                                    if (!empty($document['valid_until'])) {
                                        try {
                                            $validUntilText = (new DateTime(
                                                (string)$document['valid_until'],
                                                $timezone
                                            ))->format('d-m-Y');
                                        } catch (Throwable $e) {
                                            $validUntilText = (string)$document['valid_until'];
                                        }
                                    }

                                    $rowClass = '';

                                    if ($documentType === 'PSGA Agreement') {
                                        $rowClass = 'document-row-psga';
                                    } elseif ($documentType === 'Service Request') {
                                        $rowClass = 'document-row-rejected';
                                    }
                                    ?>

                                    <tr class="<?= h($rowClass) ?>">

                                        <!-- DOCUMENT TYPE -->

                                        <td>

                                            <?php if ($documentType === 'PSGA Agreement'): ?>

                                                <div class="document-type-title">
                                                    <i class="bi bi-file-earmark-check me-1"></i>
                                                    PSGA Agreement
                                                </div>

                                                <small class="text-muted">
                                                    Permanent Owner Document
                                                </small>

                                            <?php elseif ($documentType === 'Service Request'): ?>

                                                <div class="document-type-title text-danger">
                                                    <i class="bi bi-file-earmark-x me-1"></i>
                                                    Service Request
                                                </div>

                                                <small class="text-muted">
                                                    Rejected
                                                </small>

                                            <?php else: ?>

                                                <div class="document-type-title">
                                                    <i class="bi bi-file-earmark-text me-1"></i>
                                                    FSO Offer
                                                </div>

                                                <small class="text-muted">
                                                    Financial Service Offer
                                                </small>

                                            <?php endif; ?>

                                        </td>


                                        <!-- ACTION — ALWAYS SECOND -->

                                        <td>

                                            <?php if ($documentType === 'FSO Offer'): ?>

                                                <?php if ((int)$document['offer_id'] > 0): ?>

                                                    <a
                                                        href="offer-view.php?id=<?= (int)$document['offer_id'] ?>"
                                                        class="btn btn-sm btn-primary mb-1"
                                                    >
                                                        <i class="bi bi-eye me-1"></i>
                                                        View Offer
                                                    </a>

                                                <?php endif; ?>


                                            <?php elseif ($documentType === 'PSGA Agreement'): ?>

                                                <?php if ((int)$document['psga_id'] > 0): ?>

                                                    <a
                                                        href="psga-view.php?psga_id=<?= (int)$document['psga_id'] ?>"
                                                        class="btn btn-sm btn-outline-primary mb-1"
                                                    >
                                                        <i class="bi bi-eye me-1"></i>
                                                        View PSGA
                                                    </a>

                                                    <?php if (
                                                        $documentStatus === 'Pending Owner Confirmation'
                                                    ): ?>

                                                        <form
                                                            method="POST"
                                                            class="d-inline"
                                                            onsubmit="return confirm('Accept this PSGA Agreement?');"
                                                        >

                                                            <?= csrfField() ?>

                                                            <input
                                                                type="hidden"
                                                                name="psga_id"
                                                                value="<?= (int)$document['psga_id'] ?>"
                                                            >

                                                            <button
                                                                type="submit"
                                                                name="accept_psga"
                                                                class="btn btn-sm btn-success mb-1"
                                                            >
                                                                <i class="bi bi-check2-circle me-1"></i>
                                                                Accept
                                                            </button>

                                                        </form>


                                                        <button
                                                            type="button"
                                                            class="btn btn-sm btn-danger mb-1"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#documentPsgaRejectModal<?= (int)$document['psga_id'] ?>"
                                                        >
                                                            <i class="bi bi-x-circle me-1"></i>
                                                            Reject
                                                        </button>


                                                        <div
                                                            class="modal fade"
                                                            id="documentPsgaRejectModal<?= (int)$document['psga_id'] ?>"
                                                            tabindex="-1"
                                                            aria-hidden="true"
                                                        >

                                                            <div class="modal-dialog modal-dialog-centered">

                                                                <div class="modal-content">

                                                                    <form method="POST">

                                                                        <div class="modal-header">

                                                                            <h5 class="modal-title fw-bold text-danger">
                                                                                Reject PSGA Agreement
                                                                            </h5>

                                                                            <button
                                                                                type="button"
                                                                                class="btn-close"
                                                                                data-bs-dismiss="modal"
                                                                            ></button>

                                                                        </div>

                                                                        <div class="modal-body">

                                                                            <div class="alert alert-warning small">

                                                                                Please provide the reason for rejecting this PSGA.
                                                                                It will be returned to Admin for review.

                                                                            </div>

                                                                            <?= csrfField() ?>

                                                                            <input
                                                                                type="hidden"
                                                                                name="psga_id"
                                                                                value="<?= (int)$document['psga_id'] ?>"
                                                                            >

                                                                            <label class="form-label fw-bold">
                                                                                Rejection Reason
                                                                            </label>

                                                                            <textarea
                                                                                name="psga_reject_reason"
                                                                                class="form-control"
                                                                                rows="4"
                                                                                required
                                                                                placeholder="Enter rejection reason..."
                                                                            ></textarea>

                                                                        </div>

                                                                        <div class="modal-footer">

                                                                            <button
                                                                                type="button"
                                                                                class="btn btn-secondary"
                                                                                data-bs-dismiss="modal"
                                                                            >
                                                                                Cancel
                                                                            </button>

                                                                            <button
                                                                                type="submit"
                                                                                name="reject_psga"
                                                                                class="btn btn-danger"
                                                                            >
                                                                                <i class="bi bi-x-circle me-1"></i>
                                                                                Reject PSGA
                                                                            </button>

                                                                        </div>

                                                                    </form>

                                                                </div>

                                                            </div>

                                                        </div>

                                                    <?php elseif (
                                                        $documentStatus === 'Pending Admin Confirmation'
                                                    ): ?>

                                                        <span class="badge bg-info text-dark d-inline-block mb-1">
                                                            Accepted — Pending Admin
                                                        </span>

                                                    <?php elseif (
                                                        $documentStatus === 'Confirmed'
                                                    ): ?>

                                                        <span class="badge bg-success d-inline-block mb-1">
                                                            Confirmed
                                                        </span>

                                                    <?php elseif (
                                                        $documentStatus === 'Pending Admin Review'
                                                    ): ?>

                                                        <span class="badge bg-danger d-inline-block mb-1">
                                                            Returned to Admin
                                                        </span>

                                                    <?php endif; ?>

                                                <?php endif; ?>


                                            <?php else: ?>

                                                <span class="badge bg-danger">
                                                    Rejected
                                                </span>

                                            <?php endif; ?>

                                        </td>


                                        <!-- DOCUMENT NUMBER -->

                                        <td>

                                            <span class="document-number">

                                                <?= h(
                                                    $document['document_no'] ?? '—'
                                                ) ?>

                                            </span>

                                        </td>


                                        <!-- REFERENCE -->

                                        <td>

                                            <?= h(
                                                $document['reference'] ?? '—'
                                            ) ?>

                                        </td>


                                        <!-- ASSETS -->

                                        <td>

                                            <?php if ((int)($document['asset_count'] ?? 0) > 0): ?>

                                                <span class="badge bg-secondary">
                                                    <?= (int)$document['asset_count'] ?>
                                                </span>

                                            <?php else: ?>

                                                <span class="text-muted">
                                                    —
                                                </span>

                                            <?php endif; ?>

                                        </td>


                                        <!-- DATE -->

                                        <td>

                                            <?= h($dateText) ?>

                                        </td>


                                        <!-- VALID UNTIL -->

                                        <td>

                                            <?= h($validUntilText) ?>

                                        </td>


                                        <!-- AMOUNT -->

                                        <td>

                                            <?php if (
                                                $document['amount'] !== null
                                                &&
                                                $documentType === 'FSO Offer'
                                            ): ?>

                                                <span class="amount">
                                                    ₹<?= number_format(
                                                        (float)$document['amount'],
                                                        2
                                                    ) ?>
                                                </span>

                                            <?php else: ?>

                                                <span class="text-muted">
                                                    —
                                                </span>

                                            <?php endif; ?>

                                        </td>


                                        <!-- STATUS -->

                                        <td>

                                            <span class="badge <?= h($documentStatusClass) ?>">

                                                <?= h($documentStatusLabel) ?>

                                            </span>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </div>

        <?php endif; ?>


    <?php endif; ?>


</div>

</div>


<?php include('../includes/footer.php'); ?>