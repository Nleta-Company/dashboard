<?php
include('../config/db.php');
include('../config/auth.php');
require_once '../config/security.php';

require_role('technician');

$tech_id = (int)$_SESSION['uid'];
$id      = (int)($_GET['id'] ?? 0);
if (!$id) { header("Location: callbacks.php"); exit; }

$stmt = $conn->prepare("SELECT c.id, c.callback_number, c.issue, c.status, c.work_report, c.checklist, c.work_report_file, COALESCE(a.asset_name, 'General Check') AS asset_name FROM callbacks c LEFT JOIN assets a ON a.id=c.asset_id WHERE c.id=? AND c.technician_id=? AND c.status IN ('open','in_progress','closed')");
$stmt->bind_param("ii", $id, $tech_id);
$stmt->execute();
$cb = $stmt->get_result()->fetch_assoc();
if (!$cb) { header("Location: callbacks.php"); exit; }

$checklistItems = [
    'Original complaint is fully resolved and does not reoccur',
    'Lift operates smoothly without jerk, vibration, or abnormal noise',
    'Doors lock properly before movement',
    'Door safety sensor works (door reopens when obstructed)',
    'Accurate floor leveling, especially at the affected floor(s)',
    'Affected cabin / landing buttons are responsive',
    'Alarm bell is functional',
    'Emergency light is available',
    'Trial ride conducted with technician (Up & Down)',
    'Call-back entry updated in logbook with technician signature'
];

$selectedChecklist = json_decode($cb['checklist'] ?? '[]', true);
if (!is_array($selectedChecklist)) $selectedChecklist = [];

$error = '';

if (isset($_POST['submit'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $enteredCbNo = trim($_POST['verify_callback_number'] ?? '');
        if ($enteredCbNo !== $cb['callback_number']) {
            $error = "Incorrect callback number. Please enter the correct callback number to submit.";
        }

        if (empty($error)) {
            $report    = trim($_POST['work_report']);
            $checklist = json_encode($_POST['checklist'] ?? []);
            $fileName  = $cb['work_report_file'] ?? null;

            if ($report === '') {
                $error = "Work report is required";
            }

            if (empty($error) && !empty($_FILES['report_file']['name'])) {
                $allowed     = ['pdf','jpg','jpeg','png','doc','docx'];
                $allowedMime = ['application/pdf','image/jpeg','image/png','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
                $ext  = strtolower(pathinfo($_FILES['report_file']['name'], PATHINFO_EXTENSION));
                $mime = mime_content_type($_FILES['report_file']['tmp_name']);
                if (!in_array($ext, $allowed) || !in_array($mime, $allowedMime)) {
                    $error = "Invalid file type";
                } elseif ($_FILES['report_file']['size'] > 7242880) {
                    $error = "File too large. Max 7MB.";
                } else {
                    $uploadDir = __DIR__ . '/../uploads/callback_reports/';
                    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                    $fileName = 'CB_' . preg_replace('/[^A-Za-z0-9_\-]/', '', $cb['callback_number']) . '_' . time() . '.' . $ext;
                    move_uploaded_file($_FILES['report_file']['tmp_name'], $uploadDir . $fileName);
                }
            }

            if (empty($error)) {
                $upd = $conn->prepare("UPDATE callbacks SET work_report=?, checklist=?, status='closed', work_closed_at=NOW(), work_report_file=? WHERE id=? AND technician_id=?");
                $upd->bind_param("sssii", $report, $checklist, $fileName, $id, $tech_id);
                $upd->execute();

                // Email to owner
                require_once '../config/email.php';
                $ownerRow = $conn->prepare(
                    "SELECT u.name, u.email FROM users u
                     JOIN assets a ON a.owner_id = u.id
                     JOIN callbacks c ON c.asset_id = a.id
                     WHERE c.id = ? LIMIT 1"
                );
                $ownerRow->bind_param("i", $id);
                $ownerRow->execute();
                $owner = $ownerRow->get_result()->fetch_assoc();
                if ($owner && !empty($owner['email'])) {
                    $msg = "NLETA Report Submitted\nRef: {$cb['callback_number']}\nLift: {$cb['asset_name']}\nTechnician has submitted the work report. Please login to review and approve.";
                    sendEmail($owner['email'], $owner['name'], 'NLETA Report Submitted - ' . $cb['callback_number'], $msg);
                }

                $_SESSION['success'] = "Report submitted. Waiting for owner approval.";
                header("Location: callbacks.php"); exit;
            }
        }
    }
}

include('../includes/header.php');
include('../includes/sidebar-tech.php');
?>

<div class="content">
<h3 class="mb-3"><?= ($cb['status'] === 'closed') ? 'Re-Submit Callback Report' : 'Submit Callback Report' ?></h3>

<div class="card shadow p-4" style="max-width:800px">
<p><b>Callback ID:</b> <?= htmlspecialchars($cb['callback_number']) ?></p>
<p><b>Lift:</b> <?= htmlspecialchars($cb['asset_name']) ?></p>
<p><b>Issue:</b> <?= htmlspecialchars($cb['issue']) ?></p>

<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show">
  <?= htmlspecialchars($error) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data">
<?= csrfField() ?>

<div class="mb-3">
  <label class="form-label fw-bold">Enter Callback Number to Confirm <span class="text-danger">*</span></label>
  <input type="text" name="verify_callback_number" class="form-control"
         placeholder="e.g. NLETA-26-06-07-001" required
         value="<?= htmlspecialchars($_POST['verify_callback_number'] ?? '') ?>">
  <small class="text-muted">You must enter the correct callback number to submit this report.</small>
</div>

<label class="fw-bold mb-2">Checklist</label>
<?php foreach ($checklistItems as $item): ?>
<div class="form-check">
  <input class="form-check-input" type="checkbox" name="checklist[]"
         value="<?= htmlspecialchars($item) ?>"
         <?= in_array($item, $selectedChecklist) ? 'checked' : '' ?>>
  <label class="form-check-label"><?= htmlspecialchars($item) ?></label>
</div>
<?php endforeach; ?>

<div class="mt-3">
  <label class="form-label">Work Report</label>
  <textarea name="work_report" class="form-control" rows="5" required><?= htmlspecialchars($cb['work_report'] ?? '') ?></textarea>
</div>

<?php if (!empty($cb['work_report_file'])): ?>
<p class="mt-3 mb-1">Existing File: <a href="../uploads/callback_reports/<?= htmlspecialchars($cb['work_report_file']) ?>" target="_blank">View</a></p>
<?php endif; ?>

<input type="file" name="report_file" class="form-control mt-2" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">

<button name="submit" class="btn btn-success mt-4">
  <?= ($cb['status'] === 'closed') ? 'Re-Submit Report' : 'Submit Report' ?>
</button>
<a href="callbacks.php" class="btn btn-secondary mt-4 ms-2">Cancel</a>
</form>
</div>
</div>

<?php include('../includes/footer.php'); ?>
