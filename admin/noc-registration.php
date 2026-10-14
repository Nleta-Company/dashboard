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

require_role('admin');


/* =========================================================
   SELECTED OWNER + ASSET
========================================================= */

$selectedOwnerId = (int)($_GET['owner_id'] ?? 0);
$selectedAssetId = (int)($_GET['asset_id'] ?? 0);


/* =========================================================
   GET ALL OWNERS
========================================================= */

$owners = [];

$ownerStmt = $conn->prepare("
    SELECT id, name
    FROM users
    WHERE role = 'owner'
    ORDER BY name
");

if (!$ownerStmt) {
    die("Database error: " . htmlspecialchars($conn->error));
}

$ownerStmt->execute();
$ownerResult = $ownerStmt->get_result();

while ($owner = $ownerResult->fetch_assoc()) {
    $owners[] = $owner;
}


/* =========================================================
   GET ASSETS FOR SELECTED OWNER
========================================================= */

$assets = [];

if ($selectedOwnerId > 0) {

    $assetStmt = $conn->prepare("
        SELECT id, asset_name, asset_type, location
        FROM assets
        WHERE owner_id = ?
        ORDER BY asset_name
    ");

    if (!$assetStmt) {
        die("Database error: " . htmlspecialchars($conn->error));
    }

    $assetStmt->bind_param("i", $selectedOwnerId);
    $assetStmt->execute();
    $assetResult = $assetStmt->get_result();

    while ($asset = $assetResult->fetch_assoc()) {
        $assets[] = $asset;
    }
}


/* =========================================================
   VALIDATE SELECTED ASSET
========================================================= */

if ($selectedAssetId > 0 && $selectedOwnerId > 0) {

    $checkAssetStmt = $conn->prepare("
        SELECT id FROM assets
        WHERE id = ? AND owner_id = ?
        LIMIT 1
    ");

    if ($checkAssetStmt) {
        $checkAssetStmt->bind_param("ii", $selectedAssetId, $selectedOwnerId);
        $checkAssetStmt->execute();
        $checkAssetResult = $checkAssetStmt->get_result();

        if (!$checkAssetResult->fetch_assoc()) {
            $selectedAssetId = 0;
        }
    }
}


/* =========================================================
   FETCH EXISTING NOC & ACTIVE INSURANCE DETAILS
========================================================= */

$existingNoc       = null;
$existingInsurance = null;

if ($selectedAssetId > 0 && $selectedOwnerId > 0) {

    // A. FETCH EXISTING NOC DATA
    $nocStmt = $conn->prepare("
        SELECT * FROM noc_registration 
        WHERE asset_id = ? AND owner_id = ? 
        LIMIT 1
    ");
    if ($nocStmt) {
        $nocStmt->bind_param("ii", $selectedAssetId, $selectedOwnerId);
        $nocStmt->execute();
        $existingNoc = $nocStmt->get_result()->fetch_assoc();
    }

    // B. FETCH EXISTING ACTIVE INSURANCE DATA
    $insuranceStmt = $conn->prepare("
        SELECT i.id AS insurance_id, i.insurance_no, i.start_date, i.expiry_date, i.report_file
        FROM insurance i
        INNER JOIN assets a ON a.id = i.asset_id
        WHERE i.asset_id = ? AND a.owner_id = ? AND i.is_active = 1
        ORDER BY i.id DESC LIMIT 1
    ");

    if ($insuranceStmt) {
        $insuranceStmt->bind_param("ii", $selectedAssetId, $selectedOwnerId);
        $insuranceStmt->execute();
        $existingInsurance = $insuranceStmt->get_result()->fetch_assoc();
    }
}


/* =========================================================
   HEADER + ADMIN SIDEBAR
========================================================= */

include('../includes/header.php');
include('../includes/sidebar-admin.php');

?>


<div class="content">

    <h3 class="mb-3">
        <?= $existingNoc ? 'Edit / Update NOC & Insurance' : 'NOC Registration' ?>
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
                    <select name="owner_id" id="owner_id" class="form-control" required>
                        <option value="">Select Owner</option>
                        <?php foreach ($owners as $owner): ?>
                            <option value="<?= (int)$owner['id'] ?>" <?= $selectedOwnerId === (int)$owner['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($owner['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Asset</label>
                    <select name="asset_id" id="asset_id" class="form-control" required <?= empty($selectedOwnerId) ? 'disabled' : '' ?>>
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
                    <input type="text" name="noc_no" class="form-control" required value="<?= htmlspecialchars($existingNoc['noc_no'] ?? '') ?>">
                </div>

                <div class="col-md-4 mb-3">
                    <label class="form-label">NOC Date</label>
                    <input type="date" name="noc_date" id="noc_date" class="form-control" required value="<?= htmlspecialchars($existingNoc['noc_date'] ?? '') ?>">
                </div>

                <div class="col-md-4 mb-3">
                    <label class="form-label">NOC Expiry Date</label>
                    <input type="date" name="noc_expiry_date" id="noc_expiry_date" class="form-control" readonly required value="<?= htmlspecialchars($existingNoc['noc_expiry_date'] ?? '') ?>">
                    <small class="text-muted">Auto calculated (3 Years)</small>
                </div>
            </div>

            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">Registration Number</label>
                    <input type="text" name="registration_no" class="form-control" required value="<?= htmlspecialchars($existingNoc['registration_no'] ?? '') ?>">
                </div>

                <div class="col-md-4 mb-3">
                    <label class="form-label">Registration Date</label>
                    <input type="date" name="registration_date" class="form-control" required value="<?= htmlspecialchars($existingNoc['registration_date'] ?? '') ?>">
                </div>
            </div>

            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">Safety Awareness Date</label>
                    <input type="date" name="safety_awareness_date" class="form-control" required value="<?= htmlspecialchars($existingNoc['safety_awareness_date'] ?? '') ?>">
                    <small class="text-muted">Reminder every 6 months</small>
                </div>

                <div class="col-md-4 mb-3">
                    <label class="form-label">Mock Drill Date</label>
                    <input type="date" name="mock_drill_date" class="form-control" required value="<?= htmlspecialchars($existingNoc['mock_drill_date'] ?? '') ?>">
                    <small class="text-muted">Reminder every 1 year</small>
                </div>
            </div>


            <hr class="my-4">
            <h5 class="mb-3">Insurance Details</h5>

            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">Insurance Number</label>
                    <input type="text" name="insurance_no" class="form-control" required value="<?= htmlspecialchars($existingInsurance['insurance_no'] ?? '') ?>">
                </div>

                <div class="col-md-4 mb-3">
                    <label class="form-label">Insurance Starting Date</label>
                    <input type="date" name="insurance_start_date" id="insurance_start_date" class="form-control" required value="<?= htmlspecialchars($existingInsurance['start_date'] ?? '') ?>">
                </div>

                <div class="col-md-4 mb-3">
                    <label class="form-label">Insurance Expiry Date</label>
                    <input type="date" name="insurance_expiry_date" id="insurance_expiry_date" class="form-control" readonly required value="<?= htmlspecialchars($existingInsurance['expiry_date'] ?? '') ?>">
                    <small class="text-muted">Auto calculated (1 Year)</small>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Insurance Report</label>
                    <input type="file" name="insurance_file" class="form-control" accept=".pdf,.jpg,.jpeg,.png" <?= empty($existingInsurance['report_file']) ? 'required' : '' ?>>
                    <small class="text-muted">PDF/JPG/JPEG/PNG, maximum 5 MB</small>
                </div>
            </div>


            <?php if ($selectedAssetId > 0 && $existingInsurance): ?>

                <?php
                $insuranceExpiry = $existingInsurance['expiry_date'] ?? '';
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

                            <?php if (!empty($existingInsurance['insurance_no'])): ?>
                                <span class="me-3">
                                    <strong>Insurance No:</strong> <?= htmlspecialchars($existingInsurance['insurance_no']) ?>
                                </span>
                            <?php endif; ?>

                            <?php if (!empty($existingInsurance['start_date'])): ?>
                                <span class="me-3">
                                    <strong>Start:</strong> <?= htmlspecialchars($existingInsurance['start_date']) ?>
                                </span>
                            <?php endif; ?>

                            <?php if (!empty($insuranceExpiry)): ?>
                                <span class="me-3">
                                    <strong>Expiry:</strong> <?= htmlspecialchars($insuranceExpiry) ?>
                                </span>
                            <?php endif; ?>

                            <?php if (!empty($existingInsurance['report_file'])): ?>
                                <?php
                                $safeFile = basename($existingInsurance['report_file']);
                                $filePath = __DIR__ . '/../uploads/insurance/' . $safeFile;
                                ?>
                                <?php if (file_exists($filePath)): ?>
                                    <a href="../uploads/insurance/<?= rawurlencode($safeFile) ?>" target="_blank" class="btn btn-sm btn-outline-primary ms-2">
                                        View Insurance Report
                                    </a>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">File Not Found</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div>
                        <a href="delete-insurance.php?asset_id=<?= (int)$selectedAssetId ?>&owner_id=<?= (int)$selectedOwnerId ?>" 
                           class="btn btn-sm btn-outline-danger"
                           onclick="return confirm('Are you sure you want to clear this active insurance entry? Historical logs and physical files will remain preserved.');">
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

/* OWNER CHANGE */
document.getElementById('owner_id').addEventListener('change', function () {
    const ownerId = this.value;
    if (!ownerId) {
        window.location.href = 'noc-registration.php';
        return;
    }
    window.location.href = 'noc-registration.php?owner_id=' + encodeURIComponent(ownerId);
});

/* ASSET CHANGE */
document.getElementById('asset_id').addEventListener('change', function () {
    const assetId = this.value;
    const ownerId = document.getElementById('owner_id').value;
    if (!ownerId) return;

    if (!assetId) {
        window.location.href = 'noc-registration.php?owner_id=' + encodeURIComponent(ownerId);
        return;
    }
    window.location.href = 'noc-registration.php?owner_id=' + encodeURIComponent(ownerId) + '&asset_id=' + encodeURIComponent(assetId);
});

/* NOC EXPIRY (3 YEARS) */
document.getElementById('noc_date').addEventListener('change', function () {
    const value = this.value;
    const expiryField = document.getElementById('noc_expiry_date');
    if (!value) { expiryField.value = ''; return; }

    const parts = value.split('-');
    const date  = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
    date.setFullYear(date.getFullYear() + 3);

    const expiryYear  = date.getFullYear();
    const expiryMonth = String(date.getMonth() + 1).padStart(2, '0');
    const expiryDay   = String(date.getDate()).padStart(2, '0');

    expiryField.value = expiryYear + '-' + expiryMonth + '-' + expiryDay;
});

/* INSURANCE EXPIRY (1 YEAR) */
document.getElementById('insurance_start_date').addEventListener('change', function () {
    const value = this.value;
    const expiryField = document.getElementById('insurance_expiry_date');
    if (!value) { expiryField.value = ''; return; }

    const parts = value.split('-');
    const date  = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
    date.setFullYear(date.getFullYear() + 1);

    const expiryYear  = date.getFullYear();
    const expiryMonth = String(date.getMonth() + 1).padStart(2, '0');
    const expiryDay   = String(date.getDate()).padStart(2, '0');

    expiryField.value = expiryYear + '-' + expiryMonth + '-' + expiryDay;
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