<?php
/* =========================================================
   NLETA INSPECTOR — SAVE PAT CHECKLIST (DEDICATED ENDPOINT)
   CHK-01 — Rev.1
   MASTER: PAT-001 -> PAT-129
   Result Codes: C / NC / NA / CA
   D2: Dynamic Per-Floor Landing Door Checklist

   RULES
   -----
   C  = Complied
   NC = Non-Complied
        • Photograph OR Video REQUIRED
        • Engineering Remark REQUIRED
   NA = Not Applicable
   CA = Conditional Acceptance
        • Photograph OR Video REQUIRED
        • Technical Justification REQUIRED

   IMPORTANT
   ----------
   • Original PAT results are saved under results[]
   • Dynamic floor results are saved under floor_checklist[]
   • Floor rows do NOT create PAT-132+ IDs
   • Existing recheck data is preserved
   • Site-attendance OTP verification is enforced server-side
   • All timestamps are IST
   • Photo/video MIME and extension whitelist is NOT enforced
   • Any non-executable photo/video file format is accepted
   • 50 MB application upload limit remains
   • Server-side executable extensions remain blocked for security
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

function failBack(string $message, int $jobId, int $assignmentId = 0): void
{
    /*
     * IMPORTANT:
     * The previous endpoint redirected every validation/database
     * problem back to the checklist. If the checklist page did not
     * render $_SESSION['error'], the Inspector saw no error and it
     * looked like Save simply did nothing.
     *
     * Show the exact server-side reason here so the real problem is
     * visible instead of silently failing.
     */
    $_SESSION['error'] = $message;

    $safeMessage = nl2br(
        htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
    );

    $backUrl = 'saf-checklist.php?job_id=' . (int)$jobId;

    if ($assignmentId > 0) {
        $backUrl .= '&assignment_id=' . (int)$assignmentId;
    }

    http_response_code(422);

    echo '<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>NLETA PAT Save Error</title>
<style>
body{margin:0;background:#f4f6f9;font-family:Arial,sans-serif;color:#172033}
.wrap{max-width:900px;margin:50px auto;padding:20px}
.card{background:#fff;border:1px solid #e1e5e9;border-radius:14px;padding:25px;box-shadow:0 5px 20px rgba(0,0,0,.07)}
h2{margin:0 0 10px;color:#b02a37}
.msg{white-space:normal;background:#fff5f5;border:1px solid #f1b7bd;border-radius:10px;padding:15px;line-height:1.6}
.actions{margin-top:18px}
a{display:inline-block;background:#0d6efd;color:#fff;text-decoration:none;padding:10px 16px;border-radius:8px;font-weight:700}
.small{margin-top:12px;color:#6c757d;font-size:12px}
</style>
</head>
<body>
<div class="wrap">
<div class="card">
<h2>PAT Checklist Save Failed</h2>
<div class="msg">' . $safeMessage . '</div>
<div class="actions"><a href="' . htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') . '">← Return to PAT Checklist</a></div>
<div class="small">No database completion status was changed because the save validation failed.</div>
</div>
</div>
</body>
</html>';
    exit;
}

function normalizeFloorLabel(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    return preg_replace('/\s+/', ' ', $value);
}

function parseServedFloors(array $job): array
{
    $floors = [];

    $served = trim((string)($job['served_floors'] ?? ''));

    if ($served !== '') {
        $parts = preg_split('/[,;|]+/', $served);

        foreach ($parts as $part) {
            $floor = normalizeFloorLabel((string)$part);

            if ($floor !== '' && !in_array($floor, $floors, true)) {
                $floors[] = $floor;
            }
        }
    }

    if (!$floors) {
        $count = (int)($job['number_of_floors'] ?? 0);

        if ($count > 0) {
            $floors[] = 'B';
            $floors[] = 'G';

            for ($i = 1; $i <= $count; $i++) {
                $floors[] = (string)$i;
            }

            $floors[] = 'Top Floor';
        }
    }

    /*
     * Do not invent a large floor list when the database has no
     * floor configuration. Keep one Ground floor row as fallback.
     */
    if (!$floors) {
        $floors[] = 'G';
    }

    return $floors;
}

function validateCsrfIfAvailable(): void
{
    if (!function_exists('validateCSRFToken')) {
        return;
    }

    $token = (string)($_POST['csrf_token'] ?? '');

    if (!validateCSRFToken($token)) {
        throw new Exception('Invalid security token. Please refresh and submit again.');
    }
}


/*
 * Verify that the selected file belongs to the evidence field used.
 * We intentionally support a broad set of phone/browser formats, while
 * still preventing a PHOTO field from accepting a video and vice versa.
 */
function validateEvidenceFileType(
    string $type,
    string $originalName,
    string $mime
): void {
    $type = strtolower(trim($type));
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $mime = strtolower(trim($mime));

    $photoExtensions = [
        'jpg','jpeg','jpe','png','webp','heic','heif','avif','gif',
        'bmp','tif','tiff','svg','ico','jfif','raw','dng','cr2','cr3',
        'nef','nrw','arw','orf','rw2','raf','pef','srw','3fr','iiq'
    ];

    $videoExtensions = [
        'mp4','m4v','mov','avi','mkv','webm','3gp','3g2','wmv','flv',
        'mpeg','mpg','mpe','mts','m2ts','ts','vob','ogv','ogg','asf',
        'rm','rmvb','dv','f4v'
    ];

    if ($type === 'photo') {
        $isValid = ($mime !== '' && str_starts_with($mime, 'image/'))
            || in_array($ext, $photoExtensions, true);

        if (!$isValid) {
            throw new Exception(
                'Photograph field accepts image/photo files only.'
            );
        }

        return;
    }

    if ($type === 'video') {
        $isValid = ($mime !== '' && str_starts_with($mime, 'video/'))
            || in_array($ext, $videoExtensions, true);

        if (!$isValid) {
            throw new Exception(
                'Video field accepts video files only.'
            );
        }

        return;
    }

    throw new Exception('Invalid evidence type.');
}

function uploadEvidence(
    string $type,
    string $tmp,
    string $originalName,
    int $size,
    int $jobId,
    string $reference,
    string $mime = ''
): string {
    if (!is_uploaded_file($tmp)) {
        throw new Exception(
            $reference . ': Invalid ' . ucfirst($type) . ' upload.'
        );
    }

    /*
     * =========================================================
     * ANY PHOTO / VIDEO FORMAT
     * =========================================================
     *
     * No MIME whitelist and no photo/video extension whitelist.
     * This allows phone/browser formats such as:
     * JPG, JPEG, PNG, WEBP, HEIC, HEIF, AVIF, GIF, BMP,
     * MP4, MOV, AVI, MKV, WEBM, M4V, 3GP, etc.
     *
     * Security:
     * Executable/server-side script extensions are NOT allowed.
     * This is necessary because the uploads directory is web-accessible.
     * Allowing .php/.phtml/.cgi/.jsp etc. could create a server-execution
     * vulnerability.
     *
     * Application size limit remains 50 MB.
     */

    $max = 50 * 1024 * 1024;

    if ($size <= 0) {
        throw new Exception(
            $reference . ': Empty ' . ucfirst($type) . ' file.'
        );
    }

    if ($size > $max) {
        throw new Exception(
            $reference . ': ' . ucfirst($type) .
            ' exceeds the application limit of ' .
            ($max / 1024 / 1024) . ' MB.'
        );
    }

    $originalName = trim((string)$originalName);

    validateEvidenceFileType($type, $originalName, $mime);

    /*
     * Keep the original extension when available.
     * If the phone/browser sends a file without an extension,
     * use a harmless generic extension.
     */
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if ($ext === '') {
        $ext = ($type === 'photo') ? 'photo' : 'video';
    }

    /*
     * Never allow executable/server-side extensions inside the
     * public uploads directory.
     */
    $blockedExtensions = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8',
        'phtml', 'pht', 'phar',
        'cgi', 'pl', 'py', 'pyc',
        'sh', 'bash', 'bin',
        'exe', 'com', 'bat', 'cmd',
        'asp', 'aspx',
        'jsp', 'jspx',
        'shtml',
        'htaccess', 'htpasswd'
    ];

    if (in_array($ext, $blockedExtensions, true)) {
        throw new Exception(
            $reference .
            ': This file extension is not allowed for security reasons.'
        );
    }

    /*
     * Normalize the extension so a malicious filename such as
     * "photo.PHp" cannot bypass the blocked-extension check.
     */
    $ext = preg_replace('/[^a-z0-9]+/i', '', $ext);

    if ($ext === '') {
        $ext = ($type === 'photo') ? 'photo' : 'video';
    }

    $safeReference = preg_replace(
        '/[^A-Za-z0-9_-]/',
        '_',
        $reference
    );

    /*
     * =========================================================
     * CANONICAL PUBLIC UPLOAD LOCATION
     * =========================================================
     *
     * /htdocs/uploads/inspections/{JOB_ID}/
     */
    $projectRoot = realpath(__DIR__ . '/..');

    if ($projectRoot === false || !is_dir($projectRoot)) {
        throw new Exception(
            $reference . ': Unable to determine NLETA project root.'
        );
    }

    $uploadsRoot =
        $projectRoot .
        DIRECTORY_SEPARATOR .
        'uploads';

    $inspectionsRoot =
        $uploadsRoot .
        DIRECTORY_SEPARATOR .
        'inspections';

    $folder =
        $inspectionsRoot .
        DIRECTORY_SEPARATOR .
        (string)$jobId;

    if (!is_dir($uploadsRoot)) {
        if (
            !mkdir($uploadsRoot, 0775, true) &&
            !is_dir($uploadsRoot)
        ) {
            throw new Exception(
                $reference . ': Unable to create /uploads directory.'
            );
        }
    }

    if (!is_dir($inspectionsRoot)) {
        if (
            !mkdir($inspectionsRoot, 0775, true) &&
            !is_dir($inspectionsRoot)
        ) {
            throw new Exception(
                $reference .
                ': Unable to create /uploads/inspections directory.'
            );
        }
    }

    if (!is_dir($folder)) {
        if (
            !mkdir($folder, 0775, true) &&
            !is_dir($folder)
        ) {
            throw new Exception(
                $reference .
                ': Unable to create evidence folder: ' .
                $folder
            );
        }
    }

    if (!is_writable($folder)) {
        throw new Exception(
            $reference .
            ': Evidence folder is not writable: ' .
            $folder
        );
    }

    $filename =
        $safeReference . '_' .
        $type . '_' .
        date('Ymd_His') . '_' .
        bin2hex(random_bytes(6)) .
        '.' .
        $ext;

    $destination =
        $folder .
        DIRECTORY_SEPARATOR .
        $filename;

    if (!move_uploaded_file($tmp, $destination)) {
        $lastError = error_get_last();

        throw new Exception(
            $reference .
            ': Unable to save ' .
            $type .
            ' to canonical upload location. ' .
            (!empty($lastError['message'])
                ? $lastError['message']
                : 'move_uploaded_file() failed.')
        );
    }

    if (!is_file($destination) || filesize($destination) <= 0) {
        throw new Exception(
            $reference .
            ': Evidence was not physically created at: ' .
            $destination
        );
    }

    /*
     * DB/web-root-relative path.
     * Existing Owner/Client/Admin evidence views can continue
     * using this path without any schema change.
     */
    return
        'uploads/inspections/' .
        rawurlencode((string)$jobId) .
        '/' .
        rawurlencode($filename);
}


/*
 * Persist one immediately uploaded evidence file directly into
 * saf_checklist_evidence. This makes the evidence survive refresh,
 * Back, browser navigation and even a later failed checklist save.
 *
 * No schema change is required. We locate the current row by
 * job_id + ref_code and update/insert only the selected evidence column.
 */
function persistImmediateEvidence(
    mysqli $conn,
    int $jobId,
    string $reference,
    string $type,
    string $path,
    string $result
): void {
    $type = strtolower(trim($type));
    $reference = trim($reference);
    $path = trim($path);
    $result = strtoupper(trim($result));

    if (!in_array($type, ['photo', 'video'], true)) {
        throw new Exception('Invalid evidence type.');
    }

    if (!in_array($result, ['NC', 'CA'], true)) {
        throw new Exception(
            $reference . ': Evidence can only be uploaded for NC or CA.'
        );
    }

    if ($path === '') {
        throw new Exception($reference . ': Empty evidence path.');
    }

    /*
     * ref_code is unique per job (uq_job_ref). Use one atomic UPSERT
     * instead of SELECT + INSERT. This removes the duplicate-key race
     * that was causing errors such as:
     * "Duplicate entry '276-FLOOR-3-landing_door_manual_lo'".
     *
     * Only the selected evidence column is replaced. The other column
     * is preserved, so Photo and Video can both coexist for NC/CA.
     */
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
        throw new Exception(
            'Unable to prepare immediate evidence UPSERT: ' . $conn->error
        );
    }

    $stmt->bind_param(
        'isss',
        $jobId,
        $reference,
        $result,
        $path
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();

        throw new Exception(
            'Unable to save immediate evidence: ' . $error
        );
    }

    $stmt->close();
}

/*
 * Read all evidence already stored for this asset.
 * Used to merge immediately-uploaded evidence back into the
 * checklist JSON before a later full checklist save.
 */
function loadSavedEvidence(mysqli $conn, int $jobId): array
{
    $saved = [];

    $tableCheck = $conn->query("
        SHOW TABLES LIKE 'saf_checklist_evidence'
    ");

    if (!$tableCheck || $tableCheck->num_rows === 0) {
        return $saved;
    }

    $stmt = $conn->prepare("
        SELECT
            ref_code,
            photo_path,
            video_path,
            document_path
        FROM saf_checklist_evidence
        WHERE job_id = ?
        ORDER BY id ASC
    ");

    if (!$stmt) {
        return $saved;
    }

    $stmt->bind_param('i', $jobId);

    if ($stmt->execute()) {
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $ref = trim((string)($row['ref_code'] ?? ''));

            if ($ref === '') {
                continue;
            }

            if (!isset($saved[$ref])) {
                $saved[$ref] = [
                    'photo' => '',
                    'video' => '',
                    'document' => ''
                ];
            }

            foreach (['photo', 'video', 'document'] as $type) {
                $path = trim((string)($row[$type . '_path'] ?? ''));

                if ($path !== '') {
                    $saved[$ref][$type] = $path;
                }
            }
        }
    }

    $stmt->close();

    return $saved;
}

/*
 * Return the PHP upload error for a fixed PAT evidence field.
 */

function uploadErrorMessage(int $error): string
{
    switch ($error) {
        case UPLOAD_ERR_OK:
            return 'Upload successful.';
        case UPLOAD_ERR_INI_SIZE:
            return 'Video/photo is larger than PHP upload_max_filesize (' . (ini_get('upload_max_filesize') ?: 'unknown') . ').';
        case UPLOAD_ERR_FORM_SIZE:
            return 'Video/photo is larger than the form upload limit.';
        case UPLOAD_ERR_PARTIAL:
            return 'The video/photo upload was interrupted or only partially uploaded.';
        case UPLOAD_ERR_NO_FILE:
            return 'No file was uploaded.';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'Server temporary upload folder is missing.';
        case UPLOAD_ERR_CANT_WRITE:
            return 'Server could not write the uploaded file.';
        case UPLOAD_ERR_EXTENSION:
            return 'A PHP extension stopped the upload.';
        default:
            return 'Unknown PHP upload error (code ' . $error . ').';
    }
}

function validateEvidenceUploadError(int $error, string $reference, string $type): void
{
    if ($error === UPLOAD_ERR_NO_FILE || $error === UPLOAD_ERR_OK) {
        return;
    }

    throw new Exception(
        $reference . ': ' . ucfirst($type) . ' upload failed. ' .
        uploadErrorMessage($error)
    );
}

function jsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/*
 * Evidence uploaded by the one-file-at-a-time uploader is stored
 * directly in the normal job evidence folder.  Before accepting a
 * manifest path, make sure it belongs to this job and is a real file.
 */
function validatePreUploadedEvidencePath(
    string $path,
    int $jobId,
    string $reference,
    string $type
): string {
    $path = trim($path);

    if ($path === '') {
        return '';
    }

    $prefix = 'uploads/inspections/' . $jobId . '/';

    if (strncmp($path, $prefix, strlen($prefix)) !== 0) {
        throw new Exception(
            $reference . ': Invalid pre-uploaded ' . $type . ' path.'
        );
    }

    $relative = ltrim(str_replace('\\', '/', $path), '/');
    $physical = realpath(__DIR__ . '/../' . $relative);
    $uploadRoot = realpath(__DIR__ . '/../uploads/inspections/' . $jobId);

    if ($physical === false || $uploadRoot === false) {
        throw new Exception(
            $reference . ': Pre-uploaded ' . $type . ' file was not found.'
        );
    }

    $uploadRootWithSlash = rtrim($uploadRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    if (
        strncmp(
            $physical,
            $uploadRootWithSlash,
            strlen($uploadRootWithSlash)
        ) !== 0
    ) {
        throw new Exception(
            $reference . ': Invalid pre-uploaded ' . $type . ' location.'
        );
    }

    if (!is_file($physical) || filesize($physical) <= 0) {
        throw new Exception(
            $reference . ': Pre-uploaded ' . $type . ' file is empty or unavailable.'
        );
    }

    return $path;
}

function fixedUploadError(string $type, string $ref): int
{
    return (int)(
        $_FILES[$type]['error'][$ref]
        ?? UPLOAD_ERR_NO_FILE
    );
}

/*
 * Return the PHP upload error for a floor evidence field.
 */
function floorUploadError(
    string $type,
    string $floorLabel,
    string $floorKey
): int {
    $fieldName = 'floor_' . $type;

    return (int)(
        $_FILES[$fieldName]['error'][$floorLabel][$floorKey]
        ?? UPLOAD_ERR_NO_FILE
    );
}

$inspectorId = (int)(
    $_SESSION['uid'] ??
    $_SESSION['user_id'] ??
    0
);

$jobId = (int)($_POST['job_id'] ?? 0);
$assignmentId = (int)($_POST['assignment_id'] ?? 0);
$saveMode = strtolower(trim((string)($_POST['save_mode'] ?? 'asset')));
if (!in_array($saveMode, ['asset', 'final_submit'], true)) {
    $saveMode = 'asset';
}

if ($inspectorId <= 0 || $jobId <= 0 || $assignmentId <= 0) {
    $_SESSION['error'] =
        'Invalid Inspector inspection request.';

    header('Location: inspections.php');
    exit;
}

try {

    validateCsrfIfAvailable();

    /* =====================================================
       VERIFY INSPECTOR ASSIGNMENT + LOAD JOB
    ===================================================== */

    $verify = $conn->prepare("
        SELECT
            ia.id AS assignment_id,
            ia.inspector_id,
            ia.owner_id AS assignment_owner_id,
            ia.status AS assignment_status,

            mj.id AS job_id,
            mj.saf_id,
            mj.service_id,
            mj.asset_id,
            mj.owner_id,
            mj.status AS job_status,
            mj.job_type,
            mj.checklist AS saved_checklist,

            s.status AS saf_status,
            s.request_id,
            s.psga_no,
            s.saf_no,
            s.service_code,
            s.service_name,

            owner.name AS owner_name,
            owner.email AS owner_email,

            a.asset_name,
            a.location,
            a.display_asset_id,
            a.asset_type,
            a.category,

            ad.number_of_floors,
            ad.floor_service_type,
            ad.served_floors,
            ad.floor_interval

        FROM inspector_assignments ia

        INNER JOIN maintenance_jobs mj
            ON mj.id = ia.job_id

        INNER JOIN saf_contracts s
            ON s.id = mj.saf_id

        LEFT JOIN users owner
            ON owner.id = mj.owner_id

        LEFT JOIN assets a
            ON a.id = mj.asset_id

        LEFT JOIN asset_details ad
            ON ad.asset_id = mj.asset_id

        WHERE
            ia.id = ?
            AND ia.job_id = ?
            AND ia.inspector_id = ?
            AND mj.job_type = 'saf_inspection'

        LIMIT 1
    ");

    if (!$verify) {
        throw new Exception(
            'Unable to verify Inspector assignment: ' .
            $conn->error
        );
    }

    $verify->bind_param(
        'iii',
        $assignmentId,
        $jobId,
        $inspectorId
    );

    if (!$verify->execute()) {
        throw new Exception(
            'Unable to verify Inspector assignment: ' .
            $verify->error
        );
    }

    $job = $verify->get_result()->fetch_assoc();
    $verify->close();

    if (!$job) {
        throw new Exception(
            'This inspection is not assigned to this Inspector.'
        );
    }


    /* =====================================================
       LOAD EXISTING CHECKLIST
       Preserve previous evidence and recheck/history data.
    ===================================================== */

    $existingChecklist = [];

    if (!empty($job['saved_checklist'])) {

        $decodedExisting = json_decode(
            (string)$job['saved_checklist'],
            true
        );

        if (is_array($decodedExisting)) {
            $existingChecklist = $decodedExisting;
        }
    }

    /* =====================================================
       ROPE SUPPLEMENTARY ASSESSMENT — P1
       -----------------------------------------------------
       These fields are NOT fixed CHK-01 checkpoints, so they
       do not change the 129-checkpoint master count.
    ===================================================== */

    $ropePhysicalCondition = trim(
        (string)($_POST['rope_physical_condition'] ?? '')
    );

    $ropeReplacementDate = trim(
        (string)($_POST['rope_replacement_date'] ?? '')
    );

    $ropeAcceptanceResult = strtoupper(
        trim((string)($_POST['rope_acceptance_result'] ?? ''))
    );

    $ropeTechnicalJustification = trim(
        (string)($_POST['rope_technical_justification'] ?? '')
    );

    /*
     * IMPORTANT:
     * Initialise validation errors BEFORE rope/date validation.
     * Otherwise early validation can create an undefined $errors
     * array and make debugging/save flow unreliable.
     */
    $errors = [];

    /*
     * Evidence manifest produced by the one-file-at-a-time uploader.
     * Structure:
     * {
     *   "fixed": {"PAT-001":{"photo":"uploads/..."}},
     *   "floor": {"Floor 4":{"door_jamb_clearance":{"photo":"uploads/..."}}},
     *   "rope": {"photo":"uploads/..."}
     * }
     */
    $uploadedEvidenceManifest = [
        'fixed' => [],
        'floor' => [],
        'rope' => []
    ];

    $manifestRaw = trim((string)($_POST['uploaded_evidence_json'] ?? ''));

    if ($manifestRaw !== '') {
        $decodedManifest = json_decode($manifestRaw, true);

        if (!is_array($decodedManifest)) {
            $errors[] = 'Uploaded evidence manifest is invalid. Please upload the evidence again.';
        } else {
            foreach (['fixed', 'floor', 'rope'] as $manifestScope) {
                if (isset($decodedManifest[$manifestScope]) && is_array($decodedManifest[$manifestScope])) {
                    $uploadedEvidenceManifest[$manifestScope] = $decodedManifest[$manifestScope];
                }
            }
        }
    }

    /*
     * If a previous final-save attempt failed after the one-file-at-a-time
     * uploads completed, reuse the pending evidence stored in this Inspector
     * session.  This prevents the Inspector from having to reselect 20+ files.
     */
    $sessionPendingAll =
        is_array($_SESSION['pat_pending_evidence'] ?? null)
            ? $_SESSION['pat_pending_evidence']
            : [];

    $sessionPending =
        is_array($sessionPendingAll[$jobId] ?? null)
            ? $sessionPendingAll[$jobId]
            : [];

    foreach (['fixed', 'floor', 'rope'] as $manifestScope) {
        if (!is_array($sessionPending[$manifestScope] ?? null)) {
            continue;
        }

        if ($manifestScope === 'rope') {
            foreach ($sessionPending['rope'] as $type => $path) {
                if (
                    in_array($type, ['photo', 'video'], true) &&
                    trim((string)$path) !== '' &&
                    trim((string)($uploadedEvidenceManifest['rope'][$type] ?? '')) === ''
                ) {
                    $uploadedEvidenceManifest['rope'][$type] = $path;
                }
            }
            continue;
        }

        foreach ($sessionPending[$manifestScope] as $ref => $items) {
            if (!is_array($items)) {
                continue;
            }

            if (!isset($uploadedEvidenceManifest[$manifestScope][$ref])) {
                $uploadedEvidenceManifest[$manifestScope][$ref] = [];
            }

            foreach (['photo', 'video'] as $type) {
                $path = trim((string)($items[$type] ?? ''));

                if (
                    $path !== '' &&
                    trim((string)($uploadedEvidenceManifest[$manifestScope][$ref][$type] ?? '')) === ''
                ) {
                    $uploadedEvidenceManifest[$manifestScope][$ref][$type] = $path;
                }
            }
        }
    }

    /*
     * Load existing checklist ONCE, before any result/evidence
     * validation. This is required so previously saved photo/video
     * evidence is preserved when the Inspector edits and saves again.
     */
    $existingChecklist = [];

    if (!empty($job['saved_checklist'])) {
        $decodedExisting = json_decode(
            (string)$job['saved_checklist'],
            true
        );

        if (is_array($decodedExisting)) {
            $existingChecklist = $decodedExisting;
        }
    }

    /*
     * Merge evidence that may have been uploaded immediately
     * before the Inspector ever pressed Save Asset & Continue.
     * This prevents the later full save from deleting it.
     */
    $databaseEvidence = loadSavedEvidence($conn, $jobId);

    foreach ($databaseEvidence as $dbRef => $dbEvidence) {
        if ($dbRef === 'ROPE-SUP-01') {
            if (!isset($existingChecklist['rope_supplementary'])) {
                $existingChecklist['rope_supplementary'] = [];
            }
            if (!isset($existingChecklist['rope_supplementary']['evidence'])) {
                $existingChecklist['rope_supplementary']['evidence'] = [];
            }

            foreach (['photo', 'video'] as $type) {
                if (
                    !empty($dbEvidence[$type]) &&
                    empty($existingChecklist['rope_supplementary']['evidence'][$type])
                ) {
                    $existingChecklist['rope_supplementary']['evidence'][$type] =
                        $dbEvidence[$type];
                }
            }

            continue;
        }

        if (strpos($dbRef, 'FLOOR-') === 0) {
            $floorReference = substr($dbRef, strlen('FLOOR-'));
            $lastDash = strrpos($floorReference, '-');

            if ($lastDash !== false) {
                $floorLabel = substr($floorReference, 0, $lastDash);
                $floorKey = substr($floorReference, $lastDash + 1);

                if (!isset($existingChecklist['floor_checklist'][$floorLabel])) {
                    $existingChecklist['floor_checklist'][$floorLabel] = [];
                }
                if (!isset($existingChecklist['floor_checklist'][$floorLabel][$floorKey])) {
                    $existingChecklist['floor_checklist'][$floorLabel][$floorKey] = [];
                }
                if (!isset($existingChecklist['floor_checklist'][$floorLabel][$floorKey]['evidence'])) {
                    $existingChecklist['floor_checklist'][$floorLabel][$floorKey]['evidence'] = [];
                }

                foreach (['photo', 'video'] as $type) {
                    if (
                        !empty($dbEvidence[$type]) &&
                        empty($existingChecklist['floor_checklist'][$floorLabel][$floorKey]['evidence'][$type])
                    ) {
                        $existingChecklist['floor_checklist'][$floorLabel][$floorKey]['evidence'][$type] =
                            $dbEvidence[$type];
                    }
                }
            }

            continue;
        }

        if (preg_match('/^PAT-\d{3}$/', $dbRef)) {
            if (!isset($existingChecklist['results'][$dbRef])) {
                $existingChecklist['results'][$dbRef] = [];
            }
            if (!isset($existingChecklist['results'][$dbRef]['evidence'])) {
                $existingChecklist['results'][$dbRef]['evidence'] = [];
            }

            foreach (['photo', 'video'] as $type) {
                if (
                    !empty($dbEvidence[$type]) &&
                    empty($existingChecklist['results'][$dbRef]['evidence'][$type])
                ) {
                    $existingChecklist['results'][$dbRef]['evidence'][$type] =
                        $dbEvidence[$type];
                }
            }
        }
    }

    if (
        strcasecmp(
            trim((string)($job['saf_status'] ?? '')),
            'Accepted'
        ) !== 0
    ) {
        throw new Exception(
            'This SAF has not been accepted by the Owner.'
        );
    }

    $serviceCode = strtoupper(
        trim((string)($job['service_code'] ?? ''))
    );

    if (
        $serviceCode !== '' &&
        $serviceCode !== 'PAT'
    ) {
        throw new Exception(
            'This checklist is configured for PAT service only.'
        );
    }

    /* =====================================================
       SERVER-SIDE ASSET TYPE LOCK
       -----------------------------------------------------
       This endpoint is the LIFT + PAT endpoint.
       Escalator/Travellator PAT must use
       escalator-pat-checklist-save.php.
       This prevents an Escalator PAT job from ever being
       accidentally saved through the Lift PAT handler.
    ===================================================== */

    $assetType = strtolower(
        trim((string)($job['asset_type'] ?? ''))
    );

    $isEscalatorAsset =
        str_contains($assetType, 'escalator') ||
        str_contains($assetType, 'travellator');

    if ($isEscalatorAsset) {
        throw new Exception(
            'This is an Escalator/Travellator PAT inspection. ' .
            'Please open it through escalator-pat-checklist.php.'
        );
    }

    /*
     * Do not silently accept an unknown asset type as Lift.
     * Known Lift/Lift-related values continue normally.
     */
    $isLiftAsset =
        str_contains($assetType, 'lift') ||
        str_contains($assetType, 'elevator');

    if (!$isLiftAsset) {
        throw new Exception(
            'This PAT checklist is configured for Lift/Elevator assets only. ' .
            'Detected asset type: ' .
            (($job['asset_type'] ?? '') !== ''
                ? (string)$job['asset_type']
                : 'Unknown')
        );
    }

    /* =====================================================
       SERVER-SIDE SITE ATTENDANCE OTP LOCK
       ===================================================== */

    $attendanceTable = $conn->query("
        SHOW TABLES LIKE 'inspector_attendance'
    ");

    if (!$attendanceTable || $attendanceTable->num_rows === 0) {
        throw new Exception(
            'Site attendance OTP has not been verified.'
        );
    }

    $attendance = $conn->prepare("
        SELECT verified_at
        FROM inspector_attendance
        WHERE
            assignment_id = ?
            AND job_id = ?
            AND inspector_id = ?
            AND owner_id = ?
        LIMIT 1
    ");

    if (!$attendance) {
        throw new Exception(
            'Unable to verify site attendance: ' .
            $conn->error
        );
    }

    $attendance->bind_param(
        'iiii',
        $assignmentId,
        $jobId,
        $inspectorId,
        $job['owner_id']
    );

    $attendance->execute();

    $attendanceRow =
        $attendance->get_result()->fetch_assoc();

    $attendance->close();

    if (
        !$attendanceRow ||
        empty($attendanceRow['verified_at'])
    ) {
        throw new Exception(
            'Owner site-attendance OTP is not verified. '
            . 'Please complete Site Attendance verification first.'
        );
    }

    /*
     * =========================================================
     * ONE-FILE EVIDENCE UPLOAD MODE
     * =========================================================
     *
     * PHP's max_file_uploads limit is applied BEFORE this script
     * receives a normal multipart request.  A large PAT checklist
     * can easily contain more than 20 evidence files.
     *
     * The frontend therefore uploads each evidence file in its own
     * request.  The final checklist POST contains only the saved
     * relative paths, so PHP never has to receive 20+ files in one
     * request.
     */
    if ((string)($_POST['upload_only'] ?? '') === '1') {
        $uploadType = strtolower(trim((string)($_POST['evidence_type'] ?? '')));
        $uploadReference = trim((string)($_POST['evidence_ref'] ?? ''));
        $uploadResult = strtoupper(trim((string)($_POST['evidence_result'] ?? '')));

        if (!in_array($uploadType, ['photo', 'video'], true)) {
            jsonResponse([
                'ok' => false,
                'message' => 'Invalid evidence type.'
            ], 422);
        }

        if (!in_array($uploadResult, ['NC', 'CA'], true)) {
            jsonResponse([
                'ok' => false,
                'message' => 'Evidence can only be uploaded for NC or CA.'
            ], 422);
        }

        if ($uploadReference === '' || strlen($uploadReference) > 255) {
            jsonResponse([
                'ok' => false,
                'message' => 'Invalid evidence reference.'
            ], 422);
        }

        /*
         * Allow fixed PAT refs, Rope supplementary ref and D2 floor refs.
         * The reference is only an identifier; the normal save still
         * validates the selected result and required evidence rule.
         */
        if (
            !preg_match('/^(PAT-\\d{3}|ROPE-SUP-01|FLOOR-.+)$/', $uploadReference)
        ) {
            jsonResponse([
                'ok' => false,
                'message' => 'Invalid evidence reference.'
            ], 422);
        }

        $field = 'evidence_file';

        if (
            !isset($_FILES[$field]) ||
            !is_array($_FILES[$field]) ||
            (int)($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        ) {
            $uploadError = (int)($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE);

            jsonResponse([
                'ok' => false,
                'message' => 'Evidence upload failed. ' . uploadErrorMessage($uploadError)
            ], 422);
        }

        try {
            $savedPath = uploadEvidence(
                $uploadType,
                (string)($_FILES[$field]['tmp_name'] ?? ''),
                (string)($_FILES[$field]['name'] ?? ''),
                (int)($_FILES[$field]['size'] ?? 0),
                $jobId,
                $uploadReference,
                (string)($_FILES[$field]['type'] ?? '')
            );

            /*
             * IMPORTANT:
             * Save the evidence to the database immediately.
             * The Inspector can now refresh/go Back without losing it.
             */
            persistImmediateEvidence(
                $conn,
                $jobId,
                $uploadReference,
                $uploadType,
                $savedPath,
                $uploadResult
            );

            if (!isset($_SESSION['pat_pending_evidence']) || !is_array($_SESSION['pat_pending_evidence'])) {
                $_SESSION['pat_pending_evidence'] = [];
            }

            if (!isset($_SESSION['pat_pending_evidence'][$jobId]) || !is_array($_SESSION['pat_pending_evidence'][$jobId])) {
                $_SESSION['pat_pending_evidence'][$jobId] = [
                    'fixed' => [],
                    'floor' => [],
                    'rope' => []
                ];
            }

            if ($uploadReference === 'ROPE-SUP-01') {
                $_SESSION['pat_pending_evidence'][$jobId]['rope'][$uploadType] = $savedPath;
            } elseif (strpos($uploadReference, 'FLOOR-') === 0) {
                $floorReference = substr($uploadReference, strlen('FLOOR-'));
                $parts = explode('-', $floorReference);
                $floorKey = array_pop($parts);
                $floorLabel = implode('-', $parts);

                if (!isset($_SESSION['pat_pending_evidence'][$jobId]['floor'][$floorLabel])) {
                    $_SESSION['pat_pending_evidence'][$jobId]['floor'][$floorLabel] = [];
                }

                if (!isset($_SESSION['pat_pending_evidence'][$jobId]['floor'][$floorLabel][$floorKey])) {
                    $_SESSION['pat_pending_evidence'][$jobId]['floor'][$floorLabel][$floorKey] = [];
                }

                $_SESSION['pat_pending_evidence'][$jobId]['floor'][$floorLabel][$floorKey][$uploadType] = $savedPath;
            } else {
                if (!isset($_SESSION['pat_pending_evidence'][$jobId]['fixed'][$uploadReference])) {
                    $_SESSION['pat_pending_evidence'][$jobId]['fixed'][$uploadReference] = [];
                }

                $_SESSION['pat_pending_evidence'][$jobId]['fixed'][$uploadReference][$uploadType] = $savedPath;
            }

            jsonResponse([
                'ok' => true,
                'type' => $uploadType,
                'reference' => $uploadReference,
                'path' => $savedPath
            ]);
        } catch (Throwable $e) {
            jsonResponse([
                'ok' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /* =====================================================
       CHK-01 REV.1 FIXED MASTER — PAT-001 -> PAT-129
    ===================================================== */

    $expected = [];

    for ($i = 1; $i <= 129; $i++) {
        $expected[] =
            'PAT-' .
            str_pad(
                (string)$i,
                3,
                '0',
                STR_PAD_LEFT
            );
    }

    /*
     * These are the D2 execution-only floor checkpoints.
     * They intentionally do not receive PAT-132+ IDs.
     */
    $floorCheckpointDefinitions = [
        'landing_door_alignment' => [
            'checkpoint_name' => 'Landing door alignment',
            'priority' => 'P2'
        ],
        'landing_door_manual_lock' => [
            'checkpoint_name' => 'Landing door manual lock functional',
            'priority' => 'P1'
        ],
        'landing_push_button' => [
            'checkpoint_name' => 'Landing push button operational',
            'priority' => 'P3'
        ],
        'braille_button' => [
            'checkpoint_name' => 'Braille button provided',
            'priority' => 'P4'
        ],
        'hall_display' => [
            'checkpoint_name' => 'Hall display operational',
            'priority' => 'P2'
        ],
        'entrance_dimensions' => [
            'checkpoint_name' => 'Entrance width & height verified',
            'priority' => 'P4'
        ],
        'door_jamb_clearance' => [
            'checkpoint_name' => 'Door to jamb clearance within limit',
            'priority' => 'P1'
        ],
        'sill_gap_verified' => [
            'checkpoint_name' => 'Sill gap verified',
            'priority' => 'P3'
        ]
    ];

    $landingFloors = parseServedFloors($job);

    $ropeAgeYears = null;

    if ($ropePhysicalCondition === '' || !in_array($ropePhysicalCondition, ['Healthy', 'Not Healthy'], true)) {
        $errors[] = 'Rope physical condition is required.';
    }

    if ($ropeReplacementDate === '') {
        $errors[] = 'Rope replacement date is required.';
    } else {
        $ropeDateObj = DateTime::createFromFormat('Y-m-d', $ropeReplacementDate);
        $todayObj = new DateTime('today');

        if (!$ropeDateObj || $ropeDateObj->format('Y-m-d') !== $ropeReplacementDate) {
            $errors[] = 'Rope replacement date is invalid.';
        } elseif ($ropeDateObj > $todayObj) {
            $errors[] = 'Rope replacement date cannot be in the future.';
        } else {
            $ropeAgeYears = $ropeDateObj->diff($todayObj)->y;
        }
    }

    if ($ropePhysicalCondition !== '' && $ropeAgeYears !== null) {
        if ($ropePhysicalCondition === 'Not Healthy') {
            $ropeAcceptanceResult = 'NC';
        } elseif ($ropeAgeYears > 3) {
            $ropeAcceptanceResult = 'CA';
        } else {
            $ropeAcceptanceResult = 'C';
        }
    }

    if (!in_array($ropeAcceptanceResult, ['C', 'NC', 'CA'], true)) {
        $errors[] = 'Rope acceptance result could not be determined.';
    }

    if ($ropeAcceptanceResult === 'CA' && $ropeTechnicalJustification === '') {
        $errors[] = 'Rope technical justification is required for CA.';
    }

    $ropeEvidence = is_array($existingChecklist['rope_supplementary']['evidence'] ?? null)
        ? $existingChecklist['rope_supplementary']['evidence']
        : [];

    /*
     * INSPECTOR CHECKLIST METADATA — SOURCE OF TRUTH FOR SAVED SUBMISSION
     * ---------------------------------------------------------------
     * The Inspector UI uses the same master definition to render the
     * checklist. On SAVE we persist the actual checkpoint identity,
     * name, priority and section into maintenance_jobs.checklist.
     *
     * Admin and Owner must read these saved fields instead of keeping
     * their own PAT/SAT/SCI checkpoint-name/priority maps.
     */
    $patCheckpointMeta = [
        'PAT-001' => ['checkpoint_name' => 'Shaft pit constructed as approved drawing', 'priority' => 'P3', 'section' => 'A'],
        'PAT-002' => ['checkpoint_name' => 'Headroom available as per GAD', 'priority' => 'P3', 'section' => 'A'],
        'PAT-003' => ['checkpoint_name' => 'Shaft ventilation provided', 'priority' => 'P3', 'section' => 'A'],
        'PAT-004' => ['checkpoint_name' => 'Shaft lighting installed and operational', 'priority' => 'P2', 'section' => 'A'],
        'PAT-005' => ['checkpoint_name' => 'Shaft white wash completed', 'priority' => 'P4', 'section' => 'B'],
        'PAT-006' => ['checkpoint_name' => 'Guide rail fish plate bolts tightened', 'priority' => 'P3', 'section' => 'B'],
        'PAT-007' => ['checkpoint_name' => 'Electrical wiring properly secured', 'priority' => 'P2', 'section' => 'B'],
        'PAT-008' => ['checkpoint_name' => 'Wiring clear from moving parts', 'priority' => 'P2', 'section' => 'B'],
        'PAT-009' => ['checkpoint_name' => 'Shaft protected against rainwater ingress', 'priority' => 'P3', 'section' => 'B'],
        'PAT-010' => ['checkpoint_name' => 'Main & Counterweight guide rails match GAD', 'priority' => 'P4', 'section' => 'B'],
        'PAT-011' => ['checkpoint_name' => 'OSG distance from pit floor verified', 'priority' => 'P4', 'section' => 'C'],
        'PAT-012' => ['checkpoint_name' => 'Pit electrical wiring satisfactory', 'priority' => 'P3', 'section' => 'C'],
        'PAT-013' => ['checkpoint_name' => 'Buffers installed as per GAD', 'priority' => 'P1', 'section' => 'C'],
        'PAT-014' => ['checkpoint_name' => 'Car run-by within permissible limit', 'priority' => 'P4', 'section' => 'C'],
        'PAT-015' => ['checkpoint_name' => 'Counterweight run-by verified', 'priority' => 'P4', 'section' => 'C'],
        'PAT-016' => ['checkpoint_name' => 'Pit ladder installed correctly', 'priority' => 'P3', 'section' => 'C'],
        'PAT-017' => ['checkpoint_name' => 'Oil collectors leak proof', 'priority' => 'P4', 'section' => 'C'],
        'PAT-018' => ['checkpoint_name' => 'Pit cleanliness / painting / marking', 'priority' => 'P3', 'section' => 'C'],
        'PAT-019' => ['checkpoint_name' => 'Pit lighting operational', 'priority' => 'P2', 'section' => 'C'],
        'PAT-020' => ['checkpoint_name' => 'Water drainage provision available', 'priority' => 'P4', 'section' => 'C'],
        'PAT-021' => ['checkpoint_name' => 'Landing doors aligned in vertical line', 'priority' => 'P2', 'section' => 'D'],
        'PAT-022' => ['checkpoint_name' => 'Landing doors connected in safety circuit', 'priority' => 'P1', 'section' => 'D'],
        'PAT-023' => ['checkpoint_name' => 'Toe guard installed below landing door', 'priority' => 'P3', 'section' => 'D'],
        'PAT-024' => ['checkpoint_name' => 'Landing door electrical wiring satisfactory', 'priority' => 'P2', 'section' => 'D'],
        'PAT-025' => ['checkpoint_name' => 'Fireman glass & brake hammer available', 'priority' => 'P1', 'section' => 'D3'],
        'PAT-026' => ['checkpoint_name' => 'Fireman switch operational', 'priority' => 'P1', 'section' => 'D3'],
        'PAT-027' => ['checkpoint_name' => 'Isolation pads provided', 'priority' => 'P2', 'section' => 'E'],
        'PAT-028' => ['checkpoint_name' => 'OEM nameplate with design life', 'priority' => 'P3', 'section' => 'E'],
        'PAT-029' => ['checkpoint_name' => 'Diverter pulley aligned & guarded', 'priority' => 'P2', 'section' => 'E'],
        'PAT-030' => ['checkpoint_name' => 'Safety gear with valid certificate', 'priority' => 'P1 + CA', 'section' => 'E'],
        'PAT-031' => ['checkpoint_name' => 'Safety gear linkage connected', 'priority' => 'P1', 'section' => 'E'],
        'PAT-032' => ['checkpoint_name' => 'Earthing provided', 'priority' => 'P2', 'section' => 'E'],
        'PAT-033' => ['checkpoint_name' => 'Overload cell operational', 'priority' => 'P1', 'section' => 'E'],
        'PAT-034' => ['checkpoint_name' => 'Electrical safety switch functional', 'priority' => 'P1', 'section' => 'E'],
        'PAT-035' => ['checkpoint_name' => 'VVVF drive certified', 'priority' => 'P2 + CA', 'section' => 'F'],
        'PAT-036' => ['checkpoint_name' => 'Emergency brake lever available', 'priority' => 'P1', 'section' => 'F'],
        'PAT-037' => ['checkpoint_name' => 'Fasteners secured', 'priority' => 'P4', 'section' => 'F'],
        'PAT-038' => ['checkpoint_name' => 'Electrical wiring satisfactory', 'priority' => 'P4', 'section' => 'F'],
        'PAT-039' => ['checkpoint_name' => 'Earthing continuity verified', 'priority' => 'P1', 'section' => 'F'],
        'PAT-040' => ['checkpoint_name' => 'RCCB / ELCB installed', 'priority' => 'P2', 'section' => 'N'],
        'PAT-041' => ['checkpoint_name' => 'Correct rating verified', 'priority' => 'P2', 'section' => 'F'],
        'PAT-042' => ['checkpoint_name' => 'Tripping function tested', 'priority' => 'P2', 'section' => 'F'],
        'PAT-043' => ['checkpoint_name' => 'ARD installation', 'priority' => 'P1', 'section' => 'G'],
        'PAT-044' => ['checkpoint_name' => 'Controller securely installed', 'priority' => 'P4', 'section' => 'G'],
        'PAT-045' => ['checkpoint_name' => 'Internal wiring satisfactory', 'priority' => 'P2', 'section' => 'G'],
        'PAT-046' => ['checkpoint_name' => 'Earthing connected', 'priority' => 'P1', 'section' => 'G'],
        'PAT-047' => ['checkpoint_name' => 'Controller door earthed', 'priority' => 'P1', 'section' => 'G'],
        'PAT-048' => ['checkpoint_name' => 'Maintenance switch tested', 'priority' => 'P1', 'section' => 'G'],
        'PAT-049' => ['checkpoint_name' => 'Stop switch tested', 'priority' => 'P1', 'section' => 'G'],
        'PAT-050' => ['checkpoint_name' => 'Lockable controller with danger label', 'priority' => 'P2', 'section' => 'G'],
        'PAT-051' => ['checkpoint_name' => 'Wiring diagram available', 'priority' => 'P3', 'section' => 'G'],
        'PAT-052' => ['checkpoint_name' => 'Diagnostic keypad / HHT available', 'priority' => 'P3', 'section' => 'G'],
        'PAT-053' => ['checkpoint_name' => 'ELCB provided', 'priority' => 'P2', 'section' => 'G'],
        'PAT-054' => ['checkpoint_name' => 'OSG pulley alignment', 'priority' => 'P1', 'section' => 'H'],
        'PAT-055' => ['checkpoint_name' => 'Nameplate readable', 'priority' => 'P3', 'section' => 'H'],
        'PAT-056' => ['checkpoint_name' => 'Test certificate available', 'priority' => 'P2', 'section' => 'H'],
        'PAT-057' => ['checkpoint_name' => 'Tripping speed verified', 'priority' => 'P1', 'section' => 'H'],
        'PAT-058' => ['checkpoint_name' => 'Tension weight & safety switch', 'priority' => 'P1', 'section' => 'H'],
        'PAT-059' => ['checkpoint_name' => 'OSG rope clean without lubrication', 'priority' => 'P2', 'section' => 'H'],
        'PAT-060' => ['checkpoint_name' => 'Pit pulley clearance adequate', 'priority' => 'P3', 'section' => 'H'],
        'PAT-061' => ['checkpoint_name' => 'Guide shoes satisfactory', 'priority' => 'P2', 'section' => 'I'],
        'PAT-062' => ['checkpoint_name' => 'All fasteners secured', 'priority' => 'P4', 'section' => 'I'],
        'PAT-063' => ['checkpoint_name' => 'Oil cups installed', 'priority' => 'P3', 'section' => 'I'],
        'PAT-064' => ['checkpoint_name' => 'Emergency hooter operational', 'priority' => 'P1', 'section' => 'I'],
        'PAT-065' => ['checkpoint_name' => 'Handrail securely fixed', 'priority' => 'P3', 'section' => 'I'],
        'PAT-066' => ['checkpoint_name' => 'Cabin lighting operational', 'priority' => 'P3', 'section' => 'I'],
        'PAT-067' => ['checkpoint_name' => 'Car top wiring protected', 'priority' => 'P2', 'section' => 'I'],
        'PAT-068' => ['checkpoint_name' => 'COP stop button tested', 'priority' => 'P1', 'section' => 'I'],
        'PAT-069' => ['checkpoint_name' => 'Intercom operational', 'priority' => 'P1', 'section' => 'I'],
        'PAT-070' => ['checkpoint_name' => 'Cabin wall & ceiling satisfactory', 'priority' => 'P3', 'section' => 'I'],
        'PAT-071' => ['checkpoint_name' => 'COP buttons operational', 'priority' => 'P3', 'section' => 'I'],
        'PAT-072' => ['checkpoint_name' => 'Position indicator functional', 'priority' => 'P3', 'section' => 'I'],
        'PAT-073' => ['checkpoint_name' => 'Capacity plate fixed', 'priority' => 'P3', 'section' => 'I'],
        'PAT-074' => ['checkpoint_name' => 'CCTV installed', 'priority' => 'P2', 'section' => 'I'],
        'PAT-075' => ['checkpoint_name' => 'Car top junction box operational', 'priority' => 'P2', 'section' => 'I'],
        'PAT-076' => ['checkpoint_name' => 'Maintenance switch operational', 'priority' => 'P1', 'section' => 'I'],
        'PAT-077' => ['checkpoint_name' => 'Inspection UP/DOWN operation verified', 'priority' => 'P1', 'section' => 'I'],
        'PAT-078' => ['checkpoint_name' => 'Cabin fans operational', 'priority' => 'P3', 'section' => 'I'],
        'PAT-079' => ['checkpoint_name' => 'Car toe guard installed', 'priority' => 'P3', 'section' => 'J'],
        'PAT-080' => ['checkpoint_name' => 'Door opening / closing adjusted', 'priority' => 'P2', 'section' => 'J'],
        'PAT-081' => ['checkpoint_name' => 'Fasteners secured', 'priority' => 'P4', 'section' => 'J'],
        'PAT-082' => ['checkpoint_name' => '3D sensor / photocell working', 'priority' => 'P1', 'section' => 'J'],
        'PAT-083' => ['checkpoint_name' => 'Sill clearance uniform', 'priority' => 'P2', 'section' => 'J'],
        'PAT-084' => ['checkpoint_name' => 'Entrance dimensions verified', 'priority' => 'P4', 'section' => 'J'],
        'PAT-085' => ['checkpoint_name' => 'Car door gap from jam within limits', 'priority' => 'P2', 'section' => 'J'],
        'PAT-086' => ['checkpoint_name' => 'Lobby free from stumbling obstacles', 'priority' => 'P3', 'section' => 'J'],
        'PAT-087' => ['checkpoint_name' => 'Intercom connected with security room', 'priority' => 'P2', 'section' => 'J'],
        'PAT-088' => ['checkpoint_name' => 'Counterweight balanced at 50% load', 'priority' => 'P1', 'section' => 'K'],
        'PAT-089' => ['checkpoint_name' => 'Guide shoes satisfactory', 'priority' => 'P2', 'section' => 'K'],
        'PAT-090' => ['checkpoint_name' => 'Oil cups installed', 'priority' => 'P3', 'section' => 'K'],
        'PAT-091' => ['checkpoint_name' => 'Counterweight pulley aligned', 'priority' => 'P1', 'section' => 'K'],
        'PAT-092' => ['checkpoint_name' => 'Weights locked with tie rod', 'priority' => 'P1', 'section' => 'K'],
        'PAT-093' => ['checkpoint_name' => 'Counterweight safety gear (Hanging Pit)', 'priority' => 'P1', 'section' => 'K'],
        'PAT-094' => ['checkpoint_name' => 'Counterweight guard provided', 'priority' => 'P2', 'section' => 'K'],
        'PAT-095' => ['checkpoint_name' => 'Guard height & clearance satisfactory', 'priority' => 'P2', 'section' => 'K'],
        'PAT-096' => ['checkpoint_name' => 'Rope fastening verified', 'priority' => 'P1', 'section' => 'L'],
        'PAT-097' => ['checkpoint_name' => 'Uniform rope tension', 'priority' => 'P2', 'section' => 'L'],
        'PAT-098' => ['checkpoint_name' => 'Rope ends secured with bulldog clips', 'priority' => 'P1', 'section' => 'L'],
        'PAT-099' => ['checkpoint_name' => 'No rope twist', 'priority' => 'P1', 'section' => 'L'],
        'PAT-100' => ['checkpoint_name' => 'Rope clean without grease', 'priority' => 'P3', 'section' => 'L'],
        'PAT-101' => ['checkpoint_name' => 'Rope test certificates available', 'priority' => 'P2 + CA', 'section' => 'L'],
        'PAT-102' => ['checkpoint_name' => 'Floor level markings completed', 'priority' => 'P1', 'section' => 'L'],
        'PAT-103' => ['checkpoint_name' => 'Landing & cabin cleanliness', 'priority' => 'P4', 'section' => 'M'],
        'PAT-104' => ['checkpoint_name' => 'Noise within acceptable level', 'priority' => 'P3', 'section' => 'M'],
        'PAT-105' => ['checkpoint_name' => 'No abnormal vibration', 'priority' => 'P2', 'section' => 'M'],
        'PAT-106' => ['checkpoint_name' => 'Hoistway condition satisfactory', 'priority' => 'P3', 'section' => 'M'],
        'PAT-107' => ['checkpoint_name' => 'Machine serial matches nameplate', 'priority' => 'P2', 'section' => 'N'],
        'PAT-108' => ['checkpoint_name' => 'Load capacity matches GAD', 'priority' => 'P1 + CA', 'section' => 'N'],
        'PAT-109' => ['checkpoint_name' => 'Isolation pads provided', 'priority' => 'P2', 'section' => 'N'],
        'PAT-110' => ['checkpoint_name' => 'Minimum two earthing strips', 'priority' => 'P1', 'section' => 'N'],
        'PAT-111' => ['checkpoint_name' => 'No abnormal sound', 'priority' => 'P2', 'section' => 'N'],
        'PAT-112' => ['checkpoint_name' => 'Vibration within range', 'priority' => 'P2', 'section' => 'N'],
        'PAT-113' => ['checkpoint_name' => 'Shock treatment chart displayed', 'priority' => 'P3', 'section' => 'N'],
        'PAT-114' => ['checkpoint_name' => 'Rescue diagram displayed', 'priority' => 'P2', 'section' => 'N'],
        'PAT-115' => ['checkpoint_name' => 'Caution signage provided', 'priority' => 'P3', 'section' => 'N'],
        'PAT-116' => ['checkpoint_name' => 'Smoke window provided (MRL only)', 'priority' => 'P2', 'section' => 'N'],
        'PAT-117' => ['checkpoint_name' => 'Fire extinguisher in Machine Room (MR Lift)', 'priority' => 'P1', 'section' => 'N'],
        'PAT-118' => ['checkpoint_name' => 'Fire extinguisher beside Top Controller (MRL)', 'priority' => 'P1', 'section' => 'N'],
        'PAT-119' => ['checkpoint_name' => 'Machine room accessibility safe', 'priority' => 'P2', 'section' => 'N'],
        'PAT-120' => ['checkpoint_name' => 'Exhaust fan operational', 'priority' => 'P3', 'section' => 'N'],
        'PAT-121' => ['checkpoint_name' => 'Machine room lighting adequate', 'priority' => 'P2', 'section' => 'N'],
        'PAT-122' => ['checkpoint_name' => 'Emergency stop provided', 'priority' => 'P1', 'section' => 'N'],
        'PAT-123' => ['checkpoint_name' => 'Main cable type & size verified', 'priority' => 'P2', 'section' => 'N'],
        'PAT-124' => ['checkpoint_name' => 'Log book box installed', 'priority' => 'P3', 'section' => 'O'],
        'PAT-125' => ['checkpoint_name' => 'Designated maintenance log available', 'priority' => 'P2', 'section' => 'O'],
        'PAT-126' => ['checkpoint_name' => 'OEM/AMC safety certificate displayed', 'priority' => 'P2', 'section' => 'O'],
        'PAT-127' => ['checkpoint_name' => 'Valid CAMC / AMC agreement available', 'priority' => 'P1 + CA', 'section' => 'O'],
        'PAT-128' => ['checkpoint_name' => '“NOT IN USE” display available', 'priority' => 'P3', 'section' => 'O'],
        'PAT-129' => ['checkpoint_name' => 'Valid insurance copy available', 'priority' => 'P2', 'section' => 'O'],
    ];

    if (count($patCheckpointMeta) !== 129) {
        throw new Exception(
            'CHK-01 PAT metadata configuration error. Expected 129 checkpoints, found '
            . count($patCheckpointMeta) . '.'
        );
    }

    $postedResults =
        $_POST['result'] ?? [];

    $postedRemarks =
        $_POST['remark'] ?? [];

    $postedJustification =
        $_POST['technical_justification'] ?? [];

    $postedFloorResults =
        $_POST['floor_result'] ?? [];

    $postedFloorRemarks =
        $_POST['floor_remark'] ?? [];

    $postedFloorJustification =
        $_POST['floor_technical_justification'] ?? [];

    $sectionRemarks =
        $_POST['section_remarks'] ?? [];

    if (!is_array($postedResults)) {
        $postedResults = [];
    }

    if (!is_array($postedFloorResults)) {
        $postedFloorResults = [];
    }

    if (!is_array($postedFloorRemarks)) {
        $postedFloorRemarks = [];
    }

    if (!is_array($postedFloorJustification)) {
        $postedFloorJustification = [];
    }

    /*
     * PHP max_input_vars protection / diagnostic.
     * If the browser sends fewer than the 128 fixed PAT results,
     * the request has almost certainly been truncated before this
     * endpoint receives it. The checklist page disables irrelevant
     * empty controls, but the server should still report the exact
     * configuration problem instead of returning dozens of misleading
     * "Result is required" messages.
     */
    $missingFixedRefs = [];

    foreach ($expected as $checkRef) {
        if (!array_key_exists($checkRef, $postedResults)) {
            $missingFixedRefs[] = $checkRef;
        }
    }

    if ($missingFixedRefs) {
        $maxInputVars = (string)(ini_get('max_input_vars') ?: 'unknown');

        throw new Exception(
            'PAT form data was truncated before reaching the server. ' .
            'PHP max_input_vars is ' . $maxInputVars . '. ' .
            'Missing fixed checkpoint data starts at: ' .
            implode(', ', array_slice($missingFixedRefs, 0, 10)) .
            '. Please set PHP max_input_vars to at least 5000 and restart Apache/PHP.'
        );
    }

    $allowed =
        ['C', 'NC', 'NA', 'CA'];

    $results = [];
    $errors = [];

    /* =====================================================
       VALIDATE FIXED PAT-001 -> PAT-129
    ===================================================== */

    foreach ($expected as $ref) {

        $result = strtoupper(
            trim(
                (string)($postedResults[$ref] ?? '')
            )
        );

        if (!in_array($result, $allowed, true)) {
            $errors[] =
                $ref . ': Result is required.';

            continue;
        }

        $remark = trim(
            (string)($postedRemarks[$ref] ?? '')
        );

        $justification = trim(
            (string)($postedJustification[$ref] ?? '')
        );

        if (
            $result === 'NC' &&
            $remark === ''
        ) {
            $errors[] =
                $ref .
                ': Engineering Remark is required for NC.';
        }

        if (
            $result === 'CA' &&
            $justification === ''
        ) {
            $errors[] =
                $ref .
                ': Technical Justification is required for CA.';
        }

        /*
         * Persist Inspector master metadata with the submitted result.
         * This is the critical historical snapshot used by Admin/Owner.
         */
        $meta = $patCheckpointMeta[$ref];

        $results[$ref] = [
            'checkpoint_id' =>
                $ref,

            'checkpoint_name' =>
                $meta['checkpoint_name'],

            'priority' =>
                $meta['priority'],

            'section' =>
                $meta['section'],

            'result' =>
                $result,

            'remark' =>
                $remark,

            'technical_justification' =>
                $justification
        ];

        /*
         * Preserve previously uploaded evidence when the
         * Inspector is saving the same asset again.
         */
        if (is_array($existingChecklist['results'][$ref] ?? null)) {
            $oldEvidence = $existingChecklist['results'][$ref]['evidence'] ?? [];

            if (is_array($oldEvidence) && $oldEvidence) {
                $results[$ref]['evidence'] = $oldEvidence;
            }
        }
    }

    /* =====================================================
       VALIDATE D2 FLOOR CHECKLIST
    ===================================================== */

    $floorChecklist = [];

    foreach ($landingFloors as $floorLabel) {

        $floorChecklist[$floorLabel] = [];

        foreach (
            $floorCheckpointDefinitions
            as $floorKey => $floorDescription
        ) {

            $result = strtoupper(
                trim(
                    (string)(
                        $postedFloorResults[$floorLabel][$floorKey]
                        ?? ''
                    )
                )
            );

            $floorMeta = $floorCheckpointDefinitions[$floorKey];
            $floorDescription = (string)$floorMeta['checkpoint_name'];
            $floorPriority = (string)$floorMeta['priority'];

            $reference =
                'Floor ' .
                $floorLabel .
                ' — ' .
                $floorDescription;

            if (!in_array($result, $allowed, true)) {

                $errors[] =
                    $reference .
                    ': Result is required.';

                continue;
            }

            $remark = trim(
                (string)(
                    $postedFloorRemarks[$floorLabel][$floorKey]
                    ?? ''
                )
            );

            $justification = trim(
                (string)(
                    $postedFloorJustification[$floorLabel][$floorKey]
                    ?? ''
                )
            );

            if (
                $result === 'NC' &&
                $remark === ''
            ) {
                $errors[] =
                    $reference .
                    ': Engineering Remark is required for NC.';
            }

            if (
                $result === 'CA' &&
                $justification === ''
            ) {
                $errors[] =
                    $reference .
                    ': Technical Justification is required for CA.';
            }

            $floorChecklist[$floorLabel][$floorKey] = [
                'checkpoint_id' =>
                    'D2-' . $floorLabel . '-' . $floorKey,

                'checkpoint_name' =>
                    $floorDescription,

                'priority' =>
                    $floorPriority,

                'section' =>
                    'D2',

                'floor' =>
                    $floorLabel,

                'floor_key' =>
                    $floorKey,

                'result' =>
                    $result,

                'remark' =>
                    $remark,

                'technical_justification' =>
                    $justification
            ];

            /*
             * Preserve previously uploaded floor evidence.
             */
            $oldFloorEvidence =
                $existingChecklist['floor_checklist']
                    [$floorLabel]
                    [$floorKey]
                    ['evidence']
                ?? [];

            if (is_array($oldFloorEvidence) && $oldFloorEvidence) {
                $floorChecklist[$floorLabel][$floorKey]['evidence'] =
                    $oldFloorEvidence;
            }
        }
    }

    /* =====================================================
       NC / CA EVIDENCE RULE
       NC and CA both require at least ONE evidence item:
       PHOTO OR VIDEO.
       Either one is sufficient.
    ===================================================== */

    foreach ($expected as $ref) {

        $result = strtoupper(
            trim((string)($postedResults[$ref] ?? ''))
        );

        if (!in_array($result, ['NC', 'CA'], true)) {
            continue;
        }

        $existingPhoto = '';
        $existingVideo = '';

        if (is_array($existingChecklist['results'][$ref] ?? null)) {
            $existingPhoto = trim(
                (string)(
                    $existingChecklist['results'][$ref]['evidence']['photo']
                    ?? $existingChecklist['results'][$ref]['photo']
                    ?? ''
                )
            );
            $existingVideo = trim(
                (string)(
                    $existingChecklist['results'][$ref]['evidence']['video']
                    ?? $existingChecklist['results'][$ref]['video']
                    ?? ''
                )
            );
        }

        $photoUploadError = fixedUploadError('photo', $ref);
        $videoUploadError = fixedUploadError('video', $ref);

        validateEvidenceUploadError($photoUploadError, $ref, 'photo');
        validateEvidenceUploadError($videoUploadError, $ref, 'video');

        $manifestPhoto = '';
        $manifestVideo = '';

        if (is_array($uploadedEvidenceManifest['fixed'][$ref] ?? null)) {
            $manifestPhoto = trim(
                (string)($uploadedEvidenceManifest['fixed'][$ref]['photo'] ?? '')
            );
            $manifestVideo = trim(
                (string)($uploadedEvidenceManifest['fixed'][$ref]['video'] ?? '')
            );

            if ($manifestPhoto !== '') {
                $manifestPhoto = validatePreUploadedEvidencePath(
                    $manifestPhoto,
                    $jobId,
                    $ref,
                    'photo'
                );
            }

            if ($manifestVideo !== '') {
                $manifestVideo = validatePreUploadedEvidencePath(
                    $manifestVideo,
                    $jobId,
                    $ref,
                    'video'
                );
            }
        }

        $hasPhoto = $existingPhoto !== ''
            || $manifestPhoto !== ''
            || $photoUploadError === UPLOAD_ERR_OK;

        $hasVideo = $existingVideo !== ''
            || $manifestVideo !== ''
            || $videoUploadError === UPLOAD_ERR_OK;

        if (!$hasPhoto && !$hasVideo) {
            $errors[] =
                $ref .
                ': Upload either Photograph OR Video for ' .
                $result . '.';
        }
    }

    foreach ($landingFloors as $floorLabel) {

        foreach (
            $floorCheckpointDefinitions
            as $floorKey => $floorMeta
        ) {

            $floorDescription = (string)($floorMeta['checkpoint_name'] ?? '');

            $result = strtoupper(
                trim(
                    (string)(
                        $postedFloorResults[$floorLabel][$floorKey]
                        ?? ''
                    )
                )
            );

            if (!in_array($result, ['NC', 'CA'], true)) {
                continue;
            }

            $existingFloorPhoto = '';
            $existingFloorVideo = '';

            if (is_array(
                $existingChecklist['floor_checklist']
                    [$floorLabel]
                    [$floorKey]
                ?? null
            )) {
                $existingFloorPhoto = trim(
                    (string)(
                        $existingChecklist['floor_checklist']
                            [$floorLabel]
                            [$floorKey]
                            ['evidence']['photo']
                        ?? ''
                    )
                );
                $existingFloorVideo = trim(
                    (string)(
                        $existingChecklist['floor_checklist']
                            [$floorLabel]
                            [$floorKey]
                            ['evidence']['video']
                        ?? ''
                    )
                );
            }

            $floorPhotoUploadError = floorUploadError(
                'photo',
                $floorLabel,
                $floorKey
            );
            $floorVideoUploadError = floorUploadError(
                'video',
                $floorLabel,
                $floorKey
            );

            validateEvidenceUploadError(
                $floorPhotoUploadError,
                'Floor ' . $floorLabel . ' — ' . $floorDescription,
                'photo'
            );
            validateEvidenceUploadError(
                $floorVideoUploadError,
                'Floor ' . $floorLabel . ' — ' . $floorDescription,
                'video'
            );

            $manifestFloorPhoto = '';
            $manifestFloorVideo = '';

            if (
                is_array(
                    $uploadedEvidenceManifest['floor']
                        [$floorLabel]
                        [$floorKey]
                    ?? null
                )
            ) {
                $manifestFloorPhoto = trim(
                    (string)(
                        $uploadedEvidenceManifest['floor']
                            [$floorLabel]
                            [$floorKey]
                            ['photo']
                        ?? ''
                    )
                );

                $manifestFloorVideo = trim(
                    (string)(
                        $uploadedEvidenceManifest['floor']
                            [$floorLabel]
                            [$floorKey]
                            ['video']
                        ?? ''
                    )
                );

                $floorReference =
                    'FLOOR-' . $floorLabel . '-' . $floorKey;

                if ($manifestFloorPhoto !== '') {
                    $manifestFloorPhoto = validatePreUploadedEvidencePath(
                        $manifestFloorPhoto,
                        $jobId,
                        $floorReference,
                        'photo'
                    );
                }

                if ($manifestFloorVideo !== '') {
                    $manifestFloorVideo = validatePreUploadedEvidencePath(
                        $manifestFloorVideo,
                        $jobId,
                        $floorReference,
                        'video'
                    );
                }
            }

            $hasFloorPhoto = $existingFloorPhoto !== ''
                || $manifestFloorPhoto !== ''
                || $floorPhotoUploadError === UPLOAD_ERR_OK;

            $hasFloorVideo = $existingFloorVideo !== ''
                || $manifestFloorVideo !== ''
                || $floorVideoUploadError === UPLOAD_ERR_OK;

            if (!$hasFloorPhoto && !$hasFloorVideo) {
                $errors[] =
                    'Floor ' .
                    $floorLabel .
                    ' — ' .
                    $floorDescription .
                    ': Upload either Photograph OR Video for ' .
                    $result . '.';
            }
        }
    }

    /* =====================================================
       FINAL STATUS
    ===================================================== */

    $finalStatus = strtoupper(
        trim(
            (string)($_POST['final_status'] ?? '')
        )
    );

    if (
        !in_array(
            $finalStatus,
            [
                'PASS',
                'CONDITIONAL PASS',
                'FAIL'
            ],
            true
        )
    ) {
        $errors[] =
            'Final Inspection Status is required.';
    }

    if ($errors) {
        failBack(
            implode(
                "\n",
                array_slice($errors, 0, 30)
            ),
            $jobId,
            $assignmentId
        );
    }

    /* =====================================================
       EVIDENCE
    ===================================================== */

    $fixedEvidence = [];
    $floorEvidence = [];

    /*
     * Evidence already uploaded one-file-at-a-time.
     * Validate every manifest path before it is written into the
     * final checklist JSON.
     */
    if (is_array($uploadedEvidenceManifest['fixed'] ?? null)) {
        foreach ($uploadedEvidenceManifest['fixed'] as $ref => $files) {
            if (!is_array($files)) {
                continue;
            }

            foreach (['photo', 'video'] as $type) {
                $path = trim((string)($files[$type] ?? ''));

                if ($path === '') {
                    continue;
                }

                $fixedEvidence[$ref][$type] =
                    validatePreUploadedEvidencePath(
                        $path,
                        $jobId,
                        (string)$ref,
                        $type
                    );
            }
        }
    }

    if (is_array($uploadedEvidenceManifest['floor'] ?? null)) {
        foreach ($uploadedEvidenceManifest['floor'] as $floorLabel => $floorItems) {
            if (!is_array($floorItems)) {
                continue;
            }

            foreach ($floorItems as $floorKey => $files) {
                if (!is_array($files)) {
                    continue;
                }

                $reference =
                    'FLOOR-' . $floorLabel . '-' . $floorKey;

                foreach (['photo', 'video'] as $type) {
                    $path = trim((string)($files[$type] ?? ''));

                    if ($path === '') {
                        continue;
                    }

                    $floorEvidence[$floorLabel][$floorKey][$type] =
                        validatePreUploadedEvidencePath(
                            $path,
                            $jobId,
                            $reference,
                            $type
                        );
                }
            }
        }
    }

    if (is_array($uploadedEvidenceManifest['rope'] ?? null)) {
        foreach (['photo', 'video'] as $type) {
            $path = trim((string)($uploadedEvidenceManifest['rope'][$type] ?? ''));

            if ($path === '') {
                continue;
            }

            $ropeEvidence[$type] =
                validatePreUploadedEvidencePath(
                    $path,
                    $jobId,
                    'ROPE-SUP-01',
                    $type
                );
        }
    }

    /*
     * Rope supplementary evidence.
     * Rope NC / CA requires at least ONE evidence item:
     * PHOTO OR VIDEO. Either one is sufficient.
     * This evidence is supplementary and does not add a fixed checkpoint.
     */
    foreach (['photo', 'video'] as $ropeType) {
        $field = 'rope_' . $ropeType;

        if (!isset($_FILES[$field]) || !isset($_FILES[$field]['tmp_name'])) {
            continue;
        }

        $ropeError = (int)($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($ropeError === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        if ($ropeError !== UPLOAD_ERR_OK) {
            $errors[] = 'Rope ' . $ropeType . ' upload failed. ' . uploadErrorMessage($ropeError);
            continue;
        }

        try {
            $ropeEvidence[$ropeType] = uploadEvidence(
                $ropeType,
                (string)$_FILES[$field]['tmp_name'],
                (string)$_FILES[$field]['name'],
                (int)$_FILES[$field]['size'],
                $jobId,
                'ROPE-SUP-01'
            );
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }

    if (in_array($ropeAcceptanceResult, ['NC', 'CA'], true)) {
        $existingRopePhoto = trim(
            (string)($existingChecklist['rope_supplementary']['evidence']['photo'] ?? '')
        );
        $existingRopeVideo = trim(
            (string)($existingChecklist['rope_supplementary']['evidence']['video'] ?? '')
        );

        $hasRopePhoto = !empty($ropeEvidence['photo']) || $existingRopePhoto !== '';
        $hasRopeVideo = !empty($ropeEvidence['video']) || $existingRopeVideo !== '';

        if (!$hasRopePhoto && !$hasRopeVideo) {
            $errors[] =
                'ROPE-SUP-01: Upload either Photo OR Video for Rope ' .
                $ropeAcceptanceResult . '.';
        }
    }

    /*
     * Fixed PAT evidence.
     */
    foreach (
        ['photo', 'video']
        as $type
    ) {

        if (
            !isset($_FILES[$type]) ||
            !is_array(
                $_FILES[$type]['name'] ?? null
            )
        ) {
            continue;
        }

        foreach ($expected as $ref) {

            $error = (int)(
                $_FILES[$type]['error'][$ref]
                ?? UPLOAD_ERR_NO_FILE
            );

            if (
                $error === UPLOAD_ERR_NO_FILE
            ) {
                continue;
            }

            if ($error !== UPLOAD_ERR_OK) {
                $errors[] =
                    $ref .
                    ': ' .
                    ucfirst($type) .
                    ' upload failed. ' .
                    uploadErrorMessage($error);

                continue;
            }

            try {

                $path = uploadEvidence(
                    $type,
                    (string)(
                        $_FILES[$type]['tmp_name'][$ref]
                        ?? ''
                    ),
                    (string)(
                        $_FILES[$type]['name'][$ref]
                        ?? ''
                    ),
                    (int)(
                        $_FILES[$type]['size'][$ref]
                        ?? 0
                    ),
                    $jobId,
                    $ref
                );

                $fixedEvidence[$ref][$type] =
                    $path;

            } catch (Throwable $e) {

                $errors[] =
                    $e->getMessage();
            }
        }
    }

    /*
     * Dynamic floor evidence.
     */
    foreach (
        ['photo', 'video']
        as $type
    ) {

        $fieldName =
            'floor_' . $type;

        if (
            !isset($_FILES[$fieldName]) ||
            !is_array(
                $_FILES[$fieldName]['name'] ?? null
            )
        ) {
            continue;
        }

        foreach ($landingFloors as $floorLabel) {

            foreach (
                $floorCheckpointDefinitions
                as $floorKey => $floorMeta
            ) {

                $floorDescription = (string)($floorMeta['checkpoint_name'] ?? '');

                $error = (int)(
                    $_FILES[$fieldName]['error']
                        [$floorLabel]
                        [$floorKey]
                    ?? UPLOAD_ERR_NO_FILE
                );

                if (
                    $error === UPLOAD_ERR_NO_FILE
                ) {
                    continue;
                }

                if ($error !== UPLOAD_ERR_OK) {

                    $errors[] =
                        'Floor ' .
                        $floorLabel .
                        ' — ' .
                        $floorDescription .
                        ': ' .
                        ucfirst($type) .
                        ' upload failed. ' .
                        uploadErrorMessage($error);

                    continue;
                }

                $reference =
                    'FLOOR-' .
                    $floorLabel .
                    '-' .
                    $floorKey;

                try {

                    $path = uploadEvidence(
                        $type,
                        (string)(
                            $_FILES[$fieldName]['tmp_name']
                                [$floorLabel]
                                [$floorKey]
                            ?? ''
                        ),
                        (string)(
                            $_FILES[$fieldName]['name']
                                [$floorLabel]
                                [$floorKey]
                            ?? ''
                        ),
                        (int)(
                            $_FILES[$fieldName]['size']
                                [$floorLabel]
                                [$floorKey]
                            ?? 0
                        ),
                        $jobId,
                        $reference
                    );

                    $floorEvidence
                        [$floorLabel]
                        [$floorKey]
                        [$type] =
                        $path;

                } catch (Throwable $e) {

                    $errors[] =
                        $e->getMessage();
                }
            }
        }
    }

    if ($errors) {
        failBack(
            implode(
                "\n",
                array_slice($errors, 0, 30)
            ),
            $jobId,
            $assignmentId
        );
    }

    /* =====================================================
       MERGE FIXED EVIDENCE
    ===================================================== */

    foreach (
        $fixedEvidence
        as $ref => $files
    ) {

        if (
            !isset(
                $results[$ref]['evidence']
            )
        ) {
            $results[$ref]['evidence'] = [];
        }

        foreach (
            $files
            as $type => $path
        ) {
            $results[$ref]['evidence'][$type] =
                $path;
        }
    }

    /* =====================================================
       MERGE FLOOR EVIDENCE
    ===================================================== */

    foreach (
        $floorEvidence
        as $floorLabel => $floorItems
    ) {

        foreach (
            $floorItems
            as $floorKey => $files
        ) {

            if (
                !isset(
                    $floorChecklist
                        [$floorLabel]
                        [$floorKey]
                        ['evidence']
                )
            ) {
                $floorChecklist
                    [$floorLabel]
                    [$floorKey]
                    ['evidence'] = [];
            }

            foreach (
                $files
                as $type => $path
            ) {
                $floorChecklist
                    [$floorLabel]
                    [$floorKey]
                    ['evidence'][$type] =
                    $path;
            }
        }
    }

    /* =====================================================
       SECTION REMARKS
    ===================================================== */

    $cleanSectionRemarks = [];

    if (is_array($sectionRemarks)) {

        foreach (
            [
                'A','B','C','D','E',
                'F','G','H','I','J',
                'K','L','M','N','O'
            ]
            as $section
        ) {

            $cleanSectionRemarks[$section] =
                trim(
                    (string)(
                        $sectionRemarks[$section]
                        ?? ''
                    )
                );
        }
    }

    /* =====================================================
       BUILD CHECKLIST JSON
       Preserve all existing recheck/history data.
    ===================================================== */

    $checklist = $existingChecklist;

    $checklist['checklist_code'] =
        'CHK-01';

    $checklist['checklist_revision'] =
        'Rev.1';

    $checklist['module'] =
        'PAT';

    $checklist['timezone'] =
        'Asia/Kolkata';

    $checklist['saved_at'] =
        date('Y-m-d H:i:s');

    $checklist['saved_at_ist'] =
        date('d/m/Y H:i:s');

    $checklist['saved_by_role'] =
        'inspector';

    $checklist['saved_by'] =
        $inspectorId;

    $checklist['job_id'] =
        $jobId;

    $checklist['assignment_id'] =
        $assignmentId;

    $checklist['fixed_checkpoint_count'] =
        129;

    $checklist['rope_supplementary'] = [
        'checkpoint_id' => 'ROPE-SUP-01',
        'checkpoint_name' => 'Rope physical condition / replacement age assessment',
        'priority' => 'P1',
        'section' => 'L',
        'physical_condition' => $ropePhysicalCondition,
        'replacement_date' => $ropeReplacementDate,
        'age_years' => $ropeAgeYears,
        'result' => $ropeAcceptanceResult,
        'technical_justification' => $ropeTechnicalJustification,
        'owner_client_visible' => in_array($ropeAcceptanceResult, ['NC', 'CA'], true),
        'evidence' => $ropeEvidence
    ];

    $checklist['floor_checkpoint_count'] =
        count($landingFloors) *
        count($floorCheckpointDefinitions);

    $checklist['floor_labels'] =
        array_values($landingFloors);

    $checklist['results'] =
        $results;

    $checklist['floor_checklist'] =
        $floorChecklist;

    $checklist['section_remarks'] =
        $cleanSectionRemarks;

    $checklist['final_status'] =
        $finalStatus;

    /*
     * Owner/Client attention snapshot.
     * Includes every fixed PAT NC/CA, every D2 floor NC/CA and the
     * Rope P1 supplementary assessment when NC/CA.
     */
    $ownerClientAttention = [];

    foreach ($results as $ref => $item) {
        if (in_array(strtoupper((string)($item['result'] ?? '')), ['NC', 'CA'], true)) {
            $ownerClientAttention[] = [
                'checkpoint_id' => $ref,
                'checkpoint_name' => (string)($item['checkpoint_name'] ?? ''),
                'priority' => (string)($item['priority'] ?? ''),
                'section' => (string)($item['section'] ?? ''),
                'result' => strtoupper((string)($item['result'] ?? '')),
                'owner_client_visible' => true
            ];
        }
    }

    foreach ($floorChecklist as $floorLabel => $floorItems) {
        foreach ($floorItems as $floorKey => $item) {
            if (in_array(strtoupper((string)($item['result'] ?? '')), ['NC', 'CA'], true)) {
                $ownerClientAttention[] = [
                    'checkpoint_id' => (string)($item['checkpoint_id'] ?? ''),
                    'checkpoint_name' => (string)($item['checkpoint_name'] ?? ''),
                    'priority' => (string)($item['priority'] ?? ''),
                    'section' => 'D2',
                    'floor' => $floorLabel,
                    'result' => strtoupper((string)($item['result'] ?? '')),
                    'owner_client_visible' => true
                ];
            }
        }
    }

    if (in_array($ropeAcceptanceResult, ['NC', 'CA'], true)) {
        $ownerClientAttention[] = [
            'checkpoint_id' => 'ROPE-SUP-01',
            'checkpoint_name' => 'Rope physical condition / replacement age assessment',
            'priority' => 'P1',
            'section' => 'L',
            'result' => $ropeAcceptanceResult,
            'owner_client_visible' => true
        ];
    }

    $checklist['owner_client_attention'] = $ownerClientAttention;


    $checklist['inspector_signature'] =
        trim(
            (string)(
                $_POST['inspector_signature']
                ?? ''
            )
        );

    $checklist['owner_representative'] =
        trim(
            (string)(
                $_POST['owner_representative']
                ?? ''
            )
        );

    $checklist['amc_representative'] =
        trim(
            (string)(
                $_POST['amc_representative']
                ?? ''
            )
        );

    /*
     * Never let original checklist submission erase:
     * recheck_results, recheck_history, recheck_meta, etc.
     */

    $json = json_encode(
        $checklist,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    if ($json === false) {
        throw new Exception(
            'Unable to encode checklist data.'
        );
    }

    /* =====================================================
       TRANSACTION
    ===================================================== */

    $conn->begin_transaction();

    try {

        /*
         * Re-check authorization inside transaction.
         */
        $verify2 = $conn->prepare("
            SELECT
                ia.id,
                ia.status AS assignment_status,
                mj.status AS job_status,
                mj.checklist,
                s.status AS saf_status
            FROM inspector_assignments ia
            INNER JOIN maintenance_jobs mj
                ON mj.id = ia.job_id
            INNER JOIN saf_contracts s
                ON s.id = mj.saf_id
            WHERE
                ia.id = ?
                AND ia.job_id = ?
                AND ia.inspector_id = ?
                AND mj.job_type = 'saf_inspection'
            LIMIT 1
        ");

        if (!$verify2) {
            throw new Exception(
                'Unable to verify Inspector assignment: ' .
                $conn->error
            );
        }

        $verify2->bind_param(
            'iii',
            $assignmentId,
            $jobId,
            $inspectorId
        );

        if (!$verify2->execute()) {
            throw new Exception(
                'Unable to verify Inspector assignment: ' .
                $verify2->error
            );
        }

        $current = $verify2
            ->get_result()
            ->fetch_assoc();

        $verify2->close();

        if (!$current) {
            throw new Exception(
                'This inspection is not assigned to this Inspector.'
            );
        }

        if (
            strcasecmp(
                trim((string)$current['saf_status']),
                'Accepted'
            ) !== 0
        ) {
            throw new Exception(
                'This SAF has not been accepted by the Owner.'
            );
        }

        /*
         * Prevent an original checklist from being submitted again
         * after recheck processing has started.
         */
        $currentChecklist = [];

        if (!empty($current['checklist'])) {

            $decodedCurrent =
                json_decode(
                    (string)$current['checklist'],
                    true
                );

            if (is_array($decodedCurrent)) {
                $currentChecklist =
                    $decodedCurrent;
            }
        }

        /*
         * FINAL SUBMIT LOCK
         * ---------------------------------------------------------
         * Same behavior as the Escalator PAT workflow:
         *
         * 1. Inspector completes the Lift PAT checklist.
         * 2. Final Submit is allowed only after the required
         *    site-attendance OTP verification.
         * 3. Final Submit changes this Inspector assignment to
         *    "submitted".
         * 4. Once submitted, THIS original checklist is LOCKED.
         *
         * "completed" is still only an asset-level saved state and
         * remains editable before Final Submit.
         *
         * The submitted status is the server-side lock, so even if
         * someone manually opens the checklist URL or sends a POST,
         * the original Lift PAT checklist cannot be changed.
         */
        $currentAssignmentStatus = strtolower(
            trim((string)($current['assignment_status'] ?? ''))
        );

        if ($currentAssignmentStatus === 'submitted') {
            throw new Exception(
                'This Lift PAT checklist is locked because Final Submit '
                . 'has already been completed after site-attendance OTP verification. '
                . 'The original checklist cannot be edited.'
            );
        }

        /*
         * Preserve the existing recheck protection as well.
         * Recheck/final workflow data must never be overwritten by
         * the original checklist endpoint.
         */
        if (
            !empty(
                $currentChecklist['recheck_status']
            )
        ) {
            throw new Exception(
                'Original Lift PAT checklist is already submitted. '
                . 'Recheck/final workflow has started.'
            );
        }

        /* =================================================
           SAVE EVIDENCE RECORDS
           -------------------------------------------------
           Each asset has its own maintenance job, therefore
           evidence is stored against THIS job only.
        ================================================= */

        $deleteEvidence = $conn->prepare("
            DELETE FROM saf_checklist_evidence
            WHERE job_id = ?
        ");

        if (!$deleteEvidence) {
            throw new Exception(
                'Unable to prepare evidence cleanup: ' .
                $conn->error
            );
        }

        $deleteEvidence->bind_param('i', $jobId);

        if (!$deleteEvidence->execute()) {
            throw new Exception(
                'Unable to clear previous evidence: ' .
                $deleteEvidence->error
            );
        }

        $deleteEvidence->close();

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
            VALUES (?, ?, ?, ?, ?, NULL)
        ");

        if (!$insertEvidence) {
            throw new Exception(
                'Unable to prepare evidence save: ' .
                $conn->error
            );
        }

        /*
         * Rebuild evidence rows from the FINAL merged JSON.
         * This preserves old evidence as well as newly uploaded
         * evidence when the same asset is saved again.
         */
        foreach ($results as $ref => $item) {

            $evidence = is_array($item['evidence'] ?? null)
                ? $item['evidence']
                : [];

            $evidenceResult = strtoupper(
                trim((string)($item['result'] ?? ''))
            );

            /*
             * Evidence is valid/persistent only for NC or CA.
             * Any stale evidence left from an earlier NC/CA state is
             * removed when the checkpoint is changed to C or NA.
             */
            if (!in_array($evidenceResult, ['NC', 'CA'], true)) {
                continue;
            }

            $photoPath = (string)($evidence['photo'] ?? '');
            $videoPath = (string)($evidence['video'] ?? '');

            if ($photoPath === '' && $videoPath === '') {
                continue;
            }

            $insertEvidence->bind_param(
                'issss',
                $jobId,
                $ref,
                $evidenceResult,
                $photoPath,
                $videoPath
            );

            if (!$insertEvidence->execute()) {
                throw new Exception(
                    $ref .
                    ': Unable to save evidence record: ' .
                    $insertEvidence->error
                );
            }
        }

        foreach ($floorChecklist as $floorLabel => $floorItems) {

            foreach ($floorItems as $floorKey => $item) {

                $evidence = is_array($item['evidence'] ?? null)
                    ? $item['evidence']
                    : [];

                $evidenceResult = strtoupper(
                    trim((string)($item['result'] ?? ''))
                );

                if (!in_array($evidenceResult, ['NC', 'CA'], true)) {
                    continue;
                }

                $photoPath = (string)($evidence['photo'] ?? '');
                $videoPath = (string)($evidence['video'] ?? '');

                if ($photoPath === '' && $videoPath === '') {
                    continue;
                }

                $refCode =
                    'FLOOR-' .
                    $floorLabel .
                    '-' .
                    $floorKey;

                $insertEvidence->bind_param(
                    'issss',
                    $jobId,
                    $refCode,
                    $evidenceResult,
                    $photoPath,
                    $videoPath
                );

                if (!$insertEvidence->execute()) {
                    throw new Exception(
                        $refCode .
                        ': Unable to save evidence record: ' .
                        $insertEvidence->error
                    );
                }
            }
        }

        if (
            in_array(
                strtoupper(trim((string)$ropeAcceptanceResult)),
                ['NC', 'CA'],
                true
            ) &&
            (!empty($ropeEvidence['photo']) || !empty($ropeEvidence['video']))
        ) {
            $ropePhoto = (string)($ropeEvidence['photo'] ?? '');
            $ropeVideo = (string)($ropeEvidence['video'] ?? '');
            $ropeDbRef = 'ROPE-SUP-01';

            $insertEvidence->bind_param(
                'issss',
                $jobId,
                $ropeDbRef,
                $ropeAcceptanceResult,
                $ropePhoto,
                $ropeVideo
            );

            if (!$insertEvidence->execute()) {
                throw new Exception(
                    'ROPE-SUP-01: Unable to save Rope evidence record: ' .
                    $insertEvidence->error
                );
            }
        }

        $insertEvidence->close();

        /* =================================================
           SAVE THIS ASSET CHECKLIST
           -------------------------------------------------
           IMPORTANT:
           Do NOT send the whole SAF to Owner here.
           Each selected asset has its own checklist/job.
        ================================================= */

        /*
         * Save THIS asset checklist.
         *
         * Save & Continue with a valid Final Inspection Status
         * marks this individual PAT job as completed.
         * It does NOT submit the complete SAF to Owner.
         *
         * Final SAF submission is still handled separately by
         * submit-complete-saf.php.
         *
         * Therefore 'completed' remains an editable saved state;
         * only the separate final-submit flow changes the assignment
         * to 'submitted'.
         */
        $assetJobStatus = in_array(
            $finalStatus,
            ['PASS', 'CONDITIONAL PASS', 'FAIL'],
            true
        )
            ? 'completed'
            : 'in_progress';

        /*
         * The uploaded video is evidence only. The checklist becomes
         * completed only after the checklist JSON + final_status are
         * successfully written to maintenance_jobs.
         */
        if ($finalStatus === '') {
            throw new Exception(
                'Video was uploaded, but Final Inspection Status is empty. '
                . 'Select PASS, CONDITIONAL PASS or FAIL and save again.'
            );
        }

        $update = $conn->prepare("
            UPDATE maintenance_jobs
            SET
                checklist = ?,
                status = ?,
                updated_at = NOW()
            WHERE
                id = ?
                AND job_type = 'saf_inspection'
        ");

        if (!$update) {
            throw new Exception(
                'Unable to prepare checklist save: ' .
                $conn->error
            );
        }

        $update->bind_param(
            'ssi',
            $json,
            $assetJobStatus,
            $jobId
        );

        if (!$update->execute()) {
            throw new Exception(
                'Unable to save checklist: ' .
                $update->error
            );
        }

        $update->close();

        /*
         * Verify the ACTUAL row after UPDATE.
         * affected_rows may legally be 0 when values are unchanged,
         * so it is not a reliable success test.
         */
        $verifySaved = $conn->prepare("
            SELECT
                id,
                status,
                JSON_UNQUOTE(
                    JSON_EXTRACT(checklist, '$.final_status')
                ) AS final_status
            FROM maintenance_jobs
            WHERE
                id = ?
                AND job_type = 'saf_inspection'
            LIMIT 1
        ");

        if (!$verifySaved) {
            throw new Exception(
                'Database verification prepare failed: ' . $conn->error
            );
        }

        $verifySaved->bind_param('i', $jobId);

        if (!$verifySaved->execute()) {
            throw new Exception(
                'Database verification execute failed: ' . $verifySaved->error
            );
        }

        $savedCheck = $verifySaved->get_result()->fetch_assoc();
        $verifySaved->close();

        if (!$savedCheck) {
            throw new Exception(
                'Job #' . $jobId . ' was not found after PAT save.'
            );
        }

        $dbFinalStatus = strtoupper(
            trim((string)($savedCheck['final_status'] ?? ''))
        );

        $dbJobStatus = strtolower(
            trim((string)($savedCheck['status'] ?? ''))
        );

        if ($dbFinalStatus !== $finalStatus) {
            throw new Exception(
                'PAT final status was NOT written to database. ' .
                'Expected: ' . $finalStatus .
                ' | Database: ' .
                ($dbFinalStatus !== '' ? $dbFinalStatus : 'NULL')
            );
        }

        if (
            in_array(
                $finalStatus,
                ['PASS', 'CONDITIONAL PASS', 'FAIL'],
                true
            ) &&
            $dbJobStatus !== 'completed'
        ) {
            throw new Exception(
                'PAT final status was saved, but maintenance_jobs.status ' .
                'is still "' . $dbJobStatus . '" instead of "completed".'
            );
        }

        /*
         * A normal asset save does NOT submit the assignment.
         * It remains available so the Inspector can reopen it.
         */
        if ($saveMode !== 'final_submit') {

            /*
             * Keep the assignment status synchronized with THIS
             * asset checklist. Do not mark the whole SAF submitted.
             */
            $assetAssignmentStatus = in_array(
                $finalStatus,
                ['PASS', 'CONDITIONAL PASS', 'FAIL'],
                true
            )
                ? 'completed'
                : 'in_progress';

            $assignmentUpdate = $conn->prepare("
                UPDATE inspector_assignments
                SET
                    status = ?
                WHERE
                    id = ?
                    AND job_id = ?
                    AND inspector_id = ?
                    AND status <> 'submitted'
            ");

            if (!$assignmentUpdate) {
                throw new Exception(
                    'Unable to prepare Inspector assignment update: ' .
                    $conn->error
                );
            }

            $assignmentUpdate->bind_param(
                'siii',
                $assetAssignmentStatus,
                $assignmentId,
                $jobId,
                $inspectorId
            );

            if (!$assignmentUpdate->execute()) {
                throw new Exception(
                    'Unable to update Inspector assignment status: ' .
                    $assignmentUpdate->error
                );
            }

            $assignmentUpdate->close();

            /*
             * Verify the individual assignment status too.
             */
            $verifyAssignment = $conn->prepare("
                SELECT status
                FROM inspector_assignments
                WHERE
                    id = ?
                    AND job_id = ?
                    AND inspector_id = ?
                LIMIT 1
            ");

            if (!$verifyAssignment) {
                throw new Exception(
                    'Assignment verification prepare failed: ' . $conn->error
                );
            }

            $verifyAssignment->bind_param(
                'iii',
                $assignmentId,
                $jobId,
                $inspectorId
            );

            if (!$verifyAssignment->execute()) {
                throw new Exception(
                    'Assignment verification failed: ' . $verifyAssignment->error
                );
            }

            $assignmentCheck =
                $verifyAssignment->get_result()->fetch_assoc();

            $verifyAssignment->close();

            $savedAssignmentStatus = strtolower(
                trim((string)($assignmentCheck['status'] ?? ''))
            );

            if (
                in_array(
                    $finalStatus,
                    ['PASS', 'CONDITIONAL PASS', 'FAIL'],
                    true
                ) &&
                $savedAssignmentStatus !== 'completed'
            ) {
                throw new Exception(
                    'PAT job is completed, but Inspector assignment #' .
                    $assignmentId .
                    ' is still "' .
                    ($savedAssignmentStatus !== ''
                        ? $savedAssignmentStatus
                        : 'NULL') .
                    '" instead of "completed".'
                );
            }

            /*
             * Find the next incomplete asset under this SAME SAF.
             * Never use another SAF or another Owner.
             */
            $requestId = (int)($job['request_id'] ?? 0);
            $safId = (int)($job['saf_id'] ?? 0);

            $nextJobId = 0;
            $nextAssignmentId = 0;

            if ($requestId > 0 && $safId > 0) {

                $nextStmt = $conn->prepare("
                    SELECT
                        mj.id AS job_id,
                        ia.id AS assignment_id
                    FROM maintenance_jobs mj
                    INNER JOIN saf_contracts s
                        ON s.id = mj.saf_id
                    INNER JOIN service_request_assets sra
                        ON sra.request_id = s.request_id
                        AND sra.asset_id = mj.asset_id
                    INNER JOIN inspector_assignments ia
                        ON ia.job_id = mj.id
                        AND ia.inspector_id = ?
                    WHERE
                        mj.saf_id = ?
                        AND s.request_id = ?
                        AND mj.job_type = 'saf_inspection'
                        AND mj.id <> ?
                        AND ia.status NOT IN ('submitted', 'completed')
                    ORDER BY mj.id ASC
                ");

                if ($nextStmt) {

                    $nextStmt->bind_param(
                        'iiii',
                        $inspectorId,
                        $safId,
                        $requestId,
                        $jobId
                    );

                    if ($nextStmt->execute()) {

                        $nextResult = $nextStmt->get_result();

                        while ($nextRow = $nextResult->fetch_assoc()) {

                            $candidateChecklist = [];

                            if (!empty($nextRow['job_id'])) {
                                /*
                                 * Load candidate checklist separately so
                                 * this query remains compatible with the
                                 * existing maintenance_jobs schema.
                                 */
                                $candidateId = (int)$nextRow['job_id'];

                                $candidateStmt = $conn->prepare("
                                    SELECT checklist
                                    FROM maintenance_jobs
                                    WHERE id = ?
                                    LIMIT 1
                                ");

                                if ($candidateStmt) {
                                    $candidateStmt->bind_param(
                                        'i',
                                        $candidateId
                                    );

                                    if ($candidateStmt->execute()) {
                                        $candidateRow =
                                            $candidateStmt
                                                ->get_result()
                                                ->fetch_assoc();

                                        if (!empty($candidateRow['checklist'])) {
                                            $decodedCandidate = json_decode(
                                                (string)$candidateRow['checklist'],
                                                true
                                            );

                                            if (is_array($decodedCandidate)) {
                                                $candidateChecklist =
                                                    $decodedCandidate;
                                            }
                                        }
                                    }

                                    $candidateStmt->close();
                                }
                            }

                            $candidateFinal = strtoupper(
                                trim(
                                    (string)(
                                        $candidateChecklist['final_status']
                                        ?? ''
                                    )
                                )
                            );

                            if (!in_array(
                                $candidateFinal,
                                ['PASS', 'CONDITIONAL PASS', 'FAIL'],
                                true
                            )) {
                                $nextJobId =
                                    (int)$nextRow['job_id'];

                                $nextAssignmentId =
                                    (int)$nextRow['assignment_id'];

                                break;
                            }
                        }
                    }

                    $nextStmt->close();
                }
            }

            $conn->commit();

            $_SESSION['clear_pat_draft_job'] = $jobId;

            if (isset($_SESSION['pat_pending_evidence'][$jobId])) {
                unset($_SESSION['pat_pending_evidence'][$jobId]);
            }

            if ($nextJobId > 0 && $nextAssignmentId > 0) {
                $_SESSION['success'] =
                    'Asset checklist saved successfully. '
                    . 'Opening the next selected asset checklist.';

                header(
                    'Location: saf-checklist.php?assignment_id=' .
                    $nextAssignmentId .
                    '&job_id=' .
                    $nextJobId
                );
                exit;
            }

            $_SESSION['success'] =
                'Asset checklist saved successfully. '
                . 'Video/photo evidence and checklist status were saved to the database. '
                . 'Return to Inspections to review all assets in this SAF.';

            header('Location: inspections.php');
            exit;
        }

        /* =================================================
           FINAL SUBMIT — ONLY AFTER ALL ASSET CHECKLISTS
           ARE COMPLETE.
        ================================================= */

        $requestId = (int)($job['request_id'] ?? 0);
        $safId = (int)($job['saf_id'] ?? 0);

        if ($requestId <= 0 || $safId <= 0) {
            throw new Exception(
                'Unable to determine the Service Request for this SAF.'
            );
        }

        /*
         * Expected assets are taken from service_request_assets.
         */
        $expectedAssets = [];

        $assetStmt = $conn->prepare("
            SELECT asset_id
            FROM service_request_assets
            WHERE request_id = ?
            ORDER BY asset_id ASC
        ");

        if (!$assetStmt) {
            throw new Exception(
                'Unable to load selected SAF assets: ' .
                $conn->error
            );
        }

        $assetStmt->bind_param('i', $requestId);

        if (!$assetStmt->execute()) {
            throw new Exception(
                'Unable to load selected SAF assets: ' .
                $assetStmt->error
            );
        }

        $assetResult = $assetStmt->get_result();

        while ($assetRow = $assetResult->fetch_assoc()) {
            $expectedAssets[] = (int)$assetRow['asset_id'];
        }

        $assetStmt->close();

        /*
         * Fallback for legacy single-asset SAFs.
         */
        if (!$expectedAssets && (int)$job['asset_id'] > 0) {
            $expectedAssets[] = (int)$job['asset_id'];
        }

        if (!$expectedAssets) {
            throw new Exception(
                'No selected assets were found for this SAF.'
            );
        }

        /*
         * Lock/check every inspection job belonging to this
         * exact SAF + Service Request.
         */
        $allJobsStmt = $conn->prepare("
            SELECT
                mj.id AS job_id,
                mj.asset_id,
                mj.checklist,
                ia.id AS assignment_id,
                ia.inspector_id,
                ia.status AS assignment_status
            FROM maintenance_jobs mj
            INNER JOIN inspector_assignments ia
                ON ia.job_id = mj.id
                AND ia.inspector_id = ?
            WHERE
                mj.saf_id = ?
                AND mj.job_type = 'saf_inspection'
            ORDER BY mj.id ASC
            FOR UPDATE
        ");

        if (!$allJobsStmt) {
            throw new Exception(
                'Unable to verify all SAF asset checklists: ' .
                $conn->error
            );
        }

        $allJobsStmt->bind_param(
            'ii',
            $inspectorId,
            $safId
        );

        if (!$allJobsStmt->execute()) {
            throw new Exception(
                'Unable to verify all SAF asset checklists: ' .
                $allJobsStmt->error
            );
        }

        $allJobsResult = $allJobsStmt->get_result();

        $jobsByAsset = [];

        while ($row = $allJobsResult->fetch_assoc()) {
            $jobsByAsset[(int)$row['asset_id']] = $row;
        }

        $allJobsStmt->close();

        $incompleteAssets = [];

        foreach ($expectedAssets as $expectedAssetId) {

            if (!isset($jobsByAsset[$expectedAssetId])) {
                $incompleteAssets[] =
                    'Asset ID ' . $expectedAssetId .
                    ' has no Inspector inspection job.';
                continue;
            }

            $assetChecklist = [];

            if (!empty($jobsByAsset[$expectedAssetId]['checklist'])) {
                $decodedAssetChecklist = json_decode(
                    (string)$jobsByAsset[$expectedAssetId]['checklist'],
                    true
                );

                if (is_array($decodedAssetChecklist)) {
                    $assetChecklist = $decodedAssetChecklist;
                }
            }

            $assetFinalStatus = strtoupper(
                trim(
                    (string)(
                        $assetChecklist['final_status'] ?? ''
                    )
                )
            );

            if (!in_array(
                $assetFinalStatus,
                ['PASS', 'CONDITIONAL PASS', 'FAIL'],
                true
            )) {
                $incompleteAssets[] =
                    'Asset ID ' .
                    $expectedAssetId .
                    ' checklist is not complete.';
            }
        }

        if ($incompleteAssets) {

            throw new Exception(
                'Complete every selected asset checklist before Final Submit: ' .
                implode(' ', array_slice($incompleteAssets, 0, 15))
            );
        }

        /*
         * All selected assets are complete.
         * NOW — and only now — submit the complete SAF to Owner.
         */
        $finalJobsUpdate = $conn->prepare("
            UPDATE maintenance_jobs mj
            SET
                mj.status = 'pending_owner',
                mj.updated_at = NOW()
            WHERE
                mj.saf_id = ?
                AND mj.job_type = 'saf_inspection'
        ");

        if (!$finalJobsUpdate) {
            throw new Exception(
                'Unable to prepare final SAF submission: ' .
                $conn->error
            );
        }

        $finalJobsUpdate->bind_param(
            'i',
            $safId
        );

        if (!$finalJobsUpdate->execute()) {
            throw new Exception(
                'Unable to submit complete SAF to Owner: ' .
                $finalJobsUpdate->error
            );
        }

        $finalJobsUpdate->close();

        $finalAssignmentsUpdate = $conn->prepare("
            UPDATE inspector_assignments ia
            INNER JOIN maintenance_jobs mj
                ON mj.id = ia.job_id
            SET
                ia.status = 'submitted',
                ia.submitted_at = NOW()
            WHERE
                mj.saf_id = ?
                AND mj.job_type = 'saf_inspection'
                AND ia.inspector_id = ?
        ");

        if (!$finalAssignmentsUpdate) {
            throw new Exception(
                'Unable to prepare final Inspector submission: ' .
                $conn->error
            );
        }

        $finalAssignmentsUpdate->bind_param(
            'ii',
            $safId,
            $inspectorId
        );

        if (!$finalAssignmentsUpdate->execute()) {
            throw new Exception(
                'Unable to update final Inspector submission: ' .
                $finalAssignmentsUpdate->error
            );
        }

        $finalAssignmentsUpdate->close();

        $conn->commit();

        $_SESSION['clear_pat_draft_job'] =
            $jobId;

        if (isset($_SESSION['pat_pending_evidence'][$jobId])) {
            unset($_SESSION['pat_pending_evidence'][$jobId]);
        }

        $_SESSION['success'] =
            'Complete SAF submitted to Owner successfully. '
            . count($expectedAssets)
            . ' selected asset checklist(s) were completed.';

        header('Location: inspections.php');
        exit;

    } catch (Throwable $e) {

        $conn->rollback();

        throw $e;
    }

} catch (Throwable $e) {

    failBack(
        $e->getMessage(),
        $jobId,
        $assignmentId
    );
}
?>