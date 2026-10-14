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

$today = date('Y-m-d');


/* =========================================================
   GET ALL NOC & LATEST INSURANCE DETAILS
========================================================= */

$result = $conn->prepare("
    SELECT 
        n.*,
        u.name AS owner_name,
        a.asset_name,
        a.asset_type,
        a.location,
        i.insurance_no,
        i.start_date AS insurance_start_date,
        i.expiry_date AS insurance_expiry_date,
        i.report_file AS insurance_report_file,
        CASE
            WHEN n.noc_expiry_date < CURDATE() THEN 'expired'
            WHEN n.noc_expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 'due'
            ELSE 'active'
        END AS expiry_status
    FROM noc_registration n
    LEFT JOIN users u ON u.id = n.owner_id
    LEFT JOIN assets a ON a.id = n.asset_id
    LEFT JOIN insurance i ON i.id = (
        SELECT i2.id 
        FROM insurance i2 
        WHERE i2.asset_id = n.asset_id 
        ORDER BY i2.id DESC 
        LIMIT 1
    )
    ORDER BY n.noc_expiry_date ASC
");

if (!$result) {
  die("Database error: " . htmlspecialchars($conn->error));
}

$result->execute();
$result = $result->get_result();


/* =========================================================
   HEADER + SIDEBAR
========================================================= */

include('../includes/header.php');
include('../includes/sidebar-admin.php');

?>

<div class="content">

  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h3 class="mb-1">NOC & Insurance List</h3>
      <p class="text-muted mb-0">View and manage all NOC registrations, safety schedules, and insurance details.</p>
    </div>

    <a href="noc-registration.php" class="btn btn-primary">
      <i class="bi bi-plus-circle"></i> Add Details
    </a>
  </div>

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

  <div class="card shadow-sm">

    <div class="card-body p-0">

      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-dark">
            <tr>
              <th>#</th>
              <th>Owner</th>
              <th>Asset</th>
              <th>NOC Details</th>
              <th>Registration</th>
              <th>Safety & Mock Drill</th>
              <th>Insurance Details</th>
              <th>Status</th>
              <th class="text-center">Action</th>
            </tr>
          </thead>

          <tbody>
            <?php
            $i = 1;
            while ($row = $result->fetch_assoc()):

              /* NOC Expiry Badge */
              if ($row['expiry_status'] === 'expired') {
                $badge = '<span class="badge bg-danger">Expired</span>';
              } elseif ($row['expiry_status'] === 'due') {
                $badge = '<span class="badge bg-warning text-dark">Due Soon</span>';
              } else {
                $badge = '<span class="badge bg-success">Active</span>';
              }

              /* Safety & Mock Drill Due Alerts */
              $safety_due = (!empty($row['next_safety_date']) && $row['next_safety_date'] <= $today);
              $mock_due   = (!empty($row['next_mock_drill_date']) && $row['next_mock_drill_date'] <= $today);
            ?>
              <tr class="<?= $row['expiry_status'] === 'expired' ? 'table-danger' : ($row['expiry_status'] === 'due' ? 'table-warning' : '') ?>">

                <td><?= $i++ ?></td>

                <td>
                  <strong><?= htmlspecialchars($row['owner_name'] ?? '-') ?></strong>
                </td>

                <td>
                  <strong><?= htmlspecialchars($row['asset_name'] ?? '-') ?></strong>
                  <br>
                  <small class="text-muted">
                    <?= htmlspecialchars($row['asset_type'] ?? '') ?>
                    <?= !empty($row['location']) ? ' | ' . htmlspecialchars($row['location']) : '' ?>
                  </small>
                </td>

                <td>
                  <strong>No:</strong> <?= htmlspecialchars($row['noc_no']) ?>
                  <br>
                  <small class="text-muted">
                    <strong>Date:</strong> <?= !empty($row['noc_date']) ? date('d-m-Y', strtotime($row['noc_date'])) : '-' ?>
                    <br>
                    <strong>Expiry:</strong> <?= !empty($row['noc_expiry_date']) ? date('d-m-Y', strtotime($row['noc_expiry_date'])) : '-' ?>
                  </small>
                </td>

                <td>
                  <strong>No:</strong> <?= htmlspecialchars($row['registration_no']) ?>
                  <br>
                  <small class="text-muted">
                    <strong>Date:</strong> <?= !empty($row['registration_date']) ? date('d-m-Y', strtotime($row['registration_date'])) : '-' ?>
                  </small>
                </td>

                <td>
                  <small>
                    <strong>Safety:</strong> <?= !empty($row['safety_awareness_date']) ? date('d-m-Y', strtotime($row['safety_awareness_date'])) : '-' ?>
                    <br>
                    <strong>Mock Drill:</strong> <?= !empty($row['mock_drill_date']) ? date('d-m-Y', strtotime($row['mock_drill_date'])) : '-' ?>
                  </small>
                </td>

                <td>
                  <?php if (!empty($row['insurance_no'])): ?>
                    <strong>No:</strong> <?= htmlspecialchars($row['insurance_no']) ?>
                    <br>
                    <small class="text-muted">
                      <strong>Expiry:</strong> <?= !empty($row['insurance_expiry_date']) ? date('d-m-Y', strtotime($row['insurance_expiry_date'])) : '-' ?>
                    </small>

                    <?php if (!empty($row['insurance_report_file'])): ?>
                      <?php
                      $safeFile = basename($row['insurance_report_file']);
                      $filePath = __DIR__ . '/../uploads/insurance/' . $safeFile;
                      ?>
                      <?php if (file_exists($filePath)): ?>
                        <br>
                        <a href="../uploads/insurance/<?= rawurlencode($safeFile) ?>" target="_blank" class="btn btn-sm btn-link p-0 text-decoration-none">
                          👁 View Report
                        </a>
                      <?php else: ?>
                        <br>
                        <span class="badge bg-warning text-dark" style="font-size: 10px;">File Missing</span>
                      <?php endif; ?>
                    <?php endif; ?>

                  <?php else: ?>
                    <span class="badge bg-light text-secondary border">No Active Insurance</span>
                  <?php endif; ?>
                </td>

                <td>
                  <?= $badge ?>

                  <?php if ($safety_due): ?>
                    <br>
                    <span class="badge bg-warning text-dark mt-1" title="Next Safety Due: <?= date('d-m-Y', strtotime($row['next_safety_date'])) ?>">
                      ⚠ Safety Due
                    </span>
                  <?php endif; ?>

                  <?php if ($mock_due): ?>
                    <br>
                    <span class="badge bg-danger mt-1" title="Next Mock Drill Due: <?= date('d-m-Y', strtotime($row['next_mock_drill_date'])) ?>">
                      🚨 Mock Drill Due
                    </span>
                  <?php endif; ?>
                </td>

                <td class="text-center">
                  <a href="noc-registration.php?owner_id=<?= (int)$row['owner_id'] ?>&asset_id=<?= (int)$row['asset_id'] ?>"
                    class="btn btn-sm btn-primary mb-1"
                    title="Edit NOC & Insurance">
                    Edit
                  </a>

                  <a href="noc-delete.php?id=<?= (int)$row['id'] ?>"
                    class="btn btn-sm btn-danger mb-1"
                    onclick="return confirm('Are you sure you want to delete this NOC record?');"
                    title="Delete Record">
                    Delete
                  </a>
                </td>

              </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>

    </div>

  </div>

</div>

<?php include('../includes/footer.php'); ?>