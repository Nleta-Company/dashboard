<?php

/* =========================================================
   NLETA - FORGOT PASSWORD

   FLOW:

   Forgot Password
        ↓
   Enter registered email
        ↓
   Find user
        ↓
   Generate OTP
        ↓
   Save hashed OTP
        ↓
   Send OTP email
        ↓
   verify-otp.php
        ↓
   Verify OTP
        ↓
   reset-password.php
        ↓
   Set new password
        ↓
   Automatic login

   IMPORTANT:
   Normal login does NOT use OTP.
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
   OTP SETTINGS
========================================================= */

if (!defined('OTP_EXPIRY_MINUTES')) {
    define('OTP_EXPIRY_MINUTES', 10);
}


/* =========================================================
   VARIABLES
========================================================= */

$errors = [];

$email = '';

$success = '';


/* =========================================================
   SEND OTP
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['send_otp'])
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
           EMAIL
        ================================================= */

        $email =
            strtolower(
                trim(
                    $_POST['email'] ?? ''
                )
            );


        /* =================================================
           VALIDATE EMAIL
        ================================================= */

        if ($email === '') {

            $errors[] =
                'Please enter your email address.';

        } elseif (
            !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )
        ) {

            $errors[] =
                'Please enter a valid email address.';

        }


        /* =================================================
           FIND USER
        ================================================= */

        if (empty($errors)) {


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
                    WHERE email = ?
                    LIMIT 1
                    "
                );


            if (!$stmt) {

                $errors[] =
                    'Unable to process your request. Please try again.';

            } else {


                $stmt->bind_param(
                    "s",
                    $email
                );


                $stmt->execute();


                $user =
                    $stmt
                        ->get_result()
                        ->fetch_assoc();


                $stmt->close();


                /* =================================================
                   USER NOT FOUND
                ================================================= */

                if (!$user) {

                    /*
                     * Generic message.
                     */

                    $success =
                        'If an account exists for this email address, a password reset OTP has been sent.';

                } else {


                    /* =================================================
                       ACCOUNT STATUS
                    ================================================= */

                    if (
                        $user['account_status'] !== 'active'
                    ) {

                        $errors[] =
                            'This account is not active. Please contact NLETA administrator.';

                    }

                    /* =================================================
                       EMAIL VERIFIED
                    ================================================= */

                    elseif (
                        (int)$user['email_verified'] !== 1
                    ) {

                        $errors[] =
                            'This email address has not been verified. Please complete email verification first.';

                    } else {


                        $userId =
                            (int)$user['id'];


                        /* =================================================
                           DELETE OLD PASSWORD RESET OTP
                        ================================================= */

                        $deleteStmt =
                            $conn->prepare(
                                "
                                DELETE FROM email_otps
                                WHERE user_id = ?
                                  AND purpose = 'password_reset'
                                  AND verified_at IS NULL
                                "
                            );


                        if ($deleteStmt) {

                            $deleteStmt->bind_param(
                                "i",
                                $userId
                            );


                            $deleteStmt->execute();


                            $deleteStmt->close();

                        }


                        /* =================================================
                           GENERATE OTP
                        ================================================= */

                        try {

                            $otp =
                                (string)random_int(
                                    100000,
                                    999999
                                );

                        } catch (Throwable $e) {

                            $otp = null;

                            $errors[] =
                                'Unable to generate verification code. Please try again.';

                        }


                        if (
                            $otp !== null &&
                            empty($errors)
                        ) {


                            /* =================================================
                               HASH OTP
                            ================================================= */

                            $otpHash =
                                password_hash(
                                    $otp,
                                    PASSWORD_DEFAULT
                                );


                            if (
                                $otpHash === false
                            ) {

                                $errors[] =
                                    'Unable to create verification code. Please try again.';

                            } else {


                                /* =================================================
                                   EXPIRY
                                ================================================= */

                                $expiresAt =
                                    date(
                                        'Y-m-d H:i:s',
                                        time() +
                                        (
                                            OTP_EXPIRY_MINUTES *
                                            60
                                        )
                                    );


                                /* =================================================
                                   SAVE OTP
                                ================================================= */

                                $otpStmt =
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
                                            'password_reset',
                                            ?,
                                            0,
                                            NULL,
                                            CURRENT_TIMESTAMP
                                        )
                                        "
                                    );


                                if (!$otpStmt) {

                                    $errors[] =
                                        'Unable to create password reset request.';

                                } else {


                                    $otpStmt->bind_param(
                                        "iss",
                                        $userId,
                                        $otpHash,
                                        $expiresAt
                                    );


                                    if (
                                        !$otpStmt->execute()
                                    ) {

                                        $errors[] =
                                            'Unable to save verification code.';

                                    }


                                    $otpStmt->close();


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
                                                $otp,
                                                'password_reset'
                                            );


                                        if (!$emailSent) {


                                            /*
                                             * Delete OTP if
                                             * email failed.
                                             */

                                            $cleanupStmt =
                                                $conn->prepare(
                                                    "
                                                    DELETE FROM email_otps
                                                    WHERE user_id = ?
                                                      AND purpose = 'password_reset'
                                                      AND verified_at IS NULL
                                                    "
                                                );


                                            if ($cleanupStmt) {

                                                $cleanupStmt->bind_param(
                                                    "i",
                                                    $userId
                                                );


                                                $cleanupStmt->execute();


                                                $cleanupStmt->close();

                                            }


                                            $errors[] =
                                                'Unable to send the password reset OTP. Please check your SMTP configuration.';

                                        } else {


                                            /* =================================================
                                               OTP SESSION
                                            ================================================= */

                                            $_SESSION['otp_user_id'] =
                                                $userId;


                                            $_SESSION['otp_email'] =
                                                $user['email'];


                                            $_SESSION['otp_purpose'] =
                                                'password_reset';


                                            unset(
                                                $_SESSION['password_reset_verified']
                                            );


                                            /* =================================================
                                               REDIRECT TO OTP PAGE
                                            ================================================= */

                                            header(
                                                "Location: verify-otp.php"
                                            );

                                            exit;

                                        }

                                    }

                                }

                            }

                        }

                    }

                }

            }

        }

    }

}


/* =========================================================
   ESCAPE
========================================================= */

function forgotEscape(
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

<title>NLETA | Forgot Password</title>

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

}


.login-header {

    background:#1f2933;

    padding:16px 24px;

}


.header-inner {

    display:flex;

    align-items:center;

    gap:18px;

}


.login-header img {

    height:80px;

}


.login-header h1 {

    font-size:22px;

    margin:0;

    color:#fff;

    font-weight:600;

    line-height:1.3;

}


.reset-card {

    max-width:460px;

    margin:50px auto;

    padding:0 12px;

}


.card {

    border:none;

    border-radius:10px;

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


.back-link {

    color:#ea232a;

    text-decoration:none;

    font-weight:600;

}


.back-link:hover {

    color:#333;

    text-decoration:underline;

}


.footer {

    font-size:13px;

    color:#777;

}


@media (max-width:576px) {

    .header-inner {

        flex-direction:column;

        text-align:center;

    }


    .login-header img {

        height:60px;

    }


    .login-header h1 {

        font-size:16px;

    }

}

</style>

</head>


<body>


<div class="login-header">

<div class="header-inner">

<a
    href="https://www.nleta.org.in/"
    target="_blank"
    style="text-decoration:none;"
>

<img
    src="uploads/1767031468_nleta-logo.jpeg"
    alt="NLETA Logo"
>

</a>


<h1>

NATIONAL LIFT ESCALATOR TESTING AGENCY

<br>

(NLETA)

</h1>

</div>

</div>


<div class="container reset-card">

<div class="card shadow p-4">


<div class="text-center mb-4">

<div style="font-size:45px;">
🔐
</div>


<h3>

Forgot Password?

</h3>


<p class="text-muted">

Enter your registered email address.
We will send you a verification OTP.

</p>

</div>


<?php if ($success !== ''): ?>

<div class="alert alert-success">

<?= forgotEscape($success) ?>

</div>

<?php endif; ?>


<?php if (!empty($errors)): ?>

<div class="alert alert-danger">

<ul class="mb-0">

<?php foreach ($errors as $error): ?>

<li>

<?= forgotEscape($error) ?>

</li>

<?php endforeach; ?>

</ul>

</div>

<?php endif; ?>


<form
    method="POST"
    autocomplete="off"
>


<div class="mb-3">

<label
    for="email"
    class="form-label"
>

Registered Email Address

</label>


<input
    type="email"
    name="email"
    id="email"
    class="form-control"
    placeholder="Enter your registered email"
    value="<?= forgotEscape($email) ?>"
    autocomplete="email"
    required
>

</div>


<?= csrfField() ?>


<button
    type="submit"
    name="send_otp"
    class="btn btn-nleta w-100"
>

Send Password Reset OTP

</button>


</form>


<div class="text-center mt-3">

<a
    href="login.php"
    class="back-link"
>

← Back to Login

</a>

</div>


</div>


<p class="text-center footer mt-4">

© <?= date('Y') ?> NLETA. All Rights Reserved.

</p>


</div>

</body>

</html>