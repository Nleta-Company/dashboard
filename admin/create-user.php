<?php
/* =========================================================
   NLETA ADMIN — CREATE TECHNICIAN / INSPECTOR ACCOUNT
   ---------------------------------------------------------
   Admin can create:
   - Technician
   - Inspector

   Owner creation is NOT handled here.
========================================================= */

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

include('../config/db.php');
include('../config/auth.php');
require_once '../config/security.php';

require_role('admin');


/* =========================================================
   VARIABLES
========================================================= */

$error = '';

$allowed_roles = [
    'technician',
    'inspector'
];


/* =========================================================
   CREATE ACCOUNT
========================================================= */

if (isset($_POST['save'])) {

    /* =====================================================
       CSRF
    ===================================================== */

    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {

        $error = 'Invalid request. Please try again.';

    } else {

        /* =================================================
           INPUTS
        ================================================= */

        $name = trim(
            $_POST['name'] ?? ''
        );

        $email = filter_var(
            trim($_POST['email'] ?? ''),
            FILTER_VALIDATE_EMAIL
        );

        $email2 = trim(
            $_POST['email2'] ?? ''
        );

        $phone = preg_replace(
            '/[^0-9]/',
            '',
            trim($_POST['phone'] ?? '')
        );

        $phone2 = preg_replace(
            '/[^0-9]/',
            '',
            trim($_POST['phone2'] ?? '')
        );

        $role = trim(
            $_POST['role'] ?? ''
        );

        $plainPassword = (string)(
            $_POST['password'] ?? ''
        );


        /* =================================================
           VALIDATION
        ================================================= */

        if (!in_array($role, $allowed_roles, true)) {

            $error =
                'Please select Technician or Inspector.';

        } elseif (
            $name === '' ||
            !$email ||
            $plainPassword === ''
        ) {

            $error =
                'Name, valid email, password and role are required.';

        } elseif (
            $phone !== '' &&
            strlen($phone) !== 10
        ) {

            $error =
                'Phone number must be exactly 10 digits.';

        } elseif (
            $phone2 !== '' &&
            strlen($phone2) !== 10
        ) {

            $error =
                'Second phone number must be exactly 10 digits.';

        } elseif (
            $email2 !== '' &&
            !filter_var(
                $email2,
                FILTER_VALIDATE_EMAIL
            )
        ) {

            $error =
                'Invalid second email address.';

        } elseif (
            $email2 !== '' &&
            strcasecmp(
                $email,
                $email2
            ) === 0
        ) {

            $error =
                'Email 2 must be different from Email 1.';

        } else {

            /* =============================================
               PASSWORD STRENGTH
            ============================================== */

            $pwCheck =
                validatePasswordStrength(
                    $plainPassword
                );

            if (!$pwCheck['valid']) {

                $error =
                    $pwCheck['error'];

            } else {

                /* =========================================
                   CHECK EMAIL 1
                ========================================== */

                $check =
                    $conn->prepare("
                        SELECT id
                        FROM users
                        WHERE email = ?
                           OR email2 = ?
                        LIMIT 1
                    ");

                if (!$check) {

                    $error =
                        'Unable to check existing email: ' .
                        $conn->error;

                } else {

                    $check->bind_param(
                        'ss',
                        $email,
                        $email
                    );

                    $check->execute();

                    $existing =
                        $check
                        ->get_result()
                        ->fetch_assoc();

                    $check->close();


                    if ($existing) {

                        $error =
                            'This email is already registered.';

                    } else {

                        /* =================================
                           CHECK EMAIL 2
                        ================================= */

                        if ($email2 !== '') {

                            $check2 =
                                $conn->prepare("
                                    SELECT id
                                    FROM users
                                    WHERE email = ?
                                       OR email2 = ?
                                    LIMIT 1
                                ");

                            if (!$check2) {

                                $error =
                                    'Unable to check second email: ' .
                                    $conn->error;

                            } else {

                                $check2->bind_param(
                                    'ss',
                                    $email2,
                                    $email2
                                );

                                $check2->execute();

                                $existing2 =
                                    $check2
                                    ->get_result()
                                    ->fetch_assoc();

                                $check2->close();


                                if ($existing2) {

                                    $error =
                                        'Email 2 is already registered.';
                                }
                            }
                        }


                        /* =================================
                           CREATE ACCOUNT
                        ================================= */

                        if ($error === '') {

                            $hashedPassword =
                                password_hash(
                                    $plainPassword,
                                    PASSWORD_BCRYPT,
                                    [
                                        'cost' => 12
                                    ]
                                );


                            if ($hashedPassword === false) {

                                $error =
                                    'Unable to secure the password.';

                            } else {

                                $stmt =
                                    $conn->prepare("
                                        INSERT INTO users
                                        (
                                            name,
                                            email,
                                            email2,
                                            phone,
                                            phone2,
                                            password,
                                            role
                                        )
                                        VALUES
                                        (?, ?, ?, ?, ?, ?, ?)
                                    ");


                                if (!$stmt) {

                                    $error =
                                        'Unable to prepare account creation: ' .
                                        $conn->error;

                                } else {

                                    $stmt->bind_param(
                                        'sssssss',
                                        $name,
                                        $email,
                                        $email2,
                                        $phone,
                                        $phone2,
                                        $hashedPassword,
                                        $role
                                    );


                                    if (!$stmt->execute()) {

                                        $error =
                                            'Unable to create account: ' .
                                            $stmt->error;

                                        $stmt->close();

                                    } else {

                                        $newUserId =
                                            (int)$stmt->insert_id;

                                        $stmt->close();


                                        /* =================================
                                           SUCCESS
                                        ================================= */

                                        $roleLabel =
                                            $role === 'inspector'
                                            ? 'Inspector'
                                            : 'Technician';


                                        $_SESSION['success'] =
                                            $roleLabel .
                                            ' account created successfully. ' .
                                            'User ID: ' .
                                            $newUserId .
                                            '.';


                                        header(
                                            'Location: users.php'
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


/* =========================================================
   HEADER / SIDEBAR
========================================================= */

include('../includes/header.php');
include('../includes/sidebar-admin.php');

?>


<style>

/* =========================================================
   CREATE USER PAGE
========================================================= */

.create-user-page {
    padding-bottom: 40px;
}

.create-user-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 22px;
}

.create-user-title {
    margin: 0;
    font-size: 28px;
    font-weight: 700;
    color: #172033;
}

.create-user-subtitle {
    margin-top: 5px;
    color: #6b7280;
    font-size: 14px;
}


/* =========================================================
   FORM CARD
========================================================= */

.create-user-card {
    max-width: 680px;
    background: #ffffff;
    border: 1px solid #e4e8ee;
    border-radius: 12px;
    box-shadow:
        0 3px 12px rgba(0, 0, 0, 0.05);
    overflow: hidden;
}

.create-user-card-header {
    padding: 18px 22px;
    border-bottom: 1px solid #e7ebf0;
    background: #fafbfc;
}

.create-user-card-header h5 {
    margin: 0;
    font-size: 17px;
    font-weight: 700;
    color: #172033;
}

.create-user-card-body {
    padding: 24px;
}


/* =========================================================
   INFO
========================================================= */

.create-user-info {
    background: #eef7ff;
    border: 1px solid #cfe5ff;
    border-radius: 9px;
    padding: 14px 16px;
    margin-bottom: 22px;
    color: #44546a;
    font-size: 13px;
    line-height: 1.65;
}

.create-user-info strong {
    color: #174a7e;
}


/* =========================================================
   LABELS
========================================================= */

.create-user-card .form-label {
    font-weight: 600;
    color: #374151;
    font-size: 13px;
    margin-bottom: 6px;
}


/* =========================================================
   INPUTS
========================================================= */

.create-user-card .form-control,
.create-user-card .form-select {
    border-radius: 7px;
    border-color: #d9dee6;
    min-height: 42px;
    font-size: 14px;
}

.create-user-card .form-control:focus,
.create-user-card .form-select:focus {
    border-color: #86b7fe;
    box-shadow:
        0 0 0 3px rgba(13, 110, 253, 0.10);
}


/* =========================================================
   ROLE SELECTION
========================================================= */

.role-options {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 18px;
}

.role-option {
    position: relative;
}

.role-option input {
    position: absolute;
    opacity: 0;
}

.role-option label {
    display: block;
    border: 1px solid #dfe4ea;
    border-radius: 9px;
    padding: 14px;
    cursor: pointer;
    background: #ffffff;
    transition: all .15s ease;
}

.role-option label:hover {
    border-color: #aeb8c5;
}

.role-option input:checked + label {
    border-color: #1769d1;
    background: #f1f7ff;
}

.role-option-title {
    font-weight: 700;
    font-size: 14px;
    color: #172033;
}

.role-option-description {
    display: block;
    margin-top: 3px;
    color: #6b7280;
    font-size: 12px;
}


/* =========================================================
   PASSWORD NOTE
========================================================= */

.password-note {
    font-size: 12px;
    color: #6b7280;
    margin-top: 5px;
}


/* =========================================================
   ACTIONS
========================================================= */

.create-user-actions {
    display: flex;
    gap: 10px;
    margin-top: 22px;
}

.create-user-actions .btn {
    min-height: 42px;
    border-radius: 7px;
    font-weight: 600;
}


/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 650px) {

    .create-user-header {
        display: block;
    }

    .role-options {
        grid-template-columns: 1fr;
    }

    .create-user-card-body {
        padding: 18px;
    }

    .create-user-actions {
        flex-direction: column;
    }

    .create-user-actions .btn {
        width: 100%;
    }
}

</style>


<div class="content create-user-page">


    <!-- =====================================================
         HEADER
    ====================================================== -->

    <div class="create-user-header">

        <div>

            <h3 class="create-user-title">
                Create User Account
            </h3>

            <div class="create-user-subtitle">
                Create a Technician or Inspector account
            </div>

        </div>

    </div>


    <!-- =====================================================
         ERROR
    ====================================================== -->

    <?php if ($error !== ''): ?>

        <div
            class="alert alert-danger alert-dismissible fade show"
            role="alert"
        >

            <?= htmlspecialchars($error) ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>


    <!-- =====================================================
         FORM CARD
    ====================================================== -->

    <div class="create-user-card">


        <div class="create-user-card-header">

            <h5>
                New Account Details
            </h5>

        </div>


        <div class="create-user-card-body">


            <!-- INFO -->

            <div class="create-user-info">

                <strong>Admin Access:</strong>

                You can create either a
                <strong>Technician</strong> or
                <strong>Inspector</strong> account.

                <br>

                Owner account creation remains in the existing
                Owner registration workflow.

            </div>


            <form method="POST">

                <?= csrfField() ?>


                <!-- =========================================
                     NAME
                ========================================== -->

                <div class="mb-3">

                    <label class="form-label">
                        Full Name
                        <span class="text-danger">*</span>
                    </label>

                    <input
                        type="text"
                        name="name"
                        class="form-control"
                        placeholder="Enter full name"
                        maxlength="100"
                        required
                        autocomplete="name"
                        value="<?= htmlspecialchars(
                            $_POST['name'] ?? ''
                        ) ?>"
                    >

                </div>


                <!-- =========================================
                     EMAIL ROW
                ========================================== -->

                <div class="row">

                    <div class="col-md-6 mb-3">

                        <label class="form-label">

                            Email 1
                            <span class="text-danger">*</span>

                        </label>

                        <input
                            type="email"
                            name="email"
                            class="form-control"
                            placeholder="Primary email"
                            maxlength="100"
                            required
                            autocomplete="email"
                            value="<?= htmlspecialchars(
                                $_POST['email'] ?? ''
                            ) ?>"
                        >

                    </div>


                    <div class="col-md-6 mb-3">

                        <label class="form-label">
                            Email 2
                        </label>

                        <input
                            type="email"
                            name="email2"
                            class="form-control"
                            placeholder="Secondary email"
                            maxlength="100"
                            autocomplete="email"
                            value="<?= htmlspecialchars(
                                $_POST['email2'] ?? ''
                            ) ?>"
                        >

                    </div>

                </div>


                <!-- =========================================
                     PHONE ROW
                ========================================== -->

                <div class="row">

                    <div class="col-md-6 mb-3">

                        <label class="form-label">
                            Phone 1
                        </label>

                        <input
                            type="text"
                            name="phone"
                            class="form-control"
                            placeholder="10 digit phone"
                            maxlength="10"
                            pattern="[0-9]{10}"
                            inputmode="numeric"
                            autocomplete="tel"
                            value="<?= htmlspecialchars(
                                $_POST['phone'] ?? ''
                            ) ?>"
                        >

                    </div>


                    <div class="col-md-6 mb-3">

                        <label class="form-label">
                            Phone 2
                        </label>

                        <input
                            type="text"
                            name="phone2"
                            class="form-control"
                            placeholder="10 digit phone"
                            maxlength="10"
                            pattern="[0-9]{10}"
                            inputmode="numeric"
                            value="<?= htmlspecialchars(
                                $_POST['phone2'] ?? ''
                            ) ?>"
                        >

                    </div>

                </div>


                <!-- =========================================
                     PASSWORD
                ========================================== -->

                <div class="mb-3">

                    <label class="form-label">

                        Initial Password
                        <span class="text-danger">*</span>

                    </label>

                    <input
                        type="password"
                        name="password"
                        class="form-control"
                        placeholder="Enter initial password"
                        minlength="8"
                        required
                        autocomplete="new-password"
                    >

                    <div class="password-note">

                        Minimum requirements are enforced by the
                        existing password security configuration.

                    </div>

                </div>


                <!-- =========================================
                     ROLE
                ========================================== -->

                <label class="form-label">

                    Account Role
                    <span class="text-danger">*</span>

                </label>


                <div class="role-options">


                    <!-- TECHNICIAN -->

                    <div class="role-option">

                        <input
                            type="radio"
                            name="role"
                            id="roleTechnician"
                            value="technician"
                            <?= (
                                ($_POST['role'] ?? '')
                                === 'technician'
                            )
                                ? 'checked'
                                : ''
                            ?>
                            required
                        >

                        <label for="roleTechnician">

                            <span class="role-option-title">
                                Technician
                            </span>

                            <span class="role-option-description">
                                Maintenance and technical work
                            </span>

                        </label>

                    </div>


                    <!-- INSPECTOR -->

                    <div class="role-option">

                        <input
                            type="radio"
                            name="role"
                            id="roleInspector"
                            value="inspector"
                            <?= (
                                ($_POST['role'] ?? '')
                                === 'inspector'
                            )
                                ? 'checked'
                                : ''
                            ?>
                        >

                        <label for="roleInspector">

                            <span class="role-option-title">
                                Inspector
                            </span>

                            <span class="role-option-description">
                                Inspection and checklist work
                            </span>

                        </label>

                    </div>


                </div>


                <!-- =========================================
                     ACTIONS
                ========================================== -->

                <div class="create-user-actions">

                    <a
                        href="users.php"
                        class="btn btn-light border"
                    >
                        Cancel
                    </a>


                    <button
                        type="submit"
                        name="save"
                        value="1"
                        class="btn btn-primary flex-grow-1"
                    >

                        Create Account

                    </button>

                </div>


            </form>


        </div>

    </div>


</div>


<?php include('../includes/footer.php'); ?>