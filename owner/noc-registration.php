<?php

/* =========================================================
   ERROR DISPLAY - TEMPORARY
========================================================= */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


/* =========================================================
   INCLUDES
========================================================= */

include('../config/db.php');
include('../config/auth.php');
require_once '../config/security.php';

require_role('owner');

require_once __DIR__ . '/../config/owner_access.php';
requireOwnerAccess('noc');

$owner_id = (int)($_SESSION['uid'] ?? 0);


/* =========================================================
   GET OWNER DETAILS
========================================================= */

$ownerStmt = $conn->prepare("
    SELECT id, name
    FROM users
    WHERE id = ?
      AND role = 'owner'
    LIMIT 1
");

if (!$ownerStmt) {
    die("Database error: " . htmlspecialchars($conn->error));
}

$ownerStmt->bind_param("i", $owner_id);
$ownerStmt->execute();
$ownerResult = $ownerStmt->get_result();
$owner = $ownerResult->fetch_assoc();

if (!$owner) {
    die("Owner account not found.");
}


/* =========================================================
   GET OWNER ASSETS
========================================================= */

$assets = [];

$assetStmt = $conn->prepare("
    SELECT
        id,
        asset_name,
        asset_type,
        location
    FROM assets
    WHERE owner_id = ?
    ORDER BY asset_name
");

if (!$assetStmt) {
    die("Database error: " . htmlspecialchars($conn->error));
}

$assetStmt->bind_param("i", $owner_id);
$assetStmt->execute();
$assetResult = $assetStmt->get_result();

while ($asset = $assetResult->fetch_assoc()) {
    $assets[] = $asset;
}


/* =========================================================
   SELECTED ASSET
========================================================= */

$selectedAssetId = (int)($_GET['asset_id'] ?? 0);


/* =========================================================
   GET EXISTING ACTIVE INSURANCE (is_active = 1 ONLY)
========================================================= */

$insurance = null;

if ($selectedAssetId > 0) {

    $insuranceStmt = $conn->prepare("
        SELECT
            i.id AS insurance_id,
            i.insurance_no,
            i.start_date,
            i.expiry_date,
            i.report_file
        FROM insurance i
        INNER JOIN assets a ON a.id = i.asset_id
        WHERE i.asset_id = ?
          AND a.owner_id = ?
          AND i.is_active = 1
        ORDER BY i.id DESC
        LIMIT 1
    ");

    if ($insuranceStmt) {
        $insuranceStmt->bind_param("ii", $selectedAssetId, $owner_id);
        $insuranceStmt->execute();
        $insuranceResult = $insuranceStmt->get_result();
        $insurance = $insuranceResult->fetch_assoc();
    }
}


/* =========================================================
   HEADER + SIDEBAR
========================================================= */

include('../includes/header.php');
include('../includes/sidebar-owner.php');

?>


<div class="content">

    <h3 class="mb-3">
        NOC Registration
    </h3>


    <?php if (!empty($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?= htmlspecialchars($_SESSION['success']) ?>
            <button type="button" class="btn-close" onclick="this.closest('.alert').remove()"></button>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>


    <?php if (!empty($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?= htmlspecialchars($_SESSION['error']) ?>
            <button type="button" class="btn-close" onclick="this.closest('.alert').remove()"></button>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>


    <div class="card shadow-sm p-4">

        <form method="POST" action="noc-save.php" enctype="multipart/form-data" id="nocForm">

            <?= csrfField() ?>

            <div class="row mb-3">

                <div class="col-md-4">
                    <label class="form-label">Owner</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($owner['name']) ?>" readonly>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Asset</label>
                    <select name="asset_id" id="asset_id" class="form-control" required>
                        <option value="">Select Asset</option>
                        <?php foreach ($assets as $asset): ?>
                            <option value="<?= (int)$asset['id'] ?>" <?= $selectedAssetId === (int)$asset['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($asset['asset_name']) ?> | <?= htmlspecialchars($asset['asset_type']) ?> | <?= htmlspecialchars($asset['location']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

            </div>


            <h5 class="mt-4 mb-3">NOC Details</h5>

            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">NOC Number</label>
                    <input type="text" name="noc_no" class="form-control" required>
                </div>

                <div class="col-md-4 mb-3">
                    <label class="form-label">NOC Date</label>
                    <input type="date" name="noc_date" id="noc_date" class="form-control" required>
                </div>

                <div class="col-md-4 mb-3">
                    <label class="form-label">NOC Expiry Date</label>
                    <input type="date" name="noc_expiry_date" id="noc_expiry_date" class="form-control" readonly required>
                    <small class="text-muted">Auto calculated (3 Years)</small>
                </div>
            </div>

            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">Registration Number</label>
                    <input type="text" name="registration_no" class="form-control" required>
                </div>

                <div class="col-md-4 mb-3">
                    <label class="form-label">Registration Date</label>
                    <input type="date" name="registration_date" class="form-control" required>
                </div>
            </div>

            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">Safety Awareness Date</label>
                    <input type="date" name="safety_awareness_date" class="form-control" required>
                    <small class="text-muted">Reminder every 6 months</small>
                </div>

                <div class="col-md-4 mb-3">
                    <label class="form-label">Mock Drill Date</label>
                    <input type="date" name="mock_drill_date" class="form-control" required>
                    <small class="text-muted">Reminder every 1 year</small>
                </div>
            </div>


            <hr class="my-4">
            <h5 class="mb-3">Insurance Details</h5>

            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">Insurance Number</label>
                    <input type="text" name="insurance_no" class="form-control" required value="<?= htmlspecialchars($insurance['insurance_no'] ?? '') ?>">
                </div>

                <div class="col-md-4 mb-3">
                    <label class="form-label">Insurance Starting Date</label>
                    <input type="date" name="insurance_start_date" id="insurance_start_date" class="form-control" required value="<?= htmlspecialchars($insurance['start_date'] ?? '') ?>">
                </div>

                <div class="col-md-4 mb-3">
                    <label class="form-label">Insurance Expiry Date</label>
                    <input type="date" name="insurance_expiry_date" id="insurance_expiry_date" class="form-control" readonly required value="<?= htmlspecialchars($insurance['expiry_date'] ?? '') ?>">
                    <small class="text-muted">Auto calculated (1 Year)</small>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Insurance Report</label>
                    <input type="file" name="insurance_file" class="form-control" accept=".pdf,.jpg,.jpeg,.png" <?= empty($insurance['report_file']) ? 'required' : '' ?>>
                    <small class="text-muted">PDF/JPG/JPEG/PNG, maximum 5 MB</small>
                </div>
            </div>


            <?php if ($selectedAssetId > 0 && $insurance): ?>

                <?php
                $insuranceExpiry = $insurance['expiry_date'] ?? '';
                $insuranceStatus = 'Active';

                if (!empty($insuranceExpiry)) {
                    $expiryTimestamp = strtotime($insuranceExpiry);
                    $todayTimestamp  = strtotime(date('Y-m-d'));
                    $daysLeft        = floor(($expiryTimestamp - $todayTimestamp) / 86400);

                    if ($daysLeft < 0) {
                        $insuranceStatus = 'Expired';
                    } elseif ($daysLeft <= 30) {
                        $insuranceStatus = 'Due Soon';
                    }
                }
                ?>

                <div class="alert alert-light border mb-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <strong>Existing Insurance</strong>
                        <div class="mt-2">
                            <span class="me-3">
                                <strong>Status:</strong>
                                <span class="badge bg-<?= ($insuranceStatus === 'Active') ? 'success' : (($insuranceStatus === 'Due Soon') ? 'warning text-dark' : 'danger') ?>">
                                    <?= $insuranceStatus ?>
                                </span>
                            </span>

                            <?php if (!empty($insurance['insurance_no'])): ?>
                                <span class="me-3"><strong>Insurance No:</strong> <?= htmlspecialchars($insurance['insurance_no']) ?></span>
                            <?php endif; ?>

                            <?php if (!empty($insurance['start_date'])): ?>
                                <span class="me-3"><strong>Start:</strong> <?= htmlspecialchars($insurance['start_date']) ?></span>
                            <?php endif; ?>

                            <?php if (!empty($insuranceExpiry)): ?>
                                <span class="me-3"><strong>Expiry:</strong> <?= htmlspecialchars($insuranceExpiry) ?></span>
                            <?php endif; ?>

                            <?php if (!empty($insurance['report_file'])): ?>
                                <a href="../uploads/insurance/<?= rawurlencode(basename($insurance['report_file'])) ?>" target="_blank" class="btn btn-sm btn-outline-primary ms-2">
                                    👁 View Insurance Report
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div>
                        <a href="delete-insurance.php?asset_id=<?= (int)$selectedAssetId ?>" 
                           class="btn btn-sm btn-outline-danger"
                           onclick="return confirm('Are you sure you want to clear this active insurance entry?');">
                           🗑️ Clear Active Insurance
                        </a>
                    </div>
                </div>

            <?php endif; ?>


            <button type="submit" name="save_noc" class="btn btn-primary mt-2" <?= empty($assets) ? 'disabled' : '' ?>>
                Save NOC & Insurance Details
            </button>

        </form>

    </div>

</div>


<script>

/* NOC EXPIRY (3 YEARS) */
document.getElementById('noc_date').addEventListener('change', function () {
    const value = this.value;
    const expiryField = document.getElementById('noc_expiry_date');
    if (!value) { expiryField.value = ''; return; }

    const parts = value.split('-');
    const date  = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
    date.setFullYear(date.getFullYear() + 3);

    expiryField.value = date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
});

/* INSURANCE EXPIRY (1 YEAR) */
document.getElementById('insurance_start_date').addEventListener('change', function () {
    const value = this.value;
    const expiryField = document.getElementById('insurance_expiry_date');
    if (!value) { expiryField.value = ''; return; }

    const parts = value.split('-');
    const date  = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
    date.setFullYear(date.getFullYear() + 1);

    expiryField.value = date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
});

/* ASSET CHANGE */
document.getElementById('asset_id').addEventListener('change', function () {
    const assetId = this.value;
    if (!assetId) {
        window.location.href = 'noc-registration.php';
        return;
    }
    window.location.href = 'noc-registration.php?asset_id=' + encodeURIComponent(assetId);
});

/* PAGE LOAD */
document.addEventListener('DOMContentLoaded', function () {
    const startField  = document.getElementById('insurance_start_date');
    const expiryField = document.getElementById('insurance_expiry_date');
    if (startField && expiryField && startField.value && !expiryField.value) {
        startField.dispatchEvent(new Event('change'));
    }

    const nocDate   = document.getElementById('noc_date');
    const nocExpiry = document.getElementById('noc_expiry_date');
    if (nocDate && nocExpiry && nocDate.value && !nocExpiry.value) {
        nocDate.dispatchEvent(new Event('change'));
    }
});

</script>

<?php include('../includes/footer.php'); ?>