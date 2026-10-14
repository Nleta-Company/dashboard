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
requireOwnerAccess('noc_history');

$owner_id = (int)($_SESSION['uid'] ?? 0);


/* =========================================================
   GET COMPLETE NOC & LATEST INSURANCE HISTORY
========================================================= */

$nocs = [];

$stmt = $conn->prepare("
    SELECT
        n.id AS noc_id,
        n.asset_id,
        n.noc_no,
        n.noc_date,
        n.noc_expiry_date,
        n.registration_no,
        n.registration_date,
        n.safety_awareness_date,
        n.mock_drill_date,

        a.asset_name,
        a.asset_type,
        a.location,

        i.insurance_no,
        i.start_date AS insurance_start_date,
        i.expiry_date AS insurance_expiry_date,
        i.report_file AS insurance_report_file

    FROM noc_registration n

    INNER JOIN assets a
        ON a.id = n.asset_id

    LEFT JOIN insurance i
        ON i.id = (
            SELECT i2.id
            FROM insurance i2
            WHERE i2.asset_id = a.id
            ORDER BY i2.id DESC
            LIMIT 1
        )

    WHERE n.owner_id = ?
      AND a.owner_id = ?

    ORDER BY n.noc_date DESC, n.id DESC
");

if (!$stmt) {
    die("Database error: " . htmlspecialchars($conn->error));
}

$stmt->bind_param("ii", $owner_id, $owner_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $nocs[] = $row;
}


/* =========================================================
   HEADER + SIDEBAR
========================================================= */

include('../includes/header.php');
include('../includes/sidebar-owner.php');

?>

<div class="content">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h3 class="mb-1">
                NOC & Insurance History
            </h3>
            <p class="text-muted mb-0">
                View all NOC registration and insurance details for your assets.
            </p>
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

        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <strong>NOC & Insurance Overview</strong>
            <span class="badge bg-primary">
                <?= count($nocs) ?> Record<?= count($nocs) !== 1 ? 's' : '' ?>
            </span>
        </div>

        <div class="card-body p-0">

            <?php if (empty($nocs)): ?>

                <div class="text-center p-5">
                    <i class="bi bi-file-earmark-x" style="font-size: 45px;"></i>
                    <h5 class="mt-3">No NOC records found</h5>
                    <p class="text-muted">No NOC or insurance details have been registered for your lifts yet.</p>
                    <a href="noc-registration.php" class="btn btn-primary">Add NOC Details</a>
                </div>

            <?php else: ?>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Lift / Asset</th>
                                <th>NOC Details</th>
                                <th>Registration</th>
                                <th>Safety & Mock Drill</th>
                                <th>Insurance Details</th>
                                <th>Status</th>
                            </tr>
                        </thead>

                        <tbody>
                        <?php foreach ($nocs as $index => $noc): ?>

                            <?php
                            /* Calculate NOC Expiry Status */
                            $nocStatus = 'Active';
                            $nocBadgeClass = 'bg-success';
                            $daysLeft = null;

                            if (!empty($noc['noc_expiry_date'])) {
                                $expiryTimestamp = strtotime($noc['noc_expiry_date']);
                                $todayTimestamp  = strtotime(date('Y-m-d'));
                                $daysLeft        = floor(($expiryTimestamp - $todayTimestamp) / 86400);

                                if ($daysLeft < 0) {
                                    $nocStatus = 'Expired';
                                    $nocBadgeClass = 'bg-danger';
                                } elseif ($daysLeft <= 30) {
                                    $nocStatus = 'Due Soon';
                                    $nocBadgeClass = 'bg-warning text-dark';
                                }
                            }
                            ?>

                            <tr>
                                <td><?= $index + 1 ?></td>

                                <td>
                                    <strong><?= htmlspecialchars($noc['asset_name']) ?></strong>
                                    <br>
                                    <small class="text-muted">
                                        <?= htmlspecialchars($noc['asset_type']) ?>
                                        <?= !empty($noc['location']) ? ' | ' . htmlspecialchars($noc['location']) : '' ?>
                                    </small>
                                </td>

                                <td>
                                    <strong>No:</strong> <?= htmlspecialchars($noc['noc_no']) ?>
                                    <br>
                                    <small class="text-muted">
                                        <strong>Date:</strong> <?= !empty($noc['noc_date']) ? date('d-m-Y', strtotime($noc['noc_date'])) : '-' ?>
                                        <br>
                                        <strong>Expiry:</strong> <?= !empty($noc['noc_expiry_date']) ? date('d-m-Y', strtotime($noc['noc_expiry_date'])) : '-' ?>
                                    </small>
                                </td>

                                <td>
                                    <strong>No:</strong> <?= htmlspecialchars($noc['registration_no']) ?>
                                    <br>
                                    <small class="text-muted">
                                        <strong>Date:</strong> <?= !empty($noc['registration_date']) ? date('d-m-Y', strtotime($noc['registration_date'])) : '-' ?>
                                    </small>
                                </td>

                                <td>
                                    <small>
                                        <strong>Safety:</strong> <?= !empty($noc['safety_awareness_date']) ? date('d-m-Y', strtotime($noc['safety_awareness_date'])) : '-' ?>
                                        <br>
                                        <strong>Mock Drill:</strong> <?= !empty($noc['mock_drill_date']) ? date('d-m-Y', strtotime($noc['mock_drill_date'])) : '-' ?>
                                    </small>
                                </td>

                                <td>
                                    <?php if (!empty($noc['insurance_no'])): ?>
                                        <strong>No:</strong> <?= htmlspecialchars($noc['insurance_no']) ?>
                                        <br>
                                        <small class="text-muted">
                                            <strong>Expiry:</strong> <?= !empty($noc['insurance_expiry_date']) ? date('d-m-Y', strtotime($noc['insurance_expiry_date'])) : '-' ?>
                                        </small>

                                        <?php if (!empty($noc['insurance_report_file'])): ?>
                                            <?php
                                            $safeFile = basename($noc['insurance_report_file']);
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
                                    <span class="badge <?= $nocBadgeClass ?>">
                                        <?= $nocStatus ?>
                                    </span>
                                    <?php if ($daysLeft !== null && $daysLeft >= 0 && $daysLeft <= 30): ?>
                                        <br>
                                        <small class="text-muted" style="font-size: 11px;">
                                            <?= $daysLeft ?> day<?= $daysLeft != 1 ? 's' : '' ?> left
                                        </small>
                                    <?php endif; ?>
                                </td>
                            </tr>

                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php endif; ?>

        </div>

    </div>

</div>

<?php include('../includes/footer.php'); ?>