<?php
include('../config/db.php');
include('../config/auth.php');
require_once '../config/security.php';

require_role('owner');

require_once __DIR__ . '/../config/owner_access.php';
requireOwnerAccess('fortnightly');

$uid = (int)$_SESSION['uid'];

$items = [
    "Abnormal sound or jerk during lift movement",
    "Doors not closing properly",
    "Lift stopping unexpectedly",
    "Buttons not responding sometimes",
    "Lift moving slowly",
    "Floor level mismatch",
    "Cabin light flickering",
    "Display not working properly",
    "Lift shaking slightly while moving",
    "Lift getting stuck between floors",
    "Unusual smell near the lift",
    "Lift not responding to calls on some floors",
    "Doors opening and closing repeatedly",
    "Lift stopping longer than usual at floors",
    "Sudden jerks during start or stop",
    "Door sensor not working properly",
    "Others"
];

// Fetch owner's assets for dropdown
$assetRes = $conn->prepare("SELECT id, asset_name FROM assets WHERE owner_id=? ORDER BY asset_name");
$assetRes->bind_param("i", $uid);
$assetRes->execute();
$ownerAssets = $assetRes->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch assigned technician
$techRes = $conn->prepare("SELECT assigned_technician_id FROM users WHERE id=?");
$techRes->bind_param("i", $uid);
$techRes->execute();
$techRow = $techRes->get_result()->fetch_assoc();
$tech_id = (int)($techRow['assigned_technician_id'] ?? 0);

// Use first asset automatically
$preSelectedAsset = !empty($ownerAssets) ? (int)$ownerAssets[0]['id'] : 0;

// Check 15-day cooldown
$cooldownMsg = null;
$lastCheckStmt = $conn->prepare("SELECT check_date FROM fortnightly_checks WHERE owner_id=? ORDER BY check_date DESC LIMIT 1");
$lastCheckStmt->bind_param("i", $uid);
$lastCheckStmt->execute();
$lastCheckRow = $lastCheckStmt->get_result()->fetch_assoc();
if ($lastCheckRow) {
    $lastDate = new DateTime($lastCheckRow['check_date']);
    $nextAllowed = clone $lastDate;
    $nextAllowed->modify('+15 days');
    $today = new DateTime(date('Y-m-d'));
    if ($today < $nextAllowed) {
        $cooldownMsg = "You can submit the next fortnightly check on <strong>" . $nextAllowed->format('d M Y') . "</strong>. (Last submitted: " . $lastDate->format('d M Y') . ")";
    }
}

if (isset($_POST['save'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['fc_error'] = 'Invalid request. Please try again.';
        header("Location: fortnightly-check.php"); exit;
    }
    $asset_id   = (int)($_POST['asset_id'] ?? 0);
    // Fallback: if asset_id still 0, use owner's first asset
    if ($asset_id <= 0) $asset_id = $preSelectedAsset;
    $check_date = $_POST['check_date'];
    $remarks    = trim($_POST['remarks'] ?? '');


    $results = [];
    $complaints = [];

    foreach ($items as $i => $item) {
        $val = $_POST["status_$i"] ?? 'not_ok';
        $val = in_array($val, ['ok','not_ok']) ? $val : 'not_ok';
        $results[$item] = $val;
        if ($val === 'not_ok') {
            $complaints[] = $item;
        }
    }

    $checklist_json = json_encode($results);
    $stmt = $conn->prepare("INSERT INTO fortnightly_checks (asset_id, owner_id, check_date, checklist, remarks) VALUES (?,?,?,?,?)");
    $stmt->bind_param("iisss", $asset_id, $uid, $check_date, $checklist_json, $remarks);

    if ($stmt->execute()) {
        if (count($complaints) > 0) {
            require_once '../config/callback_helper.php';
            foreach ($complaints as $issue) {
                $cb_no = generateCallbackNumber($conn);
                $desc  = "Auto-generated from Fortnightly Check on $check_date";
                $ins   = $conn->prepare("INSERT INTO callbacks (asset_id, technician_id, callback_number, issue, type, description, status, work_started_at) VALUES (?,?,?,?,'fortnightly',?,'in_progress',NOW())");
                $ins->bind_param("iisss", $asset_id, $tech_id, $cb_no, $issue, $desc);
                $ins->execute();
            }

            if ($asset_id > 0) {
                $upd = $conn->prepare("UPDATE assets SET status='Under Maintenance' WHERE id=? AND owner_id=?");
                $upd->bind_param("ii", $asset_id, $uid);
                $upd->execute();
            }

            // Send SMS to owner if complaints > 4
            if (count($complaints) > 4) {
                $ownerPhone = getenv('ADMIN_PHONE');
                $apiKey = getenv('FAST2SMS_API_KEY');
                if ($ownerPhone && $apiKey) {
                    $count   = count($complaints);
                    $message = urlencode("NLETA Alert: Fortnightly check on $check_date has $count issues reported. Technician has been notified. Please follow up.");
                    $url = "https://www.fast2sms.com/dev/bulkV2?authorization={$apiKey}&route=q&message={$message}&language=english&flash=0&numbers={$ownerPhone}";
                    $ch = curl_init($url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                    curl_exec($ch);
                    curl_close($ch);
                }
            }

            $_SESSION['fc_success'] = "Fortnightly check submitted. " . count($complaints) . " complaint(s) sent to technician.";
        } else {
            $_SESSION['fc_success'] = "Fortnightly check submitted successfully! All items OK.";
        }
    } else {
        $_SESSION['fc_error'] = "Error: " . $stmt->error;
    }

    header("Location: fortnightly-check.php"); exit;
}

include('../includes/header.php');
include('../includes/sidebar-owner.php');
?>

<div class="content">
<h3 class="mb-4">Fortnightly (15 Days) Operational Check</h3>

<?php if (!empty($_SESSION['fc_success'])): ?>
  <div class="alert alert-success alert-dismissible fade show" id="fc-alert">
    <?= htmlspecialchars($_SESSION['fc_success']) ?>
    <button type="button" class="btn-close" onclick="this.closest('.alert').remove()"></button>
  </div>
  <?php unset($_SESSION['fc_success']); ?>
<?php endif; ?>

<?php if (!empty($_SESSION['fc_error'])): ?>
  <div class="alert alert-danger alert-dismissible fade show" id="fc-alert-err">
    <?= htmlspecialchars($_SESSION['fc_error']) ?>
    <button type="button" class="btn-close" onclick="this.closest('.alert').remove()"></button>
  </div>
  <?php unset($_SESSION['fc_error']); ?>
<?php endif; ?>

<?php if ($cooldownMsg): ?>
<div class="alert alert-warning shadow-sm">
  🕐 <?= $cooldownMsg ?>
</div>
<?php else: ?>
<div class="card shadow p-4" style="max-width:800px;">
<form method="POST">
  <?= csrfField() ?>

  <div class="mb-3">
    <label class="form-label fw-semibold">Check Date</label>
    <input type="date" name="check_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
  </div>
  <input type="hidden" name="asset_id" value="<?= $preSelectedAsset ?>">

  <!-- CHECKLIST HEADER -->
  <div class="d-flex align-items-center justify-content-between mb-2">
    <p class="fw-semibold mb-0">Checklist Items</p>
    <div class="d-flex gap-2">
      <button type="button" class="btn btn-success btn-sm" onclick="setAll('ok')">✅ All OK</button>
      <button type="button" class="btn btn-danger btn-sm" onclick="setAll('not_ok')">❌ All Not OK</button>
    </div>
  </div>

  <!-- CHECKLIST ITEMS -->
  <div class="mb-3">
    <?php foreach ($items as $i => $item): ?>
    <div class="d-flex align-items-center justify-content-between p-3 mb-2 rounded border" id="row_<?= $i ?>" style="background:#f8f9fa;">
      <span class="flex-grow-1 me-3"><?= $i+1 ?>. <?= htmlspecialchars($item) ?></span>
      <div class="d-flex gap-2">
        <button type="button"
                id="btn_ok_<?= $i ?>"
                onclick="setStatus(<?= $i ?>, 'ok')"
                class="btn btn-sm btn-outline-success">
          ✅ OK
        </button>
        <button type="button"
                id="btn_notok_<?= $i ?>"
                onclick="setStatus(<?= $i ?>, 'not_ok')"
                class="btn btn-sm btn-outline-danger">
          ❌ Not OK
        </button>
        <input type="hidden" name="status_<?= $i ?>" id="status_<?= $i ?>" value="">
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- NOT OK SUMMARY -->
  <div id="notOkSummary" class="alert alert-warning d-none mb-3">
    <strong>⚠ Issues found:</strong>
    <ul id="notOkList" class="mb-0 mt-1"></ul>
    <small class="text-muted">These will be auto-converted to complaints for the technician.</small>
  </div>

  <div class="mb-3">
    <label class="form-label fw-semibold">Remarks (optional)</label>
    <textarea name="remarks" class="form-control" rows="3" placeholder="Any additional observations..."></textarea>
  </div>

  <button name="save" class="btn btn-primary w-100">Submit Fortnightly Check</button>

</form>
</div>
<?php endif; ?>
</div>

<?php include('../includes/footer.php'); ?>

<script>
const totalItems = <?= count($items) ?>;
const itemNames  = <?= json_encode(array_values($items)) ?>;

function setStatus(i, val) {
    document.getElementById('status_' + i).value = val;

    const row      = document.getElementById('row_' + i);
    const btnOk    = document.getElementById('btn_ok_' + i);
    const btnNotOk = document.getElementById('btn_notok_' + i);

    if (val === 'ok') {
        row.style.background    = '#d4edda';
        row.style.borderColor   = '#28a745';
        btnOk.className         = 'btn btn-sm btn-success';
        btnNotOk.className      = 'btn btn-sm btn-outline-danger';
    } else {
        row.style.background    = '#f8d7da';
        row.style.borderColor   = '#dc3545';
        btnOk.className         = 'btn btn-sm btn-outline-success';
        btnNotOk.className      = 'btn btn-sm btn-danger';
    }
    updateSummary();
}

function setAll(val) {
    for (let i = 0; i < totalItems; i++) {
        setStatus(i, val);
    }
}

function updateSummary() {
    const list    = document.getElementById('notOkList');
    const summary = document.getElementById('notOkSummary');
    list.innerHTML = '';
    let count = 0;
    for (let i = 0; i < totalItems; i++) {
        const v = document.getElementById('status_' + i).value;
        if (v === 'not_ok') {
            list.innerHTML += `<li>${itemNames[i]}</li>`;
            count++;
        }
    }
    summary.classList.toggle('d-none', count === 0);
}

// Validate all items selected before submit
document.querySelector('form').addEventListener('submit', function(e) {
    for (let i = 0; i < totalItems; i++) {
        if (!document.getElementById('status_' + i).value) {
            e.preventDefault();
            alert('Please select OK or Not OK for all checklist items.');
            return;
        }
    }
});
</script>
