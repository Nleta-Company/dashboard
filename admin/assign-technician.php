<?php
include('../config/db.php');
include('../config/auth.php');
require_once '../config/security.php';

require_role('admin');

$callback_id = (int)($_GET['callback'] ?? 0);
if (!$callback_id) { header("Location: callbacks.php"); exit; }

if (isset($_POST['assign'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header("Location: callbacks.php"); exit;
    }
    $tech_id = (int)$_POST['technician_id'];
    $upd = $conn->prepare("UPDATE callbacks SET technician_id=?, status='in_progress', work_started_at=IFNULL(work_started_at,NOW()) WHERE id=?");
    $upd->bind_param("ii", $tech_id, $callback_id);
    $upd->execute();

    $_SESSION['success'] = "Technician assigned successfully";
    header("Location: callbacks.php");
    exit;
}

/* =========================
   FETCH TECHNICIANS
========================= */
$techs = $conn->query("
  SELECT id, name
  FROM users
  WHERE role='technician'
  ORDER BY name
");
?>

<?php include('../includes/header.php'); ?>
<?php include('../includes/sidebar-admin.php'); ?>

<div class="content">
<h3>Assign / Reassign Technician</h3>

<form method="POST" class="card p-4 shadow" style="max-width:400px">
  <?= csrfField() ?>
  <label class="form-label">Select Technician</label>

  <select name="technician_id" class="form-select" required>
    <option value="">-- Select Technician --</option>
    <?php while ($t = $techs->fetch_assoc()): ?>
      <option value="<?= $t['id'] ?>">
        <?= htmlspecialchars($t['name']) ?>
      </option>
    <?php endwhile; ?>
  </select>

  <button name="assign" class="btn btn-primary mt-3">
    Assign
  </button>

  <a href="callbacks.php" class="btn btn-secondary mt-3 ms-2">Cancel</a>
</form>
</div>

<?php include('../includes/footer.php'); ?>
