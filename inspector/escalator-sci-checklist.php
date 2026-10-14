<?php
/* =========================================================
   NLETA INSPECTOR — ESCALATOR SCI CHECKLIST
   ---------------------------------------------------------
   ECHK-03 — Rev.1
   Asset Type: ESCALATOR ONLY
   Module: SCI — Standard Compliance Inspection

   SOURCE OF TRUTH:
   ECHK-03 — NLETA ESCALATOR SCI MASTER CHECKLIST

   FIXED CHECKPOINTS:
   E-SCI-001 -> E-SCI-030

   RESULT CODES:
   C  = Complied
   NC = Non-Complied
   NA = Not Applicable
   CA = Conditional Acceptance

   CONDITIONS APPLIED:
   - Priority shown in the UI.
   - Reading column is NOT used because ECHK-03 has no
     Reading field.
   - C / NC / NA / CA are available.
   - NC -> Photograph + Engineering Remark REQUIRED.
   - CA -> Photograph + Technical Justification REQUIRED.
   - Video -> OPTIONAL / conditional.
   - Checklist Progress shows ALL result types:
       Total / Pending / C / NC / NA / CA / Locked
   - Cross-Lock:
       E-SCI-006 <- E-PAT-016
       E-SCI-018 <- E-PAT-049
       E-SCI-019 <- E-PAT-018
       E-SCI-023 <- E-PAT-057
       E-SCI-024 <- E-PAT-059
       E-SCI-027 <- E-SAT Functional Verification

     If source checkpoint is C, the SCI checkpoint is hidden
     and counted as Locked.

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
   LOAD ASSIGNED ESCALATOR SCI JOB
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
        '<div style="margin:30px;padding:20px;border:1px solid #dc3545;'
        . 'border-radius:10px;background:#fff5f5;font-family:Arial">'
        . '<h3 style="color:#b02a37">Escalator SCI Database Error</h3>'
        . '<strong>Prepare failed:</strong> ' . h($conn->error)
        . '</div>'
    );
}

$stmt->bind_param('ii', $jobId, $inspectorId);

if (!$stmt->execute()) {
    die(
        '<div style="margin:30px;padding:20px;border:1px solid #dc3545;'
        . 'border-radius:10px;background:#fff5f5;font-family:Arial">'
        . '<h3 style="color:#b02a37">Escalator SCI Database Error</h3>'
        . '<strong>Execute failed:</strong> ' . h($stmt->error)
        . '</div>'
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
   ESCALATOR ONLY
========================================================= */

$assetType = strtolower(trim((string)($job['asset_type'] ?? '')));

if (!str_contains($assetType, 'escalator')) {
    die('This checklist is only available for Escalator assets.');
}

/* =========================================================
   SCI ONLY
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

if ($serviceCode !== 'SCI') {
    die(
        'This checklist is configured for Escalator SCI service only. '
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
        'This Escalator SCI inspection is not available for checklist work. '
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
   ECHK-03 — EXACT 30 SOURCE CHECKPOINTS
========================================================= */

$sections = [
    'A' => [
        'title' => 'SECTION A — DOCUMENTATION & IDENTIFICATION',
        'items' => [
            ['E-SCI-001', 'Equipment identification plate available', 'P2'],
            ['E-SCI-002', 'Manufacturer name plate legible', 'P3'],
            ['E-SCI-003', 'Serial number matches records', 'P2'],
            ['E-SCI-004', 'Rated load & speed displayed', 'P2'],
            ['E-SCI-005', 'Directional signage provided', 'P3'],
            ['E-SCI-006', 'Emergency instruction signage available', 'P2'],
            ['E-SCI-007', 'Safety pictograms displayed', 'P2'],
            ['E-SCI-008', 'Maintenance logbook available', 'P2'],
        ],
    ],

    'B' => [
        'title' => 'SECTION B — STATUTORY COMPLIANCE',
        'items' => [
            ['E-SCI-009', 'Periodic inspection records available', 'P2'],
            ['E-SCI-010', 'Preventive maintenance records updated', 'P2'],
            ['E-SCI-011', 'Breakdown & repair history maintained', 'P3'],
            ['E-SCI-012', 'Risk assessment records available', 'P2'],
            ['E-SCI-013', 'OEM technical manual available', 'P3'],
            ['E-SCI-014', 'Electrical schematic available', 'P3'],
        ],
    ],

    'C' => [
        'title' => 'SECTION C — MACHINE ROOM COMPLIANCE',
        'items' => [
            ['E-SCI-015', 'Authorized access control provided', 'P2'],
            ['E-SCI-016', 'Adequate machine room ventilation', 'P2'],
            ['E-SCI-017', 'Machine room housekeeping compliant', 'P2'],
            ['E-SCI-018', 'Emergency lighting available', 'P2'],
            ['E-SCI-019', 'Safe working clearance maintained', 'P1'],
            ['E-SCI-020', 'Rotating parts adequately guarded', 'P1'],
        ],
    ],

    'D' => [
        'title' => 'SECTION D — ELECTRICAL & FIRE COMPLIANCE',
        'items' => [
            ['E-SCI-021', 'Control panel identification labels', 'P3'],
            ['E-SCI-022', 'Cable routing properly secured', 'P2'],
            ['E-SCI-023', 'Earth continuity documentation available', 'P1'],
            ['E-SCI-024', 'Fire protection provisions compliant', 'P2'],
            ['E-SCI-025', 'Emergency isolation procedure displayed', 'P2'],
        ],
    ],

    'E' => [
        'title' => 'SECTION E — PASSENGER SAFETY GOVERNANCE',
        'items' => [
            ['E-SCI-026', 'Operational safety policy available', 'P3'],
            ['E-SCI-027', 'Emergency rescue procedure displayed', 'P2'],
            ['E-SCI-028', 'Maintenance contractor details displayed', 'P3'],
            ['E-SCI-029', 'Incident reporting procedure available', 'P3'],
            ['E-SCI-030', 'Annual compliance review completed', 'P2'],
        ],
    ],
];

$totalSourceCheckpoints = 0;

foreach ($sections as $section) {
    $totalSourceCheckpoints += count($section['items']);
}

if ($totalSourceCheckpoints !== 30) {
    die(
        'ECHK-03 master configuration error. Expected 30 checkpoints, found '
        . $totalSourceCheckpoints . '.'
    );
}

/* =========================================================
   CROSS-LOCK CONFIGURATION
   Source of truth from ECHK-03.
========================================================= */

$crossLocks = [
    'E-SCI-006' => [
        'source_module' => 'PAT',
        'source_ref' => 'E-PAT-016',
        'label' => 'Locked by E-PAT-016'
    ],

    'E-SCI-018' => [
        'source_module' => 'PAT',
        'source_ref' => 'E-PAT-049',
        'label' => 'Locked by E-PAT-049'
    ],

    'E-SCI-019' => [
        'source_module' => 'PAT',
        'source_ref' => 'E-PAT-018',
        'label' => 'Locked by E-PAT-018'
    ],

    'E-SCI-023' => [
        'source_module' => 'PAT',
        'source_ref' => 'E-PAT-057',
        'label' => 'Locked by E-PAT-057'
    ],

    'E-SCI-024' => [
        'source_module' => 'PAT',
        'source_ref' => 'E-PAT-059',
        'label' => 'Locked by E-PAT-059'
    ],

    'E-SCI-027' => [
        'source_module' => 'SAT',
        'source_ref' => 'E-SAT Functional Verification',
        'label' => 'Locked by E-SAT Functional Verification'
    ],
];

/* =========================================================
   CROSS-LOCK ENGINE
   ---------------------------------------------------------
   Reads PAT/SAT checklist JSON from maintenance_jobs for
   the SAME SAF + SAME ASSET.

   Exact source checkpoint must have result = C.
========================================================= */

function checklistJsonResults(array $row): array
{
    $json = (string)($row['checklist'] ?? '');

    if ($json === '') {
        return [];
    }

    $decoded = json_decode($json, true);

    if (!is_array($decoded)) {
        return [];
    }

    $results = $decoded['results'] ?? [];

    return is_array($results) ? $results : [];
}

$lockedCheckpoints = [];
$lockReasons = [];

$sourceStmt = $conn->prepare("
    SELECT
        mj.id,
        mj.checklist,
        s.service_code,
        s.service_name
    FROM maintenance_jobs mj
    INNER JOIN saf_contracts s
        ON s.id = mj.saf_id
    WHERE
        mj.saf_id = ?
        AND mj.asset_id = ?
        AND mj.job_type = 'saf_inspection'
        AND s.service_code IN ('PAT','SAT')
    ORDER BY mj.id DESC
");

if ($sourceStmt) {

    $sourceSafId = (int)$job['saf_id'];
    $sourceAssetId = (int)$job['asset_id'];

    $sourceStmt->bind_param(
        'ii',
        $sourceSafId,
        $sourceAssetId
    );

    if ($sourceStmt->execute()) {

        $sourceResult =
            $sourceStmt->get_result();

        while ($sourceRow = $sourceResult->fetch_assoc()) {

            $module =
                strtoupper(
                    trim(
                        (string)(
                            $sourceRow['service_code']
                            ??
                            ''
                        )
                    )
                );

            $results =
                checklistJsonResults(
                    $sourceRow
                );

            foreach (
                $crossLocks as $sciRef => $lock
            ) {

                if (
                    isset(
                        $lockedCheckpoints[$sciRef]
                    )
                ) {
                    continue;
                }

                if (
                    $module !==
                    strtoupper(
                        $lock['source_module']
                    )
                ) {
                    continue;
                }

                $sourceRef =
                    $lock['source_ref'];

                /*
                 * Normal PAT/SAT checkpoint:
                 * results[REF]['result'] = C
                 */
                $sourceItem =
                    $results[$sourceRef]
                    ??
                    null;

                $sourceResultCode = '';

                if (is_array($sourceItem)) {
                    $sourceResultCode =
                        strtoupper(
                            trim(
                                (string)(
                                    $sourceItem['result']
                                    ??
                                    ''
                                )
                            )
                        );
                }

                /*
                 * E-SAT Functional Verification is a
                 * document-defined functional verification
                 * label rather than a numbered E-SAT checkpoint.
                 *
                 * Support common stored keys without changing
                 * the source document's wording.
                 */
                if (
                    $sciRef === 'E-SCI-027' &&
                    $sourceResultCode !== 'C'
                ) {

                    $functionalCandidates = [
                        'E-SAT Functional Verification',
                        'E-SAT-FUNCTIONAL-VERIFICATION',
                        'functional_verification',
                        'functional_verification_status'
                    ];

                    foreach (
                        $functionalCandidates
                        as $candidate
                    ) {

                        $candidateItem =
                            $results[$candidate]
                            ??
                            null;

                        if (is_array($candidateItem)) {

                            $candidateResult =
                                strtoupper(
                                    trim(
                                        (string)(
                                            $candidateItem['result']
                                            ??
                                            $candidateItem['status']
                                            ??
                                            ''
                                        )
                                    )
                                );

                            if (
                                $candidateResult === 'C'
                            ) {
                                $sourceResultCode = 'C';
                                break;
                            }
                        }
                    }

                    /*
                     * Also support a top-level stored value
                     * if the SAT save engine stores functional
                     * verification separately.
                     */
                    if (
                        $sourceResultCode !== 'C' &&
                        strtoupper(
                            trim(
                                (string)(
                                    $decoded['functional_verification']
                                    ??
                                    ''
                                )
                            )
                        ) === 'C'
                    ) {
                        $sourceResultCode = 'C';
                    }
                }

                if ($sourceResultCode === 'C') {

                    $lockedCheckpoints[$sciRef] = true;

                    $lockReasons[$sciRef] =
                        $lock['label'];
                }
            }
        }
    }

    $sourceStmt->close();
}

/* =========================================================
   LOAD EXISTING SCI CHECKLIST
========================================================= */

$existingChecklist = [];

if (!empty($job['saved_checklist'])) {

    $decoded =
        json_decode(
            (string)$job['saved_checklist'],
            true
        );

    if (is_array($decoded)) {
        $existingChecklist = $decoded;
    }
}

$existingResults =
    is_array(
        $existingChecklist['results'] ?? null
    )
        ? $existingChecklist['results']
        : [];

$existingSectionRemarks =
    is_array(
        $existingChecklist['section_remarks'] ?? null
    )
        ? $existingChecklist['section_remarks']
        : [];

$existingFinalStatus =
    strtoupper(
        trim(
            (string)(
                $existingChecklist['final_status']
                ??
                ''
            )
        )
    );

if (!in_array(
    $existingFinalStatus,
    ['PASS', 'CONDITIONAL PASS', 'FAIL'],
    true
)) {
    $existingFinalStatus = '';
}

$existingInspectorSignature =
    (string)(
        $existingChecklist['inspector_signature']
        ??
        ''
    );

$existingOwnerRepresentative =
    (string)(
        $existingChecklist['owner_representative']
        ??
        ''
    );

$existingAmcRepresentative =
    (string)(
        $existingChecklist['amc_representative']
        ??
        ''
    );

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
    $value =
        strtoupper(
            trim(
                savedValue(
                    $results,
                    $key,
                    'result'
                )
            )
        );

    if ($value === 'D') {
        $value = 'CA';
    }

    return in_array(
        $value,
        ['C', 'NC', 'NA', 'CA'],
        true
    )
        ? $value
        : '';
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
.legend-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;padding:18px}
.legend-item{border:1px solid #e1e5e9;border-radius:10px;padding:13px;background:#fff}
.legend-code{font-size:18px;font-weight:900}.legend-description{color:#6c757d;font-size:13px;margin-top:3px}
.legend-c{color:#198754}.legend-nc{color:#dc3545}.legend-na{color:#495057}.legend-ca{color:#9a6500}
.rule-box{margin:0 18px 18px;border:1px solid #b8d9ff;background:#eef6ff;border-radius:10px;padding:13px 15px;color:#173d66;font-size:13px;line-height:1.6}
.lock-box{margin:0 18px 18px;border:1px solid #cfe2ff;background:#f3f8ff;border-radius:10px;padding:13px 15px;color:#214d7b;font-size:13px}
.lock-list{display:flex;flex-wrap:wrap;gap:7px;margin-top:8px}
.lock-chip{display:inline-flex;align-items:center;gap:5px;background:#e8f1ff;border:1px solid #bdd7ff;border-radius:20px;padding:5px 9px;font-size:11px;font-weight:800;color:#24558c}
.section-heading{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:14px 18px;background:#0d6efd;color:#fff}
.section-heading-title{font-size:17px;font-weight:800}.section-count{background:rgba(255,255,255,.18);border-radius:20px;padding:5px 10px;font-size:11px;font-weight:800}
.section-bulk-result{padding:12px 18px;background:#f8fbff;border-bottom:1px solid #dbe8f7}
.section-bulk-label{font-size:12px;font-weight:800;color:#26364d;margin-bottom:8px}
.section-bulk-buttons{display:flex;flex-wrap:wrap;gap:7px}
.bulk-result-btn{border:1px solid #ced4da;border-radius:7px;background:#fff;padding:7px 11px;font-size:12px;font-weight:800;cursor:pointer;transition:.15s}
.bulk-result-btn:hover{transform:translateY(-1px);box-shadow:0 2px 7px rgba(0,0,0,.08)}
.bulk-c{color:#198754;border-color:#b7dfc8}.bulk-c:hover{background:#eaf8ef}
.bulk-nc{color:#dc3545;border-color:#f1b7bd}.bulk-nc:hover{background:#fff0f1}
.bulk-na{color:#495057}.bulk-ca{color:#9a6500}
.table-wrap{overflow-x:auto}
.checklist-table{width:100%;border-collapse:collapse;min-width:1000px;table-layout:fixed}
.checklist-table th{background:#f7f8fa;border-bottom:2px solid #dee2e6;color:#26364d;padding:11px 10px;font-size:12px;font-weight:800;text-transform:uppercase;white-space:nowrap}
.checklist-table td{padding:10px;border-bottom:1px solid #edf0f3;vertical-align:top;font-size:13px}
.checklist-table.no-reading th:nth-child(1),.checklist-table.no-reading td:nth-child(1){width:10%}
.checklist-table.no-reading th:nth-child(2),.checklist-table.no-reading td:nth-child(2){width:10%}
.checklist-table.no-reading th:nth-child(3),.checklist-table.no-reading td:nth-child(3){width:20%}
.checklist-table.no-reading th:nth-child(4),.checklist-table.no-reading td:nth-child(4){width:17%}
.checklist-table.no-reading th:nth-child(5),.checklist-table.no-reading td:nth-child(5){width:25%}
.checkpoint-text{font-weight:650;color:#202938;line-height:1.45}.ref-code{font-weight:900;color:#172033;white-space:nowrap}
.priority-badge{display:inline-block;min-width:43px;text-align:center;padding:5px 7px;border-radius:6px;font-size:11px;font-weight:900}
.priority-p1{background:#f8d7da;color:#842029}.priority-p2{background:#ffe5d0;color:#9a3412}.priority-p3{background:#fff3cd;color:#856404}.priority-p4{background:#cfe2ff;color:#084298}
.result-options{display:flex;flex-wrap:wrap;gap:6px;min-width:175px}.result-option{display:inline-flex;align-items:center;gap:4px;padding:6px 8px;border:1px solid #d8dde3;border-radius:7px;background:#fff;cursor:pointer;font-size:12px;font-weight:700}.result-option input{margin:0}
.evidence-area{min-width:260px;max-width:360px}.result-evidence{display:none}.result-evidence.show{display:block}
.dynamic-required{display:none}.dynamic-required.show{display:block}
.field-group{margin-top:8px}.field-group:first-child{margin-top:0}.field-label{display:block;font-size:11px;font-weight:800;margin-bottom:4px}.required{color:#dc3545}
.file-input{width:100%;font-size:12px}.file-help{display:block;margin-top:4px;font-size:10px;color:#6c757d}
.remark-input{width:100%;min-height:58px;resize:vertical;border:1px solid #ced4da;border-radius:7px;padding:8px;font-size:12px}
.locked-row{background:#f7f9fc}.locked-row td{color:#6c757d}
.locked-checkpoint{display:flex;align-items:center;gap:5px;margin-top:7px;color:#286090;font-size:11px;font-weight:800}
.locked-result{display:inline-flex;align-items:center;gap:5px;padding:7px 10px;border-radius:7px;background:#e9f2ff;border:1px solid #c6ddff;color:#24558c;font-size:12px;font-weight:800}
.summary-grid{display:grid;gap:10px;padding:18px;grid-template-columns:repeat(4,minmax(0,1fr))}
.summary-box{border:1px solid #e1e5e9;border-radius:10px;padding:14px;background:#fff;min-width:0}.summary-label{color:#6c757d;font-size:12px;font-weight:700}.summary-value{margin-top:4px;font-size:24px;font-weight:900}
.summary-total{color:#0d6efd}.summary-pending{color:#b27a00}.summary-c{color:#198754}.summary-nc{color:#dc3545}.summary-na{color:#495057}.summary-ca{color:#9a6500}.summary-locked{color:#286090}
.final-status-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;padding:18px}
.final-option{border:1px solid #ced4da;border-radius:10px;padding:14px;cursor:pointer;font-weight:800;background:#fff}.final-option input{margin-right:7px}
.final-fields{padding:0 18px 18px;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.final-field label{display:block;font-size:12px;font-weight:800;margin-bottom:5px}.final-field input{width:100%;border:1px solid #ced4da;border-radius:7px;padding:9px;font-size:13px}
.draft-status{display:inline-flex;align-items:center;gap:6px;margin-left:12px;font-size:12px;font-weight:700;color:#198754}
.draft-status.saving{color:#b27a00}.draft-status.saved{color:#198754}.draft-status.error{color:#dc3545}
.action-bar{position:sticky;bottom:0;z-index:20;background:rgba(255,255,255,.97);border-top:1px solid #dee2e6;padding:14px 18px;box-shadow:0 -3px 12px rgba(0,0,0,.05)}
.action-content{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.is-invalid{border-color:#dc3545!important}
.nc-row{background:#fff8f8}.ca-row{background:#fffaf1}
@media(max-width:1000px){.job-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.summary-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.final-fields{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:650px){.nleta-checklist-page{padding:8px}.job-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;padding:10px}.job-box{padding:9px}.job-label{font-size:9px}.job-value{font-size:12px}.legend-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;padding:10px}.legend-item{padding:10px}.legend-code{font-size:16px}.legend-description{font-size:11px}.rule-box,.lock-box{margin:0 10px 10px;font-size:11px;line-height:1.5}.section-heading{padding:11px 12px}.section-heading-title{font-size:14px}.section-count{font-size:9px}.section-bulk-result{padding:10px 12px}.section-bulk-buttons{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px}.bulk-result-btn{width:100%;min-width:0;padding:7px 6px;font-size:10px}.checklist-table{min-width:1000px}.checklist-table th,.checklist-table td{padding:8px;font-size:11px}.result-options{min-width:175px}.result-option{padding:5px 7px;font-size:11px}.evidence-area{min-width:240px;max-width:300px}.summary-grid{grid-template-columns:repeat(2,minmax(0,1fr));padding:10px}.summary-box{padding:10px}.summary-value{font-size:20px}.final-status-grid{grid-template-columns:1fr;padding:10px}.final-option{padding:10px;font-size:11px}.final-fields{grid-template-columns:1fr;padding:0 10px 10px}.action-bar{padding:10px}.action-content{align-items:stretch}.action-content>div{width:100%}.action-content .btn{margin:3px 0!important;width:100%}.draft-status{margin-left:0;display:block;margin-top:4px}.page-title{font-size:20px}.nleta-card-header{padding:13px}}
</style>

<div class="content">
<div class="nleta-checklist-page">

<div class="nleta-card">
    <div class="nleta-card-header">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <div class="controlled-badge mb-2">
                    <i class="bi bi-shield-check"></i>
                    ECHK-03 • REV.1 • CONTROLLED CHECKLIST
                </div>
                <h1 class="page-title">NLETA Escalator SCI Inspection Checklist</h1>
                <div class="page-subtitle">
                    Standard Compliance Inspection — Escalator Master Checklist
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
        <div class="job-box"><div class="job-label">Service</div><div class="job-value"><?= h($job['service_name'] ?: 'SCI') ?></div></div>
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
        <div class="job-box"><div class="job-label">Checklist</div><div class="job-value">ECHK-03 Rev.1</div></div>
        <div class="job-box"><div class="job-label">Source Checkpoints</div><div class="job-value"><?= (int)$totalSourceCheckpoints ?></div></div>
        <div class="job-box"><div class="job-label">Cross-Locked</div><div class="job-value" id="jobLockedCount"><?= count($lockedCheckpoints) ?></div></div>
    </div>

    <?php if ($isFinalSubmitted): ?>
        <div class="rule-box" style="margin-top:0">
            <strong><i class="bi bi-lock-fill me-1"></i>Final submitted:</strong>
            This SCI checklist is view-only and cannot be edited.
        </div>
    <?php endif; ?>
</div>

<div class="nleta-card">
    <div class="nleta-card-header">
        <h4 class="mb-1"><i class="bi bi-ui-checks-grid me-2"></i>Result Code Standard</h4>
        <div class="text-muted small">
            Select exactly one result for each visible Escalator SCI checkpoint.
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
            <div class="legend-code legend-na">NA</div>
            <div class="legend-description">Not Applicable</div>
        </div>
        <div class="legend-item">
            <div class="legend-code legend-ca">CA</div>
            <div class="legend-description">Conditional Acceptance</div>
        </div>
    </div>

    <div class="rule-box">
        <strong>Mandatory Rules:</strong>
        <span class="ms-2">NC → Photograph REQUIRED</span>
        <span class="ms-2">NC → Engineering Remark REQUIRED</span>
        <span class="ms-2">CA → Photograph + Technical Justification REQUIRED</span>
        <span class="ms-2">Video → OPTIONAL / conditional</span>
        <span class="ms-2">Cross-Lock → Source C hides the SCI checkpoint</span>
    </div>

    <?php if ($lockedCheckpoints): ?>
        <div class="lock-box">
            <strong><i class="bi bi-lock-fill me-1"></i>Cross-Lock Active</strong>
            <div class="lock-list">
                <?php foreach ($lockedCheckpoints as $ref => $_): ?>
                    <span class="lock-chip">
                        <i class="bi bi-lock-fill"></i>
                        <?= h($ref) ?> — <?= h($lockReasons[$ref] ?? 'Locked by source checkpoint') ?>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<form method="post"
      action="escalator-sci-checklist-save.php"
      enctype="multipart/form-data"
      id="escalatorSciChecklistForm"
      novalidate
      data-final-submitted="<?= $isFinalSubmitted ? '1' : '0' ?>">

<input type="hidden" name="job_id" value="<?= (int)$jobId ?>">
<input type="hidden" name="assignment_id" value="<?= (int)$assignmentId ?>">
<input type="hidden" name="saf_id" value="<?= (int)$job['saf_id'] ?>">
<input type="hidden" name="asset_id" value="<?= (int)$job['asset_id'] ?>">
<input type="hidden" name="checklist_code" value="ECHK-03">
<input type="hidden" name="checklist_revision" value="Rev.1">
<input type="hidden" name="module" value="SCI">
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
            <button type="button" class="bulk-result-btn bulk-c" data-section="<?= h($sectionCode) ?>" data-result="C">C — Complied</button>
            <button type="button" class="bulk-result-btn bulk-nc" data-section="<?= h($sectionCode) ?>" data-result="NC">NC — Non-Complied</button>
            <button type="button" class="bulk-result-btn bulk-na" data-section="<?= h($sectionCode) ?>" data-result="NA">NA — Not Applicable</button>
            <button type="button" class="bulk-result-btn bulk-ca" data-section="<?= h($sectionCode) ?>" data-result="CA">CA — Conditional Acceptance</button>
        </div>
    </div>

    <div class="table-wrap">
        <table class="checklist-table no-reading">

            <thead>
            <tr>
                <th>ID</th>
                <th>Priority</th>
                <th>Standard Compliance Checkpoint</th>
                <th>Result</th>
                <th>Evidence / Remark</th>
            </tr>
            </thead>

            <tbody>

            <?php foreach ($section['items'] as $item): ?>

                <?php
                $ref = $item[0];
                $description = $item[1];
                $priority = $item[2];

                $isLocked =
                    isset($lockedCheckpoints[$ref]);

                $oldResult =
                    savedResult(
                        $existingResults,
                        $ref
                    );

                $oldRemark =
                    savedValue(
                        $existingResults,
                        $ref,
                        'remark'
                    );

                $oldJustification =
                    savedValue(
                        $existingResults,
                        $ref,
                        'technical_justification'
                    );
                ?>

                <tr
                    data-checkpoint="<?= h($ref) ?>"
                    data-section="<?= h($sectionCode) ?>"
                    data-locked="<?= $isLocked ? '1' : '0' ?>"
                    class="<?= $isLocked ? 'locked-row' : '' ?>"
                >

                    <td>
                        <span class="ref-code">
                            <?= h($ref) ?>
                        </span>

                        <?php if ($isLocked): ?>
                            <div class="locked-checkpoint">
                                <i class="bi bi-lock-fill"></i>
                                AUTO LOCKED
                            </div>
                        <?php endif; ?>
                    </td>

                    <td>
                        <span class="priority-badge priority-<?= strtolower(h($priority)) ?>">
                            <?= h($priority) ?>
                        </span>
                    </td>

                    <td>
                        <div class="checkpoint-text">
                            <?= h($description) ?>
                        </div>

                        <?php if ($isLocked): ?>
                            <div class="small text-muted mt-2">
                                <?= h($lockReasons[$ref] ?? 'Source checkpoint complied.') ?>
                            </div>
                        <?php endif; ?>
                    </td>

                    <td>

                        <?php if ($isLocked): ?>

                            <div class="locked-result">
                                <i class="bi bi-lock-fill"></i>
                                C — Complied
                            </div>

                            <input
                                type="hidden"
                                name="locked_result[<?= h($ref) ?>]"
                                value="C"
                            >

                        <?php else: ?>

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

                            <div class="small text-muted mt-2 status-note" data-ref="<?= h($ref) ?>">
                                Select C, NC, NA or CA.
                            </div>

                        <?php endif; ?>

                    </td>

                    <td>

                        <?php if (!$isLocked): ?>

                            <div class="evidence-area">

                                <div class="result-evidence" data-ref="<?= h($ref) ?>">

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
                                            NC / CA → Photograph REQUIRED. Maximum 3 MB.
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
                                            Optional / conditional. Maximum 20 MB.
                                        </span>

                                    </div>

                                    <div class="dynamic-required remark-field" data-ref="<?= h($ref) ?>">

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
                                                placeholder="Engineering / compliance observation..."
                                            ><?= h($oldRemark) ?></textarea>

                                        </div>

                                    </div>

                                    <div class="dynamic-required ca-field" data-ref="<?= h($ref) ?>">

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
                                            ><?= h($oldJustification) ?></textarea>

                                        </div>

                                    </div>

                                </div>

                                <div class="small text-muted mt-2 status-note" data-ref="<?= h($ref) ?>">
                                    Select C, NC, NA or CA.
                                </div>

                            </div>

                        <?php else: ?>

                            <div class="small text-muted">
                                Evidence input not required because this checkpoint is cross-locked by a compliant PAT/SAT source checkpoint.
                            </div>

                        <?php endif; ?>

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
            ECHK-03 Rev.1 — 30 source Escalator SCI checkpoints
        </div>

    </div>

    <div class="summary-grid">

        <div class="summary-box">
            <div class="summary-label">Total Checkpoints</div>
            <div class="summary-value summary-total" id="summaryTotal">
                <?= (int)$totalSourceCheckpoints ?>
            </div>
        </div>

        <div class="summary-box">
            <div class="summary-label">Pending</div>
            <div class="summary-value summary-pending" id="summaryPending">0</div>
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
            <div class="summary-value summary-na" id="summaryNA">0</div>
        </div>

        <div class="summary-box">
            <div class="summary-label">CA — Conditional Acceptance</div>
            <div class="summary-value summary-ca" id="summaryCA">0</div>
        </div>

        <div class="summary-box">
            <div class="summary-label">Auto Locked</div>
            <div class="summary-value summary-locked" id="summaryLocked">
                <?= count($lockedCheckpoints) ?>
            </div>
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
            Overall Escalator SCI inspection outcome.
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

            <strong>ECHK-03 Rev.1</strong>

            <span class="text-muted ms-2">
                <?= (int)$totalSourceCheckpoints ?> Escalator SCI Checkpoints
            </span>

            <span id="draftStatus"
                  class="draft-status"
                  aria-live="polite"></span>

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
                    Save Escalator SCI Checklist
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
        document.getElementById('escalatorSciChecklistForm');

    if (!form) return;

    const jobId =
        form.querySelector('input[name="job_id"]')?.value || '0';

    const safId =
        form.querySelector('input[name="saf_id"]')?.value || '0';

    const locked =
        form.dataset.finalSubmitted === '1';

    const draftKey =
        'NLETA_ECHK_03_SCI_REV1_JOB_' +
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

        if (!row || row.dataset.locked === '1') {
            return;
        }

        const result = getResult(ref);

        const evidence =
            row.querySelector('.result-evidence');

        const remarkField =
            row.querySelector('.remark-field');

        const caField =
            row.querySelector('.ca-field');

        const remark =
            row.querySelector(
                'textarea[data-remark-ref="' +
                esc(ref) +
                '"]'
            );

        const justification =
            row.querySelector(
                'textarea[data-justification-ref="' +
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

        if (justification) {
            justification.required = false;
            justification.classList.remove('is-invalid');
        }

        if (photoRequired) {
            photoRequired.style.display = 'none';
        }

        row.classList.remove(
            'nc-row',
            'ca-row'
        );

        if (result === 'C') {

            if (note) {
                note.textContent = 'C — Complied.';
                note.className =
                    'small text-success mt-2 status-note';
            }

            return;
        }

        if (result === 'NA') {

            if (note) {
                note.textContent =
                    'NA — Not Applicable.';
                note.className =
                    'small text-secondary mt-2 status-note';
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

        if (result === 'CA') {

            if (evidence) {
                evidence.classList.add('show');
            }

            if (caField) {
                caField.classList.add('show');
            }

            /* CA: Engineering Remark is NOT applicable.
             * Technical Justification remains mandatory.
             */
            if (justification) {
                justification.required = true;
            }

            if (photoRequired) {
                photoRequired.style.display = 'inline';
            }

            row.classList.add('ca-row');

            if (note) {
                note.textContent =
                    'CA — Photograph and Technical Justification are mandatory.';

                note.className =
                    'small mt-2 status-note';

                note.style.color =
                    '#9a6500';
            }

            return;
        }

        if (note) {
            note.textContent =
                'Select C, NC, NA or CA.';

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

        const total =
            <?= (int)$totalSourceCheckpoints ?>;

        const lockedCount =
            form.querySelectorAll(
                'tr[data-locked="1"]'
            ).length;

        form.querySelectorAll(
            'tr[data-checkpoint]:not([data-locked="1"])'
        ).forEach(function (row) {

            const ref =
                row.dataset.checkpoint || '';

            const result =
                getResult(ref);

            if (!result) {
                return;
            }

            selected++;

            if (result === 'C') c++;
            if (result === 'NC') nc++;
            if (result === 'NA') na++;
            if (result === 'CA') ca++;
        });

        const pending =
            total -
            lockedCount -
            selected;

        function set(id, value) {

            const el =
                document.getElementById(id);

            if (el) {
                el.textContent =
                    Math.max(0, value);
            }
        }

        set('summaryTotal', total);
        set('summaryPending', pending);
        set('summaryC', c);
        set('summaryNC', nc);
        set('summaryNA', na);
        set('summaryCA', ca);
        set('summaryLocked', lockedCount);
    }

    function collectDraft() {

        const draft = {
            version:
                'ECHK-03-REV1-30',

            job_id:
                jobId,

            saf_id:
                safId,

            saved_at:
                new Date().toISOString(),

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

            const ref =
                input.dataset.ref;

            if (!ref) return;

            const row =
                input.closest('tr');

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

                result:
                    input.value,

                remark:
                    remark
                        ? remark.value
                        : '',

                technical_justification:
                    justification
                        ? justification.value
                        : ''
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

        if (restoring || locked) {
            return;
        }

        try {

            localStorage.setItem(
                draftKey,
                JSON.stringify(
                    collectDraft()
                )
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

        if (restoring || locked) {
            return;
        }

        setDraftStatus(
            'Saving…',
            'saving'
        );

        clearTimeout(saveTimer);

        saveTimer =
            setTimeout(
                saveDraft,
                250
            );
    }

    function restoreDraft() {

        if (locked) {
            return false;
        }

        let raw = null;

        try {
            raw =
                localStorage.getItem(
                    draftKey
                );
        } catch (error) {
            return false;
        }

        if (!raw) {
            return false;
        }

        let draft = null;

        try {

            draft =
                JSON.parse(raw);

        } catch (error) {

            try {
                localStorage.removeItem(
                    draftKey
                );
            } catch (ignore) {}

            return false;
        }

        if (
            !draft ||
            String(draft.job_id) !==
                String(jobId)
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

                    /*
                     * Cross-locked checkpoints must never
                     * be restored as editable.
                     */
                    const row =
                        form.querySelector(
                            'tr[data-checkpoint="' +
                            esc(ref) +
                            '"]'
                        );

                    if (
                        row &&
                        row.dataset.locked === '1'
                    ) {
                        return;
                    }

                    const item =
                        draft.results[ref] || {};

                    const result =
                        item.result || '';

                    if (
                        [
                            'C',
                            'NC',
                            'NA',
                            'CA'
                        ].includes(result)
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
                            radio.checked =
                                true;
                        }
                    }

                    const remark =
                        form.querySelector(
                            'textarea[data-remark-ref="' +
                            esc(ref) +
                            '"]'
                        );

                    if (
                        remark &&
                        typeof item.remark ===
                            'string'
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
                        typeof item.technical_justification ===
                            'string'
                    ) {
                        justification.value =
                            item.technical_justification;
                    }
                });
            }

            if (
                draft.section_remarks &&
                typeof draft.section_remarks ===
                    'object'
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
                            draft.section_remarks[section] ||
                            '';
                    }
                });
            }

            if (
                [
                    'PASS',
                    'CONDITIONAL PASS',
                    'FAIL'
                ].includes(
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
                    draft.inspector_signature ||
                    '';
            }

            if (owner) {
                owner.value =
                    draft.owner_representative ||
                    '';
            }

            if (amc) {
                amc.value =
                    draft.amc_representative ||
                    '';
            }

        } finally {
            restoring = false;
        }

        form.querySelectorAll(
            'input[name^="result["]:checked'
        ).forEach(function (input) {

            updateCheckpoint(
                input.dataset.ref
            );
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

                if (locked) {
                    return;
                }

                const sectionCard =
                    button.closest(
                        '.section-card'
                    );

                const result =
                    button.dataset.result ||
                    '';

                if (
                    !sectionCard ||
                    ![
                        'C',
                        'NC',
                        'NA',
                        'CA'
                    ].includes(result)
                ) {
                    return;
                }

                let count = 0;

                sectionCard
                    .querySelectorAll(
                        'tr[data-checkpoint]:not([data-locked="1"])'
                    )
                    .forEach(function (row) {

                        const ref =
                            row.dataset.checkpoint ||
                            '';

                        const radio =
                            row.querySelector(
                                'input[name="result[' +
                                esc(ref) +
                                ']"][value="' +
                                esc(result) +
                                '"]'
                            );

                        if (radio) {

                            radio.checked =
                                true;

                            updateCheckpoint(
                                ref
                            );

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
                    ' editable checkpoint(s)',
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
                        ? 20 * 1024 * 1024
                        : 3 * 1024 * 1024;

                if (file.size > maxSize) {

                    input.value = '';

                    input.classList.add(
                        'is-invalid'
                    );

                    alert(
                        isVideo
                            ? 'Video size cannot exceed 20 MB.'
                            : 'Photograph size cannot exceed 3 MB.'
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

            if (
                row.dataset.locked === '1'
            ) {
                return;
            }

            const ref =
                row.dataset.checkpoint || '';

            const result =
                getResult(ref);

            if (!result) {

                errors.push(
                    ref +
                    ': Result is required.'
                );

                return;
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

                const remark =
                    row.querySelector(
                        'textarea[data-remark-ref]'
                    );

                const justification =
                    row.querySelector(
                        'textarea[data-justification-ref]'
                    );

                const photo =
                    row.querySelector(
                        'input.photo-input'
                    );

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

                if (
                    !photo ||
                    !photo.files ||
                    !photo.files.length
                ) {

                    errors.push(
                        ref +
                        ': Photograph is required for CA.'
                    );

                    if (photo) {
                        photo.classList.add(
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
                    'This Escalator SCI checklist is locked after Final Submit.'
                );

                return false;
            }

            form.querySelectorAll(
                '.is-invalid'
            ).forEach(function (el) {
                el.classList.remove(
                    'is-invalid'
                );
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
                            '• ' +
                            error +
                            '\n';
                    });

                if (errors.length > 25) {

                    message +=
                        '\nAnd ' +
                        (
                            errors.length -
                            25
                        ) +
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
                '✓ Escalator SCI checklist is being saved',
                'saved'
            );

            return true;
        }
    );

    const resetButton =
        document.getElementById(
            'resetChecklist'
        );

    if (resetButton) {

        resetButton.addEventListener(
            'click',
            function (event) {

                if (!window.confirm(
                    'Reset the Escalator SCI checklist fields on this page? Unsaved data will be cleared.'
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
                            el.classList.remove(
                                'show'
                            );
                        });

                        form.querySelectorAll(
                            '.result-evidence'
                        ).forEach(function (el) {
                            el.classList.remove(
                                'show'
                            );
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