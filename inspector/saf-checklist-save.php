<?php
/* =========================================================
   NLETA INSPECTOR — SAVE PAT CHECKLIST
   CHK-01 — Rev.1
   MASTER: PAT-001 -> PAT-131
   Result Codes: C / NC / NA / CA
   D2: Dynamic Per-Floor Landing Door Checklist

   RULES
   -----
   C  = Complied
   NC = Non-Complied
        • Photograph REQUIRED
        • Video OPTIONAL
        • Engineering Remark REQUIRED
   NA = Not Applicable
   CA = Conditional Acceptance
        • Technical Justification REQUIRED

   IMPORTANT
   ----------
   • Original PAT results are saved under results[]
   • Dynamic floor results are saved under floor_checklist[]
   • Floor rows do NOT create PAT-132+ IDs
   • Existing recheck data is preserved
   • Site-attendance OTP verification is enforced server-side
   • All timestamps are IST
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
    $_SESSION['error'] = $message;

    $url = 'saf-checklist.php?job_id=' . $jobId;

    if ($assignmentId > 0) {
        $url .= '&assignment_id=' . $assignmentId;
    }

    header('Location: ' . $url);
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

function uploadEvidence(
    string $type,
    string $tmp,
    string $originalName,
    int $size,
    int $jobId,
    string $reference
): string {
    if (!is_uploaded_file($tmp)) {
        throw new Exception(
            $reference . ': Invalid ' . ucfirst($type) . ' upload.'
        );
    }

    $max = $type === 'photo'
        ? 3 * 1024 * 1024
        : 20 * 1024 * 1024;

    if ($size <= 0 || $size > $max) {
        throw new Exception(
            $reference . ': ' . ucfirst($type) .
            ' exceeds the allowed size.'
        );
    }

    $mime = '';

    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);

        if ($fi) {
            $mime = (string)finfo_file($fi, $tmp);
            finfo_close($fi);
        }
    }

    $allowedMimes = $type === 'photo'
        ? ['image/jpeg', 'image/png', 'image/webp']
        : ['video/mp4', 'video/webm', 'video/quicktime'];

    if (!in_array($mime, $allowedMimes, true)) {
        throw new Exception(
            $reference . ': Invalid ' . $type . ' file type.'
        );
    }

    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if ($ext === '') {
        $ext = $type === 'photo' ? 'jpg' : 'mp4';
    }

    $safeReference = preg_replace(
        '/[^A-Za-z0-9_-]/',
        '_',
        $reference
    );

    $folder = __DIR__ .
        '/../uploads/inspections/' .
        $jobId;

    if (!is_dir($folder) && !mkdir($folder, 0775, true)) {
        throw new Exception(
            $reference . ': Unable to create evidence folder.'
        );
    }

    $filename =
        $safeReference . '_' .
        $type . '_' .
        date('Ymd_His') . '_' .
        bin2hex(random_bytes(4)) .
        '.' .
        $ext;

    $destination = $folder . '/' . $filename;

    if (!move_uploaded_file($tmp, $destination)) {
        throw new Exception(
            $reference . ': Unable to save ' . $type . '.'
        );
    }

    return 'uploads/inspections/' .
        $jobId .
        '/' .
        $filename;
}

/*
 * Return the PHP upload error for a fixed PAT evidence field.
 */
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

    /* =====================================================
       CHK-01 REV.1 FIXED MASTER — PAT-001 -> PAT-131
    ===================================================== */

    $expected = [];

    for ($i = 1; $i <= 131; $i++) {
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
        'landing_door_alignment' =>
            'Landing door alignment',

        'landing_door_manual_lock' =>
            'Landing door manual lock functional',

        'landing_push_button' =>
            'Landing push button operational',

        'braille_button' =>
            'Braille button provided',

        'hall_display' =>
            'Hall display operational',

        'entrance_dimensions' =>
            'Entrance width & height verified',

        'door_jamb_clearance' =>
            'Door to jamb clearance within limit'
    ];

    $landingFloors = parseServedFloors($job);

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

    $allowed =
        ['C', 'NC', 'NA', 'CA'];

    $results = [];
    $errors = [];

    /* =====================================================
       VALIDATE FIXED PAT-001 -> PAT-131
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

        $results[$ref] = [
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
                'checkpoint' =>
                    $floorDescription,

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
       LOAD EXISTING CHECKLIST BEFORE EVIDENCE VALIDATION
       This lets an already-uploaded NC photograph remain
       valid when the Inspector saves the same asset again.
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
       NC EVIDENCE RULE
       NC always requires a PHOTO.
       VIDEO is optional.
    ===================================================== */

    foreach ($expected as $ref) {

        $result = strtoupper(
            trim((string)($postedResults[$ref] ?? ''))
        );

        if ($result !== 'NC') {
            continue;
        }

        $existingPhoto = '';

        if (is_array($existingChecklist['results'][$ref] ?? null)) {
            $existingPhoto = trim(
                (string)(
                    $existingChecklist['results'][$ref]['evidence']['photo']
                    ?? $existingChecklist['results'][$ref]['photo']
                    ?? ''
                )
            );
        }

        if (
            fixedUploadError('photo', $ref) === UPLOAD_ERR_NO_FILE &&
            $existingPhoto === ''
        ) {
            $errors[] =
                $ref .
                ': Photograph is required for NC.';
        }
    }

    foreach ($landingFloors as $floorLabel) {

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

            if ($result !== 'NC') {
                continue;
            }

            $existingFloorPhoto = '';

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
            }

            if (
                floorUploadError(
                    'photo',
                    $floorLabel,
                    $floorKey
                ) === UPLOAD_ERR_NO_FILE &&
                $existingFloorPhoto === ''
            ) {
                $errors[] =
                    'Floor ' .
                    $floorLabel .
                    ' — ' .
                    $floorDescription .
                    ': Photograph is required for NC.';
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
       LOAD EXISTING CHECKLIST
       Preserve recheck/history fields.
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

    /* =====================================================
       EVIDENCE
    ===================================================== */

    $fixedEvidence = [];
    $floorEvidence = [];

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
                    ' upload failed.';

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
                as $floorKey => $floorDescription
            ) {

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
                        ' upload failed.';

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
        131;

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

        if (
            !empty(
                $currentChecklist['recheck_status']
            )
        ) {
            throw new Exception(
                'Original PAT checklist is already submitted. '
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

            $photoPath = (string)($evidence['photo'] ?? '');
            $videoPath = (string)($evidence['video'] ?? '');

            if ($photoPath === '' && $videoPath === '') {
                continue;
            }

            $evidenceResult = strtoupper(
                trim((string)($item['result'] ?? ''))
            );

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

                $evidenceResult = strtoupper(
                    trim((string)($item['result'] ?? ''))
                );

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

        $insertEvidence->close();

        /* =================================================
           SAVE THIS ASSET CHECKLIST
           -------------------------------------------------
           IMPORTANT:
           Do NOT send the whole SAF to Owner here.
           Each selected asset has its own checklist/job.
        ================================================= */

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
        ");

        if (!$update) {
            throw new Exception(
                'Unable to prepare checklist save: ' .
                $conn->error
            );
        }

        $ownerId = (int)$job['owner_id'];

        $update->bind_param(
            'sii',
            $json,
            $jobId,
            $ownerId
        );

        if (!$update->execute()) {
            throw new Exception(
                'Unable to save checklist: ' .
                $update->error
            );
        }

        $update->close();

        /*
         * A normal asset save does NOT submit the assignment.
         * It remains available so the Inspector can reopen it.
         */
        if ($saveMode !== 'final_submit') {

            $assignmentUpdate = $conn->prepare("
                UPDATE inspector_assignments
                SET
                    status = 'in_progress'
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
                'iii',
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
                        AND ia.status <> 'submitted'
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

        $_SESSION['success'] =
            'Complete SAF submitted to Owner successfully. '
            . count($expectedAssets)
            . ' selected asset checklist(s) were completed.';

        header('Location: inspections.php');
        exit;


        /*
         * Clear browser draft after a successful server save.
         */
        $_SESSION['clear_pat_draft_job'] =
            $jobId;

        $_SESSION['success'] =
            'CHK-01 Rev.1 PAT checklist submitted successfully. '
            . '131 fixed checkpoints, configured floor checkpoints, '
            . 'and uploaded photo/video evidence were saved.';

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
