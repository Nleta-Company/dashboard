<?php
include('../config/db.php');
include('../config/auth.php');
require_once '../config/security.php';

require_role('admin');

$job_id = (int)($_GET['job'] ?? 0);
if (!$job_id) { header("Location: maintenance-jobs.php"); exit; }

if (isset($_POST['assign'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header("Location: maintenance-jobs.php"); exit;
    }
    $tech_id = (int)$_POST['technician_id'];
    $upd = $conn->prepare("UPDATE maintenance_jobs SET technician_id=? WHERE id=?");
    $upd->bind_param("ii", $tech_id, $job_id);
    $upd->execute();

    $_SESSION['success'] = "Technician reassigned for this job only";
    header("Location: maintenance-jobs.php");
    exit;
}

$techs = $conn->query("
  SELECT id, name FROM users WHERE role='technician' ORDER BY name
");
?>

<?php include('../includes/header.php'); ?>
<?php include('../includes/sidebar-admin.php'); ?>

<div class="content">
<h3>Reassign Maintenance Job</h3>

<form method="POST" class="card p-4 shadow" style="max-width:400px">
  <?= csrfField() ?>
  <select name="technician_id" class="form-select" required>
    <option value="">-- Select Technician --</option>
    <?php while ($t = $techs->fetch_assoc()): ?>
      <option value="<?= $t['id'] ?>">
        <?= htmlspecialchars($t['name']) ?>
      </option>
    <?php endwhile; ?>
  </select>

  <button name="assign" class="btn btn-warning mt-3">
    Reassign (One Time)
  </button>
</form>
</div>

<?php include('../includes/footer.php'); ?>
