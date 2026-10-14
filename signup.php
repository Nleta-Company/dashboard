<?php
/* =========================================================
   NLETA - PUBLIC OWNER SIGNUP WITH EMAIL OTP

   PUBLIC SIGNUP:
      1. Owner ONLY

   TECHNICIAN:
      Created ONLY by Admin

   INSPECTOR:
      Created ONLY by Admin

   ADMIN:
      NEVER ALLOWED

   FLOW:

      Owner Signup
          ↓
      Create inactive Owner account
          ↓
      Generate OTP
          ↓
      Save hashed OTP
          ↓
      Send OTP email
          ↓
      verify-otp.php
          ↓
      Email verified
          ↓
      Owner account activated

========================================================= */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/otp-mailer.php';


/* =========================================================
   OTP SETTINGS
========================================================= */

if (!defined('OTP_EXPIRY_MINUTES')) {

    define(
        'OTP_EXPIRY_MINUTES',
        10
    );

}


/* =========================================================
   IF ALREADY LOGGED IN
========================================================= */

if (
    !empty($_SESSION['uid']) &&
    !empty($_SESSION['role'])
) {

    if (
        $_SESSION['role'] === 'admin'
    ) {

        header(
            "Location: admin/dashboard.php"
        );

        exit;
    }


    if (
        $_SESSION['role'] === 'technician'
    ) {

        header(
            "Location: maintenance/dashboard.php"
        );

        exit;
    }


    if (
        $_SESSION['role'] === 'owner'
    ) {

        header(
            "Location: owner/dashboard.php"
        );

        exit;
    }


    if (
        $_SESSION['role'] === 'inspector'
    ) {

        header(
            "Location: inspector/dashboard.php"
        );

        exit;
    }

}


/* =========================================================
   VARIABLES
========================================================= */

$errors = [];

$name = '';

$organizationName = '';

$organizationAddress = '';

$phone = '';

$email = '';

$password = '';

$confirmPassword = '';

$existingUser = null;

$existingUserId = 0;


/* =========================================================
   FORM SUBMISSION
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
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
           INPUT
        ================================================= */

        $name =
            trim(
                $_POST['name'] ?? ''
            );


        $organizationName = trim($_POST['organization_name'] ?? '');

        $organizationAddress = trim($_POST['organization_address'] ?? '');


        $phone =
            trim(
                $_POST['phone'] ?? ''
            );


        $email =
            strtolower(
                trim(
                    $_POST['email'] ?? ''
                )
            );


        /*
         * PUBLIC SIGNUP IS OWNER ONLY.
         *
         * We intentionally do NOT accept
         * Technician or Inspector from POST.
         */
        $role = 'owner';


        $password =
            $_POST['password'] ?? '';


        $confirmPassword =
            $_POST['confirm_password'] ?? '';


        /* =================================================
           NAME VALIDATION
        ================================================= */

        if (
            $name === ''
        ) {

            $errors[] =
                'Please enter your name.';

        } elseif (
            mb_strlen($name) < 2
        ) {

            $errors[] =
                'Name must contain at least 2 characters.';

        } elseif (
            mb_strlen($name) > 100
        ) {

            $errors[] =
                'Name cannot exceed 100 characters.';

        }


        /* =================================================
           ORGANIZATION / ASSOCIATION NAME VALIDATION
        ================================================= */

        if ($organizationName === '') {
            $errors[] = 'Please enter Organization / Association Name.';
        } elseif (mb_strlen($organizationName) > 200) {
            $errors[] = 'Organization / Association Name cannot exceed 200 characters.';
        }


        /* =================================================
           ORGANIZATION / ASSOCIATION ADDRESS VALIDATION
        ================================================= */

        if ($organizationAddress === '') {
            $errors[] = 'Please enter Organization / Association Address.';
        } elseif (mb_strlen($organizationAddress) > 500) {
            $errors[] = 'Organization / Association Address cannot exceed 500 characters.';
        }


        /* =================================================
           PHONE VALIDATION
        ================================================= */

        $cleanPhone =
            preg_replace(
                '/\D/',
                '',
                $phone
            );


        if (
            $cleanPhone === ''
        ) {

            $errors[] =
                'Please enter your mobile number.';

        } elseif (
            strlen($cleanPhone) < 10 ||
            strlen($cleanPhone) > 15
        ) {

            $errors[] =
                'Please enter a valid mobile number.';

        } else {

            $phone =
                $cleanPhone;

        }


        /* =================================================
           EMAIL VALIDATION
        ================================================= */

        if (
            $email === ''
        ) {

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

        } elseif (
            strlen($email) > 100
        ) {

            $errors[] =
                'Email address cannot exceed 100 characters.';

        }


        /* =================================================
           PASSWORD VALIDATION
        ================================================= */

        $passwordCheck =
            validatePasswordStrength(
                $password
            );


        if (
            !$passwordCheck['valid']
        ) {

            $errors[] =
                $passwordCheck['error'];

        }


        /* =================================================
           CONFIRM PASSWORD
        ================================================= */

        if (
            $password !== $confirmPassword
        ) {

            $errors[] =
                'Passwords do not match.';

        }


        /* =================================================
           CHECK EXISTING EMAIL
        ================================================= */

        if (
            empty($errors)
        ) {

            $emailStmt =
                $conn->prepare(
                    "
                    SELECT
                        id,
                        name,
                        email,
                        phone,
                        organization_name,
                        organization_address,
                        role,
                        email_verified,
                        account_status
                    FROM users
                    WHERE email = ?
                    LIMIT 1
                    "
                );


            if (
                !$emailStmt
            ) {

                $errors[] =
                    'Unable to process registration. Please try again.';

            } else {

                $emailStmt->bind_param(
                    "s",
                    $email
                );


                $emailStmt->execute();


                $existingUser =
                    $emailStmt
                        ->get_result()
                        ->fetch_assoc();


                $emailStmt->close();


                if (
                    $existingUser
                ) {

                    $existingUserId =
                        (int)$existingUser['id'];


                    /* =====================================
                       VERIFIED ACCOUNT
                    ===================================== */

                    if (
                        (int)$existingUser['email_verified'] === 1
                    ) {

                        $errors[] =
                            'An account with this email address already exists.';

                    }

                }

            }

        }


        /* =================================================
           CHECK PHONE
        ================================================= */

        if (
            empty($errors)
        ) {

            $phoneStmt =
                $conn->prepare(
                    "
                    SELECT
                        id,
                        email_verified
                    FROM users
                    WHERE phone = ?
                    LIMIT 1
                    "
                );


            if (
                !$phoneStmt
            ) {

                $errors[] =
                    'Unable to validate mobile number. Please try again.';

            } else {

                $phoneStmt->bind_param(
                    "s",
                    $phone
                );


                $phoneStmt->execute();


                $phoneUser =
                    $phoneStmt
                        ->get_result()
                        ->fetch_assoc();


                $phoneStmt->close();


                if (
                    $phoneUser
                ) {

                    $phoneUserId =
                        (int)$phoneUser['id'];


                    /*
                     * Same pending Owner account:
                     * allow reuse.
                     */

                    if (
                        !(
                            $existingUserId > 0 &&
                            $phoneUserId === $existingUserId &&
                            (int)$phoneUser['email_verified'] === 0
                        )
                    ) {

                        $errors[] =
                            'An account with this mobile number already exists.';

                    }

                }

            }

        }


        /* =================================================
           CREATE / REUSE OWNER ACCOUNT
        ================================================= */

        if (
            empty($errors)
        ) {


            /* =================================================
               PASSWORD HASH
            ================================================= */

            $passwordHash =
                password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );


            if (
                $passwordHash === false
            ) {

                $errors[] =
                    'Unable to secure your password. Please try again.';

            } else {


                /* =================================================
                   OWNER WORKFLOW
                ================================================= */

                $workflowStage =
                    'service_selection';


                /*
                 * New Owner remains inactive
                 * until OTP verification.
                 */

                $accountStatus =
                    'inactive';


                /* =================================================
                   TRANSACTION
                ================================================= */

                $conn->begin_transaction();


                try {


                    /* =================================================
                       EXISTING UNVERIFIED OWNER
                    ================================================= */

                    if (
                        $existingUser &&
                        $existingUserId > 0 &&
                        (int)$existingUser['email_verified'] === 0
                    ) {


                        /*
                         * Only reuse the account if it is actually
                         * an Owner account.
                         *
                         * This prevents public signup from changing
                         * a pending Technician/Inspector account.
                         */

                        if (
                            ($existingUser['role'] ?? '') !== 'owner'
                        ) {

                            throw new Exception(
                                'This email belongs to an account that cannot be registered through public Owner signup. Please contact Admin.'
                            );

                        }


                        /* =========================================
                           UPDATE PENDING OWNER
                        ========================================== */

                        $updateStmt =
                            $conn->prepare(
                                "
                                UPDATE users
                                SET
                                    name = ?,
                                    email = ?,
                                    phone = ?,
                                    organization_name = ?,
                                    organization_address = ?,
                                    password = ?,
                                    role = 'owner',
                                    account_status = 'inactive',
                                    workflow_stage = 'service_selection',
                                    email_verified = 0,
                                    email_verified_at = NULL
                                WHERE id = ?
                                LIMIT 1
                                "
                            );


                        if (
                            !$updateStmt
                        ) {

                            throw new Exception(
                                'Unable to update existing account.'
                            );

                        }


                        $updateStmt->bind_param(
                            "sssssi",
                            $name,
                            $email,
                            $phone,
                            $organizationName,
                            $organizationAddress,
                            $passwordHash,
                            $existingUserId
                        );


                        if (
                            !$updateStmt->execute()
                        ) {

                            throw new Exception(
                                'Unable to update existing account.'
                            );

                        }


                        $updateStmt->close();


                        $userId =
                            $existingUserId;


                    } else {


                        /* =================================================
                           NEW OWNER ACCOUNT
                        ================================================= */

                        $insertStmt =
                            $conn->prepare(
                                "
                                INSERT INTO users
                                (
                                    name,
                                    email,
                                    phone,
                                    organization_name,
                                    organization_address,
                                    password,
                                    role,
                                    account_status,
                                    workflow_stage,
                                    created_at,
                                    email_verified,
                                    email_verified_at
                                )
                                VALUES
                                (
                                    ?,
                                    ?,
                                    ?,
                                    ?,
                                    ?,
                                    ?,
                                    'owner',
                                    'inactive',
                                    'service_selection',
                                    CURRENT_TIMESTAMP,
                                    0,
                                    NULL
                                )
                                "
                            );


                        if (
                            !$insertStmt
                        ) {

                            throw new Exception(
                                'Unable to prepare account creation.'
                            );

                        }


                        $insertStmt->bind_param(
                            "ssssss",
                            $name,
                            $email,
                            $phone,
                            $organizationName,
                            $organizationAddress,
                            $passwordHash
                        );


                        if (
                            !$insertStmt->execute()
                        ) {

                            if (
                                $insertStmt->errno === 1062
                            ) {

                                throw new Exception(
                                    'An account with this email or mobile number already exists.'
                                );

                            }


                            throw new Exception(
                                'Unable to create account.'
                            );

                        }


                        $userId =
                            (int)$conn->insert_id;


                        $insertStmt->close();

                    }


                    /* =================================================
                       DELETE OLD SIGNUP OTP
                    ================================================= */

                    $deleteOtpStmt =
                        $conn->prepare(
                            "
                            DELETE FROM email_otps
                            WHERE user_id = ?
                              AND purpose = 'signup'
                              AND verified_at IS NULL
                            "
                        );


                    if (
                        $deleteOtpStmt
                    ) {

                        $deleteOtpStmt->bind_param(
                            "i",
                            $userId
                        );


                        $deleteOtpStmt->execute();


                        $deleteOtpStmt->close();

                    }


                    /* =================================================
                       GENERATE NEW OTP
                    ================================================= */

                    try {

                        $otp =
                            (string)random_int(
                                100000,
                                999999
                            );

                    } catch (
                        Throwable $e
                    ) {

                        throw new Exception(
                            'Unable to generate verification code.'
                        );

                    }


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

                        throw new Exception(
                            'Unable to secure verification code.'
                        );

                    }


                    /* =================================================
                       OTP EXPIRY
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
                                'signup',
                                ?,
                                0,
                                NULL,
                                CURRENT_TIMESTAMP
                            )
                            "
                        );


                    if (
                        !$otpStmt
                    ) {

                        throw new Exception(
                            'Unable to prepare OTP verification.'
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
                            'Unable to save verification code.'
                        );

                    }


                    $otpStmt->close();


                    /* =================================================
                       SEND OTP EMAIL
                    ================================================= */

                    $emailSent =
                        sendNletaOtpEmail(
                            $email,
                            $name,
                            $otp,
                            'signup'
                        );


                    if (
                        !$emailSent
                    ) {

                        throw new Exception(
                            'Unable to send verification email. Please check your SMTP settings and try again.'
                        );

                    }


                    /* =================================================
                       COMMIT
                    ================================================= */

                    $conn->commit();


                    /* =================================================
                       OTP SESSION
                    ================================================= */

                    $_SESSION['otp_user_id'] =
                        $userId;


                    $_SESSION['otp_email'] =
                        $email;


                    $_SESSION['otp_purpose'] =
                        'signup';


                    /* =================================================
                       REDIRECT
                    ================================================= */

                    header(
                        "Location: verify-otp.php"
                    );

                    exit;


                } catch (
                    Throwable $e
                ) {


                    /* =================================================
                       ROLLBACK
                    ================================================= */

                    $conn->rollback();


                    error_log(
                        'NLETA Owner Signup OTP Error: ' .
                        $e->getMessage()
                    );


                    $errors[] =
                        $e->getMessage();

                }

            }

        }

    }

}


/* =========================================================
   ESCAPE HELPER
========================================================= */

function signupOld(
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

<title>NLETA | Owner Registration</title>

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


.signup-header {

    background:#1f2933;

    padding:16px 24px;

}


.signup-header .header-inner {

    display:flex;

    align-items:center;

    gap:18px;

}


.signup-header img {

    height:80px;

    width:auto;

}


.signup-header h1 {

    font-size:22px;

    margin:0;

    color:#fff;

    font-weight:600;

    line-height:1.3;

}


.signup-card {

    max-width:560px;

    margin:40px auto;

    padding:0 12px;

}


.signup-card .card {

    border:none;

    border-radius:10px;

}


.owner-account-box {

    border:2px solid #dee2e6;

    border-radius:10px;

    padding:16px;

    background:#fff;

    margin-bottom:24px;

}


.owner-account-title {

    font-size:17px;

    font-weight:600;

}


.owner-account-description {

    font-size:13px;

    color:#6c757d;

    margin-top:5px;

}


.owner-account-icon {

    font-size:24px;

    margin-bottom:8px;

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


.password-help {

    font-size:12px;

    color:#6c757d;

    margin-top:6px;

}


.signup-footer {

    font-size:13px;

    color:#777;

}


@media (max-width:576px) {

    .signup-header .header-inner {

        flex-direction:column;

        text-align:center;

        gap:10px;

    }


    .signup-header img {

        height:60px;

    }


    .signup-header h1 {

        font-size:16px;

    }


    .signup-card {

        margin-top:20px;

    }

}

</style>

</head>


<body>


<!-- =======================================================
     HEADER
======================================================== -->

<div class="signup-header">

    <div class="header-inner">

        <a
            href="https://www.nleta.org.in/"
            target="_blank"
            style="
                text-decoration:none;
                color:inherit;
            "
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


<!-- =======================================================
     SIGNUP CARD
======================================================== -->

<div class="container signup-card">

<div class="card shadow p-4">


<h4 class="text-center mb-1">

    Registration

</h4>


<p
    class="text-center text-muted mb-4"
    style="font-size:14px"
>

    Create your NLETA Owner account

</p>


<!-- =====================================================
     ERRORS
===================================================== -->

<?php if (!empty($errors)): ?>

<div
    class="alert alert-danger"
    role="alert"
>

    <strong>
        Registration failed:
    </strong>


    <ul class="mb-0 mt-2">

        <?php foreach ($errors as $error): ?>

            <li>

                <?= signupOld($error) ?>

            </li>

        <?php endforeach; ?>

    </ul>

</div>

<?php endif; ?>


<form
    method="POST"
    action=""
    autocomplete="off"
>


<!-- =====================================================
     ACCOUNT TYPE
===================================================== -->

<div class="owner-account-box text-center">

    <div class="owner-account-icon">

        <i class="bi bi-building"></i>

    </div>


    <div class="owner-account-title">

        Owner Account

    </div>


    <div class="owner-account-description">

        Registration for lift / escalator owners

    </div>

</div>


<!-- =====================================================
     NAME
===================================================== -->

<div class="mb-3">

    <label
        class="form-label"
        for="name"
    >

        Name

    </label>


    <input
        type="text"
        name="name"
        id="name"
        class="form-control"
        placeholder="Enter your name"
        value="<?= signupOld($name) ?>"
        maxlength="100"
        autocomplete="name"
        required
    >

</div>


<!-- =====================================================
     ORGANIZATION / SOCIETY NAME
===================================================== -->

<div class="mb-3">

    <label class="form-label" for="organization_name">
        Organization / Association Name
    </label>

    <input
        type="text"
        name="organization_name"
        id="organization_name"
        class="form-control"
        placeholder="Enter Organization / Association Name"
        value="<?= signupOld($organizationName) ?>"
        maxlength="200"
        autocomplete="organization"
        required
    >
</div>


<!-- =====================================================
     ORGANIZATION / ASSOCIATION ADDRESS
===================================================== -->

<div class="mb-3">

    <label class="form-label" for="organization_address">
        Organization / Association Address
    </label>

    <textarea
        name="organization_address"
        id="organization_address"
        class="form-control"
        rows="3"
        maxlength="500"
        placeholder="Enter complete Organization / Association Address"
        autocomplete="street-address"
        required
    ><?= signupOld($organizationAddress) ?></textarea>

</div>


<!-- =====================================================
     PHONE
===================================================== -->

<div class="mb-3">

    <label
        class="form-label"
        for="phone"
    >

        Mobile Number

    </label>


    <input
        type="tel"
        name="phone"
        id="phone"
        class="form-control"
        placeholder="Enter mobile number"
        value="<?= signupOld($phone) ?>"
        maxlength="15"
        inputmode="numeric"
        autocomplete="tel"
        required
    >

</div>


<!-- =====================================================
     EMAIL
===================================================== -->

<div class="mb-3">

    <label
        class="form-label"
        for="email"
    >

        Email Address

    </label>


    <input
        type="email"
        name="email"
        id="email"
        class="form-control"
        placeholder="Enter email address"
        value="<?= signupOld($email) ?>"
        maxlength="100"
        autocomplete="email"
        required
    >

</div>


<!-- =====================================================
     PASSWORD
===================================================== -->

<div class="mb-3">

    <label
        class="form-label"
        for="password"
    >

        Password

    </label>


    <input
        type="password"
        name="password"
        id="password"
        class="form-control"
        placeholder="Create password"
        minlength="8"
        autocomplete="new-password"
        required
    >


    <div class="password-help">

        Minimum 8 characters with at least:

        <br>

        • One uppercase letter
        • One lowercase letter
        • One number

    </div>

</div>


<!-- =====================================================
     CONFIRM PASSWORD
===================================================== -->

<div class="mb-4">

    <label
        class="form-label"
        for="confirm_password"
    >

        Confirm Password

    </label>


    <input
        type="password"
        name="confirm_password"
        id="confirm_password"
        class="form-control"
        placeholder="Confirm password"
        minlength="8"
        autocomplete="new-password"
        required
    >

</div>


<!-- =====================================================
     CSRF
===================================================== -->

<?= csrfField() ?>


<!-- =====================================================
     SUBMIT
===================================================== -->

<button
    type="submit"
    class="btn btn-nleta w-100"
>

    Create Owner Account & Verify Email

</button>


<!-- =====================================================
     LOGIN LINK
===================================================== -->

<div class="text-center mt-3">

    <span class="text-muted">

        Already have an account?

    </span>


    <a
        href="login.php"
        class="text-decoration-none"
    >

        Sign In

    </a>

</div>


</form>


</div>


<!-- =======================================================
     FOOTER
======================================================== -->

<p class="text-center signup-footer mt-4">

    © <?= date('Y') ?> NLETA. All Rights Reserved.

</p>


</div>


<script>

/* =========================================================
   MOBILE NUMBER
========================================================= */

document
    .getElementById('phone')
    ?.addEventListener(
        'input',
        function () {

            this.value =
                this.value.replace(
                    /\D/g,
                    ''
                );

        }
    );


/* =========================================================
   PASSWORD MATCH
========================================================= */

document
    .querySelector('form')
    ?.addEventListener(
        'submit',
        function (event) {

            const password =
                document.getElementById(
                    'password'
                ).value;


            const confirmPassword =
                document.getElementById(
                    'confirm_password'
                ).value;


            if (
                password !== confirmPassword
            ) {

                event.preventDefault();


                alert(
                    'Passwords do not match.'
                );

            }

        }
    );

</script>


</body>

</html>