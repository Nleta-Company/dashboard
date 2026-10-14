<?php

/* =========================================================
   OWNER — PAYMENTS
   PHASE 7 — PAYMENT MODULE
========================================================= */

include('../config/db.php');
include('../config/auth.php');
require_once '../config/nleta-mailer.php';

require_role('owner');

date_default_timezone_set('Asia/Kolkata');

$ownerId = (int)($_SESSION['uid'] ?? 0);

$error   = '';
$success = '';

$payments = [];


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


function money($value)
{
    return number_format(
        (float)$value,
        2,
        '.',
        ','
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
   PAYMENT SUBMISSION
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    isset($_POST['submit_payment'])
) {

    $scheduleId =
        (int)($_POST['schedule_id'] ?? 0);

    $paymentMethod =
        trim(
            $_POST['payment_method'] ?? ''
        );

    $transactionReference =
        trim(
            $_POST['transaction_reference'] ?? ''
        );

    $paymentDate =
        trim(
            $_POST['payment_date'] ?? ''
        );


    /* =====================================================
       VALIDATE METHOD
    ===================================================== */

    $allowedMethods = [
        'UPI',
        'QR',
        'Bank Transfer',
        'Other'
    ];


    if ($scheduleId <= 0) {

        $error =
            'Invalid payment schedule.';

    } elseif (
        !in_array(
            $paymentMethod,
            $allowedMethods,
            true
        )
    ) {

        $error =
            'Please select a valid payment method.';

    } elseif (
        $transactionReference === ''
    ) {

        $error =
            'Transaction / UTR reference number is required.';

    } elseif (
        $paymentDate === ''
    ) {

        $error =
            'Payment date is required.';
    }


    /* =====================================================
       DATE VALIDATION
    ===================================================== */

    if ($error === '') {

        $dateObject =
            DateTime::createFromFormat(
                'Y-m-d',
                $paymentDate
            );

        $dateErrors =
            DateTime::getLastErrors();

        $dateValid =
            $dateObject !== false
            &&
            (
                $dateErrors === false
                ||
                (
                    $dateErrors['warning_count'] === 0
                    &&
                    $dateErrors['error_count'] === 0
                )
            )
            &&
            $dateObject->format('Y-m-d')
            ===
            $paymentDate;


        if (!$dateValid) {

            $error =
                'Invalid payment date.';

        } elseif (
            $paymentDate > date('Y-m-d')
        ) {

            $error =
                'Payment date cannot be in the future.';
        }
    }


    /* =====================================================
       LOAD SCHEDULE AND VERIFY OWNER
    ===================================================== */

    $schedule = null;

    if ($error === '') {

        $scheduleStmt = $conn->prepare("
            SELECT
                ps.Schedule_ID,
                ps.Payment_ID,
                ps.Offer_ID,
                ps.PSGA_ID,
                ps.Payment_Stage,
                ps.Percentage,
                ps.Expected_Amount,
                ps.Status,
                ps.Due_Trigger,

                p.Total_Amount,
                p.Received_Amount,
                p.Balance_Amount,
                p.Payment_Status,

                o.offer_no,
                o.psga_no,
                o.status AS offer_status,
                sr.request_no AS service_request_no,
                u.name AS owner_name,
                u.email AS owner_email

            FROM payment_schedule ps

            INNER JOIN payments p
                ON p.Payment_ID = ps.Payment_ID

            INNER JOIN nleta_offer_master o
                ON o.id = ps.Offer_ID

            LEFT JOIN service_requests sr
                ON sr.id = o.request_id

            LEFT JOIN users u
                ON u.id = sr.owner_id

            INNER JOIN nleta_offer_assets oa
                ON oa.offer_id = o.id

            INNER JOIN assets a
                ON a.id = oa.asset_id

            WHERE ps.Schedule_ID = ?
              AND a.owner_id = ?

            GROUP BY
                ps.Schedule_ID,
                ps.Payment_ID,
                ps.Offer_ID,
                ps.PSGA_ID,
                ps.Payment_Stage,
                ps.Percentage,
                ps.Expected_Amount,
                ps.Status,
                ps.Due_Trigger,

                p.Total_Amount,
                p.Received_Amount,
                p.Balance_Amount,
                p.Payment_Status,

                o.offer_no,
                o.psga_no,
                o.status,
                sr.request_no,
                u.name,
                u.email

            LIMIT 1
        ");

        if (!$scheduleStmt) {

            $error =
                'Unable to validate payment schedule: '
                . $conn->error;

        } else {

            $scheduleStmt->bind_param(
                'ii',
                $scheduleId,
                $ownerId
            );

            if (
                !$scheduleStmt->execute()
            ) {

                $error =
                    'Unable to validate payment schedule: '
                    . $scheduleStmt->error;

            } else {

                $schedule =
                    $scheduleStmt
                    ->get_result()
                    ->fetch_assoc();


                if (!$schedule) {

                    $error =
                        'Payment schedule not found or access denied.';
                }
            }

            $scheduleStmt->close();
        }
    }


    /* =====================================================
       PAYMENT STAGE VALIDATION
    ===================================================== */

    if (
        $error === ''
        &&
        $schedule
    ) {

        if (
            $schedule['Status']
            ===
            'Locked'
        ) {

            $error =
                'This payment stage is locked.';

        } elseif (
            $schedule['Status']
            !==
            'Pending'
        ) {

            $error =
                'This payment stage is not currently available.';
        }
    }


    /* =====================================================
       CHECK PREVIOUS TRANSACTION
    ===================================================== */

    $latestTransaction = null;

    if (
        $error === ''
        &&
        $schedule
    ) {

        $transactionCheck =
            $conn->prepare("
                SELECT
                    Transaction_ID,
                    Status,
                    Rejection_Reason

                FROM payment_transactions

                WHERE Schedule_ID = ?

                ORDER BY Transaction_ID DESC

                LIMIT 1
            ");

        if (!$transactionCheck) {

            $error =
                'Unable to check payment history: '
                . $conn->error;

        } else {

            $transactionCheck->bind_param(
                'i',
                $scheduleId
            );

            $transactionCheck->execute();

            $latestTransaction =
                $transactionCheck
                ->get_result()
                ->fetch_assoc();

            $transactionCheck->close();


            if ($latestTransaction) {

                if (
                    $latestTransaction['Status']
                    ===
                    'Submitted'
                ) {

                    $error =
                        'This payment is already submitted and is waiting for Admin verification.';

                } elseif (
                    $latestTransaction['Status']
                    ===
                    'Verified'
                ) {

                    $error =
                        'This payment has already been verified.';
                }
            }
        }
    }


    /* =====================================================
       PAYMENT PROOF
    ===================================================== */

    $proofFileName = null;
    $proofFullPath = null;

    if ($error === '') {

        if (
            !isset($_FILES['proof_file'])
            ||
            $_FILES['proof_file']['error']
            !== UPLOAD_ERR_OK
        ) {

            $error =
                'Payment proof is required.';

        } else {

            $file =
                $_FILES['proof_file'];

            $maxSize =
                5 * 1024 * 1024;


            if (
                (int)$file['size']
                >
                $maxSize
            ) {

                $error =
                    'Payment proof must not exceed 5 MB.';

            } else {

                $extension =
                    strtolower(
                        pathinfo(
                            $file['name'],
                            PATHINFO_EXTENSION
                        )
                    );


                $allowedExtensions = [
                    'pdf',
                    'jpg',
                    'jpeg',
                    'png'
                ];


                if (
                    !in_array(
                        $extension,
                        $allowedExtensions,
                        true
                    )
                ) {

                    $error =
                        'Allowed payment proof formats: PDF, JPG, JPEG and PNG.';

                } else {

                    $finfo =
                        new finfo(
                            FILEINFO_MIME_TYPE
                        );

                    $mime =
                        $finfo->file(
                            $file['tmp_name']
                        );


                    $allowedMimeTypes = [
                        'application/pdf',
                        'image/jpeg',
                        'image/png'
                    ];


                    if (
                        !in_array(
                            $mime,
                            $allowedMimeTypes,
                            true
                        )
                    ) {

                        $error =
                            'Invalid payment proof file.';

                    } else {

                        $uploadDirectory =
                            __DIR__
                            . '/uploads/payments/';


                        if (
                            !is_dir(
                                $uploadDirectory
                            )
                        ) {

                            if (
                                !mkdir(
                                    $uploadDirectory,
                                    0755,
                                    true
                                )
                            ) {

                                $error =
                                    'Unable to create payment upload directory.';
                            }
                        }


                        if ($error === '') {

                            $uniqueName =
                                'PAYMENT_'
                                . $ownerId
                                . '_'
                                . $scheduleId
                                . '_'
                                . date('YmdHis')
                                . '_'
                                . bin2hex(
                                    random_bytes(5)
                                )
                                . '.'
                                . $extension;


                            $proofFullPath =
                                $uploadDirectory
                                . $uniqueName;


                            if (
                                !move_uploaded_file(
                                    $file['tmp_name'],
                                    $proofFullPath
                                )
                            ) {

                                $error =
                                    'Unable to save payment proof.';

                            } else {

                                $proofFileName =
                                    'uploads/payments/'
                                    . $uniqueName;
                            }
                        }
                    }
                }
            }
        }
    }


    /* =====================================================
       INSERT TRANSACTION
    ===================================================== */

    if (
        $error === ''
        &&
        $schedule
    ) {

        /*
         * EXACT amount from the saved payment schedule.
         * Owner cannot change the amount.
         */

        $submittedAmount =
            (float)$schedule[
                'Expected_Amount'
            ];


        /*
         * PSGA_ID is intentionally not generated here.
         *
         * Permanent PSGA is already stored against the Offer
         * as o.psga_no.
         */

        $transactionStmt =
            $conn->prepare("
                INSERT INTO payment_transactions
                (
                    Schedule_ID,
                    Payment_ID,
                    Offer_ID,
                    PSGA_ID,
                    Submitted_Amount,
                    Payment_Date,
                    Transaction_Reference,
                    Payment_Method,
                    Proof_File,
                    Status,
                    Submitted_At,
                    Created_At,
                    Updated_At
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
                    'Submitted',
                    NOW(),
                    NOW(),
                    NOW()
                )
            ");


        if (!$transactionStmt) {

            $error =
                'Unable to prepare payment submission: '
                . $conn->error;

        } else {

            /*
             * PSGA_ID remains NULL because this existing
             * column represents an ID, while permanent PSGA
             * number is stored in nleta_offer_master.psga_no.
             */

            $psgaId = null;


            $transactionStmt->bind_param(
                'iiiidssss',
                $schedule['Schedule_ID'],
                $schedule['Payment_ID'],
                $schedule['Offer_ID'],
                $psgaId,
                $submittedAmount,
                $paymentDate,
                $transactionReference,
                $paymentMethod,
                $proofFileName
            );


            if (
                !$transactionStmt->execute()
            ) {

                $error =
                    'Unable to submit payment: '
                    . $transactionStmt->error;


                if (
                    $proofFullPath
                    &&
                    file_exists(
                        $proofFullPath
                    )
                ) {

                    @unlink(
                        $proofFullPath
                    );
                }

            } else {

                /*
                 * Schedule becomes Submitted.
                 *
                 * Admin verification will later update
                 * the financial totals.
                 */

                $updateSchedule =
                    $conn->prepare("
                        UPDATE payment_schedule

                        SET
                            Status = 'Submitted',
                            Updated_At = NOW()

                        WHERE Schedule_ID = ?
                    ");


                if ($updateSchedule) {

                    $updateSchedule->bind_param(
                        'i',
                        $scheduleId
                    );

                    $updateSchedule->execute();

                    $updateSchedule->close();
                }

                // Get the newly-created transaction ID.
                $transactionId = (int)$transactionStmt->insert_id;

                // Notify Admin after the payment has been saved successfully.
                // Email failure does not cancel or rollback the payment.
                $adminEmail = 'info@nleta.org.in';
                $adminName  = 'NLETA Admin';

                try {
                    if (function_exists('sendNletaPaymentSubmittedAdminEmail')) {
                        $mailSent = sendNletaPaymentSubmittedAdminEmail(
                            $transactionId,
                            (int)$schedule['Schedule_ID'],
                            (string)($schedule['owner_name'] ?? ($_SESSION['name'] ?? 'Owner')),
                            (string)($schedule['owner_email'] ?? ($_SESSION['email'] ?? '')),
                            (string)($schedule['service_request_no'] ?? ''),
                            (string)($schedule['offer_no'] ?? ''),
                            (string)($schedule['psga_no'] ?? ''),
                            (string)($schedule['Payment_Stage'] ?? 'Payment'),
                            (float)$submittedAmount,
                            (string)$paymentMethod,
                            (string)$transactionReference,
                            (string)$paymentDate,
                            date('d-m-Y h:i A'),
                            (string)($proofFullPath ?? '')
                        );

                        if (!$mailSent) {
                            error_log(
                                'NLETA payment email failed for Transaction_ID='
                                . $transactionId
                            );
                        }
                    } else {
                        error_log(
                            'NLETA payment mailer function missing for Transaction_ID='
                            . $transactionId
                        );
                    }
                } catch (Throwable $mailException) {
                    error_log(
                        'NLETA payment email exception for Transaction_ID='
                        . $transactionId . ': '
                        . $mailException->getMessage()
                    );
                }


                $transactionStmt->close();


                header(
                    'Location: payments.php?submitted=1'
                );

                exit;
            }


            $transactionStmt->close();
        }
    }
}


/* =========================================================
   SUCCESS MESSAGE
========================================================= */

if (
    isset($_GET['submitted'])
    &&
    $_GET['submitted'] === '1'
) {

    $success =
        'Payment submitted successfully. It is now waiting for Admin verification.';
}


/* =========================================================
   LOAD OWNER PAYMENTS
========================================================= */

$stmt =
    $conn->prepare("
        SELECT
            p.Payment_ID,
            p.Offer_ID,
            p.Total_Amount,
            p.Received_Amount,
            p.Balance_Amount,
            p.Payment_Status,
            p.Payment_Date,
            p.Created_At,

            o.offer_no,
            o.psga_no,
            o.offer_date,
            o.validity_date,
            o.grand_total,

            sr.request_no AS service_request_no,

            COUNT(DISTINCT oa.asset_id)
                AS asset_count

        FROM payments p

        INNER JOIN nleta_offer_master o
            ON o.id = p.Offer_ID

        /*
         * A payment is valid only while its Service Request exists.
         * If Admin permanently deletes the Service Request, the
         * related payment must disappear from the Owner Payments page.
         */
        INNER JOIN service_requests sr
            ON sr.id = o.request_id

        INNER JOIN nleta_offer_assets oa
            ON oa.offer_id = o.id

        INNER JOIN assets a
            ON a.id = oa.asset_id
           AND a.owner_id = ?

        GROUP BY
            p.Payment_ID,
            p.Offer_ID,
            p.Total_Amount,
            p.Received_Amount,
            p.Balance_Amount,
            p.Payment_Status,
            p.Payment_Date,
            p.Created_At,

            o.offer_no,
            o.psga_no,
            o.offer_date,
            o.validity_date,
            o.grand_total,
            sr.request_no

        ORDER BY
            p.Payment_ID DESC
    ");


if (!$stmt) {

    $error =
        'Unable to load payments: '
        . $conn->error;

} else {

    $stmt->bind_param(
        'i',
        $ownerId
    );


    if (
        !$stmt->execute()
    ) {

        $error =
            'Unable to load payments: '
            . $stmt->error;

    } else {

        $result =
            $stmt->get_result();


        while (
            $row =
            $result->fetch_assoc()
        ) {

            $payments[] =
                $row;
        }
    }


    $stmt->close();
}


/* =========================================================
   SYNCHRONIZE COMPLETED PAYMENT STATUS
   ---------------------------------------------------------
   When the full approved amount has been received, the
   overall payment is Paid and every verified schedule stage
   is marked Paid. This makes the Owner UI show Paid instead
   of leaving the individual stages at Verified.
========================================================= */

foreach (
    $payments
    as &$payment
) {

    $paymentTotal =
        (float)($payment['Total_Amount'] ?? 0);

    $paymentReceived =
        (float)($payment['Received_Amount'] ?? 0);

    $paymentBalance =
        (float)($payment['Balance_Amount'] ?? 0);

    $isFullyPaid =
        $paymentTotal > 0
        &&
        (
            $paymentBalance <= 0.01
            ||
            $paymentReceived >= ($paymentTotal - 0.01)
            ||
            strtolower(
                trim(
                    (string)($payment['Payment_Status'] ?? '')
                )
            ) === 'paid'
        );

    if (!$isFullyPaid) {
        continue;
    }

    /* Keep the master payment row synchronized. */
    $payment['Payment_Status'] = 'Paid';
    $payment['Balance_Amount'] = 0.00;
    $payment['Received_Amount'] = $paymentTotal;

    $syncPaymentStmt = $conn->prepare("
        UPDATE payments
        SET
            Received_Amount = Total_Amount,
            Balance_Amount = 0,
            Payment_Status = 'Paid'
        WHERE Payment_ID = ?
    ");

    if ($syncPaymentStmt) {

        $syncPaymentId =
            (int)$payment['Payment_ID'];

        $syncPaymentStmt->bind_param(
            'i',
            $syncPaymentId
        );

        $syncPaymentStmt->execute();
        $syncPaymentStmt->close();
    }
}

unset($payment);


/* =========================================================
   LOAD SCHEDULES
========================================================= */

foreach (
    $payments
    as &$payment
) {

    $payment['schedule'] = [];


    $scheduleStmt =
        $conn->prepare("
            SELECT
                Schedule_ID,
                Payment_Stage,
                Percentage,
                Expected_Amount,
                Status,
                Due_Trigger,
                Created_At,
                Updated_At

            FROM payment_schedule

            WHERE Payment_ID = ?

            ORDER BY
                CASE Payment_Stage

                    WHEN 'Advance'
                        THEN 1

                    WHEN 'Before Final Checklist'
                        THEN 2

                    WHEN 'Final'
                        THEN 3

                    ELSE 4

                END
        ");


    if (!$scheduleStmt) {
        continue;
    }


    $paymentId =
        (int)$payment['Payment_ID'];


    $scheduleStmt->bind_param(
        'i',
        $paymentId
    );


    $scheduleStmt->execute();


    $scheduleResult =
        $scheduleStmt
        ->get_result();


    while (
        $schedule =
        $scheduleResult->fetch_assoc()
    ) {

        $payment['schedule'][] =
            $schedule;
    }


    $scheduleStmt->close();
}

unset($payment);


/* =========================================================
   FINAL PAYMENT / SCHEDULE STATUS SYNCHRONIZATION
   ---------------------------------------------------------
   The UI must derive the final state from the actual payment
   totals and verified schedule transactions.

   If the full payment is received (Balance <= 0 OR Received >=
   Total), the master payment becomes Paid and every payment
   schedule row becomes Paid. This is intentionally case-
   insensitive so old values such as "verified" also update.
========================================================= */

foreach (
    $payments
    as &$payment
) {

    $paymentId =
        (int)$payment['Payment_ID'];

    $paymentTotal =
        (float)($payment['Total_Amount'] ?? 0);

    $paymentReceived =
        (float)($payment['Received_Amount'] ?? 0);

    $paymentBalance =
        (float)($payment['Balance_Amount'] ?? 0);

    $masterStatus =
        strtolower(
            trim(
                (string)($payment['Payment_Status'] ?? '')
            )
        );

    /*
     * Determine completion from the money, not from the text
     * status. This fixes old records whose Payment_Status is
     * still Pending/Verified although the complete amount was
     * already verified.
     */
    $isFullyPaid =
        $paymentTotal > 0
        &&
        (
            $paymentBalance <= 0.01
            ||
            $paymentReceived >= ($paymentTotal - 0.01)
        );

    /* If database already says Paid, also synchronize schedules. */
    if ($masterStatus === 'paid') {
        $isFullyPaid = true;
    }

    if ($isFullyPaid) {

        $payment['Payment_Status'] = 'Paid';
        $payment['Balance_Amount'] = 0.00;
        $payment['Received_Amount'] = $paymentTotal;

        /* Master payment row. */
        $syncPaymentStmt = $conn->prepare("
            UPDATE payments
            SET
                Received_Amount = Total_Amount,
                Balance_Amount = 0,
                Payment_Status = 'Paid'
            WHERE Payment_ID = ?
        ");

        if ($syncPaymentStmt) {

            $syncPaymentStmt->bind_param(
                'i',
                $paymentId
            );

            $syncPaymentStmt->execute();
            $syncPaymentStmt->close();
        }

        /*
         * IMPORTANT:
         * Once the overall payment is Paid, every schedule stage
         * is financially complete. Do not only update rows whose
         * status is exactly 'Verified'; old records may use a
         * different case or wording.
         */
        $paidScheduleStmt = $conn->prepare("
            UPDATE payment_schedule
            SET
                Status = 'Paid',
                Updated_At = NOW()
            WHERE Payment_ID = ?
              AND LOWER(TRIM(Status)) IN (
                  'pending',
                  'submitted',
                  'verified',
                  'paid',
                  'completed'
              )
        ");

        if ($paidScheduleStmt) {

            $paidScheduleStmt->bind_param(
                'i',
                $paymentId
            );

            $paidScheduleStmt->execute();
            $paidScheduleStmt->close();
        }

        /* Update the already-loaded schedule rows immediately. */
        foreach (
            $payment['schedule']
            as &$scheduleRow
        ) {

            $scheduleRow['Status'] = 'Paid';
        }

        unset($scheduleRow);

    } else {

        /* Normalize already-completed individual stages only. */
        foreach (
            $payment['schedule']
            as &$scheduleRow
        ) {

            $scheduleStatus =
                strtolower(
                    trim(
                        (string)($scheduleRow['Status'] ?? '')
                    )
                );

            if (
                $scheduleStatus === 'paid'
                ||
                $scheduleStatus === 'completed'
            ) {
                $scheduleRow['Status'] = 'Paid';
            }
        }

        unset($scheduleRow);
    }
}

unset($payment);


/* =========================================================
   LOAD LATEST TRANSACTION
========================================================= */

foreach (
    $payments
    as &$payment
) {

    foreach (
        $payment['schedule']
        as &$schedule
    ) {

        $schedule['latest_transaction'] =
            null;


        $transactionStmt =
            $conn->prepare("
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

                ORDER BY
                    Transaction_ID DESC

                LIMIT 1
            ");


        if (!$transactionStmt) {
            continue;
        }


        $scheduleId =
            (int)$schedule['Schedule_ID'];


        $transactionStmt->bind_param(
            'i',
            $scheduleId
        );


        $transactionStmt->execute();


        $schedule['latest_transaction'] =
            $transactionStmt
            ->get_result()
            ->fetch_assoc();


        $transactionStmt->close();
    }

    unset($schedule);
}

unset($payment);


/* =========================================================
   HEADER / SIDEBAR
========================================================= */

include('../includes/header.php');
include('../includes/sidebar-owner.php');

?>


<style>

/* =========================================================
   PAGE
========================================================= */

.payment-page {

    width: 100%;
    min-height: calc(100vh - 65px);
    padding: 24px;
    background: #f4f6f9;
}


.payment-container {

    width: 100%;
    max-width: 1450px;
    margin: 0 auto;
}


/* =========================================================
   HEADER
========================================================= */

.payment-topbar {

    background: #ffffff;
    border: 1px solid #e3e6ea;
    border-radius: 14px;
    padding: 20px 22px;
    margin-bottom: 20px;
    box-shadow: 0 4px 18px rgba(0,0,0,.06);
}


.payment-title {

    display: flex;
    align-items: center;
    gap: 12px;
}


.payment-title-icon {

    width: 46px;
    height: 46px;
    border-radius: 10px;
    background: #eaf2ff;
    color: #0d6efd;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
}


.payment-title h2 {

    margin: 0;
    font-size: 24px;
    font-weight: 700;
}


.payment-title p {

    margin: 4px 0 0;
    color: #6c757d;
    font-size: 13px;
}


/* =========================================================
   ALERT
========================================================= */

.payment-error,
.payment-success {

    border-radius: 10px;
    padding: 14px 16px;
    margin-bottom: 18px;
}


.payment-error {

    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fecaca;
}


.payment-success {

    background: #dcfce7;
    color: #166534;
    border: 1px solid #bbf7d0;
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
    font-size: 15px;
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

    font-size: 10px;
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
   RESPONSIVE
========================================================= */

@media (max-width: 992px) {

    .summary-grid {

        grid-template-columns:
            repeat(2, minmax(150px, 1fr));
    }


    .method-grid {

        grid-template-columns:
            repeat(2, minmax(130px, 1fr));
    }
}


@media (max-width: 768px) {

    .payment-page {

        padding: 12px;
    }


    .summary-grid {

        grid-template-columns: 1fr;
    }


    .method-grid {

        grid-template-columns: 1fr;
    }


    .form-row {

        grid-template-columns: 1fr;
    }


    .payment-form {

        min-width: 300px;
    }
}



/* =========================================================
   RESPONSIVE OVERRIDES — OWNER PAYMENTS
   Desktop layout remains unchanged.
========================================================= */

.payment-page,
.payment-container,
.payment-card,
.payment-card-body,
.payment-method-box,
.schedule-wrap {
    box-sizing: border-box;
    max-width: 100%;
}

.payment-card-header > *,
.payment-title > * {
    min-width: 0;
}

.offer-number,
.psga-number,
.detail-value,
.submission-box,
.rejection-box {
    overflow-wrap: anywhere;
    word-break: break-word;
}

@media (max-width: 1199px) {

    .payment-page {
        padding: 18px;
    }

    .summary-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .method-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .schedule-table {
        min-width: 980px;
    }
}

@media (max-width: 767px) {

    .payment-page {
        width: 100%;
        min-height: calc(100vh - 60px);
        padding: 10px;
        overflow-x: hidden;
    }

    .payment-container {
        width: 100%;
        max-width: 100%;
    }

    .payment-topbar {
        padding: 14px;
        margin-bottom: 12px;
        border-radius: 12px;
    }

    .payment-title {
        gap: 9px;
        align-items: center;
    }

    .payment-title-icon {
        width: 38px;
        height: 38px;
        min-width: 38px;
        font-size: 18px;
        border-radius: 9px;
    }

    .payment-title h2 {
        font-size: 19px;
        line-height: 1.2;
    }

    .payment-title p {
        font-size: 11px;
        line-height: 1.4;
    }

    .payment-error,
    .payment-success {
        padding: 11px 12px;
        margin-bottom: 12px;
        font-size: 12px;
    }

    .payment-card {
        margin-bottom: 14px;
        border-radius: 12px;
    }

    .payment-card-header {
        padding: 13px;
        gap: 10px;
    }

    .offer-number {
        font-size: 15px;
        line-height: 1.3;
    }

    .psga-number {
        font-size: 11px;
        line-height: 1.4;
    }

    .payment-card-body {
        padding: 13px;
    }

    /* Keep summary cards 2-column on mobile. */
    .summary-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
        margin-bottom: 14px;
    }

    .summary-box {
        min-width: 0;
        padding: 10px;
        border-radius: 9px;
    }

    .summary-label {
        font-size: 10px;
        line-height: 1.25;
    }

    .summary-value {
        font-size: 14px;
        line-height: 1.3;
        overflow-wrap: anywhere;
    }

    .payment-method-box {
        padding: 12px;
        margin-bottom: 14px;
        border-radius: 10px;
    }

    .payment-method-title {
        font-size: 14px;
        margin-bottom: 10px;
    }

    /* Two payment-method cards per row on mobile. */
    .method-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
    }

    .method-option label {
        min-height: 70px;
        padding: 9px 6px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        font-size: 11px;
        line-height: 1.25;
    }

    .method-icon {
        font-size: 19px;
        margin-bottom: 3px;
    }

    .payment-details {
        margin-top: 10px;
    }

    .detail-panel {
        padding: 11px;
    }

    .detail-title {
        font-size: 13px;
    }

    .detail-row {
        gap: 8px;
        padding: 6px 0;
        font-size: 11px;
    }

    .detail-label {
        flex: 0 0 38%;
    }

    .detail-value {
        flex: 1 1 auto;
    }

    .qr-container img {
        width: min(170px, 75vw);
        height: min(170px, 75vw);
    }

    /* Keep the schedule usable without breaking the viewport. */
    .schedule-wrap {
        width: 100%;
        max-width: 100%;
        overflow-x: auto;
        overflow-y: hidden;
        -webkit-overflow-scrolling: touch;
        border: 1px solid #edf0f2;
        border-radius: 8px;
    }

    .schedule-table {
        min-width: 900px;
        width: 900px;
    }

    .schedule-table th {
        padding: 9px 10px;
        font-size: 10px;
        white-space: nowrap;
    }

    .schedule-table td {
        padding: 10px;
        font-size: 11px;
    }

    .stage-name {
        font-size: 11px;
    }

    .stage-amount {
        font-size: 12px;
    }

    .payment-status {
        padding: 4px 8px;
        font-size: 10px;
        white-space: nowrap;
    }

    .submission-box,
    .rejection-box {
        padding: 7px 8px;
        font-size: 10px;
        line-height: 1.45;
    }

    .payment-form {
        width: 100%;
        min-width: 0;
        max-width: 100%;
        margin-top: 9px;
        padding: 11px;
        border-radius: 8px;
    }

    .form-title {
        font-size: 12px;
        line-height: 1.35;
    }

    .form-row {
        grid-template-columns: 1fr;
        gap: 0;
    }

    .form-control {
        min-width: 0;
        font-size: 12px;
    }

    .amount-display {
        font-size: 14px;
    }

    .form-help {
        font-size: 9px;
        line-height: 1.35;
    }

    .payment-form .d-flex {
        flex-wrap: wrap;
    }

    .submit-btn,
    .cancel-btn,
    .action-btn {
        min-height: 36px;
        font-size: 11px;
    }

    .payment-card-body > .d-flex {
        flex-wrap: wrap;
        align-items: flex-start !important;
    }

    .schedule-title {
        font-size: 14px;
    }

    .alert.alert-light {
        padding: 10px;
        font-size: 11px;
        line-height: 1.45;
    }

    .empty-state {
        padding: 40px 14px;
        border-radius: 12px;
    }

    .empty-state i {
        font-size: 40px;
    }
}

@media (max-width: 380px) {

    .payment-page {
        padding: 8px;
    }

    .payment-card-body {
        padding: 10px;
    }

    .summary-grid {
        gap: 6px;
    }

    .summary-box {
        padding: 9px 8px;
    }

    .summary-label {
        font-size: 9px;
    }

    .summary-value {
        font-size: 13px;
    }

    .method-grid {
        gap: 6px;
    }

    .method-option label {
        min-height: 64px;
        font-size: 10px;
    }

    .schedule-table {
        min-width: 860px;
        width: 860px;
    }
}

</style>


<div class="content">

<div class="payment-page">

<div class="payment-container">


    <!-- =====================================================
         HEADER
    ====================================================== -->

    <div class="payment-topbar">

        <div class="payment-title">

            <div class="payment-title-icon">

                <i class="bi bi-credit-card-2-front"></i>

            </div>

            <div>

                <h2>
                    Payments
                </h2>

                <p>
                    Manage your Offer payment schedule and submissions
                </p>

            </div>

        </div>

    </div>


    <!-- =====================================================
         ALERTS
    ====================================================== -->

    <?php if ($error !== ''): ?>

        <div class="payment-error">

            <i class="bi bi-exclamation-triangle-fill me-2"></i>

            <?= h($error) ?>

        </div>

    <?php endif; ?>


    <?php if ($success !== ''): ?>

        <div class="payment-success">

            <i class="bi bi-check-circle-fill me-2"></i>

            <?= h($success) ?>

        </div>

    <?php endif; ?>


    <?php if (empty($payments)): ?>


        <div class="empty-state">

            <i class="bi bi-credit-card"></i>

            <h5 class="mt-3">
                No Payments Available
            </h5>

            <p class="text-muted mb-0">

                Payment schedules will appear here
                after an Offer is accepted.

            </p>

        </div>


    <?php else: ?>


        <?php foreach ($payments as $payment): ?>


            <div class="payment-card">


                <!-- =================================================
                     HEADER
                ================================================== -->

                <div class="payment-card-header">

                    <div>

                        <div class="offer-number">

                            <?= h(
                                $payment['offer_no']
                            ) ?>

                        </div>

                        <div class="psga-number">

                            <strong>
                                PSGA:
                            </strong>

                            <?= h(
                                $payment['psga_no']
                            ) ?>

                        </div>

                    </div>


                    <div>

                        <?php
                        $displayPaymentStatus =
                            trim(
                                (string)(
                                    $payment['Payment_Status']
                                    ?? ''
                                )
                            );

                        $displayTotal =
                            (float)(
                                $payment['Total_Amount']
                                ?? 0
                            );

                        $displayBalance =
                            (float)(
                                $payment['Balance_Amount']
                                ?? 0
                            );

                        /*
                         * When the complete amount has been received,
                         * the Owner page must always show Paid.
                         */
                        if (
                            $displayTotal > 0
                            &&
                            $displayBalance <= 0.01
                        ) {
                            $displayPaymentStatus = 'Paid';
                        }

                        /*
                         * Keep the payment status synchronized in the
                         * database when the financial balance is complete.
                         */
                        if (
                            $displayPaymentStatus === 'Paid'
                            &&
                            strtolower(
                                trim(
                                    (string)(
                                        $payment['Payment_Status']
                                        ?? ''
                                    )
                                )
                            ) !== 'paid'
                        ) {
                            $syncPaidStmt = $conn->prepare("
                                UPDATE payments
                                SET Payment_Status = 'Paid'
                                WHERE Payment_ID = ?
                                  AND Balance_Amount <= 0.01
                            ");

                            if ($syncPaidStmt) {
                                $syncPaymentId =
                                    (int)$payment['Payment_ID'];

                                $syncPaidStmt->bind_param(
                                    'i',
                                    $syncPaymentId
                                );

                                $syncPaidStmt->execute();
                                $syncPaidStmt->close();
                            }
                        }
                        ?>

                        <span
                            class="payment-status
                            <?= h(
                                statusClass(
                                    $displayPaymentStatus
                                )
                            ) ?>"
                        >

                            <?= h(
                                $displayPaymentStatus
                            ) ?>

                        </span>

                    </div>

                </div>


                <div class="payment-card-body">


                    <!-- =================================================
                         SUMMARY
                    ================================================== -->

                    <?php if (
                        $displayTotal > 0
                        &&
                        $displayBalance <= 0.01
                    ): ?>

                        <div
                            class="payment-success mb-3"
                            style="margin-bottom:15px;"
                        >

                            <i class="bi bi-check-circle-fill me-2"></i>

                            <strong>Payment Completed — Paid</strong>

                            <div style="font-size:12px;margin-top:4px;">

                                The full approved amount has been received
                                and verified.

                            </div>

                        </div>

                    <?php endif; ?>


                    <div class="summary-grid">


                        <div class="summary-box">

                            <div class="summary-label">
                                Total Amount
                            </div>

                            <div class="summary-value">

                                ₹<?= money(
                                    $payment[
                                        'Total_Amount'
                                    ]
                                ) ?>

                            </div>

                        </div>


                        <div class="summary-box">

                            <div class="summary-label">
                                Received
                            </div>

                            <div class="summary-value">

                                ₹<?= money(
                                    $payment[
                                        'Received_Amount'
                                    ]
                                ) ?>

                            </div>

                        </div>


                        <div class="summary-box">

                            <div class="summary-label">
                                Balance
                            </div>

                            <div class="summary-value">

                                ₹<?= money(
                                    $payment[
                                        'Balance_Amount'
                                    ]
                                ) ?>

                            </div>

                        </div>


                        <div class="summary-box">

                            <div class="summary-label">
                                Assets
                            </div>

                            <div class="summary-value">

                                <?= (int)
                                    $payment[
                                        'asset_count'
                                    ] ?>

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
                                        id="upi-<?= (int)$payment['Payment_ID'] ?>"
                                        name="method_preview_<?= (int)$payment['Payment_ID'] ?>"
                                        value="UPI"
                                        onchange="showPaymentDetails(
                                            <?= (int)$payment['Payment_ID'] ?>,
                                            'upi'
                                        )"
                                    >

                                    <label
                                        for="upi-<?= (int)$payment['Payment_ID'] ?>"
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
                                        id="qr-<?= (int)$payment['Payment_ID'] ?>"
                                        name="method_preview_<?= (int)$payment['Payment_ID'] ?>"
                                        value="QR"
                                        onchange="showPaymentDetails(
                                            <?= (int)$payment['Payment_ID'] ?>,
                                            'qr'
                                        )"
                                    >

                                    <label
                                        for="qr-<?= (int)$payment['Payment_ID'] ?>"
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
                                        id="bank-<?= (int)$payment['Payment_ID'] ?>"
                                        name="method_preview_<?= (int)$payment['Payment_ID'] ?>"
                                        value="Bank Transfer"
                                        onchange="showPaymentDetails(
                                            <?= (int)$payment['Payment_ID'] ?>,
                                            'bank'
                                        )"
                                    >

                                    <label
                                        for="bank-<?= (int)$payment['Payment_ID'] ?>"
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
                                        id="other-<?= (int)$payment['Payment_ID'] ?>"
                                        name="method_preview_<?= (int)$payment['Payment_ID'] ?>"
                                        value="Other"
                                        onchange="showPaymentDetails(
                                            <?= (int)$payment['Payment_ID'] ?>,
                                            'other'
                                        )"
                                    >

                                    <label
                                        for="other-<?= (int)$payment['Payment_ID'] ?>"
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
                                id="upi-details-<?= (int)$payment['Payment_ID'] ?>"
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
                                id="qr-details-<?= (int)$payment['Payment_ID'] ?>"
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
                                id="bank-details-<?= (int)$payment['Payment_ID'] ?>"
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
                                id="other-details-<?= (int)$payment['Payment_ID'] ?>"
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

                    <div
                        class="d-flex justify-content-between align-items-center gap-3 mb-3"
                    >

                        <div class="schedule-title mb-0">

                            Payment Schedule

                        </div>

                        <?php if (strtolower(trim((string)($payment['Payment_Status'] ?? ''))) === 'paid'): ?>

                            <span class="payment-status status-success">

                                <i class="bi bi-check-circle-fill me-1"></i>

                                Payments Completed — Paid

                            </span>

                        <?php endif; ?>

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
                                $payment['schedule']
                                as $schedule
                            ): ?>


                                <?php

                                $transaction =
                                    $schedule[
                                        'latest_transaction'
                                    ];

                                $paymentIsPaid =
                                    strtolower(
                                        trim(
                                            (string)(
                                                $payment['Payment_Status']
                                                ?? ''
                                            )
                                        )
                                    ) === 'paid';

                                /*
                                 * Once the overall payment is Paid, every
                                 * verified payment stage is financially
                                 * completed. Show Paid instead of Verified.
                                 */
                                if (
                                    $paymentIsPaid
                                ) {
                                    $schedule['Status'] = 'Paid';
                                }

                                $canSubmit =
                                    !$paymentIsPaid
                                    &&
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
                                            $paymentIsPaid
                                            &&
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

                                        <span
                                            class="payment-status
                                            <?= h(
                                                statusClass(
                                                    $schedule[
                                                        'Status'
                                                    ]
                                                )
                                            ) ?>"
                                        >

                                            <?= h(
                                                $schedule[
                                                    'Status'
                                                ]
                                            ) ?>

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


                    <!-- =================================================
                         NOTE
                    ================================================== -->

                    <div
                        class="alert alert-light border mt-4 mb-0"
                    >

                        <i
                            class="bi bi-info-circle me-2">
                        </i>

                        Payment amounts are taken directly from the
                        approved Offer payment schedule.

                        Your permanent PSGA and FSO number remain unchanged
                        throughout the payment process.

                    </div>


                </div>

            </div>


        <?php endforeach; ?>


    <?php endif; ?>


</div>

</div>

</div>


<script>

/* =========================================================
   PAYMENT DETAILS
========================================================= */

function showPaymentDetails(
    paymentId,
    type
)
{
    const types = [
        'upi',
        'qr',
        'bank',
        'other'
    ];


    types.forEach(function(item) {

        const element =
            document.getElementById(
                item +
                '-details-' +
                paymentId
            );


        if (element) {

            element.style.display =
                'none';
        }

    });


    const selected =
        document.getElementById(
            type +
            '-details-' +
            paymentId
        );


    if (selected) {

        selected.style.display =
            'block';
    }
}


/* =========================================================
   SHOW PAYMENT FORM
========================================================= */

function showPaymentForm(
    scheduleId
)
{
    const form =
        document.getElementById(
            'payment-form-' +
            scheduleId
        );


    if (!form) {
        return;
    }


    form.style.display =
        'block';


    form.scrollIntoView({
        behavior: 'smooth',
        block: 'center'
    });
}


/* =========================================================
   HIDE PAYMENT FORM
========================================================= */

function hidePaymentForm(
    scheduleId
)
{
    const form =
        document.getElementById(
            'payment-form-' +
            scheduleId
        );


    if (!form) {
        return;
    }


    form.style.display =
        'none';
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