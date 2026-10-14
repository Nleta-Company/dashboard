<?php

/* =========================================================
   NLETA OWNER SERVICE ACCESS
   ADMIN CONTROL
========================================================= */

include('../config/db.php');
include('../config/auth.php');
require_once '../config/security.php';
require_once '../config/owner_access.php';

require_role('admin');


/* =========================================================
   VARIABLES
========================================================= */

$error = '';
$success = '';

$selectedOwnerId =
    (int)($_GET['owner_id'] ?? $_POST['owner_id'] ?? 0);


/* =========================================================
   OWNER SERVICE DEFINITIONS
========================================================= */

$services = getOwnerServiceList();

/* =========================================================
   SAVE ACCESS
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    isset($_POST['save_access'])
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
            'Invalid request. Please try again.';

    } else {

        $ownerId =
            (int)(
                $_POST['owner_id']
                ?? 0
            );


        /* =================================================
           VERIFY OWNER
        ================================================= */

        $ownerStmt =
            $conn->prepare("
                SELECT
                    id,
                    name,
                    email
                FROM users
                WHERE id = ?
                  AND role = 'owner'
                LIMIT 1
            ");


        if (!$ownerStmt) {

            $error =
                'Unable to verify Owner.';

        } else {

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

                $error =
                    'Invalid Owner selected.';

            } else {


                /* =========================================
                   SELECTED SERVICES
                ========================================== */

                $selectedServices =
                    $_POST['services'] ?? [];


                if (
                    !is_array(
                        $selectedServices
                    )
                ) {

                    $selectedServices = [];

                }


                /*
                 * Only accept service codes that actually
                 * exist in owner_access.php.
                 */

                $selectedServices =
                    array_values(
                        array_intersect(
                            array_keys($services),
                            array_map(
                                'strval',
                                $selectedServices
                            )
                        )
                    );


                /* =========================================
                   SAVE IN TRANSACTION
                ========================================== */

                $conn->begin_transaction();


                try {

                    foreach (
                        $services
                        as $serviceCode => $service
                    ) {

                        if (
                            in_array(
                                $serviceCode,
                                $selectedServices,
                                true
                            )
                        ) {

                            /*
                             * Enable
                             */

                            $stmt =
                                $conn->prepare("
                                    INSERT INTO
                                        owner_service_access
                                    (
                                        owner_id,
                                        service_code,
                                        is_enabled,
                                        enabled_by,
                                        enabled_at
                                    )
                                    VALUES
                                    (
                                        ?,
                                        ?,
                                        1,
                                        ?,
                                        NOW()
                                    )
                                    ON DUPLICATE KEY UPDATE
                                        is_enabled = 1,
                                        enabled_by = VALUES(enabled_by),
                                        enabled_at = NOW()
                                ");

                            if (!$stmt) {

                                throw new Exception(
                                    'Unable to prepare access update.'
                                );
                            }


                            $adminId =
                                (int)(
                                    $_SESSION['uid']
                                    ?? 0
                                );


                            $stmt->bind_param(
                                "isi",
                                $ownerId,
                                $serviceCode,
                                $adminId
                            );


                            if (
                                !$stmt->execute()
                            ) {

                                throw new Exception(
                                    'Unable to enable ' .
                                    $service['name'] .
                                    '.'
                                );
                            }


                            $stmt->close();

                        } else {

                            /*
                             * Disable
                             */

                            $stmt =
                                $conn->prepare("
                                    INSERT INTO
                                        owner_service_access
                                    (
                                        owner_id,
                                        service_code,
                                        is_enabled,
                                        enabled_by,
                                        enabled_at
                                    )
                                    VALUES
                                    (
                                        ?,
                                        ?,
                                        0,
                                        NULL,
                                        NULL
                                    )
                                    ON DUPLICATE KEY UPDATE
                                        is_enabled = 0,
                                        enabled_by = NULL,
                                        enabled_at = NULL
                                ");


                            if (!$stmt) {

                                throw new Exception(
                                    'Unable to prepare access update.'
                                );
                            }


                            $stmt->bind_param(
                                "is",
                                $ownerId,
                                $serviceCode
                            );


                            if (
                                !$stmt->execute()
                            ) {

                                throw new Exception(
                                    'Unable to disable ' .
                                    $service['name'] .
                                    '.'
                                );
                            }


                            $stmt->close();
                        }
                    }


                    /* =====================================
                       COMMIT
                    ====================================== */

                    $conn->commit();


                    $selectedOwnerId =
                        $ownerId;


                    $success =
                        'Owner service access updated successfully.';

                } catch (Throwable $e) {

                    $conn->rollback();

                    $error =
                        'Unable to save Owner access. Please try again.';
                }
            }
        }
    }
}


/* =========================================================
   FETCH OWNERS
========================================================= */

$owners = [];

$ownerResult =
    $conn->query("
        SELECT
            id,
            name,
            email,
            account_status
        FROM users
        WHERE role = 'owner'
        ORDER BY name ASC
    ");


if ($ownerResult) {

    while (
        $row =
        $ownerResult->fetch_assoc()
    ) {

        $owners[] =
            $row;
    }
}


/* =========================================================
   SELECTED OWNER
========================================================= */

$selectedOwner = null;

if ($selectedOwnerId > 0) {

    $stmt =
        $conn->prepare("
            SELECT
                id,
                name,
                email,
                account_status
            FROM users
            WHERE id = ?
              AND role = 'owner'
            LIMIT 1
        ");


    if ($stmt) {

        $stmt->bind_param(
            "i",
            $selectedOwnerId
        );

        $stmt->execute();

        $selectedOwner =
            $stmt
            ->get_result()
            ->fetch_assoc();

        $stmt->close();
    }
}


/* =========================================================
   LOAD CURRENT ACCESS
========================================================= */

$currentAccess = [];

foreach (
    $services
    as $serviceCode => $service
) {

    $currentAccess[$serviceCode] =
        false;
}


if ($selectedOwner) {

    $accessStmt =
        $conn->prepare("
            SELECT
                service_code,
                is_enabled
            FROM owner_service_access
            WHERE owner_id = ?
        ");


    if ($accessStmt) {

        $accessStmt->bind_param(
            "i",
            $selectedOwnerId
        );

        $accessStmt->execute();

        $accessResult =
            $accessStmt
            ->get_result();


        while (
            $row =
            $accessResult->fetch_assoc()
        ) {

            $code =
                $row['service_code'];


            if (
                array_key_exists(
                    $code,
                    $currentAccess
                )
            ) {

                $currentAccess[$code] =
                    ((int)$row['is_enabled'] === 1);
            }
        }


        $accessStmt->close();
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
   PAGE
========================================================= */

.access-wrapper {

    max-width: 1100px;

}


/* =========================================================
   HEADER
========================================================= */

.access-header {

    background: #ffffff;

    border-radius: 14px;

    padding: 24px;

    margin-bottom: 20px;

    box-shadow:
        0 2px 10px rgba(
            0,
            0,
            0,
            0.08
        );

}


.access-header h3 {

    margin: 0;

    font-weight: 600;

}


/* =========================================================
   OWNER SELECT CARD
========================================================= */

.owner-select-card {

    background: #ffffff;

    border-radius: 14px;

    padding: 20px;

    margin-bottom: 20px;

    box-shadow:
        0 2px 10px rgba(
            0,
            0,
            0,
            0.08
        );

}


/* =========================================================
   SERVICE CARD
========================================================= */

.service-card {

    background: #ffffff;

    border-radius: 14px;

    padding: 18px;

    margin-bottom: 12px;

    border: 1px solid #e5e7eb;

    transition:
        0.2s ease;

}


.service-card:hover {

    box-shadow:
        0 3px 12px rgba(
            0,
            0,
            0,
            0.08
        );

}


/* =========================================================
   SERVICE ICON
========================================================= */

.service-icon {

    width: 44px;

    height: 44px;

    border-radius: 10px;

    display: flex;

    align-items: center;

    justify-content: center;

    background: #eef4ff;

    color: #0d6efd;

    font-size: 19px;

}


/* =========================================================
   SERVICE NAME
========================================================= */

.service-name {

    font-weight: 600;

    font-size: 15px;

}


/* =========================================================
   SWITCH
========================================================= */

.access-switch {

    width: 50px;

    height: 25px;

    cursor: pointer;

}


/* =========================================================
   OWNER INFO
========================================================= */

.owner-info {

    background: #f8f9fa;

    border-radius: 10px;

    padding: 14px 16px;

}


/* =========================================================
   MOBILE
========================================================= */

@media (max-width: 768px) {

    .service-card {

        padding: 14px;

    }

}

</style>


<div class="content">

<div class="access-wrapper">


<!-- =====================================================
     HEADER
====================================================== -->

<div class="access-header">

    <div
        class="d-flex
               justify-content-between
               align-items-center
               gap-3
               flex-wrap"
    >

        <div>

            <h3>

                <i
                    class="bi bi-shield-lock me-2"
                ></i>

                Owner Service Access

            </h3>

            <p class="text-muted mb-0 mt-2">

                Control which sections are available
                to each Owner.

            </p>

        </div>

    </div>

</div>


<!-- =====================================================
     MESSAGES
====================================================== -->

<?php if ($success): ?>

<div class="alert alert-success alert-dismissible fade show">

    <i class="bi bi-check-circle-fill me-2"></i>

    <?= htmlspecialchars($success) ?>

    <button
        type="button"
        class="btn-close"
        data-bs-dismiss="alert"
    ></button>

</div>

<?php endif; ?>


<?php if ($error): ?>

<div class="alert alert-danger alert-dismissible fade show">

    <i class="bi bi-exclamation-triangle-fill me-2"></i>

    <?= htmlspecialchars($error) ?>

    <button
        type="button"
        class="btn-close"
        data-bs-dismiss="alert"
    ></button>

</div>

<?php endif; ?>


<!-- =====================================================
     OWNER SELECT
====================================================== -->

<div class="owner-select-card">

    <form
        method="GET"
        id="ownerSelectForm"
    >

        <label class="form-label fw-semibold">

            Select Owner

        </label>


        <select
            name="owner_id"
            class="form-select"
            onchange="this.form.submit()"
        >

            <option value="">

                Select Owner

            </option>


            <?php foreach (
                $owners
                as $owner
            ): ?>

                <option
                    value="<?= (int)$owner['id'] ?>"
                    <?= (
                        $selectedOwnerId ===
                        (int)$owner['id']
                    )
                        ? 'selected'
                        : ''
                    ?>
                >

                    <?= htmlspecialchars(
                        $owner['name']
                    ) ?>

                    <?php if (
                        !empty(
                            $owner['email']
                        )
                    ): ?>

                        —
                        <?= htmlspecialchars(
                            $owner['email']
                        ) ?>

                    <?php endif; ?>

                </option>

            <?php endforeach; ?>

        </select>

    </form>

</div>


<?php if ($selectedOwner): ?>


<!-- =====================================================
     OWNER INFORMATION
====================================================== -->

<div class="owner-select-card">

    <div class="owner-info">

        <div class="row g-3">

            <div class="col-md-4">

                <small class="text-muted">
                    Owner
                </small>

                <div class="fw-semibold">

                    <?= htmlspecialchars(
                        $selectedOwner['name']
                    ) ?>

                </div>

            </div>


            <div class="col-md-4">

                <small class="text-muted">
                    Email
                </small>

                <div class="fw-semibold">

                    <?= htmlspecialchars(
                        $selectedOwner['email']
                    ) ?>

                </div>

            </div>


            <div class="col-md-4">

                <small class="text-muted">
                    Account Status
                </small>

                <div>

                    <?php if (
                        ($selectedOwner['account_status'] ?? 'active')
                        === 'active'
                    ): ?>

                        <span class="badge bg-success">

                            Active

                        </span>

                    <?php else: ?>

                        <span class="badge bg-secondary">

                            <?= htmlspecialchars(
                                ucfirst(
                                    $selectedOwner[
                                        'account_status'
                                    ]
                                    ?? 'unknown'
                                )
                            ) ?>

                        </span>

                    <?php endif; ?>

                </div>

            </div>

        </div>

    </div>

</div>


<!-- =====================================================
     ACCESS FORM
====================================================== -->

<form
    method="POST"
    id="accessForm"
>


    <?= csrfField() ?>


    <input
        type="hidden"
        name="owner_id"
        value="<?= $selectedOwnerId ?>"
    >


    <!-- =================================================
         QUICK ACTIONS
    ================================================== -->

    <div class="owner-select-card">

        <div
            class="d-flex
                   justify-content-between
                   align-items-center
                   flex-wrap
                   gap-2"
        >

            <div>

                <strong>
                    Service Permissions
                </strong>

                <div class="small text-muted">

                    Enable only the sections this Owner
                    is authorized to use.

                </div>

            </div>


            <div class="d-flex gap-2">

                <button
                    type="button"
                    class="btn btn-sm btn-outline-success"
                    onclick="setAllServices(true)"
                >

                    Unlock All

                </button>


                <button
                    type="button"
                    class="btn btn-sm btn-outline-secondary"
                    onclick="setAllServices(false)"
                >

                    Lock All

                </button>

            </div>

        </div>

    </div>


    <!-- =================================================
         SERVICES
    ================================================== -->

    <?php foreach (
        $services
        as $serviceCode => $service
    ): ?>


        <div class="service-card">

            <div
                class="d-flex
                       align-items-center
                       justify-content-between
                       gap-3"
            >


                <div
                    class="d-flex
                           align-items-center
                           gap-3"
                >

                    <div class="service-icon">

                        <i
                            class="bi <?= htmlspecialchars(
                                $service['icon']
                            ) ?>"
                        ></i>

                    </div>


                    <div>

                        <div class="service-name">

                            <?= htmlspecialchars(
                                $service['name']
                            ) ?>

                        </div>


                    </div>

                </div>


                <!-- SWITCH -->

                <div class="form-check form-switch">

                    <input
                        class="form-check-input access-switch"
                        type="checkbox"
                        name="services[]"
                        value="<?= htmlspecialchars(
                            $serviceCode
                        ) ?>"
                        id="service_<?= htmlspecialchars(
                            $serviceCode
                        ) ?>"
                        <?= !empty(
                            $currentAccess[
                                $serviceCode
                            ]
                        )
                            ? 'checked'
                            : ''
                        ?>
                    >

                    <label
                        class="form-check-label"
                        for="service_<?= htmlspecialchars(
                            $serviceCode
                        ) ?>"
                    >

                        <span class="access-status">

                            <?= !empty(
                                $currentAccess[
                                    $serviceCode
                                ]
                            )
                                ? 'Unlocked'
                                : 'Locked'
                            ?>

                        </span>

                    </label>

                </div>


            </div>


        </div>


    <?php endforeach; ?>


    <!-- =================================================
         SAVE
    ================================================== -->

    <div
        class="owner-select-card
               d-flex
               justify-content-between
               align-items-center
               gap-3
               flex-wrap"
    >

        <div class="text-muted small">

            <i class="bi bi-info-circle me-1"></i>

            Locked sections remain visible to the Owner
            but cannot be opened.

        </div>


        <button
            type="submit"
            name="save_access"
            class="btn btn-primary px-4"
        >

            <i class="bi bi-shield-check me-1"></i>

            Save Access

        </button>

    </div>


</form>


<?php else: ?>


<!-- =====================================================
     NO OWNER SELECTED
====================================================== -->

<div class="card shadow-sm p-5 text-center">

    <i
        class="bi bi-person-lock"
        style="
            font-size:50px;
            color:#6c757d;
        "
    ></i>


    <h5 class="mt-3">

        Select an Owner

    </h5>


    <p class="text-muted mb-0">

        Select an Owner above to manage
        their service access.

    </p>

</div>


<?php endif; ?>


</div>

</div>


<script>

/* =========================================================
   QUICK LOCK / UNLOCK
========================================================= */

function setAllServices(enabled) {

    const switches =
        document.querySelectorAll(
            '.access-switch'
        );


    switches.forEach(
        function (switchElement) {

            switchElement.checked =
                enabled;

        }
    );


    updateAccessLabels();
}


/* =========================================================
   UPDATE LABELS
========================================================= */

function updateAccessLabels() {

    const switches =
        document.querySelectorAll(
            '.access-switch'
        );


    switches.forEach(
        function (switchElement) {

            const label =
                switchElement
                .closest('.form-switch')
                .querySelector(
                    '.access-status'
                );


            if (!label) {
                return;
            }


            label.textContent =
                switchElement.checked
                    ? 'Unlocked'
                    : 'Locked';

        }
    );
}


/* =========================================================
   SWITCH CHANGE
========================================================= */

document.addEventListener(
    'change',
    function (event) {

        if (
            event.target.classList
            &&
            event.target.classList.contains(
                'access-switch'
            )
        ) {

            updateAccessLabels();

        }

    }
);


updateAccessLabels();

</script>


<?php include('../includes/footer.php'); ?>