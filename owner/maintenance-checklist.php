<?php

include('../config/db.php');
include('../config/auth.php');
require_once('../config/security.php');

require_role('owner');

$owner_id = (int)($_SESSION['uid'] ?? 0);

if ($owner_id <= 0) {
    header('Location: dashboard.php');
    exit;
}

$stmt = $conn->prepare("
    SELECT
        mj.id,
        mj.asset_id,
        mj.remarks,
        mj.created_at,
        mj.status,
        mj.job_type,
        a.asset_name,
        a.location,
        u.name AS technician_name
    FROM maintenance_jobs mj
    INNER JOIN assets a
        ON a.id = mj.asset_id
    LEFT JOIN users u
        ON u.id = mj.technician_id
    WHERE
        a.owner_id = ?
        AND LOWER(TRIM(COALESCE(mj.job_type, ''))) <> 'saf_inspection'
        AND mj.status IN ('pending_owner', 'approved', 'rejected')
    ORDER BY
        CASE
            WHEN mj.status = 'pending_owner' THEN 0
            WHEN mj.status = 'rejected' THEN 1
            ELSE 2
        END,
        mj.created_at DESC,
        mj.id DESC
");

if (!$stmt) {
    die('Unable to load maintenance checklists: ' . htmlspecialchars($conn->error));
}

$stmt->bind_param('i', $owner_id);
$stmt->execute();
$q = $stmt->get_result();

function maintenanceStatusBadge(string $status): string
{
    switch ($status) {
        case 'pending_owner':
            return '<span class="badge bg-warning text-dark">Pending Approval</span>';
        case 'approved':
            return '<span class="badge bg-success">Approved</span>';
        case 'rejected':
            return '<span class="badge bg-danger">Rejected</span>';
        default:
            return '<span class="badge bg-secondary">' .
                htmlspecialchars(ucfirst(str_replace('_', ' ', $status)), ENT_QUOTES, 'UTF-8') .
                '</span>';
    }
}

function maintenanceDateIST(?string $value): string
{
    if (empty($value)) {
        return '—';
    }

    try {
        $dt = new DateTime($value, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('Asia/Kolkata'));
        return htmlspecialchars($dt->format('d M Y h:i A') . ' IST', ENT_QUOTES, 'UTF-8');
    } catch (Exception $e) {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

include('../includes/header.php');
include('../includes/sidebar-owner.php');
?>

<div class="content">

<style>
.maintenance-checklist-page h3 {
    font-weight: 700;
    color: #172033;
    margin-bottom: 20px;
}
.maintenance-card {
    background: #fff;
    border: 1px solid #e8edf3;
    border-radius: 12px;
    box-shadow: 0 4px 18px rgba(0,0,0,.07);
    overflow: hidden;
}
.maintenance-table {
    margin: 0;
}
.maintenance-table thead th {
    background: #f6f8fb;
    color: #26364d;
    font-weight: 700;
    white-space: nowrap;
    padding: 14px;
}
.maintenance-table tbody td {
    padding: 15px 14px;
    vertical-align: middle;
    border-color: #edf0f4;
}
.maintenance-table tbody tr:hover {
    background: #f9fbfd;
}
.pending-row {
    background: #fffdf4;
}
.asset-name {
    font-weight: 700;
    color: #172033;
}
.view-maintenance-btn {
    min-width: 125px;
    font-weight: 600;
}
.empty-maintenance {
    padding: 50px 20px !important;
}
</style>

<h3>
    <i class="bi bi-clipboard2-check me-2"></i>
    Maintenance Checklist
</h3>

<?php if (!empty($_SESSION['success'])): ?>
<div class="alert alert-success alert-dismissible fade show">
    <?= htmlspecialchars($_SESSION['success']); ?>
    <?php unset($_SESSION['success']); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if (!empty($_SESSION['error'])): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <?= htmlspecialchars($_SESSION['error']); ?>
    <?php unset($_SESSION['error']); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="alert alert-info shadow-sm">
    <strong>Maintenance Checklist:</strong>
    Technician/AMC maintenance checklists are shown here separately.
    SAF inspection checklists remain under <strong>Checklist Approvals</strong>.
</div>

<div class="maintenance-card table-responsive">
<table class="table table-hover maintenance-table">
<thead>
<tr>
    <th>Lift</th>
    <th>Location</th>
    <th>Technician</th>
    <th>Status</th>
    <th>Submitted</th>
    <th>Remarks</th>
    <th>Action</th>
</tr>
</thead>
<tbody>

<?php if ($q->num_rows === 0): ?>
<tr>
    <td colspan="7" class="text-center text-muted empty-maintenance">
        <i class="bi bi-clipboard-x fs-3 d-block mb-2"></i>
        No maintenance checklist submitted yet.
    </td>
</tr>
<?php else: ?>

<?php while ($r = $q->fetch_assoc()): ?>
<tr class="<?= $r['status'] === 'pending_owner' ? 'pending-row' : '' ?>">

    <td class="asset-name">
        <?= htmlspecialchars($r['asset_name'] ?? '—') ?>
    </td>

    <td>
        <?= htmlspecialchars($r['location'] ?? '—') ?>
    </td>

    <td>
        <?= htmlspecialchars($r['technician_name'] ?? '—') ?>
    </td>

    <td>
        <?= maintenanceStatusBadge((string)$r['status']) ?>
    </td>

    <td>
        <?= maintenanceDateIST($r['created_at'] ?? null) ?>
    </td>

    <td>
        <?php if (!empty(trim($r['remarks'] ?? ''))): ?>
            <?= nl2br(htmlspecialchars($r['remarks'], ENT_QUOTES, 'UTF-8')) ?>
        <?php else: ?>
            <span class="text-muted">—</span>
        <?php endif; ?>
    </td>

    <td>
        <a
            href="view-maintenance-checklist.php?job_id=<?= (int)$r['id'] ?>"
            class="btn btn-primary btn-sm view-maintenance-btn"
        >
            <i class="bi bi-eye me-1"></i>
            View Checklist
        </a>
    </td>

</tr>
<?php endwhile; ?>

<?php endif; ?>

</tbody>
</table>
</div>

</div>

<?php include('../includes/footer.php'); ?>
