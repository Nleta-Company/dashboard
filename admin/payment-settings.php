<?php

/* =========================================================
   ADMIN — PAYMENT SETTINGS
   ========================================================= */

include('../config/db.php');
include('../config/auth.php');
include('../config/nleta-mailer.php');
require_once('../config/timezone.php');

require_role('admin');

/*
 * NLETA application timezone is IST (Asia/Kolkata).
 *
 * IMPORTANT:
 * PHP display/output uses IST.
 * Payment transaction timestamps are stored in UTC using
 * UTC_TIMESTAMP() and converted to IST only when displayed.
 *
 * This prevents the same timestamp from being shifted twice.
 */

$success = '';
$error   = '';

$adminId = (int)($_SESSION['uid'] ?? 0);

/*
 * POST/REDIRECT/GET
 * Prevents Confirm Form Resubmission / ERR_CACHE_MISS
 * when this page is refreshed after a POST.
 */
$shouldRedirectAfterPost = false;

if (isset($_SESSION['payment_settings_flash'])) {

    $flash = $_SESSION['payment_settings_flash'];

    unset($_SESSION['payment_settings_flash']);

    $success = (string)($flash['success'] ?? '');
    $error   = (string)($flash['error'] ?? '');
}



/* =========================================================
   PAYMENT VERIFIED EMAIL WRAPPER
   ---------------------------------------------------------
   Official implementation remains in config/nleta-mailer.php.
========================================================= */

function nletaPaymentVerifiedAdminEmail(
    int $transactionId,
    int $scheduleId,
    string $ownerName,
    string $ownerEmail,
    string $requestNo,
    string $offerNo,
    string $psgaNo,
    string $paymentStage,
    float $amount,
    string $paymentMethod,
    string $transactionReference,
    string $paymentDate,
    string $verifiedAt,
    float $totalAmount,
    float $receivedAmount,
    float $balanceAmount,
    string $paymentStatus
): bool {

    if (!function_exists('sendNletaPaymentVerifiedAdminEmail')) {
        error_log(
            'NLETA: official payment verification mailer function is unavailable.'
        );
        return false;
    }

    try {

        return (bool)call_user_func(
            'sendNletaPaymentVerifiedAdminEmail',
            $transactionId,
            $scheduleId,
            $ownerName,
            $ownerEmail,
            $requestNo,
            $offerNo,
            $psgaNo,
            $paymentStage,
            $amount,
            $paymentMethod,
            $transactionReference,
            $paymentDate,
            $verifiedAt,
            $totalAmount,
            $receivedAmount,
            $balanceAmount,
            $paymentStatus
        );

    } catch (Throwable $e) {

        error_log(
            'NLETA Payment Verified Admin Email Error: ' .
            $e->getMessage()
        );

        return false;
    }
}


/* =========================================================
   HELPER
========================================================= */

function nletaPaymentSettingsH($value) {
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}


/* =========================================================
   DATE / TIME — DATABASE UTC -> IST
   ---------------------------------------------------------
   Payment transaction timestamps are stored as UTC.
   They are converted to IST only for display.
========================================================= */

function formatIstDateTime($value)
{
    if (
        empty($value)
        ||
        $value === '0000-00-00 00:00:00'
    ) {
        return '—';
    }

    try {

        /*
         * payment_transactions timestamps are stored in UTC.
         * MySQL DATETIME has no timezone marker, so explicitly
         * interpret the database value as UTC.
         */
        $utc = new DateTimeImmutable(
            trim((string)$value),
            new DateTimeZone('UTC')
        );

        return $utc
            ->setTimezone(new DateTimeZone('Asia/Kolkata'))
            ->format('d-m-Y h:i A') . ' IST';

    } catch (Throwable $e) {

        return '—';
    }
}


/*
 * Payment-date fields are normally DATE values, not UTC
 * timestamps. Keep them as calendar dates without applying
 * a timezone shift.
 */
function formatIstDateOnly($value)
{
    if (
        empty($value)
        ||
        $value === '0000-00-00'
    ) {
        return '—';
    }

    try {

        return (new DateTimeImmutable(
            trim((string)$value),
            new DateTimeZone('Asia/Kolkata')
        ))->format('d-m-Y');

    } catch (Throwable $e) {

        return '—';
    }
}


/* =========================================================
   UPLOAD DIRECTORY
========================================================= */

$uploadDir =
    __DIR__ . '/../uploads/payment-settings/';

$uploadDbPath =
    'uploads/payment-settings/';


/* =========================================================
   SAVE SETTINGS
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    isset($_POST['save_settings'])
) {

    $shouldRedirectAfterPost = true;

    $upiId =
        trim(
            $_POST['upi_id'] ?? ''
        );

    $upiName =
        trim(
            $_POST['upi_name'] ?? ''
        );

    $bankName =
        trim(
            $_POST['bank_name'] ?? ''
        );

    $accountName =
        trim(
            $_POST['account_name'] ?? ''
        );

    $accountNumber =
        trim(
            $_POST['account_number'] ?? ''
        );

    $ifsc =
        trim(
            $_POST['ifsc'] ?? ''
        );

    $branch =
        trim(
            $_POST['branch'] ?? ''
        );

    $instructions =
        trim(
            $_POST['payment_instructions'] ?? ''
        );

    $isActive =
        isset($_POST['is_active'])
        ? 1
        : 0;


    /* =====================================================
       BASIC VALIDATION
    ===================================================== */

    if (
        $upiId === ''
        &&
        $bankName === ''
    ) {

        $error =
            'Please provide at least UPI or Bank payment details.';
    }


    /* =====================================================
       QR CODE UPLOAD
    ===================================================== */

    $qrCodePath = null;

    if (
        $error === ''
        &&
        isset($_FILES['qr_code'])
        &&
        $_FILES['qr_code']['error']
        !== UPLOAD_ERR_NO_FILE
    ) {

        if (
            $_FILES['qr_code']['error']
            !== UPLOAD_ERR_OK
        ) {

            $error =
                'Unable to upload QR code.';

        } else {

            $file =
                $_FILES['qr_code'];

            $maxSize =
                5 * 1024 * 1024;


            if (
                (int)$file['size']
                >
                $maxSize
            ) {

                $error =
                    'QR code must not exceed 5 MB.';

            } else {

                $extension =
                    strtolower(
                        pathinfo(
                            $file['name'],
                            PATHINFO_EXTENSION
                        )
                    );


                $allowedExtensions = [
                    'png',
                    'jpg',
                    'jpeg'
                ];


                if (
                    !in_array(
                        $extension,
                        $allowedExtensions,
                        true
                    )
                ) {

                    $error =
                        'QR code must be PNG, JPG or JPEG.';

                } else {

                    $finfo =
                        new finfo(
                            FILEINFO_MIME_TYPE
                        );

                    $mime =
                        $finfo->file(
                            $file['tmp_name']
                        );


                    $allowedMime = [
                        'image/png',
                        'image/jpeg'
                    ];


                    if (
                        !in_array(
                            $mime,
                            $allowedMime,
                            true
                        )
                    ) {

                        $error =
                            'Invalid QR code image.';

                    } else {

                        if (
                            !is_dir(
                                $uploadDir
                            )
                        ) {

                            if (
                                !mkdir(
                                    $uploadDir,
                                    0755,
                                    true
                                )
                            ) {

                                $error =
                                    'Unable to create QR upload directory.';
                            }
                        }


                        if (
                            $error === ''
                        ) {

                            $fileName =
                                'nleta_qr_'
                                . date('YmdHis')
                                . '_'
                                . bin2hex(
                                    random_bytes(5)
                                )
                                . '.'
                                . $extension;


                            $destination =
                                $uploadDir
                                . $fileName;


                            if (
                                !move_uploaded_file(
                                    $file['tmp_name'],
                                    $destination
                                )
                            ) {

                                $error =
                                    'Unable to save QR code.';

                            } else {

                                $qrCodePath =
                                    $uploadDbPath
                                    . $fileName;
                            }
                        }
                    }
                }
            }
        }
    }


    /* =====================================================
       GET CURRENT SETTINGS
    ===================================================== */

    $currentSettings = null;

    $settingsStmt =
        $conn->prepare("
            SELECT *
            FROM payment_settings
            ORDER BY id ASC
            LIMIT 1
        ");

    if ($settingsStmt) {

        $settingsStmt->execute();

        $currentSettings =
            $settingsStmt
            ->get_result()
            ->fetch_assoc();

        $settingsStmt->close();
    }


    /*
     * Keep existing QR if no new QR was uploaded.
     */

    if (
        $qrCodePath === null
        &&
        $currentSettings
    ) {

        $qrCodePath =
            $currentSettings['qr_code'];
    }


    /* =====================================================
       INSERT / UPDATE
    ===================================================== */

    if (
        $error === ''
    ) {

        if ($currentSettings) {

            $settingsId =
                (int)$currentSettings['id'];


            $updateStmt =
                $conn->prepare("
                    UPDATE payment_settings

                    SET
                        upi_id = ?,
                        upi_name = ?,
                        qr_code = ?,
                        bank_name = ?,
                        account_name = ?,
                        account_number = ?,
                        ifsc = ?,
                        branch = ?,
                        payment_instructions = ?,
                        is_active = ?,
                        updated_at = NOW()

                    WHERE id = ?
                ");


            if (!$updateStmt) {

                $error =
                    'Unable to prepare settings update: '
                    . $conn->error;

            } else {

                $updateStmt->bind_param(
                    'sssssssssii',
                    $upiId,
                    $upiName,
                    $qrCodePath,
                    $bankName,
                    $accountName,
                    $accountNumber,
                    $ifsc,
                    $branch,
                    $instructions,
                    $isActive,
                    $settingsId
                );


                if (
                    $updateStmt->execute()
                ) {

                    $success =
                        'Payment settings updated successfully.';

                } else {

                    $error =
                        'Unable to update payment settings: '
                        . $updateStmt->error;
                }


                $updateStmt->close();
            }

        } else {

            $insertStmt =
                $conn->prepare("
                    INSERT INTO payment_settings
                    (
                        upi_id,
                        upi_name,
                        qr_code,
                        bank_name,
                        account_name,
                        account_number,
                        ifsc,
                        branch,
                        payment_instructions,
                        is_active,
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
                        NOW(),
                        NOW()
                    )
                ");


            if (!$insertStmt) {

                $error =
                    'Unable to prepare settings insert: '
                    . $conn->error;

            } else {

                $insertStmt->bind_param(
                    'sssssssssi',
                    $upiId,
                    $upiName,
                    $qrCodePath,
                    $bankName,
                    $accountName,
                    $accountNumber,
                    $ifsc,
                    $branch,
                    $instructions,
                    $isActive
                );


                if (
                    $insertStmt->execute()
                ) {

                    $success =
                        'Payment settings saved successfully.';

                } else {

                    $error =
                        'Unable to save payment settings: '
                        . $insertStmt->error;
                }


                $insertStmt->close();
            }
        }
    }
}



/* =========================================================
   ADMIN PAYMENT VERIFICATION / REJECTION
   ---------------------------------------------------------
   Owner payment workflow:
   Submitted -> Admin review
   Verify    -> Verified + financial totals updated
   Reject    -> Rejected + stage returns to Pending
   Rejected  -> Owner can resubmit
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    isset($_POST['payment_action'])
) {

    $shouldRedirectAfterPost = true;

    $paymentAction =
        trim($_POST['payment_action'] ?? '');

    $transactionId =
        (int)($_POST['transaction_id'] ?? 0);

    $rejectionReason =
        trim($_POST['rejection_reason'] ?? '');

    if ($transactionId <= 0) {

        $error = 'Invalid payment transaction.';

    } elseif (
        !in_array(
            $paymentAction,
            ['verify', 'reject'],
            true
        )
    ) {

        $error = 'Invalid payment action.';

    } elseif (
        $paymentAction === 'reject'
        &&
        $rejectionReason === ''
    ) {

        $error = 'Rejection reason is required.';

    } else {

        $conn->begin_transaction();

        try {

            $transactionStmt = $conn->prepare("
                SELECT
                    pt.Transaction_ID,
                    pt.Schedule_ID,
                    pt.Payment_ID,
                    pt.Offer_ID,
                    pt.Submitted_Amount,
                    pt.Payment_Date,
                    pt.Transaction_Reference,
                    pt.Payment_Method,
                    pt.Status,
                    ps.Payment_Stage,
                    ps.Expected_Amount,
                    ps.Status AS Schedule_Status,
                    p.Total_Amount,
                    p.Received_Amount,
                    p.Balance_Amount,
                    p.Payment_Status
                FROM payment_transactions pt
                INNER JOIN payment_schedule ps
                    ON ps.Schedule_ID = pt.Schedule_ID
                INNER JOIN payments p
                    ON p.Payment_ID = pt.Payment_ID
                WHERE
                    pt.Transaction_ID = ?
                    AND pt.Status = 'Submitted'
                LIMIT 1
            ");

            if (!$transactionStmt) {
                throw new Exception(
                    'Unable to load payment transaction: ' .
                    $conn->error
                );
            }

            $transactionStmt->bind_param(
                'i',
                $transactionId
            );

            if (!$transactionStmt->execute()) {

                $stmtError =
                    $transactionStmt->error;

                $transactionStmt->close();

                throw new Exception(
                    'Unable to load payment transaction: ' .
                    $stmtError
                );
            }

            $transaction =
                $transactionStmt
                    ->get_result()
                    ->fetch_assoc();

            $transactionStmt->close();

            if (!$transaction) {

                throw new Exception(
                    'This payment is no longer waiting for Admin verification.'
                );
            }

            /* =================================================
               LOAD EMAIL DETAILS FOR ADMIN VERIFICATION MAIL
            ================================================= */

            $verificationMailData = [
                'owner_name'  => '',
                'owner_email' => '',
                'request_no'  => '',
                'offer_no'    => '',
                'psga_no'     => ''
            ];

            $mailDataStmt = $conn->prepare("
                SELECT
                    u.name AS owner_name,
                    u.email AS owner_email,
                    sr.request_no,
                    o.offer_no,
                    o.psga_no
                FROM nleta_offer_master o
                INNER JOIN service_requests sr
                    ON sr.id = o.request_id
                LEFT JOIN users u
                    ON u.id = sr.owner_id
                WHERE o.id = ?
                LIMIT 1
            ");

            if ($mailDataStmt) {

                $mailOfferId = (int)($transaction['Offer_ID'] ?? 0);

                $mailDataStmt->bind_param(
                    'i',
                    $mailOfferId
                );

                if ($mailDataStmt->execute()) {

                    $mailRow =
                        $mailDataStmt
                            ->get_result()
                            ->fetch_assoc();

                    if ($mailRow) {
                        $verificationMailData = array_merge(
                            $verificationMailData,
                            $mailRow
                        );
                    }
                }

                $mailDataStmt->close();
            }

            $scheduleId =
                (int)$transaction['Schedule_ID'];

            $paymentId =
                (int)$transaction['Payment_ID'];

            $submittedAmount =
                (float)$transaction['Submitted_Amount'];

            $expectedAmount =
                (float)$transaction['Expected_Amount'];

            /*
             * =================================================
             * REJECT
             * =================================================
             */
            if ($paymentAction === 'reject') {

                $rejectStmt = $conn->prepare("
                    UPDATE payment_transactions
                    SET
                        Status = 'Rejected',
                        Rejection_Reason = ?,
                        Updated_At = UTC_TIMESTAMP()
                    WHERE
                        Transaction_ID = ?
                        AND Status = 'Submitted'
                ");

                if (!$rejectStmt) {
                    throw new Exception(
                        'Unable to prepare payment rejection: ' .
                        $conn->error
                    );
                }

                $rejectStmt->bind_param(
                    'si',
                    $rejectionReason,
                    $transactionId
                );

                if (!$rejectStmt->execute()) {

                    $stmtError =
                        $rejectStmt->error;

                    $rejectStmt->close();

                    throw new Exception(
                        'Unable to reject payment: ' .
                        $stmtError
                    );
                }

                if ($rejectStmt->affected_rows !== 1) {

                    $rejectStmt->close();

                    throw new Exception(
                        'Payment could not be rejected because its status changed.'
                    );
                }

                $rejectStmt->close();

                /*
                 * Return the stage to Pending.
                 * This is what allows the Owner to resubmit
                 * after a rejection.
                 */
                $scheduleStmt = $conn->prepare("
                    UPDATE payment_schedule
                    SET
                        Status = 'Pending',
                        Updated_At = UTC_TIMESTAMP()
                    WHERE
                        Schedule_ID = ?
                ");

                if (!$scheduleStmt) {
                    throw new Exception(
                        'Unable to prepare payment stage reset: ' .
                        $conn->error
                    );
                }

                $scheduleStmt->bind_param(
                    'i',
                    $scheduleId
                );

                if (!$scheduleStmt->execute()) {

                    $stmtError =
                        $scheduleStmt->error;

                    $scheduleStmt->close();

                    throw new Exception(
                        'Unable to reset payment stage: ' .
                        $stmtError
                    );
                }

                $scheduleStmt->close();

                /*
                 * Do not change payments. A rejected transaction
                 * is not money received.
                 */
                $conn->commit();

                $success =
                    'Payment rejected successfully. The Owner can resubmit the payment.';

            /*
             * =================================================
             * VERIFY
             * =================================================
             */
            } else {

                /*
                 * Owner payment submission always uses the exact
                 * Expected_Amount from the schedule.
                 */
                if (
                    abs(
                        $submittedAmount -
                        $expectedAmount
                    ) > 0.01
                ) {

                    throw new Exception(
                        'Submitted amount does not match the payment schedule amount.'
                    );
                }

                if (
                    $transaction['Schedule_Status']
                    !==
                    'Submitted'
                ) {

                    throw new Exception(
                        'This payment stage is not in Submitted status.'
                    );
                }

                /*
                 * 1. Transaction -> Verified
                 */
                $verifyStmt = $conn->prepare("
                    UPDATE payment_transactions
                    SET
                        Status = 'Verified',
                        Rejection_Reason = NULL,
                        Verified_At = UTC_TIMESTAMP(),
                        Updated_At = UTC_TIMESTAMP()
                    WHERE
                        Transaction_ID = ?
                        AND Status = 'Submitted'
                ");

                if (!$verifyStmt) {
                    throw new Exception(
                        'Unable to prepare payment verification: ' .
                        $conn->error
                    );
                }

                $verifyStmt->bind_param(
                    'i',
                    $transactionId
                );

                if (!$verifyStmt->execute()) {

                    $stmtError =
                        $verifyStmt->error;

                    $verifyStmt->close();

                    throw new Exception(
                        'Unable to verify payment: ' .
                        $stmtError
                    );
                }

                if ($verifyStmt->affected_rows !== 1) {

                    $verifyStmt->close();

                    throw new Exception(
                        'Payment could not be verified because its status changed.'
                    );
                }

                $verifyStmt->close();

                /*
                 * 2. Current schedule -> Verified
                 */
                $scheduleVerifyStmt = $conn->prepare("
                    UPDATE payment_schedule
                    SET
                        Status = 'Verified',
                        Updated_At = UTC_TIMESTAMP()
                    WHERE
                        Schedule_ID = ?
                        AND Status = 'Submitted'
                ");

                if (!$scheduleVerifyStmt) {
                    throw new Exception(
                        'Unable to prepare payment stage verification: ' .
                        $conn->error
                    );
                }

                $scheduleVerifyStmt->bind_param(
                    'i',
                    $scheduleId
                );

                if (!$scheduleVerifyStmt->execute()) {

                    $stmtError =
                        $scheduleVerifyStmt->error;

                    $scheduleVerifyStmt->close();

                    throw new Exception(
                        'Unable to verify payment stage: ' .
                        $stmtError
                    );
                }

                if ($scheduleVerifyStmt->affected_rows !== 1) {

                    $scheduleVerifyStmt->close();

                    throw new Exception(
                        'Payment stage could not be verified.'
                    );
                }

                $scheduleVerifyStmt->close();

                /*
                 * 3. Update overall payment totals.
                 */
                $totalAmount =
                    (float)$transaction['Total_Amount'];

                $oldReceived =
                    (float)$transaction['Received_Amount'];

                $newReceived =
                    $oldReceived +
                    $submittedAmount;

                if ($newReceived > $totalAmount) {
                    $newReceived = $totalAmount;
                }

                $newBalance =
                    $totalAmount -
                    $newReceived;

                if ($newBalance < 0) {
                    $newBalance = 0;
                }

                $newPaymentStatus =
                    $newBalance <= 0.01
                    ? 'Paid'
                    : 'Partially Paid';

                $paymentUpdateStmt = $conn->prepare("
                    UPDATE payments
                    SET
                        Received_Amount = ?,
                        Balance_Amount = ?,
                        Payment_Status = ?
                    WHERE
                        Payment_ID = ?
                ");

                if (!$paymentUpdateStmt) {
                    throw new Exception(
                        'Unable to prepare payment total update: ' .
                        $conn->error
                    );
                }

                $paymentUpdateStmt->bind_param(
                    'ddsi',
                    $newReceived,
                    $newBalance,
                    $newPaymentStatus,
                    $paymentId
                );

                if (!$paymentUpdateStmt->execute()) {

                    $stmtError =
                        $paymentUpdateStmt->error;

                    $paymentUpdateStmt->close();

                    throw new Exception(
                        'Unable to update payment totals: ' .
                        $stmtError
                    );
                }

                $paymentUpdateStmt->close();                                                /*
                 * =================================================
                 * 4. PSGA AGREEMENT WORKFLOW
                 * =================================================
                 *
                 * After Advance Payment is verified:
                 *
                 * Advance Verified
                 *      ↓
                 * PSGA Agreement
                 *      ↓
                 * Admin Review / Generate
                 *      ↓
                 * Owner Confirmation
                 *      ↓
                 * Admin Confirmation
                 *      ↓
                 * SAF Contract
                 *      ↓
                 * Inspector Assignment
                 *
                 * IMPORTANT:
                 * SAF MUST NOT be generated at this stage.
                 *
                 * The dedicated PSGA Agreements module uses the
                 * verified Advance Payment as its eligibility
                 * condition. The permanent PSGA number remains
                 * linked to the Offer through nleta_offer_master.psga_no.
                 *
                 * Therefore this payment page only unlocks the
                 * PSGA workflow. SAF will be generated later,
                 * after PSGA is Confirmed.
                 */
                if (
                    trim(
                        (string)(
                            $transaction['Payment_Stage']
                            ?? ''
                        )
                    ) === 'Advance'
                ) {

                    error_log(
                        'NLETA: Advance payment verified for Offer ID '
                        . (int)($transaction['Offer_ID'] ?? 0)
                        . '. PSGA Agreement workflow is now available; SAF was not generated.'
                    );
                }

/*
                 * 5. Unlock the next payment stage.
                 *
                 * Advance -> Service Activation
                 * Service Activation -> Final
                 * Final -> no next stage
                 */
                $paymentStage =
                    trim(
                        (string)(
                            $transaction['Payment_Stage']
                            ?? ''
                        )
                    );

                if ($paymentStage === 'Advance') {

                    $nextStage =
                        'Before Final Checklist';

                } elseif (
                    $paymentStage === 'Before Final Checklist'
                ) {

                    $nextStage = 'Final';

                } else {

                    $nextStage = '';
                }

                if ($nextStage !== '') {

                    $unlockStmt = $conn->prepare("
                        UPDATE payment_schedule
                        SET
                            Status = 'Pending',
                            Updated_At = UTC_TIMESTAMP()
                        WHERE
                            Payment_ID = ?
                            AND Payment_Stage = ?
                            AND Status = 'Locked'
                    ");

                    if (!$unlockStmt) {
                        throw new Exception(
                            'Unable to prepare next payment stage unlock: ' .
                            $conn->error
                        );
                    }

                    $unlockStmt->bind_param(
                        'is',
                        $paymentId,
                        $nextStage
                    );

                    if (!$unlockStmt->execute()) {

                        $stmtError =
                            $unlockStmt->error;

                        $unlockStmt->close();

                        throw new Exception(
                            'Unable to unlock next payment stage: ' .
                            $stmtError
                        );
                    }

                    $unlockStmt->close();
                }

                $conn->commit();

                /* =================================================
                   SEND ADMIN EMAIL AFTER SUCCESSFUL VERIFICATION
                   -------------------------------------------------
                   Email failure does not rollback the payment.
                ================================================= */

                $verificationMailSent =

                nletaPaymentVerifiedAdminEmail(
                        $transactionId,
                        $scheduleId,
                        (string)($verificationMailData['owner_name'] ?? ''),
                        (string)($verificationMailData['owner_email'] ?? ''),
                        (string)($verificationMailData['request_no'] ?? ''),
                        (string)($verificationMailData['offer_no'] ?? ''),
                        (string)($verificationMailData['psga_no'] ?? ''),
                        $paymentStage,
                        $submittedAmount,
                        (string)($transaction['Payment_Method'] ?? ''),
                        (string)($transaction['Transaction_Reference'] ?? ''),
                        (string)($transaction['Payment_Date'] ?? ''),
                        date('d-m-Y h:i A'),
                        $totalAmount,
                        $newReceived,
                        $newBalance,
                        $newPaymentStatus
                    );

                if (!$verificationMailSent) {
                    error_log(
                        'NLETA: Payment TXN '
                        . $transactionId
                        . ' verified successfully, but Admin verification email could not be sent.'
                    );
                }

                if ($paymentStage === 'Advance') {

                    $success =
                        'Advance payment verified successfully. PSGA Agreement is now available for Admin processing. SAF will be generated only after PSGA is Confirmed.';

                } elseif (
                    $paymentStage === 'Before Final Checklist'
                ) {

                    $success =
                        'Before Final Checklist payment verified successfully. Final payment is now available to the Owner.';

                } elseif (
                    $paymentStage === 'Final'
                ) {

                    $success =
                        'Final payment verified successfully. The payment is now fully paid.';

                } else {

                    $success =
                        'Payment verified successfully.';
                }
            }

        } catch (Throwable $e) {

            $conn->rollback();

            $error =
                $e->getMessage();
        }
    }
}


/* =========================================================
   POST -> REDIRECT -> GET
========================================================= */

if ($shouldRedirectAfterPost) {

    $_SESSION['payment_settings_flash'] = [
        'success' => $success,
        'error'   => $error
    ];

    $redirectPath = strtok(
        $_SERVER['REQUEST_URI'],
        '?'
    );

    header(
        'Location: ' . $redirectPath,
        true,
        303
    );

    exit;
}


/* =========================================================
   LOAD SETTINGS
========================================================= */

$settings = null;

$settingsStmt =
    $conn->prepare("
        SELECT *
        FROM payment_settings
        ORDER BY id ASC
        LIMIT 1
    ");

if ($settingsStmt) {

    $settingsStmt->execute();

    $settings =
        $settingsStmt
        ->get_result()
        ->fetch_assoc();

    $settingsStmt->close();
}


/*
 * Fallback values if no row exists.
 */

if (!$settings) {

    $settings = [

        'upi_id' =>
            'nleta@upi',

        'upi_name' =>
            'NLETA Services',

        'qr_code' =>
            null,

        'bank_name' =>
            'NLETA Bank',

        'account_name' =>
            'NLETA Services Pvt. Ltd.',

        'account_number' =>
            'XXXXXXXXXXXX',

        'ifsc' =>
            'NLET0000001',

        'branch' =>
            'Main Branch',

        'payment_instructions' =>
            'Please make the payment using UPI/QR or bank transfer. Enter the transaction/UTR number and upload the payment proof after payment.',

        'is_active' =>
            1
    ];
}



/* =========================================================
   PAYMENT HISTORY
========================================================= */

$paymentHistory = [];
$historyError = '';

/*
 * Payment History uses the actual payment schema already used
 * by the Owner payment page:
 *
 * payment_transactions
 * payment_schedule
 * payments
 * nleta_offer_master
 * nleta_offer_assets
 * assets
 * users
 *
 * We do NOT assume owner_id, offer_no, payment_date, etc.
 * exist inside payment_transactions. The actual column names
 * are mapped below.
 */

$historySql = "
    SELECT
        pt.Transaction_ID,
        pt.Schedule_ID,
        pt.Payment_ID,
        pt.Offer_ID,
        pt.PSGA_ID,
        pt.Submitted_Amount,
        pt.Payment_Date,
        pt.Transaction_Reference,
        pt.Payment_Method,
        pt.Proof_File,
        pt.Status,
        pt.Rejection_Reason,
        pt.Submitted_At,
        pt.Verified_At,
        pt.Created_At,
        pt.Updated_At,

        ps.Payment_Stage,
        ps.Percentage,
        ps.Expected_Amount,
        ps.Due_Trigger,

        p.Total_Amount,
        p.Received_Amount,
        p.Balance_Amount,
        p.Payment_Status,

        o.offer_no,
        o.psga_no,
        o.offer_date,
        o.validity_date,

        sr.request_no AS service_request_no,

        GROUP_CONCAT(
            DISTINCT a.asset_name
            ORDER BY a.asset_name
            SEPARATOR ', '
        ) AS asset_names,

        GROUP_CONCAT(
            DISTINCT a.location
            ORDER BY a.location
            SEPARATOR ', '
        ) AS asset_locations,

        MAX(u.name) AS owner_name,
        MAX(u.email) AS owner_email,
        MAX(u.phone) AS owner_phone

    FROM payment_transactions pt

    LEFT JOIN payment_schedule ps
        ON ps.Schedule_ID = pt.Schedule_ID

    LEFT JOIN payments p
        ON p.Payment_ID = pt.Payment_ID

    INNER JOIN nleta_offer_master o
        ON o.id = pt.Offer_ID

    /*
     * A payment belongs to a Service Request through:
     * payment_transactions.Offer_ID
     *        -> nleta_offer_master.id
     *        -> nleta_offer_master.request_id
     *        -> service_requests.id
     *
     * If the Service Request has been permanently deleted,
     * its payment history must no longer be displayed here.
     */
    INNER JOIN service_requests sr
        ON sr.id = o.request_id

    LEFT JOIN nleta_offer_assets oa
        ON oa.offer_id = o.id

    LEFT JOIN assets a
        ON a.id = oa.asset_id

    LEFT JOIN users u
        ON u.id = a.owner_id

    GROUP BY
        pt.Transaction_ID,
        pt.Schedule_ID,
        pt.Payment_ID,
        pt.Offer_ID,
        pt.PSGA_ID,
        pt.Submitted_Amount,
        pt.Payment_Date,
        pt.Transaction_Reference,
        pt.Payment_Method,
        pt.Proof_File,
        pt.Status,
        pt.Rejection_Reason,
        pt.Submitted_At,
        pt.Verified_At,
        pt.Created_At,
        pt.Updated_At,

        ps.Payment_Stage,
        ps.Percentage,
        ps.Expected_Amount,
        ps.Due_Trigger,

        p.Total_Amount,
        p.Received_Amount,
        p.Balance_Amount,
        p.Payment_Status,

        o.offer_no,
        o.psga_no,
        o.offer_date,
        o.validity_date,
        sr.request_no

    ORDER BY
        pt.Transaction_ID DESC
";

$historyResult = $conn->query($historySql);

if ($historyResult) {

    while ($row = $historyResult->fetch_assoc()) {
        $paymentHistory[] = $row;
    }

} else {

    /*
     * If an older database does not have one of the optional
     * offer/asset relationships, still show the complete
     * transaction history instead of breaking the page.
     */
    $historyError =
        'Payment history could not be loaded: '
        . $conn->error;
}


/* =========================================================
   PAYMENT HISTORY FILTER
========================================================= */

$historyStatus =
    trim(
        $_GET['history_status'] ?? ''
    );

if ($historyStatus !== '') {

    $paymentHistory = array_values(
        array_filter(
            $paymentHistory,
            function ($row) use ($historyStatus) {

                $status =
                    strtolower(
                        trim(
                            (string)(
                                $row['Status'] ?? ''
                            )
                        )
                    );

                if (
                    strtolower($historyStatus) === 'paid'
                ) {

                    $paymentStatus =
                        strtolower(
                            trim(
                                (string)(
                                    $row['Payment_Status']
                                    ?? ''
                                )
                            )
                        );

                    $balance =
                        (float)(
                            $row['Balance_Amount']
                            ?? 0
                        );

                    $total =
                        (float)(
                            $row['Total_Amount']
                            ?? 0
                        );

                    return (
                        $paymentStatus === 'paid'
                        ||
                        (
                            $total > 0
                            &&
                            $balance <= 0.01
                        )
                    );
                }

                return $status ===
                    strtolower($historyStatus);
            }
        )
    );
}


/* =========================================================
   PAYMENT HISTORY TOTALS
========================================================= */

$historyTotal =
    count($paymentHistory);

$historySubmitted = 0;
$historyVerified  = 0;
$historyRejected  = 0;
$historyCompleted = 0;

foreach ($paymentHistory as $historyRow) {

    $status =
        strtolower(
            trim(
                (string)(
                    $historyRow['Status'] ?? ''
                )
            )
        );

    if (
        in_array(
            $status,
            [
                'submitted',
                'pending',
                'under_review'
            ],
            true
        )
    ) {
        $historySubmitted++;
    }

    if (
        in_array(
            $status,
            [
                'verified',
                'approved',
                'paid'
            ],
            true
        )
    ) {
        $historyVerified++;
    }

    if (
        in_array(
            $status,
            [
                'rejected',
                'failed'
            ],
            true
        )
    ) {
        $historyRejected++;
    }

    $rowPaymentStatus =
        strtolower(
            trim(
                (string)(
                    $historyRow['Payment_Status']
                    ?? ''
                )
            )
        );

    $rowBalance =
        (float)(
            $historyRow['Balance_Amount']
            ?? 0
        );

    $rowTotal =
        (float)(
            $historyRow['Total_Amount']
            ?? 0
        );

    if (
        $rowPaymentStatus === 'paid'
        ||
        (
            $rowTotal > 0
            &&
            $rowBalance <= 0.01
        )
    ) {
        $historyCompleted++;
    }
}


/* =========================================================
   HEADER / SIDEBAR
========================================================= */

include('../includes/header.php');
include('../includes/sidebar-admin.php');

?>


<style>

/* =========================================================
   PAYMENT SETTINGS PAGE
========================================================= */

.payment-settings-page {

    width: 100%;

    min-height:
        calc(100vh - 65px);

    padding: 24px;

    background: #f4f6f9;
}


.payment-settings-container {

    width: 100%;

    max-width: 1100px;

    margin: 0 auto;
}


/* =========================================================
   HEADER
========================================================= */

.settings-header {

    background: #ffffff;

    border:
        1px solid #e3e6ea;

    border-radius: 14px;

    padding: 20px 22px;

    margin-bottom: 20px;

    box-shadow:
        0 4px 18px rgba(0,0,0,.06);
}


.settings-header h2 {

    margin: 0;

    font-size: 24px;

    font-weight: 700;
}


.settings-header p {

    margin:
        5px 0 0;

    color: #6c757d;

    font-size: 13px;
}


/* =========================================================
   ALERTS
========================================================= */

.alert-box {

    border-radius: 10px;

    padding: 13px 15px;

    margin-bottom: 18px;

    font-size: 14px;
}


.alert-success {

    background: #dcfce7;

    color: #166534;

    border:
        1px solid #bbf7d0;
}


.alert-error {

    background: #fee2e2;

    color: #991b1b;

    border:
        1px solid #fecaca;
}


/* =========================================================
   CARD
========================================================= */

.settings-card {

    background: #ffffff;

    border:
        1px solid #e1e5e9;

    border-radius: 14px;

    padding: 24px;

    margin-bottom: 20px;
    margin-top: 20px;

    box-shadow:
        0 4px 18px rgba(0,0,0,.06);
}


.section-title {

    font-size: 17px;

    font-weight: 700;

    margin-bottom: 18px;

    padding-bottom: 10px;

    border-bottom:
        1px solid #e9ecef;
}


.section-title i {

    color: #0d6efd;

    margin-right: 7px;
}


/* =========================================================
   FORM
========================================================= */

.form-label {

    font-size: 13px;

    font-weight: 600;

    margin-bottom: 6px;
}


.form-control {

    border-radius: 8px;

    min-height: 42px;
}


.form-control:focus {

    border-color: #86b7fe;

    box-shadow:
        0 0 0 3px rgba(
            13,
            110,
            253,
            .10
        );
}


textarea.form-control {

    min-height: 110px;

    resize: vertical;
}


/* =========================================================
   QR
========================================================= */

.qr-preview {

    margin-top: 10px;

    padding: 12px;

    border:
        1px solid #dee2e6;

    border-radius: 10px;

    background: #f8f9fa;

    display: inline-block;
}


.qr-preview img {

    width: 180px;

    height: 180px;

    object-fit: contain;

    display: block;
}


.qr-placeholder {

    width: 180px;

    height: 180px;

    display: flex;

    align-items: center;

    justify-content: center;

    text-align: center;

    border:
        2px dashed #ced4da;

    border-radius: 10px;

    color: #6c757d;

    font-size: 13px;

    background: #ffffff;
}


/* =========================================================
   ACTIVE
========================================================= */

.active-toggle {

    display: flex;

    align-items: center;

    gap: 10px;

    padding: 12px;

    background: #f8f9fa;

    border:
        1px solid #e9ecef;

    border-radius: 9px;
}


.active-toggle label {

    margin: 0;

    font-size: 13px;

    font-weight: 600;
}


/* =========================================================
   BUTTON
========================================================= */

.save-btn {

    border: 0;

    border-radius: 8px;

    padding:
        10px 20px;

    background: #0d6efd;

    color: #ffffff;

    font-weight: 600;

    font-size: 14px;
}


.save-btn:hover {

    background: #0b5ed7;
}


/* =========================================================
   INFO
========================================================= */

.info-box {

    background: #eef6ff;

    border:
        1px solid #cfe2ff;

    color: #084298;

    border-radius: 10px;

    padding: 13px 15px;

    font-size: 13px;
}



/* =========================================================
   PAYMENT HISTORY
========================================================= */

.payment-history-card {
    background: #ffffff;
    border: 1px solid #e1e5e9;
    border-radius: 14px;
    padding: 24px;
    margin-top: 24px;
    box-shadow: 0 4px 18px rgba(0,0,0,.06);
}

.history-summary {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
    margin-bottom: 18px;
}

.history-stat {
    border: 1px solid #e6e9ed;
    border-radius: 10px;
    padding: 14px;
    background: #f8f9fa;
}

.history-stat .label {
    font-size: 12px;
    color: #6c757d;
    margin-bottom: 4px;
}

.history-stat .value {
    font-size: 22px;
    font-weight: 700;
    color: #212529;
}

.history-filter {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
    margin-bottom: 18px;
}

.history-filter select {
    min-width: 180px;
}

.history-table-wrap {
    overflow-x: auto;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
}

.history-table {
    width: 100%;
    min-width: 1450px;
    margin: 0;
    border-collapse: collapse;
}

.history-table th {
    background: #f8f9fa;
    color: #495057;
    font-size: 12px;
    font-weight: 700;
    white-space: nowrap;
    padding: 12px 10px;
    border-bottom: 1px solid #dee2e6;
}

.history-table td {
    padding: 12px 10px;
    font-size: 12px;
    vertical-align: middle;
    border-bottom: 1px solid #edf0f2;
}

.history-table tbody tr:hover {
    background: #f8fbff;
}

.history-owner {
    font-weight: 700;
    color: #17324d;
}

.history-sub {
    display: block;
    color: #6c757d;
    font-size: 11px;
    margin-top: 2px;
}

.payment-status {
    display: inline-flex;
    align-items: center;
    padding: 4px 9px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
}

.payment-status.submitted,
.payment-status.pending,
.payment-status.under_review {
    background: #fff3cd;
    color: #856404;
}

.payment-status.verified,
.payment-status.approved,
.payment-status.paid {
    background: #d1e7dd;
    color: #0f5132;
}

.payment-status.rejected,
.payment-status.failed {
    background: #f8d7da;
    color: #842029;
}

.payment-status.other {
    background: #e2e3e5;
    color: #41464b;
}

.history-empty {
    padding: 35px 20px;
    text-align: center;
    color: #6c757d;
}

.history-proof {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    text-decoration: none;
}

.history-proof:hover {
    text-decoration: underline;
}

.history-filter-btn {
    border-radius: 8px;
}

.history-clear {
    text-decoration: none;
}


.payment-action-group {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    align-items: center;
}

.payment-action-group .btn {
    white-space: nowrap;
}


/* =========================================================
   SELF-CONTAINED PAYMENT REJECTION MODAL
========================================================= */

.payment-modal-open {
    overflow: hidden;
}

.payment-reject-modal {
    position: fixed;
    inset: 0;
    z-index: 99999;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.payment-reject-modal.show {
    display: flex;
}

.payment-reject-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(0,0,0,.58);
}

.payment-reject-dialog {
    position: relative;
    z-index: 2;
    width: min(520px, 100%);
    background: #ffffff;
    border-radius: 16px;
    box-shadow: 0 20px 60px rgba(0,0,0,.28);
    overflow: hidden;
    animation: paymentRejectIn .16s ease-out;
}

@keyframes paymentRejectIn {
    from {
        opacity: 0;
        transform: translateY(12px) scale(.98);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

.payment-reject-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 15px;
    padding: 20px 22px;
    border-bottom: 1px solid #e9ecef;
}

.payment-reject-title {
    font-size: 18px;
    font-weight: 700;
    color: #842029;
}

.payment-reject-title i {
    margin-right: 7px;
}

.payment-reject-subtitle {
    margin-top: 5px;
    color: #6c757d;
    font-size: 12px;
}

.payment-reject-close {
    border: 0;
    background: transparent;
    color: #6c757d;
    font-size: 28px;
    line-height: 1;
    cursor: pointer;
    padding: 0 3px;
}

.payment-reject-body {
    padding: 20px 22px;
}

.payment-reject-warning {
    background: #fff3cd;
    border: 1px solid #ffecb5;
    color: #664d03;
    border-radius: 10px;
    padding: 12px 13px;
    font-size: 13px;
    margin-bottom: 17px;
}

.payment-reject-footer {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    padding: 15px 22px;
    border-top: 1px solid #e9ecef;
    background: #f8f9fa;
}

@media (max-width: 900px) {
    .history-summary {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 768px) {

    .payment-settings-page {

        padding: 12px;
    }


    .settings-card {

        padding: 16px;
    }
}

</style>


<div class="content">

<div class="payment-settings-page">

<div class="payment-settings-container">


    <!-- =====================================================
         HEADER
    ====================================================== -->

    <div class="settings-header">

        <h2>

            <i class="bi bi-credit-card-2-front me-2"></i>

            Payment Settings

        </h2>

        <p>

            Manage the UPI, QR and bank details displayed to Owners
            during payment.

        </p>

    </div>


    <!-- =====================================================
         SUCCESS
    ====================================================== -->

    <?php if ($success !== ''): ?>

        <div
            class="alert-box alert-success"
        >

            <i
                class="bi bi-check-circle-fill me-2">
            </i>

            <?= nletaPaymentSettingsH($success) ?>

        </div>

    <?php endif; ?>


    <!-- =====================================================
         ERROR
    ====================================================== -->

    <?php if ($error !== ''): ?>

        <div
            class="alert-box alert-error"
        >

            <i
                class="bi bi-exclamation-triangle-fill me-2">
            </i>

            <?= nletaPaymentSettingsH($error) ?>

        </div>

    <?php endif; ?>


    <!-- =================================================
         PAYMENT HISTORY
    ================================================== -->

    <div class="payment-history-card">

        <div class="section-title">

            <i class="bi bi-clock-history"></i>

            Payment History

        </div>

        <div class="info-box mb-3">

            <i class="bi bi-info-circle me-2"></i>

            Completed payments remain in Payment History for audit purposes
            while their Service Request exists. If a Service Request is
            permanently deleted, its related payment history is removed
            from this page automatically.

        </div>

        <div class="history-summary">

            <div class="history-stat">
                <div class="label">All Payments</div>
                <div class="value"><?= $historyTotal ?></div>
            </div>

            <div class="history-stat">
                <div class="label">Submitted / Pending</div>
                <div class="value"><?= $historySubmitted ?></div>
            </div>

            <div class="history-stat">
                <div class="label">Verified</div>
                <div class="value"><?= $historyVerified ?></div>
            </div>

            <div class="history-stat">
                <div class="label">Rejected</div>
                <div class="value"><?= $historyRejected ?></div>
            </div>

            <div class="history-stat">
                <div class="label">Payments Completed</div>
                <div class="value"><?= $historyCompleted ?></div>
            </div>

        </div>


        <form
            method="GET"
            class="history-filter"
        >

            <select
                name="history_status"
                class="form-select"
            >

                <option value="">
                    All Payment Status
                </option>

                <option
                    value="submitted"
                    <?= $historyStatus === 'submitted'
                        ? 'selected'
                        : '' ?>
                >
                    Submitted
                </option>

                <option
                    value="pending"
                    <?= $historyStatus === 'pending'
                        ? 'selected'
                        : '' ?>
                >
                    Pending
                </option>

                <option
                    value="under_review"
                    <?= $historyStatus === 'under_review'
                        ? 'selected'
                        : '' ?>
                >
                    Under Review
                </option>

                <option
                    value="verified"
                    <?= $historyStatus === 'verified'
                        ? 'selected'
                        : '' ?>
                >
                    Verified
                </option>

                <option
                    value="rejected"
                    <?= $historyStatus === 'rejected'
                        ? 'selected'
                        : '' ?>
                >
                    Rejected
                </option>

                <option
                    value="paid"
                    <?= $historyStatus === 'paid'
                        ? 'selected'
                        : '' ?>
                >
                    Payments Completed
                </option>

            </select>


            <button
                type="submit"
                class="btn btn-primary history-filter-btn"
            >

                <i class="bi bi-funnel me-1"></i>

                Filter

            </button>


            <?php if ($historyStatus !== ''): ?>

                <a
                    href="<?= nletaPaymentSettingsH(
                        strtok(
                            $_SERVER['REQUEST_URI'],
                            '?'
                        )
                    ) ?>"
                    class="history-clear"
                >
                    Clear Filter
                </a>

            <?php endif; ?>

        </form>


        <?php if ($historyError !== ''): ?>

            <div class="alert-box alert-error">

                <i class="bi bi-exclamation-triangle-fill me-2"></i>

                <?= nletaPaymentSettingsH($historyError) ?>

            </div>

        <?php elseif (!$paymentHistory): ?>

            <div class="history-empty">

                <i
                    class="bi bi-receipt"
                    style="font-size:32px;"
                ></i>

                <div class="mt-2 fw-semibold">
                    No payment history found.
                </div>

                <div class="small">
                    Payment submissions will appear here.
                </div>

            </div>

        <?php else: ?>

            <div class="history-table-wrap">

                <table class="history-table">

                    <thead>

                    <tr>

                        <th>#</th>

                        <th>Action</th>

                        <th>Owner</th>

                        <th>Offer / PSGA</th>

                        <th>Asset</th>

                        <th>Payment Stage</th>

                        <th>Amount</th>

                        <th>Method</th>

                        <th>UTR / Reference</th>

                        <th>Payment Date</th>

                        <th>Status</th>

                        <th>Proof</th>

                        <th>Submitted</th>

                    </tr>

                    </thead>

                    <tbody>

                    <?php foreach (
                        $paymentHistory
                        as $index =>
                        $payment
                    ): ?>

                        <?php

                        $status =
                            strtolower(
                                trim(
                                    (string)(
                                        $payment['Status']
                                        ?? 'Unknown'
                                    )
                                )
                            );

                        /*
                         * Overall payment completion:
                         * When the complete payment amount has been
                         * received and balance is zero, show
                         * "Payments Completed" even when the individual
                         * transaction itself is Verified.
                         */
                        $overallPaymentStatus =
                            strtolower(
                                trim(
                                    (string)(
                                        $payment['Payment_Status']
                                        ?? ''
                                    )
                                )
                            );

                        $paymentBalance =
                            (float)(
                                $payment['Balance_Amount']
                                ?? 0
                            );

                        $paymentTotal =
                            (float)(
                                $payment['Total_Amount']
                                ?? 0
                            );

                        $allPaymentsCompleted =
                            (
                                $overallPaymentStatus === 'paid'
                                ||
                                (
                                    $paymentTotal > 0
                                    &&
                                    $paymentBalance <= 0.01
                                )
                            );

                        $statusClass =
                            in_array(
                                $status,
                                [
                                    'submitted',
                                    'pending',
                                    'under_review',
                                    'verified',
                                    'approved',
                                    'paid',
                                    'rejected',
                                    'failed'
                                ],
                                true
                            )
                                ? $status
                                : 'other';

                        $ownerName =
                            $payment['owner_name']
                            ?? '—';

                        $offerNo =
                            $payment['offer_no']
                            ?? '—';

                        $psgaNo =
                            $payment['psga_no']
                            ?? '—';

                        $assetName =
                            $payment['asset_names']
                            ?? '—';

                        $stage =
                            $payment['Payment_Stage']
                            ?? '—';

                        $amount =
                            $payment['Submitted_Amount']
                            ?? null;

                        $method =
                            $payment['Payment_Method']
                            ?? '—';

                        $utr =
                            $payment['Transaction_Reference']
                            ?? '—';

                        $paymentDate =
                            $payment['Payment_Date']
                            ?? null;

                        $submittedAt =
                            $payment['Submitted_At']
                            ?? $payment['Created_At']
                            ?? null;

                        $proof =
                            $payment['Proof_File']
                            ?? null;

                        ?>

                        <tr>



                            <td>
                                <?= $index + 1 ?>
                            </td>


                            <td>

                                <?php if (
                                    $status === 'submitted'
                                ): ?>

                                    <div class="payment-action-group">

                                        <form
                                            method="POST"
                                            class="d-inline"
                                            onsubmit="return confirm('Verify this payment? The amount will be added to the payment totals and the next payment stage may be unlocked.');"
                                        >

                                            <input
                                                type="hidden"
                                                name="payment_action"
                                                value="verify"
                                            >

                                            <input
                                                type="hidden"
                                                name="transaction_id"
                                                value="<?= (int)(
                                                    $payment['Transaction_ID']
                                                ) ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="btn btn-sm btn-success"
                                            >

                                                <i class="bi bi-check-circle me-1"></i>
                                                Verify

                                            </button>

                                        </form>


                                        <button
                                            type="button"
                                            class="btn btn-sm btn-danger"
                                            onclick="openRejectPaymentModal(
                                                <?= (int)(
                                                    $payment['Transaction_ID']
                                                ) ?>
                                            )"
                                        >

                                            <i class="bi bi-x-circle me-1"></i>
                                            Reject

                                        </button>

                                    </div>

                                <?php elseif (
                                    $status === 'verified'
                                ): ?>

                                    <span class="text-success fw-semibold">

                                        <i class="bi bi-patch-check-fill me-1"></i>
                                        Verified

                                    </span>

                                    <?php if (
                                        !empty(
                                            $payment['Verified_At']
                                        )
                                    ): ?>

                                        <span class="history-sub">

                                            <?= nletaPaymentSettingsH(
                                                formatIstDateTime(
                                                    $payment['Verified_At']
                                                )
                                            ) ?>

                                        </span>

                                    <?php endif; ?>

                                <?php elseif (
                                    $status === 'rejected'
                                ): ?>

                                    <span class="text-danger fw-semibold">

                                        <i class="bi bi-x-circle-fill me-1"></i>
                                        Rejected

                                    </span>

                                <?php else: ?>

                                    —

                                <?php endif; ?>

                            </td>


                            <td>

                                <span class="history-owner">
                                    <?= nletaPaymentSettingsH($ownerName) ?>
                                </span>

                                <?php if (
                                    !empty(
                                        $payment['owner_email']
                                    )
                                ): ?>

                                    <span class="history-sub">
                                        <?= nletaPaymentSettingsH(
                                            $payment['owner_email']
                                        ) ?>
                                    </span>

                                <?php endif; ?>

                            </td>


                            <td>

                                <strong>
                                    <?= nletaPaymentSettingsH($offerNo) ?>
                                </strong>

                                <span class="history-sub">
                                    PSGA:
                                    <?= nletaPaymentSettingsH($psgaNo) ?>
                                </span>

                            </td>


                            <td>

                                <?= nletaPaymentSettingsH($assetName) ?>

                                <?php if (
                                    !empty(
                                        $payment['asset_locations']
                                    )
                                ): ?>

                                    <span class="history-sub">
                                        <?= nletaPaymentSettingsH(
                                            $payment['asset_locations']
                                        ) ?>
                                    </span>

                                <?php endif; ?>

                            </td>


                            <td>
                                <?= nletaPaymentSettingsH($stage) ?>
                            </td>


                            <td>

                                <?php if (
                                    $amount !== null
                                    &&
                                    $amount !== ''
                                ): ?>

                                    ₹<?= number_format(
                                        (float)$amount,
                                        2
                                    ) ?>

                                <?php else: ?>

                                    —

                                <?php endif; ?>

                                <?php if (
                                    $paymentTotal > 0
                                ): ?>

                                    <span class="history-sub">
                                        Total:
                                        ₹<?= number_format(
                                            $paymentTotal,
                                            2
                                        ) ?>
                                    </span>

                                    <span class="history-sub">
                                        Balance:
                                        ₹<?= number_format(
                                            $paymentBalance,
                                            2
                                        ) ?>
                                    </span>

                                <?php endif; ?>

                            </td>


                            <td>
                                <?= nletaPaymentSettingsH($method) ?>
                            </td>


                            <td>
                                <?= nletaPaymentSettingsH($utr) ?>
                            </td>


                            <td>

                                <?php if (
                                    $paymentDate
                                ): ?>

                                    <?= nletaPaymentSettingsH(
                                        formatIstDateOnly(
                                            substr(
                                                (string)$paymentDate,
                                                0,
                                                10
                                            )
                                        )
                                    ) ?>

                                <?php else: ?>

                                    —

                                <?php endif; ?>

                            </td>


                            <td>

                                <?php if (
                                    $allPaymentsCompleted
                                ): ?>

                                    <span
                                        class="payment-status paid"
                                    >

                                        <i class="bi bi-check-circle-fill me-1"></i>

                                        Payments Completed

                                    </span>

                                <?php else: ?>

                                    <span
                                        class="payment-status <?= nletaPaymentSettingsH(
                                            $statusClass
                                        ) ?>"
                                    >

                                        <?= nletaPaymentSettingsH(
                                            ucwords(
                                                str_replace(
                                                    '_',
                                                    ' ',
                                                    $status
                                                )
                                            )
                                        ) ?>

                                    </span>

                                <?php endif; ?>

                                <?php if (
                                    !empty(
                                        $payment['Rejection_Reason']
                                    )
                                ): ?>

                                    <span
                                        class="history-sub"
                                        title="<?= nletaPaymentSettingsH(
                                            $payment[
                                                'Rejection_Reason'
                                            ]
                                        ) ?>"
                                    >

                                        Rejection reason available

                                    </span>

                                <?php endif; ?>

                            </td>


                            <td>

                                <?php if (
                                    $proof
                                ): ?>

                                    <?php

                                    /*
                                     * Owner payment logic stores files in:
                                     *
                                     * owner/uploads/payments/
                                     *
                                     * Keep only the filename here so an
                                     * unexpected path cannot escape the
                                     * payment-proof directory.
                                     */

                                    $proofFileName =
                                        basename(
                                            (string)$proof
                                        );

                                    $proofDiskPath =
                                        __DIR__
                                        . '/../owner/uploads/payments/'
                                        . $proofFileName;

                                    $proofWebPath =
                                        '../owner/uploads/payments/'
                                        . rawurlencode(
                                            $proofFileName
                                        );

                                    ?>

                                    <?php if (
                                        $proofFileName !== ''
                                        &&
                                        is_file($proofDiskPath)
                                    ): ?>

                                        <a
                                            href="<?= nletaPaymentSettingsH($proofWebPath) ?>"
                                            target="_blank"
                                            rel="noopener"
                                            class="history-proof"
                                        >

                                            <i
                                                class="bi bi-paperclip"
                                            ></i>

                                            View

                                        </a>

                                    <?php else: ?>

                                        <span
                                            class="text-warning"
                                            title="Payment proof file was not found in owner/uploads/payments/"
                                        >

                                            <i
                                                class="bi bi-exclamation-triangle me-1"
                                            ></i>

                                            File Not Found

                                        </span>

                                    <?php endif; ?>

                                <?php else: ?>

                                    —

                                <?php endif; ?>

                            </td>


                            <td>

                                <?php if (
                                    $submittedAt
                                ): ?>

                                    <?= nletaPaymentSettingsH(
                                        formatIstDateTime(
                                            $submittedAt
                                        )
                                    ) ?>

                                <?php else: ?>

                                    —

                                <?php endif; ?>

                            </td>




                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </div>


    <!-- =====================================================
         FORM
    ====================================================== -->

    <form
        method="POST"
        enctype="multipart/form-data"
    >


        <input
            type="hidden"
            name="save_settings"
            value="1"
        >


        <!-- =================================================
             UPI
        ================================================== -->

        <div class="settings-card">

            <div class="section-title">

                <i class="bi bi-phone"></i>

                UPI Payment

            </div>


            <div class="row g-3">


                <div class="col-md-6">

                    <label class="form-label">

                        UPI ID

                    </label>

                    <input
                        type="text"
                        name="upi_id"
                        class="form-control"
                        maxlength="150"
                        value="<?= nletaPaymentSettingsH(
                            $settings['upi_id']
                            ?? ''
                        ) ?>"
                        placeholder="example@nleta"
                    >

                </div>


                <div class="col-md-6">

                    <label class="form-label">

                        UPI Name

                    </label>

                    <input
                        type="text"
                        name="upi_name"
                        class="form-control"
                        maxlength="150"
                        value="<?= nletaPaymentSettingsH(
                            $settings['upi_name']
                            ?? ''
                        ) ?>"
                        placeholder="NLETA Services"
                    >

                </div>

            </div>

        </div>


        <!-- =================================================
             QR
        ================================================== -->

        <div class="settings-card">

            <div class="section-title">

                <i class="bi bi-qr-code"></i>

                QR Code

            </div>


            <div class="row g-4 align-items-start">


                <div class="col-md-6">

                    <label class="form-label">

                        Upload QR Code

                    </label>


                    <input
                        type="file"
                        name="qr_code"
                        class="form-control"
                        accept=".png,.jpg,.jpeg"
                    >


                    <div
                        class="text-muted mt-2"
                        style="font-size:12px;"
                    >

                        PNG, JPG or JPEG.
                        Maximum 5 MB.

                    </div>

                </div>


                <div class="col-md-6">

                    <label class="form-label">

                        Current QR Code

                    </label>


                    <?php if (
                        !empty(
                            $settings['qr_code']
                        )
                    ): ?>

                        <div class="qr-preview">

                            <img
                                src="../<?= nletaPaymentSettingsH(
                                    $settings['qr_code']
                                ) ?>"
                                alt="NLETA Payment QR"
                            >

                        </div>

                    <?php else: ?>

                        <div class="qr-placeholder">

                            QR code will appear here
                            after upload.

                        </div>

                    <?php endif; ?>

                </div>

            </div>

        </div>


        <!-- =================================================
             BANK
        ================================================== -->

        <div class="settings-card">

            <div class="section-title">

                <i class="bi bi-bank"></i>

                Bank Transfer

            </div>


            <div class="row g-3">


                <div class="col-md-6">

                    <label class="form-label">

                        Bank Name

                    </label>

                    <input
                        type="text"
                        name="bank_name"
                        class="form-control"
                        maxlength="150"
                        value="<?= nletaPaymentSettingsH(
                            $settings['bank_name']
                            ?? ''
                        ) ?>"
                    >

                </div>


                <div class="col-md-6">

                    <label class="form-label">

                        Account Name

                    </label>

                    <input
                        type="text"
                        name="account_name"
                        class="form-control"
                        maxlength="200"
                        value="<?= nletaPaymentSettingsH(
                            $settings['account_name']
                            ?? ''
                        ) ?>"
                    >

                </div>


                <div class="col-md-6">

                    <label class="form-label">

                        Account Number

                    </label>

                    <input
                        type="text"
                        name="account_number"
                        class="form-control"
                        maxlength="100"
                        value="<?= nletaPaymentSettingsH(
                            $settings['account_number']
                            ?? ''
                        ) ?>"
                    >

                </div>


                <div class="col-md-6">

                    <label class="form-label">

                        IFSC

                    </label>

                    <input
                        type="text"
                        name="ifsc"
                        class="form-control"
                        maxlength="50"
                        value="<?= nletaPaymentSettingsH(
                            $settings['ifsc']
                            ?? ''
                        ) ?>"
                    >

                </div>


                <div class="col-md-6">

                    <label class="form-label">

                        Branch

                    </label>

                    <input
                        type="text"
                        name="branch"
                        class="form-control"
                        maxlength="150"
                        value="<?= nletaPaymentSettingsH(
                            $settings['branch']
                            ?? ''
                        ) ?>"
                    >

                </div>

            </div>

        </div>


        <!-- =================================================
             INSTRUCTIONS
        ================================================== -->

        <div class="settings-card">

            <div class="section-title">

                <i class="bi bi-info-circle"></i>

                Payment Instructions

            </div>


            <textarea
                name="payment_instructions"
                class="form-control"
                placeholder="Enter instructions shown to Owners..."
            ><?= nletaPaymentSettingsH(
                $settings[
                    'payment_instructions'
                ] ?? ''
            ) ?></textarea>

        </div>


        <!-- =================================================
             ACTIVE
        ================================================== -->

        <div class="settings-card">

            <div class="section-title">

                <i class="bi bi-toggle-on"></i>

                Payment Availability

            </div>


            <div class="active-toggle">

                <input
                    type="checkbox"
                    class="form-check-input"
                    id="is_active"
                    name="is_active"
                    value="1"
                    <?= !empty(
                        $settings['is_active']
                    )
                        ? 'checked'
                        : ''
                    ?>
                >

                <label
                    for="is_active"
                >

                    Show these payment details
                    to Owners

                </label>

            </div>

        </div>


        <!-- =================================================
             INFO
        ================================================== -->

        <div class="info-box mb-3">

            <i
                class="bi bi-shield-check me-2">
            </i>

            These details are payment instructions only.
            The actual payment is verified by Admin using
            the Owner's submitted transaction/reference number
            and payment proof.

        </div>


        <!-- =================================================
             SAVE
        ================================================== -->

        <div class="d-flex justify-content-end">

            <button
                type="submit"
                class="save-btn"
            >

                <i
                    class="bi bi-save me-1">
                </i>

                Save Payment Settings

            </button>

        </div>



    </form>

</div>

</div>


<!-- =========================================================
     PAYMENT REJECTION MODAL
     Self-contained modal — no Bootstrap JavaScript required
========================================================= -->

<div
    id="rejectPaymentModal"
    class="payment-reject-modal"
    aria-hidden="true"
>

    <div
        class="payment-reject-backdrop"
        onclick="closeRejectPaymentModal()"
    ></div>

    <div
        class="payment-reject-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="rejectPaymentTitle"
    >

        <form method="POST">

            <div class="payment-reject-header">

                <div>

                    <div
                        id="rejectPaymentTitle"
                        class="payment-reject-title"
                    >

                        <i class="bi bi-x-circle-fill"></i>

                        Reject Payment

                    </div>

                    <div class="payment-reject-subtitle">

                        The Owner will be allowed to resubmit
                        this payment after rejection.

                    </div>

                </div>

                <button
                    type="button"
                    class="payment-reject-close"
                    onclick="closeRejectPaymentModal()"
                    aria-label="Close"
                >
                    &times;
                </button>

            </div>


            <div class="payment-reject-body">

                <input
                    type="hidden"
                    name="payment_action"
                    value="reject"
                >

                <input
                    type="hidden"
                    name="transaction_id"
                    id="rejectTransactionId"
                    value=""
                >


                <div class="payment-reject-warning">

                    <i class="bi bi-info-circle-fill me-2"></i>

                    Rejecting the payment will return the
                    current payment stage to <strong>Pending</strong>
                    so the Owner can submit it again.

                </div>


                <label
                    class="form-label fw-semibold"
                    for="rejectionReason"
                >

                    Rejection Reason
                    <span class="text-danger">*</span>

                </label>

                <textarea
                    id="rejectionReason"
                    name="rejection_reason"
                    class="form-control"
                    rows="4"
                    maxlength="1000"
                    required
                    placeholder="Enter the reason for rejecting this payment..."
                ></textarea>

                <div class="text-muted mt-1" style="font-size:12px;">

                    Please provide a clear reason for the Owner.

                </div>

            </div>


            <div class="payment-reject-footer">

                <button
                    type="button"
                    class="btn btn-outline-secondary"
                    onclick="closeRejectPaymentModal()"
                >
                    Cancel
                </button>

                <button
                    type="submit"
                    class="btn btn-danger"
                >

                    <i class="bi bi-x-circle me-1"></i>

                    Reject Payment

                </button>

            </div>

        </form>

    </div>

</div>


<script>

/* =========================================================
   REJECT PAYMENT MODAL
========================================================= */

function openRejectPaymentModal(transactionId)
{
    const modal =
        document.getElementById(
            'rejectPaymentModal'
        );

    const input =
        document.getElementById(
            'rejectTransactionId'
        );

    const reason =
        document.getElementById(
            'rejectionReason'
        );

    if (!modal || !input) {
        return;
    }

    input.value = transactionId;

    if (reason) {
        reason.value = '';
    }

    modal.classList.add('show');

    modal.setAttribute(
        'aria-hidden',
        'false'
    );

    document.body.classList.add(
        'payment-modal-open'
    );

    setTimeout(function () {

        if (reason) {
            reason.focus();
        }

    }, 100);
}


function closeRejectPaymentModal()
{
    const modal =
        document.getElementById(
            'rejectPaymentModal'
        );

    if (!modal) {
        return;
    }

    modal.classList.remove('show');

    modal.setAttribute(
        'aria-hidden',
        'true'
    );

    document.body.classList.remove(
        'payment-modal-open'
    );
}


document.addEventListener(
    'keydown',
    function (event) {

        if (
            event.key === 'Escape'
        ) {

            closeRejectPaymentModal();
        }

    }
);

</script>