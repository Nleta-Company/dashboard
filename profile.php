<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/db.php';

$uid = (int)($_SESSION['uid'] ?? 0);

if ($uid <= 0) {
    header("Location: login.php");
    exit;
}

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
        role,
        created_at
    FROM users
    WHERE id = ?
    LIMIT 1
");

if (!$stmt) {
    die("Unable to load profile.");
}

$stmt->bind_param("i", $uid);
$stmt->execute();

$user = $stmt->get_result()->fetch_assoc();

$stmt->close();

if (!$user) {
    $_SESSION = [];
    session_destroy();

    header("Location: login.php");
    exit;
}


/* =========================================================
   ROLE
========================================================= */

switch (strtolower($user['role'] ?? '')) {

    case 'admin':
        $roleLabel = 'Administrator';
        $roleIcon = 'bi-shield-check';
        break;

    case 'owner':
        $roleLabel = 'Owner';
        $roleIcon = 'bi-person-badge';
        break;

    case 'technician':
        $roleLabel = 'Technician';
        $roleIcon = 'bi-tools';
        break;

    default:
        $roleLabel = ucfirst($user['role'] ?? 'User');
        $roleIcon = 'bi-person';
        break;
}


/* =========================================================
   DATE
========================================================= */

$createdDate = '';

if (!empty($user['created_at'])) {

    $timestamp = strtotime($user['created_at']);

    if ($timestamp !== false) {
        $createdDate = date('d M Y', $timestamp);
    } else {
        $createdDate = $user['created_at'];
    }
}


/* =========================================================
   SAFE DISPLAY
========================================================= */

$name = htmlspecialchars(
    $user['name'] ?? '',
    ENT_QUOTES,
    'UTF-8'
);

$email = htmlspecialchars(
    $user['email'] ?? '',
    ENT_QUOTES,
    'UTF-8'
);

$email2 = htmlspecialchars(
    $user['email2'] ?? '',
    ENT_QUOTES,
    'UTF-8'
);

$phone = htmlspecialchars(
    $user['phone'] ?? '',
    ENT_QUOTES,
    'UTF-8'
);

$phone2 = htmlspecialchars(
    $user['phone2'] ?? '',
    ENT_QUOTES,
    'UTF-8'
);

$organizationName = htmlspecialchars(
    $user['organization_name'] ?? '',
    ENT_QUOTES,
    'UTF-8'
);

$organizationAddress = htmlspecialchars(
    $user['organization_address'] ?? '',
    ENT_QUOTES,
    'UTF-8'
);

$role = htmlspecialchars(
    $roleLabel,
    ENT_QUOTES,
    'UTF-8'
);

$roleIconSafe = htmlspecialchars(
    $roleIcon,
    ENT_QUOTES,
    'UTF-8'
);

$accountId = (int)$user['id'];


/* =========================================================
   DASHBOARD
========================================================= */

$currentRole = strtolower(
    trim(
        $_SESSION['role'] ?? ''
    )
);

if ($currentRole === 'admin') {

    $dashboardUrl = 'admin/dashboard.php';

} elseif ($currentRole === 'owner') {

    $dashboardUrl = 'owner/dashboard.php';

} elseif ($currentRole === 'technician') {

    $dashboardUrl = 'maintenance/dashboard.php';

} else {

    $dashboardUrl = 'login.php';
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
    My Profile - NLETA
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

/* =========================================================
   GLOBAL
========================================================= */

* {
    box-sizing: border-box;
}

html,
body {
    margin: 0;
    padding: 0;
    width: 100%;
    min-height: 100%;
}

body {
    background: #f4f6f9;
    font-family: Arial, sans-serif;
    color: #1f2937;
    overflow-x: hidden;
    overflow-y: auto;
}


/* =========================================================
   HEADER
========================================================= */

.profile-header {

    height: 64px;

    background: #202428;

    color: #fff;

    display: flex;

    align-items: center;

    justify-content: center;

    position: relative;
}

.profile-header-title {

    font-size: 22px;

    font-weight: 600;
}

.profile-header-left {

    position: absolute;

    left: 24px;

    display: flex;

    align-items: center;
}

.profile-header-left i {

    font-size: 23px;
}

.profile-header-right {

    position: absolute;

    right: 24px;
}

.back-btn {

    text-decoration: none;

    color: #fff;

    border:
        1px solid
        rgba(255,255,255,.4);

    padding:
        7px 13px;

    border-radius: 6px;

    font-size: 14px;

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

.profile-container {

    width: 100%;

    max-width: 1050px;

    min-height: calc(100vh - 64px);
    height: auto;

    margin: 0 auto;

    padding: 18px 20px;

    display: flex;

    justify-content: center;

    align-items: flex-start;
}


/* =========================================================
   PROFILE CARD
========================================================= */

.profile-card {

    width: 100%;

    min-height: 600px;
    height: auto;

    background: #fff;

    border-radius: 14px;

    box-shadow:
        0 4px 18px
        rgba(0,0,0,.08);

    overflow: hidden;
}


/* =========================================================
   PROFILE TOP
========================================================= */

.profile-top {

    height: 130px;

    background:
        linear-gradient(
            135deg,
            #0d1b3e,
            #273b6b
        );

    color: #fff;

    padding:
        18px 32px;

    display: flex;

    align-items: center;

    gap: 20px;
}


/* =========================================================
   AVATAR
========================================================= */

.profile-avatar {

    width: 82px;

    height: 82px;

    border-radius: 50%;

    background: #fff;

    color: #0d1b3e;

    display: flex;

    align-items: center;

    justify-content: center;

    font-size: 37px;

    flex-shrink: 0;
}


/* =========================================================
   NAME
========================================================= */

.profile-name {

    font-size: 28px;

    font-weight: 600;

    margin-bottom: 5px;

    line-height: 1.1;
}


/* =========================================================
   ROLE
========================================================= */

.profile-role {

    display: inline-flex;

    align-items: center;

    gap: 6px;

    background:
        rgba(255,255,255,.15);

    padding:
        6px 12px;

    border-radius: 18px;

    font-size: 13px;
}


/* =========================================================
   BODY
========================================================= */

.profile-body {

    padding:
        18px 28px 20px;
}


/* =========================================================
   SECTION TITLE
========================================================= */

.section-title {

    font-size: 18px;

    font-weight: 600;

    margin-bottom: 12px;

    line-height: 1.2;
}


/* =========================================================
   GRID
========================================================= */

.profile-grid {

    display: grid;

    grid-template-columns:
        repeat(
            2,
            minmax(0, 1fr)
        );

    gap: 10px 14px;
}


/* =========================================================
   INFORMATION BOX
========================================================= */

.profile-item {

    min-height: 85px;

    border:
        1px solid
        #e1e5ea;

    border-radius: 9px;

    padding:
        10px 15px;

    background: #fafafa;

    display: flex;

    flex-direction: column;

    justify-content: center;
}


/* =========================================================
   LABEL
========================================================= */

.profile-label {

    font-size: 12px;

    color: #52657a;

    margin-bottom: 4px;

    font-weight: 600;

    line-height: 1.2;
}


/* =========================================================
   VALUE
========================================================= */

.profile-value {

    font-size: 15px;

    color: #111827;

    word-break: break-word;

    overflow-wrap: anywhere;

    line-height: 1.25;
}

.profile-value i {

    margin-right: 6px;

    font-size: 14px;
}


/* =========================================================
   EMPTY
========================================================= */

.empty-value {

    color: #9ca3af;
}


/* =========================================================
   ACTION BUTTONS
========================================================= */

.profile-actions {

    margin-top: 12px;

    padding-top: 12px;

    border-top:
        1px solid
        #e5e7eb;

    display: flex;

    justify-content: center;

    align-items: center;

    gap: 10px;
}


.edit-profile-btn,
.change-password-btn {

    display: inline-flex;

    align-items: center;

    justify-content: center;

    gap: 7px;

    padding:
        8px 20px;

    border-radius: 6px;

    font-size: 14px;

    font-weight: 600;

    text-decoration: none;

    transition: .2s;
}


/* =========================================================
   EDIT PROFILE
========================================================= */

.edit-profile-btn {

    background: #0d6efd;

    color: #fff;
}

.edit-profile-btn:hover {

    background: #084298;

    color: #fff;
}


/* =========================================================
   CHANGE PASSWORD
========================================================= */

.change-password-btn {

    background: #ea232a;

    color: #fff;
}

.change-password-btn:hover {

    background: #333;

    color: #fff;
}


/* =========================================================
   TABLET
========================================================= */

@media (max-width: 991.98px) {

    body {
        overflow-x: hidden;
        overflow-y: auto;
    }

    .profile-header {
        min-height: 64px;
        height: auto;
        padding: 12px 16px;
    }

    .profile-header-title {
        font-size: 20px;
        padding: 0 150px;
        text-align: center;
    }

    .profile-header-left {
        left: 18px;
    }

    .profile-header-right {
        right: 18px;
    }

    .profile-container {
        width: 100%;
        min-height: calc(100vh - 64px);
        height: auto;
        padding: 16px;
    }

    .profile-card {
        min-height: 0;
        height: auto;
    }

    .profile-top {
        min-height: 125px;
        height: auto;
        padding: 18px 24px;
    }

    .profile-body {
        padding: 18px 20px 22px;
    }

    .profile-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px 12px;
    }

    .profile-item {
        min-width: 0;
        height: auto;
        min-height: 85px;
    }

    .profile-value {
        overflow-wrap: anywhere;
        word-break: break-word;
    }

    .profile-actions {
        flex-wrap: wrap;
    }
}


/* =========================================================
   MOBILE
========================================================= */

@media (max-width: 700px) {

    html,
    body {
        width: 100%;
        min-height: 100%;
    }

    body {
        overflow-x: hidden;
        overflow-y: auto;
    }

    .profile-header {
        min-height: 60px;
        height: auto;
        padding: 10px 12px;
    }

    .profile-header-title {
        font-size: 18px;
        line-height: 1.2;
        padding: 0 105px;
        white-space: nowrap;
    }

    .profile-header-left {
        left: 12px;
    }

    .profile-header-left i {
        font-size: 21px;
    }

    .profile-header-right {
        right: 12px;
    }

    .back-btn {
        padding: 6px 9px;
        font-size: 12px;
        white-space: nowrap;
    }

    .back-btn i {
        margin-right: 2px;
    }

    .profile-container {
        width: 100%;
        min-height: calc(100vh - 60px);
        height: auto;
        padding: 12px 10px 24px;
        display: block;
    }

    .profile-card {
        width: 100%;
        min-height: 0;
        height: auto;
        border-radius: 12px;
    }

    .profile-top {
        min-height: 0;
        height: auto;
        padding: 18px 14px;
        flex-direction: column;
        text-align: center;
        gap: 9px;
    }

    .profile-avatar {
        width: 68px;
        height: 68px;
        font-size: 30px;
    }

    .profile-name {
        max-width: 100%;
        font-size: 22px;
        line-height: 1.2;
        overflow-wrap: anywhere;
    }

    .profile-role {
        font-size: 12px;
        padding: 5px 10px;
    }

    .profile-body {
        padding: 16px 12px 20px;
    }

    .section-title {
        font-size: 17px;
        margin-bottom: 10px;
    }

    .profile-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
    }

    .profile-item {
        width: 100%;
        min-width: 0;
        min-height: 82px;
        height: auto;
        padding: 9px 10px;
        border-radius: 8px;
    }

    .profile-label {
        font-size: 11px;
        margin-bottom: 4px;
    }

    .profile-value {
        min-width: 0;
        font-size: 13px;
        line-height: 1.3;
        overflow-wrap: anywhere;
        word-break: break-word;
    }

    .profile-value i {
        margin-right: 4px;
        font-size: 12px;
    }

    .profile-actions {
        margin-top: 12px;
        padding-top: 12px;
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
    }

    .edit-profile-btn,
    .change-password-btn {
        width: 100%;
        min-width: 0;
        padding: 8px 10px;
        font-size: 12px;
        text-align: center;
        white-space: normal;
    }
}


/* =========================================================
   VERY SMALL MOBILE
========================================================= */

@media (max-width: 430px) {

    .profile-header {
        padding: 9px 8px;
    }

    .profile-header-title {
        font-size: 16px;
        padding: 0 92px;
    }

    .profile-header-left {
        left: 8px;
    }

    .profile-header-right {
        right: 8px;
    }

    .back-btn {
        font-size: 11px;
        padding: 5px 7px;
    }

    .profile-container {
        padding: 10px 7px 20px;
    }

    .profile-body {
        padding-left: 9px;
        padding-right: 9px;
    }

    .profile-grid {
        gap: 7px;
    }

    .profile-item {
        min-height: 78px;
        padding: 8px;
    }

    .profile-label {
        font-size: 10px;
    }

    .profile-value {
        font-size: 12px;
    }

    .profile-actions {
        gap: 7px;
    }

    .edit-profile-btn,
    .change-password-btn {
        padding: 8px 6px;
        font-size: 11px;
    }
}


/* =========================================================
   EXTRA SMALL MOBILE
   Keep content usable if viewport becomes extremely narrow.
========================================================= */

@media (max-width: 340px) {

    .profile-header-title {
        font-size: 15px;
        padding: 0 82px;
    }

    .back-btn {
        font-size: 10px;
    }

    .profile-grid {
        grid-template-columns: 1fr;
    }

    .profile-actions {
        grid-template-columns: 1fr;
    }
}

</style>

</head>


<body>


<!-- =====================================================
     HEADER
====================================================== -->

<div class="profile-header">


    <div class="profile-header-left">

        <i class="bi bi-person-circle"></i>

    </div>


    <div class="profile-header-title">

        My Profile

    </div>


    <div class="profile-header-right">

        <a
            href="<?= htmlspecialchars(
                $dashboardUrl,
                ENT_QUOTES,
                'UTF-8'
            ) ?>"
            class="back-btn"
        >

            <i class="bi bi-arrow-left"></i>

            Back to Dashboard

        </a>

    </div>

</div>


<!-- =====================================================
     MAIN
====================================================== -->

<div class="profile-container">


    <div class="profile-card">


        <!-- =================================================
             PROFILE TOP
        ================================================== -->

        <div class="profile-top">


            <div class="profile-avatar">

                <i class="bi bi-person-fill"></i>

            </div>


            <div>

                <div class="profile-name">

                    <?= $name ?>

                </div>


                <div class="profile-role">

                    <i
                        class="bi <?= $roleIconSafe ?>"
                    ></i>

                    <?= $role ?>

                </div>

            </div>

        </div>


        <!-- =================================================
             PROFILE BODY
        ================================================== -->

        <div class="profile-body">


            <div class="section-title">

                <i class="bi bi-person-vcard"></i>

                Profile Information

            </div>


            <div class="profile-grid">


                <!-- ACCOUNT ID -->

                <div class="profile-item">

                    <div class="profile-label">
                        Account ID
                    </div>

                    <div class="profile-value">

                        <i class="bi bi-hash"></i>

                        <?= $accountId ?>

                    </div>

                </div>


                <!-- ROLE -->

                <div class="profile-item">

                    <div class="profile-label">
                        Role
                    </div>

                    <div class="profile-value">

                        <i
                            class="bi <?= $roleIconSafe ?>"
                        ></i>

                        <?= $role ?>

                    </div>

                </div>


                <!-- FULL NAME -->

                <div class="profile-item">

                    <div class="profile-label">
                        Full Name
                    </div>

                    <div class="profile-value">

                        <i class="bi bi-person"></i>

                        <?= $name ?>

                    </div>

                </div>


                <!-- PRIMARY EMAIL -->

                <div class="profile-item">

                    <div class="profile-label">
                        Primary Email
                    </div>

                    <div class="profile-value">

                        <i class="bi bi-envelope"></i>

                        <?php if (!empty($email)): ?>

                            <?= $email ?>

                        <?php else: ?>

                            <span class="empty-value">
                                Not provided
                            </span>

                        <?php endif; ?>

                    </div>

                </div>


                <!-- SECONDARY EMAIL -->

                <div class="profile-item">

                    <div class="profile-label">
                        Secondary Email
                    </div>

                    <div class="profile-value">

                        <i class="bi bi-envelope-plus"></i>

                        <?php if (!empty($email2)): ?>

                            <?= $email2 ?>

                        <?php else: ?>

                            <span class="empty-value">
                                Not provided
                            </span>

                        <?php endif; ?>

                    </div>

                </div>


                <!-- PRIMARY PHONE -->

                <div class="profile-item">

                    <div class="profile-label">
                        Primary Phone
                    </div>

                    <div class="profile-value">

                        <i class="bi bi-telephone"></i>

                        <?php if (!empty($phone)): ?>

                            <?= $phone ?>

                        <?php else: ?>

                            <span class="empty-value">
                                Not provided
                            </span>

                        <?php endif; ?>

                    </div>

                </div>


                <!-- SECONDARY PHONE -->

                <div class="profile-item">

                    <div class="profile-label">
                        Secondary Phone
                    </div>

                    <div class="profile-value">

                        <i class="bi bi-telephone-plus"></i>

                        <?php if (!empty($phone2)): ?>

                            <?= $phone2 ?>

                        <?php else: ?>

                            <span class="empty-value">
                                Not provided
                            </span>

                        <?php endif; ?>

                    </div>

                </div>


                <!-- ORGANIZATION / SOCIETY NAME -->

                <div class="profile-item">

                    <div class="profile-label">
                        Organization / Association Name
                    </div>

                    <div class="profile-value">

                        <i class="bi bi-building"></i>

                        <?php if (!empty($organizationName)): ?>

                            <?= $organizationName ?>

                        <?php else: ?>

                            <span class="empty-value">
                                Not provided
                            </span>

                        <?php endif; ?>

                    </div>

                </div>


                <!-- ORGANIZATION / SOCIETY ADDRESS -->

                <div class="profile-item">

                    <div class="profile-label">
                        Organization / Association Address
                    </div>

                    <div class="profile-value">

                        <i class="bi bi-geo-alt"></i>

                        <?php if (!empty($organizationAddress)): ?>

                            <?= nl2br($organizationAddress) ?>

                        <?php else: ?>

                            <span class="empty-value">
                                Not provided
                            </span>

                        <?php endif; ?>

                    </div>

                </div>


                <!-- ACCOUNT CREATED -->

                <div class="profile-item">

                    <div class="profile-label">
                        Account Created
                    </div>

                    <div class="profile-value">

                        <i class="bi bi-calendar-check"></i>

                        <?php if (!empty($createdDate)): ?>

                            <?= htmlspecialchars(
                                $createdDate,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>

                        <?php else: ?>

                            <span class="empty-value">
                                Not available
                            </span>

                        <?php endif; ?>

                    </div>

                </div>


            </div>


            <!-- =================================================
                 ACTIONS
            ================================================== -->

            <div class="profile-actions">


                <a
                    href="edit-profile.php"
                    class="edit-profile-btn"
                >

                    <i class="bi bi-pencil-square"></i>

                    Edit Profile

                </a>


                <a
                    href="change-password.php"
                    class="change-password-btn"
                >

                    <i class="bi bi-key-fill"></i>

                    Change Password

                </a>


            </div>


        </div>

    </div>

</div>


</body>

</html>