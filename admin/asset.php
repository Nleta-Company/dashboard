<?php
include('../config/db.php');
include('../config/auth.php');

require_role('admin');

include('../includes/header.php');
include('../includes/sidebar-admin.php');

$asset_id = (int)($_GET['id'] ?? 0);
if (!$asset_id) { header("Location: assets.php"); exit; }

$as = $conn->prepare("SELECT * FROM assets WHERE id=?");
$as->bind_param("i", $asset_id); $as->execute();
$asset = $as->get_result()->fetch_assoc();

if (!$asset) {
    echo "<div class='content'><h4>Asset not found</h4></div>";
    include('../includes/footer.php');
    exit;
}

$ms = $conn->prepare("SELECT maintenance_date, remarks FROM maintenance_records WHERE asset_id=? ORDER BY maintenance_date DESC");
$ms->bind_param("i", $asset_id); $ms->execute();
$maintenance = $ms->get_result();

$cs = $conn->prepare("SELECT issue, status FROM callbacks WHERE asset_id=? ORDER BY id DESC");
$cs->bind_param("i", $asset_id); $cs->execute();
$callbacks = $cs->get_result();

$is = $conn->prepare("SELECT expiry_date FROM insurance WHERE asset_id=?");
$is->bind_param("i", $asset_id); $is->execute();
$insurance = $is->get_result()->fetch_assoc();

$daysLeft = null;
if ($insurance) {
    $daysLeft = (strtotime($insurance['expiry_date']) - time()) / 86400;
}

$lms = $conn->prepare("SELECT MAX(maintenance_date) AS last_date FROM maintenance_records WHERE asset_id=?");
$lms->bind_param("i", $asset_id); $lms->execute();
$lastMaintenance = $lms->get_result()->fetch_assoc();

$maintenanceDays = null;
if ($lastMaintenance && !empty($lastMaintenance['last_date'])) {
    $maintenanceDays = (time() - strtotime($lastMaintenance['last_date'])) / 86400;
}

?>

<div class="content">

<h3 class="mb-3">
Asset Details : <?= htmlspecialchars($asset['asset_name']) ?>
</h3>

<!-- INSURANCE ALERT -->
<?php if ($insurance && $daysLeft < 0): ?>
  <div class="alert alert-danger">
<strong>Alert:</strong> Insurance has expired for this asset.
   </div>
<?php elseif ($insurance && $daysLeft <= 30): ?>
  <div class="alert alert-warning">
    <strong>Warning:</strong> Insurance will expire within 30 days.
  </div>
<?php endif; ?>

<!-- MAINTENANCE ALERT -->
<?php
$lastDate = $lastMaintenance && !empty($lastMaintenance['last_date']) 
    ? (new DateTime($lastMaintenance['last_date']))->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('d M Y')
    : null;
?>
<?php if ($maintenanceDays !== null && $maintenanceDays > 60): ?>
  <div class="alert alert-danger">
    <strong>Alert:</strong>
    Maintenance overdue for this asset.<br>
    Last maintenance:
    <?= $lastDate ?>
    <br>
    <a href="maintenance.php" class="fw-bold text-red">View Maintenance Records →</a>
  </div>

<?php elseif ($maintenanceDays !== null && $maintenanceDays > 30): ?>
  <div class="alert alert-warning">
    <strong>Warning:</strong>
    Maintenance due soon.<br>
    Last maintenance:
    <?= $lastDate ?>
    <br>
    <a href="maintenance.php" class="fw-bold">View Maintenance Records →</a>
  </div>
<?php endif; ?>


<!-- MAINTENANCE -->
<div class="card p-4 mb-4">
<h5>Maintenance History</h5>

<table class="table table-hover mt-3">
<thead class="table-light">
<tr>
  <th>Date</th>
  <th>Remarks</th>
</tr>
</thead>
<tbody>

<?php
if ($maintenance->num_rows == 0) {
    echo "<tr><td colspan='2' class='text-center'>No maintenance records</td></tr>";
}

while ($m = $maintenance->fetch_assoc()) {
    echo "<tr>
      <td>{$m['maintenance_date']}</td>
      <td>{$m['remarks']}</td>
    </tr>";
}
?>

</tbody>
</table>
</div>

<!-- CALLBACKS -->
<div class="card p-4 mb-4">
<h5>Callbacks</h5>

<table class="table table-hover mt-3">
<thead class="table-light">
<tr>
  <th>Issue</th>
  <th>Status</th>
</tr>
</thead>
<tbody>

<?php
if ($callbacks->num_rows == 0) {
    echo "<tr><td colspan='2' class='text-center'>No callbacks</td></tr>";
}

while ($c = $callbacks->fetch_assoc()) {
    $badge = $c['status'] === 'open'
        ? "<span class='badge bg-warning text-dark'>Open</span>"
        : "<span class='badge bg-success'>Closed</span>";

    echo "<tr>
      <td>" . htmlspecialchars($c['issue'] ?? '—') . "</td>
      <td>$badge</td>
    </tr>";
}
?>

</tbody>
</table>
</div>

<!-- INSURANCE -->
<div class="card p-4 mb-4">
<h5>Insurance Status</h5>

<?php
if (!$insurance) {
    echo "<p>No insurance data available</p>";
} else {
    if ($daysLeft < 0) {
        $status = "<span class='badge bg-danger'>Expired</span>";
    } elseif ($daysLeft <= 30) {
        $status = "<span class='badge bg-warning text-dark'>Due Soon</span>";
    } else {
        $status = "<span class='badge bg-success'>Active</span>";
    }

    echo "
      <p><strong>Expiry Date:</strong> " . htmlspecialchars($insurance['expiry_date']) . "</p>
      <p><strong>Status:</strong> $status</p>
    ";
}
?>
</div>

<a href="dashboard.php" class="btn btn-secondary">← Back to Dashboard</a>

</div>

<?php include('../includes/footer.php'); ?>
