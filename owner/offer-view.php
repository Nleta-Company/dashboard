<?php

/* =========================================================
   OWNER - RECEIVED FSO OFFER
   ---------------------------------------------------------
   This page intentionally uses the SAME FSO paper structure,
   wording, sections, styling and print layout as the Admin
   Offer Generator.

   Owner can only VIEW / PRINT the Offer.
   No Offer data is recalculated here.
========================================================= */

include('../config/db.php');
include('../config/auth.php');
require_once('../config/nleta-mailer.php');

require_role('owner');

$ownerId =
    (int)($_SESSION['uid'] ?? 0);

date_default_timezone_set('Asia/Kolkata');

$offerId =
    (int)($_GET['id'] ?? 0);

$error = '';

$existingOffer = null;

$client = null;

$existingItems = [];

$offerAssets = [];

/* =========================================================
   OWNER OFFER DECISION
   ---------------------------------------------------------
   Owner can change an Offer only from:
       Sent -> Accepted
       Sent -> Rejected

   PSGA number and all Offer values remain unchanged.
========================================================= */

$decisionMessage = '';
$decisionError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedOfferId =
        (int)($_POST['offer_id'] ?? 0);

    $decision =
        trim((string)($_POST['decision'] ?? ''));

    if (
        $postedOfferId <= 0
        ||
        !in_array(
            $decision,
            ['Accepted', 'Rejected'],
            true
        )
    ) {

        $decisionError =
            'Invalid Offer decision.';

    } else {

        /*
         * ACCEPT / REJECT RULE
         *
         * Owner can change an Offer only:
         * Sent -> Accepted
         * Sent -> Rejected
         *
         * PSGA number and all Offer values remain unchanged.
         *
         * IMPORTANT:
         * When an Offer is Accepted, Phase 7 payment records are
         * created from the exact saved Offer payment amounts.
         *
         * payments.PSGA_ID is intentionally NULL here because the
         * current contracts table is empty and PSGA_ID references
         * contracts.PSGA_ID. The existing PSGA number remains stored
         * in nleta_offer_master.psga_no and is NOT regenerated.
         */

        if ($decision === 'Accepted') {

            $conn->begin_transaction();

            try {

                /*
                 * 1. Accept the Offer only if:
                 *    - it belongs to this Owner
                 *    - its current status is Sent
                 */
                $decisionStmt =
                    $conn->prepare("
                        UPDATE nleta_offer_master o
                        SET
                            o.status = ?,
                            o.updated_at = NOW()
                        WHERE
                            o.id = ?
                            AND o.status = 'Sent'
                            AND EXISTS (
                                SELECT 1
                                FROM nleta_offer_assets oa
                                INNER JOIN assets a
                                    ON a.id = oa.asset_id
                                WHERE
                                    oa.offer_id = o.id
                                    AND a.owner_id = ?
                            )
                        LIMIT 1
                    ");

                if (!$decisionStmt) {
                    throw new Exception(
                        'Unable to process Offer decision: ' . $conn->error
                    );
                }

                $decisionStmt->bind_param(
                    'sii',
                    $decision,
                    $postedOfferId,
                    $ownerId
                );

                if (!$decisionStmt->execute()) {
                    $stmtError = $decisionStmt->error;
                    $decisionStmt->close();

                    throw new Exception(
                        'Unable to accept Offer: ' . $stmtError
                    );
                }

                if ($decisionStmt->affected_rows !== 1) {
                    $decisionStmt->close();

                    throw new Exception(
                        'This Offer is no longer available for a new decision. '
                        . 'Please return to Offers and check its current status.'
                    );
                }

                $decisionStmt->close();


                /*
                 * 2. Load the exact saved Offer payment values.
                 *    NO pricing is recalculated here.
                 */
                $paymentOfferStmt =
                    $conn->prepare("
                        SELECT
                            id,
                            psga_no,
                            grand_total,
                            payment_advance,
                            payment_service_activation,
                            payment_final
                        FROM nleta_offer_master
                        WHERE id = ?
                        LIMIT 1
                    ");

                if (!$paymentOfferStmt) {
                    throw new Exception(
                        'Unable to load Offer payment details: '
                        . $conn->error
                    );
                }

                $paymentOfferStmt->bind_param(
                    'i',
                    $postedOfferId
                );

                if (!$paymentOfferStmt->execute()) {
                    $stmtError = $paymentOfferStmt->error;
                    $paymentOfferStmt->close();

                    throw new Exception(
                        'Unable to load Offer payment details: ' . $stmtError
                    );
                }

                $paymentOffer =
                    $paymentOfferStmt
                        ->get_result()
                        ->fetch_assoc();

                $paymentOfferStmt->close();

                if (!$paymentOffer) {
                    throw new Exception(
                        'Accepted Offer payment details could not be loaded.'
                    );
                }


                /*
                 * 3. Check whether an overall payment record already exists.
                 *    This protects against duplicate payment creation.
                 */
                $existingPaymentStmt =
                    $conn->prepare("
                        SELECT
                            Payment_ID
                        FROM payments
                        WHERE Offer_ID = ?
                        LIMIT 1
                    ");

                if (!$existingPaymentStmt) {
                    throw new Exception(
                        'Unable to check existing payment record: '
                        . $conn->error
                    );
                }

                $existingPaymentStmt->bind_param(
                    'i',
                    $postedOfferId
                );

                if (!$existingPaymentStmt->execute()) {
                    $stmtError = $existingPaymentStmt->error;
                    $existingPaymentStmt->close();

                    throw new Exception(
                        'Unable to check existing payment record: ' . $stmtError
                    );
                }

                $existingPayment =
                    $existingPaymentStmt
                        ->get_result()
                        ->fetch_assoc();

                $existingPaymentStmt->close();


                /*
                 * 4. Create the overall payment record only once.
                 *
                 * PSGA_ID is intentionally NULL because contracts is
                 * currently empty and payments.PSGA_ID references
                 * contracts.PSGA_ID.
                 */
                if (!$existingPayment) {

                    $totalAmount =
                        (float)$paymentOffer['grand_total'];

                    $receivedAmount = 0.00;

                    $balanceAmount =
                        $totalAmount;

                    $paymentStatus = 'Pending';

                    $paymentInsertStmt =
                        $conn->prepare("
                            INSERT INTO payments
                            (
                                Offer_ID,
                                PSGA_ID,
                                Total_Amount,
                                Received_Amount,
                                Balance_Amount,
                                Payment_Status
                            )
                            VALUES
                            (
                                ?,
                                NULL,
                                ?,
                                ?,
                                ?,
                                ?
                            )
                        ");

                    if (!$paymentInsertStmt) {
                        throw new Exception(
                            'Unable to create payment record: '
                            . $conn->error
                        );
                    }

                    $paymentInsertStmt->bind_param(
                        'iddds',
                        $postedOfferId,
                        $totalAmount,
                        $receivedAmount,
                        $balanceAmount,
                        $paymentStatus
                    );

                    if (!$paymentInsertStmt->execute()) {
                        $stmtError = $paymentInsertStmt->error;
                        $paymentInsertStmt->close();

                        throw new Exception(
                            'Unable to create payment record: ' . $stmtError
                        );
                    }

                    $paymentId =
                        (int)$conn->insert_id;

                    $paymentInsertStmt->close();

                } else {

                    $paymentId =
                        (int)$existingPayment['Payment_ID'];
                }


                /*
                 * 5. Check whether payment schedule rows already exist.
                 */
                $scheduleCountStmt =
                    $conn->prepare("
                        SELECT
                            COUNT(*) AS schedule_count
                        FROM payment_schedule
                        WHERE Payment_ID = ?
                    ");

                if (!$scheduleCountStmt) {
                    throw new Exception(
                        'Unable to check payment schedule: '
                        . $conn->error
                    );
                }

                $scheduleCountStmt->bind_param(
                    'i',
                    $paymentId
                );

                if (!$scheduleCountStmt->execute()) {
                    $stmtError = $scheduleCountStmt->error;
                    $scheduleCountStmt->close();

                    throw new Exception(
                        'Unable to check payment schedule: ' . $stmtError
                    );
                }

                $scheduleRow =
                    $scheduleCountStmt
                        ->get_result()
                        ->fetch_assoc();

                $scheduleCountStmt->close();

                $scheduleCount =
                    (int)($scheduleRow['schedule_count'] ?? 0);


                /*
                 * 6. Create the three payment stages only once.
                 *
                 * Exact saved Offer amounts are used:
                 * Advance = payment_advance
                 * Before Final Checklist = payment_service_activation
                 * Final = payment_final
                 */
                if ($scheduleCount === 0) {

                    $advanceAmount =
                        (float)$paymentOffer['payment_advance'];

                    $serviceActivationAmount =
                        (float)$paymentOffer['payment_service_activation'];

                    $finalAmount =
                        (float)$paymentOffer['payment_final'];

                    $scheduleStmt =
                        $conn->prepare("
                            INSERT INTO payment_schedule
                            (
                                Payment_ID,
                                Offer_ID,
                                PSGA_ID,
                                Payment_Stage,
                                Percentage,
                                Expected_Amount,
                                Status,
                                Due_Trigger
                            )
                            VALUES
                            (
                                ?,
                                ?,
                                NULL,
                                ?,
                                ?,
                                ?,
                                ?,
                                ?
                            )
                        ");

                    if (!$scheduleStmt) {
                        throw new Exception(
                            'Unable to create payment schedule: '
                            . $conn->error
                        );
                    }


                    /* ADVANCE — 50% */
                    $stage =
                        'Advance';

                    $percentage =
                        50.00;

                    $expectedAmount =
                        $advanceAmount;

                    $stageStatus =
                        'Pending';

                    $dueTrigger =
                        'Agreement / PSGA activation';

                    $scheduleStmt->bind_param(
                        'iisddss',
                        $paymentId,
                        $postedOfferId,
                        $stage,
                        $percentage,
                        $expectedAmount,
                        $stageStatus,
                        $dueTrigger
                    );

                    if (!$scheduleStmt->execute()) {
                        $stmtError = $scheduleStmt->error;
                        $scheduleStmt->close();

                        throw new Exception(
                            'Unable to create Advance payment schedule: '
                            . $stmtError
                        );
                    }


                    /* SERVICE ACTIVATION — 25% */
                    $stage =
                        'Before Final Checklist';

                    $percentage =
                        25.00;

                    $expectedAmount =
                        $serviceActivationAmount;

                    $stageStatus =
                        'Locked';

                    $dueTrigger =
                        'Before Final Checklist';

                    if (!$scheduleStmt->execute()) {
                        $stmtError = $scheduleStmt->error;
                        $scheduleStmt->close();

                        throw new Exception(
                            'Unable to create Before Final Checklist payment schedule: '
                            . $stmtError
                        );
                    }


                    /* FINAL — 25% */
                    $stage =
                        'Final';

                    $percentage =
                        25.00;

                    $expectedAmount =
                        $finalAmount;

                    $stageStatus =
                        'Locked';

                    $dueTrigger =
                        'Before Report Issue / Final Payment';

                    if (!$scheduleStmt->execute()) {
                        $stmtError = $scheduleStmt->error;
                        $scheduleStmt->close();

                        throw new Exception(
                            'Unable to create Final payment schedule: '
                            . $stmtError
                        );
                    }

                    $scheduleStmt->close();
                }


                /*
                 * 7. Everything succeeded.
                 */
                $conn->commit();

                /*
                 * =========================================================
                 * OWNER ACCEPTED OFFER → ADMIN EMAIL
                 * ---------------------------------------------------------
                 * Email is sent ONLY after the Offer acceptance and
                 * payment schedule transaction has been committed.
                 *
                 * If email sending fails, the Offer remains Accepted.
                 * The email failure is logged and shown only as a small
                 * warning; database acceptance is never rolled back.
                 * =========================================================
                 */

                $offerAcceptedMailSent = false;

                try {

                    /* ---------------------------------------------
                       Load request / owner / service information
                    --------------------------------------------- */

                    $mailOfferStmt = $conn->prepare("
                        SELECT
                            o.id,
                            o.offer_no,
                            o.psga_no,
                            o.grand_total,
                            sr.request_no,
                            u.name AS owner_name,
                            u.email AS owner_email
                        FROM nleta_offer_master o

                        LEFT JOIN service_requests sr
                            ON sr.id = o.request_id

                        LEFT JOIN nleta_offer_assets oa
                            ON oa.offer_id = o.id

                        LEFT JOIN assets a
                            ON a.id = oa.asset_id

                        LEFT JOIN users u
                            ON u.id = a.owner_id

                        WHERE
                            o.id = ?
                            AND a.owner_id = ?
                        LIMIT 1
                    ");

                    if (!$mailOfferStmt) {
                        throw new Exception(
                            'Unable to load Offer details for Admin email: '
                            . $conn->error
                        );
                    }

                    $mailOfferStmt->bind_param(
                        'ii',
                        $postedOfferId,
                        $ownerId
                    );

                    if (!$mailOfferStmt->execute()) {
                        $stmtError = $mailOfferStmt->error;
                        $mailOfferStmt->close();

                        throw new Exception(
                            'Unable to load Offer details for Admin email: '
                            . $stmtError
                        );
                    }

                    $mailOffer =
                        $mailOfferStmt
                            ->get_result()
                            ->fetch_assoc();

                    $mailOfferStmt->close();

                    if (!$mailOffer) {
                        throw new Exception(
                            'Offer details could not be loaded for Admin email.'
                        );
                    }


                    /* ---------------------------------------------
                       Load ALL selected services
                    --------------------------------------------- */

                    $mailServices = [];

                    $serviceTableCheck =
                        $conn->query(
                            "SHOW TABLES LIKE 'service_request_services'"
                        );

                    if (
                        $serviceTableCheck
                        &&
                        $serviceTableCheck->num_rows > 0
                    ) {

                        $serviceStmt = $conn->prepare("
                            SELECT
                                sc.service_name
                            FROM service_request_services srs
                            INNER JOIN service_catalog sc
                                ON sc.id = srs.service_id
                            WHERE
                                srs.request_id = ?
                            ORDER BY
                                srs.id ASC
                        ");

                        if ($serviceStmt) {

                            $requestIdForMail =
                                (int)($mailOffer['request_id'] ?? 0);

                            $serviceStmt->bind_param(
                                'i',
                                $requestIdForMail
                            );

                            if ($serviceStmt->execute()) {

                                $serviceResult =
                                    $serviceStmt
                                        ->get_result();

                                while (
                                    $serviceRow =
                                    $serviceResult->fetch_assoc()
                                ) {

                                    $serviceNameValue =
                                        trim(
                                            (string)(
                                                $serviceRow['service_name']
                                                ?? ''
                                            )
                                        );

                                    if (
                                        $serviceNameValue !== ''
                                    ) {

                                        $mailServices[] =
                                            $serviceNameValue;
                                    }
                                }
                            }

                            $serviceStmt->close();
                        }
                    }

                    /*
                     * Legacy fallback — preserves compatibility with
                     * older single-service requests.
                     */
                    if (empty($mailServices)) {

                        $fallbackServiceStmt =
                            $conn->prepare("
                                SELECT
                                    sc.service_name
                                FROM nleta_offer_master o
                                INNER JOIN service_catalog sc
                                    ON sc.id = o.service_id
                                WHERE
                                    o.id = ?
                                LIMIT 1
                            ");

                        if ($fallbackServiceStmt) {

                            $fallbackServiceStmt->bind_param(
                                'i',
                                $postedOfferId
                            );

                            if ($fallbackServiceStmt->execute()) {

                                $fallbackService =
                                    $fallbackServiceStmt
                                        ->get_result()
                                        ->fetch_assoc();

                                if (
                                    $fallbackService
                                    &&
                                    trim(
                                        (string)(
                                            $fallbackService[
                                                'service_name'
                                            ] ?? ''
                                        )
                                    ) !== ''
                                ) {

                                    $mailServices[] =
                                        trim(
                                            (string)(
                                                $fallbackService[
                                                    'service_name'
                                                ]
                                            )
                                        );
                                }
                            }

                            $fallbackServiceStmt->close();
                        }
                    }


                    /* ---------------------------------------------
                       Count selected assets
                    --------------------------------------------- */

                    $assetCountForMail = 0;

                    $assetCountStmt =
                        $conn->prepare("
                            SELECT
                                COUNT(*) AS asset_count
                            FROM nleta_offer_assets oa
                            INNER JOIN assets a
                                ON a.id = oa.asset_id
                            WHERE
                                oa.offer_id = ?
                                AND a.owner_id = ?
                        ");

                    if ($assetCountStmt) {

                        $assetCountStmt->bind_param(
                            'ii',
                            $postedOfferId,
                            $ownerId
                        );

                        if ($assetCountStmt->execute()) {

                            $assetCountRow =
                                $assetCountStmt
                                    ->get_result()
                                    ->fetch_assoc();

                            $assetCountForMail =
                                (int)(
                                    $assetCountRow[
                                        'asset_count'
                                    ] ?? 0
                                );
                        }

                        $assetCountStmt->close();
                    }


                    /* ---------------------------------------------
                       Accepted date/time — IST
                    --------------------------------------------- */

                    $acceptedAtForMail =
                        (new DateTime(
                            'now',
                            new DateTimeZone(
                                'Asia/Kolkata'
                            )
                        ))->format(
                            'd-m-Y h:i A'
                        );


                    /* ---------------------------------------------
                       Send Admin notification
                    --------------------------------------------- */

                    $offerAcceptedMailSent =
                        sendNletaOfferAcceptedAdminEmail(
                            (int)$mailOffer['id'],
                            (string)(
                                $mailOffer['request_no']
                                ?? ''
                            ),
                            (string)(
                                $mailOffer['offer_no']
                                ?? ''
                            ),
                            (string)(
                                $mailOffer['psga_no']
                                ?? ''
                            ),
                            (string)(
                                $mailOffer['owner_name']
                                ?? 'Owner'
                            ),
                            (string)(
                                $mailOffer['owner_email']
                                ?? ''
                            ),
                            $mailServices,
                            $assetCountForMail,
                            (float)(
                                $mailOffer['grand_total']
                                ?? 0
                            ),
                            $acceptedAtForMail
                        );

                    if (!$offerAcceptedMailSent) {

                        error_log(
                            'NLETA: Offer accepted but Admin email could not be sent. Offer ID: '
                            . $postedOfferId
                        );
                    }

                } catch (Throwable $mailException) {

                    error_log(
                        'NLETA: Offer accepted, but Admin notification email failed. '
                        . 'Offer ID: '
                        . $postedOfferId
                        . ' | Error: '
                        . $mailException->getMessage()
                    );
                }


                $decisionMessage =
                    $offerAcceptedMailSent
                        ? 'Offer accepted successfully. Payment schedule created and Admin has been notified by email.'
                        : 'Offer accepted successfully. Payment schedule created, but the Admin notification email could not be sent. Please check the mail configuration.';

            } catch (Throwable $e) {

                $conn->rollback();

                $decisionError =
                    $e->getMessage();
            }

        } else {

            /*
             * REJECTED
             *
             * No payment record is created.
             */
            $decisionStmt =
                $conn->prepare("
                    UPDATE nleta_offer_master o
                    SET
                        o.status = ?,
                        o.updated_at = NOW()
                    WHERE
                        o.id = ?
                        AND o.status = 'Sent'
                        AND EXISTS (
                            SELECT 1
                            FROM nleta_offer_assets oa
                            INNER JOIN assets a
                                ON a.id = oa.asset_id
                            WHERE
                                oa.offer_id = o.id
                                AND a.owner_id = ?
                        )
                    LIMIT 1
                ");

            if (!$decisionStmt) {

                $decisionError =
                    'Unable to process Offer decision: '
                    . $conn->error;

            } else {

                $decisionStmt->bind_param(
                    'sii',
                    $decision,
                    $postedOfferId,
                    $ownerId
                );

                if ($decisionStmt->execute()) {

                    if ($decisionStmt->affected_rows === 1) {

                        $decisionMessage =
                            'Offer rejected successfully.';

                    } else {

                        $decisionError =
                            'This Offer is no longer available for a new decision. '
                            . 'Please return to Offers and check its current status.';
                    }

                } else {

                    $decisionError =
                        'Unable to reject Offer: '
                        . $decisionStmt->error;
                }

                $decisionStmt->close();
            }
        }
    }
}

if (isset($_GET['decision'])) {

    $decisionParam =
        strtolower(
            trim(
                (string)$_GET['decision']
            )
        );

    if ($decisionParam === 'accepted') {

        $decisionMessage =
            'Offer accepted successfully.';

    } elseif ($decisionParam === 'rejected') {

        $decisionMessage =
            'Offer rejected successfully.';

    }
}


/* =========================================================
   HELPERS
========================================================= */

function h($value)
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}


function statusClass($status)
{
    switch ($status) {
        case 'Verified':
        case 'Completed':
        case 'Paid':
            return 'status-success';
        case 'Submitted':
            return 'status-info';
        case 'Pending':
            return 'status-warning';
        case 'Rejected':
            return 'status-danger';
        case 'Locked':
        default:
            return 'status-muted';
    }
}

function money($value)
{
    return number_format(
        (float)$value,
        2,
        '.',
        ','
    );
}


/* =========================================================
   LOAD OFFER
   ---------------------------------------------------------
   Security:
   The Offer must:
   1. exist
   2. have status = Sent, Accepted or Rejected
   3. contain at least one asset belonging to this Owner
========================================================= */

if ($offerId <= 0) {

    $error =
        'Invalid Offer.';

} else {

    $offerStmt =
        $conn->prepare("
            SELECT
                o.*
            FROM nleta_offer_master o
            WHERE
                o.id = ?
                AND o.status IN ('Sent', 'Accepted', 'Rejected')
                AND EXISTS (
                    SELECT 1
                    FROM nleta_offer_assets oa
                    INNER JOIN assets a
                        ON a.id = oa.asset_id
                    WHERE
                        oa.offer_id = o.id
                        AND a.owner_id = ?
                )
            LIMIT 1
        ");

    if (!$offerStmt) {

        $error =
            'Unable to load Offer: '
            .
            $conn->error;

    } else {

        $offerStmt->bind_param(
            'ii',
            $offerId,
            $ownerId
        );

        $offerStmt->execute();

        $existingOffer =
            $offerStmt
            ->get_result()
            ->fetch_assoc();

        $offerStmt->close();


        if (!$existingOffer) {

            $error =
                'This Offer is no longer available because its linked asset was deleted or the Offer does not belong to you.';

        }
    }
}


/* =========================================================
   LOAD CLIENT MASTER
   ---------------------------------------------------------
   Use the exact Client_ID stored on the generated Offer.
   This ensures the Owner sees the same client information
   that Admin saw while generating the FSO.
========================================================= */

if (
    $existingOffer
) {

    $clientStmt =
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

    if ($clientStmt) {

        $clientId =
            (int)$existingOffer['client_id'];

        $clientStmt->bind_param(
            'i',
            $clientId
        );

        $clientStmt->execute();

        $client =
            $clientStmt
            ->get_result()
            ->fetch_assoc();

        $clientStmt->close();
    }

    if (!$client) {

        $error =
            'Client Master details could not be loaded for this Offer.';
    }
}


/* =========================================================
   LOAD OFFER ITEMS
   ---------------------------------------------------------
   These are the exact items saved during Offer generation.
   No new pricing calculation is performed.
========================================================= */

if (
    $existingOffer
    &&
    $error === ''
) {

    $itemStmt =
        $conn->prepare("
            SELECT
                *
            FROM nleta_offer_items
            WHERE offer_id = ?
            ORDER BY id ASC
        ");

    if (!$itemStmt) {

        $error =
            'Unable to load Offer Services: '
            .
            $conn->error;

    } else {

        $itemStmt->bind_param(
            'i',
            $offerId
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
   LOAD OFFER ASSETS
   ---------------------------------------------------------
   Used only for Owner-side verification / reference.
   The FSO service table still uses the exact saved
   nleta_offer_items rows.
========================================================= */

if (
    $existingOffer
    &&
    $error === ''
) {

    $assetStmt =
        $conn->prepare("
            SELECT

                a.id,
                a.display_asset_id,
                a.asset_name,
                a.asset_type,
                a.location,

                ad.capacity,
                ad.number_of_floors,
                ad.escalator_type,
                ad.escalator_rise

            FROM nleta_offer_assets oa

            INNER JOIN assets a
                ON a.id = oa.asset_id

            LEFT JOIN asset_details ad
                ON ad.asset_id = a.id

            WHERE
                oa.offer_id = ?
                AND a.owner_id = ?

            ORDER BY
                a.display_asset_id ASC,
                a.id ASC
        ");

    if ($assetStmt) {

        $assetStmt->bind_param(
            'ii',
            $offerId,
            $ownerId
        );

        $assetStmt->execute();

        $assetResult =
            $assetStmt
            ->get_result();

        while (
            $row =
            $assetResult->fetch_assoc()
        ) {

            $offerAssets[] =
                $row;
        }

        $assetStmt->close();
    }
}


/* =========================================================
   PAYMENT MODULE - OWNER PAYMENT OPTIONS
   ---------------------------------------------------------
   Payment record is created when the Offer is Accepted.
   This section only displays the available payment methods
   on the same Offer page. No Offer amount is recalculated.
========================================================= */

$paymentRecord = null;
$paymentScheduleRows = [];

if ($existingOffer) {

    $paymentStmt = $conn->prepare("
        SELECT
            Payment_ID,
            Offer_ID,
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
            'i',
            $offerId
        );

        $paymentStmt->execute();

        $paymentRecord =
            $paymentStmt
                ->get_result()
                ->fetch_assoc();

        $paymentStmt->close();
    }

    if ($paymentRecord) {

        $paymentScheduleStmt = $conn->prepare("
            SELECT
                Schedule_ID,
                Payment_Stage,
                Percentage,
                Expected_Amount,
                Status,
                Due_Trigger
            FROM payment_schedule
            WHERE Payment_ID = ?
            ORDER BY
                CASE Payment_Stage
                    WHEN 'Advance' THEN 1
                    WHEN 'Before Final Checklist' THEN 2
                    WHEN 'Final' THEN 3
                    ELSE 4
                END
        ");

        if ($paymentScheduleStmt) {

            $paymentIdForSchedule =
                (int)$paymentRecord['Payment_ID'];

            $paymentScheduleStmt->bind_param(
                'i',
                $paymentIdForSchedule
            );

            $paymentScheduleStmt->execute();

            $paymentScheduleResult =
                $paymentScheduleStmt->get_result();

            while (
                $scheduleRow =
                    $paymentScheduleResult->fetch_assoc()
            ) {

                $paymentScheduleRows[] =
                    $scheduleRow;
            }

            $paymentScheduleStmt->close();
        }
    }
}


/* =========================================================
   LOAD PAYMENT SETTINGS
========================================================= */

$paymentSettings = null;

$settingsStmt = $conn->prepare("
    SELECT
        id,
        upi_id,
        upi_name,
        qr_code,
        bank_name,
        account_name,
        account_number,
        ifsc,
        branch,
        payment_instructions,
        is_active
    FROM payment_settings
    WHERE is_active = 1
    ORDER BY id ASC
    LIMIT 1
");

if ($settingsStmt) {

    $settingsStmt->execute();

    $paymentSettings =
        $settingsStmt
        ->get_result()
        ->fetch_assoc();

    $settingsStmt->close();
}



/* =========================================================
   LOAD LATEST PAYMENT TRANSACTIONS
========================================================= */

foreach ($paymentScheduleRows as &$scheduleRow) {

    $scheduleRow['latest_transaction'] = null;

    $scheduleIdTmp = (int)($scheduleRow['Schedule_ID'] ?? 0);

    if ($scheduleIdTmp <= 0) {
        continue;
    }

    $transactionStmt = $conn->prepare("
        SELECT
            Transaction_ID,
            Submitted_Amount,
            Payment_Date,
            Transaction_Reference,
            Payment_Method,
            Proof_File,
            Status,
            Rejection_Reason,
            Submitted_At,
            Verified_At
        FROM payment_transactions
        WHERE Schedule_ID = ?
        ORDER BY Transaction_ID DESC
        LIMIT 1
    ");

    if ($transactionStmt) {
        $transactionStmt->bind_param('i', $scheduleIdTmp);
        $transactionStmt->execute();

        $scheduleRow['latest_transaction'] =
            $transactionStmt
                ->get_result()
                ->fetch_assoc();

        $transactionStmt->close();
    }
}
unset($scheduleRow);

/* =========================================================
   PAYMENT DISPLAY STATUS
   ---------------------------------------------------------
   When a payment transaction is verified:
     - STATUS column displays: Paid
     - SUBMISSION displays: Verified
     - ACTION displays: Verified

   The saved database payment values are not recalculated here.
========================================================= */

$allPaymentsCompleted = false;

if (!empty($paymentScheduleRows)) {

    $allPaymentsCompleted = true;

    foreach ($paymentScheduleRows as $completedSchedule) {

        $completedTransaction =
            $completedSchedule['latest_transaction'] ?? null;

        if (
            !$completedTransaction
            ||
            ($completedTransaction['Status'] ?? '') !== 'Verified'
        ) {
            $allPaymentsCompleted = false;
            break;
        }
    }
}

/* =========================================================
   DISPLAY VALUES
========================================================= */

$displaySubtotal =
    $existingOffer
    ? (float)$existingOffer['subtotal']
    : 0;

$displayGST =
    $existingOffer
    ? (float)$existingOffer['gst_amount']
    : 0;

$displayGrandTotal =
    $existingOffer
    ? (float)$existingOffer['grand_total']
    : 0;

$displayAdvance =
    $existingOffer
    ? (float)$existingOffer['payment_advance']
    : 0;

$displayServiceActivation =
    $existingOffer
    ? (float)$existingOffer['payment_service_activation']
    : 0;

$displayFinal =
    $existingOffer
    ? (float)$existingOffer['payment_final']
    : 0;


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
   HEADER / SIDEBAR
========================================================= */

include('../includes/header.php');

include('../includes/sidebar-owner.php');

?>

<style>

/* =========================================================
   OWNER SCREEN
========================================================= */

.offer-owner-page {

    width: 100%;

    min-height:
        calc(100vh - 65px);

    padding: 24px;

    background: #f4f6f9;

}


.offer-owner-topbar {

    background: #ffffff;

    border-radius: 12px;

    border: 1px solid #e3e6ea;

    padding: 18px 20px;

    margin-bottom: 20px;

    display: flex;

    justify-content: space-between;

    align-items: center;

    gap: 15px;

    flex-wrap: wrap;

}


.offer-owner-topbar h2 {

    margin: 0;

    font-size: 22px;

    font-weight: 700;

}


.offer-owner-topbar p {

    margin: 4px 0 0;

    color: #6c757d;

    font-size: 12px;

}


.offer-actions {

    display: flex;

    gap: 8px;

    flex-wrap: wrap;

}


/* =========================================================
   FSO PAPER
========================================================= */

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


    .fso-service-table td:first-child {

        text-align: left;
    }


    .fso-service-table td:not(:first-child) {

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





/* =========================================================
   OWNER OFFER DECISION
========================================================= */

.owner-offer-decision {

    background: #ffffff;

    border:
        1px solid #dfe3e8;

    border-radius: 10px;

    padding: 16px 18px;

    margin-bottom: 18px;

    box-shadow:
        0 2px 10px rgba(0,0,0,.05);

}

.owner-offer-decision-title {

    font-size: 16px;

    font-weight: 700;

    margin-bottom: 5px;

}

.owner-offer-decision-text {

    color: #6c757d;

    font-size: 13px;

    margin-bottom: 12px;

}

.owner-offer-decision-actions {

    display: flex;

    gap: 10px;

    flex-wrap: wrap;

}


/* =========================================================
   ERROR / INFO
========================================================= */

.owner-offer-error {

    background: #fee2e2;

    color: #991b1b;

    border:
        1px solid #fecaca;

    border-radius: 8px;

    padding: 13px 15px;

    margin-bottom: 18px;

}


.owner-offer-info {

    background: #dcfce7;

    color: #166534;

    border:
        1px solid #bbf7d0;

    border-radius: 8px;

    padding: 13px 15px;

    margin-bottom: 18px;

}


/* =========================================================
   PAYMENT CARD
========================================================= */

.payment-card {

    background: #ffffff;
    border: 1px solid #e1e5e9;
    border-radius: 14px;
    margin-bottom: 22px;
    overflow: hidden;
    box-shadow: 0 4px 18px rgba(0,0,0,.06);
}


.payment-card-header {

    padding: 18px 20px;
    border-bottom: 1px solid #e9ecef;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 15px;
    flex-wrap: wrap;
}


.offer-number {

    font-size: 17px;
    font-weight: 700;
    color: #0d6efd;
}


.psga-number {

    margin-top: 4px;
    color: #495057;
    font-size: 13px;
}


.payment-card-body {

    padding: 20px;
}


/* =========================================================
   SUMMARY
========================================================= */

.summary-grid {

    display: grid;
    grid-template-columns: repeat(4, minmax(150px, 1fr));
    gap: 12px;
    margin-bottom: 22px;
}


.summary-box {

    background: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 10px;
    padding: 13px 15px;
}


.summary-label {

    color: #6c757d;
    font-size: 12px;
    margin-bottom: 4px;
}


.summary-value {

    font-size: 16px;
    font-weight: 700;
}


/* =========================================================
   PAYMENT METHOD AREA
========================================================= */

.payment-method-box {

    background: #f8fbff;
    border: 1px solid #cfe2ff;
    border-radius: 12px;
    padding: 18px;
    margin-bottom: 20px;
}


.payment-method-title {

    font-size: 16px;
    font-weight: 700;
    color: #084298;
    margin-bottom: 14px;
}


.method-grid {

    display: grid;
    grid-template-columns: repeat(
        4,
        minmax(130px, 1fr)
    );

    gap: 10px;
}


.method-option {

    position: relative;
}


.method-option input {

    position: absolute;
    opacity: 0;
}


.method-option label {

    display: block;
    cursor: pointer;
    background: #ffffff;
    border: 1px solid #dee2e6;
    border-radius: 9px;
    padding: 12px;
    text-align: center;
    font-size: 13px;
    font-weight: 600;
    transition: .2s ease;
}


.method-option label:hover {

    border-color: #86b7fe;
}


.method-option input:checked + label {

    border-color: #0d6efd;
    background: #eaf2ff;
    color: #0d6efd;
}


.method-icon {

    display: block;
    font-size: 22px;
    margin-bottom: 5px;
}


/* =========================================================
   PAYMENT DETAILS
========================================================= */

.payment-details {

    margin-top: 15px;
    display: none;
}


.detail-panel {

    background: #ffffff;
    border: 1px solid #e1e5e9;
    border-radius: 9px;
    padding: 15px;
}


.detail-title {

    font-weight: 700;
    margin-bottom: 10px;
}


.detail-row {

    display: flex;
    justify-content: space-between;
    gap: 15px;
    padding: 7px 0;
    border-bottom: 1px solid #f0f0f0;
    font-size: 13px;
}


.detail-row:last-child {

    border-bottom: 0;
}


.detail-label {

    color: #6c757d;
}


.detail-value {

    font-weight: 600;
    text-align: right;
    word-break: break-word;
}


.qr-container {

    text-align: center;
    margin-top: 12px;
}


.qr-container img {

    width: 190px;
    height: 190px;
    object-fit: contain;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 5px;
    background: #ffffff;
}


/* =========================================================
   SCHEDULE
========================================================= */

.schedule-title {

    font-size: 16px;
    font-weight: 700;
    margin-bottom: 12px;
}


.schedule-wrap {

    width: 100%;
    overflow-x: auto;
}


.schedule-table {

    width: 100%;
    min-width: 1150px;
    border-collapse: collapse;
}


.schedule-table th {

    background: #f8f9fa;
    color: #495057;
    font-size: 12px;
    text-transform: uppercase;
    padding: 11px 12px;
    border-bottom: 1px solid #dee2e6;
}


.schedule-table td {

    padding: 13px 12px;
    border-bottom: 1px solid #edf0f2;
    vertical-align: top;
}


.stage-name {

    font-weight: 700;
}


.stage-amount {

    font-weight: 700;
}


/* =========================================================
   STATUS
========================================================= */

.payment-status {

    display: inline-flex;
    align-items: center;
    padding: 5px 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
}


.status-success {

    background: #dcfce7;
    color: #166534;
}


.status-info {

    background: #dbeafe;
    color: #1d4ed8;
}


.status-warning {

    background: #fef3c7;
    color: #92400e;
}


.status-danger {

    background: #fee2e2;
    color: #991b1b;
}


.status-muted {

    background: #e9ecef;
    color: #495057;
}


/* =========================================================
   SUBMISSION
========================================================= */

.submission-box {

    margin-top: 7px;
    padding: 8px 10px;
    border-radius: 7px;
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    font-size: 11px;
}


.rejection-box {

    margin-top: 8px;
    padding: 9px 10px;
    background: #fff1f2;
    color: #991b1b;
    border: 1px solid #fecdd3;
    border-radius: 7px;
    font-size: 11px;
}


/* =========================================================
   FORM
========================================================= */

.payment-form {

    margin-top: 12px;
    padding: 15px;
    background: #f8fbff;
    border: 1px solid #cfe2ff;
    border-radius: 10px;
    min-width: 350px;
}


.form-title {

    font-size: 13px;
    font-weight: 700;
    color: #084298;
    margin-bottom: 12px;
}


.form-label {

    display: block;
    font-size: 11px;
    font-weight: 600;
    margin-bottom: 4px;
}


.form-control {

    width: 100%;
    border: 1px solid #ced4da;
    border-radius: 6px;
    padding: 8px 9px;
    font-size: 12px;
    background: #ffffff;
}


.amount-display {

    background: #ffffff;
    border: 1px solid #dbeafe;
    border-radius: 7px;
    padding: 9px 10px;
    color: #0d6efd;
    font-size: 17px;
    font-weight: 700;
}


.form-row {

    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    margin-bottom: 10px;
}


.form-group {

    margin-bottom: 10px;
}


.form-help {

    font-size: 12px;
    color: #6c757d;
    margin-top: 4px;
}


.submit-btn {

    border: 0;
    background: #198754;
    color: #ffffff;
    border-radius: 7px;
    padding: 8px 14px;
    font-size: 12px;
    font-weight: 600;
}


.submit-btn:hover {

    background: #157347;
}


.cancel-btn {

    border: 1px solid #adb5bd;
    background: #ffffff;
    color: #495057;
    border-radius: 7px;
    padding: 8px 14px;
    font-size: 12px;
}


/* =========================================================
   ACTION
========================================================= */

.action-btn {

    border: 0;
    border-radius: 7px;
    padding: 8px 13px;
    background: #0d6efd;
    color: #ffffff;
    font-size: 12px;
    font-weight: 600;
}


.action-btn:hover {

    background: #0b5ed7;
}


/* =========================================================
   EMPTY
========================================================= */

.empty-state {

    background: #ffffff;
    border-radius: 14px;
    padding: 60px 20px;
    text-align: center;
    box-shadow: 0 4px 18px rgba(0,0,0,.06);
}


.empty-state i {

    font-size: 50px;
    color: #6c757d;
}




/* =========================================================
   RESPONSIVE — TABLET + MOBILE
   ---------------------------------------------------------
   Desktop layout remains unchanged.
   FSO paper keeps its A4 dimensions and scrolls horizontally
   on small screens so the official document format is not
   distorted. Payment / decision UI becomes responsive.
========================================================= */

@media (max-width: 1199.98px) {

    .offer-owner-page {
        padding: 18px;
    }

    .summary-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .method-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .payment-card-header {
        align-items: center;
    }

    .payment-card-body {
        padding: 18px;
    }

    .payment-method-box {
        padding: 16px;
    }

    .schedule-wrap {
        width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    .schedule-table {
        min-width: 1050px;
    }

    .payment-form {
        min-width: 0;
        width: 100%;
        box-sizing: border-box;
    }
}


@media (max-width: 768px) {

    .offer-owner-page {
        padding: 10px;
    }

    .offer-owner-topbar {
        padding: 14px;
        align-items: flex-start;
    }

    .offer-owner-topbar h2 {
        font-size: 18px;
    }

    .offer-owner-topbar p {
        font-size: 11px;
    }

    .offer-actions {
        width: 100%;
    }

    .offer-actions .btn {
        flex: 1 1 auto;
    }

    .owner-offer-decision {
        padding: 14px;
    }

    .owner-offer-decision-actions {
        width: 100%;
    }

    .owner-offer-decision-actions form {
        flex: 1 1 0;
    }

    .owner-offer-decision-actions .btn {
        width: 100%;
    }

    .payment-card {
        border-radius: 10px;
    }

    .payment-card-header {
        padding: 14px;
        flex-direction: column;
        align-items: flex-start;
    }

    .payment-card-body {
        padding: 14px;
    }

    .summary-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
        margin-bottom: 16px;
    }

    .summary-box {
        padding: 11px 10px;
        min-width: 0;
    }

    .summary-label {
        font-size: 11px;
    }

    .summary-value {
        font-size: 14px;
        overflow-wrap: anywhere;
    }

    .payment-method-box {
        padding: 12px;
    }

    .payment-method-title {
        font-size: 14px;
    }

    .method-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
    }

    .method-option label {
        padding: 10px 6px;
        font-size: 12px;
        min-height: 74px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
    }

    .method-icon {
        font-size: 20px;
    }

    .detail-panel {
        padding: 12px;
    }

    .detail-row {
        gap: 10px;
        align-items: flex-start;
    }

    .detail-label {
        flex: 0 0 38%;
    }

    .detail-value {
        flex: 1;
        min-width: 0;
        overflow-wrap: anywhere;
    }

    .qr-container img {
        width: min(190px, 70vw);
        height: auto;
        aspect-ratio: 1 / 1;
    }

    .schedule-title {
        font-size: 15px;
        margin-top: 16px;
    }

    .schedule-wrap {
        margin-left: 0;
        margin-right: 0;
        border: 1px solid #e9ecef;
        border-radius: 8px;
    }

    .schedule-table {
        min-width: 1000px;
        font-size: 12px;
    }

    .schedule-table th {
        padding: 9px 10px;
        font-size: 11px;
    }

    .schedule-table td {
        padding: 10px;
    }

    .payment-form {
        margin-top: 10px;
        padding: 12px;
    }

    .form-row {
        grid-template-columns: 1fr;
        gap: 0;
    }

    .form-control {
        min-width: 0;
        box-sizing: border-box;
    }

    .payment-form .d-flex {
        flex-wrap: wrap;
    }

    .payment-form .submit-btn,
    .payment-form .cancel-btn {
        flex: 1 1 140px;
    }

    .fso-paper-wrap {
        width: 100%;
        max-width: 100%;
        overflow-x: auto;
        overflow-y: hidden;
        -webkit-overflow-scrolling: touch;
        padding-bottom: 12px;
    }

    .fso-paper {
        width: 210mm;
        min-width: 210mm;
        margin-left: 0;
        margin-right: 0;
    }

    .fso-page {
        flex-shrink: 0;
    }

    .owner-offer-info,
    .owner-offer-error {
        padding: 11px 12px;
        font-size: 12px;
    }
}



/* Payment Schedule — Action is the first column */
.schedule-table th:first-child,
.schedule-table td:first-child {
    min-width: 145px;
}

@media (max-width: 575.98px) {

    .offer-owner-page {
        padding: 8px;
    }

    .offer-owner-topbar {
        padding: 12px;
    }

    .offer-owner-topbar h2 {
        font-size: 16px;
    }

    .offer-actions {
        display: grid;
        grid-template-columns: 1fr 1fr;
        width: 100%;
    }

    .offer-actions .btn {
        width: 100%;
        font-size: 12px;
        padding: 7px 8px;
    }

    .owner-offer-decision-title {
        font-size: 15px;
    }

    .owner-offer-decision-text {
        font-size: 12px;
    }

    .summary-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .summary-box {
        padding: 10px 8px;
    }

    .summary-label {
        font-size: 10px;
    }

    .summary-value {
        font-size: 13px;
    }

    .method-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .method-option label {
        min-height: 68px;
        font-size: 11px;
    }

    .detail-row {
        flex-direction: column;
        gap: 2px;
    }

    .detail-label,
    .detail-value {
        width: 100%;
        flex: none;
        text-align: left;
    }

    .form-row {
        grid-template-columns: 1fr;
    }

    .payment-form .submit-btn,
    .payment-form .cancel-btn {
        width: 100%;
        flex: 1 1 100%;
    }

    .schedule-table {
        min-width: 960px;
    }

    .payment-card-header .payment-status {
        font-size: 11px;
    }

    .fso-paper-wrap {
        padding-bottom: 8px;
    }
}


@media (max-width: 380px) {

    .summary-grid {
        gap: 6px;
    }

    .summary-box {
        padding: 9px 7px;
    }

    .summary-value {
        font-size: 12px;
    }

    .method-option label {
        padding: 8px 4px;
        min-height: 64px;
    }

    .method-icon {
        font-size: 18px;
    }

    .offer-actions {
        grid-template-columns: 1fr;
    }
}


/* =========================================================
   MOBILE
========================================================= */

@media (max-width: 992px) {

    .offer-owner-page {

        padding: 16px;

    }

}


@media (max-width: 768px) {

    .offer-owner-page {

        padding: 10px;

    }

    .offer-owner-topbar {

        padding: 14px;

    }

    .offer-owner-topbar h2 {

        font-size: 17px;

    }

    .fso-paper {

        width: 210mm;

        margin-left: 0;

        margin-right: 0;

    }

    .fso-paper-wrap {

        width: 100%;

        overflow-x: auto;

        -webkit-overflow-scrolling: touch;

        padding-bottom: 10px;

    }

}





/* =========================================================
   SINGLE A4 PAGE PRINT LAYOUT
   Existing data/content only - no database changes
========================================================= */



/* =========================================================
   OWNER FSO - FINAL PRINT FORMAT
   Exactly 2 A4 pages, no extra blank pages.
========================================================= */



/* =========================================================
   OWNER — ADMIN FSO FORMAT OVERRIDE
   The FSO paper itself intentionally matches Admin exactly.
========================================================= */

.fso-paper-wrap {
    width: 100%;
    margin: 0;
    padding: 0;
}




/* =========================================================
   PRINT — FSO ONLY
   IMPORTANT: Print target is #fsoPrintArea.
   Screen/payment/sidebar UI must never be printed.
========================================================= */
@media print {

    @page {
        size: A4 portrait;
        margin: 0;
    }

    html,
    body {
        width: 210mm !important;
        margin: 0 !important;
        padding: 0 !important;
        background: #ffffff !important;
    }

    /* Hide everything first */
    body * {
        visibility: hidden !important;
    }

    /* Show ONLY the FSO and its children */
    #fsoPrintArea,
    #fsoPrintArea * {
        visibility: visible !important;
    }

    #fsoPrintArea {
        display: block !important;
        position: absolute !important;
        left: 0 !important;
        top: 0 !important;
        width: 210mm !important;
        margin: 0 !important;
        padding: 0 !important;
        background: #ffffff !important;
        overflow: visible !important;
    }

    #fsoPrintArea .fso-paper {
        display: block !important;
        width: 210mm !important;
        margin: 0 !important;
        padding: 0 !important;
        background: #ffffff !important;
        box-shadow: none !important;
        overflow: visible !important;
    }

    /* Each .fso-page is exactly one physical A4 sheet */
    #fsoPrintArea .fso-page {
        position: relative !important;
        display: block !important;
        width: 210mm !important;
        height: 297mm !important;
        min-height: 297mm !important;
        max-height: 297mm !important;
        margin: 0 !important;
        padding: 10mm 12mm 14mm !important;
        box-sizing: border-box !important;
        background: #ffffff !important;
        box-shadow: none !important;
        overflow: hidden !important;

        page-break-before: auto !important;
        page-break-after: always !important;
        page-break-inside: avoid !important;

        break-before: auto !important;
        break-after: page !important;
        break-inside: avoid !important;
    }

    #fsoPrintArea .fso-page:last-child {
        page-break-after: auto !important;
        break-after: auto !important;
    }

    /* Keep Admin FSO typography/layout intact */
    #fsoPrintArea .fso-header {
        margin-bottom: 5px !important;
    }

    #fsoPrintArea .fso-footer {
        position: absolute !important;
        left: 0 !important;
        right: 0 !important;
        bottom: 8mm !important;
    }

    /* Never let screen-only controls consume print space */
    .screen-only,
    .offer-owner-topbar,
    .owner-offer-decision,
    .payment-card,
    .owner-offer-info,
    .owner-offer-error {
        display: none !important;
    }
}

</style>


<div class="content">

<div class="offer-owner-page">


    <!-- =================================================
         TOPBAR
    ================================================== -->

    <div
        class="offer-owner-topbar
               screen-only"
    >

        <div>

            <h2>

                <i
                    class="bi bi-file-earmark-text me-2"
                ></i>

                Financial & Service Offer

            </h2>

            <p>

                Offer received from NLETA

            </p>

        </div>


        <?php if (
            $existingOffer
        ): ?>

            <div
                class="offer-actions"
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

        <?php endif; ?>

    </div>


    <?php if (
        $decisionError !== ''
    ): ?>

        <div
            class="owner-offer-error
                   screen-only"
        >

            <?= h($decisionError) ?>

        </div>

    <?php endif; ?>


    <?php if (
        $decisionMessage !== ''
    ): ?>

        <div
            class="owner-offer-info
                   screen-only"
        >

            <i
                class="bi bi-check-circle-fill me-2"
            ></i>

            <?= h($decisionMessage) ?>

        </div>

    <?php endif; ?>


    <?php if (
        $existingOffer
        &&
        ($existingOffer['status'] ?? '') === 'Sent'
    ): ?>

        <div
            class="owner-offer-decision
                   screen-only"
        >

            <div class="owner-offer-decision-title">
                Offer Decision
            </div>

            <div class="owner-offer-decision-text">
                Please review the complete FSO above and choose
                whether you accept or reject this Offer.
            </div>

            <div class="owner-offer-decision-actions">

                <form
                    method="POST"
                    action="offer-view.php?id=<?= (int)$offerId ?>"
                    class="m-0"
                >

                    <input
                        type="hidden"
                        name="offer_id"
                        value="<?= (int)$offerId ?>"
                    >

                    <input
                        type="hidden"
                        name="decision"
                        value="Accepted"
                    >

                    <button
                        type="submit"
                        class="btn btn-success"
                        onclick="return confirm('Are you sure you want to accept this Offer?');"
                    >

                        <i class="bi bi-check-circle me-1"></i>

                        Accept Offer

                    </button>

                </form>


                <form
                    method="POST"
                    action="offer-view.php?id=<?= (int)$offerId ?>"
                    class="m-0"
                >

                    <input
                        type="hidden"
                        name="offer_id"
                        value="<?= (int)$offerId ?>"
                    >

                    <input
                        type="hidden"
                        name="decision"
                        value="Rejected"
                    >

                    <button
                        type="submit"
                        class="btn btn-danger"
                        onclick="return confirm('Are you sure you want to reject this Offer?');"
                    >

                        <i class="bi bi-x-circle me-1"></i>

                        Reject Offer

                    </button>

                </form>

            </div>

        </div>

    <?php endif; ?>


    <?php
    /*
     * =========================================================
     * PAYMENT OPTIONS
     * ---------------------------------------------------------
     * Visible only after the Offer has been accepted and the
     * Phase 7 payment record exists.
     * =========================================================
     */
    ?>


        <?php if (
            $existingOffer
            &&
            $error === ''
            &&
            $paymentRecord
        ): ?>

        <div class="payment-card screen-only">

            <div class="payment-card-header">

                <div>
                    <div class="offer-number">
                        <?= h($existingOffer['offer_no']) ?>
                    </div>

                    <div class="psga-number">
                        <strong>PSGA:</strong>
                        <?= h($existingOffer['psga_no']) ?>
                    </div>
                </div>

                <div>
                    <?php
                    $overallPaymentStatusDisplay =
                        $allPaymentsCompleted
                            ? 'Paid'
                            : ($paymentRecord['Payment_Status'] ?? 'Pending');
                    ?>
                    <span class="payment-status <?= h(statusClass($overallPaymentStatusDisplay)) ?>">
                        <?= h($overallPaymentStatusDisplay) ?>
                    </span>
                </div>

            </div>

            <div class="payment-card-body">

                <div class="summary-grid">

                    <div class="summary-box">
                        <div class="summary-label">Total Amount</div>
                        <div class="summary-value">
                            ₹<?= money($paymentRecord['Total_Amount']) ?>
                        </div>
                    </div>

                    <div class="summary-box">
                        <div class="summary-label">Received</div>
                        <div class="summary-value">
                            ₹<?= money($paymentRecord['Received_Amount']) ?>
                        </div>
                    </div>

                    <div class="summary-box">
                        <div class="summary-label">Balance</div>
                        <div class="summary-value">
                            ₹<?= money($paymentRecord['Balance_Amount']) ?>
                        </div>
                    </div>

                    <div class="summary-box">
                        <div class="summary-label">Assets</div>
                        <div class="summary-value">
                            <?= count($offerAssets) ?>
                        </div>
                    </div>

                </div>

                    <!-- =================================================
                         PAYMENT METHODS
                    ================================================== -->

                    <?php if (
                        $paymentSettings
                    ): ?>

                        <div class="payment-method-box">

                            <div class="payment-method-title">

                                <i class="bi bi-wallet2 me-1"></i>

                                Choose Payment Method

                            </div>


                            <div class="method-grid">


                                <div class="method-option">

                                    <input
                                        type="radio"
                                        id="upi-<?= (int)$paymentRecord['Payment_ID'] ?>"
                                        name="method_preview_<?= (int)$paymentRecord['Payment_ID'] ?>"
                                        value="UPI"
                                        onchange="showPaymentDetails(
                                            <?= (int)$paymentRecord['Payment_ID'] ?>,
                                            'upi'
                                        )"
                                    >

                                    <label
                                        for="upi-<?= (int)$paymentRecord['Payment_ID'] ?>"
                                    >

                                        <span class="method-icon">
                                            <i class="bi bi-phone"></i>
                                        </span>

                                        UPI

                                    </label>

                                </div>


                                <div class="method-option">

                                    <input
                                        type="radio"
                                        id="qr-<?= (int)$paymentRecord['Payment_ID'] ?>"
                                        name="method_preview_<?= (int)$paymentRecord['Payment_ID'] ?>"
                                        value="QR"
                                        onchange="showPaymentDetails(
                                            <?= (int)$paymentRecord['Payment_ID'] ?>,
                                            'qr'
                                        )"
                                    >

                                    <label
                                        for="qr-<?= (int)$paymentRecord['Payment_ID'] ?>"
                                    >

                                        <span class="method-icon">
                                            <i class="bi bi-qr-code"></i>
                                        </span>

                                        QR Code

                                    </label>

                                </div>


                                <div class="method-option">

                                    <input
                                        type="radio"
                                        id="bank-<?= (int)$paymentRecord['Payment_ID'] ?>"
                                        name="method_preview_<?= (int)$paymentRecord['Payment_ID'] ?>"
                                        value="Bank Transfer"
                                        onchange="showPaymentDetails(
                                            <?= (int)$paymentRecord['Payment_ID'] ?>,
                                            'bank'
                                        )"
                                    >

                                    <label
                                        for="bank-<?= (int)$paymentRecord['Payment_ID'] ?>"
                                    >

                                        <span class="method-icon">
                                            <i class="bi bi-bank"></i>
                                        </span>

                                        Bank Transfer

                                    </label>

                                </div>


                                <div class="method-option">

                                    <input
                                        type="radio"
                                        id="other-<?= (int)$paymentRecord['Payment_ID'] ?>"
                                        name="method_preview_<?= (int)$paymentRecord['Payment_ID'] ?>"
                                        value="Other"
                                        onchange="showPaymentDetails(
                                            <?= (int)$paymentRecord['Payment_ID'] ?>,
                                            'other'
                                        )"
                                    >

                                    <label
                                        for="other-<?= (int)$paymentRecord['Payment_ID'] ?>"
                                    >

                                        <span class="method-icon">
                                            <i class="bi bi-three-dots"></i>
                                        </span>

                                        Other

                                    </label>

                                </div>


                            </div>


                            <!-- =================================================
                                 UPI DETAILS
                            ================================================== -->

                            <div
                                id="upi-details-<?= (int)$paymentRecord['Payment_ID'] ?>"
                                class="payment-details"
                            >

                                <div class="detail-panel">

                                    <div class="detail-title">

                                        UPI Payment

                                    </div>


                                    <div class="detail-row">

                                        <span class="detail-label">
                                            UPI ID
                                        </span>

                                        <span class="detail-value">

                                            <?= h(
                                                $paymentSettings[
                                                    'upi_id'
                                                ]
                                            ) ?>

                                        </span>

                                    </div>


                                    <div class="detail-row">

                                        <span class="detail-label">
                                            UPI Name
                                        </span>

                                        <span class="detail-value">

                                            <?= h(
                                                $paymentSettings[
                                                    'upi_name'
                                                ]
                                            ) ?>

                                        </span>

                                    </div>

                                </div>

                            </div>


                            <!-- =================================================
                                 QR DETAILS
                            ================================================== -->

                            <div
                                id="qr-details-<?= (int)$paymentRecord['Payment_ID'] ?>"
                                class="payment-details"
                            >

                                <div class="detail-panel">

                                    <div class="detail-title">

                                        Scan QR Code

                                    </div>


                                    <?php if (
                                        !empty(
                                            $paymentSettings[
                                                'qr_code'
                                            ]
                                        )
                                    ): ?>

                                        <div class="qr-container">

                                            <img
                                                src="../<?= h(
                                                    $paymentSettings[
                                                        'qr_code'
                                                    ]
                                                ) ?>"
                                                alt="NLETA Payment QR"
                                            >

                                        </div>

                                    <?php else: ?>

                                        <div class="text-muted">

                                            QR code has not been uploaded yet.

                                        </div>

                                    <?php endif; ?>

                                </div>

                            </div>


                            <!-- =================================================
                                 BANK DETAILS
                            ================================================== -->

                            <div
                                id="bank-details-<?= (int)$paymentRecord['Payment_ID'] ?>"
                                class="payment-details"
                            >

                                <div class="detail-panel">

                                    <div class="detail-title">

                                        Bank Transfer Details

                                    </div>


                                    <div class="detail-row">

                                        <span class="detail-label">
                                            Bank
                                        </span>

                                        <span class="detail-value">

                                            <?= h(
                                                $paymentSettings[
                                                    'bank_name'
                                                ]
                                            ) ?>

                                        </span>

                                    </div>


                                    <div class="detail-row">

                                        <span class="detail-label">
                                            Account Name
                                        </span>

                                        <span class="detail-value">

                                            <?= h(
                                                $paymentSettings[
                                                    'account_name'
                                                ]
                                            ) ?>

                                        </span>

                                    </div>


                                    <div class="detail-row">

                                        <span class="detail-label">
                                            Account Number
                                        </span>

                                        <span class="detail-value">

                                            <?= h(
                                                $paymentSettings[
                                                    'account_number'
                                                ]
                                            ) ?>

                                        </span>

                                    </div>


                                    <div class="detail-row">

                                        <span class="detail-label">
                                            IFSC
                                        </span>

                                        <span class="detail-value">

                                            <?= h(
                                                $paymentSettings[
                                                    'ifsc'
                                                ]
                                            ) ?>

                                        </span>

                                    </div>


                                    <div class="detail-row">

                                        <span class="detail-label">
                                            Branch
                                        </span>

                                        <span class="detail-value">

                                            <?= h(
                                                $paymentSettings[
                                                    'branch'
                                                ]
                                            ) ?>

                                        </span>

                                    </div>

                                </div>

                            </div>


                            <!-- =================================================
                                 OTHER
                            ================================================== -->

                            <div
                                id="other-details-<?= (int)$paymentRecord['Payment_ID'] ?>"
                                class="payment-details"
                            >

                                <div class="detail-panel">

                                    <div class="detail-title">

                                        Other / Manual Payment

                                    </div>

                                    <div>

                                        Please contact NLETA
                                        for payment instructions.

                                    </div>

                                </div>

                            </div>


                            <?php if (
                                !empty(
                                    $paymentSettings[
                                        'payment_instructions'
                                    ]
                                )
                            ): ?>

                                <div class="mt-3 text-muted"
                                     style="font-size:12px;">

                                    <strong>
                                        Instructions:
                                    </strong>

                                    <?= nl2br(
                                        h(
                                            $paymentSettings[
                                                'payment_instructions'
                                            ]
                                        )
                                    ) ?>

                                </div>

                            <?php endif; ?>

                        </div>

                    <?php endif; ?>



                    <!-- =================================================
                         PAYMENT SCHEDULE
                    ================================================== -->

                    <div class="schedule-title">

                        Payment Schedule

                    </div>


                    <div class="schedule-wrap">

                        <table class="schedule-table">

                            <thead>

                                <tr>

                                    <th>
                                        Action
                                    </th>

<th>
                                        Stage
                                    </th>

                                    <th>
                                        %
                                    </th>

                                    <th>
                                        Expected Amount
                                    </th>

                                    <th>
                                        Due Trigger
                                    </th>

                                    <th>
                                        Status
                                    </th>

                                    <th>
                                        Submission
                                    </th>

                                    

                                </tr>

                            </thead>


                            <tbody>


                            <?php foreach (
                                $paymentScheduleRows
                                as $schedule
                            ): ?>


                                <?php

                                $transaction =
                                    $schedule[
                                        'latest_transaction'
                                    ];


                                $canSubmit =
                                    $schedule[
                                        'Status'
                                    ] === 'Pending';


                                if (
                                    $transaction
                                    &&
                                    in_array(
                                        $transaction[
                                            'Status'
                                        ],
                                        [
                                            'Submitted',
                                            'Verified'
                                        ],
                                        true
                                    )
                                ) {

                                    $canSubmit = false;
                                }


                                if (
                                    $transaction
                                    &&
                                    $transaction[
                                        'Status'
                                    ] === 'Rejected'
                                    &&
                                    $schedule[
                                        'Status'
                                    ] === 'Pending'
                                ) {

                                    $canSubmit = true;
                                }

                                ?>


                                <tr>

<td>


                                        <?php if (
                                            $canSubmit
                                        ): ?>


                                            <button
                                                type="button"
                                                class="action-btn"
                                                onclick="showPaymentForm(
                                                    <?= (int)
                                                        $schedule[
                                                            'Schedule_ID'
                                                        ]
                                                    ?>
                                                )"
                                            >

                                                <i
                                                    class="bi bi-upload me-1">
                                                </i>

                                                <?php if (
                                                    $transaction
                                                    &&
                                                    $transaction[
                                                        'Status'
                                                    ] === 'Rejected'
                                                ): ?>

                                                    Resubmit

                                                <?php else: ?>

                                                    Submit Payment

                                                <?php endif; ?>

                                            </button>


                                            <!-- =================================
                                                 SUBMISSION FORM
                                            ================================== -->

                                            <div
                                                id="payment-form-<?= (int)
                                                    $schedule[
                                                        'Schedule_ID'
                                                    ]
                                                ?>"
                                                class="payment-form"
                                                style="display:none;"
                                            >

                                                <div class="form-title">

                                                    Submit
                                                    <?= h(
                                                        $schedule[
                                                            'Payment_Stage'
                                                        ]
                                                    ) ?>
                                                    Payment

                                                </div>


                                                <form
                                                    method="POST"
                                                    action="payments.php"
                                                    enctype="multipart/form-data"
                                                    onsubmit="return confirmSubmission();"
                                                >


                                                    <input
                                                        type="hidden"
                                                        name="submit_payment"
                                                        value="1"
                                                    >


                                                    <input
                                                        type="hidden"
                                                        name="schedule_id"
                                                        value="<?= (int)
                                                            $schedule[
                                                                'Schedule_ID'
                                                            ]
                                                        ?>"
                                                    >


                                                    <!-- =========================
                                                         METHOD
                                                    ========================== -->

                                                    <div
                                                        class="form-group"
                                                    >

                                                        <label
                                                            class="form-label"
                                                        >

                                                            Payment Method

                                                        </label>


                                                        <select
                                                            name="payment_method"
                                                            class="form-control"
                                                            required
                                                        >

                                                            <option value="">
                                                                Select Payment Method
                                                            </option>

                                                            <option value="UPI">
                                                                UPI
                                                            </option>

                                                            <option value="QR">
                                                                QR Code
                                                            </option>

                                                            <option value="Bank Transfer">
                                                                Bank Transfer
                                                            </option>

                                                            <option value="Other">
                                                                Other / Manual
                                                            </option>

                                                        </select>

                                                    </div>


                                                    <!-- =========================
                                                         AMOUNT
                                                    ========================== -->

                                                    <div
                                                        class="form-group"
                                                    >

                                                        <label
                                                            class="form-label"
                                                        >

                                                            Payment Amount

                                                        </label>


                                                        <div
                                                            class="amount-display"
                                                        >

                                                            ₹<?= money(
                                                                $schedule[
                                                                    'Expected_Amount'
                                                                ]
                                                            ) ?>

                                                        </div>


                                                        <div
                                                            class="form-help"
                                                        >

                                                            This amount is fixed
                                                            from the approved
                                                            payment schedule.

                                                        </div>

                                                    </div>


                                                    <!-- =========================
                                                         REFERENCE + DATE
                                                    ========================== -->

                                                    <div
                                                        class="form-row"
                                                    >

                                                        <div>

                                                            <label
                                                                class="form-label"
                                                            >

                                                                UTR /
                                                                Transaction Reference

                                                            </label>


                                                            <input
                                                                type="text"
                                                                name="transaction_reference"
                                                                class="form-control"
                                                                maxlength="150"
                                                                required
                                                                placeholder="Enter UTR / transaction number"
                                                            >

                                                        </div>


                                                        <div>

                                                            <label
                                                                class="form-label"
                                                            >

                                                                Payment Date

                                                            </label>


                                                            <input
                                                                type="date"
                                                                name="payment_date"
                                                                class="form-control"
                                                                max="<?= h(
                                                                    date(
                                                                        'Y-m-d'
                                                                    )
                                                                ) ?>"
                                                                value="<?= h(
                                                                    date(
                                                                        'Y-m-d'
                                                                    )
                                                                ) ?>"
                                                                required
                                                            >

                                                        </div>

                                                    </div>


                                                    <!-- =========================
                                                         PROOF
                                                    ========================== -->

                                                    <div
                                                        class="form-group"
                                                    >

                                                        <label
                                                            class="form-label"
                                                        >

                                                            Payment Proof

                                                        </label>


                                                        <input
                                                            type="file"
                                                            name="proof_file"
                                                            class="form-control"
                                                            accept=".pdf,.jpg,.jpeg,.png"
                                                            required
                                                        >


                                                        <div
                                                            class="form-help"
                                                        >

                                                            PDF, JPG, JPEG or PNG.
                                                            Maximum 5 MB.

                                                        </div>

                                                    </div>


                                                    <!-- =========================
                                                         BUTTONS
                                                    ========================== -->

                                                    <div
                                                        class="d-flex gap-2 mt-3"
                                                    >

                                                        <button
                                                            type="submit"
                                                            class="submit-btn"
                                                        >

                                                            <i
                                                                class="bi bi-send me-1">
                                                            </i>

                                                            Submit Payment

                                                        </button>


                                                        <button
                                                            type="button"
                                                            class="cancel-btn"
                                                            onclick="hidePaymentForm(
                                                                <?= (int)
                                                                    $schedule[
                                                                        'Schedule_ID'
                                                                    ]
                                                                ?>
                                                            )"
                                                        >

                                                            Cancel

                                                        </button>

                                                    </div>


                                                </form>

                                            </div>


                                        <?php elseif (
                                            $schedule[
                                                'Status'
                                            ] === 'Locked'
                                        ): ?>


                                            <span
                                                class="payment-status status-muted"
                                            >

                                                <i
                                                    class="bi bi-lock-fill me-1">
                                                </i>

                                                Locked

                                            </span>


                                        <?php elseif (
                                            $transaction
                                            &&
                                            $transaction[
                                                'Status'
                                            ] === 'Submitted'
                                        ): ?>


                                            <span
                                                class="payment-status status-info"
                                            >

                                                Awaiting Verification

                                            </span>


                                        <?php elseif (
                                            $transaction
                                            &&
                                            $transaction[
                                                'Status'
                                            ] === 'Verified'
                                        ): ?>


                                            <span
                                                class="payment-status status-success"
                                            >

                                                <i class="bi bi-check-circle-fill me-1"></i>

                                                Verified

                                            </span>


                                        <?php endif; ?>


                                    </td>


                                    <td>

                                        <div class="stage-name">

                                            <?= h(
                                                $schedule[
                                                    'Payment_Stage'
                                                ]
                                            ) ?>

                                        </div>

                                    </td>


                                    <td>

                                        <?= number_format(
                                            (float)$schedule[
                                                'Percentage'
                                            ],
                                            2
                                        ) ?>%

                                    </td>


                                    <td>

                                        <span class="stage-amount">

                                            ₹<?= money(
                                                $schedule[
                                                    'Expected_Amount'
                                                ]
                                            ) ?>

                                        </span>

                                    </td>


                                    <td>

                                        <?= h(
                                            $schedule[
                                                'Due_Trigger'
                                            ]
                                        ) ?>

                                    </td>


                                    <td>

                                        <?php
                                        $statusDisplay =
                                            (
                                                $transaction
                                                &&
                                                ($transaction['Status'] ?? '') === 'Verified'
                                            )
                                                ? 'Paid'
                                                : (
                                                    $schedule['Status'] ?? 'Pending'
                                                );

                                        $statusDisplayClass =
                                            $statusDisplay === 'Paid'
                                                ? 'status-success'
                                                : statusClass($statusDisplay);
                                        ?>

                                        <span
                                            class="payment-status
                                            <?= h($statusDisplayClass) ?>"
                                        >

                                            <?= h($statusDisplay) ?>

                                        </span>


                                        <?php if (
                                            $transaction
                                            &&
                                            $transaction[
                                                'Status'
                                            ] === 'Rejected'
                                        ): ?>

                                            <div
                                                class="rejection-box"
                                            >

                                                <strong>
                                                    Payment Rejected
                                                </strong>


                                                <?php if (
                                                    !empty(
                                                        $transaction[
                                                            'Rejection_Reason'
                                                        ]
                                                    )
                                                ): ?>

                                                    <br>

                                                    Reason:

                                                    <?= h(
                                                        $transaction[
                                                            'Rejection_Reason'
                                                        ]
                                                    ) ?>

                                                <?php endif; ?>

                                            </div>

                                        <?php endif; ?>

                                    </td>


                                    <td>

                                        <?php if (
                                            $transaction
                                        ): ?>

                                            <div
                                                class="submission-box"
                                            >

                                                <strong>

                                                    <?= h(
                                                        $transaction[
                                                            'Status'
                                                        ]
                                                    ) ?>

                                                </strong>

                                                <br>

                                                Amount:
                                                ₹<?= money(
                                                    $transaction[
                                                        'Submitted_Amount'
                                                    ]
                                                ) ?>

                                                <br>

                                                Method:
                                                <?= h(
                                                    $transaction[
                                                        'Payment_Method'
                                                    ]
                                                ) ?>

                                                <br>

                                                Ref:
                                                <?= h(
                                                    $transaction[
                                                        'Transaction_Reference'
                                                    ]
                                                ) ?>

                                                <br>

                                                Date:
                                                <?= h(
                                                    date(
                                                        'd-m-Y',
                                                        strtotime(
                                                            $transaction[
                                                                'Payment_Date'
                                                            ]
                                                        )
                                                    )
                                                ) ?>

                                            </div>

                                        <?php else: ?>

                                            <span class="text-muted">

                                                No submission

                                            </span>

                                        <?php endif; ?>

                                    </td>


                                    


                                </tr>


                            <?php endforeach; ?>


                            </tbody>

                        </table>

                    </div>




                <div class="alert alert-light border mt-4 mb-0">
                    <i class="bi bi-info-circle me-2"></i>
                    Payment amounts are taken directly from the approved
                    Offer payment schedule. Your permanent PSGA and FSO
                    number remain unchanged throughout the payment process.
                </div>

            </div>
        </div>
        <?php endif; ?>




    <?php if (
        $error !== ''
    ): ?>

        <div
            class="owner-offer-error
                   screen-only"
        >

            <?= h($error) ?>

        </div>

    <?php else: ?>


        <div
            class="owner-offer-info
                   screen-only"
        >

            <i
                class="bi bi-check-circle-fill me-2"
            ></i>

            This is the official FSO sent to you by NLETA.
            The Offer below is displayed exactly from the
            generated Offer record.

        </div>


        <!-- =================================================
             FSO PAPER — SHARED ADMIN RENDERER
             Owner reads the exact Offer saved by Admin.
        ================================================== -->

        <?php
        require_once('../admin/saf-templates/fso-generator.php');
        if ($existingOffer) {
            renderNletaFso(
                $existingOffer,
                is_array($client ?? null) ? $client : [],
                is_array($existingItems ?? null) ? $existingItems : [],
                is_array($offerAssets ?? null) ? $offerAssets : [],
                $conn
            );
        }
        ?>

        <?php endif; ?>


<script>

/* =========================================================
   PAYMENT DETAILS
========================================================= */

function showPaymentDetails(paymentId, type)
{
    const types = ['upi', 'qr', 'bank', 'other'];

    types.forEach(function(item) {
        const element = document.getElementById(
            item + '-details-' + paymentId
        );

        if (element) {
            element.style.display = 'none';
        }
    });

    const selected = document.getElementById(
        type + '-details-' + paymentId
    );

    if (selected) {
        selected.style.display = 'block';
    }
}


/* =========================================================
   SHOW PAYMENT FORM
========================================================= */

function showPaymentForm(scheduleId)
{
    const form = document.getElementById(
        'payment-form-' + scheduleId
    );

    if (!form) {
        return;
    }

    form.style.display = 'block';

    form.scrollIntoView({
        behavior: 'smooth',
        block: 'center'
    });
}


/* =========================================================
   HIDE PAYMENT FORM
========================================================= */

function hidePaymentForm(scheduleId)
{
    const form = document.getElementById(
        'payment-form-' + scheduleId
    );

    if (!form) {
        return;
    }

    form.style.display = 'none';
}


/* =========================================================
   CONFIRM
========================================================= */

function confirmSubmission()
{
    return confirm(
        'Are you sure you want to submit this payment for Admin verification?'
    );
}

</script>


<?php include('../includes/footer.php'); ?>