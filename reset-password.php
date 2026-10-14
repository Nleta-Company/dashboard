<?php

/* =========================================================
   NLETA - RESET PASSWORD

   FLOW:

   Forgot Password
        ↓
   OTP
        ↓
   Verify OTP
        ↓
   New Password
        ↓
   Update Password
        ↓
   AUTOMATIC LOGIN
        ↓
   Dashboard
========================================================= */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/security.php';


/* =========================================================
   SESSION
========================================================= */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


/* =========================================================
   SECURITY CHECK
========================================================= */

if (
    empty($_SESSION['password_reset_verified']) ||
    $_SESSION['password_reset_verified'] !== true ||
    empty($_SESSION['otp_user_id']) ||
    empty($_SESSION['otp_email']) ||
    empty($_SESSION['otp_purpose']) ||
    $_SESSION['otp_purpose'] !== 'password_reset'
) {

    header(
        "Location: forgot-password.php"
    );

    exit;
}


$userId =
    (int)$_SESSION['otp_user_id'];

$email =
    $_SESSION['otp_email'];


/* =========================================================
   VARIABLES
========================================================= */

$resetError = '';

$passwordChanged = false;


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
            account_status
        FROM users
        WHERE id = ?
        LIMIT 1
        "
    );


if (!$stmt) {

    die(
        'Unable to process password reset.'
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
        $_SESSION['password_reset_verified'],
        $_SESSION['otp_user_id'],
        $_SESSION['otp_email'],
        $_SESSION['otp_purpose'],
        $_SESSION['otp_last_resend']
    );


    header(
        "Location: login.php"
    );

    exit;
}


/* =========================================================
   ACCOUNT STATUS
========================================================= */

if (
    $user['account_status'] !== 'active'
) {

    unset(
        $_SESSION['password_reset_verified'],
        $_SESSION['otp_user_id'],
        $_SESSION['otp_email'],
        $_SESSION['otp_purpose'],
        $_SESSION['otp_last_resend']
    );


    header(
        "Location: login.php"
    );

    exit;
}


/* =========================================================
   RESET PASSWORD
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['reset_password'])
) {


    /* =====================================================
       CSRF
    ===================================================== */

    if (
        !validateCSRFToken(
            $_POST['csrf_token'] ?? ''
        )
    ) {

        $resetError =
            'Invalid request. Please refresh the page and try again.';

    } else {


        $newPassword =
            $_POST['new_password'] ?? '';


        $confirmPassword =
            $_POST['confirm_password'] ?? '';


        /* =================================================
           EMPTY
        ================================================= */

        if (
            $newPassword === ''
        ) {

            $resetError =
                'Please enter a new password.';

        }

        /* =================================================
           MINIMUM LENGTH
        ================================================= */

        elseif (
            strlen($newPassword) < 8
        ) {

            $resetError =
                'Password must be at least 8 characters long.';

        }

        /* =================================================
           MAX LENGTH
        ================================================= */

        elseif (
            strlen($newPassword) > 255
        ) {

            $resetError =
                'Password is too long.';

        }

        /* =================================================
           MATCH
        ================================================= */

        elseif (
            $newPassword !==
            $confirmPassword
        ) {

            $resetError =
                'New password and confirm password do not match.';

        }

        /* =================================================
           STRENGTH
        ================================================= */

        elseif (
            !preg_match(
                '/[A-Z]/',
                $newPassword
            )
        ) {

            $resetError =
                'Password must contain at least one uppercase letter.';

        }

        elseif (
            !preg_match(
                '/[a-z]/',
                $newPassword
            )
        ) {

            $resetError =
                'Password must contain at least one lowercase letter.';

        }

        elseif (
            !preg_match(
                '/[0-9]/',
                $newPassword
            )
        ) {

            $resetError =
                'Password must contain at least one number.';

        }

        else {


            /* =================================================
               HASH PASSWORD
            ================================================= */

            $passwordHash =
                password_hash(
                    $newPassword,
                    PASSWORD_DEFAULT
                );


            if (
                $passwordHash === false
            ) {

                $resetError =
                    'Unable to secure the new password. Please try again.';

            } else {


                /* =================================================
                   UPDATE PASSWORD
                ================================================= */

                $updateStmt =
                    $conn->prepare(
                        "
                        UPDATE users
                        SET password = ?
                        WHERE id = ?
                        LIMIT 1
                        "
                    );


                if (!$updateStmt) {

                    $resetError =
                        'Unable to update password. Please try again.';

                } else {


                    $updateStmt->bind_param(
                        "si",
                        $passwordHash,
                        $userId
                    );


                    if (
                        $updateStmt->execute()
                    ) {


                        $updateStmt->close();


                        /* =========================================
                           AUTOMATIC LOGIN
                        ========================================= */


                        /*
                         * Remove old/reset authentication state.
                         */

                        unset(
                            $_SESSION['password_reset_verified'],
                            $_SESSION['otp_user_id'],
                            $_SESSION['otp_email'],
                            $_SESSION['otp_purpose'],
                            $_SESSION['otp_last_resend']
                        );


                        /*
                         * Create fresh authenticated session.
                         */

                        session_regenerate_id(true);


                        $_SESSION['uid'] =
                            (int)$user['id'];


                        $_SESSION['role'] =
                            $user['role'];


                        $_SESSION['name'] =
                            $user['name'];


                        /*
                         * Mark that login came from
                         * successful password reset.
                         */

                        $_SESSION['password_reset_success'] =
                            true;


                        $passwordChanged =
                            true;


                        /* =========================================
                           REDIRECT TO CORRECT DASHBOARD
                        ========================================= */

                        if (
                            $user['role'] === 'admin'
                        ) {

                            header(
                                "Location: admin/dashboard.php"
                            );

                            exit;

                        }


                        if (
                            $user['role'] === 'technician'
                        ) {

                            header(
                                "Location: maintenance/dashboard.php"
                            );

                            exit;

                        }


                        if (
                            $user['role'] === 'owner'
                        ) {

                            header(
                                "Location: owner/dashboard.php"
                            );

                            exit;

                        }


                        /*
                         * Unknown role.
                         */

                        $_SESSION = [];


                        session_destroy();


                        $resetError =
                            'Password changed, but the account role is invalid. Please contact NLETA administrator.';

                    } else {


                        $resetError =
                            'Unable to update password. Please try again.';


                        $updateStmt->close();

                    }

                }

            }

        }

    }

}


/* =========================================================
   ESCAPE
========================================================= */

function resetEscape(
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

<title>NLETA | Reset Password</title>

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


.reset-card {

    max-width:500px;

    margin:60px auto;

    padding:0 15px;

}


.card {

    border:none;

    border-radius:12px;

}


.lock-icon {

    font-size:48px;

}


.email-box {

    background:#f8f9fa;

    border-radius:8px;

    padding:12px;

    word-break:break-word;

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

    font-size:13px;

    color:#6c757d;

}

</style>

</head>


<body>


<div class="container reset-card">

<div class="card shadow p-4">


<!-- =====================================================
     HEADER
===================================================== -->

<div class="text-center mb-4">


<div class="lock-icon">

🔐

</div>


<h3>

Create New Password

</h3>


<p class="text-muted">

OTP verification successful.

</p>


<div class="email-box">

<strong>

<?= resetEscape($email) ?>

</strong>

</div>


</div>


<!-- =====================================================
     ERROR
===================================================== -->

<?php if ($resetError !== ''): ?>

<div class="alert alert-danger">

<?= resetEscape($resetError) ?>

</div>

<?php endif; ?>


<!-- =====================================================
     FORM
===================================================== -->

<form
    method="POST"
    id="resetForm"
    autocomplete="off"
>


<!-- NEW PASSWORD -->

<div class="mb-3">

<label
    for="new_password"
    class="form-label"
>

New Password

</label>


<input
    type="password"
    name="new_password"
    id="new_password"
    class="form-control"
    placeholder="Enter new password"
    minlength="8"
    maxlength="255"
    autocomplete="new-password"
    required
>

</div>


<!-- PASSWORD REQUIREMENTS -->

<div class="password-help mb-3">

Password must contain:

<ul class="mb-0">

<li>At least 8 characters</li>

<li>At least one uppercase letter</li>

<li>At least one lowercase letter</li>

<li>At least one number</li>

</ul>

</div>


<!-- CONFIRM -->

<div class="mb-3">

<label
    for="confirm_password"
    class="form-label"
>

Confirm New Password

</label>


<input
    type="password"
    name="confirm_password"
    id="confirm_password"
    class="form-control"
    placeholder="Re-enter new password"
    minlength="8"
    maxlength="255"
    autocomplete="new-password"
    required
>

</div>


<!-- SHOW PASSWORD -->

<div class="form-check mb-4">

<input
    type="checkbox"
    class="form-check-input"
    id="showPassword"
>


<label
    class="form-check-label"
    for="showPassword"
>

Show password

</label>

</div>


<!-- CSRF -->

<?= csrfField() ?>


<!-- BUTTON -->

<button
    type="submit"
    name="reset_password"
    id="resetButton"
    class="btn btn-nleta w-100"
>

Change Password & Login

</button>


</form>


<div class="text-center mt-3">

<a href="login.php">

← Back to Login

</a>

</div>


</div>

</div>


<script>

/* =========================================================
   SHOW PASSWORD
========================================================= */

const showPassword =
    document.getElementById(
        'showPassword'
    );


const newPassword =
    document.getElementById(
        'new_password'
    );


const confirmPassword =
    document.getElementById(
        'confirm_password'
    );


if (showPassword) {

    showPassword.addEventListener(
        'change',
        function () {

            const type =
                this.checked
                    ? 'text'
                    : 'password';


            newPassword.type =
                type;


            confirmPassword.type =
                type;

        }
    );

}


/* =========================================================
   PASSWORD MATCH
========================================================= */

const resetForm =
    document.getElementById(
        'resetForm'
    );


if (resetForm) {

    resetForm.addEventListener(
        'submit',
        function (event) {

            if (
                newPassword.value !==
                confirmPassword.value
            ) {

                event.preventDefault();


                alert(
                    'New password and confirm password do not match.'
                );


                confirmPassword.focus();


                return false;

            }


            const button =
                document.getElementById(
                    'resetButton'
                );


            if (button) {

                button.disabled =
                    true;

                button.innerText =
                    'Changing Password...';

            }

        }
    );

}

</script>


</body>

</html>