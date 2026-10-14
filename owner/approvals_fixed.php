<?php
include('../config/db.php');
include('../config/auth.php');

require_role('owner');

include('../includes/header.php');
include('../includes/sidebar-owner.php');

$owner_id = (int)$_SESSION['uid'];

$stmt = $conn->prepare("
    SELECT mj.id, mj.checklist, mj.remarks, mj.file, mj.created_at, mj.updated_at, mj.status, a.asset_name
    FROM maintenance_jobs mj
    JOIN assets a ON a.id = mj.asset_id
    WHERE a.owner_id = ?
    ORDER BY 
        CASE mj.status 
            WHEN 'pending_owner' THEN 1 
            WHEN 'approved' THEN 2 
            WHEN 'rejected' THEN 3 
            ELSE 4 
        END,
        mj.created_at DESC
");
$stmt->bind_param("i", $owner_id); $stmt->execute();
$q = $stmt->get_result();

function getStatusBadge($status) {
    switch($status) {
        case 'pending_owner': return '<span class="badge bg-warning text-dark">Pending</span>';
        case 'approved': return '<span class="badge bg-success">Approved</span>';
        case 'rejected': return '<span class="badge bg-danger">Rejected</span>';
        default: return '<span class="badge bg-secondary">'.$status.'</span>';
    }
}
?>

<div class="content">
<h3>Checklist Approvals</h3>

<?php if (!empty($_SESSION['success'])): ?>
<div class="alert alert-success alert-dismissible fade show">
  <?= htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
  <button type="button" class="btn-close" onclick="this.closest('.alert').remove()"></button>
</div>
<?php endif; ?>

<?php if (!empty($_SESSION['error'])): ?>
<div class="alert alert-danger alert-dismissible fade show">
  <?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
  <button type="button" class="btn-close" onclick="this.closest('.alert').remove()"></button>
</div>
<?php endif; ?>

<table class="table table-hover bg-white shadow mt-3">
<thead class="table-light">
<tr>
  <th>Lift</th><th>Status</th><th>Submitted</th><th>Approved</th><th>Checklist</th><th>Remarks</th><th>File</th><th width="180">Action</th>
</tr>
</thead>
<tbody>

<?php if ($q->num_rows === 0): ?>
<tr><td colspan="8" class="text-center text-muted">No maintenance records found</td></tr>
<?php endif; ?>

<?php while ($r = $q->fetch_assoc()): ?>
<tr>
  <td><strong><?= htmlspecialchars($r['asset_name']) ?></strong></td>
  <td><?= getStatusBadge($r['status']) ?></td>
<td><?= format_dt($r['created_at']) ?></td>
   <td>
     <?php if ($r['status'] === 'approved' && !empty($r['updated_at'])): ?>
       <?= format_dt($r['updated_at']) ?>
     <?php else: ?>
       <span class="text-muted">—</span>
     <?php endif; ?>
  </td>
  <td>
    <?php
    $list = json_decode($r['checklist'], true);
    if (is_array($list) && count($list)) {
        foreach ($list as $c) echo "✔ " . htmlspecialchars($c) . "<br>";
    } else {
        echo "<span class='text-muted'>No checklist</span>";
    }
    ?>
  </td>
  <td><?= nl2br(htmlspecialchars($r['remarks'] ?? '—')) ?></td>
  <td>
    <?php if (!empty($r['file'])): ?>
      <?php 
      $filePath = __DIR__ . '/../uploads/' . $r['file'];
      if (file_exists($filePath)): 
      ?>
        <a href="../uploads/<?= htmlspecialchars($r['file']) ?>" target="_blank" class="btn btn-outline-primary btn-sm">View</a>
      <?php else: ?>
        <span class="badge bg-warning text-dark" title="File missing">File Not Found</span>
      <?php endif; ?>
    <?php else: ?>
      <span class="text-muted">No file</span>
    <?php endif; ?>
  </td>
  <td>
    <?php if ($r['status'] === 'pending_owner'): ?>
      <a href="approve.php?job_id=<?= $r['id'] ?>" class="btn btn-success btn-sm mb-1">Approve</a>
      <a href="reject.php?job_id=<?= $r['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Reject this maintenance?')">Reject</a>
    <?php else: ?>
      <span class="text-muted">-</span>
    <?php endif; ?>
  </td>
</tr>
<?php endwhile; ?>

</tbody>
</table>
</div>

<?php include('../includes/footer.php'); ?>