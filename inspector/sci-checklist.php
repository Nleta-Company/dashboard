<?php
/* =========================================================
   NLETA INSPECTOR — SCI CHECKLIST
   CHK-03 — SCI MASTER CHECKLIST
   Revision: Rev.1

   SOURCE OF TRUTH:
   SCI Checklist_03_Rev.1.docx

   RESULT CODES:
       C  = Complied
       NC = Non-Complied
       NA = Not Applicable
       CA = Conditional Acceptance

   MANDATORY:
       NC -> Photograph + Engineering Remark
       CA -> Photograph + Conditional Acceptance Remark / Condition
       Video -> OPTIONAL for NC / CA
       Video -> OPTIONAL

   CROSS-LOCK:
       PAT and SAT inherited items are READ ONLY.
       SCI Inspector cannot modify inherited checkpoints.

   FINAL SUBMISSION:
       This page saves only the current Asset SCI checklist.
       Complete SAF submission remains on inspections.php.
========================================================= */

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

if (session_status() !== PHP_SESSION_ACTIVE) {
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

function csrfValue(): string
{
    if (function_exists('generateCSRFToken')) {
        return (string)generateCSRFToken();
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['csrf_token'];
}

function csrfValid(string $token): bool
{
    if (function_exists('validateCSRFToken')) {
        return (bool)validateCSRFToken($token);
    }

    return !empty($_SESSION['csrf_token'])
        && hash_equals((string)$_SESSION['csrf_token'], $token);
}

function istDate($value, string $format = 'd / m / Y h:i A'): string
{
    if (!$value) {
        return '—';
    }

    try {
        $dt = new DateTime((string)$value, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('Asia/Kolkata'));
        return $dt->format($format);
    } catch (Throwable $e) {
        return (string)$value;
    }
}

function jsonArray($value): array
{
    if (!is_string($value) || trim($value) === '') {
        return [];
    }

    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

function resultClass(string $result): string
{
    return match (strtoupper(trim($result))) {
        'C'  => 'result-c',
        'NC' => 'result-nc',
        'NA' => 'result-na',
        'CA' => 'result-ca',
        default => 'result-pending'
    };
}

/* =========================================================
   AUTHENTICATED INSPECTOR / JOB
========================================================= */

$inspectorId = (int)($_SESSION['uid'] ?? $_SESSION['user_id'] ?? 0);
$jobId = (int)($_GET['job_id'] ?? $_POST['job_id'] ?? 0);
$assignmentId = (int)($_GET['assignment_id'] ?? $_POST['assignment_id'] ?? 0);

if ($inspectorId <= 0) {
    header('Location: ../login.php');
    exit;
}

if ($jobId <= 0) {
    header('Location: inspections.php');
    exit;
}

/* =========================================================
   SCI MASTER — LIFT SCI CHECKPOINT IDs CORRECTED
========================================================= */

$sections = [
    'A' => [
        'title' => 'SECTION A — STATUTORY COMPLIANCE',
        'items' => [
            ['SCI-001', 'Lift Registration valid under State Lift Act', 'P1'],
            ['SCI-002', 'Registration Certificate displayed', 'P2'],
            ['SCI-003', 'Lift Registration Number verified', 'P1'],
            ['SCI-004', 'OEM Name Plate available', 'P2'],
            ['SCI-005', 'Rated Load & Passenger Capacity displayed', 'P1'],
            ['SCI-006', 'Capacity Plate readable', 'P2'],
            ['SCI-007', 'Design Life Plate available', 'P2'],
            ['SCI-008', 'Installation Agency details available', 'P3'],
            ['SCI-009', 'AMC Agency Registration verified', 'P2'],
        ],
    ],
    'B' => [
        'title' => 'SECTION B — DOCUMENT CONTROL',
        'items' => [
            ['SCI-010', 'General Arrangement Drawing available', 'P3'],
            ['SCI-011', 'Electrical Schematic available', 'P3'],
            ['SCI-012', 'Rescue Procedure Drawing displayed', 'P3'],
            ['SCI-013', 'Wiring Diagram updated', 'P3'],
            ['SCI-014', 'Troubleshooting Manual available', 'P3'],
            ['SCI-015', 'Equipment Test Certificates archived', 'P2'],
          
        ],
    ],
    'E' => [
        'title' => 'SECTION E — FIRE & EMERGENCY COMPLIANCE',
        'items' => [
            ['SCI-016', 'Fire Extinguisher validity verified', 'P1'],
            ['SCI-017', 'Emergency Evacuation Procedure displayed', 'P2'],
            ['SCI-018', 'Rescue Contact Numbers displayed', 'P3'],
            ['SCI-019', 'Nearby Hospital information updated', 'P3'],
            ['SCI-020', 'Fire Brigade contact updated', 'P3'],
            ['SCI-021', 'Police Emergency contact updated', 'P3'],
            ['SCI-022', 'Ambulance contact displayed', 'P3'],
        ],
    ],
    'F' => [
        'title' => 'SECTION F — ACCESSIBILITY COMPLIANCE',
        'items' => [
            ['SCI-023', 'Braille Floor Identification correct', 'P2'],
            ['SCI-024', 'Audible Floor Announcement available', 'P2'],
            ['SCI-025', 'Visual Position Indicator operational', 'P2'],
            ['SCI-026', 'Wheelchair Accessibility maintained', 'P3'],
            
            ['SCI-027', 'Cabin Lighting suitable for accessibility', 'P3'],
            ['SCI-028', 'Passenger Handrail secure', 'P3'],
          
        ],
    ],
    'G' => [
        'title' => 'SECTION G — PASSENGER SAFETY COMPLIANCE',
        'items' => [
            ['SCI-029', 'Emergency Alarm clearly audible', 'P1'],
            ['SCI-030', 'Two-Way Communication available', 'P1'],
            ['SCI-031', 'CCTV Recording operational (Optional)', 'P3'],
            ['SCI-032', 'Emergency Light available', 'P1'],
           
     
            ['SCI-033', 'Emergency Instruction displayed inside cabin', 'P2'],
            ['SCI-034', 'Anti-skid Cabin Floor maintained', 'P3'],
        ],
    ],
    'H' => [
        'title' => 'SECTION H — MAINTENANCE GOVERNANCE',
        'items' => [
            ['SCI-035', 'Log Book maintained daily & Technician Attendance Record maintained', 'P2'],
            ['SCI-036', 'Preventive Maintenance Records complete', 'P2'],
            ['SCI-037', 'Breakdown History available', 'P2'],
            ['SCI-038', 'Monthly Inspection signed by AMC', 'P2'],
            ['SCI-039', 'Valid CAMC Agreement available', 'P2'],
            
            ['SCI-040', 'Spare Parts Traceability available', 'P2'],
            ['SCI-041', 'MTBF(Mean time between failures) maintained in log book', 'P3'],
            ['SCI-042', 'Previous Safety Audit Records available', 'P2'],
        ],
    ],
    'I' => [
        'title' => 'SECTION I — OPERATIONAL COMPLIANCE',
        'items' => [
            ['SCI-043', 'Lift Operator available (where applicable)', 'P3'],
            ['SCI-044', 'Rescue-Trained Personnel identified', 'P2'],
            ['SCI-045', 'Emergency Response Time documented', 'P2'],
           
        ],
    ],
    'J' => [
        'title' => 'SECTION J — INSURANCE & LEGAL COMPLIANCE',
        'items' => [
            ['SCI-046', 'Valid Insurance Certificate available', 'P1'],
           
            ['SCI-047', 'Incident Reporting Procedure available', 'P3'],
            ['SCI-048', 'Accident Register maintained', 'P3'],
            ['SCI-049', 'Legal Compliance Declaration available', 'P2'],
        ],
    ],
    'K' => [
        'title' => 'SECTION K — DESIGN LIFE & ASSET GOVERNANCE',
        'items' => [
           
            ['SCI-050', 'Critical Component Replacement History', 'P2'],
    
        ],
    ],
];

$crossLocks = [
    'C' => [
        'title' => 'SECTION C — INHERITED MACHINE ROOM COMPLIANCE (CROSS-LOCK)',
        'source' => 'PAT',
        'items' => [
            'Machine Room Lock',
            'Access Control',
            'Machine Room Lighting',
            'Ventilation',
            'Housekeeping',
            'Emergency Stop',
            'Earthing',
            'ELCB',
        ],
    ],
    'D' => [
        'title' => 'SECTION D — INHERITED ELECTRICAL COMPLIANCE (CROSS-LOCK)',
        'source' => 'PAT',
        'items' => [
            'Dual Earthing',
            'RCCB / ELCB',
            'Panel Identification',
            'Cable Identification',
            'Shock Treatment Chart',
            'LOTO Provision',
        ],
    ],
    'E_FUNCTIONAL' => [
        'title' => 'CROSS-LOCKED FUNCTIONAL ITEMS',
        'source' => 'SAT',
        'items' => [
            'Fire Mode Operation',
            'Fire Alarm Integration',
            'Smoke Detector Interface',
            'Fireman Switch Test',
        ],
    ],
    'I_OPERATIONAL' => [
        'title' => 'CROSS-LOCKED OPERATIONAL ITEMS',
        'source' => 'PAT',
        'items' => [
            'Lobby Free from Obstruction',
            'Machine Room Accessibility',
            "Do's & Don'ts Plate",
            'Emergency Contact Plate',
        ],
    ],
    'MATRIX' => [
        'title' => 'CROSS-LOCK MATRIX (READ ONLY)',
        'source' => 'PAT / SAT',
        'items' => [
            'Shaft Construction',
            'Pit Verification',
            'Landing Doors',
            'Car Top Controls',
            'Limit Switches',
            'Machine Room Safety',
            'Electrical Earthing',
            'Fire Mode Functional Test',
            'ARD Functional Test',
            'Rescue Functional Test',
            'Load Testing',
        ],
    ],
];

/* =========================================================
   LIFT ASSET TYPE GUARD
   ---------------------------------------------------------
   This file is the Lift SCI checklist. Escalator/Travellator
   SCI must continue through the separate E-SCI checklist.
========================================================= */

$preloadAssetType = '';
/* =========================================================
   LOAD ASSIGNED SCI JOB
========================================================= */

$stmt = $conn->prepare("
    SELECT
        mj.id AS job_id,
        mj.saf_id,
        mj.service_id,
        mj.asset_id,
        mj.owner_id,
        mj.status AS job_status,
        mj.checklist,
        mj.recheck_date,
        mj.recheck_status,

        ia.id AS assignment_id,
        ia.status AS assignment_status,

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
");

if (!$stmt) {
    die('Unable to prepare SCI inspection query: ' . h($conn->error));
}

$stmt->bind_param('ii', $jobId, $inspectorId);

if (!$stmt->execute()) {
    die('Unable to load SCI inspection: ' . h($stmt->error));
}

$job = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$job) {
    header('Location: inspections.php');
    exit;
}

/* =========================================================
   INSPECTOR DISPLAY NAME — UI ONLY
========================================================= */
$inspectorDisplayName = '—';

$inspectorStmt = $conn->prepare("SELECT name FROM users WHERE id = ? LIMIT 1");
if ($inspectorStmt) {
    $inspectorStmt->bind_param('i', $inspectorId);
    if ($inspectorStmt->execute()) {
        $inspectorRow = $inspectorStmt->get_result()->fetch_assoc();
        if (!empty($inspectorRow['name'])) {
            $inspectorDisplayName = (string)$inspectorRow['name'];
        }
    }
    $inspectorStmt->close();
}

$currentIST = date('d / m / Y h:i A');

$existingInspectorSignature = (string)(
    $existingChecklist['inspector_signature']
    ?? $existingChecklist['inspector_name']
    ?? $inspectorDisplayName
);

$existingOwnerRepresentative = (string)(
    $existingChecklist['owner_representative']
    ?? ''
);

$existingAmcRepresentative = (string)(
    $existingChecklist['amc_representative']
    ?? ''
);

$totalExecutionCheckpoints = 0;
foreach ($sections as $sectionData) {
    $totalExecutionCheckpoints += count($sectionData['items']);
}

/* =========================================================
   LIFT SCI ROUTING GUARD
   ---------------------------------------------------------
   Do not silently route Escalator/Travellator into Lift SCI.
========================================================= */
$loadedAssetType = strtolower(trim((string)($job['asset_type'] ?? '')));
$isLiftAsset =
    str_contains($loadedAssetType, 'lift') ||
    str_contains($loadedAssetType, 'elevator');

if (!$isLiftAsset) {
    die(
        '<div style="margin:30px;padding:20px;border:1px solid #dc3545;'
        . 'border-radius:12px;background:#fff5f5;color:#842029;font-family:Arial">'
        . '<h3>Lift SCI Checklist Routing Error</h3>'
        . '<p>This page is only for Lift / Elevator SCI inspections.</p>'
        . '<p>Detected Asset Type: <strong>' . h($job['asset_type'] ?? 'Unknown') . '</strong></p>'
        . '<p>Please open the appropriate Escalator/Travellator SCI checklist.</p>'
        . '<p><a href="inspections.php">← Back to Inspections</a></p>'
        . '</div>'
    );
}

$actualAssignmentId = (int)$job['assignment_id'];
$actualAssetId = (int)$job['asset_id'];
$actualSafId = (int)$job['saf_id'];

$serviceCode = strtoupper(trim((string)($job['service_code'] ?? '')));

if ($serviceCode !== 'SCI') {
    die(
        '<div style="margin:30px;padding:20px;border:1px solid #dc3545;'
        . 'border-radius:12px;background:#fff5f5;color:#842029;font-family:Arial">'
        . '<h3>SCI Checklist Error</h3>'
        . '<p>This job is not an SCI inspection. Service code: <strong>'
        . h($serviceCode ?: 'EMPTY')
        . '</strong></p>'
        . '<p><a href="inspections.php">← Back to Inspections</a></p>'
        . '</div>'
    );
}

if (
    isset($job['saf_status'])
    && trim((string)$job['saf_status']) !== ''
    && strcasecmp(trim((string)$job['saf_status']), 'Accepted') !== 0
) {
    die('This SAF has not been accepted by the Owner.');
}

/* =========================================================
   ATTENDANCE OTP — DIRECT URL SAFETY CHECK
========================================================= */

$attendanceVerified = false;

$attendanceStmt = $conn->prepare("
    SELECT verified_at
    FROM inspector_attendance
    WHERE
        assignment_id = ?
        AND job_id = ?
        AND inspector_id = ?
        AND owner_id = ?
    LIMIT 1
");

if ($attendanceStmt) {
    $ownerIdForAttendance = (int)$job['owner_id'];
    $attendanceStmt->bind_param(
        'iiii',
        $actualAssignmentId,
        $jobId,
        $inspectorId,
        $ownerIdForAttendance
    );
    $attendanceStmt->execute();
    $attendanceRow = $attendanceStmt->get_result()->fetch_assoc();
    $attendanceStmt->close();

    $attendanceVerified = !empty($attendanceRow['verified_at']);
}

if (!$attendanceVerified) {
    die(
        '<div style="margin:30px;padding:20px;border:1px solid #dc3545;'
        . 'border-radius:12px;background:#fff5f5;color:#842029;font-family:Arial">'
        . '<h3>Site Attendance Verification Required</h3>'
        . '<p>Owner attendance OTP verification must be completed before the SCI inspection can start.</p>'
        . '<p><a href="inspections.php?asset_id=' . $actualAssetId . '&saf_id=' . $actualSafId . '">← Back to Inspection</a></p>'
        . '</div>'
    );
}

/* =========================================================
   EXISTING CHECKLIST
========================================================= */

$existingChecklist = jsonArray($job['checklist'] ?? '');

$existingResults = is_array($existingChecklist['results'] ?? null)
    ? $existingChecklist['results']
    : [];

$existingRemarks = is_array($existingChecklist['section_remarks'] ?? null)
    ? $existingChecklist['section_remarks']
    : [];

$existingFinalStatus = strtoupper(trim((string)($existingChecklist['final_status'] ?? '')));

if (!in_array($existingFinalStatus, ['PASS','CONDITIONAL PASS','FAIL'], true)) {
    $existingFinalStatus = '';
}

/* =========================================================
   EVIDENCE TABLE
========================================================= */

$conn->query("
    CREATE TABLE IF NOT EXISTS saf_checklist_evidence (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        job_id INT NOT NULL,
        ref_code VARCHAR(100) NOT NULL,
        result VARCHAR(30) NULL,
        photo_path TEXT NULL,
        video_path TEXT NULL,
        document_path TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_saf_checklist_evidence_job_ref (job_id, ref_code),
        KEY idx_saf_checklist_evidence_job (job_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

/* =========================================================
   LOAD EXISTING EVIDENCE
========================================================= */

$evidenceByRef = [];

$evidenceStmt = $conn->prepare("
    SELECT ref_code, result, photo_path, video_path, document_path
    FROM saf_checklist_evidence
    WHERE job_id = ?
");

if ($evidenceStmt) {
    $evidenceStmt->bind_param('i', $jobId);
    $evidenceStmt->execute();
    $evidenceResult = $evidenceStmt->get_result();

    while ($eRow = $evidenceResult->fetch_assoc()) {
        $evidenceByRef[(string)$eRow['ref_code']] = $eRow;
    }

    $evidenceStmt->close();
}

$error = '';
$success = '';

/*
 * Always initialize this variable before the POST/save flow.
 * PHP/IntelliSense can otherwise report it as undefined because
 * it is created only inside a conditional save branch.
 */
$checklistData = null;

/* =========================================================
   SAVE SCI CHECKLIST
========================================================= */

$isSaveDraft = $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_draft']);
$isSaveFinal = $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_sci']);

if ($isSaveDraft || $isSaveFinal) {

    if (!csrfValid((string)($_POST['csrf_token'] ?? ''))) {
        $error = 'Invalid security token. Please refresh and try again.';
    } elseif (
        (int)($_POST['job_id'] ?? 0) !== $jobId
        || (int)($_POST['assignment_id'] ?? 0) !== $actualAssignmentId
    ) {
        $error = 'Invalid SCI inspection selected.';
    } else {

        $postedResults = is_array($_POST['result'] ?? null)
            ? $_POST['result']
            : [];

        $postedSectionRemarks = is_array($_POST['section_remarks'] ?? null)
            ? $_POST['section_remarks']
            : [];

        $finalStatus = strtoupper(trim((string)($_POST['final_status'] ?? '')));

        if ($isSaveFinal && !in_array($finalStatus, ['PASS','CONDITIONAL PASS','FAIL'], true)) {
            $error = 'Please select a valid Final SCI Status.';
        }

        $masterRefs = [];
        $masterCheckpointMeta = [];

        /*
         * SOURCE OF TRUTH FOR SAVED CHECKPOINT METADATA
         * ---------------------------------------------------------
         * Each SCI master item is: [checkpoint_id, checkpoint_name, priority]
         * and the surrounding array key is the Section.
         * Persist this exact snapshot so Admin and Owner always
         * display the same Name + Priority + Section submitted by
         * the Inspector.
         */
        foreach ($sections as $sectionKey => $section) {
            foreach ($section['items'] as $item) {
                $ref = trim((string)($item[0] ?? ''));

                if ($ref === '') {
                    continue;
                }

                $masterRefs[] = $ref;

                $masterCheckpointMeta[$ref] = [
                    'checkpoint_id' => $ref,
                    'checkpoint_name' => trim((string)($item[1] ?? '')),
                    'priority' => trim((string)($item[2] ?? '')),
                    'section' => trim((string)$sectionKey)
                ];
            }
        }

        if ($error === '') {
            $missing = [];

            foreach ($masterRefs as $ref) {
                $r = strtoupper(trim((string)($postedResults[$ref] ?? '')));

                if (!in_array($r, ['C','NC','NA','CA'], true)) {
                    if ($isSaveFinal) {
                        $missing[] = $ref;
                    }
                    continue;
                }

                if ($r === 'NC') {
                    $remark = trim((string)($_POST['remark'][$ref] ?? ''));

                    $hasPhoto =
                        !empty($_FILES['photo']['name'][$ref])
                        || !empty($existingResults[$ref]['evidence']['photo'])
                        || !empty($existingResults[$ref]['photo'])
                        || !empty($evidenceByRef[$ref]['photo_path']);

                    if ($isSaveFinal && $remark === '') {
                        $error = $ref . ': Engineering Remark is mandatory for NC.';
                        break;
                    }

                    if ($isSaveFinal && !$hasPhoto) {
                        $error = $ref . ': Photograph is mandatory for NC.';
                        break;
                    }
                }

                if ($r === 'CA') {
                    $caRemark = trim((string)($_POST['ca_remark'][$ref] ?? ''));

                    if ($isSaveFinal && $caRemark === '') {
                        $error = $ref . ': Conditional Acceptance Remark / Condition is mandatory for CA.';
                        break;
                    }

                    $hasPhoto =
                        !empty($_FILES['photo']['name'][$ref])
                        || !empty($existingResults[$ref]['evidence']['photo'])
                        || !empty($existingResults[$ref]['photo'])
                        || !empty($evidenceByRef[$ref]['photo_path']);

                    if ($isSaveFinal && !$hasPhoto) {
                        $error = $ref . ': Photograph is mandatory for CA.';
                        break;
                    }
                }
            }

            if ($error === '' && $missing) {
                $error = 'Please select a result for every Lift SCI checkpoint. Missing: '
                    . implode(', ', array_slice($missing, 0, 20));
            }
        }

        if ($error === '') {
            $cleanResults = [];
            $uploadedFiles = [];
            $uploadErrors = [];

            $uploadDir = __DIR__ . '/../uploads/inspections/' . $jobId;

            if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0775, true)) {
                $error = 'Unable to create SCI evidence folder.';
            }

            if ($error === '') {
                foreach ($masterRefs as $ref) {
                    $result = strtoupper(trim((string)$postedResults[$ref]));

                    $old = is_array($existingResults[$ref] ?? null)
                        ? $existingResults[$ref]
                        : [];

                    $oldEvidence = is_array($old['evidence'] ?? null)
                        ? $old['evidence']
                        : [];

                    $photo = (string)(
                        $oldEvidence['photo']
                        ?? $old['photo']
                        ?? $evidenceByRef[$ref]['photo_path']
                        ?? ''
                    );

                    if (
                        isset($_FILES['photo']['error'][$ref])
                        && (int)$_FILES['photo']['error'][$ref] !== UPLOAD_ERR_NO_FILE
                    ) {
                        $uploadError = (int)$_FILES['photo']['error'][$ref];

                        if ($uploadError !== UPLOAD_ERR_OK) {
                            $uploadErrors[] = $ref . ': Photo upload error.';
                        } else {
                            $tmp = (string)$_FILES['photo']['tmp_name'][$ref];
                            $name = (string)$_FILES['photo']['name'][$ref];
                            $size = (int)$_FILES['photo']['size'][$ref];

                            if ($size > 5 * 1024 * 1024) {
                                $uploadErrors[] = $ref . ': Photograph must be 5 MB or smaller.';
                            } elseif (!is_uploaded_file($tmp)) {
                                $uploadErrors[] = $ref . ': Invalid uploaded photograph.';
                            } else {
                                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                                $allowed = ['jpg','jpeg','png','webp'];

                                if (!in_array($ext, $allowed, true)) {
                                    $uploadErrors[] = $ref . ': Photograph must be JPG, JPEG, PNG or WEBP.';
                                } else {
                                    $safeRef = preg_replace('/[^A-Za-z0-9_-]/', '_', $ref);
                                    $filename = $safeRef . '_photo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                                    $destination = $uploadDir . '/' . $filename;

                                    if (!move_uploaded_file($tmp, $destination)) {
                                        $uploadErrors[] = $ref . ': Unable to save photograph.';
                                    } else {
                                        $photo = 'uploads/inspections/' . $jobId . '/' . $filename;
                                        $uploadedFiles[] = $destination;
                                    }
                                }
                            }
                        }
                    }

                    $video = (string)(
                        $oldEvidence['video']
                        ?? $old['video']
                        ?? $evidenceByRef[$ref]['video_path']
                        ?? ''
                    );

                    if (
                        isset($_FILES['video']['error'][$ref])
                        && (int)$_FILES['video']['error'][$ref] !== UPLOAD_ERR_NO_FILE
                    ) {
                        $videoUploadError = (int)$_FILES['video']['error'][$ref];

                        if ($videoUploadError !== UPLOAD_ERR_OK) {
                            $uploadErrors[] = $ref . ': Video upload error.';
                        } else {
                            $videoTmp = (string)$_FILES['video']['tmp_name'][$ref];
                            $videoName = (string)$_FILES['video']['name'][$ref];
                            $videoSize = (int)$_FILES['video']['size'][$ref];

                            if ($videoSize > 20 * 1024 * 1024) {
                                $uploadErrors[] = $ref . ': Video must be 20 MB or smaller.';
                            } elseif (!is_uploaded_file($videoTmp)) {
                                $uploadErrors[] = $ref . ': Invalid uploaded video.';
                            } else {
                                $videoExt = strtolower(pathinfo($videoName, PATHINFO_EXTENSION));
                                $allowedVideo = ['mp4','webm','mov','avi'];

                                if (!in_array($videoExt, $allowedVideo, true)) {
                                    $uploadErrors[] = $ref . ': Video must be MP4, WEBM, MOV or AVI.';
                                } else {
                                    $safeRef = preg_replace('/[^A-Za-z0-9_-]/', '_', $ref);
                                    $videoFilename = $safeRef . '_video_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $videoExt;
                                    $videoDestination = $uploadDir . '/' . $videoFilename;

                                    if (!move_uploaded_file($videoTmp, $videoDestination)) {
                                        $uploadErrors[] = $ref . ': Unable to save video.';
                                    } else {
                                        $video = 'uploads/inspections/' . $jobId . '/' . $videoFilename;
                                        $uploadedFiles[] = $videoDestination;
                                    }
                                }
                            }
                        }
                    }

                    $meta = $masterCheckpointMeta[$ref] ?? [
                        'checkpoint_id' => $ref,
                        'checkpoint_name' => '',
                        'priority' => '',
                        'section' => ''
                    ];

                    $cleanResults[$ref] = [
                        /* Permanent master identity from the Inspector master */
                        'checkpoint_id' => $meta['checkpoint_id'],
                        'checkpoint_name' => $meta['checkpoint_name'],
                        'priority' => $meta['priority'],
                        'section' => $meta['section'],

                        /* Actual inspection submission */
                        'result' => $result,
                        'remark' => trim((string)($_POST['remark'][$ref] ?? '')),
                        'ca_remark' => trim((string)($_POST['ca_remark'][$ref] ?? '')),

                        /* Evidence */
                        'evidence' => [
                            'photo' => $photo,
                            'video' => $video
                        ],
                        'photo' => $photo,
                        'video' => $video,
                        'updated_at_ist' => date('Y-m-d H:i:s')
                    ];
                }

                if ($uploadErrors) {
                    foreach ($uploadedFiles as $file) {
                        if (is_file($file)) {
                            @unlink($file);
                        }
                    }

                    $error = implode(' ', array_slice($uploadErrors, 0, 20));
                }
            }

            if ($error === '') {

                $cleanSectionRemarks = [];
                foreach (array_keys($sections) as $sectionKey) {
                    $cleanSectionRemarks[$sectionKey] = trim(
                        (string)($postedSectionRemarks[$sectionKey] ?? '')
                    );
                }

                $checklistData = [
                    'module' => 'SCI',
                    'checklist_code' => 'CHK-03',
                    'checklist_revision' => 'Rev.1',
                    'document_no' => 'CHK-03',
                    'service_code' => 'SCI',
                    'service_name' => (string)($job['service_name'] ?? 'Safety Compliance Inspection'),
                    'job_id' => $jobId,
                    'assignment_id' => $actualAssignmentId,
                    'saf_id' => $actualSafId,
                    'asset_id' => $actualAssetId,
                    'timezone' => 'Asia/Kolkata',
                    'saved_at_ist' => date('Y-m-d H:i:s'),
                    'saved_by_role' => 'inspector',
                    'saved_by' => $inspectorId,
                    'inspector_signature' => trim((string)($_POST['inspector_signature'] ?? '')),
                    'owner_representative' => trim((string)($_POST['owner_representative'] ?? '')),
                    'amc_representative' => trim((string)($_POST['amc_representative'] ?? '')),

                    /* Checklist-level source-of-truth snapshot */
                    'checkpoint_metadata' => $masterCheckpointMeta,

                    'results' => $cleanResults,
                    'section_remarks' => $cleanSectionRemarks,
                    'final_status' => $finalStatus,
                    'save_mode' => $isSaveDraft ? 'DRAFT' : 'SAVE',
                    'cross_lock' => [
                        'mode' => 'READ_ONLY',
                        'sources' => ['PAT','SAT']
                    ]
                ];

                $json = json_encode(
                    $checklistData,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
                );

                if ($json === false) {
                    $error = 'Unable to encode SCI checklist data.';
                } else {
                    $conn->begin_transaction();

                    try {
                        $update = $conn->prepare("
                            UPDATE maintenance_jobs
                            SET
                                checklist = ?,
                                status = 'in_progress',
                                updated_at = NOW()
                            WHERE
                                id = ?
                                AND owner_id = ?
                                AND job_type = 'saf_inspection'
                            LIMIT 1
                        ");

                        if (!$update) {
                            throw new Exception('Unable to prepare SCI checklist save: ' . $conn->error);
                        }

                        $ownerId = (int)$job['owner_id'];
                        $update->bind_param('sii', $json, $jobId, $ownerId);

                        if (!$update->execute()) {
                            throw new Exception('Unable to save SCI checklist: ' . $update->error);
                        }

                        $update->close();

                        $assignmentUpdate = $conn->prepare("
                            UPDATE inspector_assignments
                            SET
                                status = 'in_progress',
                                started_at = COALESCE(started_at, NOW())
                            WHERE
                                id = ?
                                AND job_id = ?
                                AND inspector_id = ?
                                AND status <> 'submitted'
                            LIMIT 1
                        ");

                        if (!$assignmentUpdate) {
                            throw new Exception('Unable to update SCI Inspector assignment: ' . $conn->error);
                        }

                        $assignmentUpdate->bind_param(
                            'iii',
                            $actualAssignmentId,
                            $jobId,
                            $inspectorId
                        );

                        if (!$assignmentUpdate->execute()) {
                            throw new Exception('Unable to update SCI Inspector assignment: ' . $assignmentUpdate->error);
                        }

                        $assignmentUpdate->close();

                        $upsertEvidence = $conn->prepare("
                            INSERT INTO saf_checklist_evidence
                            (
                                job_id,
                                ref_code,
                                result,
                                photo_path,
                                video_path,
                                document_path,
                                created_at,
                                updated_at
                            )
                            VALUES
                            (?, ?, ?, ?, ?, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                            ON DUPLICATE KEY UPDATE
                                result = VALUES(result),
                                photo_path = CASE
                                    WHEN VALUES(photo_path) <> '' THEN VALUES(photo_path)
                                    ELSE photo_path
                                END,
                                video_path = CASE
                                    WHEN VALUES(video_path) <> '' THEN VALUES(video_path)
                                    ELSE video_path
                                END,
                                updated_at = CURRENT_TIMESTAMP
                        ");

                        if (!$upsertEvidence) {
                            throw new Exception('Unable to prepare SCI evidence save: ' . $conn->error);
                        }

                        foreach ($cleanResults as $ref => $item) {
                            $photoPath = (string)($item['evidence']['photo'] ?? '');
                            $videoPath = (string)($item['evidence']['video'] ?? '');

                            if ($photoPath === '' && $videoPath === '') {
                                continue;
                            }

                            $evidenceResult = (string)$item['result'];

                            $upsertEvidence->bind_param(
                                'issss',
                                $jobId,
                                $ref,
                                $evidenceResult,
                                $photoPath,
                                $videoPath
                            );

                            if (!$upsertEvidence->execute()) {
                                throw new Exception(
                                    $ref . ': Unable to save SCI evidence: '
                                    . $upsertEvidence->error
                                );
                            }
                        }

                        $upsertEvidence->close();

                        $conn->commit();

                        $success = $isSaveDraft
                            ? 'SCI checklist draft saved successfully. You can continue later.'
                            : 'Lift SCI checklist saved successfully for this Asset.';
                    } catch (Throwable $e) {
                        $conn->rollback();

                        foreach ($uploadedFiles as $file) {
                            if (is_file($file)) {
                                @unlink($file);
                            }
                        }

                        $error = $e->getMessage();
                    }
                }
            }
        }
    }

    if ($success !== '' && is_array($checklistData)) {
        $existingChecklist = $checklistData;
        $existingResults = is_array($checklistData['results'] ?? null)
            ? $checklistData['results']
            : [];
        $existingRemarks = is_array($checklistData['section_remarks'] ?? null)
            ? $checklistData['section_remarks']
            : [];
        $existingFinalStatus = strtoupper(
            trim((string)($checklistData['final_status'] ?? ''))
        );
    }
}

/* =========================================================
   REFRESH EXISTING DATA AFTER SAVE
========================================================= */

if ($success === '') {
    $reloadStmt = $conn->prepare("
        SELECT checklist, status, updated_at
        FROM maintenance_jobs
        WHERE id = ?
        LIMIT 1
    ");

    if ($reloadStmt) {
        $reloadStmt->bind_param('i', $jobId);
        $reloadStmt->execute();
        $reloadRow = $reloadStmt->get_result()->fetch_assoc();
        $reloadStmt->close();

        if ($reloadRow) {
            $existingChecklist = jsonArray($reloadRow['checklist'] ?? '');
            $existingResults = is_array($existingChecklist['results'] ?? null)
                ? $existingChecklist['results']
                : [];
            $existingRemarks = is_array($existingChecklist['section_remarks'] ?? null)
                ? $existingChecklist['section_remarks']
                : [];
            $existingFinalStatus = strtoupper(trim((string)($existingChecklist['final_status'] ?? '')));

            if (!in_array($existingFinalStatus, ['PASS','CONDITIONAL PASS','FAIL'], true)) {
                $existingFinalStatus = '';
            }
        }
    }
}

$total = 0;
$countC = 0;
$countNC = 0;
$countNA = 0;
$countCA = 0;
$completed = 0;

foreach ($sections as $section) {
    foreach ($section['items'] as $item) {
        $total++;
        $r = strtoupper(trim((string)($existingResults[$item[0]]['result'] ?? '')));

        if ($r === 'C') {
            $countC++;
            $completed++;
        } elseif ($r === 'NC') {
            $countNC++;
            $completed++;
        } elseif ($r === 'NA') {
            $countNA++;
            $completed++;
        } elseif ($r === 'CA') {
            $countCA++;
            $completed++;
        }
    }
}

$csrfToken = csrfValue();

include('../includes/header.php');
include('../includes/sidebar-inspector.php');
?>

<style>
/* =========================================================
   NLETA SCI CHECKLIST — SAT STYLE PROFESSIONAL INTERFACE
   ---------------------------------------------------------
   UI / CSS ONLY.
   Existing SCI PHP logic, field names, result rules and
   save behaviour are preserved.
========================================================= */

:root{
    --sci-primary:#175cd3;
    --sci-primary-dark:#1247a6;
    --sci-primary-soft:#eef5ff;
    --sci-success:#087443;
    --sci-success-soft:#ecfdf3;
    --sci-danger:#b42318;
    --sci-danger-soft:#fff1f0;
    --sci-warning:#b54708;
    --sci-warning-soft:#fffaeb;
    --sci-text:#172b4d;
    --sci-muted:#667085;
    --sci-border:#dfe5ec;
    --sci-border-dark:#cbd5e1;
    --sci-bg:#f5f7fa;
    --sci-white:#fff;
    --sci-shadow:0 8px 24px rgba(15,23,42,.06);
    --sci-shadow-lg:0 14px 38px rgba(15,23,42,.08);
    --sci-radius:14px;
}

/* PAGE */
.sci-page{
    padding:26px 24px 40px;
    background:
        radial-gradient(circle at top left,rgba(23,92,211,.045),transparent 28%),
        var(--sci-bg);
    min-height:calc(100vh - 70px);
    color:var(--sci-text);
}
.sci-page > *{
    max-width:1450px;
    margin-left:auto;
    margin-right:auto;
}

/* MAIN CARDS */
.sci-card{
    background:var(--sci-white);
    border:1px solid var(--sci-border);
    border-radius:var(--sci-radius);
    box-shadow:var(--sci-shadow);
    margin-bottom:20px;
    overflow:hidden;
}

/* SCI HEADER — SAME VISUAL LANGUAGE AS SAT */
.sci-card:first-child{
    position:relative;
    border-radius:18px;
}
.sci-card:first-child:before{
    content:"";
    position:absolute;
    inset:0 auto 0 0;
    width:5px;
    background:linear-gradient(180deg,var(--sci-primary),#5b8def);
    z-index:2;
}

.sci-head{
    position:relative;
    padding:25px 25px 0;
    background:var(--sci-white);
}
.sci-head h3{
    margin:0;
    color:#102a43;
    font-size:24px;
    line-height:1.25;
    font-weight:800;
    letter-spacing:-.35px;
}
.sci-head h3 i{
    color:var(--sci-primary);
}
.sci-sub{
    margin:8px 0 0;
    color:var(--sci-muted);
    font-size:13px;
    line-height:1.6;
}

/* OWNER & ASSET DETAILS — UI ONLY */
.owner-asset-heading{
    margin:0 25px;
    padding:10px 0 0;
    color:#102a43;
    font-size:12px;
    font-weight:900;
    text-transform:uppercase;
    letter-spacing:.5px;
}
.owner-asset-heading i{
    color:var(--sci-primary);
}

/* ASSET / JOB GRID */
.asset-grid{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:12px;
    padding:22px 25px 25px;
}
.asset-box{
    min-height:68px;
    background:#f8fafc;
    border:1px solid #e5eaf0;
    border-radius:11px;
    padding:12px 14px;
    transition:border-color .2s ease,box-shadow .2s ease,transform .2s ease;
}
.asset-box:hover{
    border-color:#cbd8ea;
    box-shadow:0 4px 12px rgba(15,23,42,.04);
    transform:translateY(-1px);
}
.asset-label{
    font-size:9px;
    line-height:1.2;
    text-transform:uppercase;
    letter-spacing:.65px;
    color:#667085;
    font-weight:800;
}
.asset-value{
    margin-top:6px;
    color:#172b4d;
    font-size:13px;
    line-height:1.4;
    font-weight:700;
    word-break:break-word;
}

/* BOOTSTRAP BADGE NORMALIZATION */
.asset-value .badge{
    display:inline-flex;
    align-items:center;
    margin-left:5px;
    padding:4px 8px;
    border-radius:999px;
    background:var(--sci-primary-soft)!important;
    border:1px solid #cfe0ff;
    color:var(--sci-primary)!important;
    font-size:9px;
    font-weight:800;
}

/* ALERTS */
.alert-sci{
    margin:0 25px 18px!important;
    border-radius:11px;
    border-width:1px;
    padding:13px 15px;
    font-size:12px;
    line-height:1.55;
    box-shadow:0 3px 10px rgba(15,23,42,.03);
}
.alert-sci.alert-info{
    background:#eff8ff;
    border-color:#b2ddff;
    color:#175cd3;
}
.alert-sci.alert-danger{
    background:var(--sci-danger-soft);
    border-color:#fecdca;
    color:var(--sci-danger);
}
.alert-sci.alert-success{
    background:var(--sci-success-soft);
    border-color:#abefc6;
    color:#067647;
}

/* SUMMARY — SAME CARD STYLE AS SAT */
.summary-grid{
    display:grid;
    grid-template-columns:repeat(5,minmax(0,1fr));
    gap:12px;
    padding:18px 20px;
}
.summary-box{
    min-height:78px;
    padding:13px 15px;
    background:#f8fafc;
    border:1px solid #e5eaf0;
    border-radius:11px;
}
.summary-label{
    color:#667085;
    font-size:9px;
    line-height:1.2;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:.6px;
}
.summary-value{
    margin-top:8px;
    color:#172b4d;
    font-size:22px;
    line-height:1;
    font-weight:900;
}
.summary-value.text-success{color:#087443!important}
.summary-value.text-danger{color:#b42318!important}

/* CROSS LOCK */
.cross-card{
    background:#f7fffa;
    border:1px solid #b7dfc8;
    border-radius:14px;
    box-shadow:var(--sci-shadow);
    margin-bottom:20px;
    overflow:hidden;
}
.cross-title{
    padding:14px 17px;
    border-bottom:1px solid #cfe9d9;
    background:linear-gradient(180deg,#f3fff7,#edfaf2);
    color:#0f5132;
    font-size:13px;
    font-weight:800;
}
.cross-source{
    padding:9px 17px;
    border-bottom:1px solid #d9eadf;
    color:#667085;
    background:#fbfffc;
    font-size:10px;
    font-weight:700;
}
.cross-list{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:8px;
    padding:14px 17px 17px;
}
.cross-item{
    padding:10px 11px;
    border:1px solid #d9eadf;
    border-radius:8px;
    background:#fff;
    color:#344054;
    font-size:11px;
    line-height:1.4;
}
.cross-item .badge{
    float:right;
    background:#dff5e8!important;
    border:1px solid #b7dfc8;
    color:#0f5132!important;
    font-size:8px;
    font-weight:800;
}
.cross-item i{
    color:#087443!important;
}

/* SECTION CARD */
.section-head{
    min-height:66px;
    padding:15px 19px;
    border-bottom:1px solid #e8edf2;
    background:linear-gradient(180deg,#fbfcfe 0%,#f7f9fc 100%);
    display:flex;
    justify-content:space-between;
    gap:14px;
    align-items:center;
    flex-wrap:wrap;
    position:relative;
}
.section-title{
    color:#102a43;
    font-size:16px;
    line-height:1.35;
    font-weight:800;
    letter-spacing:-.15px;
}
.section-count{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:90px;
    padding:5px 10px;
    border-radius:999px;
    background:var(--sci-primary-soft);
    border:1px solid #cfe0ff;
    color:var(--sci-primary);
    font-size:9px;
    font-weight:800;
    letter-spacing:.25px;
    text-transform:uppercase;
}

/* TABLE WRAPPER */
.table-wrap{
    overflow:auto;
    border:0;
    background:#fff;
}
.sci-table{
    width:100%;
    min-width:1050px;
    border-collapse:separate;
    border-spacing:0;
}
.sci-table th,
.sci-table td{
    border:0;
    border-bottom:1px solid #e5e9ef;
    border-right:1px solid #e5e9ef;
    padding:11px 12px;
    vertical-align:top;
}
.sci-table th:last-child,
.sci-table td:last-child{
    border-right:0;
}
.sci-table tbody tr:last-child td{
    border-bottom:0;
}
.sci-table th{
    position:sticky;
    top:0;
    z-index:2;
    background:#f8fafc;
    color:#344054;
    font-size:9px;
    line-height:1.3;
    text-transform:uppercase;
    letter-spacing:.55px;
    font-weight:800;
    white-space:nowrap;
}
.sci-table td{
    color:#344054;
    font-size:12px;
    line-height:1.5;
}
.sci-table tbody tr{
    transition:background .15s ease;
}
.sci-table tbody tr:hover{
    background:#fbfdff;
}
.sci-table td:nth-child(2){
    color:#172b4d;
    font-weight:600;
}
.sci-table td:first-child{
    width:105px;
}
.sci-table td:nth-child(3){
    width:90px;
}

/* CHECKPOINT ID */
.ref{
    color:var(--sci-primary);
    font-size:12px;
    font-weight:900;
    white-space:nowrap;
}
.priority{
    text-align:center;
    color:#344054;
    font-weight:800;
    white-space:nowrap;
}

/* RESULT PILLS — SAT STYLE */
.result-options{
    display:flex;
    gap:6px;
    flex-wrap:wrap;
    align-items:center;
}
.result-options label{
    position:relative;
    margin:0;
    cursor:pointer;
}
.result-options input{
    position:absolute;
    width:1px;
    height:1px;
    opacity:0;
    pointer-events:none;
}
.result-options span{
    min-width:39px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:34px;
    padding:6px 10px;
    border:1px solid #cfd6df;
    border-radius:8px;
    background:#fff;
    color:#344054;
    font-size:10px;
    font-weight:800;
    letter-spacing:.2px;
    user-select:none;
    transition:all .16s ease;
}
.result-options label:hover span{
    border-color:#98a2b3;
    background:#f8fafc;
    transform:translateY(-1px);
}
.result-options input:focus-visible + span{
    outline:3px solid rgba(23,92,211,.16);
    outline-offset:2px;
}
.result-options input:checked + span{
    background:var(--sci-primary);
    color:#fff;
    border-color:var(--sci-primary);
    box-shadow:0 3px 8px rgba(23,92,211,.18);
}

/* CONDITION / EVIDENCE */
.extra-fields{
    display:none;
    margin-top:0;
    padding:11px;
    border:1px solid #e3e8ef;
    border-radius:9px;
    background:#f8fafc;
}
.extra-fields.show{
    display:block;
    animation:sciReveal .18s ease-out;
}
@keyframes sciReveal{
    from{opacity:0;transform:translateY(-3px)}
    to{opacity:1;transform:translateY(0)}
}

.form-label{
    color:#475467;
    font-size:9px;
    line-height:1.3;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:.35px;
}
.form-control,
.form-select{
    border-color:#d0d5dd;
    border-radius:7px;
    color:#172b4d;
    background:#fff;
    font-size:11px;
    box-shadow:none;
    transition:border-color .15s ease,box-shadow .15s ease;
}
.form-control:focus,
.form-select:focus{
    border-color:#84adf4;
    box-shadow:0 0 0 3px rgba(23,92,211,.10);
}
textarea.form-control{
    min-height:64px;
    resize:vertical;
    line-height:1.45;
}
input[type="file"].form-control{
    padding:5px;
}
input[type="file"].form-control::file-selector-button{
    margin:-5px 9px -5px -5px;
    padding:7px 10px;
    border:0;
    border-right:1px solid #d0d5dd;
    background:#f2f4f7;
    color:#344054;
    font-size:10px;
    font-weight:700;
    cursor:pointer;
}
.text-success{
    color:#067647!important;
}
.extra-fields .text-success{
    display:block;
    margin-top:6px!important;
    padding:6px 8px;
    border-radius:6px;
    background:#ecfdf3;
    font-size:9px!important;
    font-weight:700;
}

/* NC / CA VISUAL STATES */
.sci-table tbody tr:has(input[value="NC"]:checked){
    background:#fff8f7;
}
.sci-table tbody tr:has(input[value="CA"]:checked){
    background:#fffdf7;
}
.sci-table tbody tr:has(input[value="NC"]:checked) td:first-child{
    box-shadow:inset 3px 0 0 #d92d20;
}
.sci-table tbody tr:has(input[value="CA"]:checked) td:first-child{
    box-shadow:inset 3px 0 0 #f79009;
}

/* SECTION REMARK */
.border-top{
    border-top:1px solid #edf0f4!important;
}
.sci-card .border-top{
    padding:15px 18px!important;
    background:#fbfcfe;
}

/* FINAL SUBMIT CARD */
.submit-box{
    padding:20px;
    background:linear-gradient(180deg,#fbfcfe 0%,#f7f9fc 100%);
}
.submit-box .row{
    align-items:end;
}
.submit-box .form-label{
    font-size:10px;
}
.submit-box .form-select{
    min-height:40px;
}
.submit-box .small{
    font-size:10px;
    line-height:1.55;
}
.submit-box .btn{
    min-height:38px;
    border-radius:8px;
    padding:8px 14px;
    font-size:11px;
    font-weight:700;
    transition:all .16s ease;
}
.submit-box .btn:hover{
    transform:translateY(-1px);
}
.submit-box .btn-success{
    background:var(--sci-success);
    border-color:var(--sci-success);
    box-shadow:0 4px 10px rgba(8,116,67,.18);
}
.submit-box .btn-success:hover{
    background:#05613a;
    border-color:#05613a;
}
.submit-box .btn-outline-secondary{
    color:#475467;
    border-color:#cfd6df;
    background:#fff;
}

/* BADGES */
.badge.bg-primary{
    background:var(--sci-primary)!important;
}
.badge.bg-secondary{
    background:#f2f4f7!important;
    color:#475467!important;
}

/* SCROLLBAR */
.table-wrap::-webkit-scrollbar{
    height:9px;
    width:9px;
}
.table-wrap::-webkit-scrollbar-track{
    background:#f1f3f6;
    border-radius:10px;
}
.table-wrap::-webkit-scrollbar-thumb{
    background:#c5ccd5;
    border-radius:10px;
}
.table-wrap::-webkit-scrollbar-thumb:hover{
    background:#aeb7c2;
}

/* RESPONSIVE */
@media(max-width:1200px){
    .asset-grid{
        grid-template-columns:repeat(2,minmax(0,1fr));
    }
    .summary-grid{
        grid-template-columns:repeat(3,minmax(0,1fr));
    }
}
@media(max-width:900px){
    .sci-page{
        padding:18px 14px 30px;
    }
    .sci-head{
        padding:20px 19px 0;
    }
    .sci-head h3{
        font-size:20px;
    }
    .asset-grid{
        padding:18px 19px 20px;
    }
    .summary-grid{
        grid-template-columns:repeat(2,minmax(0,1fr));
    }
    .cross-list{
        grid-template-columns:1fr;
    }
    .section-head{
        padding:14px 15px;
    }
}
@media(max-width:650px){
    .sci-page{
        padding:10px 8px 22px;
    }
    .sci-head{
        padding:17px 16px 0;
    }
    .sci-head h3{
        font-size:18px;
    }
    .asset-grid{
        grid-template-columns:1fr;
        padding:14px 16px 17px;
        gap:8px;
    }
    .summary-grid{
        grid-template-columns:1fr;
        padding:13px;
    }
    .alert-sci{
        margin-left:16px!important;
        margin-right:16px!important;
    }
    .section-title{
        font-size:14px;
    }
    .section-count{
        width:100%;
    }
    .submit-box{
        padding:15px;
    }
    .submit-box .btn{
        width:100%;
        margin:4px 0 0!important;
    }
}

/* =========================================================
   RESULT CONDITION — UI ONLY
   No new data, database field, checkpoint or workflow.
========================================================= */
.result-condition-strip{
    margin:0 25px 22px;
    padding:14px 15px;
    border:1px solid #e1e7ef;
    border-radius:11px;
    background:#fbfcfe;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:14px;
    flex-wrap:wrap;
}
.result-condition-title{
    color:#344054;
    font-size:10px;
    font-weight:900;
    text-transform:uppercase;
    letter-spacing:.55px;
    white-space:nowrap;
}
.result-condition-list{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}
.result-condition-item{
    display:flex;
    align-items:center;
    gap:7px;
    padding:6px 10px;
    border:1px solid #dfe5ec;
    border-radius:8px;
    background:#fff;
}
.result-condition-item span{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:28px;
    height:24px;
    border-radius:6px;
    font-size:9px;
    font-weight:900;
}
.result-condition-item small{
    color:#475467;
    font-size:9px;
    font-weight:700;
}
.rc-c span{background:#ecfdf3;color:#067647}
.rc-nc span{background:#fff1f0;color:#b42318}
.rc-na span{background:#f2f4f7;color:#475467}
.rc-ca span{background:#fffaeb;color:#b54708}

/* TOP INSPECTION SUMMARY — UI ONLY */
.inspection-top-summary{
    margin:0 18px 18px;
    border:1px solid #dbe3ec;
    border-radius:12px;
    background:#fff;
    overflow:hidden;
}
.inspection-top-summary-title{
    padding:11px 15px;
    background:#f8fafc;
    border-bottom:1px solid #e2e8f0;
    color:#172033;
    font-size:12px;
    font-weight:900;
    letter-spacing:.35px;
    text-transform:uppercase;
}
.inspection-top-summary-grid{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
}
.inspection-top-summary-item{
    min-height:58px;
    padding:10px 13px;
    border-right:1px solid #edf1f5;
    border-bottom:1px solid #edf1f5;
}
.inspection-top-summary-item:nth-child(4n){
    border-right:0;
}
.inspection-top-summary-label{
    display:block;
    margin-bottom:4px;
    color:#667085;
    font-size:9px;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:.35px;
}
.inspection-top-summary-value{
    color:#172033;
    font-size:11.5px;
    font-weight:900;
    line-height:1.35;
    word-break:break-word;
}
.inspection-top-summary-value.highlight{
    color:#155eef;
}
@media(max-width:900px){
    .inspection-top-summary-grid{
        grid-template-columns:repeat(2,minmax(0,1fr));
    }
    .inspection-top-summary-item:nth-child(4n){
        border-right:1px solid #edf1f5;
    }
    .inspection-top-summary-item:nth-child(2n){
        border-right:0;
    }
}
@media(max-width:650px){
    .inspection-top-summary{
        margin:0 10px 15px;
    }
    .inspection-top-summary-grid{
        grid-template-columns:1fr;
    }
    .inspection-top-summary-item,
    .inspection-top-summary-item:nth-child(2n),
    .inspection-top-summary-item:nth-child(4n){
        border-right:0;
    }
}

@media(max-width:650px){
    .result-condition-strip{
        margin:0 16px 17px;
        align-items:flex-start;
    }
    .result-condition-list{
        width:100%;
        display:grid;
        grid-template-columns:repeat(2,minmax(0,1fr));
    }
    .result-condition-item{
        min-width:0;
    }
    .result-condition-item small{
        overflow:hidden;
        text-overflow:ellipsis;
        white-space:nowrap;
    }
}

/* PRINT */
@media print{
    .sci-page{
        padding:0;
        background:#fff;
    }
    .sci-card,
    .cross-card{
        box-shadow:none!important;
        break-inside:avoid;
    }
    .table-wrap{
        overflow:visible;
    }
    .sci-table{
        min-width:0;
    }
}

.section-select-all{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    flex-wrap:wrap;
    padding:12px 18px;
    background:#fbfdff;
    border-bottom:1px solid var(--sci-gray-200,#dfe6ef);
}
.section-select-title{
    color:#475569;
    font-size:12px;
    font-weight:900;
    text-transform:uppercase;
    letter-spacing:.45px;
}
.section-select-buttons{
    display:flex;
    gap:7px;
    flex-wrap:wrap;
}
.select-all-btn{
    min-width:46px;
    border:1px solid #cbd5e1;
    border-radius:8px;
    padding:7px 13px;
    background:#fff;
    color:#334155;
    font-size:12px;
    font-weight:900;
    cursor:pointer;
    transition:all .15s ease;
}
.select-all-btn:hover{
    transform:translateY(-1px);
    box-shadow:0 3px 8px rgba(15,23,42,.10);
}
.select-all-btn.active{
    background:#175cd3;
    border-color:#175cd3;
    color:#fff;
    box-shadow:0 3px 8px rgba(23,92,211,.18);
}
.select-all-c:hover{border-color:#198754;color:#198754;background:#f0fff7}
.select-all-nc:hover{border-color:#dc3545;color:#dc3545;background:#fff4f4}
.select-all-na:hover{border-color:#64748b;color:#475569;background:#f8fafc}
.select-all-ca:hover{border-color:#b7791f;color:#9a6500;background:#fff9ed}


/* =========================================================
   PAT-STYLE UI COMPATIBILITY LAYER FOR LIFT SCI
   ---------------------------------------------------------
   UI only: no checkpoint/data master changes.
========================================================= */

.nleta-checklist-page{
    max-width:1500px;
    margin:0 auto;
    padding:22px 22px 55px;
    color:#172033;
    font-family:"Segoe UI",Arial,sans-serif;
}

.nleta-checklist-page .nleta-card{
    background:#fff;
    border:1px solid #e1e5ea;
    border-radius:14px;
    box-shadow:0 4px 18px rgba(0,0,0,.07);
    margin-bottom:18px;
    overflow:hidden;
}

.nleta-card-header{
    padding:18px;
    border-bottom:1px solid #e7ebef;
}

.controlled-badge{
    display:inline-flex;
    align-items:center;
    gap:7px;
    padding:6px 10px;
    border-radius:999px;
    background:#eef5ff;
    border:1px solid #cfe0ff;
    color:#175cd3;
    font-size:10px;
    font-weight:900;
    letter-spacing:.35px;
}

.page-title{
    margin:0;
    font-size:26px;
    line-height:1.25;
    font-weight:800;
    color:#172033;
}

.page-subtitle{
    margin-top:6px;
    color:#667085;
    font-size:13px;
    line-height:1.5;
}

.job-grid{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:10px;
    padding:16px 18px 18px;
}

.job-box{
    min-height:62px;
    padding:10px 12px;
    background:#f8fafc;
    border:1px solid #e1e6ed;
    border-radius:9px;
}

.job-label{
    margin-bottom:5px;
    color:#667085;
    font-size:9px;
    font-weight:800;
    line-height:1.2;
    text-transform:uppercase;
    letter-spacing:.55px;
}

.job-value{
    color:#172033;
    font-size:12px;
    line-height:1.4;
    font-weight:800;
    word-break:break-word;
}

.alert-sci{
    margin:0 18px 14px!important;
    font-size:11px;
}

.result-condition-strip{
    margin:0 18px 18px;
    border:1px solid #dfe5ec;
    border-radius:10px;
    background:#fbfcfe;
    padding:11px 13px;
}

.summary-grid-results{
    display:grid;
    grid-template-columns:repeat(6,minmax(0,1fr));
    gap:10px;
    padding:14px 16px;
}

.summary-box{
    min-height:72px;
    padding:11px 13px;
    background:#f8fafc;
    border:1px solid #e1e6ed;
    border-radius:9px;
}

.summary-label{
    color:#667085;
    font-size:9px;
    font-weight:800;
    line-height:1.25;
    text-transform:uppercase;
    letter-spacing:.45px;
}

.summary-value{
    margin-top:8px;
    color:#172033;
    font-size:21px;
    line-height:1;
    font-weight:900;
}

.section-card{
    overflow:hidden!important;
}

.section-heading{
    min-height:54px;
    padding:13px 16px;
    background:#fff;
    border-bottom:1px solid #e3e7ec;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    flex-wrap:wrap;
}

.section-heading-title{
    color:#172033;
    font-size:15px;
    font-weight:800;
    line-height:1.35;
}

.section-count{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:100px;
    padding:5px 9px;
    border-radius:999px;
    background:#f2f4f7;
    border:1px solid #dfe3e8;
    color:#475467;
    font-size:9px;
    font-weight:800;
}

.section-select-all{
    padding:10px 16px;
    background:#fbfdff;
    border-bottom:1px solid #e1e6ed;
}

.section-select-title{
    color:#475467;
    font-size:10px;
    font-weight:900;
    text-transform:uppercase;
    letter-spacing:.45px;
}

.section-select-buttons{
    display:flex;
    gap:6px;
    flex-wrap:wrap;
}

.select-all-btn{
    min-width:44px;
    padding:6px 10px;
    border-radius:7px;
    background:#fff;
    font-size:10px;
    font-weight:900;
}

.sci-table{
    min-width:1100px;
}

.sci-table th{
    background:#f8fafc;
    color:#475467;
    font-size:9px;
    padding:9px 10px;
    text-transform:uppercase;
}

.sci-table td{
    color:#344054;
    font-size:11px;
    padding:9px 10px;
}

.result-options{
    min-width:230px;
    gap:5px;
}

.result-options span{
    min-width:36px;
    min-height:30px;
    padding:5px 8px;
    border-radius:7px;
    font-size:9px;
}

.extra-fields{
    max-width:320px;
    padding:9px;
    border-radius:8px;
}

.form-label{
    font-size:9px!important;
}

.form-control{
    font-size:10px!important;
}

/* PAT-style final inspection card */
.final-status-card{
    overflow:hidden!important;
}

.final-status-header{
    padding:17px 18px 12px;
    background:#fff;
}

.final-status-header h4{
    color:#172033;
    font-size:17px;
    font-weight:800;
}

.final-status-grid{
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:12px;
    padding:0 18px 16px;
}

.final-option{
    display:flex;
    align-items:center;
    gap:8px;
    min-height:54px;
    padding:12px 14px;
    border:1px solid #ced4da;
    border-radius:9px;
    background:#fff;
    color:#20242a;
    font-size:14px;
    font-weight:900;
    cursor:pointer;
    transition:.15s ease;
}

.final-option:hover{
    border-color:#9ca3af;
    background:#fafafa;
}

.final-option input{
    margin:0;
    width:15px;
    height:15px;
    accent-color:#0d6efd;
}

.final-option input:checked + span{
    color:#0d6efd;
}

.final-fields{
    padding:0 18px 18px;
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:12px;
}

.final-field label{
    display:block;
    margin-bottom:5px;
    color:#172033;
    font-size:12px;
    font-weight:800;
}

.final-field input{
    width:100%;
    min-height:40px;
    border:1px solid #ced4da;
    border-radius:7px;
    padding:9px;
    color:#172033;
    background:#fff;
    font-size:13px;
}

.final-field input:focus{
    outline:none;
    border-color:#86b7fe;
    box-shadow:0 0 0 .2rem rgba(13,110,253,.10);
}

/* PAT-style sticky action bar */
.action-bar{
    position:sticky;
    bottom:0;
    z-index:20;
    background:rgba(255,255,255,.97);
    border-top:1px solid #dee2e6;
    padding:14px 18px;
    box-shadow:0 -3px 12px rgba(0,0,0,.05);
}

.action-content{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    flex-wrap:wrap;
}

.action-meta{
    display:flex;
    align-items:center;
    gap:10px;
    flex-wrap:wrap;
    color:#667085;
    font-size:11px;
}

.action-meta strong{
    color:#172033;
    font-size:14px;
}

.action-status{
    color:#087443;
    font-weight:800;
}

.action-buttons{
    display:flex;
    gap:8px;
    align-items:center;
}

.action-buttons .btn{
    min-height:38px;
    border-radius:7px;
    font-size:11px;
    font-weight:800;
    padding:8px 14px;
}

/* PAT-style final lock if the page is later rendered in view-only mode */
.pat-final-lock-banner{
    margin:0 0 18px;
    padding:16px 18px;
    border:2px solid #b6d4fe;
    border-radius:14px;
    background:#eef6ff;
    display:flex;
    align-items:flex-start;
    gap:14px;
    color:#084298;
    box-shadow:0 3px 12px rgba(0,0,0,.05);
}

@media(max-width:1100px){
    .job-grid{
        grid-template-columns:repeat(2,minmax(0,1fr));
    }
    .summary-grid-results{
        grid-template-columns:repeat(3,minmax(0,1fr));
    }
    .final-fields{
        grid-template-columns:1fr;
    }
}

@media(max-width:700px){
    .nleta-checklist-page{
        padding:10px;
    }
    .job-grid{
        grid-template-columns:1fr;
    }
    .summary-grid-results{
        grid-template-columns:repeat(2,minmax(0,1fr));
    }
    .final-status-grid{
        grid-template-columns:1fr;
    }
    .page-title{
        font-size:21px;
    }
    .action-content{
        align-items:stretch;
    }
    .action-content > div{
        width:100%;
    }
    .action-buttons{
        width:100%;
    }
    .action-buttons .btn{
        flex:1;
    }
}

@media(max-width:380px){
    .summary-grid-results{
        grid-template-columns:1fr;
    }
    .job-grid{
        gap:6px;
    }
}


.action-buttons .btn-outline-primary{
    border-color:#86b7fe;
    color:#0d6efd;
    background:#fff;
    font-weight:800;
}
.action-buttons .btn-outline-primary:hover{
    background:#eef5ff;
    border-color:#0d6efd;
}

/* AUTOMATIC DRAFT SAVE — NO VISIBLE SAVE-DRAFT BUTTON */
#autoSaveStatus{
    transition:opacity .2s ease;
}
</style>

<div class="content">
<div class="nleta-checklist-page">

<div class="nleta-card">

    <div class="nleta-card-header">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <div class="controlled-badge mb-2">
                    <i class="bi bi-shield-check"></i>
                    CHK-03 • REV.1 • CONTROLLED CHECKLIST
                </div>

                <h1 class="page-title">NLETA SCI Inspection Checklist</h1>

                <div class="page-subtitle">
                    Safety Compliance Inspection — Master Checklist
                </div>
            </div>

            <div class="text-end">
                <div class="small text-muted">Current IST</div>
                <strong><?= h($currentIST) ?></strong>
            </div>
        </div>
    </div>

    <div class="job-grid">
        <div class="job-box">
            <div class="job-label">PSGA / Contract No.</div>
            <div class="job-value"><?= h($job['psga_no'] ?? '—') ?></div>
        </div>

        <div class="job-box">
            <div class="job-label">SAF No.</div>
            <div class="job-value"><?= h($job['saf_no'] ?? '—') ?></div>
        </div>

        <div class="job-box">
            <div class="job-label">Asset / Lift</div>
            <div class="job-value">
                <?= h($job['display_asset_id'] ?: ($job['asset_name'] ?: '—')) ?>
            </div>
        </div>

        <div class="job-box">
            <div class="job-label">Owner</div>
            <div class="job-value"><?= h($job['owner_name'] ?? '—') ?></div>
        </div>

        <div class="job-box">
            <div class="job-label">Inspector</div>
            <div class="job-value"><?= h($inspectorDisplayName) ?></div>
        </div>

        <div class="job-box">
            <div class="job-label">Location</div>
            <div class="job-value"><?= h($job['location'] ?? '—') ?></div>
        </div>

        <div class="job-box">
            <div class="job-label">Service</div>
            <div class="job-value"><?= h($job['service_name'] ?? 'SCI') ?></div>
        </div>

        <div class="job-box">
            <div class="job-label">Equipment Type</div>
            <div class="job-value">
                <?= h($job['asset_type'] ?: ($job['category'] ?: 'Lift')) ?>
            </div>
        </div>

        <div class="job-box">
            <div class="job-label">Inspection Date</div>
            <div class="job-value">
                <?= h(istDate($job['proposed_inspection_date'] ?? null, 'd / m / Y')) ?>
            </div>
        </div>

        <div class="job-box">
            <div class="job-label">Checklist</div>
            <div class="job-value">CHK-03 Rev.1</div>
        </div>

        <div class="job-box">
            <div class="job-label">Total Execution Checkpoints</div>
            <div class="job-value"><?= (int)$totalExecutionCheckpoints ?></div>
        </div>
    </div>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger alert-sci mb-0">
            <i class="bi bi-exclamation-triangle me-2"></i><?= h($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($success !== ''): ?>
        <div class="alert alert-success alert-sci mb-0">
            <i class="bi bi-check-circle me-2"></i><?= h($success) ?>
        </div>
    <?php endif; ?>

    <div class="alert alert-info alert-sci">
        <strong>SCI Rule:</strong>
        PAT construction checkpoints and SAT functional tests are inherited through the Cross-Lock Engine and remain read-only.
        SCI records statutory, documentation, emergency, accessibility, passenger safety, maintenance governance, insurance/legal and lifecycle compliance.
    </div>

    <!-- UI ONLY: Result condition guide. No database/data fields added. -->
    <div class="result-condition-strip">
        <div class="result-condition-title">
            <i class="bi bi-ui-checks-grid me-1"></i> Result Condition
        </div>
        <div class="result-condition-list">
            <div class="result-condition-item rc-c"><span>C</span><small>Complied</small></div>
            <div class="result-condition-item rc-nc"><span>NC</span><small>Non-Complied</small></div>
            <div class="result-condition-item rc-na"><span>NA</span><small>Not Applicable</small></div>
            <div class="result-condition-item rc-ca"><span>CA</span><small>Conditional Acceptance</small></div>
        </div>
    </div>
</div>

<div class="nleta-card">
    <div class="summary-grid-results">
        <div class="summary-box">
            <div class="summary-label">Total</div>
            <div class="summary-value"><?= $total ?></div>
        </div>
        <div class="summary-box">
            <div class="summary-label">Completed</div>
            <div class="summary-value text-success"><?= $completed ?></div>
        </div>
        <div class="summary-box">
            <div class="summary-label">C — Complied</div>
            <div class="summary-value text-success"><?= $countC ?></div>
        </div>
        <div class="summary-box">
            <div class="summary-label">NC — Non-Complied</div>
            <div class="summary-value text-danger"><?= $countNC ?></div>
        </div>
        <div class="summary-box">
            <div class="summary-label">NA — Not Applicable</div>
            <div class="summary-value"><?= $countNA ?></div>
        </div>
        <div class="summary-box">
            <div class="summary-label">CA — Conditional Acceptance</div>
            <div class="summary-value"><?= $countCA ?></div>
        </div>
    </div>
</div>

<?php foreach ($crossLocks as $lock): ?>
<div class="cross-card">
    <div class="cross-title"><i class="bi bi-lock-fill me-1"></i><?= h($lock['title']) ?></div>
    <div class="cross-source">Source Module: <?= h($lock['source']) ?> • READ ONLY</div>
    <div class="cross-list">
        <?php foreach ($lock['items'] as $crossItem): ?>
            <div class="cross-item">
                <i class="bi bi-link-45deg me-1 text-primary"></i><?= h($crossItem) ?>
                <span class="badge bg-secondary float-end">Inherited</span>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>

<form method="POST" enctype="multipart/form-data" id="sciForm" novalidate>
<input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
<input type="hidden" name="job_id" value="<?= $jobId ?>">
<input type="hidden" name="assignment_id" value="<?= $actualAssignmentId ?>">

<?php foreach ($sections as $sectionKey => $section): ?>
<div class="nleta-card section-card">
    <div class="section-heading">
        <div class="section-heading-title"><?= h($section['title']) ?></div>
        <div class="section-count"><?= count($section['items']) ?> checkpoints</div>
    </div>

    <div class="section-select-all">
        <div class="section-select-title">
            <i class="bi bi-check2-square me-1"></i>
            Select All Result
        </div>
        <div class="section-select-buttons">
            <button type="button" class="select-all-btn select-all-c"
                    onclick="selectAllSCISection(this,'C')">C</button>
            <button type="button" class="select-all-btn select-all-nc"
                    onclick="selectAllSCISection(this,'NC')">NC</button>
            <button type="button" class="select-all-btn select-all-na"
                    onclick="selectAllSCISection(this,'NA')">NA</button>
            <button type="button" class="select-all-btn select-all-ca"
                    onclick="selectAllSCISection(this,'CA')">CA</button>
        </div>
    </div>

    <div class="table-wrap">
        <table class="sci-table">
            <thead>
                <tr>
                    <th>SCI ID</th>
                    <th>Compliance Checkpoint</th>
                    <th>Priority</th>
                    <th>Result</th>
                    <th>Conditional Fields / Evidence</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($section['items'] as $item): ?>
                <?php
                [$ref,$description,$priority] = $item;
                $saved = is_array($existingResults[$ref] ?? null) ? $existingResults[$ref] : [];
                $savedResult = strtoupper(trim((string)($saved['result'] ?? '')));
                $savedRemark = (string)($saved['remark'] ?? '');
                $savedCARemark = (string)($saved['ca_remark'] ?? $saved['technical_justification'] ?? '');
                $savedPhoto = (string)(
                    $saved['evidence']['photo']
                    ?? $saved['photo']
                    ?? $evidenceByRef[$ref]['photo_path']
                    ?? ''
                );
                ?>
                <tr>
                    <td class="ref"><?= h($ref) ?></td>
                    <td><?= h($description) ?></td>
                    <td class="priority"><?= h($priority) ?></td>
                    <td>
                        <div class="result-options">
                            <?php foreach (['C','NC','NA','CA'] as $option): ?>
                                <label>
                                    <input
                                        type="radio"
                                        name="result[<?= h($ref) ?>]"
                                        value="<?= $option ?>"
                                        <?= $savedResult === $option ? 'checked' : '' ?>
                                        onchange="toggleSCIFields('<?= h($ref) ?>')"
                                        required
                                    >
                                    <span><?= $option ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </td>
                    <td>
                        <div id="fields-<?= h($ref) ?>" class="extra-fields <?= in_array($savedResult,['NC','CA'],true) ? 'show' : '' ?>">
                            <div class="mb-2 nc-field-<?= h($ref) ?>" style="<?= $savedResult === 'NC' ? '' : 'display:none' ?>">
                                <label class="form-label small fw-bold">Engineering Remark <span class="text-danger">*</span></label>
                                <textarea
                                    class="form-control"
                                    name="remark[<?= h($ref) ?>]"
                                    rows="2"
                                    data-nc-remark="<?= h($ref) ?>"
                                    placeholder="Enter Engineering Remark for NC"
                                ><?= h($savedRemark) ?></textarea>
                            </div>

                            <div class="mb-2 photo-field-<?= h($ref) ?>" style="<?= in_array($savedResult,['NC','CA'],true) ? '' : 'display:none' ?>">
                                <label class="form-label small fw-bold">Photograph <span class="text-danger">*</span></label>
                                <input
                                    type="file"
                                    class="form-control"
                                    name="photo[<?= h($ref) ?>]"
                                    data-required-photo="<?= h($ref) ?>"
                                    accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                                >
                                <?php if ($savedPhoto !== ''): ?>
                                    <div class="small text-success mt-1">
                                        <i class="bi bi-paperclip me-1"></i>Existing photograph saved.
                                    </div>
                                <?php endif; ?>
                                <div class="small text-muted mt-1">NC / CA → Photograph REQUIRED.</div>
                            </div>

                            <div class="mb-2 video-field-<?= h($ref) ?>" style="<?= in_array($savedResult,['NC','CA'],true) ? '' : 'display:none' ?>">
                                <label class="form-label small fw-bold">Video Evidence <span class="text-muted">(Optional)</span></label>
                                <input
                                    type="file"
                                    class="form-control"
                                    name="video[<?= h($ref) ?>]"
                                    data-optional-video="<?= h($ref) ?>"
                                    accept="video/mp4,video/webm,video/quicktime,video/x-msvideo"
                                >
                                <?php if (!empty($saved['evidence']['video'] ?? '') || !empty($saved['video'] ?? '')): ?>
                                    <div class="small text-success mt-1"><i class="bi bi-camera-video me-1"></i>Existing video saved.</div>
                                <?php endif; ?>
                                <div class="small text-muted mt-1">Optional. MP4 / WEBM / MOV / AVI — maximum 20 MB.</div>
                            </div>

                            <div class="mb-2 ca-field-<?= h($ref) ?>" style="<?= $savedResult === 'CA' ? '' : 'display:none' ?>">
                                <label class="form-label small fw-bold">Conditional Acceptance Remark / Condition <span class="text-danger">*</span></label>
                                <textarea
                                    class="form-control"
                                    name="ca_remark[<?= h($ref) ?>]"
                                    rows="2"
                                    data-ca-remark="<?= h($ref) ?>"
                                    placeholder="Enter the condition / restriction / corrective requirement for CA"
                                ><?= h($savedCARemark) ?></textarea>
                            </div>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="p-3 border-top">
        <label class="form-label fw-bold">Section Remark <span class="text-muted">(optional)</span></label>
        <textarea class="form-control" name="section_remarks[<?= h($sectionKey) ?>]" rows="2" placeholder="Section-level observation"><?= h($existingRemarks[$sectionKey] ?? '') ?></textarea>
    </div>
</div>
<?php endforeach; ?>

<div class="nleta-card final-status-card">

    <div class="nleta-card-header final-status-header">
        <h4 class="mb-1">
            <i class="bi bi-flag me-2"></i>
            Final Inspection Status
        </h4>

        <div class="text-muted small">
            Overall SCI inspection outcome.
        </div>
    </div>

    <div class="final-status-grid">

        <?php foreach (['PASS', 'CONDITIONAL PASS', 'FAIL'] as $status): ?>
            <label class="final-option">
                <input
                    type="radio"
                    name="final_status"
                    value="<?= h($status) ?>"
                    <?= $existingFinalStatus === $status ? 'checked' : '' ?>
                    required
                >
                <span><?= h($status) ?></span>
            </label>
        <?php endforeach; ?>

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

        <div class="action-meta">
            <strong>CHK-03 Rev.1</strong>
            <span>50 Fixed Execution Checkpoints</span>
            <span class="action-status" id="autoSaveStatus">
                <i class="bi bi-cloud-check me-1"></i>
                Draft Auto-save Enabled
            </span>
        </div>

        <div class="action-buttons">
            <a
                href="inspections.php?asset_id=<?= $actualAssetId ?>&saf_id=<?= $actualSafId ?>"
                class="btn btn-outline-secondary"
            >
                <i class="bi bi-arrow-left me-1"></i>Back
            </a>

            <button
                type="submit"
                name="save_sci"
                value="1"
                class="btn btn-success"
            >
                <i class="bi bi-check2-circle me-1"></i>
                Save SCI Checklist
            </button>
        </div>

    </div>

</div>

</form>

</div>
</div>

<script>
(function () {
    let autoSaveTimer = null;
    let autoSaveInProgress = false;

    function scheduleSCIAutoSave() {
        clearTimeout(autoSaveTimer);

        const form = document.getElementById('sciForm');
        if (!form) return;

        autoSaveTimer = setTimeout(function () {
            autoSaveSCI();
        }, 1200);
    }

    function autoSaveSCI() {
        const form = document.getElementById('sciForm');
        if (!form || autoSaveInProgress) return;

        const status = document.getElementById('autoSaveStatus');
        autoSaveInProgress = true;

        if (status) {
            status.innerHTML =
                '<i class="bi bi-cloud-arrow-up me-1"></i> Saving Draft…';
        }

        const data = new FormData(form);

        // Automatic draft save must never trigger final-save validation.
        data.delete('save_sci');
        data.set('save_draft', '1');

        fetch(window.location.href, {
            method: 'POST',
            body: data,
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function (response) {
            if (!response.ok) throw new Error('Auto-save failed');
            return response.text();
        })
        .then(function () {
            if (status) {
                status.innerHTML =
                    '<i class="bi bi-cloud-check me-1"></i> Draft Saved Automatically';
            }
        })
        .catch(function () {
            if (status) {
                status.innerHTML =
                    '<i class="bi bi-cloud-exclamation me-1"></i> Draft Auto-save Retry Pending';
            }
        })
        .finally(function () {
            autoSaveInProgress = false;
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('sciForm');
        if (!form) return;

        // Save automatically after any result, remark, representative,
        // final-status, section remark, photo or video change.
        form.addEventListener('change', function () {
            scheduleSCIAutoSave();
        });

        form.addEventListener('input', function () {
            scheduleSCIAutoSave();
        });

        // Give the user a short grace period after opening the checklist,
        // but do not create any visible button.
        window.addEventListener('beforeunload', function () {
            clearTimeout(autoSaveTimer);
        });
    });
})();
</script>

<script>
function toggleSCIFields(ref) {
    const fields = document.getElementById('fields-' + ref);
    if (!fields) return;

    const checked = document.querySelector('input[name="result[' + ref + ']"]:checked');
    const value = checked ? checked.value : '';

    fields.classList.toggle('show', value === 'NC' || value === 'CA');

    document.querySelectorAll('.nc-field-' + ref).forEach(function(el) {
        el.style.display = value === 'NC' ? '' : 'none';
    });

    document.querySelectorAll('.photo-field-' + ref).forEach(function(el) {
        el.style.display = (value === 'NC' || value === 'CA') ? '' : 'none';
    });

    document.querySelectorAll('.video-field-' + ref).forEach(function(el) {
        el.style.display = (value === 'NC' || value === 'CA') ? '' : 'none';
    });

    document.querySelectorAll('.ca-field-' + ref).forEach(function(el) {
        el.style.display = value === 'CA' ? '' : 'none';
    });

    const remark = document.querySelector('[data-nc-remark="' + ref + '"]');
    const photo = document.querySelector('[data-required-photo="' + ref + '"]');
    const caRemark = document.querySelector('[data-ca-remark="' + ref + '"]');

    if (remark) remark.required = value === 'NC';

    if (photo) {
        const existing = photo.parentElement.querySelector('.text-success');
        photo.required = (value === 'NC' || value === 'CA') && !existing;
    }

    if (caRemark) caRemark.required = value === 'CA';
}

function radioNameRef(name) {
    const match = name.match(/^result\[(.+)\]$/);
    return match ? match[1] : '';
}


function selectAllSCISection(button, result) {
    // The actual section wrapper in this checklist is .section-card.
    // Previous selector .sci-section-card did not exist, so Select All
    // returned immediately without changing any radio buttons.
    const card = button.closest('.section-card');
    if (!card) {
        console.error('SCI Select All: section card not found.');
        return;
    }

    const labels = {
        C: 'C (Complied)',
        NC: 'NC (Non-Complied)',
        NA: 'NA (Not Applicable)',
        CA: 'CA (Conditional Acceptance)'
    };

    if (!confirm('Set all checkpoints in this section to ' + labels[result] + '?')) {
        return;
    }

    const radios = card.querySelectorAll('input[type="radio"][name^="result["]');
    const refs = new Set();

    radios.forEach(function(radio) {
        if (radio.value === result) {
            radio.checked = true;
            const ref = radioNameRef(radio.name);
            if (ref) {
                refs.add(ref);
            }
        }
    });

    // Refresh NC / CA evidence fields for every checkpoint in this section.
    refs.forEach(function(ref) {
        toggleSCIFields(ref);
    });

    // Keep the clicked bulk-result button visually selected.
    card.querySelectorAll('.select-all-btn').forEach(function(btn) {
        btn.classList.remove('active');
    });
    button.classList.add('active');

    // Trigger change so the existing automatic draft-save mechanism saves
    // the bulk selection without requiring a separate Save Draft button.
    if (refs.size > 0) {
        const first = card.querySelector('input[type="radio"][name^="result["]');
        if (first) {
            first.dispatchEvent(new Event('change', {bubbles: true}));
        }
    }
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('input[type="radio"][name^="result["]').forEach(function(radio) {
        if (radio.checked) {
            const ref = radioNameRef(radio.name);
            if (ref) toggleSCIFields(ref);
        }
    });

    const form = document.getElementById('sciForm');
    if (!form) return;

    form.addEventListener('submit', function(e) {
        const radios = {};

        document.querySelectorAll('input[type="radio"][name^="result["]').forEach(function(radio) {
            const ref = radioNameRef(radio.name);
            if (!ref) return;
            if (!radios[ref]) radios[ref] = false;
            if (radio.checked) radios[ref] = true;
        });

        for (const ref in radios) {
            if (!radios[ref]) {
                e.preventDefault();
                alert('Please select a result for every Lift SCI checkpoint.');
                return;
            }

            const checked = document.querySelector('input[name="result[' + ref + ']"]:checked');
            const value = checked ? checked.value : '';

            if (value === 'NC') {
                const remark = document.querySelector('[data-nc-remark="' + ref + '"]');
                if (!remark || !remark.value.trim()) {
                    e.preventDefault();
                    alert(ref + ': Engineering Remark is required for NC.');
                    if (remark) remark.focus();
                    return;
                }
            }

            if (value === 'NC' || value === 'CA') {
                const photo = document.querySelector('[data-required-photo="' + ref + '"]');
                const existing = photo && photo.parentElement.querySelector('.text-success');

                if (photo && !photo.files.length && !existing) {
                    e.preventDefault();
                    alert(ref + ': Photograph is required for ' + value + '.');
                    photo.focus();
                    return;
                }
            }

            if (value === 'CA') {
                const caRemark = document.querySelector('[data-ca-remark="' + ref + '"]');

                if (!caRemark || !caRemark.value.trim()) {
                    e.preventDefault();
                    alert(ref + ': Conditional Acceptance Remark / Condition is required for CA.');
                    if (caRemark) caRemark.focus();
                    return;
                }
            }
        }

        const finalStatus = document.querySelector('input[name="final_status"]:checked');
        if (!finalStatus) {
            e.preventDefault();
            alert('Please select Final Inspection Status.');
            const firstFinal = document.querySelector('input[name="final_status"]');
            if (firstFinal) firstFinal.focus();
            return;
        }
    });
});
</script>

<?php include('../includes/footer.php'); ?>