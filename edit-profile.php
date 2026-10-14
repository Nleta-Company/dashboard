<?php

/* =========================================================
   NLETA - EDIT PROFILE

   Works for:
   - Admin
   - Owner
   - Technician
========================================================= */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/security.php';


/* =========================================================
   LOGIN CHECK
========================================================= */

$uid = (int)($_SESSION['uid'] ?? 0);

if ($uid <= 0) {

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
        email2,
        phone,
        phone2,
        organization_name,
        organization_address,
        role
    FROM users
    WHERE id = ?
    LIMIT 1
");

if (!$stmt) {

    die("Unable to load profile.");

}

$stmt->bind_param(
    "i",
    $uid
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
   UPDATE PROFILE
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['update_profile'])
) {


    /* =====================================================
       CSRF
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
           INPUT
        ================================================= */

        $name =
            trim(
                $_POST['name'] ?? ''
            );


        $email =
            trim(
                $_POST['email'] ?? ''
            );


        $email2 =
            trim(
                $_POST['email2'] ?? ''
            );


        $phone =
            trim(
                $_POST['phone'] ?? ''
            );


        $phone2 =
            trim(
                $_POST['phone2'] ?? ''
            );


        $organizationName =
            trim(
                $_POST['organization_name'] ?? ''
            );


        $organizationAddress =
            trim(
                $_POST['organization_address'] ?? ''
            );


        /* =================================================
           ORGANIZATION / ASSOCIATION
        ================================================= */

        if ($organizationName === '') {

            $error =
                'Please enter your Organization / Association Name.';

        }

        elseif (mb_strlen($organizationName) > 200) {

            $error =
                'Organization / Association Name is too long.';

        }

        elseif ($organizationAddress === '') {

            $error =
                'Please enter your Organization / Association Address.';

        }

        elseif (mb_strlen($organizationAddress) > 500) {

            $error =
                'Organization / Association Address is too long.';

        }


        /* =================================================
           NAME
        ================================================= */

        if ($error === '' && $name === '') {

            $error =
                'Please enter your full name.';

        }

        elseif ($error === '' && mb_strlen($name) < 2) {

            $error =
                'Full name must contain at least 2 characters.';

        }

        elseif ($error === '' && mb_strlen($name) > 150) {

            $error =
                'Full name is too long.';

        }


        /* =================================================
           EMAIL
        ================================================= */

        elseif ($email === '') {

            $error =
                'Primary email address is required.';

        }

        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

            $error =
                'Please enter a valid primary email address.';

        }

        elseif (strlen($email) > 255) {

            $error =
                'Primary email address is too long.';

        }


        /* =================================================
           SECONDARY EMAIL
        ================================================= */

        elseif (
            $email2 !== '' &&
            !filter_var(
                $email2,
                FILTER_VALIDATE_EMAIL
            )
        ) {

            $error =
                'Please enter a valid secondary email address.';

        }


        /* =================================================
           CHECK PRIMARY EMAIL
        ================================================= */

        if ($error === '') {

            $checkEmail =
                $conn->prepare("
                    SELECT id
                    FROM users
                    WHERE email = ?
                    AND id != ?
                    LIMIT 1
                ");


            if (!$checkEmail) {

                $error =
                    'Unable to validate email address.';

            } else {

                $checkEmail->bind_param(
                    "si",
                    $email,
                    $uid
                );

                $checkEmail->execute();

                $existing =
                    $checkEmail
                        ->get_result()
                        ->fetch_assoc();

                $checkEmail->close();


                if ($existing) {

                    $error =
                        'This email address is already used by another account.';

                }

            }

        }


        /* =================================================
           UPDATE
        ================================================= */

        if ($error === '') {

            $update =
                $conn->prepare("
                    UPDATE users
                    SET
                        name = ?,
                        email = ?,
                        email2 = ?,
                        phone = ?,
                        phone2 = ?,
                        organization_name = ?,
                        organization_address = ?
                    WHERE id = ?
                    LIMIT 1
                ");


            if (!$update) {

                $error =
                    'Unable to update profile. Please try again.';

            } else {


                $update->bind_param(
                    "sssssssi",
                    $name,
                    $email,
                    $email2,
                    $phone,
                    $phone2,
                    $organizationName,
                    $organizationAddress,
                    $uid
                );


                if ($update->execute()) {

                    /*
                     * Update session name immediately.
                     */

                    $_SESSION['name'] =
                        $name;


                    $success =
                        'Profile updated successfully.';


                    /*
                     * Update local values so
                     * form displays latest data.
                     */

                    $user['name'] =
                        $name;

                    $user['email'] =
                        $email;

                    $user['email2'] =
                        $email2;

                    $user['phone'] =
                        $phone;

                    $user['phone2'] =
                        $phone2;

                    $user['organization_name'] =
                        $organizationName;

                    $user['organization_address'] =
                        $organizationAddress;


                } else {

                    $error =
                        'Unable to update profile. Please try again.';

                }


                $update->close();

            }

        }

    }

}


/* =========================================================
   SAFE VALUES
========================================================= */

$nameValue =
    htmlspecialchars(
        $user['name'] ?? '',
        ENT_QUOTES,
        'UTF-8'
    );


$emailValue =
    htmlspecialchars(
        $user['email'] ?? '',
        ENT_QUOTES,
        'UTF-8'
    );


$email2Value =
    htmlspecialchars(
        $user['email2'] ?? '',
        ENT_QUOTES,
        'UTF-8'
    );


$phoneValue =
    htmlspecialchars(
        $user['phone'] ?? '',
        ENT_QUOTES,
        'UTF-8'
    );


$phone2Value =
    htmlspecialchars(
        $user['phone2'] ?? '',
        ENT_QUOTES,
        'UTF-8'
    );


$organizationNameValue =
    htmlspecialchars(
        $user['organization_name'] ?? '',
        ENT_QUOTES,
        'UTF-8'
    );


$organizationAddressValue =
    htmlspecialchars(
        $user['organization_address'] ?? '',
        ENT_QUOTES,
        'UTF-8'
    );


$role =
    htmlspecialchars(
        ucfirst(
            $user['role'] ?? ''
        ),
        ENT_QUOTES,
        'UTF-8'
    );


/* =========================================================
   DASHBOARD URL
========================================================= */

$currentRole =
    strtolower(
        trim(
            $_SESSION['role'] ?? ''
        )
    );


if ($currentRole === 'admin') {

    $dashboardUrl =
        'admin/dashboard.php';

} elseif ($currentRole === 'owner') {

    $dashboardUrl =
        'owner/dashboard.php';

} elseif ($currentRole === 'technician') {

    $dashboardUrl =
        'maintenance/dashboard.php';

} else {

    $dashboardUrl =
        'login.php';
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
    Edit Profile - NLETA
</title>


<link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
    rel="stylesheet"
>

<link
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
    rel="stylesheet"
>


<style>

* {
    box-sizing: border-box;
}

body {

    margin: 0;

    background: #f4f6f9;

    font-family: Arial, sans-serif;

    color: #1f2937;

    overflow-x: hidden;

    overflow-y: auto;
}


/* =========================================================
   HEADER
========================================================= */

.page-header {

    height: 64px;

    background: #202428;

    color: #fff;

    display: flex;

    align-items: center;

    justify-content: center;

    position: relative;
}


.page-title {

    font-size: 22px;

    font-weight: 600;
}


.header-left {

    position: absolute;

    left: 24px;
}


.header-left i {

    font-size: 23px;
}


.header-right {

    position: absolute;

    right: 24px;
}


.back-btn {

    color: #fff;

    text-decoration: none;

    border:
        1px solid
        rgba(255,255,255,.4);

    padding:
        7px 13px;

    border-radius: 6px;

    font-size: 14px;
}


.back-btn:hover {

    background:
        rgba(255,255,255,.1);

    color: #fff;
}


/* =========================================================
   CONTAINER
========================================================= */

.edit-container {

    max-width: 700px;

    margin: 30px auto;

    padding:
        0 20px;
}


/* =========================================================
   CARD
========================================================= */

.edit-card {

    background: #fff;

    border-radius: 14px;

    box-shadow:
        0 4px 18px
        rgba(0,0,0,.08);

    padding: 28px;
}


/* =========================================================
   TITLE
========================================================= */

.edit-title {

    text-align: center;

    font-size: 22px;

    font-weight: 600;

    margin-bottom: 5px;
}


.edit-subtitle {

    text-align: center;

    color: #6b7280;

    font-size: 14px;

    margin-bottom: 25px;
}


/* =========================================================
   FORM LABEL
========================================================= */

.form-label {

    font-weight: 600;

    font-size: 14px;
}


/* =========================================================
   INPUT
========================================================= */

.form-control {

    min-height: 44px;

    border-radius: 7px;

    width: 100%;

    max-width: 100%;
}

textarea.form-control {

    min-height: 105px;

    resize: vertical;
}


/* =========================================================
   SAVE BUTTON
========================================================= */

.save-btn {

    background: #0d6efd;

    color: #fff;

    border: none;

    border-radius: 7px;

    padding:
        10px 25px;

    font-weight: 600;
}


.save-btn:hover {

    background: #084298;

    color: #fff;
}


/* =========================================================
   CANCEL
========================================================= */

.cancel-btn {

    background: #6c757d;

    color: #fff;

    border: none;

    border-radius: 7px;

    padding:
        10px 25px;

    font-weight: 600;

    text-decoration: none;
}


.cancel-btn:hover {

    background: #495057;

    color: #fff;
}


/* =========================================================
   RESPONSIVE SAFETY
========================================================= */

@media (max-width: 991.98px) {

    .edit-container {
        width: 100%;
        max-width: 760px;
        padding-left: 18px;
        padding-right: 18px;
    }

    .edit-card {
        width: 100%;
    }

    .form-control {
        min-width: 0;
    }
}


/* =========================================================
   TABLET
========================================================= */

@media (max-width: 991.98px) {

    .edit-container {
        width: 100%;
        max-width: 760px;
        margin: 24px auto;
        padding: 0 18px;
    }

    .edit-card {
        width: 100%;
        padding: 26px 24px;
    }

    .page-title {
        font-size: 20px;
    }

    .header-left {
        left: 18px;
    }

    .header-right {
        right: 18px;
    }

    .form-control {
        width: 100%;
        min-width: 0;
    }
}


/* =========================================================
   MOBILE
========================================================= */

@media (max-width: 700px) {

    body {
        overflow-x: hidden;
        overflow-y: auto;
    }

    .page-header {
        min-height: 60px;
        height: auto;
        padding: 10px 12px;
    }

    .page-title {
        font-size: 18px;
        line-height: 1.2;
        padding: 0 95px;
        text-align: center;
        white-space: nowrap;
    }

    .header-left {
        left: 12px;
    }

    .header-left i {
        font-size: 21px;
    }

    .header-right {
        right: 12px;
    }

    .back-btn {
        padding: 6px 9px;
        font-size: 12px;
        white-space: nowrap;
    }

    .edit-container {
        width: 100%;
        max-width: none;
        margin: 16px auto 24px;
        padding: 0 10px;
    }

    .edit-card {
        width: 100%;
        padding: 20px 14px;
        border-radius: 12px;
    }

    .edit-title {
        font-size: 20px;
    }

    .edit-subtitle {
        font-size: 13px;
        line-height: 1.4;
        margin-bottom: 20px;
    }

    .form-label {
        font-size: 13px;
    }

    .form-control {
        min-height: 44px;
        font-size: 14px;
        width: 100%;
    }

    textarea.form-control {
        min-height: 105px;
    }

    .form-text {
        font-size: 11px;
        line-height: 1.35;
    }

    .d-flex.justify-content-center.gap-2 {
        width: 100%;
        display: grid !important;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px !important;
    }

    .cancel-btn,
    .save-btn {
        width: 100%;
        min-width: 0;
        padding: 10px 8px;
        font-size: 13px;
        text-align: center;
    }
}


/* =========================================================
   VERY SMALL MOBILE
========================================================= */

@media (max-width: 430px) {

    .page-header {
        padding: 9px 8px;
    }

    .page-title {
        font-size: 16px;
        padding: 0 82px;
    }

    .header-left {
        left: 8px;
    }

    .header-right {
        right: 8px;
    }

    .back-btn {
        font-size: 11px;
        padding: 5px 7px;
    }

    .edit-container {
        padding: 0 7px;
        margin-top: 10px;
    }

    .edit-card {
        padding: 18px 10px;
    }

    .edit-title {
        font-size: 19px;
    }

    .edit-subtitle {
        font-size: 12px;
    }

    .form-control {
        font-size: 13px;
    }

    .cancel-btn,
    .save-btn {
        font-size: 12px;
        padding: 9px 5px;
    }
}


/* =========================================================
   EXTRA SMALL MOBILE
========================================================= */

@media (max-width: 340px) {

    .page-title {
        font-size: 15px;
        padding: 0 74px;
    }

    .back-btn {
        font-size: 10px;
    }

    .d-flex.justify-content-center.gap-2 {
        grid-template-columns: 1fr;
    }
}


</style>

</head>


<body>


<!-- =====================================================
     HEADER
====================================================== -->

<div class="page-header">


    <div class="header-left">

        <i class="bi bi-pencil-square"></i>

    </div>


    <div class="page-title">

        Edit Profile

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
====================================================== -->

<div class="edit-container">


    <div class="edit-card">


        <div class="edit-title">

            Edit Profile

        </div>


        <div class="edit-subtitle">

            Update your NLETA account information.

        </div>


        <!-- =================================================
             SUCCESS
        ================================================== -->

        <?php if ($success !== ''): ?>

            <div class="alert alert-success">

                <i class="bi bi-check-circle"></i>

                <?= htmlspecialchars(
                    $success,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>

            </div>

        <?php endif; ?>


        <!-- =================================================
             ERROR
        ================================================== -->

        <?php if ($error !== ''): ?>

            <div class="alert alert-danger">

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
            autocomplete="off"
        >


            <!-- FULL NAME -->

            <div class="mb-3">

                <label
                    class="form-label"
                    for="name"
                >

                    Full Name

                </label>


                <input
                    type="text"
                    name="name"
                    id="name"
                    class="form-control"
                    value="<?= $nameValue ?>"
                    maxlength="150"
                    required
                >

            </div>


            <!-- ASSOCIATION NAME -->

            <div class="mb-3">

                <label
                    class="form-label"
                    for="organization_name"
                >

                    Organization / Association Name

                </label>


                <input
                    type="text"
                    name="organization_name"
                    id="organization_name"
                    class="form-control"
                    value="<?= $organizationNameValue ?>"
                    maxlength="200"
                    autocomplete="organization"
                    required
                >

                <div class="form-text">
                    This name will be used as the permanent Organization / Association name in NLETA documentation wherever required.
                </div>

            </div>


            <!-- ASSOCIATION ADDRESS -->

            <div class="mb-3">

                <label
                    class="form-label"
                    for="organization_address"
                >

                    Organization / Association Address

                </label>


                <textarea
                    name="organization_address"
                    id="organization_address"
                    class="form-control"
                    rows="3"
                    maxlength="500"
                    autocomplete="street-address"
                    required
                ><?= $organizationAddressValue ?></textarea>

                <div class="form-text">
                    This address will be used as the permanent Organization / Association address in NLETA documentation wherever required.
                </div>

            </div>


            <!-- PRIMARY EMAIL -->

            <div class="mb-3">

                <label
                    class="form-label"
                    for="email"
                >

                    Primary Email

                </label>


                <input
                    type="email"
                    name="email"
                    id="email"
                    class="form-control"
                    value="<?= $emailValue ?>"
                    maxlength="255"
                    required
                >

            </div>


            <!-- SECONDARY EMAIL -->

            <div class="mb-3">

                <label
                    class="form-label"
                    for="email2"
                >

                    Secondary Email

                </label>


                <input
                    type="email"
                    name="email2"
                    id="email2"
                    class="form-control"
                    value="<?= $email2Value ?>"
                    maxlength="255"
                >

            </div>


            <!-- PRIMARY PHONE -->

            <div class="mb-3">

                <label
                    class="form-label"
                    for="phone"
                >

                    Primary Phone

                </label>


                <input
                    type="text"
                    name="phone"
                    id="phone"
                    class="form-control"
                    value="<?= $phoneValue ?>"
                    maxlength="15"
                >

            </div>


            <!-- SECONDARY PHONE -->

            <div class="mb-4">

                <label
                    class="form-label"
                    for="phone2"
                >

                    Secondary Phone

                </label>


                <input
                    type="text"
                    name="phone2"
                    id="phone2"
                    class="form-control"
                    value="<?= $phone2Value ?>"
                    maxlength="15"
                >

            </div>


            <!-- CSRF -->

            <?= csrfField() ?>


            <!-- BUTTONS -->

            <div class="d-flex justify-content-center gap-2">


                <a
                    href="profile.php"
                    class="cancel-btn"
                >

                    <i class="bi bi-x-circle"></i>

                    Cancel

                </a>


                <button
                    type="submit"
                    name="update_profile"
                    class="save-btn"
                >

                    <i class="bi bi-check-circle"></i>

                    Save Changes

                </button>


            </div>


        </form>


    </div>

</div>


</body>

</html>