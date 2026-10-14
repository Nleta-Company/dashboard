<?php
/* NLETA OWNER — CONDITIONAL ACCEPTANCE DECISION */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/security.php';
require_role('owner');
date_default_timezone_set('Asia/Kolkata');

function backToChecklist(int $jobId, string $message, bool $success = false): void {
    $_SESSION[$success ? 'owner_pat_success' : 'owner_pat_error'] = $message;
    $return = basename((string)($_POST['ca_return'] ?? 'view-checklist.php'));
    if (!preg_match('/^[A-Za-z0-9._-]+\.php$/', $return)) $return = 'view-checklist.php';
    header('Location: ' . $return . '?job_id=' . $jobId, true, 303);
    exit;
}

$ownerId = (int)($_SESSION['uid'] ?? $_SESSION['user_id'] ?? 0);
$jobId = (int)($_POST['job_id'] ?? 0);
$action = strtolower(trim((string)($_POST['owner_action'] ?? '')));
$caRef = trim((string)($_POST['ca_ref'] ?? ''));
$caFloor = trim((string)($_POST['ca_floor'] ?? ''));
$reason = trim((string)($_POST['ca_reject_reason'] ?? ''));

if ($ownerId <= 0 || $jobId <= 0 || !in_array($action, ['ca_approve','ca_reject'], true) || $caRef === '') {
    backToChecklist($jobId, 'Invalid Conditional Acceptance request.');
}
if (!function_exists('validateCSRFToken') || !validateCSRFToken((string)($_POST['csrf_token'] ?? ''))) {
    backToChecklist($jobId, 'Invalid security token. Please refresh and try again.');
}
if ($action === 'ca_reject' && $reason === '') {
    backToChecklist($jobId, 'Please enter a reason before rejecting this Conditional Acceptance.');
}

try {
    $conn->begin_transaction();

    $stmt = $conn->prepare("SELECT id, owner_id, checklist FROM maintenance_jobs WHERE id = ? AND owner_id = ? AND job_type = 'saf_inspection' LIMIT 1 FOR UPDATE");
    if (!$stmt) throw new RuntimeException('Unable to verify inspection job: ' . $conn->error);
    $stmt->bind_param('ii', $jobId, $ownerId);
    if (!$stmt->execute()) throw new RuntimeException('Unable to verify inspection job: ' . $stmt->error);
    $job = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$job) throw new RuntimeException('Inspection job not found or access denied.');

    $checklist = [];
    if (!empty($job['checklist'])) {
        $decoded = json_decode((string)$job['checklist'], true);
        if (is_array($decoded)) $checklist = $decoded;
    }

    $target = null;
    if ($caFloor !== '') {
        foreach (['floor_checklist','floor_results','floor_matrix'] as $container) {
            if (isset($checklist[$container][$caFloor][$caRef]) && is_array($checklist[$container][$caFloor][$caRef])) {
                $target =& $checklist[$container][$caFloor][$caRef];
                break;
            }
        }
    } else {
        if (isset($checklist['results'][$caRef]) && is_array($checklist['results'][$caRef])) {
            $target =& $checklist['results'][$caRef];
        }
    }
    if ($target === null) throw new RuntimeException('Conditional Acceptance checkpoint was not found.');

    if (strtoupper(trim((string)($target['result'] ?? ''))) !== 'CA') {
        throw new RuntimeException('Only a checkpoint currently marked CA can receive an Owner decision.');
    }

    $existing = (isset($target['owner_ca_decision']) && is_array($target['owner_ca_decision'])) ? $target['owner_ca_decision'] : [];
    $decision = ($action === 'ca_approve') ? 'approved' : 'rejected';
    if (($existing['status'] ?? '') === 'approved' && $decision === 'approved') {
        throw new RuntimeException('This Conditional Acceptance is already approved by the Owner.');
    }

    $target['owner_ca_decision'] = [
        'status' => $decision,
        'owner_id' => $ownerId,
        'decided_at_ist' => date('Y-m-d H:i:s'),
        'timezone' => 'Asia/Kolkata',
        'decision_text' => $decision === 'approved'
            ? 'Owner Approved — Conditional Acceptance permitted.'
            : 'Owner Rejected — Conditional Acceptance not permitted.',
        'reason' => $decision === 'rejected' ? $reason : ''
    ];
    unset($target);

    $json = json_encode($checklist, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) throw new RuntimeException('Unable to encode checklist.');

    $save = $conn->prepare("UPDATE maintenance_jobs SET checklist = ?, updated_at = NOW() WHERE id = ? AND owner_id = ? AND job_type = 'saf_inspection' LIMIT 1");
    if (!$save) throw new RuntimeException('Unable to prepare CA save: ' . $conn->error);
    $save->bind_param('sii', $json, $jobId, $ownerId);
    if (!$save->execute() || $save->affected_rows < 1) throw new RuntimeException('Owner CA decision could not be saved.');
    $save->close();

    $conn->commit();
    backToChecklist($jobId, $decision === 'approved'
        ? 'Conditional Acceptance approved by Owner. Owner permission has been permanently recorded for this checkpoint.'
        : 'Conditional Acceptance rejected by Owner. The rejection reason has been recorded.', true);

} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignore) {}
    error_log('NLETA Owner CA Decision Error: ' . $e->getMessage());
    backToChecklist($jobId, $e->getMessage());
}
