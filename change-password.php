<?php

/* =========================================================
   NLETA - CHANGE PASSWORD

   Works for:
   - Admin
   - Owner
   - Technician

   Requirements:
   - User must already be logged in
   - Current password must be correct
   - New password must meet security requirements
   - CSRF protection enabled

   NO OTP REQUIRED
   because the user is already authenticated.
========================================================= */


/* =========================================================
   START SESSION
========================================================= */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


/* =========================================================
   DATABASE + SECURITY
========================================================= */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/security.php';


/* =========================================================
   GET SESSION USER
========================================================= */

$uid = (int)($_SESSION['uid'] ?? 0);

$role = strtolower(
    trim(
        $_SESSION['role'] ?? ''
    )
);


/* =========================================================
   LOGIN CHECK
========================================================= */

if ($uid <= 0) {

    header("Location: login.php");
    exit;
}


/* =========================================================
   ROLE CHECK
========================================================= */

if (
    !in_array(
        $role,
        [
            'admin',
            'owner',
            'technician'
        ],
        true
    )
) {

    $_SESSION = [];

    session_destroy();

    header("Location: login.php");
    exit;
}


/* =========================================================
   LOAD USER
========================================================= */

$stmt = $conn->prepare("
    SELECT
        id,
        name,
        email,
        password,
        role,
        account_status
    FROM users
    WHERE id = ?
    LIMIT 1
");


if (!$stmt) {

    die(
        "Unable to load account."
    );
}


$stmt->bind_param(
    "i",
    $uid
);


$stmt->execute();


$result = $stmt->get_result();


$user = $result->fetch_assoc();


$stmt->close();


/* =========================================================
   USER NOT FOUND
========================================================= */

if (!$user) {

    $_SESSION = [];

    session_destroy();

    header("Location: login.php");
    exit;
}


/* =========================================================
   ACCOUNT STATUS
========================================================= */

if (
    isset($user['account_status']) &&
    $user['account_status'] !== 'active'
) {

    $_SESSION = [];

    session_destroy();

    header("Location: login.php");
    exit;
}


/* =========================================================
   VARIABLES
========================================================= */

$error = '';

$success = '';


/* =========================================================
   CHANGE PASSWORD
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['change_password'])
) {


    /* =====================================================
       CSRF VALIDATION
    ===================================================== */

    if (
        !validateCSRFToken(
            $_POST['csrf_token'] ?? ''
        )
    ) {

        $error =
            'Invalid request. Please refresh the page and try again.';

    } else {


        /* =================================================
           GET FORM DATA
        ================================================= */

        $currentPassword =
            $_POST['current_password'] ?? '';

        $newPassword =
            $_POST['new_password'] ?? '';

        $confirmPassword =
            $_POST['confirm_password'] ?? '';


        /* =================================================
           CURRENT PASSWORD EMPTY
        ================================================= */

        if (
            $currentPassword === ''
        ) {

            $error =
                'Please enter your current password.';

        }


        /* =================================================
           VERIFY CURRENT PASSWORD
        ================================================= */

        elseif (
            !password_verify(
                $currentPassword,
                $user['password']
            )
        ) {

            $error =
                'Current password is incorrect.';

        }


        /* =================================================
           NEW PASSWORD EMPTY
        ================================================= */

        elseif (
            $newPassword === ''
        ) {

            $error =
                'Please enter a new password.';

        }


        /* =================================================
           PASSWORD LENGTH
        ================================================= */

        elseif (
            strlen($newPassword) < 8
        ) {

            $error =
                'New password must be at least 8 characters long.';

        }


        /* =================================================
           PASSWORD MAX LENGTH
        ================================================= */

        elseif (
            strlen($newPassword) > 255
        ) {

            $error =
                'New password is too long.';

        }


        /* =================================================
           PASSWORD MUST BE DIFFERENT
        ================================================= */

        elseif (
            password_verify(
                $newPassword,
                $user['password']
            )
        ) {

            $error =
                'New password must be different from your current password.';

        }


        /* =================================================
           CONFIRM PASSWORD
        ================================================= */

        elseif (
            $newPassword !==
            $confirmPassword
        ) {

            $error =
                'New password and confirm password do not match.';

        }


        /* =================================================
           UPPERCASE
        ================================================= */

        elseif (
            !preg_match(
                '/[A-Z]/',
                $newPassword
            )
        ) {

            $error =
                'New password must contain at least one uppercase letter.';

        }


        /* =================================================
           LOWERCASE
        ================================================= */

        elseif (
            !preg_match(
                '/[a-z]/',
                $newPassword
            )
        ) {

            $error =
                'New password must contain at least one lowercase letter.';

        }


        /* =================================================
           NUMBER
        ================================================= */

        elseif (
            !preg_match(
                '/[0-9]/',
                $newPassword
            )
        ) {

            $error =
                'New password must contain at least one number.';

        }


        /* =================================================
           EVERYTHING VALID
        ================================================= */

        else {


            /* =================================================
               HASH NEW PASSWORD
            ================================================= */

            $newPasswordHash =
                password_hash(
                    $newPassword,
                    PASSWORD_DEFAULT
                );


            if (
                $newPasswordHash === false
            ) {

                $error =
                    'Unable to secure your new password. Please try again.';

            } else {


                /* =================================================
                   UPDATE DATABASE
                ================================================= */

                $updateStmt =
                    $conn->prepare("
                        UPDATE users
                        SET password = ?
                        WHERE id = ?
                        LIMIT 1
                    ");


                if (!$updateStmt) {

                    $error =
                        'Unable to change password. Please try again.';

                } else {


                    $updateStmt->bind_param(
                        "si",
                        $newPasswordHash,
                        $uid
                    );


                    if (
                        $updateStmt->execute()
                    ) {


                        /*
                         * Password successfully changed.
                         *
                         * User stays logged in.
                         */

                        $success =
                            'Your password has been changed successfully.';


                        /*
                         * Clear POST data.
                         */

                        $_POST = [];


                    } else {

                        $error =
                            'Unable to change password. Please try again.';

                    }


                    $updateStmt->close();

                }

            }

        }

    }

}


/* =========================================================
   DISPLAY VALUES
========================================================= */

$name =
    htmlspecialchars(
        $user['name'] ?? '',
        ENT_QUOTES,
        'UTF-8'
    );


$email =
    htmlspecialchars(
        $user['email'] ?? '',
        ENT_QUOTES,
        'UTF-8'
    );


/* =========================================================
   DASHBOARD URL
========================================================= */

if (
    $role === 'admin'
) {

    $dashboardUrl =
        'admin/dashboard.php';

} elseif (
    $role === 'owner'
) {

    $dashboardUrl =
        'owner/dashboard.php';

} else {

    $dashboardUrl =
        'maintenance/dashboard.php';

}

?>

<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>
    Change Password - NLETA
</title>


<!-- =====================================================
     BOOTSTRAP
===================================================== -->

<link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
    rel="stylesheet"
>


<!-- =====================================================
     BOOTSTRAP ICONS
===================================================== -->

<link
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
    rel="stylesheet"
>


<style>

/* =========================================================
   BODY
========================================================= */

body {

    margin: 0;

    background: #f4f6f9;

    font-family:
        Arial,
        sans-serif;

    color: #1f2937;

}


/* =========================================================
   HEADER
========================================================= */

.page-header {

    height: 74px;

    background: #202428;

    color: #fff;

    display: flex;

    align-items: center;

    justify-content: center;

    position: relative;

}


.page-title {

    font-size: 24px;

    font-weight: 600;

}


.header-left {

    position: absolute;

    left: 24px;

    display: flex;

    align-items: center;

}


.header-left i {

    font-size: 25px;

}


.header-right {

    position: absolute;

    right: 24px;

}


/* =========================================================
   BACK BUTTON
========================================================= */

.back-btn {

    text-decoration: none;

    color: #fff;

    border:
        1px solid
        rgba(255,255,255,.4);

    padding: 8px 15px;

    border-radius: 6px;

    transition: .2s;

}


.back-btn:hover {

    background:
        rgba(255,255,255,.1);

    color: #fff;

}


/* =========================================================
   CONTAINER
========================================================= */

.password-container {

    max-width: 600px;

    margin: 45px auto;

    padding: 0 20px;

}


/* =========================================================
   CARD
========================================================= */

.password-card {

    background: #fff;

    border-radius: 15px;

    box-shadow:
        0 5px 20px
        rgba(0,0,0,.08);

    padding: 35px;

}


/* =========================================================
   ICON
========================================================= */

.password-icon {

    width: 75px;

    height: 75px;

    border-radius: 50%;

    background: #f1f3f5;

    display: flex;

    align-items: center;

    justify-content: center;

    margin:
        0 auto
        20px;

}


.password-icon i {

    font-size: 35px;

    color: #ea232a;

}


/* =========================================================
   USER INFO
========================================================= */

.user-info {

    background: #f8f9fa;

    border:
        1px solid
        #e5e7eb;

    border-radius: 10px;

    padding: 15px;

    margin-bottom: 25px;

}


.user-name {

    font-size: 18px;

    font-weight: 600;

}


.user-email {

    font-size: 14px;

    color: #6b7280;

    word-break: break-word;

}


/* =========================================================
   LABEL
========================================================= */

.form-label {

    font-weight: 600;

}


/* =========================================================
   PASSWORD WRAPPER
========================================================= */

.password-wrapper {

    position: relative;

}


.password-wrapper input {

    padding-right: 48px;

}


.password-toggle {

    position: absolute;

    right: 12px;

    top: 50%;

    transform:
        translateY(-50%);

    border: none;

    background: transparent;

    color: #6b7280;

    cursor: pointer;

    font-size: 18px;

}


/* =========================================================
   REQUIREMENTS
========================================================= */

.requirements {

    background: #f8f9fa;

    border-radius: 8px;

    padding:
        12px
        15px;

    font-size: 13px;

    color: #6b7280;

}


.requirements ul {

    margin-bottom: 0;

}


/* =========================================================
   MAIN BUTTON
========================================================= */

.btn-nleta {

    background: #ea232a;

    border: none;

    color: #fff;

    padding: 11px;

    font-weight: 600;

}


.btn-nleta:hover {

    background: #333;

    color: #fff;

}


/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 700px) {

    .page-title {

        font-size: 18px;

    }


    .header-left {

        left: 12px;

    }


    .header-right {

        right: 12px;

    }


    .password-card {

        padding:
            25px
            20px;

    }


    .back-btn {

        padding:
            7px
            10px;

        font-size: 13px;

    }

}

</style>

</head>


<body>


<!-- =====================================================
     HEADER
===================================================== -->

<div class="page-header">


    <div class="header-left">

        <i class="bi bi-shield-lock"></i>

    </div>


    <div class="page-title">

        Change Password

    </div>


    <div class="header-right">

        <a
            href="<?= htmlspecialchars(
                $dashboardUrl,
                ENT_QUOTES,
                'UTF-8'
            ) ?>"
            class="back-btn"
        >

            <i class="bi bi-arrow-left"></i>

            Back

        </a>

    </div>

</div>


<!-- =====================================================
     MAIN
===================================================== -->

<div class="password-container">


    <div class="password-card">


        <!-- =================================================
             ICON
        ================================================== -->

        <div class="password-icon">

            <i class="bi bi-key-fill"></i>

        </div>


        <h3 class="text-center mb-2">

            Change Password

        </h3>


        <p class="text-center text-muted mb-4">

            Update your NLETA account password.

        </p>


        <!-- =================================================
             USER INFORMATION
        ================================================== -->

        <div class="user-info">


            <div class="user-name">

                <i class="bi bi-person-circle"></i>

                <?= $name ?>

            </div>


            <div class="user-email">

                <i class="bi bi-envelope"></i>

                <?= $email ?: 'Email not available' ?>

            </div>


        </div>


        <!-- =================================================
             SUCCESS MESSAGE
        ================================================== -->

        <?php if ($success !== ''): ?>

            <div
                class="alert alert-success"
                role="alert"
            >

                <i class="bi bi-check-circle"></i>

                <?= htmlspecialchars(
                    $success,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>

            </div>

        <?php endif; ?>


        <!-- =================================================
             ERROR MESSAGE
        ================================================== -->

        <?php if ($error !== ''): ?>

            <div
                class="alert alert-danger"
                role="alert"
            >

                <i class="bi bi-exclamation-triangle"></i>

                <?= htmlspecialchars(
                    $error,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>

            </div>

        <?php endif; ?>


        <!-- =================================================
             FORM
        ================================================== -->

        <form
            method="POST"
            id="passwordForm"
            autocomplete="off"
        >


            <!-- =============================================
                 CURRENT PASSWORD
            ============================================== -->

            <div class="mb-3">


                <label
                    for="current_password"
                    class="form-label"
                >

                    Current Password

                </label>


                <div class="password-wrapper">


                    <input
                        type="password"
                        name="current_password"
                        id="current_password"
                        class="form-control"
                        placeholder="Enter current password"
                        autocomplete="current-password"
                        required
                    >


                    <button
                        type="button"
                        class="password-toggle"
                        onclick="togglePassword(
                            'current_password',
                            'currentEye'
                        )"
                        aria-label="Show or hide current password"
                    >

                        <i
                            class="bi bi-eye"
                            id="currentEye"
                        ></i>

                    </button>


                </div>

            </div>


            <!-- =============================================
                 NEW PASSWORD
            ============================================== -->

            <div class="mb-3">


                <label
                    for="new_password"
                    class="form-label"
                >

                    New Password

                </label>


                <div class="password-wrapper">


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


                    <button
                        type="button"
                        class="password-toggle"
                        onclick="togglePassword(
                            'new_password',
                            'newEye'
                        )"
                        aria-label="Show or hide new password"
                    >

                        <i
                            class="bi bi-eye"
                            id="newEye"
                        ></i>

                    </button>


                </div>

            </div>


            <!-- =============================================
                 REQUIREMENTS
            ============================================== -->

            <div class="requirements mb-3">


                <strong>

                    Password requirements:

                </strong>


                <ul class="mt-2">

                    <li>
                        At least 8 characters
                    </li>

                    <li>
                        At least one uppercase letter
                    </li>

                    <li>
                        At least one lowercase letter
                    </li>

                    <li>
                        At least one number
                    </li>

                    <li>
                        Must be different from current password
                    </li>

                </ul>


            </div>


            <!-- =============================================
                 CONFIRM PASSWORD
            ============================================== -->

            <div class="mb-4">


                <label
                    for="confirm_password"
                    class="form-label"
                >

                    Confirm New Password

                </label>


                <div class="password-wrapper">


                    <input
                        type="password"
                        name="confirm_password"
                        id="confirm_password"
                        class="form-control"
                        placeholder="Confirm new password"
                        minlength="8"
                        maxlength="255"
                        autocomplete="new-password"
                        required
                    >


                    <button
                        type="button"
                        class="password-toggle"
                        onclick="togglePassword(
                            'confirm_password',
                            'confirmEye'
                        )"
                        aria-label="Show or hide confirm password"
                    >

                        <i
                            class="bi bi-eye"
                            id="confirmEye"
                        ></i>

                    </button>


                </div>

            </div>


            <!-- =============================================
                 CSRF
            ============================================== -->

            <?= csrfField() ?>


            <!-- =============================================
                 SUBMIT
            ============================================== -->

            <button
                type="submit"
                name="change_password"
                id="changePasswordButton"
                class="btn btn-nleta w-100"
            >

                <i class="bi bi-key"></i>

                Change Password

            </button>


        </form>


        <!-- =================================================
             BACK TO PROFILE
        ================================================== -->

        <div class="text-center mt-3">


            <a href="profile.php">

                ← Back to Profile

            </a>


        </div>


    </div>


</div>


<script>

/* =========================================================
   SHOW / HIDE PASSWORD
========================================================= */

function togglePassword(
    fieldId,
    eyeId
) {


    const field =
        document.getElementById(
            fieldId
        );


    const eye =
        document.getElementById(
            eyeId
        );


    if (
        !field ||
        !eye
    ) {

        return;

    }


    if (
        field.type === 'password'
    ) {

        field.type =
            'text';


        eye.classList.remove(
            'bi-eye'
        );


        eye.classList.add(
            'bi-eye-slash'
        );


    } else {

        field.type =
            'password';


        eye.classList.remove(
            'bi-eye-slash'
        );


        eye.classList.add(
            'bi-eye'
        );

    }

}


/* =========================================================
   FORM ELEMENTS
========================================================= */

const passwordForm =
    document.getElementById(
        'passwordForm'
    );


const currentPassword =
    document.getElementById(
        'current_password'
    );


const newPassword =
    document.getElementById(
        'new_password'
    );


const confirmPassword =
    document.getElementById(
        'confirm_password'
    );


/* =========================================================
   CLIENT-SIDE VALIDATION
========================================================= */

if (passwordForm) {


    passwordForm.addEventListener(
        'submit',
        function(event) {


            /* =============================================
               MINIMUM LENGTH
            ============================================== */

            if (
                newPassword.value.length < 8
            ) {

                event.preventDefault();


                alert(
                    'New password must be at least 8 characters long.'
                );


                newPassword.focus();


                return;

            }


            /* =============================================
               SAME PASSWORD
            ============================================== */

            if (
                currentPassword.value ===
                newPassword.value
            ) {

                event.preventDefault();


                alert(
                    'New password must be different from your current password.'
                );


                newPassword.focus();


                return;

            }


            /* =============================================
               PASSWORD MATCH
            ============================================== */

            if (
                newPassword.value !==
                confirmPassword.value
            ) {

                event.preventDefault();


                alert(
                    'New password and confirm password do not match.'
                );


                confirmPassword.focus();


                return;

            }


            /* =============================================
               UPPERCASE
            ============================================== */

            if (
                !/[A-Z]/.test(
                    newPassword.value
                )
            ) {

                event.preventDefault();


                alert(
                    'New password must contain at least one uppercase letter.'
                );


                newPassword.focus();


                return;

            }


            /* =============================================
               LOWERCASE
            ============================================== */

            if (
                !/[a-z]/.test(
                    newPassword.value
                )
            ) {

                event.preventDefault();


                alert(
                    'New password must contain at least one lowercase letter.'
                );


                newPassword.focus();


                return;

            }


            /* =============================================
               NUMBER
            ============================================== */

            if (
                !/[0-9]/.test(
                    newPassword.value
                )
            ) {

                event.preventDefault();


                alert(
                    'New password must contain at least one number.'
                );


                newPassword.focus();


                return;

            }


            /* =============================================
               PREVENT DOUBLE SUBMIT
            ============================================== */

            const button =
                document.getElementById(
                    'changePasswordButton'
                );


            if (button) {

                button.disabled =
                    true;


                button.innerHTML =
                    '<i class="bi bi-hourglass-split"></i> Changing Password...';

            }

        }
    );

}

</script>


</body>

</html>