<?php
include('../config/db.php');
include('../config/auth.php');
require_role('owner');
include('../includes/header.php');
include('../includes/sidebar-owner.php');
$owner_id = (int)$_SESSION['uid'];

$stmt = $conn->prepare("
  SELECT a.asset_name, c.issue, c.status, c.work_started_at, c.work_closed_at
  FROM callbacks c
  JOIN assets a ON a.id = c.asset_id
  WHERE a.owner_id = ?
  ORDER BY c.id DESC
");
$stmt->bind_param("i", $owner_id); $stmt->execute();
$q = $stmt->get_result();
?>
<div class="content">
<h3 class="mb-4">My Lift Callbacks</h3>
<table class="table table-hover bg-white shadow">
<thead class="table-dark">
<tr><th>Asset</th><th>Issue</th><th>Status</th><th>Started</th><th>Closed</th></tr>
</thead>
<tbody>
<?php if ($q->num_rows == 0): ?>
<tr><td colspan="5" class="text-center text-muted">No Callbacks Found</td></tr>
<?php endif; ?>
<?php while ($r = $q->fetch_assoc()):
  $badge = match($r['status']) {
    'open'        => "<span class='badge bg-secondary'>Open</span>",
    'in_progress' => "<span class='badge bg-warning text-dark'>In Progress</span>",
    'closed'      => "<span class='badge bg-info'>Pending Approval</span>",
    'approved'    => "<span class='badge bg-success'>Approved</span>",
    default       => "<span class='badge bg-dark'>Unknown</span>"
  };
?>
<tr>
  <td><?= htmlspecialchars($r['asset_name']) ?></td>
  <td><?= htmlspecialchars($r['issue']) ?></td>
  <td><?= $badge ?></td>
  <td><?= $r['work_started_at'] ? format_dt($r['work_started_at']) : '—' ?></td>
  <td><?= $r['work_closed_at'] ? format_dt($r['work_closed_at']) : '—' ?></td>
</tr>
<?php endwhile; ?>
</tbody>
</table>
</div>
<?php include('../includes/footer.php'); ?>
