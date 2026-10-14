<?php

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/otp-mailer.php';


/*
|--------------------------------------------------------------------------
| OTP SETTINGS
|--------------------------------------------------------------------------
*/

if (!defined('OTP_EXPIRY_MINUTES')) {
    define('OTP_EXPIRY_MINUTES', 10);
}

if (!defined('OTP_RESEND_COOLDOWN')) {
    define('OTP_RESEND_COOLDOWN', 60);
}


/*
|--------------------------------------------------------------------------
| ALREADY LOGGED IN
|--------------------------------------------------------------------------
*/

if (
    !empty($_SESSION['uid']) &&
    !empty($_SESSION['role'])
) {

    if ($_SESSION['role'] === 'admin') {
        header("Location: admin/dashboard.php");
        exit;
    }

    if ($_SESSION['role'] === 'technician') {
        header("Location: maintenance/dashboard.php");
        exit;
    }

    if ($_SESSION['role'] === 'owner') {
        header("Location: owner/dashboard.php");
        exit;
    }

    if ($_SESSION['role'] === 'inspector') {
        header("Location: inspector/dashboard.php");
        exit;
    }
}


$loginError = '';


/*
|--------------------------------------------------------------------------
| LOGIN
|--------------------------------------------------------------------------
*/

if (isset($_POST['login'])) {


    /*
    |--------------------------------------------------------------------------
    | CSRF
    |--------------------------------------------------------------------------
    */

    if (
        !validateCSRFToken(
            $_POST['csrf_token'] ?? ''
        )
    ) {

        $loginError =
            'Invalid request. Please refresh the page and try again.';
    } else {


        $email =
            strtolower(
                trim(
                    $_POST['email'] ?? ''
                )
            );


        $password =
            $_POST['password'] ?? '';


        $ip =
            $_SERVER['REMOTE_ADDR'] ?? 'unknown';


        /*
        |--------------------------------------------------------------------------
        | RATE LIMIT
        |--------------------------------------------------------------------------
        */

        if (
            !checkRateLimit(
                'login_' . $ip,
                5,
                900
            )
        ) {

            $loginError =
                'Too many login attempts. Please wait 15 minutes.';
        } else {


            /*
            |--------------------------------------------------------------------------
            | GET USER
            |--------------------------------------------------------------------------
            */

            $stmt =
                $conn->prepare(
                    "
                    SELECT
                        id,
                        name,
                        email,
                        role,
                        password,
                        email_verified,
                        email_verified_at,
                        account_status
                    FROM users
                    WHERE email = ?
                    LIMIT 1
                    "
                );


            if (!$stmt) {

                $loginError =
                    'Unable to process login. Please try again.';
            } else {


                $stmt->bind_param(
                    "s",
                    $email
                );


                $stmt->execute();


                $u =
                    $stmt
                    ->get_result()
                    ->fetch_assoc();


                $stmt->close();


                /*
                |--------------------------------------------------------------------------
                | CHECK PASSWORD
                |--------------------------------------------------------------------------
                */

                if (
                    !$u ||
                    !password_verify(
                        $password,
                        $u['password']
                    )
                ) {

                    $loginError =
                        'Invalid email or password.';
                } else {


                    /*
                    |--------------------------------------------------------------------------
                    | CHECK ACCOUNT STATUS
                    |--------------------------------------------------------------------------
                    */

                    if (
                        $u['account_status'] !== 'active'
                    ) {

                        $loginError =
                            'Your account is not active. Please contact NLETA administrator.';
                    } else {


                        /*
                        |--------------------------------------------------------------------------
                        | EXISTING / NEW UNVERIFIED ACCOUNT
                        |--------------------------------------------------------------------------
                        |
                        | This is important.
                        |
                        | Old accounts created before OTP also have
                        | email_verified = 0.
                        |
                        | After correct password:
                        |
                        |      Generate OTP
                        |          ↓
                        |      Save hashed OTP
                        |          ↓
                        |      Send email
                        |          ↓
                        |      verify-otp.php
                        |
                        */

                        if (
                            (int)$u['email_verified'] !== 1
                        ) {


                            /*
                            |--------------------------------------------------------------------------
                            | CHECK EMAIL
                            |--------------------------------------------------------------------------
                            */

                            if (
                                empty(trim(
                                    $u['email']
                                )) ||
                                !filter_var(
                                    $u['email'],
                                    FILTER_VALIDATE_EMAIL
                                )
                            ) {

                                $loginError =
                                    'Your account does not have a valid email address. Please contact NLETA administrator to update your email address.';
                            } else {


                                /*
                                |--------------------------------------------------------------------------
                                | GENERATE OTP
                                |--------------------------------------------------------------------------
                                */

                                try {

                                    $otp =
                                        (string)random_int(
                                            100000,
                                            999999
                                        );
                                } catch (
                                    Throwable $e
                                ) {

                                    $otp = null;

                                    $loginError =
                                        'Unable to generate verification OTP. Please try again.';
                                }


                                if (
                                    $otp !== null
                                ) {


                                    /*
                                    |--------------------------------------------------------------------------
                                    | HASH OTP
                                    |--------------------------------------------------------------------------
                                    */

                                    $otpHash =
                                        password_hash(
                                            $otp,
                                            PASSWORD_DEFAULT
                                        );


                                    if (
                                        $otpHash === false
                                    ) {

                                        $loginError =
                                            'Unable to secure verification OTP. Please try again.';
                                    } else {


                                        /*
                                        |--------------------------------------------------------------------------
                                        | EXPIRY
                                        |--------------------------------------------------------------------------
                                        */

                                        $expiresAt =
                                            date(
                                                'Y-m-d H:i:s',
                                                time() +
                                                    (
                                                        OTP_EXPIRY_MINUTES *
                                                        60
                                                    )
                                            );


                                        /*
                                        |--------------------------------------------------------------------------
                                        | TRANSACTION
                                        |--------------------------------------------------------------------------
                                        */

                                        $conn->begin_transaction();


                                        try {


                                            /*
                                            |--------------------------------------------------------------------------
                                            | DELETE OLD SIGNUP OTP
                                            |--------------------------------------------------------------------------
                                            */

                                            $deleteStmt =
                                                $conn->prepare(
                                                    "
                                                    DELETE FROM email_otps
                                                    WHERE user_id = ?
                                                      AND purpose = 'signup'
                                                      AND verified_at IS NULL
                                                    "
                                                );


                                            if (!$deleteStmt) {

                                                throw new Exception(
                                                    'Unable to remove previous OTP.'
                                                );
                                            }


                                            $userId =
                                                (int)$u['id'];


                                            $deleteStmt->bind_param(
                                                "i",
                                                $userId
                                            );


                                            if (
                                                !$deleteStmt->execute()
                                            ) {

                                                throw new Exception(
                                                    'Unable to remove previous OTP.'
                                                );
                                            }


                                            $deleteStmt->close();


                                            /*
                                            |--------------------------------------------------------------------------
                                            | INSERT NEW OTP
                                            |--------------------------------------------------------------------------
                                            */

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
                                                        'signup',
                                                        ?,
                                                        0,
                                                        NULL,
                                                        CURRENT_TIMESTAMP
                                                    )
                                                    "
                                                );


                                            if (!$otpStmt) {

                                                throw new Exception(
                                                    'Unable to create OTP.'
                                                );
                                            }


                                            $otpStmt->bind_param(
                                                "iss",
                                                $userId,
                                                $otpHash,
                                                $expiresAt
                                            );


                                            if (
                                                !$otpStmt->execute()
                                            ) {

                                                throw new Exception(
                                                    'Unable to save OTP.'
                                                );
                                            }


                                            $otpStmt->close();


                                            /*
                                            |--------------------------------------------------------------------------
                                            | SEND OTP EMAIL
                                            |--------------------------------------------------------------------------
                                            */

                                            $emailSent =
                                                sendNletaOtpEmail(
                                                    $u['email'],
                                                    $u['name'],
                                                    $otp,
                                                    'signup'
                                                );


                                            if (!$emailSent) {

                                                throw new Exception(
                                                    'Unable to send OTP email. Please check your SMTP settings.'
                                                );
                                            }


                                            /*
                                            |--------------------------------------------------------------------------
                                            | COMMIT
                                            |--------------------------------------------------------------------------
                                            */

                                            $conn->commit();


                                            /*
                                            |--------------------------------------------------------------------------
                                            | OTP SESSION
                                            |--------------------------------------------------------------------------
                                            */

                                            $_SESSION['otp_user_id'] =
                                                $userId;


                                            $_SESSION['otp_email'] =
                                                $u['email'];


                                            $_SESSION['otp_purpose'] =
                                                'signup';


                                            $_SESSION['otp_last_resend'] =
                                                time();


                                            /*
                                            |--------------------------------------------------------------------------
                                            | REDIRECT
                                            |--------------------------------------------------------------------------
                                            */

                                            header(
                                                "Location: verify-otp.php"
                                            );

                                            exit;
                                        } catch (
                                            Throwable $e
                                        ) {


                                            $conn->rollback();


                                            error_log(
                                                'NLETA Login OTP Error: ' .
                                                    $e->getMessage()
                                            );


                                            $loginError =
                                                $e->getMessage();
                                        }
                                    }
                                }
                            }
                        } else {


                            /*
                            |--------------------------------------------------------------------------
                            | EMAIL ALREADY VERIFIED
                            |--------------------------------------------------------------------------
                            |
                            | Normal login.
                            | NO OTP.
                            |
                            */

                            session_regenerate_id(true);


                            $_SESSION['uid'] =
                                (int)$u['id'];


                            $_SESSION['role'] =
                                $u['role'];


                            $_SESSION['name'] =
                                $u['name'];


                            /*
                            |--------------------------------------------------------------------------
                            | ROLE REDIRECT
                            |--------------------------------------------------------------------------
                            */

                            if (
                                $u['role'] === 'admin'
                            ) {

                                header(
                                    "Location: admin/dashboard.php"
                                );

                                exit;
                            }


                            if (
                                $u['role'] === 'technician'
                            ) {

                                header(
                                    "Location: maintenance/dashboard.php"
                                );

                                exit;
                            }


                            if (
                                $u['role'] === 'owner'
                            ) {

                                header(
                                    "Location: owner/dashboard.php"
                                );

                                exit;
                            }

                            if (
                                $u['role'] === 'inspector'
                            ) {

                                header(
                                    "Location: inspector/dashboard.php"
                                );

                                exit;
                            }


                            /*
                            |--------------------------------------------------------------------------
                            | UNKNOWN ROLE
                            |--------------------------------------------------------------------------
                            */

                            $_SESSION = [];

                            session_destroy();


                            $loginError =
                                'Invalid account role. Please contact NLETA administrator.';
                        }
                    }
                }
            }
        }
    }
}

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <title>NLETA | System Login</title>

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1">

    <link
        rel="stylesheet"
        href="assets/css/bootstrap.min.css">


    <style>
        body {

            background: #f4f6f9;

            font-family:
                "Segoe UI",
                Arial,
                sans-serif;
        }


        .login-header {

            background: #1f2933;

            padding: 16px 24px;
        }


        .login-header .header-inner {

            display: flex;

            align-items: center;

            gap: 18px;
        }


        .login-header img {

            height: 80px;
        }


        .login-header h1 {

            font-size: 22px;

            margin: 0;

            color: #fff;

            font-weight: 600;

            line-height: 1.3;
        }


        .login-card {

            max-width: 420px;

            margin: auto;

            margin-top: 40px;

            padding: 0 12px;
        }


        .login-card .card {

            border: none;

            border-radius: 10px;
        }


        .btn-nleta {

            background: #ea232a;

            border: none;

            color: #fff;
        }


        .btn-nleta:hover {

            background: #333;

            color: #fff;
        }


        .login-footer {

            font-size: 13px;

            color: #777;
        }


        .signup-link {

            color: #ea232a;

            text-decoration: none;

            font-weight: 600;
        }


        .signup-link:hover {

            color: #333;

            text-decoration: underline;
        }


        .forgot-link {

            color: #ea232a;

            text-decoration: none;

            font-size: 14px;

            font-weight: 600;
        }


        .forgot-link:hover {

            color: #333;

            text-decoration: underline;
        }


        @media (max-width:576px) {

            .login-header .header-inner {

                flex-direction: column;

                text-align: center;

                gap: 10px;
            }


            .login-header img {

                height: 60px;
            }


            .login-header h1 {

                font-size: 16px;
            }


            .login-card {

                margin-top: 20px;
            }

        }
    </style>

</head>


<body>


    <!-- =====================================================
     HEADER
===================================================== -->

    <div class="login-header">

        <div class="header-inner">

            <a
                href="https://www.nleta.org.in/"
                target="_blank"
                style="
                text-decoration:none;
                color:inherit;
            ">

                <img
                    src="uploads/1767031468_nleta-logo.jpeg"
                    alt="NLETA Logo">

            </a>


            <h1>

                NATIONAL LIFT ESCALATOR TESTING AGENCY

                <br>

                (NLETA)

            </h1>

        </div>

    </div>


    <!-- =====================================================
     LOGIN CARD
===================================================== -->

    <div class="container login-card">

        <div class="card shadow p-4">


            <h4 class="text-center mb-1">

                System Login

            </h4>


            <p
                class="text-center text-muted mb-4"
                style="font-size:14px">

                Authorized access only

            </p>


            <form
                method="POST"
                autocomplete="on">


                <!-- =====================================================
     EMAIL
===================================================== -->

                <div class="mb-3">

                    <label
                        class="form-label"
                        for="email">

                        Email Address

                    </label>


                    <input
                        type="email"
                        name="email"
                        id="email"
                        class="form-control"
                        placeholder="Enter registered email"
                        required
                        autocomplete="email"
                        value="<?= htmlspecialchars(
                                    $_POST['email'] ?? '',
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>">

                </div>


                <!-- =====================================================
     PASSWORD
===================================================== -->

                <div class="mb-2">

                    <label
                        class="form-label"
                        for="password">

                        Password

                    </label>


                    <input
                        type="password"
                        name="password"
                        id="password"
                        class="form-control"
                        placeholder="Enter password"
                        required
                        autocomplete="current-password">

                </div>


                <!-- =====================================================
     FORGOT PASSWORD
===================================================== -->

                <div class="text-end mb-3">

                    <a
                        href="forgot-password.php"
                        class="forgot-link">

                        Forgot Password?

                    </a>

                </div>


                <!-- =====================================================
     ERROR
===================================================== -->

                <?php if ($loginError): ?>

                    <div
                        class="alert alert-danger"
                        role="alert">

                        <?= htmlspecialchars(
                            $loginError,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>

                    </div>

                <?php endif; ?>


                <!-- =====================================================
     CSRF
===================================================== -->

                <?= csrfField() ?>


                <!-- =====================================================
     LOGIN BUTTON
===================================================== -->

                <button
                    type="submit"
                    class="btn btn-nleta w-100"
                    name="login">

                    Login

                </button>


                <!-- =====================================================
     CREATE ACCOUNT
===================================================== -->

                <div class="text-center mt-3">

                    <span class="text-muted">

                        Don't have an account?

                    </span>


                    <a
                        href="signup.php"
                        class="signup-link">

                        Create Account

                    </a>

                </div>


            </form>


        </div>


        <!-- =====================================================
     FOOTER
===================================================== -->

        <p class="text-center login-footer mt-4">

            © <?= date('Y') ?> NLETA. All Rights Reserved.

        </p>


    </div>


</body>

</html>