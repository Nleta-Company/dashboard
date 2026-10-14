<?php
/* =========================================================
   NLETA INSPECTOR — ESCALATOR SAT CHECKLIST
   ---------------------------------------------------------
   ECHK-02 — Rev.1
   Asset Type: ESCALATOR / TRAVELLATOR
   Module: Site Acceptance Test (SAT)

   SOURCE:
   ECHK_02_SAT Checklist.docx

   FIXED CHECKPOINTS:
   E-SAT-001 -> E-SAT-038

   RESULT CODES:
   C  = Complied
   NC = Non-Complied
   NA = Not Applicable
   CA = Conditional Acceptance

   UI:
   - Same UI/UX pattern as Escalator PAT checklist.
   - SAT-specific readings/units are preserved.
   - Escalator checklist can never open a Lift checklist.

   MANDATORY RULES:
   NC -> Photograph REQUIRED
   NC -> Engineering Remark REQUIRED
   CA -> Photograph REQUIRED + Technical Justification REQUIRED
   Numeric / OK-Low readings -> REQUIRED where defined by source.

   FINAL LOCK:
   submitted / final_submitted assignment -> view-only.

   TIMEZONE:
   Asia/Kolkata (IST)
========================================================= */

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

if (file_exists(__DIR__ . '/../config/security.php')) {
    require_once __DIR__ . '/../config/security.php';
}

require_role('inspector');
date_default_timezone_set('Asia/Kolkata');

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$inspectorId = (int)($_SESSION['uid'] ?? $_SESSION['user_id'] ?? 0);
$jobId = (int)($_GET['job_id'] ?? $_POST['job_id'] ?? 0);
$assignmentId = (int)($_GET['assignment_id'] ?? $_POST['assignment_id'] ?? 0);

if ($inspectorId <= 0) {
    header('Location: dashboard.php');
    exit;
}

if ($jobId <= 0) {
    header('Location: inspections.php');
    exit;
}

/* =========================================================
   LOAD ASSIGNED ESCALATOR SAT JOB
========================================================= */

$sql = "
    SELECT
        mj.id AS job_id,
        mj.saf_id,
        mj.service_id,
        mj.asset_id,
        mj.owner_id,
        mj.status AS job_status,
        mj.job_type,
        mj.checklist AS saved_checklist,

        ia.id AS assignment_id,
        ia.inspector_id,
        ia.status AS assignment_status,
        ia.assigned_at,
        ia.started_at,

        s.psga_no,
        s.saf_no,
        s.service_code,
        s.service_name,
        s.proposed_inspection_date,
        s.status AS saf_status,

        owner.name AS owner_name,
        owner.email AS owner_email,

        a.asset_name,
        a.display_asset_id,
        a.location,
        a.asset_type,
        a.category

    FROM inspector_assignments ia

    INNER JOIN maintenance_jobs mj
        ON mj.id = ia.job_id

    INNER JOIN saf_contracts s
        ON s.id = mj.saf_id

    LEFT JOIN users owner
        ON owner.id = mj.owner_id

    LEFT JOIN assets a
        ON a.id = mj.asset_id

    WHERE
        ia.job_id = ?
        AND ia.inspector_id = ?
        AND mj.job_type = 'saf_inspection'

    LIMIT 1
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die(
        'Escalator SAT Database Error — Prepare failed: ' .
        h($conn->error)
    );
}

$stmt->bind_param('ii', $jobId, $inspectorId);

if (!$stmt->execute()) {
    die(
        'Escalator SAT Database Error — Execute failed: ' .
        h($stmt->error)
    );
}

$job = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$job) {
    header('Location: inspections.php');
    exit;
}

$realAssignmentId = (int)($job['assignment_id'] ?? 0);

if (
    $assignmentId > 0 &&
    $realAssignmentId > 0 &&
    $assignmentId !== $realAssignmentId
) {
    die('Invalid Inspector assignment.');
}

$assignmentId = $realAssignmentId;

/* =========================================================
   ESCALATOR / TRAVELLATOR ONLY
========================================================= */

$assetType = strtolower(trim((string)($job['asset_type'] ?? '')));

if (
    !str_contains($assetType, 'escalator') &&
    !str_contains($assetType, 'travellator')
) {
    die('This checklist is only available for Escalator / Travellator assets.');
}

/* =========================================================
   SAT ONLY
========================================================= */

$serviceCode = strtoupper(trim((string)($job['service_code'] ?? '')));

if ($serviceCode === '') {
    $serviceId = (int)($job['service_id'] ?? 0);

    if ($serviceId > 0) {
        $serviceStmt = $conn->prepare("
            SELECT service_code, service_name
            FROM service_catalog
            WHERE id = ?
            LIMIT 1
        ");

        if ($serviceStmt) {
            $serviceStmt->bind_param('i', $serviceId);

            if ($serviceStmt->execute()) {
                $serviceRow = $serviceStmt->get_result()->fetch_assoc();

                if ($serviceRow) {
                    $serviceCode = strtoupper(
                        trim((string)($serviceRow['service_code'] ?? ''))
                    );

                    if (trim((string)($job['service_name'] ?? '')) === '') {
                        $job['service_name'] =
                            (string)($serviceRow['service_name'] ?? '');
                    }
                }
            }

            $serviceStmt->close();
        }
    }
}

if ($serviceCode !== 'SAT') {
    die(
        'This checklist is configured for Escalator SAT service only. '
        . 'Assigned service: ' . h($serviceCode ?: 'Unknown')
    );
}

/* =========================================================
   SAF STATUS
========================================================= */

$safStatus = strtolower(trim((string)($job['saf_status'] ?? '')));

if ($safStatus !== '' && $safStatus !== 'accepted') {
    die('The SAF must be accepted by the Owner before inspection.');
}

/* =========================================================
   STATUS / FINAL LOCK
========================================================= */

$jobStatus = strtolower(trim((string)($job['job_status'] ?? '')));
$assignmentStatus = strtolower(trim((string)($job['assignment_status'] ?? '')));

$allowedJobStatuses = [
    'assigned',
    'in_progress',
    'pending_owner',
    'approved',
    'closed',
    'completed',
    'submitted'
];

$allowedAssignmentStatuses = [
    'assigned',
    'in_progress',
    'pending_owner',
    'approved',
    'closed',
    'completed',
    'submitted',
    'final_submitted'
];

if (
    !in_array($jobStatus, $allowedJobStatuses, true) &&
    !in_array($assignmentStatus, $allowedAssignmentStatuses, true)
) {
    die(
        'This Escalator SAT inspection is not available for checklist work. '
        . 'Current status: ' .
        h($job['job_status'] ?? $job['assignment_status'] ?? 'unknown')
    );
}

$isFinalSubmitted = in_array(
    $assignmentStatus,
    ['submitted', 'final_submitted'],
    true
);

/* =========================================================
   SITE ATTENDANCE OTP GUARD
========================================================= */

$attendanceVerified = false;

$attendanceCheck = $conn->prepare("
    SELECT verified_at
    FROM inspector_attendance
    WHERE
        assignment_id = ?
        AND job_id = ?
        AND inspector_id = ?
        AND owner_id = ?
    LIMIT 1
");

if ($attendanceCheck) {
    $ownerIdForAttendance = (int)($job['owner_id'] ?? 0);

    $attendanceCheck->bind_param(
        'iiii',
        $assignmentId,
        $jobId,
        $inspectorId,
        $ownerIdForAttendance
    );

    if ($attendanceCheck->execute()) {
        $attendanceRow = $attendanceCheck->get_result()->fetch_assoc();
        $attendanceVerified = !empty($attendanceRow['verified_at']);
    }

    $attendanceCheck->close();
}

if (!$attendanceVerified) {
    $_SESSION['error'] =
        'Owner site attendance OTP is not verified. Please complete attendance verification first.';
    header('Location: inspections.php');
    exit;
}

/* =========================================================
   ECHK-02 — EXACT 38 SOURCE CHECKPOINTS
========================================================= */

$sections = [
    'A' => [
        'title' => 'SECTION A — FUNCTIONAL OPERATION TEST',
        'items' => [
            ['E-SAT-001', 'Travel direction operation', 'Pass / Fail'],
            ['E-SAT-002', 'Start & stop operation', 'Pass / Fail'],
            ['E-SAT-003', 'Normal running performance', 'Pass / Fail'],
            ['E-SAT-004', 'Reverse direction operation', 'Pass / Fail'],
            ['E-SAT-005', 'Noise & vibration during operation', 'Pass / Fail'],
            ['E-SAT-006', 'Abnormal mechanical sound', 'Pass / Fail'],
        ],
    ],
    'B' => [
        'title' => 'SECTION B — BRAKE & DRIVE SYSTEM',
        'items' => [
            ['E-SAT-007', 'Brake slip distance', 'mm'],
            ['E-SAT-008', 'Brake engagement performance', 'Pass / Fail'],
            ['E-SAT-009', 'Brake release operation', 'Pass / Fail'],
            ['E-SAT-010', 'Drive chain condition', 'Pass / Fail'],
            ['E-SAT-011', 'Drive chain tension', 'mm'],
            ['E-SAT-012', 'Gearbox oil level', 'OK / Low'],
            ['E-SAT-013', 'Gearbox oil condition', 'Pass / Fail'],
            ['E-SAT-014', 'Drive shaft alignment', 'Pass / Fail'],
        ],
    ],
    'C' => [
        'title' => 'SECTION C — STEP & HANDRAIL MEASUREMENTS',
        'items' => [
            ['E-SAT-015', 'Handrail speed equality', '%'],
            ['E-SAT-016', 'Handrail synchronization', 'Pass / Fail'],
            ['E-SAT-017', 'Comb gap measurement', 'mm'],
            ['E-SAT-018', 'Step-to-step gap', 'mm'],
            ['E-SAT-019', 'Step-to-skirt clearance', 'mm'],
            ['E-SAT-020', 'Step chain tension', 'mm'],
            ['E-SAT-021', 'Roller clearance', 'mm'],
            ['E-SAT-022', 'Spring length', 'mm'],
        ],
    ],
    'D' => [
        'title' => 'SECTION D — SAFETY DEVICE FUNCTIONAL TEST',
        'items' => [
            ['E-SAT-023', 'Emergency stop switch (Top)', 'Pass / Fail'],
            ['E-SAT-024', 'Emergency stop switch (Bottom)', 'Pass / Fail'],
            ['E-SAT-025', 'Comb impact safety switch', 'Pass / Fail'],
            ['E-SAT-026', 'Skirt obstruction switch', 'Pass / Fail'],
            ['E-SAT-027', 'Broken step detection', 'Pass / Fail'],
            ['E-SAT-028', 'Missing step detection', 'Pass / Fail'],
            ['E-SAT-029', 'Handrail inlet protection switch', 'Pass / Fail'],
            ['E-SAT-030', 'Step sag detection', 'Pass / Fail'],
        ],
    ],
    'E' => [
        'title' => 'SECTION E — INSPECTION & CONTROL FUNCTIONS',
        'items' => [
            ['E-SAT-031', 'Inspection pendant operation', 'Pass / Fail'],
            ['E-SAT-032', 'Inspection mode operation', 'Pass / Fail'],
            ['E-SAT-033', 'Direction selector switch', 'Pass / Fail'],
            ['E-SAT-034', 'Main controller functional test', 'Pass / Fail'],
            ['E-SAT-035', 'Phase failure protection', 'Pass / Fail'],
            ['E-SAT-036', 'Overload protection', 'Pass / Fail'],
            ['E-SAT-037', 'Auto restart behaviour', 'Pass / Fail'],
            ['E-SAT-038', 'Safety circuit continuity', 'Pass / Fail'],
        ],
    ],
];

$totalCheckpoints = 0;

foreach ($sections as $section) {
    $totalCheckpoints += count($section['items']);
}

if ($totalCheckpoints !== 38) {
    die(
        'ECHK-02 master configuration error. Expected 38 checkpoints, found '
        . $totalCheckpoints . '.'
    );
}

/* =========================================================
   PRIORITY — READ ONLY FROM ECHK-00 MASTER
   ---------------------------------------------------------
   Unit remains internal for Reading logic only.
   Inspector sees Priority in the second column.
========================================================= */

$priorityByCheckpoint = [
    'E-SAT-001'=>'P2','E-SAT-002'=>'P2','E-SAT-003'=>'P3','E-SAT-004'=>'P2',
    'E-SAT-005'=>'P3','E-SAT-006'=>'P3','E-SAT-007'=>'P1','E-SAT-008'=>'P1',
    'E-SAT-009'=>'P1','E-SAT-010'=>'P2','E-SAT-011'=>'P2','E-SAT-012'=>'P2',
    'E-SAT-013'=>'P2','E-SAT-014'=>'P2','E-SAT-015'=>'P2','E-SAT-016'=>'P2',
    'E-SAT-017'=>'P1','E-SAT-018'=>'P1','E-SAT-019'=>'P1','E-SAT-020'=>'P2',
    'E-SAT-021'=>'P2','E-SAT-022'=>'P2','E-SAT-023'=>'P1','E-SAT-024'=>'P1',
    'E-SAT-025'=>'P1','E-SAT-026'=>'P1','E-SAT-027'=>'P1','E-SAT-028'=>'P1',
    'E-SAT-029'=>'P1','E-SAT-030'=>'P1','E-SAT-031'=>'P2','E-SAT-032'=>'P2',
    'E-SAT-033'=>'P2','E-SAT-034'=>'P2','E-SAT-035'=>'P1','E-SAT-036'=>'P1',
    'E-SAT-037'=>'P1','E-SAT-038'=>'P1'
];

/* =========================================================
   LOAD EXISTING CHECKLIST
========================================================= */

$existingChecklist = [];

if (!empty($job['saved_checklist'])) {
    $decoded = json_decode((string)$job['saved_checklist'], true);

    if (is_array($decoded)) {
        $existingChecklist = $decoded;
    }
}

$existingResults =
    is_array($existingChecklist['results'] ?? null)
        ? $existingChecklist['results']
        : [];

$existingSectionRemarks =
    is_array($existingChecklist['section_remarks'] ?? null)
        ? $existingChecklist['section_remarks']
        : [];

$existingFinalStatus =
    strtoupper(trim((string)($existingChecklist['final_status'] ?? '')));

if (!in_array($existingFinalStatus, ['PASS', 'CONDITIONAL PASS', 'FAIL'], true)) {
    $existingFinalStatus = '';
}

$existingInspectorSignature =
    (string)($existingChecklist['inspector_signature'] ?? '');

$existingOwnerRepresentative =
    (string)($existingChecklist['owner_representative'] ?? '');

$existingAmcRepresentative =
    (string)($existingChecklist['amc_representative'] ?? '');

$currentIST = date('d/m/Y H:i:s');

/* =========================================================
   SAVED VALUE HELPERS
========================================================= */

function savedValue(array $results, string $key, string $field): string
{
    $item = $results[$key] ?? [];

    if (!is_array($item)) {
        return '';
    }

    return (string)($item[$field] ?? '');
}

function savedResult(array $results, string $key): string
{
    $value = strtoupper(trim(savedValue($results, $key, 'result')));

    if ($value === 'D') {
        $value = 'CA';
    }

    return in_array($value, ['C', 'NC', 'NA', 'CA'], true)
        ? $value
        : '';
}

function savedPhoto(array $results, string $key): string
{
    return savedValue($results, $key, 'photo_path');
}

function csrfHtml(): string
{
    if (function_exists('csrfField')) {
        return (string)csrfField();
    }

    if (function_exists('generateCSRFToken')) {
        return '<input type="hidden" name="csrf_token" value="' .
            h(generateCSRFToken()) . '">';
    }

    return '<input type="hidden" name="csrf_token" value="' .
        h($_SESSION['csrf_token'] ?? '') . '">';
}

include('../includes/header.php');
include('../includes/sidebar-inspector.php');
?>

<style>
.nleta-checklist-page{min-height:calc(100vh - 70px);padding:24px;background:#f4f6f9}
.nleta-card{background:#fff;border:1px solid #e1e5e9;border-radius:14px;box-shadow:0 4px 16px rgba(0,0,0,.06);margin-bottom:18px;overflow:hidden}
.nleta-card-header{padding:20px 22px;border-bottom:1px solid #e5e7eb}
.page-title{margin:0;font-size:26px;font-weight:800;color:#172033}
.page-subtitle{margin-top:5px;color:#6c757d}
.controlled-badge{display:inline-flex;align-items:center;gap:6px;padding:7px 11px;border-radius:20px;background:#e7f1ff;color:#0d6efd;font-size:12px;font-weight:800}
.job-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;padding:18px}
.job-box{background:#f8f9fa;border:1px solid #e3e7eb;border-radius:10px;padding:12px}
.job-label{color:#6c757d;font-size:11px;text-transform:uppercase;font-weight:700}
.job-value{color:#172033;font-weight:700;margin-top:4px;word-break:break-word}
.legend-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;padding:18px}
.legend-item{border:1px solid #e1e5e9;border-radius:10px;padding:13px;background:#fff}
.legend-code{font-size:18px;font-weight:900}.legend-description{color:#6c757d;font-size:13px;margin-top:3px}
.legend-c{color:#198754}.legend-nc{color:#dc3545}
.rule-box{margin:0 18px 18px;border:1px solid #b8d9ff;background:#eef6ff;border-radius:10px;padding:13px 15px;color:#173d66;font-size:13px}
.section-heading{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:14px 18px;background:#0d6efd;color:#fff}
.section-heading-title{font-size:17px;font-weight:800}.section-count{background:rgba(255,255,255,.18);border-radius:20px;padding:5px 10px;font-size:11px;font-weight:800}
.section-bulk-result{padding:12px 18px;background:#f8fbff;border-bottom:1px solid #dbe8f7}
.section-bulk-label{font-size:12px;font-weight:800;color:#26364d;margin-bottom:8px}
.section-bulk-buttons{display:flex;flex-wrap:wrap;gap:7px}
.bulk-result-btn{border:1px solid #ced4da;border-radius:7px;background:#fff;padding:7px 11px;font-size:12px;font-weight:800;cursor:pointer;transition:.15s}
.bulk-result-btn:hover{transform:translateY(-1px);box-shadow:0 2px 7px rgba(0,0,0,.08)}
.bulk-c{color:#198754;border-color:#b7dfc8}.bulk-c:hover{background:#eaf8ef}
.bulk-nc{color:#dc3545;border-color:#f1b7bd}.bulk-nc:hover{background:#fff0f1}
.table-wrap{overflow-x:auto}.checklist-table{width:100%;border-collapse:collapse;min-width:1180px}
.checklist-table th{background:#f7f8fa;border-bottom:2px solid #dee2e6;color:#26364d;padding:11px 10px;font-size:12px;font-weight:800;text-transform:uppercase;white-space:nowrap}
.checklist-table td{padding:10px;border-bottom:1px solid #edf0f3;vertical-align:top;font-size:13px}
.checkpoint-text{font-weight:650;color:#202938;line-height:1.45}.ref-code{font-weight:900;color:#172033;white-space:nowrap}
.unit{display:inline-block;min-width:45px;text-align:center;padding:5px 7px;border-radius:6px;font-size:11px;font-weight:900;background:#e7e7e7;color:#555}.priority-p1{background:#f8d7da;color:#842029}.priority-p2{background:#ffe5d0;color:#9a3412}.priority-p3{background:#fff3cd;color:#856404}.priority-p4{background:#cfe2ff;color:#084298}
.result-options{display:flex;flex-wrap:wrap;gap:6px;min-width:180px}.result-option{display:inline-flex;align-items:center;gap:4px;padding:6px 8px;border:1px solid #d8dde3;border-radius:7px;background:#fff;cursor:pointer;font-size:12px;font-weight:700}.result-option input{margin:0}
.reading-input{width:145px}.reading-help{font-size:10px;color:#6c757d;margin-top:4px}
.evidence-area{min-width:270px;max-width:350px}.result-evidence{display:none}.result-evidence.show{display:block}
.dynamic-required{display:none}.dynamic-required.show{display:block}
.field-group{margin-top:8px}.field-group:first-child{margin-top:0}.field-label{display:block;font-size:11px;font-weight:800;margin-bottom:4px}.required{color:#dc3545}
.file-input{width:100%;font-size:12px}.file-help{display:block;margin-top:4px;font-size:10px;color:#6c757d}
.remark-input{width:100%;min-height:58px;resize:vertical;border:1px solid #ced4da;border-radius:7px;padding:8px;font-size:12px}
.summary-grid{display:grid;gap:10px;padding:18px;grid-template-columns:repeat(3,minmax(0,1fr))}.summary-grid-all-results{grid-template-columns:repeat(3,minmax(0,1fr))}
.summary-box{border:1px solid #e1e5e9;border-radius:10px;padding:14px;background:#fff;min-width:0}.summary-label{color:#6c757d;font-size:12px;font-weight:700}.summary-value{margin-top:4px;font-size:24px;font-weight:900}
.summary-total{color:#0d6efd}.summary-c{color:#198754}.summary-nc{color:#dc3545}
.final-status-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;padding:18px}
.final-option{border:1px solid #ced4da;border-radius:10px;padding:14px;cursor:pointer;font-weight:800;background:#fff}.final-option input{margin-right:7px}
.final-fields{padding:0 18px 18px;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.final-field label{display:block;font-size:12px;font-weight:800;margin-bottom:5px}.final-field input{width:100%;border:1px solid #ced4da;border-radius:7px;padding:9px;font-size:13px}
.draft-status{display:inline-flex;align-items:center;gap:6px;margin-left:12px;font-size:12px;font-weight:700;color:#198754}
.draft-status.saving{color:#b27a00}.draft-status.saved{color:#198754}.draft-status.error{color:#dc3545}
.action-bar{position:sticky;bottom:0;z-index:20;background:rgba(255,255,255,.97);border-top:1px solid #dee2e6;padding:14px 18px;box-shadow:0 -3px 12px rgba(0,0,0,.05)}
.action-content{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.is-invalid{border-color:#dc3545!important}
.nc-row{background:#fff8f8}
@media(max-width:1000px){.job-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.summary-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.final-fields{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:650px){.final-status-grid{grid-template-columns:1fr}.nleta-checklist-page{padding:8px}.job-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;padding:10px}.job-box{padding:9px}.job-label{font-size:9px}.job-value{font-size:12px}.legend-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;padding:10px}.legend-item{padding:10px}.legend-code{font-size:16px}.legend-description{font-size:11px}.rule-box{margin:0 10px 10px;font-size:11px;line-height:1.5}.section-heading{padding:11px 12px}.section-heading-title{font-size:14px}.section-count{font-size:9px}.section-bulk-result{padding:10px 12px}.section-bulk-buttons{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px}.bulk-result-btn{width:100%;min-width:0;padding:7px 6px;font-size:10px}.checklist-table{min-width:1180px}.checklist-table th,.checklist-table td{padding:8px;font-size:11px}.result-options{min-width:180px}.result-option{padding:5px 7px;font-size:11px}.evidence-area{min-width:245px;max-width:310px}.summary-grid{grid-template-columns:repeat(2,minmax(0,1fr));padding:10px}.summary-box{padding:10px}.summary-value{font-size:20px}.final-status-grid{grid-template-columns:repeat(2,minmax(0,1fr));padding:10px}.final-option{padding:10px;font-size:11px}.final-fields{grid-template-columns:1fr;padding:0 10px 10px}.action-bar{padding:10px}.action-content{align-items:stretch}.action-content>div{width:100%}.action-content .btn{margin:3px 0!important;width:100%}.draft-status{margin-left:0;display:block;margin-top:4px}.page-title{font-size:20px}.nleta-card-header{padding:13px}}
@media(max-width:380px){.job-grid{gap:6px;padding:8px}.job-box{padding:7px}.job-value{font-size:11px}.summary-value{font-size:18px}}
</style>

<div class="content">
<div class="nleta-checklist-page">

<div class="nleta-card">
    <div class="nleta-card-header">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <div class="controlled-badge mb-2">
                    <i class="bi bi-shield-check"></i>
                    ECHK-02 • REV.1 • CONTROLLED CHECKLIST
                </div>
                <h1 class="page-title">NLETA Escalator SAT Inspection Checklist</h1>
                <div class="page-subtitle">
                    Site Acceptance Test — Escalator &amp; Travellator Master Checklist
                </div>
            </div>
            <div class="text-end">
                <div class="small text-muted">Current IST</div>
                <strong><?= h($currentIST) ?></strong>
            </div>
        </div>
    </div>

    <div class="job-grid">
        <div class="job-box"><div class="job-label">PSGA / Contract No.</div><div class="job-value"><?= h($job['psga_no'] ?? '—') ?></div></div>
        <div class="job-box"><div class="job-label">SAF No.</div><div class="job-value"><?= h($job['saf_no'] ?? '—') ?></div></div>
        <div class="job-box"><div class="job-label">Asset / Escalator</div><div class="job-value"><?= h($job['display_asset_id'] ?: ($job['asset_name'] ?: '—')) ?></div></div>
        <div class="job-box"><div class="job-label">Owner</div><div class="job-value"><?= h($job['owner_name'] ?? '—') ?></div></div>
        <div class="job-box"><div class="job-label">Inspector</div><div class="job-value"><?= h($_SESSION['name'] ?? $_SESSION['full_name'] ?? 'Assigned Inspector') ?></div></div>
        <div class="job-box"><div class="job-label">Location</div><div class="job-value"><?= h($job['location'] ?? '—') ?></div></div>
        <div class="job-box"><div class="job-label">Service</div><div class="job-value"><?= h($job['service_name'] ?: 'SAT') ?></div></div>
        <div class="job-box"><div class="job-label">Equipment Type</div><div class="job-value"><?= h($job['asset_type'] ?: ($job['category'] ?: 'Escalator')) ?></div></div>
        <div class="job-box"><div class="job-label">Inspection Date</div><div class="job-value">
            <?php
            $inspectionDate = (string)($job['proposed_inspection_date'] ?? '');
            if ($inspectionDate !== '') {
                $ts = strtotime($inspectionDate);
                echo $ts !== false ? h(date('d/m/Y', $ts)) : h($inspectionDate);
            } else {
                echo '—';
            }
            ?>
        </div></div>
        <div class="job-box"><div class="job-label">Checklist</div><div class="job-value">ECHK-02 Rev.1</div></div>
        <div class="job-box"><div class="job-label">Total Checkpoints</div><div class="job-value"><?= (int)$totalCheckpoints ?></div></div>
    </div>

    <?php if ($isFinalSubmitted): ?>
        <div class="rule-box" style="margin-top:0">
            <strong><i class="bi bi-lock-fill me-1"></i>Final submitted:</strong>
            This SAT checklist is view-only and cannot be edited.
        </div>
    <?php endif; ?>
</div>

<div class="nleta-card">
    <div class="nleta-card-header">
        <h4 class="mb-1"><i class="bi bi-ui-checks-grid me-2"></i>Result Code Standard</h4>
        <div class="text-muted small">
            Select exactly one result for each Escalator SAT checkpoint.
        </div>
    </div>

    <div class="legend-grid">
        <div class="legend-item">
            <div class="legend-code legend-c">C</div>
            <div class="legend-description">Complied</div>
        </div>
        <div class="legend-item">
            <div class="legend-code legend-nc">NC</div>
            <div class="legend-description">Non-Complied</div>
        </div>
        <div class="legend-item">
            <div class="legend-code" style="color:#6c757d">NA</div>
            <div class="legend-description">Not Applicable</div>
        </div>
        <div class="legend-item">
            <div class="legend-code" style="color:#9a6500">CA</div>
            <div class="legend-description">Conditional Acceptance</div>
        </div>
    </div>

    <div class="rule-box">
        <strong>Mandatory Rules:</strong>
        <span class="ms-2">NC → Photograph REQUIRED</span>
        <span class="ms-2">NC → Engineering Remark REQUIRED</span>
        <span class="ms-2">CA → Photograph + Technical Justification REQUIRED</span>
        <span class="ms-2">Numeric / OK-Low reading → REQUIRED where applicable</span>
        <span class="ms-2">Video → OPTIONAL</span>
    </div>
</div>

<form method="post"
      action="escalator-sat-checklist-save.php"
      enctype="multipart/form-data"
      id="escalatorSatChecklistForm"
      novalidate
      data-final-submitted="<?= $isFinalSubmitted ? '1' : '0' ?>">

<input type="hidden" name="job_id" value="<?= (int)$jobId ?>">
<input type="hidden" name="assignment_id" value="<?= (int)$assignmentId ?>">
<input type="hidden" name="saf_id" value="<?= (int)$job['saf_id'] ?>">
<input type="hidden" name="asset_id" value="<?= (int)$job['asset_id'] ?>">
<input type="hidden" name="checklist_code" value="ECHK-02">
<input type="hidden" name="checklist_revision" value="Rev.1">
<input type="hidden" name="module" value="SAT">
<input type="hidden" name="asset_type" value="Escalator">
<input type="hidden" name="timezone" value="Asia/Kolkata">
<input type="hidden" name="save_mode" id="saveMode" value="asset">

<?php echo csrfHtml(); ?>

<?php foreach ($sections as $sectionCode => $section): ?>
<div class="nleta-card section-card">

    <div class="section-heading">
        <div class="section-heading-title">
            <i class="bi bi-list-check me-2"></i>
            <?= h($section['title']) ?>
        </div>
        <div class="section-count">
            <?= count($section['items']) ?> Checkpoint(s)
        </div>
    </div>

    <div class="section-bulk-result">
        <div class="section-bulk-label">
            <i class="bi bi-check2-all me-1"></i>
            Select All Result for this Section:
        </div>

        <div class="section-bulk-buttons">
            <button type="button"
                    class="bulk-result-btn bulk-c"
                    data-section="<?= h($sectionCode) ?>"
                    data-result="C">
                C — Complied
            </button>

            <button type="button"
                    class="bulk-result-btn bulk-nc"
                    data-section="<?= h($sectionCode) ?>"
                    data-result="NC">
                NC — Non-Complied
            </button>

            <button type="button"
                    class="bulk-result-btn"
                    data-section="<?= h($sectionCode) ?>"
                    data-result="NA">
                NA — Not Applicable
            </button>

            <button type="button"
                    class="bulk-result-btn"
                    data-section="<?= h($sectionCode) ?>"
                    data-result="CA">
                CA — Conditional Acceptance
            </button>
        </div>
    </div>

    <div class="table-wrap">
        <table class="checklist-table">

            <thead>
            <tr>
                <th style="width:110px">ID</th>
                <th style="width:90px">Priority</th>
                <th>Quality Inspection Checkpoint</th>
                <th style="width:180px">Reading</th>
                <th style="width:220px">Result</th>
                <th style="width:330px">Evidence / Remark</th>
            </tr>
            </thead>

            <tbody>
            <?php foreach ($section['items'] as $item): ?>
                <?php
                $ref = $item[0];
                $description = $item[1];
                $unit = $item[2];

                $oldResult = savedResult($existingResults, $ref);
                $oldReading = savedValue($existingResults, $ref, 'reading');
                $oldRemark = savedValue($existingResults, $ref, 'remark');
                ?>

                <tr data-checkpoint="<?= h($ref) ?>"
                    data-unit="<?= h($unit) ?>"
                    data-section="<?= h($sectionCode) ?>">

                    <td>
                        <span class="ref-code"><?= h($ref) ?></span>
                    </td>

                    <td>
                        <?php $priority = $priorityByCheckpoint[$ref] ?? '—'; ?>
                        <span class="unit <?= $priority !== '—' ? 'priority-' . strtolower($priority) : '' ?>">
                            <?= h($priority) ?>
                        </span>
                    </td>

                    <td>
                        <div class="checkpoint-text">
                            <?= h($description) ?>
                        </div>
                    </td>

                    <td>
                        <?php if ($unit === 'mm' || $unit === '%'): ?>

                            <input
                                type="number"
                                step="any"
                                class="form-control reading-input"
                                name="reading[<?= h($ref) ?>]"
                                value="<?= h($oldReading) ?>"
                                placeholder="Enter <?= h($unit) ?>"
                            >

                            <div class="reading-help">
                                Numeric reading in <?= h($unit) ?>.
                            </div>

                        <?php elseif ($unit === 'OK / Low'): ?>

                            <select
                                class="form-select reading-input"
                                name="reading[<?= h($ref) ?>]"
                            >
                                <option value="">Select</option>
                                <option value="OK" <?= $oldReading === 'OK' ? 'selected' : '' ?>>
                                    OK
                                </option>
                                <option value="Low" <?= $oldReading === 'Low' ? 'selected' : '' ?>>
                                    Low
                                </option>
                            </select>

                        <?php else: ?>

                            <input
                                type="hidden"
                                name="reading[<?= h($ref) ?>]"
                                value="<?= h($oldReading) ?>"
                            >

                            <span class="reading-help">
                                Pass / Fail is recorded through C / NC.
                            </span>

                        <?php endif; ?>
                    </td>

                    <td>
                        <div class="result-options">

                            <label class="result-option">
                                <input
                                    type="radio"
                                    name="result[<?= h($ref) ?>]"
                                    value="C"
                                    data-ref="<?= h($ref) ?>"
                                    <?= $oldResult === 'C' ? 'checked' : '' ?>
                                >
                                C
                            </label>

                            <label class="result-option">
                                <input
                                    type="radio"
                                    name="result[<?= h($ref) ?>]"
                                    value="NC"
                                    data-ref="<?= h($ref) ?>"
                                    <?= $oldResult === 'NC' ? 'checked' : '' ?>
                                >
                                NC
                            </label>

                            <label class="result-option">
                                <input
                                    type="radio"
                                    name="result[<?= h($ref) ?>]"
                                    value="NA"
                                    data-ref="<?= h($ref) ?>"
                                    <?= $oldResult === 'NA' ? 'checked' : '' ?>
                                >
                                NA
                            </label>

                            <label class="result-option">
                                <input
                                    type="radio"
                                    name="result[<?= h($ref) ?>]"
                                    value="CA"
                                    data-ref="<?= h($ref) ?>"
                                    <?= $oldResult === 'CA' ? 'checked' : '' ?>
                                >
                                CA
                            </label>

                        </div>

                        <div class="small text-muted mt-2 status-note"
                             data-ref="<?= h($ref) ?>">
                            Select C, NC, NA or CA.
                        </div>
                    </td>

                    <td>
                        <div class="evidence-area">

                            <div class="result-evidence"
                                 data-ref="<?= h($ref) ?>">

                                <div class="field-group">

                                    <label class="field-label">
                                        Photograph
                                        <span
                                            class="required photo-required"
                                            data-ref="<?= h($ref) ?>"
                                            style="display:none"
                                        >*</span>
                                    </label>

                                    <input
                                        type="file"
                                        class="form-control form-control-sm file-input photo-input"
                                        name="photo[<?= h($ref) ?>]"
                                        accept="image/jpeg,image/png,image/jpg,image/webp"
                                    >

                                    <span class="file-help">
                                        NC / CA → Photograph REQUIRED. Maximum 10 MB.
                                    </span>
                                </div>

                                <div class="field-group">

                                    <label class="field-label">
                                        Video Evidence
                                        <span class="text-muted" style="font-size:10px">
                                            (Optional)
                                        </span>
                                    </label>

                                    <input
                                        type="file"
                                        class="form-control form-control-sm file-input video-input"
                                        name="video[<?= h($ref) ?>]"
                                        accept="video/mp4,video/webm,video/quicktime,video/x-msvideo"
                                    >

                                    <span class="file-help">
                                        Optional. Maximum 50 MB.
                                    </span>
                                </div>

                                <div class="dynamic-required remark-field"
                                     data-ref="<?= h($ref) ?>">

                                    <div class="field-group">

                                        <label class="field-label">
                                            Engineering Remark
                                            <span class="required">*</span>
                                        </label>

                                        <textarea
                                            class="remark-input"
                                            name="remark[<?= h($ref) ?>]"
                                            data-remark-ref="<?= h($ref) ?>"
                                            maxlength="2000"
                                            placeholder="Engineering / quality observation..."
                                        ><?= h($oldRemark) ?></textarea>

                                    </div>

                                </div>

                                <div class="dynamic-required ca-field"
                                     data-ref="<?= h($ref) ?>">

                                    <div class="field-group">

                                        <label class="field-label">
                                            Technical Justification
                                            <span class="required">*</span>
                                        </label>

                                        <textarea
                                            class="remark-input"
                                            name="technical_justification[<?= h($ref) ?>]"
                                            data-justification-ref="<?= h($ref) ?>"
                                            maxlength="3000"
                                            placeholder="Technical justification for Conditional Acceptance..."
                                        ><?= h(savedValue($existingResults, $ref, 'technical_justification')) ?></textarea>

                                    </div>

                                </div>

                            </div>

                            <div class="small text-muted mt-2 status-note"
                                 data-ref="<?= h($ref) ?>">
                                Select C, NC, NA or CA.
                            </div>

                        </div>
                    </td>

                </tr>

            <?php endforeach; ?>
            </tbody>

        </table>
    </div>

    <div class="p-3">

        <label class="field-label">
            <?= h($section['title']) ?> — Section Remark
        </label>

        <textarea
            class="remark-input"
            name="section_remarks[<?= h($sectionCode) ?>]"
            maxlength="3000"
            placeholder="Enter section-level remark..."
        ><?= h($existingSectionRemarks[$sectionCode] ?? '') ?></textarea>

    </div>

</div>
<?php endforeach; ?>

<div class="nleta-card">

    <div class="nleta-card-header">

        <h4 class="mb-1">
            <i class="bi bi-bar-chart-line me-2"></i>
            Checklist Progress
        </h4>

        <div class="text-muted small">
            ECHK-02 Rev.1 — 38 fixed Escalator SAT checkpoints
        </div>

    </div>

    <div class="summary-grid summary-grid-all-results">

        <div class="summary-box">
            <div class="summary-label">Total Checkpoints</div>
            <div class="summary-value summary-total" id="summaryTotal">
                <?= (int)$totalCheckpoints ?>
            </div>
        </div>

        <div class="summary-box">
            <div class="summary-label">C — Complied</div>
            <div class="summary-value summary-c" id="summaryC">0</div>
        </div>

        <div class="summary-box">
            <div class="summary-label">NC — Non-Complied</div>
            <div class="summary-value summary-nc" id="summaryNC">0</div>
        </div>

        <div class="summary-box">
            <div class="summary-label">NA — Not Applicable</div>
            <div class="summary-value" id="summaryNA">0</div>
        </div>

        <div class="summary-box">
            <div class="summary-label">CA — Conditional Acceptance</div>
            <div class="summary-value" id="summaryCA">0</div>
        </div>

    </div>

</div>

<div class="nleta-card">

    <div class="nleta-card-header">

        <h4 class="mb-1">
            <i class="bi bi-flag me-2"></i>
            Final Inspection Status
        </h4>

        <div class="text-muted small">
            Overall Escalator SAT inspection outcome.
        </div>

    </div>

    <div class="final-status-grid">

        <label class="final-option">
            <input
                type="radio"
                name="final_status"
                value="PASS"
                <?= $existingFinalStatus === 'PASS' ? 'checked' : '' ?>
            >
            PASS
        </label>

        <label class="final-option">
            <input
                type="radio"
                name="final_status"
                value="CONDITIONAL PASS"
                <?= $existingFinalStatus === 'CONDITIONAL PASS' ? 'checked' : '' ?>
            >
            CONDITIONAL PASS
        </label>

        <label class="final-option">
            <input
                type="radio"
                name="final_status"
                value="FAIL"
                <?= $existingFinalStatus === 'FAIL' ? 'checked' : '' ?>
            >
            FAIL
        </label>

    </div>

    <div class="final-fields">

        <div class="final-field">
            <label>Inspector Name</label>
            <input
                type="text"
                name="inspector_signature"
                value="<?= h($existingInspectorSignature) ?>"
                placeholder="Inspector name"
                maxlength="255"
            >
        </div>

        <div class="final-field">
            <label>Owner Representative</label>
            <input
                type="text"
                name="owner_representative"
                value="<?= h($existingOwnerRepresentative) ?>"
                placeholder="Owner representative"
                maxlength="255"
            >
        </div>

        <div class="final-field">
            <label>AMC Representative</label>
            <input
                type="text"
                name="amc_representative"
                value="<?= h($existingAmcRepresentative) ?>"
                placeholder="AMC representative"
                maxlength="255"
            >
        </div>

    </div>

</div>

<div class="action-bar">

    <div class="action-content">

        <div>

            <strong>ECHK-02 Rev.1</strong>

            <span class="text-muted ms-2">
                <?= (int)$totalCheckpoints ?> Escalator SAT Checkpoints
            </span>

            <span id="draftStatus"
                  class="draft-status"
                  aria-live="polite"></span>
            <span class="text-muted small d-block mt-1">
                After successful completion, you will return to Inspections for the final Owner submission step.
            </span>

        </div>

        <div>

            <a href="inspections.php"
               class="btn btn-outline-secondary me-2">
                <i class="bi bi-arrow-left me-1"></i>
                Back
            </a>

            <?php if (!$isFinalSubmitted): ?>

                <button
                    type="reset"
                    class="btn btn-outline-warning me-2"
                    id="resetChecklist"
                >
                    <i class="bi bi-arrow-counterclockwise me-1"></i>
                    Reset
                </button>

                <button
                    type="submit"
                    class="btn btn-primary"
                    id="saveChecklistButton"
                >
                    <i class="bi bi-check2-circle me-1"></i>
                    Save & Complete Escalator SAT Checklist
                </button>

            <?php else: ?>

                <span class="btn btn-outline-success disabled">
                    <i class="bi bi-lock-fill me-1"></i>
                    Final Submitted — View Only
                </span>

            <?php endif; ?>

        </div>

    </div>

</div>

</form>

</div>
</div>

<script>
(function () {
    'use strict';

    const form =
        document.getElementById('escalatorSatChecklistForm');

    if (!form) return;

    const jobId =
        form.querySelector('input[name="job_id"]')?.value || '0';

    const safId =
        form.querySelector('input[name="saf_id"]')?.value || '0';

    const locked =
        form.dataset.finalSubmitted === '1';

    const draftKey =
        'NLETA_ECHK_02_SAT_REV1_JOB_' +
        jobId +
        '_SAF_' +
        safId;

    const draftStatus =
        document.getElementById('draftStatus');

    let saveTimer = null;
    let restoring = false;

    function setDraftStatus(message, type) {
        if (!draftStatus) return;

        draftStatus.textContent = message;
        draftStatus.className =
            'draft-status ' + (type || '');
    }

    function esc(value) {
        if (window.CSS && CSS.escape) {
            return CSS.escape(String(value));
        }

        return String(value).replace(/["\\]/g, '\\$&');
    }

    function getResult(ref) {
        const radio =
            form.querySelector(
                'input[name="result[' +
                esc(ref) +
                ']"]:checked'
            );

        return radio ? radio.value : '';
    }

    function updateCheckpoint(ref) {

        const row =
            form.querySelector(
                'tr[data-checkpoint="' +
                esc(ref) +
                '"]'
            );

        if (!row) return;

        const result = getResult(ref);

        const evidence =
            row.querySelector('.result-evidence');

        const remarkField =
            row.querySelector('.remark-field');

        const caField =
            row.querySelector('.ca-field');

        const justification =
            row.querySelector(
                'textarea[data-justification-ref="' +
                esc(ref) +
                '"]'
            );

        const caRemark =
            row.querySelector(
                'textarea[data-ca-remark-ref="' +
                esc(ref) +
                '"]'
            );

        const remark =
            row.querySelector(
                'textarea[data-remark-ref="' +
                esc(ref) +
                '"]'
            );

        const photoRequired =
            row.querySelector('.photo-required');

        const note =
            row.querySelector('.status-note');

        if (evidence) {
            evidence.classList.remove('show');
        }

        if (remarkField) {
            remarkField.classList.remove('show');
        }

        if (caField) {
            caField.classList.remove('show');
        }

        if (remark) {
            remark.required = false;
            remark.classList.remove('is-invalid');
        }

        if (photoRequired) {
            photoRequired.style.display = 'none';
        }

        if (justification) {
            justification.required = false;
            justification.classList.remove('is-invalid');
        }

        row.classList.remove(
            'table-danger',
            'nc-row'
        );

        if (result === 'C') {

            if (note) {
                note.textContent = 'C — Complied.';
                note.className =
                    'small text-success mt-2 status-note';
            }

            return;
        }

        if (result === 'NC') {

            if (evidence) {
                evidence.classList.add('show');
            }

            if (remarkField) {
                remarkField.classList.add('show');
            }

            if (remark) {
                remark.required = true;
            }

            if (photoRequired) {
                photoRequired.style.display = 'inline';
            }

            row.classList.add('nc-row');

            if (note) {
                note.textContent =
                    'NC — Photograph and Engineering Remark are mandatory.';

                note.className =
                    'small text-danger mt-2 status-note';
            }

            return;
        }

        if (result === 'NA') {

            if (note) {
                note.textContent = 'NA — Not Applicable.';
                note.className =
                    'small text-secondary mt-2 status-note';
            }

            return;
        }

        if (result === 'CA') {

            if (evidence) {
                evidence.classList.add('show');
            }

            if (caField) {
                caField.classList.add('show');
            }

            if (photoRequired) {
                photoRequired.style.display = 'inline';
            }

            if (justification) {
                justification.required = true;
            }

            if (caRemark) {
                caRemark.required = true;
            }

            if (note) {
                note.textContent =
                    'CA — Photograph and Technical Justification are mandatory.';
                note.className =
                    'small mt-2 status-note';
                note.style.color = '#9a6500';
            }

            return;
        }

        if (note) {
            note.textContent = 'Select C, NC, NA or CA.';
            note.className =
                'small text-muted mt-2 status-note';
        }
    }

    function updateSummary() {

        let c = 0;
        let nc = 0;
        let na = 0;
        let ca = 0;
        let selected = 0;

        form.querySelectorAll(
            'input[name^="result["]:checked'
        ).forEach(function (input) {

            selected++;
            if (input.value === 'C') c++;
            if (input.value === 'NC') nc++;
            if (input.value === 'NA') na++;
            if (input.value === 'CA') ca++;
        });

        const pending = <?= (int)$totalCheckpoints ?> - selected;

        const set = function (id, value) {

            const el =
                document.getElementById(id);

            if (el) {
                el.textContent = value;
            }
        };

        set('summaryTotal', <?= (int)$totalCheckpoints ?>);
        set('summaryPending', pending);
        set('summaryC', c);
        set('summaryNC', nc);
        set('summaryNA', na);
        set('summaryCA', ca);
    }

    function collectDraft() {

        const draft = {
            version: 'ECHK-02-REV1-38',
            job_id: jobId,
            saf_id: safId,
            saved_at: new Date().toISOString(),
            results: {},
            section_remarks: {},
            final_status: '',
            inspector_signature: '',
            owner_representative: '',
            amc_representative: ''
        };

        form.querySelectorAll(
            'input[name^="result["]:checked'
        ).forEach(function (input) {

            const ref = input.dataset.ref;

            if (!ref) return;

            const row =
                input.closest('tr');

            const reading =
                row?.querySelector(
                    '[name="reading[' +
                    esc(ref) +
                    ']"]'
                );

            const remark =
                row?.querySelector(
                    'textarea[data-remark-ref="' +
                    esc(ref) +
                    '"]'
                );

            const justification =
                row?.querySelector(
                    'textarea[data-justification-ref="' +
                    esc(ref) +
                    '"]'
                );

            draft.results[ref] = {
                result: input.value,
                reading: reading
                    ? reading.value
                    : '',
                remark: remark
                    ? remark.value
                    : '',
                technical_justification: justification
                    ? justification.value
                    : '',
                ca_remark: ''
            };
        });

        form.querySelectorAll(
            'textarea[name^="section_remarks["]'
        ).forEach(function (textarea) {

            const match =
                textarea.name.match(
                    /^section_remarks\[(.+)\]$/
                );

            if (match) {
                draft.section_remarks[match[1]] =
                    textarea.value || '';
            }
        });

        const final =
            form.querySelector(
                'input[name="final_status"]:checked'
            );

        draft.final_status =
            final ? final.value : '';

        draft.inspector_signature =
            form.querySelector(
                'input[name="inspector_signature"]'
            )?.value || '';

        draft.owner_representative =
            form.querySelector(
                'input[name="owner_representative"]'
            )?.value || '';

        draft.amc_representative =
            form.querySelector(
                'input[name="amc_representative"]'
            )?.value || '';

        return draft;
    }

    function saveDraft() {

        if (restoring || locked) return;

        try {

            localStorage.setItem(
                draftKey,
                JSON.stringify(collectDraft())
            );

            setDraftStatus(
                '✓ Draft saved',
                'saved'
            );

        } catch (error) {

            console.error(error);

            setDraftStatus(
                'Draft storage unavailable',
                'error'
            );
        }
    }

    function scheduleDraftSave() {

        if (restoring || locked) return;

        setDraftStatus(
            'Saving…',
            'saving'
        );

        clearTimeout(saveTimer);

        saveTimer = setTimeout(
            saveDraft,
            250
        );
    }

    function restoreDraft() {

        if (locked) return false;

        let raw = null;

        try {
            raw = localStorage.getItem(draftKey);
        } catch (error) {
            return false;
        }

        if (!raw) return false;

        let draft = null;

        try {
            draft = JSON.parse(raw);
        } catch (error) {
            try {
                localStorage.removeItem(draftKey);
            } catch (ignore) {}
            return false;
        }

        if (
            !draft ||
            String(draft.job_id) !== String(jobId)
        ) {
            return false;
        }

        restoring = true;

        try {

            if (
                draft.results &&
                typeof draft.results === 'object'
            ) {

                Object.keys(
                    draft.results
                ).forEach(function (ref) {

                    const item =
                        draft.results[ref] || {};

                    const result =
                        item.result || '';

                    if (
                        ['C', 'NC', 'NA', 'CA'].includes(result)
                    ) {

                        const radio =
                            form.querySelector(
                                'input[name="result[' +
                                esc(ref) +
                                ']"][value="' +
                                esc(result) +
                                '"]'
                            );

                        if (radio) {
                            radio.checked = true;
                        }
                    }

                    const reading =
                        form.querySelector(
                            '[name="reading[' +
                            esc(ref) +
                            ']"]'
                        );

                    if (
                        reading &&
                        typeof item.reading === 'string'
                    ) {
                        reading.value =
                            item.reading;
                    }

                    const remark =
                        form.querySelector(
                            'textarea[data-remark-ref="' +
                            esc(ref) +
                            '"]'
                        );

                    if (
                        remark &&
                        typeof item.remark === 'string'
                    ) {
                        remark.value =
                            item.remark;
                    }

                    const justification =
                        form.querySelector(
                            'textarea[data-justification-ref="' +
                            esc(ref) +
                            '"]'
                        );

                    if (
                        justification &&
                        typeof item.technical_justification === 'string'
                    ) {
                        justification.value =
                            item.technical_justification;
                    }

                });
            }

            if (
                draft.section_remarks &&
                typeof draft.section_remarks === 'object'
            ) {

                Object.keys(
                    draft.section_remarks
                ).forEach(function (section) {

                    const textarea =
                        form.querySelector(
                            'textarea[name="section_remarks[' +
                            esc(section) +
                            ']"]'
                        );

                    if (textarea) {
                        textarea.value =
                            draft.section_remarks[section] || '';
                    }
                });
            }

            if (
                ['PASS', 'CONDITIONAL PASS', 'FAIL'].includes(
                    draft.final_status || ''
                )
            ) {

                const radio =
                    form.querySelector(
                        'input[name="final_status"][value="' +
                        esc(draft.final_status) +
                        '"]'
                    );

                if (radio) {
                    radio.checked = true;
                }
            }

            const inspector =
                form.querySelector(
                    'input[name="inspector_signature"]'
                );

            const owner =
                form.querySelector(
                    'input[name="owner_representative"]'
                );

            const amc =
                form.querySelector(
                    'input[name="amc_representative"]'
                );

            if (inspector) {
                inspector.value =
                    draft.inspector_signature || '';
            }

            if (owner) {
                owner.value =
                    draft.owner_representative || '';
            }

            if (amc) {
                amc.value =
                    draft.amc_representative || '';
            }

        } finally {
            restoring = false;
        }

        form.querySelectorAll(
            'input[name^="result["]:checked'
        ).forEach(function (input) {
            updateCheckpoint(input.dataset.ref);
        });

        updateSummary();

        setDraftStatus(
            '✓ Draft restored',
            'saved'
        );

        return true;
    }

    form.querySelectorAll(
        'input[name^="result["]'
    ).forEach(function (input) {

        input.addEventListener(
            'change',
            function () {

                updateCheckpoint(
                    input.dataset.ref
                );

                updateSummary();
                scheduleDraftSave();
            }
        );
    });

    form.querySelectorAll(
        'input[name^="reading["], select[name^="reading["]'
    ).forEach(function (input) {
        input.addEventListener('input', scheduleDraftSave);
        input.addEventListener('change', scheduleDraftSave);
    });

    form.querySelectorAll(
        'textarea, input[type="text"]'
    ).forEach(function (input) {

        input.addEventListener(
            'input',
            scheduleDraftSave
        );

        input.addEventListener(
            'change',
            scheduleDraftSave
        );
    });

    form.querySelectorAll(
        'input[name="final_status"]'
    ).forEach(function (input) {

        input.addEventListener(
            'change',
            scheduleDraftSave
        );
    });

    form.querySelectorAll(
        '.bulk-result-btn'
    ).forEach(function (button) {

        button.addEventListener(
            'click',
            function () {

                if (locked) return;

                const sectionCard =
                    button.closest('.section-card');

                const result =
                    button.dataset.result || '';

                if (
                    !sectionCard ||
                    !['C', 'NC', 'NA', 'CA'].includes(result)
                ) {
                    return;
                }

                let count = 0;

                sectionCard
                    .querySelectorAll(
                        'tr[data-checkpoint]'
                    )
                    .forEach(function (row) {

                        const ref =
                            row.dataset.checkpoint || '';

                        const radio =
                            row.querySelector(
                                'input[name="result[' +
                                esc(ref) +
                                ']"][value="' +
                                esc(result) +
                                '"]'
                            );

                        if (radio) {

                            radio.checked = true;

                            updateCheckpoint(ref);

                            count++;
                        }
                    });

                updateSummary();
                scheduleDraftSave();

                setDraftStatus(
                    '✓ ' +
                    result +
                    ' applied to ' +
                    count +
                    ' checkpoint(s)',
                    'saved'
                );
            }
        );
    });

    form.querySelectorAll(
        'input[type="file"]'
    ).forEach(function (input) {

        input.addEventListener(
            'change',
            function () {

                input.classList.remove(
                    'is-invalid'
                );

                if (
                    !input.files ||
                    !input.files.length
                ) {
                    return;
                }

                const file =
                    input.files[0];

                const isVideo =
                    input.classList.contains(
                        'video-input'
                    );

                const maxSize =
                    isVideo
                        ? 50 * 1024 * 1024
                        : 10 * 1024 * 1024;

                if (file.size > maxSize) {

                    input.value = '';

                    input.classList.add(
                        'is-invalid'
                    );

                    alert(
                        isVideo
                            ? 'Video size cannot exceed 50 MB.'
                            : 'Photograph size cannot exceed 10 MB.'
                    );

                    return;
                }

                setDraftStatus(
                    isVideo
                        ? '✓ Optional video selected'
                        : '✓ Photo selected',
                    'saved'
                );
            }
        );
    });

    function validateForm() {

        const errors = [];

        form.querySelectorAll(
            'tr[data-checkpoint]'
        ).forEach(function (row) {

            const ref =
                row.dataset.checkpoint || '';

            const unit =
                row.dataset.unit || '';

            const result =
                getResult(ref);

            if (!result) {

                errors.push(
                    ref + ': Result is required.'
                );

                return;
            }

            const reading =
                row.querySelector(
                    '[name="reading[' +
                    esc(ref) +
                    ']"]'
                );

            if (
                unit === 'mm' ||
                unit === '%'
            ) {

                if (
                    !reading ||
                    String(reading.value).trim() === '' ||
                    !Number.isFinite(Number(reading.value))
                ) {

                    errors.push(
                        ref +
                        ': Numeric reading is required.'
                    );
                }
            }

            if (unit === 'OK / Low') {

                if (
                    !reading ||
                    !['OK', 'Low'].includes(
                        String(reading.value)
                    )
                ) {

                    errors.push(
                        ref +
                        ': Select OK or Low.'
                    );
                }
            }

            if (result === 'NC') {

                const remark =
                    row.querySelector(
                        'textarea[data-remark-ref]'
                    );

                const photo =
                    row.querySelector(
                        'input.photo-input'
                    );

                if (
                    !remark ||
                    remark.value.trim() === ''
                ) {

                    errors.push(
                        ref +
                        ': Engineering Remark is required for NC.'
                    );

                    if (remark) {
                        remark.classList.add(
                            'is-invalid'
                        );
                    }
                }

                if (
                    !photo ||
                    !photo.files ||
                    !photo.files.length
                ) {

                    errors.push(
                        ref +
                        ': Photograph is required for NC.'
                    );

                    if (photo) {
                        photo.classList.add(
                            'is-invalid'
                        );
                    }
                }
            }
            if (result === 'CA') {

                const justification =
                    row.querySelector(
                        'textarea[data-justification-ref="' +
                        esc(ref) +
                        '"]'
                    );

                const photo =
                    row.querySelector(
                        'input.photo-input'
                    );

                const hasPhoto =
                    !!(
                        photo &&
                        photo.files &&
                        photo.files.length
                    );

                /*
                 * CA evidence rule:
                 * Photograph is mandatory.
                 * Video remains optional / conditional.
                 */
                if (!hasPhoto) {

                    errors.push(
                        ref +
                        ': Photograph is required for CA.'
                    );

                    if (photo) {
                        photo.classList.add('is-invalid');
                    }
                }

                if (
                    !justification ||
                    justification.value.trim() === ''
                ) {

                    errors.push(
                        ref +
                        ': Technical Justification is required for CA.'
                    );

                    if (justification) {
                        justification.classList.add(
                            'is-invalid'
                        );
                    }
                }

            }

        });

        const finalStatus =
            form.querySelector(
                'input[name="final_status"]:checked'
            );

        if (!finalStatus) {

            errors.push(
                'Please select Final Inspection Status.'
            );
        }

        return errors;
    }

    form.addEventListener(
        'submit',
        function (event) {

            if (locked) {

                event.preventDefault();

                alert(
                    'This Escalator SAT checklist is locked after Final Submit.'
                );

                return false;
            }

            form.querySelectorAll(
                '.is-invalid'
            ).forEach(function (el) {
                el.classList.remove('is-invalid');
            });

            const errors =
                validateForm();

            if (errors.length) {

                event.preventDefault();

                let message =
                    'Please correct the following:\n\n';

                errors.slice(0, 25)
                    .forEach(function (error) {
                        message +=
                            '• ' + error + '\n';
                    });

                if (errors.length > 25) {
                    message +=
                        '\nAnd ' +
                        (errors.length - 25) +
                        ' more issue(s).';
                }

                alert(message);

                const firstInvalid =
                    form.querySelector(
                        '.is-invalid'
                    );

                if (firstInvalid) {

                    firstInvalid.scrollIntoView({
                        behavior: 'smooth',
                        block: 'center'
                    });

                    firstInvalid.focus();
                }

                return false;
            }

            setDraftStatus(
                '✓ Escalator SAT checklist is being submitted',
                'saved'
            );

            /* Final server save is authoritative. Clear only the
             * local draft so old incomplete data cannot reappear
             * after the completed checklist is saved.
             */
            try {
                localStorage.removeItem(draftKey);
            } catch (e) {}

            return true;
        }
    );

    const resetButton =
        document.getElementById('resetChecklist');

    if (resetButton) {

        resetButton.addEventListener(
            'click',
            function (event) {

                if (!window.confirm(
                    'Reset the Escalator SAT checklist fields on this page? Unsaved data will be cleared.'
                )) {

                    event.preventDefault();
                    return;
                }

                try {
                    localStorage.removeItem(
                        draftKey
                    );
                } catch (e) {}

                window.setTimeout(
                    function () {

                        form.querySelectorAll(
                            '.dynamic-required'
                        ).forEach(function (el) {
                            el.classList.remove('show');
                        });

                        form.querySelectorAll(
                            '.result-evidence'
                        ).forEach(function (el) {
                            el.classList.remove('show');
                        });

                        updateSummary();

                        setDraftStatus(
                            'Draft cleared',
                            'saving'
                        );
                    },
                    50
                );
            }
        );
    }

    window.addEventListener(
        'beforeunload',
        function () {

            if (!restoring && !locked) {
                saveDraft();
            }
        }
    );

    if (!locked) {
        restoreDraft();
    }

    if (locked) {

        form.querySelectorAll(
            'input, textarea, select, button'
        ).forEach(function (el) {

            if (el.type !== 'hidden') {
                el.disabled = true;
            }
        });

        form.querySelectorAll(
            'input, textarea, select'
        ).forEach(function (el) {
            el.readOnly = true;
        });

        const saveButton =
            document.getElementById(
                'saveChecklistButton'
            );

        if (saveButton) {
            saveButton.style.display = 'none';
        }

        const reset =
            document.getElementById(
                'resetChecklist'
            );

        if (reset) {
            reset.style.display = 'none';
        }

        setDraftStatus(
            'Final submitted — checklist is view-only',
            'saved'
        );
    }

    form.querySelectorAll(
        'input[name^="result["]:checked'
    ).forEach(function (input) {
        updateCheckpoint(
            input.dataset.ref
        );
    });

    updateSummary();

})();
</script>

<?php include('../includes/footer.php'); ?>
