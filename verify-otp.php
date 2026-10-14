<?php

/* =========================================================
   NLETA - OTP VERIFICATION

   PURPOSES:

   signup
   password_reset

   FLOW:

   Signup:
       Signup
          ↓
       OTP
          ↓
       Verify
          ↓
       Account Active
          ↓
       Login

   Password Reset:
       Forgot Password
          ↓
       OTP
          ↓
       Verify
          ↓
       New Password
          ↓
       Automatic Login
========================================================= */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/otp-mailer.php';


/* =========================================================
   SESSION
========================================================= */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


/* =========================================================
   SETTINGS
========================================================= */

if (!defined('OTP_EXPIRY_MINUTES')) {
    define('OTP_EXPIRY_MINUTES', 10);
}

if (!defined('OTP_MAX_ATTEMPTS')) {
    define('OTP_MAX_ATTEMPTS', 5);
}

if (!defined('OTP_RESEND_COOLDOWN')) {
    define('OTP_RESEND_COOLDOWN', 60);
}


/* =========================================================
   SESSION CHECK
========================================================= */

if (
    empty($_SESSION['otp_user_id']) ||
    empty($_SESSION['otp_email']) ||
    empty($_SESSION['otp_purpose'])
) {

    header("Location: login.php");
    exit;
}


$userId =
    (int)$_SESSION['otp_user_id'];

$email =
    $_SESSION['otp_email'];

$purpose =
    $_SESSION['otp_purpose'];


/* =========================================================
   PURPOSE CHECK
========================================================= */

if (
    !in_array(
        $purpose,
        ['signup', 'password_reset'],
        true
    )
) {

    unset(
        $_SESSION['otp_user_id'],
        $_SESSION['otp_email'],
        $_SESSION['otp_purpose'],
        $_SESSION['otp_last_resend'],
        $_SESSION['password_reset_verified']
    );


    header("Location: login.php");
    exit;
}


/* =========================================================
   VARIABLES
========================================================= */

$errors = [];

$success = '';

$remainingSeconds = 0;

$resendCooldown = 0;


/* =========================================================
   GET USER
========================================================= */

$stmt =
    $conn->prepare(
        "
        SELECT
            id,
            name,
            email,
            role,
            email_verified,
            account_status
        FROM users
        WHERE id = ?
        LIMIT 1
        "
    );


if (!$stmt) {

    die(
        'Unable to process OTP verification.'
    );

}


$stmt->bind_param(
    "i",
    $userId
);


$stmt->execute();


$user =
    $stmt
        ->get_result()
        ->fetch_assoc();


$stmt->close();


/* =========================================================
   USER NOT FOUND
========================================================= */

if (!$user) {

    unset(
        $_SESSION['otp_user_id'],
        $_SESSION['otp_email'],
        $_SESSION['otp_purpose'],
        $_SESSION['otp_last_resend'],
        $_SESSION['password_reset_verified']
    );


    header("Location: login.php");
    exit;
}


$email =
    $user['email'];


/* =========================================================
   GET ACTIVE OTP
========================================================= */

function getActiveOtp(
    mysqli $conn,
    int $userId,
    string $purpose
): ?array {

    $stmt =
        $conn->prepare(
            "
            SELECT
                id,
                otp_hash,
                expires_at,
                attempts,
                created_at
            FROM email_otps
            WHERE user_id = ?
              AND purpose = ?
              AND verified_at IS NULL
            ORDER BY id DESC
            LIMIT 1
            "
        );


    if (!$stmt) {
        return null;
    }


    $stmt->bind_param(
        "is",
        $userId,
        $purpose
    );


    $stmt->execute();


    $row =
        $stmt
            ->get_result()
            ->fetch_assoc();


    $stmt->close();


    return $row ?: null;
}


/* =========================================================
   RESEND OTP
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['resend_otp'])
) {


    /* =====================================================
       CSRF
    ===================================================== */

    if (
        !validateCSRFToken(
            $_POST['csrf_token'] ?? ''
        )
    ) {

        $errors[] =
            'Invalid request. Please refresh the page and try again.';

    } else {


        /* =================================================
           COOLDOWN
        ================================================= */

        $lastResend =
            (int)(
                $_SESSION['otp_last_resend'] ?? 0
            );


        $elapsed =
            time() - $lastResend;


        if (
            $lastResend > 0 &&
            $elapsed < OTP_RESEND_COOLDOWN
        ) {

            $resendCooldown =
                OTP_RESEND_COOLDOWN - $elapsed;


            $errors[] =
                'Please wait ' .
                $resendCooldown .
                ' seconds before requesting another OTP.';

        } else {


            /* =================================================
               GENERATE NEW OTP
            ================================================= */

            try {

                $newOtp =
                    (string)random_int(
                        100000,
                        999999
                    );

            } catch (Throwable $e) {

                $newOtp = null;

                $errors[] =
                    'Unable to generate a new OTP. Please try again.';

            }


            if (
                $newOtp !== null
            ) {


                /* =================================================
                   HASH
                ================================================= */

                $newOtpHash =
                    password_hash(
                        $newOtp,
                        PASSWORD_DEFAULT
                    );


                if (
                    $newOtpHash === false
                ) {

                    $errors[] =
                        'Unable to secure the new OTP. Please try again.';

                } else {


                    /* =================================================
                       EXPIRY
                    ================================================= */

                    $newExpiresAt =
                        date(
                            'Y-m-d H:i:s',
                            time() +
                            (
                                OTP_EXPIRY_MINUTES *
                                60
                            )
                        );


                    /*
                     * Delete any previous OTP.
                     */

                    $deleteStmt =
                        $conn->prepare(
                            "
                            DELETE FROM email_otps
                            WHERE user_id = ?
                              AND purpose = ?
                              AND verified_at IS NULL
                            "
                        );


                    if ($deleteStmt) {

                        $deleteStmt->bind_param(
                            "is",
                            $userId,
                            $purpose
                        );


                        $deleteStmt->execute();


                        $deleteStmt->close();

                    }


                    /* =================================================
                       INSERT NEW OTP
                    ================================================= */

                    $insertStmt =
                        $conn->prepare(
                            "
                            INSERT INTO email_otps
                            (
                                user_id,
                                otp_hash,
                                purpose,
                                expires_at,
                                attempts,
                                verified_at,
                                created_at
                            )
                            VALUES
                            (
                                ?,
                                ?,
                                ?,
                                ?,
                                0,
                                NULL,
                                CURRENT_TIMESTAMP
                            )
                            "
                        );


                    if (!$insertStmt) {

                        $errors[] =
                            'Unable to create new OTP.';

                    } else {


                        $insertStmt->bind_param(
                            "isss",
                            $userId,
                            $newOtpHash,
                            $purpose,
                            $newExpiresAt
                        );


                        if (
                            !$insertStmt->execute()
                        ) {

                            $errors[] =
                                'Unable to save new OTP.';

                        }


                        $insertStmt->close();


                        /* =================================================
                           SEND EMAIL
                        ================================================= */

                        if (
                            empty($errors)
                        ) {


                            $emailSent =
                                sendNletaOtpEmail(
                                    $user['email'],
                                    $user['name'],
                                    $newOtp,
                                    $purpose
                                );


                            if (!$emailSent) {

                                $errors[] =
                                    'Unable to send OTP email. Please check your SMTP configuration.';

                            } else {


                                $_SESSION['otp_last_resend'] =
                                    time();


                                $success =
                                    'A new OTP has been sent to ' .
                                    $user['email'] .
                                    '.';

                            }

                        }

                    }

                }

            }

        }

    }

}


/* =========================================================
   VERIFY OTP
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['verify_otp'])
) {


    /* =====================================================
       CSRF
    ===================================================== */

    if (
        !validateCSRFToken(
            $_POST['csrf_token'] ?? ''
        )
    ) {

        $errors[] =
            'Invalid request. Please refresh the page and try again.';

    } else {


        $enteredOtp =
            trim(
                $_POST['otp'] ?? ''
            );


        /* =================================================
           FORMAT
        ================================================= */

        if (
            !preg_match(
                '/^[0-9]{6}$/',
                $enteredOtp
            )
        ) {

            $errors[] =
                'Please enter the 6-digit OTP.';

        } else {


            /* =================================================
               GET OTP
            ================================================= */

            $otpRow =
                getActiveOtp(
                    $conn,
                    $userId,
                    $purpose
                );


            if (!$otpRow) {

                $errors[] =
                    'No active OTP was found. Please click "Resend OTP" to receive a new OTP.';

            } else {


                /* =================================================
                   EXPIRY
                ================================================= */

                if (
                    strtotime(
                        $otpRow['expires_at']
                    ) < time()
                ) {

                    $errors[] =
                        'This OTP has expired. Please click "Resend OTP".';

                }

                /* =================================================
                   ATTEMPTS
                ================================================= */

                elseif (
                    (int)$otpRow['attempts'] >=
                    OTP_MAX_ATTEMPTS
                ) {

                    $errors[] =
                        'Too many incorrect attempts. Please click "Resend OTP".';

                }

                /* =================================================
                   CHECK OTP
                ================================================= */

                elseif (
                    !password_verify(
                        $enteredOtp,
                        $otpRow['otp_hash']
                    )
                ) {


                    $attemptStmt =
                        $conn->prepare(
                            "
                            UPDATE email_otps
                            SET attempts = attempts + 1
                            WHERE id = ?
                            LIMIT 1
                            "
                        );


                    if ($attemptStmt) {

                        $otpId =
                            (int)$otpRow['id'];


                        $attemptStmt->bind_param(
                            "i",
                            $otpId
                        );


                        $attemptStmt->execute();


                        $attemptStmt->close();

                    }


                    $errors[] =
                        'Invalid OTP. Please check your code and try again.';

                }

                /* =================================================
                   OTP CORRECT
                ================================================= */

                else {


                    $conn->begin_transaction();


                    try {


                        /* =============================================
                           MARK OTP VERIFIED
                        ============================================= */

                        $verifyStmt =
                            $conn->prepare(
                                "
                                UPDATE email_otps
                                SET verified_at = CURRENT_TIMESTAMP
                                WHERE id = ?
                                LIMIT 1
                                "
                            );


                        if (!$verifyStmt) {

                            throw new Exception(
                                'Unable to verify OTP.'
                            );

                        }


                        $otpId =
                            (int)$otpRow['id'];


                        $verifyStmt->bind_param(
                            "i",
                            $otpId
                        );


                        if (
                            !$verifyStmt->execute()
                        ) {

                            throw new Exception(
                                'Unable to verify OTP.'
                            );

                        }


                        $verifyStmt->close();


                        /* =============================================
                           SIGNUP
                        ============================================= */

                        if (
                            $purpose === 'signup'
                        ) {


                            $activateStmt =
                                $conn->prepare(
                                    "
                                    UPDATE users
                                    SET
                                        email_verified = 1,
                                        email_verified_at = CURRENT_TIMESTAMP,
                                        account_status = 'active'
                                    WHERE id = ?
                                    LIMIT 1
                                    "
                                );


                            if (!$activateStmt) {

                                throw new Exception(
                                    'Unable to activate account.'
                                );

                            }


                            $activateStmt->bind_param(
                                "i",
                                $userId
                            );


                            if (
                                !$activateStmt->execute()
                            ) {

                                throw new Exception(
                                    'Unable to activate account.'
                                );

                            }


                            $activateStmt->close();


                            $conn->commit();


                            /*
                             * Clear OTP session.
                             */

                            unset(
                                $_SESSION['otp_user_id'],
                                $_SESSION['otp_email'],
                                $_SESSION['otp_purpose'],
                                $_SESSION['otp_last_resend']
                            );


                            /*
                             * Send user to login.
                             */

                            header(
                                "Location: login.php?verified=1"
                            );

                            exit;

                        }


                        /* =============================================
                           PASSWORD RESET
                        ============================================= */

                        if (
                            $purpose === 'password_reset'
                        ) {


                            /*
                             * OTP is verified.
                             *
                             * reset-password.php can now
                             * allow password change.
                             */

                            $_SESSION['password_reset_verified'] =
                                true;


                            $conn->commit();


                            /*
                             * Redirect to password page.
                             */

                            header(
                                "Location: reset-password.php"
                            );

                            exit;

                        }


                    } catch (
                        Throwable $e
                    ) {


                        $conn->rollback();


                        error_log(
                            'NLETA OTP Verification Error: ' .
                            $e->getMessage()
                        );


                        $errors[] =
                            'Unable to complete verification. Please try again.';

                    }

                }

            }

        }

    }

}


/* =========================================================
   TIMER
========================================================= */

$otpRow =
    getActiveOtp(
        $conn,
        $userId,
        $purpose
    );


if ($otpRow) {

    $remainingSeconds =
        max(
            0,
            strtotime(
                $otpRow['expires_at']
            ) - time()
        );

}


/* =========================================================
   RESEND TIMER
========================================================= */

$lastResend =
    (int)(
        $_SESSION['otp_last_resend'] ?? 0
    );


if ($lastResend > 0) {

    $resendCooldown =
        max(
            0,
            OTP_RESEND_COOLDOWN -
            (
                time() -
                $lastResend
            )
        );

}


/* =========================================================
   ESCAPE
========================================================= */

function otpEscape(
    $value
): string {

    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );

}

?>

<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<title>NLETA | Verify OTP</title>

<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>

<link
    rel="stylesheet"
    href="assets/css/bootstrap.min.css"
>

<style>

body {

    background:#f4f6f9;

    font-family:
        "Segoe UI",
        Arial,
        sans-serif;

    min-height:100vh;

}


.verify-card {

    max-width:500px;

    margin:60px auto;

    padding:0 15px;

}


.card {

    border:none;

    border-radius:12px;

}


.otp-input {

    font-size:28px;

    letter-spacing:10px;

    text-align:center;

    font-weight:600;

}


.btn-nleta {

    background:#ea232a;

    border:none;

    color:#fff;

}


.btn-nleta:hover {

    background:#333;

    color:#fff;

}


.btn-resend {

    background:#fff;

    border:1px solid #ea232a;

    color:#ea232a;

}


.btn-resend:hover {

    background:#ea232a;

    color:#fff;

}


.btn-resend:disabled {

    opacity:.65;

    cursor:not-allowed;

}


.email-box {

    background:#f8f9fa;

    border-radius:8px;

    padding:12px;

    word-break:break-word;

}


.timer {

    font-weight:600;

    color:#ea232a;

}


.resend-countdown {

    font-size:13px;

    color:#6c757d;

}

</style>

</head>


<body>


<div class="container verify-card">

<div class="card shadow p-4">


<div class="text-center mb-4">

<div style="font-size:45px;">
📧
</div>


<h3>

<?php if ($purpose === 'signup'): ?>

Verify Your Email

<?php else: ?>

Verify Password Reset OTP

<?php endif; ?>

</h3>


<p class="text-muted">

OTP sent to:

</p>


<div class="email-box">

<strong>

<?= otpEscape($email) ?>

</strong>

</div>

</div>


<?php if ($success !== ''): ?>

<div class="alert alert-success">

<?= otpEscape($success) ?>

</div>

<?php endif; ?>


<?php if (!empty($errors)): ?>

<div class="alert alert-danger">

<ul class="mb-0">

<?php foreach ($errors as $error): ?>

<li>

<?= otpEscape($error) ?>

</li>

<?php endforeach; ?>

</ul>

</div>

<?php endif; ?>


<!-- =====================================================
     OTP TIMER
===================================================== -->

<div class="text-center mb-3">

<?php if ($remainingSeconds > 0): ?>

OTP expires in

<span
    id="otpTimer"
    class="timer"
    data-seconds="<?= (int)$remainingSeconds ?>"
>

--:--

</span>

<?php else: ?>

<span class="text-danger">

OTP expired.

</span>

<?php endif; ?>

</div>


<!-- =====================================================
     VERIFY
===================================================== -->

<form
    method="POST"
    autocomplete="off"
>


<div class="mb-3">

<label
    for="otp"
    class="form-label"
>

Enter 6-Digit OTP

</label>


<input
    type="text"
    name="otp"
    id="otp"
    class="form-control otp-input"
    placeholder="000000"
    maxlength="6"
    inputmode="numeric"
    autocomplete="one-time-code"
    required
    autofocus
>

</div>


<?= csrfField() ?>


<button
    type="submit"
    name="verify_otp"
    class="btn btn-nleta w-100"
>

Verify OTP

</button>


</form>


<!-- =====================================================
     RESEND
===================================================== -->

<div class="text-center mt-4">

<p class="text-muted mb-2">

Didn't receive the OTP?

</p>


<form
    method="POST"
    id="resendForm"
>


<?= csrfField() ?>


<button
    type="submit"
    name="resend_otp"
    id="resendBtn"
    class="btn btn-resend w-100"
    <?= $resendCooldown > 0 ? 'disabled' : '' ?>
>

Resend OTP

</button>


</form>


<div
    id="resendCountdown"
    class="resend-countdown mt-2"
    style="<?= $resendCooldown > 0 ? '' : 'display:none;' ?>"
>

You can resend OTP in

<strong id="resendSeconds">

<?= (int)$resendCooldown ?>

</strong>

seconds.

</div>

</div>


<!-- =====================================================
     BACK
===================================================== -->

<div class="text-center mt-3">

<?php if ($purpose === 'signup'): ?>

<a href="signup.php">

← Back to Create Account

</a>

<?php else: ?>

<a href="forgot-password.php">

← Back to Forgot Password

</a>

<?php endif; ?>

</div>


</div>

</div>


<script>

/* =========================================================
   OTP NUMBERS ONLY
========================================================= */

const otpInput =
    document.getElementById('otp');


if (otpInput) {

    otpInput.addEventListener(
        'input',
        function () {

            this.value =
                this.value
                    .replace(/\D/g, '')
                    .slice(0, 6);

        }
    );

}


/* =========================================================
   OTP EXPIRY TIMER
========================================================= */

const otpTimer =
    document.getElementById('otpTimer');


if (otpTimer) {

    let seconds =
        parseInt(
            otpTimer.dataset.seconds,
            10
        );


    function updateOtpTimer() {

        if (seconds <= 0) {

            otpTimer.innerText =
                'Expired';

            return;

        }


        const minutes =
            Math.floor(
                seconds / 60
            );


        const remaining =
            seconds % 60;


        otpTimer.innerText =
            String(minutes)
                .padStart(2, '0')
            + ':' +
            String(remaining)
                .padStart(2, '0');


        seconds--;

    }


    updateOtpTimer();


    setInterval(
        updateOtpTimer,
        1000
    );

}


/* =========================================================
   RESEND COUNTDOWN
========================================================= */

const resendBtn =
    document.getElementById(
        'resendBtn'
    );


const resendCountdown =
    document.getElementById(
        'resendCountdown'
    );


const resendSeconds =
    document.getElementById(
        'resendSeconds'
    );


<?php if ($resendCooldown > 0): ?>

if (
    resendBtn &&
    resendCountdown &&
    resendSeconds
) {

    let cooldown =
        <?= (int)$resendCooldown ?>;


    function updateResendCountdown() {

        if (cooldown <= 0) {

            resendBtn.disabled =
                false;

            resendBtn.innerText =
                'Resend OTP';

            resendCountdown.style.display =
                'none';

            return;

        }


        resendSeconds.innerText =
            cooldown;


        cooldown--;

    }


    updateResendCountdown();


    setInterval(
        updateResendCountdown,
        1000
    );

}

<?php endif; ?>


/* =========================================================
   PREVENT DOUBLE CLICK
========================================================= */

const resendForm =
    document.getElementById(
        'resendForm'
    );


if (resendForm) {

    resendForm.addEventListener(
        'submit',
        function () {

            if (resendBtn) {

                resendBtn.disabled =
                    true;

                resendBtn.innerText =
                    'Sending OTP...';

            }

        }
    );

}

</script>


</body>

</html>