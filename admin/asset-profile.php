<?php
include('../config/db.php');
include('../config/auth.php');

require_role('admin');

include('../includes/header.php');
include('../includes/sidebar-admin.php');


/* =========================================================
   GET ASSET
========================================================= */

$asset_id = (int)($_GET['id'] ?? 0);

if ($asset_id <= 0) {
    header("Location: assets.php");
    exit;
}


/* =========================================================
   FETCH ASSET
========================================================= */

$stmt = $conn->prepare("
    SELECT
        a.*,
        u.name AS owner_name
    FROM assets a
    LEFT JOIN users u
        ON u.id = a.owner_id
    WHERE a.id = ?
    LIMIT 1
");

$stmt->bind_param("i", $asset_id);
$stmt->execute();

$asset = $stmt->get_result()->fetch_assoc();


if (!$asset) {

    echo "
    <div class='content'>
        <div class='alert alert-danger'>
            Asset not found.
        </div>

        <a href='assets.php' class='btn btn-secondary'>
            ← Back to Assets
        </a>
    </div>
    ";

    include('../includes/footer.php');
    exit;
}


/* =========================================================
   STATUS BADGE
========================================================= */

$badge = match($asset['status'] ?? '') {

    'Active'
        => 'success',

    'Under Maintenance'
        => 'warning',

    'Out of Service'
        => 'danger',

    default
        => 'secondary'
};


/* =========================================================
   MAINTENANCE HISTORY
========================================================= */

$maintenanceStmt = $conn->prepare("
    SELECT
        maintenance_date,
        status,
        remarks
    FROM maintenance_records
    WHERE asset_id = ?
    ORDER BY maintenance_date DESC
");

$maintenanceStmt->bind_param("i", $asset_id);
$maintenanceStmt->execute();

$maintenance = $maintenanceStmt->get_result();


/* =========================================================
   CALLBACK HISTORY
========================================================= */

$callbackStmt = $conn->prepare("
    SELECT
        issue,
        status,
        created_at
    FROM callbacks
    WHERE asset_id = ?
    ORDER BY id DESC
");

$callbackStmt->bind_param("i", $asset_id);
$callbackStmt->execute();

$callbacks = $callbackStmt->get_result();


/* =========================================================
   INSURANCE
========================================================= */

$insurance = null;

$insuranceStmt = $conn->prepare("
    SELECT *
    FROM insurance
    WHERE asset_id = ?
    ORDER BY id DESC
    LIMIT 1
");

$insuranceStmt->bind_param("i", $asset_id);
$insuranceStmt->execute();

$insurance = $insuranceStmt->get_result()->fetch_assoc();


/* =========================================================
   LAST MAINTENANCE
========================================================= */

$lastMaintenanceStmt = $conn->prepare("
    SELECT MAX(maintenance_date) AS last_date
    FROM maintenance_records
    WHERE asset_id = ?
");

$lastMaintenanceStmt->bind_param("i", $asset_id);
$lastMaintenanceStmt->execute();

$lastMaintenance = $lastMaintenanceStmt
    ->get_result()
    ->fetch_assoc();

$lastMaintenanceDate = $lastMaintenance['last_date'] ?? null;


/* =========================================================
   NEXT MAINTENANCE
========================================================= */

$nextMaintenanceDate = null;

if (
    !empty($lastMaintenanceDate) &&
    !empty($asset['maintenance_interval'])
) {

    try {

        $nextDate = new DateTime($lastMaintenanceDate);

        $nextDate->modify(
            '+' . (int)$asset['maintenance_interval'] . ' days'
        );

        $nextMaintenanceDate = $nextDate->format('Y-m-d');

    } catch (Exception $e) {

        $nextMaintenanceDate = null;
    }
}


/* =========================================================
   DISPLAY FORMAT
========================================================= */

function formatDateDisplay($date)
{
    if (empty($date)) {
        return '—';
    }

    try {

        return (new DateTime($date))
            ->format('d M Y');

    } catch (Exception $e) {

        return htmlspecialchars($date);
    }
}

?>

<div class="content">


<!-- =========================================================
     HEADER
========================================================= -->

<div class="d-flex justify-content-between align-items-center mb-4">

    <div>

        <h3 class="mb-1">
            Asset Profile
        </h3>

        <div class="text-muted">

            Asset:

            <strong>
                <?= htmlspecialchars(
                    $asset['asset_name'] ?? '—'
                ) ?>
            </strong>

        </div>

    </div>


    <div class="d-flex gap-2">

        <a
            href="edit-asset.php?id=<?= (int)$asset['id'] ?>"
            class="btn btn-primary">

            ✏️ Edit Asset

        </a>

        <a
            href="assets.php"
            class="btn btn-secondary">

            ← Back

        </a>

    </div>

</div>


<!-- =========================================================
     MAIN ASSET INFORMATION
========================================================= -->

<div class="card shadow-sm mb-4">

    <div class="card-header">

        <h5 class="mb-0">
            Asset Information
        </h5>

    </div>


    <div class="card-body">

        <div class="row g-4">


            <!-- DISPLAY ASSET ID -->

            <div class="col-md-4">

                <small class="text-muted">
                    Asset ID
                </small>

                <div class="fs-5 fw-bold text-primary">

                    <?= !empty($asset['display_asset_id'])
                        ? htmlspecialchars($asset['display_asset_id'])
                        : '—'
                    ?>

                </div>

            </div>


            <!-- DATABASE ID -->

            <div class="col-md-4">

                <small class="text-muted">
                    System Asset ID
                </small>

                <div class="fw-bold">

                    <?= (int)$asset['id'] ?>

                </div>

            </div>


            <!-- ASSET NAME -->

            <div class="col-md-4">

                <small class="text-muted">
                    Asset Name
                </small>

                <div class="fw-bold">

                    <?= htmlspecialchars(
                        $asset['asset_name'] ?? '—'
                    ) ?>

                </div>

            </div>


            <!-- OWNER -->

            <div class="col-md-4">

                <small class="text-muted">
                    Owner
                </small>

                <div>

                    <?= htmlspecialchars(
                        $asset['owner_name'] ?? '—'
                    ) ?>

                </div>

            </div>


            <!-- TYPE -->

            <div class="col-md-4">

                <small class="text-muted">
                    Asset Type
                </small>

                <div>

                    <?= htmlspecialchars(
                        $asset['asset_type'] ?? '—'
                    ) ?>

                </div>

            </div>


            <!-- CATEGORY -->

            <div class="col-md-4">

                <small class="text-muted">
                    Category
                </small>

                <div>

                    <?= htmlspecialchars(
                        $asset['category'] ?? '—'
                    ) ?>

                </div>

            </div>


            <!-- LOCATION -->

            <div class="col-md-4">

                <small class="text-muted">
                    Location
                </small>

                <div>

                    <?= htmlspecialchars(
                        $asset['location'] ?? '—'
                    ) ?>

                </div>

            </div>


            <!-- STATE -->

            <div class="col-md-4">

                <small class="text-muted">
                    State / UT
                </small>

                <div>

                    <?= htmlspecialchars(
                        $asset['state'] ?? '—'
                    ) ?>

                </div>

            </div>


            <!-- STATUS -->

            <div class="col-md-4">

                <small class="text-muted">
                    Current Status
                </small>

                <div>

                    <span class="badge bg-<?= $badge ?>">

                        <?= htmlspecialchars(
                            $asset['status'] ?? '—'
                        ) ?>

                    </span>

                </div>

            </div>


            <!-- MAINTENANCE INTERVAL -->

            <div class="col-md-4">

                <small class="text-muted">
                    Maintenance Interval
                </small>

                <div>

                    <?php if (!empty($asset['maintenance_interval'])): ?>

                        <?= (int)$asset['maintenance_interval'] ?>
                        days

                    <?php else: ?>

                        —

                    <?php endif; ?>

                </div>

            </div>


            <!-- START DATE -->

            <div class="col-md-4">

                <small class="text-muted">
                    Maintenance Start Date
                </small>

                <div>

                    <?= formatDateDisplay(
                        $asset['maintenance_start_date'] ?? null
                    ) ?>

                </div>

            </div>


            <!-- LAST MAINTENANCE -->

            <div class="col-md-4">

                <small class="text-muted">
                    Last Maintenance
                </small>

                <div>

                    <?= formatDateDisplay(
                        $lastMaintenanceDate
                    ) ?>

                </div>

            </div>


            <!-- NEXT MAINTENANCE -->

            <div class="col-md-4">

                <small class="text-muted">
                    Next Maintenance
                </small>

                <div>

                    <?= formatDateDisplay(
                        $nextMaintenanceDate
                    ) ?>

                </div>

            </div>


            <!-- CREATED -->

            <div class="col-md-4">

                <small class="text-muted">
                    Created
                </small>

                <div>

                    <?= formatDateDisplay(
                        $asset['created_at'] ?? null
                    ) ?>

                </div>

            </div>


            <!-- UPDATED -->

            <div class="col-md-4">

                <small class="text-muted">
                    Last Updated
                </small>

                <div>

                    <?= formatDateDisplay(
                        $asset['updated_at'] ?? null
                    ) ?>

                </div>

            </div>

        </div>

    </div>

</div>


<!-- =========================================================
     MAINTENANCE HISTORY
========================================================= -->

<div class="card shadow-sm mb-4">

    <div class="card-header">

        <h5 class="mb-0">
            Maintenance History
        </h5>

    </div>


    <div class="card-body">

        <div class="table-responsive">

            <table class="table table-hover mb-0">

                <thead class="table-light">

                    <tr>

                        <th>Date</th>
                        <th>Status</th>
                        <th>Remarks</th>

                    </tr>

                </thead>


                <tbody>

                <?php if ($maintenance->num_rows === 0): ?>

                    <tr>

                        <td
                            colspan="3"
                            class="text-center text-muted">

                            No maintenance records

                        </td>

                    </tr>

                <?php else: ?>

                    <?php while ($m = $maintenance->fetch_assoc()): ?>

                        <tr>

                            <td>

                                <?= formatDateDisplay(
                                    $m['maintenance_date'] ?? null
                                ) ?>

                            </td>


                            <td>

                                <?php

                                $mStatus = strtolower(
                                    $m['status'] ?? ''
                                );

                                if ($mStatus === 'completed') {
                                    $mBadge = 'success';
                                } elseif ($mStatus === 'approved') {
                                    $mBadge = 'success';
                                } elseif ($mStatus === 'rejected') {
                                    $mBadge = 'danger';
                                } else {
                                    $mBadge = 'secondary';
                                }

                                ?>

                                <span class="badge bg-<?= $mBadge ?>">

                                    <?= htmlspecialchars(
                                        $m['status'] ?? '—'
                                    ) ?>

                                </span>

                            </td>


                            <td>

                                <?= htmlspecialchars(
                                    $m['remarks'] ?? '—'
                                ) ?>

                            </td>

                        </tr>

                    <?php endwhile; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>


<!-- =========================================================
     CALLBACK HISTORY
========================================================= -->

<div class="card shadow-sm mb-4">

    <div class="card-header">

        <h5 class="mb-0">
            Callback History
        </h5>

    </div>


    <div class="card-body">

        <div class="table-responsive">

            <table class="table table-hover mb-0">

                <thead class="table-light">

                    <tr>

                        <th>Issue</th>
                        <th>Status</th>
                        <th>Date</th>

                    </tr>

                </thead>


                <tbody>

                <?php if ($callbacks->num_rows === 0): ?>

                    <tr>

                        <td
                            colspan="3"
                            class="text-center text-muted">

                            No callbacks

                        </td>

                    </tr>

                <?php else: ?>

                    <?php while ($c = $callbacks->fetch_assoc()): ?>

                        <tr>

                            <td>

                                <?= htmlspecialchars(
                                    $c['issue'] ?? '—'
                                ) ?>

                            </td>


                            <td>

                                <?php

                                $cStatus = strtolower(
                                    $c['status'] ?? ''
                                );

                                $cBadge =
                                    $cStatus === 'open'
                                    ? 'warning'
                                    : 'success';

                                ?>

                                <span
                                    class="badge bg-<?= $cBadge ?>">

                                    <?= htmlspecialchars(
                                        $c['status'] ?? '—'
                                    ) ?>

                                </span>

                            </td>


                            <td>

                                <?= formatDateDisplay(
                                    $c['created_at'] ?? null
                                ) ?>

                            </td>

                        </tr>

                    <?php endwhile; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>


<!-- =========================================================
     INSURANCE
========================================================= -->

<div class="card shadow-sm mb-4">

    <div class="card-header">

        <h5 class="mb-0">
            Insurance
        </h5>

    </div>


    <div class="card-body">

        <?php if (!$insurance): ?>

            <p class="text-muted mb-0">
                No insurance data available.
            </p>

        <?php else: ?>

            <?php

            $expiryDate = $insurance['expiry_date'] ?? null;

            $daysLeft = null;

            if ($expiryDate) {

                $today = new DateTime(
                    'now',
                    new DateTimeZone('Asia/Kolkata')
                );

                $expiry = new DateTime(
                    $expiryDate,
                    new DateTimeZone('Asia/Kolkata')
                );

                $daysLeft = (int)$today
                    ->diff($expiry)
                    ->format('%r%a');
            }

            if ($daysLeft !== null && $daysLeft < 0) {

                $insuranceBadge = 'danger';
                $insuranceStatus = 'Expired';

            } elseif (
                $daysLeft !== null &&
                $daysLeft <= 30
            ) {

                $insuranceBadge = 'warning';
                $insuranceStatus = 'Due Soon';

            } else {

                $insuranceBadge = 'success';
                $insuranceStatus = 'Active';
            }

            ?>

            <div class="row g-3">

                <div class="col-md-4">

                    <small class="text-muted">
                        Expiry Date
                    </small>

                    <div class="fw-bold">

                        <?= formatDateDisplay(
                            $expiryDate
                        ) ?>

                    </div>

                </div>


                <div class="col-md-4">

                    <small class="text-muted">
                        Status
                    </small>

                    <div>

                        <span
                            class="badge bg-<?= $insuranceBadge ?>">

                            <?= $insuranceStatus ?>

                        </span>

                    </div>

                </div>


                <?php if ($daysLeft !== null): ?>

                    <div class="col-md-4">

                        <small class="text-muted">
                            Days Remaining
                        </small>

                        <div class="fw-bold">

                            <?= $daysLeft ?>

                        </div>

                    </div>

                <?php endif; ?>

            </div>

        <?php endif; ?>

    </div>

</div>


<!-- =========================================================
     FUTURE MODULES
========================================================= -->

<div class="card shadow-sm mb-4">

    <div class="card-header">

        <h5 class="mb-0">
            Asset Program Records
        </h5>

    </div>


    <div class="card-body">

        <div class="row g-3">


            <div class="col-md-4">

                <div class="border rounded p-3 h-100">

                    <strong>
                        NOC
                    </strong>

                    <p class="text-muted small mb-0 mt-1">
                        NOC records will be connected here.
                    </p>

                </div>

            </div>


            <div class="col-md-4">

                <div class="border rounded p-3 h-100">

                    <strong>
                        AMC
                    </strong>

                    <p class="text-muted small mb-0 mt-1">
                        AMC records will be connected here.
                    </p>

                </div>

            </div>


            <div class="col-md-4">

                <div class="border rounded p-3 h-100">

                    <strong>
                        Verification
                    </strong>

                    <p class="text-muted small mb-0 mt-1">
                        Verification records will be connected here.
                    </p>

                </div>

            </div>


        </div>

    </div>

</div>


<!-- =========================================================
     BACK BUTTON
========================================================= -->

<a
    href="assets.php"
    class="btn btn-secondary">

    ← Back to Assets

</a>


</div>

<?php include('../includes/footer.php'); ?>