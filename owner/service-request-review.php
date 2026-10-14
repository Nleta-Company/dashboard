<?php
/* =========================================================
   NLETA — PHASE 4 — STEP 3
   OWNER — REVIEW & SUBMIT

   FLOW:

   Owner
      ↓
   Review
      ↓
   Submit Service Request
      ↓
   Database
      ↓
   Email Admin
      ↓
   Admin Login
      ↓
   Accept / Reject
========================================================= */

include('../config/db.php');
include('../config/auth.php');

require_role('owner');


/* =========================================================
   GENERAL MAILER
========================================================= */

require_once(
    '../config/nleta-mailer.php'
);


if (
    session_status() !== PHP_SESSION_ACTIVE
) {
    session_start();
}


$owner_id =
    (int)(
        $_SESSION['uid']
        ??
        0
    );


if (
    $owner_id <= 0
) {

    header(
        "Location: ../login.php"
    );

    exit;
}


date_default_timezone_set(
    'Asia/Kolkata'
);


/* =========================================================
   CSRF
========================================================= */

if (
    empty(
        $_SESSION[
            'csrf_service_request_review'
        ]
    )
) {

    $_SESSION[
        'csrf_service_request_review'
    ] =
        bin2hex(
            random_bytes(32)
        );
}


$csrfToken =
    $_SESSION[
        'csrf_service_request_review'
    ];


/* =========================================================
   SERVICES — MULTIPLE SERVICE SELECTION
========================================================= */

$service_ids = [];

if (
    isset($_SESSION['phase4_service_ids']) &&
    is_array($_SESSION['phase4_service_ids'])
) {
    foreach ($_SESSION['phase4_service_ids'] as $sid) {
        $sid = (int)$sid;

        if ($sid > 0) {
            $service_ids[] = $sid;
        }
    }
}

/* Backward compatibility with old single-service session. */
if (
    empty($service_ids) &&
    !empty($_SESSION['phase4_service_id'])
) {
    $oldServiceId = (int)$_SESSION['phase4_service_id'];

    if ($oldServiceId > 0) {
        $service_ids[] = $oldServiceId;
    }
}

$service_ids = array_values(
    array_unique($service_ids)
);

if (empty($service_ids)) {
    header("Location: service-selection.php");
    exit;
}


/* =========================================================
   LOAD ALL SERVICES
========================================================= */

$servicePlaceholders = implode(
    ',',
    array_fill(0, count($service_ids), '?')
);

$serviceBindTypes = str_repeat(
    'i',
    count($service_ids)
);

$stmt = $conn->prepare("
    SELECT
        id,
        service_code,
        service_name,
        description
    FROM service_catalog
    WHERE id IN ($servicePlaceholders)
      AND is_active = 1
    ORDER BY sort_order ASC, id ASC
");

if (!$stmt) {
    die('Unable to load selected services.');
}

$serviceValues = $service_ids;
$serviceRefs = [$serviceBindTypes];

foreach ($serviceValues as $key => $value) {
    $serviceRefs[] = &$serviceValues[$key];
}

call_user_func_array(
    [$stmt, 'bind_param'],
    $serviceRefs
);

$stmt->execute();

$serviceResult = $stmt->get_result();

$services = [];

while ($row = $serviceResult->fetch_assoc()) {
    $services[] = $row;
}

$stmt->close();

if (count($services) !== count($service_ids)) {
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

/*
 * First service is retained as the legacy/primary service.
 * All selected services are stored in service_request_services
 * during submission below.
 */
$service_id = (int)$services[0]['id'];
$service = $services[0];


/* =========================================================
   SELECTED ASSETS
========================================================= */

$selectedAssetIds =
    $_SESSION[
        'phase4_asset_ids'
    ]
    ??
    [];


if (
    !is_array(
        $selectedAssetIds
    )
) {

    $selectedAssetIds =
        [];
}


$selectedAssetIds =
    array_values(
        array_unique(
            array_filter(
                array_map(
                    'intval',
                    $selectedAssetIds
                ),
                function ($id) {

                    return $id > 0;

                }
            )
        )
    );


if (
    !$selectedAssetIds
) {

    header(
        "Location: service-lifts.php"
    );

    exit;
}


/* =========================================================
   LOAD SERVICE
========================================================= */

$stmt =
    $conn->prepare("
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


if (!$stmt) {

    die(
        'Unable to load service.'
    );
}


$stmt->bind_param(
    "i",
    $service_id
);


$stmt->execute();


$service =
    $stmt
        ->get_result()
        ->fetch_assoc();


$stmt->close();


if (
    !$service
) {

    unset(
        $_SESSION[
            'phase4_service_id'
        ],
        $_SESSION[
            'phase4_asset_ids'
        ]
    );


    header(
        "Location: service-selection.php"
    );

    exit;
}


$service_code =
    strtoupper(
        trim(
            (string)
            $service['service_code']
        )
    );

/* =========================================================
   LOAD SELECTED ASSETS
========================================================= */

$placeholders =
    implode(
        ',',
        array_fill(
            0,
            count(
                $selectedAssetIds
            ),
            '?'
        )
    );


$types =
    str_repeat(
        'i',
        count(
            $selectedAssetIds
        )
    );


$sql = "
    SELECT
        id,
        display_asset_id,
        asset_name,
        asset_type,
        category,
        location,
        status
    FROM assets
    WHERE owner_id = ?
      AND id IN ($placeholders)
    ORDER BY
        display_asset_id ASC,
        id ASC
";


$stmt =
    $conn->prepare(
        $sql
    );


if (!$stmt) {

    die(
        'Unable to load selected assets.'
    );
}


$bindTypes =
    'i' .
    $types;


$bindValues =
    array_merge(
        [$owner_id],
        $selectedAssetIds
    );


$refs =
    [$bindTypes];


foreach (
    $bindValues
    as $key => $value
) {

    $refs[] =
        &$bindValues[$key];
}


call_user_func_array(
    [
        $stmt,
        'bind_param'
    ],
    $refs
);


$stmt->execute();


$assets = [];


$result =
    $stmt
        ->get_result();


while (
    $row =
    $result->fetch_assoc()
) {

    $assets[] =
        $row;
}


$stmt->close();


if (
    count($assets) !==
    count($selectedAssetIds)
) {

    unset(
        $_SESSION[
            'phase4_asset_ids'
        ]
    );


    header(
        "Location: service-lifts.php"
    );

    exit;
}


/* =========================================================
   FORM
========================================================= */

$error = '';

$requirements =
    trim(
        (string)(
            $_POST[
                'requirements'
            ]
            ??
            ''
        )
    );


/* =========================================================
   SUBMIT
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
) {

    $postedToken =
        $_POST[
            'csrf_token'
        ]
        ??
        '';


    /* =====================================================
       CSRF
    ===================================================== */

    if (
        !$postedToken
        ||
        !hash_equals(
            $csrfToken,
            $postedToken
        )
    ) {

        $error =
            'Security validation failed. Please try again.';

    }


    /* =====================================================
       REQUIREMENTS LENGTH
    ===================================================== */

    elseif (
        strlen(
            $requirements
        ) > 5000
    ) {

        $error =
            'Requirements / Remarks cannot exceed 5000 characters.';

    }


    /* =====================================================
       DATABASE TRANSACTION
    ===================================================== */

    else {

        $conn->begin_transaction();


        try {

            /* =============================================
               VERIFY OWNER ASSET OWNERSHIP
            ============================================= */

            $check =
                $conn->prepare("
                    SELECT id
                    FROM assets
                    WHERE owner_id = ?
                      AND id = ?
                    LIMIT 1
                ");


            if (!$check) {

                throw new Exception(
                    'Unable to verify asset ownership.'
                );
            }


            foreach (
                $selectedAssetIds
                as $assetId
            ) {

                $check->bind_param(
                    "ii",
                    $owner_id,
                    $assetId
                );


                $check->execute();


                if (
                    !$check
                        ->get_result()
                        ->fetch_assoc()
                ) {

                    throw new Exception(
                        'One or more selected assets are no longer available.'
                    );
                }
            }


            $check->close();


            /* =============================================
               CREATE REQUEST
               ------------------------------------------------
               service_requests.service_id is retained as the
               primary/legacy service. Every selected service
               is also stored in service_request_services.
            ============================================= */

            $submittedAt =
                date(
                    'Y-m-d H:i:s'
                );


            $temporaryRequestNo =
                'TEMP-' .
                bin2hex(
                    random_bytes(8)
                );


            $insert =
                $conn->prepare("
                    INSERT INTO service_requests
                    (
                        request_no,
                        owner_id,
                        service_id,
                        requirements,
                        status,
                        submitted_at
                    )
                    VALUES
                    (
                        ?,
                        ?,
                        ?,
                        ?,
                        'Submitted',
                        ?
                    )
                ");


            if (!$insert) {

                throw new Exception(
                    'Unable to prepare Service Request.'
                );
            }


            $insert->bind_param(
                "siiss",
                $temporaryRequestNo,
                $owner_id,
                $service_id,
                $requirements,
                $submittedAt
            );


            if (
                !$insert->execute()
            ) {

                throw new Exception(
                    'Unable to create service request: ' .
                    $insert->error
                );
            }


            $requestId =
                (int)$conn->insert_id;


            $insert->close();


            /* =============================================
               STORE ALL SELECTED SERVICES
            ============================================= */

            $createServiceLinkTable = $conn->query("
                CREATE TABLE IF NOT EXISTS service_request_services (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    request_id INT NOT NULL,
                    service_id INT NOT NULL,
                    created_at DATETIME NOT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_request_service (request_id, service_id),
                    KEY idx_request_id (request_id),
                    KEY idx_service_id (service_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            if (!$createServiceLinkTable) {
                throw new Exception(
                    'Unable to prepare multiple-service storage: ' .
                    $conn->error
                );
            }

            $serviceLink =
                $conn->prepare("
                    INSERT INTO service_request_services
                    (
                        request_id,
                        service_id,
                        created_at
                    )
                    VALUES (?, ?, ?)
                ");

            if (!$serviceLink) {
                throw new Exception(
                    'Unable to prepare selected service attachment.'
                );
            }

            foreach ($service_ids as $selectedServiceId) {

                $serviceLink->bind_param(
                    "iis",
                    $requestId,
                    $selectedServiceId,
                    $submittedAt
                );

                if (!$serviceLink->execute()) {
                    throw new Exception(
                        'Unable to attach selected service: ' .
                        $serviceLink->error
                    );
                }
            }

            $serviceLink->close();


            if (
                $requestId <= 0
            ) {

                throw new Exception(
                    'Invalid Service Request ID.'
                );
            }


            /* =============================================
               REQUEST NUMBER
            ============================================= */

            $requestNo =
                'SR-' .
                str_pad(
                    (string)$requestId,
                    4,
                    '0',
                    STR_PAD_LEFT
                );


            $update =
                $conn->prepare("
                    UPDATE service_requests
                    SET request_no = ?
                    WHERE id = ?
                    LIMIT 1
                ");


            if (!$update) {

                throw new Exception(
                    'Unable to prepare request number update.'
                );
            }


            $update->bind_param(
                "si",
                $requestNo,
                $requestId
            );


            if (
                !$update->execute()
            ) {

                throw new Exception(
                    'Unable to generate Service Request Number.'
                );
            }


            $update->close();


            /* =============================================
               ATTACH ASSETS
            ============================================= */

            $attach =
                $conn->prepare("
                    INSERT INTO
                        service_request_assets
                    (
                        request_id,
                        asset_id
                    )
                    VALUES
                    (
                        ?,
                        ?
                    )
                ");


            if (!$attach) {

                throw new Exception(
                    'Unable to prepare asset attachment.'
                );
            }


            foreach (
                $selectedAssetIds
                as $assetId
            ) {

                $attach->bind_param(
                    "ii",
                    $requestId,
                    $assetId
                );


                if (
                    !$attach->execute()
                ) {

                    throw new Exception(
                        'Unable to attach selected asset: ' .
                        $attach->error
                    );
                }
            }


            $attach->close();


            /* =============================================
               COMMIT DATABASE
            ============================================= */

            $conn->commit();


            /* =================================================
               DATABASE IS NOW SUCCESSFULLY SAVED
               
               IMPORTANT:
               Email is sent AFTER commit.
               
               Therefore an email failure will NOT rollback
               the Service Request.
            ================================================== */


            /* =============================================
               GET OWNER DETAILS
            ============================================= */

            $ownerName =
                '';

            $ownerEmail =
                '';


            $ownerStmt =
                $conn->prepare("
                    SELECT
                        name,
                        email
                    FROM users
                    WHERE id = ?
                    LIMIT 1
                ");


            if (
                $ownerStmt
            ) {

                $ownerStmt->bind_param(
                    "i",
                    $owner_id
                );


                $ownerStmt->execute();


                $ownerRow =
                    $ownerStmt
                        ->get_result()
                        ->fetch_assoc();


                $ownerStmt->close();


                if (
                    $ownerRow
                ) {

                    $ownerName =
                        trim(
                            (string)(
                                $ownerRow['name']
                                ??
                                ''
                            )
                        );


                    $ownerEmail =
                        trim(
                            (string)(
                                $ownerRow['email']
                                ??
                                ''
                            )
                        );
                }
            }


            /* =============================================
               SEND ADMIN EMAIL
            ============================================= */

            $adminEmailSent =
                sendNletaServiceRequestAdminEmail(
                    $requestId,
                    $requestNo,
                    $ownerName,
                    $ownerEmail,
                    $service['service_name'],
                    $submittedAt,
                    count(
                        $selectedAssetIds
                    )
                );


            /* =============================================
               SUCCESS SESSION
            ============================================= */

            $_SESSION[
                'phase4_completed_request_id'
            ] =
                $requestId;


            $_SESSION[
                'phase4_completed_request_no'
            ] =
                $requestNo;


            $_SESSION[
                'phase4_completed_at'
            ] =
                $submittedAt;


            $_SESSION[
                'phase4_completed_service'
            ] =
                $service['service_name'];


            /*
             * Store email result so the success page can
             * optionally display it.
             */

            $_SESSION[
                'phase4_admin_email_sent'
            ] =
                $adminEmailSent;


            /* =============================================
               CLEAR WORKFLOW SESSION
            ============================================= */

            unset(
                $_SESSION['phase4_asset_ids'],
                $_SESSION['phase4_service_ids'],
                $_SESSION['phase4_service_codes'],
                $_SESSION['phase4_service_names'],
                $_SESSION['phase4_service_id'],
                $_SESSION['phase4_service_code'],
                $_SESSION['phase4_service_name']
            );


            /* =============================================
               SUCCESS
            ============================================= */

            header(
                "Location: service-request-success.php"
            );

            exit;

        }


        catch (
            Throwable $e
        ) {

            /*
             * Only rollback if transaction is still active.
             */

            try {

                $conn->rollback();

            } catch (
                Throwable $rollbackError
            ) {

                /* Ignore rollback error. */

            }


            $error =
                $e->getMessage();
        }
    }
}


include(
    '../includes/header.php'
);


include(
    '../includes/sidebar-owner.php'
);

?>

<style>

.phase4-wrapper {

    max-width: 1100px;

    margin: auto;
}


.head,
.cardx,
.submit-box {

    background: #fff;

    border-radius: 14px;

    padding: 22px;

    box-shadow:
        0 2px 10px
        rgba(0,0,0,.08);

    margin-bottom: 20px;
}


.steps {

    display: flex;

    gap: 10px;

    align-items: center;

    flex-wrap: wrap;

    margin-top: 20px;
}


.step {

    display: flex;

    align-items: center;

    gap: 8px;

    color: #6c757d;
}


.step.done {

    color: #198754;

    font-weight: 600;
}


.step.active {

    color: #0d6efd;

    font-weight: 600;
}


.num {

    width: 32px;

    height: 32px;

    border-radius: 50%;

    display: flex;

    align-items: center;

    justify-content: center;

    background: #e9ecef;
}


.done .num {

    background: #198754;

    color: #fff;
}


.active .num {

    background: #0d6efd;

    color: #fff;
}


.asset-row {

    border:
        1px solid #e5e7eb;

    border-radius: 12px;

    padding: 16px;

    margin-bottom: 12px;
}


.asset-id {

    font-weight: 700;

    font-size: 16px;
}


.meta {

    font-size: 13px;

    color: #6c757d;
}

</style>


<div class="content">


<div class="phase4-wrapper">


<!-- =====================================================
     HEADER
===================================================== -->

<div class="head">


<div
    class="
        d-flex
        justify-content-between
        align-items-start
    "
>


<div>


<h3>

    <i
        class="bi bi-clipboard-check me-2"
    ></i>

    Step 3 — Review & Submit

</h3>


<p class="text-muted mb-0">

    Review your Service + Lift / Escalator
    assets before submitting.

</p>


</div>


<div class="text-end">


<small class="text-muted">

    Current Date & Time

</small>


<div class="fw-semibold">

    <?= htmlspecialchars(
        date(
            'd-m-Y h:i A'
        )
    ) ?>

</div>


</div>


</div>


<!-- STEPS -->

<div class="steps">


<div class="step done">

<span class="num">

    ✓

</span>

Select Service

</div>


<span>→</span>


<div class="step done">

<span class="num">

    ✓

</span>

Add Assets

</div>


<span>→</span>


<div class="step active">

<span class="num">

    3

</span>

Review & Submit

</div>


</div>


</div>


<?php if ($error): ?>


<div class="alert alert-danger">

<i
    class="bi bi-exclamation-triangle-fill me-2"
></i>


<?= htmlspecialchars(
    $error
) ?>


</div>


<?php endif; ?>


<!-- =====================================================
     SERVICE
===================================================== -->

<div class="cardx">

<h5>
    <i class="bi bi-clipboard2-check me-2"></i>
    Selected Services
</h5>

<div class="mt-3">

    <?php foreach ($services as $selectedService): ?>

        <div class="border rounded p-3 mb-2">

            <div>
                <span class="badge bg-primary">
                    <?= htmlspecialchars(
                        $selectedService['service_code']
                    ) ?>
                </span>

                <strong class="ms-2">
                    <?= htmlspecialchars(
                        $selectedService['service_name']
                    ) ?>
                </strong>
            </div>

            <?php if (!empty($selectedService['description'])): ?>

                <div class="text-muted small mt-2">
                    <?= nl2br(
                        htmlspecialchars(
                            $selectedService['description']
                        )
                    ) ?>
                </div>

            <?php endif; ?>

        </div>

    <?php endforeach; ?>

</div>

</div>


<!-- =====================================================
     SELECTED ASSETS
===================================================== -->

<div class="cardx">


<div
    class="
        d-flex
        justify-content-between
        align-items-center
        mb-3
    "
>


<h5 class="mb-0">

    <i
        class="bi bi-buildings me-2"
    ></i>

    Selected Assets

</h5>


<span class="badge bg-primary">

    <?= count(
        $assets
    ) ?>


    <?= count(
        $assets
    ) === 1
        ? 'Asset'
        : 'Assets'
    ?>


</span>


</div>


<?php foreach (
    $assets
    as $asset
): ?>


<div class="asset-row">


<div
    class="
        d-flex
        justify-content-between
        align-items-start
        gap-3
    "
>


<div>


<div class="asset-id">

    <?= htmlspecialchars(
        $asset[
            'display_asset_id'
        ]
    ) ?>

</div>


<div>

    <?= htmlspecialchars(
        $asset[
            'asset_name'
        ]
    ) ?>

</div>


<div class="meta mt-1">

    Location:

    <?= htmlspecialchars(
        $asset[
            'location'
        ]
        ?: '—'
    ) ?>

</div>


<div class="meta">

    Category:

    <?= htmlspecialchars(
        $asset[
            'category'
        ]
        ?: '—'
    ) ?>

</div>


<div class="meta">

    Status:

    <?= htmlspecialchars(
        $asset[
            'status'
        ]
        ?: '—'
    ) ?>

</div>


</div>


<span
    class="
        badge
        <?= strtolower(
            $asset[
                'asset_type'
            ]
        ) === 'escalator'
            ? 'bg-warning text-dark'
            : 'bg-primary'
        ?>
    "
>


<?= htmlspecialchars(
    $asset[
        'asset_type'
    ]
) ?>


</span>


</div>


</div>


<?php endforeach; ?>


<a
    href="service-lifts.php"
    class="btn btn-outline-secondary btn-sm"
>


<i
    class="bi bi-pencil me-1"
></i>


Change Assets


</a>


</div>


<!-- =====================================================
     REQUIREMENTS
===================================================== -->

<form
    method="POST"
    id="reviewForm"
>


<input
    type="hidden"
    name="csrf_token"
    value="<?= htmlspecialchars(
        $csrfToken
    ) ?>"
>


<div class="cardx">


<h5>


<i
    class="bi bi-chat-left-text me-2"
></i>


Requirements / Remarks


<span
    class="text-muted small"
>


(Optional)


</span>


</h5>


<textarea
    name="requirements"
    id="requirements"
    class="form-control mt-3"
    maxlength="5000"
    rows="6"
    placeholder="Enter requirements, site information, special instructions or remarks..."
><?= htmlspecialchars(
    $requirements
) ?></textarea>


<div
    class="
        text-end
        text-muted
        small
        mt-2
    "
    id="requirementsCount"
>


<?= strlen(
    $requirements
) ?>


/ 5000


</div>


</div>


<!-- =====================================================
     SUBMIT
===================================================== -->

<div
    class="
        submit-box
        d-flex
        justify-content-between
        align-items-center
        gap-3
    "
>


<div class="text-muted small">


<strong>

    Ready to submit?

</strong>


<br>


Your Service Request will be sent
for Admin review.


</div>


<div class="d-flex gap-2">


<a
    href="service-lifts.php"
    class="btn btn-outline-secondary"
>

    Back

</a>


<button
    type="submit"
    class="btn btn-primary px-4"
    id="submitBtn"
>


<i
    class="bi bi-send me-1"
></i>


Submit Service Request


</button>


</div>


</div>


</form>


</div>


</div>


<?php

include(
    '../includes/footer.php'
);

?>


<script>

document.addEventListener(
    'DOMContentLoaded',
    function () {


        const textarea =
            document.getElementById(
                'requirements'
            );


        const counter =
            document.getElementById(
                'requirementsCount'
            );


        const form =
            document.getElementById(
                'reviewForm'
            );


        const button =
            document.getElementById(
                'submitBtn'
            );


        /* =====================================================
           CHARACTER COUNT
        ===================================================== */

        if (
            textarea &&
            counter
        ) {

            textarea.addEventListener(
                'input',
                function () {

                    counter.textContent =
                        textarea.value.length +
                        ' / 5000';

                }
            );

        }


        /* =====================================================
           SUBMIT CONFIRMATION
        ===================================================== */

        if (
            form
        ) {

            form.addEventListener(
                'submit',
                function (event) {


                    const confirmed =
                        confirm(
                            'Are you sure you want to submit this Service Request?'
                        );


                    if (
                        !confirmed
                    ) {

                        event.preventDefault();

                        return;
                    }


                    if (
                        button
                    ) {

                        button.disabled =
                            true;


                        button.innerHTML =
                            '<span class="spinner-border spinner-border-sm me-1"></span>' +
                            'Submitting...';

                    }

                }
            );

        }

    }
);

</script>