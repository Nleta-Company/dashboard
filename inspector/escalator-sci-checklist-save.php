<?php
/* =========================================================
   NLETA — ESCALATOR SCI CHECKLIST SAVE
   ECHK-03 Rev.1
   ---------------------------------------------------------
   C  = Complied
   NC = Non-Complied
   NA = Not Applicable
   CA = Conditional Acceptance

   NC: Photo + Engineering Remark REQUIRED
   CA: Photo + Technical Justification REQUIRED
   Video: OPTIONAL
   Final submitted: LOCKED
   Timezone: Asia/Kolkata
========================================================= */

error_reporting(E_ALL);
ini_set('display_errors', '1');

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

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function backError(string $message, int $jobId, int $assignmentId): void {
    $_SESSION['error'] = $message;
    header('Location: escalator-sci-checklist.php?' . http_build_query([
        'job_id' => $jobId,
        'assignment_id' => $assignmentId
    ]));
    exit;
}

function checkCsrf(): void {
    $token = (string)($_POST['csrf_token'] ?? '');

    if (function_exists('validateCSRFToken')) {
        if (!validateCSRFToken($token)) {
            throw new RuntimeException('Invalid security token. Please refresh and try again.');
        }
        return;
    }

    if (function_exists('validate_csrf_token')) {
        if (!validate_csrf_token($token)) {
            throw new RuntimeException('Invalid security token. Please refresh and try again.');
        }
        return;
    }

    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
    if ($sessionToken !== '' && ($token === '' || !hash_equals($sessionToken, $token))) {
        throw new RuntimeException('Invalid security token. Please refresh and try again.');
    }
}

function fileAt(array $files, string $ref): array {
    return [
        'name' => (string)($files['name'][$ref] ?? ''),
        'type' => (string)($files['type'][$ref] ?? ''),
        'tmp_name' => (string)($files['tmp_name'][$ref] ?? ''),
        'error' => (int)($files['error'][$ref] ?? UPLOAD_ERR_NO_FILE),
        'size' => (int)($files['size'][$ref] ?? 0)
    ];
}

function savePhoto(array $file, int $jobId, string $ref): string {
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException("$ref: Photograph upload failed.");
    }

    if ($file['size'] <= 0 || $file['size'] > 3 * 1024 * 1024) {
        throw new RuntimeException("$ref: Photograph must be maximum 3 MB.");
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','webp'], true)) {
        throw new RuntimeException("$ref: Photograph must be JPG, JPEG, PNG or WEBP.");
    }

    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
            $mime = finfo_file($fi, $file['tmp_name']);
            finfo_close($fi);
            if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) {
                throw new RuntimeException("$ref: Invalid photograph type.");
            }
        }
    }

    $dir = __DIR__ . '/../uploads/inspections/' . $jobId;
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("$ref: Unable to create evidence folder.");
    }

    $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $ref);
    $name = $safe . '_photo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = $dir . DIRECTORY_SEPARATOR . $name;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException("$ref: Unable to save photograph.");
    }

    return 'uploads/inspections/' . $jobId . '/' . $name;
}

function saveVideo(array $file, int $jobId, string $ref): string {
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException("$ref: Video upload failed.");
    }

    if ($file['size'] <= 0 || $file['size'] > 20 * 1024 * 1024) {
        throw new RuntimeException("$ref: Video must be maximum 20 MB.");
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['mp4','webm','mov','avi'], true)) {
        throw new RuntimeException("$ref: Video must be MP4, WEBM, MOV or AVI.");
    }

    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
            $mime = finfo_file($fi, $file['tmp_name']);
            finfo_close($fi);
            if (!in_array($mime, ['video/mp4','video/webm','video/quicktime','video/x-msvideo'], true)) {
                throw new RuntimeException("$ref: Invalid video type.");
            }
        }
    }

    $dir = __DIR__ . '/../uploads/inspections/' . $jobId;
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("$ref: Unable to create evidence folder.");
    }

    $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $ref);
    $name = $safe . '_video_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = $dir . DIRECTORY_SEPARATOR . $name;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException("$ref: Unable to save video.");
    }

    return 'uploads/inspections/' . $jobId . '/' . $name;
}

function deleteFiles(array $paths): void {
    foreach ($paths as $path) {
        if (!$path) continue;
        $absolute = __DIR__ . '/../' . ltrim($path, '/\\');
        if (is_file($absolute)) @unlink($absolute);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: inspections.php');
    exit;
}

$inspectorId = (int)($_SESSION['uid'] ?? $_SESSION['user_id'] ?? 0);
$jobId = (int)($_POST['job_id'] ?? 0);
$assignmentId = (int)($_POST['assignment_id'] ?? 0);

if (!$inspectorId || !$jobId || !$assignmentId) {
    backError('Invalid Escalator SCI checklist request.', $jobId, $assignmentId);
}

$createdFiles = [];

try {
    checkCsrf();

    /* =====================================================
       ECHK-03 — FIXED 30 CHECKPOINTS
    ===================================================== */
    $sections = [
        'A' => [
            ['E-SCI-001','Equipment identification plate available','P2'],
            ['E-SCI-002','Manufacturer name plate legible','P3'],
            ['E-SCI-003','Serial number matches records','P2'],
            ['E-SCI-004','Rated load & speed displayed','P2'],
            ['E-SCI-005','Directional signage provided','P3'],
            ['E-SCI-006','Emergency instruction signage available','P2'],
            ['E-SCI-007','Safety pictograms displayed','P2'],
            ['E-SCI-008','Maintenance logbook available','P2'],
        ],
        'B' => [
            ['E-SCI-009','Periodic inspection records available','P2'],
            ['E-SCI-010','Preventive maintenance records updated','P2'],
            ['E-SCI-011','Breakdown & repair history maintained','P3'],
            ['E-SCI-012','Risk assessment records available','P2'],
            ['E-SCI-013','OEM technical manual available','P3'],
            ['E-SCI-014','Electrical schematic available','P3'],
        ],
        'C' => [
            ['E-SCI-015','Authorized access control provided','P2'],
            ['E-SCI-016','Adequate machine room ventilation','P2'],
            ['E-SCI-017','Machine room housekeeping compliant','P2'],
            ['E-SCI-018','Emergency lighting available','P2'],
            ['E-SCI-019','Safe working clearance maintained','P1'],
            ['E-SCI-020','Rotating parts adequately guarded','P1'],
        ],
        'D' => [
            ['E-SCI-021','Control panel identification labels','P3'],
            ['E-SCI-022','Cable routing properly secured','P2'],
            ['E-SCI-023','Earth continuity documentation available','P1'],
            ['E-SCI-024','Fire protection provisions compliant','P2'],
            ['E-SCI-025','Emergency isolation procedure displayed','P2'],
        ],
        'E' => [
            ['E-SCI-026','Operational safety policy available','P3'],
            ['E-SCI-027','Emergency rescue procedure displayed','P2'],
            ['E-SCI-028','Maintenance contractor details displayed','P3'],
            ['E-SCI-029','Incident reporting procedure available','P3'],
            ['E-SCI-030','Annual compliance review completed','P2'],
        ],
    ];

    $checkpoints = [];
    foreach ($sections as $sectionCode => $items) {
        foreach ($items as $item) {
            $checkpoints[$item[0]] = [
                'name' => $item[1],
                'priority' => $item[2],
                'section' => $sectionCode
            ];
        }
    }

    if (count($checkpoints) !== 30) {
        throw new RuntimeException('ECHK-03 configuration error.');
    }

    /* =====================================================
       VERIFY CURRENT ASSIGNMENT / JOB
    ===================================================== */
    $stmt = $conn->prepare("
        SELECT
            mj.id AS job_id,
            mj.saf_id,
            mj.service_id,
            mj.asset_id,
            mj.owner_id,
            mj.job_type,
            mj.checklist AS saved_checklist,
            mj.status AS job_status,

            ia.id AS assignment_id,
            ia.inspector_id,
            ia.status AS assignment_status,

            s.service_code,
            s.service_name,
            s.status AS saf_status,

            a.asset_type,
            a.display_asset_id,
            a.asset_name,
            a.location

        FROM inspector_assignments ia
        INNER JOIN maintenance_jobs mj ON mj.id = ia.job_id
        INNER JOIN saf_contracts s ON s.id = mj.saf_id
        LEFT JOIN assets a ON a.id = mj.asset_id

        WHERE
            ia.id = ?
            AND ia.job_id = ?
            AND ia.inspector_id = ?
            AND mj.job_type = 'saf_inspection'
        LIMIT 1
    ");

    if (!$stmt) {
        throw new RuntimeException('Assignment query failed: ' . $conn->error);
    }

    $stmt->bind_param('iii', $assignmentId, $jobId, $inspectorId);
    if (!$stmt->execute()) {
        throw new RuntimeException('Assignment query failed: ' . $stmt->error);
    }

    $job = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$job) {
        throw new RuntimeException('This Escalator SCI inspection is not assigned to this Inspector.');
    }

    $assetType = strtolower(trim((string)($job['asset_type'] ?? '')));
    if (!str_contains($assetType, 'escalator')) {
        throw new RuntimeException('Invalid routing. This save file is only for Escalator SCI.');
    }

    $serviceCode = strtoupper(trim((string)($job['service_code'] ?? '')));
    if ($serviceCode !== 'SCI') {
        throw new RuntimeException(
            'Invalid service. This save file is only for SCI. Assigned service: ' .
            ($serviceCode ?: 'Unknown')
        );
    }

    $safStatus = strtolower(trim((string)($job['saf_status'] ?? '')));
    if ($safStatus !== 'accepted') {
        throw new RuntimeException('The SAF must be accepted by the Owner before inspection.');
    }

    $assignmentStatus = strtolower(trim((string)($job['assignment_status'] ?? '')));
    if (in_array($assignmentStatus, ['submitted','final_submitted'], true)) {
        throw new RuntimeException('This Escalator SCI checklist is locked after Final Submit.');
    }

    /* =====================================================
       ATTENDANCE OTP GUARD
    ===================================================== */
    $attendance = false;

    $att = $conn->prepare("
        SELECT verified_at
        FROM inspector_attendance
        WHERE
            assignment_id = ?
            AND job_id = ?
            AND inspector_id = ?
            AND owner_id = ?
        LIMIT 1
    ");

    if ($att) {
        $ownerId = (int)$job['owner_id'];
        $att->bind_param('iiii', $assignmentId, $jobId, $inspectorId, $ownerId);
        if ($att->execute()) {
            $row = $att->get_result()->fetch_assoc();
            $attendance = !empty($row['verified_at']);
        }
        $att->close();
    }

    if (!$attendance) {
        throw new RuntimeException(
            'Owner site attendance OTP is not verified. Please complete attendance verification first.'
        );
    }

    /* =====================================================
       EXISTING CHECKLIST
    ===================================================== */
    $existing = [];
    if (!empty($job['saved_checklist'])) {
        $decoded = json_decode((string)$job['saved_checklist'], true);
        if (is_array($decoded)) $existing = $decoded;
    }

    $oldResults =
        is_array($existing['results'] ?? null)
            ? $existing['results']
            : [];

    $postedResults =
        is_array($_POST['result'] ?? null)
            ? $_POST['result']
            : [];

    $postedLocked =
        is_array($_POST['locked_result'] ?? null)
            ? $_POST['locked_result']
            : [];

    $postedRemarks =
        is_array($_POST['remark'] ?? null)
            ? $_POST['remark']
            : [];

    $postedJustifications =
        is_array($_POST['technical_justification'] ?? null)
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
       CROSS-LOCKS
       Source C => SCI checkpoint automatically C/locked.
    ===================================================== */
    $crossLocks = [
        'E-SCI-006' => ['module'=>'PAT','ref'=>'E-PAT-016'],
        'E-SCI-018' => ['module'=>'PAT','ref'=>'E-PAT-049'],
        'E-SCI-019' => ['module'=>'PAT','ref'=>'E-PAT-018'],
        'E-SCI-023' => ['module'=>'PAT','ref'=>'E-PAT-057'],
        'E-SCI-024' => ['module'=>'PAT','ref'=>'E-PAT-059'],
        'E-SCI-027' => ['module'=>'SAT','ref'=>'E-SAT Functional Verification'],
    ];

    $autoLocked = [];

    $src = $conn->prepare("
        SELECT
            mj.checklist,
            s.service_code
        FROM maintenance_jobs mj
        INNER JOIN saf_contracts s ON s.id = mj.saf_id
        WHERE
            mj.saf_id = ?
            AND mj.asset_id = ?
            AND mj.job_type = 'saf_inspection'
            AND s.service_code IN ('PAT','SAT')
        ORDER BY mj.id DESC
    ");

    if ($src) {
        $safId = (int)$job['saf_id'];
        $assetId = (int)$job['asset_id'];
        $src->bind_param('ii', $safId, $assetId);

        if ($src->execute()) {
            $rs = $src->get_result();

            while ($source = $rs->fetch_assoc()) {
                $module = strtoupper(trim((string)$source['service_code']));
                $sourceChecklist = json_decode((string)($source['checklist'] ?? ''), true);

                if (!is_array($sourceChecklist)) continue;

                $sourceResults =
                    is_array($sourceChecklist['results'] ?? null)
                        ? $sourceChecklist['results']
                        : [];

                foreach ($crossLocks as $sciRef => $lock) {
                    if (isset($autoLocked[$sciRef])) continue;
                    if ($module !== $lock['module']) continue;

                    $sourceRef = $lock['ref'];
                    $sourceResult = '';

                    if (isset($sourceResults[$sourceRef]) && is_array($sourceResults[$sourceRef])) {
                        $sourceResult = strtoupper(trim((string)(
                            $sourceResults[$sourceRef]['result']
                            ?? $sourceResults[$sourceRef]['status']
                            ?? ''
                        )));
                    }

                    if ($sourceResult === 'C') {
                        $autoLocked[$sciRef] = true;
                    }
                }
            }
        }

        $src->close();
    }

    /* =====================================================
       BUILD RESULT PAYLOAD
    ===================================================== */
    $results = [];

    foreach ($checkpoints as $ref => $meta) {

        if (isset($autoLocked[$ref]) || isset($postedLocked[$ref])) {
            $results[$ref] = [
                'checkpoint' => $meta['name'],
                'priority' => $meta['priority'],
                'section' => $meta['section'],
                'result' => 'C',
                'remark' => 'Auto locked — source checkpoint complied.',
                'technical_justification' => '',
                'photo_path' => '',
                'video_path' => '',
                'cross_locked' => true
            ];
            continue;
        }

        $result =
            strtoupper(trim((string)($postedResults[$ref] ?? '')));

        if (!in_array($result, ['C','NC','NA','CA'], true)) {
            throw new RuntimeException("$ref: Select C, NC, NA or CA.");
        }

        $remark =
            trim((string)($postedRemarks[$ref] ?? ''));

        $justification =
            trim((string)($postedJustifications[$ref] ?? ''));

        $photoPath =
            (string)($oldResults[$ref]['photo_path'] ?? '');

        $videoPath =
            (string)($oldResults[$ref]['video_path'] ?? '');

        /* NC */
        if ($result === 'NC') {

            if ($remark === '') {
                throw new RuntimeException(
                    "$ref: Engineering Remark is mandatory for NC."
                );
            }

            $photo = fileAt($photoFiles, $ref);

            if ($photo['error'] === UPLOAD_ERR_NO_FILE || $photo['name'] === '') {
                throw new RuntimeException(
                    "$ref: Photograph is mandatory for NC."
                );
            }

            $photoPath = savePhoto($photo, $jobId, $ref);
            $createdFiles[] = $photoPath;
        }

        /* CA */
        if ($result === 'CA') {

            /* CA: Engineering Remark is NOT applicable.
             * Technical Justification remains mandatory.
             */
            $remark = '';

            if ($justification === '') {
                throw new RuntimeException(
                    "$ref: Technical Justification is mandatory for CA."
                );
            }

            $photo = fileAt($photoFiles, $ref);

            if ($photo['error'] === UPLOAD_ERR_NO_FILE || $photo['name'] === '') {
                throw new RuntimeException(
                    "$ref: Photograph is mandatory for CA."
                );
            }

            $photoPath = savePhoto($photo, $jobId, $ref);
            $createdFiles[] = $photoPath;
        }

        /* Video is optional */
        $video = fileAt($videoFiles, $ref);

        if (
            $video['error'] !== UPLOAD_ERR_NO_FILE &&
            $video['name'] !== ''
        ) {
            $videoPath = saveVideo($video, $jobId, $ref);
            $createdFiles[] = $videoPath;
        }

        $results[$ref] = [
            'checkpoint' => $meta['name'],
            'priority' => $meta['priority'],
            'section' => $meta['section'],
            'result' => $result,
            'remark' => $remark,
            'technical_justification' => $justification,
            'photo_path' => $photoPath,
            'video_path' => $videoPath,
            'cross_locked' => false
        ];
    }

    /* =====================================================
       FINAL STATUS
    ===================================================== */
    $finalStatus =
        strtoupper(trim((string)($_POST['final_status'] ?? '')));

    if (!in_array(
        $finalStatus,
        ['PASS','CONDITIONAL PASS','FAIL'],
        true
    )) {
        throw new RuntimeException('Please select Final Inspection Status.');
    }

    $sectionRemarks =
        is_array($_POST['section_remarks'] ?? null)
            ? $_POST['section_remarks']
            : [];

    $payload = [
        'checklist_code' => 'ECHK-03',
        'checklist_revision' => 'Rev.1',
        'module' => 'SCI',
        'asset_type' => 'Escalator',
        'service_code' => 'SCI',
        'service_name' => (string)($job['service_name'] ?? 'SCI'),
        'timezone' => 'Asia/Kolkata',
        'saved_at_ist' => date('Y-m-d H:i:s'),

        'job_id' => $jobId,
        'assignment_id' => $assignmentId,
        'saf_id' => (int)$job['saf_id'],
        'asset_id' => (int)$job['asset_id'],
        'inspector_id' => $inspectorId,

        'checkpoint_count' => 30,
        'results' => $results,
        'section_remarks' => $sectionRemarks,
        'final_status' => $finalStatus,

        'inspector_signature' =>
            trim((string)($_POST['inspector_signature'] ?? '')),

        'owner_representative' =>
            trim((string)($_POST['owner_representative'] ?? '')),

        'amc_representative' =>
            trim((string)($_POST['amc_representative'] ?? '')),

        'evidence_rules' => [
            'NC' => 'Photograph + Engineering Remark REQUIRED',
            'CA' => 'Photograph + Technical Justification REQUIRED',
            'VIDEO' => 'OPTIONAL / CONDITIONAL'
        ]
    ];

    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT |
        JSON_THROW_ON_ERROR
    );

    /* =====================================================
       TRANSACTION + SECOND LOCK CHECK
    ===================================================== */
    $conn->begin_transaction();

    $lock = $conn->prepare("
        SELECT status
        FROM inspector_assignments
        WHERE
            id = ?
            AND job_id = ?
            AND inspector_id = ?
        LIMIT 1
        FOR UPDATE
    ");

    if (!$lock) {
        throw new RuntimeException('Unable to lock assignment: ' . $conn->error);
    }

    $lock->bind_param('iii', $assignmentId, $jobId, $inspectorId);

    if (!$lock->execute()) {
        throw new RuntimeException('Unable to lock assignment: ' . $lock->error);
    }

    $lockedRow = $lock->get_result()->fetch_assoc();
    $lock->close();

    if (!$lockedRow) {
        throw new RuntimeException('Inspector assignment not found.');
    }

    $lockedStatus =
        strtolower(trim((string)($lockedRow['status'] ?? '')));

    if (in_array($lockedStatus, ['submitted','final_submitted'], true)) {
        throw new RuntimeException(
            'This Escalator SCI checklist is locked after Final Submit.'
        );
    }

    /* =====================================================
       SAVE JSON TO maintenance_jobs
    ===================================================== */
    $update = $conn->prepare("
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

    if (!$update) {
        throw new RuntimeException('Unable to prepare checklist save: ' . $conn->error);
    }

    $ownerId = (int)$job['owner_id'];
    $safId = (int)$job['saf_id'];
    $assetId = (int)$job['asset_id'];

    $update->bind_param(
        'siiii',
        $json,
        $jobId,
        $ownerId,
        $safId,
        $assetId
    );

    if (!$update->execute()) {
        throw new RuntimeException(
            'Unable to save Escalator SCI checklist: ' . $update->error
        );
    }

    $update->close();

    /* =====================================================
       OPTIONAL EVIDENCE INDEX
       Uses saf_checklist_evidence if it exists.
    ===================================================== */
    $table = $conn->query("SHOW TABLES LIKE 'saf_checklist_evidence'");

    if ($table && $table->num_rows > 0) {

        $delete = $conn->prepare("
            DELETE FROM saf_checklist_evidence
            WHERE job_id = ?
        ");

        if ($delete) {
            $delete->bind_param('i', $jobId);
            $delete->execute();
            $delete->close();
        }

        $insert = $conn->prepare("
            INSERT INTO saf_checklist_evidence
            (
                job_id,
                ref_code,
                result,
                photo_path,
                video_path,
                document_path
            )
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        if ($insert) {

            foreach ($results as $ref => $item) {

                $result = (string)($item['result'] ?? '');
                $photo = (string)($item['photo_path'] ?? '');
                $video = (string)($item['video_path'] ?? '');
                $document = '';

                if ($photo === '' && $video === '') {
                    continue;
                }

                $insert->bind_param(
                    'isssss',
                    $jobId,
                    $ref,
                    $result,
                    $photo,
                    $video,
                    $document
                );

                if (!$insert->execute()) {
                    throw new RuntimeException(
                        "Unable to save evidence for $ref: " . $insert->error
                    );
                }
            }

            $insert->close();
        }
    }

    /* =====================================================
       MARK THIS INSPECTOR JOB COMPLETED
       Final Submit is still a separate lock action.
    ===================================================== */
    $assignmentUpdate = $conn->prepare("
        UPDATE inspector_assignments
        SET status = 'completed'
        WHERE
            id = ?
            AND job_id = ?
            AND inspector_id = ?
            AND status NOT IN ('submitted','final_submitted')
    ");

    if (!$assignmentUpdate) {
        throw new RuntimeException(
            'Unable to prepare assignment update: ' . $conn->error
        );
    }

    $assignmentUpdate->bind_param(
        'iii',
        $assignmentId,
        $jobId,
        $inspectorId
    );

    if (!$assignmentUpdate->execute()) {
        throw new RuntimeException(
            'Unable to update assignment: ' . $assignmentUpdate->error
        );
    }

    $assignmentUpdate->close();

    $conn->commit();

    $_SESSION['success'] =
        'Escalator SCI checklist saved successfully.';

    header(
        'Location: escalator-sci-checklist.php?' .
        http_build_query([
            'job_id' => $jobId,
            'assignment_id' => $assignmentId
        ])
    );
    exit;

} catch (Throwable $e) {

    try {
        $conn->rollback();
    } catch (Throwable $ignore) {}

    deleteFiles($createdFiles);

    backError(
        $e->getMessage(),
        $jobId,
        $assignmentId
    );
}
?>
