<?php

/* =========================================================
   NLETA MAINTENANCE MANAGEMENT SYSTEM
   PHASE 4 — SERVICE SELECTION
   OWNER SIDE

   FLOW
   ---------------------------------------------------------
   Step 1  Select Service
        ↓
   Step 2  My Lifts
        ↓
   Step 3  Review & Submit
        ↓
   Service Request
========================================================= */


/* =========================================================
   INCLUDES
========================================================= */

include('../config/db.php');
include('../config/auth.php');

require_role('owner');


/* =========================================================
   SESSION
========================================================= */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}


/* =========================================================
   OWNER ID
========================================================= */

$owner_id =
    (int)(
        $_SESSION['uid']
        ?? 0
    );


if ($owner_id <= 0) {

    header(
        "Location: ../login.php"
    );

    exit;
}


/* =========================================================
   TIMEZONE — IST
========================================================= */

$timezone =
    new DateTimeZone(
        'Asia/Kolkata'
    );


$now =
    new DateTime(
        'now',
        $timezone
    );


/* =========================================================
   CSRF
========================================================= */

if (
    empty(
        $_SESSION[
            'csrf_service_selection'
        ]
    )
) {

    $_SESSION[
        'csrf_service_selection'
    ] =
        bin2hex(
            random_bytes(32)
        );
}


$csrfToken =
    $_SESSION[
        'csrf_service_selection'
    ];


/* =========================================================
   RESET SERVICE SELECTION
========================================================= */

if (
    isset($_GET['reset'])
    &&
    $_GET['reset'] === '1'
) {

    unset(
        $_SESSION['phase4_service_ids'],
        $_SESSION['phase4_service_codes'],
        $_SESSION['phase4_service_names'],
        $_SESSION['phase4_service_id'],
        $_SESSION['phase4_service_code'],
        $_SESSION['phase4_service_name'],
        $_SESSION['phase4_asset_ids']
    );

    header("Location: service-selection.php");
    exit;
}


/* =========================================================
   MESSAGE
========================================================= */

$error = '';

$selectedServiceIds = [];


/* =========================================================
   EXISTING MULTI-SERVICE SESSION
========================================================= */

if (
    isset($_SESSION['phase4_service_ids'])
    &&
    is_array($_SESSION['phase4_service_ids'])
) {
    foreach (
        $_SESSION['phase4_service_ids'] as $serviceId
    ) {
        $serviceId = (int)$serviceId;

        if ($serviceId > 0) {
            $selectedServiceIds[] = $serviceId;
        }
    }
}

$selectedServiceIds = array_values(
    array_unique($selectedServiceIds)
);


/* =========================================================
   BACKWARD COMPATIBILITY
   ---------------------------------------------------------
   If an old single service session exists, show it as
   selected once. It will be converted to the new array
   format when the owner submits this page.
========================================================= */

if (
    empty($selectedServiceIds)
    &&
    !empty($_SESSION['phase4_service_id'])
) {
    $oldServiceId = (int)$_SESSION['phase4_service_id'];

    if ($oldServiceId > 0) {
        $selectedServiceIds[] = $oldServiceId;
    }
}


/* =========================================================
   HANDLE SERVICE SELECTION
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
) {

    /* =====================================================
       CSRF VALIDATION
    ===================================================== */

    $postedToken = $_POST['csrf_token'] ?? '';

    if (
        empty($postedToken)
        ||
        !hash_equals(
            $csrfToken,
            $postedToken
        )
    ) {

        $error =
            'Security validation failed. Please try again.';

    } else {

        /* =================================================
           GET SELECTED SERVICES
        ================================================= */

        $postedServiceIds =
            $_POST['service_ids'] ?? [];

        if (!is_array($postedServiceIds)) {
            $postedServiceIds = [];
        }


        /* =================================================
           CLEAN IDS
        ================================================= */

        $cleanServiceIds = [];

        foreach (
            $postedServiceIds as $serviceId
        ) {

            $serviceId = (int)$serviceId;

            if ($serviceId > 0) {
                $cleanServiceIds[] = $serviceId;
            }
        }

        $cleanServiceIds = array_values(
            array_unique($cleanServiceIds)
        );


        /* =================================================
           REQUIRE AT LEAST ONE SERVICE
        ================================================= */

        if (empty($cleanServiceIds)) {

            $selectedServiceIds = [];

            $error =
                'Please select at least one service before continuing.';

        } else {

            /* =================================================
               VERIFY EVERY SELECTED SERVICE
            ================================================= */

            $verifiedServices = [];
            $invalidServiceFound = false;

            $serviceStmt = $conn->prepare("
                SELECT
                    id,
                    service_code,
                    service_name,
                    description
                FROM service_catalog
                WHERE id = ?
                  AND is_active = 1
                LIMIT 1
            ");

            if (!$serviceStmt) {

                $error =
                    'Unable to verify the selected services.';

            } else {

                foreach (
                    $cleanServiceIds as $serviceId
                ) {

                    $serviceStmt->bind_param(
                        "i",
                        $serviceId
                    );

                    $serviceStmt->execute();

                    $serviceResult =
                        $serviceStmt->get_result();

                    $service =
                        $serviceResult->fetch_assoc();

                    if (!$service) {
                        $invalidServiceFound = true;
                        break;
                    }

                    $verifiedServices[] = $service;
                }

                $serviceStmt->close();


                if ($invalidServiceFound) {

                    $error =
                        'One or more selected services are no longer available. Please refresh and select again.';

                } else {

                    /* =====================================
                       STORE MULTIPLE SERVICES
                    ====================================== */

                    $serviceIds = [];
                    $serviceCodes = [];
                    $serviceNames = [];

                    foreach (
                        $verifiedServices as $service
                    ) {

                        $serviceIds[] =
                            (int)$service['id'];

                        $serviceCodes[] =
                            $service['service_code'];

                        $serviceNames[] =
                            $service['service_name'];
                    }

                    $_SESSION['phase4_service_ids'] =
                        $serviceIds;

                    $_SESSION['phase4_service_codes'] =
                        $serviceCodes;

                    $_SESSION['phase4_service_names'] =
                        $serviceNames;


                    /* =====================================
                       REMOVE OLD SINGLE-SERVICE SESSION
                    ====================================== */

                    unset(
                        $_SESSION['phase4_service_id'],
                        $_SESSION['phase4_service_code'],
                        $_SESSION['phase4_service_name']
                    );


                    /* =====================================
                       CLEAR OLD ASSET SELECTION
                       Because services have changed.
                    ====================================== */

                    unset(
                        $_SESSION['phase4_asset_ids']
                    );


                    /* =====================================
                       CONTINUE
                    ====================================== */

                    header(
                        "Location: service-lifts.php"
                    );

                    exit;
                }
            }
        }
    }
}


/* =========================================================
   LOAD ACTIVE SERVICES
========================================================= */

$services = [];

$serviceListStmt = $conn->prepare("
    SELECT
        id,
        service_code,
        service_name,
        description
    FROM service_catalog
    WHERE is_active = 1
    ORDER BY
        sort_order ASC,
        id ASC
");

if ($serviceListStmt) {

    $serviceListStmt->execute();

    $serviceListResult =
        $serviceListStmt->get_result();

    while (
        $row =
        $serviceListResult->fetch_assoc()
    ) {

        $services[] = $row;
    }

    $serviceListStmt->close();
}


$selectedCount =
    count($selectedServiceIds);

/* =========================================================
   HEADER / SIDEBAR
========================================================= */

include('../includes/header.php');

include('../includes/sidebar-owner.php');

?>


<style>

.phase4-wrapper {
    max-width: 1200px;
    margin: 0 auto;
}

.phase4-header {
    background: #ffffff;
    border-radius: 14px;
    padding: 24px;
    margin-bottom: 24px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.08);
}

.phase4-header h3 {
    margin: 0;
    font-weight: 600;
}

.phase4-header p {
    margin: 8px 0 0;
    color: #6c757d;
}

.multi-service-info {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 18px;
    padding: 12px 14px;
    border-radius: 10px;
    background: #f4f8ff;
    border: 1px solid #dbe8ff;
    color: #315b9d;
    font-size: 14px;
}

.multi-service-info i {
    font-size: 18px;
}

.phase4-steps {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 20px;
    flex-wrap: wrap;
}

.phase4-step {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #6c757d;
    font-size: 14px;
}

.phase4-step-number {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #e9ecef;
    font-weight: 600;
}

.phase4-step.active {
    color: #0d6efd;
    font-weight: 600;
}

.phase4-step.active .phase4-step-number {
    background: #0d6efd;
    color: #ffffff;
}

.phase4-arrow {
    color: #adb5bd;
}

.service-section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    margin-bottom: 18px;
}

.service-section-title {
    margin: 0;
    font-size: 18px;
    font-weight: 600;
    color: #212529;
}

.service-section-subtitle {
    margin: 4px 0 0;
    color: #6c757d;
    font-size: 13px;
}

.selected-count {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    background: #eaf2ff;
    color: #0d6efd;
    border-radius: 20px;
    padding: 7px 12px;
    font-size: 13px;
    font-weight: 600;
    white-space: nowrap;
}

.service-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
}

.service-card {
    position: relative;
}

.service-card input[type="checkbox"] {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}

.service-card label {
    display: block;
    height: 100%;
    background: #ffffff;
    border: 2px solid #e5e7eb;
    border-radius: 14px;
    padding: 22px;
    cursor: pointer;
    transition:
        border-color .15s ease,
        box-shadow .15s ease,
        transform .15s ease,
        background .15s ease;
}

.service-card label:hover {
    border-color: #86b7fe;
    box-shadow: 0 4px 14px rgba(0,0,0,0.08);
    transform: translateY(-1px);
}

.service-card input[type="checkbox"]:checked + label {
    border-color: #0d6efd;
    background: #f4f8ff;
    box-shadow: 0 0 0 3px rgba(13,110,253,0.10);
}

.service-icon {
    width: 46px;
    height: 46px;
    border-radius: 12px;
    background: #eaf2ff;
    color: #0d6efd;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    margin-bottom: 14px;
}

.service-name {
    font-size: 17px;
    font-weight: 600;
    color: #212529;
    padding-right: 40px;
}

.service-code {
    display: inline-block;
    margin-top: 8px;
    font-size: 12px;
    font-weight: 600;
    color: #0d6efd;
    background: #eaf2ff;
    padding: 4px 8px;
    border-radius: 6px;
}

.service-description {
    margin-top: 10px;
    font-size: 13px;
    line-height: 1.5;
    color: #6c757d;
}

.service-check {
    position: absolute;
    top: 20px;
    right: 20px;
    width: 28px;
    height: 28px;
    border-radius: 7px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #ffffff;
    border: 2px solid #ced4da;
    color: transparent;
    transition: all .15s ease;
    pointer-events: none;
}

.service-card input[type="checkbox"]:checked ~ .service-check {
    background: #0d6efd;
    border-color: #0d6efd;
    color: #ffffff;
}

.phase4-actions {
    background: #ffffff;
    border-radius: 14px;
    padding: 18px 20px;
    margin-top: 24px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.08);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
}

.step-info {
    display: flex;
    flex-direction: column;
    gap: 3px;
}

.step-info-title {
    font-weight: 600;
}

.step-info-text {
    color: #6c757d;
    font-size: 13px;
}

@media (max-width: 768px) {

    .service-grid {
        grid-template-columns: 1fr;
    }

    .service-section-header {
        align-items: flex-start;
        flex-direction: column;
    }

    .phase4-actions {
        flex-direction: column;
        align-items: stretch;
    }

    .phase4-actions .action-buttons {
        width: 100%;
    }

    .phase4-actions .action-buttons .btn {
        flex: 1;
    }
}

</style>


<div class="content">

<div class="phase4-wrapper">

<!-- =====================================================
     PAGE HEADER
===================================================== -->

<div class="phase4-header">

    <div class="d-flex justify-content-between align-items-start gap-3">

        <div>

            <h3>
                <i class="bi bi-clipboard-plus me-2"></i>
                Service Selection
            </h3>

            <p>
                Select one or multiple services required for your Assets.
            </p>

            <div class="multi-service-info">

                <i class="bi bi-info-circle-fill"></i>

                <span>
                    You can select
                    <strong>multiple services</strong>
                    in the same service request.
                </span>

            </div>

        </div>

        <div class="text-end">

            <small class="text-muted">
                Request Date &amp; Time
            </small>

            <div class="fw-semibold">
                <?= htmlspecialchars(
                    $now->format('d-m-Y h:i A')
                ) ?>
            </div>

        </div>

    </div>


    <!-- =================================================
         STEP INDICATOR
    ================================================== -->

    <div class="phase4-steps">

        <div class="phase4-step active">

            <span class="phase4-step-number">1</span>

            <span>Select Services</span>

        </div>

        <span class="phase4-arrow">→</span>

        <div class="phase4-step">

            <span class="phase4-step-number">2</span>

            <span>Select Assets</span>

        </div>

        <span class="phase4-arrow">→</span>

        <div class="phase4-step">

            <span class="phase4-step-number">3</span>

            <span>Review &amp; Submit</span>

        </div>

    </div>

</div>


<!-- =====================================================
     ERROR
===================================================== -->

<?php if (!empty($error)): ?>

<div class="alert alert-danger shadow-sm">

    <i class="bi bi-exclamation-triangle-fill me-2"></i>

    <?= htmlspecialchars($error) ?>

</div>

<?php endif; ?>


<!-- =====================================================
     NO SERVICES
===================================================== -->

<?php if (empty($services)): ?>

<div class="alert alert-warning shadow-sm">

    <i class="bi bi-info-circle-fill me-2"></i>

    No services are currently available.
    Please contact the administrator.

</div>

<?php else: ?>


<!-- =====================================================
     SERVICE FORM
===================================================== -->

<form
    method="POST"
    action="service-selection.php"
    id="serviceSelectionForm"
>

    <input
        type="hidden"
        name="csrf_token"
        value="<?= htmlspecialchars($csrfToken) ?>"
    >


    <!-- =================================================
         SERVICE HEADER
    ================================================== -->

    <div class="service-section-header">

        <div>

            <h4 class="service-section-title">
                Available Services
            </h4>

            <p class="service-section-subtitle">
                Select all services required for this request.
            </p>

        </div>

        <div class="selected-count">

            <i class="bi bi-check2-circle"></i>

            <span id="selectedCountText">
                <?= $selectedCount ?>
                <?= $selectedCount === 1
                    ? 'Service Selected'
                    : 'Services Selected' ?>
            </span>

        </div>

    </div>


    <!-- =================================================
         SERVICE GRID
    ================================================== -->

    <div class="service-grid">

        <?php foreach ($services as $service): ?>

            <?php
            $serviceId = (int)$service['id'];

            $isSelected =
                in_array(
                    $serviceId,
                    $selectedServiceIds,
                    true
                );
            ?>

            <div class="service-card">

                <input
                    type="checkbox"
                    name="service_ids[]"
                    id="service_<?= $serviceId ?>"
                    value="<?= $serviceId ?>"
                    <?= $isSelected ? 'checked' : '' ?>
                >

                <label for="service_<?= $serviceId ?>">

                    <div class="service-icon">

                        <i class="bi bi-shield-check"></i>

                    </div>

                    <div class="service-name">

                        <?= htmlspecialchars(
                            $service['service_name']
                        ) ?>

                    </div>

                    <span class="service-code">

                        <?= htmlspecialchars(
                            $service['service_code']
                        ) ?>

                    </span>

                    <?php if (!empty($service['description'])): ?>

                        <div class="service-description">

                            <?= htmlspecialchars(
                                $service['description']
                            ) ?>

                        </div>

                    <?php endif; ?>

                </label>


                <div class="service-check">

                    <i class="bi bi-check-lg"></i>

                </div>

            </div>

        <?php endforeach; ?>

    </div>


    <!-- =================================================
         ACTIONS
    ================================================== -->

    <div class="phase4-actions">

        <div class="step-info">

            <div class="step-info-title">
                Step 1 of 3
            </div>

            <div
                class="step-info-text"
                id="selectionHint"
            >
                <?php if ($selectedCount === 0): ?>

                    Select at least one service to continue.

                <?php elseif ($selectedCount === 1): ?>

                    1 service selected. You can select more if required.

                <?php else: ?>

                    <?= $selectedCount ?>
                    services selected. You can continue to Asset Selection.

                <?php endif; ?>
            </div>

        </div>


        <div class="d-flex gap-2 action-buttons">

            <?php if ($selectedCount > 0): ?>

                <a
                    href="service-selection.php?reset=1"
                    class="btn btn-outline-secondary"
                >

                    <i class="bi bi-arrow-counterclockwise me-1"></i>

                    Reset

                </a>

            <?php endif; ?>


            <button
                type="submit"
                class="btn btn-primary px-4"
                id="continueButton"
            >

                Continue

                <i class="bi bi-arrow-right ms-1"></i>

            </button>

        </div>

    </div>

</form>

<?php endif; ?>

</div>

</div>


<?php include('../includes/footer.php'); ?>


<script>

document.addEventListener(
    'DOMContentLoaded',
    function () {

        const form =
            document.getElementById(
                'serviceSelectionForm'
            );

        const continueButton =
            document.getElementById(
                'continueButton'
            );

        const selectedCountText =
            document.getElementById(
                'selectedCountText'
            );

        const selectionHint =
            document.getElementById(
                'selectionHint'
            );

        if (!form) {
            return;
        }


        /* =================================================
           ALL SERVICE CHECKBOXES
        ================================================== */

        const serviceCheckboxes =
            form.querySelectorAll(
                'input[name="service_ids[]"]'
            );


        /* =================================================
           UPDATE UI
        ================================================== */

        function updateSelectionCount() {

            let count = 0;

            serviceCheckboxes.forEach(
                function (checkbox) {

                    if (checkbox.checked) {
                        count++;
                    }

                }
            );


            if (selectedCountText) {

                selectedCountText.textContent =
                    count +
                    (
                        count === 1
                            ? ' Service Selected'
                            : ' Services Selected'
                    );
            }


            if (selectionHint) {

                if (count === 0) {

                    selectionHint.textContent =
                        'Select at least one service to continue.';

                } else if (count === 1) {

                    selectionHint.textContent =
                        '1 service selected. You can select more if required.';

                } else {

                    selectionHint.textContent =
                        count +
                        ' services selected. You can continue to Asset Selection.';
                }
            }
        }


        /* =================================================
           CHECKBOX EVENTS
        ================================================== */

        serviceCheckboxes.forEach(
            function (checkbox) {

                checkbox.addEventListener(
                    'change',
                    updateSelectionCount
                );

            }
        );


        /* =================================================
           FORM SUBMIT
        ================================================== */

        form.addEventListener(
            'submit',
            function (event) {

                let selected = 0;

                serviceCheckboxes.forEach(
                    function (checkbox) {

                        if (checkbox.checked) {
                            selected++;
                        }

                    }
                );


                if (selected === 0) {

                    event.preventDefault();

                    alert(
                        'Please select at least one service before continuing.'
                    );

                    return false;
                }


                if (continueButton) {

                    continueButton.disabled = true;

                    continueButton.innerHTML =
                        '<span class="spinner-border spinner-border-sm me-1"></span>' +
                        'Loading...';
                }
            }
        );


        /* =================================================
           INITIAL UI
        ================================================== */

        updateSelectionCount();

    }
);

</script>
