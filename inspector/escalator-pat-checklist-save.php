<?php
/* =========================================================
   NLETA INSPECTOR — ESCALATOR PAT CHECKLIST SAVE
   E-CHK-PAT — Rev.1
   Asset Type: ESCALATOR / TRAVELLATOR
   Service: PAT

   RESULT CODES:
   C  = Complied
   NC = Non-Complied
   NA = Not Applicable
   CA = Conditional Acceptance

   MANDATORY:
   NC -> Photograph + Engineering Remark
   CA -> Technical Justification

   Lift PAT save endpoint is NOT used/modified here.
   Timezone: Asia/Kolkata
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

function backError(string $message, int $jobId, int $assignmentId): void
{
    $_SESSION['error'] = $message;

    header(
        'Location: escalator-pat-checklist.php?job_id=' .
        $jobId .
        '&assignment_id=' .
        $assignmentId
    );

    exit;
}

function validateCsrfRequest(): void
{
    $token = (string)($_POST['csrf_token'] ?? '');

    if (function_exists('validateCSRFToken')) {
        if (!validateCSRFToken($token)) {
            throw new Exception(
                'Invalid security token. Please refresh the checklist and submit again.'
            );
        }
        return;
    }

    if (function_exists('validate_csrf_token')) {
        if (!validate_csrf_token($token)) {
            throw new Exception(
                'Invalid security token. Please refresh the checklist and submit again.'
            );
        }
        return;
    }

    /*
     * Some NLETA installations use a session csrf_token without
     * exposing a helper function.
     */
    $sessionToken = (string)(
        $_SESSION['csrf_token'] ??
        $_SESSION['escalator_pat_csrf'] ??
        ''
    );

    if ($sessionToken !== '') {
        if ($token === '' || !hash_equals($sessionToken, $token)) {
            throw new Exception(
                'Invalid security token. Please refresh the checklist and submit again.'
            );
        }
    }
}

function uploadEvidence(
    string $type,
    string $tmpName,
    string $originalName,
    int $size,
    int $jobId,
    string $ref
): string {
    if (!is_uploaded_file($tmpName)) {
        throw new Exception($ref . ': Invalid uploaded file.');
    }

    $maxSize =
        $type === 'photo'
            ? 10 * 1024 * 1024
            : 20 * 1024 * 1024;

    if ($size <= 0 || $size > $maxSize) {
        throw new Exception(
            $ref . ': ' .
            ucfirst($type) .
            ' exceeds the allowed file size.'
        );
    }

    $mime = '';

    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo) {
            $mime = (string)finfo_file($finfo, $tmpName);
            finfo_close($finfo);
        }
    }

    $allowedPhoto = [
        'image/jpeg',
        'image/png',
        'image/webp'
    ];

    $allowedVideo = [
        'video/mp4',
        'video/webm',
        'video/quicktime',
        'video/x-msvideo'
    ];

    $allowed =
        $type === 'photo'
            ? $allowedPhoto
            : $allowedVideo;

    if ($mime !== '' && !in_array($mime, $allowed, true)) {
        throw new Exception(
            $ref . ': Invalid ' . $type . ' file type.'
        );
    }

    $extension =
        strtolower(
            pathinfo($originalName, PATHINFO_EXTENSION)
        );

    if ($extension === '') {
        $extension =
            $type === 'photo'
                ? 'jpg'
                : 'mp4';
    }

    $folder =
        __DIR__ .
        '/../uploads/inspections/' .
        $jobId;

    if (!is_dir($folder)) {
        if (!mkdir($folder, 0775, true) && !is_dir($folder)) {
            throw new Exception(
                $ref . ': Unable to create evidence folder.'
            );
        }
    }

    $safeRef =
        preg_replace(
            '/[^A-Za-z0-9_-]/',
            '_',
            $ref
        );

    $filename =
        $safeRef .
        '_' .
        $type .
        '_' .
        date('Ymd_His') .
        '_' .
        bin2hex(random_bytes(4)) .
        '.' .
        $extension;

    $destination =
        $folder . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($tmpName, $destination)) {
        throw new Exception(
            $ref . ': Unable to save ' . $type . ' evidence.'
        );
    }

    return
        'uploads/inspections/' .
        $jobId .
        '/' .
        $filename;
}

/* =========================================================
   E-CHK-PAT — 47 ACTIVE MASTER CHECKPOINTS (E-PAT-001 → E-PAT-047)
========================================================= */

$checkpoints = [
    'E-PAT-001' => 'Asset identification plate available',
    'E-PAT-002' => 'Emergency stop switch accessible',
    'E-PAT-003' => 'Emergency stop marking visible',
    'E-PAT-004' => 'Comb plate securely installed',
    'E-PAT-005' => 'Comb plate teeth undamaged',
    'E-PAT-006' => 'Skirt panel clearance safe',
    'E-PAT-007' => 'Step / pallet free from damage',
    'E-PAT-008' => 'Missing step detection functional',
    'E-PAT-009' => 'Handrail entry guards provided',
    'E-PAT-010' => 'Handrail condition safe',
    'E-PAT-011' => 'Balustrade glass / panel intact',
    'E-PAT-012' => 'Landing area obstruction free',
    'E-PAT-013' => 'Safety signage installed',
    'E-PAT-014' => 'Direction indicators visible',
    'E-PAT-015' => 'Warning labels legible',
    'E-PAT-016' => 'Electrical covers secured',
    'E-PAT-017' => 'Exposed rotating parts protected',
    'E-PAT-018' => 'Access panels locked',
    'E-PAT-019' => 'Passenger exit area safe',
    'E-PAT-020' => 'Loose components absent',
    'E-PAT-021' => 'Overall passenger safety satisfactory',
    'E-PAT-022' => 'Floor Plate Switch – Physical condition & functioning',
    'E-PAT-023' => 'Handrail Inlet Bottom Right – Physical condition & functioning',
    'E-PAT-024' => 'Handrail Inlet Bottom Left – Physical condition & functioning',
    'E-PAT-025' => 'Handrail Inlet Top Right – Physical condition & functioning',
    'E-PAT-026' => 'Handrail Inlet Top Left – Physical condition & functioning',
    'E-PAT-027' => 'Handrail Chain Broken Switch – Physical functioning condition',
    'E-PAT-028' => 'Comb Carrier Bottom Left Switch – Physical condition & functioning',
    'E-PAT-029' => 'Comb Carrier Bottom Right Switch – Physical condition & functioning',
    'E-PAT-030' => 'Comb Carrier Top Left Switch – Physical condition & functioning',
    'E-PAT-031' => 'Comb Carrier Top Right Switch – Physical condition & functioning',
    'E-PAT-032' => 'Step Up Thrust Top Switch – Physical condition & functioning',
    'E-PAT-033' => 'Step Up Thrust Top Bottom Switch – Physical condition & functioning',
    'E-PAT-034' => 'Direction Key Switch Top – Physical condition & functioning',
    'E-PAT-035' => 'Direction Key Switch Bottom – Physical condition & functioning',
    'E-PAT-036' => 'Emergency Stop Switch Top – Physical condition & functioning',
    'E-PAT-037' => 'Emergency Stop Switch Bottom – Physical condition & functioning',
    'E-PAT-038' => 'Emergency Stop Switch Middle – Physical condition & functioning',
    'E-PAT-039' => 'Step Sag Switch Top – Physical condition & functioning',
    'E-PAT-040' => 'Step Sag Switch Bottom – Physical condition & functioning',
    'E-PAT-041' => 'Check the condition and function of traffic lights',
    'E-PAT-042' => 'Check the condition and function of handrail lights, if applicable',
    'E-PAT-043' => 'Check for skirt brush condition and availability',
    'E-PAT-044' => 'Function of bottom pit light',
    'E-PAT-045' => 'Function of pit maintenance stop switch',
    'E-PAT-046' => 'Function and condition of controller main switch',
    'E-PAT-047' => 'Check for gear box oil leaks',
];

/* =========================================================
   E-CHK-PAT — CHECKPOINT-WISE PRIORITY
   ---------------------------------------------------------
   Priority is fixed against each E-PAT checkpoint, matching
   the PAT checklist UI. Inspector cannot edit Priority.
========================================================= */

$priorityByCheckpoint = [
    'E-PAT-001' => 'P2',
    'E-PAT-002' => 'P1',
    'E-PAT-003' => 'P2',
    'E-PAT-004' => 'P2',
    'E-PAT-005' => 'P1',
    'E-PAT-006' => 'P1',
    'E-PAT-007' => 'P1',
    'E-PAT-008' => 'P1',
    'E-PAT-009' => 'P1',
    'E-PAT-010' => 'P1',
    'E-PAT-011' => 'P2',
    'E-PAT-012' => 'P2',
    'E-PAT-013' => 'P2',
    'E-PAT-014' => 'P3',
    'E-PAT-015' => 'P2',
    'E-PAT-016' => 'P2',
    'E-PAT-017' => 'P1',
    'E-PAT-018' => 'P1',
    'E-PAT-019' => 'P2',
    'E-PAT-020' => 'P2',
    'E-PAT-021' => 'P1',
    'E-PAT-022' => 'P1',
    'E-PAT-023' => 'P1',
    'E-PAT-024' => 'P1',
    'E-PAT-025' => 'P1',
    'E-PAT-026' => 'P1',
    'E-PAT-027' => 'P1',
    'E-PAT-028' => 'P1',
    'E-PAT-029' => 'P1',
    'E-PAT-030' => 'P1',
    'E-PAT-031' => 'P1',
    'E-PAT-032' => 'P1',
    'E-PAT-033' => 'P1',
    'E-PAT-034' => 'P1',
    'E-PAT-035' => 'P1',
    'E-PAT-036' => 'P1',
    'E-PAT-037' => 'P1',
    'E-PAT-038' => 'P1',
    'E-PAT-039' => 'P1',
    'E-PAT-040' => 'P1',
    'E-PAT-041' => 'P2',
    'E-PAT-042' => 'P3',
    'E-PAT-043' => 'P2',
    'E-PAT-044' => 'P2',
    'E-PAT-045' => 'P1',
    'E-PAT-046' => 'P1',
    'E-PAT-047' => 'P1'
];

/* =========================================================
   E-CHK-PAT — CANONICAL CHECKPOINT METADATA
   ---------------------------------------------------------
   Inspector is the single source of truth for the saved
   checklist metadata. Owner/Admin must read these saved
   values from maintenance_jobs.checklist JSON.

   Each result stores:
   - checkpoint_id
   - checkpoint_name
   - priority
   - section
   - result
   - remark
   - technical_justification
========================================================= */

$sectionByCheckpoint = [];

foreach ($checkpoints as $ref => $description) {
    $number = (int)preg_replace('/\D+/', '', $ref);

    if ($number >= 1 && $number <= 6) {
        $sectionByCheckpoint[$ref] = 'A';
    } elseif ($number >= 7 && $number <= 12) {
        $sectionByCheckpoint[$ref] = 'B';
    } elseif ($number >= 13 && $number <= 18) {
        $sectionByCheckpoint[$ref] = 'C';
    } elseif ($number >= 19 && $number <= 20) {
        $sectionByCheckpoint[$ref] = 'D';
    } elseif ($number === 21) {
        $sectionByCheckpoint[$ref] = 'E';
    } elseif ($number >= 22 && $number <= 40) {
        $sectionByCheckpoint[$ref] = 'F';
    } elseif ($number >= 41 && $number <= 47) {
        $sectionByCheckpoint[$ref] = 'G';
    } else {
        $sectionByCheckpoint[$ref] = '';
    }
}


if (count($checkpoints) !== 47) {
    throw new Exception(
        'E-CHK-PAT master configuration error. Expected 47 active checkpoints.'
    );
}

/*
 * ACTIVE ID INTEGRITY:
 * The active Escalator PAT master must be exactly sequential:
 * E-PAT-001 through E-PAT-047, with no gaps or duplicate IDs.
 */
$expectedCheckpointIds = [];
for ($i = 1; $i <= 47; $i++) {
    $expectedCheckpointIds[] = 'E-PAT-' . str_pad((string)$i, 3, '0', STR_PAD_LEFT);
}

if (array_keys($checkpoints) !== $expectedCheckpointIds) {
    throw new Exception(
        'E-CHK-PAT master configuration error. Active checkpoint IDs must run sequentially from E-PAT-001 to E-PAT-047.'
    );
}

$inspectorId =
    (int)(
        $_SESSION['uid'] ??
        $_SESSION['user_id'] ??
        0
    );

$jobId =
    (int)(
        $_POST['job_id'] ??
        0
    );

$assignmentId =
    (int)(
        $_POST['assignment_id'] ??
        0
    );

$safId =
    (int)(
        $_POST['saf_id'] ??
        0
    );

if (
    $inspectorId <= 0 ||
    $jobId <= 0 ||
    $assignmentId <= 0
) {
    header('Location: inspections.php');
    exit;
}

try {

    validateCsrfRequest();

    /* =====================================================
       VERIFY ASSIGNMENT + ROUTING AGAIN ON SERVER
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
        throw new Exception(
            'Unable to prepare Inspector assignment verification: ' .
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
        throw new Exception(
            'Unable to verify Inspector assignment: ' .
            $stmt->error
        );
    }

    $job = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$job) {
        throw new Exception(
            'This Escalator PAT inspection is not assigned to this Inspector.'
        );
    }

    /* Assignment ID must be exact. */
    if (
        (int)$job['assignment_id'] !==
        $assignmentId
    ) {
        throw new Exception(
            'Invalid Inspector assignment.'
        );
    }

    /* Escalator / Travellator only. */
    $assetType =
        strtolower(
            trim(
                (string)(
                    $job['asset_type'] ??
                    ''
                )
            )
        );

    $isEscalator =
        str_contains($assetType, 'escalator') ||
        str_contains($assetType, 'travellator');

    if (!$isEscalator) {
        throw new Exception(
            'Invalid checklist routing. This endpoint is only for Escalator / Travellator.'
        );
    }

    /* PAT only. */
    $serviceCode =
        strtoupper(
            trim(
                (string)(
                    $job['service_code'] ??
                    ''
                )
            )
        );

    if ($serviceCode !== 'PAT') {
        throw new Exception(
            'Invalid checklist routing. Assigned service is ' .
            ($serviceCode ?: 'Unknown') .
            ', not PAT.'
        );
    }

    /* SAF must be accepted. */
    $safStatus =
        strtolower(
            trim(
                (string)(
                    $job['saf_status'] ??
                    ''
                )
            )
        );

    if (
        $safStatus !== '' &&
        $safStatus !== 'accepted'
    ) {
        throw new Exception(
            'This SAF has not been accepted by the Owner.'
        );
    }

    /* =====================================================
       SITE ATTENDANCE OTP
    ===================================================== */

    $attendanceTable =
        $conn->query(
            "SHOW TABLES LIKE 'inspector_attendance'"
        );

    if (
        !$attendanceTable ||
        $attendanceTable->num_rows === 0
    ) {
        throw new Exception(
            'Owner site-attendance OTP is not verified.'
        );
    }

    $attendance =
        $conn->prepare("
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
            'Unable to prepare site-attendance verification: ' .
            $conn->error
        );
    }

    $ownerId =
        (int)($job['owner_id'] ?? 0);

    $attendance->bind_param(
        'iiii',
        $assignmentId,
        $jobId,
        $inspectorId,
        $ownerId
    );

    if (!$attendance->execute()) {
        throw new Exception(
            'Unable to verify site attendance: ' .
            $attendance->error
        );
    }

    $attendanceRow =
        $attendance
            ->get_result()
            ->fetch_assoc();

    $attendance->close();

    if (
        !$attendanceRow ||
        empty($attendanceRow['verified_at'])
    ) {
        throw new Exception(
            'Owner site-attendance OTP is not verified. '
            . 'Please complete attendance verification first.'
        );
    }

    /* =====================================================
       LOAD EXISTING CHECKLIST
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

    $oldResults =
        is_array(
            $existingChecklist['results'] ?? null
        )
            ? $existingChecklist['results']
            : [];

/*
 * LEGACY ID MIGRATION:
 * Existing records created with the old active numbering
 * E-PAT-022..E-PAT-050 are mapped to the new sequential
 * E-PAT-019..E-PAT-047 numbering when they are saved again.
 * Old E-PAT-019..E-PAT-021 were removed checkpoints and are not
 * copied into the new active numbering.
 */
for ($oldNo = 22; $oldNo <= 50; $oldNo++) {
    $oldRef = 'E-PAT-' . str_pad((string)$oldNo, 3, '0', STR_PAD_LEFT);
    $newRef = 'E-PAT-' . str_pad((string)($oldNo - 3), 3, '0', STR_PAD_LEFT);

    if (
        !isset($oldResults[$newRef]) &&
        isset($oldResults[$oldRef])
    ) {
        $oldResults[$newRef] = $oldResults[$oldRef];
    }
}

/* =====================================================
   POST RESULTS
===================================================== */

    $postedResults =
        is_array($_POST['result'] ?? null)
            ? $_POST['result']
            : [];

    $postedRemarks =
        is_array($_POST['remark'] ?? null)
            ? $_POST['remark']
            : [];

    $postedJustifications =
        is_array(
            $_POST['technical_justification'] ?? null
        )
            ? $_POST['technical_justification']
            : [];

    $allowedResults = [
        'C',
        'NC',
        'NA',
        'CA'
    ];

    $results = [];
    $errors = [];

    foreach (
        $checkpoints as $ref => $description
    ) {

        $result =
            strtoupper(
                trim(
                    (string)(
                        $postedResults[$ref] ??
                        ''
                    )
                )
            );

        if (
            !in_array(
                $result,
                $allowedResults,
                true
            )
        ) {
            $errors[] =
                $ref .
                ': Result is required.';

            continue;
        }

        $remark =
            trim(
                (string)(
                    $postedRemarks[$ref] ??
                    ''
                )
            );

        $technicalJustification =
            trim(
                (string)(
                    $postedJustifications[$ref] ??
                    ''
                )
            );

        /*
         * NC mandatory:
         * Engineering Remark
         * Photograph is checked below.
         */
        if (
            $result === 'NC' &&
            $remark === ''
        ) {
            $errors[] =
                $ref .
                ': Engineering Remark is required for NC.';
        }

        /*
         * CA mandatory:
         * Technical Justification
         */
        if (
            $result === 'CA' &&
            $technicalJustification === ''
        ) {
            $errors[] =
                $ref .
                ': Technical Justification is required for CA.';
        }

        $results[$ref] = [
            'checkpoint_id' => $ref,
            'checkpoint_name' => $description,
            'priority' => $priorityByCheckpoint[$ref] ?? '',
            'section' => $sectionByCheckpoint[$ref] ?? '',
            'result' => $result,
            'remark' => $remark,
            'technical_justification' => $technicalJustification
        ];

        /*
         * Preserve previously saved evidence until
         * new evidence is uploaded.
         */
        if (
            isset($oldResults[$ref]) &&
            is_array($oldResults[$ref])
        ) {

            if (
                !empty(
                    $oldResults[$ref]['photo']
                )
            ) {
                $results[$ref]['photo'] =
                    (string)$oldResults[$ref]['photo'];
            }

            if (
                !empty(
                    $oldResults[$ref]['video']
                )
            ) {
                $results[$ref]['video'] =
                    (string)$oldResults[$ref]['video'];
            }
        }
    }

    /*
     * NC photograph requirement.
     */
    foreach (
        $checkpoints as $ref => $description
    ) {

        $result =
            strtoupper(
                trim(
                    (string)(
                        $postedResults[$ref] ??
                        ''
                    )
                )
            );

        if ($result !== 'NC') {
            continue;
        }

        $newPhotoError =
            (int)(
                $_FILES['photo']['error'][$ref] ??
                UPLOAD_ERR_NO_FILE
            );

        $existingPhoto =
            trim(
                (string)(
                    $oldResults[$ref]['photo'] ??
                    $oldResults[$ref]['evidence']['photo'] ??
                    ''
                )
            );

        if (
            $newPhotoError ===
            UPLOAD_ERR_NO_FILE &&
            $existingPhoto === ''
        ) {
            $errors[] =
                $ref .
                ': Photograph is required for NC.';
        }
    }

    if (!empty($errors)) {

        backError(
            implode(
                "\n",
                array_slice(
                    $errors,
                    0,
                    40
                )
            ),
            $jobId,
            $assignmentId
        );
    }

    /* =====================================================
       FILE UPLOADS
    ===================================================== */

    foreach (
        ['photo', 'video'] as $type
    ) {

        if (
            !isset($_FILES[$type]) ||
            !is_array(
                $_FILES[$type]['name'] ??
                null
            )
        ) {
            continue;
        }

        foreach (
            $checkpoints as $ref => $description
        ) {

            $uploadError =
                (int)(
                    $_FILES[$type]['error'][$ref] ??
                    UPLOAD_ERR_NO_FILE
                );

            if (
                $uploadError ===
                UPLOAD_ERR_NO_FILE
            ) {
                continue;
            }

            if (
                $uploadError !==
                UPLOAD_ERR_OK
            ) {
                $errors[] =
                    $ref .
                    ': ' .
                    ucfirst($type) .
                    ' upload failed.';
                continue;
            }

            try {

                $path =
                    uploadEvidence(
                        $type,
                        (string)(
                            $_FILES[$type]['tmp_name'][$ref] ??
                            ''
                        ),
                        (string)(
                            $_FILES[$type]['name'][$ref] ??
                            ''
                        ),
                        (int)(
                            $_FILES[$type]['size'][$ref] ??
                            0
                        ),
                        $jobId,
                        $ref
                    );

                $results[$ref][$type] =
                    $path;

            } catch (Throwable $e) {

                $errors[] =
                    $e->getMessage();
            }
        }
    }

    if (!empty($errors)) {

        backError(
            implode(
                "\n",
                array_slice(
                    $errors,
                    0,
                    40
                )
            ),
            $jobId,
            $assignmentId
        );
    }

    /* =====================================================
       FINAL STATUS
    ===================================================== */

    $finalStatus =
        strtoupper(
            trim(
                (string)(
                    $_POST['final_status'] ??
                    ''
                )
            )
        );

    $allowedFinalStatuses = [
        'PASS',
        'CONDITIONAL PASS',
        'FAIL'
    ];

    if (
        !in_array(
            $finalStatus,
            $allowedFinalStatuses,
            true
        )
    ) {
        throw new Exception(
            'Final Inspection Status is required.'
        );
    }

    $inspectorSignature =
        trim(
            (string)(
                $_POST['inspector_signature'] ??
                ''
            )
        );

    $ownerRepresentative =
        trim(
            (string)(
                $_POST['owner_representative'] ??
                ''
            )
        );

    $amcRepresentative =
        trim(
            (string)(
                $_POST['amc_representative'] ??
                ''
            )
        );

    if ($inspectorSignature === '') {
        throw new Exception(
            'Inspector Name is required.'
        );
    }

    /* =====================================================
       SECTION REMARKS
    ===================================================== */

    $sectionRemarks =
        is_array(
            $_POST['section_remarks'] ?? null
        )
            ? $_POST['section_remarks']
            : [];

    foreach (
        $sectionRemarks as $section => $remark
    ) {
        $sectionRemarks[$section] =
            trim((string)$remark);
    }

    /* =====================================================
       BUILD MASTER CHECKLIST JSON
    ===================================================== */

    $checklistData =
        $existingChecklist;

    $checklistData['checklist_code'] =
        'E-CHK-PAT';

    $checklistData['checklist_revision'] =
        'Rev.1';

    $checklistData['document_no'] =
        'E-CHK-PAT';

    $checklistData['module'] =
        'PAT';

    $checklistData['service_code'] =
        'PAT';

    $checklistData['service_name'] =
        (string)(
            $job['service_name'] ??
            'Partial Acceptance Test'
        );

    $checklistData['asset_type'] =
        'Escalator & Travellator';

    $checklistData['asset_id'] =
        (int)$job['asset_id'];

    $checklistData['display_asset_id'] =
        (string)(
            $job['display_asset_id'] ??
            ''
        );

    $checklistData['job_id'] =
        $jobId;

    $checklistData['assignment_id'] =
        $assignmentId;

    $checklistData['saf_id'] =
        (int)$job['saf_id'];

    $checklistData['saf_no'] =
        (string)(
            $job['saf_no'] ??
            ''
        );

    $checklistData['psga_no'] =
        (string)(
            $job['psga_no'] ??
            ''
        );

    $checklistData['checkpoint_prefix'] =
        'E-PAT';

    $checklistData['checkpoint_count'] =
        47;

    $checklistData['priority_by_checkpoint'] =
        $priorityByCheckpoint;

    /*
     * Canonical Inspector metadata. This is informational/
     * compatibility data; each individual result row above is
     * the historical source of truth.
     */
    $checklistData['checkpoint_metadata'] = [];

    foreach ($checkpoints as $ref => $description) {
        $checklistData['checkpoint_metadata'][$ref] = [
            'checkpoint_id' => $ref,
            'checkpoint_name' => $description,
            'priority' => $priorityByCheckpoint[$ref] ?? '',
            'section' => $sectionByCheckpoint[$ref] ?? ''
        ];
    }

    $checklistData['result_codes'] = [
        'C'  => 'Complied',
        'NC' => 'Non-Complied',
        'NA' => 'Not Applicable',
        'CA' => 'Conditional Acceptance'
    ];

    $checklistData['mandatory_rules'] = [
        'NC' => [
            'photograph_required' => true,
            'engineering_remark_required' => true
        ],
        'CA' => [
            'technical_justification_required' => true
        ]
    ];



    $checklistData['results'] =
        $results;

    $checklistData['section_remarks'] =
        $sectionRemarks;

    $checklistData['final_status'] =
        $finalStatus;

    $checklistData['inspector_signature'] =
        $inspectorSignature;

    $checklistData['owner_representative'] =
        $ownerRepresentative;

    $checklistData['amc_representative'] =
        $amcRepresentative;

    $checklistData['timezone'] =
        'Asia/Kolkata';

    $checklistData['saved_at'] =
        date('Y-m-d H:i:s');

    $checklistData['saved_at_ist'] =
        date('d/m/Y H:i:s');

    $checklistData['saved_by_role'] =
        'inspector';

    $checklistData['saved_by'] =
        $inspectorId;

    $json =
        json_encode(
            $checklistData,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_PRETTY_PRINT
        );

    if ($json === false) {
        throw new Exception(
            'Unable to encode Escalator PAT checklist data.'
        );
    }

    /* =====================================================
       TRANSACTION
    ===================================================== */

    $conn->begin_transaction();

    try {

        /*
         * Re-verify the exact assignment inside the transaction.
         */
        $verify =
            $conn->prepare("
                SELECT
                    ia.id,
                    ia.inspector_id,
                    ia.status AS assignment_status,
                    mj.owner_id,
                    mj.asset_id,
                    mj.saf_id,
                    mj.status AS job_status,
                    s.status AS saf_status,
                    s.service_code,
                    a.asset_type
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

        if (!$verify) {
            throw new Exception(
                'Unable to re-check Inspector assignment: ' .
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
                'Unable to re-check Inspector assignment: ' .
                $verify->error
            );
        }

        $current =
            $verify
                ->get_result()
                ->fetch_assoc();

        $verify->close();

        if (!$current) {
            throw new Exception(
                'This inspection assignment is no longer valid.'
            );
        }

        $transactionAssignmentStatus = strtolower(
            trim((string)($current['assignment_status'] ?? ''))
        );

        if (in_array(
            $transactionAssignmentStatus,
            ['submitted', 'final_submitted'],
            true
        )) {
            throw new Exception(
                'This Escalator PAT checklist has already been finally submitted and is locked.'
            );
        }

        $currentAssetType =
            strtolower(
                trim(
                    (string)(
                        $current['asset_type'] ??
                        ''
                    )
                )
            );

        if (
            !str_contains(
                $currentAssetType,
                'escalator'
            ) &&
            !str_contains(
                $currentAssetType,
                'travellator'
            )
        ) {
            throw new Exception(
                'Escalator PAT authorization failed.'
            );
        }

        if (
            strtoupper(
                trim(
                    (string)(
                        $current['service_code'] ??
                        ''
                    )
                )
            ) !== 'PAT'
        ) {
            throw new Exception(
                'PAT service authorization failed.'
            );
        }

        if (
            strtolower(
                trim(
                    (string)(
                        $current['saf_status'] ??
                        ''
                    )
                )
            ) !== 'accepted'
        ) {
            throw new Exception(
                'This SAF is not currently accepted by the Owner.'
            );
        }

        /* =================================================
           SAVE EVIDENCE TABLE IF AVAILABLE
        ================================================= */

        $evidenceTable =
            $conn->query(
                "SHOW TABLES LIKE 'saf_checklist_evidence'"
            );

        if (
            $evidenceTable &&
            $evidenceTable->num_rows > 0
        ) {

            /*
             * Replace evidence for this checklist job with
             * the current complete evidence state.
             */
            $deleteEvidence =
                $conn->prepare("
                    DELETE FROM saf_checklist_evidence
                    WHERE job_id = ?
                ");

            if (!$deleteEvidence) {
                throw new Exception(
                    'Unable to prepare evidence cleanup: ' .
                    $conn->error
                );
            }

            $deleteEvidence->bind_param(
                'i',
                $jobId
            );

            if (!$deleteEvidence->execute()) {
                throw new Exception(
                    'Unable to clean old evidence: ' .
                    $deleteEvidence->error
                );
            }

            $deleteEvidence->close();

            $insertEvidence =
                $conn->prepare("
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

            foreach (
                $results as $ref => $item
            ) {

                $photo =
                    (string)(
                        $item['photo'] ??
                        ''
                    );

                $video =
                    (string)(
                        $item['video'] ??
                        ''
                    );

                if (
                    $photo === '' &&
                    $video === ''
                ) {
                    continue;
                }

                $resultCode =
                    strtoupper(
                        trim(
                            (string)(
                                $item['result'] ??
                                ''
                            )
                        )
                    );

                $insertEvidence->bind_param(
                    'issss',
                    $jobId,
                    $ref,
                    $resultCode,
                    $photo,
                    $video
                );

                if (
                    !$insertEvidence->execute()
                ) {
                    throw new Exception(
                        $ref .
                        ': Unable to save evidence: ' .
                        $insertEvidence->error
                    );
                }
            }

            $insertEvidence->close();
        }

        /* =================================================
           SAVE CHECKLIST JSON
        ================================================= */

        $updateJob =
            $conn->prepare("
                UPDATE maintenance_jobs
                SET
                    checklist = ?,
                    /* Saved checklist state; final OTP submission is separate. */
                    status = 'completed',
                    updated_at = NOW()
                WHERE
                    id = ?
                    AND job_type = 'saf_inspection'
            ");

        if (!$updateJob) {
            throw new Exception(
                'Unable to prepare checklist save: ' .
                $conn->error
            );
        }

        $updateJob->bind_param(
            'si',
            $json,
            $jobId
        );

        if (
            !$updateJob->execute() ||
            $updateJob->affected_rows < 1
        ) {
            throw new Exception(
                'Escalator PAT checklist could not be saved for this job.'
            );
        }

        $updateJob->close();

        /* =================================================
           UPDATE INSPECTOR ASSIGNMENT
        ================================================= */

        $assignmentStatus =
            'completed';

        $updateAssignment =
            $conn->prepare("
                UPDATE inspector_assignments
                SET status = ?
                WHERE
                    id = ?
                    AND job_id = ?
                    AND inspector_id = ?
                    AND status <> 'submitted'
            ");

        if (!$updateAssignment) {
            throw new Exception(
                'Unable to prepare Inspector assignment update: ' .
                $conn->error
            );
        }

        $updateAssignment->bind_param(
            'siii',
            $assignmentStatus,
            $assignmentId,
            $jobId,
            $inspectorId
        );

        if (
            !$updateAssignment->execute()
        ) {
            throw new Exception(
                'Unable to update Inspector assignment: ' .
                $updateAssignment->error
            );
        }

        $updateAssignment->close();

        $conn->commit();

    } catch (Throwable $e) {

        $conn->rollback();

        throw $e;
    }

    /*
     * Clear the local browser draft after successful server save.
     * The checklist page can use this flag if needed.
     */
    $_SESSION['escalator_pat_saved_job_id'] =
        $jobId;

    $_SESSION['success'] =
        'Escalator PAT checklist E-CHK-PAT Rev.1 saved successfully. '
        . 'All 47 active master checkpoints were saved for Asset ID '
        . (
            !empty($job['display_asset_id'])
                ? $job['display_asset_id']
                : $job['asset_id']
        )
        . '.';

    header('Location: inspections.php');
    exit;

} catch (Throwable $e) {

    backError(
        $e->getMessage(),
        $jobId,
        $assignmentId
    );
}
?>