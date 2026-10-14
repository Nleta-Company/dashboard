<?php

include('../config/db.php');
include('../config/auth.php');
require_once '../config/security.php';

require_role('admin');


/* =========================================================
   SESSION MESSAGES
========================================================= */

$error = '';
$success = '';

if (!empty($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}

if (!empty($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']);
}


/* =========================================================
   ASSIGN TECHNICIANS TO OWNER
========================================================= */

if (isset($_POST['assign_technicians'])) {

    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {

        $_SESSION['error'] =
            'Invalid request. Please try again.';

        header("Location: users.php");
        exit;
    }

    $ownerId = (int)($_POST['owner_id'] ?? 0);

    $selectedTechnicians =
        $_POST['technician_ids'] ?? [];

    if (!is_array($selectedTechnicians)) {
        $selectedTechnicians = [];
    }


    /* =====================================================
       CLEAN IDS
    ===================================================== */

    $selectedTechnicians =
        array_values(
            array_unique(
                array_filter(
                    array_map(
                        'intval',
                        $selectedTechnicians
                    ),
                    function ($id) {
                        return $id > 0;
                    }
                )
            )
        );


    /* =====================================================
       VERIFY OWNER
    ===================================================== */

    $ownerStmt = $conn->prepare("
        SELECT id, name
        FROM users
        WHERE id = ?
          AND role = 'owner'
        LIMIT 1
    ");

    if (!$ownerStmt) {

        $_SESSION['error'] =
            'Unable to process Owner selection.';

        header("Location: users.php");
        exit;
    }

    $ownerStmt->bind_param(
        "i",
        $ownerId
    );

    $ownerStmt->execute();

    $owner =
        $ownerStmt
        ->get_result()
        ->fetch_assoc();

    $ownerStmt->close();


    if (!$owner) {

        $_SESSION['error'] =
            'Invalid Owner selected.';

        header("Location: users.php");
        exit;
    }


    /* =====================================================
       VERIFY TECHNICIANS
    ===================================================== */

    $validTechnicians = [];

    if (!empty($selectedTechnicians)) {

        $placeholders =
            implode(
                ',',
                array_fill(
                    0,
                    count($selectedTechnicians),
                    '?'
                )
            );

        $types =
            str_repeat(
                'i',
                count($selectedTechnicians)
            );

        $sql = "
            SELECT id
            FROM users
            WHERE role = 'technician'
              AND account_status = 'active'
              AND id IN ($placeholders)
        ";

        $techStmt =
            $conn->prepare($sql);

        if (!$techStmt) {

            $_SESSION['error'] =
                'Unable to validate technicians.';

            header("Location: users.php");
            exit;
        }

        $techStmt->bind_param(
            $types,
            ...$selectedTechnicians
        );

        $techStmt->execute();

        $techResult =
            $techStmt->get_result();

        while (
            $techRow =
            $techResult->fetch_assoc()
        ) {

            $validTechnicians[] =
                (int)$techRow['id'];
        }

        $techStmt->close();
    }


    /* =====================================================
       SAVE ASSIGNMENTS
    ===================================================== */

    $conn->begin_transaction();

    try {

        /* REMOVE OLD ASSIGNMENTS */

        $deleteStmt =
            $conn->prepare("
                DELETE FROM owner_technician_assignments
                WHERE owner_id = ?
            ");

        if (!$deleteStmt) {
            throw new Exception(
                'Unable to prepare assignment removal.'
            );
        }

        $deleteStmt->bind_param(
            "i",
            $ownerId
        );

        if (!$deleteStmt->execute()) {
            throw new Exception(
                'Unable to remove previous assignments.'
            );
        }

        $deleteStmt->close();


        /* INSERT NEW ASSIGNMENTS */

        if (!empty($validTechnicians)) {

            $insertStmt =
                $conn->prepare("
                    INSERT INTO owner_technician_assignments
                    (
                        owner_id,
                        technician_id,
                        assigned_by,
                        assigned_at,
                        status
                    )
                    VALUES
                    (
                        ?,
                        ?,
                        ?,
                        CURRENT_TIMESTAMP,
                        'active'
                    )
                ");

            if (!$insertStmt) {
                throw new Exception(
                    'Unable to prepare technician assignment.'
                );
            }

            $adminId =
                (int)$_SESSION['uid'];

            foreach (
                $validTechnicians
                as $technicianId
            ) {

                $insertStmt->bind_param(
                    "iii",
                    $ownerId,
                    $technicianId,
                    $adminId
                );

                if (!$insertStmt->execute()) {
                    throw new Exception(
                        'Unable to save technician assignment.'
                    );
                }
            }

            $insertStmt->close();
        }


        /* =================================================
           LEGACY DEFAULT TECHNICIAN
        ================================================= */

        $legacyTechnicianId =
            !empty($validTechnicians)
            ? $validTechnicians[0]
            : null;

        $legacyStmt =
            $conn->prepare("
                UPDATE users
                SET assigned_technician_id = ?
                WHERE id = ?
                  AND role = 'owner'
            ");

        if (!$legacyStmt) {
            throw new Exception(
                'Unable to update technician assignment.'
            );
        }

        $legacyStmt->bind_param(
            "ii",
            $legacyTechnicianId,
            $ownerId
        );

        if (!$legacyStmt->execute()) {
            throw new Exception(
                'Unable to update technician assignment.'
            );
        }

        $legacyStmt->close();


        $conn->commit();


        $count =
            count($validTechnicians);


        if ($count === 0) {

            $_SESSION['success'] =
                'All technicians have been removed from this Owner.';

        } elseif ($count === 1) {

            $_SESSION['success'] =
                'Technician assigned successfully.';

        } else {

            $_SESSION['success'] =
                $count .
                ' technicians assigned successfully.';
        }

    } catch (Throwable $e) {

        $conn->rollback();

        $_SESSION['error'] =
            'Unable to save technician assignment. Please try again.';
    }


    header("Location: users.php");
    exit;
}


/* =========================================================
   FETCH USERS
========================================================= */

$users = $conn->query("
    SELECT
        id,
        name,
        email,
        email2,
        phone,
        phone2,
        role,
        account_status,
        workflow_stage,
        assigned_technician_id,
        created_at
    FROM users
    ORDER BY
        CASE role
            WHEN 'admin' THEN 1
            WHEN 'owner' THEN 2
            WHEN 'technician' THEN 3
            WHEN 'inspector' THEN 4
            ELSE 5
        END,
        name ASC
");


if (!$users) {

    die(
        "Unable to load users: " .
        htmlspecialchars($conn->error)
    );
}


/* =========================================================
   FETCH ACTIVE TECHNICIANS
========================================================= */

$technicians = [];

$techResult = $conn->query("
    SELECT
        id,
        name,
        email,
        phone,
        account_status
    FROM users
    WHERE role = 'technician'
      AND account_status = 'active'
    ORDER BY name ASC
");

if ($techResult) {

    while (
        $tech =
        $techResult->fetch_assoc()
    ) {

        $technicians[(int)$tech['id']] =
            $tech;
    }
}


/* =========================================================
   FETCH OWNER → TECHNICIAN ASSIGNMENTS
========================================================= */

$assignments = [];

$assignmentResult = $conn->query("
    SELECT
        ota.owner_id,
        ota.technician_id,
        u.name AS technician_name
    FROM owner_technician_assignments ota
    INNER JOIN users u
        ON u.id = ota.technician_id
    WHERE ota.status = 'active'
      AND u.role = 'technician'
    ORDER BY
        ota.owner_id ASC,
        u.name ASC
");

if ($assignmentResult) {

    while (
        $assignment =
        $assignmentResult->fetch_assoc()
    ) {

        $ownerId =
            (int)$assignment['owner_id'];

        if (
            !isset(
                $assignments[$ownerId]
            )
        ) {

            $assignments[$ownerId] = [];
        }

        $assignments[$ownerId][] = [

            'id' =>
                (int)$assignment['technician_id'],

            'name' =>
                $assignment['technician_name']
        ];
    }
}


/* =========================================================
   USER COUNTS
========================================================= */

$totalUsers = 0;
$totalOwners = 0;
$totalTechnicians = 0;
$totalInspectors = 0;
$totalAdmins = 0;

$summaryResult = $conn->query("
    SELECT
        role,
        COUNT(*) AS total
    FROM users
    GROUP BY role
");

if ($summaryResult) {

    while (
        $summary =
        $summaryResult->fetch_assoc()
    ) {

        $count =
            (int)$summary['total'];

        $totalUsers +=
            $count;

        switch ($summary['role']) {

            case 'owner':

                $totalOwners =
                    $count;

                break;

            case 'technician':

                $totalTechnicians =
                    $count;

                break;

            case 'inspector':

                $totalInspectors =
                    $count;

                break;

            case 'admin':

                $totalAdmins =
                    $count;

                break;
        }
    }
}


/* =========================================================
   PAGE
========================================================= */

include('../includes/header.php');
include('../includes/sidebar-admin.php');

?>

<style>

/* =========================================================
   USER MANAGEMENT PAGE
========================================================= */

.user-management-page {
    padding-bottom: 40px;
}


/* =========================================================
   PAGE HEADER
========================================================= */

.user-page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    margin-bottom: 22px;
}

.user-page-title {
    margin: 0;
    font-size: 28px;
    font-weight: 700;
    color: #172033;
}

.user-page-subtitle {
    margin-top: 5px;
    color: #6b7280;
    font-size: 14px;
}


/* =========================================================
   CREATE BUTTON
========================================================= */

.create-user-btn {
    white-space: nowrap;
    border-radius: 8px;
    padding: 10px 18px;
    font-weight: 600;
}


/* =========================================================
   INFORMATION PANEL
========================================================= */

.user-info-panel {
    background: #eef7ff;
    border: 1px solid #cfe5ff;
    border-radius: 10px;
    padding: 16px 20px;
    margin-bottom: 22px;
}

.user-info-panel-title {
    font-weight: 700;
    color: #174a7e;
    margin-bottom: 8px;
}

.user-info-panel-text {
    margin: 0;
    color: #44546a;
    font-size: 14px;
    line-height: 1.65;
}


/* =========================================================
   SUMMARY CARDS
========================================================= */

.user-summary-row {
    display: grid;
    grid-template-columns:
        repeat(5, minmax(0, 1fr));
    gap: 14px;
    margin-bottom: 24px;
}

.user-summary-card {
    background: #ffffff;
    border: 1px solid #e4e8ee;
    border-radius: 10px;
    min-height: 110px;
    padding: 18px;
    display: flex;
    align-items: center;
    gap: 14px;
    box-shadow:
        0 2px 8px rgba(0, 0, 0, 0.04);
}

.user-summary-icon {
    width: 46px;
    height: 46px;
    border-radius: 9px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: 19px;
    font-weight: 700;
}

.user-summary-number {
    font-size: 25px;
    line-height: 1;
    font-weight: 700;
    color: #172033;
    margin-bottom: 6px;
}

.user-summary-label {
    font-size: 13px;
    color: #6b7280;
    font-weight: 500;
}


/* SUMMARY ICON COLORS */

.summary-total .user-summary-icon {
    background: #eef2ff;
    color: #4338ca;
}

.summary-owner .user-summary-icon {
    background: #fff7df;
    color: #b77900;
}

.summary-technician .user-summary-icon {
    background: #eaf3ff;
    color: #1769d1;
}

.summary-inspector .user-summary-icon {
    background: #eaf9f0;
    color: #17834b;
}

.summary-admin .user-summary-icon {
    background: #ffeded;
    color: #d73535;
}


/* =========================================================
   USER TABLE CARD
========================================================= */

.users-table-card {
    background: #ffffff;
    border: 1px solid #e4e8ee;
    border-radius: 10px;
    box-shadow:
        0 2px 8px rgba(0, 0, 0, 0.04);
    overflow: hidden;
}

.users-table-header {
    padding: 17px 20px;
    border-bottom: 1px solid #e7ebf0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.users-table-title {
    margin: 0;
    font-size: 16px;
    font-weight: 700;
    color: #172033;
}

.users-table-count {
    font-size: 13px;
    color: #6b7280;
}


/* =========================================================
   TABLE
========================================================= */

.users-table {
    margin: 0;
}

.users-table thead th {
    background: #f7f9fc;
    border-bottom: 1px solid #e1e6ed;
    color: #4b5563;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .3px;
    padding: 13px 14px;
    white-space: nowrap;
}

.users-table tbody td {
    padding: 13px 14px;
    font-size: 13px;
    color: #374151;
    border-bottom: 1px solid #edf0f3;
    vertical-align: middle;
}

.users-table tbody tr:last-child td {
    border-bottom: none;
}

.users-table tbody tr:hover {
    background: #fafcff;
}


/* =========================================================
   ROLE BADGES
========================================================= */

.role-badge {
    display: inline-flex;
    align-items: center;
    padding: 5px 9px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 700;
}

.role-admin {
    background: #fdecec;
    color: #c62828;
}

.role-owner {
    background: #fff5d8;
    color: #956900;
}

.role-technician {
    background: #eaf3ff;
    color: #1769d1;
}

.role-inspector {
    background: #e9f8ef;
    color: #167344;
}


/* =========================================================
   STATUS BADGES
========================================================= */

.status-badge {
    display: inline-flex;
    padding: 5px 9px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 700;
}

.status-active {
    background: #e9f8ef;
    color: #167344;
}

.status-inactive {
    background: #f0f1f3;
    color: #616975;
}

.status-suspended {
    background: #fdecec;
    color: #c62828;
}


/* =========================================================
   WORKFLOW BADGE
========================================================= */

.workflow-badge {
    display: inline-block;
    background: #f6f7f9;
    border: 1px solid #e3e6eb;
    color: #5d6673;
    border-radius: 6px;
    padding: 5px 8px;
    font-size: 11px;
}


/* =========================================================
   TECHNICIAN ASSIGNMENT
========================================================= */

.assigned-tech {
    display: inline-block;
    background: #edf9f2;
    color: #187346;
    border: 1px solid #d5efdf;
    border-radius: 5px;
    padding: 4px 7px;
    margin: 2px 3px 2px 0;
    font-size: 11px;
    font-weight: 600;
}

.not-assigned {
    color: #9ca3af;
    font-size: 12px;
}


/* =========================================================
   ACTION BUTTONS
========================================================= */

.action-btn {
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    padding: 6px 10px;
}

.action-edit {
    margin-right: 4px;
}


/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 1200px) {

    .user-summary-row {
        grid-template-columns:
            repeat(3, minmax(0, 1fr));
    }
}

@media (max-width: 768px) {

    .user-page-header {
        flex-direction: column;
        align-items: flex-start;
    }

    .create-user-btn {
        width: 100%;
    }

    .user-summary-row {
        grid-template-columns:
            repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 520px) {

    .user-summary-row {
        grid-template-columns: 1fr;
    }

    .user-page-title {
        font-size: 23px;
    }
}

</style>


<div class="content user-management-page">


<!-- =======================================================
     PAGE HEADER
======================================================== -->

<div class="user-page-header">

    <div>

        <h3 class="user-page-title">
            User Management
        </h3>

        <div class="user-page-subtitle">
            Manage Admin, Owner, Technician and Inspector accounts
        </div>

    </div>


    <a
        href="create-user.php"
        class="btn btn-primary create-user-btn"
    >

        + Create Technician / Inspector

    </a>

</div>


<!-- =======================================================
     SUCCESS
======================================================== -->

<?php if ($success): ?>

<div
    class="alert alert-success alert-dismissible fade show"
    role="alert"
>

    <?= htmlspecialchars($success) ?>

    <button
        type="button"
        class="btn-close"
        data-bs-dismiss="alert"
    ></button>

</div>

<?php endif; ?>


<!-- =======================================================
     ERROR
======================================================== -->

<?php if ($error): ?>

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


<!-- =======================================================
     INFORMATION PANEL
======================================================== -->

<div class="user-info-panel">

    <div class="user-info-panel-title">
        Account Management
    </div>

    <p class="user-info-panel-text">

        <strong>Technician / Inspector:</strong>
        Admin can create these accounts using the button above.

        &nbsp;&nbsp;|&nbsp;&nbsp;

        <strong>Owner:</strong>
        Owner accounts continue through the existing Owner
        registration workflow.

        &nbsp;&nbsp;|&nbsp;&nbsp;

        <strong>Assignment:</strong>
        Admin can assign multiple active Technicians to an Owner.

    </p>

</div>


<!-- =======================================================
     SUMMARY CARDS
======================================================== -->

<div class="user-summary-row">


    <!-- TOTAL -->

    <div class="user-summary-card summary-total">

        <div class="user-summary-icon">
            U
        </div>

        <div>

            <div class="user-summary-number">
                <?= $totalUsers ?>
            </div>

            <div class="user-summary-label">
                Total Users
            </div>

        </div>

    </div>


    <!-- OWNER -->

    <div class="user-summary-card summary-owner">

        <div class="user-summary-icon">
            O
        </div>

        <div>

            <div class="user-summary-number">
                <?= $totalOwners ?>
            </div>

            <div class="user-summary-label">
                Owners
            </div>

        </div>

    </div>


    <!-- TECHNICIAN -->

    <div class="user-summary-card summary-technician">

        <div class="user-summary-icon">
            T
        </div>

        <div>

            <div class="user-summary-number">
                <?= $totalTechnicians ?>
            </div>

            <div class="user-summary-label">
                Technicians
            </div>

        </div>

    </div>


    <!-- INSPECTOR -->

    <div class="user-summary-card summary-inspector">

        <div class="user-summary-icon">
            I
        </div>

        <div>

            <div class="user-summary-number">
                <?= $totalInspectors ?>
            </div>

            <div class="user-summary-label">
                Inspectors
            </div>

        </div>

    </div>


    <!-- ADMIN -->

    <div class="user-summary-card summary-admin">

        <div class="user-summary-icon">
            A
        </div>

        <div>

            <div class="user-summary-number">
                <?= $totalAdmins ?>
            </div>

            <div class="user-summary-label">
                Admins
            </div>

        </div>

    </div>


</div>


<!-- =======================================================
     USERS TABLE
======================================================== -->

<div class="users-table-card">


    <div class="users-table-header">

        <h5 class="users-table-title">
            Registered Users
        </h5>

        <span class="users-table-count">
            <?= $totalUsers ?> total accounts
        </span>

    </div>


    <div class="table-responsive">

        <table
            class="table users-table align-middle"
        >

            <thead>

                <tr>

                    <th>ID</th>

                    <th>Name</th>

                    <th>Email</th>

                    <th>Phone</th>

                    <th>Role</th>

                    <th>Status</th>

                    <th>Workflow</th>

                    <th>Assigned Technician(s)</th>

                    <th>Created</th>

                    <th>Action</th>

                </tr>

            </thead>


            <tbody>


<?php if ($users->num_rows === 0): ?>

                <tr>

                    <td
                        colspan="10"
                        class="text-center py-5"
                    >

                        <div class="text-muted">
                            No users found.
                        </div>

                    </td>

                </tr>


<?php else: ?>


<?php while ($user = $users->fetch_assoc()): ?>


<?php

$userId =
    (int)$user['id'];

$userRole =
    $user['role'];

$userAssignments =
    $assignments[$userId] ?? [];


/* ROLE */

$roleClass = match ($userRole) {

    'admin' =>
        'role-admin',

    'owner' =>
        'role-owner',

    'technician' =>
        'role-technician',

    'inspector' =>
        'role-inspector',

    default =>
        ''
};


$roleLabel = match ($userRole) {

    'admin' =>
        'Admin',

    'owner' =>
        'Owner',

    'technician' =>
        'Technician',

    'inspector' =>
        'Inspector',

    default =>
        ucfirst($userRole)
};


/* STATUS */

$status =
    $user['account_status']
    ?? 'active';

$statusClass = match ($status) {

    'active' =>
        'status-active',

    'inactive' =>
        'status-inactive',

    'suspended' =>
        'status-suspended',

    default =>
        'status-inactive'
};


/* WORKFLOW */

$workflow =
    trim(
        (string)(
            $user['workflow_stage']
            ?? ''
        )
    );

$workflowLabel =
    $workflow !== ''
    ? ucwords(
        str_replace(
            '_',
            ' ',
            $workflow
        )
    )
    : '—';


/* CREATED */

$createdAt =
    $user['created_at']
    ?? '';

?>

                <tr>


                    <!-- ID -->

                    <td>

                        <strong>
                            #<?= $userId ?>
                        </strong>

                    </td>


                    <!-- NAME -->

                    <td>

                        <strong>
                            <?= htmlspecialchars(
                                $user['name']
                            ) ?>
                        </strong>

                    </td>


                    <!-- EMAIL -->

                    <td>

                        <?= htmlspecialchars(
                            $user['email']
                        ) ?>


<?php if (!empty($user['email2'])): ?>

                        <br>

                        <small class="text-muted">

                            <?= htmlspecialchars(
                                $user['email2']
                            ) ?>

                        </small>

<?php endif; ?>

                    </td>


                    <!-- PHONE -->

                    <td>

                        <?=
                            !empty($user['phone'])
                            ? htmlspecialchars(
                                $user['phone']
                            )
                            : '—'
                        ?>


<?php if (!empty($user['phone2'])): ?>

                        <br>

                        <small class="text-muted">

                            <?= htmlspecialchars(
                                $user['phone2']
                            ) ?>

                        </small>

<?php endif; ?>

                    </td>


                    <!-- ROLE -->

                    <td>

                        <span
                            class="role-badge <?= $roleClass ?>"
                        >

                            <?= htmlspecialchars(
                                $roleLabel
                            ) ?>

                        </span>

                    </td>


                    <!-- STATUS -->

                    <td>

                        <span
                            class="status-badge <?= $statusClass ?>"
                        >

                            <?= htmlspecialchars(
                                ucfirst($status)
                            ) ?>

                        </span>

                    </td>


                    <!-- WORKFLOW -->

                    <td>

                        <span class="workflow-badge">

                            <?= htmlspecialchars(
                                $workflowLabel
                            ) ?>

                        </span>

                    </td>


                    <!-- ASSIGNED TECHNICIANS -->

                    <td style="min-width:220px;">


<?php if ($userRole === 'owner'): ?>


<?php if (!empty($userAssignments)): ?>


<?php foreach (
    $userAssignments
    as $assignment
): ?>

                        <span class="assigned-tech">

                            <?= htmlspecialchars(
                                $assignment['name']
                            ) ?>

                        </span>

<?php endforeach; ?>


<?php else: ?>

                        <span class="not-assigned">
                            Not Assigned
                        </span>

<?php endif; ?>


<?php else: ?>

                        <span class="not-assigned">
                            —
                        </span>

<?php endif; ?>

                    </td>


                    <!-- CREATED -->

                    <td>

                        <?php

                        if ($createdAt !== '') {

                            echo htmlspecialchars(
                                date(
                                    'd-m-Y',
                                    strtotime($createdAt)
                                )
                            );

                        } else {

                            echo '—';
                        }

                        ?>

                    </td>


                    <!-- ACTION -->

                    <td style="min-width:190px;">


                        <a
                            href="edit-user.php?id=<?= $userId ?>"
                            class="btn btn-sm btn-primary action-btn action-edit"
                        >

                            Edit

                        </a>


<?php if ($userRole === 'owner'): ?>

                        <button
                            type="button"
                            class="btn btn-sm btn-success action-btn"
                            data-bs-toggle="modal"
                            data-bs-target="#assignModal<?= $userId ?>"
                        >

                            Assign

                        </button>

<?php endif; ?>


                    </td>

                </tr>


<?php endwhile; ?>


<?php endif; ?>


            </tbody>

        </table>

    </div>

</div>


<!-- =======================================================
     ASSIGN TECHNICIAN MODALS
======================================================== -->

<?php

$ownerModalResult =
    $conn->query("
        SELECT
            id,
            name
        FROM users
        WHERE role = 'owner'
        ORDER BY name ASC
    ");

?>


<?php if ($ownerModalResult): ?>


<?php while (
    $owner =
    $ownerModalResult->fetch_assoc()
): ?>


<?php

$ownerId =
    (int)$owner['id'];

$ownerAssignments =
    $assignments[$ownerId] ?? [];

$selectedIds = [];

foreach (
    $ownerAssignments
    as $assigned
) {

    $selectedIds[] =
        (int)$assigned['id'];
}

?>


<!-- =====================================================
     OWNER ASSIGNMENT MODAL
====================================================== -->

<div
    class="modal fade"
    id="assignModal<?= $ownerId ?>"
    tabindex="-1"
    aria-hidden="true"
>

    <div
        class="modal-dialog modal-dialog-centered"
    >

        <div class="modal-content">


            <div class="modal-header">

                <h5 class="modal-title">
                    Assign Technician
                </h5>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                ></button>

            </div>


            <form method="POST">

                <div class="modal-body">

                    <?= csrfField() ?>


                    <input
                        type="hidden"
                        name="owner_id"
                        value="<?= $ownerId ?>"
                    >


                    <!-- OWNER -->

                    <div class="mb-3">

                        <label
                            class="form-label fw-semibold"
                        >
                            Owner
                        </label>

                        <input
                            type="text"
                            class="form-control"
                            value="<?= htmlspecialchars(
                                $owner['name']
                            ) ?>"
                            readonly
                        >

                    </div>


                    <!-- TECHNICIANS -->

                    <label
                        class="form-label fw-semibold"
                    >

                        Select Technician(s)

                    </label>


<?php if (empty($technicians)): ?>

                    <div class="alert alert-warning">

                        No active technicians are registered.

                        <br><br>

                        Please create a Technician account first.

                    </div>


<?php else: ?>


                    <div
                        class="border rounded p-3"
                        style="
                            max-height:300px;
                            overflow-y:auto;
                        "
                    >


<?php foreach (
    $technicians
    as $technician
): ?>


                        <div
                            class="form-check mb-3"
                        >

                            <input
                                class="form-check-input"
                                type="checkbox"
                                name="technician_ids[]"
                                value="<?= (int)$technician['id'] ?>"
                                id="owner<?= $ownerId ?>tech<?= (int)$technician['id'] ?>"
                                <?= in_array(
                                    (int)$technician['id'],
                                    $selectedIds,
                                    true
                                )
                                    ? 'checked'
                                    : ''
                                ?>
                            >


                            <label
                                class="form-check-label"
                                for="owner<?= $ownerId ?>tech<?= (int)$technician['id'] ?>"
                            >

                                <strong>

                                    <?= htmlspecialchars(
                                        $technician['name']
                                    ) ?>

                                </strong>


<?php if (!empty($technician['email'])): ?>

                                <br>

                                <small class="text-muted">

                                    <?= htmlspecialchars(
                                        $technician['email']
                                    ) ?>

                                </small>

<?php endif; ?>


                            </label>

                        </div>


<?php endforeach; ?>


                    </div>


                    <small class="text-muted">

                        Select one or more active technicians.

                    </small>


<?php endif; ?>


                </div>


                <div class="modal-footer">

                    <button
                        type="button"
                        class="btn btn-secondary"
                        data-bs-dismiss="modal"
                    >

                        Cancel

                    </button>


                    <button
                        type="submit"
                        name="assign_technicians"
                        value="1"
                        class="btn btn-success"
                    >

                        Save Assignment

                    </button>

                </div>


            </form>

        </div>

    </div>

</div>


<?php endwhile; ?>


<?php endif; ?>


</div>


<?php include('../includes/footer.php'); ?>