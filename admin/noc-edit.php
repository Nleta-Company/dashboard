<?php

/* =========================================================
   ERROR DISPLAY - TEMPORARY
========================================================= */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


/* =========================================================
   INCLUDES & AUTH
========================================================= */

include('../config/db.php');
include('../config/auth.php');
require_once '../config/security.php';

require_role('admin');

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    header("Location: noc-list.php");
    exit;
}


/* =========================================================
   1. FETCH NOC DATA
========================================================= */

$noc_stmt = $conn->prepare("SELECT * FROM noc_registration WHERE id = ?");
$noc_stmt->bind_param("i", $id);
$noc_stmt->execute();
$data = $noc_stmt->get_result()->fetch_assoc();

if (!$data) {
    header("Location: noc-list.php");
    exit;
}

$selectedOwnerId = (int)$data['owner_id'];
$selectedAssetId = (int)$data['asset_id'];


/* =========================================================
   2. FETCH LATEST INSURANCE DATA (ANY IS_ACTIVE STATUS)
========================================================= */

$insurance = null;

if ($selectedAssetId > 0) {
    $ins_stmt = $conn->prepare("
        SELECT * FROM insurance 
        WHERE asset_id = ? 
        ORDER BY id DESC 
        LIMIT 1
    ");
    if ($ins_stmt) {
        $ins_stmt->bind_param("i", $selectedAssetId);
        $ins_stmt->execute();
        $insurance = $ins_stmt->get_result()->fetch_assoc();
    }
}


/* =========================================================
   3. FETCH OWNERS & ASSETS
========================================================= */

// All Owners
$owners = $conn->query("
    SELECT id, name 
    FROM users 
    WHERE role = 'owner' 
    ORDER BY name
");

// Assets for Current Owner
$assets_stmt = $conn->prepare("
    SELECT id, asset_name, asset_type, location 
    FROM assets 
    WHERE owner_id = ? 
    ORDER BY asset_name
");
$assets_stmt->bind_param("i", $selectedOwnerId);
$assets_stmt->execute();
$assets = $assets_stmt->get_result();


/* =========================================================
   HEADER + SIDEBAR
========================================================= */

include('../includes/header.php');
include('../includes/sidebar-admin.php');

?>

<div class="content">

    <h3 class="mb-3">Edit NOC & Insurance Details</h3>

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

    <form method="POST" action="noc-update.php" enctype="multipart/form-data" class="card p-4 shadow-sm">
        
        <?= csrfField() ?>
        
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="insurance_id" value="<?= (int)($insurance['id'] ?? 0) ?>">

        <h5 class="mb-3">Asset Information</h5>
        <div class="row mb-3">
            <div class="col-md-4">
                <label class="form-label">Owner</label>
                <select name="owner_id" id="owner_id" class="form-control" required>
                    <option value="">Select Owner</option>
                    <?php while ($o = $owners->fetch_assoc()): ?>
                        <option value="<?= $o['id'] ?>" <?= $o['id'] == $selectedOwnerId ? 'selected' : '' ?>>
                            <?= htmlspecialchars($o['name']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="col-md-4">
                <label class="form-label">Asset</label>
                <select name="asset_id" id="asset_id" class="form-control" required>
                    <option value="">Select Asset</option>
                    <?php while ($a = $assets->fetch_assoc()): ?>
                        <option value="<?= $a['id'] ?>" <?= $a['id'] == $selectedAssetId ? 'selected' : '' ?>>
                            <?= htmlspecialchars($a['asset_name']) ?> | <?= htmlspecialchars($a['asset_type']) ?> <?= $a['location'] ? ' | ' . htmlspecialchars($a['location']) : '' ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
        </div>


        <h5 class="mt-3 mb-3">NOC Details</h5>
        <div class="row">
            <div class="col-md-4 mb-3">
                <label class="form-label">NOC No</label>
                <input type="text" name="noc_no" class="form-control" value="<?= htmlspecialchars($data['noc_no'] ?? '') ?>" required>
            </div>

            <div class="col-md-4 mb-3">
                <label class="form-label">NOC Date</label>
                <input type="date" name="noc_date" id="noc_date" class="form-control" value="<?= htmlspecialchars($data['noc_date'] ?? '') ?>" required>
            </div>

            <div class="col-md-4 mb-3">
                <label class="form-label">NOC Expiry</label>
                <input type="date" name="noc_expiry_date" id="noc_expiry_date" class="form-control" value="<?= htmlspecialchars($data['noc_expiry_date'] ?? '') ?>" readonly>
                <small class="text-muted">Auto calculated (3 Years)</small>
            </div>
        </div>

        <div class="row">
            <div class="col-md-4 mb-3">
                <label class="form-label">Registration No</label>
                <input type="text" name="registration_no" class="form-control" value="<?= htmlspecialchars($data['registration_no'] ?? '') ?>" required>
            </div>

            <div class="col-md-4 mb-3">
                <label class="form-label">Registration Date</label>
                <input type="date" name="registration_date" class="form-control" value="<?= htmlspecialchars($data['registration_date'] ?? '') ?>" required>
            </div>
        </div>

        <div class="row">
            <div class="col-md-4 mb-3">
                <label class="form-label">Safety Awareness Date</label>
                <input type="date" name="safety_awareness_date" class="form-control" value="<?= htmlspecialchars($data['safety_awareness_date'] ?? '') ?>" required>
            </div>

            <div class="col-md-4 mb-3">
                <label class="form-label">Mock Drill Date</label>
                <input type="date" name="mock_drill_date" class="form-control" value="<?= htmlspecialchars($data['mock_drill_date'] ?? '') ?>" required>
            </div>
        </div>


        <hr class="my-4">
        <h5 class="mb-3">Insurance Details</h5>

        <div class="row">
            <div class="col-md-4 mb-3">
                <label class="form-label">Insurance Number</label>
                <input type="text" name="insurance_no" class="form-control" value="<?= htmlspecialchars($insurance['insurance_no'] ?? '') ?>" required>
            </div>

            <div class="col-md-4 mb-3">
                <label class="form-label">Insurance Starting Date</label>
                <input type="date" name="insurance_start_date" id="insurance_start_date" class="form-control" value="<?= htmlspecialchars($insurance['start_date'] ?? '') ?>" required>
            </div>

            <div class="col-md-4 mb-3">
                <label class="form-label">Insurance Expiry Date</label>
                <input type="date" name="insurance_expiry_date" id="insurance_expiry_date" class="form-control" value="<?= htmlspecialchars($insurance['expiry_date'] ?? '') ?>" readonly required>
                <small class="text-muted">Auto calculated (1 Year)</small>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">Insurance Report File</label>
                <input type="file" name="insurance_file" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                <small class="text-muted">Leave empty to keep existing file. Accepted formats: PDF/JPG/PNG (Max 5MB)</small>

                <?php if (!empty($insurance['report_file'])): ?>
                    <?php
                    $safeFile = basename($insurance['report_file']);
                    $filePath = __DIR__ . '/../uploads/insurance/' . $safeFile;
                    ?>
                    <?php if (file_exists($filePath)): ?>
                        <div class="mt-2">
                            <a href="../uploads/insurance/<?= rawurlencode($safeFile) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                👁 View Uploaded Insurance Report
                            </a>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>


        <div class="mt-4 d-flex gap-2">
            <button type="submit" class="btn btn-primary">Update Details</button>

            <a href="noc-list.php" class="btn btn-secondary">Cancel</a>

            <a href="noc-delete.php?id=<?= $id ?>"
               class="btn btn-outline-danger ms-auto"
               onclick="return confirm('⚠ Are you sure you want to delete this NOC record?');">
               Delete NOC
            </a>
        </div>

    </form>
</div>


<script>
/* NOC Expiry Auto Calc (3 Years) */
document.getElementById('noc_date').addEventListener('change', function(){
    let d = new Date(this.value);
    if (!isNaN(d.getTime())) {
        d.setFullYear(d.getFullYear() + 3);
        document.getElementById('noc_expiry_date').value = d.toISOString().split('T')[0];
    }
});

/* Insurance Expiry Auto Calc (1 Year) */
document.getElementById('insurance_start_date').addEventListener('change', function(){
    let d = new Date(this.value);
    if (!isNaN(d.getTime())) {
        d.setFullYear(d.getFullYear() + 1);
        document.getElementById('insurance_expiry_date').value = d.toISOString().split('T')[0];
    }
});

/* Reload Assets on Owner Selection Change */
document.getElementById('owner_id').addEventListener('change', function(){
    let ownerId = this.value;
    let assetSelect = document.getElementById('asset_id');

    assetSelect.innerHTML = '<option value="">Loading...</option>';

    if (ownerId) {
        fetch('get-assets-by-owner.php?owner_id=' + ownerId)
          .then(res => res.json())
          .then(data => {
              assetSelect.innerHTML = '<option value="">Select Asset</option>';
              if (data.length === 0) {
                  assetSelect.innerHTML += '<option value="">No Asset Found</option>';
              }
              data.forEach(a => {
                  assetSelect.innerHTML += `
                    <option value="${a.id}">
                      ${a.asset_name} | ${a.asset_type} | ${a.location ?? ''}
                    </option>`;
              });
          });
    } else {
        assetSelect.innerHTML = '<option value="">Select Asset</option>';
    }
});
</script>

<?php include('../includes/footer.php'); ?>