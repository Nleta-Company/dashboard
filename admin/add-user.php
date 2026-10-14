<?php
/* =========================================================
   NLETA ADMIN — CREATE TECHNICIAN / INSPECTOR ACCOUNT
   ---------------------------------------------------------
   Admin can create:
   - Technician
   - Inspector

   Owner creation is NOT handled here.
   Existing Owner signup/creation remains unchanged.

   New account:
   - Gets database User ID
   - Logs in with registered Email + Password
   - Password is stored only as bcrypt hash
   - Credentials are sent to registered email(s)
========================================================= */

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

include('../config/db.php');
include('../config/auth.php');
require_once '../config/security.php';

require_role('admin');

$error = '';

$allowed_roles = [
    'technician',
    'inspector'
];

if (isset($_POST['save'])) {

    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {

        $error = "Invalid request. Please try again.";

    } else {

        $name = trim($_POST['name'] ?? '');

        $email = filter_var(
            trim($_POST['email'] ?? ''),
            FILTER_VALIDATE_EMAIL
        );

        $email2 = trim($_POST['email2'] ?? '');

        $role = trim($_POST['role'] ?? '');

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

        $plainPassword = (string)(
            $_POST['password'] ?? ''
        );


        /* =================================================
           VALIDATION
        ================================================== */

        if (!in_array($role, $allowed_roles, true)) {

            $error = "Please select Technician or Inspector.";

        } elseif (
            $name === '' ||
            !$email ||
            $plainPassword === ''
        ) {

            $error =
                "Name, valid email, password and role are required.";

        } elseif (
            $phone !== '' &&
            strlen($phone) !== 10
        ) {

            $error =
                "Phone number must be exactly 10 digits.";

        } elseif (
            $phone2 !== '' &&
            strlen($phone2) !== 10
        ) {

            $error =
                "Second phone number must be exactly 10 digits.";

        } elseif (
            $email2 !== '' &&
            !filter_var($email2, FILTER_VALIDATE_EMAIL)
        ) {

            $error =
                "Invalid second email address.";

        } elseif (
            $email2 !== '' &&
            strcasecmp($email, $email2) === 0
        ) {

            $error =
                "Email 2 must be different from Email 1.";

        } else {

            /* =============================================
               PASSWORD STRENGTH
            ============================================== */

            $pwCheck =
                validatePasswordStrength($plainPassword);

            if (!$pwCheck['valid']) {

                $error = $pwCheck['error'];

            } else {

                /* =========================================
                   CHECK PRIMARY EMAIL
                ========================================== */

                $check = $conn->prepare("
                    SELECT id
                    FROM users
                    WHERE email = ?
                       OR email2 = ?
                    LIMIT 1
                ");

                if (!$check) {

                    $error =
                        "Unable to check existing email: " .
                        $conn->error;

                } else {

                    $check->bind_param(
                        "ss",
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
                            "This email is already registered.";

                    } else {

                        /* =================================
                           CHECK EMAIL 2
                        ================================= */

                        if ($email2 !== '') {

                            $check2 = $conn->prepare("
                                SELECT id
                                FROM users
                                WHERE email = ?
                                   OR email2 = ?
                                LIMIT 1
                            ");

                            if (!$check2) {

                                $error =
                                    "Unable to check second email: " .
                                    $conn->error;

                            } else {

                                $check2->bind_param(
                                    "ss",
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
                                        "Email 2 is already registered.";
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
                                    ['cost' => 12]
                                );

                            if ($hashedPassword === false) {

                                $error =
                                    "Unable to secure the password.";

                            } else {

                                $stmt = $conn->prepare("
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
                                        "Unable to prepare account creation: " .
                                        $conn->error;

                                } else {

                                    $stmt->bind_param(
                                        "sssssss",
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
                                            "Unable to create account: " .
                                            $stmt->error;

                                    } else {

                                        $newUserId =
                                            (int)$stmt->insert_id;

                                        $stmt->close();

                                        /* =============================
                                           SEND CREDENTIALS BY EMAIL
                                        ============================== */

                                        require_once '../config/email.php';

                                        $roleLabel =
                                            $role === 'inspector'
                                            ? 'Inspector'
                                            : 'Technician';

                                        /*
                                         * Login is by Email + Password.
                                         * User ID is supplied for reference.
                                         */
                                        $loginUrl = 'login.php';

                                        $message =
                                            "Welcome to NLETA Maintenance System!\n\n" .
                                            "Your {$roleLabel} account has been created by Admin.\n\n" .
                                            "LOGIN CREDENTIALS\n" .
                                            "--------------------------\n" .
                                            "User ID: {$newUserId}\n" .
                                            "Name: {$name}\n" .
                                            "Role: {$roleLabel}\n" .
                                            "Email: {$email}\n" .
                                            "Password: {$plainPassword}\n\n" .
                                            "Login with your Email and Password.\n" .
                                            "Login: {$loginUrl}\n\n" .
                                            "Please keep your password secure.";

                                        $primaryMailSent =
                                            sendEmail(
                                                $email,
                                                $name,
                                                'Welcome to NLETA - Your Login Credentials',
                                                $message
                                            );

                                        if ($email2 !== '') {

                                            sendEmail(
                                                $email2,
                                                $name,
                                                'Welcome to NLETA - Your Login Credentials',
                                                $message
                                            );
                                        }

                                        $_SESSION['success'] =
                                            "{$roleLabel} account created successfully. " .
                                            "User ID: {$newUserId}. " .
                                            "Login credentials were sent to the registered email.";

                                        header("Location: users.php");
                                        exit;
                                    }

                                    if ($stmt instanceof mysqli_stmt) {
                                        $stmt->close();
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

include('../includes/header.php');
include('../includes/sidebar-admin.php');
?>

<div class="content">

    <h3 class="mb-4">
        Create Technician / Inspector Account
    </h3>

    <?php if ($error): ?>

        <div class="alert alert-danger alert-dismissible fade show">

            <?= htmlspecialchars($error) ?>

            <button
                type="button"
                class="btn-close"
                onclick="this.closest('.alert').remove()">
            </button>

        </div>

    <?php endif; ?>


    <div
        class="card p-4 shadow"
        style="max-width:560px"
    >

        <div class="alert alert-info">

            <strong>Admin Account Creation</strong>

            <br>

            Admin can create a <strong>Technician</strong> or
            <strong>Inspector</strong> account here.

            <br><br>

            <strong>Login:</strong>
            The new user will log in using their
            <strong>Email + Password</strong>.

            <br>

            The generated User ID is also included in the
            welcome email for reference.

            <br><br>

            <strong>Owner:</strong>
            Owner account creation remains on the existing
            Owner signup/creation workflow.

        </div>


        <form method="POST">

            <?= csrfField() ?>


            <label class="form-label">
                Name <span class="text-danger">*</span>
            </label>

            <input
                name="name"
                class="form-control mb-3"
                placeholder="Full name"
                required
                maxlength="100"
                autocomplete="name"
            >


            <label class="form-label">
                Email 1 <span class="text-danger">*</span>
            </label>

            <input
                name="email"
                type="email"
                class="form-control mb-3"
                placeholder="Gmail / Primary Email"
                required
                maxlength="100"
                autocomplete="email"
            >


            <label class="form-label">
                Email 2 (optional)
            </label>

            <input
                name="email2"
                type="email"
                class="form-control mb-3"
                placeholder="Secondary Email"
                maxlength="100"
                autocomplete="email"
            >


            <label class="form-label">
                Phone 1 (10 digits)
            </label>

            <input
                name="phone"
                type="text"
                class="form-control mb-3"
                placeholder="Primary Phone Number"
                maxlength="10"
                pattern="[0-9]{10}"
                inputmode="numeric"
                autocomplete="tel"
            >


            <label class="form-label">
                Phone 2 (optional)
            </label>

            <input
                name="phone2"
                type="text"
                class="form-control mb-3"
                placeholder="Secondary Phone Number"
                maxlength="10"
                pattern="[0-9]{10}"
                inputmode="numeric"
            >


            <label class="form-label">
                Initial Password <span class="text-danger">*</span>
            </label>

            <input
                name="password"
                type="password"
                class="form-control mb-2"
                placeholder="Minimum 8 chars, upper + lower + number"
                required
                minlength="8"
                autocomplete="new-password"
            >

            <div class="form-text mb-3">
                This password is used for the first login and is sent
                to the registered email.
            </div>


            <label class="form-label">
                Role <span class="text-danger">*</span>
            </label>

            <select
                name="role"
                class="form-select mb-3"
                required
            >

                <option value="">
                    Select Role
                </option>

                <option value="technician">
                    Technician
                </option>

                <option value="inspector">
                    Inspector
                </option>

            </select>


            <button
                type="submit"
                name="save"
                value="1"
                class="btn btn-primary w-100"
            >
                Create Account
            </button>

        </form>

    </div>

</div>

<?php include('../includes/footer.php'); ?>
