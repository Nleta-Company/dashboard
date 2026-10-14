<?php
/* =========================================================
   NLETA — PHASE 4
   OWNER — SERVICE REQUEST SUCCESS
========================================================= */

include('../config/db.php');
include('../config/auth.php');
require_role('owner');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$owner_id =
    (int)($_SESSION['uid'] ?? 0);

if ($owner_id <= 0) {
    header("Location: ../login.php");
    exit;
}

date_default_timezone_set('Asia/Kolkata');


/* =========================================================
   SUCCESS DATA
========================================================= */

/*
 * Refresh-safe request ID:
 * - First load: take the completed request ID from session.
 * - Refresh: use the request_id already placed in the URL.
 */
$requestId =
    (int)(
        $_GET['request_id']
        ?? $_SESSION[
            'phase4_completed_request_id'
        ]
        ?? 0
    );


$requestNo =
    $_SESSION[
        'phase4_completed_request_no'
    ] ?? '';


$completedAt =
    $_SESSION[
        'phase4_completed_at'
    ] ?? '';


$serviceName =
    $_SESSION[
        'phase4_completed_service'
    ] ?? '';


/* =========================================================
   SERVICE TYPE
========================================================= */

$serviceCode =
    '';


if ($requestId > 0) {

    /*
     * serviceCode is loaded again from the request below.
     * Keep this variable available for the SAT-specific UI.
     */
}


if ($requestId <= 0) {

    header(
        "Location: service-selection.php"
    );

    exit;
}


/* =========================================================
   LOAD REQUEST
========================================================= */

$request = null;


$stmt =
    $conn->prepare("
        SELECT
            sr.id,
            sr.request_no,
            sr.owner_id,
            sr.service_id,
            sr.requirements,
            sr.status,
            sr.submitted_at,
            sc.service_code,
            sc.service_name,
            sc.description
        FROM service_requests sr

        INNER JOIN service_catalog sc
            ON sc.id = sr.service_id

        WHERE sr.id = ?
          AND sr.owner_id = ?

        LIMIT 1
    ");


$stmt->bind_param(
    "ii",
    $requestId,
    $owner_id
);


$stmt->execute();


$request =
    $stmt
        ->get_result()
        ->fetch_assoc();


$stmt->close();


if (!$request) {

    header(
        "Location: service-selection.php"
    );

    exit;
}


$serviceCode =
    strtoupper(
        trim(
            (string)(
                $request['service_code']
                ?? ''
            )
        )
    );


$isSAT =
    ($serviceCode === 'SAT');


/* =========================================================
   LOAD ALL SERVICES IN THIS REQUEST
========================================================= */

$requestServices = [];

$serviceListStmt = $conn->prepare("
    SELECT
        sc.id,
        sc.service_code,
        sc.service_name,
        sc.description
    FROM service_request_services srs
    INNER JOIN service_catalog sc
        ON sc.id = srs.service_id
    WHERE srs.request_id = ?
    ORDER BY srs.id ASC
");

if ($serviceListStmt) {

    $serviceListStmt->bind_param(
        "i",
        $requestId
    );

    $serviceListStmt->execute();

    $serviceListResult =
        $serviceListStmt->get_result();

    while (
        $serviceRow =
        $serviceListResult->fetch_assoc()
    ) {
        $requestServices[] = $serviceRow;
    }

    $serviceListStmt->close();
}

/*
 * Backward compatibility:
 * Old requests may not have service_request_services.
 * Use service_requests.service_id in that case.
 */
if (empty($requestServices)) {

    $requestServices[] = [
        'id' =>
            (int)$request['service_id'],

        'service_code' =>
            $request['service_code'],

        'service_name' =>
            $request['service_name'],

        'description' =>
            $request['description']
    ];
}


/* =========================================================
   LOAD REQUEST ASSETS
========================================================= */

$assets = [];


$stmt =
    $conn->prepare("
        SELECT
            a.id,
            a.display_asset_id,
            a.asset_name,
            a.asset_type,
            a.category,
            a.location,
            a.status
        FROM service_request_assets sra

        INNER JOIN assets a
            ON a.id = sra.asset_id

        WHERE sra.request_id = ?
          AND a.owner_id = ?

        ORDER BY
            a.display_asset_id ASC,
            a.id ASC
    ");


$stmt->bind_param(
    "ii",
    $requestId,
    $owner_id
);


$stmt->execute();


$result =
    $stmt->get_result();


while (
    $row =
    $result->fetch_assoc()
) {

    $assets[] =
        $row;
}


$stmt->close();


/* =========================================================
   MAKE SUCCESS PAGE REFRESH-SAFE
   ---------------------------------------------------------
   On the first load, the request ID comes from the session.
   Redirect once to the same page with request_id in the URL.
   After that, refreshes use the URL request_id and do not
   depend on temporary session data.
========================================================= */

if (
    empty($_GET['request_id']) &&
    $requestId > 0
) {

    $successPage =
        basename($_SERVER['PHP_SELF']);

    header(
        "Location: {$successPage}?request_id=" .
        urlencode((string)$requestId)
    );

    exit;
}


/* =========================================================
   CLEAR ONLY TEMPORARY SUCCESS SESSION DATA
   ---------------------------------------------------------
   The page itself now has request_id in the URL, so a
   browser refresh will remain on this success page.
========================================================= */

unset(
    $_SESSION[
        'phase4_completed_request_id'
    ],
    $_SESSION[
        'phase4_completed_request_no'
    ],
    $_SESSION[
        'phase4_completed_at'
    ],
    $_SESSION[
        'phase4_completed_service'
    ]
);


include('../includes/header.php');
include('../includes/sidebar-owner.php');
?>

<style>

.success-wrapper {

    max-width: 1000px;

    margin: auto;
}

.success-card {

    background: #fff;

    border-radius: 16px;

    padding: 35px;

    box-shadow:
        0 3px 15px
        rgba(0,0,0,.08);

    text-align: center;
}

.success-icon {

    width: 80px;

    height: 80px;

    border-radius: 50%;

    background: #d1e7dd;

    color: #198754;

    display: flex;

    align-items: center;

    justify-content: center;

    font-size: 42px;

    margin:
        0 auto 20px;
}

.request-number {

    font-size: 28px;

    font-weight: 800;

    color: #0d6efd;

    margin: 15px 0;
}

.info-card {

    background: #fff;

    border-radius: 14px;

    padding: 22px;

    box-shadow:
        0 2px 10px
        rgba(0,0,0,.08);

    margin-top: 20px;

    text-align: left;
}

.asset-row {

    border:
        1px solid #e5e7eb;

    border-radius: 12px;

    padding: 15px;

    margin-bottom: 10px;
}

.asset-id {

    font-weight: 700;
}

.meta {

    font-size: 13px;

    color: #6c757d;
}

.workflow {

    display: flex;

    align-items: center;

    justify-content: center;

    flex-wrap: wrap;

    gap: 10px;

    margin-top: 25px;
}

.workflow-step {

    background: #f8f9fa;

    border:
        1px solid #dee2e6;

    border-radius: 10px;

    padding:
        10px 14px;

    font-size: 13px;

}

.workflow-step.active {

    background: #eaf2ff;

    border-color: #0d6efd;

    color: #0d6efd;

    font-weight: 700;
}

</style>


<div class="content">

<div class="success-wrapper">


<!-- =====================================================
     SUCCESS
===================================================== -->

<div class="success-card">

<div class="success-icon">

    <i class="bi bi-check-lg"></i>

</div>


<h2>

    <?= $isSAT
        ? 'SAT Service Request Submitted'
        : 'Service Request Submitted' ?>

</h2>


<p class="text-muted">

    <?=
        $isSAT
            ? 'Your SAT request has been successfully submitted after PAT completion.'
            : 'Your Service Request has been successfully submitted.'
    ?>

</p>


<div class="request-number">

    <?= htmlspecialchars(
        $request['request_no']
    ) ?>

</div>


<div>

<span class="badge bg-success">

    <?= htmlspecialchars(
        $request['status']
    ) ?>

</span>

</div>


<div class="text-muted small mt-3">

Submitted on:

<?= htmlspecialchars(
    date(
        'd-m-Y h:i A',
        strtotime(
            $request['submitted_at']
        )
    )
) ?>

</div>


<!-- =====================================================
     WORKFLOW
===================================================== -->

<div class="workflow">

<div class="workflow-step">

    ✓ Service Selected

</div>

<span>→</span>

<div class="workflow-step">

    ✓ Assets Added

</div>

<span>→</span>

<div class="workflow-step">

    ✓ Review Submitted

</div>

<span>→</span>

<div class="workflow-step active">

    <?= $isSAT
        ? 'SAT Offer Generator'
        : 'Offer Generator' ?>

</div>

</div>

</div>


<!-- =====================================================
     REQUEST DETAILS
===================================================== -->

<div class="info-card">

<h5>

    <i
        class="bi bi-file-earmark-text me-2"
    ></i>

    Service Request Details

</h5>


<div class="row g-3 mt-2">


<div class="col-md-6">

<div class="text-muted small">

    Request Number

</div>

<strong>

    <?= htmlspecialchars(
        $request['request_no']
    ) ?>

</strong>

</div>


<div class="col-md-6">

<div class="text-muted small">

    Services

</div>

<div class="mt-1">

    <?php foreach ($requestServices as $requestService): ?>

        <div class="mb-1">

            <span class="badge bg-primary">
                <?= htmlspecialchars(
                    $requestService['service_code']
                ) ?>
            </span>

            <strong class="ms-1">
                <?= htmlspecialchars(
                    $requestService['service_name']
                ) ?>
            </strong>

        </div>

    <?php endforeach; ?>

</div>

</div>


<div class="col-md-6">

<div class="text-muted small">

    Status

</div>

<strong>

    <?= htmlspecialchars(
        $request['status']
    ) ?>

</strong>

</div>


<div class="col-md-6">

<div class="text-muted small">

    Submitted

</div>

<strong>

    <?= htmlspecialchars(
        date(
            'd-m-Y h:i A',
            strtotime(
                $request['submitted_at']
            )
        )
    ) ?>

</strong>

</div>

</div>


<?php if (
    !empty($request['requirements'])
): ?>

<hr>

<div class="text-muted small">

    Requirements / Remarks

</div>

<div class="mt-2">

    <?= nl2br(
        htmlspecialchars(
            $request['requirements']
        )
    ) ?>

</div>

<?php endif; ?>

</div>


<!-- =====================================================
     SELECTED ASSETS
===================================================== -->

<div class="info-card">

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

    Selected Lift / Escalator Assets

</h5>


<span class="badge bg-primary">

    <?= count($assets) ?>

    <?= count($assets) === 1
        ? 'Asset'
        : 'Assets'
    ?>

</span>

</div>


<?php if (!$assets): ?>

<div class="alert alert-warning">

    No assets were found for this request.

</div>

<?php else: ?>


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
        $asset['display_asset_id']
    ) ?>

</div>

<div>

    <?= htmlspecialchars(
        $asset['asset_name']
    ) ?>

</div>

<div class="meta mt-1">

    Location:

    <?= htmlspecialchars(
        $asset['location']
        ?: '—'
    ) ?>

</div>

<div class="meta">

    Category:

    <?= htmlspecialchars(
        $asset['category']
        ?: '—'
    ) ?>

</div>

<div class="meta">

    Status:

    <?= htmlspecialchars(
        $asset['status']
        ?: '—'
    ) ?>

</div>

</div>


<span
    class="
        badge
        <?= strtolower(
            $asset['asset_type']
        ) === 'escalator'
            ? 'bg-warning text-dark'
            : 'bg-primary'
        ?>
    "
>

    <?= htmlspecialchars(
        $asset['asset_type']
    ) ?>

</span>

</div>

</div>

<?php endforeach; ?>

<?php endif; ?>

</div>


<!-- =====================================================
     NEXT PHASE
===================================================== -->

<div class="info-card">

<div
    class="
        alert
        alert-info
        mb-0
    "
>

<i
    class="bi bi-info-circle me-2"
></i>

<strong>Next Phase:</strong>

<?php if ($isSAT): ?>

Your SAT Service Request will proceed to

<strong>

    Offer Generator.

</strong>

The Admin will process the SAT request
and generate the commercial offer.

<?php else: ?>

Your Service Request will proceed to

<strong>

    Offer Generator.

</strong>

The Admin will process the request
and generate the commercial offer.

<?php endif; ?>

</div>

</div>


<!-- =====================================================
     ACTIONS
===================================================== -->

<div
    class="
        d-flex
        justify-content-center
        gap-2
        flex-wrap
        mt-4
    "
>

<a
    href="../owner/dashboard.php"
    class="btn btn-primary"
>

    <i
        class="bi bi-speedometer2 me-1"
    ></i>

    Go to Owner Dashboard

</a>


<a
    href="service-selection.php"
    class="btn btn-outline-primary"
>

    <i
        class="bi bi-plus-circle me-1"
    ></i>

    New Service Request

</a>

</div>


</div>

</div>


<?php include('../includes/footer.php'); ?>