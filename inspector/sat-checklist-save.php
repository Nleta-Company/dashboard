<?php
/* =========================================================
   NLETA INSPECTOR — SAVE SAT CHECKLIST
   CHK-02 — SAT MASTER CHECKLIST
   Revision: Rev.1

   Saves one selected Asset / Job SAT checklist.

   Rules:
   C  = Pass
   NC = Fail -> Engineering Remark REQUIRED
   NA = Not Applicable
   CA = Conditional Acceptance -> Conditional Acceptance Remark REQUIRED

   Evidence:
   Photograph = OPTIONAL
   Video      = OPTIONAL
   Document   = OPTIONAL

   SAT acceptance:
   Functional Test / Load Test / Rescue Test /
   Electrical Test = PASS or FAIL
   Overall SAT = SATISFACTORY or UNSATISFACTORY

   Original PAT data is never overwritten.
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

function failBack(string $message, int $jobId, int $assignmentId = 0, int $assetId = 0): void
{
    $_SESSION['error'] = $message;

    $url = 'saf-checklist.php?job_id=' . $jobId;

    if ($assignmentId > 0) {
        $url .= '&assignment_id=' . $assignmentId;
    }

    if ($assetId > 0) {
        $url .= '&asset_id=' . $assetId;
    }

    header('Location: ' . $url);
    exit;
}

function validateCsrf(string $token): bool
{
    if (function_exists('validateCSRFToken')) {
        return (bool)validateCSRFToken($token);
    }

    return !empty($_SESSION['csrf_token'])
        && hash_equals((string)$_SESSION['csrf_token'], $token);
}

function cleanText($value, int $max = 2000): string
{
    $value = trim((string)$value);

    if (strlen($value) > $max) {
        $value = substr($value, 0, $max);
    }

    return $value;
}

function normalizeResult($value): string
{
    $value = strtoupper(trim((string)$value));

    return in_array($value, ['C','NC','NA','CA'], true)
        ? $value
        : '';
}

function ensureUploadDirectory(string $dir): void
{
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create SAT evidence directory.');
        }
    }
}

function saveUploadedFile(
    array $file,
    string $prefix,
    int $jobId,
    array $allowedMime,
    int $maxBytes
): string {
    if (!isset($file['error']) || (int)$file['error'] === UPLOAD_ERR_NO_FILE) {
        return '';
    }

    if ((int)$file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Evidence upload failed for ' . $prefix . '.');
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    $name = (string)($file['name'] ?? '');
    $size = (int)($file['size'] ?? 0);

    if ($tmp === '' || $name === '' || $size <= 0 || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Invalid evidence upload for ' . $prefix . '.');
    }

    if ($size > $maxBytes) {
        throw new RuntimeException(
            $prefix . ' exceeds the maximum allowed file size of '
            . number_format($maxBytes / 1048576, 1)
            . ' MB.'
        );
    }

    /*
     * Same evidence policy as the updated PAT checklist:
     * accept normal phone/browser formats without a restrictive
     * MIME whitelist. Server-executable extensions remain blocked
     * for security because uploads are stored under a web path.
     */
    $originalExt = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    $blockedExtensions = [
        'php','php3','php4','php5','php7','php8','phtml','phar',
        'cgi','pl','py','pyc','jsp','jspx','asp','aspx','exe','com',
        'bat','cmd','sh','bash','bin','dll','so'
    ];

    if ($originalExt !== '' && in_array($originalExt, $blockedExtensions, true)) {
        throw new RuntimeException(
            'Unsupported executable/server-side file type for ' . $prefix . '.'
        );
    }

    $safeExt = preg_replace('/[^a-z0-9]+/i', '', $originalExt);
    if ($safeExt === '') {
        $safeExt = 'bin';
    }
    $safeExt = substr($safeExt, 0, 12);

    $root = dirname(__DIR__);
    $relativeDir = 'uploads/inspections/' . $jobId . '/';
    $absoluteDir = $root . '/uploads/inspections/' . $jobId . '/';

    ensureUploadDirectory($absoluteDir);

    $filename =
        'SAT_'
        . $jobId
        . '_'
        . preg_replace('/[^A-Za-z0-9_-]/', '_', $prefix)
        . '_'
        . bin2hex(random_bytes(8))
        . '.'
        . $safeExt;

    $target = $absoluteDir . $filename;

    if (!move_uploaded_file($tmp, $target)) {
        throw new RuntimeException('Unable to store evidence file for ' . $prefix . '.');
    }

    return $relativeDir . $filename;
}

function persistImmediateEvidence(
    mysqli $conn,
    int $jobId,
    string $reference,
    string $type,
    string $path,
    string $result
): void {
    $reference = trim($reference);
    $type = strtolower(trim($type));
    $result = strtoupper(trim($result));

    if ($jobId <= 0 || $reference === '' || $path === '') {
        throw new RuntimeException('Invalid SAT evidence persistence data.');
    }

    if (!in_array($type, ['photo', 'video'], true)) {
        throw new RuntimeException('Invalid SAT evidence type.');
    }

    if (!in_array($result, ['NC', 'CA'], true)) {
        throw new RuntimeException('Evidence can only be uploaded for NC or CA.');
    }

    if ($type === 'photo') {
        $sql = "
            INSERT INTO saf_checklist_evidence
            (
                job_id,
                ref_code,
                result,
                photo_path,
                video_path,
                document_path
            )
            VALUES (?, ?, ?, ?, '', NULL)
            ON DUPLICATE KEY UPDATE
                result = VALUES(result),
                photo_path = VALUES(photo_path)
        ";
    } else {
        $sql = "
            INSERT INTO saf_checklist_evidence
            (
                job_id,
                ref_code,
                result,
                photo_path,
                video_path,
                document_path
            )
            VALUES (?, ?, ?, '', ?, NULL)
            ON DUPLICATE KEY UPDATE
                result = VALUES(result),
                video_path = VALUES(video_path)
        ";
    }

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException(
            'Unable to prepare SAT immediate evidence save: ' . $conn->error
        );
    }

    $stmt->bind_param('isss', $jobId, $reference, $result, $path);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();

        throw new RuntimeException(
            'Unable to save SAT evidence for ' . $reference . ': ' . $error
        );
    }

    $stmt->close();
}

function loadSavedEvidence(mysqli $conn, int $jobId): array
{
    $rows = [];

    $tableCheck = $conn->query("SHOW TABLES LIKE 'saf_checklist_evidence'");

    if (!$tableCheck || $tableCheck->num_rows === 0) {
        return $rows;
    }

    $stmt = $conn->prepare("
        SELECT
            ref_code,
            result,
            photo_path,
            video_path,
            document_path
        FROM saf_checklist_evidence
        WHERE job_id = ?
    ");

    if (!$stmt) {
        return $rows;
    }

    $stmt->bind_param('i', $jobId);
    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $rows[(string)$row['ref_code']] = $row;
    }

    $stmt->close();

    return $rows;
}

$inspectorId = (int)($_SESSION['uid'] ?? $_SESSION['user_id'] ?? 0);
$jobId = (int)($_POST['job_id'] ?? 0);
$assignmentId = (int)($_POST['assignment_id'] ?? 0);
$assetId = (int)($_POST['selected_asset_id'] ?? 0);
$safId = (int)($_POST['saf_id'] ?? 0);

if ($inspectorId <= 0) {
    header('Location: dashboard.php');
    exit;
}

if ($jobId <= 0) {
    header('Location: inspections.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: saf-checklist.php?job_id=' . $jobId);
    exit;
}

/* =========================================================
   SAVE MODE
   ---------------------------------------------------------
   asset      = final SAT submission
   auto_draft = automatic background draft save
   ---------------------------------------------------------
   IMPORTANT:
   No separate SAT draft endpoint is used. Both modes are
   handled by this existing sat-checklist-save.php file.
========================================================= */
$saveMode = strtolower(trim((string)($_POST['save_mode'] ?? 'asset')));
$isAutoDraft = ($saveMode === 'auto_draft');
$isAjax = (
    $isAutoDraft
    && (
        (string)($_POST['ajax'] ?? '') === '1'
        || isset($_SERVER['HTTP_X_REQUESTED_WITH'])
    )
);

function draftJsonResponse(bool $success, string $message, array $extra = []): void
{
    if (ob_get_length()) {
        @ob_clean();
    }

    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        array_merge(
            [
                'success' => $success,
                'message' => $message,
            ],
            $extra
        ),
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

if (!validateCsrf((string)($_POST['csrf_token'] ?? ''))) {
    failBack('Invalid security token. Please refresh and try again.', $jobId, $assignmentId, $assetId);
}

/* =========================================================
   VERIFY ASSIGNMENT + SAT SERVICE
========================================================= */

$stmt = $conn->prepare("
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

        s.service_code,
        s.service_name,
        s.status AS saf_status

    FROM maintenance_jobs mj

    INNER JOIN inspector_assignments ia
        ON ia.job_id = mj.id
        AND ia.inspector_id = ?

    INNER JOIN saf_contracts s
        ON s.id = mj.saf_id

    WHERE
        mj.id = ?
        AND mj.job_type = 'saf_inspection'

    LIMIT 1
");

if (!$stmt) {
    failBack('Unable to prepare SAT verification: ' . $conn->error, $jobId, $assignmentId, $assetId);
}

$stmt->bind_param('ii', $inspectorId, $jobId);

if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    failBack('Unable to verify SAT inspection: ' . $error, $jobId, $assignmentId, $assetId);
}

$job = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$job) {
    failBack('This SAT inspection is not assigned to you.', $jobId, $assignmentId, $assetId);
}

$actualAssignmentId = (int)$job['assignment_id'];
$actualAssetId = (int)$job['asset_id'];
$actualSafId = (int)$job['saf_id'];

if ($assignmentId > 0 && $assignmentId !== $actualAssignmentId) {
    failBack('Invalid Inspector assignment.', $jobId, $actualAssignmentId, $actualAssetId);
}

if ($assetId > 0 && $assetId !== $actualAssetId) {
    failBack('Selected Asset does not match the SAT job.', $jobId, $actualAssignmentId, $actualAssetId);
}

if ($safId > 0 && $safId !== $actualSafId) {
    failBack('Selected SAF does not match the SAT job.', $jobId, $actualAssignmentId, $actualAssetId);
}

if (strtoupper(trim((string)$job['service_code'])) !== 'SAT') {
    failBack(
        'This job is not a SAT service. SAT checklist cannot be used for service '
        . ($job['service_code'] ?: 'Unknown') . '.',
        $jobId,
        $actualAssignmentId,
        $actualAssetId
    );
}

if (
    trim((string)($job['saf_status'] ?? '')) !== ''
    && strcasecmp(trim((string)$job['saf_status']), 'Accepted') !== 0
) {
    failBack('This SAT/SAF has not been accepted by the Owner.', $jobId, $actualAssignmentId, $actualAssetId);
}

$jobStatus = strtolower(trim((string)$job['job_status']));
$assignmentStatus = strtolower(trim((string)$job['assignment_status']));

if (
    !in_array($jobStatus, ['assigned','in_progress'], true)
    && !in_array($assignmentStatus, ['assigned','in_progress'], true)
) {
    failBack(
        'This SAT inspection is not available for checklist work.',
        $jobId,
        $actualAssignmentId,
        $actualAssetId
    );
}

/* =========================================================
   IMMEDIATE SINGLE-FILE EVIDENCE UPLOAD
   ---------------------------------------------------------
   Same persistence behavior as PAT:
   - one file at a time
   - physical file saved immediately
   - DB evidence row UPSERTed immediately
   - survives refresh/back before final checklist submit
========================================================= */
$uploadOnly = (string)($_POST['upload_only'] ?? '') === '1';

if ($uploadOnly) {
    $uploadType = strtolower(trim((string)($_POST['evidence_type'] ?? '')));
    $uploadReference = trim((string)($_POST['evidence_ref'] ?? ''));
    $uploadResult = strtoupper(trim((string)($_POST['evidence_result'] ?? '')));

    if (!in_array($uploadType, ['photo', 'video'], true)) {
        draftJsonResponse(false, 'Invalid evidence type.');
    }

    if ($uploadReference === '') {
        draftJsonResponse(false, 'Evidence reference is missing.');
    }

    if (!in_array($uploadResult, ['NC', 'CA'], true)) {
        draftJsonResponse(false, 'Evidence is only allowed for NC or CA.');
    }

    $file = $_FILES['evidence_file'] ?? null;

    if (
        !is_array($file)
        || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
    ) {
        draftJsonResponse(false, 'Please select a file.');
    }

    $savedPath = '';

    try {
        $savedPath = saveUploadedFile(
            $file,
            $uploadReference . '_' . $uploadType,
            $jobId,
            [],
            50 * 1024 * 1024
        );

        persistImmediateEvidence(
            $conn,
            $jobId,
            $uploadReference,
            $uploadType,
            $savedPath,
            $uploadResult
        );

        draftJsonResponse(
            true,
            'Evidence saved on server.',
            [
                'job_id' => $jobId,
                'reference' => $uploadReference,
                'result' => $uploadResult,
                'type' => $uploadType,
                'path' => $savedPath,
                'url' => '../' . ltrim($savedPath, '/'),
            ]
        );
    } catch (Throwable $e) {
        if ($savedPath !== '') {
            $absolute = dirname(__DIR__) . '/' . ltrim($savedPath, '/');

            if (is_file($absolute)) {
                @unlink($absolute);
            }
        }

        draftJsonResponse(false, $e->getMessage());
    }
}

/* =========================================================
   READ POSTED DATA
========================================================= */

$postedResults = $_POST['result'] ?? [];
$postedMatrices = $_POST['matrix'] ?? [];
$postedAcceptance = $_POST['acceptance_result'] ?? [];
$postedSignatures = $_POST['signatures'] ?? [];
$postedFinalStatus = strtoupper(trim((string)($_POST['final_status'] ?? '')));

if (!is_array($postedResults)) {
    $postedResults = [];
}

if (!is_array($postedMatrices)) {
    $postedMatrices = [];
}

if (!is_array($postedAcceptance)) {
    $postedAcceptance = [];
}

if (!is_array($postedSignatures)) {
    $postedSignatures = [];
}

/* =========================================================
   CONTROLLED SAT REFS
========================================================= */

$fixedRefs = [
    'SAT-001','SAT-002',
    'SAT-003','SAT-004','SAT-005','SAT-006',
    'SAT-007','SAT-008','SAT-009','SAT-010',
    'SAT-011','SAT-012','SAT-013','SAT-014','SAT-015',
    'SAT-016','SAT-017','SAT-018','SAT-019','SAT-020','SAT-021','SAT-022','SAT-023',
    'SAT-024','SAT-025',
    'SAT-026','SAT-027','SAT-028','SAT-029',
    'SAT-030','SAT-031'
];

/* =========================================================
   SAT CHECKPOINT METADATA — SAVED INSPECTOR SNAPSHOT
   ---------------------------------------------------------
   This metadata is written into maintenance_jobs.checklist
   together with the Inspector's actual result/remarks.

   Admin and Owner MUST read this saved snapshot.
   It prevents the review pages from showing:
   - Checkpoint name not recorded
   - missing Priority
   - missing Section

   The IDs, names, priorities and sections below are the
   controlled CHK-02 Rev.1 definitions used by this SAT form.
========================================================= */

$satCheckpointMeta = [
    'SAT-001' => ['checkpoint_name' => 'Load Weighing Device activates correctly', 'priority' => 'P1', 'section' => 'B'],
    'SAT-002' => ['checkpoint_name' => 'Overload indication at rated load', 'priority' => 'P1', 'section' => 'B'],
    'SAT-003' => ['checkpoint_name' => 'Phase L1 → Earth', 'priority' => 'P1', 'section' => 'C'],
    'SAT-004' => ['checkpoint_name' => 'Phase L2 → Earth', 'priority' => 'P1', 'section' => 'C'],
    'SAT-005' => ['checkpoint_name' => 'Phase L3 → Earth', 'priority' => 'P1', 'section' => 'C'],
    'SAT-006' => ['checkpoint_name' => 'Control Circuit → Earth', 'priority' => 'P1', 'section' => 'C'],
    'SAT-007' => ['checkpoint_name' => 'Car OSG Mechanical Operation', 'priority' => 'P1', 'section' => 'D'],
    'SAT-008' => ['checkpoint_name' => 'Counterweight OSG Operation (Hanging Pit Only)', 'priority' => 'P1', 'section' => 'D'],
    'SAT-009' => ['checkpoint_name' => 'Mechanical Tripping Test', 'priority' => 'P1', 'section' => 'D'],
    'SAT-010' => ['checkpoint_name' => 'Electrical Tripping Test', 'priority' => 'P1', 'section' => 'D'],
    'SAT-011' => ['checkpoint_name' => 'Phase Protection Device', 'priority' => 'P1', 'section' => 'E'],
    'SAT-012' => ['checkpoint_name' => 'Over Current Protection', 'priority' => 'P1', 'section' => 'E'],
    'SAT-013' => ['checkpoint_name' => 'Noise Level Evaluation', 'priority' => 'P3', 'section' => 'E'],
    'SAT-014' => ['checkpoint_name' => 'Ride Quality / Vibration', 'priority' => 'P2', 'section' => 'E'],
    'SAT-015' => ['checkpoint_name' => 'Seismic Sensor (If Applicable)', 'priority' => 'P2', 'section' => 'E'],
    'SAT-016' => ['checkpoint_name' => 'CCTV Functional', 'priority' => 'P3', 'section' => 'F'],
    'SAT-017' => ['checkpoint_name' => 'Fan Switch Operation', 'priority' => 'P3', 'section' => 'F'],
    'SAT-018' => ['checkpoint_name' => 'Anti-skid Cabin Floor', 'priority' => 'P3', 'section' => 'F'],
    'SAT-019' => ['checkpoint_name' => 'Cabin Lighting (Minimum 200 Lux)', 'priority' => 'P2', 'section' => 'F'],
    'SAT-020' => ['checkpoint_name' => 'Braille Buttons Functional', 'priority' => 'P3', 'section' => 'F'],
    'SAT-021' => ['checkpoint_name' => 'Voice Announcement System', 'priority' => 'P3', 'section' => 'F'],
    'SAT-022' => ['checkpoint_name' => 'COP Cabinet Lock', 'priority' => 'P2', 'section' => 'F'],
    'SAT-023' => ['checkpoint_name' => 'Emergency Light Operation', 'priority' => 'P1', 'section' => 'F'],
    'SAT-024' => ['checkpoint_name' => 'Manual Rescue System', 'priority' => 'P1', 'section' => 'J'],
    'SAT-025' => ['checkpoint_name' => 'Electrical Rescue System', 'priority' => 'P1', 'section' => 'J'],
    'SAT-026' => ['checkpoint_name' => "Fireman's Operation", 'priority' => 'P1', 'section' => 'K'],
    'SAT-027' => ['checkpoint_name' => 'Fire Mode Travel', 'priority' => 'P1', 'section' => 'K'],
    'SAT-028' => ['checkpoint_name' => 'Fire Alarm Integration', 'priority' => 'P1', 'section' => 'K'],
    'SAT-029' => ['checkpoint_name' => 'Smoke Detector Interface', 'priority' => 'P1', 'section' => 'K'],
    'SAT-030' => ['checkpoint_name' => 'UPS Operation', 'priority' => 'P1', 'section' => 'L'],
    'SAT-031' => ['checkpoint_name' => 'DG Changeover Operation', 'priority' => 'P1', 'section' => 'L'],
];

$satMatrixMeta = [
    'A|up_current' => ['checkpoint_name' => 'UP Current', 'priority' => 'P1', 'section' => 'A'],
    'A|down_current' => ['checkpoint_name' => 'DOWN Current', 'priority' => 'P1', 'section' => 'A'],
    'A|voltage' => ['checkpoint_name' => 'Voltage', 'priority' => 'P1', 'section' => 'A'],
    'A|starting_current' => ['checkpoint_name' => 'Starting Current', 'priority' => 'P1', 'section' => 'A'],
    'A|running_current' => ['checkpoint_name' => 'Running Current', 'priority' => 'P1', 'section' => 'A'],
    'A|leveling_accuracy' => ['checkpoint_name' => 'Leveling Accuracy', 'priority' => 'P1', 'section' => 'A'],
    'E|safety_gear' => ['checkpoint_name' => 'Safety Gear Test', 'priority' => 'P1', 'section' => 'E'],
    'E|buffer_compression' => ['checkpoint_name' => 'Buffer Compression Test', 'priority' => 'P1', 'section' => 'E'],
    'G|working' => ['checkpoint_name' => 'Manual Rescue Operation — Working', 'priority' => 'P1', 'section' => 'G'],
    'H|working' => ['checkpoint_name' => 'Battery Rescue Operation — Working', 'priority' => 'P1', 'section' => 'H'],
    'I|ard' => ['checkpoint_name' => 'Automatic Rescue Device (ARD)', 'priority' => 'P1', 'section' => 'I'],
];

$errors = [];
$cleanResults = [];
$cleanMatrices = [];
$evidenceRows = [];

/* Existing server evidence counts during final validation. */
$preExistingEvidence = loadSavedEvidence($conn, $jobId);

function hasPostedOrSavedEvidence(
    ?array $photoFile,
    ?array $videoFile,
    array $savedEvidence
): bool {
    $photoUploaded =
        is_array($photoFile)
        && (int)($photoFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
        && !empty($photoFile['tmp_name']);

    $videoUploaded =
        is_array($videoFile)
        && (int)($videoFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
        && !empty($videoFile['tmp_name']);

    return $photoUploaded
        || $videoUploaded
        || trim((string)($savedEvidence['photo_path'] ?? '')) !== ''
        || trim((string)($savedEvidence['video_path'] ?? '')) !== '';
}

/*
 * SAT evidence upload limits.
 * Photos are allowed up to 7 MB so the checklist follows the
 * project's increased photo-upload allowance.
 */
const SAT_PHOTO_MAX_BYTES = 50 * 1024 * 1024;
const SAT_VIDEO_MAX_BYTES = 50 * 1024 * 1024;
const SAT_DOCUMENT_MAX_BYTES = 50 * 1024 * 1024;

function uploadHasFile($file): bool
{
    return is_array($file)
        && isset($file['error'])
        && (int)$file['error'] === UPLOAD_ERR_OK
        && !empty($file['tmp_name'])
        && !empty($file['name']);
}

function uploadErrorText($file, string $prefix): string
{
    if (!is_array($file) || !isset($file['error'])) {
        return '';
    }

    $error = (int)$file['error'];

    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        return $prefix . ' could not be uploaded because the PHP upload size limit was exceeded. Please use a file within the allowed size.';
    }

    if ($error !== UPLOAD_ERR_OK && $error !== UPLOAD_ERR_NO_FILE) {
        return $prefix . ' upload failed (PHP upload error ' . $error . ').';
    }

    return '';
}

/* =========================================================
   FILE MAP
========================================================= */

$photoFiles = isset($_FILES['photo']) && is_array($_FILES['photo'])
    ? $_FILES['photo']
    : [];

$videoFiles = isset($_FILES['video']) && is_array($_FILES['video'])
    ? $_FILES['video']
    : [];

$documentFiles = isset($_FILES['document']) && is_array($_FILES['document'])
    ? $_FILES['document']
    : [];

$matrixPhotoFiles = isset($_FILES['matrix_photo']) && is_array($_FILES['matrix_photo'])
    ? $_FILES['matrix_photo']
    : [];

$matrixVideoFiles = isset($_FILES['matrix_video']) && is_array($_FILES['matrix_video'])
    ? $_FILES['matrix_video']
    : [];

/* =========================================================
   VALIDATE FIXED CHECKPOINTS
========================================================= */

foreach ($fixedRefs as $ref) {

    $row = isset($postedResults[$ref]) && is_array($postedResults[$ref])
        ? $postedResults[$ref]
        : [];

    $result = normalizeResult($row['result'] ?? '');

    $remark = cleanText($row['remark'] ?? '', 2000);
    $justification = cleanText($row['technical_justification'] ?? '', 2000);

    if ($result === '') {
        $errors[] = $ref . ': Result is required.';
        continue;
    }

    if ($result === 'NC') {

        /* NC requires Engineering Remark only. Evidence is optional. */
        if ($remark === '') {
            $errors[] = $ref . ': Engineering Remark is required for NC.';
        }

        if (!hasPostedOrSavedEvidence(
            $photoFiles[$ref] ?? null,
            $videoFiles[$ref] ?? null,
            $preExistingEvidence[$ref] ?? []
        )) {
            $errors[] = $ref . ': Photograph OR Video evidence is required for NC.';
        }

        $photoFile = $photoFiles[$ref] ?? null;
        $photoUploadError = uploadErrorText(
            $photoFile,
            $ref . ': Photograph'
        );

        if ($photoUploadError !== '') {
            $errors[] = $photoUploadError;
        }

        $videoFile = $videoFiles[$ref] ?? null;
        $videoUploadError = uploadErrorText($videoFile, $ref . ': Video');

        if ($videoUploadError !== '') {
            $errors[] = $videoUploadError;
        }
    }

    if ($result === 'CA') {

        /* CA requires Conditional Acceptance Remark only. Evidence is optional. */
        if ($justification === '') {
            $errors[] =
                $ref .
                ': Conditional Acceptance Remark is required for CA.';
        }

        if (!hasPostedOrSavedEvidence(
            $photoFiles[$ref] ?? null,
            $videoFiles[$ref] ?? null,
            $preExistingEvidence[$ref] ?? []
        )) {
            $errors[] = $ref . ': Photograph OR Video evidence is required for CA.';
        }

        $photoFile = $photoFiles[$ref] ?? null;

        $photoUploadError = uploadErrorText(
            $photoFile,
            $ref . ': Photograph for CA'
        );

        if ($photoUploadError !== '') {
            $errors[] = $photoUploadError;
        }

        $videoFile = $videoFiles[$ref] ?? null;
        $videoUploadError = uploadErrorText($videoFile, $ref . ': Video for CA');

        if ($videoUploadError !== '') {
            $errors[] = $videoUploadError;
        }
    }

    $meta = $satCheckpointMeta[$ref] ?? [
        'checkpoint_name' => $ref,
        'priority' => '',
        'section' => '',
    ];

    $cleanResults[$ref] = [
        'ref' => $ref,
        'checkpoint_name' => $meta['checkpoint_name'],
        'priority' => $meta['priority'],
        'section' => $meta['section'],
        'result' => $result,
        'remark' => $remark,
        'technical_justification' => $justification,
        'checkpoint_type' => 'fixed',
    ];
}

/* =========================================================
   MATRIX DEFINITIONS
========================================================= */

$loadConditions = ['0%','25%','50%','75%','100%'];

$matrixDefinitions = [
    'A' => [
        'up_current',
        'down_current',
        'voltage',
        'starting_current',
        'running_current',
        'leveling_accuracy'
    ],
    'E' => [
        'safety_gear',
        'buffer_compression'
    ],
    'G' => [
        'working'
    ],
    'H' => [
        'working'
    ],
    'I' => [
        'ard'
    ],
];

/* =========================================================
   VALIDATE MATRIX CELLS
========================================================= */

foreach ($matrixDefinitions as $section => $keys) {

    foreach ($keys as $matrixKey) {

        foreach ($loadConditions as $condition) {

            $row =
                isset($postedMatrices[$section][$matrixKey][$condition])
                && is_array($postedMatrices[$section][$matrixKey][$condition])
                    ? $postedMatrices[$section][$matrixKey][$condition]
                    : [];

            $result = normalizeResult($row['result'] ?? '');

            $actualReading = cleanText($row['actual_reading'] ?? '', 255);
            $remark = cleanText($row['remark'] ?? '', 1000);
            $justification = cleanText($row['technical_justification'] ?? '', 1000);

            $refCode =
                $section
                . '|'
                . $matrixKey
                . '|'
                . $condition;

            if ($result === '') {
                $errors[] =
                    $section
                    . ' / '
                    . $matrixKey
                    . ' / '
                    . $condition
                    . ': Result is required.';
                continue;
            }

            if ($result === 'NC' && $remark === '') {
                $errors[] =
                    $section
                    . ' / '
                    . $matrixKey
                    . ' / '
                    . $condition
                    . ': Engineering Remark is required for NC.';
            }

            if ($result === 'CA' && $justification === '') {
                $errors[] =
                    $section
                    . ' / '
                    . $matrixKey
                    . ' / '
                    . $condition
                    . ': Conditional Acceptance Remark is required for CA.';
            }

            $matrixPhoto =
                $matrixPhotoFiles[$section][$matrixKey][$condition]
                ?? null;

            /*
             * Same PAT rule: NC/CA requires Photograph OR Video.
             * Existing server evidence also satisfies the rule.
             */
            if ($result === 'NC' || $result === 'CA') {

                $matrixSavedEvidence = $preExistingEvidence[$refCode] ?? [];

                if (!hasPostedOrSavedEvidence(
                    $matrixPhoto,
                    $matrixVideoFiles[$section][$matrixKey][$condition] ?? null,
                    $matrixSavedEvidence
                )) {
                    $errors[] =
                        $section . ' / ' . $matrixKey . ' / ' . $condition
                        . ': Photograph OR Video evidence is required for '
                        . $result . '.';
                }

                $matrixUploadError = uploadErrorText(
                    $matrixPhoto,
                    $section
                    . ' / '
                    . $matrixKey
                    . ' / '
                    . $condition
                    . ': Photograph for '
                    . $result
                );

                if ($matrixUploadError !== '') {
                    $errors[] = $matrixUploadError;
                }

                $matrixVideo = $matrixVideoFiles[$section][$matrixKey][$condition] ?? null;
                $matrixVideoUploadError = uploadErrorText(
                    $matrixVideo,
                    $section . ' / ' . $matrixKey . ' / ' . $condition . ': Video'
                );

                if ($matrixVideoUploadError !== '') {
                    $errors[] = $matrixVideoUploadError;
                }
            }

            $matrixMeta = $satMatrixMeta[$section . '|' . $matrixKey] ?? [
                'checkpoint_name' => $matrixKey,
                'priority' => '',
                'section' => $section,
            ];

            $cleanMatrices[$refCode] = [
                'ref' => $refCode,
                'checkpoint_name' => $matrixMeta['checkpoint_name'],
                'priority' => $matrixMeta['priority'],
                'section' => $matrixMeta['section'],
                'matrix_key' => $matrixKey,
                'load_condition' => $condition,
                'result' => $result,
                'actual_reading' => $actualReading,
                'remark' => $remark,
                'technical_justification' => $justification,
                'checkpoint_type' => 'matrix',
            ];
        }
    }
}

/* =========================================================
   SAT ACCEPTANCE RESULT
========================================================= */

$acceptance = [];

foreach (
    [
        'functional_test',
        'load_test',
        'rescue_test',
        'electrical_test'
    ] as $key
) {

    $value = strtoupper(trim((string)($postedAcceptance[$key] ?? '')));

    if (!in_array($value, ['PASS','FAIL'], true)) {
        $errors[] =
            'SAT Acceptance Result: '
            . ucwords(str_replace('_', ' ', $key))
            . ' is required.';
    }

    $acceptance[$key] = $value;
}

$overallSat =
    strtoupper(
        trim(
            (string)(
                $postedAcceptance['overall_sat']
                ?? ''
            )
        )
    );

if (!in_array($overallSat, ['SATISFACTORY','UNSATISFACTORY'], true)) {
    $errors[] = 'Overall SAT result is required.';
}

$acceptance['overall_sat'] = $overallSat;

if (!$isAutoDraft && !in_array($postedFinalStatus, ['PASS', 'CONDITIONAL PASS', 'FAIL'], true)) {
    $errors[] = 'Final Inspection Status is required.';
}

/* =========================================================
   SIGNATURES
========================================================= */

$signatures = [
    'inspector' => cleanText($postedSignatures['inspector'] ?? '', 255),
    'owner' => cleanText($postedSignatures['owner'] ?? '', 255),
    'amc' => cleanText($postedSignatures['amc'] ?? '', 255),
];

/* Final submission is strict. Auto-draft intentionally accepts
 * incomplete checkpoints / matrices / acceptance results. */
if (!$isAutoDraft && $errors) {
    failBack(
        implode("\n", array_slice($errors, 0, 30)),
        $jobId,
        $actualAssignmentId,
        $actualAssetId
    );
}

/* =========================================================
   PROCESS UPLOADS
========================================================= */

/*
 * Tracks newly uploaded files so they can be removed if the transaction fails.
 * Declared outside the final-submit block because the outer error handlers
 * also reference it. Auto-draft leaves this array empty.
 */
$uploadedFiles = [];

/*
 * IMPORTANT:
 * Auto-draft NEVER processes $_FILES.
 * Evidence is uploaded only during the real final submit.
 */

if (!$isAutoDraft) {

    try {

        foreach ($cleanResults as $ref => &$row) {

            $row['photo'] = '';
            $row['video'] = '';
            $row['document'] = '';

            if (isset($photoFiles[$ref])) {
                $row['photo'] = saveUploadedFile(
                    $photoFiles[$ref],
                    $ref . '_photo',
                    $jobId,
                    [
                        'image/jpeg' => 'jpg',
                        'image/png' => 'png',
                        'image/webp' => 'webp',
                    ],
                    SAT_PHOTO_MAX_BYTES
                );

                if ($row['photo'] !== '') {
                    $uploadedFiles[] = dirname(__DIR__) . '/' . $row['photo'];
                }
            }

            if (isset($videoFiles[$ref])) {
                $row['video'] = saveUploadedFile(
                    $videoFiles[$ref],
                    $ref . '_video',
                    $jobId,
                    [
                        'video/mp4' => 'mp4',
                        'video/webm' => 'webm',
                        'video/quicktime' => 'mov',
                        'video/x-msvideo' => 'avi',
                    ],
                    SAT_VIDEO_MAX_BYTES
                );

                if ($row['video'] !== '') {
                    $uploadedFiles[] = dirname(__DIR__) . '/' . $row['video'];
                }
            }

            if (isset($documentFiles[$ref])) {
                $row['document'] = saveUploadedFile(
                    $documentFiles[$ref],
                    $ref . '_document',
                    $jobId,
                    [
                        'application/pdf' => 'pdf',
                        'image/jpeg' => 'jpg',
                        'image/png' => 'png',
                        'image/webp' => 'webp',
                        'application/msword' => 'doc',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                    ],
                    SAT_DOCUMENT_MAX_BYTES
                );

                if ($row['document'] !== '') {
                    $uploadedFiles[] = dirname(__DIR__) . '/' . $row['document'];
                }
            }
        }

        unset($row);

        foreach ($cleanMatrices as $refCode => &$row) {

            $row['photo'] = '';
            $row['video'] = '';
            $row['document'] = '';

            $section = $row['section'];
            $matrixKey = $row['matrix_key'];
            $condition = $row['load_condition'];

            $matrixPhoto =
                $matrixPhotoFiles[$section][$matrixKey][$condition]
                ?? null;

            $matrixVideo =
                $matrixVideoFiles[$section][$matrixKey][$condition]
                ?? null;

            if ($matrixPhoto) {
                $row['photo'] = saveUploadedFile(
                    $matrixPhoto,
                    $refCode . '_photo',
                    $jobId,
                    [
                        'image/jpeg' => 'jpg',
                        'image/png' => 'png',
                        'image/webp' => 'webp',
                    ],
                    SAT_PHOTO_MAX_BYTES
                );

                if ($row['photo'] !== '') {
                    $uploadedFiles[] =
                        dirname(__DIR__) . '/' . $row['photo'];
                }
            }

            /* Matrix video is optional and uploads only on Final Submit. */
            if ($matrixVideo) {
                $row['video'] = saveUploadedFile(
                    $matrixVideo,
                    $refCode . '_video',
                    $jobId,
                    [
                        'video/mp4' => 'mp4',
                        'video/webm' => 'webm',
                        'video/quicktime' => 'mov',
                        'video/x-msvideo' => 'avi',
                    ],
                    SAT_VIDEO_MAX_BYTES
                );

                if ($row['video'] !== '') {
                    $uploadedFiles[] =
                        dirname(__DIR__) . '/' . $row['video'];
                }
            }
        }

        unset($row);

    } catch (Throwable $e) {

        foreach ($uploadedFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        if ($isAutoDraft) {
            draftJsonResponse(false, $e->getMessage());
        }

        failBack(
            $e->getMessage(),
            $jobId,
            $actualAssignmentId,
            $actualAssetId
        );
    }

}

/* =========================================================
   READ PREVIOUSLY SAVED CHECKLIST FOR EVIDENCE FALLBACK
========================================================= */
$existingSavedChecklist = json_decode(
    (string)($job['saved_checklist'] ?? ''),
    true
);

if (!is_array($existingSavedChecklist)) {
    $existingSavedChecklist = [];
}

/* =========================================================
   PRESERVE EXISTING EVIDENCE WHEN NO NEW FILE IS UPLOADED
   ---------------------------------------------------------
   Evidence is resolved from BOTH:
   1) saf_checklist_evidence
   2) the previously saved maintenance_jobs.checklist JSON

   This is intentionally PAT-style. If one source is missing or
   has a blank path, the other source is used. Existing evidence
   is NEVER lost merely because the DB index row is incomplete.
========================================================= */

$existingEvidence = [];

$existingEvidenceStmt = $conn->prepare("
    SELECT
        ref_code,
        photo_path,
        video_path,
        document_path
    FROM saf_checklist_evidence
    WHERE job_id = ?
");

if ($existingEvidenceStmt) {

    $existingEvidenceStmt->bind_param('i', $jobId);
    $existingEvidenceStmt->execute();

    $existingEvidenceResult = $existingEvidenceStmt->get_result();

    while ($eRow = $existingEvidenceResult->fetch_assoc()) {
        $existingEvidence[(string)$eRow['ref_code']] = $eRow;
    }

    $existingEvidenceStmt->close();
}

foreach ($cleanResults as $ref => &$row) {

    $old = $existingEvidence[$ref] ?? [];

    if ($row['photo'] === '') {
        $row['photo'] = (string)($old['photo_path'] ?? '');
    }

    if ($row['video'] === '') {
        $row['video'] = (string)($old['video_path'] ?? '');
    }

    if ($row['document'] === '') {
        $row['document'] = (string)($old['document_path'] ?? '');
    }

    $row['evidence'] = [
        'photo' => (string)$row['photo'],
        'video' => (string)$row['video'],
        'document' => (string)$row['document'],
    ];
}

unset($row);

foreach ($cleanMatrices as $ref => &$row) {

    $old = $existingEvidence[$ref] ?? [];

    if ($row['photo'] === '') {
        $row['photo'] = (string)($old['photo_path'] ?? '');
    }

    if ($row['video'] === '') {
        $row['video'] = (string)($old['video_path'] ?? '');
    }

    if ($row['document'] === '') {
        $row['document'] = (string)($old['document_path'] ?? '');
    }

    $row['evidence'] = [
        'photo' => (string)$row['photo'],
        'video' => (string)$row['video'],
        'document' => (string)$row['document'],
    ];
}

unset($row);

/* ---------------------------------------------------------
 * Final evidence normalization.
 * Keep stored paths relative to project root, exactly as the
 * Owner/Admin viewers expect: uploads/inspections/...
 * --------------------------------------------------------- */
foreach ($cleanResults as &$row) {
    foreach (['photo','video','document'] as $field) {
        $row[$field] = ltrim(str_replace('\\', '/', trim((string)($row[$field] ?? ''))), '/');
    }

    $row['evidence'] = [
        'photo' => $row['photo'],
        'video' => $row['video'],
        'document' => $row['document'],
    ];
}
unset($row);

foreach ($cleanMatrices as &$row) {
    foreach (['photo','video','document'] as $field) {
        $row[$field] = ltrim(str_replace('\\', '/', trim((string)($row[$field] ?? ''))), '/');
    }

    $row['evidence'] = [
        'photo' => $row['photo'],
        'video' => $row['video'],
        'document' => $row['document'],
    ];
}
unset($row);

/* =========================================================
   BUILD EVIDENCE ROWS
========================================================= */

foreach ($cleanResults as $ref => $row) {

    if (
        $row['photo'] !== ''
        || $row['video'] !== ''
        || $row['document'] !== ''
    ) {
        $evidenceRows[] = [
            'ref' => $ref,
            'result' => $row['result'],
            'photo' => $row['photo'],
            'video' => $row['video'],
            'document' => $row['document'],
        ];
    }
}

foreach ($cleanMatrices as $ref => $row) {

    if (
        $row['photo'] !== ''
        || $row['video'] !== ''
        || $row['document'] !== ''
    ) {
        $evidenceRows[] = [
            'ref' => $ref,
            'result' => $row['result'],
            'photo' => $row['photo'],
            'video' => $row['video'],
            'document' => $row['document'],
        ];
    }
}

/* =========================================================
   EXISTING FINAL STATUS
   ---------------------------------------------------------
   Auto-draft must never turn an already submitted SAT result
   into PASS/FAIL just because the draft is incomplete.
========================================================= */
$savedFinalStatus = '';
if (is_array($existingSavedChecklist)) {
    $savedFinalStatus = trim((string)($existingSavedChecklist['final_status'] ?? ''));
}

/* =========================================================
   BUILD CHECKLIST JSON
========================================================= */

$checklistData = [
    'module' => 'SAT',
    'checklist_code' => 'CHK-02',
    'revision' => 'Rev.1',
    'document_no' => 'CHK-02',
    'service_code' => 'SAT',
    'service_name' => 'Satisfactory Acceptance Test',
    'metadata_version' => 'SAT-MASTER-CHK-02-Rev.1-SAVED-ROW-METADATA-EVIDENCE',
    'job_id' => $jobId,
    'assignment_id' => $actualAssignmentId,
    'saf_id' => $actualSafId,
    'asset_id' => $actualAssetId,

    'checkpoint_metadata' => array_merge(
        $satCheckpointMeta,
        array_map(
            static function (array $meta): array {
                return $meta;
            },
            $satMatrixMeta
        )
    ),

    /*
     * Evidence is stored in the same way as PAT:
     * - physical file under uploads/inspections/{jobId}/
     * - flat photo/video/document fields for compatibility
     * - nested evidence[] snapshot for Admin/Owner readers
     * - saf_checklist_evidence database row
     */
    'results' => $cleanResults,
    'matrices' => $cleanMatrices,

    'acceptance_result' => $acceptance,

    'signatures' => $signatures,

    'cross_lock' => [
        'source' => 'PAT',
        'mode' => 'READ_ONLY',
        'items' => [
            'Car Top Controls',
            'Landing Door Lock',
            'Landing Push Buttons',
            'Hall Display',
            'Limit Switches',
            'Inspection Switch',
            'Alarm',
            'Intercom',
            'Auto Attendant',
            'Running Clearance',
        ],
    ],

    /*
     * Explicit Final Inspection Status from the SAT form.
     * If an auto-draft does not yet have a selected value, preserve
     * the previously saved final status instead of overwriting it.
     */
    'final_status' =>
        $postedFinalStatus !== ''
            ? $postedFinalStatus
            : (string)($savedFinalStatus ?? ''),

    'overall_sat' => $overallSat,

    'draft' => $isAutoDraft,
    'save_mode' => $isAutoDraft ? 'auto_draft' : 'final_submit',

    'standard_clause' =>
        cleanText($_POST['standard_clause'] ?? '', 255),

    'actual_reading_mode' => 'Numeric',

    'acceptance_limit_mode' => 'Controlled / Master Checklist',

    'cross_lock_source' => 'PAT / SAT',

    'closure_method' => 'Functional Retest',

    'saved_at_ist' => date('Y-m-d H:i:s'),
];

/* =========================================================
   JSON
========================================================= */

$json = json_encode(
    $checklistData,
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES |
    JSON_PRETTY_PRINT
);

if ($json === false) {

    foreach ($uploadedFiles as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }

    if ($isAutoDraft) {
        draftJsonResponse(false, 'Unable to prepare SAT draft data.');
    }

    failBack(
        'Unable to prepare SAT checklist data.',
        $jobId,
        $actualAssignmentId,
        $actualAssetId
    );
}

/* =========================================================
   DATABASE TRANSACTION
========================================================= */

$conn->begin_transaction();

try {

    /*
     * Save only this Asset's SAT checklist.
     * Do NOT change all jobs for the SAF here.
     *
     * Complete SAF submission remains on inspections.php.
     */
    $update = $conn->prepare("
        UPDATE maintenance_jobs
        SET
            checklist = ?,
            updated_at = NOW()
        WHERE
            id = ?
            AND owner_id = ?
            AND saf_id = ?
            AND asset_id = ?
            AND job_type = 'saf_inspection'
        LIMIT 1
    ");

    if (!$update) {
        throw new RuntimeException(
            'Unable to prepare SAT checklist save: ' . $conn->error
        );
    }

    $ownerId = (int)$job['owner_id'];

    $update->bind_param(
        'siiii',
        $json,
        $jobId,
        $ownerId,
        $actualSafId,
        $actualAssetId
    );

    if (!$update->execute()) {
        throw new RuntimeException(
            'Unable to save SAT checklist: ' . $update->error
        );
    }

    if ($update->affected_rows < 0) {
        throw new RuntimeException(
            'SAT checklist could not be saved.'
        );
    }

    $update->close();

    /*
     * Evidence database rows are saved exactly like PAT:
     * - Final asset save uploads/merges evidence and rebuilds the
     *   saf_checklist_evidence rows for this job.
     * - Auto-draft never receives/processes files, so it leaves
     *   existing evidence rows untouched.
     */ 
    if (!$isAutoDraft) {

        /*
         * Replace SAT evidence rows for this job.
         * Original PAT evidence is stored under the PAT job and
         * is therefore untouched.
         */
        $deleteEvidence = $conn->prepare("
            DELETE FROM saf_checklist_evidence
            WHERE job_id = ?
        ");

        if (!$deleteEvidence) {
            throw new RuntimeException(
                'Unable to prepare SAT evidence cleanup: ' . $conn->error
            );
        }

        $deleteEvidence->bind_param('i', $jobId);

        if (!$deleteEvidence->execute()) {
            throw new RuntimeException(
                'Unable to clear previous SAT evidence: '
                . $deleteEvidence->error
            );
        }

        $deleteEvidence->close();

        /*
         * The project already uses this evidence table.
         * Use only the columns confirmed by the existing save flow.
         */
        $insertEvidence = $conn->prepare("
            INSERT INTO saf_checklist_evidence
            (
                job_id,
                ref_code,
                result,
                photo_path,
                video_path,
                document_path
            )
            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                ?,
                ?
            )
        ");

        if (!$insertEvidence) {
            throw new RuntimeException(
                'Unable to prepare SAT evidence save: ' . $conn->error
            );
        }

        foreach ($evidenceRows as $evidence) {

            $ref = (string)$evidence['ref'];
            $result = (string)$evidence['result'];
            $photo = (string)$evidence['photo'];
            $video = (string)$evidence['video'];
            $document = (string)$evidence['document'];

            $insertEvidence->bind_param(
                'isssss',
                $jobId,
                $ref,
                $result,
                $photo,
                $video,
                $document
            );

            if (!$insertEvidence->execute()) {
                throw new RuntimeException(
                    'Unable to save SAT evidence for '
                    . $ref
                    . ': '
                    . $insertEvidence->error
                );
            }
        }

        $insertEvidence->close();

    }

    /*
     * Do not mark the entire SAF pending_owner here.
     * This is an individual Asset checklist save.
     */
    $conn->commit();

} catch (Throwable $e) {

    try {
        $conn->rollback();
    } catch (Throwable $ignore) {
    }

    foreach ($uploadedFiles as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }

    if ($isAutoDraft) {
        draftJsonResponse(false, 'SAT draft save failed: ' . $e->getMessage());
    }

    failBack(
        $e->getMessage(),
        $jobId,
        $actualAssignmentId,
        $actualAssetId
    );
}

/* =========================================================
   SUCCESS
========================================================= */

if ($isAutoDraft) {
    draftJsonResponse(
        true,
        'SAT draft saved automatically.',
        [
            'job_id' => $jobId,
            'saved_at_ist' => date('Y-m-d H:i:s'),
            'save_mode' => 'auto_draft',
        ]
    );
}

$_SESSION['success'] =
    'CHK-02 Rev.1 SAT checklist saved successfully for Asset #'
    . $actualAssetId
    . '.';

$_SESSION['clear_sat_draft_job'] = $jobId;

header(
    'Location: inspections.php?sat_saved=1'
);

exit;
?>