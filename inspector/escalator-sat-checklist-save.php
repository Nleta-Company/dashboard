<?php
/* =========================================================
   NLETA INSPECTOR — ESCALATOR SAT CHECKLIST SAVE
   ---------------------------------------------------------
   ECHK-02 — Rev.1
   Asset Type: ESCALATOR / TRAVELLATOR
   Module: Site Acceptance Test (SAT)

   This save handler matches:
   inspector/escalator-sat-checklist.php

   FIXED CHECKPOINTS:
   E-SAT-001 -> E-SAT-038

   RESULT CODES:
   C  = Complied
   NC = Non-Complied
   NA = Not Applicable
   CA = Conditional Acceptance

   MANDATORY:
   NC -> Photograph + Engineering Remark
   CA -> Photograph + Technical Justification
   Video -> Optional
   Numeric / OK-Low readings -> Required where applicable

   FINAL LOCK:
   submitted / final_submitted assignment cannot be edited.

   IMPORTANT:
   Save is for THIS asset/job only.
   It does not submit the complete SAF to Owner.
   Complete SAF submission remains with the separate SAF
   final-submit workflow.

   TIMEZONE:
   Asia/Kolkata
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

/* =========================================================
   HELPERS
========================================================= */

function satBackError(string $message, int $jobId = 0, int $assignmentId = 0): void
{
    $_SESSION['error'] = $message;

    $url = 'escalator-sat-checklist.php';

    $params = [];

    if ($jobId > 0) {
        $params['job_id'] = $jobId;
    }

    if ($assignmentId > 0) {
        $params['assignment_id'] = $assignmentId;
    }

    if ($params) {
        $url .= '?' . http_build_query($params);
    }

    header('Location: ' . $url);
    exit;
}

function satCsrfCheck(): void
{
    $token = (string)($_POST['csrf_token'] ?? '');

    if (function_exists('validateCSRFToken')) {
        if (!validateCSRFToken($token)) {
            throw new RuntimeException(
                'Invalid security token. Please refresh the checklist and submit again.'
            );
        }

        return;
    }

    if (function_exists('validate_csrf_token')) {
        if (!validate_csrf_token($token)) {
            throw new RuntimeException(
                'Invalid security token. Please refresh the checklist and submit again.'
            );
        }

        return;
    }

    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');

    if (
        $sessionToken !== '' &&
        (
            $token === '' ||
            !hash_equals($sessionToken, $token)
        )
    ) {
        throw new RuntimeException(
            'Invalid security token. Please refresh the checklist and submit again.'
        );
    }
}

function satUploadItem(array $files, string $ref): array
{
    return [
        'name'     => (string)($files['name'][$ref] ?? ''),
        'type'     => (string)($files['type'][$ref] ?? ''),
        'tmp_name' => (string)($files['tmp_name'][$ref] ?? ''),
        'error'    => (int)($files['error'][$ref] ?? UPLOAD_ERR_NO_FILE),
        'size'     => (int)($files['size'][$ref] ?? 0),
    ];
}

function satSavePhoto(array $file, int $jobId, string $ref): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException(
            $ref . ': Photograph upload failed.'
        );
    }

    if (
        empty($file['tmp_name']) ||
        !is_uploaded_file($file['tmp_name'])
    ) {
        throw new RuntimeException(
            $ref . ': Invalid photograph upload.'
        );
    }

    if (
        (int)$file['size'] <= 0 ||
        (int)$file['size'] > (10 * 1024 * 1024)
    ) {
        throw new RuntimeException(
            $ref . ': Photograph must be 3 MB or smaller.'
        );
    }

    $mime = '';

    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);

        if ($fi) {
            $mime = (string)finfo_file(
                $fi,
                $file['tmp_name']
            );

            finfo_close($fi);
        }
    }

    if (
        $mime !== '' &&
        !in_array(
            $mime,
            [
                'image/jpeg',
                'image/png',
                'image/webp',
            ],
            true
        )
    ) {
        throw new RuntimeException(
            $ref . ': Photograph must be JPG, JPEG, PNG or WEBP.'
        );
    }

    $extension =
        strtolower(
            pathinfo(
                (string)$file['name'],
                PATHINFO_EXTENSION
            )
        );

    if (!in_array(
        $extension,
        ['jpg', 'jpeg', 'png', 'webp'],
        true
    )) {
        throw new RuntimeException(
            $ref . ': Invalid photograph extension.'
        );
    }

    $uploadDir =
        __DIR__ .
        '/../uploads/inspections/' .
        $jobId;

    if (
        !is_dir($uploadDir) &&
        !mkdir($uploadDir, 0775, true) &&
        !is_dir($uploadDir)
    ) {
        throw new RuntimeException(
            $ref . ': Unable to create evidence folder.'
        );
    }

    $safeRef =
        preg_replace(
            '/[^A-Za-z0-9_-]/',
            '_',
            $ref
        );

    $filename =
        $safeRef .
        '_photo_' .
        date('Ymd_His') .
        '_' .
        bin2hex(random_bytes(4)) .
        '.' .
        $extension;

    $destination =
        $uploadDir .
        DIRECTORY_SEPARATOR .
        $filename;

    if (!move_uploaded_file(
        $file['tmp_name'],
        $destination
    )) {
        throw new RuntimeException(
            $ref . ': Unable to save photograph.'
        );
    }

    return
        'uploads/inspections/' .
        $jobId .
        '/' .
        $filename;
}

function satSaveVideo(array $file, int $jobId, string $ref): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException(
            $ref . ': Video upload failed.'
        );
    }

    if (
        empty($file['tmp_name']) ||
        !is_uploaded_file($file['tmp_name'])
    ) {
        throw new RuntimeException(
            $ref . ': Invalid video upload.'
        );
    }

    if (
        (int)$file['size'] <= 0 ||
        (int)$file['size'] > (50 * 1024 * 1024)
    ) {
        throw new RuntimeException(
            $ref . ': Video must be 20 MB or smaller.'
        );
    }

    $mime = '';

    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);

        if ($fi) {
            $mime = (string)finfo_file(
                $fi,
                $file['tmp_name']
            );

            finfo_close($fi);
        }
    }

    $allowedMimes = [
        'video/mp4',
        'video/webm',
        'video/quicktime',
        'video/x-msvideo',
    ];

    if (
        $mime !== '' &&
        !in_array($mime, $allowedMimes, true)
    ) {
        throw new RuntimeException(
            $ref . ': Invalid video type.'
        );
    }

    $extension =
        strtolower(
            pathinfo(
                (string)$file['name'],
                PATHINFO_EXTENSION
            )
        );

    if (!in_array(
        $extension,
        ['mp4', 'webm', 'mov', 'avi'],
        true
    )) {
        throw new RuntimeException(
            $ref . ': Video must be MP4, WEBM, MOV or AVI.'
        );
    }

    $uploadDir =
        __DIR__ .
        '/../uploads/inspections/' .
        $jobId;

    if (
        !is_dir($uploadDir) &&
        !mkdir($uploadDir, 0775, true) &&
        !is_dir($uploadDir)
    ) {
        throw new RuntimeException(
            $ref . ': Unable to create evidence folder.'
        );
    }

    $safeRef =
        preg_replace(
            '/[^A-Za-z0-9_-]/',
            '_',
            $ref
        );

    $filename =
        $safeRef .
        '_video_' .
        date('Ymd_His') .
        '_' .
        bin2hex(random_bytes(4)) .
        '.' .
        $extension;

    $destination =
        $uploadDir .
        DIRECTORY_SEPARATOR .
        $filename;

    if (!move_uploaded_file(
        $file['tmp_name'],
        $destination
    )) {
        throw new RuntimeException(
            $ref . ': Unable to save video.'
        );
    }

    return
        'uploads/inspections/' .
        $jobId .
        '/' .
        $filename;
}

function satDeleteUploadedFiles(array $paths): void
{
    foreach ($paths as $relativePath) {
        if (!$relativePath) {
            continue;
        }

        $absolute =
            __DIR__ .
            '/../' .
            ltrim($relativePath, '/\\');

        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }
}

/* =========================================================
   REQUEST METHOD
========================================================= */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: inspections.php');
    exit;
}

$inspectorId =
    (int)(
        $_SESSION['uid'] ??
        $_SESSION['user_id'] ??
        0
    );

$jobId =
    (int)($_POST['job_id'] ?? 0);

$assignmentId =
    (int)($_POST['assignment_id'] ?? 0);

if (
    $inspectorId <= 0 ||
    $jobId <= 0 ||
    $assignmentId <= 0
) {
    satBackError(
        'Invalid Escalator SAT checklist request.',
        $jobId,
        $assignmentId
    );
}

$createdFiles = [];

try {

    /* =====================================================
       CSRF
    ===================================================== */

    satCsrfCheck();

    /* =====================================================
       FIXED ECHK-02 MASTER
    ===================================================== */

    $checkpoints = [

        'E-SAT-001' => [
            'name' => 'Travel direction operation',
            'unit' => 'Pass / Fail'
        ],

        'E-SAT-002' => [
            'name' => 'Start & stop operation',
            'unit' => 'Pass / Fail'
        ],

        'E-SAT-003' => [
            'name' => 'Normal running performance',
            'unit' => 'Pass / Fail'
        ],

        'E-SAT-004' => [
            'name' => 'Reverse direction operation',
            'unit' => 'Pass / Fail'
        ],

        'E-SAT-005' => [
            'name' => 'Noise & vibration during operation',
            'unit' => 'Pass / Fail'
        ],

        'E-SAT-006' => [
            'name' => 'Abnormal mechanical sound',
            'unit' => 'Pass / Fail'
        ],

        'E-SAT-007' => [
            'name' => 'Brake slip distance',
            'unit' => 'mm'
        ],

        'E-SAT-008' => [
            'name' => 'Brake engagement performance',
            'unit' => 'Pass / Fail'
        ],

        'E-SAT-009' => [
            'name' => 'Brake release operation',
            'unit' => 'Pass / Fail'
        ],

        'E-SAT-010' => [
            'name' => 'Drive chain condition',
            'unit' => 'Pass / Fail'
        ],

        'E-SAT-011' => [
            'name' => 'Drive chain tension',
            'unit' => 'mm'
        ],

        'E-SAT-012' => [
            'name' => 'Gearbox oil level',
            'unit' => 'OK / Low'
        ],

        'E-SAT-013' => [
            'name' => 'Gearbox oil condition',
            'unit' => 'Pass / Fail'
        ],

        'E-SAT-014' => [
            'name' => 'Drive shaft alignment',
            'unit' => 'Pass / Fail'
        ],

        'E-SAT-015' => [
            'name' => 'Handrail speed equality',
            'unit' => '%'
        ],

        'E-SAT-016' => [
            'name' => 'Handrail synchronization',
            'unit' => 'Pass / Fail'
        ],

        'E-SAT-017' => [
            'name' => 'Comb gap measurement',
            'unit' => 'mm'
        ],

        'E-SAT-018' => [
            'name' => 'Step-to-step gap',
            'unit' => 'mm'
        ],

        'E-SAT-019' => [
            'name' => 'Step-to-skirt clearance',
            'unit' => 'mm'
        ],

        'E-SAT-020' => [
            'name' => 'Step chain tension',
            'unit' => 'mm'
        ],

        'E-SAT-021' => [
            'name' => 'Roller clearance',
            'unit' => 'mm'
        ],

        'E-SAT-022' => [
            'name' => 'Spring length',
            'unit' => 'mm'
        ],

        'E-SAT-023' => [
            'name' => 'Emergency stop switch (Top)',
            'unit' => 'Pass / Fail'
        ],

        'E-SAT-024' => [
            'name' => 'Emergency stop switch (Bottom)',
            'unit' => 'Pass / Fail'
        ],

        'E-SAT-025' => [
            'name' => 'Comb impact safety switch',
            'unit' => 'Pass / Fail'
        ],

        'E-SAT-026' => [
            'name' => 'Skirt obstruction switch',
            'unit' => 'Pass / Fail'
        ],

        'E-SAT-027' => [
            'name' => 'Broken step detection',
            'unit' => 'Pass / Fail'
        ],

        'E-SAT-028' => [
            'name' => 'Missing step detection',
            'unit' => 'Pass / Fail'
        ],

        'E-SAT-029' => [
            'name' => 'Handrail inlet protection switch',
            'unit' => 'Pass / Fail'
        ],

        'E-SAT-030' => [
            'name' => 'Step sag detection',
            'unit' => 'Pass / Fail'
        ],

        'E-SAT-031' => [
            'name' => 'Inspection pendant operation',
            'unit' => 'Pass / Fail'
        ],

        'E-SAT-032' => [
            'name' => 'Inspection mode operation',
            'unit' => 'Pass / Fail'
        ],

        'E-SAT-033' => [
            'name' => 'Direction selector switch',
            'unit' => 'Pass / Fail'
        ],

        'E-SAT-034' => [
            'name' => 'Main controller functional test',
            'unit' => 'Pass / Fail'
        ],

        'E-SAT-035' => [
            'name' => 'Phase failure protection',
            'unit' => 'Pass / Fail'
        ],

        'E-SAT-036' => [
            'name' => 'Overload protection',
            'unit' => 'Pass / Fail'
        ],

        'E-SAT-037' => [
            'name' => 'Auto restart behaviour',
            'unit' => 'Pass / Fail'
        ],

        'E-SAT-038' => [
            'name' => 'Safety circuit continuity',
            'unit' => 'Pass / Fail'
        ],
    ];

    if (count($checkpoints) !== 38) {
        throw new RuntimeException(
            'ECHK-02 configuration error. Expected 38 checkpoints.'
        );
    }

    $numericRefs = [
        'E-SAT-007',
        'E-SAT-011',
        'E-SAT-015',
        'E-SAT-017',
        'E-SAT-018',
        'E-SAT-019',
        'E-SAT-020',
        'E-SAT-021',
        'E-SAT-022',
    ];

    $okLowRefs = [
        'E-SAT-012'
    ];


    /* =====================================================
       INSPECTOR MASTER METADATA
       -----------------------------------------------------
       This is the source of truth for Owner/Admin.
       Priority is saved together with every checkpoint row.
    ===================================================== */

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

    /*
     * ECHK-02 Section Master
     * ----------------------
     * $checkpoints above is the flat 38-checkpoint save master.
     * It does NOT contain an "items" child array.
     *
     * Therefore do not loop:
     *     $sectionData['items']
     *
     * Section ownership is explicitly mapped here so the saved
     * Inspector metadata contains the same Section shown by the
     * Escalator SAT checklist UI.
     *
     * A = E-SAT-001  to E-SAT-006
     * B = E-SAT-007  to E-SAT-014
     * C = E-SAT-015  to E-SAT-022
     * D = E-SAT-023  to E-SAT-030
     * E = E-SAT-031  to E-SAT-038
     */
    $sectionByCheckpoint = [];

    for ($i = 1; $i <= 38; $i++) {

        $ref = 'E-SAT-' . str_pad(
            (string)$i,
            3,
            '0',
            STR_PAD_LEFT
        );

        if ($i <= 6) {
            $sectionByCheckpoint[$ref] =
                'A — FUNCTIONAL OPERATION TEST';

        } elseif ($i <= 14) {
            $sectionByCheckpoint[$ref] =
                'B — BRAKE & DRIVE SYSTEM';

        } elseif ($i <= 22) {
            $sectionByCheckpoint[$ref] =
                'C — STEP & HANDRAIL MEASUREMENTS';

        } elseif ($i <= 30) {
            $sectionByCheckpoint[$ref] =
                'D — SAFETY DEVICE FUNCTIONAL TEST';

        } else {
            $sectionByCheckpoint[$ref] =
                'E — INSPECTION & CONTROL FUNCTIONS';
        }
    }

    if (count($sectionByCheckpoint) !== 38) {
        throw new RuntimeException(
            'ECHK-02 section configuration error. Expected 38 sections.'
        );
    }

    if (count($priorityByCheckpoint) !== 38) {
        throw new RuntimeException(
            'ECHK-02 priority configuration error. Expected 38 priorities.'
        );
    }

    /* =====================================================
       VERIFY ASSIGNMENT + JOB
    ===================================================== */

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

            s.psga_no,
            s.saf_no,
            s.service_code,
            s.service_name,
            s.status AS saf_status,

            a.asset_type,
            a.display_asset_id,
            a.asset_name,
            a.location

        FROM inspector_assignments ia

        INNER JOIN maintenance_jobs mj
            ON mj.id = ia.job_id

        INNER JOIN saf_contracts s
            ON s.id = mj.saf_id

        LEFT JOIN assets a
            ON a.id = mj.asset_id

        WHERE
            ia.id = ?
            AND ia.job_id = ?
            AND ia.inspector_id = ?
            AND mj.job_type = 'saf_inspection'

        LIMIT 1
    ");

    if (!$stmt) {
        throw new RuntimeException(
            'Unable to verify Escalator SAT assignment: ' .
            $conn->error
        );
    }

    $stmt->bind_param(
        'iii',
        $assignmentId,
        $jobId,
        $inspectorId
    );

    if (!$stmt->execute()) {
        throw new RuntimeException(
            'Unable to verify Escalator SAT assignment: ' .
            $stmt->error
        );
    }

    $job = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$job) {
        throw new RuntimeException(
            'This Escalator SAT inspection is not assigned to this Inspector.'
        );
    }

    /* =====================================================
       ESCALATOR / TRAVELLATOR ONLY
    ===================================================== */

    $assetType =
        strtolower(
            trim(
                (string)($job['asset_type'] ?? '')
            )
        );

    if (
        !str_contains($assetType, 'escalator') &&
        !str_contains($assetType, 'travellator')
    ) {
        throw new RuntimeException(
            'Invalid checklist routing. This save endpoint is only for Escalator / Travellator.'
        );
    }

    /* =====================================================
       SAT ONLY
    ===================================================== */

    $serviceCode =
        strtoupper(
            trim(
                (string)($job['service_code'] ?? '')
            )
        );

    if ($serviceCode !== 'SAT') {
        throw new RuntimeException(
            'Invalid checklist routing. Assigned service is ' .
            ($serviceCode ?: 'Unknown') .
            ', not SAT.'
        );
    }

    /* =====================================================
       SAF ACCEPTED
    ===================================================== */

    $safStatus =
        strtolower(
            trim(
                (string)($job['saf_status'] ?? '')
            )
        );

    if (
        $safStatus !== '' &&
        $safStatus !== 'accepted'
    ) {
        throw new RuntimeException(
            'The SAF must be accepted by the Owner before inspection.'
        );
    }

    /* =====================================================
       FINAL LOCK
    ===================================================== */

    $assignmentStatus =
        strtolower(
            trim(
                (string)($job['assignment_status'] ?? '')
            )
        );

    if (in_array(
        $assignmentStatus,
        ['submitted', 'final_submitted'],
        true
    )) {
        throw new RuntimeException(
            'This Escalator SAT checklist is locked after Final Submit.'
        );
    }

    /* =====================================================
       ATTENDANCE OTP GUARD
    ===================================================== */

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

        $ownerId =
            (int)($job['owner_id'] ?? 0);

        $attendanceStmt->bind_param(
            'iiii',
            $assignmentId,
            $jobId,
            $inspectorId,
            $ownerId
        );

        if ($attendanceStmt->execute()) {

            $attendanceRow =
                $attendanceStmt
                    ->get_result()
                    ->fetch_assoc();

            $attendanceVerified =
                !empty(
                    $attendanceRow['verified_at']
                );
        }

        $attendanceStmt->close();
    }

    if (!$attendanceVerified) {
        throw new RuntimeException(
            'Owner site attendance OTP is not verified. Please complete attendance verification first.'
        );
    }

    /* =====================================================
       EXISTING CHECKLIST
       Preserve old evidence paths when no new file is sent.
    ===================================================== */

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

    /* =====================================================
       POST ARRAYS
    ===================================================== */

    $postedResults =
        is_array($_POST['result'] ?? null)
            ? $_POST['result']
            : [];

    $postedReadings =
        is_array($_POST['reading'] ?? null)
            ? $_POST['reading']
            : [];

    $postedRemarks =
        is_array($_POST['remark'] ?? null)
            ? $_POST['remark']
            : [];

    /*
     * CA uses Photograph + Technical Justification only.
     * Engineering Remark is mandatory for NC, not for CA.
     * A ca_remark field is accepted if an older UI sends it,
     * but it is not required for CA.
     */
    $postedCaRemarks =
        is_array($_POST['ca_remark'] ?? null)
            ? $_POST['ca_remark']
            : [];

    $postedJustifications =
        is_array(
            $_POST['technical_justification'] ?? null
        )
            ? $_POST['technical_justification']
            : [];

    $photoFiles =
        is_array($_FILES['photo'] ?? null)
            ? $_FILES['photo']
            : [];

    $videoFiles =
        is_array($_FILES['video'] ?? null)
            ? $_FILES['video']
            : [];

    /* =====================================================
       BUILD RESULTS
    ===================================================== */

    $results = [];

    foreach ($checkpoints as $ref => $meta) {

        $result =
            strtoupper(
                trim(
                    (string)(
                        $postedResults[$ref] ?? ''
                    )
                )
            );

        if (!in_array(
            $result,
            ['C', 'NC', 'NA', 'CA'],
            true
        )) {
            throw new RuntimeException(
                $ref .
                ': Select C, NC, NA or CA.'
            );
        }

        $reading =
            trim(
                (string)(
                    $postedReadings[$ref] ?? ''
                )
            );

        /* Numeric reading validation */
        if (in_array(
            $ref,
            $numericRefs,
            true
        )) {

            if (
                $reading === '' ||
                !is_numeric($reading)
            ) {
                throw new RuntimeException(
                    $ref .
                    ': Numeric reading is required.'
                );
            }
        }

        /* OK / Low validation */
        if (in_array(
            $ref,
            $okLowRefs,
            true
        )) {

            if (!in_array(
                $reading,
                ['OK', 'Low'],
                true
            )) {
                throw new RuntimeException(
                    $ref .
                    ': Select OK or Low.'
                );
            }
        }

        $remark =
            trim(
                (string)(
                    $postedRemarks[$ref] ?? ''
                )
            );

        $caRemark =
            trim(
                (string)(
                    $postedCaRemarks[$ref] ?? ''
                )
            );

        $justification =
            trim(
                (string)(
                    $postedJustifications[$ref] ?? ''
                )
            );

        $photoPath =
            (string)(
                $existingResults[$ref]['photo_path']
                ??
                ''
            );

        $videoPath =
            (string)(
                $existingResults[$ref]['video_path']
                ??
                ''
            );

        /* =================================================
           NC
        ================================================= */

        if ($result === 'NC') {

            if ($remark === '') {
                throw new RuntimeException(
                    $ref .
                    ': Engineering Remark is required for NC.'
                );
            }

            $photoFile =
                satUploadItem(
                    $photoFiles,
                    $ref
                );

            if (
                ($photoFile['name'] ?? '') === '' ||
                ($photoFile['error'] ?? UPLOAD_ERR_NO_FILE)
                    === UPLOAD_ERR_NO_FILE
            ) {
                throw new RuntimeException(
                    $ref .
                    ': Photograph is required for NC.'
                );
            }

            $photoPath =
                satSavePhoto(
                    $photoFile,
                    $jobId,
                    $ref
                );

            $createdFiles[] = $photoPath;
        }

        /* =================================================
           CA
           Photograph + Technical Justification REQUIRED.
           Engineering Remark is NOT mandatory for CA.
        ================================================= */

        if ($result === 'CA') {

            if ($justification === '') {
                throw new RuntimeException(
                    $ref .
                    ': Technical Justification is required for CA.'
                );
            }

            $photoFile =
                satUploadItem(
                    $photoFiles,
                    $ref
                );

            if (
                ($photoFile['name'] ?? '') === '' ||
                ($photoFile['error'] ?? UPLOAD_ERR_NO_FILE)
                    === UPLOAD_ERR_NO_FILE
            ) {
                throw new RuntimeException(
                    $ref .
                    ': Photograph is required for CA.'
                );
            }

            $photoPath =
                satSavePhoto(
                    $photoFile,
                    $jobId,
                    $ref
                );

            $createdFiles[] = $photoPath;
        }

        /* =================================================
           OPTIONAL VIDEO
        ================================================= */

        $videoFile =
            satUploadItem(
                $videoFiles,
                $ref
            );

        if (
            ($videoFile['name'] ?? '') !== '' &&
            ($videoFile['error'] ?? UPLOAD_ERR_NO_FILE)
                !== UPLOAD_ERR_NO_FILE
        ) {

            $videoPath =
                satSaveVideo(
                    $videoFile,
                    $jobId,
                    $ref
                );

            $createdFiles[] = $videoPath;
        }

        $results[$ref] = [
            'checkpoint_id' =>
                $ref,

            'checkpoint_name' =>
                $meta['name'],

            'priority' =>
                $priorityByCheckpoint[$ref] ?? '—',

            'section' =>
                $sectionByCheckpoint[$ref] ?? '—',

            'checkpoint' =>
                $meta['name'],

            'unit' =>
                $meta['unit'],

            'reading' =>
                $reading,

            'result' =>
                $result,

            'remark' =>
                $remark,

            'ca_remark' =>
                $caRemark,

            'technical_justification' =>
                $justification,

            'photo_path' =>
                $photoPath,

            'video_path' =>
                $videoPath,
        ];
    }

    /* =====================================================
       FINAL STATUS
       Current UI sends PASS / FAIL.
    ===================================================== */

    $finalStatus =
        strtoupper(
            trim(
                (string)(
                    $_POST['final_status'] ?? ''
                )
            )
        );

    if (!in_array(
        $finalStatus,
        ['PASS', 'CONDITIONAL PASS', 'FAIL'],
        true
    )) {
        throw new RuntimeException(
            'Please select Final Inspection Status: PASS, CONDITIONAL PASS or FAIL.'
        );
    }

    /* =====================================================
       SECTION REMARKS
    ===================================================== */

    $sectionRemarks = [];

    foreach (
        ['A', 'B', 'C', 'D', 'E']
        as $section
    ) {
        $sectionRemarks[$section] =
            trim(
                (string)(
                    $_POST['section_remarks'][$section]
                    ??
                    ''
                )
            );
    }

    /* =====================================================
       FINAL REPRESENTATIVES
    ===================================================== */

    $inspectorSignature =
        trim(
            (string)(
                $_POST['inspector_signature']
                ??
                ''
            )
        );

    $ownerRepresentative =
        trim(
            (string)(
                $_POST['owner_representative']
                ??
                ''
            )
        );

    $amcRepresentative =
        trim(
            (string)(
                $_POST['amc_representative']
                ??
                ''
            )
        );

    /* =====================================================
       PAYLOAD
    ===================================================== */

    $payload = [

        'checklist_code' =>
            'ECHK-02',

        'checklist_revision' =>
            'Rev.1',

        'module' =>
            'SAT',

        'asset_type' =>
            'Escalator',

        'service_code' =>
            'SAT',

        'service_name' =>
            (string)(
                $job['service_name']
                ??
                'SAT'
            ),

        'timezone' =>
            'Asia/Kolkata',

        'saved_at_ist' =>
            date('Y-m-d H:i:s'),

        'inspector_id' =>
            $inspectorId,

        'assignment_id' =>
            $assignmentId,

        'job_id' =>
            $jobId,

        'saf_id' =>
            (int)$job['saf_id'],

        'asset_id' =>
            (int)$job['asset_id'],

        'checkpoint_count' =>
            38,

        'result_codes' => [
            'C' =>
                'Complied',
            'NC' =>
                'Non-Complied',
            'NA' =>
                'Not Applicable',
            'CA' =>
                'Conditional Acceptance',
        ],

        'checkpoint_metadata' =>
            array_map(
                function ($ref, $meta) use ($priorityByCheckpoint, $sectionByCheckpoint) {
                    return [
                        'checkpoint_id' =>
                            $ref,
                        'checkpoint_name' =>
                            $meta['name'],
                        'priority' =>
                            $priorityByCheckpoint[$ref] ?? '—',
                        'section' =>
                            $sectionByCheckpoint[$ref] ?? '—',
                    ];
                },
                array_keys($checkpoints),
                array_values($checkpoints)
            ),

        'results' =>
            $results,

        'section_remarks' =>
            $sectionRemarks,

        'final_status' =>
            $finalStatus,

        'inspector_signature' =>
            $inspectorSignature,

        'owner_representative' =>
            $ownerRepresentative,

        'amc_representative' =>
            $amcRepresentative,

        'acceptance_result' =>
            $finalStatus,

        'reading_mode' =>
            'Source Defined',

        'evidence_rules' => [
            'NC' =>
                'Photograph + Engineering Remark REQUIRED',
            'CA' =>
                'Photograph + Technical Justification REQUIRED',
            'VIDEO' =>
                'OPTIONAL',
        ],
    ];

    $json =
        json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_PRETTY_PRINT |
            JSON_THROW_ON_ERROR
        );

    /* =====================================================
       TRANSACTION
    ===================================================== */

    $conn->begin_transaction();

    /* =====================================================
       RECHECK ASSIGNMENT INSIDE TRANSACTION
    ===================================================== */

    $lockStmt = $conn->prepare("
        SELECT
            status
        FROM inspector_assignments
        WHERE
            id = ?
            AND job_id = ?
            AND inspector_id = ?
        LIMIT 1
        FOR UPDATE
    ");

    if (!$lockStmt) {
        throw new RuntimeException(
            'Unable to lock Inspector assignment: ' .
            $conn->error
        );
    }

    $lockStmt->bind_param(
        'iii',
        $assignmentId,
        $jobId,
        $inspectorId
    );

    if (!$lockStmt->execute()) {
        throw new RuntimeException(
            'Unable to lock Inspector assignment: ' .
            $lockStmt->error
        );
    }

    $lockedAssignment =
        $lockStmt
            ->get_result()
            ->fetch_assoc();

    $lockStmt->close();

    if (!$lockedAssignment) {
        throw new RuntimeException(
            'Inspector assignment was not found.'
        );
    }

    $lockedStatus =
        strtolower(
            trim(
                (string)(
                    $lockedAssignment['status']
                    ??
                    ''
                )
            )
        );

    if (in_array(
        $lockedStatus,
        ['submitted', 'final_submitted'],
        true
    )) {
        throw new RuntimeException(
            'This Escalator SAT checklist is locked after Final Submit.'
        );
    }

    /* =====================================================
       SAVE CHECKLIST TO THIS ASSET JOB
    ===================================================== */

    $updateStmt = $conn->prepare("
        UPDATE maintenance_jobs
        SET
            checklist = ?,
            status = 'completed',
            updated_at = NOW()
        WHERE
            id = ?
            AND owner_id = ?
            AND saf_id = ?
            AND asset_id = ?
            AND job_type = 'saf_inspection'
        LIMIT 1
    ");

    if (!$updateStmt) {
        throw new RuntimeException(
            'Unable to prepare Escalator SAT checklist save: ' .
            $conn->error
        );
    }

    $ownerId =
        (int)$job['owner_id'];

    $safId =
        (int)$job['saf_id'];

    $assetId =
        (int)$job['asset_id'];

    $updateStmt->bind_param(
        'siiii',
        $json,
        $jobId,
        $ownerId,
        $safId,
        $assetId
    );

    if (!$updateStmt->execute()) {
        throw new RuntimeException(
            'Unable to save Escalator SAT checklist: ' .
            $updateStmt->error
        );
    }

    if ($updateStmt->affected_rows < 0) {
        throw new RuntimeException(
            'Escalator SAT checklist could not be saved.'
        );
    }

    $updateStmt->close();

    /* =====================================================
       SAVE EVIDENCE INDEX
       -----------------------------------------------------
       Keep the checklist save independent from the optional
       evidence index table. If the table exists, synchronize it.
       If it does not exist, the checklist itself is still saved.
    ===================================================== */

    $evidenceTable = $conn->query(
        "SHOW TABLES LIKE 'saf_checklist_evidence'"
    );

    if ($evidenceTable && $evidenceTable->num_rows > 0) {

        $deleteEvidence = $conn->prepare("
            DELETE FROM saf_checklist_evidence
            WHERE job_id = ?
        ");

        if (!$deleteEvidence) {
            throw new RuntimeException(
                'Unable to prepare SAT evidence cleanup: ' .
                $conn->error
            );
        }

        $deleteEvidence->bind_param(
            'i',
            $jobId
        );

        if (!$deleteEvidence->execute()) {
            throw new RuntimeException(
                'Unable to clear previous SAT evidence: ' .
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
                'Unable to prepare SAT evidence save: ' .
                $conn->error
            );
        }

        foreach ($results as $ref => $row) {

            $result =
                (string)($row['result'] ?? '');

            $photoPath =
                (string)($row['photo_path'] ?? '');

            $videoPath =
                (string)($row['video_path'] ?? '');

            if (
                $photoPath === '' &&
                $videoPath === ''
            ) {
                continue;
            }

            $documentPath = '';

            $insertEvidence->bind_param(
                'isssss',
                $jobId,
                $ref,
                $result,
                $photoPath,
                $videoPath,
                $documentPath
            );

            if (!$insertEvidence->execute()) {
                throw new RuntimeException(
                    'Unable to save SAT evidence for ' .
                    $ref .
                    ': ' .
                    $insertEvidence->error
                );
            }
        }

        $insertEvidence->close();
    }

    /* =====================================================
       ASSIGNMENT STATUS
       -----------------------------------------------------
       A completed checklist marks THIS asset assignment as
       completed. It does NOT submit the whole SAF to Owner.
    ===================================================== */

    $assetAssignmentStatus =
        in_array(
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
            AND status NOT IN ('submitted','final_submitted')
    ");

    if (!$assignmentUpdate) {
        throw new RuntimeException(
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
        throw new RuntimeException(
            'Unable to synchronize Inspector assignment: ' .
            $assignmentUpdate->error
        );
    }

    $assignmentUpdate->close();

    /* Verify that the final checklist data and workflow status
       were actually written before committing. */
    $verify = $conn->prepare("
        SELECT
            mj.checklist,
            mj.status AS job_status,
            ia.status AS assignment_status
        FROM maintenance_jobs mj
        INNER JOIN inspector_assignments ia
            ON ia.id = ?
            AND ia.job_id = mj.id
            AND ia.inspector_id = ?
        WHERE
            mj.id = ?
        LIMIT 1
    ");

    if (!$verify) {
        throw new RuntimeException(
            'Unable to verify completed Escalator SAT checklist: ' .
            $conn->error
        );
    }

    $verify->bind_param(
        'iii',
        $assignmentId,
        $inspectorId,
        $jobId
    );

    if (!$verify->execute()) {
        throw new RuntimeException(
            'Unable to verify completed Escalator SAT checklist: ' .
            $verify->error
        );
    }

    $verifiedRow = $verify->get_result()->fetch_assoc();
    $verify->close();

    if (!$verifiedRow) {
        throw new RuntimeException(
            'Completed Escalator SAT checklist could not be verified.'
        );
    }

    $savedChecklist =
        json_decode(
            (string)($verifiedRow['checklist'] ?? ''),
            true
        );

    $savedFinalStatus =
        is_array($savedChecklist)
            ? strtoupper(
                trim(
                    (string)(
                        $savedChecklist['final_status'] ?? ''
                    )
                )
            )
            : '';

    if ($savedFinalStatus !== $finalStatus) {
        throw new RuntimeException(
            'Escalator SAT final status was not written correctly. ' .
            'Expected: ' .
            $finalStatus .
            ' | Database: ' .
            ($savedFinalStatus !== ''
                ? $savedFinalStatus
                : 'NULL')
        );
    }

    if (
        strtolower(
            trim(
                (string)($verifiedRow['job_status'] ?? '')
            )
        ) !== 'completed'
    ) {
        throw new RuntimeException(
            'Escalator SAT checklist was saved, but maintenance_jobs.status is not "completed".'
        );
    }

    if (
        strtolower(
            trim(
                (string)($verifiedRow['assignment_status'] ?? '')
            )
        ) !== 'completed'
    ) {
        throw new RuntimeException(
            'Escalator SAT checklist was saved, but Inspector assignment is not "completed".'
        );
    }

    $conn->commit();

    $_SESSION['success'] =
        'Escalator SAT checklist completed successfully for ' .
        (
            (string)(
                $job['display_asset_id']
                ??
                $job['asset_name']
                ??
                'asset'
            )
        ) .
        '.';

    /*
     * Redirect back to the same checklist so the saved data
     * is immediately visible.
     */
    header('Location: inspections.php');

    exit;

} catch (Throwable $e) {

    if ($conn->errno) {
        try {
            $conn->rollback();
        } catch (Throwable $ignore) {
        }
    }

    satDeleteUploadedFiles($createdFiles);

    satBackError(
        $e->getMessage(),
        $jobId,
        $assignmentId
    );
}
?>